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
 * A shortcode ends at its own closing tag, so a post about this plugin used to be
 * the one post this plugin could not carry. Doubling the brackets of a tag inside
 * a snippet writes it as text, and the matcher steps over the doubled form.
 *
 * What is held in place here is the whole of that: the pattern is core's with one
 * addition and nothing else, a reader is shown the tags the author typed, and the
 * database keeps the bytes the author wrote however many times the post is saved.
 */
class Tag_Escape_Test extends WP_UnitTestCase {

	/**
	 * A snippet whose code is a whole snippet, with both tags escaped.
	 *
	 * @var string
	 */
	const CONTENT = "[sourcecode language=\"php\"]\n[[sourcecode language=\"php\"]]\nfunction f() {}\n[[/sourcecode]]\n[/sourcecode]";

	/**
	 * What a reader is to be shown inside the code box.
	 *
	 * @var string
	 */
	const QUOTED = "[sourcecode language=\"php\"]\nfunction f() {}\n[/sourcecode]";

	/**
	 * Registers the pipeline.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		Shortcode_Handler::get_instance()->register_hooks();

	}

	/**
	 * The pattern is WordPress's, with one alternative added and nothing else. A
	 * pattern of this plugin's own would have to be kept in step with core's by hand
	 * for as long as the plugin exists, and the one place it fell behind would be a
	 * shortcode bounded differently here from the way `do_shortcode()` bounds it.
	 *
	 * @return void
	 */
	public function test_the_pattern_is_cores_with_one_addition(): void {

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
	 * The reader's half. One code box, showing the tags the author typed, and
	 * nothing of the outer shortcode left over after it.
	 *
	 * @return void
	 */
	public function test_an_escaped_tag_renders_as_the_text_it_stands_for(): void {

		$rendered = (string) apply_filters( 'the_content', self::CONTENT );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Running content through core's own hooks is what an integration test does.

		$this->assertSame( 1, substr_count( $rendered, '<pre ' ), 'The escaped closing tag ended the snippet, so the box was cut short.' );

		$this->assertStringContainsString(
			Renderer::escape_verbatim( self::QUOTED ),
			$rendered,
			'The reader was shown the doubled brackets rather than the tags the author wrote.'
		);

		$this->assertStringNotContainsString( '[[', $rendered, 'The escape reached the page.' );

	}

	/**
	 * The database's half, and the one that costs bytes when it is wrong. Nothing
	 * transformed is ever stored, so the escape has to survive being saved over and
	 * over — an author who edits the post ten times must not lose a bracket a time.
	 *
	 * @return void
	 */
	public function test_the_escape_survives_being_saved_again_and_again(): void {

		$post_id = self::factory()->post->create( [ 'post_content' => wp_slash( self::CONTENT ) ] );

		for ( $round = 1; $round <= 3; $round++ ) {

			$this->assertSame(
				self::CONTENT,
				(string) get_post_field( 'post_content', $post_id, 'raw' ),
				sprintf( 'Round %d changed the bytes the author wrote.', $round )
			);

			wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => wp_slash( self::CONTENT ),
				]
			);

		}

	}

	/**
	 * A snippet holding a bare closing tag still ends there, exactly as it always
	 * has. The escape is an addition and not a change: content written before it
	 * existed goes on being read the way it was read when it was written.
	 *
	 * @return void
	 */
	public function test_an_unescaped_closing_tag_still_ends_the_snippet(): void {

		$rendered = (string) apply_filters( 'the_content', '[php]echo 1;[/php] and then [/php] again' );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- As above.

		$this->assertSame( 1, substr_count( $rendered, '<pre ' ) );
		$this->assertStringContainsString( 'and then [/php] again', $rendered );

	}

	/*
	 * WordPress's own escape of a whole shortcode, `[[php]…[/php]]`, is a different
	 * construct from the one this file is about and is untouched by any of it. It is
	 * asserted in `Backward_Compatibility_Test`, which drives the same string through
	 * a real save as well as through rendering, and so says everything a case here
	 * could and more.
	 */

}    //end of class


//EOF
