<?php
/**
 * Singleton abstract class meant to be extended by any class that wishes to implement Singleton pattern
 *
 * @author Amit Gupta <http://amitgupta.in/>
 */

namespace iG\Syntax_Hiliter;

abstract class Singleton {

	/**
	 * @var Array An array containing instances of all initialized child classes
	 */
	protected static $_instances = array();

	/**
	 * Protected constructor to prevent direct object creation
	 */
	protected function  __construct() {}

	/**
	 * Prevent object cloning of children
	 */
	final protected function  __clone() {}

	/**
	 * Function to retrieve the instance of the class
	 * @return iG\Syntax_Hiliter\Singleton
	 */
	final public static function get_instance() {
		$class = get_called_class();

		if ( ! isset( static::$_instances[ $class ] ) ) {
			self::$_instances[ $class ] = new $class();
		}

		return self::$_instances[ $class ];
	}

}	//end of class


//EOF