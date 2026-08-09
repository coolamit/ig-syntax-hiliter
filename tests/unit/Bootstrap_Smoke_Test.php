<?php
/**
 * Smoke test proving the unit tier is wired up and runs without WordPress.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Checks the plugin can be located and autoloaded with no WordPress present.
 */
class Bootstrap_Smoke_Test extends TestCase {

	/**
	 * The plugin's main file is where the test run says it is.
	 *
	 * @return void
	 */
	public function test_plugin_main_file_exists(): void {

		$this->assertFileExists( IG_SYNTAX_HILITER_TESTS_PLUGIN_DIR . '/ig-syntax-hiliter.php' );

	}

	/**
	 * The plugin root constant the autoloader relies on is set.
	 *
	 * @return void
	 */
	public function test_plugin_root_constant_is_defined(): void {

		$this->assertTrue( defined( 'IG_SYNTAX_HILITER_ROOT' ) );
		$this->assertDirectoryExists( IG_SYNTAX_HILITER_ROOT );

	}

	/**
	 * The plugin's own autoloader has been registered.
	 *
	 * @return void
	 */
	public function test_plugin_autoloader_is_registered(): void {

		$this->assertContains( 'ig_syntax_hiliter_autoloader', (array) spl_autoload_functions() );

	}

	/**
	 * WordPress is not loaded, and must never be, for this tier.
	 *
	 * @return void
	 */
	public function test_wordpress_is_not_loaded(): void {

		$this->assertFalse( function_exists( 'add_action' ), 'The unit tier must run with no WordPress loaded.' );
		$this->assertFalse( class_exists( 'WP_UnitTestCase', false ), 'The unit tier must not load the WordPress test library.' );

	}

}    //end of class


//EOF
