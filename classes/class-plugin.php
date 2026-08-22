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
 * Each service hooks itself up from its own constructor. Nothing is gated on
 * `is_admin()` — a REST request is not an admin request, and `Admin` is the only
 * `Base` child, which is what runs a pending migration.
 */
final class Plugin {

	use Singleton;

	/**
	 * Constructor.
	 */
	protected function __construct() {

		$this->_load_services();

	}

	/**
	 * Loads the plugin's services.
	 *
	 * The services are built in this order.
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

	}

} // end of class

// EOF
