/**
 * Settings page behaviour for the iG:Syntax Hiliter plugin.
 *
 * No jQuery: fetch to the plugin's REST routes plus a live region.
 */

( function () {
	'use strict';

	/**
	 * A control standing for one setting.
	 *
	 * A yes/no setting is a `role="switch"` button, a choice is a `<select>`.
	 */
	type OptionControl = HTMLButtonElement | HTMLSelectElement;

	/**
	 * An error carrying what this code knows about a failed request.
	 *
	 * Every member is optional — a network failure gives a plain `Error`.
	 */
	interface RequestError extends Error {
		status?: number | undefined;
		code?: string | undefined;
		isTimeout?: boolean | undefined;
	}

	/**
	 * What a save answers with.
	 *
	 * The stored value, which the control is put back to.
	 */
	interface OptionResponse {
		value?: string | undefined;

		// Settings the route moved alongside the saved one, name → stored value.
		also?: Record< string, string > | undefined;
	}

	/**
	 * What the theme refresh answers with.
	 *
	 * Both optional; an answer missing `choices` must leave the dropdown alone.
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
	 * Counts are `unknown` because an answer may carry no number; see
	 * `readCount()`.
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
	 * Excludes `partial`, which is a flag, so `addCount()` cannot reach it.
	 */
	type RevertCountName = Exclude< keyof RevertTotals, 'partial' >;

	const config = window.igSyntaxHiliterAdmin;

	if ( ! config || ! config.restUrl ) {
		return;
	}

	/*
	 * Read once here; `request()` is hoisted above the guard, so TS will not
	 * carry the narrowing into it.
	 */
	const restUrl = config.restUrl;

	/*
	 * Read at call time, not closed over: core returns a fresh nonce on every
	 * successful cookie-auth REST response and `request()` stores it. An expired
	 * one is answered by the 403 branch.
	 */
	let nonce = config.nonce;

	// Not copied by value — the refresh button replaces `themes`.
	const adminConfig = config;

	// Fallback keeps the page saving if PHP stopped sending strings.
	const strings: IgshAdminStrings = config.i18n || ( {} as IgshAdminStrings );

	// No-op fallback for a dequeued `notices.js`.
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

	// Every request locks the page, so a timeout is required.
	const REQUEST_TIMEOUT_MS = 15000;

	// Longer: a batch rewrites up to 200 posts.
	const REVERT_TIMEOUT_MS = 60000;

	// The preview's own style tag; not enqueued by PHP.
	const PREVIEW_FONT_STYLE_ID = 'igsh-preview-font';

	/**
	 * The name of a setting, as the screen already shows it.
	 *
	 * Read off the `<label for>`, so one translated string serves both.
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
	 * A toggle has no options, so it gets no name.
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
	 * `%1$s` by position, bare `%s` in order, matching PHP `sprintf()`. A
	 * placeholder with no value is left standing.
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
	 * Timeout is required and has no default — the page is locked for the whole
	 * request. The clock is stopped whichever way it ends. No AbortController
	 * means no timeout.
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
		 * A fetch throwing synchronously would never reach the caller's `.catch()`
		 * and the page would stay locked.
		 */
		try {
			pending = window.fetch( restUrl + route, options );
		} catch ( error ) {
			stopClock();

			return window.Promise.reject( error );
		}

		return pending
			.then( function ( response ) {
				// Only an accepted nonce yields a fresh one; read whatever the status.
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
					 * An abort we asked for is rethrown as a timeout so callers need not
					 * match on `AbortError`.
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
	 * The `instanceof` is what narrows the type for TypeScript.
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
	 * A switch's value is `aria-checked`, so what the screen reader is told and
	 * what is saved are the same fact.
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
	 * Names the setting and what it became; the notice takes the reader's eye
	 * off the control.
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
	 * Puts the controls of any settings which moved with the saved one right, and
	 * says what moved.
	 *
	 * A dependent setting is moved by the same request, so one save can change
	 * two controls. `dataset.igshPrevious` moves with it, or the next failed save
	 * would restore a value nobody holds.
	 *
	 * @param also Setting name to its stored value, or nothing.
	 *
	 * @return A sentence to append to the save message, empty when nothing moved.
	 */
	function applyAlso( also: Record< string, string > | undefined ): string {
		if ( ! also ) {
			return '';
		}

		let message = '';

		Object.keys( also ).forEach( function ( name ) {
			const stored = also[ name ];
			const other = controlNamed( name );

			// Not faults: a setting may be stored and not shown, and this is the
			// JSON boundary.
			if ( ! other || 'string' !== typeof stored ) {
				return;
			}

			other.dataset.igshPrevious = stored;

			writeControl( other, stored );

			message +=
				' ' +
				fill(
					'yes' === stored
						? strings.savedAlsoOn
						: strings.savedAlsoOff,
					controlLabel( other )
				);
		} );

		return message;
	}

	/**
	 * Locks or unlocks the whole page for the length of a request.
	 *
	 * All settings live in one stored array, so two overlapping saves silently
	 * undo each other; locking every control is what makes a second request
	 * impossible. Unlocking restores exactly the elements this disabled.
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
	 * One lock per interaction, not per request — the revert is one lock over a
	 * GET and every POST batch.
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
	 * A 403 + `rest_cookie_invalid_nonce` means the page has outlived the nonce
	 * and must be reloaded; core's English arrives untranslated so it is
	 * replaced. A timeout's message is an internal marker and is never shown.
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

		// One notice per save, so two saves leave two messages each naming its
		// own setting.
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

				// Read after the control is restored, so the message describes what
				// is stored.
				notice.settle(
					savedMessage( control, label ) +
						applyAlso( payload && payload.also ),
					'success'
				);
			} )
			.catch( function ( error: RequestError ) {
				/*
				 * A timed-out save may still have been written, so the control is
				 * reverted and the message says what is on screen may not be what is
				 * stored.
				 */
				const message = error.isTimeout
					? fill( strings.saveTimedOut, label )
					: describeError( error, fill( strings.saveFailed, label ) );

				// `init()` records the value first; where there is none the control
				// keeps what the user chose.
				if ( undefined !== previous ) {
					writeControl( control, previous );
				}

				notice.settle( message, 'error' );
			} )
			.finally( function () {
				// Preview follows the control whichever way the save went.
				syncPreview();
			} );
	}

	/**
	 * Rereads the themes on disk and repaints the dropdown from the answer.
	 *
	 * The directory listing is cached for a week. The stored theme keeps its
	 * option even when the answer no longer offers it — a `select` with no
	 * matching option shows the first one instead.
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

				// Below the guard: replacing the URL map when the list was not sent
				// would leave the two describing different lists.
				if ( urls ) {
					adminConfig.themes = urls;
				}

				const previous = control.value;
				const values = Object.keys( choices );

				// The option showing the stored theme, kept where the rebuilt list no
				// longer offers it.
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

				// Excludes the `none` entry, which is not a theme.
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

				// A disabled element loses focus to `<body>`; give it back only if the
				// reader has not moved on.
				const owner = button.ownerDocument;

				if ( ! button.disabled && owner.body === owner.activeElement ) {
					button.focus();
				}

				// `config.themes` has just been replaced.
				syncPreview();
			} );
	}

	/**
	 * Points a stylesheet link at a URL, building the link where there is none.
	 *
	 * The link is `Asset_Manager`'s, found by the id PHP sent; a site on "None"
	 * has none, so the first pick builds it. An empty `href` loads nothing —
	 * removing the attribute would make the browser fetch the settings page as
	 * a stylesheet.
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
	 * Two steps: fetching a family does not apply it. The rule itself is built
	 * by `Asset_Manager` and sent over, so the preview applies a font exactly as
	 * the front end does.
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
	 * Line numbers are markup, not styling: Prism's plugin builds the rows when
	 * it highlights. Switching off means removing the class and the rows, then
	 * re-highlighting — the line-highlight plugin positions its band differently
	 * with and without numbers. `highlightElement()` rather than the plugin
	 * directly, because its `before-sanity-check` hook clears the old band.
	 *
	 * @param box  The `pre` element of the preview.
	 * @param show Whether line numbers are wanted.
	 */
	function applyPreviewLineNumbers( box: HTMLElement, show: boolean ): void {
		const LINE_NUMBERS = 'line-numbers';

		if ( show === box.classList.contains( LINE_NUMBERS ) ) {
			return;
		}

		if ( show ) {
			box.classList.add( LINE_NUMBERS );
		} else {
			box.classList.remove( LINE_NUMBERS );

			box.querySelector( '.line-numbers-rows' )?.remove();
		}

		const code = box.querySelector( 'code' );
		const prism = window.Prism;

		if ( code && prism?.highlightElement ) {
			prism.highlightElement( code );
		}
	}

	/**
	 * Puts the preview in step with every control which changes how a box looks.
	 *
	 * Reads the controls rather than being told what changed, so there is one
	 * description of a code box. The toolbar and copy button are hidden with a
	 * class rather than unloaded, because this page must show both answers
	 * without a reload.
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
		 * `match-braces` is set by PHP and read once at highlight time; these three
		 * are read at paint time so they can switch live. Both interactions default
		 * on — only the `no-brace-*` classes turn them off.
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
	 * Reads the control, not the stored value: the preview is about what is on
	 * screen.
	 *
	 * @param name Setting to read.
	 *
	 * @return Its value, or NULL when the screen has no such control.
	 */
	function controlValue( name: string ): string | null {
		const control = controlNamed( name );

		return control ? readControl( control ) : null;
	}

	/**
	 * Finds the control which stands for a setting.
	 *
	 * @param name Setting to find.
	 *
	 * @return Its control, or NULL: a setting may be stored and not shown, so
	 *         NULL is a real answer.
	 */
	function controlNamed( name: string ): OptionControl | null {
		return document.querySelector< OptionControl >(
			'[data-igsh-option="' + name + '"]'
		);
	}

	/**
	 * Fills a translated string's count into it.
	 *
	 * Every `%d`/`%1$d` is replaced; a string with neither gets the count
	 * appended.
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
	 * Built as a node so no translated string can carry markup.
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
	 * Takes the same lock a save takes, but has no timeout: a run lasts as long
	 * as the site has posts.
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
		// eslint-disable-next-line no-alert -- rewrites post_content site-wide and cannot be undone; a custom dialog would be one more thing to get wrong on a page that loads no libraries.
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
			 * A switch is a button, so it reports a click; a `<select>` flips itself.
			 * Space and Enter arrive as clicks.
			 */
			if ( isToggle( control ) ) {
				control.addEventListener( 'click', function () {
					writeControl(
						control,
						'yes' === readControl( control ) ? 'no' : 'yes'
					);

					// Preview follows the control, not the save; a failed save reverts
					// both.
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

// EOF
