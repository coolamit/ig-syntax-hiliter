<?php
/**
 * Tests for the `[github]` Gist pipeline.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Gist_Embed;
use iG\Syntax_Hiliter\Shortcode_Handler;
use WP_UnitTestCase;

/**
 * Checks that Gist embeds behave exactly as they did before.
 */
class Gist_Embed_Test extends WP_UnitTestCase {

	/**
	 * Registers the pipeline once WordPress is up.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		Gist_Embed::get_instance()->register_hooks();

	}

	/**
	 * Method to run content through one of WordPress' own filters.
	 *
	 * @param string $filter  Filter name.
	 * @param string $content Content to filter.
	 *
	 * @return string
	 */
	protected function _filter( string $filter, string $content ): string {
		return (string) apply_filters( $filter, $content );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Running content through core's own hooks is what an integration test does.
	}

	/**
	 * Method to build the embed markup a Gist id is expected to produce.
	 *
	 * @param string $id Gist id.
	 *
	 * @return string
	 */
	protected function _expected_embed( string $id ): string {
		return sprintf( '<script src="https://gist.github.com/%s.js"></script>', $id );  // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- A Gist embed is an inline third party script tag, by design.
	}

	/**
	 * The Gist pipeline runs ahead of `wptexturize`.
	 *
	 * Texturize is registered on `the_content` at priority 10 and curls the quotes
	 * around a `gist="…"` URL before this pipeline can parse it, which is what broke
	 * that attribute for the whole of v5. Priority 9 is the fix, so it is pinned.
	 *
	 * @return void
	 */
	public function test_hooks_are_registered_ahead_of_texturize(): void {

		$gist = Gist_Embed::get_instance();

		$this->assertSame( 9, has_filter( 'the_content', [ $gist, 'parse' ] ) );
		$this->assertLessThan(
			has_filter( 'the_content', 'wptexturize' ),
			has_filter( 'the_content', [ $gist, 'parse' ] ),
			'The Gist pipeline must parse its attributes before texturize rewrites them.'
		);

		$this->assertSame( 9, has_filter( 'the_excerpt', [ $gist, 'parse' ] ) );

		// `gist_in_comments` is off by default, so comments get the link form.
		$this->assertSame( 9, has_filter( 'comment_text', [ $gist, 'parse' ] ) );

	}

	/**
	 * An id becomes an embed script.
	 *
	 * @return void
	 */
	public function test_an_id_becomes_an_embed(): void {

		$output = $this->_filter( 'the_content', '[github id="abc123"]' );

		$this->assertStringContainsString( $this->_expected_embed( 'abc123' ), $output );

	}

	/**
	 * A full Gist URL wins over the id, and only its last segment is used.
	 *
	 * Run through `the_content` rather than called directly, because that is the
	 * path the quoted URL was broken on for the whole of v5: `wptexturize` curled
	 * the quotes at priority 10 before this pipeline could read them, leaving
	 * `https://gist.github.com/.js`. The embed now runs at 9, so the URL survives.
	 *
	 * @return void
	 */
	public function test_a_url_wins_over_an_id(): void {

		$output = $this->_filter( 'the_content', '[github id="ignored" gist="https://gist.github.com/someone/def456/"]' );

		$this->assertStringContainsString( $this->_expected_embed( 'def456' ), $output );

	}

	/**
	 * Every documented way of naming a Gist still renders exactly what it rendered.
	 *
	 * The id is hardened against path segments that are not Gist ids, and this is
	 * what says the hardening did not take a real Gist with it. A 32 character hex
	 * id is what GitHub actually hands out, so it is the shape pinned here, on the
	 * embed path and the link path both.
	 *
	 * @return void
	 */
	public function test_the_documented_forms_render_as_they_always_did(): void {

		$id = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

		$this->assertStringContainsString(
			$this->_expected_embed( $id ),
			$this->_filter( 'the_content', sprintf( '[github id="%s"]', $id ) )
		);

		$this->assertStringContainsString(
			$this->_expected_embed( $id ),
			$this->_filter( 'the_content', sprintf( '[github gist="https://gist.github.com/someone/%s"]', $id ) )
		);

		$this->assertStringContainsString(
			sprintf( '<a href="https://gist.github.com/%1$s" rel="nofollow">https://gist.github.com/%1$s</a>', $id ),
			$this->_filter( 'the_excerpt', sprintf( '[github id="%s"]', $id ) )
		);

	}

	/**
	 * A path segment that is not a Gist id never reaches the URL.
	 *
	 * `sanitize_user()` stood in the sanitising slot until 6.0, and being a username
	 * sanitiser it lets `. - _ @` and spaces through — so `..` went into the path of
	 * a URL this plugin then printed. Both paths are checked because the embed and
	 * the link are two different pieces of markup built from that one URL.
	 *
	 * @return void
	 */
	public function test_a_traversal_never_reaches_the_gist_url(): void {

		$inputs = [
			'[github gist="https://gist.github.com/someone/../evil"]',
			'[github gist="https://gist.github.com/someone/.."]',
			'[github id=".."]',
			'[github id="../evil"]',
		];

		foreach ( $inputs as $input ) {

			foreach ( [ 'the_content', 'the_excerpt' ] as $filter ) {

				$this->assertStringNotContainsString(
					'..',
					$this->_filter( $filter, $input ),
					sprintf( '`%1$s` put a traversal into the output of `%2$s`.', $input, $filter )
				);
			}
		}

	}

	/**
	 * An id which cannot be a Gist id prints nothing at all.
	 *
	 * Not a URL with the offending characters taken out of it: an id with characters
	 * removed names a different Gist, so a refusal is the only honest answer.
	 *
	 * @return void
	 */
	public function test_an_id_that_cannot_be_a_gist_id_prints_nothing(): void {

		$inputs = [
			'[github id=".."]',
			'[github id="a b"]',
			'[github id="../evil"]',
			'[github gist="https://gist.github.com/someone/.."]',
		];

		foreach ( $inputs as $input ) {

			foreach ( [ 'the_content', 'the_excerpt' ] as $filter ) {

				$this->assertStringNotContainsString(
					'gist.github.com',
					$this->_filter( $filter, $input ),
					sprintf( '`%1$s` was printed as a Gist URL by `%2$s`.', $input, $filter )
				);
			}
		}

	}

	/**
	 * A bare `[github]` with no attributes renders nothing, and above all does not
	 * fatal — the v5 signature took `array $atts`, which fatals on the empty string
	 * a shortcode with no attributes used to be handed. WordPress hands callbacks an
	 * array from 6.5 onwards, so the string is asserted against directly.
	 *
	 * @return void
	 */
	public function test_a_bare_github_tag_renders_nothing_and_does_not_fatal(): void {

		$output = $this->_filter( 'the_content', 'before [github] after' );

		$this->assertStringNotContainsString( '[github]', $output );
		$this->assertStringNotContainsString( 'gist.github.com', $output );
		$this->assertStringContainsString( 'before', $output );
		$this->assertStringContainsString( 'after', $output );

		$this->assertSame( '', Gist_Embed::get_instance()->render( '' ) );

	}

	/**
	 * Where a script cannot go, a link goes instead.
	 *
	 * @return void
	 */
	public function test_an_excerpt_gets_a_link_instead_of_a_script(): void {

		$output = $this->_filter( 'the_excerpt', '[github id="abc123"]' );

		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringContainsString( 'class="igsh-gist"', $output );
		$this->assertStringContainsString( 'https://gist.github.com/abc123', $output );

	}

	/**
	 * Comments get the link form while `gist_in_comments` is off.
	 *
	 * @return void
	 */
	public function test_comments_get_a_link_while_the_option_is_off(): void {

		$output = $this->_filter( 'comment_text', '[github id="abc123"]' );

		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringContainsString( 'class="igsh-gist"', $output );

	}

	/**
	 * The Gist pipeline borrows the shortcode registry and gives it back.
	 *
	 * @return void
	 */
	public function test_the_shortcode_registry_is_left_as_it_was(): void {

		add_shortcode( 'ig_sh_test_tag', '__return_empty_string' );

		$before = $GLOBALS['shortcode_tags'];

		$this->_filter( 'the_content', '[github id="abc123"]' );

		$this->assertSame( $before, $GLOBALS['shortcode_tags'] );

		remove_shortcode( 'ig_sh_test_tag' );

	}

	/**
	 * A Gist reference inside a snippet is code, not a Gist.
	 *
	 * @return void
	 */
	public function test_a_gist_tag_inside_a_snippet_is_left_as_code(): void {

		Shortcode_Handler::get_instance()->register_hooks();

		$output = $this->_filter( 'the_content', '[php][github id="abc123"][/php]' );

		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringContainsString( '[github id=&quot;abc123&quot;]', $output );

	}

}    //end of class


//EOF
