<?php
/**
 * Tests for the singleton wiring under the Base class and its children.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Option;
use iG\Syntax_Hiliter\Tests\Integration\Fixtures\Singleton_Fixture_Alpha;
use iG\Syntax_Hiliter\Tests\Integration\Fixtures\Singleton_Fixture_Beta;
use ReflectionProperty;
use WP_UnitTestCase;

/**
 * Up to v5.1 Base used the Singleton trait itself, which holds exactly one
 * instance slot. A static property declared in a trait that a parent class uses is
 * shared by every child of that parent, so the first child instantiated was handed
 * back to every other one. v5 survived that only because the Gatekeeper
 * instantiated exactly one child.
 *
 * The answer for most of 6.0 was a map on Base keyed by class name, which worked
 * and was in the wrong place: the trait is per class by design, so a child which
 * uses it gets a slot of its own and there is nothing to work around. Base holds no
 * singleton at all now, and both fixtures below are arranged exactly as `Admin` is
 * — the plugin's one real child of Base — so that what is asserted here is what
 * production does.
 */
class Base_Singleton_Test extends WP_UnitTestCase {

	/**
	 * Two children of Base get two different objects, each of its own class, and
	 * each of them stable.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_gives_each_subclass_its_own_stable_instance(): void {

		$alpha = Singleton_Fixture_Alpha::get_instance();
		$beta  = Singleton_Fixture_Beta::get_instance();

		$this->assertInstanceOf( Singleton_Fixture_Alpha::class, $alpha );
		$this->assertInstanceOf( Singleton_Fixture_Beta::class, $beta );
		$this->assertNotSame( $alpha, $beta );

		$this->assertSame( $alpha, Singleton_Fixture_Alpha::get_instance() );
		$this->assertSame( $beta, Singleton_Fixture_Beta::get_instance() );

	}

	/**
	 * A child using the trait still runs Base's constructor.
	 *
	 * This is the half that nothing asserted before, and it guards the only silent
	 * failure in the arrangement. A constructor declared in the class beats one a
	 * trait brings in, and a trait's beats one inherited from a parent — so a child
	 * which uses `Singleton` and declares no constructor of its own takes the trait's
	 * empty one. Nothing errors. `$_option` is simply never set, and `Migrate` never
	 * runs, because `Base::__construct()` is the only thing which triggers a pending
	 * migration.
	 *
	 * `$_option` is read through reflection because it is protected and is meant to
	 * be: what is being asserted is that the constructor ran, and that property is
	 * the evidence it leaves.
	 *
	 * `isInitialized()` comes first, and that ordering is the whole of what keeps this
	 * failure readable. `Base::$_option` is a typed property with no default, so a
	 * constructor which never ran leaves it *uninitialized* rather than null, and
	 * `getValue()` on it throws an `Error` which says nothing about migrations. The
	 * type belongs on the class — the property really cannot be null, since
	 * `Base::__construct()` assigns `Option::get_instance()` and that always returns an
	 * object — so the diagnostic belongs here instead. Declaring it `?Option` would not
	 * have helped: nullable is not defaulted, and `?Option $_option;` throws exactly the
	 * same `Error`.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_still_runs_the_parent_constructor(): void {

		$property = new ReflectionProperty( Singleton_Fixture_Alpha::class, '_option' );

		foreach ( [ Singleton_Fixture_Alpha::get_instance(), Singleton_Fixture_Beta::get_instance() ] as $fixture ) {

			$this->assertTrue(
				$property->isInitialized( $fixture ),
				'Base\'s constructor never ran, so the options property was never set — which is what happens when a child using the Singleton trait declares no constructor of its own.'
			);

			$this->assertInstanceOf(
				Option::class,
				$property->getValue( $fixture ),
				'The options object is set by Base\'s constructor, and by nothing else.'
			);

		}

	}

}    //end of class


//EOF
