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
	 * A key which exists but holds NULL — which only a hand edited option or a
	 * third party can produce — reads as this setting's default. Every caller of
	 * this expects a usable value, a `yes`/`no` or a theme name, and none of them
	 * is written to receive NULL.
	 *
	 * @param string $name Option name.
	 *
	 * @return mixed The stored value, this setting's default when there is none, or FALSE when the plugin has no such setting.
	 */
	public function get( string $name ) {

		if ( empty( $name ) || ! array_key_exists( $name, $this->_default_options ) ) {
			return false;
		}

		return $this->_options[ $name ] ?? $this->_default_options[ $name ];

	}

	/**
	 * Method to get the value a setting has when the site has never changed it.
	 *
	 * @param string $name Option name.
	 *
	 * @return string The default, or an empty string when the plugin has no such setting.
	 */
	public function get_default( string $name ): string {

		$value = $this->_default_options[ $name ] ?? '';

		return ( is_scalar( $value ) ) ? (string) $value : '';

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
	 * The stored array is read again immediately before it is written, and the one
	 * setting named here is applied to what was read. The snapshot this object took
	 * when it was built is never what gets written: the settings screen saves one
	 * setting per request, so two settings changed in quick succession are two
	 * overlapping requests, and a request which wrote its own snapshot back would
	 * put the other request's setting back the way it was before. Both requests
	 * would report success and one of the two settings would not be in the
	 * database.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value to save.
	 *
	 * @return bool Returns TRUE if option is successfully saved else FALSE
	 */
	public function save( string $name, $value ): bool {

		//the set of settings this plugin has is what decides whether a name may be saved,
		//rather than the array in hand: a key which exists but holds NULL is still one of
		//this plugin's settings, and must not be left unsavable
		if ( empty( $name ) || ! array_key_exists( $name, $this->_default_options ) ) {
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

		if ( empty( $value ) && true !== $can_be_empty ) {
			return false;
		}

		$this->_load_all_options();    //whatever is stored now, not what was stored when this object was built

		$this->_options[ $name ] = $value;

		return $this->commit();    //lets save in DB as well

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
