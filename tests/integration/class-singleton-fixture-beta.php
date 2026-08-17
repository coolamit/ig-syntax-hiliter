<?php
/**
 * A second throwaway child of Base, used by Base_Singleton_Test.
 *
 * It lives in its own file, named after the class, because the tests directory
 * is autoloaded PSR-4 and because only one object structure is allowed per
 * file. PHPUnit does not collect it as a test — the suites match `*Test.php`.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Base;

/**
 * Second fixture subclass of Base.
 */
class Singleton_Fixture_Beta extends Base {

}    //end of class


//EOF
