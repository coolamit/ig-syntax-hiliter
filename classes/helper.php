<?php
/**
 * Class containing collection of helper methods.
 *
 * @package iG_Syntax_Hiliter
 *
 * @author Amit Gupta <https://amitgupta.in/>
 */

namespace iG\Syntax_Hiliter;

use ErrorException;

/**
 * Stateless utilities shared across the plugin.
 */
class Helper {

	/**
	 * Method to check if an array is associative array or not.
	 *
	 * @param array $array_to_check Array which is to be checked.
	 *
	 * @return bool Returns TRUE if the array is associative else FALSE. Even a single numeric key would make this function return FALSE.
	 */
	public static function is_associative_array( array $array_to_check ): bool {
		return ! (bool) count( array_filter( array_keys( $array_to_check ), 'is_numeric' ) );
	}

	/**
	 * Method to check for existence of a file
	 *
	 * @param string $path Physical path of the file which is to be checked.
	 *
	 * @return bool Returns TRUE if file path is valid else FALSE
	 */
	public static function is_file_path_valid( string $path ): bool {
		return ( ! empty( $path ) && file_exists( $path ) && validate_file( $path ) === 0 );
	}

	/**
	 * Method to render a template and return the markup
	 *
	 * @param string $template File path to the template file.
	 * @param array  $vars     Associative array of values which are to be injected into the template. The array keys become var names and key values respective var values.
	 * @param bool   $output   Optional - Set to TRUE to print out parsed template content, FALSE to return it as string.
	 *
	 * @return string|void
	 *
	 * @throws \ErrorException If the template path is empty or invalid, or if the vars are not an associative array.
	 */
	public static function render_template( string $template, array $vars = [], bool $output = false ) {

		if ( empty( $template ) ) {
			throw new ErrorException( 'Template file path not defined, this code is not psychic!' );
		}

		if ( ! static::is_file_path_valid( $template ) ) {
			throw new ErrorException(
				sprintf(
					'Template %s does not exist',
					esc_html( basename( $template ) )
				)
			);
		}

		if ( ! empty( $vars ) && ! static::is_associative_array( $vars ) ) {
			throw new ErrorException( 'Variables for the template must be passed as an associative array' );
		}

		extract( $vars, EXTR_SKIP );    // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Template vars are named by the caller and this is how they reach the template.

		ob_start();
		require $template;
		$html = ob_get_clean();

		if ( true === $output ) {
			echo $html;    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaping is the template's job.
			return;
		}

		return $html;

	}

	/**
	 * Method to remove forward slash from the beginning of a string
	 *
	 * @param string $path String from which forward slash is to be removed from beginning.
	 *
	 * @return string String with forward slash removed from beginning
	 */
	public static function unleadingslashit( string $path ): string {
		return ltrim( $path, '/' );
	}

	/**
	 * Method to get the URL of an asset if relative path to asset is passed else the URL to assets folder.
	 *
	 * @param string $path Optional asset path relative from assets folder.
	 *
	 * @return string URL to asset or asset folder
	 */
	public static function get_asset_url( string $path = '' ): string {
		return plugins_url(
			sprintf( '/assets/%s', static::unleadingslashit( $path ) ),
			__DIR__
		);
	}

	/**
	 * This function accepts two arrays, $new & $default. The common items
	 * keep value from $new, any extra items in $new are discarded
	 * & extra items in $default are kept as is. This is different from wp_parse_args()
	 * which would keep all values from $new & $default and override common values
	 * in $default.
	 *
	 * @param array $defaults Array containing default values which are to be overridden.
	 * @param array $updates  Array containing new values.
	 *
	 * @return array An array containing new values from $updates which override existing values in $defaults
	 */
	public static function array_merge( array $defaults = [], array $updates = [] ): array {

		if ( empty( $defaults ) ) {
			return [];
		}

		if ( empty( $updates ) ) {
			return $defaults;
		}

		foreach ( $defaults as $key => $value ) {

			if ( ! array_key_exists( $key, $updates ) ) {
				//this key doesn't exist in $updates array, so skip to next
				continue;
			}

			$defaults[ $key ] = $updates[ $key ];

		}

		return $defaults;

	}

	/**
	 * This function accepts a boolean value and converts it into "yes" if value
	 * is TRUE else "no"
	 *
	 * @param mixed $value Value to convert. Anything which is not a bool is returned untouched.
	 *
	 * @return mixed
	 */
	public static function bool_to_yesno( $value ) {

		if ( ! is_bool( $value ) ) {
			return $value;
		}

		$value = ( true === $value ) ? 'yes' : 'no';

		return $value;

	}

}    //end of class

//EOF
