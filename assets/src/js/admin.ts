/**
 * Settings page behaviour for the iG:Syntax Hiliter plugin.
 *
 * No jQuery, no libraries: a fetch to the plugin's REST routes, a small live
 * region for feedback, and the browser's own tooltips and confirmation dialog.
 */

( function () {
	'use strict';

	/**
	 * A control standing for one setting.
	 *
	 * A yes/no setting is a `role="switch"` button and a choice is a `<select>`;
	 * the template draws no other kind.
	 */
	type OptionControl = HTMLButtonElement | HTMLSelectElement;

	/**
	 * Anything the page switches off while it is working.
	 */
	type LockableElement = OptionControl | HTMLButtonElement;

	/**
	 * An error carrying what this code knows about a failed request.
	 *
	 * Every member is optional: a network failure produces a plain `Error` with
	 * none of them, and the code reading these has to cope with that.
	 */
	interface RequestError extends Error {
		status?: number | undefined;
		code?: string | undefined;
		isTimeout?: boolean | undefined;
	}

	/**
	 * What a save answers with.
	 */
	interface OptionResponse {
		value?: string | undefined;
		message?: string | undefined;
	}

	/**
	 * What the revert route answers when asked how much there is to do.
	 */
	interface RevertState {
		total?: number | undefined;
	}

	/**
	 * What one converted batch answers with.
	 *
	 * The counts are `unknown` rather than `number` on purpose — `readCount()`
	 * exists precisely because an answer may carry no number at all, and typing
	 * them as numbers here would describe the answer this code hopes for rather
	 * than the one it has to survive.
	 */
	interface RevertBatch {
		processed?: unknown;
		converted?: unknown;
		skipped?: unknown;
		failed?: unknown;
		blocks_left_alone?: unknown;
		done?: boolean | undefined;
		cursor?: number | undefined;
	}

	/**
	 * Running totals across every batch of a conversion run.
	 */
	interface RevertTotals {
		processed: number;
		converted: number;
		skipped: number;
		failed: number;
		blocksLeftAlone: number;
		partial: boolean;
	}

	/**
	 * The totals a count can be added to.
	 *
	 * Named separately from `RevertTotals` so that `partial`, which is a flag and
	 * not a count, cannot be reached by `addCount()`.
	 */
	type RevertCountName = Exclude< keyof RevertTotals, 'partial' >;

	const config = window.igSyntaxHiliterAdmin;

	if ( ! config || ! config.restUrl ) {
		return;
	}

	/*
	 * Read out of the config once, here. `request()` is a function declaration and
	 * so is hoisted above the guard just made, which means TypeScript will not
	 * carry that guard into it — and a non-null assertion in there would be a
	 * claim rather than a check.
	 */
	const restUrl = config.restUrl;
	const nonce = config.nonce;

	/*
	 * PHP sends every one of these strings, so the type says so. The fallback is
	 * for a PHP which stopped sending them, and it deliberately leaves the page
	 * working rather than dead: a missing string reads badly, a thrown error
	 * leaves a settings page that saves nothing.
	 */
	const strings: IgshAdminStrings = config.i18n || ( {} as IgshAdminStrings );

	let toast: HTMLDivElement | null = null;
	let toastTimer: number | undefined;
	let busyElements: LockableElement[] = [];
	let pageBusy = false;

	/*
	 * How long a save is given before the page gives up on it. A save locks the
	 * whole screen, so a server which answers nothing at all would otherwise
	 * leave it locked until somebody thought to reload it.
	 */
	const SAVE_TIMEOUT_MS = 15000;

	/**
	 * Shows a short message in the page's live region.
	 *
	 * @param message Message to show.
	 * @param isError Whether the message reports a failure.
	 * @param sticky  Whether the message stays until it is replaced.
	 */
	function notify(
		message: string,
		isError?: boolean,
		sticky?: boolean
	): void {
		if ( ! toast ) {
			toast = document.createElement( 'div' );
			toast.className = 'igsh-toast';
			toast.setAttribute( 'role', 'status' );
			toast.setAttribute( 'aria-live', 'polite' );
			document.body.appendChild( toast );
		}

		const element = toast;

		window.clearTimeout( toastTimer );

		element.textContent = message;
		element.classList.toggle( 'igsh-toast--error', !! isError );
		element.classList.add( 'igsh-toast--visible' );

		if ( ! sticky ) {
			toastTimer = window.setTimeout(
				function () {
					element.classList.remove( 'igsh-toast--visible' );
				},
				isError ? 6000 : 2500
			);
		}
	}

	/**
	 * Calls one of the plugin's REST routes.
	 *
	 * A timeout is only worth having where the page is waiting on the answer with
	 * everything locked, so only the save asks for one; the revert runs for as
	 * long as its batches take and is given none. The clock is stopped whichever
	 * way the request ends, success or failure, so that a request which answered
	 * in time can never be aborted afterwards.
	 *
	 * A browser with no AbortController simply gets no timeout. That is the older
	 * behaviour and it is a safe one: the page still locks and still unlocks on
	 * every answer, it just has no way of giving up on silence.
	 *
	 * What comes back is whatever the route sent, so the caller names the shape it
	 * expects. Nothing here checks that it got one — this is the JSON boundary,
	 * and the code past it is written to survive an answer that is missing things.
	 *
	 * @param method  Request method.
	 * @param route   Route path, relative to the plugin's namespace.
	 * @param body    Optional request body.
	 * @param timeout Optional milliseconds to wait before giving up.
	 *
	 * @return The decoded response body.
	 */
	function request< T >(
		method: string,
		route: string,
		body?: object,
		timeout?: number
	): Promise< T > {
		const options: RequestInit = {
			method,
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': nonce,
				Accept: 'application/json',
			},
		};
		let timer: number | null = null;
		let expired = false;
		let pending: Promise< Response >;

		if ( body ) {
			( options.headers as Record< string, string > )[ 'Content-Type' ] =
				'application/json';
			options.body = JSON.stringify( body );
		}

		if ( timeout && window.AbortController ) {
			const controller = new window.AbortController();

			options.signal = controller.signal;

			timer = window.setTimeout( function () {
				expired = true;

				controller.abort();
			}, timeout );
		}

		/**
		 * Stops the clock, however the request ended.
		 */
		function stopClock(): void {
			if ( null !== timer ) {
				window.clearTimeout( timer );

				timer = null;
			}
		}

		/*
		 * A fetch which throws on its way out rather than returning a rejected
		 * promise would never reach the caller's .catch(), and the page would stay
		 * locked with nothing left to unlock it.
		 */
		try {
			pending = window.fetch( restUrl + route, options );
		} catch ( error ) {
			stopClock();

			return window.Promise.reject( error );
		}

		return pending
			.then( function ( response ) {
				return response.json().then(
					function (
						payload:
							| ( T & { message?: string; code?: string } )
							| null
					) {
						if ( response.ok ) {
							return payload as T;
						}

						const error: RequestError = new Error(
							( payload && payload.message ) ||
								response.statusText
						);

						error.status = response.status;
						error.code = payload ? payload.code : undefined;

						throw error;
					},
					function () {
						const error: RequestError = new Error(
							response.statusText
						);

						error.status = response.status;

						throw error;
					}
				);
			} )
			.then(
				function ( payload ) {
					stopClock();

					return payload;
				},
				function ( error: RequestError ) {
					stopClock();

					/*
					 * An abort this asked for is handed on as the timeout it was, and
					 * not as whatever the browser called it. The browser reports it as a
					 * DOMException named AbortError, but a caller which has to recognise
					 * it by that name is reading someone else's wording, and it cannot
					 * tell an abort of ours from one anything else on the page asked for.
					 * The message on it is never shown: a caller which times out words
					 * that for itself.
					 */
					if ( expired ) {
						const timedOut: RequestError = new Error( 'timeout' );

						timedOut.isTimeout = true;

						throw timedOut;
					}

					throw error;
				}
			);
	}

	/**
	 * Whether a control stands for a yes/no setting.
	 *
	 * The template writes `data-igsh-toggle` onto a switch button and onto nothing
	 * else, so the flag and the element type always agree. The `instanceof` is
	 * what tells TypeScript that; it is not a second opinion about the markup.
	 *
	 * @param control Control to test.
	 *
	 * @return True when the control is a toggle.
	 */
	function isToggle( control: OptionControl ): control is HTMLButtonElement {
		return (
			Boolean( control.dataset.igshToggle ) &&
			control instanceof HTMLButtonElement
		);
	}

	/**
	 * Reads the value a control currently stands for.
	 *
	 * A switch says what it stands for in `aria-checked`, and that is read here
	 * rather than kept alongside in a second place: what the screen reader is told
	 * and what gets saved are then the same fact.
	 *
	 * @param control Control to read.
	 *
	 * @return Value to store for the setting.
	 */
	function readControl( control: OptionControl ): string {
		if ( isToggle( control ) ) {
			return 'true' === control.getAttribute( 'aria-checked' )
				? 'yes'
				: 'no';
		}

		return control.value;
	}

	/**
	 * Puts a control back to a value it held earlier.
	 *
	 * @param control Control to set.
	 * @param value   Value to set it to.
	 */
	function writeControl( control: OptionControl, value: string ): void {
		if ( isToggle( control ) ) {
			control.setAttribute(
				'aria-checked',
				'yes' === value ? 'true' : 'false'
			);

			return;
		}

		control.value = value;
	}

	/**
	 * Locks or unlocks the whole page for the length of a request.
	 *
	 * The screen saves one setting per request and all seven settings live in one
	 * stored array, so two saves in quick succession are two overlapping requests:
	 * the second re-reads that array out of the copy its own PHP process cached
	 * when it started, writes its own idea of it back, and silently undoes the
	 * first while both report success. Locking every control for the length of a
	 * save is what makes a second request impossible rather than merely unlikely.
	 *
	 * Unlocking puts back exactly the elements this disabled, which is why they
	 * are remembered rather than looked up again: a control already disabled for
	 * some reason of its own was never ours to switch on, and a blanket pass over
	 * the page would switch it on anyway.
	 *
	 * @param isBusy Whether the page is working.
	 */
	function setBusy( isBusy: boolean ): void {
		if ( ! isBusy ) {
			busyElements.forEach( function ( element ) {
				element.disabled = false;
			} );

			busyElements = [];
			pageBusy = false;

			return;
		}

		if ( pageBusy ) {
			return;
		}

		pageBusy = true;

		const elements: LockableElement[] = Array.from(
			document.querySelectorAll< OptionControl >( '[data-igsh-option]' )
		);
		const button = document.getElementById(
			'igsh-revert-blocks'
		) as HTMLButtonElement | null;

		if ( button ) {
			elements.push( button );
		}

		elements.forEach( function ( element ) {
			if ( element.disabled ) {
				return;
			}

			element.disabled = true;

			busyElements.push( element );
		} );
	}

	/**
	 * Sends one setting, and puts the control back if it does not save.
	 *
	 * @param control Control which changed.
	 */
	function saveSetting( control: OptionControl ): void {
		const name = control.dataset.igshOption;
		const value = readControl( control );
		const previous = control.dataset.igshPrevious;

		setBusy( true );

		notify( strings.saving, false, true );

		request< OptionResponse >(
			'POST',
			'option',
			{ name, value },
			SAVE_TIMEOUT_MS
		)
			.then( function ( payload ) {
				control.dataset.igshPrevious =
					payload && payload.value ? payload.value : value;

				writeControl( control, control.dataset.igshPrevious );

				notify(
					( payload && payload.message ) || strings.saved,
					false
				);
			} )
			.catch( function ( error: RequestError ) {
				let message = strings.saveFailed + ' ' + error.message;

				/*
				 * A save which timed out may still have been saved: the request was
				 * abandoned, not cancelled, and the site may well have written it after
				 * the page stopped listening. So the control goes back to what it showed
				 * before, as it does for any other failure, and the message says that
				 * what is on screen may no longer be what is stored.
				 */
				if ( error.isTimeout ) {
					message = strings.saveTimedOut;
				} else if (
					403 === error.status &&
					'rest_cookie_invalid_nonce' === error.code
				) {
					message = strings.reloadNeeded;
				}

				/*
				 * `init()` records the value before anything can change it, so there is
				 * always something to go back to. Where there is not, the control is
				 * left showing what the user chose rather than being blanked — putting
				 * a select back to nothing would be a worse answer than leaving it.
				 */
				if ( undefined !== previous ) {
					writeControl( control, previous );
				}

				notify( message, true );
			} )
			.finally( function () {
				setBusy( false );
			} );
	}

	/**
	 * Fills a translated string's count into it.
	 *
	 * Every %d and %1$d in the string is replaced, and a string carrying neither
	 * gets the count put on the end, so a mistranslated string costs a clumsy
	 * sentence rather than a placeholder on screen or a count nobody is shown.
	 *
	 * @param template String the count goes into.
	 * @param count    Number to put in it.
	 *
	 * @return The string, with the count in it.
	 */
	function withCount( template: string, count: number ): string {
		const text = String( template || '' );
		const filled = text.replace( /%(?:\d+\$)?d/g, String( count ) );

		if ( filled !== text ) {
			return filled;
		}

		return '' === text ? String( count ) : text + ' ' + String( count );
	}

	/**
	 * Adds one clause to the report.
	 *
	 * Built as a node rather than as markup, so that no translated string can
	 * carry any.
	 *
	 * @param status    Element the report is written to.
	 * @param text      Clause to add.
	 * @param isWarning Whether the clause names code which will be lost.
	 */
	function appendClause(
		status: HTMLElement,
		text: string,
		isWarning: boolean
	): void {
		const clause = document.createElement( 'span' );

		if ( isWarning ) {
			clause.className = 'igsh-revert__warning';
		}

		clause.textContent = ' ' + String( text || '' );

		status.appendChild( clause );
	}

	/**
	 * Writes the closing report of a conversion run.
	 *
	 * @param status Element the report is written to.
	 * @param totals Running totals from every batch.
	 */
	function reportRevert( status: HTMLElement, totals: RevertTotals ): void {
		status.textContent = withCount( strings.revertDone, totals.converted );

		if ( totals.skipped ) {
			appendClause(
				status,
				withCount( strings.revertDoneLeft, totals.skipped ),
				false
			);
		}

		if ( totals.blocksLeftAlone ) {
			appendClause(
				status,
				withCount( strings.revertDoneBlocks, totals.blocksLeftAlone ),
				true
			);
		}

		if ( totals.failed ) {
			appendClause(
				status,
				withCount( strings.revertDoneFailed, totals.failed ),
				true
			);
		}

		if ( totals.partial ) {
			appendClause( status, strings.revertDonePartial, false );
		}
	}

	/**
	 * Reads one count out of a batch's answer.
	 *
	 * @param value Value the answer carried.
	 *
	 * @return The count, or NULL when the answer carried no number.
	 */
	function readCount( value: unknown ): number | null {
		const count = Number( value );

		if ( null === value || '' === value || ! isFinite( count ) ) {
			return null;
		}

		return count;
	}

	/**
	 * Adds one of a batch's counts to the running totals.
	 *
	 * A count the answer did not carry leaves the total where it was and marks the
	 * totals short, so the report can say that it is missing something instead of
	 * quietly leaving a clause out.
	 *
	 * @param totals Running totals to add to.
	 * @param name   Total to add to.
	 * @param value  Value the answer carried.
	 */
	function addCount(
		totals: RevertTotals,
		name: RevertCountName,
		value: unknown
	): void {
		const count = readCount( value );

		if ( null === count ) {
			totals.partial = true;

			return;
		}

		totals[ name ] += count;
	}

	/**
	 * Runs the block to shortcode conversion, one batch at a time.
	 *
	 * This takes the same lock a save takes — it rewrites content, and a setting
	 * saved halfway through it has no business landing in the middle of that — but
	 * it is deliberately given no timeout. A run legitimately lasts as long as the
	 * site has posts to walk, and the button it disables is disabled by the lock,
	 * so it keeps no idea of its own about what is switched off.
	 *
	 * @param progress Wrapper holding the progress meter.
	 * @param meter    The progress meter itself.
	 * @param status   Element the running total is written to.
	 */
	function runRevert(
		progress: HTMLElement,
		meter: HTMLProgressElement,
		status: HTMLElement
	): void {
		// eslint-disable-next-line no-alert -- this rewrites post_content across the whole site and cannot be undone from here. Stopping the click is the point of the control, and a custom dialog would be one more thing to get wrong on a page that loads no libraries.
		if ( ! window.confirm( strings.revertConfirm ) ) {
			return;
		}

		const totals: RevertTotals = {
			processed: 0,
			converted: 0,
			skipped: 0,
			failed: 0,
			blocksLeftAlone: 0,
			partial: false,
		};

		setBusy( true );

		status.textContent = strings.revertRunning;

		request< RevertState >( 'GET', 'revert' )
			.then( function ( state ) {
				const total = state && state.total ? state.total : 0;

				if ( ! total ) {
					status.textContent = strings.revertNone;

					return null;
				}

				meter.max = total;
				meter.value = 0;
				progress.hidden = false;

				return nextBatch( 0 );
			} )
			.catch( function ( error: RequestError ) {
				status.textContent = strings.revertFailed + ' ' + error.message;
			} )
			.finally( function () {
				setBusy( false );
			} );

		/**
		 * Fetches and applies one batch, then the next.
		 *
		 * @param cursor Id of the last post already handled.
		 *
		 * @return Resolved once there is nothing left to do.
		 */
		function nextBatch( cursor: number ): Promise< null > {
			return request< RevertBatch >( 'POST', 'revert', { cursor } ).then(
				function ( batch ) {
					const processed = readCount( batch.processed );

					addCount( totals, 'processed', batch.processed );
					addCount( totals, 'converted', batch.converted );
					addCount( totals, 'skipped', batch.skipped );
					addCount( totals, 'failed', batch.failed );
					addCount(
						totals,
						'blocksLeftAlone',
						batch.blocks_left_alone
					);

					meter.value = Math.min( totals.processed, meter.max );

					status.textContent = strings.revertRunning;

					// An answer which does not say how much it did is the end of the run: there is nothing to carry on from.
					if ( batch.done || ! processed ) {
						reportRevert( status, totals );

						meter.value = meter.max;

						return null;
					}

					return nextBatch( batch.cursor ?? 0 );
				}
			);
		}
	}

	/**
	 * Wires the page up.
	 */
	function init(): void {
		const controls =
			document.querySelectorAll< OptionControl >( '[data-igsh-option]' );

		controls.forEach( function ( control ) {
			control.dataset.igshPrevious = readControl( control );

			/*
			 * A switch is a button, so it reports a click and never a change; it is
			 * flipped here and then saved. A `<select>` reports a change and flips
			 * itself. Space and Enter both arrive as a click on a button, so the
			 * keyboard needs nothing of its own.
			 */
			if ( isToggle( control ) ) {
				control.addEventListener( 'click', function () {
					writeControl(
						control,
						'yes' === readControl( control ) ? 'no' : 'yes'
					);

					saveSetting( control );
				} );

				return;
			}

			control.addEventListener( 'change', function () {
				saveSetting( control );
			} );
		} );

		const button = document.getElementById( 'igsh-revert-blocks' );
		const progress = document.getElementById( 'igsh-revert-progress' );
		const meter = document.getElementById(
			'igsh-revert-meter'
		) as HTMLProgressElement | null;
		const status = document.getElementById( 'igsh-revert-status' );

		if ( button && progress && meter && status ) {
			button.addEventListener( 'click', function () {
				runRevert( progress, meter, status );
			} );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();

//EOF
