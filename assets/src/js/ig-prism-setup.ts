/**
 * Front end setup for iG:Syntax Hiliter.
 *
 * Loads after Prism and its plugins and before Prism highlights on
 * DOMContentLoaded. Vanilla JS, no jQuery.
 */
( function () {
	'use strict';

	const Prism = window.Prism;

	if ( ! Prism ) {
		return;
	}

	const settings: IgshFrontendSettings = window.igSyntaxHiliter || {};
	const plugins = Prism.plugins || {};

	if ( plugins.autoloader && settings.componentsUrl ) {
		plugins.autoloader.languages_path = settings.componentsUrl;
	}

	/**
	 * Is this code box, or anything containing it, opted out of the toolbar?
	 *
	 * @param element Element to test.
	 * @return True when the toolbar should not be shown.
	 */
	function isToolbarOptedOut( element: Element | null ): boolean {
		if ( ! element || ! element.closest ) {
			return false;
		}

		return null !== element.closest( '.igsh-no-toolbar' );
	}

	if (
		plugins.toolbar &&
		'function' === typeof plugins.toolbar.registerButton
	) {
		plugins.toolbar.registerButton( 'igsh-file-label', function ( env ):
			| Element
			| undefined {
			/*
			 * `parentElement` rather than `parentNode`: the check below only ever
			 * accepts a `<pre>`, which is an element, so the two agree on every
			 * input that gets past it — and this one says so in its type.
			 */
			const pre = env.element ? env.element.parentElement : null;

			if ( ! pre || 'pre' !== pre.nodeName.toLowerCase() ) {
				return;
			}

			if ( isToolbarOptedOut( pre ) ) {
				return;
			}

			const label = pre.getAttribute( 'data-file' );

			if ( ! label ) {
				return;
			}

			const item = document.createElement( 'span' );

			item.className = 'igsh-file-label';
			item.textContent = label;
			item.setAttribute(
				'title',
				( settings.fileLabel ? settings.fileLabel + ': ' : '' ) + label
			);

			return item;
		} );
	}
} )();
