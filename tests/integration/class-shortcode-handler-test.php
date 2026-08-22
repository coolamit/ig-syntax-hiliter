<?php
/**
 * Tests for the shortcode handler.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Base;
use iG\Syntax_Hiliter\Legacy_Map;
use iG\Syntax_Hiliter\Option;
use iG\Syntax_Hiliter\Renderer;
use iG\Syntax_Hiliter\Shortcode_Handler;
use iG\Syntax_Hiliter\Tests\Integration\Fixtures\Default_Settings;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Hook_Test_Helpers;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use WP_UnitTestCase;

/**
 * Where the pipeline is hooked; every shipped tag, alias and `[sourcecode]`
 * rendering straight out of unconverted `post_content`; comments following
 * `hilite_comments`; and excerpts stripping rather than rendering.
 */
class Shortcode_Handler_Test extends WP_UnitTestCase {

	use Hook_Test_Helpers;
	use Pipeline_Test_Helpers;

	/**
	 * Marker carried by the code in the fixture below.
	 *
	 * @var string
	 */
	protected const string _MARKER = 'leaked-code-marker';

	/**
	 * Code carrying URLs of the kinds an autolinker looks for.
	 *
	 * @var string
	 */
	protected const string _CODE_WITH_URLS = "\$api = 'https://example.com/v1/thing?a=1&b=2';\n// see http://example.org/docs\nwww.example.net/plain\nsomeone@example.com";

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
	 * What a reader is to be shown inside the code box.
	 *
	 * @var string
	 */
	protected const string _QUOTED = "[sourcecode language=\"php\"]\nfunction f() {}\n[/sourcecode]";

	/**
	 * The shortcode handler as the plugin booted it.
	 *
	 * @var \iG\Syntax_Hiliter\Shortcode_Handler|null
	 */
	protected ?Shortcode_Handler $_original_handler = null;

	/**
	 * The options object as the plugin booted it.
	 *
	 * @var \iG\Syntax_Hiliter\Option|null
	 */
	protected ?Option $_original_option = null;

	/**
	 * Registers the pipeline once WordPress is up, and remembers what to put back.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		Shortcode_Handler::get_instance();

		$this->_original_handler = Shortcode_Handler::get_instance();
		$this->_original_option  = Option::get_instance();

	}

	/**
	 * Puts the singletons back, so a test which rewired the plugin cannot leak into
	 * the next one. WordPress' own test case restores the filters.
	 *
	 * @return void
	 */
	public function tear_down(): void {

		$this->_set_singleton( Option::class, $this->_original_option );
		$this->_set_singleton( Shortcode_Handler::class, $this->_original_handler );

		parent::tear_down();

	}

	/**
	 * Every tag the plugin has ever shipped, plus the three shorthand aliases.
	 *
	 * Read from `Legacy_Map` rather than written out here, so that the matrix
	 * follows the map.
	 *
	 * @return array
	 */
	public static function shipped_tag_provider(): array {

		$cases = [];

		foreach ( Legacy_Map::get_instance()->get_language_map() as $tag => $language ) {
			$cases[ $tag ] = [ $tag, $language ];
		}

		return $cases;

	}

	/**
	 * Code which carries a `<` that opens no element, all of it ordinary source.
	 *
	 * @return array
	 */
	public static function unclosed_angle_bracket_provider(): array {

		return [
			'an opening PHP tag'        => [ "<?php\necho '%s';" ],
			'an opening PHP tag inline' => [ "<?php echo '%s';" ],
			'a heredoc opener'          => [ "\$sql = <<<SQL\nSELECT '%s'\nSQL;" ],
			'a less than comparison'    => [ "if ( \$a < \$b ) {\n\techo '%s';\n}" ],
			'a generic type argument'   => [ "var x = new List<Thing;\n// %s" ],
			'an unclosed HTML comment'  => [ "<!-- todo\necho '%s';" ],
		];

	}

	/**
	 * Method to provide the ways a flag turns up in a real database.
	 *
	 * @return array
	 */
	public function stored_flag_provider(): array {

		return [
			[ null, null, true, false, 'A setting stored as NULL' ],
			[ 'perhaps', 'perhaps', true, false, 'A stored value which is not a flag at all' ],
			[ '0', '1', false, true, 'The booleans versions up to 3.5 stored' ],
			[ 'no', 'yes', false, true, 'The words 4.0 onwards stored' ],
		];

	}

	/**
	 * Method to rebuild the shortcode handler against a different option value.
	 *
	 * The old filters are removed first, because a stale registration of the booted
	 * handler would be indistinguishable from a fresh one at the same priority.
	 *
	 * @param string $name  Option name.
	 * @param string $value Option value.
	 *
	 * @return Shortcode_Handler The rebuilt handler, whose callbacks are what the
	 *                           caller asserts against.
	 */
	protected function _rewire_handler_with( string $name, string $value ): Shortcode_Handler {

		$this->_store_option( $name, $value );

		$filters = array_merge(
			[ 'the_content', 'comment_text' ],
			Shortcode_Handler::EXCERPT_FILTERS,
			Shortcode_Handler::SAVE_FILTERS
		);

		foreach ( $filters as $filter ) {
			remove_all_filters( $filter );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Core hooks the plugin registers on.
		}

		$this->_set_singleton( Option::class, null );
		$this->_set_singleton( Shortcode_Handler::class, null );

		return Shortcode_Handler::get_instance();

	}

	/**
	 * Method to build an options object which has just read what is stored, which
	 * is one request's worth of the plugin.
	 *
	 * @return \iG\Syntax_Hiliter\Option
	 */
	protected function _new_reader(): Option {

		$this->_set_singleton( Option::class, null );

		return Option::get_instance();

	}

	/**
	 * Method to store content the way a classic editor post stores it, and hand back
	 * exactly what landed in the database.
	 *
	 * @param string $content Post content.
	 *
	 * @return string
	 */
	protected function _store( string $content ): string {

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
	 * Method to build the code every snippet in the fixture carries.
	 *
	 * One payload for every snippet, so a single expected string covers the whole
	 * fixture. It holds the whole legacy surface: a script tag, HTML entities, PHP open
	 * and close tags, mixed HTML/JS, both kinds of quote, a bare URL, and a line
	 * which looks like another plugin's shortcode.
	 *
	 * @return string
	 */
	protected static function _payload(): string {

		return implode(
			"\n",
			[
				'<script src="https://example.com/x.js"></script>',  // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Author written code in a fixture, not markup this plugin emits.
				"<?php echo '<div class=\"a\">' . \$x . '</div>'; ?>",
				'// entities: &amp; &lt; &#039; and "double" and \'single\' quotes',
				'if ( a < b && c > d ) { alert( "hi" ); }',
				'[gallery id="1" size="full"]',
				'https://example.com/plain-url',
			]
		);

	}

	/**
	 * Method to build the fixture, one entry per snippet, in the order they appear.
	 *
	 * One tag of each shape rather than all of them: a named language tag, a
	 * shorthand alias, and the generic tag both with the canonical attribute names
	 * and with the legacy spellings of them.
	 *
	 * @return array List of arrays with `shortcode` and `language` keys.
	 */
	protected static function _fixture(): array {

		$payload = static::_payload();
		$generic = Legacy_Map::GENERIC_TAG;

		return [
			[
				'shortcode' => sprintf( "[php]\n%s\n[/php]", $payload ),
				'language'  => 'php',
			],
			[
				'shortcode' => sprintf( "[js]\n%s\n[/js]", $payload ),
				'language'  => 'javascript',
			],
			[
				'shortcode' => sprintf( "[%1\$s language=\"css\" highlight=\"2,4-6\" file=\"x.php\" gutter=\"no\" firstline=\"10\"]\n%2\$s\n[/%1\$s]", $generic, $payload ),
				'language'  => 'css',
			],
			[
				'shortcode' => sprintf( "[%1\$s lang=\"ruby\" num=\"7\"]\n%2\$s\n[/%1\$s]", $generic, $payload ),
				'language'  => 'ruby',
			],
		];

	}

	/**
	 * Method to build the fixture's post content.
	 *
	 * @return string
	 */
	protected static function _fixture_content(): string {

		$paragraphs = [ 'Opening paragraph of a very old post.' ];

		foreach ( static::_fixture() as $snippet ) {
			$paragraphs[] = $snippet['shortcode'];
		}

		$paragraphs[] = 'Closing paragraph of a very old post.';

		return implode( "\n\n", $paragraphs );

	}

	/**
	 * Method to create the fixture post.
	 *
	 * @param string $excerpt Optional manual excerpt.
	 *
	 * @return int Post id.
	 */
	protected function _create_fixture_post( string $excerpt = '' ): int {

		return self::factory()->post->create(
			[
				'post_title'   => 'Legacy fixture',
				'post_content' => wp_slash( static::_fixture_content() ),
				'post_excerpt' => wp_slash( $excerpt ),
			]
		);

	}

	/**
	 * Method to pull every rendered code box out of some markup.
	 *
	 * @param string $html Rendered markup.
	 *
	 * @return array List of arrays with `language` and `code` keys, in document order.
	 */
	protected function _code_boxes( string $html ): array {

		preg_match_all( '#<pre [^>]*><code class="language-([^"]*)">(.*?)</code></pre>#s', $html, $matches, PREG_SET_ORDER );

		$boxes = [];

		foreach ( $matches as $match ) {
			$boxes[] = [
				'language' => $match[1],
				'code'     => $match[2],
			];
		}

		return $boxes;

	}

	/**
	 * Method to render a post the way a single post view renders it.
	 *
	 * @param int $post_id Post id.
	 *
	 * @return string
	 */
	protected function _render_single( int $post_id ): string {

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		ob_start();
		the_content();

		return (string) ob_get_clean();

	}

	/**
	 * Method to assert that some markup carries the whole fixture, intact.
	 *
	 * @param string $output  Rendered markup.
	 * @param string $context Name of the context being rendered, for failure messages.
	 *
	 * @return void
	 */
	protected function _assert_fixture_rendered( string $output, string $context ): void {

		$fixture  = static::_fixture();
		$boxes    = $this->_code_boxes( $output );
		$expected = Renderer::get_instance()->escape_verbatim( static::_payload() );

		$this->assertCount( count( $fixture ), $boxes, sprintf( '%s: one code box per snippet.', $context ) );

		foreach ( $fixture as $index => $snippet ) {

			$this->assertSame(
				$snippet['language'],
				$boxes[ $index ]['language'],
				sprintf( '%s: snippet %d (%s) should be highlighted as %s.', $context, $index, substr( $snippet['shortcode'], 0, 20 ), $snippet['language'] )
			);

			$this->assertSame(
				$expected,
				$boxes[ $index ]['code'],
				sprintf( '%s: snippet %d (%s) came back changed.', $context, $index, substr( $snippet['shortcode'], 0, 20 ) )
			);

		}

		$this->assertStringContainsString( 'Opening paragraph of a very old post.', $output, $context );
		$this->assertStringContainsString( 'Closing paragraph of a very old post.', $output, $context );
		$this->assertStringNotContainsString( '[/php]', $output, sprintf( '%s: no shortcode should survive as text.', $context ) );

	}

	/**
	 * Method to build post content of prose around one snippet.
	 *
	 * @return string
	 */
	protected static function _content(): string {
		return static::_content_around( sprintf( "\$secret = '%s';", static::_MARKER ) );
	}

	/**
	 * Method to build post content of prose around one snippet carrying given code.
	 *
	 * @param string $code  Code the snippet carries.
	 * @param string $intro Prose ahead of the snippet.
	 *
	 * @return string
	 */
	protected static function _content_around( string $code, string $intro = 'Intro paragraph.' ): string {
		return sprintf( "%s\n\n[php]\n%s\n[/php]\n\nOutro paragraph.", $intro, $code );
	}

	/**
	 * Method to create a post with no manual excerpt.
	 *
	 * @param string|null $content Content to store, or NULL for the default fixture.
	 *
	 * @return int Post id.
	 */
	protected function _create_post( ?string $content = null ): int {

		return self::factory()->post->create(
			[
				'post_content' => wp_slash( $content ?? static::_content() ),
				'post_excerpt' => '',
			]
		);

	}

	/**
	 * Method to assert that an excerpt carries the prose and nothing of the snippet.
	 *
	 * @param string $excerpt Excerpt to check.
	 * @param string $because What is being checked.
	 *
	 * @return void
	 */
	protected function _assert_prose_only( string $excerpt, string $because ): void {

		$this->assertStringNotContainsString( static::_MARKER, $excerpt, $because . ': the code is in the excerpt.' );
		$this->assertDoesNotMatchRegularExpression( '#\[/?php#', $excerpt, $because . ': a piece of the shortcode is in the excerpt.' );
		$this->assertStringContainsString( 'Outro paragraph.', $excerpt, $because . ': prose past the snippet was lost.' );

	}

	/**
	 * Method to assert that no code box in some markup holds a link.
	 *
	 * @param string $html    Rendered markup.
	 * @param string $context Name of the context, for failure messages.
	 *
	 * @return void
	 */
	protected function _assert_no_links_in_code( string $html, string $context ): void {

		preg_match_all( '#<code class="language-[^"]*">(.*?)</code>#s', $html, $matches );

		$this->assertNotEmpty( $matches[1], sprintf( '%s: there should be a code box to look inside.', $context ) );

		foreach ( $matches[1] as $code ) {
			$this->assertStringNotContainsString( '<a ', $code, $context );
			$this->assertStringNotContainsString( 'href', $code, $context );
			$this->assertStringNotContainsString( '</a>', $code, $context );
		}

	}

	/**
	 * The shortcode handler's eleven registrations, on the settings it boots with.
	 *
	 * `PRIORITY_STRIP_BODY` is 0, which `has_filter()` reports as a falsy `0` and an
	 * absent callback as `false`; it is the earlier of the two strips the automatic
	 * excerpt needs, since core's `strip_shortcodes()` cannot be trusted with source code.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_registers_the_shortcode_handlers_hooks(): void {

		$handler = Shortcode_Handler::get_instance();

		// Named rather than read from the constants, so a filter dropped from a list does not take its own coverage with it.
		$this->assertSame(
			[ 'content_save_pre', 'content_filtered_save_pre' ],
			Shortcode_Handler::SAVE_FILTERS
		);

		$this->assertSame(
			[ 'get_the_excerpt', 'the_excerpt', 'the_excerpt_rss' ],
			Shortcode_Handler::EXCERPT_FILTERS
		);

		// A filter which writes to the database has no business on a strip list.
		$this->assertFalse( has_filter( 'excerpt_save_pre', [ $handler, 'strip' ] ) );

		// KSES has to sit between the two save passes for any of this to be worth doing.
		$this->assertSame( 10, has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );

		$this->_assert_hooked(
			'the_content',
			[ $handler, 'strip_for_excerpt' ],
			Shortcode_Handler::PRIORITY_STRIP_BODY,
			'The automatic excerpt gets its own strip of the post body, ahead of the protect pass.'
		);

		$this->_assert_hooked(
			'the_content',
			[ $handler, 'protect_display' ],
			Shortcode_Handler::PRIORITY_PROTECT,
			'Code is lifted out before any other filter runs.'
		);

		$this->_assert_hooked(
			'the_content',
			[ $handler, 'restore_display' ],
			Shortcode_Handler::PRIORITY_RESTORE,
			'And put back after the last one has finished.'
		);

		foreach ( Shortcode_Handler::EXCERPT_FILTERS as $filter ) {

			$this->_assert_hooked(
				$filter,
				[ $handler, 'strip' ],
				Shortcode_Handler::PRIORITY_STRIP,
				sprintf( 'A code box makes no sense in a summary, so %s strips instead of rendering.', $filter )
			);

		}

		$this->_assert_hooked(
			'strip_shortcodes_tagnames',
			[ $handler, 'claim_stripped_tags' ],
			null,
			'The tags are never registered with add_shortcode(), so core is told about them here.'
		);

		foreach ( Shortcode_Handler::SAVE_FILTERS as $filter ) {

			$this->_assert_hooked(
				$filter,
				[ $handler, 'protect_save' ],
				Shortcode_Handler::PRIORITY_PROTECT,
				sprintf( 'Code is lifted out of %s before KSES can reach it.', $filter )
			);

			$this->_assert_hooked(
				$filter,
				[ $handler, 'restore_save' ],
				Shortcode_Handler::PRIORITY_RESTORE,
				sprintf( 'And the author\'s original bytes go back into %s, byte for byte.', $filter )
			);

		}

	}

	/**
	 * With `hilite_comments` on, comments render code and are not stripped.
	 *
	 * The setting is read once, when the handler registers, so the wiring is taken down
	 * and put back up, and the assertions go against the new object whose callbacks
	 * are distinct from the booted one's. `_assert_not_hooked()` pins the other half.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_renders_code_in_comments_when_the_setting_is_on(): void {

		$handler = $this->_rewire_handler_with( 'hilite_comments', 'yes' );

		$this->_assert_hooked(
			'comment_text',
			[ $handler, 'protect_display' ],
			Shortcode_Handler::PRIORITY_PROTECT,
			'A comment is on the display list, so its code is protected like a post\'s.'
		);

		$this->_assert_hooked(
			'comment_text',
			[ $handler, 'restore_display' ],
			Shortcode_Handler::PRIORITY_RESTORE,
			'And restored as a rendered code box.'
		);

		$this->_assert_not_hooked(
			'comment_text',
			[ $handler, 'strip' ],
			'A comment which renders its code must not also have it stripped.'
		);

	}

	/**
	 * With `hilite_comments` off, comments are stripped and render nothing.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_strips_comments_when_the_setting_is_off(): void {

		$handler = $this->_rewire_handler_with( 'hilite_comments', 'no' );

		$this->_assert_hooked(
			'comment_text',
			[ $handler, 'strip' ],
			Shortcode_Handler::PRIORITY_STRIP,
			'A comment joins the strip list, exactly as an excerpt does.'
		);

		$this->_assert_not_hooked(
			'comment_text',
			[ $handler, 'protect_display' ],
			'And is not protected for display, because nothing is going to render it.'
		);

		$this->_assert_not_hooked(
			'comment_text',
			[ $handler, 'restore_display' ],
			'Nor restored.'
		);

	}

	/**
	 * Every shipped tag and alias renders from unconverted content, carrying the
	 * whole legacy surface: a script tag, HTML entities, PHP open and close tags
	 * and mixed HTML/JS.
	 *
	 * @test
	 *
	 * @dataProvider shipped_tag_provider
	 *
	 * @param string $tag      Legacy shortcode tag.
	 * @param string $language Canonical language id it should resolve to.
	 *
	 * @return void
	 */
	public function it_renders_a_shipped_tag_from_unconverted_post_content( string $tag, string $language ): void {

		$code = implode(
			"\n",
			[
				sprintf( '// marker-%s', $tag ),
				'<script src="https://example.com/x.js"></script>',  // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Author written code in a fixture, not markup this plugin emits.
				"<?php echo '<b>' . \$x . '</b>'; ?>",
				'entities: &amp; &lt; and "double" and \'single\' quotes',
			]
		);

		$stored = $this->_store( sprintf( "[%1\$s]\n%2\$s\n[/%1\$s]", $tag, $code ) );
		$output = $this->_filter( 'the_content', $stored );

		$this->assertStringContainsString( sprintf( '<code class="language-%s">', $language ), $output, sprintf( '[%s] should render as %s.', $tag, $language ) );
		$this->assertStringContainsString( Renderer::get_instance()->escape_verbatim( $code ), $output, sprintf( '[%s] mangled its code.', $tag ) );
		$this->assertStringNotContainsString( sprintf( '[%s]', $tag ), $output );

	}

	/**
	 * `[sourcecode]` renders from unconverted content.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_renders_the_generic_tag_from_unconverted_post_content(): void {

		$stored = $this->_store( '[sourcecode language="python" firstline="3"]print( "hi" )[/sourcecode]' );
		$output = $this->_filter( 'the_content', $stored );

		$this->assertStringContainsString( '<code class="language-python">', $output );
		$this->assertStringContainsString( 'data-start="3"', $output );
		$this->assertStringContainsString( Renderer::get_instance()->escape_verbatim( 'print( "hi" )' ), $output );

	}

	/**
	 * Comments have no block path, so the shortcode pipeline must run on them while
	 * `hilite_comments` is on.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_highlights_comments_while_the_option_is_on(): void {

		$this->assertSame( 'yes', Option::get_instance()->get( 'hilite_comments' ) );

		$output = $this->_filter( 'comment_text', "[php]\n\$a = 1;\n[/php]" );

		$this->assertStringContainsString( '<code class="language-php">', $output );
		$this->assertStringContainsString( '$a = 1;', $output );

	}

	/**
	 * Comments are stripped instead while `hilite_comments` is off.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_strips_comments_while_the_option_is_off(): void {

		$this->_rewire_handler_with( 'hilite_comments', 'no' );

		$output = $this->_filter( 'comment_text', "before [php]\n\$a = 1;\n[/php] after" );

		$this->assertStringNotContainsString( '<pre', $output );
		$this->assertStringNotContainsString( 'igsh-code-box', $output );
		$this->assertStringNotContainsString( '$a = 1;', $output );
		$this->assertStringNotContainsString( '[php]', $output );
		$this->assertStringContainsString( 'before', $output );
		$this->assertStringContainsString( 'after', $output );

	}

	/**
	 * Excerpts strip code rather than rendering it.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_strips_code_from_excerpts_rather_than_rendering_it(): void {

		foreach ( Shortcode_Handler::EXCERPT_FILTERS as $filter ) {

			$output = $this->_filter( $filter, 'before [php]$secret = 1;[/php] after' );

			$this->assertStringNotContainsString( '<pre', $output, $filter );
			$this->assertStringNotContainsString( 'igsh-code-box', $output, $filter );
			$this->assertStringNotContainsString( '$secret', $output, $filter );
			$this->assertStringNotContainsString( '[php]', $output, $filter );
			$this->assertStringContainsString( 'before', $output, $filter );
			$this->assertStringContainsString( 'after', $output, $filter );

		}

	}

	/**
	 * An escaped shortcode in a post with no excerpt of its own leaves the excerpt
	 * WordPress builds for it carrying neither a code box nor the code.
	 *
	 * The excerpt body is stripped at `the_content` priority 0 and protected at 1; an
	 * escape unwrapped by the first is a real shortcode to the second, so the strip
	 * takes the escape off along with the snippets.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_leaves_a_clean_automatic_excerpt_for_an_escaped_shortcode(): void {

		$post_id = self::factory()->post->create(
			[
				'post_content' => wp_slash( "Intro paragraph.\n\n[[php]\$secret = 'escaped-leak-marker';[/php]]\n\nOutro paragraph." ),
				'post_excerpt' => '',
			]
		);

		$excerpt = get_the_excerpt( $post_id );

		$this->assertStringNotContainsString( '<pre', $excerpt, 'A code box was rendered into the excerpt.' );
		$this->assertStringNotContainsString( 'igsh-code-box', $excerpt, 'A code box container was rendered into the excerpt.' );
		$this->assertStringNotContainsString( 'escaped-leak-marker', $excerpt, 'The code reached the excerpt as prose.' );
		$this->assertDoesNotMatchRegularExpression( '#\[/?php#', $excerpt, 'A piece of the shortcode reached the excerpt.' );
		$this->assertStringContainsString( 'Outro paragraph.', $excerpt, 'Prose past the escape was lost.' );

	}

	/**
	 * The legacy surface renders in a single post view, escaped once.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_renders_the_legacy_surface_in_a_single_post_view(): void {

		$output = $this->_render_single( $this->_create_fixture_post() );

		$this->_assert_fixture_rendered( $output, 'single post view' );

		// An entity the author typed is text, and is shown to the reader as the text it is.
		$this->assertStringContainsString( 'entities: &amp;amp; &amp;lt; &amp;#039;', $output );

		// A character the author typed is encoded once, and once only.
		$this->assertStringContainsString( '&lt;script src=', $output );
		$this->assertStringNotContainsString( '&amp;lt;script src=', $output );

	}

	/**
	 * The RSS feed is wired to it too, where a snippet is code rather than markup
	 * the reader executes.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_renders_a_snippet_in_the_feed_as_code_and_not_markup(): void {

		$post_id = self::factory()->post->create(
			[
				'post_content' => wp_slash( sprintf( "[php]\n%s\n[/php]", static::_payload() ) ),
			]
		);

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$feed = get_the_content_feed( 'rss2' );

		$this->assertStringContainsString( '<code class="language-php">', $feed );
		$this->assertStringContainsString( Renderer::get_instance()->escape_verbatim( static::_payload() ), $feed );

		$this->assertStringNotContainsString( '<script src="https://example.com/x.js">', $feed );  // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Asserting the fixture's code is NOT emitted as markup.
		$this->assertStringNotContainsString( '<?php', $feed );

	}

	/**
	 * The attribute grammar survives the display chain.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_carries_the_attribute_grammar_through_the_display_chain(): void {

		$output = $this->_render_single( $this->_create_fixture_post() );

		$this->assertStringContainsString( 'data-line="2,4-6"', $output );
		$this->assertStringContainsString( '<span class="igsh-code-box__file">x.php</span>', $output );
		$this->assertStringContainsString( 'data-start="10"', $output );
		$this->assertStringContainsString( 'data-start="7"', $output );

		// `gutter="no"` is the only snippet in the fixture without line numbers.
		preg_match_all( '#<pre [^>]*class="([^"]*)"#', $output, $matches );

		$without_line_numbers = array_filter(
			$matches[1],
			static function ( string $classes ): bool {
				return ( ! str_contains( $classes, 'line-numbers' ) );
			}
		);

		$this->assertCount( 1, $without_line_numbers );
		$this->assertSame( [ 'language-css' ], array_values( $without_line_numbers ) );

	}

	/**
	 * A manual excerpt carries no code, and no leftover shortcode either.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_leaves_no_code_in_a_manual_excerpt(): void {

		$post_id = $this->_create_fixture_post( 'Summary with [php]$secret = 1;[/php] in it.' );
		$excerpt = get_the_excerpt( $post_id );

		$this->assertStringContainsString( 'Summary with', $excerpt );
		$this->assertStringNotContainsString( '$secret', $excerpt );
		$this->assertStringNotContainsString( '[php]', $excerpt );
		$this->assertStringNotContainsString( '<pre', $excerpt );
		$this->assertStringNotContainsString( 'igsh-code-box', $excerpt );

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
			Renderer::get_instance()->escape_verbatim( self::_QUOTED ),
			$rendered,
			'The reader was shown the doubled brackets rather than the tags the author wrote.'
		);

		$this->assertStringNotContainsString( '[[', $rendered, 'The escape reached the page.' );

	}

	/**
	 * A URL in a post stays plain text even when a theme or plugin puts
	 * `make_clickable` on the content, while prose around the snippet is still
	 * linked, so this is measuring the snippet and not a dead filter chain.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_keeps_urls_through_make_clickable_on_the_content(): void {

		add_filter( 'the_content', 'make_clickable', 10 );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, core callback, added the way a theme would.

		$output = $this->_filter(
			'the_content',
			sprintf( "Read https://example.com/docs first.\n\n[php]\n%s\n[/php]", self::_CODE_WITH_URLS )
		);

		remove_filter( 'the_content', 'make_clickable', 10 );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, core callback.

		$this->_assert_no_links_in_code( $output, 'make_clickable at 10' );
		$this->assertStringContainsString( esc_html( self::_CODE_WITH_URLS ), $output );
		$this->assertStringContainsString( '<a href="https://example.com/docs"', $output );

	}

	/**
	 * The same holds in a comment, where core hangs `make_clickable` by default.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_keeps_urls_in_a_comment_snippet_as_plain_text(): void {

		$priority = has_filter( 'comment_text', 'make_clickable' );

		$this->assertNotFalse( $priority, 'Core autolinks comments out of the box.' );
		$this->assertGreaterThan( Shortcode_Handler::PRIORITY_PROTECT, $priority );
		$this->assertLessThan( Shortcode_Handler::PRIORITY_RESTORE, $priority );

		$output = $this->_filter( 'comment_text', sprintf( "[php]\n%s\n[/php]", self::_CODE_WITH_URLS ) );

		$this->_assert_no_links_in_code( $output, 'comment_text' );

	}

	/**
	 * A snippet whose code carries an unclosed `<` neither reaches the excerpt nor
	 * takes the prose after it away.
	 *
	 * Core's `strip_shortcodes()` escapes every bracket inside what `wp_html_split()`
	 * reads as an element, and a `<` with no `>` makes an element of everything left,
	 * the closing tag included — so the plugin strips the snippet off the body itself.
	 *
	 * @test
	 *
	 * @dataProvider unclosed_angle_bracket_provider
	 *
	 * @param string $code Code the snippet carries, with one `%s` for the marker.
	 *
	 * @return void
	 */
	public function it_leaves_a_clean_excerpt_for_a_snippet_carrying_an_unclosed_angle_bracket( string $code ): void {

		$post_id = $this->_create_post( static::_content_around( sprintf( $code, static::_MARKER ) ) );

		$this->_assert_prose_only( get_the_excerpt( $post_id ), 'automatic excerpt' );

	}

	/**
	 * Prose which carries an unclosed `<` ahead of a snippet does not drag the snippet
	 * into the excerpt with it.
	 *
	 * The same escaping the other way: an element begun in the prose swallows the
	 * opening tag, so core strips nothing and the code reaches `wp_trim_words()`.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_leaves_a_clean_excerpt_for_prose_carrying_an_unclosed_angle_bracket(): void {

		$post_id = $this->_create_post(
			static::_content_around(
				sprintf( "echo '%s';", static::_MARKER ),
				'Intro paragraph, in which 1 < 2 is noted.'
			)
		);

		$excerpt = get_the_excerpt( $post_id );

		$this->_assert_prose_only( $excerpt, 'automatic excerpt' );
		$this->assertStringContainsString( 'is noted.', $excerpt, 'Prose ahead of the snippet was lost.' );

	}

	/**
	 * An archive of excerpts leaves the full post view which follows it alone.
	 *
	 * Both halves of one request: no code in the excerpts, and all of it still in the
	 * single post view rendered after them.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_leaves_the_single_post_which_follows_an_archive_of_excerpts_alone(): void {

		$post_id = $this->_create_post();

		$this->go_to( home_url( '/' ) );

		$this->assertTrue( is_home(), 'The archive context is what is being rendered here.' );

		$excerpts = '';

		while ( have_posts() ) {

			the_post();

			ob_start();
			the_excerpt();

			$excerpts .= (string) ob_get_clean();

		}

		$this->assertStringNotContainsString( static::_MARKER, $excerpts, 'archive excerpts' );

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		ob_start();
		the_content();

		$single = (string) ob_get_clean();

		$this->assertStringContainsString( '<pre ', $single, 'single post view' );
		$this->assertStringContainsString( static::_MARKER, $single, 'single post view' );

	}

	/**
	 * An excerpt taken in the middle of a full render does not disturb it.
	 *
	 * The nested `get_the_excerpt()` runs between the protect pass at `the_content`
	 * priority 1 and the restore pass at priority 100, which is where request scoped
	 * state left switched on would do its damage.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_does_not_blank_a_render_an_excerpt_was_taken_midway_through(): void {

		$post_id = $this->_create_post();
		$excerpt = '';
		$taken   = false;

		$nested = static function ( $content ) use ( &$taken, $post_id, &$excerpt ) {

			// The excerpt runs `the_content` again, which lands back here. A flag stops
			// that rather than a `remove_filter()` call: taking the only callback off the
			// priority being run makes core skip the priority after it, and the restore
			// pass with it.
			if ( $taken ) {
				return $content;
			}

			$taken   = true;
			$excerpt = get_the_excerpt( $post_id );

			return $content;

		};

		add_filter( 'the_content', $nested, 50 );

		$output = $this->_filter( 'the_content', static::_content() );

		remove_filter( 'the_content', $nested, 50 );

		$this->assertStringNotContainsString( static::_MARKER, $excerpt, 'excerpt taken mid render' );
		$this->assertStringContainsString( static::_MARKER, $output, 'The render the excerpt interrupted still carries its code box.' );

	}

	/**
	 * A manual excerpt is stored exactly as it was written, and stripped only on the
	 * way out. `excerpt_save_pre` writes to the database, and `is_admin()` is no
	 * guard: it is false for REST, WP-CLI, cron and the revert tool.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_stores_an_excerpt_exactly_as_it_was_written(): void {

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
	 * Whatever a flag is stored as, both readers agree on what it means.
	 *
	 * `Validate::to_yesno()` is the one converter, so `1`/`0`, `true`/`false`,
	 * `on`/`off` and `yes`/`no` all read alike, and anything else reads as the
	 * setting's default. Two settings with opposite defaults on every row, so a reader
	 * answering the same thing for both fails rather than passing half the time.
	 *
	 * @test
	 *
	 * @dataProvider stored_flag_provider
	 *
	 * @param mixed  $hilite        What `hilite_comments`, whose default is `yes`, is stored as.
	 * @param mixed  $gist          What `gist_in_comments`, whose default is `no`, is stored as.
	 * @param bool   $expect_hilite What `hilite_comments` should read as.
	 * @param bool   $expect_gist   What `gist_in_comments` should read as.
	 * @param string $description   What the row stands for, for the failure message.
	 *
	 * @return void
	 */
	public function it_reads_a_stored_flag_as_what_it_means( $hilite, $gist, bool $expect_hilite, bool $expect_gist, string $description ): void {

		update_option(
			Base::PLUGIN_ID . '-options',
			array_merge(
				Default_Settings::V6,
				[
					'hilite_comments'  => $hilite,
					'gist_in_comments' => $gist,
				]
			)
		);

		$this->_new_reader();

		$this->assertSame(
			$expect_hilite,
			Shortcode_Handler::get_instance()->is_plugin_option_on( 'hilite_comments', 'yes' ),
			sprintf( '%s did not read as expected for a setting which defaults to yes.', $description )
		);

		$this->assertSame(
			$expect_gist,
			Shortcode_Handler::get_instance()->is_plugin_option_on( 'gist_in_comments', 'no' ),
			sprintf( '%s did not read as expected for a setting which defaults to no.', $description )
		);

	}

} // end of class

// EOF
