<?php
/**
 * An excerpt WordPress generates for itself carries no snippet code.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Shortcode_Handler;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use WP_UnitTestCase;

/**
 * `Legacy_Content_Test` covers the plain case. These cover what generating an
 * excerpt must not do to the rest of the request — leave state behind which blanks
 * a code box rendered after it, or around it — and the code which core's own
 * shortcode strip cannot be trusted with.
 */
class Automatic_Excerpt_Test extends WP_UnitTestCase {

	use Pipeline_Test_Helpers;

	/**
	 * Marker carried by the code in the fixture below.
	 *
	 * @var string
	 */
	protected const string _MARKER = 'leaked-code-marker';

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
	 * Code which carries a `<` that opens no element.
	 *
	 * `<?php` is the first token of a great many of this plugin's snippets, and the
	 * rest of these are ordinary source too: a heredoc opener, a comparison, an
	 * unclosed HTML comment, a generic type argument.
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
	 * A snippet whose code carries an unclosed `<` neither reaches the excerpt nor
	 * takes the prose after it away.
	 *
	 * The body an automatic excerpt is built from goes through core's
	 * `strip_shortcodes()`, which escapes every `[` and `]` inside anything
	 * `wp_html_split()` reads as an element — and a `<` with no `>` after it makes an
	 * element of everything left, this snippet's own closing tag included. Core then
	 * strips an opening tag it thinks is self closing and leaves the code behind, so
	 * this plugin has to take the snippet off the body itself.
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
	 * The same escaping, reaching the other way: an element which begins in the prose
	 * swallows the snippet's opening tag, so core strips nothing at all and the whole
	 * body arrives at `wp_trim_words()` with the code in it.
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

}    //end of class


//EOF
