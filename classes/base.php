<?php
/**
 * iG Syntax Hiliter base class
 * This is an abstract class which contains some common functionality and is to be
 * inherited, on its own it doesn't do anything
 *
 * @author Amit Gupta <http://amitgupta.in/>
 */

namespace iG\Syntax_Hiliter;

abstract class Base extends Singleton {

	/**
	 * @var const Class constant containing unique plugin ID
	 */
	const PLUGIN_ID = "ig-syntax-hiliter";
	/**
	 * @var const Class constant containing plugin name for display
	 */
	const PLUGIN_NAME = "iG:Syntax Hiliter";
	/**
	 * @var const Class constant containing life of language name cache in seconds
	 */
	const LANGUAGES_CACHE_LIFE = WEEK_IN_SECONDS;	//1 week
	/**
	 * @var String Static var containing path to GeSHi language file folder
	 */
	protected static $__dirs;

	/**
	 * @var iG\Syntax_Hiliter\Option
	 */
	protected $_option;
	/**
	 * @var iG\Syntax_Hiliter\Validate
	 */
	protected $_validate;

	/**
	 * Default constructor for all children
	 */
	protected function __construct() {
		$this->_setup_dir_paths();

		//init options
		$this->_option = Option::get_instance();

		//init validation
		$this->_validate = Validate::get_instance();

		/*
		 * Migrate settings if plugin has been upgraded
		 * and a migration is needed.
		 */
		$this->_maybe_migrate_older_settings();

		/*
		 * Make sure languages cache is re-built if needed.
		 */
		$this->get_languages();
	}

	/**
	 * This function scans the validated language file directories, retrieves the
	 * names of all files with .php extension and returns an array with unique
	 * file names
	 */
	public function build_language_list() {
		if ( empty( self::$__dirs ) ) {
			return false;
		}

		$tags = array();

		foreach ( self::$__dirs as $dir ) {
			$tags = array_merge( $tags, glob( $dir . "/*.php" ) );
		}

		$tags = array_unique( array_filter( array_map( array( $this, '_file_to_tag_name' ), $tags ) ) );

		sort( $tags );

		$this->_set_language_cache_build_time();	//set language list built time

		return $tags;
	}

	/**
	 * This function uses WP TLC class to fetch the language file names from supported directories
	 * and schedules a refresh of the transient cache. It also allows a force rebuild of
	 * transient cache via the optional $force_rebuild parameter.
	 */
	public function get_languages( $force_rebuild = 'no' ) {
		$force_rebuild = ( ! $this->_validate->is_yesno( $force_rebuild ) ) ? 'no' : strtolower( $force_rebuild );

		$cache = new Cache( self::PLUGIN_ID . '-languages' );

		if ( $force_rebuild == 'yes' ) {
			$cache->delete();	//delete existing cache so we'll get fresh one below
		}

		$languages = $cache->updates_with( array( $this, 'build_language_list' ) )
							->expires_in( self::LANGUAGES_CACHE_LIFE )
							->get();

		if ( empty( $languages ) ) {
			return;
		}

		return array_combine( $languages, $languages );
	}

	/**
	 * Returns the timestamp when language file name cache was last built. If no timestamp
	 * found then it sets current timestamp and returns that.
	 */
	protected function _get_language_cache_build_time() {
		$time = get_option( self::PLUGIN_ID . '-lang-time' );

		if ( $time === false ) {
			return $this->_set_language_cache_build_time();
		}

		return $time;
	}

	/**
	 * Sets the timestamp to mark when language file name cache was built
	 */
	protected function _set_language_cache_build_time() {
		$time = time();

		update_option( self::PLUGIN_ID . '-lang-time', $time );

		return $time;
	}

	/**
	 * Returns the file name of a php file from the physical file path
	 */
	protected function _file_to_tag_name( $file_path ) {

		$file_path = str_replace( '\\', '/', $file_path );

		if ( empty( $file_path ) || strpos( $file_path, '.php' ) === false ) {

			$file_path = '';

		} else {

			$file_path = explode( '/', $file_path );
			$file_path = explode( '.', array_pop( $file_path ) );

			array_pop( $file_path );

			$file_path = implode( '.', $file_path );

		}

		return $file_path;

	}

	/**
	 * This function is for migrating settings of older versions of plugins to the
	 * current version
	 *
	 * @return void
	 */
	protected function _maybe_migrate_older_settings() {
		Migrate::get_instance()->settings( $this );
	}

	/**
	 * This function stores geshi language file directory paths in an array. Besides
	 * the directory in the plugin's directory, it looks for geshi directory in parent
	 * and child theme directories, and whichever exists is stored.
	 */
	protected function _setup_dir_paths() {

		self::$__dirs['geshi'] = dirname( __DIR__ ) . "/geshi";					//setup geshi dir path
		self::$__dirs['theme'] = get_template_directory() . "/geshi";			//setup geshi dir path in current theme folder
		self::$__dirs['child_theme'] = get_stylesheet_directory() . "/geshi";	//setup geshi dir path in child theme folder

		if ( ! is_dir( self::$__dirs['child_theme'] ) || self::$__dirs['theme'] == self::$__dirs['child_theme'] ) {

			unset( self::$__dirs['child_theme'] );	//no geshi dir in child theme

		}

		if ( ! is_dir( self::$__dirs['theme'] ) ) {

			unset( self::$__dirs['theme'] );	//no geshi dir in current theme

		}

	}

}	//end of class


//EOF