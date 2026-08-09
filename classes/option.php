<?php
/**
 * Class for fetching and saving plugin options
 *
 * @package iG_Syntax_Hiliter
 *
 * @author Amit Gupta <https://amitgupta.in/>
 */

namespace iG\Syntax_Hiliter;

use iG\Syntax_Hiliter\Traits\Singleton;

/**
 * The plugin's settings, as one option array with a known set of keys.
 *
 * Only the keys below exist: a value stored under any other name is dropped the
 * next time the options are saved.
 */
class Option {

	use Singleton;

	/**
	 * An array which contains plugin options.
	 *
	 * @var array
	 */
	protected $_options;

	/**
	 * An array which contains default plugin options.
	 *
	 * @var array
	 */
	protected $_default_options = [
		'theme'                => Asset_Manager::DEFAULT_THEME,    //base name of the bundled theme stylesheet, or 'none' for no stylesheet
		'toolbar'              => 'yes',    //show toolbar above hilited code by default
		'copy_code'            => 'yes',    //show the copy to clipboard button by default
		'show_line_numbers'    => 'yes',    //show line numbers in code by default
		'normalize_whitespace' => 'no',    //don't strip common indentation from code by default
		'hilite_comments'      => 'yes',    //hilite code posted in comments by default
		'gist_in_comments'     => 'no',    //don't embed Github Gist in comments by default
	];

	/**
	 * Class constructor
	 */
	protected function __construct() {
		$this->_load_all_options();
	}

	/**
	 * Method to load the plugin's options from the DB.
	 *
	 * @return void
	 */
	protected function _load_all_options(): void {

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
	 * @param string $name Option name.
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
	public function get_all(): array {
		return $this->_options;
	}

	/**
	 * Method to save an option. It takes care of sanitizing the value before
	 * saving it and saves an option only if the option name already exists.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value to save.
	 *
	 * @return bool Returns TRUE if option is successfully saved else FALSE
	 */
	public function save( string $name, $value ): bool {

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
	public function commit(): bool {

		if ( empty( $this->_options ) ) {
			return false;
		}

		update_option( Base::PLUGIN_ID . '-options', $this->_options );

		return true;

	}

}    //end of class

//EOF
