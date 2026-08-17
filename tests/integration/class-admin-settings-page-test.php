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
	 * Scripts the preview code box needs.
	 *
	 * @var array
	 */
	const PREVIEW_SCRIPTS = [
		'ig-syntax-hiliter-engine',
		'ig-syntax-hiliter-autoloader',
		'ig-syntax-hiliter-toolbar',
		'ig-syntax-hiliter-show-language',
		'ig-syntax-hiliter-copy-to-clipboard',
		'ig-syntax-hiliter-line-numbers',
		'ig-syntax-hiliter-setup',
	];

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
	 * "None" heads the dropdown and every theme under it is in order by name.
	 *
	 * There are more than forty themes on that list. The registry hands them over
	 * grouped by the directory they were vendored into, which is a fact about this
	 * plugin's file layout and not one a site owner can be expected to know, so the
	 * screen sorts them. "None" is not a theme at all and goes on top rather than at
	 * the end of a list it is not part of.
	 *
	 * @return void
	 */
	public function test_the_theme_dropdown_puts_none_first_and_sorts_the_rest(): void {

		$choices = Admin::get_theme_choices();
		$slugs   = array_keys( $choices );

		$this->assertSame( Asset_Manager::THEME_NONE, $slugs[0] ?? '', 'The "no theme" choice is not at the top of the dropdown.' );

		// Nothing was dropped on the way through the sort.
		$this->assertCount( count( Asset_Manager::get_themes() ) + 1, $choices );

		$titles = array_values( $choices );

		array_shift( $titles );    //"None" is placed rather than sorted, so it is not part of what is asserted below

		$sorted = $titles;

		usort( $sorted, 'strnatcasecmp' );

		$this->assertSame( $sorted, $titles, 'The themes are not in order by name.' );

	}

	/**
	 * The screen carries a preview code box, with its code escaped.
	 *
	 * The box is what makes a list of 43 themes usable: it is rendered by the
	 * plugin's own renderer, so it is the same markup a reader gets, and the sample
	 * is source code which has to arrive as text rather than as markup.
	 *
	 * @return void
	 */
	public function test_the_screen_shows_a_preview_code_box(): void {

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$html = $this->_render();

		$this->assertStringContainsString( 'igsh-preview', $html );
		$this->assertStringContainsString( 'id="igsh-preview-box"', $html );

		$this->assertMatchesRegularExpression(
			'#<pre[^>]*class="[^"]*language-php#',
			$html,
			'The preview box is not a code box in the preview language.'
		);

		$this->assertStringContainsString( '&lt;?php', $html, 'The preview code reached the page as markup rather than as text.' );
		$this->assertStringNotContainsString( '<code class="language-php"><?php', $html );

	}

	/**
	 * The preview snippet's lines are short enough to fit the column.
	 *
	 * The themes ask for type sizes half again apart — 18px at one end and about
	 * 11.7px at the other — so a line which fits beside the settings in one theme
	 * runs off the edge in another. The box scrolls, so a long line costs no more
	 * than a scrollbar, but a sample somebody has to drag sideways to read is a poor
	 * way of showing them a colour scheme. Nothing else says the width matters, so
	 * this does.
	 *
	 * @return void
	 */
	public function test_the_preview_snippet_stays_inside_the_column(): void {

		$markup = Admin::get_preview_markup();
		$code   = html_entity_decode( wp_strip_all_tags( $markup ), ENT_QUOTES, 'UTF-8' );

		foreach ( explode( "\n", $code ) as $line ) {

			// A tab is drawn as four columns: Prism's themes all set `tab-size: 4`.
			$width = mb_strlen( str_replace( "\t", '    ', $line ) );

			$this->assertLessThanOrEqual(
				Admin::PREVIEW_LINE_LENGTH,
				$width,
				sprintf( 'The preview snippet has a line of %1$d columns: %2$s', $width, trim( $line ) )
			);

		}

	}

	/**
	 * Every theme the dropdown offers has a stylesheet the preview can load.
	 *
	 * The preview repaints by pointing a `link` tag at another stylesheet, so it is
	 * handed the URL of each one. This is what fails the day a theme is added to the
	 * registry and that list is not — which would show a site owner a theme that does
	 * nothing when they pick it.
	 *
	 * @return void
	 */
	public function test_every_offered_theme_has_a_stylesheet_for_the_preview(): void {

		$urls = Admin::get_theme_urls();

		$this->assertSame( array_keys( Admin::get_theme_choices() ), array_keys( $urls ) );

		foreach ( $urls as $slug => $url ) {

			if ( Asset_Manager::THEME_NONE === $slug ) {
				$this->assertSame( '', $url, '"None" means no stylesheet, so it names none.' );

				continue;
			}

			$this->assertStringEndsWith( sprintf( '/%s.min.css', $slug ), $url, sprintf( 'The %s theme has no stylesheet for the preview.', $slug ) );

		}

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

		foreach ( self::PREVIEW_SCRIPTS as $handle ) {
			$this->assertFalse( wp_script_is( $handle, 'enqueued' ), sprintf( 'The preview\'s %s loaded on somebody else\'s admin page.', $handle ) );
		}

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

		/*
		 * The preview box needs the engine and every plugin that changes how a code box
		 * looks, whatever the settings currently say — the reader can switch any of them
		 * while looking at it, and nothing can be fetched at that moment.
		 */
		foreach ( self::PREVIEW_SCRIPTS as $handle ) {
			$this->assertTrue( wp_script_is( $handle, 'enqueued' ), sprintf( 'The preview did not load %s.', $handle ) );
		}

		$this->assertTrue( wp_style_is( 'ig-syntax-hiliter-theme', 'enqueued' ), 'The preview loaded no theme stylesheet.' );
		$this->assertTrue( wp_style_is( 'ig-syntax-hiliter-chrome', 'enqueued' ) );

		$this->assertSame(
			'ig-syntax-hiliter-theme-css',
			Asset_Manager::get_theme_style_id(),
			'The script is told which tag to repaint, and that is the tag WordPress printed.'
		);

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
