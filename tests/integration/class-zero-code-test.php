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
 * `0` is code, and every path in the plugin has to keep treating it as code.
 *
 * Three places ask whether a snippet is empty before deciding to render or rewrite
 * it, and all three ask with `'' === trim( $code )` rather than with `empty()`. That
 * is the one place in the plugin where the difference between those two is not a
 * style question: `empty( '0' )` is TRUE, so an `empty()` there reads a snippet whose
 * code is the digit zero as a snippet with no code in it.
 *
 * What each of the three would then do:
 *
 * - `Content_Protector::_render_entry()` — the shortcode renders as nothing.
 * - `Block::render()` — the block renders as nothing. Its own comment says the two
 *   paths must agree, and they would, on the wrong answer.
 * - `Block_Converter::block_to_shortcode()` — the revert tool **drops the block from
 *   the post**. That one is not a display bug. It is deleting somebody's snippet, on
 *   the way out of a plugin they have just decided to stop using.
 *
 * A file whose whole content is `0` is a real thing — a feature flag, a counter, a
 * fixture, the answer in a puzzle. Nothing else in 5,378 assertions covers it, which
 * is exactly how a sweep replacing every `'' ===` in the plugin could have gone in
 * green.
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
	 * The revert tool keeps it, rather than dropping the block on the floor.
	 *
	 * This is the case worth having. The other two are a snippet which does not show
	 * up; this one is a snippet which is gone from the post content for good, and the
	 * tool that did it is the one a site owner reaches for precisely because they want
	 * their code to survive the plugin being switched off.
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
	 * A language named `0` is not code, and does get the default.
	 *
	 * The contrast, and it is here on purpose. Everywhere else the plugin hands an
	 * unrecognised language name straight back — the author's word is theirs, and one
	 * overwritten could never be recovered. `0` is the exception, and it is Amit's call:
	 * `language="0"` is not somebody naming a language, it is invalid use, so the guards
	 * on it read with `empty()` like every other guard in the plugin.
	 *
	 * Written down as a test rather than left to be inferred from a diff, because the
	 * three cases above look like the same question and get the opposite answer. What
	 * separates them is that `0` is a plausible *file*, and never a plausible *language*.
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
	 * The control, so the three above are measuring something.
	 *
	 * A snippet which really is empty still renders as nothing, on both paths. Without
	 * this, every assertion here would be satisfied by a plugin which had simply
	 * stopped checking for an empty snippet at all.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_still_renders_nothing_for_a_genuinely_empty_snippet(): void {

		$this->assertStringNotContainsString(
			'<pre ',
			$this->_filter( 'the_content', '[php][/php]' ),
			'An empty shortcode rendered a code box.'
		);

		$this->assertStringNotContainsString(
			'<pre ',
			$this->_filter( 'the_content', static::_block( [ 'code' => '' ] ) ),
			'An empty block rendered a code box.'
		);

	}

}    //end of class


//EOF
