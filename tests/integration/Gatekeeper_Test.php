<?php
/**
 * AC-12 — the plugin refuses to load below either version floor.
 *
 * The Gatekeeper is the one thing that has to work on an environment the test
 * suite cannot create — an old PHP, an old WordPress, or both. Its comparison
 * is therefore kept free of any environment lookup of its own so that it can be
 * driven with arbitrary version strings from here.
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
	 * Supported combinations are let through.
	 *
	 * @return void
	 */
	public function test_supported_environments_are_allowed(): void {

		$this->assertTrue( iG_Syntax_Hiliter_Gatekeeper::meets_requirements( '8.4.0', '6.9.0' ) );
		$this->assertTrue( iG_Syntax_Hiliter_Gatekeeper::meets_requirements( '8.4.12', '6.9.3' ) );
		$this->assertTrue( iG_Syntax_Hiliter_Gatekeeper::meets_requirements( '8.5.0', '7.0.3' ) );
		$this->assertTrue( iG_Syntax_Hiliter_Gatekeeper::meets_requirements( '9.0.0', '8.0.0' ) );

	}

	/**
	 * Too old a PHP is refused, whatever the WordPress version is.
	 *
	 * @return void
	 */
	public function test_php_below_the_floor_is_refused(): void {

		$this->assertFalse( iG_Syntax_Hiliter_Gatekeeper::meets_requirements( '8.3.99', '7.0.3' ) );
		$this->assertFalse( iG_Syntax_Hiliter_Gatekeeper::meets_requirements( '8.0.0', '6.9.0' ) );
		$this->assertFalse( iG_Syntax_Hiliter_Gatekeeper::meets_requirements( '7.4.33', '6.9.0' ) );
		$this->assertFalse( iG_Syntax_Hiliter_Gatekeeper::meets_requirements( '5.6.40', '6.9.0' ) );

	}

	/**
	 * Too old a WordPress is refused, whatever the PHP version is.
	 *
	 * @return void
	 */
	public function test_wordpress_below_the_floor_is_refused(): void {

		$this->assertFalse( iG_Syntax_Hiliter_Gatekeeper::meets_requirements( '8.5.0', '6.8.3' ) );
		$this->assertFalse( iG_Syntax_Hiliter_Gatekeeper::meets_requirements( '8.4.0', '6.8.0' ) );
		$this->assertFalse( iG_Syntax_Hiliter_Gatekeeper::meets_requirements( '8.4.0', '5.9.0' ) );
		$this->assertFalse( iG_Syntax_Hiliter_Gatekeeper::meets_requirements( '8.4.0', '4.1' ) );

	}

	/**
	 * The version shapes PHP and WordPress really ship compare sanely: WordPress
	 * publishes the first release of a branch as `6.9`, PHP ships `8.5.0RC1` and
	 * `8.4.0-dev`, and an unreadable version is refused rather than waved through.
	 *
	 * @return void
	 */
	public function test_real_world_version_shapes_compare_sanely(): void {

		$this->assertTrue( iG_Syntax_Hiliter_Gatekeeper::meets_requirements( '8.4', '6.9' ) );
		$this->assertTrue( iG_Syntax_Hiliter_Gatekeeper::meets_requirements( '8.5.0RC1', '6.9-beta1' ) );
		$this->assertTrue( iG_Syntax_Hiliter_Gatekeeper::meets_requirements( '8.4.0-dev', '7.0-RC2' ) );
		$this->assertTrue( iG_Syntax_Hiliter_Gatekeeper::meets_requirements( '8.4.0', '7.0.3.4' ) );

		$this->assertFalse( iG_Syntax_Hiliter_Gatekeeper::meets_requirements( '8.3-dev', '6.9' ) );
		$this->assertFalse( iG_Syntax_Hiliter_Gatekeeper::meets_requirements( 'unknown', '6.9' ) );
		$this->assertFalse( iG_Syntax_Hiliter_Gatekeeper::meets_requirements( '8.4.0', '' ) );

	}

	/**
	 * The environment the tests themselves run on is a supported one, so the rest
	 * of the suite is exercising a plugin that actually loaded.
	 *
	 * @return void
	 */
	public function test_the_test_environment_is_supported(): void {

		$this->assertTrue( iG_Syntax_Hiliter_Gatekeeper::is_environment_supported() );

	}

}    //end of class


//EOF
