<?php
/**
 * Singleton trait meant to be implemented in any class that wishes to implement Singleton pattern
 *
 * @package iG_Syntax_Hiliter
 *
 * @author Amit Gupta <https://amitgupta.in/>
 *
 * @since  2020-05-31
 */

namespace iG\Syntax_Hiliter\Traits;

/**
 * Gives the class using it a single shared instance and a private constructor.
 */
trait Singleton {

	/**
	 * Instance of the current class which has implemented this trait.
	 *
	 * Typed `?object` and not `static`, because `static` is not usable as a property
	 * type. What narrows it to the class asking is the `is_a( …, static::class )`
	 * check in `get_instance()`, which stays for that reason.
	 *
	 * @var object|null
	 */
	protected static ?object $_instance = null;

	/**
	 * Protected constructor to prevent direct object creation.
	 */
	protected function __construct() {}

	/**
	 * Prevent object cloning of children.
	 *
	 * @return void
	 */
	final protected function __clone() {}

	/**
	 * Method to retrieve the singleton instance of the class.
	 *
	 * The return type is `static` and not `object`, so a caller is handed the class
	 * it asked for rather than something it has to narrow again. That is what let
	 * three classes stop hand-rolling a copy of this method purely to keep their own
	 * type.
	 *
	 * Arguments are passed to the constructor and are used **only on the first call**,
	 * which is inherent to a singleton rather than a shortcoming of this: the second
	 * caller is handed the object the first one built. A class whose constructor takes
	 * arguments should therefore be able to build itself from none, or be built by
	 * whoever reaches it first and by nobody else.
	 *
	 * `is_a()` rather than `isset()` alone: the property is declared in this trait, so
	 * a parent and a child which both use the trait have one each — but a class which
	 * declares its own would otherwise be able to hand back an instance of something
	 * else entirely.
	 *
	 * @param mixed ...$args Arguments for the constructor, used only when the instance is built.
	 *
	 * @return static
	 */
	final public static function get_instance( mixed ...$args ): static {

		if ( ! isset( static::$_instance ) || ! is_a( static::$_instance, static::class ) ) {
			static::$_instance = new static( ...$args );
		}

		return static::$_instance;

	}    //end get_instance()

}    //end of trait

//EOF
