<?php
/**
 * A snippet may quote this plugin's own tags.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Helper;
use iG\Syntax_Hiliter\Legacy_Map;
use iG\Syntax_Hiliter\Renderer;
use iG\Syntax_Hiliter\Shortcode_Handler;
use WP_UnitTestCase;

/**
 * Doubling the brackets of a claimed tag inside a snippet writes it as text, and
 * the matcher steps over the doubled form. The pattern is core's with one addition,
 * the reader sees the tags the author typed, and the stored bytes stay as written.
 */
class Tag_Escape_Test extends WP_UnitTestCase {

	/**
	 * A snippet whose code is a whole snippet, with both tags escaped.
	 *
	 * @var string
	 */
	protected const string _CONTENT = "[sourcecode language=\"php\"]\n[[sourcecode language=\"php\"]]\nfunction f() {}\n[[/sourcecode]]\n[/sourcecode]";

	/**
	 * What a reader is to be shown inside the code box.
	 *
	 * @var string
	 */
	protected const string _QUOTED = "[sourcecode language=\"php\"]\nfunction f() {}\n[/sourcecode]";

	/**
	 * Registers the pipeline.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		Shortcode_Handler::get_instance();

	}

	/**
	 * The pattern is WordPress's with one alternative added and nothing else, so a
	 * shortcode is never bounded differently here from the way `do_shortcode()` bounds it.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_builds_cores_pattern_with_one_addition(): void {

		$tags = Legacy_Map::get_tags();
		$core = get_shortcode_regex( $tags );
		$ours = Helper::get_shortcode_pattern( $tags );

		$this->assertNotSame( $core, $ours, 'The pattern came back unchanged, so the escape is not in it.' );

		$this->assertSame(
			$core,
			str_replace( '(?:\[\[\/\2\]\]|\[(?!\/\2\]))', '\[(?!\/\2\])', $ours ),
			'The two patterns differ by more than the one alternative.'
		);

	}

	/**
	 * One code box showing the tags the author typed, with nothing of the outer
	 * shortcode left over after it.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_renders_an_escaped_tag_as_the_text_it_stands_for(): void {

		$rendered = (string) apply_filters( 'the_content', self::_CONTENT );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Running content through core's own hooks is what an integration test does.

		$this->assertSame( 1, substr_count( $rendered, '<pre ' ), 'The escaped closing tag ended the snippet, so the box was cut short.' );

		$this->assertStringContainsString(
			Renderer::escape_verbatim( self::_QUOTED ),
			$rendered,
			'The reader was shown the doubled brackets rather than the tags the author wrote.'
		);

		$this->assertStringNotContainsString( '[[', $rendered, 'The escape reached the page.' );

	}

	/**
	 * The escape survives repeated saves: an author who edits the post ten times
	 * must not lose a bracket a time.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_keeps_the_escape_through_being_saved_again_and_again(): void {

		$post_id = self::factory()->post->create( [ 'post_content' => wp_slash( self::_CONTENT ) ] );

		for ( $round = 1; $round <= 3; $round++ ) {

			$this->assertSame(
				self::_CONTENT,
				(string) get_post_field( 'post_content', $post_id, 'raw' ),
				sprintf( 'Round %d changed the bytes the author wrote.', $round )
			);

			wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => wp_slash( self::_CONTENT ),
				]
			);

		}

	}

	/**
	 * A snippet holding a bare closing tag still ends there: the escape is an
	 * addition, and older content is read the way it was written.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_still_ends_the_snippet_at_an_unescaped_closing_tag(): void {

		$rendered = (string) apply_filters( 'the_content', '[php]echo 1;[/php] and then [/php] again' );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- As above.

		$this->assertSame( 1, substr_count( $rendered, '<pre ' ) );
		$this->assertStringContainsString( 'and then [/php] again', $rendered );

	}

} // end of class

// EOF
