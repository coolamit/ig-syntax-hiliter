<?php
/**
 * Tests for the v6 bootstrap: constants, the orchestrator, and the fact that
 * nothing GeSHi shaped is left anywhere near it.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Plugin;
use WP_UnitTestCase;

/**
 * Checks the plugin loads, activates and orchestrates cleanly.
 */
class Plugin_Bootstrap_Test extends WP_UnitTestCase {

	/**
	 * The version constant is the semver *string* `6.0.0`.
	 *
	 * It was a float up to v5.1, which is the single most consequential change
	 * in the v6 bootstrap: everything that compares it has to use
	 * version_compare(), never a numeric comparison.
	 *
	 * @return void
	 */
	public function test_version_constant_is_a_semver_string(): void {

		$this->assertTrue( defined( 'IG_SYNTAX_HILITER_VERSION' ) );
		$this->assertIsString( IG_SYNTAX_HILITER_VERSION );
		$this->assertSame( '6.0.0', IG_SYNTAX_HILITER_VERSION );
		$this->assertNotSame( 6.0, IG_SYNTAX_HILITER_VERSION );

	}

	/**
	 * The version is the same in the plugin header and in readme.txt as it is
	 * in the constant. These three drift apart easily and a mismatch fails the
	 * release build, so it is caught here instead.
	 *
	 * @return void
	 */
	public function test_version_is_consistent_across_the_plugin_header_and_readme(): void {

		$header = get_file_data(
			IG_SYNTAX_HILITER_TESTS_PLUGIN_DIR . '/ig-syntax-hiliter.php',
			array(
				'Version'     => 'Version',
				'RequiresWP'  => 'Requires at least',
				'RequiresPHP' => 'Requires PHP',
				'TextDomain'  => 'Text Domain',
			)
		);

		$this->assertSame( IG_SYNTAX_HILITER_VERSION, $header['Version'] );
		$this->assertSame( '6.9', $header['RequiresWP'] );
		$this->assertSame( '8.4', $header['RequiresPHP'] );
		$this->assertSame( 'igsyntax-hiliter', $header['TextDomain'] );

		$readme = (string) file_get_contents( IG_SYNTAX_HILITER_TESTS_PLUGIN_DIR . '/readme.txt' );

		$this->assertMatchesRegularExpression( '/^Stable tag:\s*6\.0\.0\s*$/m', $readme );
		$this->assertMatchesRegularExpression( '/^Requires at least:\s*6\.9\s*$/m', $readme );
		$this->assertMatchesRegularExpression( '/^Requires PHP:\s*8\.4\s*$/m', $readme );

	}

	/**
	 * The dead v5 URL constant is gone and the ones that are still used are
	 * defined.
	 *
	 * @return void
	 */
	public function test_only_the_constants_that_are_used_are_defined(): void {

		$this->assertFalse( defined( 'IG_SYNTAX_HILITER_URL' ), 'IG_SYNTAX_HILITER_URL was dead code in v5 and is removed in v6.' );

		$this->assertSame( IG_SYNTAX_HILITER_TESTS_PLUGIN_DIR, IG_SYNTAX_HILITER_ROOT );
		$this->assertTrue( defined( 'IG_SYNTAX_HILITER_BASENAME' ) );
		$this->assertSame( plugin_basename( IG_SYNTAX_HILITER_ROOT . '/ig-syntax-hiliter.php' ), IG_SYNTAX_HILITER_BASENAME );

	}

	/**
	 * The orchestrator is loaded and is a singleton.
	 *
	 * @return void
	 */
	public function test_orchestrator_is_loaded_once(): void {

		$this->assertTrue( class_exists( Plugin::class, false ) );
		$this->assertSame( Plugin::get_instance(), Plugin::get_instance() );

	}

	/**
	 * Paths and URLs are derived from the plugin's own location, so nothing
	 * breaks if the plugin directory is not named after the repository (CI-9:
	 * the repo is `ig-syntax-hiliter`, the slug is `igsyntax-hiliter`).
	 *
	 * @return void
	 */
	public function test_paths_do_not_assume_the_plugin_folder_name(): void {

		$plugin = Plugin::get_instance();

		$this->assertSame( IG_SYNTAX_HILITER_ROOT . '/', $plugin->get_path() );
		$this->assertSame( IG_SYNTAX_HILITER_ROOT . '/assets/css/admin.css', $plugin->get_path( 'assets/css/admin.css' ) );
		$this->assertFileExists( $plugin->get_path( 'assets/css/admin.css' ) );

		$folder = basename( IG_SYNTAX_HILITER_ROOT );

		$this->assertStringEndsWith( $folder . '/assets/css/admin.css', $plugin->get_url( 'assets/css/admin.css' ) );
		$this->assertStringStartsWith( 'http', $plugin->get_url() );

		$this->assertSame( IG_SYNTAX_HILITER_VERSION, $plugin->get_version() );

	}

	/**
	 * The loader is hooked where v5 hooked it, and running it again is a no-op
	 * that raises no PHP diagnostic of any kind.
	 *
	 * @return void
	 */
	public function test_reloading_the_plugin_raises_no_php_diagnostics(): void {

		$this->assertSame( 10, has_action( 'init', 'ig_syntax_hiliter_loader' ) );

		$diagnostics = array();

		set_error_handler(  // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Capturing PHP diagnostics is the whole point of this test.
			static function ( $errno, $errstr, $errfile, $errline ) use ( &$diagnostics ) {
				$diagnostics[] = sprintf( '%d: %s in %s on line %d', $errno, $errstr, $errfile, $errline );

				return true;
			}
		);

		try {
			ig_syntax_hiliter_loader();
			$instance = Plugin::get_instance();
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $diagnostics, 'Loading the plugin must raise no notices, warnings or deprecations.' );
		$this->assertSame( Plugin::get_instance(), $instance );

	}

	/**
	 * The plugin activates cleanly through WordPress' own activation path.
	 *
	 * @return void
	 */
	public function test_plugin_activates_cleanly(): void {

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$plugin = plugin_basename( IG_SYNTAX_HILITER_ROOT . '/ig-syntax-hiliter.php' );

		if ( ! is_readable( WP_PLUGIN_DIR . '/' . $plugin ) ) {
			$this->markTestSkipped( 'The plugin is not inside this install\'s plugin directory, so it cannot be activated through WordPress.' );
		}

		$result = activate_plugin( $plugin );

		$this->assertNull( $result, 'activate_plugin() returns a WP_Error when activation produces output or fails.' );
		$this->assertTrue( is_plugin_active( $plugin ) );

		deactivate_plugins( $plugin );

	}

	/**
	 * GeSHi is gone: no class, no vendored library, no v5 front end assets.
	 *
	 * @return void
	 */
	public function test_geshi_is_gone(): void {

		$this->assertFalse( class_exists( 'GeSHi', false ) );
		$this->assertFileDoesNotExist( IG_SYNTAX_HILITER_ROOT . '/classes/geshi.php' );
		$this->assertDirectoryDoesNotExist( IG_SYNTAX_HILITER_ROOT . '/geshi' );
		$this->assertFileDoesNotExist( IG_SYNTAX_HILITER_ROOT . '/assets/js/front-end.js' );
		$this->assertFileDoesNotExist( IG_SYNTAX_HILITER_ROOT . '/assets/js/igeek-utils.js' );
		$this->assertFileDoesNotExist( IG_SYNTAX_HILITER_ROOT . '/assets/css/front-end.css' );
		$this->assertFileDoesNotExist( IG_SYNTAX_HILITER_ROOT . '/assets/scss/config.rb' );

	}

	/**
	 * The v5 Frontend class and its template are gone, replaced by the shortcode
	 * handler and the content protector.
	 *
	 * @return void
	 */
	public function test_v5_frontend_is_gone(): void {

		$this->assertFalse( class_exists( '\iG\Syntax_Hiliter\Frontend', false ) );
		$this->assertFileDoesNotExist( IG_SYNTAX_HILITER_ROOT . '/classes/frontend.php' );
		$this->assertFileDoesNotExist( IG_SYNTAX_HILITER_ROOT . '/templates/frontend-code-box.php' );

		$this->assertTrue( class_exists( '\iG\Syntax_Hiliter\Shortcode_Handler' ) );
		$this->assertTrue( class_exists( '\iG\Syntax_Hiliter\Content_Protector' ) );
		$this->assertTrue( class_exists( '\iG\Syntax_Hiliter\Gist_Embed' ) );

	}

	/**
	 * The autoloader resolves the plugin's classes, including the trait, with
	 * no help from WordPress.
	 *
	 * @return void
	 */
	public function test_autoloader_resolves_plugin_classes(): void {

		$this->assertTrue( class_exists( '\iG\Syntax_Hiliter\Option' ) );
		$this->assertTrue( class_exists( '\iG\Syntax_Hiliter\Validate' ) );
		$this->assertTrue( trait_exists( '\iG\Syntax_Hiliter\Traits\Singleton' ) );
		$this->assertFalse( class_exists( '\iG\Syntax_Hiliter\No_Such_Class' ) );

	}

}    //end of class


//EOF
