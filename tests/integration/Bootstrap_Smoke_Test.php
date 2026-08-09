<?php
/**
 * Smoke test proving the integration tier boots WordPress with the plugin loaded.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use WP_UnitTestCase;

/**
 * Checks WordPress is up and that it loaded this plugin.
 */
class Bootstrap_Smoke_Test extends WP_UnitTestCase {

	/**
	 * WordPress itself is loaded and is new enough for this plugin.
	 *
	 * @return void
	 */
	public function test_wordpress_is_loaded(): void {

		$this->assertTrue( function_exists( 'add_action' ) );
		$this->assertTrue( defined( 'ABSPATH' ) );
		$this->assertTrue( version_compare( get_bloginfo( 'version' ), '6.9', '>=' ), 'The plugin requires WordPress 6.9 or newer.' );

	}

	/**
	 * The plugin's main file ran and defined its constants.
	 *
	 * @return void
	 */
	public function test_plugin_is_loaded(): void {

		$this->assertTrue( defined( 'IG_SYNTAX_HILITER_VERSION' ) );
		$this->assertTrue( defined( 'IG_SYNTAX_HILITER_ROOT' ) );
		$this->assertSame( IG_SYNTAX_HILITER_TESTS_PLUGIN_DIR, IG_SYNTAX_HILITER_ROOT );
		$this->assertTrue( function_exists( 'ig_syntax_hiliter_loader' ) );

	}

	/**
	 * The plugin was loaded early enough to see the whole WordPress bootstrap.
	 *
	 * @return void
	 */
	public function test_plugin_loaded_before_init(): void {

		$this->assertGreaterThan( 0, did_action( 'muplugins_loaded' ) );
		$this->assertGreaterThan( 0, did_action( 'init' ) );

	}

	/**
	 * The plugin's autoloader resolves plugin classes inside WordPress.
	 *
	 * @return void
	 */
	public function test_plugin_classes_autoload(): void {

		$this->assertTrue( class_exists( '\iG\Syntax_Hiliter\Helper' ) );

	}

}    //end of class


//EOF
