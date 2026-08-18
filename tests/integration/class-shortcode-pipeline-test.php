<?php
/**
 * Tests for the protect-then-restore pipeline itself.
 *
 * What each legacy tag renders as belongs to `Backward_Compatibility_Test` and
 * `Legacy_Content_Test`; what is checked here is the mechanism which lets them do
 * it — where the hooks sit, and the fact that nothing between the two passes ever
 * gets a look at the code.
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

		Shortcode_Handler::get_instance()->register_hooks();

		$this->_captured = '';

	}

	/**
	 * A hostile filter of the kind that broke this in production: it strips script
	 * tags and turns bare URLs into links.
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
	 * @return void
	 */
	public function test_hooks_are_registered_at_the_bracketing_priorities(): void {

		$handler = Shortcode_Handler::get_instance();

		/*
		 * Named rather than read from the constants, because everything below reads
		 * from the constants — a filter dropped from one of these lists would
		 * otherwise take its own coverage with it.
		 */
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
	 * @return void
	 */
	public function test_a_tag_the_plugin_never_shipped_is_left_alone(): void {

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
	 * @return void
	 */
	public function test_an_empty_snippet_renders_nothing(): void {

		$output = $this->_filter( 'the_content', 'before[php][/php]after' );

		$this->assertStringNotContainsString( '<pre ', $output );
		$this->assertStringNotContainsString( '[php]', $output );
		$this->assertStringContainsString( 'before', $output );
		$this->assertStringContainsString( 'after', $output );

	}

	/**
	 * While the filter chain runs, the content holds no code.
	 *
	 * @return void
	 */
	public function test_the_filter_chain_never_sees_the_code(): void {

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
	 * `serialize_block_attributes()` escapes `<`, `>`, `&` and `--` in the JSON a
	 * delimiter carries, but neither `[` nor `]`. A matcher which does not know where
	 * delimiters are therefore rewrites inside one, and whatever it puts there is
	 * sitting in an HTML comment which `do_blocks()` has yet to read.
	 *
	 * @return void
	 */
	public function test_a_shortcode_inside_another_plugins_block_delimiter_is_left_alone(): void {

		$delimiter = '<!-- wp:acme/notice {"text":"Try [php]echo 1;[/php] today"} /-->';

		add_filter( 'the_content', [ $this, 'spy_filter' ], 5 );

		$this->_filter( 'the_content', $delimiter );

		remove_filter( 'the_content', [ $this, 'spy_filter' ], 5 );

		$this->assertSame( $delimiter, $this->_captured, 'The delimiter reached the block parser exactly as it was written.' );

	}

	/**
	 * The production bug this pipeline exists for: a script tag inside a code box,
	 * with a filter at priority 10 that strips scripts and autolinks URLs.
	 *
	 * @return void
	 */
	public function test_a_script_tag_survives_a_hostile_filter(): void {

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
	 * @return void
	 */
	public function test_a_code_box_is_not_wrapped_in_a_paragraph(): void {

		$output = $this->_filter( 'the_content', "Some text:\n[php]echo 1;[/php]\nMore text" );

		$this->assertStringNotContainsString( '<p><pre', $output );
		$this->assertStringNotContainsString( '<br />' . "\n" . '<pre', $output );
		$this->assertStringContainsString( '<pre ', $output );
		$this->assertStringContainsString( 'Some text:', $output );
		$this->assertStringContainsString( 'More text', $output );

	}

	/**
	 * The seam the block's render callback needs.
	 *
	 * `do_blocks()` runs at `the_content` priority 9, so anything a render callback
	 * emits is exposed to the filters at 10. A callback which asks whether a
	 * protected run is in flight, and stashes its markup when it is, gets the same
	 * immunity a shortcode gets.
	 *
	 * @return void
	 */
	public function test_a_render_callback_can_stash_its_markup(): void {

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

}    //end of class


//EOF
