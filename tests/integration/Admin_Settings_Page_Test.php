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
use WP_UnitTestCase;

/**
 * The settings screen renders, shows the v6 settings and nothing else, and asks the
 * browser for no jQuery.
 *
 * Rendering it at all is the point of the first test: the screen was switched off
 * for the whole of M1 and M2 because the v5 class called methods that had been
 * deleted with the GeSHi language scan, and loading it would have fataled inside
 * wp-admin.
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
	 * The settings GeSHi took with it are gone from the screen, and the two things
	 * M4 added to it are on it.
	 *
	 * @return void
	 */
	public function test_the_screen_shows_the_v6_settings_and_not_the_v5_ones(): void {

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$html = $this->_render();

		foreach ( [ 'fe-styles', 'strict_mode', 'non_strict_mode', 'plain_text', 'link_to_manual', 'igsh_refresh_languages' ] as $gone ) {
			$this->assertStringNotContainsString( $gone, $html, sprintf( 'The removed %s setting is still on the screen.', $gone ) );
		}

		// FR-6.3 — the screen states where a site puts its own language files.
		$this->assertStringContainsString( Language_Registry::DROPIN_DIR, $html );
		$this->assertStringEndsWith( Language_Registry::DROPIN_DIR . '/', Admin::get_dropin_display_path() );

		// Decision 20 — the way out of the block format is offered here.
		$this->assertStringContainsString( 'igsh-revert-blocks', $html );

	}

	/**
	 * AC-13 — the settings screen carries no jQuery dependency of its own, and its
	 * assets load on that screen and nowhere else.
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
