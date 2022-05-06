<?php
/**
 * Class for migrating old plugin values to new
 *
 * @author Amit Gupta <https://amitgupta.in/>
 *
 * @since 2015-07-20
 */

namespace iG\Syntax_Hiliter;

use \iG\Syntax_Hiliter\Traits\Singleton;

class Migrate {

	use Singleton;

	const V35_OPTION_NAME = 'igsh_options';

	/**
	 * @var \iG\Syntax_Hiliter\Option
	 */
	protected $_option;

	/**
	 * @var float Current plugin version in DB
	 */
	protected $_db_version;

	/**
	 * Class constructor
	 */
	protected function __construct() {
		//init options
		$this->_option = Option::get_instance();
	}

	public function settings( Base $obj ) : void {

		$this->_db_version = $this->_get_last_version();

		if ( round( floatval( IG_SYNTAX_HILITER_VERSION ), 1 ) === $this->_db_version ) {
			return;    //current version, nothing to migrate, bail out
		}

		switch ( $this->_db_version ) {

			case 3.5:
				$this->_settings_from_35();
				break;

			case 4.2:
			case 4.3:
				$this->_initialize_on_fresh_install( $obj );
				$this->_add_migrated_from_version();
				break;

			default:
				$this->_initialize_on_fresh_install( $obj );
				break;

		}

		update_option( Base::PLUGIN_ID . '-version', IG_SYNTAX_HILITER_VERSION );

	}

	/**
	 * This function returns the last version of plugin that was installed.
	 *
	 * @return float Last version of plugin installed. Returns 0 if its a fresh install.
	 */
	protected function _get_last_version() : float {

		$db_version = floatval( get_option( Base::PLUGIN_ID . '-version', 0 ) );

		if ( empty( $db_version ) && $this->_is_updating_from_35() ) {
			$db_version = 3.5;
		}

		return round( floatval( $db_version ), 1 );

	}

	/**
	 * Adds a option with last plugin version which is displayed on plugin option page
	 * when its next loaded. This option gets deleted after first display.
	 *
	 * @return void
	 */
	protected function _add_migrated_from_version() : void {
		update_option( Base::PLUGIN_ID . '-migrated-from', $this->_db_version );
	}

	/**
	 * This function checks whether the plugin's last version in use was v3.5.x
	 * or not.
	 *
	 * @return bool Returns TRUE if plugin's last version in use was v3.5.x else FALSE
	 */
	protected function _is_updating_from_35() : bool {
		$old_options = get_option( self::V35_OPTION_NAME, false );

		if ( false === $old_options || ! is_array( $old_options ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Migrate settings from version 3.5 or older
	 *
	 * @return void
	 */
	protected function _settings_from_35() : void {

		$old_options = get_option( self::V35_OPTION_NAME, [] );

		if ( isset( $old_options['PLAIN_TEXT'] ) ) {
			$this->_option->save( 'plain_text', Helper::bool_to_yesno( $old_options['PLAIN_TEXT'] ) );
		}

		if ( isset( $old_options['PARSE_COMMENTS'] ) ) {
			$this->_option->save( 'hilite_comments', Helper::bool_to_yesno( $old_options['PARSE_COMMENTS'] ) );
		}

		if ( isset( $old_options['LINE_NUMBERS'] ) ) {
			$this->_option->save( 'show_line_numbers', Helper::bool_to_yesno( $old_options['LINE_NUMBERS'] ) );
		}

		delete_option( self::V35_OPTION_NAME );    //delete old options from DB

		$this->_add_migrated_from_version();

		unset( $old_options );

	}

	/**
	 * Initialize settings on fresh install
	 *
	 * @return void
	 */
	protected function _initialize_on_fresh_install( Base $obj ) : void {

		$obj->get_languages( 'yes' );    //rebuild language file list

		$this->_option->commit();

	}

}    //end of class

//EOF
