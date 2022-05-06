<?php
/**
 * Singleton trait meant to be implemented in any class that wishes to implement Singleton pattern
 *
 * @author Amit Gupta <https://amitgupta.in/>
 *
 * @since  2020-05-31
 */

namespace iG\Syntax_Hiliter\Traits;

trait Singleton {

	/**
	 * @var object Var containing instance of current class which has implemented this trait
	 */
	protected static $_instance;

	/**
	 * Protected constructor to prevent direct object creation
	 */
	protected function  __construct() {}

	/**
	 * Prevent object cloning of children
	 */
	final protected function  __clone() {}

	/**
	 * Method to retrieve the singleton instance of the class
	 *
	 * @return object
	 */
	final public static function get_instance() : object {

		$class = get_called_class();

		if ( empty( static::$_instance ) ) {
			static::$_instance = new $class();
		}

		return static::$_instance;

	}

}    // end of trait

//EOF
