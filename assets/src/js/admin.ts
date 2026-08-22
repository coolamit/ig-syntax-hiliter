/**
 * Settings page behaviour for the iG:Syntax Hiliter plugin.
 */

( function () {
	'use strict';

	/**
	 * A control standing for one setting: a `role="switch"` button or a `<select>`.
	 */
	type OptionControl = HTMLButtonElement | HTMLSelectElement;

	/**
	 * What a save answers with.
	 */
	interface OptionResponse {
		value?: string | undefined;

		// Settings moved alongside the saved one, name → stored value.
		also?: Record< string, string > | undefined;
	}

	/**
	 * What the theme refresh answers with.
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

	// Read once after the guard; the narrowing does not reach hoisted functions.
	const api = publishedApi;

	const adminConfig = config;

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

	const PREVIEW_FONT_STYLE_ID = 'igsh-preview-font';

	/**
	 * The name of a setting, read off its `<label for>`.
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
	 * `igshPrevious` moves with the control, or the next failed save restores a
	 * value nobody holds.
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
	 * One notice per save.
	 *
	 * @param control Control which changed.
	 */
	function saveSetting( control: OptionControl ): void {
		const name = control.dataset.igshOption;
		const value = readControl( control );
		const previous = control.dataset.igshPrevious;
		const label = controlLabel( control );

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

				notice.settle(
					savedMessage( control, label ) +
						applyAlso( payload && payload.also ),
					'success'
				);
			} )
			.catch( function ( error: IgshRequestError ) {
				// A timed-out save may still have been written.
				const message = error.isTimeout
					? api.fill( strings.saveTimedOut, label )
					: api.describeError(
							error,
							api.fill( strings.saveFailed, label )
					  );

				if ( undefined !== previous ) {
					writeControl( control, previous );
				}

				notice.settle( message, 'error' );
			} )
			.finally( function () {
				syncPreview();
			} );
	}

	/**
	 * Rereads the themes on disk and repaints the dropdown from the answer.
	 *
	 * The stored theme keeps its option when the rebuilt list no longer offers
	 * it.
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

				// Replace the URL map only when the list came too.
				if ( urls ) {
					adminConfig.themes = urls;
				}

				const previous = control.value;
				const values = Object.keys( choices );

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

				// Excludes the `none` entry.
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

				// Focus is given back only if the reader has not moved on.
				const owner = button.ownerDocument;

				if ( ! button.disabled && owner.body === owner.activeElement ) {
					button.focus();
				}

				syncPreview();
			} );
	}

	/**
	 * Points a stylesheet link at a URL, building the link where there is none.
	 *
	 * An empty `href` loads nothing; removing the attribute would fetch the page
	 * as a stylesheet.
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
	 * Fetching a family does not apply it; the rule comes from PHP so the
	 * preview matches the front end.
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
	 * The rows are markup Prism builds, so the box is re-highlighted, through
	 * `highlightElement()` because its `before-sanity-check` hook clears the band.
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
	 * Reads the controls, not the stored values.
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

		// `match-braces` is set by PHP and read once at highlight time; these three
		// are read at paint time.
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
	 * @return Its control, or NULL when the screen has no such control.
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

			// A switch is a button, so it reports a click; a `<select>` flips itself.
			if ( isToggle( control ) ) {
				control.addEventListener( 'click', function () {
					writeControl(
						control,
						'yes' === readControl( control ) ? 'no' : 'yes'
					);

					// Preview follows the control, not the save.
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
