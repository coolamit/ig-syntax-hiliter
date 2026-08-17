/**
 * The globals the scripts in this directory read and write, and nothing else.
 *
 * All three are classic scripts rather than modules — they are enqueued with
 * `wp_enqueue_script()` and run in the global scope — so what they can see is
 * whatever PHP printed before them, whatever Prism put on `window`, and whatever
 * another of these scripts published there. None of that has types of its own,
 * and this file is where they are written down.
 *
 * `types/` is for declarations that patch a third-party package. These describe
 * this plugin's own contract with itself, so they live beside the code.
 */

/**
 * What a message says about the thing it reports.
 *
 * `busy` is something still happening, and is the one tone that does not take
 * itself off the screen.
 */
type IgshNoticeTone = 'busy' | 'success' | 'error';

/**
 * One message on screen, as its caller holds it.
 *
 * A caller keeps this for as long as it has more to say about the same thing —
 * a save reports itself as `busy` and then settles that same message into a
 * `success` or an `error`, rather than leaving the first one up and stacking a
 * second beside it.
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
 * Keep this list and that method in step. A key added there and not here is
 * simply unreachable; a key removed there and left here reads as `undefined` on
 * screen, which is what the settings page would show a site owner.
 */
interface IgshAdminStrings {
	saving: string;
	savedOn: string;
	savedOff: string;
	savedChoice: string;
	saved: string;
	saveFailed: string;
	saveTimedOut: string;
	reloadNeeded: string;
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
	 * `none` is in it and carries an empty string, because it is a choice like any
	 * other and the preview has to be able to look it up and find nothing to load.
	 */
	themes?: Record< string, string > | undefined;

	/**
	 * Id of the `link` tag carrying the theme stylesheet.
	 *
	 * Sent rather than spelled out here: WordPress builds it from the handle, and
	 * the handle belongs to `Asset_Manager`.
	 */
	themeStyleId?: string | undefined;
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
 * Deliberately not `@types/prismjs`. Prism is loaded from `assets/lib/` and is
 * never imported, so a full declaration set would describe a library this code
 * does not link against and would go stale without anything noticing. Declared
 * here is the one thing the setup script touches, and no more — the same
 * reasoning as `types/wordpress-block-editor.d.ts`.
 *
 * Every member is optional because the whole point of the setup script is to
 * cope with a Prism whose plugins did not load.
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
	 * The settings page preview needs it: line numbers are drawn by a Prism plugin
	 * when the box is highlighted, so switching them on means asking Prism to go
	 * over the same box a second time.
	 */
	highlightElement?: ( ( element: Element ) => void ) | undefined;
}

interface Window {
	igSyntaxHiliterAdmin?: IgshAdminConfig | undefined;
	igSyntaxHiliter?: IgshFrontendSettings | undefined;
	igshNotices?: IgshNotices | undefined;
	Prism?: IgshPrism | undefined;
}
