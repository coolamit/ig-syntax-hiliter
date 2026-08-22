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
	protected array $_options;

	/**
	 * An array which contains default plugin options.
	 *
	 * Filled from `Validate`, which declares the settings and their accepted values.
	 *
	 * @var array
	 */
	protected array $_default_options = [];

	/**
	 * Class constructor
	 */
	protected function __construct() {

		$this->_default_options = Validate::get_instance()->get_option_defaults();

		$this->_load_all_options();

	}

	/**
	 * Method to load the plugin's options from the DB.
	 *
	 * @return void
	 */
	protected function _load_all_options(): void {

		$db_options = get_option( Base::PLUGIN_ID . '-options', false );

		if ( empty( $db_options ) || ! is_array( $db_options ) ) {
			$db_options = [];
		}

		$this->_options = Helper::array_merge( $this->_default_options, $db_options );

	}

	/**
	 * Getter method to fetch a single option by name
	 *
	 * A key holding NULL reads as the setting's default.
	 *
	 * @param string $name Option name.
	 *
	 * @return mixed The stored value, this setting's default when there is none, or FALSE when
	 *               the plugin has no such setting.
	 */
	public function get( string $name ): mixed {

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
	 * Method to save an option. `Validate` decides what the value may be and this
	 * saves an option only if the option name already exists.
	 *
	 * `Validate` decides the value, so nothing unrecognised is ever written. The stored
	 * array is re-read immediately before writing: the screen saves one setting per
	 * request and writing a stale snapshot would revert a concurrent save.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value to save.
	 *
	 * @return bool Returns TRUE if option is successfully saved else FALSE
	 */
	public function save( string $name, mixed $value ): bool {

		// checked against the declared settings, not the array in hand, so a key holding NULL
		// is still savable
		if ( empty( $name ) || ! array_key_exists( $name, $this->_default_options ) ) {
			return false;
		}

		$value = Validate::get_instance()->get_sanitized_option_value( $name, $value );

		$this->_load_all_options();    // whatever is stored now, not what was stored when this object was built

		$this->_options[ $name ] = $value;

		return $this->commit();

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

} // end of class

// EOF
