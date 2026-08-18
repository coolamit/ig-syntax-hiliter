<?php
/**
 * Tests for the display path under content PCRE cannot cope with.
 *
 * `Save_Protection_Test` covers the same ground on the way to the database, which is
 * where losing bytes is permanent. It is not the only path a post of that size takes:
 * `the_content` runs the same matcher over the same bytes on every page view, and a
 * pass which gave up there empties the page instead of the row. Nothing in the suite
 * ran a post anywhere near PCRE's ceiling through the display chain before this file.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Block;
use iG\Syntax_Hiliter\Content_Protector;
use iG\Syntax_Hiliter\Renderer;
use iG\Syntax_Hiliter\Shortcode_Handler;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

/**
 * What a reader is served when the content is bigger than PCRE will look at.
 */
class Display_Protection_Test extends WP_UnitTestCase {

	use Pipeline_Test_Helpers;

	/**
	 * Registers the pipeline, and the block, once WordPress is up.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		Shortcode_Handler::get_instance();

		/*
		 * Whether the block type reaches the registry through the plugin's own `init`
		 * callback by the time PHPUnit gets here is not what is under test, and a
		 * dynamic block which is not registered renders as nothing at all — which would
		 * look exactly like the failure these tests are watching for.
		 */
		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( Block::NAME ) ) {
			Block::get_instance()->register_block();
		}

	}

	/**
	 * Method to build content the shortcode matcher gives up on part way through.
	 *
	 * The attribute part of core's shortcode pattern is a lazily quantified group
	 * nested inside another one, so a `[php ` which never closes makes PCRE walk every
	 * partition of what follows it before admitting there is no match. It stops and
	 * reports a limit it hit long before it reaches the end.
	 *
	 * The snippet in front of that is ordinary and matches at once, which is the whole
	 * point of the fixture: it puts the walk half way through the content before the
	 * matcher gives up, which is the only state in which giving up has anything to
	 * undo.
	 *
	 * @return string
	 */
	protected static function _matcher_killing_content(): string {
		return "[php]echo 1;[/php]\n\n[php " . str_repeat( 'a/', 50000 ) . ' ] end';
	}

	/**
	 * A display pass the shortcode matcher gave up on protects nothing at all — not
	 * even the snippet it had already reached.
	 *
	 * `preg_match()` reports a limit it hit in a way a caller reading the return value
	 * alone cannot tell from "there is nothing more here", and the walk over the
	 * content is resumable, so a pass which gave up half way would hand the rest of the
	 * chain a mixture: placeholders for the snippets it got to, raw bytes for the ones
	 * it did not. The pass at the far end which turns those placeholders back into code
	 * boxes is looking at the very content PCRE has just refused, so it is the pass
	 * most likely to give up next — and a placeholder nothing puts back is a code box
	 * that reaches the reader as `{igshx…}`. Giving up has to mean the content was left
	 * as it was found, and a reader who sees the shortcode they wrote still has their
	 * code.
	 *
	 * @return void
	 */
	public function test_a_display_pass_the_matcher_gave_up_on_protects_nothing(): void {

		$content   = static::_matcher_killing_content();
		$protector = Content_Protector::get_instance();
		$limit     = (string) ini_get( 'pcre.backtrack_limit' );

		// Pinned to PHP's own default so that a php.ini which lifts the ceiling turns this into a slow test rather than a hung one. Put back below.
		ini_set( 'pcre.backtrack_limit', '1000000' );  // phpcs:ignore WordPress.PHP.IniSet.Risky -- See above.

		$protected = $protector->protect_for_display( $content );
		$error     = preg_last_error();
		$restored  = $protector->restore_rendered( $protected );
		$rendered  = $this->_filter( 'the_content', $content );

		ini_set( 'pcre.backtrack_limit', $limit );  // phpcs:ignore WordPress.PHP.IniSet.Risky -- Putting back the value saved above.

		$this->assertNotSame( PREG_NO_ERROR, $error, 'PCRE did not give up, so this is not the test it says it is.' );

		$this->assertNotSame( '', $protected );
		$this->assertSame( $content, $protected, 'A pass the matcher gave up on stashed the snippet it had already reached.' );
		$this->assertSame( $content, $restored, 'And there was nothing for the restore pass to put back.' );

		$this->assertNotSame( '', $rendered, 'The page was served as nothing at all.' );
		$this->assertStringContainsString( '[php]echo 1;[/php]', $rendered, 'The snippet reaches the reader as the text they wrote, which is recoverable.' );
		$this->assertStringNotContainsString( Content_Protector::PLACEHOLDER_PREFIX, $rendered, 'And no placeholder was left behind for them to look at.' );

	}

	/**
	 * A post far past the size at which PCRE gives up is rendered whole.
	 *
	 * The twin of `Save_Protection_Test::test_a_post_far_past_pcres_ceiling_is_stored_intact()`.
	 * That one proves the bytes reach the database; this one proves they reach a
	 * reader, which is a different chain over the same content — the delimiter scan and
	 * the shortcode walk run again on every page view, `do_blocks()` renders between
	 * the two passes, and the restore pass has to find every placeholder that the
	 * protect pass and the block's render callback between them put there.
	 *
	 * A snippet is exactly the kind of content which runs to this size, and the
	 * fixture's code names this plugin's own tags three thousand times over, which is
	 * what the delimiter scan has to keep the matcher away from.
	 *
	 * @return void
	 */
	public function test_a_post_far_past_pcres_ceiling_renders_intact(): void {

		$line = "function f() { return 1; }    // [php]echo 1;[/php] <>&\"'\n";
		$code = str_repeat( $line, 3000 );

		$content = sprintf(
			"PREFIX\n\n%s\n\n[php]echo 2;[/php]\n\nSUFFIX",
			static::_block(
				[
					'code'     => $code,
					'language' => 'php',
				]
			)
		);

		$this->assertGreaterThan( 100 * 1024, strlen( $content ), 'The fixture has to be well past where PCRE gives up.' );

		$rendered = $this->_filter( 'the_content', $content );

		$this->assertNotSame( '', $rendered, 'The page was served as nothing at all.' );

		$this->assertStringContainsString( 'PREFIX', $rendered );
		$this->assertStringContainsString( 'SUFFIX', $rendered );

		$this->assertStringNotContainsString( '<!-- wp:', $rendered, 'The block was rendered rather than served as its own delimiter.' );
		$this->assertStringNotContainsString( Content_Protector::PLACEHOLDER_PREFIX, $rendered, 'Every placeholder the two passes made was put back.' );

		$this->assertSame(
			3000,
			substr_count( $rendered, Renderer::escape_verbatim( $line ) ),
			'Every line of the code came back, escaped as the author typed it and not one line short.'
		);

		$this->assertStringContainsString( 'echo 2;', $rendered, 'And the snippet beside the block rendered too.' );

	}

}    //end of class


//EOF
