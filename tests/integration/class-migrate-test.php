<?php
/**
 * An existing install's settings survive the upgrade to v6.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Admin;
use iG\Syntax_Hiliter\Base;
use iG\Syntax_Hiliter\Cache;
use iG\Syntax_Hiliter\Migrate;
use iG\Syntax_Hiliter\Option;
use iG\Syntax_Hiliter\Tests\Integration\Fixtures\Default_Settings;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use iG\Syntax_Hiliter\Themes;
use WP_UnitTestCase;

/**
 * The v5 settings map to the v6 set, the v3.5 path still works, a fresh install
 * lands on the defaults, and none of it happens twice.
 */
class Migrate_Test extends WP_UnitTestCase {

	use Pipeline_Test_Helpers;

	/**
	 * The option array a v5.1 install holds.
	 *
	 * Every value is the opposite of its v6 default, so a setting which fails to
	 * carry across cannot pass by coincidence.
	 *
	 * @var array
	 */
	protected const array _V5_OPTIONS = [
		'fe-styles'         => 'no',
		'strict_mode'       => 'always',
		'non_strict_mode'   => [ 'php' ],
		'toolbar'           => 'no',
		'plain_text'        => 'no',
		'show_line_numbers' => 'no',
		'hilite_comments'   => 'no',
		'link_to_manual'    => 'yes',
		'gist_in_comments'  => 'yes',
	];

	/**
	 * The options object as the plugin booted it.
	 *
	 * @var \iG\Syntax_Hiliter\Option|null
	 */
	protected ?Option $_original_option = null;

	/**
	 * The migration object as the plugin booted it.
	 *
	 * @var \iG\Syntax_Hiliter\Migrate|null
	 */
	protected ?Migrate $_original_migrate = null;

	/**
	 * Remembers the singletons to put back.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		$this->_original_option  = Option::get_instance();
		$this->_original_migrate = Migrate::get_instance();

		delete_option( Base::PLUGIN_ID . '-version' );
		delete_option( Base::PLUGIN_ID . '-options' );
		delete_option( Base::PLUGIN_ID . '-migrated-from' );
		delete_option( Migrate::V35_OPTION_NAME );

	}

	/**
	 * Puts the singletons back, so a migrated install cannot leak into the next test.
	 *
	 * @return void
	 */
	public function tear_down(): void {

		$this->_set_singleton( Option::class, $this->_original_option );
		$this->_set_singleton( Migrate::class, $this->_original_migrate );

		parent::tear_down();

	}

	/**
	 * A v5.1 install lands on the v6 option set with the mapped values.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_maps_a_v51_installs_settings_to_the_v6_option_set(): void {

		update_option( Base::PLUGIN_ID . '-version', 5.1 );
		update_option( Base::PLUGIN_ID . '-options', static::_V5_OPTIONS );

		$this->_migrate();

		$this->assertSame(
			[
				'theme'             => 'none',    // fe-styles=no
				'font'              => 'none',    // new, and off: a font is fetched from another host
				'toolbar'           => 'no',      // carried
				'copy_code'         => 'no',      // plain_text=no
				'show_line_numbers' => 'no',      // carried
				'match_braces'      => 'yes',     // new, defaults on
				'rainbow_braces'    => 'no',      // new, defaults off
				'hilite_comments'   => 'no',      // carried
				'gist_in_comments'  => 'yes',     // carried
				'gist_limit_height' => 'yes',     // new, defaults on
			],
			get_option( Base::PLUGIN_ID . '-options' )
		);

		$this->assertSame( IG_SYNTAX_HILITER_VERSION, get_option( Base::PLUGIN_ID . '-version' ) );
		$this->assertSame( '5.1.0', get_option( Base::PLUGIN_ID . '-migrated-from' ) );

	}

	/**
	 * The style setting is the only one whose mapping branches, so both of its
	 * outcomes are checked.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_maps_the_v5_style_setting_to_the_default_theme_when_it_was_on(): void {

		update_option( Base::PLUGIN_ID . '-version', 5.1 );
		update_option(
			Base::PLUGIN_ID . '-options',
			array_merge(
				static::_V5_OPTIONS,
				[
					'fe-styles'  => 'yes',
					'plain_text' => 'yes',
				]
			)
		);

		$this->_migrate();

		$options = get_option( Base::PLUGIN_ID . '-options' );

		$this->assertSame( Themes::DEFAULT_THEME, $options['theme'] );
		$this->assertSame( 'yes', $options['copy_code'] );

	}

	/**
	 * The migration is triggered on every page load, so running twice would mean
	 * rewriting the site's settings on every request.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_does_not_migrate_again_on_a_subsequent_load(): void {

		update_option( Base::PLUGIN_ID . '-version', 5.1 );
		update_option( Base::PLUGIN_ID . '-options', static::_V5_OPTIONS );

		$this->_migrate();

		// A v5 shaped array again: a second migration would rewrite it.
		update_option( Base::PLUGIN_ID . '-options', [ 'fe-styles' => 'no' ] );

		$this->_migrate();

		$this->assertSame(
			[ 'fe-styles' => 'no' ],
			get_option( Base::PLUGIN_ID . '-options' ),
			'The migration ran a second time on an up to date install.'
		);

	}

	/**
	 * A v3.5 install is recognised by its own option name, which is read, mapped
	 * and then deleted. Its "plain text" setting became the copy button in v6.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_maps_a_v35_installs_legacy_options_and_deletes_them(): void {

		update_option(
			Migrate::V35_OPTION_NAME,
			[
				'PLAIN_TEXT'     => false,
				'PARSE_COMMENTS' => false,
				'LINE_NUMBERS'   => false,
			]
		);

		$this->_migrate();

		$options = get_option( Base::PLUGIN_ID . '-options' );

		$this->assertSame( 'no', $options['copy_code'] );
		$this->assertSame( 'no', $options['hilite_comments'] );
		$this->assertSame( 'no', $options['show_line_numbers'] );

		$this->assertFalse( get_option( Migrate::V35_OPTION_NAME, false ) );
		$this->assertSame( '3.5.0', get_option( Base::PLUGIN_ID . '-migrated-from' ) );
		$this->assertSame( IG_SYNTAX_HILITER_VERSION, get_option( Base::PLUGIN_ID . '-version' ) );

	}

	/**
	 * That version wrote booleans, but an install may hold the integers or the strings
	 * some other hand put there. `0` means the setting off, and off must never turn
	 * into the v6 default of on.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_keeps_the_settings_of_a_v35_install_which_stored_its_flags_as_integers(): void {

		update_option(
			Migrate::V35_OPTION_NAME,
			[
				'PLAIN_TEXT'     => 0,
				'PARSE_COMMENTS' => 1,
				'LINE_NUMBERS'   => '0',
			]
		);

		$this->_migrate();

		$options = get_option( Base::PLUGIN_ID . '-options' );

		$this->assertSame( 'no', $options['copy_code'], 'A setting the owner had switched off came out switched on.' );
		$this->assertSame( 'yes', $options['hilite_comments'] );
		$this->assertSame( 'no', $options['show_line_numbers'], 'A setting the owner had switched off came out switched on.' );

	}

	/**
	 * A v3.5 value which cannot be read as a flag at all lands on the v6 default,
	 * because everything downstream compares a setting against `yes` and anything
	 * else is silently off.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_lands_a_v35_value_which_is_not_a_flag_on_the_v6_default(): void {

		update_option(
			Migrate::V35_OPTION_NAME,
			[
				'PLAIN_TEXT'     => 'perhaps',
				'PARSE_COMMENTS' => [ 'yes' ],
			]
		);

		$this->_migrate();

		$options = get_option( Base::PLUGIN_ID . '-options' );

		$this->assertSame( 'yes', $options['copy_code'] );
		$this->assertSame( 'yes', $options['hilite_comments'] );

	}

	/**
	 * A stored version which is this one spelled some other way normalises to this
	 * one, so the migration does nothing but rewrite the spelling.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_rewrites_a_non_canonical_stored_version_to_the_running_one(): void {

		update_option( Base::PLUGIN_ID . '-version', '6.0' );
		update_option( Base::PLUGIN_ID . '-options', Default_Settings::V6 );

		$this->_migrate();

		$this->assertSame( IG_SYNTAX_HILITER_VERSION, get_option( Base::PLUGIN_ID . '-version' ) );

		// The install was already up to date, so nothing was migrated.
		$this->assertSame( Default_Settings::V6, get_option( Base::PLUGIN_ID . '-options' ) );
		$this->assertFalse( get_option( Base::PLUGIN_ID . '-migrated-from', false ) );

	}

	/**
	 * The spelling rewrite clears the caches: it is the one upgrade which reaches no
	 * other clean up.
	 *
	 * Every spelling of this version normalises to `6.0.0`, so `settings()` takes its
	 * early return between them and `_clean_up()` is never reached down that path; the
	 * theme list is cached for a week and the files on disk are what a plugin update
	 * changes.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_clears_the_caches_when_it_rewrites_the_version(): void {

		$cache_key = Cache::KEY_PREFIX . md5( Themes::CACHE_KEY );

		update_option( Base::PLUGIN_ID . '-version', '6.0' );
		update_option( Base::PLUGIN_ID . '-options', Default_Settings::V6 );
		update_option(
			$cache_key,
			[
				'expiry' => ( time() + HOUR_IN_SECONDS ),
				'data'   => [ 'prism-gone' => 'A theme from the previous build' ],
			],
			false
		);

		$this->_migrate();

		$this->assertSame( IG_SYNTAX_HILITER_VERSION, get_option( Base::PLUGIN_ID . '-version' ) );
		$this->assertFalse( get_option( $cache_key, false ), 'The previous build\'s theme list must not survive the upgrade.' );

	}

	/**
	 * A pre-release suffix is dropped before the three numeric parts are taken, so
	 * `6.0.1-beta-1` is `6.0.1` and not the `6.0.0` that `floatval()` would make of it.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_normalises_a_version_to_its_numeric_parts(): void {

		$normalize = new \ReflectionMethod( Migrate::class, '_normalize_version' );

		// A float key would be truncated to an int, so these are pairs and not a map.
		$cases = [
			[ '6.0.1-beta-1', '6.0.1' ],
			[ '6.10.0-rc1', '6.10.0' ],
			[ '6.0.0-rc-1', '6.0.0' ],
			[ '6.0', '6.0.0' ],
			[ 5.1, '5.1.0' ],
			[ 'junk', '' ],
			[ '', '' ],
		];

		foreach ( $cases as [ $version, $normalised ] ) {
			$this->assertSame( $normalised, $normalize->invoke( null, $version ), sprintf( 'Normalising "%s"', $version ) );
		}

	}

	/**
	 * An up-to-date install keeps its caches on an ordinary page load.
	 *
	 * An install already spelling its version the way this one does is an ordinary
	 * page load, and that runs on every request the site serves, so it must not throw
	 * the caches away.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_keeps_the_caches_of_an_up_to_date_install(): void {

		$cache_key = Cache::KEY_PREFIX . md5( Themes::CACHE_KEY );
		$cached    = [
			'expiry' => ( time() + HOUR_IN_SECONDS ),
			'data'   => [ 'prism-okaidia' => 'Okaidia' ],
		];

		update_option( Base::PLUGIN_ID . '-version', IG_SYNTAX_HILITER_VERSION );
		update_option( Base::PLUGIN_ID . '-options', Default_Settings::V6 );
		update_option( $cache_key, $cached, false );

		$this->_migrate();

		$this->assertSame( $cached, get_option( $cache_key, false ), 'A page load on an install which is up to date must leave the caches alone.' );

	}

	/**
	 * A stored version from the future is left alone, spelling and all.
	 *
	 * This version does not know what a later one means by what it stored, and
	 * rewriting it would downgrade the install's record of itself.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_leaves_a_version_from_the_future_alone(): void {

		foreach ( [ '7.0.0', '7.0.0-rc1', '7.1' ] as $version ) {

			update_option( Base::PLUGIN_ID . '-version', $version );
			update_option( Base::PLUGIN_ID . '-options', [ 'toolbar' => 'no' ] );

			$this->_migrate();

			$this->assertSame( $version, get_option( Base::PLUGIN_ID . '-version' ) );
			$this->assertSame( [ 'toolbar' => 'no' ], get_option( Base::PLUGIN_ID . '-options' ) );

		}

	}

	/**
	 * A fresh install writes the defaults and no upgrade notice.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_lands_a_fresh_install_on_the_v6_defaults(): void {

		$this->_migrate();

		$this->assertSame( Default_Settings::V6, get_option( Base::PLUGIN_ID . '-options' ) );
		$this->assertSame( IG_SYNTAX_HILITER_VERSION, get_option( Base::PLUGIN_ID . '-version' ) );
		$this->assertFalse( get_option( Base::PLUGIN_ID . '-migrated-from', false ) );

	}

	/**
	 * The language list and its timestamp described the GeSHi file scan, which no
	 * longer exists. Left behind, the cache would be served to the v6 registry.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_deletes_the_language_cache_and_its_timestamp_on_upgrade(): void {

		$cache_key = Cache::KEY_PREFIX . md5( Base::PLUGIN_ID . '-languages' );

		update_option( Base::PLUGIN_ID . '-version', 5.1 );
		update_option( Base::PLUGIN_ID . '-options', static::_V5_OPTIONS );
		update_option( Base::PLUGIN_ID . '-lang-time', time() );
		update_option(
			$cache_key,
			[
				'expiry' => ( time() + HOUR_IN_SECONDS ),
				'data'   => [ 'php' ],
			]
		);

		$this->_migrate();

		$this->assertFalse( get_option( Base::PLUGIN_ID . '-lang-time', false ) );
		$this->assertFalse( get_option( $cache_key, false ) );

	}

	/**
	 * The site owner is told about the migration on the first admin page they open,
	 * whichever one it is, and told once.
	 *
	 * A notice printed on this plugin's settings page alone never reaches an owner who
	 * does not open that page, and reads as the migration happening only then.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_shows_the_migration_notice_on_whichever_admin_page_comes_first(): void {

		set_current_screen( 'dashboard' );

		update_option( Base::PLUGIN_ID . '-version', 5.1 );
		update_option( Base::PLUGIN_ID . '-options', static::_V5_OPTIONS );

		$this->_migrate();

		$this->assertSame( '5.1.0', get_option( Base::PLUGIN_ID . '-migrated-from' ) );

		ob_start();
		Admin::get_instance()->maybe_show_migration_message();
		$notice = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-success', $notice, 'The dashboard showed nothing.' );
		$this->assertStringContainsString( '5.1.0', $notice );

		// Shown once: the option it reads is deleted as it prints.
		ob_start();
		Admin::get_instance()->maybe_show_migration_message();
		$again = (string) ob_get_clean();

		$this->assertSame( '', $again, 'The notice printed a second time.' );
		$this->assertFalse( get_option( Base::PLUGIN_ID . '-migrated-from', false ) );

		set_current_screen( 'front' );

	}

	/**
	 * Method to run the migration against whatever is currently in the DB.
	 *
	 * Both singletons are dropped first, because the options object reads the DB
	 * once when it is built and the migration holds on to it.
	 *
	 * @return void
	 */
	protected function _migrate(): void {

		$this->_set_singleton( Option::class, null );
		$this->_set_singleton( Migrate::class, null );

		Migrate::get_instance()->settings();

	}

} // end of class

// EOF
