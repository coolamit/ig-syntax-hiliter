<?php
/**
 * Class for fetching and saving plugin options
 *
 * @author Amit Gupta <http://amitgupta.in/>
 */

namespace iG\Syntax_Hiliter;

class Option extends Singleton {

	/**
	 * @var Array An array which contains plugin options
	 */
	protected $_options;

	/**
	 * @var Array An array which contains default plugin options
	 */
	protected $_default_options = array(
		'fe-styles'         => 'yes',				//use plugin CSS to style hilited code box by default
		'strict_mode'       => 'maybe',				//don't use GeSHi strict mode always
		'non_strict_mode'   => array( 'php' ),		//langauges where strict mode is disabled
		'toolbar'           => 'yes',				//show toolbar above hilited code by default
		'plain_text'        => 'yes',				//show option to view code in plain text by default
		'show_line_numbers' => 'yes',				//show line numbers in code by default
		'hilite_comments'   => 'yes',				//hilite code posted in comments by default
		'link_to_manual'    => 'no',				//don't link keywords to manual by default
		'gist_in_comments'  => 'no',				//don't embed Github Gist in comments by default
	);

	protected function __construct() {
		$this->_load_all_options();
	}

	/**
	 * @return void
	 */
	protected function _load_all_options() {
		//fetch options array from wp_options & then do a safe merge with default options
		$db_options = get_option( Base::PLUGIN_ID . '-options', false );

		if ( empty( $db_options ) || ! is_array( $db_options ) ) {
			$db_options = array();
		}

		$this->_options = Helper::array_merge( $this->_default_options, $db_options );
	}

	/**
	 * This function is just a wrapper to fetch an option from $_options class var,
	 * since direct access to the $_options var is not allowed
	 */
	public function get( $option_name ) {
		if ( empty( $option_name ) || ! is_string( $option_name ) ) {
			return false;
		}

		if ( isset( $this->_options[ $option_name ] ) ) {
			return $this->_options[ $option_name ];
		}

		return false;
	}

	public function get_all() {
		return $this->_options;
	}

	/**
	 * This function is for setting a value in the $_options class var as direct
	 * access to it isn't allowed. It takes care of sanitizing the value before
	 * putting it in $_options & saves only if the option name exists already.
	 */
	public function save( $option_name, $option_value ) {

		if ( empty( $option_name ) || ! is_string( $option_name ) || ! isset( $this->_options[ $option_name ] ) ) {
			return false;
		}

		$can_be_empty = false;

		if ( is_array( $option_value ) ) {
			$option_value = array_map( 'strtolower', array_map( 'trim', array_map( 'sanitize_title', $option_value ) ) );
			$can_be_empty = true;
		} else {
			$option_value = strtolower( trim( sanitize_title( $option_value ) ) );
		}

		if ( ! empty( $option_value ) || $can_be_empty === true ) {
			$this->_options[ $option_name ] = $option_value;
			$this->commit();	//lets save in DB as well

			return true;
		}

		return false;

	}

	/**
	 * This function is for saving the $_options class var in the DB, can be called anytime
	 * or in the class destructor
	 *
	 * @return bool
	 */
	public function commit() {
		if ( empty( $this->_options ) ) {
			return false;
		}

		update_option( Base::PLUGIN_ID . '-options', $this->_options );

		return true;
	}

}	//end of class


//EOF