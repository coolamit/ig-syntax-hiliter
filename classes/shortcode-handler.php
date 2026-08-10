<?php
/**
 * Adapter which renders the plugin's legacy shortcodes.
 *
 * @package iG_Syntax_Hiliter
 */

namespace iG\Syntax_Hiliter;

use iG\Syntax_Hiliter\Traits\Singleton;

/**
 * Wires the protect then restore engine to the filters WordPress runs content
 * through, and turns a matched shortcode into a snippet.
 *
 * Display filters get a protect pass before anything else has run and a restore
 * pass after everything else has finished. Save filters get the same treatment,
 * except that what comes back is the author's original bytes rather than markup —
 * KSES is bracketed, never bypassed, and nothing transformed is ever stored.
 *
 * Contexts which carry a summary rather than a page get a strip pass instead, and
 * the plugin's tags are declared to `strip_shortcodes()` so that the summary core
 * builds for itself is stripped too.
 */
class Shortcode_Handler {

	use Singleton;

	/**
	 * Priority at which content is protected.
	 *
	 * @var int
	 */
	const PRIORITY_PROTECT = 1;

	/**
	 * Priority at which content is restored.
	 *
	 * Late enough to be past `wpautop`, KSES, `balanceTags` and anything a theme or
	 * another plugin is likely to add.
	 *
	 * @var int
	 */
	const PRIORITY_RESTORE = 100;

	/**
	 * Priority at which snippets are stripped out.
	 *
	 * @var int
	 */
	const PRIORITY_STRIP = 2;

	/**
	 * Filters whose content is saved rather than displayed.
	 *
	 * @var array
	 */
	const SAVE_FILTERS = [
		'content_save_pre',
		'content_filtered_save_pre',
	];

	/**
	 * Filters which carry a summary, where a code box makes no sense.
	 *
	 * Display filters, every one of them. `excerpt_save_pre` looks like it belongs
	 * here and does not: it writes to the database, and stripping is a display
	 * decision (I5). A manual excerpt is stored with its shortcodes intact and the
	 * filters below take them off on the way out.
	 *
	 * @var array
	 */
	const EXCERPT_FILTERS = [
		'get_the_excerpt',
		'the_excerpt',
		'the_excerpt_rss',
	];

	/**
	 * Whether the hooks have been registered already.
	 *
	 * @var bool
	 */
	protected bool $_hooked = false;

	/**
	 * Method to hook the shortcode pipeline up to WordPress.
	 *
	 * @return void
	 */
	public function register_hooks(): void {

		if ( $this->_hooked ) {
			return;
		}

		$this->_hooked = true;

		$hilite_comments = ( 'yes' === static::get_plugin_option( 'hilite_comments', 'yes' ) );

		$display_filters = [ 'the_content' ];
		$strip_filters   = static::EXCERPT_FILTERS;

		if ( $hilite_comments ) {
			$display_filters[] = 'comment_text';
		} else {
			$strip_filters[] = 'comment_text';
		}

		foreach ( $display_filters as $filter ) {
			add_filter( $filter, [ $this, 'protect_display' ], static::PRIORITY_PROTECT );
			add_filter( $filter, [ $this, 'restore_display' ], static::PRIORITY_RESTORE );
		}

		foreach ( $strip_filters as $filter ) {
			add_filter( $filter, [ $this, 'strip' ], static::PRIORITY_STRIP );
		}

		add_filter( 'strip_shortcodes_tagnames', [ $this, 'claim_stripped_tags' ] );

		foreach ( static::SAVE_FILTERS as $filter ) {
			add_filter( $filter, [ $this, 'protect_save' ], static::PRIORITY_PROTECT );
			add_filter( $filter, [ $this, 'restore_save' ], static::PRIORITY_RESTORE );
		}

	}    //end register_hooks()

	/**
	 * Method to lift snippets out of content on its way to the browser.
	 *
	 * @param mixed $content Content being filtered.
	 *
	 * @return mixed
	 */
	public function protect_display( $content ) {

		if ( ! is_string( $content ) ) {
			return $content;
		}

		return Content_Protector::get_instance()->protect_for_display( $content );

	}    //end protect_display()

	/**
	 * Method to put rendered code boxes back into content.
	 *
	 * @param mixed $content Content being filtered.
	 *
	 * @return mixed
	 */
	public function restore_display( $content ) {

		if ( ! is_string( $content ) ) {
			return $content;
		}

		return Content_Protector::get_instance()->restore_rendered( $content );

	}    //end restore_display()

	/**
	 * Method to lift snippets out of content on its way to the database.
	 *
	 * The content is slashed here. Nothing is unslashed, parsed or rewritten — the
	 * matched bytes are simply set aside and handed back untouched at
	 * `self::PRIORITY_RESTORE`.
	 *
	 * @param mixed $content Content being filtered.
	 *
	 * @return mixed
	 */
	public function protect_save( $content ) {

		if ( ! is_string( $content ) ) {
			return $content;
		}

		return Content_Protector::get_instance()->protect_for_save( $content );

	}    //end protect_save()

	/**
	 * Method to put the author's original bytes back into content.
	 *
	 * @param mixed $content Content being filtered.
	 *
	 * @return mixed
	 */
	public function restore_save( $content ) {

		if ( ! is_string( $content ) ) {
			return $content;
		}

		return Content_Protector::get_instance()->restore_verbatim( $content );

	}    //end restore_save()

	/**
	 * Method to remove snippets from content which cannot carry a code box.
	 *
	 * Every filter this is hooked to renders; none of them stores. What is stripped
	 * here is a copy on its way to a summary, never the author's excerpt.
	 *
	 * @param mixed $content Content being filtered.
	 *
	 * @return mixed
	 */
	public function strip( $content ) {

		if ( ! is_string( $content ) ) {
			return $content;
		}

		return Content_Protector::get_instance()->strip( $content );

	}    //end strip()

	/**
	 * Method to declare this plugin's tags to `strip_shortcodes()`.
	 *
	 * The tags are never handed to `add_shortcode()`, so core cannot tell they are
	 * shortcodes and leaves them in place. `wp_trim_excerpt()` strips shortcodes out
	 * of the post body and then runs what is left through `the_content` to build an
	 * automatic excerpt, so without this a snippet would be rendered into a code box
	 * and `wp_trim_words()` would take the markup off and leave the code as prose.
	 *
	 * @param mixed $tags Shortcode tags core is about to strip.
	 *
	 * @return mixed
	 */
	public function claim_stripped_tags( $tags ) {

		if ( ! is_array( $tags ) ) {
			return $tags;
		}

		return array_values( array_unique( array_merge( $tags, Legacy_Map::get_tags() ) ) );

	}    //end claim_stripped_tags()

	/**
	 * Method to build a snippet from a matched shortcode.
	 *
	 * A named language tag names its own language; `[sourcecode]` carries it in an
	 * attribute.
	 *
	 * @param string       $tag  Shortcode tag which was matched.
	 * @param array|string $atts Attributes, either parsed or as the raw attribute string.
	 * @param string       $code Shortcode content, ie. the source code.
	 *
	 * @return \iG\Syntax_Hiliter\Snippet
	 */
	public static function build_snippet( string $tag, array|string $atts, string $code ): Snippet {

		$atts = ( is_string( $atts ) ) ? shortcode_parse_atts( $atts ) : $atts;
		$atts = ( is_array( $atts ) ) ? $atts : [];

		// Drops attributes the plugin does not know, and keeps the `shortcode_atts_{$tag}` filter working.
		$atts = shortcode_atts( static::get_default_atts(), $atts, $tag );

		if ( Legacy_Map::GENERIC_TAG !== $tag ) {
			$atts['language'] = $tag;
		}

		return Snippet::from_shortcode_atts( $atts, $code, static::show_line_numbers() );

	}    //end build_snippet()

	/**
	 * Method to get the attributes a legacy shortcode may carry.
	 *
	 * Every default is empty so that nothing is forced on to a snippet which said
	 * nothing about it. `plaintext`, `toolbar` and `strict_mode` are accepted and
	 * ignored.
	 *
	 * @return array
	 */
	public static function get_default_atts(): array {

		return [
			'language'    => '',
			'lang'        => '',
			'firstline'   => '',
			'num'         => '',
			'highlight'   => '',
			'file'        => '',
			'gutter'      => '',
			'plaintext'   => '',
			'toolbar'     => '',
			'strict_mode' => '',
		];

	}    //end get_default_atts()

	/**
	 * Method to check whether line numbers are shown unless a snippet says otherwise.
	 *
	 * @return bool
	 */
	public static function show_line_numbers(): bool {
		return ( 'no' !== strtolower( trim( (string) Option::get_instance()->get( 'show_line_numbers' ) ) ) );
	}    //end show_line_numbers()

	/**
	 * Method to read one plugin option with a fallback.
	 *
	 * The one reader every caller shares, so that a missing or unusable value means
	 * the same thing wherever it is read.
	 *
	 * @param string $name     Option name.
	 * @param string $fallback Value to use when the option is missing or unusable.
	 *
	 * @return string
	 */
	public static function get_plugin_option( string $name, string $fallback ): string {

		$value = Option::get_instance()->get( $name );

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return $fallback;
		}

		return strtolower( trim( $value ) );

	}    //end get_plugin_option()

}    //end of class


//EOF
