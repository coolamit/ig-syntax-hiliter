<?php
/**
 * Tests for the `[github]` Gist pipeline.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Asset_Manager;
use iG\Syntax_Hiliter\Block;
use iG\Syntax_Hiliter\Gist_Embed;
use iG\Syntax_Hiliter\Option;
use iG\Syntax_Hiliter\Shortcode_Handler;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use ReflectionProperty;
use WP_UnitTestCase;

/**
 * Checks that Gist embeds behave exactly as they did before.
 */
class Gist_Embed_Test extends WP_UnitTestCase {

	use Pipeline_Test_Helpers;

	/**
	 * Registers the pipeline once WordPress is up.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		Gist_Embed::get_instance();

		// The singleton and the style registry both outlive a test; start from a request which has rendered nothing.
		( new ReflectionProperty( Gist_Embed::class, '_has_embeds' ) )->setValue( Gist_Embed::get_instance(), false );

		wp_dequeue_style( Gist_Embed::STYLE_HANDLE );

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
	 * Texturize runs on `the_content` at priority 10 and curls the quotes around a
	 * `gist="…"` URL before this pipeline can parse it, so priority 9 is pinned.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_registers_its_hooks_ahead_of_texturize(): void {

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
	 * @test
	 *
	 * @return void
	 */
	public function it_turns_an_id_into_an_embed(): void {

		$output = $this->_filter( 'the_content', '[github id="abc123"]' );

		$this->assertStringContainsString( $this->_expected_embed( 'abc123' ), $output );

	}

	/**
	 * A full Gist URL wins over the id, and only its last segment is used.
	 *
	 * Run through `the_content` rather than called directly, so that `wptexturize`
	 * gets its chance at the quoted URL.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_lets_a_url_win_over_an_id(): void {

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
	 * @test
	 *
	 * @return void
	 */
	public function it_renders_the_documented_forms_as_they_always_did(): void {

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
	 * A username sanitiser lets `. - _ @` and spaces through, so `..` can reach the
	 * path of a URL this plugin prints. Both paths are checked because the embed and
	 * the link are two different pieces of markup built from that one URL.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_never_lets_a_traversal_reach_the_gist_url(): void {

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
	 * @test
	 *
	 * @return void
	 */
	public function it_prints_nothing_for_an_id_that_cannot_be_a_gist_id(): void {

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
	 * A bare `[github]` with no attributes renders nothing and does not fatal.
	 *
	 * WordPress hands callbacks an array from 6.5 onwards, so the empty string is
	 * asserted against directly.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_renders_nothing_for_a_bare_github_tag_and_does_not_fatal(): void {

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
	 * @test
	 *
	 * @return void
	 */
	public function it_gives_an_excerpt_a_link_instead_of_a_script(): void {

		$output = $this->_filter( 'the_excerpt', '[github id="abc123"]' );

		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringContainsString( 'class="igsh-gist"', $output );
		$this->assertStringContainsString( 'https://gist.github.com/abc123', $output );

	}

	/**
	 * Comments get the link form while `gist_in_comments` is off.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_gives_comments_a_link_while_the_option_is_off(): void {

		$output = $this->_filter( 'comment_text', '[github id="abc123"]' );

		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringContainsString( 'class="igsh-gist"', $output );

	}

	/**
	 * The Gist pipeline borrows the shortcode registry and gives it back.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_leaves_the_shortcode_registry_as_it_was(): void {

		add_shortcode( 'ig_sh_test_tag', '__return_empty_string' );

		$before = $GLOBALS['shortcode_tags'];

		$this->_filter( 'the_content', '[github id="abc123"]' );

		$this->assertSame( $before, $GLOBALS['shortcode_tags'] );

		remove_shortcode( 'ig_sh_test_tag' );

	}

	/**
	 * Content with no `[github` in it is handed straight back.
	 *
	 * The guard keeps the parse, which borrows the whole shortcode registry, off every
	 * post with no Gist on it. `&#91;` is the case that comes back changed without it:
	 * `do_shortcodes_in_html_tags()` decodes brackets inside a tag and
	 * `unescape_invalid_shortcodes()` does not put them back. The method is called
	 * directly because the claim is that this method changes nothing.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_returns_content_without_the_tag_untouched(): void {

		$embed = Gist_Embed::get_instance();

		$samples = [
			'A footnote marker [1] and nothing else.',
			'<a href="/x" title="&#91;see this&#93;">a link</a>',
			'[gallery ids="1,2,3"]',
			'[gist] is not this plugin\'s tag.',
		];

		foreach ( $samples as $content ) {

			$this->assertSame(
				$content,
				$embed->parse( $content ),
				'Content with no [github in it is returned byte for byte.'
			);

		}

		// And the guard is not simply refusing everything.
		$this->assertStringContainsString(
			$this->_expected_embed( 'abc123' ),
			$embed->parse( 'before [github id="abc123"] after' )
		);

	}

	/**
	 * The stylesheet which boxes an embed loads only where there is one to box.
	 *
	 * A page carrying nothing but a Gist loads no stylesheet of this plugin's
	 * otherwise, and a page with no embed must not pay for this one.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_loads_the_gist_stylesheet_only_where_a_gist_was_embedded(): void {

		$gist = Gist_Embed::get_instance();

		$this->assertSame( Asset_Manager::PRIORITY_DECIDE, has_action( 'wp_footer', [ $gist, 'enqueue' ] ) );

		$gist->enqueue();

		$this->assertFalse( wp_style_is( Gist_Embed::STYLE_HANDLE, 'enqueued' ), 'Nothing has been embedded yet.' );

		$this->_filter( 'the_content', '[github id="abc123"]' );

		$gist->enqueue();

		$this->assertTrue( wp_style_is( Gist_Embed::STYLE_HANDLE, 'enqueued' ) );

	}

	/**
	 * A link is not an embed, so it needs no stylesheet.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_loads_no_stylesheet_for_a_gist_rendered_as_a_link(): void {

		$gist = Gist_Embed::get_instance();

		$this->_filter( 'the_excerpt', '[github id="abc123"]' );

		$gist->enqueue();

		$this->assertFalse( wp_style_is( Gist_Embed::STYLE_HANDLE, 'enqueued' ) );

	}

	/**
	 * With the setting off, the embed is unchanged and nothing is loaded for it.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_does_not_load_the_stylesheet_while_the_setting_is_off(): void {

		$option   = Option::get_instance();
		$property = new ReflectionProperty( Option::class, '_options' );
		$before   = $property->getValue( $option );

		$property->setValue( $option, array_merge( (array) $before, [ 'gist_limit_height' => 'no' ] ) );

		try {

			$this->_filter( 'the_content', '[github id="abc123"]' );

			Gist_Embed::get_instance()->enqueue();

		} finally {
			$property->setValue( $option, $before );
		}

		$this->assertFalse( wp_style_is( Gist_Embed::STYLE_HANDLE, 'enqueued' ) );

	}

	/**
	 * The Gist block renders through this same pipeline.
	 *
	 * There is one embed implementation, not two, so the block and the shortcode agree
	 * on the id sanitising, the link form and what a comment may carry.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_renders_the_gist_block_through_this_pipeline(): void {

		$block = Block::get_instance();

		$this->assertSame(
			$this->_expected_embed( 'abc123' ),
			$block->render_gist( [ 'url' => 'https://gist.github.com/someone/abc123' ] )
		);

		// The same refusals the shortcode makes.
		$this->assertSame( '', $block->render_gist( [ 'url' => '' ] ) );
		$this->assertSame( '', $block->render_gist( [] ) );
		$this->assertSame( '', $block->render_gist( [ 'url' => 'https://gist.github.com/someone/..' ] ) );

	}

	/**
	 * A Gist reference inside a snippet is code, not a Gist.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_leaves_a_gist_tag_inside_a_snippet_as_code(): void {

		Shortcode_Handler::get_instance();

		$output = $this->_filter( 'the_content', '[php][github id="abc123"][/php]' );

		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringContainsString( '[github id=&quot;abc123&quot;]', $output );

	}

} // end of class

// EOF
