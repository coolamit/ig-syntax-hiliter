/**
 * Globals the classic scripts in this directory read and write.
 *
 * They are enqueued with `wp_enqueue_script()`, so what they see is what PHP
 * printed, what Prism put on `window`, and what a sibling published.
 */

/**
 * What a message says about the thing it reports.
 *
 * `busy` does not take itself off the screen.
 */
type IgshNoticeTone = 'busy' | 'success' | 'error';

/**
 * One message on screen, as its caller holds it.
 *
 * A caller keeps this while it has more to say about the same thing — `busy`
 * then settled into `success`/`error`.
 */
interface IgshNotice {
	settle: ( message: string, tone: IgshNoticeTone ) => void;
	dismiss: () => void;
}

/**
 * The notice stack, published by `notices.js`.
 */
interface IgshNotices {
	notify: ( message: string, tone: IgshNoticeTone ) => IgshNotice;
}

/**
 * Every string `Admin::_get_script_data()` sends over.
 *
 * Keep in step with that method; a key removed there reads as `undefined` on
 * screen.
 */
interface IgshAdminStrings {
	saving: string;
	savedOn: string;
	savedOff: string;
	savedChoice: string;
	savedAlsoOn: string;
	savedAlsoOff: string;
	saved: string;
	saveFailed: string;
	saveTimedOut: string;
	reloadNeeded: string;
	themesRefreshing: string;
	themesRefreshed: string;
	themesRefreshFail: string;
	revertConfirm: string;
	revertNone: string;
	revertRunning: string;
	revertDone: string;
	revertDoneLeft: string;
	revertDoneBlocks: string;
	revertDoneFailed: string;
	revertDonePartial: string;
	revertFailed: string;
}

/**
 * What PHP prints above the settings page script.
 */
interface IgshAdminConfig {
	restUrl: string;
	nonce: string;

	/**
	 * Every theme the dropdown offers, to the stylesheet it loads.
	 *
	 * `none` is in it with an empty string — the preview must look it up and
	 * find nothing to load.
	 */
	themes?: Record< string, string > | undefined;

	/**
	 * Id of the `link` tag carrying the theme stylesheet.
	 *
	 * Sent rather than spelled out: WordPress builds it from the handle.
	 */
	themeStyleId?: string | undefined;

	/**
	 * Every font the dropdown offers, to its stylesheet and the rule that
	 * applies it.
	 *
	 * `none` is in it with two empty strings, as in `themes`.
	 */
	fonts?: Record< string, { url: string; css: string } > | undefined;

	/**
	 * Id of the `link` tag carrying the webfont stylesheet.
	 */
	fontStyleId?: string | undefined;
	i18n: IgshAdminStrings;
}

/**
 * What PHP prints above the front-end setup script.
 */
interface IgshFrontendSettings {
	componentsUrl?: string | undefined;
}

/**
 * The autoloader plugin, as far as this plugin uses it.
 */
interface IgshPrismAutoloader {
	languages_path: string;
}

/**
 * Prism itself.
 *
 * Not `@types/prismjs`: Prism is loaded from `assets/lib/` and never imported,
 * so only what the setup script touches is declared. Every member is optional
 * because the setup script exists to cope with plugins that did not load.
 */
interface IgshPrism {
	plugins?:
		| {
				autoloader?: IgshPrismAutoloader | undefined;
		  }
		| undefined;

	/**
	 * Highlights one element again.
	 *
	 * The settings preview re-highlights a box to add or remove line numbers.
	 */
	highlightElement?: ( ( element: Element ) => void ) | undefined;
}

interface Window {
	igSyntaxHiliterAdmin?: IgshAdminConfig | undefined;
	igSyntaxHiliter?: IgshFrontendSettings | undefined;
	igshNotices?: IgshNotices | undefined;
	Prism?: IgshPrism | undefined;
}
