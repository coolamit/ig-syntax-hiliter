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
 * The one class that decides what a setting may hold; `Option` stores and reads.
 * A value is either one the setting accepts or it is replaced by that setting's default.
 */
class Validate {

	use Singleton;

	/**
	 * What each setting accepts, and what it is when the site has never set it.
	 *
	 * A setting not named here cannot be checked and so cannot be saved. `allowed`
	 * is NULL where the list is resolved at read time.
	 *
	 * @var array
	 */
	protected array $_option_values = [
		'theme'             => [
			'allowed' => null,
			'default' => Themes::DEFAULT_THEME,
		],
		'font'              => [
			'allowed' => null,
			'default' => Fonts::FONT_NONE,
		],
		'toolbar'           => [
			'allowed' => [ 'yes', 'no' ],
			'default' => 'yes',
		],
		'copy_code'         => [
			'allowed' => [ 'yes', 'no' ],
			'default' => 'yes',
		],
		'show_line_numbers' => [
			'allowed' => [ 'yes', 'no' ],
			'default' => 'yes',
		],
		'match_braces'      => [
			'allowed' => [ 'yes', 'no' ],
			'default' => 'yes',    // nothing shows until a reader hovers
		],
		'rainbow_braces'    => [
			'allowed' => [ 'yes', 'no' ],
			'default' => 'no',    // repaints every box on the site
		],
		'hilite_comments'   => [
			'allowed' => [ 'yes', 'no' ],
			'default' => 'yes',
		],
		'gist_in_comments'  => [
			'allowed' => [ 'yes', 'no' ],
			'default' => 'no',
		],
		'gist_limit_height' => [
			'allowed' => [ 'yes', 'no' ],
			'default' => 'yes',
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
	public function is_yesno( mixed $value ): bool {

		if ( ! is_string( $value ) ) {
			return false;
		}

		$value = strtolower( trim( $value ) );

		return ( in_array( $value, [ 'yes', 'no' ], true ) );

	}

	/**
	 * Method to read a value as a yes/no setting.
	 *
	 * `TRUE`, `1`, `'1'`, `'on'` and `'yes'` all mean the same; versions up to 3.5 stored
	 * real booleans and 4.0 onwards the words, so both turn up. Always returns `yes` or `no`.
	 *
	 * @param mixed  $value    Value to read.
	 * @param string $fallback Value to use when the one in hand cannot be read as a flag.
	 *
	 * @return string
	 */
	public function to_yesno( mixed $value, string $fallback ): string {

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

		// NULL means the list is resolved here, from the same source the choices are offered from
		if ( 'theme' === $name ) {
			return array_merge( array_keys( Themes::get_instance()->get_themes() ), [ Themes::THEME_NONE ] );
		}

		if ( 'font' === $name ) {
			return array_merge( array_keys( Fonts::get_instance()->get_fonts() ), [ Fonts::FONT_NONE ] );
		}

		return [];    // declared as unfixed with nothing here able to resolve it, so nothing is accepted

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
	 * Always returns something the setting accepts; an unrecognised value becomes the
	 * default rather than a refusal, because migration, WP-CLI and third parties have
	 * nobody to report to. The settings screen answers 400 instead, via
	 * `Admin::validate_option_value()`.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value as it arrived.
	 *
	 * @return string A value this setting accepts, or an empty string when this plugin has no
	 *                such setting.
	 */
	public function get_sanitized_option_value( string $name, mixed $value ): string {

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
		 * The default is not looked up in the list: a bundled theme missing from disk is
		 * not offered, and checking the fallback would make the theme setting unsavable on
		 * that site.
		 */
		return ( in_array( $value, $allowed, true ) ) ? $value : $default;

	}

} // end of class

// EOF
