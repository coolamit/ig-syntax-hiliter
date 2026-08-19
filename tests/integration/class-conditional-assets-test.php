<?php
/**
 * A page without snippets loads none of this plugin's assets.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Asset_Manager;
use iG\Syntax_Hiliter\Helper;
use iG\Syntax_Hiliter\Option;
use iG\Syntax_Hiliter\Shortcode_Handler;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Asset_Test_Helpers;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use WP_UnitTestCase;

/**
 * Asserts against the enqueue state left behind after the footer pass, for pages
 * which have no code on them.
 */
class Conditional_Assets_Test extends WP_UnitTestCase {

	use Pipeline_Test_Helpers;

	use Asset_Test_Helpers;

	/**
	 * Registers the pipeline once WordPress is up.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		Shortcode_Handler::get_instance();

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
			/*
			 * The options object reads the stored array once and holds it for the rest
			 * of the request. The database is rolled back after this test, so the object
			 * has to go with it or the next test reads a theme nobody saved.
			 */
			$this->_set_singleton( Option::class, null );

		}

	}

	/**
	 * Both settings off means no brace matching script, no stylesheet and no class.
	 *
	 * Nothing else can see any of this. The script is what creates the spans the
	 * nesting colours are painted on, and the class on the body is what tells it to
	 * create them — so all three have to go together, and a site owner who has
	 * switched both settings off has to get a page which is exactly the page they got
	 * before this feature existed.
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
	 * **This is the case the two settings exist for and the one nothing else guards.**
	 * The `brace-level-N` classes the colours are painted on are added inside the same
	 * hook `match-braces` gates, so the colours alone would be a setting which does
	 * nothing — the class has to go on. `no-brace-hover` and `no-brace-select` are then
	 * the only thing standing between "I wanted the colours" and an interaction the
	 * site owner switched off, because the plugin defaults both of them on.
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
	 * `assets/build/` is generated and git-ignored, and `assets/lib/` is a vendored
	 * upstream release which is copied in by hand — so a fresh checkout, an
	 * interrupted build and a half finished vendoring all look the same from here: a
	 * `<script>` tag pointing at a 404. Nothing else in three tiers would say so,
	 * because enqueuing a handle whose file is missing is not an error anywhere in
	 * WordPress.
	 *
	 * Read off what was actually enqueued rather than from a list written out here,
	 * so a plugin vendored later is covered the day it is wired up and not the day
	 * somebody remembers this case.
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
				continue;    //a webfont stylesheet, which is not ours and is not on this disk
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
	 * Plenty of things render content from `wp_footer` — a modal, a late list of
	 * related posts, a comment list built on demand. A code box which arrives that
	 * way used to land on the page after the one and only decision had been taken,
	 * with no stylesheet, no theme and no highlighter, and nothing left on the page
	 * able to put that right.
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
	 * An odd thing to assert — it reads a build artefact for a string — and it earns
	 * its place because this exact declaration was missing and nothing noticed. Prism's
	 * line numbers plugin carries `white-space: inherit` on the `code` at the same
	 * specificity as this plugin's rule and loads after it, so with line numbers on the
	 * code takes its answer from the `pre`. With nothing said there, the last word
	 * belonged to the theme at 0-1-1 and any site CSS touching `pre` took it away —
	 * lines wrapped, and the line numbers stopped lining up with the code.
	 *
	 * No tier here can see a rendered box, so the rule itself is what is checked.
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

		/*
		 * The container is what the rule finds the box by, and a descendant combinator
		 * is what reaches it: the toolbar plugin wraps each `pre` in a `.code-toolbar`
		 * div at runtime, so a child combinator would stop matching the moment the
		 * toolbar setting is on.
		 */
		$this->assertStringNotContainsString(
			'.igsh-code-box>pre',
			$css,
			'A child combinator would miss every box once the toolbar wraps the pre.'
		);

	}

	/**
	 * The stylesheet reads every custom property the font setting sets.
	 *
	 * The two halves are in different files on purpose — the selectors here, the values
	 * from PHP — which is what keeps adding a font a one file job and the cascade
	 * legible. The cost of that split is that either half can stop referring to the
	 * other without a word: a rule which stopped reading a variable would simply draw
	 * the fallback for ever, and a site owner would report that picking a font does
	 * nothing.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_reads_what_the_font_setting_sets_into_the_stylesheet(): void {

		$css = (string) file_get_contents( IG_SYNTAX_HILITER_ROOT . '/assets/build/css/frontend-chrome.css' );

		foreach ( [ '--igsh-code-font', '--igsh-code-ligatures', '--igsh-code-letter-spacing' ] as $property ) {

			$this->assertStringContainsString(
				sprintf( 'var(%s', $property ),
				$css,
				sprintf( 'Nothing in the stylesheet reads %s, so setting it does nothing.', $property )
			);

		}

	}

	/**
	 * The font's values are printed once, however many times the assets are decided.
	 *
	 * The manager decides at `wp_footer` 1 and again at 19, and it has to enqueue on
	 * both passes — the second is what catches a snippet rendered from the footer
	 * itself. Adding the inline values twice only prints them twice, which is what the
	 * front end did until this was guarded.
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

}    //end of class


//EOF
