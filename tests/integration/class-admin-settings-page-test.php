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
use iG\Syntax_Hiliter\Fonts;
use iG\Syntax_Hiliter\Option;
use iG\Syntax_Hiliter\Themes;
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
	protected const string _HANDLE = 'ig-syntax-hiliter-admin';

	/**
	 * Handle the notice stack is registered under.
	 *
	 * @var string
	 */
	protected const string _NOTICES_HANDLE = 'ig-syntax-hiliter-notices';

	/**
	 * Scripts the preview code box needs.
	 *
	 * @var array
	 */
	protected const array _PREVIEW_SCRIPTS = [
		'ig-syntax-hiliter-engine',
		'ig-syntax-hiliter-autoloader',
		'ig-syntax-hiliter-toolbar',
		'ig-syntax-hiliter-show-language',
		'ig-syntax-hiliter-copy-to-clipboard',
		'ig-syntax-hiliter-line-numbers',
		'ig-syntax-hiliter-line-highlight',
		'ig-syntax-hiliter-match-braces',
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
	 * @test
	 *
	 * @return void
	 */
	public function it_renders_the_settings_page_for_an_administrator(): void {

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
	 * @test
	 *
	 * @return void
	 */
	public function it_shows_the_v6_settings_and_not_the_v5_ones(): void {

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$html = $this->_render();

		foreach ( [ 'fe-styles', 'strict_mode', 'non_strict_mode', 'plain_text', 'link_to_manual', 'igsh_refresh_languages' ] as $gone ) {
			$this->assertStringNotContainsString( $gone, $html, sprintf( 'The removed %s setting is still on the screen.', $gone ) );
		}

		// Neither the whitespace setting nor the language directory exists: the first did nothing visible, and every language Prism has is shipped.
		$this->assertStringNotContainsString( 'normalize_whitespace', $html );
		$this->assertStringNotContainsString( 'igsyntax-hiliter/components', $html );

		// The revert tool, the way out of the block format, is offered on this screen.
		$this->assertStringContainsString( 'igsh-revert-blocks', $html );

	}

	/**
	 * The theme dropdown offers Okaidia as the default, and names the Prism theme
	 * after itself.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_offers_the_bundled_themes_in_the_theme_dropdown(): void {

		$choices = Admin::get_theme_choices();

		$this->assertArrayHasKey( Themes::DEFAULT_THEME, $choices, 'The default theme is one the screen offers.' );
		$this->assertSame( 'Prism', $choices['prism'] ?? '', 'The Prism theme is named after itself, not after being the default.' );

	}

	/**
	 * "None" heads the dropdown and every theme under it is in order by name.
	 *
	 * The registry hands the themes over grouped by the directory they were vendored
	 * into, so the screen sorts them. "None" is not a theme and is placed on top.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_puts_none_first_in_the_theme_dropdown_and_sorts_the_rest(): void {

		$choices = Admin::get_theme_choices();
		$slugs   = array_keys( $choices );

		$this->assertSame( Themes::THEME_NONE, $slugs[0] ?? '', 'The "no theme" choice is not at the top of the dropdown.' );

		// Nothing was dropped on the way through the sort.
		$this->assertCount( count( Themes::get_themes() ) + 1, $choices );

		$titles = array_values( $choices );

		array_shift( $titles );    // "None" is placed rather than sorted, so it is not part of what is asserted below

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
	 * @test
	 *
	 * @return void
	 */
	public function it_shows_a_preview_code_box(): void {

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
	 * The preview snippet has lines highlighted in it.
	 *
	 * Line highlighting is decided per snippet and has no control on the screen, so the
	 * preview is the only place a site owner sees it. The literal covers a run and a
	 * single line, both halves of the grammar.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_highlights_lines_in_the_preview_snippet(): void {

		$this->assertStringContainsString(
			'data-line="15-19,23"',
			Admin::get_preview_markup(),
			'The preview box asks for no line highlighting, so a reader never sees any.'
		);

	}

	/**
	 * The preview snippet shows a reader what the four ligature fonts do.
	 *
	 * Fifteen fonts are offered and four of them draw `=>`, `&&` and `===` as single
	 * glyphs. A sample carrying none of those sequences would make the whole point of
	 * picking one of those four invisible in the one place it is meant to be seen.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_shows_what_a_ligature_font_does_in_the_preview_snippet(): void {

		$markup = Admin::get_preview_markup();
		$code   = html_entity_decode( wp_strip_all_tags( $markup ), ENT_QUOTES, 'UTF-8' );

		foreach ( [ '=>', '&&', '===', '->' ] as $sequence ) {

			$this->assertStringContainsString(
				$sequence,
				$code,
				sprintf( 'The preview snippet has no %s in it for a ligature to show up on.', $sequence )
			);

		}

	}

	/**
	 * The font control is on the screen, and it is under the theme control.
	 *
	 * The order is the schema's, and it is the schema the template walks.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_puts_the_font_control_below_the_theme_control(): void {

		$names = array_keys( Admin::get_settings_schema() );

		$this->assertSame( [ 'theme', 'font' ], array_slice( $names, 0, 2 ) );

		$html = $this->_render();

		$this->assertStringContainsString( 'data-igsh-option="font"', $html );

		$this->assertLessThan(
			strpos( $html, 'data-igsh-option="font"' ),
			strpos( $html, 'data-igsh-option="theme"' ),
			'The font control is drawn after the theme control.'
		);

	}

	/**
	 * The font dropdown is drawn in two labelled groups with `None` above both.
	 *
	 * The grouping is carried on the schema entry beside `choices`, and the template is
	 * the only thing turning it into markup. The theme dropdown carries no groups and
	 * must stay flat, so that half is asserted too.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_draws_the_font_dropdown_in_two_groups(): void {

		$html = $this->_render();

		$groups = Admin::get_font_groups();

		$this->assertCount( 2, $groups );

		$positions = [];

		foreach ( array_keys( $groups ) as $label ) {

			$needle = sprintf( '<optgroup label="%s">', esc_attr( $label ) );

			$this->assertStringContainsString( $needle, $html, sprintf( 'The %s group is drawn.', $label ) );

			$positions[] = strpos( $html, $needle );

		}

		$this->assertSame( 2, substr_count( $html, '<optgroup' ) );
		$this->assertSame( 2, substr_count( $html, '</optgroup>' ) );

		// None is not a font, so it is drawn ahead of the first group rather than inside one.
		$none = strpos( $html, sprintf( '<option value="%s"', esc_attr( Fonts::FONT_NONE ) ) );

		$this->assertNotFalse( $none );
		$this->assertLessThan( min( $positions ), $none, 'None sits above both groups.' );

		// The theme control carries no groups, and the same template branch has to leave it alone.
		$theme = strpos( $html, 'data-igsh-option="theme"' );
		$font  = strpos( $html, 'data-igsh-option="font"' );

		$this->assertGreaterThan( $theme, min( $positions ), 'Nothing groups the theme dropdown.' );
		$this->assertGreaterThan( $font, min( $positions ) );

	}

	/**
	 * The screen says where a font comes from, because it comes from another host.
	 *
	 * Every other asset this plugin loads is one it ships. A site owner switching
	 * this on is adding a third party request to every page carrying code, and they
	 * should not have to read the source to find that out.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_says_a_font_is_fetched_from_another_host(): void {

		$schema = Admin::get_settings_schema();

		$this->assertStringContainsString( 'fonts.bunny.net', $schema['font']['description'] );

		$this->assertStringContainsString(
			'fonts.bunny.net',
			$this->_render(),
			'The description reaches the page a site owner reads.'
		);

	}

	/**
	 * Every theme the dropdown offers has a stylesheet the preview can load.
	 *
	 * The preview repaints by pointing a `link` tag at another stylesheet, so it is
	 * handed the URL of each one. This is what fails the day a theme is added to the
	 * registry and that list is not — which would show a site owner a theme that does
	 * nothing when they pick it.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_gives_every_offered_theme_a_stylesheet_for_the_preview(): void {

		$urls = Admin::get_theme_urls();

		$this->assertSame( array_keys( Admin::get_theme_choices() ), array_keys( $urls ) );

		foreach ( $urls as $slug => $url ) {

			if ( Themes::THEME_NONE === $slug ) {
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
	 * The admin stylesheet styles `input[type="checkbox"]` at a higher specificity than
	 * a class of this plugin's, which shrinks the hit area to a square in the corner.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_draws_a_toggle_as_a_button_and_not_a_checkbox(): void {

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
	 * Drawing the first choice instead would show every toggle as on while the value
	 * behaves as off, and a control which looks right is one nobody puts right.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_draws_the_setting_default_for_a_stored_value_outside_the_schema(): void {

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$option   = Option::get_instance();
		$property = new ReflectionProperty( Option::class, '_options' );
		$before   = $property->getValue( $option );

		$property->setValue(
			$option,
			array_merge(
				(array) $before,
				[
					'gist_in_comments' => 'perhaps',    // This setting is off by default.
					'hilite_comments'  => 'perhaps',    // And this one is on by default.
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
	 * The one dependency it declares is this plugin's own notice stack, which itself
	 * depends on nothing: two scripts of ours and no library.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_asks_for_no_jquery(): void {

		$admin = Admin::get_instance();

		$admin->enqueue_assets( 'options-writing.php' );

		$this->assertFalse( wp_script_is( self::_HANDLE, 'enqueued' ), 'The settings assets loaded on somebody else\'s admin page.' );
		$this->assertFalse( wp_script_is( self::_NOTICES_HANDLE, 'enqueued' ), 'The notice stack loaded on somebody else\'s admin page.' );

		foreach ( self::_PREVIEW_SCRIPTS as $handle ) {
			$this->assertFalse( wp_script_is( $handle, 'enqueued' ), sprintf( 'The preview\'s %s loaded on somebody else\'s admin page.', $handle ) );
		}

		$admin->enqueue_assets( Admin::PAGE_HOOK );

		$this->assertTrue( wp_script_is( self::_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( self::_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_script_is( self::_NOTICES_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( self::_NOTICES_HANDLE, 'enqueued' ) );

		$this->assertSame( [ self::_NOTICES_HANDLE ], wp_scripts()->registered[ self::_HANDLE ]->deps );
		$this->assertSame( [ self::_NOTICES_HANDLE ], wp_styles()->registered[ self::_HANDLE ]->deps );

		// The notice stack knows nothing about this screen, so it asks for nothing.
		$this->assertSame( [], wp_scripts()->registered[ self::_NOTICES_HANDLE ]->deps );
		$this->assertSame( [], wp_styles()->registered[ self::_NOTICES_HANDLE ]->deps );

		$this->assertFalse( wp_script_is( 'jquery', 'enqueued' ) );

		// The preview loads every engine plugin whatever the settings say: nothing can be fetched once the reader switches one.
		foreach ( self::_PREVIEW_SCRIPTS as $handle ) {
			$this->assertTrue( wp_script_is( $handle, 'enqueued' ), sprintf( 'The preview did not load %s.', $handle ) );
		}

		$this->assertTrue( wp_style_is( 'ig-syntax-hiliter-theme', 'enqueued' ), 'The preview loaded no theme stylesheet.' );
		$this->assertTrue( wp_style_is( 'ig-syntax-hiliter-chrome', 'enqueued' ) );

		// The band over a highlighted line is painted entirely by this stylesheet, so the script alone would pass while showing nothing.
		$this->assertTrue(
			wp_style_is( 'ig-syntax-hiliter-line-highlight', 'enqueued' ),
			'The preview loaded the line highlight script with no stylesheet to paint the band.'
		);

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
	 * has gone stale is a 404 in wp-admin and nothing else.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_enqueues_the_screen_assets_from_paths_which_exist(): void {

		$root = untrailingslashit( IG_SYNTAX_HILITER_ROOT );

		foreach ( [ 'css/admin.css', 'css/notices.css', 'js/admin.js', 'js/notices.js' ] as $asset ) {
			$this->assertFileExists(
				sprintf( '%s/assets/build/%s', $root, $asset ),
				sprintf( '`assets/build/%s` is enqueued but is not there. Run `make build`.', $asset )
			);
		}

	}

} // end of class

// EOF
