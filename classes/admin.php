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
	 * Class constructor
	 */
	protected function __construct() {

		parent::__construct();

		$this->_setup_hooks();

	}

	/**
	 * Method to set up listeners to WP hooks
	 *
	 * @return void
	 */
	protected function _setup_hooks() : void {

		/*
		 * Actions
		 */
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_stuff' ] );
		add_action( 'wp_ajax_ig-sh-save-options', [ $this, 'save_plugin_options' ] );
		add_action( 'admin_notices', [ $this, 'maybe_show_migration_message' ] );

		/*
		 * Filters
		 */
		add_filter( 'plugin_action_links', [ $this, 'get_action_links' ], 10, 2 );

	}

	/**
	 * This function checks whether the plugin options have been migrated from an older
	 * version or not. If they have been then it shows a one time notice on the plugin's
	 * admin page and then deletes the older version number from DB.
	 */
	public function maybe_show_migration_message() : void {

		if ( get_current_screen()->id !== sprintf( 'settings_page_%s-page', parent::PLUGIN_ID ) ) {
			//not our admin page, bail out
			return;
		}

		$old_version = round( floatval( get_option( parent::PLUGIN_ID . '-migrated-from', 0 ) ), 1 );

		if ( 0 < $old_version ) {

			if ( floatval( IG_SYNTAX_HILITER_VERSION ) > $old_version ) {
				printf(
					'<div class="updated fade"><p>Options migrated successfully from v%f</p></div>',
					$old_version
				);
			}

			//delete this from DB, not needed anymore
			delete_option( parent::PLUGIN_ID . '-migrated-from' );

		}

	}

	/**
	 * Method to add plugin settings page in the Settings menu
	 *
	 * @return void
	 */
	public function add_menu() : void {
		add_options_page(
			sprintf( '%s Options', parent::PLUGIN_NAME ),
			parent::PLUGIN_NAME,
			'manage_options',
			sprintf( '%s-page', parent::PLUGIN_ID ),
			[ $this, 'admin_page' ]
		);
	}

	/**
	 * Method to construct the UI for the plugin settings page
	 *
	 * @return void
	 */
	public function admin_page() : void {

		Helper::render_template(
			sprintf( '%s/templates/plugin-options-page.php', untrailingslashit( IG_SYNTAX_HILITER_ROOT ) ),
			[
				'plugin_name'      => parent::PLUGIN_NAME,
				'options'          => $this->_option->get_all(),
				'strict_mode_opts' => Validate::$strict_mode_values,
				'human_time_diff'  => Helper::human_time_diff( time(), ( $this->_get_language_cache_build_time() + parent::LANGUAGES_CACHE_LIFE ) ),
			],
			true
		);

	}

	/**
	 * Method to handle our AJAX requests
	 *
	 * @return void
	 */
	public function save_plugin_options() : void {

		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$response = new Ajax_Response();

		$response->add_nonce( sprintf( '%s-nonce', parent::PLUGIN_ID ) );

		//check & see if we have the values
		$option_name  = Helper::filter_input( INPUT_POST, 'option_name', FILTER_SANITIZE_STRING );
		$option_value = Helper::filter_input( INPUT_POST, 'option_value', FILTER_SANITIZE_STRING );

		if (
			! check_ajax_referer( parent::PLUGIN_ID . '-nonce', '_ig_sh_nonce', false )
			|| empty( $option_name ) || empty( $option_value )
		) {
			$response->add_error( 'Invalid request sent, please refresh the page and try again' );
			$response->send();
		}

		$option_name  = sanitize_text_field( strtolower( trim( $option_name ) ) );
		$option_value = sanitize_text_field( strtolower( trim( $option_value ) ) );

		if ( 'igsh_refresh_languages' === $option_name && 'rebuild' === $option_value ) {

			//rebuild language file list
			$this->get_languages( 'yes' );

			$response->add_success( 'Shorthand Tags rebuilt successfully' );
			$response->add( 'time', Helper::human_time_diff( time(), ( $this->_get_language_cache_build_time() + parent::LANGUAGES_CACHE_LIFE ) ) );

		} elseif ( $this->_option->get( $option_name ) !== false ) {

			$response->add_success( 'Option Saved successfully' );    //assume option saved successfully

			switch ( $option_name ) {

				case 'strict_mode':
					$this->_option->save(
						$option_name,
						$this->_validate->sanitize_strict_mode_values( $option_value )
					);
					break;

				case 'non_strict_mode':
					$sanitized_language_names = $this->_validate->languages(
						explode( ',', $option_value ),
						array_values( $this->get_languages() )
					);

					$this->_option->save( $option_name, $sanitized_language_names );

					unset( $sanitized_language_names );
					break;

				default:
					if ( ! $this->_validate->is_yesno( $option_value ) ) {
						$response->add_error( 'Invalid request sent, please refresh the page and try again' );
						$response->send();
					} else {
						$this->_option->save( $option_name, $option_value );
					}

					break;

			}

		}

		$response->send();

	}    //end save_plugin_options()

	/**
	 * Method to load assets on settings page in wp-admin
	 *
	 * @return void
	 */
	public function enqueue_stuff( $hook ) : void {

		if ( ! is_admin() || $hook !== sprintf( 'settings_page_%s-page', parent::PLUGIN_ID ) ) {
			//page is not in wp-admin or not our settings page, so bail out
			return;
		}

		//load stylesheet
		wp_enqueue_style(
			sprintf( '%s-admin', parent::PLUGIN_ID ),
			plugins_url( '/assets/css/admin.css', __DIR__ ),
			false,
			IG_SYNTAX_HILITER_VERSION
		);

		//load jQuery::msg stylesheet
		wp_enqueue_style(
			sprintf( '%s-jquery-msg', parent::PLUGIN_ID ),
			plugins_url( '/assets/css/jquery.msg.css', __DIR__ ),
			false,
			IG_SYNTAX_HILITER_VERSION
		);

		//load jQuery::center script
		wp_enqueue_script(
			sprintf( '%s-jquery-center', parent::PLUGIN_ID ),
			plugins_url( '/assets/js/jquery.center.min.js', __DIR__ ),
			[ 'jquery' ],
			IG_SYNTAX_HILITER_VERSION
		);

		//load jQuery::msg script
		wp_enqueue_script(
			sprintf( '%s-jquery-msg', parent::PLUGIN_ID ),
			plugins_url( '/assets/js/jquery.msg.min.js', __DIR__ ),
			[ sprintf( '%s-jquery-center', parent::PLUGIN_ID ) ],
			IG_SYNTAX_HILITER_VERSION
		);

		//load our script
		wp_enqueue_script(
			sprintf( '%s-admin', parent::PLUGIN_ID ),
			plugins_url( '/assets/js/admin.js', __DIR__ ),
			[ sprintf( '%s-jquery-msg', parent::PLUGIN_ID ) ],
			IG_SYNTAX_HILITER_VERSION
		);

		//some vars in JS that we'll need
		wp_localize_script(
			sprintf( '%s-admin', parent::PLUGIN_ID ),
			'ig_sh',
			[
				'plugins_url' => plugins_url( '/', __DIR__ ),
				'nonce'       => wp_create_nonce( sprintf( '%s-nonce', parent::PLUGIN_ID ) ),
			]
		);

	}

	/**
	 * Method to add link to plugin settings page in the plugin listing once plugin has been activated.
	 *
	 * @param array  $links
	 * @param string $file
	 *
	 * @return array
	 */
	public function get_action_links( array $links, string $file ) : array {

		if ( IG_SYNTAX_HILITER_BASENAME !== $file ) {
			return $links;
		}

		$settings_page_slug = sprintf(
			'options-general.php?page=%s-page',
			parent::PLUGIN_ID
		);

		$settings_link = sprintf(
			'<a href="%s" aria-label="Configure %s">Settings</a>',
			esc_url( admin_url( $settings_page_slug ) ),
			esc_attr( parent::PLUGIN_NAME )
		);

		array_unshift( $links, $settings_link );

		return $links;

	}

}    //end of class

//EOF
