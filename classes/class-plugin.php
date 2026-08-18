<?php
/**
 * Plugin orchestrator.
 *
 * @package iG_Syntax_Hiliter
 *
 * @author Amit Gupta <https://amitgupta.in/>
 */

namespace iG\Syntax_Hiliter;

use iG\Syntax_Hiliter\Traits\Singleton;

/**
 * Boots the plugin's services once the Gatekeeper has cleared the environment.
 *
 * Nothing here is gated on `is_admin()`. A REST request is not an admin request,
 * so the settings and revert routes would never be registered; and `Admin` is the
 * only class left extending `Base`, which is what runs a pending migration.
 */
final class Plugin {

	use Singleton;

	/**
	 * Constructor.
	 */
	protected function __construct() {

		$this->_load_services();

	}    //end __construct()

	/**
	 * Loads the plugin's services.
	 *
	 * @return void
	 */
	protected function _load_services(): void {

		Asset_Manager::get_instance()->register_hooks();
		Shortcode_Handler::get_instance()->register_hooks();
		Gist_Embed::get_instance()->register_hooks();
		Block::get_instance()->register_hooks();
		Admin::get_instance()->register_hooks();
		Block_Converter::get_instance()->register_hooks();

	}    //end _load_services()

	/**
	 * Returns an absolute filesystem path inside the plugin directory.
	 *
	 * Resolved from this file's own location, so nothing depends on the plugin
	 * directory's name.
	 *
	 * @param string $path Optional. Path relative to the plugin directory.
	 * @return string Absolute path, with no trailing slash added of its own.
	 */
	public function get_path( string $path = '' ): string {

		return plugin_dir_path( __DIR__ ) . ltrim( $path, '/' );

	}    //end get_path()

	/**
	 * Returns the plugin version.
	 *
	 * The one place the version constant is read. Four classes each open coded this
	 * `defined()` check before, and their fallbacks had quietly drifted apart: three
	 * answered `0`, which is a cache busting string an asset URL can carry, and
	 * `Migrate` answered an empty string, which is load bearing there because it is
	 * what tells a fresh install from an upgrade. Both are still wanted, so the
	 * fallback is the caller's to name and the difference is stated at each call
	 * rather than buried in four copies of the same check.
	 *
	 * Static, and it has to be: `Plugin::__construct()` boots every service the
	 * plugin has, so reaching this through `get_instance()` from a class which merely
	 * wanted to know the version would boot the plugin to answer.
	 *
	 * The plugin spells its version `Major.Minor`, eg. `6.0`. Compare it with
	 * `version_compare()` after normalising it, never numerically.
	 *
	 * @param string $fallback Optional. What to answer where the constant is not defined.
	 *
	 * @return string Version string, eg. `6.0`.
	 */
	public static function get_version( string $fallback = '' ): string {

		return ( defined( 'IG_SYNTAX_HILITER_VERSION' ) ) ? (string) IG_SYNTAX_HILITER_VERSION : $fallback;

	}    //end get_version()

}    //end of class

//EOF
