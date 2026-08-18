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

		/*
		 * The class is a singleton and the style registry is a global, so both
		 * outlive a test. Every test here starts from the state a request which has
		 * rendered nothing starts in.
		 */
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
	 * Content with no `[github` in it is handed straight back.
	 *
	 * This runs on `the_content` and three excerpt filters for every post on every
	 * request, and it borrows the whole shortcode registry to do its work. The guard
	 * is what keeps it from doing any of that on the overwhelming majority of posts,
	 * which have no Gist on them — and a bare `[` is not enough to tell, because most
	 * real writing has one somewhere.
	 *
	 * Every string below contains a `[`, so each one would have gone the long way
	 * round before. `&#91;` is the case that used to come back changed rather than
	 * merely come back slowly: core's `do_shortcodes_in_html_tags()` decodes the
	 * brackets inside an HTML tag and `unescape_invalid_shortcodes()` does not put
	 * them back.
	 *
	 * The method is called directly rather than through `the_content`, because the
	 * claim is that this method changes nothing — and the rest of the chain, texturize
	 * and `wpautop` included, changes plenty.
	 *
	 * @return void
	 */
	public function test_content_without_the_tag_comes_back_untouched(): void {

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

		//and the guard is not simply refusing everything
		$this->assertStringContainsString(
			$this->_expected_embed( 'abc123' ),
			$embed->parse( 'before [github id="abc123"] after' )
		);

	}

	/**
	 * The stylesheet which boxes an embed loads only where there is one to box.
	 *
	 * A page carrying nothing but a Gist loads no stylesheet of this plugin's
	 * otherwise, so this is the only thing that puts one on it — and a page with no
	 * embed must not pay for it.
	 *
	 * @return void
	 */
	public function test_the_gist_stylesheet_loads_only_where_a_gist_was_embedded(): void {

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
	 * @return void
	 */
	public function test_a_gist_rendered_as_a_link_loads_no_stylesheet(): void {

		$gist = Gist_Embed::get_instance();

		$this->_filter( 'the_excerpt', '[github id="abc123"]' );

		$gist->enqueue();

		$this->assertFalse( wp_style_is( Gist_Embed::STYLE_HANDLE, 'enqueued' ) );

	}

	/**
	 * With the setting off, the embed is unchanged and nothing is loaded for it.
	 *
	 * @return void
	 */
	public function test_the_stylesheet_is_not_loaded_while_the_setting_is_off(): void {

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
	 * There is one embed implementation, not two, which is what keeps the block and
	 * the twenty year old shortcode agreeing on the id sanitising, on the link form
	 * and on what a comment may carry.
	 *
	 * @return void
	 */
	public function test_the_gist_block_renders_through_this_pipeline(): void {

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
	 * @return void
	 */
	public function test_a_gist_tag_inside_a_snippet_is_left_as_code(): void {

		Shortcode_Handler::get_instance();

		$output = $this->_filter( 'the_content', '[php][github id="abc123"][/php]' );

		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringContainsString( '[github id=&quot;abc123&quot;]', $output );

	}

}    //end of class


//EOF
