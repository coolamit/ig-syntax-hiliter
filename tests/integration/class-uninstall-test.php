<?php
/**
 * Deleting the plugin leaves nothing of it in the options table.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Base;
use iG\Syntax_Hiliter\Cache;
use iG\Syntax_Hiliter\Migrate;
use WP_UnitTestCase;

/**
 * Runs the uninstall routine the way WordPress runs it, with the plugin's own
 * classes deliberately not involved. It tests `uninstall.php`, which runs with the
 * plugin not loaded, so there is no class to name.
 */
class Uninstall_Test extends WP_UnitTestCase {

	/**
	 * Every option this plugin has ever written is gone afterwards, including the
	 * cache options whose names are an MD5 and can only be matched by prefix.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_removes_every_option_the_plugin_stores_on_uninstall(): void {

		update_option( Base::PLUGIN_ID . '-options', [ 'theme' => 'prism' ] );
		update_option( Base::PLUGIN_ID . '-version', IG_SYNTAX_HILITER_VERSION );
		update_option( Base::PLUGIN_ID . '-migrated-from', '5.1.0' );
		update_option( Base::PLUGIN_ID . '-lang-time', time() );
		update_option( Migrate::V35_OPTION_NAME, [ 'PLAIN_TEXT' => true ] );
		update_option( Cache::KEY_PREFIX . md5( Base::PLUGIN_ID . '-languages' ), [ 'data' => [ 'php' ] ] );
		update_option( Cache::KEY_PREFIX . md5( 'ig-syntax-hiliter-languages-' . IG_SYNTAX_HILITER_VERSION ), [ 'data' => [ 'php' ] ] );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'igsyntax-hiliter/ig-syntax-hiliter.php' );    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Name is fixed by WordPress; it is what uninstall.php checks for.
		}

		require IG_SYNTAX_HILITER_TESTS_PLUGIN_DIR . '/uninstall.php';

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Asserting on the table itself is the point of this test.
		$leftovers = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'ig-syntax-hiliter%' OR option_name LIKE 'igsh%'"
		);

		$this->assertSame( [], $leftovers );

	}

} // end of class

// EOF
