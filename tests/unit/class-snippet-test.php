<?php
/**
 * Tests for the snippet value object and the legacy attribute grammar.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Unit;

use iG\Syntax_Hiliter\Snippet;
use PHPUnit\Framework\TestCase;

/**
 * The legacy shortcode attribute grammar, and the value object it builds.
 */
class Snippet_Test extends TestCase {

	/**
	 * With no attributes at all, the defaults apply.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_applies_the_v5_defaults_when_no_attributes_are_given(): void {

		$snippet = Snippet::from_shortcode_atts( [], 'echo 1;' );

		$this->assertSame( 'echo 1;', $snippet->code );
		$this->assertSame( 'code', $snippet->language );
		$this->assertSame( 1, $snippet->first_line );
		$this->assertSame( [], $snippet->highlight_lines );
		$this->assertSame( '', $snippet->file );
		$this->assertTrue( $snippet->show_line_numbers );

	}

	/**
	 * The language is kept exactly as the author typed it and never resolved here,
	 * the legacy `lang` spelling is read, and `language` wins when both are given.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_reads_the_language_attribute_grammar(): void {

		$this->assertSame( 'js', Snippet::from_shortcode_atts( [ 'language' => 'js' ], 'x' )->language );
		$this->assertSame( 'html4strict', Snippet::from_shortcode_atts( [ 'language' => 'html4strict' ], 'x' )->language );
		$this->assertSame( 'madeuplang', Snippet::from_shortcode_atts( [ 'language' => 'madeuplang' ], 'x' )->language );

		$this->assertSame( 'php', Snippet::from_shortcode_atts( [ 'lang' => 'php' ], 'x' )->language );
		$this->assertSame(
			'php',
			Snippet::from_shortcode_atts(
				[
					'language' => 'php',
					'lang'     => 'ruby',
				],
				'x'
			)->language
		);

	}

	/**
	 * Attribute names are matched without regard to case.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_reads_attribute_names_case_insensitively(): void {

		$snippet = Snippet::from_shortcode_atts(
			[
				'LANGUAGE'  => 'php',
				'FirstLine' => '4',
			],
			'x'
		);

		$this->assertSame( 'php', $snippet->language );
		$this->assertSame( 4, $snippet->first_line );

	}

	/**
	 * The first line number is `max( 1, num, firstline )`.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_reads_the_first_line_grammar(): void {

		$this->assertSame( 5, Snippet::from_shortcode_atts( [ 'firstline' => '5' ], 'x' )->first_line );
		$this->assertSame( 5, Snippet::from_shortcode_atts( [ 'num' => '5' ], 'x' )->first_line );

		$this->assertSame(
			7,
			Snippet::from_shortcode_atts(
				[
					'firstline' => '3',
					'num'       => '7',
				],
				'x'
			)->first_line
		);

		$this->assertSame(
			7,
			Snippet::from_shortcode_atts(
				[
					'firstline' => '7',
					'num'       => '3',
				],
				'x'
			)->first_line
		);

		$this->assertSame( 1, Snippet::from_shortcode_atts( [ 'firstline' => '0' ], 'x' )->first_line );
		$this->assertSame( 3, Snippet::from_shortcode_atts( [ 'firstline' => '-3' ], 'x' )->first_line );
		$this->assertSame( 1, Snippet::from_shortcode_atts( [ 'firstline' => 'nonsense' ], 'x' )->first_line );

	}

	/**
	 * A first line number at the bottom of the integer range is a number, not a fatal.
	 *
	 * `abs( PHP_INT_MIN )` does not fit in an integer, so PHP returns a float and the
	 * constructor's `int` parameter refuses it. Both spellings of the number saturate.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_reads_a_first_line_at_the_bottom_of_the_integer_range(): void {

		$saturated = Snippet::from_shortcode_atts( [ 'firstline' => '9223372036854775808' ], 'x' )->first_line;

		$this->assertSame( PHP_INT_MAX, $saturated );

		$this->assertSame( $saturated, Snippet::from_shortcode_atts( [ 'firstline' => '-9223372036854775808' ], 'x' )->first_line );
		$this->assertSame( $saturated, Snippet::from_shortcode_atts( [ 'num' => '-9223372036854775808' ], 'x' )->first_line );
		$this->assertSame( $saturated, Snippet::from_shortcode_atts( [ 'firstline' => '-99999999999999999999' ], 'x' )->first_line );

		$this->assertSame(
			$saturated,
			Snippet::from_block_attributes(
				[
					'code'      => 'x',
					'firstLine' => PHP_INT_MIN,
				]
			)->first_line
		);

		// A block attribute arrives as JSON, so the number can reach the parser as a float.
		$this->assertSame(
			$saturated,
			Snippet::from_block_attributes(
				[
					'code'      => 'x',
					'firstLine' => -9.3e18,
				]
			)->first_line
		);

	}

	/**
	 * The `"2,4-6"` range grammar is parsed into a sorted, unique list of lines,
	 * and nonsense yields nothing rather than an error.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_reads_the_highlight_range_grammar(): void {

		$this->assertSame( [ 2, 4, 5, 6 ], Snippet::from_shortcode_atts( [ 'highlight' => '2,4-6' ], 'x' )->highlight_lines );
		$this->assertSame( [ 3 ], Snippet::from_shortcode_atts( [ 'highlight' => '3' ], 'x' )->highlight_lines );
		$this->assertSame( [ 1, 2, 3 ], Snippet::from_shortcode_atts( [ 'highlight' => '3-1' ], 'x' )->highlight_lines );
		$this->assertSame( [ 2, 3 ], Snippet::from_shortcode_atts( [ 'highlight' => ' 3 , 2 , 3 ' ], 'x' )->highlight_lines );
		$this->assertSame( [ 4 ], Snippet::from_shortcode_atts( [ 'highlight' => '4-4' ], 'x' )->highlight_lines );

		$this->assertSame( [], Snippet::from_shortcode_atts( [ 'highlight' => '0' ], 'x' )->highlight_lines );
		$this->assertSame( [], Snippet::from_shortcode_atts( [ 'highlight' => '' ], 'x' )->highlight_lines );
		$this->assertSame( [], Snippet::from_shortcode_atts( [ 'highlight' => 'abc' ], 'x' )->highlight_lines );
		$this->assertSame( [], Snippet::from_shortcode_atts( [], 'x' )->highlight_lines );

	}

	/**
	 * A range cannot be used to exhaust memory.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_caps_a_highlight_range(): void {

		$lines = Snippet::from_shortcode_atts( [ 'highlight' => '1-999999999' ], 'x' )->highlight_lines;

		$this->assertCount( Snippet::MAX_RANGE_LENGTH, $lines );

	}

	/**
	 * Nor can any number of ranges together.
	 *
	 * Capping one range bounds nothing: ranges are comma separated and unbounded in
	 * number. What is expanded is what is kept.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_caps_the_whole_highlight_attribute(): void {

		$ranges = [];

		for ( $index = 1; $index <= 500; $index++ ) {
			$ranges[] = sprintf( '%d-%d', $index, $index + Snippet::MAX_RANGE_LENGTH );
		}

		$lines = Snippet::from_shortcode_atts( [ 'highlight' => implode( ',', $ranges ) ], 'x' )->highlight_lines;

		$this->assertCount( Snippet::MAX_HIGHLIGHT_LINES, $lines );

	}

	/**
	 * A range at the very top of the integer range ends.
	 *
	 * Counting up to `PHP_INT_MAX` overflows the loop variable into a float, which never advances again.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_ends_a_range_at_the_top_of_the_integer_range(): void {

		$this->assertSame(
			[ PHP_INT_MAX ],
			Snippet::from_shortcode_atts(
				[ 'highlight' => sprintf( '%d-%d', PHP_INT_MAX, PHP_INT_MAX ) ],
				'x'
			)->highlight_lines
		);

	}

	/**
	 * `gutter` is the per snippet line numbers switch, falling back to the site setting.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_lets_gutter_override_the_site_setting(): void {

		$this->assertFalse( Snippet::from_shortcode_atts( [ 'gutter' => 'no' ], 'x', true )->show_line_numbers );
		$this->assertTrue( Snippet::from_shortcode_atts( [ 'gutter' => 'yes' ], 'x', false )->show_line_numbers );
		$this->assertTrue( Snippet::from_shortcode_atts( [ 'gutter' => ' YES ' ], 'x', false )->show_line_numbers );

		// Anything which is not yes or no is not an opinion, so the site setting stands.
		$this->assertTrue( Snippet::from_shortcode_atts( [ 'gutter' => 'maybe' ], 'x', true )->show_line_numbers );
		$this->assertFalse( Snippet::from_shortcode_atts( [ 'gutter' => 'maybe' ], 'x', false )->show_line_numbers );
		$this->assertFalse( Snippet::from_shortcode_atts( [], 'x', false )->show_line_numbers );

	}

	/**
	 * The attributes the plugin no longer acts on still parse, and change nothing.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_accepts_and_ignores_the_retired_attributes(): void {

		$plain = Snippet::from_shortcode_atts( [ 'language' => 'php' ], 'x' );

		$noisy = Snippet::from_shortcode_atts(
			[
				'language'    => 'php',
				'plaintext'   => 'no',
				'toolbar'     => 'no',
				'strict_mode' => 'always',
				'made_up'     => 'whatever',
				'anotherone'  => '<script>alert(1)</script>',
			],
			'x'
		);

		$this->assertEquals( $plain, $noisy );

	}

	/**
	 * WordPress hands a shortcode callback an empty string when it has no
	 * attributes, and old content can carry attributes of any shape at all.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_does_not_fatal_on_malformed_attributes(): void {

		$snippet = Snippet::from_shortcode_atts(
			[
				'language' => 'php',
				'file'     => [ 'not', 'a', 'string' ],
				0          => 'valueless',
			],
			'x'
		);

		$this->assertSame( 'php', $snippet->language );
		$this->assertSame( '', $snippet->file );

		$empty = Snippet::from_shortcode_atts( '', 'echo 1;' );

		$this->assertSame( 'echo 1;', $empty->code );
		$this->assertSame( 'code', $empty->language );

	}

	/**
	 * The file label has its whitespace collapsed and is otherwise left alone.
	 *
	 * An angle bracket in a file name is a type parameter; the value object holds what
	 * the author wrote and the renderer is where it is made safe.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_cleans_a_file_label_up(): void {

		$this->assertSame( 'wp-config.php', Snippet::from_shortcode_atts( [ 'file' => '  wp-config.php  ' ], 'x' )->file );
		$this->assertSame( 'a b', Snippet::from_shortcode_atts( [ 'file' => "a\n\tb" ], 'x' )->file );

		$kept = [
			'vector<int>.cpp',
			'Foo<T>.cs',
			'List<String>.java',
			'a<b c.php',
			'<script>alert(1)</script>x.php',
		];

		foreach ( $kept as $label ) {
			$this->assertSame( $label, Snippet::from_shortcode_atts( [ 'file' => $label ], 'x' )->file );
		}

	}

	/**
	 * Shortcode content is trimmed, and not otherwise touched.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_trims_code_but_does_not_alter_it(): void {

		$code    = "<?php\n\techo '<b>&amp;</b>';\n";
		$snippet = Snippet::from_shortcode_atts( [], "\n" . $code . "\n" );

		$this->assertSame( trim( $code ), $snippet->code );
		$this->assertStringContainsString( '&amp;', $snippet->code );

	}

	/**
	 * Block attributes go through the same parser as shortcode attributes, and a
	 * block which says nothing about line numbers inherits the site setting.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_reads_block_attributes_through_the_shortcode_parser(): void {

		$snippet = Snippet::from_block_attributes(
			[
				'code'            => 'echo 1;',
				'language'        => 'php',
				'showLineNumbers' => false,
				'firstLine'       => 12,
				'highlightLines'  => '2,4-6',
				'file'            => 'index.php',
			]
		);

		$this->assertSame( 'echo 1;', $snippet->code );
		$this->assertSame( 'php', $snippet->language );
		$this->assertFalse( $snippet->show_line_numbers );
		$this->assertSame( 12, $snippet->first_line );
		$this->assertSame( [ 2, 4, 5, 6 ], $snippet->highlight_lines );
		$this->assertSame( 'index.php', $snippet->file );

		$this->assertFalse( Snippet::from_block_attributes( [ 'code' => 'x' ], '', false )->show_line_numbers );
		$this->assertTrue( Snippet::from_block_attributes( [ 'code' => 'x' ], '', true )->show_line_numbers );
		$this->assertSame( 'code', Snippet::from_block_attributes( [ 'code' => 'x' ] )->language );

	}

} // end of class

// EOF
