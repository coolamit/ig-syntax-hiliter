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
	 * I1 — code is escaped exactly once, and is otherwise untouched.
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
	 * An entity the author typed is text, and stays text.
	 *
	 * A highlighter which decodes what it was given shows something the author did
	 * not write, so the ampersand that opens an entity is encoded exactly like the
	 * one that does not. Byte for byte, because the way an entity is spelled is part
	 * of the snippet.
	 *
	 * @return void
	 */
	public function test_entities_the_author_typed_are_never_decoded_or_respelled(): void {

		$cases = [
			'&amp;lt;b&amp;gt;'       => '&amp;amp;lt;b&amp;amp;gt;',
			'&lt;b&gt;bold&lt;/b&gt;' => '&amp;lt;b&amp;gt;bold&amp;lt;/b&amp;gt;',
			'a&nbsp;b'                => 'a&amp;nbsp;b',
			'&#60;script&#62;'        => '&amp;#60;script&amp;#62;',
			'&lt;b&gt; & "x"'         => '&amp;lt;b&amp;gt; &amp; &quot;x&quot;',
		];

		foreach ( $cases as $typed => $expected ) {

			$this->renderer->reset_counter();

			$this->assertStringContainsString(
				sprintf( '<code class="language-php">%s</code>', $expected ),
				$this->renderer->render_snippet( new Snippet( (string) $typed, 'php' ) ),
				sprintf( '"%s" was not left as the author typed it.', $typed )
			);

		}

	}

	/**
	 * The round trip, stated once: whatever the author typed, one decode of what the
	 * reader is served gives their bytes back. That is the property the escaping
	 * exists for, and it holds down both paths into the renderer.
	 *
	 * @return void
	 */
	public function test_the_reader_sees_exactly_what_the_author_typed(): void {

		$code = "&amp; &lt;b&gt; &nbsp; &#60; & < > \" ' <b>bold</b>\n\$x = 'a' . \"b\";";

		$paths = [
			'shortcode' => Snippet::from_shortcode_atts( [ 'language' => 'php' ], $code ),
			'block'     => Snippet::from_block_attributes(
				[
					'code'     => $code,
					'language' => 'php',
				]
			),
		];

		foreach ( $paths as $path => $snippet ) {

			$this->renderer->reset_counter();

			$markup = $this->renderer->render_snippet( $snippet );

			$this->assertSame(
				1,
				preg_match( '#<code[^>]*>(.*)</code></pre>$#s', $markup, $matches ),
				sprintf( 'The %s path rendered no code element.', $path )
			);

			$this->assertSame(
				$code,
				html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ),
				sprintf( 'The %s path did not give the author back their bytes.', $path )
			);

			// Nothing the author typed reaches the reader as markup of its own.
			$this->assertStringNotContainsString( '<b>', $matches[1], sprintf( 'The %s path let a tag through.', $path ) );

		}

	}

	/**
	 * Ordinary code is encoded once and once only — the fix for the entities above
	 * must not turn `<` into `&amp;lt;`.
	 *
	 * @return void
	 */
	public function test_ordinary_code_is_not_double_escaped(): void {

		$markup = $this->renderer->render_snippet( new Snippet( 'if ( $a < $b && $c > $d )', 'php' ) );

		$this->assertStringContainsString(
			'<code class="language-php">if ( $a &lt; $b &amp;&amp; $c &gt; $d )</code>',
			$markup
		);

	}

	/**
	 * A file label is a label, not markup, so an entity in it is shown rather than
	 * decoded. The themes paint it out of the attribute, which the browser decodes
	 * once on the way.
	 *
	 * @return void
	 */
	public function test_a_file_label_keeps_the_entities_the_author_typed(): void {

		$markup = $this->renderer->render_snippet(
			Snippet::from_shortcode_atts(
				[
					'language' => 'php',
					'file'     => 'a&amp;b &lt;c&gt;.php',
				],
				'x'
			)
		);

		$this->assertStringContainsString( 'data-file="a&amp;amp;b &amp;lt;c&amp;gt;.php"', $markup );

	}

	/**
	 * AC-6 / I4 — a language the registry cannot confirm falls back rather than
	 * being written out. The class goes on the `<pre>` as well as the `<code>`,
	 * because every bundled theme selects on `pre[class*="language-"]` and without
	 * it a plain box is never painted.
	 *
	 * @return void
	 */
	public function test_unknown_language_falls_back(): void {

		foreach ( [ 'madeuplang', '', 'none', 'typescript', 'code', 'text' ] as $language ) {

			$markup = $this->renderer->render_snippet( new Snippet( 'x', $language ) );

			$this->assertStringContainsString(
				'<code class="language-none">',
				$markup,
				sprintf( 'Language "%s" should have fallen back.', $language )
			);

			$this->assertMatchesRegularExpression(
				'#<pre [^>]*class="language-none[^"]*"#',
				$markup,
				sprintf( 'Language "%s" should have left the box styled.', $language )
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
	 * The three stages of resolution: the name as typed, the legacy map, and the
	 * registry's own aliases — each case insensitive and whitespace tolerant. The
	 * legacy map's own table belongs to `Legacy_Map_Test`.
	 *
	 * @return void
	 */
	public function test_language_resolution(): void {

		$expected = [
			'php'         => 'php',
			'PHP'         => 'php',
			'  php  '     => 'php',
			'html4strict' => 'markup',
			'js'          => 'javascript',
			'markup'      => 'markup',
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
