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
 *
 * No singleton here; a child that wants one uses the `Singleton` trait. A child using
 * the trait must declare a constructor calling `parent::__construct()`, or it gets the
 * trait's empty one and never sets `$_option` or runs `Migrate`.
 */
abstract class Base {

	/**
	 * Unique plugin ID.
	 *
	 * @var string
	 */
	public const string PLUGIN_ID = 'ig-syntax-hiliter';

	/**
	 * Plugin name, for display.
	 *
	 * @var string
	 */
	public const string PLUGIN_NAME = 'iG:Syntax Hiliter';

	/**
	 * Plugin options.
	 *
	 * @var \iG\Syntax_Hiliter\Option
	 */
	protected Option $_option;

	/**
	 * Default constructor for all children.
	 */
	protected function __construct() {

		$this->_option = Option::get_instance();

		$this->_maybe_migrate_older_settings();

	}

	/**
	 * Migrates the settings of older versions of the plugin to the current one.
	 *
	 * @return void
	 */
	protected function _maybe_migrate_older_settings(): void {

		Migrate::get_instance()->settings();

	}

} // end of class

// EOF
