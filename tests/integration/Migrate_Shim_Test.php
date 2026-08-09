<?php
/**
 * Tests for the interim Migrate guard.
 *
 * Migrate opened with a float comparison of the stored version against
 * IG_SYNTAX_HILITER_VERSION, on every single page load. The constant is a
 * string from 6.0.0 onwards, so that comparison has been replaced with an exact
 * string match. The full version_compare() rewrite, the v5 to v6 option mapping
 * and the option cleanup all land in M4; what is tested here is only that the
 * guard stops the migration running more than once.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Base;
use iG\Syntax_Hiliter\Migrate;
use WP_UnitTestCase;

/**
 * Checks the migration runs exactly once per version.
 */
class Migrate_Shim_Test extends WP_UnitTestCase {

	/**
	 * A fresh install writes the version as a string and initialises options.
	 *
	 * @return void
	 */
	public function test_fresh_install_writes_the_version_as_a_string(): void {

		delete_option( Base::PLUGIN_ID . '-version' );
		delete_option( Base::PLUGIN_ID . '-options' );

		Migrate::get_instance()->settings();

		$this->assertSame( '6.0.0', get_option( Base::PLUGIN_ID . '-version' ) );
		$this->assertIsArray( get_option( Base::PLUGIN_ID . '-options' ) );

	}

	/**
	 * Once the stored version matches, the migration does nothing at all on
	 * every subsequent load. If it were still running, it would rewrite the
	 * options that are deleted below.
	 *
	 * @return void
	 */
	public function test_migration_does_not_run_again_on_a_second_load(): void {

		delete_option( Base::PLUGIN_ID . '-version' );
		delete_option( Base::PLUGIN_ID . '-options' );

		Migrate::get_instance()->settings();

		$this->assertSame( '6.0.0', get_option( Base::PLUGIN_ID . '-version' ) );

		delete_option( Base::PLUGIN_ID . '-options' );

		Migrate::get_instance()->settings();

		$this->assertFalse( get_option( Base::PLUGIN_ID . '-options', false ), 'The migration ran a second time on an up to date install.' );
		$this->assertSame( '6.0.0', get_option( Base::PLUGIN_ID . '-version' ) );

	}

	/**
	 * A stored version is compared as a string, not as a float — a v5.1 install
	 * holds the float `5.1`, and `6.0` is what floatval() makes of `6.0.0`, so a
	 * float comparison would treat that as current and skip the upgrade entirely.
	 *
	 * The v5 to v6 settings mapping itself is M4's job; what matters here is that
	 * the upgrade path runs at all.
	 *
	 * @return void
	 */
	public function test_a_float_shaped_stored_version_is_not_mistaken_for_the_current_one(): void {

		foreach ( [ 5.1, '6.0' ] as $stored ) {

			update_option( Base::PLUGIN_ID . '-version', $stored );
			delete_option( Base::PLUGIN_ID . '-options' );

			Migrate::get_instance()->settings();

			$this->assertSame( '6.0.0', get_option( Base::PLUGIN_ID . '-version' ) );
			$this->assertIsArray( get_option( Base::PLUGIN_ID . '-options' ) );

		}

	}

}    //end of class


//EOF
