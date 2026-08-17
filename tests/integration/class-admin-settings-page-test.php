<?php
/**
 * Tests for the settings screen itself.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Admin;
use iG\Syntax_Hiliter\Asset_Manager;
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
	 * Handle the notice stack is registered under.
	 *
	 * @var string
	 */
	const NOTICES_HANDLE = 'ig-syntax-hiliter-notices';

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
	 * The settings GeSHi took with it are gone from the screen, and so are the two
	 * this version dropped. The revert tool is on it.
	 *
	 * @return void
	 */
	public function test_the_screen_shows_the_v6_settings_and_not_the_v5_ones(): void {

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$html = $this->_render();

		foreach ( [ 'fe-styles', 'strict_mode', 'non_strict_mode', 'plain_text', 'link_to_manual', 'igsh_refresh_languages' ] as $gone ) {
			$this->assertStringNotContainsString( $gone, $html, sprintf( 'The removed %s setting is still on the screen.', $gone ) );
		}

		// Dropped in 6.0: the whitespace setting did nothing visible, and every language Prism has is now shipped.
		$this->assertStringNotContainsString( 'normalize_whitespace', $html );
		$this->assertStringNotContainsString( 'igsyntax-hiliter/components', $html );

		// The revert tool, the way out of the block format, is offered on this screen.
		$this->assertStringContainsString( 'igsh-revert-blocks', $html );

	}

	/**
	 * The theme dropdown offers Okaidia as the default, and names the Prism theme
	 * after itself.
	 *
	 * @return void
	 */
	public function test_the_theme_dropdown_offers_the_bundled_themes(): void {

		$choices = Admin::get_theme_choices();

		$this->assertArrayHasKey( Asset_Manager::DEFAULT_THEME, $choices, 'The default theme is one the screen offers.' );
		$this->assertSame( 'prism-okaidia', Asset_Manager::DEFAULT_THEME );
		$this->assertSame( 'Prism', $choices['prism'] ?? '', 'The Prism theme is named after itself, not after being the default.' );

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
			sprintf( '#<(?:button|select)\b[^>]*data-igsh-option="%s"[^>]*>#s', preg_quote( $name, '#' ) ),
			$html,
			$matches
		);

		return ( 1 === $found ) ? $matches[0] : '';

	}

	/**
	 * A toggle is a button the whole of which can be clicked, not a checkbox.
	 *
	 * The admin stylesheet styles `input[type="checkbox"]` at a higher specificity
	 * than a class of this plugin's, which put the real hit area back to a square
	 * in the corner of the switch: every toggle on this screen could only be
	 * operated through its label, and no test tier saw it for fifteen sessions.
	 *
	 * @return void
	 */
	public function test_a_toggle_is_a_button_and_not_a_checkbox(): void {

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$html    = $this->_render();
		$control = $this->_get_control( $html, 'toolbar' );

		$this->assertStringStartsWith( '<button', $control, 'The toggle is not a button.' );
		$this->assertStringContainsString( 'type="button"', $control );
		$this->assertStringContainsString( 'role="switch"', $control );
		$this->assertStringContainsString( 'aria-checked=', $control );

		$this->assertStringNotContainsString( 'type="checkbox"', $html, 'A checkbox is still being drawn for a setting.' );

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
					'gist_in_comments' => 'perhaps',    //this setting is off by default
					'hilite_comments'  => 'perhaps',    //and this one is on by default
				]
			)
		);

		try {
			$html = $this->_render();
		} finally {
			$property->setValue( $option, $before );
		}

		$off = $this->_get_control( $html, 'gist_in_comments' );
		$on  = $this->_get_control( $html, 'hilite_comments' );

		$this->assertNotSame( '', $off, 'The gist_in_comments control is not on the page at all.' );
		$this->assertNotSame( '', $on, 'The hilite_comments control is not on the page at all.' );

		$this->assertStringContainsString( 'aria-checked="false"', $off, 'A setting which is off by default was drawn as on.' );
		$this->assertStringContainsString( 'aria-checked="true"', $on );

	}

	/**
	 * The settings screen carries no jQuery dependency, and its assets load on
	 * that screen and nowhere else.
	 *
	 * The one dependency it does declare is this plugin's own notice stack, which
	 * itself depends on nothing. That is the whole of the chain: two scripts of
	 * ours and no library, which is what keeps this page cheap. Nothing else may
	 * be added to either list without a reason good enough to write down here.
	 *
	 * @return void
	 */
	public function test_the_screen_asks_for_no_jquery(): void {

		$admin = Admin::get_instance();

		$admin->enqueue_assets( 'options-writing.php' );

		$this->assertFalse( wp_script_is( self::HANDLE, 'enqueued' ), 'The settings assets loaded on somebody else\'s admin page.' );
		$this->assertFalse( wp_script_is( self::NOTICES_HANDLE, 'enqueued' ), 'The notice stack loaded on somebody else\'s admin page.' );

		$admin->enqueue_assets( Admin::PAGE_HOOK );

		$this->assertTrue( wp_script_is( self::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( self::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_script_is( self::NOTICES_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( self::NOTICES_HANDLE, 'enqueued' ) );

		$this->assertSame( [ self::NOTICES_HANDLE ], wp_scripts()->registered[ self::HANDLE ]->deps );
		$this->assertSame( [ self::NOTICES_HANDLE ], wp_styles()->registered[ self::HANDLE ]->deps );

		//the notice stack knows nothing about this screen, so it asks for nothing
		$this->assertSame( [], wp_scripts()->registered[ self::NOTICES_HANDLE ]->deps );
		$this->assertSame( [], wp_styles()->registered[ self::NOTICES_HANDLE ]->deps );

		$this->assertFalse( wp_script_is( 'jquery', 'enqueued' ) );

	}

	/**
	 * Both of the screen's compiled assets are where they are enqueued from.
	 *
	 * `build/` and `assets/build/` are generated and git-ignored, so a path that
	 * has gone stale is a 404 in wp-admin and nothing else — no PHP notice, no
	 * failing request, just a settings page that quietly stops working.
	 *
	 * @return void
	 */
	public function test_the_screen_assets_exist_where_they_are_enqueued_from(): void {

		$root = untrailingslashit( IG_SYNTAX_HILITER_ROOT );

		foreach ( [ 'css/admin.css', 'css/notices.css', 'js/admin.js', 'js/notices.js' ] as $asset ) {
			$this->assertFileExists(
				sprintf( '%s/assets/build/%s', $root, $asset ),
				sprintf( '`assets/build/%s` is enqueued but is not there. Run `make build`.', $asset )
			);
		}

	}

}    //end of class


//EOF
