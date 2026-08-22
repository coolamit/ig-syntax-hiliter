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
 * A Gist holds no code, only a reference to one, so this pipeline has nothing to
 * protect and stands apart from the protect then restore engine.
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
	 * Ahead of priority 10, where `wptexturize` runs: it curls the quotes around
	 * `gist="https://…"` and `wp_parse_url()` then splits the URL at the entity.
	 * `no_texturize_shortcodes` cannot help, since this plugin never registers its tags globally.
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
	 * Display filters, every one. A save filter would store the link in place of the
	 * author's `[github]` tag.
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

	}

	/**
	 * Method to hook this class up to WordPress.
	 *
	 * `gist_in_comments` decides whether `comment_text` gets an embed or a link.
	 *
	 * @return void
	 */
	protected function _register_hooks(): void {

		$embed_filters = [ 'the_content' ];

		if ( Shortcode_Handler::get_instance()->is_plugin_option_on( 'gist_in_comments', 'no' ) ) {
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

		// The same two moments the asset manager decides at: a Gist rendered from `wp_footer`
		// itself would miss the first pass, and core prints the footer styles at 20. A page
		// carrying only a Gist loads no other stylesheet of this plugin's, so this is wiring
		// of its own.
		add_action( 'wp_footer', [ $this, 'enqueue' ], Asset_Manager::PRIORITY_DECIDE );
		add_action( 'wp_footer', [ $this, 'enqueue' ], Asset_Manager::PRIORITY_DECIDE_AGAIN );

	}

	/**
	 * Method to enqueue the Gist stylesheet, if the page has an embed on it.
	 *
	 * Safe to call more than once; enqueuing an enqueued handle does nothing.
	 *
	 * @return void
	 */
	public function enqueue(): void {

		if ( is_admin() || ! $this->_has_embeds ) {
			return;
		}

		if ( ! Shortcode_Handler::get_instance()->is_plugin_option_on( 'gist_limit_height', 'yes' ) ) {
			return;
		}

		wp_enqueue_style(
			static::STYLE_HANDLE,
			Helper::get_asset_url( 'build/css/gist.css' ),
			[],
			Helper::get_version( '0' )
		);

	}

	/**
	 * Method to run the Gist shortcode over content.
	 *
	 * Only this plugin's tag is registered for the duration of the call. The guard names
	 * the tag rather than looking for a bare `[`: a `[` appears in most writing, and the
	 * cheaper test would pay for `do_shortcodes_in_html_tags()` on nearly every page.
	 * Tags are case sensitive and allow no whitespace after `[`.
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

	}

	/**
	 * Method to render one Gist.
	 *
	 * @param array|string $atts Shortcode attributes. WordPress passes an empty string when
	 *                           there are none.
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

		$id = $this->resolve_id( $atts );

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

	}

	/**
	 * Method to work out which Gist a set of attributes names.
	 *
	 * The one place `gist=` and `id=` become a Gist id, so the embed and the revert tool
	 * cannot disagree. `gist` wins where it names anything.
	 *
	 * @param array $atts Attributes, with `gist` and `id` keys as `render()` receives them.
	 *
	 * @return string The Gist id, or an empty string where the attributes name no Gist this
	 *                plugin will print.
	 */
	public function resolve_id( array $atts ): string {

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

		return $this->_sanitize_id( (string) $id );

	}

	/**
	 * Method to sanitize a Gist id.
	 *
	 * Letters and digits only, since the id becomes a path segment of a URL this plugin
	 * prints. Refused outright rather than stripped, because a stripped id names a
	 * different Gist.
	 *
	 * @param string $id Gist id to sanitize.
	 *
	 * @return string
	 */
	protected function _sanitize_id( string $id ): string {

		if ( 1 !== preg_match( '/^[A-Za-z0-9]+$/', $id ) ) {
			return '';
		}

		return $id;

	}

} // end of class

// EOF
