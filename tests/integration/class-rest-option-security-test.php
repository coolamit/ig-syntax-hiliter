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
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The settings route is the only way into the plugin's settings, so it is the only
 * thing standing between a stranger and the site's configuration.
 *
 * Every test here asserts the stored settings as well as the status code. A 403
 * that wrote anyway would be worse than no check at all, and a status code on its
 * own cannot tell the two apart.
 */
class Rest_Option_Security_Test extends WP_UnitTestCase {

	/**
	 * Route under test.
	 *
	 * @var string
	 */
	const ROUTE = '/' . Admin::REST_NAMESPACE . '/option';

	/**
	 * The theme refresh route, which is on the same permission callback.
	 *
	 * @var string
	 */
	const THEMES_ROUTE = '/' . Admin::REST_NAMESPACE . '/themes';

	/**
	 * Brings up a REST server with the plugin's routes on it.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		Admin::get_instance()->register_hooks();
		Block_Converter::get_instance()->register_hooks();

		/*
		 * The test case puts the hook registry back the way it found it after every
		 * test, and register_hooks() only ever runs once per process, so the actions
		 * are put back by hand when they have been taken away.
		 */
		foreach ( [ Admin::get_instance(), Block_Converter::get_instance() ] as $service ) {

			if ( false === has_action( 'rest_api_init', [ $service, 'register_rest_routes' ] ) ) {
				add_action( 'rest_api_init', [ $service, 'register_rest_routes' ] );
			}
		}

		$GLOBALS['wp_rest_server'] = null;  // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Forcing a fresh REST server so the routes above are registered on it. The global is core's, not this plugin's.

		rest_get_server();

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
	 * Method to send a well formed save request.
	 *
	 * The body is always valid, so that what the response reports is the access
	 * check and never a quarrel about the parameters.
	 *
	 * @param string $name  Setting name to send.
	 * @param string $value Value to send.
	 *
	 * @return \WP_REST_Response
	 */
	protected function _save( string $name, string $value ) {

		$request = new WP_REST_Request( 'POST', self::ROUTE );

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
	 * @return void
	 */
	public function test_a_logged_out_request_is_refused_and_saves_nothing(): void {

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
	 * @return void
	 */
	public function test_a_subscriber_request_is_refused_and_saves_nothing(): void {

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
	 * @return void
	 */
	public function test_an_option_this_plugin_does_not_own_is_refused(): void {

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
	 * @return void
	 */
	public function test_a_value_outside_the_schema_is_refused(): void {

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$before = $this->_get_stored_settings();

		$this->assertSame( 400, $this->_save( 'toolbar', 'perhaps' )->get_status() );
		$this->assertSame( 400, $this->_save( 'theme', 'no-such-theme' )->get_status() );

		$this->assertSame( $before, $this->_get_stored_settings() );

	}

	/**
	 * A setting name which is not a string is refused, and refused cleanly.
	 *
	 * Nothing is written either way, so this is about what the caller is told. A PHP
	 * warning is printed ahead of the response body on a site showing errors, which
	 * makes the body unparseable: the screen then reports a generic failure instead
	 * of saying what was wrong with the request.
	 *
	 * @return void
	 */
	public function test_a_setting_name_which_is_not_a_string_is_refused_without_a_php_diagnostic(): void {

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

			$request = new WP_REST_Request( 'POST', self::ROUTE );

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
	 * The control for everything above: an administrator sending a valid request does
	 * get the setting saved. Without this, every refusal here could be passing
	 * because the route does not work at all.
	 *
	 * @return void
	 */
	public function test_an_administrator_saves_a_setting(): void {

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

		return rest_do_request( new WP_REST_Request( 'POST', self::THEMES_ROUTE ) );

	}

	/**
	 * The theme refresh is refused to a stranger and to a subscriber.
	 *
	 * It deletes an option and reads the disk, so it is a write and is behind the
	 * same capability as the settings themselves. The two refusals are told apart
	 * because a caller which is merely logged out has something to do about it.
	 *
	 * @return void
	 */
	public function test_the_theme_refresh_is_refused_to_anybody_who_may_not_change_settings(): void {

		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->_refresh_themes()->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertSame( 403, $this->_refresh_themes()->get_status() );

	}

	/**
	 * An administrator gets the rebuilt list back, and not merely a "done".
	 *
	 * The button exists for the case where what is on disk is not what was cached,
	 * so an answer which does not carry the list cannot tell a site owner whether
	 * the theme they went looking for is there now.
	 *
	 * @return void
	 */
	public function test_the_theme_refresh_answers_with_the_rebuilt_list(): void {

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$response = $this->_refresh_themes();
		$payload  = $response->get_data();

		$this->assertSame( 200, $response->get_status() );

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

}    //end of class


//EOF
