<?php
/**
 * Tests for what the asset manager registers and enqueues, and when.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Asset_Manager;
use iG\Syntax_Hiliter\Block;
use iG\Syntax_Hiliter\Fonts;
use iG\Syntax_Hiliter\Helper;
use iG\Syntax_Hiliter\Option;
use iG\Syntax_Hiliter\Renderer;
use iG\Syntax_Hiliter\Shortcode_Handler;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Asset_Test_Helpers;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Hook_Test_Helpers;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

/**
 * The hooks the asset manager registers, and the enqueue state it leaves behind
 * after the footer pass: nothing for a page without code, and exactly what a page
 * with code needs, fonts included.
 */
class Asset_Manager_Test extends WP_UnitTestCase {

	use Pipeline_Test_Helpers;

	use Asset_Test_Helpers;

	use Hook_Test_Helpers;

	/**
	 * Host every webfont is fetched from, and the only host this plugin may reach.
	 *
	 * @var string
	 */
	protected const string _FONT_HOST = 'fonts.bunny.net';

	/**
	 * Code carrying URLs of the kinds an autolinker looks for.
	 *
	 * @var string
	 */
	protected const string _CODE_WITH_URLS = "\$api = 'https://example.com/v1/thing?a=1&b=2';\n// see http://example.org/docs\nwww.example.net/plain\nsomeone@example.com";

	/**
	 * Registers the pipeline, and the block, once WordPress is up.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		Shortcode_Handler::get_instance();

		// Under test is the render callback, not whether the plugin's `init` callback has run yet.
		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( Block::NAME ) ) {
			Block::get_instance()->register_block();
		}

		$this->_reset_asset_state();

	}

	/**
	 * Puts the asset state and the options object back for whatever runs next.
	 *
	 * @return void
	 */
	public function tear_down(): void {

		$this->_reset_asset_state();

		// The options object holds the stored array for the request; the database is rolled back after each test, so the object goes with it.
		$this->_set_singleton( Option::class, null );

		parent::tear_down();

	}

	/**
	 * The asset manager decides twice, and one `has_action()` cannot see the second.
	 *
	 * A snippet rendered from `wp_footer` itself reaches the page with no Prism if the
	 * registration at `PRIORITY_DECIDE_AGAIN` goes missing; core prints the footer
	 * scripts at 20, so 19 is the last moment which still reaches the page.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_registers_the_asset_managers_hooks(): void {

		$assets = Asset_Manager::get_instance();

		$this->_assert_hooked(
			'wp_footer',
			[ $assets, 'enqueue' ],
			Asset_Manager::PRIORITY_DECIDE,
			'Assets are decided once the page has rendered, so the snippet signal is trustworthy.'
		);

		$this->_assert_hooked(
			'wp_footer',
			[ $assets, 'enqueue' ],
			Asset_Manager::PRIORITY_DECIDE_AGAIN,
			'And again just before core prints the footer, for a snippet rendered from wp_footer itself.'
		);

		$this->assertSame(
			[ Asset_Manager::PRIORITY_DECIDE, Asset_Manager::PRIORITY_DECIDE_AGAIN ],
			$this->_hooked_priorities( 'wp_footer', [ $assets, 'enqueue' ] ),
			'Two passes and no more — a third would be a decision nothing asked for.'
		);

		$this->_assert_hooked(
			'body_class',
			[ $assets, 'get_body_classes' ],
			10,
			'The brace matching classes go on the body, which is the ancestor the engine walks up to.'
		);

	}

	/**
	 * A post with no snippets enqueues nothing at all.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_enqueues_nothing_for_a_page_without_snippets(): void {

		$output = $this->_render_page( "An ordinary post.\n\nWith an ordinary second paragraph." );

		$this->assertFalse( Asset_Manager::get_instance()->has_snippets() );
		$this->assertSame( [], $this->_plugin_asset_handles() );
		$this->assertStringNotContainsString( '<pre', $output );
		$this->assertStringNotContainsString( 'igsh-code-box', $output );

	}

	/**
	 * Content which only looks like a snippet enqueues nothing either: a post
	 * talking about the shortcodes, or an escaped one, which is text.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_enqueues_nothing_for_content_which_only_looks_like_a_snippet(): void {

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
	 * @test
	 *
	 * @return void
	 */
	public function it_enqueues_nothing_for_a_page_with_an_empty_snippet(): void {

		$this->_render_page( 'before [php][/php] after' );

		$this->assertFalse( Asset_Manager::get_instance()->has_snippets() );
		$this->assertSame( [], $this->_plugin_asset_handles() );

	}

	/**
	 * The control: a page which does have a snippet loads the assets, so the tests
	 * above are measuring something.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_enqueues_for_a_page_with_a_snippet(): void {

		$this->_render_page( "[php]\necho 1;\n[/php]" );

		$this->assertTrue( Asset_Manager::get_instance()->has_snippets() );

		$this->assertSame(
			[
				'script:ig-syntax-hiliter-autoloader',
				'script:ig-syntax-hiliter-copy-to-clipboard',
				'script:ig-syntax-hiliter-engine',
				'script:ig-syntax-hiliter-line-numbers',
				'script:ig-syntax-hiliter-match-braces',
				'script:ig-syntax-hiliter-setup',
				'script:ig-syntax-hiliter-show-language',
				'script:ig-syntax-hiliter-toolbar',
				'style:ig-syntax-hiliter-chrome',
				'style:ig-syntax-hiliter-line-numbers',
				'style:ig-syntax-hiliter-match-braces',
				'style:ig-syntax-hiliter-theme',
				'style:ig-syntax-hiliter-toolbar',
			],
			$this->_plugin_asset_handles()
		);

	}

	/**
	 * The line highlight plugin loads for a snippet which highlights lines, and for no
	 * other kind of page.
	 *
	 * The whole list is asserted, as the control above does, so the pair says "these two
	 * and only these two arrived". The band over a highlighted line is painted by the
	 * CSS, so the stylesheet is named beside the script.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_enqueues_the_line_highlight_plugin_for_a_snippet_which_highlights(): void {

		$markup = $this->_render_page( "[php highlight=\"2,4-6\"]\necho 1;\necho 2;\necho 3;\necho 4;\necho 5;\necho 6;\n[/php]" );

		$this->assertStringContainsString(
			'data-line="2,4-6"',
			$markup,
			'The snippet asked for line highlighting and the renderer said nothing about it.'
		);

		$this->assertSame(
			[
				'script:ig-syntax-hiliter-autoloader',
				'script:ig-syntax-hiliter-copy-to-clipboard',
				'script:ig-syntax-hiliter-engine',
				'script:ig-syntax-hiliter-line-highlight',
				'script:ig-syntax-hiliter-line-numbers',
				'script:ig-syntax-hiliter-match-braces',
				'script:ig-syntax-hiliter-setup',
				'script:ig-syntax-hiliter-show-language',
				'script:ig-syntax-hiliter-toolbar',
				'style:ig-syntax-hiliter-chrome',
				'style:ig-syntax-hiliter-line-highlight',
				'style:ig-syntax-hiliter-line-numbers',
				'style:ig-syntax-hiliter-match-braces',
				'style:ig-syntax-hiliter-theme',
				'style:ig-syntax-hiliter-toolbar',
			],
			$this->_plugin_asset_handles()
		);

	}

	/**
	 * The line highlight script waits for the line numbers script.
	 *
	 * It measures the rendered numbers to place its band when a box carries them, so
	 * the order is not a preference. A dependency naming a handle nobody registered
	 * would be dropped by WordPress without a word.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_loads_line_highlighting_after_the_line_numbers_it_measures(): void {

		$this->_render_page( "[php highlight=\"1\"]\necho 1;\n[/php]" );

		$script = wp_scripts()->registered['ig-syntax-hiliter-line-highlight'] ?? null;

		$this->assertNotNull( $script, 'The line highlight script is registered.' );
		$this->assertSame( [ 'ig-syntax-hiliter-line-numbers' ], $script->deps );

		$this->assertTrue(
			wp_script_is( 'ig-syntax-hiliter-line-numbers', 'enqueued' ),
			'The handle it depends on was never enqueued, so WordPress drops it.'
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
	 * @test
	 *
	 * @return void
	 */
	public function it_enqueues_a_theme_from_the_collection_out_of_its_own_directory(): void {

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
			// The options object holds the stored array for the request; the database is rolled back after each test, so the object goes with it.
			$this->_set_singleton( Option::class, null );

		}

	}

	/**
	 * Both settings off means no brace matching script, no stylesheet and no class.
	 *
	 * The script creates the spans the colours are painted on and the body class tells
	 * it to, so all three have to go together.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_matches_no_braces_when_both_settings_are_off(): void {

		$option = Option::get_instance();

		$option->save( 'match_braces', 'no' );
		$option->save( 'rainbow_braces', 'no' );

		try {

			$this->_render_page( "[php]\necho 1;\n[/php]" );

			$handles = $this->_plugin_asset_handles();

			$this->assertNotContains( 'script:ig-syntax-hiliter-match-braces', $handles );
			$this->assertNotContains( 'style:ig-syntax-hiliter-match-braces', $handles );

			$this->assertSame( [], $this->_brace_body_classes() );

		} finally {
			$this->_set_singleton( Option::class, null );
		}

	}

	/**
	 * Matching on and the colours off is the shipped default, and it says one class.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_asks_only_for_matching_by_default(): void {

		$this->_render_page( "[php]\necho 1;\n[/php]" );

		$handles = $this->_plugin_asset_handles();

		$this->assertContains( 'script:ig-syntax-hiliter-match-braces', $handles );
		$this->assertContains( 'style:ig-syntax-hiliter-match-braces', $handles );

		$this->assertSame( [ 'match-braces' ], $this->_brace_body_classes() );

	}

	/**
	 * The colours on top of the matching say both classes and nothing else.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_asks_for_the_nesting_colours_beside_the_matching(): void {

		$option = Option::get_instance();

		$option->save( 'rainbow_braces', 'yes' );

		try {

			$this->assertSame(
				[ 'match-braces', 'rainbow-braces' ],
				$this->_brace_body_classes()
			);

		} finally {
			$this->_set_singleton( Option::class, null );
		}

	}

	/**
	 * The colours wanted without the matching still load the script, and switch the
	 * hover and the click off by name.
	 *
	 * The `brace-level-N` classes are added inside the hook `match-braces` gates, so the
	 * class has to go on; the plugin defaults hover and select on, so both are named off.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_switches_the_interaction_off_when_only_the_colours_are_wanted(): void {

		$option = Option::get_instance();

		$option->save( 'match_braces', 'no' );
		$option->save( 'rainbow_braces', 'yes' );

		try {

			$this->_render_page( "[php]\necho 1;\n[/php]" );

			$handles = $this->_plugin_asset_handles();

			$this->assertContains( 'script:ig-syntax-hiliter-match-braces', $handles );
			$this->assertContains( 'style:ig-syntax-hiliter-match-braces', $handles );

			$this->assertSame(
				[ 'match-braces', 'rainbow-braces', 'no-brace-hover', 'no-brace-select' ],
				$this->_brace_body_classes()
			);

		} finally {
			$this->_set_singleton( Option::class, null );
		}

	}

	/**
	 * Every file the plugin enqueues out of its own assets directory is on disk.
	 *
	 * `assets/build/` is generated and `assets/lib/` is vendored by hand, and enqueuing
	 * a handle whose file is missing is not an error anywhere in WordPress. The list is
	 * read off what was enqueued, so a plugin vendored later is covered when wired up.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_ships_every_file_it_enqueues(): void {

		$this->_render_page( "[php]\necho 1;\n[/php]" );

		$sources = array_merge(
			array_values( wp_scripts()->registered ),
			array_values( wp_styles()->registered )
		);

		$checked = 0;

		foreach ( $sources as $dependency ) {

			if ( ! str_starts_with( (string) $dependency->handle, Asset_Manager::HANDLE_PREFIX ) ) {
				continue;
			}

			$source = (string) $dependency->src;

			if ( ! str_starts_with( $source, Helper::get_asset_url() ) ) {
				continue;    // A webfont stylesheet, which is not ours and is not on this disk.
			}

			$this->assertFileExists(
				Helper::get_asset_path( substr( $source, strlen( Helper::get_asset_url() ) ) ),
				sprintf( 'The %s handle is enqueued from a file which is not there.', $dependency->handle )
			);

			++$checked;

		}

		$this->assertGreaterThan( 5, $checked, 'A page with a snippet enqueues rather more than five files of ours.' );

	}

	/**
	 * Method to read the brace classes this plugin puts on the body element.
	 *
	 * Asked of the filter rather than of the method, so that a registration which
	 * went missing fails here as loudly as a wrong answer does.
	 *
	 * @return array Numerically indexed list of the classes this plugin added.
	 */
	protected function _brace_body_classes(): array {

		$classes = (array) apply_filters( 'body_class', [] );    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter, applied here so a missing registration fails as loudly as a wrong answer.

		return array_values(
			array_filter(
				$classes,
				static fn ( string $name ): bool => str_contains( $name, 'brace' )
			)
		);

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
	 * Core's `wp_print_footer_scripts()` walks the whole queue, and another plugin's
	 * broken dependency would fail whichever test printed first. Printing this plugin's
	 * handles alone still exercises the real printer.
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

			// A handle marked done on the registry, which outlives the test, would print nothing a second time.
			$dependencies->done = array_values( array_diff( $dependencies->done, $handles ) );

			$dependencies->do_items( $handles );

		}

	}

	/**
	 * Method to run the footer, with something rendering content part way through it.
	 *
	 * `_fire_footer()` restores every pass the manager sits at but prints nothing.
	 * These tests need the page as well: an enqueue which happens after the footer
	 * has been printed reaches nobody, so something has to print at the moment core
	 * prints, and the assertions have to be able to tell "enqueued" from "enqueued in
	 * time".
	 *
	 * @param callable $render Callback which renders content from the footer.
	 *
	 * @return string Everything the footer printed.
	 */
	protected function _fire_footer_around( callable $render ): string {

		return $this->_fire_footer(
			[
				[ 10, $render ],
				[
					$this->_core_footer_print_priority(),
					function (): void {
						$this->_print_plugin_assets();
					},
				],
			]
		);

	}

	/**
	 * A snippet which only appears once the footer has started still gets its assets.
	 *
	 * Plenty of things render content from `wp_footer`; a code box arriving after a
	 * single decision would reach the page with no stylesheet and no highlighter.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_still_gives_a_snippet_rendered_late_in_the_footer_its_assets(): void {

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
	 * @test
	 *
	 * @return void
	 */
	public function it_still_needs_a_snippet_to_have_rendered_before_a_second_decision(): void {

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
	 * @test
	 *
	 * @return void
	 */
	public function it_enqueues_the_assets_for_a_snippet_in_a_comment(): void {

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

	/**
	 * The plugin's own stylesheet says a code box does not wrap, on the `pre`.
	 *
	 * Prism's line numbers plugin puts `white-space: inherit` on the `code`, so with
	 * line numbers on the answer comes from the `pre`; with nothing said there, any site
	 * CSS touching `pre` wraps the lines, so the rule is asserted against the compiled
	 * stylesheet.
	 *
	 * It lives here because the stylesheet is the one this class enqueues, held against
	 * the markup `Renderer` emits.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_says_in_the_stylesheet_that_a_code_box_does_not_wrap(): void {

		$css = (string) file_get_contents( IG_SYNTAX_HILITER_ROOT . '/assets/build/css/frontend-chrome.css' );

		$this->assertMatchesRegularExpression(
			'~\.igsh-code-box\s+pre\[class\*=[^\]]+\][^{}]*\{[^}]*white-space:\s*pre[;}]~',
			$css,
			'The pre carries no white-space of its own, so a wrapping site stylesheet wins.'
		);

		// The toolbar plugin wraps each `pre` in a div at runtime, so only a descendant combinator reaches it.
		$this->assertStringNotContainsString(
			'.igsh-code-box>pre',
			$css,
			'A child combinator would miss every box once the toolbar wraps the pre.'
		);

	}

	/**
	 * The font's values are printed once, however many times the assets are decided.
	 *
	 * The manager decides at `wp_footer` 1 and again at 19 and enqueues on both passes;
	 * adding the inline values on each pass would print them twice.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_prints_the_font_values_once(): void {

		$option = Option::get_instance();

		$option->save( 'font', 'fira-code' );

		try {

			$this->_render_page( "[php]\necho 1;\n[/php]" );

			$rules = wp_styles()->get_data( 'ig-syntax-hiliter-chrome', 'after' );
			$rules = ( is_array( $rules ) ) ? $rules : [];

			$this->assertCount( 1, $rules, 'The font values are added once, not once per footer pass.' );

		} finally {
			$this->_set_singleton( Option::class, null );
		}

	}

	/**
	 * A page whose only snippet is a block still loads the highlighter.
	 *
	 * The signal is raised by the renderer, so a callback which built its own markup
	 * would leave a block-only page with unhighlighted, unstyled code.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_enqueues_the_assets_for_a_page_whose_only_snippet_is_a_block(): void {

		$this->_filter(
			'the_content',
			static::_block(
				[
					'code'     => 'echo 1;',
					'language' => 'php',
				]
			)
		);

		$manager = Asset_Manager::get_instance();

		$this->assertTrue( $manager->has_snippets() );
		$this->assertSame( [ 'php' ], $manager->get_languages() );

		$this->_fire_footer();

		$this->assertTrue( wp_script_is( 'ig-syntax-hiliter-engine', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'ig-syntax-hiliter-theme', 'enqueued' ) );

	}

	/**
	 * Nothing on the page asks for the bogus language, and the box is still given
	 * the engine and a theme to be styled by. The check is against what was
	 * registered, not against markup.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_enqueues_no_component_for_an_unknown_language(): void {

		$this->_filter( 'the_content', '[sourcecode language="madeuplang"]xyz[/sourcecode]' );

		$manager = Asset_Manager::get_instance();

		$this->assertTrue( $manager->has_snippets(), 'A plain box is still a box, so the engine loads.' );
		$this->assertSame( [], $manager->get_languages(), 'An unresolvable language is not a language.' );

		$this->_fire_footer();

		foreach ( $this->_all_asset_urls() as $asset ) {
			$this->assertStringNotContainsString( 'madeuplang', $asset );
			$this->assertStringNotContainsString( 'prism-none', $asset );
		}

		$this->assertTrue( wp_script_is( 'ig-syntax-hiliter-engine', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'ig-syntax-hiliter-theme', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'ig-syntax-hiliter-chrome', 'enqueued' ) );

	}

	/**
	 * A language the registry does know is not enqueued either: the engine's own
	 * loader fetches language files at runtime, and enqueuing them eagerly would put
	 * the 404 risk straight back.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_leaves_a_known_language_to_the_runtime_loader(): void {

		$this->_filter( 'the_content', '[php]echo 1;[/php]' );

		$this->assertSame( [ 'php' ], Asset_Manager::get_instance()->get_languages() );

		$this->_fire_footer();

		foreach ( $this->_all_asset_urls() as $asset ) {
			$this->assertStringNotContainsString( 'prism-php', $asset );
		}

		$this->assertTrue( wp_script_is( 'ig-syntax-hiliter-autoloader', 'enqueued' ) );

	}

	/**
	 * The Autolinker component is not bundled and is never loaded.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_neither_bundles_nor_enqueues_the_autolinker_component(): void {

		$this->assertDirectoryDoesNotExist( dirname( __DIR__, 2 ) . '/assets/lib/prism/plugins/autolinker' );

		$this->_filter( 'the_content', sprintf( "[php]\n%s\n[/php]", self::_CODE_WITH_URLS ) );

		$this->_fire_footer();

		foreach ( $this->_all_asset_urls() as $asset ) {
			$this->assertStringNotContainsString( 'autolinker', $asset );
		}

	}

	/**
	 * Method to read the rules added inline against the plugin's own stylesheet.
	 *
	 * @return string
	 */
	protected function _inline_chrome_rules(): string {

		$rules = wp_styles()->get_data( 'ig-syntax-hiliter-chrome', 'after' );

		if ( ! is_array( $rules ) ) {
			return '';
		}

		return implode( '', $rules );

	}

	/**
	 * The promise of the default: a page with code on it, and the font setting left
	 * alone, reaches out to nobody.
	 *
	 * Every other asset the plugin loads is a file it ships; a font is not, and a
	 * plugin which quietly fetched one from a third party would be making a decision
	 * that belongs to the site owner.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_fetches_no_font_unless_one_is_chosen(): void {

		$this->assertSame(
			Fonts::FONT_NONE,
			Option::get_instance()->get( 'font' ),
			'The shipped default loads no font.'
		);

		$this->_render_page( "[php]\necho 1;\n[/php]" );

		$this->assertTrue( Asset_Manager::get_instance()->has_snippets(), 'The page really did render a code box.' );

		foreach ( $this->_all_asset_urls() as $asset ) {
			$this->assertStringNotContainsString( static::_FONT_HOST, $asset );
		}

		$this->assertArrayNotHasKey(
			'ig-syntax-hiliter-font',
			wp_styles()->registered,
			'Nothing registers a webfont stylesheet when no font is chosen.'
		);

		$this->assertSame(
			'',
			$this->_inline_chrome_rules(),
			'No rule is added to the chrome stylesheet either.'
		);

	}

	/**
	 * A chosen font is fetched exactly once, from that host and no other, and the
	 * rule which applies it rides along with the plugin's own stylesheet.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_fetches_a_chosen_font_once_and_applies_it(): void {

		Option::get_instance()->save( 'font', 'jetbrains-mono' );

		$this->_render_page( "[php]\necho 1;\n[/php]" );

		$found = [];

		foreach ( $this->_all_asset_urls() as $asset ) {

			if ( ! str_contains( $asset, static::_FONT_HOST ) ) {
				continue;
			}

			$found[] = $asset;

		}

		$this->assertCount( 1, $found, 'Exactly one asset is fetched from the font service.' );

		$style = wp_styles()->registered['ig-syntax-hiliter-font'] ?? null;

		$this->assertNotNull( $style, 'The webfont stylesheet is registered.' );
		$this->assertSame( Fonts::get_font_url( 'jetbrains-mono' ), (string) $style->src );

		// No version on a URL which belongs to somebody else: `wp_enqueue_style()` is passed NULL.
		$this->assertStringNotContainsString( 'ver=', (string) $style->src );

		$rules = $this->_inline_chrome_rules();

		$this->assertStringContainsString( '"JetBrains Mono"', $rules );
		$this->assertStringContainsString( '--igsh-code-font', $rules );

		// Values and not a rule: the selectors live in the stylesheet.
		$this->assertStringNotContainsString( Renderer::ID_PREFIX, $rules );
		$this->assertStringStartsWith( ':root {', $rules );

	}

	/**
	 * A font this plugin does not offer loads nothing, rather than falling back to
	 * some other font.
	 *
	 * The theme setting falls back the other way, to the default theme, because a
	 * code box with no colours looks broken. There is no equivalent here: a wrong
	 * typeface is not worth a request to another host.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_loads_nothing_for_a_font_this_plugin_does_not_offer(): void {

		$this->assertSame( '', Fonts::get_font_url( 'comic-sans-ms' ) );
		$this->assertSame( '', Fonts::get_font_css( 'comic-sans-ms' ) );

		Option::get_instance()->save( 'font', 'comic-sans-ms' );

		$this->assertSame(
			Fonts::FONT_NONE,
			Option::get_instance()->get( 'font' ),
			'A font outside the list is stored as the default, which is None.'
		);

		$this->_render_page( "[php]\necho 1;\n[/php]" );

		foreach ( $this->_all_asset_urls() as $asset ) {
			$this->assertStringNotContainsString( static::_FONT_HOST, $asset );
		}

	}

} // end of class

// EOF
