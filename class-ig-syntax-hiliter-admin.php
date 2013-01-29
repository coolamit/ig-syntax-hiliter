<?php

/**
 * iG Syntax Hiliter Admin Class
 * This class handles all the stuff for settings page of the plugin in wp-admin
 */

class iG_Syntax_Hiliter_Admin extends iG_Syntax_Hiliter {

	/**
	 * @var obj Contains class instance
	 */
	private static $_instance;

	/**
	 * private constructor, singleton pattern implemented
	 */
	private function __construct() {
		//init options
		$this->_init_options();

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
	 * function to give the singleton instance if exists or make one
	 */
	public static function get_instance() {
		if( ! is_a( self::$_instance, __CLASS__ ) ) {
			$class_name = __CLASS__;
			self::$_instance = new $class_name();
		}
		return self::$_instance;
	}

	/**
	 * This function checks whether the plugin options have been migrated from an older
	 * version or not. If they have been then it shows a one time notice on the plugin's
	 * admin page and then deletes the older version number from DB.
	 */
	public function show_migration_message() {
		if( get_current_screen()->id !== 'settings_page_' . parent::plugin_id . '-page' ) {
			//not our admin page, bail out
			return false;
		}

		$old_version = floatval( get_option( self::plugin_id . '-migrated-from', 0 ) );
		if( $old_version > 0 ) {
			if( $old_version < floatval(IG_SYNTAX_HILITER_VERSION) ) {
				echo '<div class="updated fade"><p>Options migrated successfully from v' . $old_version . '</p></div>';
			}
			//delete this from DB, not needed anymore
			delete_option( self::plugin_id . '-migrated-from' );
		}

		unset( $old_version );
	}

	/**
	 * This function adds plugin's admin page in the Settings menu
	 */
	public function add_menu() {
		add_options_page( parent::plugin_name . ' Options', parent::plugin_name, 'manage_options', parent::plugin_id . '-page', array($this, 'admin_page') );
	}

	/**
	 * This function constructs the UI for the plugin admin page
	 */
	public function admin_page() {
?>
		<div class="wrap">
			<h2><?php print(parent::plugin_name . ' Options'); ?></h2>
			<p>&nbsp;</p>
			<p>You can change global options here, changes are saved automatically.</p>
			<p>&nbsp;</p>
			<table id="ig-sh-admin-ui" width="35%" border="0">
				<tr>
					<td width="75%"><label for="toolbar">Show Toolbar?</label></td>
					<td align="right">
						<select name="toolbar" id="toolbar" class="ig-sh-option">
							<option value="yes" <?php selected( $this->_get_option('toolbar'), 'yes' ) ?>>YES</option>
							<option value="no" <?php selected( $this->_get_option('toolbar'), 'no' ) ?>>NO</option>
						</select>
					</td>
				</tr>
				<tr>
					<td><label for="plain_text">Show Plain Text Option?</label></td>
					<td align="right">
						<select name="plain_text" id="plain_text" class="ig-sh-option">
							<option value="yes" <?php selected( $this->_get_option('plain_text'), 'yes' ) ?>>YES</option>
							<option value="no" <?php selected( $this->_get_option('plain_text'), 'no' ) ?>>NO</option>
						</select>
					</td>
				</tr>
				<tr>
					<td><label for="show_line_numbers">Show line numbers in code?</label></td>
					<td align="right">
						<select name="show_line_numbers" id="show_line_numbers" class="ig-sh-option">
							<option value="yes" <?php selected( $this->_get_option('show_line_numbers'), 'yes' ) ?>>YES</option>
							<option value="no" <?php selected( $this->_get_option('show_line_numbers'), 'no' ) ?>>NO</option>
						</select>
					</td>
				</tr>
				<tr>
					<td><label for="hilite_comments">Hilite code in comments?</label></td>
					<td align="right">
						<select name="hilite_comments" id="hilite_comments" class="ig-sh-option">
							<option value="yes" <?php selected( $this->_get_option('hilite_comments'), 'yes' ) ?>>YES</option>
							<option value="no" <?php selected( $this->_get_option('hilite_comments'), 'no' ) ?>>NO</option>
						</select>
					</td>
				</tr>
				<tr>
					<td><label for="link_to_manual">Link keywords/function names to Manual (if available)?</label></td>
					<td align="right">
						<select name="link_to_manual" id="link_to_manual" class="ig-sh-option">
							<option value="yes" <?php selected( $this->_get_option('link_to_manual'), 'yes' ) ?>>YES</option>
							<option value="no" <?php selected( $this->_get_option('link_to_manual'), 'no' ) ?>>NO</option>
						</select>
					</td>
				</tr>
				<tr>
					<td><label for="gist_in_comments">Enable GitHub Gist embed in comments?</label></td>
					<td align="right">
						<select name="gist_in_comments" id="gist_in_comments" class="ig-sh-option">
							<option value="yes" <?php selected( $this->_get_option('gist_in_comments'), 'yes' ) ?>>YES</option>
							<option value="no" <?php selected( $this->_get_option('gist_in_comments'), 'no' ) ?>>NO</option>
						</select>
					</td>
				</tr>
			</table>
		</div>
<?php
	}

	/**
	 * This function takes in the message & its type to be sent in response to AJAX call
	 * and returns the proper HTML string which can be sent back to browser as is.
	 */
	private function _create_ajax_message( $message, $type='success' ) {
		if( empty($message) ) {
			return;
		}

		$type = ( strtolower( trim($type) ) === 'success' ) ? 'success' : 'error';	//type can only be either one

		return '<span class="ig-sh-' . $type . '">' . $message . '</span>';
	}

	/**
	 * This function is used to send a JSON encoded response to the browser. It accepts
	 * a string or an array as parameter.
	 */
	private function _send_ajax_response( $response = array() ) {
		$response = ( ! is_array($response) ) ? array($response) : $response;

		header("Content-Type: application/json");
		echo json_encode( $response );		//we want json
		unset( $response );	//clean up
		die();	//wp_die() is not good if you're sending json content
	}

	/**
	 * This function is called by WP to handle our AJAX requests
	 */
	public function save_plugin_options() {
		if( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$response = array(
			'nonce' => wp_create_nonce( parent::plugin_id . '-nonce' ),	//lets refresh the nonce for our next ajax call
			'error' => 1	//lets assume its an error
		);

		//check & see if we have the values
		if( ! isset($_POST['option_name']) || ! isset($_POST['option_value']) || ! check_ajax_referer( parent::plugin_id . '-nonce', '_ig_sh_nonce', false ) ) {
			$response['msg'] = $this->_create_ajax_message( 'Invalid request sent, please refresh the page and try again', 'error' );
			$this->_send_ajax_response($response);
		}

		$_POST['option_name'] = sanitize_text_field( strtolower( trim($_POST['option_name']) ) );
		$_POST['option_value'] = sanitize_text_field( strtolower( trim($_POST['option_value']) ) );

		if( ! $this->_is_yesno( $_POST['option_value'] ) || ! $this->_get_option( $_POST['option_name'] ) ) {
			$response['msg'] = $this->_create_ajax_message( 'Invalid request sent, please refresh the page and try again', 'error' );
			$this->_send_ajax_response($response);
		} else {
			$this->_set_option( $_POST['option_name'], $_POST['option_value'] );
			$response['error'] = 0;
			$response['msg'] = $this->_create_ajax_message( 'Option Saved successfully', 'success' );
		}

		$this->_send_ajax_response($response);
	}

	/**
	 * function to enqueue stuff in wp-admin head
	 */
	public function enqueue_stuff($hook) {
		if( ! is_admin() || $hook !== 'settings_page_' . parent::plugin_id . '-page' ) {
			//page is not in wp-admin or not our settings page, so bail out
			return false;
		}

		//load stylesheet
		wp_enqueue_style( parent::plugin_id . '-admin', plugins_url( 'css/admin.css', __FILE__ ), false );
		//load jQuery::msg stylesheet
		wp_enqueue_style( parent::plugin_id . '-jquery-msg', plugins_url( 'css/jquery.msg.css', __FILE__ ), false );

		//load jQuery::center script
		wp_enqueue_script( parent::plugin_id . '-jquery-center', plugins_url( 'js/jquery.center.min.js', __FILE__ ), array( 'jquery' ) );
		//load jQuery::msg script
		wp_enqueue_script( parent::plugin_id . '-jquery-msg', plugins_url( 'js/jquery.msg.min.js', __FILE__ ), array( parent::plugin_id . '-jquery-center' ) );
		//load our script
		wp_enqueue_script( parent::plugin_id . '-admin', plugins_url( 'js/admin.js', __FILE__ ), array( parent::plugin_id . '-jquery-msg' ) );

		//some vars in JS that we'll need
		wp_localize_script( parent::plugin_id . '-admin', 'ig_sh', array(
			'plugins_url' => plugins_url( '', __FILE__ ) . '/',
			'nonce' => wp_create_nonce( parent::plugin_id . '-nonce' )
		) );
	}

//end of class
}


//EOF
