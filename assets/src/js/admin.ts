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

	const config = window.igSyntaxHiliterAdmin;

	if ( ! config || ! config.restUrl ) {
		return;
	}

	const publishedApi = window.igshAdminApi;

	if ( ! publishedApi ) {
		return;
	}

	/*
	 * Read once here; the functions below are hoisted above the guard, so TS
	 * will not carry the narrowing into them.
	 */
	const api = publishedApi;

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
			return api.fill(
				'yes' === readControl( control )
					? strings.savedOn
					: strings.savedOff,
				label
			);
		}

		const choice = choiceLabel( control );

		if ( '' === choice ) {
			return api.fill( strings.saved, label );
		}

		return api.fill( strings.savedChoice, label, choice );
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
				api.fill(
					'yes' === stored
						? strings.savedAlsoOn
						: strings.savedAlsoOff,
					controlLabel( other )
				);
		} );

		return message;
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
		const notice = notices.notify(
			api.fill( strings.saving, label ),
			'busy'
		);

		api.locked( function () {
			return api.request< OptionResponse >(
				'POST',
				'option',
				api.REQUEST_TIMEOUT_MS,
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
			.catch( function ( error: IgshRequestError ) {
				/*
				 * A timed-out save may still have been written, so the control is
				 * reverted and the message says what is on screen may not be what is
				 * stored.
				 */
				const message = error.isTimeout
					? api.fill( strings.saveTimedOut, label )
					: api.describeError(
							error,
							api.fill( strings.saveFailed, label )
					  );

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

		api.locked( function () {
			return api.request< ThemesResponse >(
				'POST',
				'themes',
				api.REQUEST_TIMEOUT_MS
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
					api.withCount(
						strings.themesRefreshed,
						Math.max( 0, values.length - 1 )
					),
					'success'
				);
			} )
			.catch( function ( error: IgshRequestError ) {
				notice.settle(
					api.describeError( error, strings.themesRefreshFail ),
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
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();

// EOF
