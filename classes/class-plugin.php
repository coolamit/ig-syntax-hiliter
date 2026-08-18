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

}    //end of class

//EOF
