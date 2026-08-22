<?php
/**
 * Tests for the version arithmetic the migration rests on.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Unit;

use iG\Syntax_Hiliter\Migrate;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Every version the plugin has ever stored is normalised before it is compared, and
 * that needs no WordPress. What the migration does with the answer is an integration case.
 */
class Migrate_Test extends TestCase {

	/**
	 * A pre-release suffix is dropped before the three numeric parts are taken, so
	 * `6.0.1-beta-1` is `6.0.1` and not the `6.0.0` that `floatval()` would make of it.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_normalises_a_version_to_its_numeric_parts(): void {

		$normalize = new ReflectionMethod( Migrate::class, '_normalize_version' );

		// The constructor reaches `Option`, which needs WordPress; the method does not.
		$migrate = ( new ReflectionClass( Migrate::class ) )->newInstanceWithoutConstructor();

		// A float key would be truncated to an int, so these are pairs and not a map.
		$cases = [
			[ '6.0.1-beta-1', '6.0.1' ],
			[ '6.10.0-rc1', '6.10.0' ],
			[ '6.0.0-rc-1', '6.0.0' ],
			[ '6.0', '6.0.0' ],
			[ 5.1, '5.1.0' ],
			[ 'junk', '' ],
			[ '', '' ],
		];

		foreach ( $cases as [ $version, $normalised ] ) {
			$this->assertSame( $normalised, $normalize->invoke( $migrate, $version ), sprintf( 'Normalising "%s"', $version ) );
		}

	}

} // end of class

// EOF
