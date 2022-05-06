<?php
/**
 * iG Syntax Hiliter base class
 * This is an abstract class which contains some common functionality and is to be
 * inherited, on its own it doesn't do anything
 *
 * @author Amit Gupta <https://amitgupta.in/>
 */

namespace iG\Syntax_Hiliter;

use \iG\Syntax_Hiliter\Traits\Singleton;

abstract class Base {

	use Singleton;

	/**
	 * @var const Class constant containing unique plugin ID
	 */
	const PLUGIN_ID = 'ig-syntax-hiliter';

	/**
	 * @var const Class constant containing plugin name for display
	 */
	const PLUGIN_NAME = 'iG:Syntax Hiliter';

	/**
	 * @var const Class constant containing life of language name cache in seconds
	 */
	const LANGUAGES_CACHE_LIFE = WEEK_IN_SECONDS;    // 1 week

	/**
	 * @var array Array containing paths to GeSHi language file folders
	 */
	protected $__dirs;

	/**
	 * @var \iG\Syntax_Hiliter\Option
	 */
	protected $_option;

	/**
	 * @var \iG\Syntax_Hiliter\Validate
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
	 * This method scans the validated language file directories, retrieves the
	 * names of all language files and returns an array with unique file names.
	 *
	 * @return array
	 */
	public function get_uncached_languages() : array {

		$tags = [];

		if ( empty( $this->__dirs ) ) {
			return $tags;
		}

		foreach ( $this->__dirs as $dir ) {
			$pattern = sprintf(
				'%s/*.php',
				untrailingslashit( $dir )
			);

			$tags = array_merge( $tags, glob( $pattern ) );
		}

		$tags = array_unique(
			array_filter(
				array_map( [ $this, '_file_to_tag_name' ], $tags )
			)
		);

		sort( $tags );

		$this->_set_language_cache_build_time();    //set language list built time

		return $tags;

	}

	/**
	 * Method to get cached array of language file names from supported directories.
	 *
	 * @param string $force_rebuild Optional parameter to force rebuild the cache
	 *
	 * @return array
	 *
	 * @throws \ErrorException
	 */
	public function get_languages( string $force_rebuild = 'no' ) : array {

		$force_rebuild = ( ! $this->_validate->is_yesno( $force_rebuild ) ) ? 'no' : strtolower( $force_rebuild );

		$cache = Cache::create( self::PLUGIN_ID . '-languages' );

		if ( 'yes' === $force_rebuild ) {
			$cache->delete();    //delete existing cache so we'll get fresh one below
		}

		$languages = $cache->updates_with( [ $this, 'get_uncached_languages' ] )
							->expires_in( self::LANGUAGES_CACHE_LIFE )
							->get();

		if ( empty( $languages ) ) {
			return [];
		}

		return array_combine( $languages, $languages );

	}

	/**
	 * Returns the timestamp when language file name cache was last built. If no timestamp
	 * found then it sets current timestamp and returns that.
	 *
	 * @return int
	 */
	protected function _get_language_cache_build_time() : int {

		$time = get_option( self::PLUGIN_ID . '-lang-time' );

		if ( false === $time ) {
			return $this->_set_language_cache_build_time();
		}

		return $time;

	}

	/**
	 * Sets the timestamp to mark when language file name cache was built
	 *
	 * @return int
	 */
	protected function _set_language_cache_build_time() : int {

		$time = time();

		update_option( self::PLUGIN_ID . '-lang-time', $time );

		return $time;

	}

	/**
	 * Returns the file name of a php file from the physical file path
	 *
	 * @return string
	 */
	protected function _file_to_tag_name( string $path ) : string {

		$path = str_replace( '\\', '/', $path );

		if ( empty( $path ) || substr( $path, -4 ) !== '.php' ) {
			$path = '';
		} else {

			$path = explode( '/', $path );
			$path = explode( '.', array_pop( $path ) );

			array_pop( $path );

			$path = implode( '.', $path );

		}

		return $path;

	}

	/**
	 * This function is for migrating settings of older versions of plugins to the
	 * current version
	 *
	 * @return void
	 */
	protected function _maybe_migrate_older_settings() : void {
		Migrate::get_instance()->settings( $this );
	}

	/**
	 * This function stores geshi language file directory paths in an array. Besides
	 * the directory in the plugin's directory, it looks for geshi directory in parent
	 * and child theme directories, and whichever exists is stored.
	 */
	protected function _setup_dir_paths() : void {

		//setup geshi dir path
		$this->__dirs['geshi'] = sprintf(
			'%s/geshi',
			untrailingslashit( IG_SYNTAX_HILITER_ROOT )
		);

		$theme_path = sprintf(
			'%s/geshi',
			untrailingslashit( get_template_directory() )
		);

		$child_theme_path =sprintf(
			'%s/geshi',
			untrailingslashit( get_stylesheet_directory() )
		);

		//setup geshi dir path in current theme folder
		if ( is_dir( $theme_path ) ) {
			$this->__dirs['theme'] = $theme_path;
		}

		//setup geshi dir path in child theme folder
		if ( $theme_path !== $child_theme_path && is_dir( $child_theme_path ) ) {
			$this->__dirs['child_theme'] = $child_theme_path;
		}

		unset( $child_theme_path, $theme_path );

	}

}    //end of class

//EOF
