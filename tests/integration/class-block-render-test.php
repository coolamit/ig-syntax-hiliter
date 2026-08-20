<?php
/**
 * Tests for the block's render callback.
 *
 * The block is the second way into the renderer; `do_blocks()` runs at `the_content`
 * priority 9, between the protect pass and the filters it protects against.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Asset_Manager;
use iG\Syntax_Hiliter\Block;
use iG\Syntax_Hiliter\Content_Protector;
use iG\Syntax_Hiliter\Helper;
use iG\Syntax_Hiliter\Shortcode_Handler;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Asset_Test_Helpers;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

/**
 * What the block renders, and what the rest of the request is allowed to do to it.
 */
class Block_Render_Test extends WP_UnitTestCase {

	use Pipeline_Test_Helpers;

	use Asset_Test_Helpers;

	/**
	 * Marker carried by the code in the excerpt fixture.
	 *
	 * @var string
	 */
	protected const string _MARKER = 'leaked-block-code-marker';

	/**
	 * Whether the block was rendered during the run under test.
	 *
	 * @var bool
	 */
	protected bool $_block_rendered = false;

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

		$this->_block_rendered = false;

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
	 * Lets this plugin's block through the excerpt gate, which by default keeps it out.
	 *
	 * @return array
	 */
	public function allow_this_plugins_block() {
		return [ Block::NAME ];
	}

	/**
	 * Records that this plugin's block was rendered.
	 *
	 * @param string $content Rendered block content.
	 * @param array  $block   Parsed block.
	 *
	 * @return string
	 */
	public function note_rendered_block( $content, $block ) {

		if ( Block::NAME === ( $block['blockName'] ?? '' ) ) {
			$this->_block_rendered = true;
		}

		return $content;

	}

	/**
	 * Method to make two renders of the same snippet comparable.
	 *
	 * The box id counts boxes rendered in the request, so it differs between two
	 * renders of identical snippets by design.
	 *
	 * @param string $markup Rendered markup.
	 *
	 * @return string
	 */
	protected static function _normalize( string $markup ): string {
		// Not anchored on a leading space, so it does not depend on where `id` sits among the attributes.
		return trim( (string) preg_replace( '/id="ig-sh-\d+"/', 'id="ig-sh-N"', $markup ) );
	}

	/**
	 * `Block::NAME` and the `name` in `block.json` name the same block.
	 *
	 * The save path shields this plugin's delimiters from KSES by matching on the
	 * constant, so a name which drifted would silently stop matching and stored code
	 * would be lost. The build is checked first: `register_block()` returns quietly
	 * without it, which would look exactly like drift.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_registers_the_block_the_pipeline_matches_on(): void {

		$this->assertFileExists(
			Helper::get_path( Block::BUILD_DIR ) . '/block.json',
			'The block is not built, so nothing was registered and there is no name to compare.'
		);

		$this->assertTrue(
			WP_Block_Type_Registry::get_instance()->is_registered( Block::NAME ),
			'Nothing is registered under Block::NAME, so the constant and block.json name different blocks.'
		);

	}

	/**
	 * There is one renderer. The block and the shortcode reach it through separate
	 * attribute mappers, so the two are free to drift apart without anything
	 * noticing. This is what notices.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_renders_the_same_code_box_for_a_block_and_the_equivalent_shortcode(): void {

		$code = "function greet( \$name ) {\n\techo \"Hello, \$name\";\n}";

		$from_block = $this->_filter(
			'the_content',
			static::_block(
				[
					'code'            => $code,
					'language'        => 'php',
					'showLineNumbers' => true,
					'firstLine'       => 12,
					'highlightLines'  => '2,4-6',
					'file'            => 'greet.php',
				]
			)
		);

		$from_shortcode = $this->_filter(
			'the_content',
			sprintf(
				'[sourcecode language="php" gutter="yes" firstline="12" highlight="2,4-6" file="greet.php"]%s[/sourcecode]',
				$code
			)
		);

		$this->assertStringContainsString( '<pre ', $from_block, 'The block rendered a code box at all.' );
		$this->assertSame( static::_normalize( $from_shortcode ), static::_normalize( $from_block ) );

	}

	/**
	 * Block markup survives a hostile filter at priority 10.
	 *
	 * `do_blocks()` hands the callback's markup into a filter chain which has not run
	 * yet. The URL outside the block is the control proving the hostile filter ran.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_keeps_block_markup_through_a_hostile_filter_at_priority_ten(): void {

		$code    = '<script src="http://inside.test/y.js"></script>';  // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Test fixture standing in for author written code, not markup this plugin emits.
		$outside = 'http://outside.test/a.js';

		$content = sprintf(
			"Prose mentioning %s here.\n\n%s",
			$outside,
			static::_block(
				[
					'code'     => $code,
					'language' => 'php',
				]
			)
		);

		add_filter( 'the_content', [ $this, 'hostile_filter' ], 10 );

		$output = $this->_filter( 'the_content', $content );

		remove_filter( 'the_content', [ $this, 'hostile_filter' ], 10 );

		$this->assertStringContainsString(
			sprintf( '<a href="%1$s">%1$s</a>', $outside ),
			$output,
			'The hostile filter ran, so what follows is measuring something.'
		);

		$this->assertStringContainsString( esc_html( $code ), $output );
		$this->assertStringNotContainsString( '<a href="http://inside.test/y.js"', $output );

		// Texturize would have curled the quotes had it been given the chance.
		$this->assertStringNotContainsString( '&#8220;', $output );
		$this->assertStringNotContainsString( '&#8221;', $output );

	}

	/**
	 * There is one escape point. The block's code reaches the renderer as JSON out
	 * of the delimiter rather than as shortcode content, which is the path along
	 * which a second escape could be added without anyone noticing at the renderer.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_escapes_code_in_a_block_exactly_once(): void {

		$code = "if ( \$a && \$b ) {\n\techo \"<b>\" . \$x . '</b>';\n}";

		$output = $this->_filter(
			'the_content',
			static::_block(
				[
					'code'     => $code,
					'language' => 'php',
				]
			)
		);

		$this->assertSame( 1, preg_match( '#<code class="language-php">(.*)</code>#s', $output, $matches ) );
		$this->assertSame( $code, html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ) );

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
	 * A block a site has allowed into an excerpt renders nothing there.
	 *
	 * `excerpt_remove_blocks()` renders the blocks on its allow list itself; a code
	 * box handed to `wp_trim_words()` would be stripped to its code as prose.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_leaks_no_code_from_a_block_allowed_into_an_excerpt(): void {

		$post_id = self::factory()->post->create(
			[
				'post_content' => wp_slash(
					static::_block(
						[
							'code'     => sprintf( "\$secret = '%s';", static::_MARKER ),
							'language' => 'php',
						]
					)
				),
				'post_excerpt' => '',
			]
		);

		add_filter( 'excerpt_allowed_blocks', [ $this, 'allow_this_plugins_block' ] );
		add_filter( 'render_block', [ $this, 'note_rendered_block' ], 10, 2 );

		$excerpt = get_the_excerpt( $post_id );

		remove_filter( 'render_block', [ $this, 'note_rendered_block' ], 10 );
		remove_filter( 'excerpt_allowed_blocks', [ $this, 'allow_this_plugins_block' ] );

		$this->assertTrue( $this->_block_rendered, 'The block was really let into the excerpt, so this test is measuring something.' );
		$this->assertStringNotContainsString( static::_MARKER, $excerpt );

	}

	/**
	 * A snippet whose code documents this plugin still renders.
	 *
	 * `serialize_block_attributes()` escapes `<`, `>`, `&` and `--` but neither bracket,
	 * so a matcher blind to delimiters would rewrite inside one.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_still_renders_a_block_whose_code_contains_a_shortcode(): void {

		$code = 'Use [php]echo 1;[/php] in your post.';

		$output = $this->_filter(
			'the_content',
			static::_block(
				[
					'code'     => $code,
					'language' => 'php',
				]
			)
		);

		$this->assertStringContainsString( '<pre ', $output, 'The block rendered a code box at all.' );
		$this->assertStringContainsString( $code, $output, 'The shortcode in the code is text, and it is all still there.' );

	}

	/**
	 * A run left in flight renders normally rather than emitting a placeholder
	 * nothing will restore.
	 *
	 * The protect pass and the restore pass are two separate filter callbacks, so no
	 * frame spans both and no `finally` can close the pair. A filter which hands back
	 * something that is not content — or which throws, or which tears the chain down
	 * — leaves the run open.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_does_not_let_a_run_left_in_flight_swallow_a_later_code_box(): void {

		$protector = Content_Protector::get_instance();

		$breaker = static function () {
			return null;
		};

		add_filter( 'the_content', $breaker, 50 );

		$this->_filter( 'the_content', '[php]echo 1;[/php]' );

		remove_filter( 'the_content', $breaker, 50 );

		$this->assertFalse( $protector->is_protecting(), 'The chain never reached the restore pass, so nothing is in flight.' );

		$markup = Block::get_instance()->render(
			[
				'code'     => 'echo 2;',
				'language' => 'php',
			]
		);

		$this->assertStringContainsString( '<pre ', $markup );
		$this->assertStringNotContainsString( Content_Protector::PLACEHOLDER_PREFIX, $markup );

	}

	/**
	 * A language the registry cannot resolve degrades.
	 *
	 * Nothing filters a block attribute on its way in: the shortcode path drops
	 * what it does not recognise through `shortcode_atts()`, while whatever JSON is
	 * in the delimiter is what the mapper is handed, so an arbitrary name reaches the
	 * renderer here and must end in a plain box and no request for a language file
	 * which is not there.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_degrades_a_block_with_an_unresolvable_language_to_a_plain_box(): void {

		$output = $this->_filter(
			'the_content',
			static::_block(
				[
					'code'     => 'xyz',
					'language' => 'madeuplang',
				]
			)
		);

		$this->assertStringContainsString( '<code class="language-none">', $output );
		$this->assertStringNotContainsString( 'madeuplang', $output );

		$this->assertTrue( Asset_Manager::get_instance()->has_snippets(), 'A plain box is still a box, so the engine loads.' );
		$this->assertSame( [], Asset_Manager::get_instance()->get_languages(), 'An unresolvable language is not a language.' );

		$this->_fire_footer();

		foreach ( $this->_all_asset_urls() as $asset ) {
			$this->assertStringNotContainsString( 'madeuplang', $asset );
		}

	}

} // end of class

// EOF
