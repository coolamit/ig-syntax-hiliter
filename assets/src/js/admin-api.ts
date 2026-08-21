/**
 * Shared transport and page lock for the settings page scripts.
 *
 * Publishes `window.igshAdminApi`, which `admin.js` and `revert.js` read.
 */

( function () {
	'use strict';

	/**
	 * A control standing for one setting: a `role="switch"` button or a `<select>`.
	 */
	type OptionControl = HTMLButtonElement | HTMLSelectElement;

	const config = window.igSyntaxHiliterAdmin;

	if ( ! config || ! config.restUrl ) {
		return;
	}

	// Read once after the guard; the narrowing does not reach hoisted functions.
	const restUrl = config.restUrl;

	// Re-read at call time: core returns a fresh nonce on every response.
	let nonce = config.nonce;

	const strings: IgshAdminStrings = config.i18n || ( {} as IgshAdminStrings );

	let busyElements: OptionControl[] = [];
	let pageBusy = false;

	// Required: the page is locked for the whole request.
	const REQUEST_TIMEOUT_MS = 15000;

	/**
	 * Fills a translated string's placeholders in.
	 *
	 * `%1$s` by position, bare `%s` in order, as `sprintf()`; a placeholder with
	 * no value is left standing.
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
	 * The timeout is required: the page is locked for the whole request.
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
		 * Stops the clock.
		 */
		function stopClock(): void {
			if ( null !== timer ) {
				window.clearTimeout( timer );

				timer = null;
			}
		}

		// A fetch that throws synchronously would leave the page locked.
		try {
			pending = window.fetch( restUrl + route, options );
		} catch ( error ) {
			stopClock();

			return window.Promise.reject( error );
		}

		return pending
			.then( function ( response ) {
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

						const error: IgshRequestError = new Error(
							( payload && payload.message ) ||
								response.statusText
						);

						error.status = response.status;
						error.code = payload ? payload.code : undefined;

						throw error;
					},
					function () {
						const error: IgshRequestError = new Error(
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
				function ( error: IgshRequestError ) {
					stopClock();

					// Our abort is rethrown as a timeout; callers need not match `AbortError`.
					if ( expired ) {
						const timedOut: IgshRequestError = new Error(
							'timeout'
						);

						timedOut.isTimeout = true;

						throw timedOut;
					}

					throw error;
				}
			);
	}

	/**
	 * Locks or unlocks the whole page for the length of a request.
	 *
	 * All settings are one stored array, so two overlapping saves silently undo
	 * each other.
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
	 * One lock per interaction, not per request.
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
	 * 403 + `rest_cookie_invalid_nonce` means the page outlived the nonce; core's
	 * message arrives untranslated.
	 *
	 * @param error    The rejected request's error.
	 * @param fallback What to say when neither rule applies.
	 *
	 * @return The message to show.
	 */
	function describeError(
		error: IgshRequestError,
		fallback: string
	): string {
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
	 * Fills a translated string's count into it.
	 *
	 * Every `%d`/`%1$d`; a string with neither gets the count appended.
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

	window.igshAdminApi = {
		request,
		locked,
		describeError,
		fill,
		withCount,
		REQUEST_TIMEOUT_MS,
	};
} )();

// EOF
