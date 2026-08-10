<?php
/**
 * Tests for save time protection of snippet code.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Block;
use iG\Syntax_Hiliter\Content_Protector;
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
	 * Method to build the block delimiter an editor save would leave in the content.
	 *
	 * Core does the serializing, so the fixture carries the bytes the block editor
	 * would really submit rather than a hand written approximation of them.
	 *
	 * @param string $block_name Block name.
	 * @param array  $attributes Block attributes.
	 *
	 * @return string
	 */
	protected static function _block( string $block_name, array $attributes ): string {

		return serialize_block(
			[
				'blockName'    => $block_name,
				'attrs'        => $attributes,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);

	}

	/**
	 * Method to store content and hand back exactly what landed in the database.
	 *
	 * @param string $content Post content.
	 *
	 * @return string
	 */
	protected function _store( string $content ): string {

		$post_id = self::factory()->post->create(
			[
				'post_content' => wp_slash( $content ),
				'post_status'  => 'draft',
			]
		);

		return (string) get_post_field( 'post_content', $post_id, 'raw' );

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
	 * A contributor saves a script tag inside a snippet, the stored content is byte
	 * identical to what was submitted, and saving it again changes nothing.
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
	 * Byte identical storage through the block path, which is where automatic
	 * conversion of legacy content leaves an author's code.
	 *
	 * KSES reaches block attributes: `wp_filter_post_kses()` → `pre_kses` →
	 * `wp_pre_kses_block_attributes()` → `filter_block_content()` runs `wp_kses()`
	 * over every string attribute of every block and re-serialises what comes back.
	 * Unprotected, an author without `unfiltered_html` loses the script line outright
	 * and gets `<`, `'` and `&` entity encoded into the database — on multisite that
	 * is everyone below super admin, site administrators included.
	 *
	 * @return void
	 */
	public function test_a_contributor_saves_block_code_byte_identical_and_resaves_with_no_diff(): void {

		$this->_become_contributor();

		$content = static::_block(
			Block::NAME,
			[
				'code'     => "<script src=\"https://evil.test/a.js\"></script>\nif (a < b) { echo 'a & b'; }",  // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Test fixture standing in for author written code, not markup this plugin emits.
				'language' => 'php',
			]
		);

		$first  = $this->_store( $content );
		$second = $this->_store( $first );

		$this->assertSame( $content, $first );
		$this->assertSame( $first, $second );

	}

	/**
	 * Another plugin's block is another plugin's business, and shielding it from KSES
	 * would hand an author without `unfiltered_html` a way around it.
	 *
	 * @return void
	 */
	public function test_another_plugins_block_is_still_filtered_on_save(): void {

		$this->_become_contributor();

		$content = static::_block( 'acme/notice', [ 'text' => '<script>alert(1)</script>' ] );

		$stored = $this->_store( $content );

		$this->assertNotSame( $content, $stored, 'KSES still gets to rewrite a block this plugin does not own.' );
		$this->assertStringNotContainsString( 'script', $stored );

	}

	/**
	 * A manual excerpt is stored exactly as it was written, and stripped only on the
	 * way out.
	 *
	 * `excerpt_save_pre` writes to the database. Stripping there deletes the author's
	 * bytes outright, and the Gist pipeline hooked to the same filter stored rendered
	 * markup in their place. `is_admin()` is no guard: it is false for REST, which is
	 * how WP 6.9's block editor saves, and false for WP-CLI, cron and this plugin's
	 * own revert tool.
	 *
	 * @return void
	 */
	public function test_an_excerpt_is_stored_exactly_as_it_was_written(): void {

		$excerpt = 'Summary with [php]echo 1;[/php] and [github id=42] in it.';

		$post_id = self::factory()->post->create(
			[
				'post_excerpt' => wp_slash( $excerpt ),
				'post_status'  => 'draft',
			]
		);

		$this->assertSame( $excerpt, get_post_field( 'post_excerpt', $post_id, 'raw' ) );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => wp_slash( 'Rewritten body.' ),
			]
		);

		$this->assertSame(
			$excerpt,
			get_post_field( 'post_excerpt', $post_id, 'raw' ),
			'An update which never mentions the excerpt still re-saves it, which is how the revert tool would have destroyed one.'
		);

		$rendered = (string) apply_filters( 'get_the_excerpt', $excerpt );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Running content through core's own hooks is what an integration test does.

		$this->assertStringNotContainsString( '[php]', $rendered, 'Stripping is a display decision, and it still happens.' );
		$this->assertStringNotContainsString( 'echo 1;', $rendered );

	}

	/**
	 * A block whose code names this plugin's tags does not swallow the snippet which
	 * follows it.
	 *
	 * The shortcode matcher declines a match which begins inside a block delimiter,
	 * because a delimiter's JSON is the block's data and not content. Declining has to
	 * mean "resume after the delimiter", not "consume this span and carry on" — the
	 * span a declined match covers can reach past the delimiter, and everything in it
	 * is then never offered to the matcher at all. The snippet after it reaches KSES
	 * unprotected and the author loses the lines KSES does not allow.
	 *
	 * @return void
	 */
	public function test_a_block_naming_plugin_tags_does_not_swallow_a_later_snippet(): void {

		$this->_become_contributor();

		$content = sprintf(
			"%s\n\n%s",
			static::_block(
				Block::NAME,
				[
					'code'     => "// wrap the snippet in [php] and close it after\n",
					'language' => 'php',
				]
			),
			self::HOSTILE_CONTENT
		);

		$this->assertSame( $content, $this->_store( $content ) );

	}

	/**
	 * And the same two the other way round, which is the order that always worked.
	 *
	 * @return void
	 */
	public function test_a_snippet_before_a_block_naming_plugin_tags_is_still_protected(): void {

		$this->_become_contributor();

		$content = sprintf(
			"%s\n\n%s",
			self::HOSTILE_CONTENT,
			static::_block(
				Block::NAME,
				[
					'code'     => "// wrap the snippet in [php] and close it after\n",
					'language' => 'php',
				]
			)
		);

		$this->assertSame( $content, $this->_store( $content ) );

	}

	/**
	 * A post far past the size at which PCRE gives up is stored exactly as it was
	 * written.
	 *
	 * A tempered pattern over a block delimiter's attributes backtracks
	 * catastrophically. Somewhere around 32 KB `preg_replace_callback()` stops and
	 * returns NULL, and `(string) NULL` is the empty string — so on `content_save_pre`
	 * the entire post is stored empty. Not truncated: gone. A snippet is exactly the
	 * kind of content which runs to that size, and this is the only fixture in the
	 * suite big enough to reach it.
	 *
	 * @return void
	 */
	public function test_a_post_far_past_pcres_ceiling_is_stored_intact(): void {

		$this->_become_contributor();

		$code = str_repeat( "function f() { return 1; }    // [php]echo 1;[/php] <>&\"'\n", 3000 );

		$content = sprintf(
			"PREFIX\n\n%s\n\n[php]echo 2;[/php]\n\nSUFFIX",
			static::_block(
				Block::NAME,
				[
					'code'     => $code,
					'language' => 'php',
				]
			)
		);

		$this->assertGreaterThan( 100 * 1024, strlen( $content ), 'The fixture has to be well past where PCRE gives up.' );

		$this->assertSame( $content, $this->_store( $content ) );

	}

	/**
	 * A snippet whose code is a block delimiter is code, not a block.
	 *
	 * Restoration is one pass and never re-reads what it has just put back, so a
	 * snippet stashed with a placeholder already inside it would come out of the
	 * database with that placeholder still in it.
	 *
	 * @return void
	 */
	public function test_a_block_delimiter_inside_a_snippet_survives_storage(): void {

		$this->_become_contributor();

		$content = sprintf(
			'[sourcecode language="html"]%s[/sourcecode]',
			static::_block( Block::NAME, [ 'code' => 'echo 1;' ] )
		);

		$this->assertSame( $content, $this->_store( $content ) );

	}

	/**
	 * A snippet an author wrote inside an HTML comment survives the round trip.
	 *
	 * A placeholder carrying `-->` closes the comment it lands inside. KSES then
	 * strips the comment markers out of what it takes to be one comment, wraps the
	 * remains in fresh ones and escapes the orphaned tail — and no placeholder is
	 * left for the restore pass to find, so the code is gone for good.
	 *
	 * @return void
	 */
	public function test_a_snippet_inside_an_html_comment_survives_storage(): void {

		$this->_become_contributor();

		$content = '<!-- note: [php]echo 1;[/php] -->';

		$this->assertSame( $content, $this->_store( $content ) );

	}

	/**
	 * A placeholder shaped token an author typed is text, not a placeholder.
	 *
	 * Every key carries a per request salt, so nothing written by hand can ever name
	 * a stashed entry.
	 *
	 * @return void
	 */
	public function test_a_placeholder_an_author_typed_is_left_alone(): void {

		$content = sprintf( 'Before %s after.', Content_Protector::get_placeholder( str_repeat( 'a', 32 ) ) );

		$this->assertSame( $content, $this->_store( $content ) );
		$this->assertStringContainsString( $content, (string) apply_filters( 'the_content', $content ) );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Running content through core's own hooks is what an integration test does.

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
