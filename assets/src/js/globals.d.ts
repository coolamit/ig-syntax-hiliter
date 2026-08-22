/**
 * Globals the classic scripts in this directory read and write: what PHP
 * printed, what Prism put on `window`, and what a sibling script published.
 */

/**
 * What a message says about the thing it reports.
 */
type IgshNoticeTone = 'busy' | 'success' | 'error';

/**
 * One message on screen, as its caller holds it.
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
 */
interface IgshRequestError extends Error {
	status?: number | undefined;
	code?: string | undefined;
	isTimeout?: boolean | undefined;
}

/**
 * The transport and the page lock, published by `admin-api.js`.
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
 * Every string `Admin::_get_script_data()` sends over; keep in step with it.
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

	// Theme slug to stylesheet URL; `none` maps to an empty string.
	themes?: Record< string, string > | undefined;

	// Id of the `link` tag carrying the theme stylesheet.
	themeStyleId?: string | undefined;

	// Font slug to its stylesheet URL and the rule applying it; `none` maps to
	// empty strings.
	fonts?: Record< string, { url: string; css: string } > | undefined;

	// Id of the `link` tag carrying the webfont stylesheet.
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
 * Prism, as far as these scripts use it; every member is optional because a
 * plugin may not have loaded.
 */
interface IgshPrism {
	plugins?:
		| {
				autoloader?: IgshPrismAutoloader | undefined;
		  }
		| undefined;

	highlightElement?: ( ( element: Element ) => void ) | undefined;
}

interface Window {
	igSyntaxHiliterAdmin?: IgshAdminConfig | undefined;
	igSyntaxHiliter?: IgshFrontendSettings | undefined;
	igshNotices?: IgshNotices | undefined;
	igshAdminApi?: IgshAdminApi | undefined;
	Prism?: IgshPrism | undefined;
}
