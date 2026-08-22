<?php
/**
 * A throwaway child of Base, used by Base_Test.
 *
 * Named for the class, which is what `WordPress.Files.FileName` enforces; PHPUnit
 * does not collect it, since the suites match `-test.php`.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration\Fixtures;

use iG\Syntax_Hiliter\Base;
use iG\Syntax_Hiliter\Traits\Singleton;

/**
 * First fixture subclass of Base.
 */
class Singleton_Fixture_Alpha extends Base {

	use Singleton;

	/**
	 * Class constructor.
	 *
	 * Declared for the same reason `Admin::__construct()` is: without it the
	 * `Singleton` trait's empty constructor beats `Base`'s and the migration never runs.
	 */
	protected function __construct() {    // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- Without this the Singleton trait's empty constructor beats Base's.

		parent::__construct();

	}

} // end of class

// EOF
