<?php
/**
 * Class for validating plugin values
 *
 * @author Amit Gupta <http://amitgupta.in/>
 *
 * @since 2015-07-22
 */

namespace iG\Syntax_Hiliter;

class Validate extends Singleton {

	/**
	 * @var array Allowed values for strict mode option
	 */
	public static $strict_mode_values = array( 'always', 'maybe', 'never' );

	/**
	 * Protected constructor, Singleton implemented
	 */
	protected function __construct() {}

	/**
	 * This function checks whether the passed value is YES/NO or not. If it is then
	 * it returns TRUE else FALSE. The parameter accepts only string.
	 *
	 * @return boolean
	 */
	public function is_yesno( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}

		$value = strtolower( trim( $value ) );

		if ( $value === "yes" || $value === "no" ) {
			return true;
		}

		return false;
	}

	/**
	 * This function sanitizes the value passed as per the allowed values for strict mode option.
	 * If the passed value does not match one of the possible values then it returns a default value.
	 *
	 * @param string $value Value for strict mode option
	 * @return string
	 */
	public function sanitize_strict_mode_values( $value ) {
		$value = sanitize_title( strtolower( trim( $value ) ) );

		if ( empty( $value ) || ! is_string( $value ) || ! in_array( $value, static::$strict_mode_values ) ) {
			$value = static::$strict_mode_values[0];
		}

		return $value;
	}

	/**
	 * Sanitize languages array
	 *
	 * @param array $values An array containing language names which need to be validated/sanitized
	 * @param array $languages An array of all language names
	 * @return array A sanitized $values array, non-existing and duplicate language names removed
	 */
	public function languages( array $values = array(), array $languages = array() ) {

		if ( empty( $values ) || empty( $languages ) ) {
			return array();
		}

		$values = array_filter( array_unique( array_map( 'sanitize_file_name', array_map( 'trim', $values ) ) ) );

		$values_count = count( $values );
		$clean_values = array();

		for ( $i = 0; $i < $values_count; $i++ ) {

			if ( in_array( $values[ $i ], $languages ) ) {
				$clean_values[] = $values[ $i ];
			}

		}

		unset( $values_count );

		return $clean_values;

	}

}	//end of class


//EOF