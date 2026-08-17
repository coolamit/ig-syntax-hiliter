<?php
/**
 * Base class of the iG:Syntax Hiliter plugin.
 *
 * This is an abstract class which contains some common functionality and is to
 * be inherited; on its own it does not do anything.
 *
 * @package iG_Syntax_Hiliter
 *
 * @author Amit Gupta <https://amitgupta.in/>
 */

namespace iG\Syntax_Hiliter;

/**
 * Common wiring shared by the plugin's WordPress facing classes.
 */
abstract class Base {

	/**
	 * Unique plugin ID.
	 *
	 * @var string
	 */
	const PLUGIN_ID = 'ig-syntax-hiliter';

	/**
	 * Plugin name, for display.
	 *
	 * @var string
	 */
	const PLUGIN_NAME = 'iG:Syntax Hiliter';

	/**
	 * Singleton instances, keyed by class name.
	 *
	 * The Singleton trait is deliberately not used here: a static property
	 * declared in a trait used by a parent is shared by every child of that
	 * parent, so the first child instantiated would be handed back to all the
	 * others.
	 *
	 * @var array<string, static>
	 */
	protected static array $_instances = [];

	/**
	 * Plugin options.
	 *
	 * @var \iG\Syntax_Hiliter\Option
	 */
	protected $_option;

	/**
	 * Default constructor for all children.
	 */
	protected function __construct() {

		//init options
		$this->_option = Option::get_instance();

		/*
		 * Migrate settings if the plugin has been upgraded and a migration is
		 * needed.
		 */
		$this->_maybe_migrate_older_settings();

	}    //end __construct()

	/**
	 * Prevents cloning of children.
	 *
	 * @return void
	 */
	final protected function __clone() {}

	/**
	 * Returns the singleton instance of the class this is called on.
	 *
	 * @return static
	 */
	final public static function get_instance(): static {

		$class = static::class;

		if ( ! isset( static::$_instances[ $class ] ) ) {
			static::$_instances[ $class ] = new $class();
		}

		return static::$_instances[ $class ];

	}    //end get_instance()

	/**
	 * Migrates the settings of older versions of the plugin to the current one.
	 *
	 * @return void
	 */
	protected function _maybe_migrate_older_settings(): void {

		Migrate::get_instance()->settings();

	}    //end _maybe_migrate_older_settings()

}    //end of class

//EOF
