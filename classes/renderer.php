<?php
/**
 * Renders a snippet as a code box.
 *
 * @package iG_Syntax_Hiliter
 */

namespace iG\Syntax_Hiliter;

/**
 * Turns a snippet into the markup the highlighter expects.
 *
 * `render_snippet()` holds the only `esc_html()` call applied to snippet code
 * anywhere in the plugin, and every path which needs a code box goes through it.
 * A language class is written only once the registry has confirmed the language can
 * be loaded, which is what keeps the browser from asking for a file that is not
 * there.
 */
class Renderer {

	/**
	 * Prefix of the DOM id given to each rendered code box.
	 *
	 * @var string
	 */
	const ID_PREFIX = 'ig-sh-';

	/**
	 * Singleton instance.
	 *
	 * @var \iG\Syntax_Hiliter\Renderer|null
	 */
	protected static ?self $_instance = null;

	/**
	 * The registry consulted to validate a language.
	 *
	 * @var \iG\Syntax_Hiliter\Language_Registry
	 */
	protected Language_Registry $_registry;

	/**
	 * Number of code boxes rendered so far in this request.
	 *
	 * @var int
	 */
	protected int $_counter = 0;

	/**
	 * Class constructor.
	 *
	 * @param \iG\Syntax_Hiliter\Language_Registry $registry Registry used to validate languages.
	 */
	public function __construct( Language_Registry $registry ) {
		$this->_registry = $registry;
	}    //end __construct()

	/**
	 * Method to get the shared renderer.
	 *
	 * @return \iG\Syntax_Hiliter\Renderer
	 */
	public static function get_instance(): self {

		if ( is_null( static::$_instance ) ) {
			static::$_instance = new static( Language_Registry::get_instance() );
		}

		return static::$_instance;

	}    //end get_instance()

	/**
	 * Method to render a snippet as a code box.
	 *
	 * @param \iG\Syntax_Hiliter\Snippet $snippet Snippet to render.
	 *
	 * @return string HTML markup for the code box.
	 */
	public function render_snippet( Snippet $snippet ): string {

		$language = $this->resolve_language( $snippet->language );

		Asset_Manager::get_instance()->snippet_rendered(
			$language,
			$snippet->show_line_numbers,
			! empty( $snippet->highlight_lines )
		);

		++$this->_counter;

		$classes = [ sprintf( 'language-%s', $language ) ];

		if ( $snippet->show_line_numbers ) {
			$classes[] = 'line-numbers';
		}

		$attributes = [
			'id'    => sprintf( '%s%d', static::ID_PREFIX, $this->_counter ),
			'class' => implode( ' ', $classes ),
		];

		if ( 1 !== $snippet->first_line ) {
			$attributes['data-start'] = (string) $snippet->first_line;
		}

		if ( ! empty( $snippet->highlight_lines ) ) {
			$attributes['data-line'] = static::compact_line_ranges( $snippet->highlight_lines );
		}

		if ( '' !== $snippet->file ) {
			$attributes['data-file'] = $snippet->file;
		}

		// Opt out of the output buffering page optimizers, which run beyond any filter this plugin can hook.
		$attributes['data-no-optimize'] = '1';
		$attributes['data-cfasync']     = 'false';

		return sprintf(
			'<pre %1$s><code class="language-%2$s">%3$s</code></pre>',
			static::_build_attributes( $attributes ),
			esc_attr( $language ),
			esc_html( $snippet->code )
		);

	}    //end render_snippet()

	/**
	 * Method to work out which language a snippet is highlighted as.
	 *
	 * The legacy map is consulted before the registry, because a name such as `text`
	 * or `c_mac` means something specific to this plugin and something else to the
	 * highlighter. A language the registry cannot confirm degrades to "no language".
	 *
	 * @param string $language Language as typed by the author.
	 *
	 * @return string Canonical language id, or the "do not highlight" id.
	 */
	public function resolve_language( string $language ): string {

		$language = strtolower( trim( $language ) );

		if ( '' === $language || Language_Registry::NO_LANGUAGE === $language ) {
			return Language_Registry::NO_LANGUAGE;
		}

		$mapped = Legacy_Map::to_language_id( $language );

		if ( Language_Registry::NO_LANGUAGE === $mapped ) {
			return Language_Registry::NO_LANGUAGE;
		}

		if ( ! is_null( $mapped ) && $this->_registry->has( $mapped ) ) {
			return $mapped;
		}

		return $this->_registry->resolve( $language ) ?? Language_Registry::NO_LANGUAGE;

	}    //end resolve_language()

	/**
	 * Method to reset the code box counter.
	 *
	 * @return void
	 */
	public function reset_counter(): void {
		$this->_counter = 0;
	}    //end reset_counter()

	/**
	 * Method to squeeze a list of line numbers back into range notation.
	 *
	 * `[ 2, 4, 5, 6 ]` becomes `"2,4-6"`.
	 *
	 * @param array $lines Sorted, unique list of line numbers.
	 *
	 * @return string
	 */
	public static function compact_line_ranges( array $lines ): string {

		$lines = Snippet::normalize_line_numbers( $lines );

		if ( empty( $lines ) ) {
			return '';
		}

		$ranges = [];
		$start  = null;
		$end    = null;

		foreach ( $lines as $line ) {

			if ( is_null( $start ) ) {

				$start = $line;
				$end   = $line;

				continue;

			}

			if ( ( $end + 1 ) === $line ) {

				$end = $line;

				continue;

			}

			$ranges[] = ( $start === $end ) ? (string) $start : sprintf( '%d-%d', $start, $end );
			$start    = $line;
			$end      = $line;

		}

		$ranges[] = ( $start === $end ) ? (string) $start : sprintf( '%d-%d', $start, $end );

		return implode( ',', $ranges );

	}    //end compact_line_ranges()

	/**
	 * Method to build an HTML attribute string.
	 *
	 * @param array $attributes Attribute name to value.
	 *
	 * @return string
	 */
	protected static function _build_attributes( array $attributes ): string {

		$markup = [];

		foreach ( $attributes as $name => $value ) {
			$markup[] = sprintf( '%s="%s"', $name, esc_attr( $value ) );
		}

		return implode( ' ', $markup );

	}    //end _build_attributes()

}    //end of class


//EOF
