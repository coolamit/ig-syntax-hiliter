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
 * Runs on every page load and migrates the plugin's own option rows and nothing
 * else, never post content. Versions are compared with `version_compare()` after
 * normalising to three parts, never numerically.
 */
class Migrate {

	use Singleton;

	/**
	 * Name of the option the plugin used up to v3.5.
	 *
	 * @var string
	 */
	public const string V35_OPTION_NAME = 'igsh_options';

	/**
	 * Version at which the plugin moved to the option array it still uses; anything below
	 * is migrated from the v3.5 option.
	 *
	 * @var string
	 */
	protected const string _V4_VERSION = '4.0.0';

	/**
	 * Plugin options.
	 *
	 * @var \iG\Syntax_Hiliter\Option
	 */
	protected Option $_option;

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

		$this->_option = Option::get_instance();

	}

	/**
	 * Migrates the plugin's settings when the installed version has changed.
	 *
	 * Runs on every page load; the early return keeps it free once the install is up to date.
	 *
	 * @return void
	 */
	public function settings(): void {

		$this->_db_version = $this->_get_last_version();

		if ( ! empty( $this->_db_version ) && version_compare( $this->_db_version, static::_normalize_version( $this->_get_plugin_version() ), '>=' ) ) {

			$this->_maybe_rewrite_stored_version();

			return;    // up to date, nothing to migrate

		}

		if ( empty( $this->_db_version ) ) {

			$this->_initialize_on_fresh_install();

		} else {

			if ( version_compare( $this->_db_version, static::_V4_VERSION, '<' ) ) {
				$this->_settings_from_35();
			} else {
				$this->_settings_from_5x();
			}

			$this->_clean_up();
			$this->_add_migrated_from_version();

		}

		update_option( Base::PLUGIN_ID . '-version', $this->_get_plugin_version() );

	}

	/**
	 * Method to write the running version back when what is stored is the same
	 * version spelled differently.
	 *
	 * A stored `6.0.0` or `6.0.0-beta1` normalises to the same value as `6.0`, so
	 * `settings()` returns early and never writes the running spelling back. That early
	 * return is right about settings and wrong about caches, because the files on
	 * disk really did change — so the caches are cleared here, on the one condition
	 * that says an upgrade happened. Only a stored version which normalises to
	 * exactly the running one is touched.
	 *
	 * @return void
	 */
	protected function _maybe_rewrite_stored_version(): void {

		$version = $this->_get_plugin_version();

		if ( empty( $version ) || static::_normalize_version( $version ) !== $this->_db_version ) {
			return;    // some other version is stored, it is not this one's to rewrite
		}

		$stored = get_option( Base::PLUGIN_ID . '-version', '' );
		$stored = ( is_scalar( $stored ) ) ? (string) $stored : '';

		if ( $stored === $version ) {
			return;    // already spelled the way this version spells it
		}

		$this->_clean_up();

		update_option( Base::PLUGIN_ID . '-version', $version );

	}

	/**
	 * Method to normalise a version to three numeric parts.
	 *
	 * Versions up to 5.1 were stored as floats and the plugin spells its own with
	 * two parts; `version_compare()` reads `5.1` as older than `5.1.0`.
	 *
	 * @param mixed $version Version as it was stored, or as the plugin declares it.
	 *
	 * @return string Three part version, or an empty string when there is no usable version.
	 */
	protected static function _normalize_version( mixed $version ): string {

		$version = ( is_scalar( $version ) ) ? trim( (string) $version ) : '';

		if ( empty( $version ) ) {
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

		// there has never been a version zero, so that is junk rather than a version
		return ( '0.0.0' === $version ) ? '' : $version;

	}

	/**
	 * Method to get the version of the plugin which is running.
	 *
	 * @return string
	 */
	protected function _get_plugin_version(): string {
		return Helper::get_version();
	}

	/**
	 * This function returns the last version of plugin that was installed.
	 *
	 * @return string Normalised last version of plugin installed. Empty string if its a
	 *                fresh install.
	 */
	protected function _get_last_version(): string {

		$db_version = static::_normalize_version( get_option( Base::PLUGIN_ID . '-version', '' ) );

		if ( empty( $db_version ) && $this->_is_updating_from_35() ) {
			$db_version = '3.5.0';
		}

		return $db_version;

	}

	/**
	 * Adds a option with last plugin version which is displayed on plugin option page
	 * when its next loaded. This option gets deleted after first display.
	 *
	 * @return void
	 */
	protected function _add_migrated_from_version(): void {

		update_option( Base::PLUGIN_ID . '-migrated-from', $this->_db_version );

	}

	/**
	 * This function checks whether the plugin's last version in use was v3.5.x
	 * or not.
	 *
	 * @return bool Returns TRUE if plugin's last version in use was v3.5.x else FALSE
	 */
	protected function _is_updating_from_35(): bool {
		return is_array( get_option( static::V35_OPTION_NAME, false ) );
	}

	/**
	 * Migrate settings from version 3.5 or older
	 *
	 * 3.5 stored three booleans under its own option; "show plain text" became the
	 * copy button in v6. Each value is read through `to_yesno()` rather than cast, so a
	 * stored `0` still means off.
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

			$this->_option->save(
				$new_name,
				Validate::get_instance()->to_yesno( $old_options[ $old_name ], $this->_option->get_default( $new_name ) )
			);

		}

		delete_option( static::V35_OPTION_NAME );

	}

	/**
	 * Migrate settings from version 4.0 up to 5.1
	 *
	 * Settings keeping their names survive Option's merge over the defaults; only the
	 * two whose name or meaning changed are mapped here, and the GeSHi settings are dropped.
	 *
	 * @return void
	 */
	protected function _settings_from_5x(): void {

		$old_options = get_option( Base::PLUGIN_ID . '-options', [] );

		if ( ! is_array( $old_options ) ) {
			$old_options = [];
		}

		$validate = Validate::get_instance();

		if ( isset( $old_options['fe-styles'] ) ) {

			$this->_option->save(
				'theme',
				( 'no' === $validate->to_yesno( $old_options['fe-styles'], 'yes' ) ) ? Themes::THEME_NONE : Themes::DEFAULT_THEME
			);

		}

		if ( isset( $old_options['plain_text'] ) ) {
			$this->_option->save( 'copy_code', $validate->to_yesno( $old_options['plain_text'], 'yes' ) );
		}

		// Option holds only the v6 settings, so committing drops the removed ones from the DB.
		$this->_option->commit();

	}

	/**
	 * Method to remove what the old version left behind.
	 *
	 * The language timestamp belonged to the GeSHi file scan. Every cache this plugin
	 * writes shares one prefix, so clearing by prefix also clears an earlier build's
	 * registry cache.
	 *
	 * @return void
	 */
	protected function _clean_up(): void {

		global $wpdb;

		delete_option( Base::PLUGIN_ID . '-lang-time' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One off lookup of option names by prefix, which no WordPress API offers.
		$cache_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( Cache::KEY_PREFIX ) . '%'
			)
		);

		foreach ( (array) $cache_keys as $cache_key ) {
			delete_option( $cache_key );    // one at a time so the options cache stays honest
		}

	}

	/**
	 * Initialize settings on fresh install
	 *
	 * @return void
	 */
	protected function _initialize_on_fresh_install(): void {

		$this->_option->commit();

	}

} // end of class

// EOF
