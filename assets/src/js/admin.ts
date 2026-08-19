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
	 *
	 * The stored value, which is what the control is put back to — the setting's
	 * own default comes back where the value sent was one it does not accept, so
	 * what is on screen afterwards is what is in the database. The message the
	 * reader sees is built here, not sent, because only this side knows the label
	 * of the setting it is about.
	 */
	interface OptionResponse {
		value?: string | undefined;
	}

	/**
	 * What the theme refresh answers with.
	 *
	 * Both are optional and are checked before they are used: this is the JSON
	 * boundary, and an answer missing one of them must leave the dropdown alone
	 * rather than empty it.
	 */
	interface ThemesResponse {
		choices?: Record< string, string > | undefined;
		urls?: Record< string, string > | undefined;
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

	/*
	 * The nonce is the one of these which changes, so it is a variable and is read
	 * at call time rather than closed over as a value. Core hands a fresh one back
	 * on the way out of every successful cookie authenticated REST response — see
	 * `rest_cookie_check_errors()` — and `request()` stores what it is given, so
	 * every save, revert and theme refresh re-arms it as a side effect of work the
	 * page was doing anyway. A screen being used goes on working for as long as it
	 * is used, without this plugin adding a route or a timer of its own. A screen
	 * left untouched for longer than a nonce lives still needs reloading, and that
	 * is what the 403 branch is for.
	 */
	let nonce = config.nonce;

	/*
	 * The same object, under a name the guard above has narrowed. The refresh
	 * button replaces `themes`, so this cannot be copied out by value the way the
	 * two strings above are.
	 */
	const adminConfig = config;

	/*
	 * PHP sends every one of these strings, so the type says so. The fallback is
	 * for a PHP which stopped sending them, and it deliberately leaves the page
	 * working rather than dead: a missing string reads badly, a thrown error
	 * leaves a settings page that saves nothing.
	 */
	const strings: IgshAdminStrings = config.i18n || ( {} as IgshAdminStrings );

	/*
	 * `notices.js` is declared as a dependency of this script in PHP, so it is
	 * always there. The fallback is for the case where somebody dequeues it: the
	 * page goes on saving settings, it just stops narrating them. Silently doing
	 * the work beats a settings page that throws on every change.
	 */
	const notices: IgshNotices = window.igshNotices || {
		notify(): IgshNotice {
			return {
				settle(): void {},
				dismiss(): void {},
			};
		},
	};

	let busyElements: OptionControl[] = [];
	let pageBusy = false;

	/*
	 * How long a request is given before the page gives up on it. Every request
	 * this page makes locks the whole screen, so a server which answers nothing at
	 * all would otherwise leave it locked until somebody thought to reload it.
	 * `request()` takes this as a required argument rather than an optional one,
	 * so that a caller cannot leave the page with no way out of a silence.
	 */
	const REQUEST_TIMEOUT_MS = 15000;

	/*
	 * And what one batch of the revert is given. Longer, because a batch rewrites
	 * the content of up to two hundred posts and the whole run is many batches —
	 * but a batch which has genuinely stopped answering still has to end, or the
	 * run sits there reporting "converting" for good.
	 */
	const REVERT_TIMEOUT_MS = 60000;

	/*
	 * Id of the `style` tag the preview keeps its font rule in. It is the preview's
	 * own and belongs to nothing PHP enqueued, which is why it is spelled out here
	 * and the stylesheet link's id is not.
	 */
	const PREVIEW_FONT_STYLE_ID = 'igsh-preview-font';

	/**
	 * The name of a setting, as the screen already shows it.
	 *
	 * Read off the `<label for="…">` the template prints rather than sent over a
	 * second time in the script data: both a button and a select are labelable
	 * elements, so the browser has already made that association and `labels` is
	 * it. One translated string, in one place, is what the reader sees in both.
	 *
	 * An empty answer is survivable — `fill()` still produces a sentence.
	 *
	 * @param control Control standing for the setting.
	 *
	 * @return The label's text, or an empty string when there is no label.
	 */
	function controlLabel( control: OptionControl ): string {
		const label = control.labels ? control.labels[ 0 ] : null;

		return ( label?.textContent ?? '' ).trim();
	}

	/**
	 * The name of the value a choice control currently stands for.
	 *
	 * Read off the selected `<option>` for the same reason `controlLabel()` reads
	 * the `<label>`: the words are in the document already, translated once by the
	 * same PHP that drew the control. Sending them over again in the script data
	 * would be a second copy of the same list to keep in step.
	 *
	 * A toggle has no options to read, and gets no name from here — it is reported
	 * as enabled or disabled instead.
	 *
	 * @param control Control standing for the setting.
	 *
	 * @return The selected option's text, or an empty string when there is none.
	 */
	function choiceLabel( control: OptionControl ): string {
		if ( isToggle( control ) ) {
			return '';
		}

		const option = control.selectedOptions[ 0 ];

		return ( option?.textContent ?? '' ).trim();
	}

	/**
	 * Fills a translated string's placeholders in.
	 *
	 * `%1$s` is taken by position and a bare `%s` in order, which is what
	 * `sprintf()` would have done with the same string on the PHP side. A
	 * placeholder with no value behind it is left standing rather than blanked,
	 * and a string carrying no placeholder at all gets the values put on the
	 * front — so a mistranslated string costs a clumsy sentence rather than a
	 * `%s` on screen or a message naming nothing.
	 *
	 * `withCount()` below is the `%d` half of this and stays separate: its
	 * fallback is about a number rather than a name, and the revert report is the
	 * only thing that needs it.
	 *
	 * @param template String the values go into.
	 * @param values   Values to put in it, in order.
	 *
	 * @return The string, with the values in it.
	 */
	function fill( template: string, ...values: string[] ): string {
		const text = String( template || '' );
		const given = values.filter( function ( value ) {
			return '' !== value;
		} );

		if ( ! given.length ) {
			return text;
		}

		let next = 0;

		const filled = text.replace(
			/%(?:(\d+)\$)?s/g,
			function ( placeholder: string, position?: string ): string {
				const at = position ? parseInt( position, 10 ) - 1 : next++;

				return values[ at ] ?? placeholder;
			}
		);

		if ( filled !== text ) {
			return filled;
		}

		const named = given.join( ' — ' );

		return '' === text ? named : named + ' — ' + text;
	}

	/**
	 * Calls one of the plugin's REST routes.
	 *
	 * The timeout is required and has no default. The page is locked for the whole
	 * of every request it makes, so a caller which forgot one would leave the screen
	 * locked on a server which answered nothing at all — and that is a thing a
	 * caller was able to forget, so it is now a thing a caller cannot express. The
	 * clock is stopped whichever way the request ends, success or failure, so that a
	 * request which answered in time can never be aborted afterwards.
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
	 * @param timeout Milliseconds to wait before giving up.
	 * @param body    Optional request body.
	 *
	 * @return The decoded response body.
	 */
	function request< T >(
		method: string,
		route: string,
		timeout: number,
		body?: object
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
				/*
				 * Only a response whose nonce was accepted carries a fresh one —
				 * `rest_cookie_check_errors()` returns its 403 before it reaches the
				 * `send_header()` call. So this keeps a working page working and cannot
				 * rescue one whose nonce has already gone; `describeError()` is what
				 * answers that, by asking the reader to reload. The header is read
				 * whatever the status because "there is one" is the only question worth
				 * asking. Same origin, so every header is readable, and the call already
				 * sends its cookies.
				 */
				const fresh = response.headers.get( 'X-WP-Nonce' );

				if ( fresh ) {
					nonce = fresh;
				}

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
	 * What to say about a setting that has just been saved.
	 *
	 * Naming the setting is not enough on its own: switching a toggle off and
	 * switching it back on both used to read "Show the toolbar — saved", which
	 * confirms that something happened without confirming what — and what is the
	 * one part the reader can no longer see, the notice having taken their eye off
	 * the control. It is the whole of what the live region announces, too.
	 *
	 * @param control Control that was saved.
	 * @param label   Name of the setting.
	 *
	 * @return The message to show.
	 */
	function savedMessage( control: OptionControl, label: string ): string {
		if ( isToggle( control ) ) {
			return fill(
				'yes' === readControl( control )
					? strings.savedOn
					: strings.savedOff,
				label
			);
		}

		const choice = choiceLabel( control );

		if ( '' === choice ) {
			return fill( strings.saved, label );
		}

		return fill( strings.savedChoice, label, choice );
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

		const elements: OptionControl[] = Array.from(
			document.querySelectorAll< OptionControl >( '[data-igsh-option]' )
		);
		const buttons = [ 'igsh-revert-blocks', 'igsh-refresh-themes' ];

		buttons.forEach( function ( id ) {
			const button = document.getElementById(
				id
			) as HTMLButtonElement | null;

			if ( button ) {
				elements.push( button );
			}
		} );

		elements.forEach( function ( element ) {
			if ( element.disabled ) {
				return;
			}

			element.disabled = true;

			busyElements.push( element );
		} );
	}

	/**
	 * Runs one piece of work with the page locked, and unlocks it however it ends.
	 *
	 * The only caller of `setBusy()` there is. It exists because the lock and the
	 * unlock were written out at each of the three places which make requests, and
	 * a caller which performs a step is a caller which can leave it out — the theme
	 * refresh did, and locked the settings screen with nothing left to unlock it.
	 *
	 * The unit is the interaction and not the request. The revert is one lock over
	 * a `GET` and however many `POST` batches follow it, and unlocking between them
	 * would hand the page back to the reader half way through a run.
	 *
	 * @param work What to do while the page is locked.
	 *
	 * @return Whatever the work resolved to.
	 */
	function locked< T >( work: () => Promise< T > ): Promise< T > {
		setBusy( true );

		return work().finally( function () {
			setBusy( false );
		} );
	}

	/**
	 * Turns a failed request into something worth showing a reader.
	 *
	 * Two rules, and both are about the page rather than about what was being done,
	 * which is why they are here and not written out at each caller:
	 *
	 * A nonce which is no longer accepted means the page has been open longer than
	 * the nonce lives — 12 to 24 hours — and nothing it sends will be accepted until
	 * it is reloaded. Core's own English for that arrives untranslated, so it is
	 * replaced rather than appended to.
	 *
	 * A timeout carries the word `timeout` as its message, which is a marker for
	 * this code and was never meant to be read by anybody, so the tail is dropped.
	 *
	 * @param error    The rejected request's error.
	 * @param fallback What to say when neither rule applies.
	 *
	 * @return The message to show.
	 */
	function describeError( error: RequestError, fallback: string ): string {
		if ( error.isTimeout ) {
			return fallback;
		}

		if (
			403 === error.status &&
			'rest_cookie_invalid_nonce' === error.code
		) {
			// About the page rather than about what it was doing, so it names none.
			return strings.reloadNeeded;
		}

		return fallback + ' ' + error.message;
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
		const label = controlLabel( control );

		/*
		 * One message per save, which reports itself and then settles into what
		 * became of it. Two settings changed one after the other therefore leave
		 * two messages on screen, each naming its own setting — which is the whole
		 * reason they carry the setting's name.
		 */
		const notice = notices.notify( fill( strings.saving, label ), 'busy' );

		locked( function () {
			return request< OptionResponse >(
				'POST',
				'option',
				REQUEST_TIMEOUT_MS,
				{
					name,
					value,
				}
			);
		} )
			.then( function ( payload ) {
				control.dataset.igshPrevious =
					payload && payload.value ? payload.value : value;

				writeControl( control, control.dataset.igshPrevious );

				/*
				 * Read after the control has been put back, so the message describes
				 * what is stored rather than what was sent. The two agree today — the
				 * REST layer answers 400 for a value the setting does not accept rather
				 * than quietly substituting one — but this is the honest order.
				 */
				notice.settle( savedMessage( control, label ), 'success' );
			} )
			.catch( function ( error: RequestError ) {
				/*
				 * A save which timed out may still have been saved: the request was
				 * abandoned, not cancelled, and the site may well have written it after
				 * the page stopped listening. So the control goes back to what it showed
				 * before, as it does for any other failure, and the message says that
				 * what is on screen may no longer be what is stored. That is more than
				 * `describeError()` can say for a timeout, which is why this one is
				 * answered here and everything else is answered there.
				 */
				const message = error.isTimeout
					? fill( strings.saveTimedOut, label )
					: describeError( error, fill( strings.saveFailed, label ) );

				/*
				 * `init()` records the value before anything can change it, so there is
				 * always something to go back to. Where there is not, the control is
				 * left showing what the user chose rather than being blanked — putting
				 * a select back to nothing would be a worse answer than leaving it.
				 */
				if ( undefined !== previous ) {
					writeControl( control, previous );
				}

				notice.settle( message, 'error' );
			} )
			.finally( function () {
				/*
				 * Whichever way the save went. On success the control may have come back
				 * holding the stored value rather than the one sent, and on failure it has
				 * been put back to what it was; the preview shows what the control shows
				 * either way.
				 */
				syncPreview();
			} );
	}

	/**
	 * Rereads the themes on disk and repaints the dropdown from the answer.
	 *
	 * The list is a directory reading cached for a week, so this is the way to see
	 * a theme which has only just been put there — or to lose one which is no
	 * longer readable. The answer carries the list rather than a "done", because
	 * the only thing worth knowing is what is in it now.
	 *
	 * The stored theme keeps its place even where the answer no longer offers it.
	 * A `select` whose value matches no option shows the first one instead, and a
	 * dropdown quietly showing a theme other than the one in the database is a
	 * worse answer than one showing a theme which has gone missing.
	 *
	 * @param button  The refresh button.
	 * @param control The theme control.
	 */
	function refreshThemes(
		button: HTMLButtonElement,
		control: HTMLSelectElement
	): void {
		const notice = notices.notify( strings.themesRefreshing, 'busy' );

		button.classList.add( 'is-busy' );

		locked( function () {
			return request< ThemesResponse >(
				'POST',
				'themes',
				REQUEST_TIMEOUT_MS
			);
		} )
			.then( function ( payload ) {
				const choices = payload ? payload.choices : undefined;
				const urls = payload ? payload.urls : undefined;

				if ( ! choices ) {
					notice.settle( strings.themesRefreshFail, 'error' );

					return;
				}

				/*
				 * Below the guard, and it has to be. An answer carrying the URLs but
				 * not the list leaves the dropdown alone, as documented — so replacing
				 * the map the preview paints from would leave the two describing
				 * different lists while the reader was told nothing had changed.
				 */
				if ( urls ) {
					adminConfig.themes = urls;
				}

				const previous = control.value;
				const values = Object.keys( choices );

				/*
				 * The option showing the stored theme, so it can be kept where the
				 * rebuilt list no longer offers it. Values are unique in a dropdown, so
				 * asking the browser for it says in one line what a loop said in five.
				 */
				const kept = control.querySelector< HTMLOptionElement >(
					'option[value="' + CSS.escape( previous ) + '"]'
				);

				while ( control.firstChild ) {
					control.removeChild( control.firstChild );
				}

				if ( kept && ! values.includes( previous ) ) {
					control.appendChild( kept );
				}

				values.forEach( function ( value ) {
					const option = document.createElement( 'option' );

					option.value = value;
					option.textContent = choices[ value ] ?? value;

					control.appendChild( option );
				} );

				control.value = previous;

				/*
				 * Without the `none` entry, which `Admin::get_theme_choices()` puts on
				 * the front and which is not a theme. The count is there so a site owner
				 * can check the answer against what is in the directory, and one they
				 * cannot check is worse than none at all.
				 */
				notice.settle(
					withCount(
						strings.themesRefreshed,
						Math.max( 0, values.length - 1 )
					),
					'success'
				);
			} )
			.catch( function ( error: RequestError ) {
				notice.settle(
					describeError( error, strings.themesRefreshFail ),
					'error'
				);
			} )
			.finally( function () {
				button.classList.remove( 'is-busy' );

				/*
				 * The page lock disabled this button, and a disabled element loses focus
				 * to `<body>`. This is the button somebody is most likely to press twice,
				 * so a keyboard user gets it back rather than having to tab to it again.
				 * Guarded, because a reader who moved on to another control in the
				 * meantime should not be dragged back here.
				 */
				const owner = button.ownerDocument;

				if ( ! button.disabled && owner.body === owner.activeElement ) {
					button.focus();
				}

				/*
				 * The preview is painted from `config.themes`, which has just been
				 * replaced, and the control may now be showing a theme which was not
				 * there a moment ago.
				 */
				syncPreview();
			} );
	}

	/**
	 * Points a stylesheet link at a URL, building the link where there is none.
	 *
	 * The tag is the one `Asset_Manager` enqueued, found by the id PHP sent over. A
	 * site whose setting is "None" has no such tag, because nothing was enqueued to
	 * make one, so the first value picked builds it — and picking "None" again empties
	 * it rather than removing it, which keeps the id in the document for the next
	 * change to find.
	 *
	 * An empty `href` on a stylesheet link loads nothing, which is what "None" means.
	 * Removing the attribute would make the browser resolve the page's own URL and
	 * fetch the settings page as a stylesheet.
	 *
	 * @param id   Element id of the link tag.
	 * @param href URL it should point at, or an empty string for "load nothing".
	 */
	function ensureStylesheet( id: string, href: string ): void {
		let link = document.getElementById( id ) as HTMLLinkElement | null;

		if ( ! link ) {
			if ( '' === href ) {
				return;
			}

			link = document.createElement( 'link' );
			link.id = id;
			link.rel = 'stylesheet';

			document.head.appendChild( link );
		}

		link.href = href;
	}

	/**
	 * Points the theme stylesheet at the theme now chosen.
	 *
	 * @param theme Value the theme control now holds.
	 */
	function applyPreviewTheme( theme: string ): void {
		const themes = adminConfig.themes;
		const id = adminConfig.themeStyleId;

		if ( ! themes || ! id || ! ( theme in themes ) ) {
			return;
		}

		ensureStylesheet( id, themes[ theme ] ?? '' );
	}

	/**
	 * Puts the font now chosen on the preview box.
	 *
	 * Two steps, because fetching a family does not put it on anything. The
	 * stylesheet is the same routine the theme uses. The rule then goes into a
	 * `style` tag of the preview's own,
	 * appended to the head so that it comes after everything wp-admin enqueued and
	 * wins at the same specificity without `!important`.
	 *
	 * The rule itself is built by `Asset_Manager` and sent over, never assembled
	 * here: the preview has to apply a font exactly as the front end does, and two
	 * pieces of code building that rule would be two chances to disagree.
	 *
	 * @param font Value the font control now holds.
	 */
	function applyPreviewFont( font: string ): void {
		const fonts = adminConfig.fonts;
		const id = adminConfig.fontStyleId;

		if ( ! fonts || ! id || ! ( font in fonts ) ) {
			return;
		}

		const chosen = fonts[ font ];

		ensureStylesheet( id, chosen?.url ?? '' );

		let rule = document.getElementById( PREVIEW_FONT_STYLE_ID );

		if ( ! rule ) {
			rule = document.createElement( 'style' );
			rule.id = PREVIEW_FONT_STYLE_ID;

			document.head.appendChild( rule );
		}

		rule.textContent = chosen?.css ?? '';
	}

	/**
	 * Draws the preview box with or without line numbers.
	 *
	 * Line numbers are markup, not styling: Prism's plugin builds a row of numbers
	 * when it highlights a box carrying the class. So switching them on means adding
	 * the class and asking Prism to go over the box again, and switching them off
	 * means taking the class off and taking the rows out.
	 *
	 * Verified against the vendored plugin rather than assumed: its `complete` hook
	 * builds the rows only where the class is active and no rows are there already,
	 * so a second pass over a box which has them cannot produce a second set. The
	 * toolbar plugin guards the same way against wrapping a box twice.
	 *
	 * @param box  The `pre` element of the preview.
	 * @param show Whether line numbers are wanted.
	 */
	function applyPreviewLineNumbers( box: HTMLElement, show: boolean ): void {
		const LINE_NUMBERS = 'line-numbers';

		if ( ! show ) {
			box.classList.remove( LINE_NUMBERS );

			box.querySelector( '.line-numbers-rows' )?.remove();

			return;
		}

		if ( box.classList.contains( LINE_NUMBERS ) ) {
			return;
		}

		box.classList.add( LINE_NUMBERS );

		const code = box.querySelector( 'code' );
		const prism = window.Prism;

		if ( code && prism?.highlightElement ) {
			prism.highlightElement( code );
		}
	}

	/**
	 * Puts the preview in step with every control which changes how a box looks.
	 *
	 * Called whenever one of those controls moves and again once a save has settled,
	 * because a save which fails puts its control back and the preview has to go back
	 * with it. It reads the controls rather than being told what changed, so there is
	 * one description of what a code box looks like and not one per control.
	 *
	 * The toolbar and the copy button are hidden with a class instead of being
	 * unloaded, which is the difference between the preview and a front end page: the
	 * front end knows what it needs before it loads anything, and this page has to be
	 * able to show both answers without a reload.
	 */
	function syncPreview(): void {
		const preview = document.getElementById( 'igsh-preview' );

		if ( ! preview ) {
			return;
		}

		const theme = controlValue( 'theme' );

		if ( null !== theme ) {
			applyPreviewTheme( theme );
		}

		const font = controlValue( 'font' );

		if ( null !== font ) {
			applyPreviewFont( font );
		}

		preview.classList.toggle(
			'igsh-no-toolbar',
			'yes' !== controlValue( 'toolbar' )
		);

		preview.classList.toggle(
			'igsh-preview--no-copy',
			'yes' !== controlValue( 'copy_code' )
		);

		/*
		 * `match-braces` itself is on the container from PHP and stays there: the
		 * engine reads it once, while it is highlighting, so a box which did not
		 * carry it at load can never gain the brace markup afterwards. These three
		 * are read at paint time and at event time instead, which is what lets them
		 * switch in front of the reader.
		 *
		 * The two `no-brace-*` classes are how the front end keeps the colours and
		 * the interaction separate, and the preview has to say the same thing: the
		 * plugin defaults both interactions on, and only those names turn them off.
		 */
		const matching = 'yes' === controlValue( 'match_braces' );

		preview.classList.toggle(
			'rainbow-braces',
			'yes' === controlValue( 'rainbow_braces' )
		);

		preview.classList.toggle( 'no-brace-hover', ! matching );
		preview.classList.toggle( 'no-brace-select', ! matching );

		const box = preview.querySelector< HTMLElement >(
			'pre[class*="language-"]'
		);

		if ( box ) {
			applyPreviewLineNumbers(
				box,
				'yes' === controlValue( 'show_line_numbers' )
			);
		}
	}

	/**
	 * Reads what one of the settings controls is showing.
	 *
	 * The control is the answer, not the stored value: the preview is about what the
	 * page is showing at this moment, which is what the reader is looking at.
	 *
	 * @param name Setting to read.
	 *
	 * @return Its value, or NULL when the screen has no such control.
	 */
	function controlValue( name: string ): string | null {
		const control = document.querySelector< OptionControl >(
			'[data-igsh-option="' + name + '"]'
		);

		return control ? readControl( control ) : null;
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

		status.textContent = strings.revertRunning;

		/*
		 * One lock over the whole run, and not one per request: the reader must not
		 * get the page back between two batches of a conversion which is still going.
		 */
		locked( function () {
			return request< RevertState >(
				'GET',
				'revert',
				REQUEST_TIMEOUT_MS
			).then( function ( state ) {
				const total = state && state.total ? state.total : 0;

				if ( ! total ) {
					status.textContent = strings.revertNone;

					return null;
				}

				meter.max = total;
				meter.value = 0;
				progress.hidden = false;

				return nextBatch( 0 );
			} );
		} ).catch( function ( error: RequestError ) {
			status.textContent = describeError( error, strings.revertFailed );
		} );

		/**
		 * Fetches and applies one batch, then the next.
		 *
		 * @param cursor Id of the last post already handled.
		 *
		 * @return Resolved once there is nothing left to do.
		 */
		function nextBatch( cursor: number ): Promise< null > {
			return request< RevertBatch >(
				'POST',
				'revert',
				REVERT_TIMEOUT_MS,
				{
					cursor,
				}
			).then( function ( batch ) {
				const processed = readCount( batch.processed );

				addCount( totals, 'processed', batch.processed );
				addCount( totals, 'converted', batch.converted );
				addCount( totals, 'skipped', batch.skipped );
				addCount( totals, 'failed', batch.failed );
				addCount( totals, 'blocksLeftAlone', batch.blocks_left_alone );

				meter.value = Math.min( totals.processed, meter.max );

				status.textContent = strings.revertRunning;

				// An answer which does not say how much it did is the end of the run: there is nothing to carry on from.
				if ( batch.done || ! processed ) {
					reportRevert( status, totals );

					meter.value = meter.max;

					return null;
				}

				return nextBatch( batch.cursor ?? 0 );
			} );
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

					/*
					 * The preview follows the control and not the save: a reader who has
					 * just clicked wants to see the result now, and the save may take as
					 * long as the site takes to answer. A save which fails puts the control
					 * back and `saveSetting()` syncs the preview again behind it.
					 */
					syncPreview();

					saveSetting( control );
				} );

				return;
			}

			control.addEventListener( 'change', function () {
				syncPreview();

				saveSetting( control );
			} );
		} );

		syncPreview();

		const refresh = document.getElementById(
			'igsh-refresh-themes'
		) as HTMLButtonElement | null;
		const themeControl = document.querySelector< HTMLSelectElement >(
			'select[data-igsh-option="theme"]'
		);

		if ( refresh && themeControl ) {
			refresh.addEventListener( 'click', function () {
				refreshThemes( refresh, themeControl );
			} );
		}

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
