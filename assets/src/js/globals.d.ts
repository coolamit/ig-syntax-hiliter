/**
 * The globals the two scripts in this directory read, and nothing else.
 *
 * Both are classic scripts rather than modules — they are enqueued with
 * `wp_enqueue_script()` and run in the global scope — so what they can see is
 * whatever PHP printed before them and whatever Prism put on `window`. None of
 * that has types of its own, and this file is where they are written down.
 *
 * `types/` is for declarations that patch a third-party package. These describe
 * this plugin's own contract with itself, so they live beside the code.
 */

/**
 * Every string `Admin::_get_script_data()` sends over.
 *
 * Keep this list and that method in step. A key added there and not here is
 * simply unreachable; a key removed there and left here reads as `undefined` on
 * screen, which is what the settings page would show a site owner.
 */
interface IgshAdminStrings {
	saving: string;
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
}

interface Window {
	igSyntaxHiliterAdmin?: IgshAdminConfig | undefined;
	igSyntaxHiliter?: IgshFrontendSettings | undefined;
	Prism?: IgshPrism | undefined;
}
