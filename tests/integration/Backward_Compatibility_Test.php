<?php
/**
 * Decision 21 — content that has never been near the block editor keeps working.
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
use ReflectionProperty;
use WP_UnitTestCase;

/**
 * Every shipped tag, every alias and `[sourcecode]` render straight out of
 * unconverted `post_content`; comments follow the `hilite_comments` setting; and
 * excerpts strip rather than render. `[github]` has its own pipeline and its own
 * test case.
 */
class Backward_Compatibility_Test extends WP_UnitTestCase {

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

		Shortcode_Handler::get_instance()->register_hooks();

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
	 * Method to replace a singleton instance.
	 *
	 * @param string      $class_name Fully qualified class name.
	 * @param object|null $instance   Instance to install.
	 *
	 * @return void
	 */
	protected function _set_singleton( string $class_name, ?object $instance ): void {
		( new ReflectionProperty( $class_name, '_instance' ) )->setValue( null, $instance );
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

		Shortcode_Handler::get_instance()->register_hooks();

	}

	/**
	 * Method to run content through one of WordPress' own filters.
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
	 * AC-1 / Decision 21 — every shipped tag and alias renders from unconverted
	 * content, carrying everything AC-1 names: a script tag, HTML entities, PHP
	 * open and close tags and mixed HTML/JS.
	 *
	 * @dataProvider shipped_tag_provider
	 *
	 * @param string $tag      Legacy shortcode tag.
	 * @param string $language Canonical language id it should resolve to.
	 *
	 * @return void
	 */
	public function test_a_shipped_tag_renders_from_unconverted_post_content( string $tag, string $language ): void {

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
	 * Decision 21 — `[sourcecode]` renders from unconverted content.
	 *
	 * @return void
	 */
	public function test_the_generic_tag_renders_from_unconverted_post_content(): void {

		$stored = $this->_store( '[sourcecode language="python" firstline="3"]print( "hi" )[/sourcecode]' );
		$output = $this->_filter( 'the_content', $stored );

		$this->assertStringContainsString( '<code class="language-python">', $output );
		$this->assertStringContainsString( 'data-start="3"', $output );
		$this->assertStringContainsString( Renderer::escape_verbatim( 'print( "hi" )' ), $output );

	}

	/**
	 * Decision 21 — comments have no block path, so the shortcode pipeline must run
	 * on them while `hilite_comments` is on.
	 *
	 * @return void
	 */
	public function test_comments_are_highlighted_while_the_option_is_on(): void {

		$this->assertSame( 'yes', Option::get_instance()->get( 'hilite_comments' ) );

		$output = $this->_filter( 'comment_text', "[php]\n\$a = 1;\n[/php]" );

		$this->assertStringContainsString( '<code class="language-php">', $output );
		$this->assertStringContainsString( '$a = 1;', $output );

	}

	/**
	 * Decision 21 — and must strip instead while it is off.
	 *
	 * @return void
	 */
	public function test_comments_are_stripped_while_the_option_is_off(): void {

		$this->_rewire_with_option( 'hilite_comments', 'no' );

		$handler = Shortcode_Handler::get_instance();

		$this->assertSame( 2, has_filter( 'comment_text', [ $handler, 'strip' ] ) );
		$this->assertFalse( has_filter( 'comment_text', [ $handler, 'protect_display' ] ) );

		$output = $this->_filter( 'comment_text', "before [php]\n\$a = 1;\n[/php] after" );

		$this->assertStringNotContainsString( '<pre', $output );
		$this->assertStringNotContainsString( '$a = 1;', $output );
		$this->assertStringNotContainsString( '[php]', $output );
		$this->assertStringContainsString( 'before', $output );
		$this->assertStringContainsString( 'after', $output );

	}

	/**
	 * Decision 21 — excerpts strip code rather than rendering it.
	 *
	 * @return void
	 */
	public function test_excerpts_strip_code_rather_than_rendering_it(): void {

		foreach ( Shortcode_Handler::EXCERPT_FILTERS as $filter ) {

			$output = $this->_filter( $filter, 'before [php]$secret = 1;[/php] after' );

			$this->assertStringNotContainsString( '<pre', $output, $filter );
			$this->assertStringNotContainsString( '$secret', $output, $filter );
			$this->assertStringNotContainsString( '[php]', $output, $filter );
			$this->assertStringContainsString( 'before', $output, $filter );
			$this->assertStringContainsString( 'after', $output, $filter );

		}

	}

	/**
	 * An escaped shortcode round-trips through storage and comes out as text.
	 *
	 * @return void
	 */
	public function test_an_escaped_shortcode_survives_storage_and_renders_as_text(): void {

		$content = '[[php]echo 1;[/php]]';
		$stored  = $this->_store( $content );
		$output  = $this->_filter( 'the_content', $stored );

		$this->assertStringContainsString( $content, $output );
		$this->assertStringNotContainsString( '<pre', $output );

	}

}    //end of class


//EOF
