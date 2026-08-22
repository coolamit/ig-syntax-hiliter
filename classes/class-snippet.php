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
	protected const string _DEFAULT_LANGUAGE = 'code';

	/**
	 * Largest number of lines a single `highlight` range may expand to.
	 *
	 * Caps `highlight="1-999999999"`, which would otherwise exhaust memory.
	 *
	 * @var int
	 */
	public const int MAX_RANGE_LENGTH = 10000;

	/**
	 * Largest number of lines the whole `highlight` attribute may expand to.
	 *
	 * The per-range cap bounds one range; ranges are comma separated and unbounded in
	 * number, so `highlight="1-10000,2-10001,…"` costs ten thousand lines per ten bytes.
	 * This is the cap that bounds the work.
	 *
	 * @var int
	 */
	public const int MAX_HIGHLIGHT_LINES = 10000;

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
	 * Takes the attribute set of a shortcode, which is the plugin's own vocabulary, and does
	 * every normalisation here; the named constructors only map their source onto it.
	 * `language` (or `lang`), `firstline` (or `num`), `highlight`, `file` and `gutter` are
	 * read; anything else is ignored.
	 *
	 * @param string $code                 The source code, pristine and unescaped.
	 * @param array  $atts                 Attributes, keyed by name.
	 * @param bool   $default_line_numbers Site wide line number setting, used when `gutter`
	 *                                     says nothing.
	 */
	public function __construct( string $code, array $atts = [], bool $default_line_numbers = true ) {

		$atts = $this->_normalize_atts( $atts );

		$language = $this->_get_att( $atts, 'language' );
		$language = ( empty( $language ) ) ? $this->_get_att( $atts, 'lang' ) : $language;

		$this->code              = $code;
		$this->language          = ( empty( $language ) ) ? static::_DEFAULT_LANGUAGE : $language;
		$this->show_line_numbers = $this->_yesno_to_bool( $this->_get_att( $atts, 'gutter' ) ) ?? $default_line_numbers;
		$this->first_line        = max(
			1,
			$this->_to_line_number( $atts['num'] ?? 0 ),
			$this->_to_line_number( $atts['firstline'] ?? 0 )
		);
		$this->highlight_lines   = $this->_parse_line_ranges( $atts['highlight'] ?? '' );
		$this->file              = $this->_sanitize_file_label( $this->_get_att( $atts, 'file' ) );

	}

	/**
	 * Named constructor which builds a snippet from legacy shortcode attributes.
	 *
	 * @param array|string $atts                 Raw shortcode attributes. WordPress passes an
	 *                                           empty string when a shortcode has none.
	 * @param string       $code                 Shortcode content, ie. the source code.
	 * @param bool         $default_line_numbers Site wide line number setting, used when
	 *                                           `gutter` says nothing.
	 *
	 * @return \iG\Syntax_Hiliter\Snippet
	 */
	public static function from_shortcode_atts( array|string $atts = [], string $code = '', bool $default_line_numbers = true ): self {
		return new static( trim( $code ), ( is_array( $atts ) ) ? $atts : [], $default_line_numbers );
	}

	/**
	 * Named constructor which builds a snippet from block attributes.
	 *
	 * @param array  $attributes           Block attributes.
	 * @param string $code                 Source code, when it is not in the attributes.
	 * @param bool   $default_line_numbers Site wide line number setting, used when the block
	 *                                     says nothing.
	 *
	 * @return \iG\Syntax_Hiliter\Snippet
	 */
	public static function from_block_attributes( array $attributes = [], string $code = '', bool $default_line_numbers = true ): self {

		$atts = [
			'language'  => $attributes['language'] ?? '',
			'firstline' => $attributes['firstLine'] ?? 1,
			'highlight' => $attributes['highlightLines'] ?? '',
			'file'      => $attributes['file'] ?? '',
		];

		if ( isset( $attributes['showLineNumbers'] ) ) {
			$atts['gutter'] = ( $attributes['showLineNumbers'] ) ? 'yes' : 'no';
		}

		return new static(
			( isset( $attributes['code'] ) ) ? (string) $attributes['code'] : $code,
			$atts,
			$default_line_numbers
		);

	}

	/**
	 * Method to parse the line range grammar into a list of line numbers.
	 *
	 * `"2,4-6"` becomes `[ 2, 4, 5, 6 ]`. Reversed ranges are flipped and junk is
	 * dropped. Lines are collected as keys and the expression stops at
	 * `self::MAX_HIGHLIGHT_LINES`, so the work is bounded by the cap and not by the
	 * number of ranges typed.
	 *
	 * @param mixed $value Range expression, or an array of line numbers.
	 *
	 * @return array Sorted, unique list of line numbers.
	 */
	protected function _parse_line_ranges( mixed $value ): array {

		$parts = ( is_array( $value ) ) ? $value : explode( ',', (string) $value );
		$lines = [];

		foreach ( $parts as $part ) {

			$budget = static::MAX_HIGHLIGHT_LINES - count( $lines );

			if ( 1 > $budget ) {
				break;
			}

			$part = trim( (string) $part );

			if ( empty( $part ) ) {
				continue;
			}

			if ( ! str_contains( $part, '-' ) ) {

				$line = $this->_to_line_number( $part );

				if ( 0 < $line ) {
					$lines[ $line ] = $line;
				}

				continue;

			}

			$range = explode( '-', $part, 2 );
			$start = $this->_to_line_number( trim( $range[0] ) );
			$end   = $this->_to_line_number( trim( $range[1] ) );

			if ( $end < $start ) {
				[ $start, $end ] = [ $end, $start ];
			}

			$start = max( 1, $start );

			/*
			 * How many lines to take, rather than where to stop: a loop counting to
			 * `PHP_INT_MAX` overflows to a float on the last increment, which compares equal
			 * to the end and never advances. `$start + $index` never leaves the integer range.
			 */
			$length = min( $end - $start + 1, static::MAX_RANGE_LENGTH, $budget );

			for ( $index = 0; $index < $length; $index++ ) {
				$lines[ $start + $index ] = $start + $index;
			}
		}

		return $this->_normalize_line_numbers( $lines );

	}

	/**
	 * Method to sort a list of line numbers and drop duplicates and nonsense.
	 *
	 * @param array $lines List of line numbers.
	 *
	 * @return array Sorted, unique list of line numbers, all greater than zero.
	 */
	protected function _normalize_line_numbers( array $lines ): array {

		$lines = array_filter(
			array_map( 'intval', $lines ),
			fn ( int $line ): bool => ( 0 < $line )
		);

		$lines = array_values( array_unique( $lines ) );

		sort( $lines, SORT_NUMERIC );

		return $lines;

	}

	/**
	 * Method to turn a yes/no attribute value into a boolean.
	 *
	 * Three-state on purpose: an attribute has to be able to express no opinion.
	 *
	 * @param string $value Attribute value.
	 *
	 * @return bool|null TRUE or FALSE when the value is yes or no, NULL otherwise.
	 */
	protected function _yesno_to_bool( string $value ): ?bool {

		$value = strtolower( trim( $value ) );

		if ( 'yes' === $value ) {
			return true;
		}

		if ( 'no' === $value ) {
			return false;
		}

		return null;

	}

	/**
	 * Method to clean up a file name label.
	 *
	 * Whitespace collapsed and nothing else — tags and length belong at the output
	 * boundary, in `Renderer::render_snippet()`.
	 *
	 * @param string $file Raw label as written by the author.
	 *
	 * @return string
	 */
	protected function _sanitize_file_label( string $file ): string {

		$collapsed = preg_replace( '/\s+/', ' ', $file );

		// A pattern which fails changes nothing, rather than losing the label.
		return trim( ( is_string( $collapsed ) ) ? $collapsed : $file );

	}

	/**
	 * Method to read a value as a line number, ie. an integer which is never negative.
	 *
	 * `abs()` alone is not enough: `abs( PHP_INT_MIN )` is a float, `max()` propagates
	 * it, and every `int` parameter here refuses it — an uncaught `TypeError` out of
	 * `the_content` on every render. A magnitude too large saturates at `PHP_INT_MAX`,
	 * matching `intval()`.
	 *
	 * @param mixed $value Value as the author wrote it.
	 *
	 * @return int Zero or greater.
	 */
	protected function _to_line_number( mixed $value ): int {

		// A float beyond the integer range cannot be cast to one without a warning and a
		// nonsense result.
		if ( is_float( $value ) && ( ! is_finite( $value ) || (float) PHP_INT_MAX <= abs( $value ) ) ) {
			return PHP_INT_MAX;
		}

		$value = intval( $value );

		if ( 0 <= $value ) {
			return $value;
		}

		return ( PHP_INT_MIN === $value ) ? PHP_INT_MAX : -$value;

	}

	/**
	 * Method to lower case attribute names and drop what cannot be an attribute value.
	 *
	 * Numerically indexed attributes, which is how WordPress reports a valueless
	 * attribute, and objects are discarded. Scalars and arrays are kept as they are, so a
	 * number reaches `_to_line_number()` as the number it was.
	 *
	 * @param array $atts Raw attributes.
	 *
	 * @return array
	 */
	protected function _normalize_atts( array $atts ): array {

		$normalized = [];

		foreach ( $atts as $key => $value ) {

			if ( ! is_string( $key ) || is_object( $value ) ) {
				continue;
			}

			$normalized[ strtolower( trim( $key ) ) ] = $value;

		}

		return $normalized;

	}

	/**
	 * Method to read one normalized attribute as text.
	 *
	 * @param array  $atts Normalized attributes.
	 * @param string $name Attribute name.
	 *
	 * @return string Attribute value, or an empty string when it is not there or not a scalar.
	 */
	protected function _get_att( array $atts, string $name ): string {
		return ( isset( $atts[ $name ] ) && is_scalar( $atts[ $name ] ) ) ? trim( (string) $atts[ $name ] ) : '';
	}

} // end of class

// EOF
