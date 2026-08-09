<?php
/**
 * Tests for the renderer, the plugin's single escape point.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Unit;

use iG\Syntax_Hiliter\Language_Registry;
use iG\Syntax_Hiliter\Renderer;
use iG\Syntax_Hiliter\Snippet;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/wp-shims.php';

/**
 * Everything the renderer emits, and everything it refuses to emit.
 */
class Renderer_Test extends TestCase {

	/**
	 * Renderer under test.
	 *
	 * @var \iG\Syntax_Hiliter\Renderer
	 */
	protected Renderer $renderer;

	/**
	 * Build a renderer over a small, known registry.
	 *
	 * @return void
	 */
	protected function setUp(): void {

		parent::setUp();

		$this->renderer = new Renderer(
			new Language_Registry(
				[
					'languages' => [
						'php'        => [
							'title'  => 'PHP',
							'file'   => 'prism-php.min.js',
							'dropin' => false,
						],
						'javascript' => [
							'title'  => 'JavaScript',
							'file'   => 'prism-javascript.min.js',
							'dropin' => false,
						],
						'markup'     => [
							'title'  => 'Markup',
							'file'   => 'prism-markup.min.js',
							'dropin' => false,
						],
						'ruby'       => [
							'title'  => 'Ruby',
							'file'   => 'prism-ruby.min.js',
							'dropin' => false,
						],
					],
					'aliases'   => [
						'js'   => 'javascript',
						'html' => 'markup',
					],
				]
			)
		);

	}

	/**
	 * The shape of the markup, in full.
	 *
	 * @return void
	 */
	public function test_markup_shape(): void {

		$markup = $this->renderer->render_snippet(
			new Snippet( 'echo 1;', 'php', true, 5, [ 2, 4, 5, 6 ], 'index.php' )
		);

		$this->assertSame(
			'<pre id="ig-sh-1" class="language-php line-numbers" data-start="5" data-line="2,4-6" data-file="index.php" data-no-optimize="1" data-cfasync="false"><code class="language-php">echo 1;</code></pre>',
			$markup
		);

	}

	/**
	 * Only the attributes a snippet actually needs are written.
	 *
	 * @return void
	 */
	public function test_optional_attributes_are_omitted(): void {

		$markup = $this->renderer->render_snippet( new Snippet( 'echo 1;', 'php', false ) );

		$this->assertStringNotContainsString( 'line-numbers', $markup );
		$this->assertStringNotContainsString( 'data-start', $markup );
		$this->assertStringNotContainsString( 'data-line=', $markup );
		$this->assertStringNotContainsString( 'data-file', $markup );

		// The optimizer opt outs are not optional.
		$this->assertStringContainsString( 'data-no-optimize="1"', $markup );
		$this->assertStringContainsString( 'data-cfasync="false"', $markup );

	}

	/**
	 * A first line of 1 is the default and needs no attribute.
	 *
	 * @return void
	 */
	public function test_first_line_attribute(): void {

		$this->assertStringNotContainsString(
			'data-start',
			$this->renderer->render_snippet( new Snippet( 'x', 'php', true, 1 ) )
		);

		$this->assertStringContainsString(
			'data-start="2"',
			$this->renderer->render_snippet( new Snippet( 'x', 'php', true, 2 ) )
		);

	}

	/**
	 * Code is escaped exactly once, and is otherwise untouched.
	 *
	 * @return void
	 */
	public function test_code_is_escaped_exactly_once(): void {

		$code   = "<?php\n\$x = '<script src=\"http://example.com/x.js\"></script>';\n// A & B\n";  // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Test data, not a script the plugin loads.
		$markup = $this->renderer->render_snippet( new Snippet( $code, 'php' ) );

		$expected = '<pre id="ig-sh-1" class="language-php line-numbers" data-no-optimize="1" data-cfasync="false">'
			. '<code class="language-php">'
			. "&lt;?php\n\$x = &#039;&lt;script src=&quot;http://example.com/x.js&quot;&gt;&lt;/script&gt;&#039;;\n// A &amp; B\n"
			. '</code></pre>';

		$this->assertSame( $expected, $markup );

		// One decode gets the author's bytes back, which is what "once" means.
		$this->assertSame(
			$code,
			html_entity_decode(
				(string) preg_replace( '#^.*?<code[^>]*>(.*)</code></pre>$#s', '$1', $markup ),
				ENT_QUOTES,
				'UTF-8'
			)
		);

	}

	/**
	 * Code which already holds entities is not encoded a second time.
	 *
	 * @return void
	 */
	public function test_existing_entities_are_not_double_encoded(): void {

		$markup = $this->renderer->render_snippet( new Snippet( '&amp; &lt;', 'php' ) );

		$this->assertStringContainsString( '<code class="language-php">&amp; &lt;</code>', $markup );

	}

	/**
	 * A language the registry cannot confirm falls back rather than being written out.
	 *
	 * @return void
	 */
	public function test_unknown_language_falls_back(): void {

		foreach ( [ 'madeuplang', '', 'none', 'typescript' ] as $language ) {

			$markup = $this->renderer->render_snippet( new Snippet( 'x', $language ) );

			$this->assertStringContainsString(
				'<code class="language-none">',
				$markup,
				sprintf( 'Language "%s" should have fallen back.', $language )
			);

			if ( '' === $language || Language_Registry::NO_LANGUAGE === $language ) {
				continue;
			}

			$this->assertStringNotContainsString(
				sprintf( 'language-%s', $language ),
				$markup,
				sprintf( 'Language "%s" must not reach the markup.', $language )
			);

		}

	}

	/**
	 * The tags which never highlighted anything in v5 still do not.
	 *
	 * @return void
	 */
	public function test_plain_languages_fall_back(): void {

		$this->assertStringContainsString(
			'<code class="language-none">',
			$this->renderer->render_snippet( new Snippet( 'x', 'code' ) )
		);

		$this->assertStringContainsString(
			'<code class="language-none">',
			$this->renderer->render_snippet( new Snippet( 'x', 'text' ) )
		);

	}

	/**
	 * Legacy language names are translated, and aliases resolve.
	 *
	 * @return void
	 */
	public function test_language_resolution(): void {

		$expected = [
			'php'         => 'php',
			'PHP'         => 'php',
			'  php  '     => 'php',
			'js'          => 'javascript',
			'jquery'      => 'javascript',
			'html'        => 'markup',
			'html4strict' => 'markup',
			'html5'       => 'markup',
			'xml'         => 'markup',
			'rails'       => 'ruby',
			'madeuplang'  => 'none',
		];

		foreach ( $expected as $typed => $canonical ) {
			$this->assertSame( $canonical, $this->renderer->resolve_language( $typed ) );
		}

	}

	/**
	 * A language the legacy map knows but the registry does not still falls back.
	 *
	 * @return void
	 */
	public function test_mapped_language_missing_from_the_registry_falls_back(): void {

		// The fixture registry has no `apacheconf`, which is what `apache` maps to.
		$this->assertSame( 'none', $this->renderer->resolve_language( 'apache' ) );

	}

	/**
	 * The same snippet rendered twice gets two different DOM ids.
	 *
	 * @return void
	 */
	public function test_repeated_snippets_get_unique_ids(): void {

		$snippet = new Snippet( 'echo 1;', 'php' );

		$first  = $this->renderer->render_snippet( $snippet );
		$second = $this->renderer->render_snippet( $snippet );
		$third  = $this->renderer->render_snippet( new Snippet( 'echo 2;', 'php' ) );

		$this->assertStringContainsString( 'id="ig-sh-1"', $first );
		$this->assertStringContainsString( 'id="ig-sh-2"', $second );
		$this->assertStringContainsString( 'id="ig-sh-3"', $third );

	}

	/**
	 * A file label is escaped like everything else, and cannot break out.
	 *
	 * @return void
	 */
	public function test_file_label_is_escaped(): void {

		$markup = $this->renderer->render_snippet(
			Snippet::from_shortcode_atts(
				[
					'language' => 'php',
					'file'     => 'a" onload="alert(1)',
				],
				'x'
			)
		);

		$this->assertStringContainsString( 'data-file="a&quot; onload=&quot;alert(1)"', $markup );
		$this->assertStringNotContainsString( 'onload="alert', $markup );

	}

	/**
	 * The attributes v6 no longer acts on never reach the markup.
	 *
	 * @return void
	 */
	public function test_retired_attributes_do_not_leak(): void {

		$markup = $this->renderer->render_snippet(
			Snippet::from_shortcode_atts(
				[
					'language'    => 'php',
					'plaintext'   => 'yes',
					'toolbar'     => 'no',
					'strict_mode' => 'always',
					'made_up'     => 'leaky',
				],
				'echo 1;'
			)
		);

		foreach ( [ 'plaintext', 'toolbar', 'strict_mode', 'made_up', 'leaky' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $markup );
		}

	}

	/**
	 * Line numbers are squeezed back into the range notation they came from.
	 *
	 * @return void
	 */
	public function test_line_ranges_are_compacted(): void {

		$this->assertSame( '', Renderer::compact_line_ranges( [] ) );
		$this->assertSame( '3', Renderer::compact_line_ranges( [ 3 ] ) );
		$this->assertSame( '2,4-6', Renderer::compact_line_ranges( [ 2, 4, 5, 6 ] ) );
		$this->assertSame( '1-3', Renderer::compact_line_ranges( [ 3, 1, 2 ] ) );
		$this->assertSame( '1,3,5', Renderer::compact_line_ranges( [ 1, 3, 5 ] ) );
		$this->assertSame( '1-2,9-10', Renderer::compact_line_ranges( [ 1, 2, 9, 10 ] ) );

	}

	/**
	 * A shortcode and a block describing the same snippet render identically.
	 *
	 * @return void
	 */
	public function test_shortcode_and_block_paths_agree(): void {

		$from_shortcode = $this->renderer->render_snippet(
			Snippet::from_shortcode_atts(
				[
					'language'  => 'js',
					'firstline' => '4',
					'highlight' => '2,4-6',
					'file'      => 'app.js',
				],
				'let x = 1;'
			)
		);

		$this->renderer->reset_counter();

		$from_block = $this->renderer->render_snippet(
			Snippet::from_block_attributes(
				[
					'code'           => 'let x = 1;',
					'language'       => 'js',
					'firstLine'      => 4,
					'highlightLines' => '2,4-6',
					'file'           => 'app.js',
				]
			)
		);

		$this->assertSame( $from_shortcode, $from_block );

	}

}    //end of class


//EOF
