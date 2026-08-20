<?php
/**
 * Content that has never been near the block editor keeps working.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Base;
use iG\Syntax_Hiliter\Legacy_Map;
use iG\Syntax_Hiliter\Option;
use iG\Syntax_Hiliter\Renderer;
use iG\Syntax_Hiliter\Shortcode_Handler;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use WP_UnitTestCase;

/**
 * Every shipped tag, every alias and `[sourcecode]` render straight out of
 * unconverted `post_content`; comments follow the `hilite_comments` setting; and
 * excerpts strip rather than render. `[github]` has its own pipeline and its own
 * test case.
 */
class Backward_Compatibility_Test extends WP_UnitTestCase {

	use Pipeline_Test_Helpers;

	/**
	 * The shortcode handler as the plugin booted it.
	 *
	 * @var \iG\Syntax_Hiliter\Shortcode_Handler|null
	 */
	protected ?Shortcode_Handler $_original_handler = null;

	/**
	 * The options object as the plugin booted it.
	 *
	 * @var \iG\Syntax_Hiliter\Option|null
	 */
	protected ?Option $_original_option = null;

	/**
	 * Registers the pipeline once WordPress is up, and remembers what to put back.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		Shortcode_Handler::get_instance();

		$this->_original_handler = Shortcode_Handler::get_instance();
		$this->_original_option  = Option::get_instance();

	}

	/**
	 * Puts the singletons back, so a test which rewired the plugin cannot leak into
	 * the next one. WordPress' own test case restores the filters.
	 *
	 * @return void
	 */
	public function tear_down(): void {

		$this->_set_singleton( Option::class, $this->_original_option );
		$this->_set_singleton( Shortcode_Handler::class, $this->_original_handler );

		parent::tear_down();

	}

	/**
	 * Every tag the plugin has ever shipped, plus the three shorthand aliases.
	 *
	 * Read from `Legacy_Map` rather than written out here, so that the matrix
	 * follows the map.
	 *
	 * @return array
	 */
	public static function shipped_tag_provider(): array {

		$cases = [];

		foreach ( Legacy_Map::get_language_map() as $tag => $language ) {
			$cases[ $tag ] = [ $tag, $language ];
		}

		return $cases;

	}

	/**
	 * Method to rebuild the shortcode pipeline against a different option value.
	 *
	 * The handler reads `hilite_comments` once, when it registers, so the only way
	 * to exercise the other setting is to take the wiring down and put it back up.
	 *
	 * @param string $name  Option name.
	 * @param string $value Option value.
	 *
	 * @return void
	 */
	protected function _rewire_with_option( string $name, string $value ): void {

		$options          = (array) get_option( Base::PLUGIN_ID . '-options', [] );
		$options[ $name ] = $value;

		update_option( Base::PLUGIN_ID . '-options', $options );

		$filters = array_merge(
			[ 'the_content', 'comment_text' ],
			Shortcode_Handler::EXCERPT_FILTERS,
			Shortcode_Handler::SAVE_FILTERS
		);

		foreach ( $filters as $filter ) {
			remove_all_filters( $filter );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Core hooks the plugin registers on.
		}

		$this->_set_singleton( Option::class, null );
		$this->_set_singleton( Shortcode_Handler::class, null );

		Shortcode_Handler::get_instance();

	}

	/**
	 * Method to store content the way a classic editor post stores it, and hand back
	 * exactly what landed in the database.
	 *
	 * @param string $content Post content.
	 *
	 * @return string
	 */
	protected function _store( string $content ): string {

		$post_id = self::factory()->post->create(
			[
				'post_content' => wp_slash( $content ),
			]
		);

		$stored = (string) get_post_field( 'post_content', $post_id, 'raw' );

		$this->assertSame( $content, $stored, 'Storage must not touch a byte of unconverted content.' );

		return $stored;

	}

	/**
	 * Every shipped tag and alias renders from unconverted content, carrying the
	 * whole legacy surface: a script tag, HTML entities, PHP open and close tags
	 * and mixed HTML/JS.
	 *
	 * @test
	 *
	 * @dataProvider shipped_tag_provider
	 *
	 * @param string $tag      Legacy shortcode tag.
	 * @param string $language Canonical language id it should resolve to.
	 *
	 * @return void
	 */
	public function it_renders_a_shipped_tag_from_unconverted_post_content( string $tag, string $language ): void {

		$code = implode(
			"\n",
			[
				sprintf( '// marker-%s', $tag ),
				'<script src="https://example.com/x.js"></script>',  // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Author written code in a fixture, not markup this plugin emits.
				"<?php echo '<b>' . \$x . '</b>'; ?>",
				'entities: &amp; &lt; and "double" and \'single\' quotes',
			]
		);

		$stored = $this->_store( sprintf( "[%1\$s]\n%2\$s\n[/%1\$s]", $tag, $code ) );
		$output = $this->_filter( 'the_content', $stored );

		$this->assertStringContainsString( sprintf( '<code class="language-%s">', $language ), $output, sprintf( '[%s] should render as %s.', $tag, $language ) );
		$this->assertStringContainsString( Renderer::escape_verbatim( $code ), $output, sprintf( '[%s] mangled its code.', $tag ) );
		$this->assertStringNotContainsString( sprintf( '[%s]', $tag ), $output );

	}

	/**
	 * `[sourcecode]` renders from unconverted content.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_renders_the_generic_tag_from_unconverted_post_content(): void {

		$stored = $this->_store( '[sourcecode language="python" firstline="3"]print( "hi" )[/sourcecode]' );
		$output = $this->_filter( 'the_content', $stored );

		$this->assertStringContainsString( '<code class="language-python">', $output );
		$this->assertStringContainsString( 'data-start="3"', $output );
		$this->assertStringContainsString( Renderer::escape_verbatim( 'print( "hi" )' ), $output );

	}

	/**
	 * Comments have no block path, so the shortcode pipeline must run on them while
	 * `hilite_comments` is on.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_highlights_comments_while_the_option_is_on(): void {

		$this->assertSame( 'yes', Option::get_instance()->get( 'hilite_comments' ) );

		$output = $this->_filter( 'comment_text', "[php]\n\$a = 1;\n[/php]" );

		$this->assertStringContainsString( '<code class="language-php">', $output );
		$this->assertStringContainsString( '$a = 1;', $output );

	}

	/**
	 * Comments are stripped instead while `hilite_comments` is off.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_strips_comments_while_the_option_is_off(): void {

		$this->_rewire_with_option( 'hilite_comments', 'no' );

		$handler = Shortcode_Handler::get_instance();

		$this->assertSame( 2, has_filter( 'comment_text', [ $handler, 'strip' ] ) );
		$this->assertFalse( has_filter( 'comment_text', [ $handler, 'protect_display' ] ) );

		$output = $this->_filter( 'comment_text', "before [php]\n\$a = 1;\n[/php] after" );

		$this->assertStringNotContainsString( '<pre', $output );
		$this->assertStringNotContainsString( 'igsh-code-box', $output );
		$this->assertStringNotContainsString( '$a = 1;', $output );
		$this->assertStringNotContainsString( '[php]', $output );
		$this->assertStringContainsString( 'before', $output );
		$this->assertStringContainsString( 'after', $output );

	}

	/**
	 * Excerpts strip code rather than rendering it.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_strips_code_from_excerpts_rather_than_rendering_it(): void {

		foreach ( Shortcode_Handler::EXCERPT_FILTERS as $filter ) {

			$output = $this->_filter( $filter, 'before [php]$secret = 1;[/php] after' );

			$this->assertStringNotContainsString( '<pre', $output, $filter );
			$this->assertStringNotContainsString( 'igsh-code-box', $output, $filter );
			$this->assertStringNotContainsString( '$secret', $output, $filter );
			$this->assertStringNotContainsString( '[php]', $output, $filter );
			$this->assertStringContainsString( 'before', $output, $filter );
			$this->assertStringContainsString( 'after', $output, $filter );

		}

	}

	/**
	 * An escaped shortcode reaches the database with both pairs of brackets on it.
	 *
	 * The save path must never take the outer pair off. It would take one bracket per
	 * edit, and the edit after that would store what the author wrote as an example as
	 * a real snippet.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_stores_an_escaped_shortcode_byte_for_byte(): void {

		$content = '[[php]echo 1;[/php]]';

		$this->assertSame( $content, $this->_store( $content ) );

	}

	/**
	 * Editing and saving a post over and over neither eats a bracket nor turns the
	 * author's example into a snippet.
	 *
	 * Rendering between the saves is the point: what the reader is shown is not what
	 * goes back to the database.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_keeps_an_escaped_shortcode_through_repeated_edits(): void {

		$content = '[[php]echo 1;[/php]]';
		$post_id = self::factory()->post->create(
			[
				'post_content' => wp_slash( $content ),
			]
		);

		for ( $round = 1; $round <= 3; ++$round ) {

			$stored = (string) get_post_field( 'post_content', $post_id, 'raw' );

			$this->assertSame( $content, $stored, sprintf( 'Round %d changed what is stored.', $round ) );

			$output = $this->_filter( 'the_content', $stored );

			$this->assertStringContainsString( '[php]echo 1;[/php]', $output, sprintf( 'Round %d lost the text.', $round ) );
			$this->assertStringNotContainsString( '[[php]', $output, sprintf( 'Round %d showed the reader the escape.', $round ) );
			$this->assertStringNotContainsString( '[/php]]', $output, sprintf( 'Round %d showed the reader the closing escape.', $round ) );
			$this->assertStringNotContainsString( '<pre', $output, sprintf( 'Round %d rendered a code box.', $round ) );

			wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => wp_slash( $stored ),
				]
			);

		}

	}

	/**
	 * An escaped shortcode in a post with no excerpt of its own leaves the excerpt
	 * WordPress builds for it carrying neither a code box nor the code.
	 *
	 * The excerpt body is stripped at `the_content` priority 0 and protected at 1; an
	 * escape unwrapped by the first is a real shortcode to the second, so the strip
	 * takes the escape off along with the snippets.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_leaves_a_clean_automatic_excerpt_for_an_escaped_shortcode(): void {

		$post_id = self::factory()->post->create(
			[
				'post_content' => wp_slash( "Intro paragraph.\n\n[[php]\$secret = 'escaped-leak-marker';[/php]]\n\nOutro paragraph." ),
				'post_excerpt' => '',
			]
		);

		$excerpt = get_the_excerpt( $post_id );

		$this->assertStringNotContainsString( '<pre', $excerpt, 'A code box was rendered into the excerpt.' );
		$this->assertStringNotContainsString( 'igsh-code-box', $excerpt, 'A code box container was rendered into the excerpt.' );
		$this->assertStringNotContainsString( 'escaped-leak-marker', $excerpt, 'The code reached the excerpt as prose.' );
		$this->assertDoesNotMatchRegularExpression( '#\[/?php#', $excerpt, 'A piece of the shortcode reached the excerpt.' );
		$this->assertStringContainsString( 'Outro paragraph.', $excerpt, 'Prose past the escape was lost.' );

	}

} // end of class

// EOF
