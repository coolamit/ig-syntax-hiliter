<?php

/**
 * iG Syntax Hiliter base class
 * This is an abstract class which contains some common functionality and is to be
 * inherited, on its own it doesn't do anything
 */

abstract class iG_Syntax_Hiliter {

	/**
	 * @var const Class constant containing unique plugin ID
	 */
	const plugin_id = "ig-syntax-hiliter";
	/**
	 * @var const Class constant containing plugin name for display
	 */
	const plugin_name = "iG:Syntax Hiliter";
	/**
	 * @var const Class constant containing life of language name cache in seconds
	 */
	const languages_cache_life = 259200;		//3 days
	/**
	 * @var String Static var containing path to GeSHi language file folder
	 */
	protected static $__dirs;

	/**
	 * @var Array An array which contains plugin options
	 */
	private $_options;

	/**
	 * This function returns time difference in human readable format
	 * like 2 days 13 hours 28 minutes
	 */
	public function human_time_diff( $from, $to = '' ) {
		if( empty( $to ) ) {
			$to = time();
		}

		$minute_in_seconds = 60;
		$hour_in_seconds = 60 * $minute_in_seconds;
		$day_in_seconds = 24 * $hour_in_seconds;

		$diff = (int) abs( $to - $from );

		$since = array();

		if( $diff >= $day_in_seconds ) {
			$days = intval( $diff / $day_in_seconds );
			$since[] = sprintf( _n( '%s day', '%s days', $days ), $days );
			$diff = $diff - ( $days * $day_in_seconds );
		}

		if( $diff >= $hour_in_seconds ) {
			$hours = intval( $diff / $hour_in_seconds );
			$since[] = sprintf( _n( '%s hour', '%s hours', $hours ), $hours );
			$diff = $diff - ( $hours * $hour_in_seconds );
		}

		if( $diff >= $minute_in_seconds ) {
			$minutes = intval( $diff / $minute_in_seconds );
			$since[] = sprintf( _n( '%s minute', '%s minutes', $minutes ), $minutes );
		}

		return implode( ' ', $since );
	}

	/**
	 * This function scans the validated language file directories, retrieves the
	 * names of all files with .php extension and returns an array with unique
	 * file names
	 */
	public function build_language_list() {
		if( empty( self::$__dirs ) ) {
			return false;
		}

		$tags = array();

		foreach( self::$__dirs as $dir ) {
			$tags = array_merge( $tags, glob( $dir . "/*.php" ) );
		}

		$tags = array_unique( array_filter( array_map( array( $this, '_file_to_tag_name' ), $tags ) ) );
		sort( $tags );

		$this->_set_language_build_time();	//set language list built time

		return $tags;
	}

	/**
	 * This function uses WP TLC class to fetch the language file names from supported directories
	 * and schedules a refresh of the transient cache. It also allows a force rebuild of
	 * transient cache via the optional $force_rebuild parameter.
	 */
	protected function _get_languages( $force_rebuild = 'no' ) {
		$force_rebuild = ( ! $this->_is_yesno( $force_rebuild ) ) ? 'no' : strtolower( $force_rebuild );

		$transient = new TLC_Transient( self::plugin_id . '-languages' );

		if( $force_rebuild == 'yes' ) {
			$transient->delete();	//delete existing cache so we'll get fresh one below
		}

		$languages = $transient->updates_with( array( $this, 'build_language_list' ) )
								->expires_in( self::languages_cache_life )
								->get();

		if( empty( $languages ) ) {
			return;
		}

		return array_combine( $languages, $languages );
	}

	/**
	 * Returns the timestamp when language file name cache was last built. If no timestamp
	 * found then it sets current timestamp and returns that.
	 */
	protected function _get_language_build_time() {
		$time = get_option( self::plugin_id . '-lang-time' );
		if( $time === false ) {
			return $this->_set_language_build_time();
		}

		return $time;
	}

	/**
	 * Sets the timestamp to mark when language file name cache was built
	 */
	protected function _set_language_build_time() {
		$time = time();
		update_option( self::plugin_id . '-lang-time', $time );
		return $time;
	}

	/**
	 * Returns the file name of a php file from the physical file path
	 */
	protected function _file_to_tag_name( $file_path ) {
		$file_path = str_replace( '\\', '/', $file_path );
		$file_path = ( empty($file_path) || strpos( $file_path, '.php' ) === false ) ? '' : ltrim( rtrim( substr( $file_path, strrpos( $file_path, '/' ) ), '.php' ), '/' );
		return $file_path;
	}

	/**
	 * This function accepts a boolean value and converts it into "yes" if value
	 * is TRUE else "no"
	 */
	protected function _bool_to_yesno( $bool_var ) {
		if( ! is_bool($bool_var) ) {
			return $bool_var;
		}
		$bool_var = ( $bool_var === true ) ? 'yes' : 'no';
		return $bool_var;
	}

	/**
	 * This function checks whether the passed value is YES/NO or not. If it is then
	 * it returns TRUE else FALSE. The parameter accepts only string.
	 */
	protected function _is_yesno( $value ) {
		if( ! is_string($value) ) {
			return false;
		}

		$value = strtolower( trim( $value ) );

		if( $value === "yes" || $value === "no" ) {
			return true;
		}

		return false;
	}

	/**
	 * This function is for migrating settings of older versions of plugins to the
	 * current version
	 */
	protected function _migrate_older_settings() {
		$db_version = floatval( get_option( self::plugin_id . '-version', 3.5 ) );
		if( $db_version == floatval(IG_SYNTAX_HILITER_VERSION) ) {
			return;	//current version, nothing to migrate, bail out
		}

		//migrate settings from v3.5 or older
		if( $db_version == 3.5 ) {
			$old_option_name = "igsh_options";
			$old_options = get_option( $old_option_name, array() );
			$this->_options['plain_text'] = ( isset( $old_options['PLAIN_TEXT'] ) ) ? $this->_bool_to_yesno( $old_options['PLAIN_TEXT'] ) : $this->_options['plain_text'];
			$this->_options['hilite_comments'] = ( isset( $old_options['PARSE_COMMENTS'] ) ) ? $this->_bool_to_yesno( $old_options['PARSE_COMMENTS'] ) : $this->_options['hilite_comments'];
			$this->_options['show_line_numbers'] = ( isset( $old_options['LINE_NUMBERS'] ) ) ? $this->_bool_to_yesno( $old_options['LINE_NUMBERS'] ) : $this->_options['show_line_numbers'];
			delete_option( $old_option_name );	//delete old options from DB
			update_option( self::plugin_id . '-migrated-from', $db_version );
			unset( $old_option_name, $old_options );
		}

		unset( $db_version );

		update_option( self::plugin_id . '-version', IG_SYNTAX_HILITER_VERSION );
	}

	/**
	 * This function accepts two arrays, $new & $default. The common items
	 * keep value from $new, any extra items in $new are discarded
	 * & extra items in $default are kept as is.
	 */
	protected function _array_merge( $default = array(), $new = array() ) {
		if( empty($default) || ! is_array($default) ) {
			return false;
		}

		if( empty($new) || ! is_array($new) ) {
			return $default;
		}

		foreach( $default as $key => $value ) {
			if( ! array_key_exists( $key, $new ) ) {
				//this key doesn't exist in $new array, so skip to next
				continue;
			}
			$default[$key] = $new[$key];
		}

		return $default;
	}

	/**
	 * This function stores geshi language file directory paths in an array. Besides
	 * the directory in the plugin's directory, it looks for geshi directory in parent
	 * and child theme directories, and whichever exists is stored.
	 */
	protected function _setup_dir_paths() {
		self::$__dirs['geshi'] = dirname( __FILE__ ) . "/geshi";	//setup geshi dir path
		self::$__dirs['theme'] = get_template_directory() . "/geshi";	//setup geshi dir path in current theme folder
		self::$__dirs['child_theme'] = get_stylesheet_directory() . "/geshi";	//setup geshi dir path in child theme folder

		if( ! is_dir( self::$__dirs['child_theme'] ) || self::$__dirs['theme'] == self::$__dirs['child_theme'] ) {
			unset( self::$__dirs['child_theme'] );	//no geshi dir in child theme
		}

		if( ! is_dir( self::$__dirs['theme'] ) ) {
			unset( self::$__dirs['theme'] );	//no geshi dir in current theme
		}
	}

	/**
	 * This function is to be run in the constructor of the sub-class. It initializes
	 * the options storing them in the $_options class var. If the options are not in
	 * DB then it creates them using default ones and it also calls the migration
	 * function to migrate settings from older versions of plugin.
	 */
	protected function _init_options() {
		$this->_setup_dir_paths();	//setup valid language dir paths

		$default_options = array(
			'toolbar' => 'yes',					//show toolbar above hilited code by default
			'plain_text' => 'yes',				//show option to view code in plain text by default
			'show_line_numbers' => 'yes',		//show line numbers in code by default
			'hilite_comments' => 'yes',			//hilite code posted in comments by default
			'link_to_manual' => 'no',			//don't link keywords to manual by default
			'gist_in_comments' => 'no',			//don't embed Github Gist in comments by default
		);
		//fetch options array from wp_options & then do a safe merge with default options
		$db_options = get_option( self::plugin_id . '-options', false );
		if( empty($db_options) || ! is_array($db_options) ) {
			$db_options = array();
		}

		$this->_options = $db_options;
		if( count($db_options) !== count($default_options) ) {
			$this->_options = $this->_array_merge( $default_options, $db_options );
		}

		if( empty($this->_options) || ! is_array($this->_options) ) {
			$this->_options = $default_options;
		}

		//run the migration function to migrate any settings if this is first run after plugin upgrade
		$this->_migrate_older_settings();
	}

	/**
	 * This function is just a wrapper to fetch an option from $_options class var,
	 * since direct access to the $_options var is not allowed
	 */
	protected function _get_option( $option_name ) {
		if( empty($option_name) || ! is_string($option_name) ) {
			return false;
		}

		if( isset( $this->_options[$option_name] ) ) {
			return $this->_options[$option_name];
		}

		return false;
	}

	/**
	 * This function is for setting a value in the $_options class var as direct
	 * access to it isn't allowed. It takes care of sanitizing the value before
	 * putting it in $_options & saves only if the option name exists already.
	 */
	protected function _set_option( $option_name, $option_value ) {
		if( empty($option_name) || ! is_string($option_name) || ! isset( $this->_options[$option_name] ) ) {
			return false;
		}

		$option_value = sanitize_title( $this->_bool_to_yesno( strtolower( trim($option_value) ) ) );
		if( ! empty($option_value) ) {
			$this->_options[$option_name] = $option_value;
			$this->_commit();	//lets save in DB as well
			return true;
		}

		return false;
	}

	/**
	 * This function is for saving the $_options class var in the DB, can be called anytime
	 * or in the class destructor
	 */
	private function _commit() {
		if( empty($this->_options) ) {
			return false;
		}

		$options = $this->_options;
		update_option( self::plugin_id . '-options', $options );
		unset( $options );

		return true;
	}


//end of class
}


//EOF
