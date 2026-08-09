<?php
/**
 * Tests for the Gatekeeper's environment check.
 *
 * The Gatekeeper is the one thing that has to work on an environment the test
 * suite cannot create — an old PHP, an old WordPress, or both. Its comparison
 * is therefore kept free of any environment lookup of its own so that it can be
 * driven with arbitrary version strings from here.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG_Syntax_Hiliter_Gatekeeper;
use WP_UnitTestCase;

/**
 * Checks that the plugin refuses to load below either version floor.
 */
class Gatekeeper_Test extends WP_UnitTestCase {

	/**
	 * The Gatekeeper is loaded, and states the floors the PRD requires.
	 *
	 * @return void
	 */
	public function test_the_declared_minimums_are_php_84_and_wp_69(): void {

		$this->assertTrue( class_exists( iG_Syntax_Hiliter_Gatekeeper::class, false ) );
		$this->assertSame( '8.4', iG_Syntax_Hiliter_Gatekeeper::MIN_PHP_VERSION_REQUIRED );
		$this->assertSame( '6.9', iG_Syntax_Hiliter_Gatekeeper::MIN_WP_VERSION_REQUIRED );

	}

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
	 * Failing both floors is still a single refusal.
	 *
	 * @return void
	 */
	public function test_failing_both_floors_is_refused(): void {

		$this->assertFalse( iG_Syntax_Hiliter_Gatekeeper::meets_requirements( '7.4.0', '5.4.0' ) );

	}

	/**
	 * A two part version is not treated as older than its own `.0` release, and
	 * a pre release is not treated as older than the release it leads to.
	 *
	 * @return void
	 */
	public function test_short_and_pre_release_versions_compare_sanely(): void {

		$this->assertTrue( iG_Syntax_Hiliter_Gatekeeper::meets_requirements( '8.4', '6.9' ) );
		$this->assertTrue( iG_Syntax_Hiliter_Gatekeeper::meets_requirements( '8.5.0RC1', '6.9-beta1' ) );
		$this->assertTrue( iG_Syntax_Hiliter_Gatekeeper::meets_requirements( '8.4.0-dev', '7.0-RC2' ) );
		$this->assertFalse( iG_Syntax_Hiliter_Gatekeeper::meets_requirements( '8.3-dev', '6.9' ) );

	}

	/**
	 * Version normalization is total — it never returns anything but three
	 * dot separated integers, whatever it is handed.
	 *
	 * @return void
	 */
	public function test_version_normalization(): void {

		$this->assertSame( '6.9.0', iG_Syntax_Hiliter_Gatekeeper::normalize_version( '6.9' ) );
		$this->assertSame( '6.9.0', iG_Syntax_Hiliter_Gatekeeper::normalize_version( '6.9-beta1' ) );
		$this->assertSame( '7.0.3', iG_Syntax_Hiliter_Gatekeeper::normalize_version( '7.0.3' ) );
		$this->assertSame( '7.0.3', iG_Syntax_Hiliter_Gatekeeper::normalize_version( '7.0.3.4' ) );
		$this->assertSame( '8.5.0', iG_Syntax_Hiliter_Gatekeeper::normalize_version( '8.5.0RC1' ) );
		$this->assertSame( '8.0.0', iG_Syntax_Hiliter_Gatekeeper::normalize_version( '8' ) );
		$this->assertSame( '0.0.0', iG_Syntax_Hiliter_Gatekeeper::normalize_version( '' ) );
		$this->assertSame( '0.0.0', iG_Syntax_Hiliter_Gatekeeper::normalize_version( 'unknown' ) );

	}

	/**
	 * The environment the tests themselves run on is a supported one, so the
	 * rest of the suite is exercising a plugin that actually loaded.
	 *
	 * @return void
	 */
	public function test_the_test_environment_is_supported(): void {

		$this->assertTrue( iG_Syntax_Hiliter_Gatekeeper::is_environment_supported() );
		$this->assertSame( PHP_VERSION, iG_Syntax_Hiliter_Gatekeeper::get_php_version() );
		$this->assertSame( get_bloginfo( 'version' ), iG_Syntax_Hiliter_Gatekeeper::get_wp_version() );

	}

}    //end of class


//EOF
