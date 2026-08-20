/**
 * A stack of short lived messages, top right of the screen.
 *
 * Knows nothing about settings or REST — it is handed a string and a tone. A
 * classic script publishing `window.igshNotices`; `assets/src/js/` is compiled
 * with `module: none`, so there is no `import` to share it by.
 */

( function () {
	'use strict';

	/**
	 * How long a message stays on screen, by tone, in milliseconds.
	 *
	 * `busy` is zero — it stays until `settle()` replaces it. A failure lingers
	 * longer than a success.
	 */
	const LINGER_MS: Record< IgshNoticeTone, number > = {
		busy: 0,
		success: 2500,
		error: 6000,
	};

	/**
	 * How long the leaving animation runs, in milliseconds.
	 *
	 * Must match `$igsh-notice-fade` in `assets/src/scss/notices.scss` — the
	 * element is removed when this elapses.
	 */
	const FADE_MS = 150;

	/**
	 * Every tone there is, so that `settle()` can clear the ones it is not.
	 */
	const TONES: IgshNoticeTone[] = [ 'busy', 'success', 'error' ];

	let stack: HTMLDivElement | null = null;

	/**
	 * The container every message goes into, made on first use.
	 *
	 * One live region for the whole stack: a region must be in the document
	 * before text arrives in it for a screen reader to announce it.
	 * `isConnected` is rechecked because anything may have replaced the body.
	 */
	function getStack(): HTMLDivElement {
		if ( stack && stack.isConnected ) {
			return stack;
		}

		stack = document.createElement( 'div' );

		stack.className = 'igsh-notices';
		stack.setAttribute( 'role', 'status' );
		stack.setAttribute( 'aria-live', 'polite' );

		document.body.appendChild( stack );

		return stack;
	}

	/**
	 * Puts a message on screen and hands back the handle to it.
	 *
	 * @param message Text to show.
	 * @param tone    What the message says about the thing it reports.
	 *
	 * @return The message, for as long as its caller wants to keep speaking.
	 */
	function notify( message: string, tone: IgshNoticeTone ): IgshNotice {
		const element = document.createElement( 'div' );

		let timer: number | undefined;

		element.className = 'igsh-notice';

		/**
		 * Fades the message out, then takes it out of the document.
		 */
		function dismiss(): void {
			window.clearTimeout( timer );

			element.classList.add( 'igsh-notice--leaving' );

			timer = window.setTimeout( function () {
				element.remove();
			}, FADE_MS );
		}

		/**
		 * Replaces what the message says, and how long it has left.
		 *
		 * @param text     Text to show instead.
		 * @param nextTone Tone to show it in.
		 */
		function settle( text: string, nextTone: IgshNoticeTone ): void {
			window.clearTimeout( timer );

			// A settled message that has already gone is put back rather than lost.
			if ( ! element.isConnected ) {
				getStack().prepend( element );
			}

			element.textContent = String( text || '' );
			element.classList.remove( 'igsh-notice--leaving' );

			TONES.forEach( function ( name ) {
				element.classList.toggle(
					'igsh-notice--' + name,
					name === nextTone
				);
			} );

			if ( 0 < LINGER_MS[ nextTone ] ) {
				timer = window.setTimeout( dismiss, LINGER_MS[ nextTone ] );
			}
		}

		// Newest first; the stack is a flex column, so prepending is all the
		// ordering there is.
		getStack().prepend( element );

		settle( message, tone );

		return {
			settle,
			dismiss,
		};
	}

	window.igshNotices = {
		notify,
	};
} )();

// EOF
