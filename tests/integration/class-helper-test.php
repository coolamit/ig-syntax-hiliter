<?php
/**
 * Tests for the helper where it needs WordPress.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Helper;
use iG\Syntax_Hiliter\Legacy_Map;
use iG\Syntax_Hiliter\Shortcode_Handler;
use WP_UnitTestCase;

/**
 * The shortcode pattern is core's with one addition and bounds a snippet the way
 * `do_shortcode()` does; paths and the version are read from the plugin's own
 * location and constant.
 */
class Helper_Test extends WP_UnitTestCase {

	/**
	 * Registers the pipeline.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		Shortcode_Handler::get_instance();

	}

	/**
	 * The pattern is WordPress's with one alternative added and nothing else, so a
	 * shortcode is never bounded differently here from the way `do_shortcode()` bounds it.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_builds_cores_pattern_with_one_addition(): void {

		$tags = Legacy_Map::get_instance()->get_tags();
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
	 * A snippet holding a bare closing tag still ends there: the escape is an
	 * addition, and older content is read the way it was written.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_still_ends_the_snippet_at_an_unescaped_closing_tag(): void {

		$rendered = (string) apply_filters( 'the_content', '[php]echo 1;[/php] and then [/php] again' );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Running content through core's own hooks is what an integration test does.

		$this->assertSame( 1, substr_count( $rendered, '<pre ' ) );
		$this->assertStringContainsString( 'and then [/php] again', $rendered );

	}

	/**
	 * Paths are derived from the plugin's own location, so nothing breaks if the
	 * plugin directory is not named after the repository (the repo is
	 * `ig-syntax-hiliter`, the slug is `igsyntax-hiliter`).
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_builds_paths_without_assuming_the_plugin_folder_name(): void {

		$this->assertSame( IG_SYNTAX_HILITER_ROOT . '/', Helper::get_path() );
		$this->assertSame( IG_SYNTAX_HILITER_ROOT . '/assets/build/css/admin.css', Helper::get_path( 'assets/build/css/admin.css' ) );
		$this->assertFileExists( Helper::get_path( 'assets/build/css/admin.css' ) );

		$this->assertSame( IG_SYNTAX_HILITER_VERSION, Helper::get_version() );

	}

} // end of class

// EOF
