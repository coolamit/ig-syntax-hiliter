<?php
/**
 * GitHub Gist embeds.
 *
 * @package iG_Syntax_Hiliter
 */

namespace iG\Syntax_Hiliter;

use iG\Syntax_Hiliter\Traits\Singleton;

/**
 * Turns `[github]` into a Gist embed script, or into a link where a script cannot go.
 *
 * A Gist holds no code of its own, only a reference to one, so this pipeline has
 * nothing to protect and stands apart from the protect then restore engine. It
 * runs where it has always run and behaves as it has always behaved.
 */
class Gist_Embed {

	use Singleton;

	/**
	 * Shortcode tag this class claims.
	 *
	 * @var string
	 */
	public const string TAG = 'github';

	/**
	 * Priority at which a Gist becomes an embed.
	 *
	 * Ahead of priority 10, where `wptexturize` runs. Texturize curls the quotes
	 * around an attribute value it cannot see is a shortcode attribute, which turns
	 * `gist="https://…"` into a URL that `wp_parse_url()` then splits at the entity.
	 * Core cannot spare the attribute because this plugin never registers its tags
	 * globally, so nothing is left for `no_texturize_shortcodes` to match on.
	 *
	 * @var int
	 */
	public const int PRIORITY_EMBED = 9;

	/**
	 * Priority at which a Gist becomes a link.
	 *
	 * @var int
	 */
	public const int PRIORITY_LINK = 9;

	/**
	 * Handle the Gist stylesheet is registered under.
	 *
	 * @var string
	 */
	public const string STYLE_HANDLE = 'ig-syntax-hiliter-gist';

	/**
	 * Filters which cannot carry an embed, and get a link instead.
	 *
	 * Display filters, every one of them. A save filter would put the link in the
	 * database in place of the author's `[github]` tag, which is a transformation
	 * this plugin does not get to store.
	 *
	 * @var array
	 */
	protected array $_link_filters = [
		'get_the_excerpt',
		'the_excerpt',
		'the_excerpt_rss',
	];

	/**
	 * Whether an embed has been rendered on this page.
	 *
	 * @var bool
	 */
	protected bool $_has_embeds = false;

	/**
	 * Class constructor.
	 */
	protected function __construct() {

		$this->_register_hooks();

	}    //end __construct()

	/**
	 * Method to hook this class up to WordPress.
	 *
	 * `gist_in_comments` is read here rather than at filter time, because it decides
	 * whether `comment_text` gets an embed or a link.
	 *
	 * @return void
	 */
	protected function _register_hooks(): void {

		$embed_filters = [ 'the_content' ];

		if ( Shortcode_Handler::is_plugin_option_on( 'gist_in_comments', 'no' ) ) {
			$embed_filters[] = 'comment_text';
		} else {
			$this->_link_filters[] = 'comment_text';
		}

		foreach ( $embed_filters as $filter ) {
			add_filter( $filter, [ $this, 'parse' ], static::PRIORITY_EMBED );
		}

		foreach ( $this->_link_filters as $filter ) {
			add_filter( $filter, [ $this, 'parse' ], static::PRIORITY_LINK );
		}

		/*
		 * The same two moments the asset manager decides at, and for the same reason:
		 * a Gist rendered by something which itself runs from `wp_footer` would miss
		 * the first pass, and core prints the footer styles at priority 20. A page
		 * carrying nothing but a Gist loads no stylesheet of this plugin's otherwise,
		 * so this is wiring of its own rather than one more rule in an existing sheet.
		 */
		add_action( 'wp_footer', [ $this, 'enqueue' ], Asset_Manager::PRIORITY_DECIDE );
		add_action( 'wp_footer', [ $this, 'enqueue' ], Asset_Manager::PRIORITY_DECIDE_AGAIN );

	}    //end _register_hooks()

	/**
	 * Method to enqueue the Gist stylesheet, if the page has an embed on it.
	 *
	 * Safe to call more than once: enqueuing a handle which is already enqueued
	 * does nothing.
	 *
	 * @return void
	 */
	public function enqueue(): void {

		if ( is_admin() || ! $this->_has_embeds ) {
			return;
		}

		if ( ! Shortcode_Handler::is_plugin_option_on( 'gist_limit_height', 'yes' ) ) {
			return;
		}

		wp_enqueue_style(
			static::STYLE_HANDLE,
			Helper::get_asset_url( 'build/css/gist.css' ),
			[],
			Helper::get_version( '0' )
		);

	}    //end enqueue()

	/**
	 * Method to run the Gist shortcode over content.
	 *
	 * Only this plugin's tag is registered for the duration of the call, so no other
	 * plugin's shortcode is processed here by accident.
	 *
	 * The guard names the tag rather than looking for a bare `[`. This runs on
	 * `the_content` and three excerpt filters for every post on every request, and a
	 * `[` appears in most real writing — so the cheaper test was answering "maybe" on
	 * nearly every page and paying for `do_shortcodes_in_html_tags()` to split the
	 * whole body and the shortcode matcher to walk it, in order to find a tag that was
	 * never there. Shortcode tags are case sensitive and core's matcher allows no
	 * whitespace between `[` and the name, so `[github` is the only way this one can
	 * begin; the escaped form `[[github …]]` contains it too.
	 *
	 * @param mixed $content Content being filtered.
	 *
	 * @return mixed
	 */
	public function parse( mixed $content ): mixed {

		if ( ! is_string( $content ) || is_admin() || ! str_contains( $content, '[' . static::TAG ) ) {
			return $content;
		}

		global $shortcode_tags;

		$original_shortcode_tags = $shortcode_tags;

		remove_all_shortcodes();

		add_shortcode( static::TAG, [ $this, 'render' ] );

		$content = do_shortcode( $content );

		$shortcode_tags = $original_shortcode_tags;    // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Putting back the registry this method borrowed.

		return $content;

	}    //end parse()

	/**
	 * Method to render one Gist.
	 *
	 * @param array|string $atts Shortcode attributes. WordPress passes an empty string when there are none.
	 *
	 * @return string
	 */
	public function render( array|string $atts = [] ): string {

		$atts = shortcode_atts(
			[
				'id'   => 0,
				'gist' => '',
			],
			$atts,
			static::TAG
		);

		$id = static::resolve_id( $atts );

		if ( empty( $id ) ) {
			return '';
		}

		$gist = sprintf( 'https://gist.github.com/%s', $id );

		if ( in_array( current_filter(), $this->_link_filters, true ) ) {
			return sprintf(
				'<div class="igsh-gist"><span class="igsh-gist__label">%1$s</span> <a href="%2$s" rel="nofollow">%3$s</a></div>',
				esc_html__( 'Github Gist:', 'igsyntax-hiliter' ),
				esc_url( $gist ),
				esc_html( $gist )
			);
		}

		$this->_has_embeds = true;

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- A Gist embed is a third party script tag placed inline, by design.
		return sprintf( '<script src="%s.js"></script>', esc_url( $gist ) );

	}    //end render()

	/**
	 * Method to work out which Gist a set of attributes names.
	 *
	 * The one place `gist="<url>"` and `id="<id>"` are turned into a Gist id, so that
	 * the embed and the revert tool cannot come to different answers about the same
	 * block. A drift between the two would be a Gist rendering one way before the tool
	 * runs and another way after it, which is precisely the failure the tool exists to
	 * prevent.
	 *
	 * The `gist` attribute wins where it names anything at all: it is the form the
	 * block writes and the form nearly every author types.
	 *
	 * @param array $atts Attributes, with `gist` and `id` keys as `self::render()` receives them.
	 *
	 * @return string The Gist id, or an empty string where the attributes name no Gist this plugin will print.
	 */
	public static function resolve_id( array $atts ): string {

		$id   = $atts['id'] ?? 0;
		$path = wp_parse_url( untrailingslashit( (string) ( $atts['gist'] ?? '' ) ), PHP_URL_PATH );

		if ( ! empty( $path ) ) {

			// The `gist` attribute takes priority: the id is the last segment of its URL.
			$segments = explode( '/', $path );
			$gist_id  = array_pop( $segments );

			if ( ! empty( $gist_id ) ) {
				$id = $gist_id;
			}
		}

		if ( empty( $id ) ) {
			return '';
		}

		return static::_sanitize_id( (string) $id );

	}    //end resolve_id()

	/**
	 * Method to sanitize a Gist id.
	 *
	 * The id becomes one path segment of a URL this plugin prints, so it is held to
	 * what a Gist id is: letters and digits, nothing else. `sanitize_user()` stood
	 * here until 6.0 and is the wrong tool — it sanitizes usernames, so it permits
	 * `_ . - @` and spaces, and a `..` popped off the end of a `gist` URL therefore
	 * went into that path whole.
	 *
	 * Anything else is refused outright rather than stripped down to the characters
	 * that would survive, because a stripped id names a different Gist, and every
	 * caller already reads an empty id as "print nothing". A failed match, including
	 * the `false` PCRE returns on an error, lands on the same refusal.
	 *
	 * @param string $id Gist id to sanitize.
	 *
	 * @return string
	 */
	protected static function _sanitize_id( string $id ): string {

		if ( 1 !== preg_match( '/^[A-Za-z0-9]+$/', $id ) ) {
			return '';
		}

		return $id;

	}    //end _sanitize_id()

}    //end of class


//EOF
