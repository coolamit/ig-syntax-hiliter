<?php
/**
 * Class for validating plugin values
 *
 * @package iG_Syntax_Hiliter
 *
 * @author Amit Gupta <https://amitgupta.in/>
 *
 * @since 2015-07-22
 */

namespace iG\Syntax_Hiliter;

use iG\Syntax_Hiliter\Traits\Singleton;

/**
 * Validators for the plugin's stored option values.
 */
class Validate {

	use Singleton;

	/**
	 * Class constructor
	 */
	protected function __construct() {}

	/**
	 * This function checks whether the passed value is YES/NO or not. If it is then
	 * it returns TRUE else FALSE. The parameter accepts only string.
	 *
	 * @param mixed $value Value to check.
	 *
	 * @return bool
	 */
	public function is_yesno( $value ): bool {

		if ( ! is_string( $value ) ) {
			return false;
		}

		$value = strtolower( trim( $value ) );

		return ( in_array( $value, [ 'yes', 'no' ], true ) );

	}

}    //end of class

//EOF
