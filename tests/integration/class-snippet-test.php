<?php
/**
 * Tests for the snippet value object where the pipeline has to observe it.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Shortcode_Handler;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use WP_UnitTestCase;

/**
 * `Snippet`'s attribute reading where it takes the pipeline to see it: a `language`
 * or `file` of `0` is invalid use, not data, and reads as empty.
 */
class Snippet_Test extends WP_UnitTestCase {

	use Pipeline_Test_Helpers;

	/**
	 * The code every case here is about.
	 *
	 * @var string
	 */
	protected const string _ZERO = '0';

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

} // end of class

// EOF
