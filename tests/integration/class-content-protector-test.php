<?php
/**
 * Tests for the protect-then-restore pipeline.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Block;
use iG\Syntax_Hiliter\Content_Protector;
use iG\Syntax_Hiliter\Gist_Embed;
use iG\Syntax_Hiliter\Renderer;
use iG\Syntax_Hiliter\Shortcode_Handler;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

/**
 * Code is lifted out of the content before any filter runs and put back after the
 * last one has finished: byte identical on save, rendered on display, and never seen
 * by the filter chain in between.
 */
class Content_Protector_Test extends WP_UnitTestCase {

	use Pipeline_Test_Helpers;

	/**
	 * Content used by most of the tests here. Every part of it is something KSES
	 * would remove or rewrite if it were given the chance.
	 *
	 * @var string
	 */
	protected const string _HOSTILE_CONTENT = "Intro paragraph.\n\n[php]\n<script src=\"https://example.com/a.js\"></script>\n<?php echo '<div>' . \$a . '</div>'; ?>\n\$re = '/\\d+\\s\"x\"/';\n[/php]\n\nOutro paragraph.";  // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Test fixture standing in for author written code, not markup this plugin emits.

	/**
	 * A snippet whose code is a whole snippet, with both tags escaped.
	 *
	 * @var string
	 */
	protected const string _CONTENT = "[sourcecode language=\"php\"]\n[[sourcecode language=\"php\"]]\nfunction f() {}\n[[/sourcecode]]\n[/sourcecode]";

	/**
	 * The digit zero: code, not an empty snippet.
	 *
	 * @var string
	 */
	protected const string _ZERO = '0';

	/**
	 * Content captured mid chain by the spy filter.
	 *
	 * @var string
	 */
	protected string $_captured = '';

	/**
	 * Registers the pipeline, the Gist pipeline and the block once WordPress is up.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		Shortcode_Handler::get_instance();
		Gist_Embed::get_instance();

		// An unregistered dynamic block renders as nothing, which is the failure some tests here watch for.
		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( Block::NAME ) ) {
			Block::get_instance()->register_block();
		}

		$this->_captured = '';

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
	 * Method to store content the way a classic editor post stores it, and hand back
	 * exactly what landed in the database.
	 *
	 * @param string $content Post content.
	 *
	 * @return string
	 */
	protected function _store_unconverted( string $content ): string {

		$post_id = self::factory()->post->create(
			[
				'post_content' => wp_slash( $content ),
			]
		);

		$stored = (string) get_post_field( 'post_content', $post_id, 'raw' );

		$this->assertSame( $content, $stored, 'Storage must not touch a byte of unconverted content.' );

		return $stored;

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
	 * The attribute part of core's shortcode pattern is a lazily quantified group inside
	 * another, so an unclosed `[php ` makes PCRE walk every partition before giving up.
	 * The ordinary snippet in front of it puts the walk half way through, which is the
	 * only state in which giving up has anything to undo.
	 *
	 * @return string
	 */
	protected static function _matcher_killing_content(): string {
		return "[php]echo 1;[/php]\n\n[php " . str_repeat( 'a/', 50000 ) . ' ] end';
	}

	/**
	 * A hostile filter: it strips script tags and turns bare URLs into links.
	 *
	 * @param string $content Content being filtered.
	 *
	 * @return string
	 */
	public function hostile_filter( $content ) {

		$content = (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $content );

		return (string) preg_replace( '#(https?://[^\s<]+)#i', '<a href="$1">$1</a>', $content );

	}

	/**
	 * Records what the filter chain is carrying at the point it is hooked.
	 *
	 * @param string $content Content being filtered.
	 *
	 * @return string
	 */
	public function spy_filter( $content ) {

		$this->_captured = (string) $content;

		return $content;

	}

	/**
	 * A contributor saves a script tag inside a snippet, the stored content is byte
	 * identical to what was submitted, and saving it again changes nothing.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_lets_a_contributor_save_byte_identical_content_and_resave_with_no_diff(): void {

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
	 * @test
	 *
	 * @return void
	 */
	public function it_stores_the_same_bytes_in_revisions(): void {

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
	 * Content outside a snippet is left to KSES. This is also what proves KSES is live
	 * for the tests above.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_still_filters_markup_outside_a_snippet(): void {

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
	 * Byte identical storage through the block path.
	 *
	 * KSES reaches block attributes through `wp_pre_kses_block_attributes()`, so an
	 * unprotected author without `unfiltered_html` loses the script line and gets
	 * `<`, `'` and `&` entity encoded into the database.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_lets_a_contributor_save_block_code_byte_identical_and_resave_with_no_diff(): void {

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
	 * @test
	 *
	 * @return void
	 */
	public function it_still_filters_another_plugins_block_on_save(): void {

		$this->_become_contributor();

		$content = static::_block( [ 'text' => '<script>alert(1)</script>' ], 'acme/notice' );

		$stored = $this->_store( $content );

		$this->assertNotSame( $content, $stored, 'KSES still gets to rewrite a block this plugin does not own.' );
		$this->assertStringNotContainsString( 'script', $stored );

	}

	/**
	 * A block whose code names this plugin's tags does not swallow the snippet which
	 * follows it. Declining a match which begins inside a delimiter has to mean
	 * "resume after the delimiter", since the declined span can reach past it.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_does_not_let_a_block_naming_plugin_tags_swallow_a_later_snippet(): void {

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
	 * @test
	 *
	 * @return void
	 */
	public function it_still_protects_a_snippet_before_a_block_naming_plugin_tags(): void {

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
	 * written. A tempered pattern over a delimiter's attributes backtracks
	 * catastrophically around 32 KB, and a NULL from `preg_replace_callback()` cast
	 * to string stores the whole post as empty.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_stores_a_post_far_past_pcres_ceiling_intact(): void {

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
	 * A save pass the shortcode matcher gave up on protects nothing at all, not even
	 * the snippet it had already reached.
	 *
	 * A pass which gave up half way would hand on placeholders for the snippets it got
	 * to, and the restore pass over content PCRE has just failed on is the one most
	 * likely to give up in its turn, storing `{igshx…}` where the code was.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_protects_nothing_on_a_save_pass_the_matcher_gave_up_on(): void {

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
	 * `preg_replace_callback()` hands back NULL on a backtrack, recursion or JIT stack
	 * limit, and `(string) NULL` on `content_save_pre` stores the post as zero bytes.
	 * The snippet's code is lost either way; what the guard buys is the rest of the
	 * post. PCRE is crippled between the two passes, not for the whole request,
	 * because the protect pass has to succeed for there to be anything to restore.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_does_not_empty_the_post_on_a_restore_pass_that_gave_up(): void {

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
	 * A snippet whose code is a block delimiter is code, not a block. Restoration is
	 * one pass, so a snippet stashed with a placeholder inside it would be stored
	 * with that placeholder still in it.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_keeps_a_block_delimiter_inside_a_snippet_through_storage(): void {

		$this->_become_contributor();

		$content = sprintf(
			'[sourcecode language="html"]%s[/sourcecode]',
			static::_block( [ 'code' => 'echo 1;' ] )
		);

		$this->assertSame( $content, $this->_store( $content ) );

	}

	/**
	 * A snippet an author wrote inside an HTML comment survives the round trip. A
	 * placeholder carrying `-->` would close the comment, KSES would rewrite the
	 * remains, and no placeholder would be left for the restore pass to find.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_keeps_a_snippet_inside_an_html_comment_through_storage(): void {

		$this->_become_contributor();

		$content = '<!-- note: [php]echo 1;[/php] -->';

		$this->assertSame( $content, $this->_store( $content ) );

	}

	/**
	 * A placeholder shaped token an author typed is text, not a placeholder: every
	 * key carries a per request salt.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_leaves_a_placeholder_an_author_typed_alone(): void {

		$content = sprintf( 'Before %s after.', Content_Protector::get_instance()->get_placeholder( str_repeat( 'a', 32 ) ) );

		$this->assertSame( $content, $this->_store( $content ) );
		$this->assertStringContainsString( $content, (string) apply_filters( 'the_content', $content ) );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Running content through core's own hooks is what an integration test does.

	}

	/**
	 * A user who can post unfiltered HTML still gets byte identical storage.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_also_lets_an_administrator_save_byte_identical_content(): void {

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$post_id = self::factory()->post->create(
			[
				'post_content' => wp_slash( self::_HOSTILE_CONTENT ),
				'post_status'  => 'draft',
			]
		);

		$this->assertSame( self::_HOSTILE_CONTENT, get_post_field( 'post_content', $post_id, 'raw' ) );

	}

	/**
	 * A display pass the shortcode matcher gave up on protects nothing at all, not
	 * even the snippet it had already reached.
	 *
	 * A pass which gave up half way would leave placeholders for the snippets it got to
	 * and raw bytes for the rest, and the restore pass runs over the same content PCRE
	 * just refused. Giving up has to mean the content was left as it was found.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_protects_nothing_on_a_display_pass_the_matcher_gave_up_on(): void {

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
	 * The display chain runs the delimiter scan and the shortcode walk on every page
	 * view, with `do_blocks()` between the two passes. The fixture's code names this
	 * plugin's own tags three thousand times over, which the delimiter scan has to keep
	 * the matcher away from.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_renders_a_post_far_past_pcres_ceiling_intact(): void {

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
			substr_count( $rendered, Renderer::get_instance()->escape_verbatim( $line ) ),
			'Every line of the code came back, escaped as the author typed it and not one line short.'
		);

		$this->assertStringContainsString( 'echo 2;', $rendered, 'And the snippet beside the block rendered too.' );

	}

	/**
	 * A shortcode with nothing in it renders nothing, exactly as it did before.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_renders_nothing_for_an_empty_snippet(): void {

		$output = $this->_filter( 'the_content', 'before[php][/php]after' );

		$this->assertStringNotContainsString( '<pre ', $output );

		// Container included: a wrapper around no box is still a box on the page.
		$this->assertStringNotContainsString( 'igsh-code-box', $output );

		$this->assertStringNotContainsString( '[php]', $output );
		$this->assertStringContainsString( 'before', $output );
		$this->assertStringContainsString( 'after', $output );

	}

	/**
	 * While the filter chain runs, the content holds no code.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_never_shows_the_code_to_the_filter_chain(): void {

		add_filter( 'the_content', [ $this, 'spy_filter' ], 50 );

		$this->_filter( 'the_content', "[php]\n\$secret = 'nothing should see this';\n[/php]" );

		remove_filter( 'the_content', [ $this, 'spy_filter' ], 50 );

		$this->assertStringContainsString( Content_Protector::PLACEHOLDER_PREFIX, $this->_captured );
		$this->assertStringNotContainsString( 'nothing should see this', $this->_captured );
		$this->assertStringNotContainsString( '[php]', $this->_captured );

	}

	/**
	 * A shortcode written inside another plugin's block attributes is that plugin's
	 * text, not a snippet.
	 *
	 * `serialize_block_attributes()` escapes `<`, `>`, `&` and `--` but neither bracket,
	 * so a matcher blind to delimiters would rewrite inside one.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_leaves_a_shortcode_inside_another_plugins_block_delimiter_alone(): void {

		$delimiter = '<!-- wp:acme/notice {"text":"Try [php]echo 1;[/php] today"} /-->';

		add_filter( 'the_content', [ $this, 'spy_filter' ], 5 );

		$this->_filter( 'the_content', $delimiter );

		remove_filter( 'the_content', [ $this, 'spy_filter' ], 5 );

		$this->assertSame( $delimiter, $this->_captured, 'The delimiter reached the block parser exactly as it was written.' );

	}

	/**
	 * A script tag inside a code box survives a filter at priority 10 that strips
	 * scripts and autolinks URLs.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_keeps_a_script_tag_through_a_hostile_filter(): void {

		$code = '<script src="https://example.com/thing.js"></script>';  // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Test fixture standing in for author written code, not markup this plugin emits.

		add_filter( 'the_content', [ $this, 'hostile_filter' ], 10 );

		$output = $this->_filter( 'the_content', sprintf( "[php]\n%s\n[/php]", $code ) );

		remove_filter( 'the_content', [ $this, 'hostile_filter' ], 10 );

		$this->assertStringContainsString( esc_html( $code ), $output );
		$this->assertStringNotContainsString( '<a href="https://example.com/thing.js"', $output );

		// Texturize would have curled the quotes had it been given the chance.
		$this->assertStringNotContainsString( '&#8220;', $output );
		$this->assertStringNotContainsString( '&#8221;', $output );

	}

	/**
	 * A code box never ends up inside a paragraph, which is what would happen if the
	 * placeholder were not block level by the time `wpautop` reached it.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_does_not_wrap_a_code_box_in_a_paragraph(): void {

		$output = $this->_filter( 'the_content', "Some text:\n[php]echo 1;[/php]\nMore text" );

		// The container is the top level element, so it is the one `wpautop` could wrap; checking the `pre` alone would miss it.
		$this->assertStringNotContainsString( '<p><div class="igsh-code-box"', $output );
		$this->assertStringNotContainsString( '<br />' . "\n" . '<div class="igsh-code-box"', $output );

		$this->assertStringNotContainsString( '<p><pre', $output );
		$this->assertStringNotContainsString( '<br />' . "\n" . '<pre', $output );
		$this->assertStringContainsString( '<pre ', $output );
		$this->assertStringContainsString( 'Some text:', $output );
		$this->assertStringContainsString( 'More text', $output );

	}

	/**
	 * The seam the block's render callback needs: `do_blocks()` runs at priority 9,
	 * so a callback which stashes its markup during a protected run gets the same
	 * immunity a shortcode gets.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_lets_a_render_callback_stash_its_markup(): void {

		$protector = Content_Protector::get_instance();
		$markup    = '<pre id="from-a-render-callback"><code>fetch( "https://example.com/x.js" );</code></pre>';

		$this->assertFalse( $protector->is_protecting(), 'Nothing is in flight before a filter runs.' );

		$callback = static function ( $content ) use ( $protector, $markup ) {

			if ( ! $protector->is_protecting() ) {
				return $content;
			}

			return $content . $protector->stash_markup( $markup );

		};

		add_filter( 'the_content', $callback, 9 );
		add_filter( 'the_content', [ $this, 'hostile_filter' ], 10 );

		$output = $this->_filter( 'the_content', 'Text.' );

		remove_filter( 'the_content', $callback, 9 );
		remove_filter( 'the_content', [ $this, 'hostile_filter' ], 10 );

		$this->assertStringContainsString( $markup, $output );
		$this->assertStringNotContainsString( '<a href="https://example.com/x.js"', $output );
		$this->assertFalse( $protector->is_protecting(), 'The run is over once the content has been restored.' );

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
	 * A run left in flight renders normally rather than emitting a placeholder
	 * nothing will restore.
	 *
	 * The protect pass and the restore pass are two separate filter callbacks, so no
	 * frame spans both and no `finally` can close the pair. A filter which hands back
	 * something that is not content — or which throws, or which tears the chain down
	 * — leaves the run open.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_does_not_let_a_run_left_in_flight_swallow_a_later_code_box(): void {

		$protector = Content_Protector::get_instance();

		$breaker = static function () {
			return null;
		};

		add_filter( 'the_content', $breaker, 50 );

		$this->_filter( 'the_content', '[php]echo 1;[/php]' );

		remove_filter( 'the_content', $breaker, 50 );

		$this->assertFalse( $protector->is_protecting(), 'The chain never reached the restore pass, so nothing is in flight.' );

		$markup = Block::get_instance()->render(
			[
				'code'     => 'echo 2;',
				'language' => 'php',
			]
		);

		$this->assertStringContainsString( '<pre ', $markup );
		$this->assertStringNotContainsString( Content_Protector::PLACEHOLDER_PREFIX, $markup );

	}

	/**
	 * A Gist reference inside a snippet is code, not a Gist.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_leaves_a_gist_tag_inside_a_snippet_as_code(): void {

		Shortcode_Handler::get_instance();

		$output = $this->_filter( 'the_content', '[php][github id="abc123"][/php]' );

		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringContainsString( '[github id=&quot;abc123&quot;]', $output );

	}

	/**
	 * Editing and saving a post over and over neither eats a bracket nor turns the
	 * author's example into a snippet.
	 *
	 * Rendering between the saves is the point: what the reader is shown is not what
	 * goes back to the database.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_keeps_an_escaped_shortcode_through_repeated_edits(): void {

		$content = '[[php]echo 1;[/php]]';
		$post_id = self::factory()->post->create(
			[
				'post_content' => wp_slash( $content ),
			]
		);

		for ( $round = 1; $round <= 3; ++$round ) {

			$stored = (string) get_post_field( 'post_content', $post_id, 'raw' );

			$this->assertSame( $content, $stored, sprintf( 'Round %d changed what is stored.', $round ) );

			$output = $this->_filter( 'the_content', $stored );

			$this->assertStringContainsString( '[php]echo 1;[/php]', $output, sprintf( 'Round %d lost the text.', $round ) );
			$this->assertStringNotContainsString( '[[php]', $output, sprintf( 'Round %d showed the reader the escape.', $round ) );
			$this->assertStringNotContainsString( '[/php]]', $output, sprintf( 'Round %d showed the reader the closing escape.', $round ) );
			$this->assertStringNotContainsString( '<pre', $output, sprintf( 'Round %d rendered a code box.', $round ) );

			wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => wp_slash( $stored ),
				]
			);

		}

	}

} // end of class

// EOF
