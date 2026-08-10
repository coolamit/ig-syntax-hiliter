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
	const TAG = 'github';

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
	const PRIORITY_EMBED = 9;

	/**
	 * Priority at which a Gist becomes a link.
	 *
	 * @var int
	 */
	const PRIORITY_LINK = 9;

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
	 * Whether the hooks have been registered already.
	 *
	 * @var bool
	 */
	protected bool $_hooked = false;

	/**
	 * Method to hook the Gist pipeline up to WordPress.
	 *
	 * @return void
	 */
	public function register_hooks(): void {

		if ( $this->_hooked ) {
			return;
		}

		$this->_hooked = true;

		$embed_filters = [ 'the_content' ];

		if ( 'yes' === Shortcode_Handler::get_plugin_option( 'gist_in_comments', 'no' ) ) {
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

	}    //end register_hooks()

	/**
	 * Method to run the Gist shortcode over content.
	 *
	 * Only this plugin's tag is registered for the duration of the call, so no other
	 * plugin's shortcode is processed here by accident.
	 *
	 * @param mixed $content Content being filtered.
	 *
	 * @return mixed
	 */
	public function parse( $content ) {

		if ( ! is_string( $content ) || is_admin() || ! str_contains( $content, '[' ) ) {
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

		$id   = $atts['id'];
		$path = wp_parse_url( untrailingslashit( (string) $atts['gist'] ), PHP_URL_PATH );

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

		$id = $this->_sanitize_id( (string) $id );

		if ( '' === $id ) {
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

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- A Gist embed is a third party script tag placed inline, by design.
		return sprintf( '<script src="%s.js"></script>', esc_url( $gist ) );

	}    //end render()

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
	 * that would survive, because a stripped id names a different Gist, and both
	 * callers already read an empty id as "print nothing". A failed match, including
	 * the `false` PCRE returns on an error, lands on the same refusal.
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

	}    //end _sanitize_id()

}    //end of class


//EOF
