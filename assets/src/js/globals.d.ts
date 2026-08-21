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
 * An error carrying what this code knows about a failed request.
 *
 * Every member is optional — a network failure gives a plain `Error`.
 */
interface IgshRequestError extends Error {
	status?: number | undefined;
	code?: string | undefined;
	isTimeout?: boolean | undefined;
}

/**
 * The transport and the page lock, published by `admin-api.js`.
 *
 * `request()` carries the nonce, `locked()` holds the page for the length of
 * one piece of work; the rest are the string helpers both consumers need.
 */
interface IgshAdminApi {
	request: < T >(
		method: string,
		route: string,
		timeout: number,
		body?: object
	) => Promise< T >;
	locked: < T >( work: () => Promise< T > ) => Promise< T >;
	describeError: ( error: IgshRequestError, fallback: string ) => string;
	fill: ( template: string, ...values: string[] ) => string;
	withCount: ( template: string, count: number ) => string;
	REQUEST_TIMEOUT_MS: number;
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
	igshAdminApi?: IgshAdminApi | undefined;
	Prism?: IgshPrism | undefined;
}
