<?php
/**
 * The option set v6 ships with, written out.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration\Fixtures;

/**
 * What a fresh v6 install has in the database.
 *
 * A literal on purpose: reading it from `Validate` would make every case that
 * asserts against it agree by construction.
 */
class Default_Settings {

	/**
	 * The option set v6 ships with.
	 *
	 * @var array
	 */
	public const array V6 = [
		'theme'             => 'prism-okaidia',
		'font'              => 'none',
		'toolbar'           => 'yes',
		'copy_code'         => 'yes',
		'show_line_numbers' => 'yes',
		'match_braces'      => 'yes',
		'rainbow_braces'    => 'no',
		'hilite_comments'   => 'yes',
		'gist_in_comments'  => 'no',
		'gist_limit_height' => 'yes',
	];

} // end of class

// EOF
