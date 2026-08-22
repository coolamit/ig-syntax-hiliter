<?php
/**
 * Renders a snippet as a code box.
 *
 * @package iG_Syntax_Hiliter
 */

namespace iG\Syntax_Hiliter;

use iG\Syntax_Hiliter\Traits\Singleton;

/**
 * Turns a snippet into the markup the highlighter expects.
 *
 * `render_snippet()` holds the only escaping applied to snippet code anywhere in
 * the plugin, and every path which needs a code box goes through it. A language
 * class is written only once the registry has confirmed the language can be loaded,
 * which is what keeps the browser from asking for a file that is not there.
 */
class Renderer {

	use Singleton;

	/**
	 * Prefix of the DOM id given to each rendered code box.
	 *
	 * @var string
	 */
	public const string ID_PREFIX = 'ig-sh-';

	/**
	 * How many characters of a file label are put on the page.
	 *
	 * A label is a path as often as it is a file name, and a path is long.
	 *
	 * @var int
	 */
	protected const int _FILE_LABEL_LENGTH = 30;

	/**
	 * What stands in front of a label which was cut.
	 *
	 * @var string
	 */
	protected const string _FILE_LABEL_ELLIPSIS = '…';

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
	 * Public and stays public: a declared constructor beats the trait's, and this class
	 * is built by hand with a registry of its own. The registry defaults because
	 * `get_instance()` calls the constructor with nothing.
	 *
	 * @param \iG\Syntax_Hiliter\Language_Registry|null $registry Registry used to validate
	 *                                                            languages. The shared one
	 *                                                            when none is named.
	 */
	public function __construct( ?Language_Registry $registry = null ) {
		$this->_registry = $registry ?? Language_Registry::get_instance();
	}

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
			'class' => implode( ' ', $classes ),
		];

		if ( 1 !== $snippet->first_line ) {

			$attributes['data-start'] = (string) $snippet->first_line;

			/*
			 * `data-start` is read by the line-numbers plugin; the line-highlight plugin
			 * reads this. Without it a range is measured against the physical line count —
			 * an 11-line snippet shown as 5–15 has `11-13` clamped to `11-11` — and with
			 * line numbers off the band is drawn `first_line - 1` lines too low.
			 */
			$attributes['data-line-offset'] = (string) ( $snippet->first_line - 1 );

		}

		if ( ! empty( $snippet->highlight_lines ) ) {
			$attributes['data-line'] = $this->compact_line_ranges( $snippet->highlight_lines );
		}

		// Opt out of the output buffering page optimizers, which run beyond any filter this
		// plugin can hook.
		$attributes['data-no-optimize'] = '1';
		$attributes['data-cfasync']     = 'false';

		$markup = sprintf(
			'<pre %1$s><code class="language-%2$s">%3$s</code></pre>',
			$this->_build_attributes( $attributes ),
			esc_attr( $language ),
			$this->escape_verbatim( $snippet->code )
		);

		/*
		 * The label sits above the box, not inside: the line-numbers plugin reserves the
		 * left gutter and line-highlight puts its badge top-left, and both measure the
		 * `pre` alone. The container is unconditional and carries the id;
		 * `frontend-chrome.scss` keys off `.igsh-code-box`.
		 */
		$label = wp_strip_all_tags( $snippet->file );
		$file  = '';

		if ( ! empty( $label ) ) {

			$shortened = $this->shorten_file_label( $label );

			$file = sprintf(
				'<span class="igsh-code-box__file"%1$s>%2$s</span>',
				$this->_build_label_title( $label, $shortened ),
				$this->escape_verbatim( $shortened )
			);

		}

		return sprintf(
			'<div class="igsh-code-box" id="%1$s">%2$s%3$s</div>',
			esc_attr( sprintf( '%s%d', static::ID_PREFIX, $this->_counter ) ),
			$file,
			$markup
		);

	}

	/**
	 * Method to cut a file label down to what goes on the page.
	 *
	 * The tail is kept, because the end of a path is the file name. `mb_substr()`
	 * where available, so a label is never cut mid-character.
	 *
	 * @param string $label File label, with any markup already taken out of it.
	 *
	 * @return string The label, or its last characters behind an ellipsis.
	 */
	public function shorten_file_label( string $label ): string {

		$length = ( function_exists( 'mb_strlen' ) ) ? mb_strlen( $label, 'UTF-8' ) : strlen( $label );

		if ( static::_FILE_LABEL_LENGTH >= $length ) {
			return $label;
		}

		$keep = static::_FILE_LABEL_LENGTH - 1;    // the ellipsis takes one of them
		$tail = ( function_exists( 'mb_substr' ) ) ? mb_substr( $label, -$keep, null, 'UTF-8' ) : substr( $label, -$keep );

		return static::_FILE_LABEL_ELLIPSIS . $tail;

	}

	/**
	 * Method to build the `title` attribute of a file label.
	 *
	 * Only a label which was cut gets one: a tooltip repeating the screen teaches a
	 * reader that hovering is pointless. The shortened form is passed in rather than
	 * computed again.
	 *
	 * @param string $label     File label, with any markup already taken out of it.
	 * @param string $shortened The same label as `shorten_file_label()` left it.
	 *
	 * @return string The attribute with its leading space, or an empty string.
	 */
	protected function _build_label_title( string $label, string $shortened ): string {

		if ( $shortened === $label ) {
			return '';
		}

		return sprintf( ' title="%s"', $this->escape_verbatim( $label ) );

	}

	/**
	 * Method to escape text so that a reader is shown the bytes an author typed.
	 *
	 * Differs from `esc_html()`/`esc_attr()` in one respect: it encodes an ampersand
	 * that already begins an entity. A highlighter shows source exactly as written, and
	 * source routinely contains entities as literal text; nothing at render time can
	 * tell an author's literal `&lt;` from a meant-as-markup one, so every byte is
	 * treated as literal.
	 *
	 * @param string $text Text exactly as the author wrote it.
	 *
	 * @return string
	 */
	public function escape_verbatim( string $text ): string {

		// `esc_html()` with double encoding turned on and nothing else changed.
		$escaped = _wp_specialchars( $text, ENT_QUOTES, false, true );

		if ( ! empty( $escaped ) || empty( $text ) ) {
			return $escaped;
		}

		/*
		 * An empty code box is the one failure a highlighter must never have.
		 * `htmlspecialchars()` returns empty for text invalid in the target charset unless
		 * told to substitute, and `_wp_specialchars()` cannot be told — it overwrites any
		 * unrecognised quote style with ENT_QUOTES. The second pass substitutes U+FFFD and
		 * keeps the rest.
		 */
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true );

	}

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

		if ( empty( $language ) || Language_Registry::NO_LANGUAGE === $language ) {
			return Language_Registry::NO_LANGUAGE;
		}

		$mapped = Legacy_Map::get_instance()->to_language_id( $language );

		if ( Language_Registry::NO_LANGUAGE === $mapped ) {
			return Language_Registry::NO_LANGUAGE;
		}

		if ( ! is_null( $mapped ) && $this->_registry->has( $mapped ) ) {
			return $mapped;
		}

		return $this->_registry->resolve( $language ) ?? Language_Registry::NO_LANGUAGE;

	}

	/**
	 * Method to reset the code box counter.
	 *
	 * @return void
	 */
	public function reset_counter(): void {
		$this->_counter = 0;
	}

	/**
	 * Method to squeeze a list of line numbers back into range notation.
	 *
	 * `[ 2, 4, 5, 6 ]` becomes `"2,4-6"`.
	 *
	 * @param array $lines List of line numbers, in any order.
	 *
	 * @return string
	 */
	public function compact_line_ranges( array $lines ): string {

		$lines = array_filter(
			array_map( 'intval', $lines ),
			static function ( int $line ): bool {
				return ( 0 < $line );
			}
		);

		$lines = array_values( array_unique( $lines ) );

		sort( $lines, SORT_NUMERIC );

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

	}

	/**
	 * Method to build an HTML attribute string.
	 *
	 * Escaped even though every value is digits or a language id: nothing leaves this
	 * class unescaped.
	 *
	 * @param array $attributes Attribute name to value.
	 *
	 * @return string
	 */
	protected function _build_attributes( array $attributes ): string {

		$markup = [];

		foreach ( $attributes as $name => $value ) {
			$markup[] = sprintf( '%s="%s"', $name, $this->escape_verbatim( (string) $value ) );
		}

		return implode( ' ', $markup );

	}

} // end of class

// EOF
