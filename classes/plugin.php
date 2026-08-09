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
	 * Returns the URL of a file inside the plugin directory.
	 *
	 * @param string $path Optional. Path relative to the plugin directory.
	 * @return string
	 */
	public function get_url( string $path = '' ): string {

		return plugins_url( ltrim( $path, '/' ), __DIR__ );

	}    //end get_url()

	/**
	 * Returns the plugin version.
	 *
	 * @return string Semantic version string, eg. `6.0.0`.
	 */
	public function get_version(): string {

		return (string) IG_SYNTAX_HILITER_VERSION;

	}    //end get_version()

}    //end of class

//EOF
