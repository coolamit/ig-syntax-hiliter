<?php
/**
 * AC-1 — an excerpt WordPress generates for itself carries no snippet code.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Shortcode_Handler;
use WP_UnitTestCase;

/**
 * `Legacy_Content_Test` covers the excerpt itself. These cover what generating one
 * must not do to the rest of the request: leave state behind which blanks a code
 * box rendered after it, or around it.
 */
class Automatic_Excerpt_Test extends WP_UnitTestCase {

	/**
	 * Marker carried by the code in the fixture below.
	 *
	 * @var string
	 */
	const MARKER = 'leaked-code-marker';

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
	 * Method to build post content of prose around one snippet.
	 *
	 * @return string
	 */
	protected static function _content(): string {
		return sprintf( "Intro paragraph.\n\n[php]\n\$secret = '%s';\n[/php]\n\nOutro paragraph.", static::MARKER );
	}

	/**
	 * Method to create a post with no manual excerpt.
	 *
	 * @return int Post id.
	 */
	protected function _create_post(): int {

		return self::factory()->post->create(
			[
				'post_content' => wp_slash( static::_content() ),
				'post_excerpt' => '',
			]
		);

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
	 * AC-1 — an archive of excerpts leaves the full post view which follows it alone.
	 *
	 * Both halves of one request: no code in the excerpts, and all of it still in the
	 * single post view rendered after them.
	 *
	 * @return void
	 */
	public function test_an_archive_of_excerpts_leaves_the_single_post_which_follows_alone(): void {

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

		$this->assertStringNotContainsString( static::MARKER, $excerpts, 'archive excerpts' );

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		ob_start();
		the_content();

		$single = (string) ob_get_clean();

		$this->assertStringContainsString( '<pre ', $single, 'single post view' );
		$this->assertStringContainsString( static::MARKER, $single, 'single post view' );

	}

	/**
	 * AC-1 — an excerpt taken in the middle of a full render does not disturb it.
	 *
	 * The nested `get_the_excerpt()` runs between the protect pass at `the_content`
	 * priority 1 and the restore pass at priority 100, which is where request scoped
	 * state left switched on would do its damage.
	 *
	 * @return void
	 */
	public function test_an_excerpt_taken_midway_through_a_render_does_not_blank_it(): void {

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

		$this->assertStringNotContainsString( static::MARKER, $excerpt, 'excerpt taken mid render' );
		$this->assertStringContainsString( static::MARKER, $output, 'The render the excerpt interrupted still carries its code box.' );

	}

}    //end of class


//EOF
