/**
 * Front end setup for iG:Syntax Hiliter.
 *
 * Loads after Prism and its plugins and before Prism highlights on
 * DOMContentLoaded. Vanilla JS, no jQuery.
 */
( function () {
	'use strict';

	var Prism = window.Prism;

	if ( ! Prism ) {
		return;
	}

	var settings = window.igSyntaxHiliter || {};
	var plugins = Prism.plugins || {};

	if ( plugins.autoloader && settings.componentsUrl ) {
		plugins.autoloader.languages_path = settings.componentsUrl;
	}

	/**
	 * Is this code box, or anything containing it, opted out of the toolbar?
	 *
	 * @param {Element} element Element to test.
	 * @return {boolean} True when the toolbar should not be shown.
	 */
	function isToolbarOptedOut( element ) {
		if ( ! element || ! element.closest ) {
			return false;
		}

		return null !== element.closest( '.igsh-no-toolbar' );
	}

	if ( plugins.toolbar && 'function' === typeof plugins.toolbar.registerButton ) {
		plugins.toolbar.registerButton( 'igsh-file-label', function ( env ) {
			var pre = env.element ? env.element.parentNode : null;

			if ( ! pre || 'pre' !== pre.nodeName.toLowerCase() ) {
				return;
			}

			if ( isToolbarOptedOut( pre ) ) {
				return;
			}

			var label = pre.getAttribute( 'data-file' );

			if ( ! label ) {
				return;
			}

			var item = document.createElement( 'span' );

			item.className = 'igsh-file-label';
			item.textContent = label;
			item.setAttribute(
				'title',
				( settings.fileLabel ? settings.fileLabel + ': ' : '' ) + label
			);

			return item;
		} );
	}
}() );
