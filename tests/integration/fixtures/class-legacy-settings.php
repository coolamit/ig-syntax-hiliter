<?php
/**
 * The option array a v5.1 install holds, written out.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration\Fixtures;

/**
 * What a v5.1 install has in the database before the upgrade to v6.
 *
 * Every value is the opposite of its v6 default, so a setting which fails to carry
 * across cannot pass by coincidence.
 */
class Legacy_Settings {

	/**
	 * The option array a v5.1 install holds.
	 *
	 * @var array
	 */
	public const array V5_1 = [
		'fe-styles'         => 'no',
		'strict_mode'       => 'always',
		'non_strict_mode'   => [ 'php' ],
		'toolbar'           => 'no',
		'plain_text'        => 'no',
		'show_line_numbers' => 'no',
		'hilite_comments'   => 'no',
		'link_to_manual'    => 'yes',
		'gist_in_comments'  => 'yes',
	];

} // end of class

// EOF
