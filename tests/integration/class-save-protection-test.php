<?php
/**
 * Tests for save time protection of snippet code.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Content_Protector;
use iG\Syntax_Hiliter\Shortcode_Handler;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use WP_UnitTestCase;

/**
 * Checks that a user without `unfiltered_html` can store code which KSES would
 * otherwise destroy, and that what lands in the database is byte for byte what was
 * submitted.
 */
class Save_Protection_Test extends WP_UnitTestCase {

	use Pipeline_Test_Helpers;

	/**
	 * Content used by most of the tests here. Every part of it is something KSES
	 * would remove or rewrite if it were given the chance.
	 *
	 * @var string
	 */
	protected const string _HOSTILE_CONTENT = "Intro paragraph.\n\n[php]\n<script src=\"https://example.com/a.js\"></script>\n<?php echo '<div>' . \$a . '</div>'; ?>\n\$re = '/\\d+\\s\"x\"/';\n[/php]\n\nOutro paragraph.";  // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Test fixture standing in for author written code, not markup this plugin emits.

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
	 * The filter pair is symmetric: what goes in comes out, slashes and all.
	 *
	 * @return void
	 */
	public function test_the_save_filters_are_a_round_trip(): void {

		$slashed = wp_slash( self::_HOSTILE_CONTENT );

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
				'post_content' => wp_slash( self::_HOSTILE_CONTENT ),
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

		$this->assertSame( self::_HOSTILE_CONTENT, $first );
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
				'post_content' => wp_slash( self::_HOSTILE_CONTENT ),
				'post_status'  => 'publish',
			]
		);

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => wp_slash( self::_HOSTILE_CONTENT ),
				'post_title'   => 'Changed title',
			]
		);

		$revisions = wp_get_post_revisions( $post_id );

		$this->assertNotEmpty( $revisions, 'The post type supports revisions, so there should be at least one.' );

		foreach ( $revisions as $revision ) {
			$this->assertSame( self::_HOSTILE_CONTENT, $revision->post_content );
		}

		$this->assertSame( self::_HOSTILE_CONTENT, get_post_field( 'post_content', $post_id, 'raw' ) );

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

		$content = static::_block( [ 'text' => '<script>alert(1)</script>' ], 'acme/notice' );

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
				[
					'code'     => "// wrap the snippet in [php] and close it after\n",
					'language' => 'php',
				]
			),
			self::_HOSTILE_CONTENT
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
			self::_HOSTILE_CONTENT,
			static::_block(
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
	 * A save pass the shortcode matcher gave up on protects nothing at all — not even
	 * the snippet it had already reached.
	 *
	 * `preg_match()` reports a limit it hit in a way a caller reading the return value
	 * alone cannot tell from "there is nothing more here", and the walk over the
	 * content is resumable, so a pass which gave up half way would hand back content
	 * carrying placeholders for the snippets it got to and the raw bytes of the ones it
	 * did not. That stash is only ever unwound by the restore pass at the far end of
	 * the same chain, and this is content PCRE has just proved it cannot cope with — so
	 * the pass which has to put those placeholders back is the one most likely to give
	 * up in its turn, and what reaches the database is a post with `{igshx…}` where its
	 * code used to be. Whatever else giving up means, it has to mean the content was
	 * left as it was found.
	 *
	 * @return void
	 */
	public function test_a_save_pass_the_matcher_gave_up_on_protects_nothing(): void {

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$content   = static::_matcher_killing_content();
		$protector = Content_Protector::get_instance();
		$limit     = (string) ini_get( 'pcre.backtrack_limit' );

		// Pinned to PHP's own default so that a php.ini which lifts the ceiling turns this into a slow test rather than a hung one. Put back below.
		ini_set( 'pcre.backtrack_limit', '1000000' );  // phpcs:ignore WordPress.PHP.IniSet.Risky -- See above.

		$protected = $protector->protect_for_save( $content );
		$error     = preg_last_error();
		$restored  = $protector->restore_verbatim( $protected );
		$filtered  = $this->_filter( 'content_save_pre', $content );

		ini_set( 'pcre.backtrack_limit', $limit );  // phpcs:ignore WordPress.PHP.IniSet.Risky -- Putting back the value saved above.

		$this->assertNotSame( PREG_NO_ERROR, $error, 'PCRE did not give up, so this is not the test it says it is.' );

		$this->assertNotSame( '', $protected );
		$this->assertSame( $content, $protected, 'A pass the matcher gave up on stashed the snippet it had already reached.' );
		$this->assertSame( $content, $restored, 'And there was nothing for the restore pass to put back.' );

		$this->assertNotSame( '', $filtered );
		$this->assertSame( $content, $filtered, 'The post which provoked it is stored exactly as it was written.' );

	}

	/**
	 * A restore pass which gave up does not store the post as nothing at all.
	 *
	 * This is the shape the shipped bug took. `preg_replace_callback()` hands back NULL
	 * when it hits a backtrack, recursion or JIT stack limit, `(string) NULL` is the
	 * empty string, and the restore pass sits on `content_save_pre` — so a post whose
	 * size defeated PCRE was written to the database as zero bytes. Not truncated:
	 * gone, along with every revision of it that the same request re-saved.
	 *
	 * The code inside the snippet is lost here either way: once the pass which puts the
	 * author's bytes back has given up, the placeholder standing in for them is all
	 * there is, and the stash it names does not outlive the request. What the guard
	 * buys is the rest of the post — the prose, the other blocks, everything the
	 * snippet was surrounded by — which is the difference between an edit an author can
	 * see and undo and an entry that has to be recovered from a backup.
	 *
	 * PCRE is crippled between the two passes rather than for the whole request,
	 * because the protect pass has to succeed for there to be anything to restore.
	 *
	 * @return void
	 */
	public function test_a_restore_pass_that_gave_up_does_not_empty_the_post(): void {

		$this->_become_contributor();

		$limit = (string) ini_get( 'pcre.backtrack_limit' );
		$error = PREG_NO_ERROR;
		$mend  = Shortcode_Handler::PRIORITY_RESTORE + 1;

		$cripple = static function ( $content ) {

			// A limit of zero is the one value every pattern fails against, however cheap it is to match. Put back by the callback below.
			ini_set( 'pcre.backtrack_limit', '0' );  // phpcs:ignore WordPress.PHP.IniSet.Risky -- See above.

			return $content;

		};

		$repair = static function ( $content ) use ( $limit, &$error ) {

			$error = preg_last_error();

			ini_set( 'pcre.backtrack_limit', $limit );  // phpcs:ignore WordPress.PHP.IniSet.Risky -- Putting back the value the callback above replaced.

			return $content;

		};

		add_filter( 'content_save_pre', $cripple, 50 );
		add_filter( 'content_save_pre', $repair, $mend );

		try {
			$stored = $this->_filter( 'content_save_pre', self::_HOSTILE_CONTENT );
		} finally {

			ini_set( 'pcre.backtrack_limit', $limit );  // phpcs:ignore WordPress.PHP.IniSet.Risky -- The callback above puts it back on the way through; this is the one that runs when the chain does not get that far.

			remove_filter( 'content_save_pre', $cripple, 50 );
			remove_filter( 'content_save_pre', $repair, $mend );

		}

		$this->assertNotSame( PREG_NO_ERROR, $error, 'PCRE did not give up, so this is not the test it says it is.' );

		$this->assertNotSame( '', $stored, 'The post was stored as nothing at all, which is the bug this exists to stop.' );
		$this->assertStringContainsString( 'Intro paragraph.', $stored, 'The prose in front of the snippet survived.' );
		$this->assertStringContainsString( 'Outro paragraph.', $stored, 'And the prose behind it.' );

		$this->assertStringContainsString(
			Content_Protector::PLACEHOLDER_PREFIX,
			$stored,
			'The snippet is still a placeholder, which is what proves the restore pass really did give up.'
		);

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
			static::_block( [ 'code' => 'echo 1;' ] )
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
				'post_content' => wp_slash( self::_HOSTILE_CONTENT ),
				'post_status'  => 'draft',
			]
		);

		$this->assertSame( self::_HOSTILE_CONTENT, get_post_field( 'post_content', $post_id, 'raw' ) );

	}

}    //end of class


//EOF
