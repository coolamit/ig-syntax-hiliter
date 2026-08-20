<?php
/**
 * Tests for the v6 bootstrap: the declared version and environment floor, the
 * paths, and the fact that nothing GeSHi shaped is left anywhere near it.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Helper;
use iG\Syntax_Hiliter\Plugin;
use iG_Syntax_Hiliter_Gatekeeper;
use WP_UnitTestCase;

/**
 * Checks the plugin declares itself consistently and loads cleanly.
 */
class Plugin_Bootstrap_Test extends WP_UnitTestCase {

	/**
	 * The version and the environment floor say the same thing in the constant, the
	 * plugin header, readme.txt and the Gatekeeper. A mismatch fails the release
	 * build, so it is caught here instead.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_keeps_the_version_consistent_across_the_plugin_header_and_readme(): void {

		$header = get_file_data(
			IG_SYNTAX_HILITER_TESTS_PLUGIN_DIR . '/ig-syntax-hiliter.php',
			[
				'Version'     => 'Version',
				'RequiresWP'  => 'Requires at least',
				'RequiresPHP' => 'Requires PHP',
				'TextDomain'  => 'Text Domain',
			]
		);

		$this->assertSame( IG_SYNTAX_HILITER_VERSION, $header['Version'] );
		$this->assertSame( '6.9', $header['RequiresWP'] );
		$this->assertSame( '8.4', $header['RequiresPHP'] );
		$this->assertSame( 'igsyntax-hiliter', $header['TextDomain'] );

		// The gate has to refuse exactly what the header says is required.
		$this->assertSame( $header['RequiresPHP'], iG_Syntax_Hiliter_Gatekeeper::MIN_PHP_VERSION_REQUIRED );
		$this->assertSame( $header['RequiresWP'], iG_Syntax_Hiliter_Gatekeeper::MIN_WP_VERSION_REQUIRED );

		// The coding standard checks the same floors, so moving one names every file that has to move with it.
		$phpcs = (string) file_get_contents( IG_SYNTAX_HILITER_TESTS_PLUGIN_DIR . '/phpcs.xml.dist' );

		$this->assertStringContainsString(
			sprintf( '<config name="testVersion" value="%s-"/>', $header['RequiresPHP'] ),
			$phpcs,
			'phpcs.xml.dist checks a different PHP version than the plugin requires.'
		);

		$this->assertStringContainsString(
			sprintf( '<config name="minimum_wp_version" value="%s"/>', $header['RequiresWP'] ),
			$phpcs,
			'phpcs.xml.dist checks a different WordPress version than the plugin requires.'
		);

		$readme = (string) file_get_contents( IG_SYNTAX_HILITER_TESTS_PLUGIN_DIR . '/readme.txt' );

		// The release guard compares the stable tag against the header byte for byte, so this reads the constant.
		$this->assertMatchesRegularExpression(
			sprintf( '/^Stable tag:\s*%s\s*$/m', preg_quote( IG_SYNTAX_HILITER_VERSION, '/' ) ),
			$readme
		);
		$this->assertMatchesRegularExpression( '/^Requires at least:\s*6\.9\s*$/m', $readme );
		$this->assertMatchesRegularExpression( '/^Requires PHP:\s*8\.4\s*$/m', $readme );

	}

	/**
	 * Paths are derived from the plugin's own location, so nothing breaks if the
	 * plugin directory is not named after the repository (the repo is
	 * `ig-syntax-hiliter`, the slug is `igsyntax-hiliter`).
	 *
	 * That the version constant is defined is asserted first: every reader goes
	 * through `Helper::get_version()`, which answers the caller's fallback rather
	 * than failing, so nothing else could see it go.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_builds_paths_without_assuming_the_plugin_folder_name(): void {

		$this->assertTrue( defined( 'IG_SYNTAX_HILITER_VERSION' ), 'The version constant is what every version answer in the plugin comes from.' );

		$this->assertSame( IG_SYNTAX_HILITER_ROOT . '/', Helper::get_path() );
		$this->assertSame( IG_SYNTAX_HILITER_ROOT . '/assets/build/css/admin.css', Helper::get_path( 'assets/build/css/admin.css' ) );
		$this->assertFileExists( Helper::get_path( 'assets/build/css/admin.css' ) );

		$this->assertSame( IG_SYNTAX_HILITER_VERSION, Helper::get_version() );

	}

	/**
	 * The loader is hooked where v5 hooked it, and running it again prints nothing
	 * and raises no PHP diagnostic of any kind.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_loads_silently(): void {

		$this->assertSame( 10, has_action( 'init', 'ig_syntax_hiliter_loader' ) );

		$diagnostics = [];

		set_error_handler(  // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Capturing PHP diagnostics is the whole point of this test.
			static function ( $errno, $errstr, $errfile, $errline ) use ( &$diagnostics ) {
				$diagnostics[] = sprintf( '%d: %s in %s on line %d', $errno, $errstr, $errfile, $errline );

				return true;
			}
		);

		ob_start();

		try {
			ig_syntax_hiliter_loader();
			$instance = Plugin::get_instance();
		} finally {
			$printed = (string) ob_get_clean();

			restore_error_handler();
		}

		$this->assertSame( [], $diagnostics, 'Loading the plugin must raise no notices, warnings or deprecations.' );
		$this->assertSame( '', $printed, 'Loading the plugin must print nothing, or activation fails with unexpected output.' );
		$this->assertSame( Plugin::get_instance(), $instance );

	}

	/**
	 * The v5 engine is gone: no GeSHi class or library, no Frontend class, none of
	 * the front end assets that went with them.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_ships_none_of_the_v5_engine(): void {

		$this->assertFalse( class_exists( 'GeSHi', false ) );
		$this->assertFalse( class_exists( '\iG\Syntax_Hiliter\Frontend', false ) );

		$gone = [
			'classes/geshi.php',
			'classes/frontend.php',
			'templates/frontend-code-box.php',
			'assets/src/js/front-end.js',
			'assets/src/js/igeek-utils.js',
			'assets/src/scss/front-end.scss',
			'assets/src/scss/config.rb',
		];

		foreach ( $gone as $path ) {
			$this->assertFileDoesNotExist( IG_SYNTAX_HILITER_ROOT . '/' . $path );
		}

		$this->assertDirectoryDoesNotExist( IG_SYNTAX_HILITER_ROOT . '/geshi' );

		// The release zip is packaged from disk, so a stale pre-6.0 asset directory would ship files nothing enqueues.
		$retired = [
			'assets/css',
			'assets/js',
			'assets/scss',
		];

		foreach ( $retired as $directory ) {
			$this->assertDirectoryDoesNotExist( IG_SYNTAX_HILITER_ROOT . '/' . $directory );
		}

	}

} // end of class

// EOF
