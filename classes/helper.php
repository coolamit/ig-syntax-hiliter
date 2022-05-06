<?php
/**
 * Class containing collection of helper methods.
 *
 * @author Amit Gupta <https://amitgupta.in/>
 */

namespace iG\Syntax_Hiliter;

use \ErrorException;

class Helper {

	/**
	 * Method to check if an array is associative array or not.
	 *
	 * @param array $array_to_check Array which is to be checked
	 *
	 * @return bool Returns TRUE if the array is associative else FALSE. Even a single numeric key would make this function return FALSE.
	 */
	public static function is_associative_array( array $array_to_check ) : bool {
		return ! (bool) count( array_filter( array_keys( $array_to_check ), 'is_numeric' ) );
	}

	/**
	 * Method to check for existence of a file
	 *
	 * @param string $path Physical path of the file which is to be checked
	 *
	 * @return bool Returns TRUE if file path is valid else FALSE
	 */
	public static function is_file_path_valid( string $path ) : bool {
		return ( ! empty( $path ) && file_exists( $path ) && validate_file( $path ) === 0 );
	}

	/**
	 * Method to render a template and return the markup
	 *
	 * @param string $template File path to the template file
	 * @param array  $vars     Associative array of values which are to be injected into the template. The array keys become var names and key values respective var values.
	 * @param bool   $output   Optional - Set to TRUE to print out parsed template content, FALSE to return it as string
	 *
	 * @return string|void
	 *
	 * @throws \ErrorException
	 */
	public static function render_template( string $template, array $vars = [], bool $output = false ) {

		if ( empty( $template ) ) {
			throw new ErrorException( 'Template file path not defined, this code is not psychic!' );
		}

		if ( ! static::is_file_path_valid( $template ) ) {
			throw new ErrorException(
				sprintf(
					'Template %s does not exist',
					basename( $template )
				)
			);
		}

		if ( ! empty( $vars ) && ! static::is_associative_array( $vars ) ) {
			throw new ErrorException( 'Variables for the template must be passed as an associative array' );
		}

		extract( $vars, EXTR_SKIP );

		ob_start();
		require $template;
		$html = ob_get_clean();

		if ( true === $output ) {
			echo $html;    // phpcs:ignore This is ignored because any escaping to be done should be done in the template itself.
			return;
		}

		return $html;

	}

	/**
	 * Method to remove forward slash from the beginning of a string
	 *
	 * @param string $path String from which forward slash is to be removed from beginning
	 *
	 * @return string String with forward slash removed from beginning
	 */
	public static function unleadingslashit( string $path ) : string {
		return ltrim( $path, '/' );
	}

	/**
	 * Method to add one forward slash at the beginning of a string
	 *
	 * @param string $path String to which forward slash is to be added at beginning
	 *
	 * @return string String with forward slash added at beginning
	 */
	public static function leadingslashit( string $path ) : string {
		return sprintf( '/%s', static::unleadingslashit( $path ) );
	}

	/**
	 * Method to get the URL of an asset if relative path to asset is passed else the URL to assets folder.
	 *
	 * @param string $path Optional asset path relative from assets folder
	 *
	 * @return string URL to asset or asset folder
	 */
	public static function get_asset_url( string $path = '' ) : string {
		return plugins_url(
			sprintf( '/assets/%s', static::unleadingslashit( $path ) ),
			__DIR__
		);
	}

	/**
	 * Method to get difference between two timestamps in a humanly readable format
	 *
	 * @param int $from
	 * @param int $to
	 *
	 * @return string
	 */
	public static function human_time_diff( int $from = 0, int $to = 0 ) : string {

		$to = ( 1 > $to ) ? time() : $to;

		$divs = [
			'second' => 60,
			'minute' => 60,
			'hour'   => 24,
			'day'    => 7,
			'week'   => ( 30 / 7 ),
			'month'  => 12,
		];

		$in_future = ( $to > $from );
		$diff      = abs( intval( $from - $to ) );
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
	 * @param array $new     Array containing new values
	 *
	 * @return array An array containing new values from $new which override existing values in $default
	 */
	public static function array_merge( array $default = [], array $new = [] ) : array {

		if ( empty( $default ) ) {
			return [];
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
	 *
	 * @return string
	 */
	public static function bool_to_yesno( $value ) {

		if ( ! is_bool( $value ) ) {
			return $value;
		}

		$value = ( true === $value ) ? 'yes' : 'no';

		return $value;

	}

	/**
	 * This function accepts a string value and converts it into TRUE if value
	 * is 'yes' else FALSE
	 *
	 * @param string $value
	 *
	 * @return bool
	 */
	public static function yesno_to_bool( $value ) {

		if ( ! is_string( $value ) ) {
			return $value;
		}

		$value = ( strtolower( trim( $value ) ) == 'yes' ) ? true : false;

		return $value;

	}

	/**
	 * Improved version of PHP's inbuilt filter_input() which works well on PHP CLI
	 * as well which PHP default method does not.
	 *
	 * @param int    $type          One of INPUT_GET, INPUT_POST, INPUT_COOKIE, INPUT_SERVER, or INPUT_ENV
	 * @param string $variable_name Name of a variable to get
	 * @param int    $filter        The ID of the filter to apply
	 * @param null   $options       Filter to apply
	 *
	 * @return mixed|bool|null Value of the requested variable on success, FALSE if the filter fails, or NULL if the variable_name variable is not set.
	 *
	 * @since 2017-10-05 Amit Gupta
	 */
	public static function filter_input( int $type, string $variable_name, int $filter = FILTER_DEFAULT, $options = null ) {

		if ( 'cli' !== php_sapi_name() ) {

			/*
			 * Code is not running on PHP CLI and we are in clear.
			 * Use the PHP method and bail out.
			 */
			switch ( $filter ) {

				case FILTER_SANITIZE_STRING:
					$sanitized_variable = sanitize_text_field( filter_input( $type, $variable_name, $filter ) );
					break;

				default:
					$sanitized_variable = filter_input( $type, $variable_name, $filter, $options );
					break;

			}

			return $sanitized_variable;

		}

		/*
		 * Code is running on PHP CLI and INPUT_SERVER returns NULL
		 * even for set vars when run on CLI
		 * @see https://bugs.php.net/bug.php?id=49184
		 *
		 * This is a workaround for that bug till its resolved in PHP binary
		 * which doesn't look to be anytime soon. This is a friggin' 10 year old bug.
		 */

		$input             = '';
		$allowed_html_tags = wp_kses_allowed_html( 'post' );

		/*
		 * Marking the switch() block below to be ignored by PHPCS
		 * because PHPCS squawks on using superglobals like $_POST or $_GET
		 * directly but it can't be helped in this case as this code
		 * is running on CLI.
		 */

		// phpcs:disable

		switch( $type ) {

			case INPUT_GET:
				if ( ! isset( $_GET[ $variable_name ] ) ) {
					return null;
				}

				$input = wp_kses( $_GET[ $variable_name ], $allowed_html_tags );
				break;

			case INPUT_POST:
				if ( ! isset( $_POST[ $variable_name ] ) ) {
					return null;
				}

				$input = wp_kses( $_POST[ $variable_name ], $allowed_html_tags );
				break;

			case INPUT_COOKIE:
				if ( ! isset( $_COOKIE[ $variable_name ] ) ) {
					return null;
				}

				$input = wp_kses( $_COOKIE[ $variable_name ], $allowed_html_tags );
				break;

			case INPUT_SERVER:
				if ( ! isset( $_SERVER[ $variable_name ] ) ) {
					return null;
				}

				$input = wp_kses( $_SERVER[ $variable_name ], $allowed_html_tags );
				break;

			case INPUT_ENV:
				if ( ! isset( $_ENV[ $variable_name ] ) ) {
					return null;
				}

				$input = wp_kses( $_ENV[ $variable_name ], $allowed_html_tags );
				break;

			default:
				return null;
				break;

		}    // end switch()

		// phpcs:enable

		return filter_var( $input, $filter );

	}    //end filter_input()

}    //end of class

//EOF
