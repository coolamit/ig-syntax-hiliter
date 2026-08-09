<?php
/**
 * Tests for the block's render callback.
 *
 * The block is the second way into the renderer, and the only one which does not
 * go through the shortcode pipeline: `do_blocks()` runs at `the_content` priority
 * 9, between the protect pass and the filters it protects against. What is checked
 * here is that the second way in arrives at the same place as the first, and is
 * given the same protection once it gets there.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Asset_Manager;
use iG\Syntax_Hiliter\Block;
use iG\Syntax_Hiliter\Shortcode_Handler;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

/**
 * What the block renders, and what the rest of the request is allowed to do to it.
 */
class Block_Render_Test extends WP_UnitTestCase {

	use Asset_Test_Helpers;

	/**
	 * Marker carried by the code in the excerpt fixture.
	 *
	 * @var string
	 */
	const MARKER = 'leaked-block-code-marker';

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

		Shortcode_Handler::get_instance()->register_hooks();

		/*
		 * What is under test here is the render callback, not when the block type
		 * reaches the registry. Registering it if it is not there already keeps
		 * these tests independent of whether the plugin's own `init` callback has
		 * run by the time PHPUnit gets here.
		 */
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
	 * Method to build the block delimiter an editor save would leave in the content.
	 *
	 * Core does the serializing, so what the tests are handed is what the block
	 * parser is built to read back.
	 *
	 * @param array $attributes Block attributes.
	 *
	 * @return string
	 */
	protected static function _block( array $attributes ): string {

		return serialize_block(
			[
				'blockName'    => Block::NAME,
				'attrs'        => $attributes,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);

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
		return trim( (string) preg_replace( '/ id="ig-sh-\d+"/', ' id="ig-sh-N"', $markup ) );
	}

	/**
	 * I3 — one renderer. The block and the shortcode reach it through separate
	 * attribute mappers, so the two are free to drift apart without anything
	 * noticing. This is what notices.
	 *
	 * @return void
	 */
	public function test_a_block_and_the_equivalent_shortcode_render_the_same_code_box(): void {

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
	 * AC-2 through the block path — the whole point of the release.
	 *
	 * `do_blocks()` hands the callback's markup straight back into a filter chain
	 * which has not run yet, so without the protector every priority 10 filter on
	 * the site gets to rewrite the code. The URL outside the block is the control:
	 * it proves the hostile filter really ran.
	 *
	 * @return void
	 */
	public function test_block_markup_survives_a_hostile_filter_at_priority_ten(): void {

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
	 * I1 — one escape point. The block's code reaches the renderer as JSON out of
	 * the delimiter rather than as shortcode content, which is the path along which
	 * a second escape could be added without anyone noticing at the renderer.
	 *
	 * @return void
	 */
	public function test_code_in_a_block_is_escaped_exactly_once(): void {

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
	 * AC-8 — a page whose only snippet is a block still loads the highlighter.
	 *
	 * The signal is raised by the renderer, so a callback which ever built its own
	 * markup instead would leave a block only page with unhighlighted, unstyled
	 * code and nothing to say so.
	 *
	 * A page with no snippet at all is `Conditional_Assets_Test`'s business.
	 *
	 * @return void
	 */
	public function test_a_page_whose_only_snippet_is_a_block_enqueues_the_assets(): void {

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
	 * AC-1 through the block path — the defect which was found and fixed for
	 * shortcodes, coming back through a door nobody has walked through yet.
	 *
	 * `wp_trim_excerpt()` unhooks `do_blocks` and calls `excerpt_remove_blocks()`,
	 * which renders the blocks on its allow list itself. This block is not on that
	 * list by default, so nothing happens today — but a site may put it there, and
	 * when it does the callback must render nothing rather than hand
	 * `wp_trim_words()` a code box to strip the markup off and leave the code as
	 * prose.
	 *
	 * @return void
	 */
	public function test_a_block_allowed_into_an_excerpt_leaks_no_code(): void {

		$post_id = self::factory()->post->create(
			[
				'post_content' => wp_slash(
					static::_block(
						[
							'code'     => sprintf( "\$secret = '%s';", static::MARKER ),
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
		$this->assertStringNotContainsString( static::MARKER, $excerpt );

	}

	/**
	 * I4 — a language the registry cannot resolve degrades.
	 *
	 * Nothing filters a block attribute on its way in: the shortcode path drops
	 * what it does not recognise through `shortcode_atts()`, while whatever JSON is
	 * in the delimiter is what the mapper is handed. This is therefore the path
	 * along which an arbitrary language name reaches the renderer, and it must end
	 * in a plain box and no request for a language file which is not there.
	 *
	 * @return void
	 */
	public function test_a_block_with_an_unresolvable_language_degrades_to_a_plain_box(): void {

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

}    //end of class


//EOF
