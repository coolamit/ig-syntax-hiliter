<?php
/**
 * Tests for the per class singleton slots on the Base class.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Base;
use iG\Syntax_Hiliter\Traits\Singleton;
use WP_UnitTestCase;

/**
 * Up to v5.1 Base used the Singleton trait, which holds exactly one instance
 * slot. A static property declared in a trait that a parent class uses is
 * shared by every child of that parent, so the first child instantiated was
 * handed back to every other one. v5 survived that only because the Gatekeeper
 * instantiated exactly one child. v6 does not, so Base keeps its own instances
 * keyed by class name and these tests hold that in place.
 */
class Base_Singleton_Test extends WP_UnitTestCase {

	/**
	 * Two children of Base get two different objects, each of its own class.
	 *
	 * @return void
	 */
	public function test_each_subclass_gets_its_own_instance(): void {

		$alpha = Singleton_Fixture_Alpha::get_instance();
		$beta  = Singleton_Fixture_Beta::get_instance();

		$this->assertInstanceOf( Singleton_Fixture_Alpha::class, $alpha );
		$this->assertInstanceOf( Singleton_Fixture_Beta::class, $beta );
		$this->assertNotSame( $alpha, $beta );

	}

	/**
	 * Each of those slots is still a singleton.
	 *
	 * @return void
	 */
	public function test_each_slot_is_stable(): void {

		$this->assertSame( Singleton_Fixture_Alpha::get_instance(), Singleton_Fixture_Alpha::get_instance() );
		$this->assertSame( Singleton_Fixture_Beta::get_instance(), Singleton_Fixture_Beta::get_instance() );

	}

	/**
	 * The order the children are asked for in makes no difference — which is
	 * exactly what the shared slot got wrong.
	 *
	 * @return void
	 */
	public function test_instance_identity_does_not_depend_on_call_order(): void {

		$beta_first = Singleton_Fixture_Beta::get_instance();

		$this->assertNotInstanceOf( Singleton_Fixture_Alpha::class, $beta_first );
		$this->assertInstanceOf( Singleton_Fixture_Beta::class, Singleton_Fixture_Beta::get_instance() );
		$this->assertInstanceOf( Singleton_Fixture_Alpha::class, Singleton_Fixture_Alpha::get_instance() );

	}

	/**
	 * Base no longer uses the Singleton trait, which is what made the slot
	 * shared in the first place. The trait itself is untouched and is still
	 * used by the classes that have no children.
	 *
	 * @return void
	 */
	public function test_base_does_not_use_the_singleton_trait(): void {

		$this->assertNotContains( Singleton::class, class_uses( Base::class ) );
		$this->assertContains( Singleton::class, class_uses( 'iG\Syntax_Hiliter\Option' ) );

	}

}    //end of class


//EOF
