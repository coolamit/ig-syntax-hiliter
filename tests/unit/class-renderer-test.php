<?php
/**
 * Tests for the renderer, the plugin's single escape point.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Unit;

use iG\Syntax_Hiliter\Asset_Manager;
use iG\Syntax_Hiliter\Language_Registry;
use iG\Syntax_Hiliter\Renderer;
use iG\Syntax_Hiliter\Snippet;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

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
	 * An `Asset_Manager` built without its constructor is planted first: `render_snippet()`
	 * signals every snippet to it, and that constructor hooks into WordPress. Shimming
	 * `add_action()` would blind `Unit_Tier_Isolation_Test`.
	 *
	 * @return void
	 */
	protected function setUp(): void {

		parent::setUp();

		( new ReflectionProperty( Asset_Manager::class, '_instance' ) )->setValue(
			null,
			( new ReflectionClass( Asset_Manager::class ) )->newInstanceWithoutConstructor()
		);

		$this->renderer = new Renderer(
			new Language_Registry(
				[
					'languages' => [
						'php'        => [
							'title' => 'PHP',
							'file'  => 'prism-php.min.js',
						],
						'javascript' => [
							'title' => 'JavaScript',
							'file'  => 'prism-javascript.min.js',
						],
						'markup'     => [
							'title' => 'Markup',
							'file'  => 'prism-markup.min.js',
						],
						'ruby'       => [
							'title' => 'Ruby',
							'file'  => 'prism-ruby.min.js',
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
	 * @test
	 *
	 * @return void
	 */
	public function it_renders_the_whole_markup_shape(): void {

		$markup = $this->renderer->render_snippet(
			new Snippet( 'echo 1;', 'php', true, 5, [ 2, 4, 5, 6 ], 'index.php' )
		);

		$this->assertSame(
			'<div class="igsh-code-box" id="ig-sh-1"><span class="igsh-code-box__file">index.php</span>'
			. '<pre class="language-php line-numbers" data-start="5" data-line-offset="4" data-line="2,4-6" data-no-optimize="1" data-cfasync="false"><code class="language-php">echo 1;</code></pre>'
			. '</div>',
			$markup
		);

	}

	/**
	 * A snippet with no file label still gets its container, and no label span.
	 *
	 * The container is what a code box is; the span depends on the label.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_wraps_a_snippet_without_a_file_label_and_writes_no_label_span(): void {

		$markup = $this->renderer->render_snippet( new Snippet( 'echo 1;', 'php' ) );

		$this->assertStringStartsWith( '<div class="igsh-code-box" id="ig-sh-1">', $markup );
		$this->assertStringEndsWith( '</pre></div>', $markup );

		$this->assertStringNotContainsString( 'igsh-code-box__file', $markup );

	}

	/**
	 * Only the attributes a snippet actually needs are written.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_writes_only_the_attributes_a_snippet_needs(): void {

		$markup = $this->renderer->render_snippet( new Snippet( 'echo 1;', 'php', false ) );

		$this->assertStringNotContainsString( 'line-numbers', $markup );
		$this->assertStringNotContainsString( 'data-start', $markup );
		$this->assertStringNotContainsString( 'data-line=', $markup );
		$this->assertStringNotContainsString( 'data-line-offset', $markup );

		// The id belongs to the container, so the `pre` carries none at all.
		$this->assertSame( 1, preg_match( '#<pre class="language-php"[^>]*>#', $markup ) );
		$this->assertStringNotContainsString( '<pre id=', $markup );

		// The optimizer opt outs are not optional.
		$this->assertStringContainsString( 'data-no-optimize="1"', $markup );
		$this->assertStringContainsString( 'data-cfasync="false"', $markup );

	}

	/**
	 * Code is escaped exactly once, and is otherwise untouched.
	 *
	 * The exact string is what says "once": an author's `&amp;` comes out as
	 * `&amp;amp;`, but their `<` must not become `&amp;amp;lt;`.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_escapes_code_exactly_once(): void {

		$code   = "<?php\n\$x = '<script src=\"http://example.com/x.js\"></script>';\n// A & B\n";  // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Test data, not a script the plugin loads.
		$markup = $this->renderer->render_snippet( new Snippet( $code, 'php' ) );

		$expected = '<div class="igsh-code-box" id="ig-sh-1">'
			. '<pre class="language-php line-numbers" data-no-optimize="1" data-cfasync="false">'
			. '<code class="language-php">'
			. "&lt;?php\n\$x = &#039;&lt;script src=&quot;http://example.com/x.js&quot;&gt;&lt;/script&gt;&#039;;\n// A &amp; B\n"
			. '</code></pre></div>';

		$this->assertSame( $expected, $markup );

	}

	/**
	 * An entity the author typed is text, and stays text.
	 *
	 * The ampersand that opens an entity is encoded exactly like the one that does
	 * not; the way an entity is spelled is part of the snippet.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_never_decodes_or_respells_the_entities_the_author_typed(): void {

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
	 * @test
	 *
	 * @return void
	 */
	public function it_shows_the_reader_exactly_what_the_author_typed(): void {

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
				preg_match( '#<code[^>]*>(.*)</code></pre></div>$#s', $markup, $matches ),
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
	 * A file label is a label, not markup, so an entity in it is shown rather than
	 * decoded — the same rule the code itself is held to.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_keeps_the_entities_the_author_typed_in_a_file_label(): void {

		$markup = $this->renderer->render_snippet(
			Snippet::from_shortcode_atts(
				[
					'language' => 'php',
					'file'     => 'a&amp;b &lt;c&gt;.php',
				],
				'x'
			)
		);

		$this->assertStringContainsString(
			'<span class="igsh-code-box__file">a&amp;amp;b &amp;lt;c&amp;gt;.php</span>',
			$markup
		);

	}

	/**
	 * A language the registry cannot confirm falls back rather than being written
	 * out. The class goes on the `<pre>` as well as the `<code>`, because every
	 * bundled theme selects on `pre[class*="language-"]` and without it a plain box
	 * is never painted.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_falls_back_for_an_unknown_language(): void {

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

			if ( empty( $language ) || Language_Registry::NO_LANGUAGE === $language ) {
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
	 * registry's own aliases — each case insensitive and whitespace tolerant.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_resolves_a_language_through_all_three_stages(): void {

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
	 * @test
	 *
	 * @return void
	 */
	public function it_falls_back_for_a_mapped_language_missing_from_the_registry(): void {

		// The fixture registry has no `apacheconf`, which is what `apache` maps to.
		$this->assertSame( 'none', $this->renderer->resolve_language( 'apache' ) );

	}

	/**
	 * The same snippet rendered twice gets two different DOM ids.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_gives_repeated_snippets_unique_ids(): void {

		$snippet = new Snippet( 'echo 1;', 'php' );

		$first  = $this->renderer->render_snippet( $snippet );
		$second = $this->renderer->render_snippet( $snippet );
		$third  = $this->renderer->render_snippet( new Snippet( 'echo 2;', 'php' ) );

		// Anchored on the container: `assertStringContainsString( 'id="ig-sh-1"' )` passes whichever element carries the id.
		$this->assertStringStartsWith( '<div class="igsh-code-box" id="ig-sh-1">', $first );
		$this->assertStringStartsWith( '<div class="igsh-code-box" id="ig-sh-2">', $second );
		$this->assertStringStartsWith( '<div class="igsh-code-box" id="ig-sh-3">', $third );

		// And nowhere else: the `pre` has no id of its own.
		$this->assertStringNotContainsString( '<pre id=', $first );

	}

	/**
	 * A file label is escaped like everything else, and cannot break out.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_escapes_a_file_label(): void {

		$markup = $this->renderer->render_snippet(
			Snippet::from_shortcode_atts(
				[
					'language' => 'php',
					'file'     => 'a" onload="alert(1)',
				],
				'x'
			)
		);

		$this->assertStringContainsString(
			'<span class="igsh-code-box__file">a&quot; onload=&quot;alert(1)</span>',
			$markup
		);

		$this->assertStringNotContainsString( 'onload="alert', $markup );

	}

	/**
	 * The file label is free text and is treated as hostile: escaping makes it safe,
	 * and stripping the markup first is a second layer. The cost is that a type
	 * parameter goes with the tags, so `vector<int>.cpp` is shown as `vector.cpp`.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_strips_a_file_label_which_looks_like_markup(): void {

		$markup = $this->renderer->render_snippet(
			Snippet::from_shortcode_atts(
				[
					'language' => 'php',
					'file'     => '<b>vector<int>.cpp</b>',
				],
				'x'
			)
		);

		$this->assertStringContainsString(
			'<span class="igsh-code-box__file">vector.cpp</span>',
			$markup
		);

	}

	/**
	 * A label which is nothing but markup gets no span — an empty one would draw a
	 * gap above the box — but the container is still there.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_writes_no_label_span_for_a_file_label_which_is_only_markup(): void {

		$markup = $this->renderer->render_snippet(
			Snippet::from_shortcode_atts(
				[
					'language' => 'php',
					'file'     => '<script>alert(1)</script>',
				],
				'x'
			)
		);

		$this->assertStringStartsWith( '<div class="igsh-code-box" id="ig-sh-1">', $markup );

		$this->assertStringNotContainsString( 'igsh-code-box__file', $markup );
		$this->assertStringNotContainsString( 'alert(1)', $markup );

	}

	/**
	 * Thirty characters go on the page and the whole label goes in the tooltip; the
	 * tail is kept, because the end of a path is the file name.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_cuts_a_long_file_label_and_makes_the_whole_of_it_the_tooltip(): void {

		$label = 'aaaaaaaaaa/bbbbbbbbbb/cccccccccc/dd.php';

		$this->assertSame( 39, strlen( $label ), 'The fixture is not longer than the label length.' );

		$markup = $this->renderer->render_snippet( new Snippet( 'x', 'php', true, 1, [], $label ) );

		$this->assertStringContainsString(
			sprintf(
				'<span class="igsh-code-box__file" title="%s">…/bbbbbbbbbb/cccccccccc/dd.php</span>',
				$label
			),
			$markup
		);

	}

	/**
	 * A label that fits is written whole and gets no tooltip. A tooltip repeating
	 * what is already on screen teaches a reader that hovering it is pointless.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_writes_a_label_which_fits_whole_with_no_tooltip(): void {

		$label = str_repeat( 'a', 26 ) . '.php';

		$this->assertSame( 30, strlen( $label ), 'The fixture is not exactly the label length.' );

		$markup = $this->renderer->render_snippet( new Snippet( 'x', 'php', true, 1, [], $label ) );

		$this->assertStringContainsString(
			sprintf( '<span class="igsh-code-box__file">%s</span>', $label ),
			$markup
		);

		$this->assertStringNotContainsString( 'title=', $markup );

	}

	/**
	 * The cut counts characters and not bytes. Cutting a UTF-8 label by bytes ends it
	 * on half a character, which the browser draws as a replacement glyph.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_does_not_cut_a_multibyte_label_through_a_character(): void {

		$label = str_repeat( 'é', 35 );

		$markup = $this->renderer->render_snippet( new Snippet( 'x', 'php', true, 1, [], $label ) );

		$this->assertStringContainsString(
			sprintf( '>…%s</span>', str_repeat( 'é', 29 ) ),
			$markup
		);

		$this->assertSame(
			1,
			preg_match( '#<span class="igsh-code-box__file"[^>]*>(.*?)</span>#u', $markup ),
			'The label element holds a byte sequence UTF-8 cannot read.'
		);

	}

	/**
	 * A byte the site charset cannot read costs that byte, not the whole snippet.
	 *
	 * `htmlspecialchars()` returns the empty string for text invalid in its charset,
	 * so one stray byte would otherwise empty the whole code box and the file label.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_does_not_empty_the_box_for_a_byte_the_charset_cannot_read(): void {

		// Valid ISO-8859-1, invalid UTF-8: the shape a pre-4.2 latin1 column still holds.
		$code = "\xA9 " . 'if ( $a < $b ) { echo "x"; }';

		$markup = $this->renderer->render_snippet( new Snippet( $code, 'php', true, 1, [], "caf\xE9 & co.php" ) );

		$this->assertSame(
			1,
			preg_match( '#<code[^>]*>(.*)</code></pre>#s', $markup, $matches ),
			'The renderer emitted no code element.'
		);

		$this->assertNotSame( '', $matches[1], 'One unreadable byte emptied the whole code box.' );

		// Everything either side of the bad byte is escaped exactly as it always was.
		$this->assertStringContainsString( '&lt; $b ) { echo &quot;x&quot;; }', $matches[1] );

		$this->assertMatchesRegularExpression(
			'#<span class="igsh-code-box__file">[^<]+ &amp; co\.php</span>#',
			$markup,
			'The label was lost with the byte.'
		);

	}

	/**
	 * Line numbers are squeezed back into the range notation they came from.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_compacts_the_line_ranges(): void {

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
	 * @test
	 *
	 * @return void
	 */
	public function it_renders_the_same_on_the_shortcode_and_block_paths(): void {

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

	/**
	 * A snippet numbered from anywhere but line 1 tells the highlighter so.
	 *
	 * `data-line-offset` is not a second spelling of `data-start`: the line numbers
	 * plugin reads `data-start` for the gutter, the line highlight plugin reads the
	 * offset to map `data-line` onto the code. Without it an 11-line snippet shown as
	 * 5 to 15 has `11-13` clamped to `11-11`.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_tells_the_highlighter_the_offset_for_a_snippet_starting_elsewhere(): void {

		$markup = $this->renderer->render_snippet(
			new Snippet( 'echo 1;', 'php', true, 5, [ 11, 12, 13 ] )
		);

		$this->assertStringContainsString( 'data-start="5"', $markup );
		$this->assertStringContainsString( 'data-line-offset="4"', $markup );
		$this->assertStringContainsString( 'data-line="11-13"', $markup );

	}

	/**
	 * The offset is never zero and never negative.
	 *
	 * It is `first_line - 1`, so it rests on `Snippet` clamping `first_line` to 1 in
	 * its constructor. A snippet clamped back to line 1 gets neither attribute.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_produces_no_offset_for_a_first_line_below_one(): void {

		foreach ( [ 0, -7 ] as $first_line ) {

			$markup = $this->renderer->render_snippet(
				new Snippet( 'echo 1;', 'php', true, $first_line, [ 2 ] )
			);

			$this->assertStringNotContainsString(
				'data-line-offset',
				$markup,
				sprintf( 'A first line of %d is clamped to 1, so there is no offset to state.', $first_line )
			);

			$this->assertStringNotContainsString( 'data-start', $markup );

		}

	}

} // end of class

// EOF
