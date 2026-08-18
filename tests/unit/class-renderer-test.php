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
	 * An `Asset_Manager` built without its constructor is planted first, because
	 * `render_snippet()` signals every snippet to it and `Asset_Manager::__construct()`
	 * is where that class hooks itself up to WordPress. There is no WordPress here and
	 * there must not be: shimming `add_action()` would have answered this and would
	 * have blinded `Unit_Tier_Isolation_Test`, whose canary for a loaded WordPress is
	 * that very function. What is under test is what the renderer emits, and the
	 * signal only sets properties, so a service which never ran its constructor
	 * answers it exactly as the real one does.
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
	 * @return void
	 */
	public function test_markup_shape(): void {

		$markup = $this->renderer->render_snippet(
			new Snippet( 'echo 1;', 'php', true, 5, [ 2, 4, 5, 6 ], 'index.php' )
		);

		$this->assertSame(
			'<div class="igsh-code-box"><span class="igsh-code-box__file">index.php</span>'
			. '<pre id="ig-sh-1" class="language-php line-numbers" data-start="5" data-line-offset="4" data-line="2,4-6" data-no-optimize="1" data-cfasync="false"><code class="language-php">echo 1;</code></pre>'
			. '</div>',
			$markup
		);

	}

	/**
	 * A snippet with no file label is the bare code box it has always been.
	 *
	 * The wrapper exists to carry the label, so a snippet without one must not gain
	 * an element: every theme, and every site's own CSS, selects on what was there
	 * before.
	 *
	 * @return void
	 */
	public function test_a_snippet_without_a_file_label_is_not_wrapped(): void {

		$markup = $this->renderer->render_snippet( new Snippet( 'echo 1;', 'php' ) );

		$this->assertStringStartsWith( '<pre ', $markup );
		$this->assertStringEndsWith( '</pre>', $markup );
		$this->assertStringNotContainsString( 'igsh-code-box', $markup );

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
		$this->assertStringNotContainsString( 'data-line-offset', $markup );
		$this->assertStringNotContainsString( 'igsh-code-box', $markup );

		// The optimizer opt outs are not optional.
		$this->assertStringContainsString( 'data-no-optimize="1"', $markup );
		$this->assertStringContainsString( 'data-cfasync="false"', $markup );

	}

	/**
	 * Code is escaped exactly once, and is otherwise untouched.
	 *
	 * The exact string below is what says "once": ordinary source is encoded and not
	 * re-encoded, so the fix which makes `&amp;` in an author's text come out as
	 * `&amp;amp;` must not also turn their `<` into `&amp;amp;lt;`.
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
	 * A file label is a label, not markup, so an entity in it is shown rather than
	 * decoded — the same rule the code itself is held to.
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

		$this->assertStringContainsString(
			'<span class="igsh-code-box__file">a&quot; onload=&quot;alert(1)</span>',
			$markup
		);

		$this->assertStringNotContainsString( 'onload="alert', $markup );

	}

	/**
	 * The file label is a free text attribute and is treated as hostile. Escaping it
	 * is what makes it safe; taking the markup out of it first is the second layer,
	 * and a free text attribute printed on every page of a site is worth two.
	 *
	 * The cost is stated rather than hidden: a type parameter goes with the tags, so
	 * `vector<int>.cpp` is shown as `vector.cpp`. 5.1 did the same.
	 *
	 * @return void
	 */
	public function test_a_file_label_which_looks_like_markup_is_stripped(): void {

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
	 * A label which is nothing but markup leaves nothing to label the box with, so
	 * the box is the bare one a snippet without a label has always had. An empty
	 * element would be a wrapper and a gap above every such snippet.
	 *
	 * @return void
	 */
	public function test_a_file_label_which_is_only_markup_leaves_the_box_bare(): void {

		$markup = $this->renderer->render_snippet(
			Snippet::from_shortcode_atts(
				[
					'language' => 'php',
					'file'     => '<script>alert(1)</script>',
				],
				'x'
			)
		);

		$this->assertStringStartsWith( '<pre ', $markup );
		$this->assertStringNotContainsString( 'igsh-code-box', $markup );
		$this->assertStringNotContainsString( 'alert(1)', $markup );

	}

	/**
	 * A label is as often a path as a file name, and a path is long. Thirty
	 * characters go on the page and the whole of it goes in the tooltip, which is
	 * what 5.1 did — the tail is what is kept, because the end of a path is the part
	 * that names the file.
	 *
	 * @return void
	 */
	public function test_a_long_file_label_is_cut_and_the_whole_of_it_is_the_tooltip(): void {

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
	 * @return void
	 */
	public function test_a_label_which_fits_is_written_whole_with_no_tooltip(): void {

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
	 * @return void
	 */
	public function test_a_multibyte_label_is_not_cut_through_a_character(): void {

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
	 * `htmlspecialchars()` returns the empty string for text which is invalid in the
	 * charset it is escaping for, and `_wp_specialchars()` gives it no way to be told
	 * to substitute instead. So one stray byte anywhere in a snippet emptied the entire
	 * code box — and it emptied the file label the same way. The failure mode of an
	 * escape has to be "changed nothing", never "matched everything".
	 *
	 * @return void
	 */
	public function test_a_byte_the_charset_cannot_read_does_not_empty_the_box(): void {

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

	/**
	 * A snippet numbered from anywhere but line 1 tells the highlighter so.
	 *
	 * **`data-line-offset` is not a second spelling of `data-start`.** They are read by
	 * two different Prism plugins: the line numbers plugin reads `data-start` to label
	 * the gutter, and the line highlight plugin reads this one to learn that the
	 * numbers in `data-line` are the ones on screen rather than the code's own.
	 *
	 * Without it a range is measured against the number of lines the code physically
	 * has. A snippet of 11 lines displayed as 5 to 15 had `11-13` clamped back to
	 * `11-11`, so a three line range highlighted one line — and with line numbers
	 * switched off the same plugin takes an arithmetic branch and draws the band
	 * `first_line - 1` lines too low.
	 *
	 * This case is here because the attribute looks redundant beside `data-start` and
	 * reads like something to tidy away.
	 *
	 * @return void
	 */
	public function test_a_snippet_starting_elsewhere_tells_the_highlighter_the_offset(): void {

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
	 * It is `first_line - 1`, so it is only ever right because `first_line` cannot be
	 * below 1. `Snippet` clamps it in the constructor, which is what covers every
	 * caller — the block, the shortcode, WP-CLI, block markup written by hand, and an
	 * attribute stored by an older version. A snippet clamped back to line 1 needs
	 * neither attribute and gets neither.
	 *
	 * Asserted rather than left to a reading of the value object, because a second
	 * attribute now rests on that clamp.
	 *
	 * @return void
	 */
	public function test_a_first_line_below_one_produces_no_offset(): void {

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

}    //end of class


//EOF
