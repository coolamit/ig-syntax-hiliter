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
	const string FILTER_TAGS = 'ig_syntax_hiliter/shortcode_tags';

	/**
	 * The generic tag, which is ours whatever its `language` attribute says.
	 *
	 * @var string
	 */
	const string GENERIC_TAG = 'sourcecode';

	/**
	 * The tag list the alternation below was built from.
	 *
	 * The list comes through a filter, so it can change inside a request and the
	 * memo has to be keyed on it rather than merely set once. `Content_Protector`
	 * keeps its shortcode pattern the same way and for the same reason.
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
	 * The last three keys are shorthand aliases; `[sourcecode]` is absent because it
	 * carries its language in an attribute.
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
	 * Method to write one of this plugin's tags inside a snippet as text.
	 *
	 * A snippet ends at its own closing tag, so a snippet whose code quotes one used
	 * to be impossible to write. Doubling the brackets is the escape: `[[php]]` is the
	 * text `[php]` and `[[/php]]` is the text `[/php]`, and the matcher steps over the
	 * doubled form instead of closing on it. WordPress's own escape for a whole
	 * shortcode, `[[php]…[/php]]`, is a different construct and is left exactly as it
	 * is — this only ever doubles a tag which is already a single pair of brackets.
	 *
	 * This is what the revert tool writes with. It used to refuse such a block and
	 * report it instead, which left the one post most likely to hold this plugin's
	 * tags — a post about this plugin — as the one post the tool could not rescue.
	 *
	 * @param string $code Source code as the author wrote it.
	 *
	 * @return string|null The code with every claimed tag in it escaped, or NULL when PCRE gave up on it.
	 */
	public static function escape_tags( string $code ): ?string {

		//the cheap test first: no bracket, no tag, and nothing to build an alternation for
		if ( ! str_contains( $code, '[' ) ) {
			return $code;
		}

		$tags = static::_get_tag_alternation();

		if ( '' === $tags ) {
			return $code;
		}

		$escaped = preg_replace(
			sprintf( '/\[\/?(?:%s)(?![\w-])[^\]]*\]/i', $tags ),
			'[$0]',
			$code
		);

		/*
		 * `preg_replace()` hands back NULL on a backtrack, recursion or JIT stack limit,
		 * and this runs on the way to a post being written. A failure has to mean "wrote
		 * nothing", never "wrote an empty snippet", so the caller is told rather than
		 * handed a string.
		 */
		if ( ! is_string( $escaped ) || PREG_NO_ERROR !== preg_last_error() ) {
			return null;
		}

		return $escaped;

	}    //end escape_tags()

	/**
	 * Method to read an escaped tag inside a snippet back as the text it stands for.
	 *
	 * The mirror of `self::escape_tags()`, and the only two places it is called from
	 * are the two ways a shortcode's code becomes a snippet: the display path and the
	 * editor's conversion of a shortcode into a block. Stored content is never
	 * touched, so an author who typed `[[/php]]` keeps those bytes in their post.
	 *
	 * One level of nesting falls out of this rather than being special cased.
	 * `[[[/php]]]` is stepped over by the matcher, unescaped once here, and shows the
	 * reader `[[/php]]`.
	 *
	 * @param string $code Source code, as the matcher found it.
	 *
	 * @return string The code with one pair of brackets taken off every escaped tag.
	 */
	public static function unescape_tags( string $code ): string {

		//as in `escape_tags()`: almost no snippet holds a doubled bracket, and this runs once per snippet built
		if ( ! str_contains( $code, '[[' ) ) {
			return $code;
		}

		$tags = static::_get_tag_alternation();

		if ( '' === $tags ) {
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

	}    //end unescape_tags()

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

	/**
	 * Method to get an alternation which matches any one of the claimed tags.
	 *
	 * @return string Pattern fragment for a `/` delimited pattern, or an empty string when the plugin claims no tags.
	 */
	protected static function _get_tag_alternation(): string {

		$tags = static::get_tags();

		if ( $tags === static::$_alternation_tags && '' !== static::$_alternation ) {
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

	}    //end _get_tag_alternation()

}    //end of class


//EOF
