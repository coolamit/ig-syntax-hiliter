<?php
/**
 * Tests for the protect-then-restore pipeline itself: where the hooks sit, and
 * that nothing between the two passes ever gets a look at the code.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Content_Protector;
use iG\Syntax_Hiliter\Legacy_Map;
use iG\Syntax_Hiliter\Shortcode_Handler;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use WP_UnitTestCase;

/**
 * The pipeline, and the immunity it exists to provide.
 */
class Shortcode_Pipeline_Test extends WP_UnitTestCase {

	use Pipeline_Test_Helpers;

	/**
	 * Content captured mid chain by the spy filter.
	 *
	 * @var string
	 */
	protected string $_captured = '';

	/**
	 * Registers the pipeline once WordPress is up.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		Shortcode_Handler::get_instance();

		$this->_captured = '';

	}

	/**
	 * A hostile filter: it strips script tags and turns bare URLs into links.
	 *
	 * @param string $content Content being filtered.
	 *
	 * @return string
	 */
	public function hostile_filter( $content ) {

		$content = (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $content );

		return (string) preg_replace( '#(https?://[^\s<]+)#i', '<a href="$1">$1</a>', $content );

	}

	/**
	 * Records what the filter chain is carrying at the point it is hooked.
	 *
	 * @param string $content Content being filtered.
	 *
	 * @return string
	 */
	public function spy_filter( $content ) {

		$this->_captured = (string) $content;

		return $content;

	}

	/**
	 * The plugin's hooks sit where they are supposed to sit, on both the display
	 * filters and the save filters.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_registers_its_hooks_at_the_bracketing_priorities(): void {

		$handler = Shortcode_Handler::get_instance();

		// Named rather than read from the constants, so a filter dropped from a list does not take its own coverage with it.
		$this->assertSame(
			[ 'content_save_pre', 'content_filtered_save_pre' ],
			Shortcode_Handler::SAVE_FILTERS
		);

		$this->assertSame(
			[ 'get_the_excerpt', 'the_excerpt', 'the_excerpt_rss' ],
			Shortcode_Handler::EXCERPT_FILTERS
		);

		// A filter which writes to the database has no business on a strip list.
		$this->assertNotContains( 'excerpt_save_pre', Shortcode_Handler::EXCERPT_FILTERS );
		$this->assertFalse( has_filter( 'excerpt_save_pre', [ $handler, 'strip' ] ) );

		$this->assertSame( 1, has_filter( 'the_content', [ $handler, 'protect_display' ] ) );
		$this->assertSame( 100, has_filter( 'the_content', [ $handler, 'restore_display' ] ) );

		$this->assertSame( 1, has_filter( 'comment_text', [ $handler, 'protect_display' ] ) );
		$this->assertSame( 100, has_filter( 'comment_text', [ $handler, 'restore_display' ] ) );

		foreach ( Shortcode_Handler::SAVE_FILTERS as $filter ) {
			$this->assertSame( 1, has_filter( $filter, [ $handler, 'protect_save' ] ), $filter );
			$this->assertSame( 100, has_filter( $filter, [ $handler, 'restore_save' ] ), $filter );
		}

		foreach ( Shortcode_Handler::EXCERPT_FILTERS as $filter ) {
			$this->assertSame( 2, has_filter( $filter, [ $handler, 'strip' ] ), $filter );
		}

		// KSES has to sit between the two save passes for any of this to be worth doing.
		$this->assertSame( 10, has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );

	}

	/**
	 * A tag the plugin never shipped is not the plugin's. It is neither registered
	 * nor rendered nor stripped. `[sourcecode]` always is, whatever language it
	 * names, and an unresolvable one degrades.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_leaves_a_tag_the_plugin_never_shipped_alone(): void {

		$content = '[email]someone@example.com[/email]';

		$this->assertStringContainsString( $content, $this->_filter( 'the_content', $content ) );
		$this->assertStringContainsString( $content, $this->_filter( 'the_excerpt', $content ) );
		$this->assertSame( wp_slash( $content ), $this->_filter( 'content_save_pre', wp_slash( $content ) ) );

		$this->assertNotContains( 'email', Legacy_Map::get_tags() );
		$this->assertArrayNotHasKey( 'email', $GLOBALS['shortcode_tags'] );

		$generic = $this->_filter( 'the_content', '[sourcecode language="email"]a@b.com[/sourcecode]' );

		$this->assertStringContainsString( '<code class="language-none">', $generic );
		$this->assertStringNotContainsString( 'language-email', $generic );

	}

	/**
	 * A shortcode with nothing in it renders nothing, exactly as it did before.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_renders_nothing_for_an_empty_snippet(): void {

		$output = $this->_filter( 'the_content', 'before[php][/php]after' );

		$this->assertStringNotContainsString( '<pre ', $output );

		// Container included: a wrapper around no box is still a box on the page.
		$this->assertStringNotContainsString( 'igsh-code-box', $output );

		$this->assertStringNotContainsString( '[php]', $output );
		$this->assertStringContainsString( 'before', $output );
		$this->assertStringContainsString( 'after', $output );

	}

	/**
	 * While the filter chain runs, the content holds no code.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_never_shows_the_code_to_the_filter_chain(): void {

		add_filter( 'the_content', [ $this, 'spy_filter' ], 50 );

		$this->_filter( 'the_content', "[php]\n\$secret = 'nothing should see this';\n[/php]" );

		remove_filter( 'the_content', [ $this, 'spy_filter' ], 50 );

		$this->assertStringContainsString( Content_Protector::PLACEHOLDER_PREFIX, $this->_captured );
		$this->assertStringNotContainsString( 'nothing should see this', $this->_captured );
		$this->assertStringNotContainsString( '[php]', $this->_captured );

	}

	/**
	 * A shortcode written inside another plugin's block attributes is that plugin's
	 * text, not a snippet.
	 *
	 * `serialize_block_attributes()` escapes `<`, `>`, `&` and `--` but neither bracket,
	 * so a matcher blind to delimiters would rewrite inside one.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_leaves_a_shortcode_inside_another_plugins_block_delimiter_alone(): void {

		$delimiter = '<!-- wp:acme/notice {"text":"Try [php]echo 1;[/php] today"} /-->';

		add_filter( 'the_content', [ $this, 'spy_filter' ], 5 );

		$this->_filter( 'the_content', $delimiter );

		remove_filter( 'the_content', [ $this, 'spy_filter' ], 5 );

		$this->assertSame( $delimiter, $this->_captured, 'The delimiter reached the block parser exactly as it was written.' );

	}

	/**
	 * A script tag inside a code box survives a filter at priority 10 that strips
	 * scripts and autolinks URLs.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_keeps_a_script_tag_through_a_hostile_filter(): void {

		$code = '<script src="https://example.com/thing.js"></script>';  // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Test fixture standing in for author written code, not markup this plugin emits.

		add_filter( 'the_content', [ $this, 'hostile_filter' ], 10 );

		$output = $this->_filter( 'the_content', sprintf( "[php]\n%s\n[/php]", $code ) );

		remove_filter( 'the_content', [ $this, 'hostile_filter' ], 10 );

		$this->assertStringContainsString( esc_html( $code ), $output );
		$this->assertStringNotContainsString( '<a href="https://example.com/thing.js"', $output );

		// Texturize would have curled the quotes had it been given the chance.
		$this->assertStringNotContainsString( '&#8220;', $output );
		$this->assertStringNotContainsString( '&#8221;', $output );

	}

	/**
	 * A code box never ends up inside a paragraph, which is what would happen if the
	 * placeholder were not block level by the time `wpautop` reached it.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_does_not_wrap_a_code_box_in_a_paragraph(): void {

		$output = $this->_filter( 'the_content', "Some text:\n[php]echo 1;[/php]\nMore text" );

		// The container is the top level element, so it is the one `wpautop` could wrap; checking the `pre` alone would miss it.
		$this->assertStringNotContainsString( '<p><div class="igsh-code-box"', $output );
		$this->assertStringNotContainsString( '<br />' . "\n" . '<div class="igsh-code-box"', $output );

		$this->assertStringNotContainsString( '<p><pre', $output );
		$this->assertStringNotContainsString( '<br />' . "\n" . '<pre', $output );
		$this->assertStringContainsString( '<pre ', $output );
		$this->assertStringContainsString( 'Some text:', $output );
		$this->assertStringContainsString( 'More text', $output );

	}

	/**
	 * The seam the block's render callback needs: `do_blocks()` runs at priority 9,
	 * so a callback which stashes its markup during a protected run gets the same
	 * immunity a shortcode gets.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_lets_a_render_callback_stash_its_markup(): void {

		$protector = Content_Protector::get_instance();
		$markup    = '<pre id="from-a-render-callback"><code>fetch( "https://example.com/x.js" );</code></pre>';

		$this->assertFalse( $protector->is_protecting(), 'Nothing is in flight before a filter runs.' );

		$callback = static function ( $content ) use ( $protector, $markup ) {

			if ( ! $protector->is_protecting() ) {
				return $content;
			}

			return $content . $protector->stash_markup( $markup );

		};

		add_filter( 'the_content', $callback, 9 );
		add_filter( 'the_content', [ $this, 'hostile_filter' ], 10 );

		$output = $this->_filter( 'the_content', 'Text.' );

		remove_filter( 'the_content', $callback, 9 );
		remove_filter( 'the_content', [ $this, 'hostile_filter' ], 10 );

		$this->assertStringContainsString( $markup, $output );
		$this->assertStringNotContainsString( '<a href="https://example.com/x.js"', $output );
		$this->assertFalse( $protector->is_protecting(), 'The run is over once the content has been restored.' );

	}

} // end of class

// EOF
