<?php
/**
 * Immutable value object describing one code snippet.
 *
 * @package iG_Syntax_Hiliter
 */

namespace iG\Syntax_Hiliter;

/**
 * One code snippet, exactly as its author described it.
 *
 * The code is held pristine and unescaped and the language is held as typed.
 * Escaping and language resolution both belong to the renderer.
 */
class Snippet {

	/**
	 * Language assumed when the author names none.
	 *
	 * @var string
	 */
	const DEFAULT_LANGUAGE = 'code';

	/**
	 * Largest number of lines a single `highlight` range may expand to.
	 *
	 * Caps `highlight="1-999999999"`, which would otherwise exhaust memory.
	 *
	 * @var int
	 */
	const MAX_RANGE_LENGTH = 10000;

	/**
	 * Largest number of lines the whole `highlight` attribute may expand to.
	 *
	 * The per range cap above bounds one range and nothing else, and ranges are
	 * comma separated and unbounded in number, so `highlight="1-10000,2-10001,…"`
	 * costs ten thousand lines for every ten bytes the author writes. Two hundred
	 * ranges — under 2KB — took 148MB in the VM, on every front end render, and
	 * essentially all of it was spent building values which were then discarded as
	 * duplicates. This is the cap that actually bounds the work.
	 *
	 * @var int
	 */
	const MAX_HIGHLIGHT_LINES = 10000;

	/**
	 * The source code, pristine and unescaped.
	 *
	 * @var string
	 */
	public readonly string $code;

	/**
	 * The language as the author typed it.
	 *
	 * @var string
	 */
	public readonly string $language;

	/**
	 * Whether line numbers are shown for this snippet.
	 *
	 * @var bool
	 */
	public readonly bool $show_line_numbers;

	/**
	 * The number the first line is labelled with.
	 *
	 * @var int
	 */
	public readonly int $first_line;

	/**
	 * Line numbers to highlight, sorted ascending, unique, all greater than zero.
	 *
	 * @var array
	 */
	public readonly array $highlight_lines;

	/**
	 * Optional file name label shown alongside the snippet.
	 *
	 * @var string
	 */
	public readonly string $file;

	/**
	 * Class constructor.
	 *
	 * @param string $code              The source code, pristine and unescaped.
	 * @param string $language          Language as typed by the author.
	 * @param bool   $show_line_numbers Whether line numbers are shown.
	 * @param int    $first_line        Number the first line is labelled with.
	 * @param array  $highlight_lines   Line numbers to highlight.
	 * @param string $file              Optional file name label.
	 */
	public function __construct(
		string $code,
		string $language = self::DEFAULT_LANGUAGE,
		bool $show_line_numbers = true,
		int $first_line = 1,
		array $highlight_lines = [],
		string $file = ''
	) {

		$language = trim( $language );

		$this->code              = $code;
		$this->language          = ( '' === $language ) ? static::DEFAULT_LANGUAGE : $language;
		$this->show_line_numbers = $show_line_numbers;
		$this->first_line        = max( 1, $first_line );
		$this->highlight_lines   = static::normalize_line_numbers( $highlight_lines );
		$this->file              = static::sanitize_file_label( $file );

	}    //end __construct()

	/**
	 * Named constructor which builds a snippet from legacy shortcode attributes.
	 *
	 * Understands `language` (or `lang`), `firstline` (or `num`), `highlight`,
	 * `file` and `gutter`. `plaintext`, `toolbar` and `strict_mode` are accepted and
	 * ignored; anything else is discarded.
	 *
	 * @param array|string $atts                 Raw shortcode attributes. WordPress passes an empty string when a shortcode has none.
	 * @param string       $code                 Shortcode content, ie. the source code.
	 * @param bool         $default_line_numbers Site wide line number setting, used when `gutter` says nothing.
	 *
	 * @return \iG\Syntax_Hiliter\Snippet
	 */
	public static function from_shortcode_atts( array|string $atts = [], string $code = '', bool $default_line_numbers = true ): self {

		$atts = static::_normalize_atts( is_array( $atts ) ? $atts : [] );

		$language = static::_get_att( $atts, 'language' );
		$language = ( '' === $language ) ? static::_get_att( $atts, 'lang' ) : $language;
		$language = ( '' === $language ) ? static::DEFAULT_LANGUAGE : $language;

		$first_line = max(
			1,
			static::_to_line_number( static::_get_att( $atts, 'num' ) ),
			static::_to_line_number( static::_get_att( $atts, 'firstline' ) )
		);

		$gutter            = static::yesno_to_bool( static::_get_att( $atts, 'gutter' ) );
		$show_line_numbers = ( null === $gutter ) ? $default_line_numbers : $gutter;

		return new static(
			trim( $code ),
			$language,
			$show_line_numbers,
			$first_line,
			static::parse_line_ranges( static::_get_att( $atts, 'highlight' ) ),
			static::_get_att( $atts, 'file' )
		);

	}    //end from_shortcode_atts()

	/**
	 * Named constructor which builds a snippet from block attributes.
	 *
	 * @param array  $attributes           Block attributes.
	 * @param string $code                 Source code, when it is not in the attributes.
	 * @param bool   $default_line_numbers Site wide line number setting, used when the block says nothing.
	 *
	 * @return \iG\Syntax_Hiliter\Snippet
	 */
	public static function from_block_attributes( array $attributes = [], string $code = '', bool $default_line_numbers = true ): self {

		$code = ( isset( $attributes['code'] ) ) ? (string) $attributes['code'] : $code;

		$show_line_numbers = $default_line_numbers;

		if ( isset( $attributes['showLineNumbers'] ) ) {
			$show_line_numbers = (bool) $attributes['showLineNumbers'];
		}

		return new static(
			$code,
			(string) ( $attributes['language'] ?? '' ),
			$show_line_numbers,
			static::_to_line_number( $attributes['firstLine'] ?? 1 ),
			static::parse_line_ranges( $attributes['highlightLines'] ?? '' ),
			(string) ( $attributes['file'] ?? '' )
		);

	}    //end from_block_attributes()

	/**
	 * Method to parse the line range grammar into a list of line numbers.
	 *
	 * `"2,4-6"` becomes `[ 2, 4, 5, 6 ]`. Reversed ranges are flipped and junk is
	 * dropped.
	 *
	 * The lines are collected as keys rather than appended, and the whole expression
	 * stops once `self::MAX_HIGHLIGHT_LINES` of them have been found, so a line
	 * counted twice costs nothing and the work an author can ask for is bounded by
	 * the cap rather than by how many ranges they cared to type.
	 *
	 * @param mixed $value Range expression, or an array of line numbers.
	 *
	 * @return array Sorted, unique list of line numbers.
	 */
	public static function parse_line_ranges( $value ): array {

		$parts = ( is_array( $value ) ) ? $value : explode( ',', (string) $value );
		$lines = [];

		foreach ( $parts as $part ) {

			$budget = static::MAX_HIGHLIGHT_LINES - count( $lines );

			if ( 1 > $budget ) {
				break;
			}

			$part = trim( (string) $part );

			if ( '' === $part ) {
				continue;
			}

			if ( ! str_contains( $part, '-' ) ) {

				$line = static::_to_line_number( $part );

				if ( 0 < $line ) {
					$lines[ $line ] = $line;
				}

				continue;

			}

			$range = explode( '-', $part, 2 );
			$start = static::_to_line_number( trim( $range[0] ) );
			$end   = static::_to_line_number( trim( $range[1] ) );

			if ( $end < $start ) {
				[ $start, $end ] = [ $end, $start ];
			}

			$start = max( 1, $start );

			/*
			 * How many lines to take, rather than where to stop. A loop counting up to an
			 * end of PHP_INT_MAX overflows into a float on the last increment, a float which
			 * compares equal to the end it is tested against and does not advance again, so
			 * the loop never ends: `highlight="9223372036854775807-9223372036854775807"` is
			 * 39 bytes and exhausted the memory limit. Counted this way, `$start + $index`
			 * is never past `$end` and so never leaves the integer range.
			 */
			$length = min( $end - $start + 1, static::MAX_RANGE_LENGTH, $budget );

			for ( $index = 0; $index < $length; $index++ ) {
				$lines[ $start + $index ] = $start + $index;
			}
		}

		return static::normalize_line_numbers( $lines );

	}    //end parse_line_ranges()

	/**
	 * Method to sort a list of line numbers and drop duplicates and nonsense.
	 *
	 * @param array $lines List of line numbers.
	 *
	 * @return array Sorted, unique list of line numbers, all greater than zero.
	 */
	public static function normalize_line_numbers( array $lines ): array {

		$lines = array_filter(
			array_map( 'intval', $lines ),
			static function ( int $line ): bool {
				return ( 0 < $line );
			}
		);

		$lines = array_values( array_unique( $lines ) );

		sort( $lines, SORT_NUMERIC );

		return $lines;

	}    //end normalize_line_numbers()

	/**
	 * Method to turn a yes/no attribute value into a boolean.
	 *
	 * @param string $value Attribute value.
	 *
	 * @return bool|null TRUE or FALSE when the value is yes or no, NULL otherwise, which tells the caller the author expressed no opinion.
	 */
	public static function yesno_to_bool( string $value ): ?bool {

		$value = strtolower( trim( $value ) );

		if ( 'yes' === $value ) {
			return true;
		}

		if ( 'no' === $value ) {
			return false;
		}

		return null;

	}    //end yesno_to_bool()

	/**
	 * Method to clean up a file name label.
	 *
	 * Whitespace is collapsed, and that is the whole of it. The label is never treated
	 * as markup on any path it reaches: `Renderer::escape_verbatim()` escapes it into
	 * the `data-file` attribute, the stylesheet paints it from that attribute, the
	 * front end script assigns it with `textContent`, and the editor holds it in a text
	 * control. Stripping tags therefore protected nothing, and it deleted the type
	 * parameter out of `vector<int>.cpp`, `Foo<T>.cs` and `List<String>.java` and
	 * everything after an unbalanced `<`, which is a file name an author might well
	 * write.
	 *
	 * @param string $file Raw label as written by the author.
	 *
	 * @return string
	 */
	public static function sanitize_file_label( string $file ): string {

		$collapsed = preg_replace( '/\s+/', ' ', $file );

		// A pattern which fails changes nothing, rather than losing the label.
		return trim( ( is_string( $collapsed ) ) ? $collapsed : $file );

	}    //end sanitize_file_label()

	/**
	 * Method to read a value as a line number, ie. an integer which is never negative.
	 *
	 * `abs()` is not enough on its own, and the difference is a white screen rather
	 * than a wrong number. `abs( PHP_INT_MIN )` is one larger than any integer, so PHP
	 * hands back a float, `max()` propagates it, and every `int` parameter on this
	 * class refuses it: `[php firstline="-9223372036854775808"]` stored fine and threw
	 * an uncaught `TypeError` out of `the_content` on every render of the post
	 * afterwards. A magnitude too large to be an integer saturates at `PHP_INT_MAX`
	 * here, which is what `intval()` already does with a number written past the top of
	 * the range, so the two spellings of the same absurd number agree.
	 *
	 * @param mixed $value Value as the author wrote it.
	 *
	 * @return int Zero or greater.
	 */
	protected static function _to_line_number( mixed $value ): int {

		// A float beyond the integer range cannot be cast to one without a warning and a nonsense result.
		if ( is_float( $value ) && ( ! is_finite( $value ) || (float) PHP_INT_MAX <= abs( $value ) ) ) {
			return PHP_INT_MAX;
		}

		$value = intval( $value );

		if ( 0 <= $value ) {
			return $value;
		}

		return ( PHP_INT_MIN === $value ) ? PHP_INT_MAX : -$value;

	}    //end _to_line_number()

	/**
	 * Method to lower case attribute names and stringify their values.
	 *
	 * Numerically indexed attributes, which is how WordPress reports a valueless
	 * attribute, are discarded.
	 *
	 * @param array $atts Raw attributes.
	 *
	 * @return array
	 */
	protected static function _normalize_atts( array $atts ): array {

		$normalized = [];

		foreach ( $atts as $key => $value ) {

			if ( ! is_string( $key ) || is_array( $value ) || is_object( $value ) ) {
				continue;
			}

			$normalized[ strtolower( trim( $key ) ) ] = (string) $value;

		}

		return $normalized;

	}    //end _normalize_atts()

	/**
	 * Method to read one normalized attribute.
	 *
	 * @param array  $atts Normalized attributes.
	 * @param string $name Attribute name.
	 *
	 * @return string Attribute value, or an empty string when it is not there.
	 */
	protected static function _get_att( array $atts, string $name ): string {
		return trim( $atts[ $name ] ?? '' );
	}    //end _get_att()

}    //end of class


//EOF
