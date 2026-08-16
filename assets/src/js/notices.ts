/**
 * A stack of short lived messages, top right of the screen.
 *
 * Deliberately knows nothing about settings, about REST, or about any screen
 * this plugin draws: it is handed a string and a tone, and it puts that string
 * on screen in the colour that tone calls for. The settings page is its only
 * caller today. It is a script of its own so that the next thing needing to say
 * something to a site owner does not grow a second copy of this.
 *
 * A classic script, like everything else in this directory: no modules, no
 * bundler, no jQuery. `assets/src/js/` is compiled by `tsc` with `module: none`,
 * so there is no `import` to share this by — it publishes one global,
 * `window.igshNotices`, and takes no dependencies of any kind.
 *
 * Its styles are `assets/src/scss/notices.scss`, which is a stylesheet of its own
 * for the same reason.
 */

( function () {
	'use strict';

	/**
	 * How long a message stays on screen, by tone, in milliseconds.
	 *
	 * `busy` is zero, meaning it stays until `settle()` replaces it — it reports
	 * something still happening, and taking it away while it is still true would
	 * say the opposite of what it means.
	 *
	 * A failure is left up more than twice as long as a success. A success
	 * confirms something the reader just did and already expected; a failure tells
	 * them something they did not expect and may have to act on.
	 */
	const LINGER_MS: Record< IgshNoticeTone, number > = {
		busy: 0,
		success: 2500,
		error: 6000,
	};

	/**
	 * How long the leaving animation runs, in milliseconds.
	 *
	 * **Must match `$igsh-notice-fade` in `assets/src/scss/notices.scss`.** The
	 * element is taken out of the document when this has elapsed, and removing it
	 * early would cut the animation off part way.
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
	 * One live region for the whole stack rather than one per message: a live
	 * region has to be in the document *before* text arrives inside it for a
	 * screen reader to announce that text. So the region is the part that
	 * persists, and each message is something added to it.
	 *
	 * `isConnected` is checked rather than trusted, because anything at all may
	 * have replaced the body of the page in between two calls.
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

			/*
			 * A caller which settles a message that has already gone gets it back
			 * rather than nothing at all. Nothing in this plugin does that today —
			 * a `busy` message never leaves on its own — but this is a published
			 * API now, and losing a message silently is the worst way to fail.
			 */
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

		/*
		 * Newest first. The stack is a flex column, so prepending is the whole of
		 * "the latest message is on top and the ones before it move down" — no
		 * measuring, no repositioning, nothing to keep in step.
		 */
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

//EOF
