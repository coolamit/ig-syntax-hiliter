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
 * Booting a service is the whole of what this class does. Each one hooks itself up
 * to WordPress from its own constructor, so building it is all that is asked of it
 * here and the singleton is the only guard any of them needs. They each carried a
 * `register_hooks()` and a `_hooked` flag before, which existed purely because the
 * caller was external and could call twice.
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
	 * The order is preserved from when this method registered the hooks itself, and
	 * it still matters: the Gatekeeper calls `Plugin::get_instance()` as the first
	 * thing on `init`, so this is what reaches every service first and the order
	 * below is the order they are built in.
	 *
	 * @return void
	 */
	protected function _load_services(): void {

		Asset_Manager::get_instance();
		Shortcode_Handler::get_instance();
		Gist_Embed::get_instance();
		Block::get_instance();
		Admin::get_instance();
		Block_Converter::get_instance();

	}    //end _load_services()

}    //end of class

//EOF
