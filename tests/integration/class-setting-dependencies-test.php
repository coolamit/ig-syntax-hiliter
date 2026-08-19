<?php
/**
 * Tests for the settings which cannot work without another setting.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Admin;
use iG\Syntax_Hiliter\Base;
use iG\Syntax_Hiliter\Option;
use iG\Syntax_Hiliter\Tests\Integration\Fixtures\Default_Settings;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use ReflectionProperty;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Two settings do nothing on their own, and the route is what keeps them honest.
 *
 * The copy button is drawn inside the toolbar and the brace colours are painted on
 * spans the brace matching creates, so either one switched on alone is a control a
 * site owner has set and which does nothing. `save_option()` therefore moves the
 * setting each one needs, in the same request.
 *
 * **The route and not the settings page**, which is why these are asserted here and
 * not in a browser. The page locks every control for the length of a save precisely
 * so that a second request cannot overlap the first — all the settings live in one
 * stored array and the second would write its own idea of that array back over the
 * first — so a screen answering one click with two saves would be doing the thing
 * the lock exists to prevent.
 *
 * The two moves which must *not* happen are asserted as carefully as the two which
 * must. Somebody may well want the toolbar without the copy button.
 */
class Setting_Dependencies_Test extends WP_UnitTestCase {

	use Pipeline_Test_Helpers;

	/**
	 * Route under test.
	 *
	 * @var string
	 */
	protected const string _ROUTE = '/' . Admin::REST_NAMESPACE . '/option';

	/**
	 * Brings up a REST server with the plugin's routes on it.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		Admin::get_instance();

		/*
		 * The class hooks itself from its constructor, which runs once per process,
		 * and the test case puts the hook registry back the way it found it after
		 * every test. So the action is put back by hand when it has been taken away —
		 * asking for the instance again cannot do it, the object already exists.
		 */
		if ( false === has_action( 'rest_api_init', [ Admin::get_instance(), 'register_rest_routes' ] ) ) {
			add_action( 'rest_api_init', [ Admin::get_instance(), 'register_rest_routes' ] );
		}

		$GLOBALS['wp_rest_server'] = null;  // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Forcing a fresh REST server so the route above is registered on it. The global is core's, not this plugin's.

		rest_get_server();

		$this->_reload_options();

	}

	/**
	 * Puts the settings object back for whatever runs next.
	 *
	 * **After the rollback and not before it.** The options object reads the stored
	 * array once when it is built, and every case here writes settings; without this
	 * the object `Admin` is holding would go on reporting values whose database row
	 * had been rolled away, for every later suite in the same process.
	 *
	 * @return void
	 */
	public function tear_down(): void {

		parent::tear_down();

		$this->_reload_options();

	}

	/**
	 * Method to give `Admin` a settings object which has just read the database.
	 *
	 * Dropping the singleton is not enough on its own: `Base::__construct()` binds
	 * the instance it was given when it ran, and asking for a new one cannot reach a
	 * property on an object which already exists.
	 *
	 * @return void
	 */
	protected function _reload_options(): void {

		$this->_set_singleton( Option::class, null );

		( new ReflectionProperty( Base::class, '_option' ) )->setValue( Admin::get_instance(), Option::get_instance() );

	}

	/**
	 * Method to put a known set of settings in the database.
	 *
	 * Written straight to the option rather than saved through the plugin, so that
	 * the starting point of a case cannot itself be changed by the rule under test.
	 *
	 * @param array $settings Settings to set, over the shipped defaults.
	 *
	 * @return void
	 */
	protected function _seed( array $settings ): void {

		update_option( Base::PLUGIN_ID . '-options', array_merge( Default_Settings::V6, $settings ) );

		$this->_reload_options();

	}

	/**
	 * Method to save one setting through the route, as an administrator.
	 *
	 * @param string $name  Setting to save.
	 * @param string $value Value to save it with.
	 *
	 * @return array What the route answered with.
	 */
	protected function _save( string $name, string $value ): array {

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new WP_REST_Request( 'POST', self::_ROUTE );

		$request->set_body_params(
			[
				'name'  => $name,
				'value' => $value,
			]
		);

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status(), sprintf( 'Saving %s was refused.', $name ) );

		return (array) $response->get_data();

	}

	/**
	 * Method to read the settings as they are stored, rather than as the plugin
	 * happens to be holding them in memory.
	 *
	 * @return array
	 */
	protected function _get_stored_settings(): array {

		$stored = get_option( Base::PLUGIN_ID . '-options', [] );

		return ( is_array( $stored ) ) ? $stored : [];

	}

	/**
	 * Switching the brace colours on switches the brace matching on with them.
	 *
	 * The colours are painted on the spans the matching creates, so this is the move
	 * without which the setting a site owner just switched on would do nothing at all.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_switches_on_what_a_setting_needs(): void {

		$this->_seed(
			[
				'match_braces'   => 'no',
				'rainbow_braces' => 'no',
			]
		);

		$answer = $this->_save( 'rainbow_braces', 'yes' );
		$stored = $this->_get_stored_settings();

		$this->assertSame( 'yes', $stored['rainbow_braces'] ?? '' );
		$this->assertSame( 'yes', $stored['match_braces'] ?? '', 'The colours were switched on and the matching they need was not.' );

		$this->assertSame( [ 'match_braces' => 'yes' ], $answer['also'] ?? null, 'The answer has to name what moved, or the screen cannot put that control right.' );

	}

	/**
	 * Switching the brace matching off switches the colours off with it.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_switches_off_whatever_needed_the_setting_switched_off(): void {

		$this->_seed(
			[
				'match_braces'   => 'yes',
				'rainbow_braces' => 'yes',
			]
		);

		$answer = $this->_save( 'match_braces', 'no' );
		$stored = $this->_get_stored_settings();

		$this->assertSame( 'no', $stored['match_braces'] ?? '' );
		$this->assertSame( 'no', $stored['rainbow_braces'] ?? '', 'The matching went off and left the colours on, which paint nothing.' );

		$this->assertSame( [ 'rainbow_braces' => 'no' ], $answer['also'] ?? null );

	}

	/**
	 * Switching a dependent off says nothing about what it needed.
	 *
	 * This is half of what makes the rule a dependency rather than a pair of settings
	 * welded together. Somebody who switches the colours off has said nothing at all
	 * about whether they still want to see a bracket's partner on hover.
	 *
	 * **Both starting points, because each one catches a different mistake.** From
	 * matching on, this fails if the rule ever takes the requirement off with the
	 * setting that needed it. From matching off — the state WP-CLI can still write —
	 * it fails if the rule which switches a requirement *on* is ever let loose on a
	 * value of `no`. Either seed alone passes whether that half works or not, which
	 * is what breaking each guard in turn showed.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_leaves_a_requirement_alone_when_a_dependent_goes_off(): void {

		foreach ( [ 'yes', 'no' ] as $matching ) {

			$this->_seed(
				[
					'match_braces'   => $matching,
					'rainbow_braces' => 'yes',
				]
			);

			$answer = $this->_save( 'rainbow_braces', 'no' );
			$stored = $this->_get_stored_settings();

			$this->assertSame( 'no', $stored['rainbow_braces'] ?? '' );

			$this->assertSame(
				$matching,
				$stored['match_braces'] ?? '',
				sprintf( 'Switching the colours off moved the matching, which was %s.', $matching )
			);

			$this->assertSame( [], $answer['also'] ?? null );

		}

	}

	/**
	 * Switching a requirement on says nothing about what depends on it.
	 *
	 * The other half, and it needs the awkward starting point to mean anything. The
	 * route can no longer produce colours-on-with-matching-off, but WP-CLI and any
	 * other writer of the option still can, and it is the state the front end is
	 * built to render — so this is the site owner who has it, opens the settings page
	 * and switches the matching on. **Their colours must survive that.**
	 *
	 * Seeding both off instead would pass whether the rule worked or not: the reverse
	 * move skips a setting which is already off, so there would be nothing to see.
	 * That is exactly what this case did until removing the guard failed nothing.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_leaves_a_dependent_alone_when_a_requirement_comes_on(): void {

		$this->_seed(
			[
				'match_braces'   => 'no',
				'rainbow_braces' => 'yes',
			]
		);

		$answer = $this->_save( 'match_braces', 'yes' );
		$stored = $this->_get_stored_settings();

		$this->assertSame( 'yes', $stored['match_braces'] ?? '' );
		$this->assertSame( 'yes', $stored['rainbow_braces'] ?? '', 'Switching the matching on switched the colours off.' );

		$this->assertSame( [], $answer['also'] ?? null );

	}

	/**
	 * The copy button takes the toolbar with it, because it is drawn in the toolbar.
	 *
	 * This pair has depended on each other since 6.0 and was documented rather than
	 * enforced, which left a site owner able to switch on a button that is never
	 * drawn. It is here to make sure one rule covers both pairs rather than the
	 * brackets getting a rule of their own.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_switches_the_toolbar_on_with_the_copy_button(): void {

		$this->_seed(
			[
				'toolbar'   => 'no',
				'copy_code' => 'no',
			]
		);

		$answer = $this->_save( 'copy_code', 'yes' );
		$stored = $this->_get_stored_settings();

		$this->assertSame( 'yes', $stored['copy_code'] ?? '' );
		$this->assertSame( 'yes', $stored['toolbar'] ?? '', 'The copy button went on with no toolbar to draw it in.' );

		$this->assertSame( [ 'toolbar' => 'yes' ], $answer['also'] ?? null );

	}

	/**
	 * And switching the toolbar off switches the copy button off with it.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_switches_the_copy_button_off_with_the_toolbar(): void {

		$this->_seed(
			[
				'toolbar'   => 'yes',
				'copy_code' => 'yes',
			]
		);

		$answer = $this->_save( 'toolbar', 'no' );
		$stored = $this->_get_stored_settings();

		$this->assertSame( 'no', $stored['toolbar'] ?? '' );
		$this->assertSame( 'no', $stored['copy_code'] ?? '' );

		$this->assertSame( [ 'copy_code' => 'no' ], $answer['also'] ?? null );

	}

	/**
	 * A partner already where it needs to be is not named in the answer.
	 *
	 * `also` is a list of controls the screen has to put right, so naming one which
	 * did not move would have the page repaint a control nobody touched and the notice
	 * report a change nobody made.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_names_nothing_when_the_partner_is_already_where_it_needs_to_be(): void {

		$this->_seed(
			[
				'match_braces'   => 'yes',
				'rainbow_braces' => 'no',
			]
		);

		$answer = $this->_save( 'rainbow_braces', 'yes' );
		$stored = $this->_get_stored_settings();

		$this->assertSame( 'yes', $stored['rainbow_braces'] ?? '' );
		$this->assertSame( 'yes', $stored['match_braces'] ?? '' );

		$this->assertSame( [], $answer['also'] ?? null );

	}

	/**
	 * Every declared dependency names a setting this plugin actually has.
	 *
	 * A `requires` naming a setting which does not exist fails silently: nothing is
	 * ever switched on with the setting that declares it, and the screen looks exactly
	 * as it does when the rule is working. The same goes for a setting requiring
	 * itself, which would ask the route to save the value it has just saved.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_declares_every_dependency_against_a_setting_it_has(): void {

		$schema  = Admin::get_settings_schema();
		$checked = 0;

		foreach ( $schema as $name => $setting ) {

			if ( empty( $setting['requires'] ) ) {
				continue;
			}

			$required = $setting['requires'];

			$this->assertArrayHasKey(
				$required,
				$schema,
				sprintf( '%1$s requires %2$s, which is not a setting this plugin has.', $name, $required )
			);

			$this->assertNotSame( $name, $required, sprintf( '%s requires itself.', $name ) );

			$this->assertSame(
				'toggle',
				$schema[ $required ]['type'] ?? '',
				sprintf( '%1$s requires %2$s, which is not a toggle and so cannot be switched on.', $name, $required )
			);

			++$checked;

		}

		$this->assertSame( 2, $checked, 'Two settings declare a dependency: the copy button and the brace colours.' );

	}

}    //end of class

//EOF
