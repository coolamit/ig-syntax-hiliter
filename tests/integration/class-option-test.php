<?php
/**
 * Tests for how one setting reaches the stored option array, and how it reads back.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Base;
use iG\Syntax_Hiliter\Option;
use iG\Syntax_Hiliter\Tests\Integration\Fixtures\Default_Settings;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use WP_UnitTestCase;

/**
 * The settings are one option array holding ten keys, and the screen saves one of
 * those keys per request. Everything here is about what happens to the other nine
 * while that one is written, and about how a stored value reads back.
 */
class Option_Test extends WP_UnitTestCase {

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
	 * Two settings changed in quick succession are two overlapping requests, each
	 * having read the settings before either wrote. Neither may undo the other.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_does_not_let_two_overlapping_saves_discard_one_another(): void {

		$first  = $this->_new_reader();
		$second = $this->_new_reader();

		$this->assertTrue( $first->save( 'copy_code', 'no' ) );
		$this->assertTrue( $second->save( 'toolbar', 'no' ) );

		$stored = $this->_get_stored_settings();

		$this->assertSame( 'no', $stored['toolbar'] ?? '', 'The second save never reached the database.' );
		$this->assertSame( 'no', $stored['copy_code'] ?? '', 'The second save put the first save\'s setting back the way it was.' );

		// The writer which wrote last reads what is stored, not the snapshot it started with.
		$this->assertSame( 'no', $second->get( 'toolbar' ) );
		$this->assertSame( 'no', $second->get( 'copy_code' ) );

	}

	/**
	 * Saving one setting leaves the other nine exactly as they were stored, including
	 * any this version does not know about being dropped as it always has been.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_leaves_the_rest_of_the_stored_array_alone_when_saving_one_setting(): void {

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
	 * @test
	 *
	 * @return void
	 */
	public function it_still_saves_a_setting_stored_as_null(): void {

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
	 * @test
	 *
	 * @return void
	 */
	public function it_reads_a_setting_stored_as_null_as_its_default(): void {

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
	 * @test
	 *
	 * @return void
	 */
	public function it_neither_saves_nor_reads_a_name_this_plugin_does_not_own(): void {

		$option = $this->_new_reader();

		$this->assertFalse( $option->save( 'blogname', 'owned' ) );
		$this->assertFalse( $option->get( 'blogname' ) );
		$this->assertArrayNotHasKey( 'blogname', $this->_get_stored_settings() );

	}

} // end of class

// EOF
