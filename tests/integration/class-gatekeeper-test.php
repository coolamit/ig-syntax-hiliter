<?php
/**
 * The plugin refuses to load below either version floor.
 *
 * The Gatekeeper is the one thing that has to work on an environment the test
 * suite cannot create — an old PHP, an old WordPress, or both. It therefore takes
 * the two versions it judges as constructor arguments, so that one can be built
 * here for any environment at all and asked what it makes of it.
 *
 * Building one does nothing. `run()` is what loads the plugin or hooks the notice,
 * and nothing here calls it.
 *
 * The floors themselves are asserted in `Plugin_Bootstrap_Test`, against the
 * plugin header which declares them.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG_Syntax_Hiliter_Gatekeeper;
use WP_UnitTestCase;

/**
 * Checks the environment comparison, on both floors and on the version shapes
 * PHP and WordPress really ship.
 */
class Gatekeeper_Test extends WP_UnitTestCase {

	/**
	 * Method to build a Gatekeeper which judges the environment named, whatever the
	 * environment running this actually is.
	 *
	 * @param string $php_version PHP version it is to judge.
	 * @param string $wp_version  WordPress version it is to judge.
	 *
	 * @return \iG_Syntax_Hiliter_Gatekeeper
	 */
	protected function _gate( string $php_version, string $wp_version ): iG_Syntax_Hiliter_Gatekeeper {

		return new iG_Syntax_Hiliter_Gatekeeper( $php_version, $wp_version );

	}

	/**
	 * Supported combinations are let through.
	 *
	 * @return void
	 */
	public function test_supported_environments_are_allowed(): void {

		$this->assertTrue( $this->_gate( '8.4.0', '6.9.0' )->is_environment_supported() );
		$this->assertTrue( $this->_gate( '8.4.12', '6.9.3' )->is_environment_supported() );
		$this->assertTrue( $this->_gate( '8.5.0', '7.0.3' )->is_environment_supported() );
		$this->assertTrue( $this->_gate( '9.0.0', '8.0.0' )->is_environment_supported() );

	}

	/**
	 * Too old a PHP is refused, whatever the WordPress version is.
	 *
	 * @return void
	 */
	public function test_php_below_the_floor_is_refused(): void {

		$this->assertFalse( $this->_gate( '8.3.99', '7.0.3' )->is_environment_supported() );
		$this->assertFalse( $this->_gate( '8.0.0', '6.9.0' )->is_environment_supported() );
		$this->assertFalse( $this->_gate( '7.4.33', '6.9.0' )->is_environment_supported() );
		$this->assertFalse( $this->_gate( '5.6.40', '6.9.0' )->is_environment_supported() );

	}

	/**
	 * Too old a WordPress is refused, whatever the PHP version is.
	 *
	 * @return void
	 */
	public function test_wordpress_below_the_floor_is_refused(): void {

		$this->assertFalse( $this->_gate( '8.5.0', '6.8.3' )->is_environment_supported() );
		$this->assertFalse( $this->_gate( '8.4.0', '6.8.0' )->is_environment_supported() );
		$this->assertFalse( $this->_gate( '8.4.0', '5.9.0' )->is_environment_supported() );
		$this->assertFalse( $this->_gate( '8.4.0', '4.1' )->is_environment_supported() );

	}

	/**
	 * The version shapes PHP and WordPress really ship compare sanely: WordPress
	 * publishes the first release of a branch as `6.9`, PHP ships `8.5.0RC1` and
	 * `8.4.0-dev`, and an unreadable version is refused rather than waved through.
	 *
	 * @return void
	 */
	public function test_real_world_version_shapes_compare_sanely(): void {

		$this->assertTrue( $this->_gate( '8.4', '6.9' )->is_environment_supported() );
		$this->assertTrue( $this->_gate( '8.5.0RC1', '6.9-beta1' )->is_environment_supported() );
		$this->assertTrue( $this->_gate( '8.4.0-dev', '7.0-RC2' )->is_environment_supported() );
		$this->assertTrue( $this->_gate( '8.4.0', '7.0.3.4' )->is_environment_supported() );

		$this->assertFalse( $this->_gate( '8.3-dev', '6.9' )->is_environment_supported() );
		$this->assertFalse( $this->_gate( 'unknown', '6.9' )->is_environment_supported() );
		$this->assertFalse( $this->_gate( '8.4.0', '' )->is_environment_supported() );

	}

	/**
	 * A Gatekeeper built with no arguments judges the environment it is running in.
	 *
	 * That is what the plugin's own boot does, so it is the case the site depends
	 * on — and it passing here also says the rest of the suite is exercising a
	 * plugin that really did load.
	 *
	 * @return void
	 */
	public function test_the_test_environment_is_supported(): void {

		$this->assertTrue( ( new iG_Syntax_Hiliter_Gatekeeper() )->is_environment_supported() );

	}

	/**
	 * The refusal notice names both floors and both versions actually in use.
	 *
	 * This is the sentence read by the one person who most needs it — somebody
	 * whose site is too old to load the plugin at all — and until the Gatekeeper
	 * could be built without loading the plugin there was no way to assert a word
	 * of it. The output is captured rather than printed, because `phpunit.xml.dist`
	 * fails a test which prints anything.
	 *
	 * @return void
	 */
	public function test_the_refusal_notice_names_the_versions_it_judged(): void {

		$gate = $this->_gate( '7.4.33', '6.8' );

		$this->assertFalse( $gate->is_environment_supported(), 'The environment this notice is about must be a refused one.' );

		ob_start();

		try {
			$gate->show_admin_notice();
		} finally {
			$notice = (string) ob_get_clean();
		}

		$this->assertStringContainsString( 'notice-error', $notice );
		$this->assertStringContainsString( iG_Syntax_Hiliter_Gatekeeper::PLUGIN_NAME, $notice );

		//what it needs
		$this->assertStringContainsString( iG_Syntax_Hiliter_Gatekeeper::MIN_PHP_VERSION_REQUIRED, $notice );
		$this->assertStringContainsString( iG_Syntax_Hiliter_Gatekeeper::MIN_WP_VERSION_REQUIRED, $notice );

		//and what it found, which is the half a site owner cannot look up
		$this->assertStringContainsString( '7.4.33', $notice );
		$this->assertStringContainsString( '6.8', $notice );

	}

}    //end of class


//EOF
