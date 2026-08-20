<?php
/**
 * Tests for the settings save route's access control.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Admin;
use iG\Syntax_Hiliter\Base;
use iG\Syntax_Hiliter\Block_Converter;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Asset_Test_Helpers;
use iG\Syntax_Hiliter\Themes;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The settings route is the only way into the plugin's settings. Every test here
 * asserts the stored settings as well as the status code: a 403 that wrote anyway
 * would be worse than no check at all.
 */
class Rest_Option_Security_Test extends WP_UnitTestCase {

	use Asset_Test_Helpers;

	/**
	 * Route under test.
	 *
	 * @var string
	 */
	protected const string _ROUTE = '/' . Admin::REST_NAMESPACE . '/option';

	/**
	 * The theme refresh route, which is on the same permission callback.
	 *
	 * @var string
	 */
	protected const string _THEMES_ROUTE = '/' . Admin::REST_NAMESPACE . '/themes';

	/**
	 * Brings up a REST server with the plugin's routes on it.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		Admin::get_instance();
		Block_Converter::get_instance();

		// Each class hooks from its constructor, which runs once per process, and the test case restores the hook registry after every test, so the action is put back by hand.
		foreach ( [ Admin::get_instance(), Block_Converter::get_instance() ] as $service ) {

			if ( false === has_action( 'rest_api_init', [ $service, 'register_rest_routes' ] ) ) {
				add_action( 'rest_api_init', [ $service, 'register_rest_routes' ] );
			}
		}

		$GLOBALS['wp_rest_server'] = null;  // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Forcing a fresh REST server so the routes above are registered on it. The global is core's, not this plugin's.

		rest_get_server();

	}

	/**
	 * Throws away the theme list the refresh case warms: the static in front of the
	 * cached option is memory, which the transaction cannot roll back.
	 *
	 * @return void
	 */
	public function tear_down(): void {

		$this->_reset_theme_cache();

		parent::tear_down();

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
	 * Method to send a well formed save request, so that what the response reports
	 * is the access check and never a quarrel about the parameters.
	 *
	 * @param string $name  Setting name to send.
	 * @param string $value Value to send.
	 *
	 * @return \WP_REST_Response
	 */
	protected function _save( string $name, string $value ) {

		$request = new WP_REST_Request( 'POST', self::_ROUTE );

		$request->set_body_params(
			[
				'name'  => $name,
				'value' => $value,
			]
		);

		return rest_do_request( $request );

	}

	/**
	 * A request with nobody behind it is refused with 401 and changes nothing.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_refuses_a_logged_out_request_and_saves_nothing(): void {

		wp_set_current_user( 0 );

		$before   = $this->_get_stored_settings();
		$response = $this->_save( 'toolbar', 'no' );

		$this->assertSame( $before, $this->_get_stored_settings(), 'The refused request wrote a setting anyway.' );
		$this->assertSame( 401, $response->get_status() );

	}

	/**
	 * A logged in user without `manage_options` is refused with 403 and changes
	 * nothing.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_refuses_a_subscriber_request_and_saves_nothing(): void {

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$before   = $this->_get_stored_settings();
		$response = $this->_save( 'toolbar', 'no' );

		$this->assertSame( $before, $this->_get_stored_settings(), 'The refused request wrote a setting anyway.' );
		$this->assertSame( 403, $response->get_status() );

	}

	/**
	 * The route saves this plugin's settings and nothing else. A name it does not
	 * own is refused, so it can never become a way of writing an arbitrary option.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_refuses_an_option_this_plugin_does_not_own(): void {

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$blog_name = get_option( 'blogname' );
		$before    = $this->_get_stored_settings();

		$response = $this->_save( 'blogname', 'owned' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( $blog_name, get_option( 'blogname' ) );
		$this->assertSame( $before, $this->_get_stored_settings() );

	}

	/**
	 * A value the setting does not accept is refused, and the setting keeps the value
	 * it had.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_refuses_a_value_outside_the_schema(): void {

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$before = $this->_get_stored_settings();

		$this->assertSame( 400, $this->_save( 'toolbar', 'perhaps' )->get_status() );
		$this->assertSame( 400, $this->_save( 'theme', 'no-such-theme' )->get_status() );

		$this->assertSame( $before, $this->_get_stored_settings() );

	}

	/**
	 * A setting name which is not a string is refused cleanly. A PHP warning printed
	 * ahead of the response body makes it unparseable, and the screen then reports a
	 * generic failure instead of what was wrong.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_refuses_a_setting_name_which_is_not_a_string_without_a_php_diagnostic(): void {

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$before      = $this->_get_stored_settings();
		$diagnostics = [];

		set_error_handler(  // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Catching PHP diagnostics is what this test is for.
			static function ( $errno, $errstr, $errfile, $errline ) use ( &$diagnostics ) {
				$diagnostics[] = sprintf( '%d: %s in %s on line %d', $errno, $errstr, $errfile, $errline );

				return true;
			}
		);

		try {

			$request = new WP_REST_Request( 'POST', self::_ROUTE );

			$request->set_body_params(
				[
					'name'  => [ 'toolbar' ],
					'value' => 'no',
				]
			);

			$response = rest_do_request( $request );

		} finally {
			restore_error_handler();
		}

		$this->assertSame( [], $diagnostics, 'Refusing a setting name which is not a string raised a PHP diagnostic.' );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( $before, $this->_get_stored_settings() );

	}

	/**
	 * The control: an administrator sending a valid request does get the setting
	 * saved, so the refusals above are not passing because the route does not work.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_lets_an_administrator_save_a_setting(): void {

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$response = $this->_save( 'toolbar', 'no' );
		$stored   = $this->_get_stored_settings();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no', $stored['toolbar'] ?? '' );

	}

	/**
	 * Method to ask for the theme list to be read off the disk again.
	 *
	 * @return \WP_REST_Response
	 */
	protected function _refresh_themes() {

		return rest_do_request( new WP_REST_Request( 'POST', self::_THEMES_ROUTE ) );

	}

	/**
	 * The theme refresh is refused to a stranger and to a subscriber. It deletes an
	 * option and reads the disk, so it is behind the same capability as the settings.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_refuses_the_theme_refresh_to_anybody_who_may_not_change_settings(): void {

		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->_refresh_themes()->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertSame( 403, $this->_refresh_themes()->get_status() );

	}

	/**
	 * An administrator gets the rebuilt list back, and not merely a "done". A list
	 * is planted in the cache first, and its absence from the answer is what makes
	 * this a test of the rebuild rather than of the cache.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_answers_the_theme_refresh_with_the_rebuilt_list(): void {

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->_plant_cached_themes( [ 'prism-not-a-theme' => 'Planted' ] );

		$response = $this->_refresh_themes();
		$payload  = $response->get_data();

		$this->assertSame( 200, $response->get_status() );

		$this->assertArrayNotHasKey(
			'prism-not-a-theme',
			(array) ( $payload['choices'] ?? [] ),
			'The planted list survived, so the route answered the cache instead of rereading the disk.'
		);

		$this->assertSame(
			Admin::get_theme_choices(),
			$payload['choices'] ?? null,
			'The answer carries the choices the dropdown is drawn from.'
		);

		$this->assertSame(
			Admin::get_theme_urls(),
			$payload['urls'] ?? null,
			'And the stylesheet URLs, so the preview can paint a theme which has just appeared.'
		);

	}

} // end of class

// EOF
