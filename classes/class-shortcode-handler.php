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
 * Display filters get a protect pass first and a restore pass last; save filters the
 * same, except the author's original bytes come back rather than markup. Summary
 * contexts get a strip pass instead.
 */
class Shortcode_Handler {

	use Singleton;

	/**
	 * Priority at which content is protected.
	 *
	 * @var int
	 */
	public const int PRIORITY_PROTECT = 1;

	/**
	 * Priority at which content is restored.
	 *
	 * Late enough to be past `wpautop`, KSES, `balanceTags` and anything a theme or plugin adds.
	 *
	 * @var int
	 */
	public const int PRIORITY_RESTORE = 100;

	/**
	 * Priority at which snippets are stripped out.
	 *
	 * @var int
	 */
	public const int PRIORITY_STRIP = 2;

	/**
	 * Priority at which snippets leave the body an automatic excerpt is built from.
	 *
	 * Ahead of `self::PRIORITY_PROTECT`, so the protect pass has no snippet left to shield.
	 *
	 * @var int
	 */
	public const int PRIORITY_STRIP_BODY = 0;

	/**
	 * Filters whose content is saved rather than displayed.
	 *
	 * @var array
	 */
	public const array SAVE_FILTERS = [
		'content_save_pre',
		'content_filtered_save_pre',
	];

	/**
	 * Filter WordPress builds an automatic excerpt inside.
	 *
	 * @var string
	 */
	protected const string _EXCERPT_FILTER = 'get_the_excerpt';

	/**
	 * Filters which carry a summary, where a code box makes no sense.
	 *
	 * Display filters only. `excerpt_save_pre` looks like it belongs here and does
	 * not — it writes to the database, and stripping is a display decision.
	 *
	 * @var array
	 */
	public const array EXCERPT_FILTERS = [
		self::_EXCERPT_FILTER,
		'the_excerpt',
		'the_excerpt_rss',
	];

	/**
	 * Class constructor.
	 */
	protected function __construct() {

		$this->_register_hooks();

	}

	/**
	 * Method to hook this class up to WordPress.
	 *
	 * `hilite_comments` is read here because it moves `comment_text` between the display
	 * list and the strip list.
	 *
	 * @return void
	 */
	protected function _register_hooks(): void {

		$hilite_comments = $this->is_plugin_option_on( 'hilite_comments', 'yes' );

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

	}

	/**
	 * Method to lift snippets out of content on its way to the browser.
	 *
	 * @param mixed $content Content being filtered.
	 *
	 * @return mixed
	 */
	public function protect_display( mixed $content ): mixed {

		if ( ! is_string( $content ) ) {
			return $content;
		}

		return Content_Protector::get_instance()->protect_for_display( $content );

	}

	/**
	 * Method to put rendered code boxes back into content.
	 *
	 * @param mixed $content Content being filtered.
	 *
	 * @return mixed
	 */
	public function restore_display( mixed $content ): mixed {

		if ( ! is_string( $content ) ) {
			return $content;
		}

		return Content_Protector::get_instance()->restore_rendered( $content );

	}

	/**
	 * Method to lift snippets out of content on its way to the database.
	 *
	 * The content is slashed here; the matched bytes are set aside and handed back untouched.
	 *
	 * @param mixed $content Content being filtered.
	 *
	 * @return mixed
	 */
	public function protect_save( mixed $content ): mixed {

		if ( ! is_string( $content ) ) {
			return $content;
		}

		return Content_Protector::get_instance()->protect_for_save( $content );

	}

	/**
	 * Method to put the author's original bytes back into content.
	 *
	 * @param mixed $content Content being filtered.
	 *
	 * @return mixed
	 */
	public function restore_save( mixed $content ): mixed {

		if ( ! is_string( $content ) ) {
			return $content;
		}

		return Content_Protector::get_instance()->restore_verbatim( $content );

	}

	/**
	 * Method to remove snippets from content which cannot carry a code box.
	 *
	 * Every filter this is hooked to renders; none of them stores.
	 *
	 * @param mixed $content Content being filtered.
	 *
	 * @return mixed
	 */
	public function strip( mixed $content ): mixed {

		if ( ! is_string( $content ) ) {
			return $content;
		}

		return Content_Protector::get_instance()->strip( $content );

	}

	/**
	 * Method to remove snippets from the body an automatic excerpt is built from.
	 *
	 * `wp_trim_excerpt()` runs the body through `strip_shortcodes()` first. Core's strip
	 * runs `do_shortcodes_in_html_tags()`, which escapes `[`/`]` inside anything
	 * `wp_html_split()` reads as an element — a `<` up to the next `>`, or to the end of
	 * the content. Source code is full of a `<` that opens no element (`<?php`, `<<<EOT`,
	 * `List<T`, `a < b`), so the escaping reaches past the snippet's closing tag and core
	 * hands back the code plus a stray `[/php]` as prose. So the strip happens here
	 * instead, with this plugin's matcher.
	 *
	 * @param mixed $content Content being filtered.
	 *
	 * @return mixed
	 */
	public function strip_for_excerpt( mixed $content ): mixed {

		if ( ! $this->is_generating_excerpt() ) {
			return $content;
		}

		return $this->strip( $content );

	}

	/**
	 * Method to check whether WordPress is building an excerpt out of a post body.
	 *
	 * `wp_trim_excerpt()` is hooked to `self::_EXCERPT_FILTER`, so every step it takes
	 * runs with it on the stack — core's own state says which job `the_content` is doing.
	 * Not a flag of this plugin's own: a callback which throws between set and clear
	 * would blank every code box for the rest of the request.
	 *
	 * @return bool
	 */
	public function is_generating_excerpt(): bool {
		return doing_filter( static::_EXCERPT_FILTER );
	}

	/**
	 * Method to declare this plugin's tags to `strip_shortcodes()`.
	 *
	 * The tags are never handed to `add_shortcode()`, so core cannot tell they are
	 * shortcodes; anything summarising a post via `strip_shortcodes()` would otherwise
	 * be handed the code as prose. Withheld while an excerpt is being generated: core's
	 * strip would damage the code before `strip_for_excerpt()` sees the same body on
	 * `the_content`.
	 *
	 * @param mixed $tags Shortcode tags core is about to strip.
	 *
	 * @return mixed
	 */
	public function claim_stripped_tags( mixed $tags ): mixed {

		if ( ! is_array( $tags ) || $this->is_generating_excerpt() ) {
			return $tags;
		}

		return array_values( array_unique( array_merge( $tags, Legacy_Map::get_instance()->get_tags() ) ) );

	}

	/**
	 * Method to build a snippet from a matched shortcode.
	 *
	 * A named language tag names its own language; `[sourcecode]` carries it in an
	 * attribute. One pair of brackets is taken off every escaped tag in the code,
	 * mirroring `Legacy_Map::escape_tags()`; nothing stored is touched.
	 *
	 * @param string       $tag  Shortcode tag which was matched.
	 * @param array|string $atts Attributes, either parsed or as the raw attribute string.
	 * @param string       $code Shortcode content, ie. the source code.
	 *
	 * @return \iG\Syntax_Hiliter\Snippet
	 */
	public function build_snippet( string $tag, array|string $atts, string $code ): Snippet {

		$code = Legacy_Map::get_instance()->unescape_tags( $code );
		$atts = ( is_string( $atts ) ) ? shortcode_parse_atts( $atts ) : $atts;
		$atts = ( is_array( $atts ) ) ? $atts : [];

		// Drops attributes the plugin does not know, and keeps the `shortcode_atts_{$tag}`
		// filter working.
		$atts = shortcode_atts( $this->get_default_atts(), $atts, $tag );

		if ( Legacy_Map::GENERIC_TAG !== $tag ) {
			$atts['language'] = $tag;
		}

		return Snippet::from_shortcode_atts( $atts, $code, $this->show_line_numbers() );

	}

	/**
	 * Method to get the attributes a legacy shortcode may carry.
	 *
	 * Every default is empty so nothing is forced on a snippet which said nothing about
	 * it. `plaintext`, `toolbar` and `strict_mode` are accepted and ignored.
	 *
	 * @return array
	 */
	public function get_default_atts(): array {

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

	}

	/**
	 * Method to check whether line numbers are shown unless a snippet says otherwise.
	 *
	 * @return bool
	 */
	public function show_line_numbers(): bool {
		return ( 'no' !== strtolower( trim( (string) Option::get_instance()->get( 'show_line_numbers' ) ) ) );
	}

	/**
	 * Method to read one plugin option with a fallback.
	 *
	 * The one reader every caller shares, so a missing or unusable value means the same
	 * thing everywhere.
	 *
	 * @param string $name     Option name.
	 * @param string $fallback Value to use when the option is missing or unusable.
	 *
	 * @return string
	 */
	public function get_plugin_option( string $name, string $fallback ): string {

		$value = Option::get_instance()->get( $name );

		// `'' ===` and not `empty()`: a stored `0` means off, and `empty( '0' )` is TRUE — so an
		// `empty()` would return the fallback (`yes` for `hilite_comments`) before `to_yesno()`
		// ever saw the value.
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return $fallback;
		}

		return strtolower( trim( $value ) );

	}

	/**
	 * Method to read one yes/no plugin option as a boolean.
	 *
	 * The one place a setting becomes a boolean, so every switch turns on the same set of
	 * stored values.
	 *
	 * @param string $name     Option name.
	 * @param string $fallback Value to use when the option is missing or unusable, `yes` or `no`.
	 *
	 * @return bool
	 */
	public function is_plugin_option_on( string $name, string $fallback ): bool {
		return ( 'yes' === Validate::get_instance()->to_yesno( $this->get_plugin_option( $name, $fallback ), $fallback ) );
	}

} // end of class

// EOF
