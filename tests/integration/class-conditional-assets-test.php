<?php
/**
 * A page without snippets loads none of this plugin's assets.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Asset_Manager;
use iG\Syntax_Hiliter\Option;
use iG\Syntax_Hiliter\Shortcode_Handler;
use ReflectionProperty;
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
	 * A post with no snippets enqueues nothing at all.
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
	 * Content which only looks like a snippet enqueues nothing either: a post
	 * talking about the shortcodes, or an escaped one, which is text.
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
	 * An empty snippet renders nothing, so it needs nothing.
	 *
	 * @return void
	 */
	public function test_a_page_with_an_empty_snippet_enqueues_nothing(): void {

		$this->_render_page( 'before [php][/php] after' );

		$this->assertFalse( Asset_Manager::get_instance()->has_snippets() );
		$this->assertSame( [], $this->_plugin_asset_handles() );

	}

	/**
	 * The control: a page which does have a snippet loads the assets, so the tests
	 * above are measuring something.
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
				'script:ig-syntax-hiliter-show-language',
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
	 * A theme from the second theme directory is enqueued from that directory.
	 *
	 * The plugin ships themes from two places — Prism's own dist themes and the
	 * separate PrismJS/prism-themes collection — and only the stylesheet's URL says
	 * which of the two a theme was resolved out of. A page rendering a snippet is
	 * where that resolution is used, so it is where it is measured.
	 *
	 * @return void
	 */
	public function test_a_theme_from_the_collection_is_enqueued_from_its_own_directory(): void {

		$option = Option::get_instance();

		$option->save( 'theme', 'prism-nord' );

		try {

			$this->_render_page( "[php]\necho 1;\n[/php]" );

			$style = wp_styles()->registered['ig-syntax-hiliter-theme'] ?? null;

			$this->assertNotNull( $style, 'The theme stylesheet is registered.' );

			$this->assertStringEndsWith(
				'/assets/lib/prism-themes/prism-nord.min.css',
				(string) $style->src
			);

		} finally {
			/*
			 * The options object reads the stored array once and holds it for the rest
			 * of the request. The database is rolled back after this test, so the object
			 * has to go with it or the next test reads a theme nobody saved.
			 */
			( new ReflectionProperty( Option::class, '_instance' ) )->setValue( null, null );

		}

	}

	/**
	 * Method to find the `wp_footer` priority at which core prints the footer.
	 *
	 * Read off the hook before anything is taken off it, so that "the last moment an
	 * enqueue still reaches the page" stays whatever core says it is rather than a
	 * number written down here.
	 *
	 * @return int
	 */
	protected function _core_footer_print_priority(): int {

		$found = null;

		foreach ( (array) ( $GLOBALS['wp_filter']['wp_footer']->callbacks ?? [] ) as $priority => $callbacks ) {

			if ( ! isset( $callbacks['wp_print_footer_scripts'] ) ) {
				continue;
			}

			$found = (int) $priority;

			break;

		}

		$this->assertNotNull( $found, 'Core prints the footer assets from wp_footer.' );

		return (int) $found;

	}

	/**
	 * Method to print the scripts and styles this plugin has enqueued, and only those.
	 *
	 * Core's own `wp_print_footer_scripts()` walks the whole queue, and the queue is
	 * not this plugin's. The install these tests run against brings its own enqueues
	 * — on this machine a mu-plugin queues a handle at `init` whose dependency is
	 * never registered on the front end — and `WP_Dependencies::all_deps()` raises a
	 * `_doing_it_wrong()` over each one as it walks past. The test library turns that
	 * into a failure of whichever test happened to print first, so a test which
	 * printed the whole queue would pass or fail on what else is installed.
	 *
	 * Printing this plugin's handles alone still exercises the real printer: the
	 * dependencies walked are the plugin's own, and a handle which was not enqueued
	 * by the time this runs prints nothing.
	 *
	 * @return void
	 */
	protected function _print_plugin_assets(): void {

		foreach ( [ wp_styles(), wp_scripts() ] as $dependencies ) {

			$handles = array_values(
				array_filter(
					$dependencies->queue,
					static function ( $handle ): bool {
						return str_starts_with( (string) $handle, Asset_Manager::HANDLE_PREFIX );
					}
				)
			);

			if ( empty( $handles ) ) {
				continue;
			}

			/*
			 * Printing marks a handle done on a registry which outlives the test, and a
			 * handle already marked done is skipped. `_reset_asset_state()` takes this
			 * plugin's handles back out of the registry between tests; this takes them
			 * out of the record of what has already been printed, so that a second test
			 * printing the same handles is not silently handed an empty footer.
			 */
			$dependencies->done = array_values( array_diff( $dependencies->done, $handles ) );

			$dependencies->do_items( $handles );

		}

	}

	/**
	 * Method to run the footer, with something rendering content part way through it.
	 *
	 * `_fire_footer()` restores every pass the manager sits at but prints nothing,
	 * because for its callers the enqueue state is the whole answer. These tests need
	 * the page as well: an enqueue which happens after the footer has been printed
	 * reaches nobody, so something has to print at the moment core prints, and the
	 * assertions have to be able to tell "enqueued" from "enqueued in time".
	 *
	 * @param callable $render Callback which renders content from the footer.
	 *
	 * @return string Everything the footer printed.
	 */
	protected function _fire_footer_around( callable $render ): string {

		$manager    = Asset_Manager::get_instance();
		$priorities = $this->_manager_footer_priorities();
		$print_at   = $this->_core_footer_print_priority();

		$this->assertNotEmpty( $priorities, 'The asset manager decides during wp_footer.' );

		remove_all_actions( 'wp_footer' );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook the plugin registers on.

		foreach ( $priorities as $priority ) {
			add_action( 'wp_footer', [ $manager, 'enqueue' ], $priority );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook the plugin registers on.
		}

		add_action( 'wp_footer', $render, 10 );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook the plugin registers on.

		add_action(  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook the plugin registers on.
			'wp_footer',
			function (): void {
				$this->_print_plugin_assets();
			},
			$print_at
		);

		ob_start();

		do_action( 'wp_footer' );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook the plugin registers on.

		return (string) ob_get_clean();

	}

	/**
	 * A snippet which only appears once the footer has started still gets its assets.
	 *
	 * Plenty of things render content from `wp_footer` — a modal, a late list of
	 * related posts, a comment list built on demand. A code box which arrives that
	 * way used to land on the page after the one and only decision had been taken,
	 * with no stylesheet, no theme and no highlighter, and nothing left on the page
	 * able to put that right.
	 *
	 * @return void
	 */
	public function test_a_snippet_rendered_late_in_the_footer_still_gets_its_assets(): void {

		$post_id = self::factory()->post->create(
			[
				'post_content' => 'An ordinary post with no code on it.',
			]
		);

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		ob_start();
		the_content();
		ob_get_clean();

		$this->assertFalse( Asset_Manager::get_instance()->has_snippets(), 'The post itself has no code on it.' );

		$footer = $this->_fire_footer_around(
			static function (): void {
				echo (string) apply_filters( 'the_content', "[php]\necho 1;\n[/php]" );  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Rendering content is what the filter is for.
			}
		);

		$this->assertStringContainsString( '<pre', $footer, 'The late render did put a code box on the page.' );
		$this->assertTrue( Asset_Manager::get_instance()->has_snippets() );

		$handles = $this->_plugin_asset_handles();

		$this->assertContains( 'script:ig-syntax-hiliter-engine', $handles );
		$this->assertContains( 'script:ig-syntax-hiliter-setup', $handles );
		$this->assertContains( 'style:ig-syntax-hiliter-chrome', $handles );

		// Enqueued is only half of it; the decision has to be early enough to print.
		$this->assertStringContainsString( 'prism-core.min.js', $footer );
		$this->assertStringContainsString( 'ig-prism-setup.js', $footer );
		$this->assertStringContainsString( 'frontend-chrome.css', $footer );

		// The setup script is configured once, however many passes there were.
		$this->assertSame( 1, substr_count( $footer, 'var igSyntaxHiliter' ) );

	}

	/**
	 * Deciding a second time does not lower the bar: a page with no code on it,
	 * footer and all, still loads nothing.
	 *
	 * @return void
	 */
	public function test_a_second_decision_still_needs_a_snippet_to_have_rendered(): void {

		$footer = $this->_fire_footer_around(
			static function (): void {
				echo (string) apply_filters( 'the_content', 'Just words, and a mention of [php] which is not one.' );  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Rendering content is what the filter is for.
			}
		);

		$this->assertFalse( Asset_Manager::get_instance()->has_snippets() );
		$this->assertSame( [], $this->_plugin_asset_handles() );
		$this->assertStringNotContainsString( 'prism', $footer );

	}

	/**
	 * A comment with a snippet on an otherwise code-free post counts too.
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
