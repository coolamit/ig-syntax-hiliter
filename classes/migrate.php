<?php
/**
 * Class for migrating old plugin values to new.
 *
 * @package iG_Syntax_Hiliter
 *
 * @author Amit Gupta <https://amitgupta.in/>
 *
 * @since 2015-07-20
 */

namespace iG\Syntax_Hiliter;

use iG\Syntax_Hiliter\Traits\Singleton;

/**
 * Brings the plugin's stored settings up to date with the installed version.
 *
 * Versions are compared with `version_compare()` and never numerically. Every
 * version this plugin has ever stored is normalised to a three part semantic
 * version first, so that the float `5.1` an old install holds and the string
 * `6.0.0` this one writes are comparable.
 */
class Migrate {

	use Singleton;

	/**
	 * Name of the option the plugin used up to v3.5.
	 *
	 * @var string
	 */
	const V35_OPTION_NAME = 'igsh_options';

	/**
	 * Version at which the plugin moved to the option array it still uses.
	 *
	 * Anything below this is migrated from the v3.5 option instead.
	 *
	 * @var string
	 */
	const V4_VERSION = '4.0.0';

	/**
	 * Plugin options.
	 *
	 * @var \iG\Syntax_Hiliter\Option
	 */
	protected $_option;

	/**
	 * Normalised plugin version found in the DB, empty on a fresh install.
	 *
	 * @var string
	 */
	protected string $_db_version = '';

	/**
	 * Class constructor.
	 */
	protected function __construct() {

		//init options
		$this->_option = Option::get_instance();

	}    //end __construct()

	/**
	 * Migrates the plugin's settings when the installed version has changed.
	 *
	 * This runs on every page load, so the short circuit below is what keeps it
	 * from costing anything once the install is up to date.
	 *
	 * @return void
	 */
	public function settings(): void {

		$this->_db_version = $this->_get_last_version();

		if ( '' !== $this->_db_version && version_compare( $this->_db_version, static::_normalize_version( $this->_get_plugin_version() ), '>=' ) ) {
			return;    //up to date, nothing to migrate
		}

		if ( '' === $this->_db_version ) {

			$this->_initialize_on_fresh_install();

		} else {

			if ( version_compare( $this->_db_version, static::V4_VERSION, '<' ) ) {
				$this->_settings_from_35();
			} else {
				$this->_settings_from_5x();
			}

			$this->_clean_up();
			$this->_add_migrated_from_version();

		}

		update_option( Base::PLUGIN_ID . '-version', $this->_get_plugin_version() );

	}    //end settings()

	/**
	 * Method to normalise a version to three numeric parts.
	 *
	 * Versions up to 5.1 were stored as floats, so `version_compare()` would read
	 * `5.1` as older than `5.1.0` and a two part `6.0` as older than `6.0.0`.
	 * Padding both sides of every comparison to three parts removes that trap.
	 *
	 * @param mixed $version Version as it was stored, or as the plugin declares it.
	 *
	 * @return string Three part version, or an empty string when there is no usable version.
	 */
	protected static function _normalize_version( $version ): string {

		$version = ( is_scalar( $version ) ) ? trim( (string) $version ) : '';

		if ( '' === $version ) {
			return '';
		}

		if ( ! preg_match( '/^\d+(\.\d+)*$/', $version ) ) {
			$version = (string) floatval( $version );
		}

		$parts = array_slice(
			array_pad( explode( '.', $version ), 3, '0' ),
			0,
			3
		);

		$version = implode( '.', array_map( 'intval', $parts ) );

		//there has never been a version zero, so that is junk rather than a version
		return ( '0.0.0' === $version ) ? '' : $version;

	}    //end _normalize_version()

	/**
	 * Method to get the version of the plugin which is running.
	 *
	 * @return string
	 */
	protected function _get_plugin_version(): string {
		return ( defined( 'IG_SYNTAX_HILITER_VERSION' ) ) ? (string) IG_SYNTAX_HILITER_VERSION : '';
	}    //end _get_plugin_version()

	/**
	 * This function returns the last version of plugin that was installed.
	 *
	 * @return string Normalised last version of plugin installed. Empty string if its a fresh install.
	 */
	protected function _get_last_version(): string {

		$db_version = static::_normalize_version( get_option( Base::PLUGIN_ID . '-version', '' ) );

		if ( '' === $db_version && $this->_is_updating_from_35() ) {
			$db_version = '3.5.0';
		}

		return $db_version;

	}    //end _get_last_version()

	/**
	 * Adds a option with last plugin version which is displayed on plugin option page
	 * when its next loaded. This option gets deleted after first display.
	 *
	 * @return void
	 */
	protected function _add_migrated_from_version(): void {

		update_option( Base::PLUGIN_ID . '-migrated-from', $this->_db_version );

	}    //end _add_migrated_from_version()

	/**
	 * This function checks whether the plugin's last version in use was v3.5.x
	 * or not.
	 *
	 * @return bool Returns TRUE if plugin's last version in use was v3.5.x else FALSE
	 */
	protected function _is_updating_from_35(): bool {

		$old_options = get_option( static::V35_OPTION_NAME, false );

		if ( false === $old_options || ! is_array( $old_options ) ) {
			return false;
		}

		return true;

	}    //end _is_updating_from_35()

	/**
	 * Migrate settings from version 3.5 or older
	 *
	 * Version 3.5 stored three booleans under its own option name. Two of them still
	 * exist under the same names; the third, "show plain text", became the copy
	 * to clipboard button in v6.
	 *
	 * @return void
	 */
	protected function _settings_from_35(): void {

		$old_options = get_option( static::V35_OPTION_NAME, [] );

		if ( ! is_array( $old_options ) ) {
			$old_options = [];
		}

		$option_map = [
			'PLAIN_TEXT'     => 'copy_code',
			'PARSE_COMMENTS' => 'hilite_comments',
			'LINE_NUMBERS'   => 'show_line_numbers',
		];

		foreach ( $option_map as $old_name => $new_name ) {

			if ( ! isset( $old_options[ $old_name ] ) ) {
				continue;
			}

			$this->_option->save( $new_name, Helper::bool_to_yesno( $old_options[ $old_name ] ) );

		}

		delete_option( static::V35_OPTION_NAME );    //delete old options from DB

		unset( $old_options );

	}    //end _settings_from_35()

	/**
	 * Migrate settings from version 4.0 up to 5.1
	 *
	 * `toolbar`, `show_line_numbers`, `hilite_comments` and `gist_in_comments`
	 * keep their names and their values, which Option already does on its own
	 * when it merges the stored array over the defaults. Only the two settings
	 * whose name or meaning changed are mapped here; `strict_mode`,
	 * `non_strict_mode` and `link_to_manual` were GeSHi features and are dropped.
	 *
	 * @return void
	 */
	protected function _settings_from_5x(): void {

		$old_options = get_option( Base::PLUGIN_ID . '-options', [] );

		if ( ! is_array( $old_options ) ) {
			$old_options = [];
		}

		if ( isset( $old_options['fe-styles'] ) ) {

			$this->_option->save(
				'theme',
				( 'no' === $this->_to_yesno( $old_options['fe-styles'], 'yes' ) ) ? Asset_Manager::THEME_NONE : Asset_Manager::DEFAULT_THEME
			);

		}

		if ( isset( $old_options['plain_text'] ) ) {
			$this->_option->save( 'copy_code', $this->_to_yesno( $old_options['plain_text'], 'yes' ) );
		}

		/*
		 * Option holds only the settings v6 has, so committing is what drops the
		 * removed ones from the DB.
		 */
		$this->_option->commit();

		unset( $old_options );

	}    //end _settings_from_5x()

	/**
	 * Method to read a stored value as a yes/no setting.
	 *
	 * @param mixed  $value    Value as it was stored.
	 * @param string $fallback Value to use when the stored one is neither yes nor no.
	 *
	 * @return string
	 */
	protected function _to_yesno( $value, string $fallback ): string {

		$value = ( is_scalar( $value ) ) ? strtolower( trim( (string) $value ) ) : '';

		return ( Validate::get_instance()->is_yesno( $value ) ) ? $value : $fallback;

	}    //end _to_yesno()

	/**
	 * Method to remove what the old version left behind.
	 *
	 * The language list cache and its timestamp both belonged to the GeSHi file
	 * scan, which no longer exists. Every cache this plugin has ever written is
	 * keyed by the same prefix, so clearing the lot also clears a registry cache
	 * left by an earlier 6.x build.
	 *
	 * @return void
	 */
	protected function _clean_up(): void {

		global $wpdb;

		delete_option( Base::PLUGIN_ID . '-lang-time' );

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One off lookup of option names by prefix, which no WordPress API offers.
		$cache_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( Cache::KEY_PREFIX ) . '%'
			)
		);

		foreach ( (array) $cache_keys as $cache_key ) {
			delete_option( $cache_key );    //deleted one at a time so that the options cache stays honest
		}

	}    //end _clean_up()

	/**
	 * Initialize settings on fresh install
	 *
	 * @return void
	 */
	protected function _initialize_on_fresh_install(): void {

		$this->_option->commit();

	}    //end _initialize_on_fresh_install()

}    //end of class

//EOF
