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
	 * Reports the batch size the current test wants.
	 *
	 * @return int
	 */
	public function get_batch_size(): int {
		return $this->_batch_size;
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
			'processed' => 0,
			'converted' => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'requests'  => 0,
		];

		$cursor = 0;

		do {

			$batch = $this->_process( $cursor );

			++$totals['requests'];

			foreach ( [ 'processed', 'converted', 'skipped', 'failed' ] as $key ) {
				$totals[ $key ] += (int) $batch[ $key ];
			}

			$cursor = (int) $batch['cursor'];

			$this->assertLessThan( 40, $totals['requests'], 'The batching did not finish, so it is going round in circles.' );

		} while ( empty( $batch['done'] ) );

		return $totals;

	}

	/**
	 * Decision 20 — the rewrite is surgical. The block delimiter becomes a shortcode
	 * and every other byte of the post is exactly where it was.
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

		$this->assertSame( 1, preg_match( '#<pre .*?</pre>#s', $rendered, $matches ) );
		$this->assertSame( $from_block, $matches[0] );

	}

	/**
	 * Decision 20 — every block attribute finds its shortcode attribute, and one the
	 * block never set stays unset so that the site default goes on deciding.
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
		$this->assertSame( $content, get_post_field( 'post_content', $post_id, 'raw' ) );

	}

	/**
	 * A big snippet is the one most worth rescuing and the one a pattern gives up on,
	 * so the tool has to get through it. The fixture is built here rather than
	 * committed, and is far past the size at which the tempered pattern this replaced
	 * exhausted the PCRE JIT stack and quietly converted nothing.
	 *
	 * The rewrite is exercised on its own rather than over a post, because a post this
	 * size cannot be got into the database while `Content_Protector` carries a pattern
	 * of the same shape on `content_save_pre`.
	 *
	 * @return void
	 */
	public function test_a_snippet_far_too_big_for_a_pattern_is_converted(): void {

		$code = '';

		for ( $line = 0; $line < 3000; $line++ ) {
			$code .= sprintf( "\$data[%d] = [ 'name' => \"row %d\", 'ok' => true ];\n", $line, $line );
		}

		$this->assertGreaterThan(
			100 * KB_IN_BYTES,
			strlen( $code ),
			'The fixture is not big enough to be the test it says it is.'
		);

		$result = Block_Converter::convert_content(
			"Before.\n\n" . $this->_block(
				[
					'code'     => $code,
					'language' => 'php',
				]
			) . "\n\nAfter."
		);

		$this->assertSame( 1, $result['converted'] );
		$this->assertSame( 0, $result['skipped'] + $result['failed'] );

		$this->assertSame(
			sprintf( "Before.\n\n[sourcecode language=\"php\"]\n%s\n[/sourcecode]\n\nAfter.", $code ),
			$result['content']
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
	 * Decision 20 — the batching is cursor based, so it never slides over a post as
	 * the rows it is walking stop matching.
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
	 * Decision 20 — the scope is public post types and every status but the two which
	 * mean the content is gone. Drafts and scheduled posts are in; the trash is not.
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
	 * Decision 4 — the route which rewrites content is behind the same check as the
	 * one which saves a setting, and a refusal changes nothing.
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
	 * Decision 18 — the tool writes the generic tag and only ever the generic tag,
	 * whatever the language was.
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
