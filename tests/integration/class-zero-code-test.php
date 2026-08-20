<?php
/**
 * Tests for a snippet whose entire code is the digit zero.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Block;
use iG\Syntax_Hiliter\Block_Converter;
use iG\Syntax_Hiliter\Shortcode_Handler;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

/**
 * `0` is code. Three places ask whether a snippet is empty with `'' === trim( $code )`
 * rather than `empty()`, because `empty( '0' )` is TRUE. With `empty()` there:
 *
 * - `Content_Protector::_render_entry()` renders the shortcode as nothing.
 * - `Block::render()` renders the block as nothing.
 * - `Block_Converter::block_to_shortcode()` drops the block from the post for good.
 */
class Zero_Code_Test extends WP_UnitTestCase {

	use Pipeline_Test_Helpers;

	/**
	 * The code every case here is about.
	 *
	 * @var string
	 */
	protected const string _ZERO = '0';

	/**
	 * Brings up the pipeline and the block, the way the two suites which own them do.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		Shortcode_Handler::get_instance();

		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( Block::NAME ) ) {
			Block::get_instance()->register_block();
		}

	}

	/**
	 * The shortcode path renders it.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_renders_a_code_box_for_a_shortcode_whose_code_is_zero(): void {

		$rendered = $this->_filter( 'the_content', sprintf( '[php]%s[/php]', static::_ZERO ) );

		$this->assertStringContainsString(
			'<pre ',
			$rendered,
			'A snippet whose code is 0 was read as an empty snippet and rendered as nothing.'
		);

		$this->assertStringContainsString(
			'>' . static::_ZERO . '<',
			$rendered,
			'The code box rendered, but the zero is not in it.'
		);

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
	 * The revert tool keeps it rather than dropping the block. The other two cases are
	 * a snippet which does not show up; this one is a snippet gone from the post for good.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_keeps_a_block_whose_code_is_zero_in_the_revert_tool(): void {

		$block = static::_block(
			[
				'code'     => static::_ZERO,
				'language' => 'php',
			]
		);

		$result = Block_Converter::convert_content( $block );

		$this->assertSame( 1, $result['converted'], 'The block was not converted.' );

		$this->assertStringContainsString(
			static::_ZERO,
			$result['content'],
			'The revert tool dropped the block, so the code is no longer in the post at all.'
		);

		$this->assertStringNotContainsString(
			'<!-- wp:',
			$result['content'],
			'A block delimiter survived the rewrite.'
		);

		$rendered = $this->_filter( 'the_content', $result['content'] );

		$this->assertStringContainsString(
			'>' . static::_ZERO . '<',
			$rendered,
			'The shortcode the tool wrote does not render the code it was given.'
		);

	}

	/**
	 * A language named `0` is not code and does get the default: `language="0"` is
	 * invalid use, not somebody naming a language, so its guards read with `empty()`.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_does_not_keep_a_language_named_zero(): void {

		$rendered = $this->_filter( 'the_content', sprintf( '[sourcecode language="%s"]echo 1;[/sourcecode]', static::_ZERO ) );

		$this->assertStringNotContainsString(
			'language-' . static::_ZERO,
			$rendered,
			'A language named 0 was kept as the author typed it, which is the behaviour this pins the opposite of.'
		);

		$this->assertStringContainsString(
			'<pre ',
			$rendered,
			'The snippet still renders — only the language name was replaced.'
		);

	}

	/**
	 * The control: a snippet which really is empty still renders as nothing on both
	 * paths, so the cases above are not satisfied by a plugin that stopped checking.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_still_renders_nothing_for_a_genuinely_empty_snippet(): void {

		$shortcode = $this->_filter( 'the_content', '[php][/php]' );
		$block     = $this->_filter( 'the_content', static::_block( [ 'code' => '' ] ) );

		$this->assertStringNotContainsString( '<pre ', $shortcode, 'An empty shortcode rendered a code box.' );
		$this->assertStringNotContainsString( '<pre ', $block, 'An empty block rendered a code box.' );

		// The wrapper is emitted for every box, so an empty container would slip past the two assertions above.
		$this->assertStringNotContainsString( 'igsh-code-box', $shortcode, 'An empty shortcode rendered a container.' );
		$this->assertStringNotContainsString( 'igsh-code-box', $block, 'An empty block rendered a container.' );

	}

} // end of class

// EOF
