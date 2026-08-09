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
	 * @return void
	 */
	public function test_defaults_match_v5(): void {

		$snippet = Snippet::from_shortcode_atts( [], 'echo 1;' );

		$this->assertSame( 'echo 1;', $snippet->code );
		$this->assertSame( 'code', $snippet->language );
		$this->assertSame( 1, $snippet->first_line );
		$this->assertSame( [], $snippet->highlight_lines );
		$this->assertSame( '', $snippet->file );
		$this->assertTrue( $snippet->show_line_numbers );

	}

	/**
	 * The language is kept exactly as the author typed it, never resolved here.
	 *
	 * @return void
	 */
	public function test_language_is_stored_as_typed(): void {

		$this->assertSame( 'js', Snippet::from_shortcode_atts( [ 'language' => 'js' ], 'x' )->language );
		$this->assertSame( 'html4strict', Snippet::from_shortcode_atts( [ 'language' => 'html4strict' ], 'x' )->language );
		$this->assertSame( 'madeuplang', Snippet::from_shortcode_atts( [ 'language' => 'madeuplang' ], 'x' )->language );

	}

	/**
	 * The legacy `lang` spelling is read, and `language` wins when both are given.
	 *
	 * @return void
	 */
	public function test_legacy_lang_attribute_is_read(): void {

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
	 * @return void
	 */
	public function test_attribute_names_are_case_insensitive(): void {

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
	 * @return void
	 */
	public function test_first_line_grammar(): void {

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
	 * The `"2,4-6"` range grammar is parsed into a sorted, unique list of lines.
	 *
	 * @return void
	 */
	public function test_highlight_range_grammar(): void {

		$this->assertSame( [ 2, 4, 5, 6 ], Snippet::from_shortcode_atts( [ 'highlight' => '2,4-6' ], 'x' )->highlight_lines );
		$this->assertSame( [ 3 ], Snippet::from_shortcode_atts( [ 'highlight' => '3' ], 'x' )->highlight_lines );
		$this->assertSame( [ 1, 2, 3 ], Snippet::from_shortcode_atts( [ 'highlight' => '3-1' ], 'x' )->highlight_lines );
		$this->assertSame( [ 2, 3 ], Snippet::from_shortcode_atts( [ 'highlight' => ' 3 , 2 , 3 ' ], 'x' )->highlight_lines );
		$this->assertSame( [ 4 ], Snippet::from_shortcode_atts( [ 'highlight' => '4-4' ], 'x' )->highlight_lines );

	}

	/**
	 * Nonsense in the highlight attribute yields nothing, and never an error.
	 *
	 * @return void
	 */
	public function test_highlight_ignores_nonsense(): void {

		$this->assertSame( [], Snippet::from_shortcode_atts( [ 'highlight' => '0' ], 'x' )->highlight_lines );
		$this->assertSame( [], Snippet::from_shortcode_atts( [ 'highlight' => '' ], 'x' )->highlight_lines );
		$this->assertSame( [], Snippet::from_shortcode_atts( [ 'highlight' => 'abc' ], 'x' )->highlight_lines );
		$this->assertSame( [], Snippet::from_shortcode_atts( [], 'x' )->highlight_lines );

	}

	/**
	 * A range cannot be used to exhaust memory.
	 *
	 * @return void
	 */
	public function test_highlight_range_is_capped(): void {

		$lines = Snippet::from_shortcode_atts( [ 'highlight' => '1-999999999' ], 'x' )->highlight_lines;

		$this->assertCount( Snippet::MAX_RANGE_LENGTH, $lines );

	}

	/**
	 * `gutter` is the per snippet line numbers switch, falling back to the site setting.
	 *
	 * @return void
	 */
	public function test_gutter_overrides_the_site_setting(): void {

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
	 * @return void
	 */
	public function test_retired_attributes_are_accepted_and_ignored(): void {

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
	 * Attribute values which are not scalars are discarded rather than fatal.
	 *
	 * @return void
	 */
	public function test_hostile_attributes_do_not_fatal(): void {

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

	}

	/**
	 * WordPress hands a shortcode callback an empty string when it has no attributes.
	 *
	 * @return void
	 */
	public function test_empty_string_attributes_do_not_fatal(): void {

		$snippet = Snippet::from_shortcode_atts( '', 'echo 1;' );

		$this->assertSame( 'echo 1;', $snippet->code );
		$this->assertSame( 'code', $snippet->language );

	}

	/**
	 * The file label is cleaned up but otherwise left alone.
	 *
	 * @return void
	 */
	public function test_file_label_is_cleaned_up(): void {

		$this->assertSame( 'wp-config.php', Snippet::from_shortcode_atts( [ 'file' => '  wp-config.php  ' ], 'x' )->file );
		$this->assertSame( 'alert(1)', Snippet::from_shortcode_atts( [ 'file' => '<script>alert(1)</script>' ], 'x' )->file );
		$this->assertSame( 'a b', Snippet::from_shortcode_atts( [ 'file' => "a\n\tb" ], 'x' )->file );

	}

	/**
	 * Shortcode content is trimmed, and not otherwise touched.
	 *
	 * @return void
	 */
	public function test_code_is_trimmed_but_not_altered(): void {

		$code    = "<?php\n\techo '<b>&amp;</b>';\n";
		$snippet = Snippet::from_shortcode_atts( [], "\n" . $code . "\n" );

		$this->assertSame( trim( $code ), $snippet->code );
		$this->assertStringContainsString( '&amp;', $snippet->code );

	}

	/**
	 * A snippet cannot be changed once it has been made.
	 *
	 * @return void
	 */
	public function test_snippet_is_immutable(): void {

		$snippet = new Snippet( 'x', 'php' );

		$this->expectException( \Error::class );

		$snippet->language = 'ruby';

	}

	/**
	 * Block attributes go through the same parser as shortcode attributes.
	 *
	 * @return void
	 */
	public function test_block_attributes(): void {

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

	}

	/**
	 * A block which says nothing about line numbers inherits the site setting.
	 *
	 * @return void
	 */
	public function test_block_attributes_fall_back_to_the_site_setting(): void {

		$this->assertFalse( Snippet::from_block_attributes( [ 'code' => 'x' ], '', false )->show_line_numbers );
		$this->assertTrue( Snippet::from_block_attributes( [ 'code' => 'x' ], '', true )->show_line_numbers );
		$this->assertSame( 'code', Snippet::from_block_attributes( [ 'code' => 'x' ] )->language );

	}

}    //end of class


//EOF
