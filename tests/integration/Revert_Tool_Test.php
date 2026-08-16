<?php
/**
 * Tests for the block to shortcode revert tool.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Admin;
use iG\Syntax_Hiliter\Block;
use iG\Syntax_Hiliter\Block_Converter;
use iG\Syntax_Hiliter\Legacy_Map;
use iG\Syntax_Hiliter\Renderer;
use iG\Syntax_Hiliter\Shortcode_Handler;
use iG\Syntax_Hiliter\Snippet;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The revert tool is a site owner's way out of the block format, and it rewrites
 * their content to give it to them. So the two things it must never do are damage
 * a byte it was not asked to touch, and leave a post behind.
 *
 * Everything here is about one of those two.
 */
class Revert_Tool_Test extends WP_UnitTestCase {

	/**
	 * Route the batches are fetched from.
	 *
	 * @var string
	 */
	const ROUTE = '/' . Admin::REST_NAMESPACE . '/revert';

	/**
	 * Code used by most of the fixtures. It carries braces, quotes and a closing
	 * HTML tag, all of which the delimiter pattern and the rewrite have to survive.
	 *
	 * @var string
	 */
	const CODE = "function f( \$a ) {\n\techo '<b>' . \$a . '</b>';\n}";

	/**
	 * Batch size used while the batching tests run.
	 *
	 * @var int
	 */
	protected int $_batch_size = 2;

	/**
	 * PCRE settings as they stood before a test lowered them.
	 *
	 * @var array
	 */
	protected array $_pcre_settings = [];

	/**
	 * Registers the pipeline and the routes.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		Shortcode_Handler::get_instance()->register_hooks();
		Admin::get_instance()->register_hooks();
		Block_Converter::get_instance()->register_hooks();

		/*
		 * The test case puts the hook registry back the way it found it after every
		 * test, and register_hooks() only ever runs once per process, so the action
		 * is put back by hand when it has been taken away.
		 */
		if ( false === has_action( 'rest_api_init', [ Block_Converter::get_instance(), 'register_rest_routes' ] ) ) {
			add_action( 'rest_api_init', [ Block_Converter::get_instance(), 'register_rest_routes' ] );
		}

		$GLOBALS['wp_rest_server'] = null;  // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Forcing a fresh REST server so the routes above are registered on it. The global is core's, not this plugin's.

		rest_get_server();

		add_filter( Block_Converter::FILTER_BATCH_SIZE, [ $this, 'get_batch_size' ] );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook name comes from the class constant it is named after.

	}

	/**
	 * Puts the PCRE settings back for whatever runs next, whether or not the test
	 * which lowered them got as far as putting them back itself.
	 *
	 * @return void
	 */
	public function tear_down(): void {

		$this->_restore_pcre();

		parent::tear_down();

	}

	/**
	 * Reports the batch size the current test wants.
	 *
	 * @return int
	 */
	public function get_batch_size(): int {
		return $this->_batch_size;
	}

	/**
	 * Method to lower the PCRE settings until an ordinary pattern gives up.
	 *
	 * The JIT goes as well as the limit. With it on, PCRE gives up only on a pattern it
	 * has real work to do, so what "PCRE has given up" means would depend on the subject
	 * each test happened to hand it.
	 *
	 * @return void
	 */
	protected function _make_pcre_give_up(): void {

		foreach ( [ 'pcre.jit', 'pcre.backtrack_limit' ] as $setting ) {
			$this->_pcre_settings[ $setting ] = (string) ini_get( $setting );
		}

		ini_set( 'pcre.jit', '0' );  // phpcs:ignore WordPress.PHP.IniSet.Risky -- Making PCRE give up on purpose is the only way to reach the branch under test. The values are put back below.
		ini_set( 'pcre.backtrack_limit', '0' );  // phpcs:ignore WordPress.PHP.IniSet.Risky -- As above.

	}

	/**
	 * Method to put back whatever self::_make_pcre_give_up() changed.
	 *
	 * @return void
	 */
	protected function _restore_pcre(): void {

		foreach ( $this->_pcre_settings as $setting => $value ) {
			ini_set( $setting, $value );  // phpcs:ignore WordPress.PHP.IniSet.Risky -- Putting back the values saved before they were lowered.
		}

		$this->_pcre_settings = [];

	}

	/**
	 * Method to check that PCRE really is giving up, so that a test which says it is
	 * measuring the failure branch is measuring it.
	 *
	 * @return bool
	 */
	protected function _pcre_is_giving_up(): bool {
		return ( null === preg_replace( '/\s+/', ' ', 'a  b' ) );
	}

	/**
	 * Method to build the block delimiter exactly as WordPress writes it.
	 *
	 * @param array $attributes Block attributes.
	 *
	 * @return string
	 */
	protected function _block( array $attributes ): string {

		return serialize_block(
			[
				'blockName'    => Block::NAME,
				'attrs'        => $attributes,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);

	}

	/**
	 * Method to become somebody who is allowed to run the tool.
	 *
	 * @return void
	 */
	protected function _become_administrator(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Method to run one batch and get the response body.
	 *
	 * @param int $cursor Id of the last post already handled.
	 *
	 * @return array
	 */
	protected function _process( int $cursor ): array {

		$request = new WP_REST_Request( 'POST', self::ROUTE );

		$request->set_body_params( [ 'cursor' => $cursor ] );

		return (array) rest_do_request( $request )->get_data();

	}

	/**
	 * Method to run the tool to completion, the way the settings page runs it.
	 *
	 * @return array Running totals, plus how many requests it took.
	 */
	protected function _run_to_completion(): array {

		$totals = [
			'processed'         => 0,
			'converted'         => 0,
			'skipped'           => 0,
			'failed'            => 0,
			'blocks_left_alone' => 0,
			'requests'          => 0,
		];

		$cursor = 0;

		do {

			$batch = $this->_process( $cursor );

			++$totals['requests'];

			foreach ( [ 'processed', 'converted', 'skipped', 'failed', 'blocks_left_alone' ] as $key ) {
				$totals[ $key ] += (int) $batch[ $key ];
			}

			$cursor = (int) $batch['cursor'];

			$this->assertLessThan( 40, $totals['requests'], 'The batching did not finish, so it is going round in circles.' );

		} while ( empty( $batch['done'] ) );

		return $totals;

	}

	/**
	 * The rewrite is surgical. The block delimiter becomes a shortcode and every
	 * other byte of the post is exactly where it was.
	 *
	 * @return void
	 */
	public function test_only_the_block_delimiter_is_rewritten(): void {

		$this->_become_administrator();

		$prefix = "<!-- wp:paragraph -->\n<p>Before the code, with a  double  space and an [email]a@b.com[/email] tag.</p>\n<!-- /wp:paragraph -->\n\n";
		$suffix = "\n\n<!-- wp:paragraph -->\n<p>After the code.</p>\n<!-- /wp:paragraph -->";

		$post_id = self::factory()->post->create(
			[
				'post_content' => wp_slash(
					$prefix . $this->_block(
						[
							'code'     => self::CODE,
							'language' => 'php',
						]
					) . $suffix
				),
			]
		);

		$this->_run_to_completion();

		$expected = sprintf( "[sourcecode language=\"php\"]\n%s\n[/sourcecode]", self::CODE );

		$this->assertSame( $prefix . $expected . $suffix, get_post_field( 'post_content', $post_id, 'raw' ) );

	}

	/**
	 * The shortcode the tool writes renders the same code box the block rendered.
	 * A revert which changed what the reader sees would not be a revert.
	 *
	 * @return void
	 */
	public function test_the_shortcode_renders_what_the_block_rendered(): void {

		$this->_become_administrator();

		$attributes = [
			'code'            => self::CODE,
			'language'        => 'php',
			'firstLine'       => 12,
			'highlightLines'  => '2,4-6',
			'file'            => 'example.php',
			'showLineNumbers' => true,
		];

		$renderer = Renderer::get_instance();

		$renderer->reset_counter();

		$from_block = $renderer->render_snippet(
			Snippet::from_block_attributes( $attributes, '', Shortcode_Handler::show_line_numbers() )
		);

		$post_id = self::factory()->post->create(
			[ 'post_content' => wp_slash( $this->_block( $attributes ) ) ]
		);

		$this->_run_to_completion();

		$renderer->reset_counter();

		$rendered = apply_filters( 'the_content', get_post_field( 'post_content', $post_id, 'raw' ) );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Running content through core's own hook is what an integration test does.

		// The fixture carries a file label, so the code box comes wrapped.
		$this->assertSame( 1, preg_match( '#<div class="igsh-code-box">.*?</pre></div>#s', $rendered, $matches ) );
		$this->assertSame( $from_block, $matches[0] );

	}

	/**
	 * Every block attribute finds its shortcode attribute, and one the block never
	 * set stays unset so that the site default goes on deciding.
	 *
	 * @return void
	 */
	public function test_block_attributes_map_on_to_shortcode_attributes(): void {

		$with_everything = Block_Converter::block_to_shortcode(
			[
				'code'            => 'x',
				'language'        => 'PHP',
				'showLineNumbers' => false,
				'firstLine'       => 12,
				'highlightLines'  => '4-6,2',
				'file'            => 'wp-config.php',
			]
		);

		$this->assertStringContainsString( 'language="php"', $with_everything );
		$this->assertStringContainsString( 'gutter="no"', $with_everything );
		$this->assertStringContainsString( 'firstline="12"', $with_everything );
		$this->assertStringContainsString( 'highlight="2,4-6"', $with_everything );
		$this->assertStringContainsString( 'file="wp-config.php"', $with_everything );

		$with_nothing = Block_Converter::block_to_shortcode(
			[
				'code'     => 'x',
				'language' => 'php',
			]
		);

		$this->assertSame( "[sourcecode language=\"php\"]\nx\n[/sourcecode]", $with_nothing );

	}

	/**
	 * A file label carrying the characters that end a shortcode attribute, or the
	 * shortcode itself, cannot break out of the shortcode it is written into.
	 *
	 * @return void
	 */
	public function test_a_hostile_file_label_cannot_break_the_shortcode(): void {

		$shortcode = (string) Block_Converter::block_to_shortcode(
			[
				'code'     => self::CODE,
				'language' => 'php',
				'file'     => 'we"ird] [php]name.php',
			]
		);

		$this->assertSame(
			1,
			preg_match( sprintf( '/%s/', get_shortcode_regex( [ 'sourcecode' ] ) ), $shortcode, $matches ),
			'The shortcode the tool wrote does not parse as one shortcode.'
		);

		$this->assertSame( $shortcode, $matches[0], 'Something outside the shortcode was left over.' );
		$this->assertSame( self::CODE, trim( $matches[5] ) );

		$atts = shortcode_parse_atts( $matches[3] );

		$this->assertSame( 'php', $atts['language'] );
		$this->assertSame( 'weird phpname.php', $atts['file'] );

	}

	/**
	 * The language used to be cleaned up by a pattern, and a pattern which gives up
	 * hands back NULL. Cast to a string that is an empty one, so a PCRE failure wrote
	 * `language=""` into every snippet the run rewrote and left the reader with
	 * unhighlighted code and nothing to explain it.
	 *
	 * The language here is one PCRE has to do real work on, because that is the only
	 * kind it ever gave up on: a language already made of nothing but safe characters
	 * matches nowhere, and a pattern which matches nowhere is never asked to backtrack.
	 * So a cleaned language coming back out of a run PCRE cannot complete is both halves
	 * of it — the name was kept, and what would have broken the shortcode was still
	 * taken off.
	 *
	 * @return void
	 */
	public function test_a_language_is_cleaned_up_even_when_pcre_has_given_up(): void {

		$this->_make_pcre_give_up();

		$shortcode = (string) Block_Converter::block_to_shortcode(
			[
				'code'     => self::CODE,
				'language' => 'PHP" ]',
			]
		);

		$gave_up = $this->_pcre_is_giving_up();

		$this->_restore_pcre();

		$this->assertTrue( $gave_up, 'PCRE ran to completion, so this is not the test it says it is.' );
		$this->assertStringContainsString( 'language="php"', $shortcode, 'The language came back blanked, or still carrying what breaks a shortcode.' );

	}

	/**
	 * The same failure on the file label, where the pattern is only tidying whitespace
	 * and everything which makes the label safe has already happened. So the label
	 * comes back untidy rather than not at all.
	 *
	 * @return void
	 */
	public function test_a_file_label_survives_a_pattern_pcre_gave_up_on(): void {

		$this->_make_pcre_give_up();

		$shortcode = (string) Block_Converter::block_to_shortcode(
			[
				'code'     => self::CODE,
				'language' => 'php',
				'file'     => 'my  notes.php',
			]
		);

		$gave_up = $this->_pcre_is_giving_up();

		$this->_restore_pcre();

		$this->assertTrue( $gave_up, 'PCRE ran to completion, so this is not the test it says it is.' );
		$this->assertStringContainsString( 'file="my  notes.php"', $shortcode, 'The label came back tidied, blanked or not at all.' );

	}

	/**
	 * A snippet whose code contains the closing tag cannot be written as a shortcode
	 * without losing the rest of it, so that block is left alone and reported rather
	 * than damaged.
	 *
	 * @return void
	 */
	public function test_a_snippet_that_cannot_be_a_shortcode_is_left_alone(): void {

		$this->_become_administrator();

		$content = $this->_block(
			[
				'code'     => 'echo "[/sourcecode]";',
				'language' => 'php',
			]
		);

		$post_id = self::factory()->post->create( [ 'post_content' => wp_slash( $content ) ] );

		$totals = $this->_run_to_completion();

		$this->assertSame( 0, $totals['converted'] );
		$this->assertSame( 1, $totals['skipped'] );
		$this->assertSame( 1, $totals['blocks_left_alone'], 'The post was left alone because a block of ours was, and only the block count says so.' );
		$this->assertSame( $content, get_post_field( 'post_content', $post_id, 'raw' ) );

	}

	/**
	 * A post can hold both kinds of block at once, and the post level buckets cannot
	 * say so: the post converted, so that is the bucket it lands in, and the block
	 * left behind is invisible in every one of them. The batch has to carry the block
	 * count as well, or the site owner is told the post is done while a snippet in it
	 * is still a block.
	 *
	 * @return void
	 */
	public function test_a_block_left_alone_is_reported_even_when_its_post_converted(): void {

		$this->_become_administrator();

		$left_alone = $this->_block(
			[
				'code'     => 'echo "[/sourcecode]";',
				'language' => 'php',
			]
		);

		$post_id = self::factory()->post->create(
			[
				'post_content' => wp_slash(
					$this->_block(
						[
							'code'     => self::CODE,
							'language' => 'php',
						]
					) . "\n\n" . $left_alone
				),
			]
		);

		$totals = $this->_run_to_completion();

		$this->assertSame( 1, $totals['converted'], 'The post converted, so that is the bucket it belongs in.' );
		$this->assertSame( 0, $totals['skipped'] + $totals['failed'], 'Nothing here is a post left alone or a post that failed.' );
		$this->assertSame( 1, $totals['blocks_left_alone'], 'The block left behind is reported nowhere else.' );

		$content = get_post_field( 'post_content', $post_id, 'raw' );

		$this->assertSame(
			sprintf( "[sourcecode language=\"php\"]\n%s\n[/sourcecode]\n\n%s", self::CODE, $left_alone ),
			$content
		);

	}

	/**
	 * A big snippet is the one most worth rescuing and the one a pattern gives up on,
	 * so the tool has to get through it. The fixture is built here rather than
	 * committed, and is far past the size at which the tempered pattern this replaced
	 * exhausted the PCRE JIT stack and quietly converted nothing.
	 *
	 * The code carries `[`, so the scan which finds where this plugin's shortcodes sit
	 * crosses the whole of it as well, and that scan is a pattern. A pattern which gave
	 * up here would take the conversion with it, because a rewrite that cannot tell a
	 * block from a snippet does nothing at all.
	 *
	 * The whole route is exercised, post and all. A snippet this size has to reach the
	 * database as a block, come back out, and go in again as a shortcode.
	 *
	 * @return void
	 */
	public function test_a_snippet_far_too_big_for_a_pattern_is_converted(): void {

		$this->_become_administrator();

		$code = '';

		for ( $line = 0; $line < 5000; $line++ ) {
			$code .= sprintf( "\$data[%d] = [ 'name' => \"row %d\", 'ok' => true ];\n", $line, $line );
		}

		$this->assertGreaterThan(
			200 * KB_IN_BYTES,
			strlen( $code ),
			'The fixture is not big enough to be the test it says it is.'
		);

		$content = "Before.\n\n" . $this->_block(
			[
				'code'     => $code,
				'language' => 'php',
			]
		) . "\n\nAfter.";

		$post_id = self::factory()->post->create( [ 'post_content' => wp_slash( $content ) ] );

		$this->assertSame(
			$content,
			get_post_field( 'post_content', $post_id, 'raw' ),
			'The fixture did not reach the database whole, so what follows would prove nothing.'
		);

		$totals = $this->_run_to_completion();

		$this->assertSame( 1, $totals['converted'] );
		$this->assertSame( 0, $totals['skipped'] + $totals['failed'] );

		$this->assertSame(
			sprintf( "Before.\n\n[sourcecode language=\"php\"]\n%s\n[/sourcecode]\n\nAfter.", $code ),
			get_post_field( 'post_content', $post_id, 'raw' )
		);

	}

	/**
	 * Code which reads like a delimiter does not end one. `serialize_block_attributes()`
	 * escapes `-`, `<` and `>` on the way in, which is what the end of the delimiter is
	 * found by, so the whole snippet comes back — braces, comment markers and all.
	 *
	 * As above, the rewrite is exercised on its own: a shortcode holding a delimiter in
	 * its code does not survive `Content_Protector` on the way to the database.
	 *
	 * @return void
	 */
	public function test_a_delimiter_lookalike_in_the_code_survives(): void {

		$code = sprintf(
			"function f() {\n\treturn { a: 1 };\n}\n// <!-- wp:%s {\"code\":\"gotcha\"} /--> and a bare --> as well\n",
			Block_Converter::BLOCK_NAME
		);

		$result = Block_Converter::convert_content(
			$this->_block(
				[
					'code'     => $code,
					'language' => 'php',
				]
			)
		);

		$this->assertSame( 1, $result['converted'] );

		$this->assertSame(
			sprintf( "[sourcecode language=\"php\"]\n%s\n[/sourcecode]", $code ),
			$result['content']
		);

	}

	/**
	 * The shortcode the tool writes carries the block's code verbatim, so a snippet
	 * about blocks still holds a delimiter afterwards and the post still matches the
	 * marker the tool searches on. The site owner is therefore offered the button
	 * again, and the second run has to leave the snippet exactly where the first run
	 * put it: rewriting a delimiter inside a shortcode nests one shortcode in another
	 * one's code, and everything past the inner closing tag stops being the snippet.
	 *
	 * @return void
	 */
	public function test_a_second_run_leaves_the_snippet_the_first_run_wrote_alone(): void {

		$this->_become_administrator();

		$code = sprintf(
			"function f() {\n\treturn { a: 1 };\n}\n// <!-- wp:%s {\"code\":\"gotcha\"} /--> is in a comment\n",
			Block_Converter::BLOCK_NAME
		);

		$post_id = self::factory()->post->create(
			[
				'post_content' => wp_slash(
					$this->_block(
						[
							'code'     => $code,
							'language' => 'php',
						]
					)
				),
			]
		);

		$first    = $this->_run_to_completion();
		$expected = sprintf( "[sourcecode language=\"php\"]\n%s\n[/sourcecode]", $code );

		$this->assertSame( 1, $first['converted'] );
		$this->assertSame( $expected, get_post_field( 'post_content', $post_id, 'raw' ) );

		$second = $this->_run_to_completion();

		$this->assertSame( 1, $second['processed'], 'The marker is still in the content, so the post is still handed out.' );
		$this->assertSame( 0, $second['converted'] + $second['failed'] );
		$this->assertSame( 1, $second['skipped'], 'Nothing in the post was rewritten, so the post was left alone.' );
		$this->assertSame( 0, $second['blocks_left_alone'], 'What is left is a snippet, not a block, so no block was left alone.' );

		$this->assertSame(
			$expected,
			get_post_field( 'post_content', $post_id, 'raw' ),
			'The second run rewrote the snippet the first run wrote.'
		);

		$this->assertSame(
			1,
			Block_Converter::count_remaining(),
			'The count is a LIKE over the content and the marker is still in it, so the post goes on being counted.'
		);

	}

	/**
	 * The scan which tells a snippet from a block is a pattern, and PCRE reports having
	 * given up on the content in a way that is indistinguishable from having found
	 * nothing. Read as "there are no shortcodes here", every delimiter inside one would
	 * be rewritten — which is the very damage this scan exists to prevent, on the
	 * content most likely to provoke it. So a scan that gave up rewrites nothing.
	 *
	 * @return void
	 */
	public function test_a_scan_pcre_gave_up_on_rewrites_nothing(): void {

		$content = sprintf(
			"[sourcecode language=\"php\"]\n// <!-- wp:%s {\"code\":\"gotcha\"} /-->\n[/sourcecode]\n\n",
			Block_Converter::BLOCK_NAME
		) . $this->_block(
			[
				'code'     => self::CODE,
				'language' => 'php',
			]
		);

		$limit = (string) ini_get( 'pcre.backtrack_limit' );

		ini_set( 'pcre.backtrack_limit', '1' );  // phpcs:ignore WordPress.PHP.IniSet.Risky -- Making PCRE give up on purpose is the only way to reach the branch under test. The value is put back below.

		$result = Block_Converter::convert_content( $content );

		ini_set( 'pcre.backtrack_limit', $limit );  // phpcs:ignore WordPress.PHP.IniSet.Risky -- Putting back the value saved above.

		$this->assertNotSame( PREG_NO_ERROR, preg_last_error(), 'PCRE did not give up, so this is not the test it says it is.' );
		$this->assertSame( 0, $result['converted'] + $result['skipped'] + $result['failed'] );
		$this->assertSame( $content, $result['content'], 'A scan that gave up rewrote the content anyway.' );

	}

	/**
	 * A delimiter inside a snippet is the author's text and a delimiter beside it is a
	 * block, and the tool has to tell the two apart in the same post. Leaving the block
	 * behind would be the fix for the case above overreaching.
	 *
	 * @return void
	 */
	public function test_a_block_beside_a_snippet_which_quotes_one_still_converts(): void {

		$this->_become_administrator();

		$quoted = sprintf(
			"[php]\n// <!-- wp:%s {\"code\":\"gotcha\"} /-->\n[/php]",
			Block_Converter::BLOCK_NAME
		);

		$post_id = self::factory()->post->create(
			[
				'post_content' => wp_slash(
					$quoted . "\n\n" . $this->_block(
						[
							'code'     => self::CODE,
							'language' => 'php',
						]
					)
				),
			]
		);

		$totals = $this->_run_to_completion();

		$this->assertSame( 1, $totals['converted'], 'The block beside the snippet is a block and had to convert.' );
		$this->assertSame( 0, $totals['skipped'] + $totals['failed'] + $totals['blocks_left_alone'] );

		$this->assertSame(
			sprintf( "%s\n\n[sourcecode language=\"php\"]\n%s\n[/sourcecode]", $quoted, self::CODE ),
			get_post_field( 'post_content', $post_id, 'raw' ),
			'The snippet quoting a delimiter is not byte identical to what went in.'
		);

	}

	/**
	 * `blocks_left_alone` counts blocks and the buckets count posts, so a post holding
	 * two blocks the tool declined is one of the first and two of the second. Nothing
	 * else in this file tells a count of blocks apart from a count of posts that had
	 * one.
	 *
	 * @return void
	 */
	public function test_two_blocks_left_alone_in_one_post_are_one_post_and_two_blocks(): void {

		$this->_become_administrator();

		$content = $this->_block(
			[
				'code'     => 'echo "[/sourcecode] one";',
				'language' => 'php',
			]
		) . "\n\n" . $this->_block(
			[
				'code'     => 'echo "[/sourcecode] two";',
				'language' => 'php',
			]
		);

		$post_id = self::factory()->post->create( [ 'post_content' => wp_slash( $content ) ] );

		$totals = $this->_run_to_completion();

		$this->assertSame( 0, $totals['converted'] + $totals['failed'] );
		$this->assertSame( 1, $totals['skipped'], 'The unit here is posts, and there is one post.' );
		$this->assertSame( 2, $totals['blocks_left_alone'], 'The unit here is blocks, and there are two.' );
		$this->assertSame( $content, get_post_field( 'post_content', $post_id, 'raw' ) );

	}

	/**
	 * A post can hold a delimiter nothing can read and a block the tool declined at
	 * once. The post is reported as a failure, which is the worse of the two, and the
	 * block count still has to carry the block that was left.
	 *
	 * @return void
	 */
	public function test_a_block_left_alone_is_reported_beside_a_delimiter_that_cannot_be_read(): void {

		$this->_become_administrator();

		$content = sprintf( '<!-- wp:%s {"code": "echo 1;",} /-->', Block_Converter::BLOCK_NAME ) . "\n\n" . $this->_block(
			[
				'code'     => 'echo "[/sourcecode]";',
				'language' => 'php',
			]
		);

		$post_id = self::factory()->post->create( [ 'post_content' => wp_slash( $content ) ] );

		$totals = $this->_run_to_completion();

		$this->assertSame( 1, $totals['failed'], 'A delimiter nothing can read is what the post is reported as.' );
		$this->assertSame( 0, $totals['converted'] + $totals['skipped'] );
		$this->assertSame( 1, $totals['blocks_left_alone'], 'The block left alone is reported whichever bucket the post lands in.' );
		$this->assertSame( $content, get_post_field( 'post_content', $post_id, 'raw' ) );

	}

	/**
	 * A post which converted and had a block declined, whose write then failed, is
	 * reported as a failure — and the block that was left is still a block that was
	 * left, because the post kept every byte it had.
	 *
	 * @return void
	 */
	public function test_a_block_left_alone_is_reported_when_the_write_fails(): void {

		$this->_become_administrator();

		$content = $this->_block(
			[
				'code'     => self::CODE,
				'language' => 'php',
			]
		) . "\n\n" . $this->_block(
			[
				'code'     => 'echo "[/sourcecode]";',
				'language' => 'php',
			]
		);

		$post_id = self::factory()->post->create( [ 'post_content' => wp_slash( $content ) ] );

		add_filter( 'wp_insert_post_empty_content', '__return_true' );

		$totals = $this->_run_to_completion();

		remove_filter( 'wp_insert_post_empty_content', '__return_true' );

		$this->assertSame( 1, $totals['failed'], 'The rewrite could not be written, so that is the bucket the post lands in.' );
		$this->assertSame( 0, $totals['converted'] + $totals['skipped'] );
		$this->assertSame( 1, $totals['blocks_left_alone'], 'The block left alone is reported whichever bucket the post lands in.' );
		$this->assertSame( $content, get_post_field( 'post_content', $post_id, 'raw' ), 'The write failed, so the post is exactly as it was.' );

	}

	/**
	 * Every post examined lands in exactly one bucket, so the three of them add up to
	 * what was processed. A progress meter is driven off that, and a post which fell
	 * through every branch or was counted twice would show up as a bar that never
	 * arrives or one that overshoots.
	 *
	 * @return void
	 */
	public function test_the_post_buckets_add_up_to_what_was_processed(): void {

		$this->_become_administrator();

		$this->_batch_size = 20;

		self::factory()->post->create(
			[
				'post_content' => wp_slash(
					$this->_block(
						[
							'code'     => self::CODE,
							'language' => 'php',
						]
					)
				),
			]
		);

		self::factory()->post->create(
			[
				'post_content' => wp_slash(
					$this->_block(
						[
							'code'     => 'echo "[/sourcecode]";',
							'language' => 'php',
						]
					)
				),
			]
		);

		self::factory()->post->create(
			[
				'post_content' => wp_slash(
					sprintf( '<!-- wp:%s {"code": "echo 1;",} /-->', Block_Converter::BLOCK_NAME )
				),
			]
		);

		$batch = $this->_process( 0 );

		$this->assertSame( 3, $batch['processed'] );
		$this->assertSame( 1, $batch['converted'], 'The batch is only a mixed one if every bucket got a post.' );
		$this->assertSame( 1, $batch['skipped'], 'The batch is only a mixed one if every bucket got a post.' );
		$this->assertSame( 1, $batch['failed'], 'The batch is only a mixed one if every bucket got a post.' );

		$this->assertSame(
			$batch['processed'],
			$batch['converted'] + $batch['skipped'] + $batch['failed'],
			'A post examined has to land in exactly one bucket.'
		);

	}

	/**
	 * A delimiter the tool cannot read is reported as a failure, not as a post it
	 * chose to leave alone. The two mean different things to a site owner: one is
	 * theirs to look at, the other is the tool working as intended.
	 *
	 * @return void
	 */
	public function test_a_delimiter_that_cannot_be_read_is_a_failure_and_not_a_skip(): void {

		$this->_become_administrator();

		$content = sprintf(
			"Before.\n\n<!-- wp:%s {\"code\": \"echo 1;\",} /-->\n\nAfter.",
			Block_Converter::BLOCK_NAME
		);

		$post_id = self::factory()->post->create( [ 'post_content' => wp_slash( $content ) ] );

		$totals = $this->_run_to_completion();

		$this->assertSame( 1, $totals['failed'] );
		$this->assertSame( 0, $totals['converted'] + $totals['skipped'] );
		$this->assertSame( $content, get_post_field( 'post_content', $post_id, 'raw' ) );

	}

	/**
	 * A delimiter which is not self closing is not this plugin's block, whatever its
	 * name says, and is left exactly where it was found.
	 *
	 * @return void
	 */
	public function test_a_delimiter_which_is_not_self_closing_is_left_byte_identical(): void {

		$this->_become_administrator();

		$content = sprintf(
			"<!-- wp:%1\$s {\"code\":\"echo 1;\"} -->\n<pre>echo 1;</pre>\n<!-- /wp:%1\$s -->",
			Block_Converter::BLOCK_NAME
		);

		$post_id = self::factory()->post->create( [ 'post_content' => wp_slash( $content ) ] );

		$totals = $this->_run_to_completion();

		$this->assertSame( 0, $totals['converted'] + $totals['failed'] );
		$this->assertSame( 1, $totals['skipped'] );
		$this->assertSame( 0, $totals['blocks_left_alone'], 'Nothing here is a block of this plugin\'s, so nothing was left alone.' );
		$this->assertSame( $content, get_post_field( 'post_content', $post_id, 'raw' ) );

	}

	/**
	 * A block carrying no code has nothing to show a reader, so it is taken out
	 * rather than written into the post as an empty shortcode.
	 *
	 * @return void
	 */
	public function test_a_block_carrying_no_code_is_removed(): void {

		$this->_become_administrator();

		$prefix = "Before.\n\n";
		$suffix = "\n\nAfter.";

		$post_id = self::factory()->post->create(
			[ 'post_content' => wp_slash( $prefix . $this->_block( [] ) . $suffix ) ]
		);

		$totals = $this->_run_to_completion();

		$this->assertSame( 1, $totals['converted'] );
		$this->assertSame( $prefix . $suffix, get_post_field( 'post_content', $post_id, 'raw' ) );
		$this->assertSame( 0, Block_Converter::count_remaining() );

	}

	/**
	 * The batching is cursor based, so it never slides over a post as the rows it
	 * is walking stop matching.
	 *
	 * @return void
	 */
	public function test_batching_gets_through_every_post(): void {

		$this->_become_administrator();

		$this->_batch_size = 2;

		$post_ids = [];

		for ( $i = 0; $i < 7; $i++ ) {
			$post_ids[] = self::factory()->post->create(
				[
					'post_content' => wp_slash(
						sprintf( 'Post %d.', $i ) . "\n\n" . $this->_block(
							[
								'code'     => sprintf( 'echo %d;', $i ),
								'language' => 'php',
							]
						)
					),
				]
			);
		}

		$totals = $this->_run_to_completion();

		$this->assertSame( 7, $totals['converted'] );
		$this->assertSame( 0, $totals['skipped'] + $totals['failed'] );
		$this->assertGreaterThan( 3, $totals['requests'], 'Seven posts in batches of two cannot be one request.' );
		$this->assertSame( 0, Block_Converter::count_remaining() );

		foreach ( $post_ids as $index => $post_id ) {

			$content = get_post_field( 'post_content', $post_id, 'raw' );

			$this->assertStringNotContainsString( Block_Converter::BLOCK_MARKER, $content );
			$this->assertStringContainsString( sprintf( "[sourcecode language=\"php\"]\necho %d;\n[/sourcecode]", $index ), $content );

		}

	}

	/**
	 * A run that stops halfway leaves the site in a state the next run picks up from,
	 * and the posts it already finished are not touched a second time.
	 *
	 * @return void
	 */
	public function test_an_interrupted_run_resumes_without_doing_anything_twice(): void {

		$this->_become_administrator();

		$this->_batch_size = 2;

		for ( $i = 0; $i < 5; $i++ ) {
			self::factory()->post->create(
				[
					'post_content' => wp_slash(
						$this->_block(
							[
								'code'     => sprintf( 'echo %d;', $i ),
								'language' => 'php',
							]
						)
					),
				]
			);
		}

		$first = $this->_process( 0 );

		$this->assertSame( 2, $first['converted'] );
		$this->assertFalse( $first['done'] );

		// The browser was closed, so the next run starts over from the beginning.
		$totals = $this->_run_to_completion();

		$this->assertSame( 3, $totals['converted'] );
		$this->assertSame( 0, Block_Converter::count_remaining() );

		$query = new \WP_Query(
			[
				'post_type'              => 'post',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		$this->assertCount( 5, $query->posts );

		foreach ( $query->posts as $post_id ) {
			$this->assertSame(
				1,
				substr_count( (string) get_post_field( 'post_content', $post_id, 'raw' ), '[sourcecode ' ),
				'A post already converted was converted again.'
			);
		}

	}

	/**
	 * The scope is public post types and every status but the two which mean the
	 * content is gone. Drafts and scheduled posts are in; the trash is not.
	 *
	 * @return void
	 */
	public function test_the_scope_covers_drafts_but_not_the_trash(): void {

		$this->_become_administrator();

		$this->_batch_size = 20;

		$content = $this->_block(
			[
				'code'     => 'echo 1;',
				'language' => 'php',
			]
		);

		$in  = [];
		$out = [];

		foreach ( [ 'publish', 'draft', 'pending', 'private', 'future' ] as $status ) {

			$args = [
				'post_content' => wp_slash( $content ),
				'post_status'  => $status,
			];

			if ( 'future' === $status ) {
				$args['post_date'] = gmdate( 'Y-m-d H:i:s', time() + YEAR_IN_SECONDS );
			}

			$in[ $status ] = self::factory()->post->create( $args );

		}

		foreach ( [ 'trash', 'auto-draft' ] as $status ) {
			$out[ $status ] = self::factory()->post->create(
				[
					'post_content' => wp_slash( $content ),
					'post_status'  => $status,
				]
			);
		}

		$this->_run_to_completion();

		foreach ( $in as $status => $post_id ) {
			$this->assertStringNotContainsString(
				Block_Converter::BLOCK_MARKER,
				(string) get_post_field( 'post_content', $post_id, 'raw' ),
				sprintf( 'A %s post was left holding a block.', $status )
			);
		}

		foreach ( $out as $status => $post_id ) {
			$this->assertSame(
				$content,
				get_post_field( 'post_content', $post_id, 'raw' ),
				sprintf( 'A %s post was rewritten, and it should not have been.', $status )
			);
		}

	}

	/**
	 * The route which rewrites content is behind the same check as the one which
	 * saves a setting, and a refusal changes nothing.
	 *
	 * @return void
	 */
	public function test_the_revert_route_refuses_anybody_who_may_not_manage_options(): void {

		$content = $this->_block(
			[
				'code'     => 'echo 1;',
				'language' => 'php',
			]
		);

		$post_id = self::factory()->post->create( [ 'post_content' => wp_slash( $content ) ] );

		wp_set_current_user( 0 );

		$this->assertSame( 401, rest_do_request( new WP_REST_Request( 'GET', self::ROUTE ) )->get_status() );
		$this->assertSame( 401, rest_do_request( new WP_REST_Request( 'POST', self::ROUTE ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', self::ROUTE ) )->get_status() );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'POST', self::ROUTE ) )->get_status() );

		$this->assertSame( $content, get_post_field( 'post_content', $post_id, 'raw' ) );

	}

	/**
	 * The tool writes the generic tag and only ever the generic tag, whatever the
	 * language was.
	 *
	 * @return void
	 */
	public function test_the_tool_always_writes_the_generic_tag(): void {

		$shortcode = (string) Block_Converter::block_to_shortcode(
			[
				'code'     => 'echo 1;',
				'language' => 'php',
			]
		);

		$this->assertSame( Legacy_Map::GENERIC_TAG, Block_Converter::SHORTCODE_TAG );
		$this->assertStringStartsWith( '[sourcecode ', $shortcode );
		$this->assertStringNotContainsString( '[php]', $shortcode );

	}

}    //end of class


//EOF
