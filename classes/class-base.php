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
 * There is no singleton here, deliberately. A child which wants one uses the
 * `Singleton` trait, which gives that child an instance slot of its own — that is
 * what a per class singleton trait is for, and putting the store on this class
 * instead was how it came to hold a map keyed by class name to work around being
 * in the wrong place. A child using the trait **must declare a constructor calling
 * `parent::__construct()`**, or it takes the trait's empty one and never runs the
 * two lines below; `Admin` says so at greater length.
 */
abstract class Base {

	/**
	 * Unique plugin ID.
	 *
	 * @var string
	 */
	const string PLUGIN_ID = 'ig-syntax-hiliter';

	/**
	 * Plugin name, for display.
	 *
	 * @var string
	 */
	const string PLUGIN_NAME = 'iG:Syntax Hiliter';

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

		//init options
		$this->_option = Option::get_instance();

		/*
		 * Migrate settings if the plugin has been upgraded and a migration is
		 * needed.
		 */
		$this->_maybe_migrate_older_settings();

	}    //end __construct()

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
