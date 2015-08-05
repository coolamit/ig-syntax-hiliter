<?php
/**
 * Class containing collection of helper methods.
 *
 * @author Amit Gupta <http://amitgupta.in/>
 */

namespace iG\Syntax_Hiliter;

use \ErrorException;

class Helper {

	/**
	 * Method to check if an array is associative array or not.
	 *
	 * @param array $array_to_check Array which is to be checked
	 * @return boolean Returns TRUE if the array is associative else FALSE. Even a single numeric key would make this function return FALSE.
	 */
	public static function is_associative_array( array $array_to_check ) {
		return ! (bool) count( array_filter( array_keys( $array_to_check ), 'is_numeric' ) );
	}

	/**
	 * Method to render a template and return the markup
	 *
	 * @param string $template File path to the template file
	 * @param array $vars Associative array of values which are to be injected into the template. The array keys become var names and key values respective var values.
	 * @return string Template markup ready for output
	 */
	public static function render_template( $template, array $vars = array() ) {

		if ( empty( $template ) ) {
			throw new ErrorException( 'Template file path not defined, this code is not psychic!' );
		}

		if ( ! file_exists( $template ) ) {
			throw new ErrorException( 'Template ' . basename( $template ) . ' does not exist' );
		}

		if ( ! empty( $vars ) && ! static::is_associative_array( $vars ) ) {
			throw new ErrorException( 'Variables for the template must be passed as an associative array' );
		}

		extract( $vars, EXTR_SKIP );

		ob_start();
		require $template;
		return ob_get_clean();

	}

	/**
	 * This method returns the URL of an asset if relative path to asset is passed else
	 * the URL to assets folder.
	 *
	 * @param string $asset_path Optional asset path relative from assets folder
	 * @return string URL to asset or asset folder
	 */
	public static function get_asset_url( $asset_path = '' ) {
		return plugins_url( sprintf( '/assets/%s', ltrim( $asset_path, '/' ) ), dirname( __DIR__ ) );
	}

	public static function human_time_diff( $from = 0, $to = 0 ) {

		$from = intval( $from );
		$to = ( intval( $to ) < 1 ) ? time() : intval( $to );

		$divs = array(
			'second' => 60,
			'minute' => 60,
			'hour'   => 24,
			'day'    => 7,
			'week'   => ( 30 / 7 ),
			'month'  => 12,
		);

		$in_future = ( $to > $from );

		$diff = abs( intval( $from - $to ) );

		$diff_unit = 'year';

		foreach ( $divs as $div_unit => $div_val ) {

			if ( $diff < $div_val ) {
				$diff_unit = $div_unit;
				break;
			}

			$diff = $diff / $div_val;

		}

		$diff = ( $diff < 1 ) ? 1 : intval( $diff );

		$diff_unit .= ( $diff > 1 ) ? 's' : '';
		$time_whence = ( $in_future ) ? 'from now' : 'ago';

		return sprintf( '%d %s %s', $diff, $diff_unit, $time_whence );

	}

	/**
	 * This function accepts two arrays, $new & $default. The common items
	 * keep value from $new, any extra items in $new are discarded
	 * & extra items in $default are kept as is. This is different from wp_parse_args()
	 * which would keep all values from $new & $default and override common values
	 * in $default.
	 *
	 * @param array $default Array containing default values which are to be overridden
	 * @param array $new Array containing new values
	 * @return array An array containing new values from $new which override existing values in $default
	 */
	public static function array_merge( array $default = array(), array $new = array() ) {

		if ( empty( $default ) ) {
			return false;
		}

		if ( empty( $new ) ) {
			return $default;
		}

		foreach( $default as $key => $value ) {

			if ( ! array_key_exists( $key, $new ) ) {
				//this key doesn't exist in $new array, so skip to next
				continue;
			}

			$default[ $key ] = $new[ $key ];

		}

		return $default;

	}

	/**
	 * This function accepts a boolean value and converts it into "yes" if value
	 * is TRUE else "no"
	 *
	 * @param bool $value
	 * @return string
	 */
	public static function bool_to_yesno( $value ) {
		if ( ! is_bool( $value ) ) {
			return $value;
		}

		$value = ( $value === true ) ? 'yes' : 'no';

		return $value;
	}

	/**
	 * This function accepts a string value and converts it into TRUE if value
	 * is 'yes' else FALSE
	 *
	 * @param string $value
	 * @return bool
	 */
	public static function yesno_to_bool( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}

		$value = ( strtolower( trim( $value ) ) == 'yes' ) ? true : false;

		return $value;
	}

}	//end of class



//EOF