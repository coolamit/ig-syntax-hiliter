<?php
/**
 * Gatekeeper class for the plugin which loads plugin only if minimum requirements are satisfied.
 *
 * @author Amit Gupta <https://amitgupta.in/>
 *
 * @since 2015-07-26
 */


final class iG_Syntax_Hiliter_Gatekeeper {

	const MIN_PHP_VERSION_REQUIRED = '7.4.0';

	/**
	 * Constructor
	 */
	public function  __construct() {

		if ( $this->_has_pass() ) {

			$this->_load_plugin();

		} else {
			add_action( 'admin_notices', array( $this, 'show_admin_notice' ) );
		}

	}

	/**
	 * Factory method to initialize the class
	 *
	 * @return \iG_Syntax_Hiliter_Gatekeeper
	 */
	public static function activate() {

		$class = get_called_class();

		return ( new $class() );

	}

	/**
	 * Check if plugin's minimum requirements are satisfied or not. If they are then
	 * Gatekeeper can grant a pass else deny passage.
	 *
	 * @return boolean Returns TRUE if minimum requirements are met else FALSE
	 */
	private function _has_pass() {

		if ( version_compare( phpversion(), self::MIN_PHP_VERSION_REQUIRED ) == -1 ) {
			//min requirements not met
			return false;
		}

		return true;

	}

	/**
	 * Load up the plugin
	 *
	 * @return void
	 */
	private function _load_plugin() {

		//load up autoloader
		require_once( IG_SYNTAX_HILITER_ROOT . '/autoloader.php' );

		if ( is_admin() ) {

			//initialize the admin class
			\iG\Syntax_Hiliter\Admin::get_instance();

		} else {

			//load up GeSHi
			require_once( IG_SYNTAX_HILITER_ROOT . '/classes/geshi.php' );

			//initialize the front-end class
			\iG\Syntax_Hiliter\Frontend::get_instance();

		}

	}

	/**
	 * Called on 'admin_notices' hook this function shows an error message
	 * on all admin screens (on purpose) notifying the administrator that plugin's
	 * minimum requirements are not met and either the plugin should be disabled or
	 * minimum requirements should be met.
	 *
	 * @return void
	 */
	public function show_admin_notice() {

		printf(
			'<div class="error"><p>PHP version %s or greater is needed for <strong>iG:Syntax Hiliter</strong> plugin. You must upgrade your PHP to make it work.</p></div>',
			esc_html( self::MIN_PHP_VERSION_REQUIRED )
		);

	}

}    //end of class

//EOF
