<?php
/**
 * A post written any time since 2004 renders unmangled.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Legacy_Map;
use iG\Syntax_Hiliter\Renderer;
use iG\Syntax_Hiliter\Shortcode_Handler;
use WP_UnitTestCase;

/**
 * Renders a fixture post carrying the whole legacy payload — a script tag, entities,
 * PHP tags, mixed HTML/JS — through the display chain, and checks the code comes
 * back byte for byte, escaped exactly once.
 *
 * The tag matrix itself is `Backward_Compatibility_Test`'s: every shipped tag and
 * alias runs through a data provider there. What is checked here is the contexts a
 * post is displayed in, and the attribute grammar which has to survive them.
 */
class Legacy_Content_Test extends WP_UnitTestCase {

	/**
	 * Registers the pipeline once WordPress is up.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		Shortcode_Handler::get_instance();

	}

	/**
	 * Method to build the code every snippet in the fixture carries.
	 *
	 * One payload for every snippet, so a single expected string covers the whole
	 * fixture. It holds the whole legacy surface: a script tag, HTML entities, PHP open
	 * and close tags, mixed HTML/JS, both kinds of quote, a bare URL, and a line
	 * which looks like another plugin's shortcode.
	 *
	 * @return string
	 */
	protected static function _payload(): string {

		return implode(
			"\n",
			[
				'<script src="https://example.com/x.js"></script>',  // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Author written code in a fixture, not markup this plugin emits.
				"<?php echo '<div class=\"a\">' . \$x . '</div>'; ?>",
				'// entities: &amp; &lt; &#039; and "double" and \'single\' quotes',
				'if ( a < b && c > d ) { alert( "hi" ); }',
				'[gallery id="1" size="full"]',
				'https://example.com/plain-url',
			]
		);

	}

	/**
	 * Method to build the fixture, one entry per snippet, in the order they appear.
	 *
	 * One tag of each shape rather than all of them: a named language tag, a
	 * shorthand alias, and the generic tag both with the canonical attribute names
	 * and with the legacy spellings of them.
	 *
	 * @return array List of arrays with `shortcode` and `language` keys.
	 */
	protected static function _fixture(): array {

		$payload = static::_payload();
		$generic = Legacy_Map::GENERIC_TAG;

		return [
			[
				'shortcode' => sprintf( "[php]\n%s\n[/php]", $payload ),
				'language'  => 'php',
			],
			[
				'shortcode' => sprintf( "[js]\n%s\n[/js]", $payload ),
				'language'  => 'javascript',
			],
			[
				'shortcode' => sprintf( "[%1\$s language=\"css\" highlight=\"2,4-6\" file=\"x.php\" gutter=\"no\" firstline=\"10\"]\n%2\$s\n[/%1\$s]", $generic, $payload ),
				'language'  => 'css',
			],
			[
				'shortcode' => sprintf( "[%1\$s lang=\"ruby\" num=\"7\"]\n%2\$s\n[/%1\$s]", $generic, $payload ),
				'language'  => 'ruby',
			],
		];

	}

	/**
	 * Method to build the fixture's post content.
	 *
	 * @return string
	 */
	protected static function _fixture_content(): string {

		$paragraphs = [ 'Opening paragraph of a very old post.' ];

		foreach ( static::_fixture() as $snippet ) {
			$paragraphs[] = $snippet['shortcode'];
		}

		$paragraphs[] = 'Closing paragraph of a very old post.';

		return implode( "\n\n", $paragraphs );

	}

	/**
	 * Method to create the fixture post.
	 *
	 * @param string $excerpt Optional manual excerpt.
	 *
	 * @return int Post id.
	 */
	protected function _create_fixture_post( string $excerpt = '' ): int {

		return self::factory()->post->create(
			[
				'post_title'   => 'Legacy fixture',
				'post_content' => wp_slash( static::_fixture_content() ),
				'post_excerpt' => wp_slash( $excerpt ),
			]
		);

	}

	/**
	 * Method to pull every rendered code box out of some markup.
	 *
	 * @param string $html Rendered markup.
	 *
	 * @return array List of arrays with `language` and `code` keys, in document order.
	 */
	protected function _code_boxes( string $html ): array {

		preg_match_all( '#<pre [^>]*><code class="language-([^"]*)">(.*?)</code></pre>#s', $html, $matches, PREG_SET_ORDER );

		$boxes = [];

		foreach ( $matches as $match ) {
			$boxes[] = [
				'language' => $match[1],
				'code'     => $match[2],
			];
		}

		return $boxes;

	}

	/**
	 * Method to render a post the way a single post view renders it.
	 *
	 * @param int $post_id Post id.
	 *
	 * @return string
	 */
	protected function _render_single( int $post_id ): string {

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		ob_start();
		the_content();

		return (string) ob_get_clean();

	}

	/**
	 * Method to assert that some markup carries the whole fixture, intact.
	 *
	 * @param string $output  Rendered markup.
	 * @param string $context Name of the context being rendered, for failure messages.
	 *
	 * @return void
	 */
	protected function _assert_fixture_rendered( string $output, string $context ): void {

		$fixture  = static::_fixture();
		$boxes    = $this->_code_boxes( $output );
		$expected = Renderer::escape_verbatim( static::_payload() );

		$this->assertCount( count( $fixture ), $boxes, sprintf( '%s: one code box per snippet.', $context ) );

		foreach ( $fixture as $index => $snippet ) {

			$this->assertSame(
				$snippet['language'],
				$boxes[ $index ]['language'],
				sprintf( '%s: snippet %d (%s) should be highlighted as %s.', $context, $index, substr( $snippet['shortcode'], 0, 20 ), $snippet['language'] )
			);

			$this->assertSame(
				$expected,
				$boxes[ $index ]['code'],
				sprintf( '%s: snippet %d (%s) came back changed.', $context, $index, substr( $snippet['shortcode'], 0, 20 ) )
			);

		}

		$this->assertStringContainsString( 'Opening paragraph of a very old post.', $output, $context );
		$this->assertStringContainsString( 'Closing paragraph of a very old post.', $output, $context );
		$this->assertStringNotContainsString( '[/php]', $output, sprintf( '%s: no shortcode should survive as text.', $context ) );

	}

	/**
	 * The legacy surface renders in a single post view, escaped once.
	 *
	 * @return void
	 */
	public function test_the_legacy_surface_renders_in_a_single_post_view(): void {

		$output = $this->_render_single( $this->_create_fixture_post() );

		$this->_assert_fixture_rendered( $output, 'single post view' );

		// An entity the author typed is text, and is shown to the reader as the text it is.
		$this->assertStringContainsString( 'entities: &amp;amp; &amp;lt; &amp;#039;', $output );

		// A character the author typed is encoded once, and once only.
		$this->assertStringContainsString( '&lt;script src=', $output );
		$this->assertStringNotContainsString( '&amp;lt;script src=', $output );

	}

	/**
	 * An archive loop is wired to the same pipeline.
	 *
	 * @return void
	 */
	public function test_a_snippet_renders_in_an_archive_loop(): void {

		self::factory()->post->create(
			[
				'post_content' => wp_slash( sprintf( "[php]\n%s\n[/php]", static::_payload() ) ),
			]
		);

		$this->go_to( home_url( '/' ) );

		$this->assertTrue( is_home(), 'The archive context is what is being rendered here.' );

		$output = '';

		while ( have_posts() ) {

			the_post();

			ob_start();
			the_content();

			$output .= (string) ob_get_clean();

		}

		$this->assertStringContainsString( '<code class="language-php">', $output );
		$this->assertStringContainsString( Renderer::escape_verbatim( static::_payload() ), $output );

	}

	/**
	 * The RSS feed is wired to it too, where a snippet is code rather than markup
	 * the reader executes.
	 *
	 * @return void
	 */
	public function test_the_feed_renders_a_snippet_as_code_not_markup(): void {

		$post_id = self::factory()->post->create(
			[
				'post_content' => wp_slash( sprintf( "[php]\n%s\n[/php]", static::_payload() ) ),
			]
		);

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$feed = get_the_content_feed( 'rss2' );

		$this->assertStringContainsString( '<code class="language-php">', $feed );
		$this->assertStringContainsString( Renderer::escape_verbatim( static::_payload() ), $feed );

		$this->assertStringNotContainsString( '<script src="https://example.com/x.js">', $feed );  // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Asserting the fixture's code is NOT emitted as markup.
		$this->assertStringNotContainsString( '<?php', $feed );

	}

	/**
	 * The attribute grammar survives the display chain.
	 *
	 * @return void
	 */
	public function test_the_attribute_grammar_survives_the_display_chain(): void {

		$output = $this->_render_single( $this->_create_fixture_post() );

		$this->assertStringContainsString( 'data-line="2,4-6"', $output );
		$this->assertStringContainsString( '<span class="igsh-code-box__file">x.php</span>', $output );
		$this->assertStringContainsString( 'data-start="10"', $output );
		$this->assertStringContainsString( 'data-start="7"', $output );

		// `gutter="no"` is the only snippet in the fixture without line numbers.
		preg_match_all( '#<pre [^>]*class="([^"]*)"#', $output, $matches );

		$without_line_numbers = array_filter(
			$matches[1],
			static function ( string $classes ): bool {
				return ( ! str_contains( $classes, 'line-numbers' ) );
			}
		);

		$this->assertCount( 1, $without_line_numbers );
		$this->assertSame( [ 'language-css' ], array_values( $without_line_numbers ) );

	}

	/**
	 * A manual excerpt carries no code, and no leftover shortcode either.
	 *
	 * @return void
	 */
	public function test_a_manual_excerpt_carries_no_code(): void {

		$post_id = $this->_create_fixture_post( 'Summary with [php]$secret = 1;[/php] in it.' );
		$excerpt = get_the_excerpt( $post_id );

		$this->assertStringContainsString( 'Summary with', $excerpt );
		$this->assertStringNotContainsString( '$secret', $excerpt );
		$this->assertStringNotContainsString( '[php]', $excerpt );
		$this->assertStringNotContainsString( '<pre', $excerpt );

	}

	/**
	 * An automatic excerpt carries no code either.
	 *
	 * @return void
	 */
	public function test_an_automatic_excerpt_carries_no_code(): void {

		$post_id = self::factory()->post->create(
			[
				'post_content' => "Intro.\n\n[php]\n\$secret = 'leaked-code-marker';\n[/php]\n\nOutro.",
				'post_excerpt' => '',
			]
		);

		$this->assertStringNotContainsString( 'leaked-code-marker', get_the_excerpt( $post_id ) );

	}

}    //end of class


//EOF
