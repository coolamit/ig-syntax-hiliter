<?php
/**
 * A throwaway child of Base, used by Base_Singleton_Test.
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

/**
 * First fixture subclass of Base.
 */
class Singleton_Fixture_Alpha extends Base {

}    //end of class


//EOF
