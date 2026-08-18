<?php
/**
 * Tests for how one setting reaches the stored option array, and how it reads back.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Admin;
use iG\Syntax_Hiliter\Asset_Manager;
use iG\Syntax_Hiliter\Base;
use iG\Syntax_Hiliter\Option;
use iG\Syntax_Hiliter\Shortcode_Handler;
use iG\Syntax_Hiliter\Tests\Integration\Fixtures\Default_Settings;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use iG\Syntax_Hiliter\Validate;
use WP_UnitTestCase;

/**
 * The settings are one option array holding seven keys, and the screen saves one of
 * those keys per request. Everything here is about what happens to the other six
 * while that one is written, and about what a setting may hold at all.
 */
class Option_Save_Test extends WP_UnitTestCase {

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

		/*
		 * Every case starts from a site holding exactly what v6 ships with, because
		 * what each of them is about is one setting moving off that. A case which needs
		 * something else stored writes over this.
		 */
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
	 * Method to build an options object which has just read what is stored.
	 *
	 * This is one request's worth of the plugin: the object reads the option array
	 * once when it is built, and holds that for the rest of the request.
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
	 * Two settings changed in quick succession are two requests which overlap, each
	 * having read the settings before either of them wrote. Neither may undo the
	 * other: both report success, so a discarded write is one nobody is told about.
	 *
	 * @return void
	 */
	public function test_two_overlapping_saves_do_not_discard_one_another(): void {

		$first  = $this->_new_reader();
		$second = $this->_new_reader();

		$this->assertTrue( $first->save( 'copy_code', 'no' ) );
		$this->assertTrue( $second->save( 'toolbar', 'no' ) );

		$stored = $this->_get_stored_settings();

		$this->assertSame( 'no', $stored['toolbar'] ?? '', 'The second save never reached the database.' );
		$this->assertSame( 'no', $stored['copy_code'] ?? '', 'The second save put the first save\'s setting back the way it was.' );

		//and the writer which wrote last reads what is actually stored, not the snapshot it started with
		$this->assertSame( 'no', $second->get( 'toolbar' ) );
		$this->assertSame( 'no', $second->get( 'copy_code' ) );

	}

	/**
	 * Saving one setting leaves the other six exactly as they were stored, including
	 * any this version does not know about being dropped as it always has been.
	 *
	 * @return void
	 */
	public function test_saving_one_setting_leaves_the_rest_of_the_stored_array_alone(): void {

		update_option(
			Base::PLUGIN_ID . '-options',
			array_merge(
				Default_Settings::V6,
				[
					'gist_in_comments' => 'yes',
					'theme'            => 'okaidia',
				]
			)
		);

		$this->_new_reader()->save( 'toolbar', 'no' );

		$stored = $this->_get_stored_settings();

		$this->assertSame( 'no', $stored['toolbar'] ?? '' );
		$this->assertSame( 'yes', $stored['gist_in_comments'] ?? '' );
		$this->assertSame( 'okaidia', $stored['theme'] ?? '' );

	}

	/**
	 * A setting stored as NULL is still one of this plugin's settings. Left
	 * unsavable, it answers 500 to every attempt to put it right, for good.
	 *
	 * @return void
	 */
	public function test_a_setting_stored_as_null_can_still_be_saved(): void {

		update_option(
			Base::PLUGIN_ID . '-options',
			array_merge( Default_Settings::V6, [ 'toolbar' => null ] )
		);

		$option = $this->_new_reader();

		$this->assertTrue( $option->save( 'toolbar', 'no' ), 'A setting stored as NULL could not be saved.' );
		$this->assertSame( 'no', $option->get( 'toolbar' ) );
		$this->assertSame( 'no', $this->_get_stored_settings()['toolbar'] ?? '' );

	}

	/**
	 * Every reader of a setting expects a value it can compare against, so NULL is
	 * read as that setting's default rather than handed on.
	 *
	 * @return void
	 */
	public function test_a_setting_stored_as_null_reads_as_its_default(): void {

		update_option(
			Base::PLUGIN_ID . '-options',
			array_merge(
				Default_Settings::V6,
				[
					'hilite_comments'  => null,
					'gist_in_comments' => null,
				]
			)
		);

		$option = $this->_new_reader();

		$this->assertSame( 'yes', $option->get( 'hilite_comments' ) );
		$this->assertSame( 'no', $option->get( 'gist_in_comments' ) );

	}

	/**
	 * The guard on the whole of the above: a name this plugin does not own is not a
	 * setting, whatever is in the array in hand, and is neither saved nor read.
	 *
	 * @return void
	 */
	public function test_a_name_this_plugin_does_not_own_is_neither_saved_nor_read(): void {

		$option = $this->_new_reader();

		$this->assertFalse( $option->save( 'blogname', 'owned' ) );
		$this->assertFalse( $option->get( 'blogname' ) );
		$this->assertArrayNotHasKey( 'blogname', $this->_get_stored_settings() );

	}

	/**
	 * A value this setting does not accept is replaced by the setting's default, never
	 * stored.
	 *
	 * Until 6.0 the value went through `sanitize_title()`, which is not a check at all:
	 * `evil` is a perfectly good slug and went in untouched. A request edited on its way
	 * to the server — devtools, an extension, anything not the settings screen — could
	 * put an arbitrary value into the settings that way.
	 *
	 * @dataProvider unacceptable_value_provider
	 *
	 * @param mixed  $value       Value as it arrives.
	 * @param string $description What the case is.
	 *
	 * @return void
	 */
	public function test_a_value_a_setting_does_not_accept_lands_on_its_default( $value, string $description ): void {

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
	 * Every spelling a flag has ever had reads as the same setting. Versions up to 3.5
	 * stored real booleans and 4.0 onwards stored the words, so both turn up in the
	 * wild.
	 *
	 * @dataProvider flag_spelling_provider
	 *
	 * @param mixed  $value    Value as it arrives.
	 * @param string $expected What it must be stored as.
	 *
	 * @return void
	 */
	public function test_every_spelling_of_a_flag_is_stored_as_yes_or_no( $value, string $expected ): void {

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
	 * The theme setting takes a bundled theme or nothing at all. It is the one setting
	 * whose value becomes a file path, so it is the one worth being sure about.
	 *
	 * @return void
	 */
	public function test_a_theme_this_plugin_does_not_ship_lands_on_the_default_theme(): void {

		$option = $this->_new_reader();

		$option->save( 'theme', '../../../../wp-config' );

		$this->assertSame( Asset_Manager::DEFAULT_THEME, $this->_get_stored_settings()['theme'] ?? '' );

		$option->save( 'theme', 'prism-okaidia' );

		$this->assertSame( 'prism-okaidia', $this->_get_stored_settings()['theme'] ?? '', 'A theme this plugin does ship could not be saved.' );

	}

	/**
	 * The screen must not offer a value storage would refuse. The two lists are built
	 * separately — `Admin` pairs values with labels, `Validate` holds the values — so
	 * this is the seam where they could drift apart.
	 *
	 * The two are compared as **sets**, because only one of them has an order that
	 * means anything. `Validate` answers a list to look a value up in; the screen
	 * decides what a reader reads down, which for the themes is "None" first and then
	 * by name. Asserting the two sequences match would be asserting that the dropdown
	 * is ordered by whatever the allowlist happens to be built from.
	 *
	 * @return void
	 */
	public function test_the_settings_screen_offers_exactly_what_can_be_stored(): void {

		$validate = Validate::get_instance();

		foreach ( Admin::get_settings_schema() as $name => $setting ) {

			$this->assertEqualsCanonicalizing(
				$validate->get_option_values( $name ),
				array_keys( $setting['choices'] ),
				sprintf( 'The %s setting offers a different set of values than it accepts.', $name )
			);

		}

	}

	/**
	 * The defaults every site that has never opened the settings page is running on.
	 * Spelled out here rather than read from the class under test, because the whole
	 * point is to notice one of them moving.
	 *
	 * @return void
	 */
	public function test_the_shipped_defaults_are_what_they_have_always_been(): void {
		$this->assertSame( Default_Settings::V6, Validate::get_instance()->get_option_defaults() );
	}

	/**
	 * Whatever a flag is stored as, both readers agree on what it means.
	 *
	 * `Validate::to_yesno()` is the one converter and `Shortcode_Handler` reaches it
	 * on the read side, so `1`/`0`, `true`/`false`, `on`/`off` and `yes`/`no` all read
	 * alike — versions up to 3.5 stored real booleans and 4.0 onwards stored the
	 * words, and both are still out there. A value which is none of those is not a
	 * flag at all and reads as the setting's default, which is the same answer
	 * `Option::get()` gives for a NULL.
	 *
	 * Two settings on every row, with opposite defaults, so that a reader which
	 * quietly answered the same thing for both would fail rather than pass half the
	 * time.
	 *
	 * @dataProvider stored_flag_provider
	 *
	 * @param mixed  $hilite      What `hilite_comments`, whose default is `yes`, is stored as.
	 * @param mixed  $gist        What `gist_in_comments`, whose default is `no`, is stored as.
	 * @param bool   $expect_hilite What `hilite_comments` should read as.
	 * @param bool   $expect_gist   What `gist_in_comments` should read as.
	 * @param string $description What the row stands for, for the failure message.
	 *
	 * @return void
	 */
	public function test_a_stored_flag_reads_as_what_it_means( $hilite, $gist, bool $expect_hilite, bool $expect_gist, string $description ): void {

		update_option(
			Base::PLUGIN_ID . '-options',
			array_merge(
				Default_Settings::V6,
				[
					'hilite_comments'  => $hilite,
					'gist_in_comments' => $gist,
				]
			)
		);

		$this->_new_reader();

		$this->assertSame(
			$expect_hilite,
			Shortcode_Handler::is_plugin_option_on( 'hilite_comments', 'yes' ),
			sprintf( '%s did not read as expected for a setting which defaults to yes.', $description )
		);

		$this->assertSame(
			$expect_gist,
			Shortcode_Handler::is_plugin_option_on( 'gist_in_comments', 'no' ),
			sprintf( '%s did not read as expected for a setting which defaults to no.', $description )
		);

	}

	/**
	 * Method to provide the ways a flag turns up in a real database.
	 *
	 * @return array
	 */
	public function stored_flag_provider(): array {

		return [
			[ null, null, true, false, 'A setting stored as NULL' ],
			[ 'perhaps', 'perhaps', true, false, 'A stored value which is not a flag at all' ],
			[ '0', '1', false, true, 'The booleans versions up to 3.5 stored' ],
			[ 'no', 'yes', false, true, 'The words 4.0 onwards stored' ],
		];

	}

}    //end of class


//EOF
