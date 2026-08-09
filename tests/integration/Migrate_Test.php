<?php
/**
 * AC-11 — an existing install's settings survive the upgrade to v6.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Base;
use iG\Syntax_Hiliter\Cache;
use iG\Syntax_Hiliter\Migrate;
use iG\Syntax_Hiliter\Option;
use ReflectionProperty;
use WP_UnitTestCase;

/**
 * The v5 settings map to the v6 set, the v3.5 path still works, a fresh install
 * lands on the defaults, and none of it happens twice.
 */
class Migrate_Test extends WP_UnitTestCase {

	/**
	 * The option array a v5.1 install holds.
	 *
	 * Every value is the opposite of its v6 default, so a setting which fails to
	 * carry across cannot pass by coincidence.
	 *
	 * @var array
	 */
	const V5_OPTIONS = [
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
	 * The option set v6 ships with.
	 *
	 * @var array
	 */
	const V6_DEFAULTS = [
		'theme'                => 'prism',
		'toolbar'              => 'yes',
		'copy_code'            => 'yes',
		'show_line_numbers'    => 'yes',
		'normalize_whitespace' => 'no',
		'hilite_comments'      => 'yes',
		'gist_in_comments'     => 'no',
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
	 * @return void
	 */
	public function test_a_v51_install_maps_its_settings_to_the_v6_option_set(): void {

		update_option( Base::PLUGIN_ID . '-version', 5.1 );
		update_option( Base::PLUGIN_ID . '-options', static::V5_OPTIONS );

		$this->_migrate();

		$this->assertSame(
			[
				'theme'                => 'none',            // fe-styles=no
				'toolbar'              => 'no',              // carried
				'copy_code'            => 'no',              // plain_text=no
				'show_line_numbers'    => 'no',              // carried
				'normalize_whitespace' => 'no',              // new, defaults off
				'hilite_comments'      => 'no',              // carried
				'gist_in_comments'     => 'yes',             // carried
			],
			get_option( Base::PLUGIN_ID . '-options' )
		);

		$this->assertSame( '6.0.0', get_option( Base::PLUGIN_ID . '-version' ) );
		$this->assertSame( '5.1.0', get_option( Base::PLUGIN_ID . '-migrated-from' ) );

	}

	/**
	 * The style setting is the only one whose mapping branches, so both of its
	 * outcomes are checked.
	 *
	 * @return void
	 */
	public function test_the_v5_style_setting_maps_to_the_default_theme_when_it_was_on(): void {

		update_option( Base::PLUGIN_ID . '-version', 5.1 );
		update_option(
			Base::PLUGIN_ID . '-options',
			array_merge(
				static::V5_OPTIONS,
				[
					'fe-styles'  => 'yes',
					'plain_text' => 'yes',
				]
			)
		);

		$this->_migrate();

		$options = get_option( Base::PLUGIN_ID . '-options' );

		$this->assertSame( 'prism', $options['theme'] );
		$this->assertSame( 'yes', $options['copy_code'] );

	}

	/**
	 * The migration is triggered on every page load, so running twice would mean
	 * rewriting the site's settings on every request.
	 *
	 * @return void
	 */
	public function test_the_migration_does_not_run_again_on_a_subsequent_load(): void {

		update_option( Base::PLUGIN_ID . '-version', 5.1 );
		update_option( Base::PLUGIN_ID . '-options', static::V5_OPTIONS );

		$this->_migrate();

		//a v5 shaped array again: a second migration would rewrite it
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
	 * @return void
	 */
	public function test_a_v35_install_maps_its_legacy_options_and_deletes_them(): void {

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
		$this->assertSame( '6.0.0', get_option( Base::PLUGIN_ID . '-version' ) );

	}

	/**
	 * A fresh install writes the defaults and no upgrade notice.
	 *
	 * @return void
	 */
	public function test_a_fresh_install_lands_on_the_v6_defaults(): void {

		$this->_migrate();

		$this->assertSame( static::V6_DEFAULTS, get_option( Base::PLUGIN_ID . '-options' ) );
		$this->assertSame( '6.0.0', get_option( Base::PLUGIN_ID . '-version' ) );
		$this->assertFalse( get_option( Base::PLUGIN_ID . '-migrated-from', false ) );

	}

	/**
	 * The language list and its timestamp described the GeSHi file scan, which no
	 * longer exists. Left behind, the cache would be served to the v6 registry.
	 *
	 * @return void
	 */
	public function test_the_upgrade_deletes_the_language_cache_and_its_timestamp(): void {

		$cache_key = Cache::KEY_PREFIX . md5( Base::PLUGIN_ID . '-languages' );

		update_option( Base::PLUGIN_ID . '-version', 5.1 );
		update_option( Base::PLUGIN_ID . '-options', static::V5_OPTIONS );
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

	/**
	 * Method to replace a singleton instance.
	 *
	 * @param string      $class_name Fully qualified class name.
	 * @param object|null $instance   Instance to install.
	 *
	 * @return void
	 */
	protected function _set_singleton( string $class_name, ?object $instance ): void {
		( new ReflectionProperty( $class_name, '_instance' ) )->setValue( null, $instance );
	}

}    //end of class


//EOF
