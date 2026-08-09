<?php
/**
 * Map of the shortcode tags this plugin owns.
 *
 * @package iG_Syntax_Hiliter
 */

namespace iG\Syntax_Hiliter;

/**
 * Single source of truth for which shortcode tags belong to this plugin.
 *
 * Ownership is decided by the tag alone, so a tag the plugin has never shipped is
 * never registered and never claimed. That is what keeps it off other plugins'
 * shortcodes.
 */
class Legacy_Map {

	/**
	 * Filter for adding or removing claimed shortcode tags.
	 *
	 * @var string
	 */
	const FILTER_TAGS = 'ig_syntax_hiliter/shortcode_tags';

	/**
	 * The generic tag, which is ours whatever its `language` attribute says.
	 *
	 * @var string
	 */
	const GENERIC_TAG = 'sourcecode';

	/**
	 * Legacy tag to canonical language id.
	 *
	 * The last three keys are shorthand aliases; `[sourcecode]` is absent because it
	 * carries its language in an attribute.
	 *
	 * @var array
	 */
	protected static $_language_map = [
		'actionscript'  => 'actionscript',
		'actionscript3' => 'actionscript',
		'apache'        => 'apacheconf',
		'applescript'   => 'applescript',
		'asp'           => 'aspnet',
		'bash'          => 'bash',
		'c'             => 'c',
		'c_mac'         => 'c',
		'code'          => Language_Registry::NO_LANGUAGE,
		'cpp'           => 'cpp',
		'csharp'        => 'csharp',
		'css'           => 'css',
		'diff'          => 'diff',
		'groovy'        => 'groovy',
		'html4strict'   => 'markup',
		'html5'         => 'markup',
		'ini'           => 'ini',
		'java'          => 'java',
		'java5'         => 'java',
		'javascript'    => 'javascript',
		'jquery'        => 'javascript',
		'mysql'         => 'sql',
		'oracle11'      => 'plsql',
		'pcre'          => 'regex',
		'perl'          => 'perl',
		'perl6'         => 'perl',
		'php'           => 'php',
		'postgresql'    => 'sql',
		'python'        => 'python',
		'rails'         => 'ruby',
		'ruby'          => 'ruby',
		'sql'           => 'sql',
		'text'          => Language_Registry::NO_LANGUAGE,
		'vb'            => 'visual-basic',
		'vbnet'         => 'visual-basic',
		'xml'           => 'markup',
		'yaml'          => 'yaml',
		'as'            => 'actionscript',
		'html'          => 'markup',
		'js'            => 'javascript',
	];

	/**
	 * Method to get the claimed shortcode tags before any filtering.
	 *
	 * @return array Numerically indexed array of shortcode tag names.
	 */
	public static function get_default_tags(): array {

		return array_merge(
			array_keys( static::$_language_map ),
			[ static::GENERIC_TAG ]
		);

	}    //end get_default_tags()

	/**
	 * Method to get the shortcode tags the plugin claims.
	 *
	 * @return array Numerically indexed array of shortcode tag names.
	 */
	public static function get_tags(): array {

		/**
		 * Filters the shortcode tags claimed by the plugin.
		 *
		 * @param array $tags Numerically indexed array of shortcode tag names.
		 */
		$tags = apply_filters( static::FILTER_TAGS, static::get_default_tags() );    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook name is the prefixed class constant above.

		if ( ! is_array( $tags ) ) {
			return static::get_default_tags();
		}

		$tags = array_filter(
			array_map(
				static function ( $tag ) {
					return strtolower( trim( (string) $tag ) );
				},
				$tags
			)
		);

		return array_values( array_unique( $tags ) );

	}    //end get_tags()

	/**
	 * Method to check whether a shortcode tag belongs to this plugin.
	 *
	 * @param string $tag Shortcode tag name.
	 *
	 * @return bool
	 */
	public static function is_our_tag( string $tag ): bool {
		return in_array( strtolower( trim( $tag ) ), static::get_tags(), true );
	}    //end is_our_tag()

	/**
	 * Method to get the full legacy tag to language id map.
	 *
	 * @return array Map of legacy tag name to canonical language id.
	 */
	public static function get_language_map(): array {
		return static::$_language_map;
	}    //end get_language_map()

	/**
	 * Method to translate a legacy tag or language name into a canonical language id.
	 *
	 * @param string $tag Legacy tag or language name as written by the author.
	 *
	 * @return string|null Canonical language id, or NULL when the tag is not ours.
	 */
	public static function to_language_id( string $tag ): ?string {

		$tag = strtolower( trim( $tag ) );

		return static::$_language_map[ $tag ] ?? null;

	}    //end to_language_id()

}    //end of class


//EOF
