<?php
/**
 * The plugin refuses to load below either version floor.
 *
 * The Gatekeeper takes the two versions it judges as constructor arguments, so one can
 * be built for an environment the suite cannot create. Building one does nothing;
 * `run()` loads the plugin or hooks the notice, and nothing here calls it.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG_Syntax_Hiliter_Gatekeeper;
use WP_UnitTestCase;

/**
 * The half which needs WordPress: the running environment, and the notice built with `esc_html()` and `__()`.
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
	 * A Gatekeeper built with no arguments judges the environment it is running in.
	 *
	 * That is what the plugin's own boot does, and it passing says the rest of the
	 * suite is exercising a plugin that really did load.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_supports_the_test_environment(): void {

		$this->assertTrue( ( new iG_Syntax_Hiliter_Gatekeeper() )->is_environment_supported() );

	}

	/**
	 * The refusal notice names both floors and both versions actually in use.
	 *
	 * The output is captured rather than printed, because `phpunit.xml.dist` fails a
	 * test which prints anything.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_names_the_versions_it_judged_in_the_refusal_notice(): void {

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

		$this->assertStringContainsString( iG_Syntax_Hiliter_Gatekeeper::MIN_PHP_VERSION_REQUIRED, $notice );
		$this->assertStringContainsString( iG_Syntax_Hiliter_Gatekeeper::MIN_WP_VERSION_REQUIRED, $notice );

		// What it found, which is the half a site owner cannot look up.
		$this->assertStringContainsString( '7.4.33', $notice );
		$this->assertStringContainsString( '6.8', $notice );

	}

} // end of class

// EOF
