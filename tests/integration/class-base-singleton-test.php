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
 * A static property declared in a trait that a parent uses is shared by every child
 * of that parent. `Base` holds no singleton; both fixtures are arranged exactly as
 * `Admin` is.
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
	 * A child using the `Singleton` trait still runs `Base`'s constructor.
	 *
	 * A trait's constructor beats an inherited one, so a child declaring none takes the
	 * trait's empty one: `$_option` is never set and `Migrate` never runs.
	 * `isInitialized()` is checked first because `Base::$_option` is typed with no
	 * default, so `getValue()` on an unrun constructor throws an `Error` that says
	 * nothing about migrations.
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

} // end of class

// EOF
