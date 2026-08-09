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
 * Core services always load; only the settings screen is admin gated.
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

		// TODO (M2): attach the shortcode handler, content protector and Gist pipeline.

		/*
		 * TODO (M4): restore `if ( is_admin() ) { Admin::get_instance(); }`.
		 * Admin still calls language-scan methods that no longer exist on Base,
		 * so the settings screen would fatal.
		 */

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
