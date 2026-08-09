<?php
/**
 * AC-8 — a page without snippets loads none of this plugin's assets.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Asset_Manager;
use iG\Syntax_Hiliter\Shortcode_Handler;
use WP_UnitTestCase;

/**
 * Asserts against the enqueue state left behind after the footer pass, for pages
 * which have no code on them.
 */
class Conditional_Assets_Test extends WP_UnitTestCase {

	use Asset_Test_Helpers;

	/**
	 * Registers the pipeline once WordPress is up.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		Shortcode_Handler::get_instance()->register_hooks();

		$this->_reset_asset_state();

	}

	/**
	 * Puts the asset state back for whatever runs next.
	 *
	 * @return void
	 */
	public function tear_down(): void {

		$this->_reset_asset_state();

		parent::tear_down();

	}

	/**
	 * Method to render a post the way a single post view renders it, then run the
	 * footer pass over what that left behind.
	 *
	 * @param string $content Post content.
	 *
	 * @return string Rendered markup.
	 */
	protected function _render_page( string $content ): string {

		$post_id = self::factory()->post->create(
			[
				'post_content' => wp_slash( $content ),
			]
		);

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		ob_start();
		the_content();
		$output = (string) ob_get_clean();

		$this->_fire_footer();

		return $output;

	}

	/**
	 * AC-8 — a post with no snippets enqueues nothing at all.
	 *
	 * @return void
	 */
	public function test_a_page_without_snippets_enqueues_nothing(): void {

		$output = $this->_render_page( "An ordinary post.\n\nWith an ordinary second paragraph." );

		$this->assertFalse( Asset_Manager::get_instance()->has_snippets() );
		$this->assertSame( [], $this->_plugin_asset_handles() );
		$this->assertStringNotContainsString( '<pre', $output );

	}

	/**
	 * AC-8 — nor does content which only looks like a snippet: a post talking about
	 * the shortcodes, or an escaped one, which is text.
	 *
	 * @return void
	 */
	public function test_content_which_only_looks_like_a_snippet_enqueues_nothing(): void {

		$this->_render_page( 'Wrap your code in [php] and the plugin will highlight it.' );

		$this->assertFalse( Asset_Manager::get_instance()->has_snippets() );
		$this->assertSame( [], $this->_plugin_asset_handles() );

		$output = $this->_render_page( '[[php]echo 1;[/php]]' );

		$this->assertStringContainsString( '[php]echo 1;[/php]', $output );
		$this->assertFalse( Asset_Manager::get_instance()->has_snippets() );
		$this->assertSame( [], $this->_plugin_asset_handles() );

	}

	/**
	 * AC-8 — an empty snippet renders nothing, so it needs nothing.
	 *
	 * @return void
	 */
	public function test_a_page_with_an_empty_snippet_enqueues_nothing(): void {

		$this->_render_page( 'before [php][/php] after' );

		$this->assertFalse( Asset_Manager::get_instance()->has_snippets() );
		$this->assertSame( [], $this->_plugin_asset_handles() );

	}

	/**
	 * AC-8 — the control: a page which does have a snippet loads the assets, so the
	 * tests above are measuring something.
	 *
	 * @return void
	 */
	public function test_a_page_with_a_snippet_does_enqueue(): void {

		$this->_render_page( "[php]\necho 1;\n[/php]" );

		$this->assertTrue( Asset_Manager::get_instance()->has_snippets() );

		$this->assertSame(
			[
				'script:ig-syntax-hiliter-autoloader',
				'script:ig-syntax-hiliter-copy-to-clipboard',
				'script:ig-syntax-hiliter-engine',
				'script:ig-syntax-hiliter-line-numbers',
				'script:ig-syntax-hiliter-setup',
				'script:ig-syntax-hiliter-toolbar',
				'style:ig-syntax-hiliter-chrome',
				'style:ig-syntax-hiliter-line-numbers',
				'style:ig-syntax-hiliter-theme',
				'style:ig-syntax-hiliter-toolbar',
			],
			$this->_plugin_asset_handles()
		);

	}

	/**
	 * AC-8 — a comment with a snippet on an otherwise code-free post counts too.
	 *
	 * @return void
	 */
	public function test_a_snippet_in_a_comment_enqueues_the_assets(): void {

		$post_id = self::factory()->post->create(
			[
				'post_content' => 'An ordinary post.',
			]
		);

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		ob_start();
		the_content();
		ob_get_clean();

		$this->assertFalse( Asset_Manager::get_instance()->has_snippets() );

		apply_filters( 'comment_text', '[php]echo 1;[/php]' );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Rendering a comment is what a comment does.

		$this->_fire_footer();

		$this->assertTrue( Asset_Manager::get_instance()->has_snippets() );
		$this->assertNotSame( [], $this->_plugin_asset_handles() );

	}

}    //end of class


//EOF
