<?php
/**
 * Class for validating plugin values
 *
 * @package iG_Syntax_Hiliter
 *
 * @author Amit Gupta <https://amitgupta.in/>
 *
 * @since 2015-07-22
 */

namespace iG\Syntax_Hiliter;

use iG\Syntax_Hiliter\Traits\Singleton;

/**
 * Validators for the plugin's stored option values.
 *
 * This is the one class which decides what a setting may hold. `Option` stores and
 * reads; it does not judge. Keeping the two apart is what stops a second opinion
 * about a value growing somewhere else, and it is why the defaults live here beside
 * the values they fall back from rather than in the class doing the writing.
 *
 * Nothing here sanitizes in the sense of rewriting an unrecognised value into a
 * tidier one. `sanitize_title()` was doing that until 6.0 and it was never a check:
 * `evil` is a perfectly good slug, so a tampered request put `evil` into the settings
 * unaltered while a legitimate value with a space in it was quietly reshaped. A value
 * is either one this setting accepts or it is replaced by the setting's default.
 */
class Validate {

	use Singleton;

	/**
	 * What each setting accepts, and what it is when the site has never set it.
	 *
	 * A setting which is not named here cannot be checked and so cannot be saved,
	 * which is what makes adding one and forgetting this map a visible failure rather
	 * than a silent hole.
	 *
	 * `allowed` is NULL where the list is not fixed and has to be resolved when it is
	 * asked for. A setting whose list is `yes`/`no` is read as a flag, so that a
	 * caller holding a boolean gets the same answer as one holding the word.
	 *
	 * @var array
	 */
	protected array $_option_values = [
		'theme'                => [
			'allowed' => null,    //the bundled themes readable on disk, plus `none`
			'default' => Asset_Manager::DEFAULT_THEME,
		],
		'toolbar'              => [
			'allowed' => [ 'yes', 'no' ],
			'default' => 'yes',    //show toolbar above hilited code by default
		],
		'copy_code'            => [
			'allowed' => [ 'yes', 'no' ],
			'default' => 'yes',    //show the copy to clipboard button by default
		],
		'show_line_numbers'    => [
			'allowed' => [ 'yes', 'no' ],
			'default' => 'yes',    //show line numbers in code by default
		],
		'normalize_whitespace' => [
			'allowed' => [ 'yes', 'no' ],
			'default' => 'no',    //don't strip common indentation from code by default
		],
		'hilite_comments'      => [
			'allowed' => [ 'yes', 'no' ],
			'default' => 'yes',    //hilite code posted in comments by default
		],
		'gist_in_comments'     => [
			'allowed' => [ 'yes', 'no' ],
			'default' => 'no',    //don't embed Github Gist in comments by default
		],
	];

	/**
	 * Class constructor
	 */
	protected function __construct() {}

	/**
	 * This function checks whether the passed value is YES/NO or not. If it is then
	 * it returns TRUE else FALSE. The parameter accepts only string.
	 *
	 * @param mixed $value Value to check.
	 *
	 * @return bool
	 */
	public function is_yesno( $value ): bool {

		if ( ! is_string( $value ) ) {
			return false;
		}

		$value = strtolower( trim( $value ) );

		return ( in_array( $value, [ 'yes', 'no' ], true ) );

	}

	/**
	 * Method to read a value as a yes/no setting.
	 *
	 * `TRUE`, `1`, `'1'`, `'on'` and `'yes'` all mean the same thing to the person who
	 * set them, and so do their opposites; anything which is neither is not a flag at
	 * all and takes the fallback. Versions of this plugin up to 3.5 stored these
	 * settings as real booleans and 4.0 onwards stored the words, so both spellings
	 * turn up in the wild and both have to read the same way.
	 *
	 * The return is always `yes` or `no`, whatever came in.
	 *
	 * @param mixed  $value    Value to read.
	 * @param string $fallback Value to use when the one in hand cannot be read as a flag.
	 *
	 * @return string
	 */
	public function to_yesno( $value, string $fallback ): string {

		$flag = ( is_scalar( $value ) ) ? filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) : null;

		if ( is_null( $flag ) ) {
			return $fallback;
		}

		return ( true === $flag ) ? 'yes' : 'no';

	}

	/**
	 * Method to get the values a setting accepts.
	 *
	 * @param string $name Option name.
	 *
	 * @return array The accepted values, or an empty array when this plugin has no such setting.
	 */
	public function get_option_values( string $name ): array {

		if ( ! array_key_exists( $name, $this->_option_values ) ) {
			return [];
		}

		$allowed = $this->_option_values[ $name ]['allowed'];

		if ( is_array( $allowed ) ) {
			return $allowed;
		}

		//NULL means the list is not fixed, and `theme` is the only setting which says so:
		//what it accepts is whatever is readable on disk, plus the choice to load no
		//stylesheet at all
		if ( 'theme' === $name ) {
			return array_merge( array_keys( Asset_Manager::get_themes() ), [ Asset_Manager::THEME_NONE ] );
		}

		return [];    //declared as unfixed with nothing here able to resolve it, so nothing is accepted

	}

	/**
	 * Method to get the value a setting has when the site has never set it.
	 *
	 * @param string $name Option name.
	 *
	 * @return string The default, or an empty string when this plugin has no such setting.
	 */
	public function get_option_default( string $name ): string {
		return $this->_option_values[ $name ]['default'] ?? '';
	}

	/**
	 * Method to get every setting's default, keyed by name.
	 *
	 * This is the set of settings this plugin has, and `Option` builds itself from it.
	 *
	 * @return array
	 */
	public function get_option_defaults(): array {
		return array_map( static fn ( array $setting ): string => $setting['default'], $this->_option_values );
	}

	/**
	 * Method to read a value as a setting, whatever it arrives as.
	 *
	 * This always hands back something the setting accepts. A value which is not one
	 * of them is replaced by that setting's default rather than refused, because the
	 * callers which reach this — migration, WP-CLI, a third party — have nobody to
	 * report a refusal to, and storing something unrecognised is the worse outcome.
	 *
	 * The settings screen never reaches that fallback: `Admin::validate_option_value()`
	 * answers 400 for a value outside the setting's choices, so a caller who sent
	 * something wrong is told so and the control on screen goes back to what it was.
	 * The two are doing different jobs and both are wanted.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value as it arrived.
	 *
	 * @return string A value this setting accepts, or an empty string when this plugin has no such setting.
	 */
	public function get_sanitized_option_value( string $name, $value ): string {

		if ( ! array_key_exists( $name, $this->_option_values ) ) {
			return '';
		}

		$default = $this->get_option_default( $name );
		$allowed = $this->get_option_values( $name );

		if ( [ 'yes', 'no' ] === $allowed ) {
			return $this->to_yesno( $value, $default );
		}

		$value = ( is_scalar( $value ) ) ? strtolower( trim( (string) $value ) ) : '';

		/*
		 * The default is handed back without being looked up in the list, because a
		 * setting's list can be shorter than its own default: a bundled theme whose
		 * stylesheet is missing from disk is not offered, and checking the fallback
		 * would leave the theme setting unsavable on that site rather than merely
		 * unable to hold that one theme. What is stored not being on disk is a case
		 * `Asset_Manager::_enqueue_theme()` already reads and falls back from.
		 */
		return ( in_array( $value, $allowed, true ) ) ? $value : $default;

	}

}    //end of class

//EOF
