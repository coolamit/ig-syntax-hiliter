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
 * Contexts which carry a summary rather than a page get a strip pass instead. The
 * summary WordPress builds for itself out of the post body gets one of its own, run
 * ahead of everything else on `the_content`, because core's `strip_shortcodes()`
 * cannot be trusted with source code — see `self::strip_for_excerpt()`.
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
	 * Priority at which snippets leave the body an automatic excerpt is built from.
	 *
	 * Ahead of `self::PRIORITY_PROTECT`, so that the content the protect pass is
	 * handed has no snippet left in it to shield.
	 *
	 * @var int
	 */
	const PRIORITY_STRIP_BODY = 0;

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
	 * Filter WordPress builds an automatic excerpt inside.
	 *
	 * @var string
	 */
	const EXCERPT_FILTER = 'get_the_excerpt';

	/**
	 * Filters which carry a summary, where a code box makes no sense.
	 *
	 * Display filters, every one of them. `excerpt_save_pre` looks like it belongs
	 * here and does not: it writes to the database, and stripping is a display
	 * decision. A manual excerpt is stored with its shortcodes intact and the
	 * filters below take them off on the way out.
	 *
	 * @var array
	 */
	const EXCERPT_FILTERS = [
		self::EXCERPT_FILTER,
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

		$hilite_comments = static::is_plugin_option_on( 'hilite_comments', 'yes' );

		$display_filters = [ 'the_content' ];
		$strip_filters   = static::EXCERPT_FILTERS;

		if ( $hilite_comments ) {
			$display_filters[] = 'comment_text';
		} else {
			$strip_filters[] = 'comment_text';
		}

		add_filter( 'the_content', [ $this, 'strip_for_excerpt' ], static::PRIORITY_STRIP_BODY );

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
	 * Method to remove snippets from the body an automatic excerpt is built from.
	 *
	 * `wp_trim_excerpt()` builds a summary for a post which has no excerpt of its own
	 * by running the post body through `strip_shortcodes()`, then through the whole
	 * `the_content` chain, then through `wp_trim_words()`. Core's strip is the wrong
	 * tool for this plugin's tags and cannot be made into the right one, so the strip
	 * happens here instead, on the same body, moments later, with the matcher the rest
	 * of the plugin uses.
	 *
	 * What is wrong with core's strip is `do_shortcodes_in_html_tags()`, which
	 * `strip_shortcodes()` runs first. It splits the content with `wp_html_split()`
	 * and escapes every `[` and `]` inside anything that split reads as an element —
	 * and an element is a `<` and everything up to the next `>`, or to the end of the
	 * content when there is no `>` at all. Source code is full of a `<` which opens no
	 * element: `<?php`, `<<<EOT`, `List<T`, `if ( a < b )`. The escaping then reaches
	 * across the snippet's own closing tag, so core's pattern sees an opening tag with
	 * no closing one, removes just that, and hands back the code and a stray `[/php]`
	 * as prose. `wp_trim_words()` then either shows the code or, when the `<` reads as
	 * a tag to `strip_tags()`, eats everything after it — the rest of the post's prose
	 * with it. A `<` in the prose ahead of a snippet does the same to the opening tag.
	 *
	 * Running here rather than at `self::PRIORITY_RESTORE` means the code leaves the
	 * content before anything else on `the_content` is handed it, which is what the
	 * protect pass would otherwise have to guarantee.
	 *
	 * @param mixed $content Content being filtered.
	 *
	 * @return mixed
	 */
	public function strip_for_excerpt( $content ) {

		if ( ! static::is_generating_excerpt() ) {
			return $content;
		}

		return $this->strip( $content );

	}    //end strip_for_excerpt()

	/**
	 * Method to check whether WordPress is building an excerpt out of a post body.
	 *
	 * `wp_trim_excerpt()` is hooked to `self::EXCERPT_FILTER`, so every step it takes —
	 * the shortcode strip, the `the_content` chain, the word trim — runs with that
	 * filter still on the stack. Core's own state therefore says which of its two jobs
	 * `the_content` is doing, and it says so from a stack, so an excerpt taken part way
	 * through a render answers yes only for as long as it is in flight.
	 *
	 * Deliberately not a flag of this plugin's own. A flag set in one filter callback
	 * and cleared in another has no frame able to hold a `finally`: a callback which
	 * throws between the two leaves it set, and every code box for the rest of the
	 * request is blanked by it.
	 *
	 * @return bool
	 */
	public static function is_generating_excerpt(): bool {
		return doing_filter( static::EXCERPT_FILTER );
	}    //end is_generating_excerpt()

	/**
	 * Method to declare this plugin's tags to `strip_shortcodes()`.
	 *
	 * The tags are never handed to `add_shortcode()`, so core cannot tell they are
	 * shortcodes and leaves them in place. Anything which summarises a post by calling
	 * `strip_shortcodes()` for itself would otherwise be handed the code as prose, so
	 * the tags are declared here.
	 *
	 * They are withheld from the one caller this plugin can do better than. While an
	 * excerpt is being generated the call comes from `wp_trim_excerpt()`, whose strip
	 * damages exactly the content this plugin exists to carry — `self::strip_for_excerpt()`
	 * has the reasoning — and the same body reaches `the_content` immediately
	 * afterwards, where the strip is done properly. Claiming the tags there as well
	 * would not add a second chance; it would destroy the content before the good pass
	 * ever saw it.
	 *
	 * @param mixed $tags Shortcode tags core is about to strip.
	 *
	 * @return mixed
	 */
	public function claim_stripped_tags( $tags ) {

		if ( ! is_array( $tags ) || static::is_generating_excerpt() ) {
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

	/**
	 * Method to read one yes/no plugin option as a boolean.
	 *
	 * The one place a setting becomes a `TRUE` or a `FALSE`, so that every switch in
	 * the plugin turns on the same set of stored values. It matters for what is
	 * already in the database rather than for what gets written now: a row hand
	 * edited, or written by a version of this plugin from before its values were
	 * checked on the way in, holds whatever it holds, and reading it through one
	 * converter is what makes that harmless without anything having to rewrite it.
	 *
	 * @param string $name     Option name.
	 * @param string $fallback Value to use when the option is missing or unusable, `yes` or `no`.
	 *
	 * @return bool
	 */
	public static function is_plugin_option_on( string $name, string $fallback ): bool {
		return ( 'yes' === Validate::get_instance()->to_yesno( static::get_plugin_option( $name, $fallback ), $fallback ) );
	}    //end is_plugin_option_on()

}    //end of class


//EOF
