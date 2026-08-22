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
} )();
