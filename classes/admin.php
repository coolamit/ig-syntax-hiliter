<?php
/**
 * iG Syntax Hiliter Admin Class
 * This class handles all the stuff for settings page of the plugin in wp-admin
 *
 * @author Amit Gupta <http://amitgupta.in/>
 */

namespace iG\Syntax_Hiliter;

class Admin extends Base {

	/**
	 * protected constructor, singleton pattern implemented
	 */
	protected function __construct() {
		parent::__construct();

		$this->_setup_hooks();
	}

	protected function _setup_hooks() {
		//call function to add options menu item
		add_action( 'admin_menu', array( $this, 'add_menu' ) );

		//setup our style/script enqueuing for wp-admin
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_stuff' ) );

		//setup callback for AJAX on admin page
		add_action( 'wp_ajax_ig-sh-save-options', array( $this, 'save_plugin_options' ) );

		//callback to show any migration messages
		add_action( 'admin_notices', array( $this, 'show_migration_message' ) );
	}

	/**
	 * This function checks whether the plugin options have been migrated from an older
	 * version or not. If they have been then it shows a one time notice on the plugin's
	 * admin page and then deletes the older version number from DB.
	 */
	public function show_migration_message() {
		if ( get_current_screen()->id !== sprintf( 'settings_page_%s-page', parent::PLUGIN_ID ) ) {
			//not our admin page, bail out
			return false;
		}

		$old_version = round( floatval( get_option( parent::PLUGIN_ID . '-migrated-from', 0 ) ), 1 );

		if ( $old_version > 0 ) {

			if ( $old_version < floatval( IG_SYNTAX_HILITER_VERSION ) ) {

				printf( '<div class="updated fade"><p>Options migrated successfully from v%f</p></div>', floatval( $old_version ) );

			}

			//delete this from DB, not needed anymore
			delete_option( parent::PLUGIN_ID . '-migrated-from' );

		}

		unset( $old_version );
	}

	/**
	 * This function adds plugin's admin page in the Settings menu
	 */
	public function add_menu() {
		add_options_page( parent::PLUGIN_NAME . ' Options', parent::PLUGIN_NAME, 'manage_options', parent::PLUGIN_ID . '-page', array( $this, 'admin_page' ) );
	}

	/**
	 * This function constructs the UI for the plugin admin page
	 */
	public function admin_page() {

		echo Helper::render_template( IG_SYNTAX_HILITER_ROOT . '/templates/plugin-options-page.php', array(
			'plugin_name'      => parent::PLUGIN_NAME,
			'options'          => $this->_option->get_all(),
			'strict_mode_opts' => Validate::$strict_mode_values,
			'human_time_diff'  => Helper::human_time_diff( time(), ( $this->_get_language_cache_build_time() + parent::LANGUAGES_CACHE_LIFE ) ),
		) );

	}

	/**
	 * This function is called by WP to handle our AJAX requests
	 */
	public function save_plugin_options() {

		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$response = new Ajax_Response();

		$response->add_nonce( parent::PLUGIN_ID . '-nonce' );

		//check & see if we have the values
		if (
			! check_ajax_referer( parent::PLUGIN_ID . '-nonce', '_ig_sh_nonce', false )
			|| ! isset( $_POST['option_name'] ) || ! isset( $_POST['option_value'] )
		) {
			$response->add_error( 'Invalid request sent, please refresh the page and try again' );
			$response->send();
		}

		$input = array(
			'option_name'  => sanitize_text_field( strtolower( trim( $_POST['option_name'] ) ) ),
			'option_value' => sanitize_text_field( strtolower( trim( $_POST['option_value'] ) ) ),
		);

		if ( $input['option_name'] == 'igsh_refresh_languages' && $input['option_value'] == 'rebuild' ) {

			//rebuild language file list
			$this->get_languages( 'yes' );

			$response->add_success( 'Shorthand Tags rebuilt successfully' );
			$response->add( 'time', Helper::human_time_diff( time(), ( $this->_get_language_cache_build_time() + parent::LANGUAGES_CACHE_LIFE ) ) );

		} elseif ( $this->_option->get( $input['option_name'] ) !== false ) {

			$response->add_success( 'Option Saved successfully' );	//assume option saved successfully

			switch ( $input['option_name'] ) {
				case 'strict_mode':
					$this->_option->save( $input['option_name'], $this->_validate->sanitize_strict_mode_values( $input['option_value'] ) );
					break;
				case 'non_strict_mode':
					$sanitized_language_names = $this->_validate->languages( explode( ',', $input['option_value'] ), array_values( $this->get_languages() ) );
					$this->_option->save( $input['option_name'], $sanitized_language_names );

					unset( $sanitized_language_names );
					break;
				default:
					if ( ! $this->_validate->is_yesno( $input['option_value'] ) ) {
						$response->add_error( 'Invalid request sent, please refresh the page and try again' );
						$response->send();
					} else {
						$this->_option->save( $input['option_name'], $input['option_value'] );
					}
					break;
			}

		}

		$response->send();

	}	//end save_plugin_options()

	/**
	 * function to enqueue stuff in wp-admin head
	 */
	public function enqueue_stuff( $hook ) {

		if ( ! is_admin() || $hook !== sprintf( 'settings_page_%s-page', parent::PLUGIN_ID ) ) {
			//page is not in wp-admin or not our settings page, so bail out
			return false;
		}

		//load stylesheet
		wp_enqueue_style( parent::PLUGIN_ID . '-admin', plugins_url( 'assets/css/admin.css', __DIR__ ), false, IG_SYNTAX_HILITER_VERSION );
		//load jQuery::msg stylesheet
		wp_enqueue_style( parent::PLUGIN_ID . '-jquery-msg', plugins_url( 'assets/css/jquery.msg.css', __DIR__ ), false, IG_SYNTAX_HILITER_VERSION );

		//load jQuery::center script
		wp_enqueue_script( parent::PLUGIN_ID . '-jquery-center', plugins_url( 'assets/js/jquery.center.min.js', __DIR__ ), array( 'jquery' ), IG_SYNTAX_HILITER_VERSION );
		//load jQuery::msg script
		wp_enqueue_script( parent::PLUGIN_ID . '-jquery-msg', plugins_url( 'assets/js/jquery.msg.min.js', __DIR__ ), array( parent::PLUGIN_ID . '-jquery-center' ), IG_SYNTAX_HILITER_VERSION );
		//load our script
		wp_enqueue_script( parent::PLUGIN_ID . '-admin', plugins_url( 'assets/js/admin.js', __DIR__ ), array( parent::PLUGIN_ID . '-jquery-msg' ), IG_SYNTAX_HILITER_VERSION );

		//some vars in JS that we'll need
		wp_localize_script( parent::PLUGIN_ID . '-admin', 'ig_sh', array(
			'plugins_url' => untrailingslashit( plugins_url( '', __DIR__ ) ) . '/',
			'nonce' => wp_create_nonce( parent::PLUGIN_ID . '-nonce' ),
		) );

	}

}	//end of class


//EOF