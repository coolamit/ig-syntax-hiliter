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
 * `render_snippet()` holds the only escaping applied to snippet code anywhere in
 * the plugin, and every path which needs a code box goes through it. A language
 * class is written only once the registry has confirmed the language can be loaded,
 * which is what keeps the browser from asking for a file that is not there.
 */
class Renderer {

	/**
	 * Prefix of the DOM id given to each rendered code box.
	 *
	 * @var string
	 */
	const ID_PREFIX = 'ig-sh-';

	/**
	 * How many characters of a file label are put on the page.
	 *
	 * Carried over from `Frontend::FILE_PATH_LENGTH` in 5.1, which showed the same
	 * thirty. A label is a path as often as it is a file name, and a path is long.
	 *
	 * @var int
	 */
	const FILE_LABEL_LENGTH = 30;

	/**
	 * What stands in front of a label which was cut.
	 *
	 * @var string
	 */
	const FILE_LABEL_ELLIPSIS = '…';

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

		// Opt out of the output buffering page optimizers, which run beyond any filter this plugin can hook.
		$attributes['data-no-optimize'] = '1';
		$attributes['data-cfasync']     = 'false';

		$markup = sprintf(
			'<pre %1$s><code class="language-%2$s">%3$s</code></pre>',
			static::_build_attributes( $attributes ),
			esc_attr( $language ),
			static::escape_verbatim( $snippet->code )
		);

		$label = wp_strip_all_tags( $snippet->file );

		if ( '' === $label ) {
			return $markup;
		}

		/*
		 * The file label sits above the box rather than inside it, and only a snippet
		 * which has one is wrapped at all — every other snippet's markup is what it
		 * always was. It used to be a toolbar item, which meant it was invisible until
		 * the reader hovered and sat in the corner the copy button wanted. Inside the
		 * box there is nowhere for it to go either: the line numbers plugin reserves
		 * the left gutter and the line highlight plugin puts its own badge in the top
		 * left. Both measure the `pre` alone, so a sibling in front of it moves neither.
		 */
		return sprintf(
			'<div class="igsh-code-box"><span class="igsh-code-box__file"%1$s>%2$s</span>%3$s</div>',
			static::_build_label_title( $label ),
			static::escape_verbatim( static::shorten_file_label( $label ) ),
			$markup
		);

	}    //end render_snippet()

	/**
	 * Method to cut a file label down to what goes on the page.
	 *
	 * The tail is what is kept, because the end of a path is the file name and the
	 * file name is what the label is for. 5.1 did the same, in `_snip_file_path()`.
	 *
	 * `mb_substr()` where the site has it, so a label is never cut through the middle
	 * of a character and left as a broken byte sequence for the browser to draw as a
	 * replacement glyph.
	 *
	 * @param string $label File label, with any markup already taken out of it.
	 *
	 * @return string The label, or its last characters behind an ellipsis.
	 */
	public static function shorten_file_label( string $label ): string {

		$length = ( function_exists( 'mb_strlen' ) ) ? mb_strlen( $label, 'UTF-8' ) : strlen( $label );

		if ( static::FILE_LABEL_LENGTH >= $length ) {
			return $label;
		}

		$keep = static::FILE_LABEL_LENGTH - 1;    //the ellipsis takes one of them
		$tail = ( function_exists( 'mb_substr' ) ) ? mb_substr( $label, -$keep, null, 'UTF-8' ) : substr( $label, -$keep );

		return static::FILE_LABEL_ELLIPSIS . $tail;

	}    //end shorten_file_label()

	/**
	 * Method to build the `title` attribute of a file label.
	 *
	 * Only a label which was cut gets one. A tooltip repeating what is already on
	 * screen is noise, and a reader who hovers and is told what they can already read
	 * learns that hovering this element is pointless.
	 *
	 * @param string $label File label, with any markup already taken out of it.
	 *
	 * @return string The attribute with its leading space, or an empty string.
	 */
	protected static function _build_label_title( string $label ): string {

		if ( static::shorten_file_label( $label ) === $label ) {
			return '';
		}

		return sprintf( ' title="%s"', static::escape_verbatim( $label ) );

	}    //end _build_label_title()

	/**
	 * Method to escape text so that a reader is shown the bytes an author typed.
	 *
	 * This is the definition of the plugin's escaping, and `render_snippet()` is the
	 * only thing which applies it. It differs from `esc_html()` and `esc_attr()`
	 * in one respect: it encodes an ampersand that already begins an entity as well
	 * as one that does not. It also never hands back an empty string for text which
	 * was not empty, which the comment on the second pass below has the reasoning for.
	 *
	 * That difference is the whole point. A highlighter shows source exactly as it
	 * was written, and source routinely contains entities as literal text — `&amp;`
	 * in an XML snippet, `&nbsp;` in an HTML one, `&lt;` in a PHP string. Leaving
	 * those alone hands the browser the entity rather than the text: an author who
	 * writes `&lt;b&gt;` is shown `<b>`, one who writes `a&nbsp;b` gets a real
	 * non-breaking space, and `&#60;` comes back spelled `&#060;`. Nothing at render
	 * time can tell an entity an author typed from one they meant literally, so the
	 * only coherent rule is to treat every byte as literal text. This is also what
	 * GeSHi did in v5, whose `hsc()` translated `&` unconditionally.
	 *
	 * @param string $text Text exactly as the author wrote it.
	 *
	 * @return string
	 */
	public static function escape_verbatim( string $text ): string {

		// `esc_html()` is `_wp_specialchars( $text, ENT_QUOTES, false, false )`, so this is
		// that call with double encoding turned on and nothing else changed — the site's
		// own charset still decides how the bytes are read.
		$escaped = _wp_specialchars( $text, ENT_QUOTES, false, true );

		if ( '' !== $escaped || '' === $text ) {
			return $escaped;
		}

		/*
		 * Nothing else empties a string this function was given something to escape, and
		 * an empty code box is the one failure a highlighter must never have. It happens
		 * when a byte cannot be read in the site's charset: `htmlspecialchars()` returns
		 * the empty string for text invalid in the target charset unless it is told to
		 * substitute, and `_wp_specialchars()` cannot be told — it overwrites any quote
		 * style it does not recognise with ENT_QUOTES, so ENT_SUBSTITUTE never reaches
		 * the call it would have to reach. So the failure is caught instead, and the
		 * second pass substitutes U+FFFD for the unreadable bytes and keeps the rest.
		 *
		 * Reached only once the site's own charset has already refused the text, which
		 * is why naming a charset here cannot change how any renderable snippet is read.
		 * A latin1 charset reads every byte, so this is the UTF-8 site whose column is
		 * still latin1 — the install that has been carrying its content since before
		 * WordPress 4.2, which is exactly the content this plugin exists to keep
		 * rendering.
		 */
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true );

	}    //end escape_verbatim()

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
	 * Every value here is built by this class out of digits and a language id, so
	 * the escaping has nothing to do; it is applied all the same, because the rule
	 * is that nothing leaves this class unescaped and an exception for values which
	 * happen to be safe today is an exception somebody adds an attribute under.
	 *
	 * @param array $attributes Attribute name to value.
	 *
	 * @return string
	 */
	protected static function _build_attributes( array $attributes ): string {

		$markup = [];

		foreach ( $attributes as $name => $value ) {
			$markup[] = sprintf( '%s="%s"', $name, static::escape_verbatim( (string) $value ) );
		}

		return implode( ' ', $markup );

	}    //end _build_attributes()

}    //end of class


//EOF
