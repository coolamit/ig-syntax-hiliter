<?php
/**
 * The Gatekeeper's version comparison.
 *
 * In the unit tier, because none of it needs WordPress. The Gatekeeper touches
 * WordPress at exactly three points — `$GLOBALS['wp_version']`, which is skipped
 * when a version is passed to it; `add_action()` in `run()`; and `esc_html()` and
 * `__()` in `show_admin_notice()` — and every case below passes both versions and
 * calls only `is_environment_supported()`. They used to boot a database backed
 * suite to compare two strings.
 *
 * The class is deliberately the plugin's one non-namespaced class, so it is not
 * autoloadable and is required by name. `tests/compat/refusal-check.php` does the
 * same on a bare interpreter with no Composer at all, which is what says this
 * works.
 *
 * The floors themselves are asserted in `Plugin_Bootstrap_Test`, against the
 * plugin header which declares them.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Unit;

use iG_Syntax_Hiliter_Gatekeeper;
use PHPUnit\Framework\TestCase;

require_once IG_SYNTAX_HILITER_ROOT . '/classes/class-ig-syntax-hiliter-gatekeeper.php';

/**
 * Checks the environment comparison, on both floors and on the version shapes PHP
 * and WordPress really ship.
 */
class Gatekeeper_Versions_Test extends TestCase {

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
	 * @test
	 *
	 * @return void
	 */
	public function it_allows_a_supported_environment(): void {

		$this->assertTrue( $this->_gate( '8.4.0', '6.9.0' )->is_environment_supported() );
		$this->assertTrue( $this->_gate( '8.4.12', '6.9.3' )->is_environment_supported() );
		$this->assertTrue( $this->_gate( '8.5.0', '7.0.3' )->is_environment_supported() );
		$this->assertTrue( $this->_gate( '9.0.0', '8.0.0' )->is_environment_supported() );

	}

	/**
	 * Too old a PHP is refused, whatever the WordPress version is.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_refuses_php_below_the_floor(): void {

		$this->assertFalse( $this->_gate( '8.3.99', '7.0.3' )->is_environment_supported() );
		$this->assertFalse( $this->_gate( '8.0.0', '6.9.0' )->is_environment_supported() );
		$this->assertFalse( $this->_gate( '7.4.33', '6.9.0' )->is_environment_supported() );
		$this->assertFalse( $this->_gate( '5.6.40', '6.9.0' )->is_environment_supported() );

	}

	/**
	 * Too old a WordPress is refused, whatever the PHP version is.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_refuses_wordpress_below_the_floor(): void {

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
	 * @test
	 *
	 * @return void
	 */
	public function it_compares_real_world_version_shapes_sanely(): void {

		$this->assertTrue( $this->_gate( '8.4', '6.9' )->is_environment_supported() );
		$this->assertTrue( $this->_gate( '8.5.0RC1', '6.9-beta1' )->is_environment_supported() );
		$this->assertTrue( $this->_gate( '8.4.0-dev', '7.0-RC2' )->is_environment_supported() );
		$this->assertTrue( $this->_gate( '8.4.0', '7.0.3.4' )->is_environment_supported() );

		$this->assertFalse( $this->_gate( '8.3-dev', '6.9' )->is_environment_supported() );
		$this->assertFalse( $this->_gate( 'unknown', '6.9' )->is_environment_supported() );
		$this->assertFalse( $this->_gate( '8.4.0', '' )->is_environment_supported() );

	}

}    //end of class


//EOF
