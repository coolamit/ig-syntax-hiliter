<?php
/**
 * The option set v6 ships with, written out.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration\Fixtures;

use iG\Syntax_Hiliter\Asset_Manager;

/**
 * What a fresh v6 install has in the database.
 *
 * **A literal, and it stays a literal.** Reading it out of `Validate` would make
 * every case which asserts against it agree with the plugin by construction, and
 * the whole point of the cases which use it is to notice a default moving. Two
 * files carried byte identical copies of this before, which is a different problem
 * with the same answer: one literal, in one place, and a diff on it when a default
 * really does move.
 *
 * `Migrate_Test` still writes its *migrated* array out inline, and must: that one
 * is what a v5 site turns into, not what a fresh install starts as, and the two
 * happening to agree today is not a reason to write them once.
 */
class Default_Settings {

	/**
	 * The option set v6 ships with.
	 *
	 * @var array
	 */
	const V6 = [
		'theme'             => Asset_Manager::DEFAULT_THEME,
		'font'              => Asset_Manager::FONT_NONE,
		'toolbar'           => 'yes',
		'copy_code'         => 'yes',
		'show_line_numbers' => 'yes',
		'hilite_comments'   => 'yes',
		'gist_in_comments'  => 'no',
		'gist_limit_height' => 'yes',
	];

}    //end of class

//EOF
