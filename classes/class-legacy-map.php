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
 * never claimed.
 */
class Legacy_Map {

	/**
	 * Filter for adding or removing claimed shortcode tags.
	 *
	 * @var string
	 */
	public const string FILTER_TAGS = 'ig_syntax_hiliter/shortcode_tags';

	/**
	 * The generic tag, which is ours whatever its `language` attribute says.
	 *
	 * @var string
	 */
	public const string GENERIC_TAG = 'sourcecode';

	/**
	 * The tag list the alternation below was built from.
	 *
	 * Keyed on the tag list because the list comes through a filter and can change
	 * inside a request.
	 *
	 * @var array
	 */
	protected static array $_alternation_tags = [];

	/**
	 * The claimed tags as a regular expression alternation.
	 *
	 * @var string
	 */
	protected static string $_alternation = '';

	/**
	 * Legacy tag to canonical language id.
	 *
	 * `[sourcecode]` is absent because it carries its language in an attribute.
	 *
	 * @var array
	 */
	protected static array $_language_map = [
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

	}

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

	}

	/**
	 * Method to write one of this plugin's tags inside a snippet as text.
	 *
	 * A snippet ends at its own closing tag, so a tag quoted inside one has its brackets
	 * doubled: `[[php]]` is the text `[php]`, and the matcher steps over the doubled form
	 * instead of closing on it. WordPress's own whole-shortcode escape `[[php]…[/php]]`
	 * is a different construct and is left alone.
	 *
	 * @param string $code Source code as the author wrote it.
	 *
	 * @return string|null The code with every claimed tag in it escaped, or NULL when PCRE
	 *                     gave up on it.
	 */
	public static function escape_tags( string $code ): ?string {

		if ( ! str_contains( $code, '[' ) ) {
			return $code;
		}

		$tags = static::_get_tag_alternation();

		if ( empty( $tags ) ) {
			return $code;
		}

		$escaped = preg_replace(
			sprintf( '/\[\/?(?:%s)(?![\w-])[^\]]*\]/i', $tags ),
			'[$0]',
			$code
		);

		// A NULL from PCRE on the way to a write must mean "wrote nothing", so the caller is told.
		if ( ! is_string( $escaped ) || PREG_NO_ERROR !== preg_last_error() ) {
			return null;
		}

		return $escaped;

	}

	/**
	 * Method to read an escaped tag inside a snippet back as the text it stands for.
	 *
	 * The mirror of `self::escape_tags()`, called only on the display path and on the
	 * editor's shortcode to block conversion; stored content is never touched.
	 *
	 * @param string $code Source code, as the matcher found it.
	 *
	 * @return string The code with one pair of brackets taken off every escaped tag.
	 */
	public static function unescape_tags( string $code ): string {

		if ( ! str_contains( $code, '[[' ) ) {
			return $code;
		}

		$tags = static::_get_tag_alternation();

		if ( empty( $tags ) ) {
			return $code;
		}

		$unescaped = preg_replace(
			sprintf( '/\[(\[\/?(?:%s)(?![\w-])[^\]]*\])\]/i', $tags ),
			'$1',
			$code
		);

		// A pattern which gave up changes nothing, rather than losing the snippet.
		if ( ! is_string( $unescaped ) || PREG_NO_ERROR !== preg_last_error() ) {
			return $code;
		}

		return $unescaped;

	}

	/**
	 * Method to get the full legacy tag to language id map.
	 *
	 * @return array Map of legacy tag name to canonical language id.
	 */
	public static function get_language_map(): array {
		return static::$_language_map;
	}

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

	}

	/**
	 * Method to get an alternation which matches any one of the claimed tags.
	 *
	 * @return string Pattern fragment for a `/` delimited pattern, or an empty string when the
	 *                plugin claims no tags.
	 */
	protected static function _get_tag_alternation(): string {

		$tags = static::get_tags();

		if ( $tags === static::$_alternation_tags && ! empty( static::$_alternation ) ) {
			return static::$_alternation;
		}

		static::$_alternation_tags = $tags;

		static::$_alternation = ( empty( $tags ) ) ? '' : implode(
			'|',
			array_map(
				static function ( $tag ) {
					return preg_quote( $tag, '/' );
				},
				$tags
			)
		);

		return static::$_alternation;

	}

} // end of class

// EOF
