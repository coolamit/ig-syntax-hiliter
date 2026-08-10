<?php
/**
 * Tests for the settings screen itself.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Admin;
use iG\Syntax_Hiliter\Language_Registry;
use iG\Syntax_Hiliter\Option;
use ReflectionProperty;
use WP_UnitTestCase;

/**
 * The settings screen renders, shows the v6 settings and nothing else, and asks the
 * browser for no jQuery.
 *
 * Rendering it at all is the point of the first test: a settings screen that fatals
 * inside wp-admin is invisible until an administrator opens it.
 */
class Admin_Settings_Page_Test extends WP_UnitTestCase {

	/**
	 * Handle the settings page assets are registered under.
	 *
	 * @var string
	 */
	const HANDLE = 'ig-syntax-hiliter-admin';

	/**
	 * Method to render the settings page and capture what it printed.
	 *
	 * @return string
	 */
	protected function _render(): string {

		ob_start();

		try {
			Admin::get_instance()->render_page();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;

	}

	/**
	 * The screen renders for an administrator, raising no PHP diagnostic and printing
	 * a control for every setting the plugin has.
	 *
	 * @return void
	 */
	public function test_the_settings_page_renders_for_an_administrator(): void {

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$diagnostics = [];

		set_error_handler(  // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Catching PHP diagnostics is what this test is for.
			static function ( $errno, $errstr, $errfile, $errline ) use ( &$diagnostics ) {
				$diagnostics[] = sprintf( '%d: %s in %s on line %d', $errno, $errstr, $errfile, $errline );

				return true;
			}
		);

		try {
			$html = $this->_render();
		} finally {
			restore_error_handler();
		}

		$this->assertSame( [], $diagnostics, 'Rendering the settings screen must raise no notices, warnings or deprecations.' );
		$this->assertStringContainsString( 'igsh-settings', $html );

		foreach ( array_keys( Admin::get_settings_schema() ) as $name ) {
			$this->assertStringContainsString(
				sprintf( 'data-igsh-option="%s"', $name ),
				$html,
				sprintf( 'The %s setting has no control on the page.', $name )
			);
		}

	}

	/**
	 * The settings GeSHi took with it are gone from the screen, and the drop-in
	 * language directory and the revert tool are on it.
	 *
	 * @return void
	 */
	public function test_the_screen_shows_the_v6_settings_and_not_the_v5_ones(): void {

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$html = $this->_render();

		foreach ( [ 'fe-styles', 'strict_mode', 'non_strict_mode', 'plain_text', 'link_to_manual', 'igsh_refresh_languages' ] as $gone ) {
			$this->assertStringNotContainsString( $gone, $html, sprintf( 'The removed %s setting is still on the screen.', $gone ) );
		}

		// The screen states where a site puts its own language files.
		$this->assertStringContainsString( Language_Registry::DROPIN_DIR, $html );
		$this->assertStringEndsWith( Language_Registry::DROPIN_DIR . '/', Admin::get_dropin_display_path() );

		// The revert tool, the way out of the block format, is offered on this screen.
		$this->assertStringContainsString( 'igsh-revert-blocks', $html );

	}

	/**
	 * Method to pull one setting's control out of the rendered page.
	 *
	 * @param string $html Markup the page printed.
	 * @param string $name Setting whose control is wanted.
	 *
	 * @return string The opening tag of the control, or an empty string when there is no such control.
	 */
	protected function _get_control( string $html, string $name ): string {

		$found = preg_match(
			sprintf( '#<input\b[^>]*data-igsh-option="%s"[^>]*>#s', preg_quote( $name, '#' ) ),
			$html,
			$matches
		);

		return ( 1 === $found ) ? $matches[0] : '';

	}

	/**
	 * A stored value the setting does not offer draws the control at that setting's
	 * own default.
	 *
	 * It used to draw the first choice, which for every toggle on this screen is
	 * "yes". Every reader of a yes/no setting compares it against `yes`, so an
	 * unrecognised value behaves as off — and the screen said on. A control which
	 * already looks right is one nobody puts right.
	 *
	 * @return void
	 */
	public function test_a_stored_value_outside_the_schema_draws_the_setting_default(): void {

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$option   = Option::get_instance();
		$property = new ReflectionProperty( Option::class, '_options' );
		$before   = $property->getValue( $option );

		$property->setValue(
			$option,
			array_merge(
				(array) $before,
				[
					'normalize_whitespace' => 'perhaps',    //this setting is off by default
					'hilite_comments'      => 'perhaps',    //and this one is on by default
				]
			)
		);

		try {
			$html = $this->_render();
		} finally {
			$property->setValue( $option, $before );
		}

		$off = $this->_get_control( $html, 'normalize_whitespace' );
		$on  = $this->_get_control( $html, 'hilite_comments' );

		$this->assertNotSame( '', $off, 'The normalize_whitespace control is not on the page at all.' );
		$this->assertNotSame( '', $on, 'The hilite_comments control is not on the page at all.' );

		$this->assertStringNotContainsString( 'checked', $off, 'A setting which is off by default was drawn as on.' );
		$this->assertStringContainsString( 'checked', $on );

	}

	/**
	 * The settings screen carries no jQuery dependency of its own, and its assets
	 * load on that screen and nowhere else.
	 *
	 * @return void
	 */
	public function test_the_screen_asks_for_no_jquery(): void {

		$admin = Admin::get_instance();

		$admin->enqueue_assets( 'options-writing.php' );

		$this->assertFalse( wp_script_is( self::HANDLE, 'enqueued' ), 'The settings assets loaded on somebody else\'s admin page.' );

		$admin->enqueue_assets( Admin::PAGE_HOOK );

		$this->assertTrue( wp_script_is( self::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( self::HANDLE, 'enqueued' ) );

		$this->assertSame( [], wp_scripts()->registered[ self::HANDLE ]->deps );
		$this->assertFalse( wp_script_is( 'jquery', 'enqueued' ) );

	}

}    //end of class


//EOF
