<?php
/**
 * Tests for how one setting reaches the stored option array.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Base;
use iG\Syntax_Hiliter\Option;
use ReflectionProperty;
use WP_UnitTestCase;

/**
 * The settings are one option array holding seven keys, and the screen saves one of
 * those keys per request. Everything here is about what happens to the other six
 * while that one is written.
 */
class Option_Save_Test extends WP_UnitTestCase {

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
	 * Remembers the singleton to put back.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		$this->_original_option = Option::get_instance();

	}

	/**
	 * Puts the singleton back, so that a test's stand in options object cannot leak
	 * into the next one.
	 *
	 * @return void
	 */
	public function tear_down(): void {

		( new ReflectionProperty( Option::class, '_instance' ) )->setValue( null, $this->_original_option );

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

		( new ReflectionProperty( Option::class, '_instance' ) )->setValue( null, null );

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

		update_option( Base::PLUGIN_ID . '-options', static::V6_DEFAULTS );

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
				static::V6_DEFAULTS,
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
			array_merge( static::V6_DEFAULTS, [ 'toolbar' => null ] )
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
				static::V6_DEFAULTS,
				[
					'hilite_comments'      => null,
					'normalize_whitespace' => null,
				]
			)
		);

		$option = $this->_new_reader();

		$this->assertSame( 'yes', $option->get( 'hilite_comments' ) );
		$this->assertSame( 'no', $option->get( 'normalize_whitespace' ) );

	}

	/**
	 * The guard on the whole of the above: a name this plugin does not own is not a
	 * setting, whatever is in the array in hand, and is neither saved nor read.
	 *
	 * @return void
	 */
	public function test_a_name_this_plugin_does_not_own_is_neither_saved_nor_read(): void {

		update_option( Base::PLUGIN_ID . '-options', static::V6_DEFAULTS );

		$option = $this->_new_reader();

		$this->assertFalse( $option->save( 'blogname', 'owned' ) );
		$this->assertFalse( $option->get( 'blogname' ) );
		$this->assertArrayNotHasKey( 'blogname', $this->_get_stored_settings() );

	}

}    //end of class


//EOF
