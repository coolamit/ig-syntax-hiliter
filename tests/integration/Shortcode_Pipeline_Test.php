<?php
/**
 * Tests for the legacy shortcode display pipeline.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Asset_Manager;
use iG\Syntax_Hiliter\Content_Protector;
use iG\Syntax_Hiliter\Legacy_Map;
use iG\Syntax_Hiliter\Shortcode_Handler;
use WP_UnitTestCase;

/**
 * Checks that legacy shortcodes render, and that nothing between the two passes
 * ever gets a look at the code.
 */
class Shortcode_Pipeline_Test extends WP_UnitTestCase {

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
	 * Method to run content through one of WordPress' own filters.
	 *
	 * Every test here goes through this one place, so the sniff which objects to a
	 * plugin invoking a core hook name is answered once instead of on every line.
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
	 * The plugin's hooks sit where they are supposed to sit.
	 *
	 * @return void
	 */
	public function test_hooks_are_registered_at_the_bracketing_priorities(): void {

		$handler = Shortcode_Handler::get_instance();

		$this->assertSame( 1, has_filter( 'the_content', [ $handler, 'protect_display' ] ) );
		$this->assertSame( 100, has_filter( 'the_content', [ $handler, 'restore_display' ] ) );

		$this->assertSame( 1, has_filter( 'comment_text', [ $handler, 'protect_display' ] ) );
		$this->assertSame( 100, has_filter( 'comment_text', [ $handler, 'restore_display' ] ) );

		$this->assertSame( 1, has_filter( 'content_save_pre', [ $handler, 'protect_save' ] ) );
		$this->assertSame( 100, has_filter( 'content_save_pre', [ $handler, 'restore_save' ] ) );

		foreach ( Shortcode_Handler::EXCERPT_FILTERS as $filter ) {
			$this->assertSame( 2, has_filter( $filter, [ $handler, 'strip' ] ), $filter );
		}

		// KSES has to sit between the two save passes for any of this to be worth doing.
		$this->assertSame( 10, has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );

	}

	/**
	 * A language named tag renders as a code box in that language.
	 *
	 * @return void
	 */
	public function test_a_named_language_tag_renders(): void {

		$output = $this->_filter( 'the_content', "[php]\n\$a = 1;\n[/php]" );

		$this->assertStringContainsString( '<pre ', $output );
		$this->assertStringContainsString( 'class="language-php line-numbers"', $output );
		$this->assertStringContainsString( '<code class="language-php">', $output );
		$this->assertStringContainsString( '$a = 1;', $output );

	}

	/**
	 * A shorthand alias resolves to the language it is short for.
	 *
	 * @return void
	 */
	public function test_a_shorthand_alias_renders(): void {

		$output = $this->_filter( 'the_content', '[js]var a = 1;[/js]' );

		$this->assertStringContainsString( '<code class="language-javascript">', $output );

	}

	/**
	 * The generic tag takes its language from an attribute, and understands the
	 * whole legacy attribute grammar.
	 *
	 * @return void
	 */
	public function test_the_generic_tag_renders_with_its_attributes(): void {

		$output = $this->_filter(
			'the_content',
			"[sourcecode language=\"css\" firstline=\"5\" highlight=\"2,4-6\" file=\"style.css\" gutter=\"no\"]\na {}\n[/sourcecode]"
		);

		$this->assertStringContainsString( 'class="language-css"', $output );
		$this->assertStringNotContainsString( 'line-numbers', $output );
		$this->assertStringContainsString( 'data-start="5"', $output );
		$this->assertStringContainsString( 'data-line="2,4-6"', $output );
		$this->assertStringContainsString( 'data-file="style.css"', $output );

	}

	/**
	 * The legacy spellings of the attribute names still work.
	 *
	 * @return void
	 */
	public function test_the_legacy_attribute_spellings_still_work(): void {

		$output = $this->_filter( 'the_content', '[sourcecode lang="ruby" num="7"]puts 1[/sourcecode]' );

		$this->assertStringContainsString( 'class="language-ruby', $output );
		$this->assertStringContainsString( 'data-start="7"', $output );

	}

	/**
	 * A language nothing can resolve degrades to a plain box rather than asking the
	 * browser for a language file that is not there.
	 *
	 * @return void
	 */
	public function test_an_unresolvable_language_degrades_to_no_language(): void {

		$output = $this->_filter( 'the_content', '[sourcecode language="madeuplang"]xyz[/sourcecode]' );

		$this->assertStringContainsString( '<code class="language-none">', $output );
		$this->assertStringNotContainsString( 'language-madeuplang', $output );

	}

	/**
	 * A tag the plugin never shipped is not the plugin's. It is neither registered
	 * nor rendered nor stripped.
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

	}

	/**
	 * An escaped shortcode is not a snippet, and is left as the author wrote it.
	 *
	 * @return void
	 */
	public function test_an_escaped_shortcode_is_left_alone(): void {

		$content = '[[php]echo 1;[/php]]';
		$output  = $this->_filter( 'the_content', $content );

		$this->assertStringContainsString( $content, $output );
		$this->assertStringNotContainsString( '<pre ', $output );

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
	 * The whole point: while the filter chain runs, the content holds no code.
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
	 * Entities, PHP tags and mixed markup come out the far end exactly as written.
	 *
	 * @return void
	 */
	public function test_entities_and_php_tags_are_not_mangled(): void {

		$code = "<?php echo '<div>' . \$a . '</div>'; ?>\n// &amp; &lt; -- \"quoted\" 'single'";

		$output = $this->_filter( 'the_content', sprintf( '[php]%s[/php]', $code ) );

		$this->assertStringContainsString( esc_html( $code ), $output );

	}

	/**
	 * A code box never ends up inside a paragraph.
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
	 * Two identical snippets on a page get two different DOM ids.
	 *
	 * @return void
	 */
	public function test_repeated_snippets_get_distinct_ids(): void {

		$output = $this->_filter( 'the_content', "[php]echo 1;[/php]\n\n[php]echo 1;[/php]" );

		preg_match_all( '/id="(ig-sh-\d+)"/', $output, $matches );

		$this->assertCount( 2, $matches[1] );
		$this->assertSame( $matches[1], array_unique( $matches[1] ) );

	}

	/**
	 * Comments are not block content and never will be, so the shortcode pipeline
	 * is the only path they have.
	 *
	 * @return void
	 */
	public function test_comments_are_highlighted(): void {

		$output = $this->_filter( 'comment_text', '[php]echo 1;[/php]' );

		$this->assertStringContainsString( '<code class="language-php">', $output );
		$this->assertStringContainsString( 'echo 1;', $output );

	}

	/**
	 * Excerpts strip snippets rather than rendering them.
	 *
	 * @return void
	 */
	public function test_excerpts_strip_snippets(): void {

		foreach ( [ 'the_excerpt', 'the_excerpt_rss', 'get_the_excerpt' ] as $filter ) {

			$output = $this->_filter( $filter, 'before [php]echo 1;[/php] after' );

			$this->assertStringNotContainsString( '<pre ', $output, $filter );
			$this->assertStringNotContainsString( '[php]', $output, $filter );
			$this->assertStringNotContainsString( 'echo 1;', $output, $filter );
			$this->assertStringContainsString( 'before', $output, $filter );
			$this->assertStringContainsString( 'after', $output, $filter );

		}

	}

	/**
	 * A feed goes through `the_content` first, so a snippet renders there too.
	 *
	 * @return void
	 */
	public function test_feeds_render_snippets(): void {

		$code = '<script src="https://example.com/thing.js"></script>';  // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Test fixture standing in for author written code, not markup this plugin emits.

		$post_id = self::factory()->post->create(
			[
				'post_content' => sprintf( "[php]\n%s\n[/php]", $code ),
			]
		);

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$output = get_the_content_feed( 'rss2' );

		$this->assertStringContainsString( '<code class="language-php">', $output );
		$this->assertStringContainsString( esc_html( $code ), $output );

	}

	/**
	 * The seam M3's block render callback needs.
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

	/**
	 * The filtered content field gets the same save time treatment.
	 *
	 * @return void
	 */
	public function test_the_filtered_content_field_is_protected_too(): void {

		$handler = Shortcode_Handler::get_instance();

		$this->assertSame( 1, has_filter( 'content_filtered_save_pre', [ $handler, 'protect_save' ] ) );
		$this->assertSame( 100, has_filter( 'content_filtered_save_pre', [ $handler, 'restore_save' ] ) );

		$slashed = wp_slash( "[php]\n<script src=\"https://example.com/a.js\"></script>\n[/php]" );  // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Test fixture standing in for author written code, not markup this plugin emits.

		$this->assertSame( $slashed, $this->_filter( 'content_filtered_save_pre', $slashed ) );

	}

	/**
	 * Rendering a snippet is what tells the asset manager there is something on the
	 * page worth loading assets for.
	 *
	 * @return void
	 */
	public function test_rendering_signals_the_asset_manager(): void {

		$this->_filter( 'the_content', '[php]echo 1;[/php]' );

		$this->assertTrue( Asset_Manager::get_instance()->has_snippets() );
		$this->assertContains( 'php', Asset_Manager::get_instance()->get_languages() );

	}

}    //end of class


//EOF
