<?php
/**
 * A second throwaway child of Base, used by Base_Singleton_Test.
 *
 * It lives in its own file because only one object structure is allowed per file,
 * and the file is named for the class it declares because that is the rule every
 * file in this plugin follows. The tests are autoloaded by a Composer classmap,
 * which would have accepted any name at all — `WordPress.Files.FileName` is what
 * holds the name to the class. PHPUnit does not collect this as a test: the suites
 * match `-test.php`.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration\Fixtures;

use iG\Syntax_Hiliter\Base;
use iG\Syntax_Hiliter\Traits\Singleton;

/**
 * Second fixture subclass of Base.
 */
class Singleton_Fixture_Beta extends Base {

	use Singleton;

	/**
	 * Class constructor.
	 *
	 * Declared for the reason `Admin::__construct()` is declared, and this fixture
	 * exists to hold that reason in place: without it the trait's empty constructor
	 * beats the parent's, and nothing in the plugin would say so until a migration
	 * quietly failed to run on somebody's upgrade.
	 */
	protected function __construct() {    // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- Anything but useless: without this the Singleton trait's empty constructor beats Base's.

		parent::__construct();

	}    //end __construct()

}    //end of class


//EOF
