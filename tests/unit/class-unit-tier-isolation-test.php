<?php
/**
 * The unit tier runs with no WordPress installation.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The domain core is designed to have no WordPress dependency. This tier is what
 * holds that in place, and is worth nothing if WordPress is quietly loaded into it.
 */
class Unit_Tier_Isolation_Test extends TestCase {

	/**
	 * WordPress is not loaded, and must never be, for this tier.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_runs_with_no_wordpress_loaded(): void {

		$this->assertFalse( function_exists( 'add_action' ), 'The unit tier must run with no WordPress loaded.' );
		$this->assertFalse( class_exists( 'WP_UnitTestCase', false ), 'The unit tier must not load the WordPress test library.' );

	}

} // end of class

// EOF
