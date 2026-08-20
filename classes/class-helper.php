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
	 * @return bool Returns TRUE if the array is associative else FALSE. Even a single numeric
	 *              key would make this function return FALSE.
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
	 * @param array  $vars     Associative array of values which are to be injected into the
	 *                         template. The array keys become var names and key values
	 *                         respective var values.
	 * @param bool   $output   Optional - Set to TRUE to print out parsed template content,
	 *                         FALSE to return it as string.
	 *
	 * @return bool|string The markup, or TRUE where it was printed (`void` cannot go in a union).
	 *
	 * @throws \ErrorException If the template path is empty or invalid, or if the vars are not
	 *                         an associative array.
	 */
	public static function render_template( string $template, array $vars = [], bool $output = false ): bool|string {

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
			return true;
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
	 * Method to get the URL of an asset if relative path to asset is passed else the URL to
	 * assets folder.
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
	 * Method to get the absolute path of an asset if relative path to asset is passed else
	 * the path of assets folder.
	 *
	 * The on disk counterpart of `get_asset_url()`, taking the same relative path.
	 *
	 * @param string $path Optional asset path relative from assets folder.
	 *
	 * @return string Absolute path of the asset or of the assets folder
	 */
	public static function get_asset_path( string $path = '' ): string {
		return sprintf(
			'%s/assets/%s',
			dirname( __DIR__ ),
			static::unleadingslashit( $path )
		);
	}

	/**
	 * Method to get an absolute filesystem path inside the plugin directory.
	 *
	 * Resolved from this file's own location, so nothing depends on the plugin
	 * directory's name.
	 *
	 * @param string $path Optional. Path relative to the plugin directory.
	 *
	 * @return string Absolute path, with no trailing slash added of its own.
	 */
	public static function get_path( string $path = '' ): string {
		return plugin_dir_path( __DIR__ ) . static::unleadingslashit( $path );
	}

	/**
	 * Method to get the plugin version.
	 *
	 * The one place `IG_SYNTAX_HILITER_VERSION` is read. The fallback is the caller's to
	 * name: an asset URL wants `'0'`, `Migrate` wants `''` because empty is what tells a
	 * fresh install from an upgrade. Compare with `version_compare()` after normalising,
	 * never numerically.
	 *
	 * @param string $fallback Optional. What to answer where the constant is not defined.
	 *
	 * @return string Version string, eg. `6.0.0`.
	 */
	public static function get_version( string $fallback = '' ): string {
		return ( defined( 'IG_SYNTAX_HILITER_VERSION' ) ) ? (string) IG_SYNTAX_HILITER_VERSION : $fallback;
	}

	/**
	 * Method to build the pattern which matches this plugin's shortcodes.
	 *
	 * WordPress's own pattern plus one addition: a closing tag with doubled brackets
	 * is consumed as code rather than ending the snippet (see `Legacy_Map::escape_tags()`).
	 * The alternative must be first in the inner group, because the surrounding
	 * quantifiers are possessive and order decides. If the substring is not found
	 * exactly once the pattern is returned untouched.
	 *
	 * @param array $tags Shortcode tags to match.
	 *
	 * @return string Pattern without delimiters, the way `get_shortcode_regex()` returns one.
	 */
	public static function get_shortcode_pattern( array $tags ): string {

		$pattern = get_shortcode_regex( $tags );
		$search  = '\[(?!\/\2\])';

		if ( 1 !== substr_count( $pattern, $search ) ) {
			return $pattern;
		}

		return str_replace( $search, '(?:\[\[\/\2\]\]|\[(?!\/\2\]))', $pattern );

	}

	/**
	 * Method to overlay $updates onto $defaults, keeping only the keys $defaults has.
	 *
	 * Unlike `wp_parse_args()`, extra keys in $updates are discarded.
	 *
	 * @param array $defaults Array containing default values which are to be overridden.
	 * @param array $updates  Array containing new values.
	 *
	 * @return array An array containing new values from $updates which override existing
	 *               values in $defaults
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
				continue;
			}

			$defaults[ $key ] = $updates[ $key ];

		}

		return $defaults;

	}

} // end of class

// EOF
