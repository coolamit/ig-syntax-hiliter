<?php
/**
 * Tests for what a setting may hold: the allowlist, the yes/no reading and the defaults.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Base;
use iG\Syntax_Hiliter\Option;
use iG\Syntax_Hiliter\Tests\Integration\Fixtures\Default_Settings;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use iG\Syntax_Hiliter\Themes;
use iG\Syntax_Hiliter\Validate;
use WP_UnitTestCase;

/**
 * What a setting may hold at all: a value outside the allowlist lands on the
 * default, every spelling of a flag is stored as yes or no, and the defaults are
 * the ones the plugin has always shipped.
 */
class Validate_Test extends WP_UnitTestCase {

	use Pipeline_Test_Helpers;

	/**
	 * The options object as the plugin booted it.
	 *
	 * @var \iG\Syntax_Hiliter\Option|null
	 */
	protected ?Option $_original_option = null;

	/**
	 * Remembers the singleton to put back.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		$this->_original_option = Option::get_instance();

		// Every case starts from the shipped defaults; one which needs something else writes over this.
		update_option( Base::PLUGIN_ID . '-options', Default_Settings::V6 );

	}

	/**
	 * Puts the singleton back, so that a test's stand in options object cannot leak
	 * into the next one.
	 *
	 * @return void
	 */
	public function tear_down(): void {

		$this->_set_singleton( Option::class, $this->_original_option );

		parent::tear_down();

	}

	/**
	 * Method to build an options object which has just read what is stored, which
	 * is one request's worth of the plugin.
	 *
	 * @return \iG\Syntax_Hiliter\Option
	 */
	protected function _new_reader(): Option {

		$this->_set_singleton( Option::class, null );

		return Option::get_instance();

	}

	/**
	 * Method to read the settings as they are stored.
	 *
	 * @return array
	 */
	protected function _get_stored_settings(): array {

		$stored = get_option( Base::PLUGIN_ID . '-options', [] );

		return ( is_array( $stored ) ) ? $stored : [];

	}

	/**
	 * A value this setting does not accept is replaced by the setting's default, never
	 * stored. A slug sanitiser is not a check: `evil` is a perfectly good slug.
	 *
	 * @test
	 *
	 * @dataProvider unacceptable_value_provider
	 *
	 * @param mixed  $value       Value as it arrives.
	 * @param string $description What the case is.
	 *
	 * @return void
	 */
	public function it_lands_a_value_a_setting_does_not_accept_on_its_default( $value, string $description ): void {

		$option = $this->_new_reader();

		$option->save( 'gist_in_comments', $value );

		$this->assertSame( 'no', $this->_get_stored_settings()['gist_in_comments'] ?? '', $description );

	}

	/**
	 * Values which are not a yes, a no or a flag.
	 *
	 * @return array
	 */
	public function unacceptable_value_provider(): array {

		return [
			[ 'perhaps', 'A word which is neither yes nor no was stored.' ],
			[ 'evil', 'A made up value was stored, which is what sanitize_title() allowed.' ],
			[ '', 'An empty value was stored.' ],
			[ '2', 'A number which is not a flag was stored.' ],
			[ [ 'yes' ], 'An array was stored.' ],
			[ null, 'NULL was stored.' ],
		];

	}

	/**
	 * Every spelling a flag has ever had reads as the same setting: versions up to 3.5
	 * stored real booleans and 4.0 onwards stored the words.
	 *
	 * @test
	 *
	 * @dataProvider flag_spelling_provider
	 *
	 * @param mixed  $value    Value as it arrives.
	 * @param string $expected What it must be stored as.
	 *
	 * @return void
	 */
	public function it_stores_every_spelling_of_a_flag_as_yes_or_no( $value, string $expected ): void {

		$this->_new_reader()->save( 'gist_in_comments', $value );

		$this->assertSame( $expected, $this->_get_stored_settings()['gist_in_comments'] ?? '' );

	}

	/**
	 * The spellings, and what each one means.
	 *
	 * @return array
	 */
	public function flag_spelling_provider(): array {

		return [
			[ 'yes', 'yes' ],
			[ 'YES', 'yes' ],
			[ ' yes ', 'yes' ],
			[ true, 'yes' ],
			[ 1, 'yes' ],
			[ '1', 'yes' ],
			[ 'on', 'yes' ],
			[ 'true', 'yes' ],
			[ 'no', 'no' ],
			[ 'No', 'no' ],
			[ false, 'no' ],
			[ 0, 'no' ],
			[ '0', 'no' ],
			[ 'off', 'no' ],
			[ 'false', 'no' ],
		];

	}

	/**
	 * The theme setting takes a bundled theme or nothing at all; it is the one
	 * setting whose value becomes a file path.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_lands_a_theme_this_plugin_does_not_ship_on_the_default_theme(): void {

		$option = $this->_new_reader();

		$option->save( 'theme', '../../../../wp-config' );

		$this->assertSame( Themes::DEFAULT_THEME, $this->_get_stored_settings()['theme'] ?? '' );

		$option->save( 'theme', 'prism-okaidia' );

		$this->assertSame( 'prism-okaidia', $this->_get_stored_settings()['theme'] ?? '', 'A theme this plugin does ship could not be saved.' );

	}

	/**
	 * The defaults every site that has never opened the settings page is running on,
	 * spelled out rather than read from the class under test so that one moving is noticed.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_ships_the_defaults_it_has_always_shipped(): void {
		$this->assertSame( Default_Settings::V6, Validate::get_instance()->get_option_defaults() );
	}

} // end of class

// EOF
