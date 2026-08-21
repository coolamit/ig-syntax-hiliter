<?php
/**
 * Tests for the block.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Asset_Manager;
use iG\Syntax_Hiliter\Block;
use iG\Syntax_Hiliter\Fonts;
use iG\Syntax_Hiliter\Helper;
use iG\Syntax_Hiliter\Language_Registry;
use iG\Syntax_Hiliter\Legacy_Map;
use iG\Syntax_Hiliter\Option;
use iG\Syntax_Hiliter\Shortcode_Handler;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Asset_Test_Helpers;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Hook_Test_Helpers;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use ReflectionProperty;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

/**
 * The block: its registration, what it renders through the pipeline, the data and
 * the font it hands the editor.
 */
class Block_Test extends WP_UnitTestCase {

	use Pipeline_Test_Helpers;

	use Asset_Test_Helpers;

	use Hook_Test_Helpers;

	/**
	 * Marker carried by the code in the excerpt fixture.
	 *
	 * @var string
	 */
	protected const string _MARKER = 'leaked-block-code-marker';

	/**
	 * The code the zero cases are about.
	 *
	 * @var string
	 */
	protected const string _ZERO = '0';

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
	 * The block's generated editor script handle.
	 *
	 * @return string
	 */
	protected function _get_editor_handle(): string {

		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( Block::NAME );

		$this->assertNotNull( $block_type, 'The block is registered, so this checkout has been built.' );

		return (string) ( $block_type->editor_script_handles[0] ?? '' );

	}

	/**
	 * The editor data object, as it reaches the browser.
	 *
	 * @return array
	 */
	protected function _get_localised_editor_data(): array {

		$handle = $this->_get_editor_handle();

		// Inline scripts are appended, not replaced, so drop what an earlier call left.
		unset( wp_scripts()->registered[ $handle ]->extra['before'] );

		Block::get_instance()->add_editor_data();

		$before = wp_scripts()->get_data( $handle, 'before' );
		$before = ( is_array( $before ) ) ? implode( "\n", $before ) : (string) $before;

		$this->assertStringContainsString( Block::EDITOR_DATA_OBJECT, $before, 'The data object reaches the editor.' );

		$this->assertSame(
			1,
			preg_match( sprintf( '/var %s = (\{.*\});/s', preg_quote( Block::EDITOR_DATA_OBJECT, '/' ) ), $before, $matches ),
			'The data object is assigned as one JSON literal.'
		);

		$data = json_decode( $matches[1], true );

		$this->assertIsArray( $data, 'What was printed is readable JSON.' );

		return $data;

	}

	/**
	 * Method to read the rules added inline against the editor font stylesheet.
	 *
	 * @return string
	 */
	protected function _editor_font_rules(): string {

		$rules = wp_styles()->get_data( 'ig-syntax-hiliter-editor-font', 'after' );

		if ( ! is_array( $rules ) ) {
			return '';
		}

		return implode( '', $rules );

	}

	/**
	 * Method to forget the editor font, so each case starts where a request does.
	 *
	 * @return void
	 */
	protected function _reset_editor_font(): void {

		wp_dequeue_style( 'ig-syntax-hiliter-editor-font' );
		wp_deregister_style( 'ig-syntax-hiliter-editor-font' );

		( new ReflectionProperty( Asset_Manager::class, '_editor_font_styled' ) )
			->setValue( Asset_Manager::get_instance(), false );

		$this->_set_singleton( Option::class, null );

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
	 * The block registers the hooks the rest of the block suite calls by hand.
	 *
	 * The `init` priority is asserted and not merely the registration. The plugin boots
	 * on `init` at 10, and a callback added at a priority which does not yet exist is
	 * picked up in that same run; one appended to the priority currently running is
	 * never reached, and the block silently vanishes.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_registers_the_blocks_hooks(): void {

		$block = Block::get_instance();

		$this->_assert_hooked(
			'enqueue_block_editor_assets',
			[ $block, 'add_editor_data' ],
			null,
			'The editor is handed the tag list and the language map.'
		);

		$this->_assert_hooked(
			'enqueue_block_assets',
			[ $block, 'enqueue_editor_font' ],
			null,
			'The chosen font reaches the editor canvas, which is an iframe that enqueue_block_editor_assets does not reach.'
		);

		$this->_assert_hooked(
			'init',
			[ $block, 'register_block' ],
			Block::PRIORITY_REGISTER,
			'The block registers behind the priority the plugin boots at, which is what gets it into the same run.'
		);

		$this->assertGreaterThan(
			10,
			Block::PRIORITY_REGISTER,
			'The plugin boots on init at priority 10, so anything at 10 or earlier is appended to a priority already running and never reached.'
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

	/**
	 * The block path renders it, and renders the same thing.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_renders_a_code_box_for_a_block_whose_code_is_zero(): void {

		$rendered = $this->_filter(
			'the_content',
			static::_block(
				[
					'code'     => static::_ZERO,
					'language' => 'php',
				]
			)
		);

		$this->assertStringContainsString(
			'<pre ',
			$rendered,
			'A block whose code is 0 was read as an empty block and rendered as nothing.'
		);

		$this->assertStringContainsString(
			'>' . static::_ZERO . '<',
			$rendered,
			'The code box rendered, but the zero is not in it.'
		);

	}

	/**
	 * The control: a block which really is empty still renders as nothing, so the
	 * case above is not satisfied by a plugin that stopped checking.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_still_renders_nothing_for_a_genuinely_empty_block(): void {

		$block = $this->_filter( 'the_content', static::_block( [ 'code' => '' ] ) );

		$this->assertStringNotContainsString( '<pre ', $block, 'An empty block rendered a code box.' );

		// The wrapper is emitted for every box, so an empty container would slip past the assertion above.
		$this->assertStringNotContainsString( 'igsh-code-box', $block, 'An empty block rendered a container.' );

	}

	/**
	 * The Gist block renders through this same pipeline.
	 *
	 * There is one embed implementation, not two, so the block and the shortcode agree
	 * on the id sanitising, the link form and what a comment may carry.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_renders_the_gist_block_through_this_pipeline(): void {

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
	 * The editor's own strings can be translated.
	 *
	 * Core calls `wp_set_script_translations()` in `register_block_script_handle()`,
	 * but only when `block.json` declares a `textdomain` and the built script lists
	 * `wp-i18n` among its dependencies. Lose either and the editor reverts to English.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_sets_the_editor_script_up_for_translation(): void {

		$handle = $this->_get_editor_handle();

		$this->assertNotSame( '', $handle, 'Block registration generated an editor script handle.' );

		$script = wp_scripts()->registered[ $handle ] ?? null;

		$this->assertNotNull( $script, 'The generated handle is a registered script.' );
		$this->assertContains( 'wp-i18n', $script->deps, 'The editor script still imports the internationalisation library.' );
		$this->assertSame( 'igsyntax-hiliter', $script->textdomain, 'The editor script is pointed at this plugin\'s text domain.' );

	}

	/**
	 * The editor is handed the tag list and the languages PHP knows about.
	 *
	 * The editor claims exactly the tags PHP claims, filter included, rather than
	 * carrying a second copy of the list in JavaScript. If this never arrives the
	 * language dropdown is empty and automatic conversion claims nothing.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_hands_the_editor_the_tag_list_and_the_languages(): void {

		$data = $this->_get_localised_editor_data();

		$this->assertNotEmpty( $data['languages'] ?? [], 'The language dropdown has something to draw.' );
		$this->assertContains( 'php', $data['legacyTags'] ?? [], 'The editor claims the tags PHP claims.' );
		$this->assertSame( 'sourcecode', $data['genericTag'] ?? '', 'The generic tag is named.' );

	}

	/**
	 * The editor can turn a legacy tag or an alias into a canonical language id.
	 *
	 * The dropdown is drawn from canonical ids alone, so an alias which never
	 * reaches the editor is a snippet converted to a block holding a name no option
	 * carries — where the control shows the first option instead and writing that
	 * back overwrites the language.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_hands_the_editor_the_language_aliases(): void {

		$data    = $this->_get_localised_editor_data();
		$aliases = $data['languageAliases'] ?? [];

		$this->assertIsArray( $aliases, 'The alias table reaches the editor.' );
		$this->assertSame( Language_Registry::NO_LANGUAGE, $data['noLanguage'] ?? '', 'The sentinel is named rather than left for the editor to guess.' );

		// The library's own aliases, which is what `[sourcecode language="js"]` needs.
		$this->assertSame( 'javascript', $aliases['js'] ?? '', 'A library alias resolves.' );

		// This plugin's own tags, which the library has never heard of.
		$this->assertSame( 'markup', $aliases['html'] ?? '', 'A legacy tag resolves.' );
		$this->assertSame( 'markup', $aliases['html4strict'] ?? '', 'A GeSHi era tag resolves.' );
		$this->assertSame( 'apacheconf', $aliases['apache'] ?? '', 'A legacy tag whose id is spelled differently resolves.' );
		$this->assertSame( 'sql', $aliases['mysql'] ?? '', 'A dialect resolves to the language which highlights it.' );

		// `[code]` and `[text]` have meant "show it, do not highlight it" since 2004.
		$this->assertSame( Language_Registry::NO_LANGUAGE, $aliases['code'] ?? '', 'The generic legacy tag resolves to the sentinel.' );
		$this->assertSame( Language_Registry::NO_LANGUAGE, $aliases['text'] ?? '', 'The plain text tag resolves to the sentinel.' );

		$registry = Language_Registry::get_instance();

		foreach ( Legacy_Map::get_language_map() as $tag => $id ) {

			if ( Language_Registry::NO_LANGUAGE === $id ) {
				continue;
			}

			$this->assertTrue(
				$registry->has( $id ),
				sprintf( 'The legacy map sends `%1$s` to `%2$s`, which the bundled library still has a file for.', $tag, $id )
			);

			$this->assertSame( $id, $aliases[ $tag ] ?? '', sprintf( 'The editor resolves `%s` the way the server does.', $tag ) );

		}

	}

	/**
	 * Every alias offered names a language the site can actually load.
	 *
	 * An entry pointing nowhere would put an id into a block attribute which nothing
	 * on the site can highlight, and would throw away the author's own word in the
	 * process — which the `ig_syntax_hiliter/languages` filter may yet have made good.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_points_no_alias_at_a_language_the_site_cannot_load(): void {

		$aliases  = $this->_get_localised_editor_data()['languageAliases'] ?? [];
		$registry = Language_Registry::get_instance();

		$this->assertNotEmpty( $aliases, 'There is something to check.' );

		foreach ( $aliases as $alias => $id ) {

			if ( Language_Registry::NO_LANGUAGE === $id ) {
				continue;
			}

			$this->assertTrue( $registry->has( $id ), sprintf( 'The alias `%1$s` points at `%2$s`, which the registry holds.', $alias, $id ) );

		}

	}

	/**
	 * The editor asks for no font unless one is chosen.
	 *
	 * The same promise the front end makes, and it has to be kept on a screen a site
	 * owner opens far more often than they open their own posts.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_fetches_no_font_for_the_editor_unless_one_is_chosen(): void {

		$this->_reset_editor_font();

		set_current_screen( 'post' );

		try {

			Block::get_instance()->enqueue_editor_font();

			$this->assertArrayNotHasKey( 'ig-syntax-hiliter-editor-font', wp_styles()->registered );
			$this->assertSame( '', $this->_editor_font_rules() );

		} finally {
			$this->_reset_editor_font();

			set_current_screen( 'front' );
		}

	}

	/**
	 * A chosen font reaches the editor, and reaches nothing but this plugin's block.
	 *
	 * The rule sets two custom properties on the block wrapper and says nothing else,
	 * which is what keeps it away from every other block on the screen. Core fires the
	 * hook a second time while it builds the editor iframe, and the two passes share
	 * the registered style objects.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_sends_a_chosen_font_to_the_block_and_nothing_else(): void {

		$this->_reset_editor_font();

		set_current_screen( 'post' );

		Option::get_instance()->save( 'font', 'fira-code' );

		try {

			Block::get_instance()->enqueue_editor_font();
			Block::get_instance()->enqueue_editor_font();

			$style = wp_styles()->registered['ig-syntax-hiliter-editor-font'] ?? null;

			$this->assertNotNull( $style, 'The webfont stylesheet is registered for the editor.' );
			$this->assertSame( Fonts::get_font_url( 'fira-code' ), (string) $style->src );

			$rules = $this->_editor_font_rules();

			$this->assertSame(
				Fonts::get_editor_font_css( 'fira-code' ),
				$rules,
				'The rule is added once, however many times the hook fires.'
			);

			$this->assertStringStartsWith( '.wp-block-igsyntax-hiliter-code {', $rules );
			$this->assertStringContainsString( '--igsh-editor-font: "Fira Code"', $rules );

		} finally {
			$this->_reset_editor_font();

			set_current_screen( 'front' );
		}

	}

	/**
	 * The front end never loads the editor's font stylesheet.
	 *
	 * `enqueue_block_assets` fires on the front end too, and the plugin's whole rule
	 * about assets is that a page carrying no code box downloads nothing of ours.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_leaves_the_front_end_to_its_own_asset_decision(): void {

		$this->_reset_editor_font();

		Option::get_instance()->save( 'font', 'fira-code' );

		try {

			Block::get_instance()->enqueue_editor_font();

			$this->assertArrayNotHasKey( 'ig-syntax-hiliter-editor-font', wp_styles()->registered );

		} finally {
			$this->_reset_editor_font();
		}

	}

} // end of class

// EOF
