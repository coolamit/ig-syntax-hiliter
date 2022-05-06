<?php
/**
 * Class for fetching and saving plugin options
 *
 * @author Amit Gupta <https://amitgupta.in/>
 */

namespace iG\Syntax_Hiliter;

use \iG\Syntax_Hiliter\Traits\Singleton;

class Option {

	use Singleton;

	/**
	 * @var array An array which contains plugin options
	 */
	protected $_options;

	/**
	 * @var array An array which contains default plugin options
	 */
	protected $_default_options = [
		'fe-styles'         => 'yes',    //use plugin CSS to style hilited code box by default
		'strict_mode'       => 'maybe',    //don't use GeSHi strict mode always
		'non_strict_mode'   => [ 'php' ],    //langauges where strict mode is disabled
		'toolbar'           => 'yes',    //show toolbar above hilited code by default
		'plain_text'        => 'yes',    //show option to view code in plain text by default
		'show_line_numbers' => 'yes',    //show line numbers in code by default
		'hilite_comments'   => 'yes',    //hilite code posted in comments by default
		'link_to_manual'    => 'no',    //don't link keywords to manual by default
		'gist_in_comments'  => 'no',    //don't embed Github Gist in comments by default
	];

	/**
	 * Class constructor
	 */
	protected function __construct() {
		$this->_load_all_options();
	}

	/**
	 * @return void
	 */
	protected function _load_all_options() : void {

		//fetch options array from wp_options & then do a safe merge with default options
		$db_options = get_option( Base::PLUGIN_ID . '-options', false );

		if ( empty( $db_options ) || ! is_array( $db_options ) ) {
			$db_options = [];
		}

		$this->_options = Helper::array_merge( $this->_default_options, $db_options );

	}

	/**
	 * Getter method to fetch a single option by name
	 *
	 * @param string $name
	 *
	 * @return mixed
	 */
	public function get( string $name ) {

		if ( ! empty( $name ) && isset( $this->_options[ $name ] ) ) {
			return $this->_options[ $name ];
		}

		return false;

	}

	/**
	 * Method to get all options
	 *
	 * @return array
	 */
	public function get_all() : array {
		return $this->_options;
	}

	/**
	 * Method to save an option. It takes care of sanitizing the value before
	 * saving it and saves an option only if the option name already exists.
	 *
	 * @param string $name
	 * @param mixed  $value
	 *
	 * @return bool Returns TRUE if option is successfully saved else FALSE
	 */
	public function save( string $name, $value ) : bool {

		if ( empty( $name ) || ! isset( $this->_options[ $name ] ) ) {
			return false;
		}

		$can_be_empty = false;

		if ( is_array( $value ) ) {

			$value = array_map( 'sanitize_title', $value );
			$value = array_map( 'trim', $value );
			$value = array_map( 'strtolower', $value );

			$can_be_empty = true;

		} else {
			$value = strtolower( trim( sanitize_title( $value ) ) );
		}

		if ( ! empty( $value ) || true === $can_be_empty ) {

			$this->_options[ $name ] = $value;

			$this->commit();    //lets save in DB as well

			return true;

		}

		return false;

	}

	/**
	 * Method to save options in DB. This can be called anytime and even in the class destructor.
	 *
	 * @return bool
	 */
	public function commit() : bool {

		if ( empty( $this->_options ) ) {
			return false;
		}

		update_option( Base::PLUGIN_ID . '-options', $this->_options );

		return true;

	}

}    //end of class

//EOF
