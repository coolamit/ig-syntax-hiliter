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
 * Two settings do nothing on their own: the copy button is drawn inside the toolbar
 * and the brace colours are painted on spans the brace matching creates, so
 * `save_option()` moves the setting each one needs in the same request. It is the
 * route and not the page, because the page locks every control for the length of a
 * save so that two requests cannot overlap.
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

		// The class hooks from its constructor, which runs once per process, and the test case restores the hook registry after every test, so the action is put back by hand.
		if ( false === has_action( 'rest_api_init', [ Admin::get_instance(), 'register_rest_routes' ] ) ) {
			add_action( 'rest_api_init', [ Admin::get_instance(), 'register_rest_routes' ] );
		}

		$GLOBALS['wp_rest_server'] = null;  // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Forcing a fresh REST server so the route above is registered on it. The global is core's, not this plugin's.

		rest_get_server();

		$this->_reload_options();

	}

	/**
	 * Puts the settings object back for whatever runs next. After the rollback and not
	 * before it: the options object reads the stored array once, and the object `Admin`
	 * holds would otherwise report values whose row had been rolled away.
	 *
	 * @return void
	 */
	public function tear_down(): void {

		parent::tear_down();

		$this->_reload_options();

	}

	/**
	 * Method to give `Admin` a settings object which has just read the database.
	 * Dropping the singleton is not enough: `Base::__construct()` bound the instance
	 * it was given when it ran.
	 *
	 * @return void
	 */
	protected function _reload_options(): void {

		$this->_set_singleton( Option::class, null );

		( new ReflectionProperty( Base::class, '_option' ) )->setValue( Admin::get_instance(), Option::get_instance() );

	}

	/**
	 * Method to put a known set of settings in the database, written straight to the
	 * option so the starting point cannot be changed by the rule under test.
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
	 * Switching the brace colours on switches the brace matching on with them; the
	 * colours are painted on spans the matching creates.
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
	 * Both starting points, because each catches a different mistake: from matching
	 * on, a rule taking the requirement off with the dependent; from matching off,
	 * the state WP-CLI can still write, a rule switching a requirement on for a `no`.
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
	 * The route cannot produce colours-on-with-matching-off, but WP-CLI still can, and
	 * a site owner who has it and switches the matching on must keep their colours.
	 * Seeding both off would pass whether the rule worked or not.
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
	 * Asserted so that one rule covers both pairs.
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
	 * A partner already where it needs to be is not named in the answer: `also` is
	 * the list of controls the screen has to repaint.
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
	 * Every declared dependency names a toggle this plugin has, and not itself. A
	 * `requires` naming a setting which does not exist fails silently.
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

} // end of class

// EOF
