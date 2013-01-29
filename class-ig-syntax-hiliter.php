<?php

/**
 * iG Syntax Hiliter Class base class
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
	 * @var String Static var containing path to GeSHi language file folder
	 */
	protected static $__geshi_dir;

	/**
	 * @var Array An array which contains plugin options
	 */
	private $_options;

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
	 * This function is to be run in the constructor of the sub-class. It initializes
	 * the options storing them in the $_options class var. If the options are not in
	 * DB then it creates them using default ones and it also calls the migration
	 * function to migrate settings from older versions of plugin.
	 */
	protected function _init_options() {
		//setup geshi dir path
		self::$__geshi_dir = __DIR__ . "/geshi";

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
