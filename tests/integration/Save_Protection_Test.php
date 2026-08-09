<?php
/**
 * Tests for save time protection of snippet code.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Shortcode_Handler;
use WP_UnitTestCase;

/**
 * Checks that a user without `unfiltered_html` can store code which KSES would
 * otherwise destroy, and that what lands in the database is byte for byte what was
 * submitted.
 */
class Save_Protection_Test extends WP_UnitTestCase {

	/**
	 * Content used by most of the tests here. Every part of it is something KSES
	 * would remove or rewrite if it were given the chance.
	 *
	 * @var string
	 */
	const HOSTILE_CONTENT = "Intro paragraph.\n\n[php]\n<script src=\"https://example.com/a.js\"></script>\n<?php echo '<div>' . \$a . '</div>'; ?>\n\$re = '/\\d+\\s\"x\"/';\n[/php]\n\nOutro paragraph.";  // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Test fixture standing in for author written code, not markup this plugin emits.

	/**
	 * Registers the pipeline once WordPress is up.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		Shortcode_Handler::get_instance()->register_hooks();

	}

	/**
	 * Method to run content through one of WordPress' own filters.
	 *
	 * @param string $filter  Filter name.
	 * @param string $content Content to filter.
	 *
	 * @return string
	 */
	protected function _filter( string $filter, string $content ): string {
		return (string) apply_filters( $filter, $content );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Running content through core's own hooks is what an integration test does.
	}

	/**
	 * Method to become a user who has to go through KSES.
	 *
	 * @return int User id.
	 */
	protected function _become_contributor(): int {

		$user_id = self::factory()->user->create( [ 'role' => 'contributor' ] );

		wp_set_current_user( $user_id );

		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$this->assertSame( 10, has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );

		return $user_id;

	}

	/**
	 * The filter pair is symmetric: what goes in comes out, slashes and all.
	 *
	 * @return void
	 */
	public function test_the_save_filters_are_a_round_trip(): void {

		$slashed = wp_slash( self::HOSTILE_CONTENT );

		$this->assertSame( $slashed, $this->_filter( 'content_save_pre', $slashed ) );

		// And again, which is what a revision or an autosave does in the same request.
		$this->assertSame( $slashed, $this->_filter( 'content_save_pre', $slashed ) );

	}

	/**
	 * AC-3 — a contributor saves a script tag inside a snippet, the stored content
	 * is byte identical to what was submitted, and saving it again changes nothing.
	 *
	 * @return void
	 */
	public function test_a_contributor_saves_byte_identical_content_and_resaves_with_no_diff(): void {

		$this->_become_contributor();

		$post_id = self::factory()->post->create(
			[
				'post_content' => wp_slash( self::HOSTILE_CONTENT ),
				'post_status'  => 'draft',
			]
		);

		$first = get_post_field( 'post_content', $post_id, 'raw' );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => wp_slash( $first ),
			]
		);

		$second = get_post_field( 'post_content', $post_id, 'raw' );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => wp_slash( $second ),
			]
		);

		$this->assertSame( self::HOSTILE_CONTENT, $first );
		$this->assertSame( $first, $second );
		$this->assertSame( $second, get_post_field( 'post_content', $post_id, 'raw' ) );

	}

	/**
	 * Revisions run the same filters again, in the same request, and store the same
	 * bytes.
	 *
	 * @return void
	 */
	public function test_revisions_store_the_same_bytes(): void {

		$this->_become_contributor();

		$post_id = self::factory()->post->create(
			[
				'post_content' => wp_slash( self::HOSTILE_CONTENT ),
				'post_status'  => 'publish',
			]
		);

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => wp_slash( self::HOSTILE_CONTENT ),
				'post_title'   => 'Changed title',
			]
		);

		$revisions = wp_get_post_revisions( $post_id );

		$this->assertNotEmpty( $revisions, 'The post type supports revisions, so there should be at least one.' );

		foreach ( $revisions as $revision ) {
			$this->assertSame( self::HOSTILE_CONTENT, $revision->post_content );
		}

		$this->assertSame( self::HOSTILE_CONTENT, get_post_field( 'post_content', $post_id, 'raw' ) );

	}

	/**
	 * Content outside a snippet is not this plugin's business, and KSES is left to
	 * do its job on it. This is also what proves KSES is live for the tests above,
	 * rather than them passing because nothing was filtering in the first place.
	 *
	 * @return void
	 */
	public function test_markup_outside_a_snippet_is_still_filtered(): void {

		$this->_become_contributor();

		$post_id = self::factory()->post->create(
			[
				'post_content' => wp_slash( "<script>alert(1)</script>\n\n[php]echo 1;[/php]" ),
				'post_status'  => 'draft',
			]
		);

		$stored = get_post_field( 'post_content', $post_id, 'raw' );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $stored );
		$this->assertStringContainsString( '[php]echo 1;[/php]', $stored );

	}

	/**
	 * A user who can post unfiltered HTML still gets byte identical storage.
	 *
	 * @return void
	 */
	public function test_an_administrator_also_saves_byte_identical_content(): void {

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$post_id = self::factory()->post->create(
			[
				'post_content' => wp_slash( self::HOSTILE_CONTENT ),
				'post_status'  => 'draft',
			]
		);

		$this->assertSame( self::HOSTILE_CONTENT, get_post_field( 'post_content', $post_id, 'raw' ) );

	}

}    //end of class


//EOF
