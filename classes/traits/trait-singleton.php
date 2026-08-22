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
	 * `static` is not a legal property type; `get_instance()`'s `is_a( …, static::class )`
	 * narrows it.
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
	 * Returns `static`, so a caller gets the class it asked for. `is_a()` as well as
	 * `isset()`: a child that inherits the trait from a parent rather than using it shares
	 * the parent's slot, and would otherwise be handed the parent's instance.
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

	}

} // end of trait

// EOF
