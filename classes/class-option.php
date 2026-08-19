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
	 * Filled from `Validate`, which is where the settings are declared along with the
	 * values each of them accepts. This class does not keep a second copy of that
	 * list: two declarations of the same fact are two things to keep in step, and the
	 * one which drifts is always the one nothing reads.
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
	 * Whatever arrives is read as this setting through `Validate`, which hands back a
	 * value the setting accepts or that setting's default. Nothing unrecognised is
	 * ever written, so a request edited on its way here cannot put an arbitrary value
	 * into the settings — which is what the old `sanitize_title()` call let through,
	 * since a made up value is usually a perfectly good slug.
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
	public function save( string $name, mixed $value ): bool {

		//the set of settings this plugin has is what decides whether a name may be saved,
		//rather than the array in hand: a key which exists but holds NULL is still one of
		//this plugin's settings, and must not be left unsavable
		if ( empty( $name ) || ! array_key_exists( $name, $this->_default_options ) ) {
			return false;
		}

		$value = Validate::get_instance()->get_sanitized_option_value( $name, $value );

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
