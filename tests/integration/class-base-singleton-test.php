<?php
/**
 * Tests for the per class singleton slots on the Base class.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use WP_UnitTestCase;

/**
 * Up to v5.1 Base used the Singleton trait, which holds exactly one instance
 * slot. A static property declared in a trait that a parent class uses is
 * shared by every child of that parent, so the first child instantiated was
 * handed back to every other one. v5 survived that only because the Gatekeeper
 * instantiated exactly one child. v6 does not, so Base keeps its own instances
 * keyed by class name and this test holds that in place.
 */
class Base_Singleton_Test extends WP_UnitTestCase {

	/**
	 * Two children of Base get two different objects, each of its own class, and
	 * each of them stable.
	 *
	 * @return void
	 */
	public function test_each_subclass_gets_its_own_stable_instance(): void {

		$alpha = Singleton_Fixture_Alpha::get_instance();
		$beta  = Singleton_Fixture_Beta::get_instance();

		$this->assertInstanceOf( Singleton_Fixture_Alpha::class, $alpha );
		$this->assertInstanceOf( Singleton_Fixture_Beta::class, $beta );
		$this->assertNotSame( $alpha, $beta );

		$this->assertSame( $alpha, Singleton_Fixture_Alpha::get_instance() );
		$this->assertSame( $beta, Singleton_Fixture_Beta::get_instance() );

	}

}    //end of class


//EOF
