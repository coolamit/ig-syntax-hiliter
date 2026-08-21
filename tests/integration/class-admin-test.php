<?php
/**
 * Tests for the settings screen and the REST routes behind it.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Admin;
use iG\Syntax_Hiliter\Asset_Manager;
use iG\Syntax_Hiliter\Base;
use iG\Syntax_Hiliter\Fonts;
use iG\Syntax_Hiliter\Migrate;
use iG\Syntax_Hiliter\Option;
use iG\Syntax_Hiliter\Tests\Integration\Fixtures\Default_Settings;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Asset_Test_Helpers;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Hook_Test_Helpers;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use iG\Syntax_Hiliter\Themes;
use iG\Syntax_Hiliter\Validate;
use ReflectionProperty;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * `Admin` is the settings screen and the REST routes it saves through: the screen
 * renders the v6 settings and nothing else, the routes refuse anybody who may not
 * change settings, a dependent setting moves its partner, and every hook is registered.
 */
class Admin_Test extends WP_UnitTestCase {

	use Asset_Test_Helpers;
	use Hook_Test_Helpers;
	use Pipeline_Test_Helpers;

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
	 * Handle the shared transport and page lock script is registered under.
	 *
	 * @var string
	 */
	protected const string _API_HANDLE = 'ig-syntax-hiliter-admin-api';

	/**
	 * Handle the revert tool script is registered under.
	 *
	 * @var string
	 */
	protected const string _REVERT_HANDLE = 'ig-syntax-hiliter-revert';

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
	 * The option array a v5.1 install holds.
	 *
	 * Every value is the opposite of its v6 default, so a setting which fails to
	 * carry across cannot pass by coincidence.
	 *
	 * @var array
	 */
	protected const array _V5_OPTIONS = [
		'fe-styles'         => 'no',
		'strict_mode'       => 'always',
		'non_strict_mode'   => [ 'php' ],
		'toolbar'           => 'no',
		'plain_text'        => 'no',
		'show_line_numbers' => 'no',
		'hilite_comments'   => 'no',
		'link_to_manual'    => 'yes',
		'gist_in_comments'  => 'yes',
	];

	/**
	 * The options object as the plugin booted it.
	 *
	 * @var \iG\Syntax_Hiliter\Option|null
	 */
	protected ?Option $_original_option = null;

	/**
	 * The migration object as the plugin booted it.
	 *
	 * @var \iG\Syntax_Hiliter\Migrate|null
	 */
	protected ?Migrate $_original_migrate = null;

	/**
	 * Remembers the singletons to put back, and brings up a REST server with the
	 * plugin's routes on it.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		$this->_original_option  = Option::get_instance();
		$this->_original_migrate = Migrate::get_instance();

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
	 * Puts the singletons and the theme list back for whatever runs next.
	 *
	 * The settings object is reloaded after the rollback and not before it: the
	 * options object reads the stored array once, and the object `Admin` holds would
	 * otherwise report values whose row had been rolled away.
	 *
	 * @return void
	 */
	public function tear_down(): void {

		$this->_reset_theme_cache();

		$this->_set_singleton( Option::class, $this->_original_option );
		$this->_set_singleton( Migrate::class, $this->_original_migrate );

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
	 * Method to save one setting through the route, as an administrator.
	 *
	 * @param string $name  Setting to save.
	 * @param string $value Value to save it with.
	 *
	 * @return array What the route answered with.
	 */
	protected function _save_as_administrator( string $name, string $value ): array {

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
	 * The dependencies it declares are its own: the settings script asks for the
	 * notice stack and the api script, the revert script for the api script, and
	 * those two ask for nothing — four scripts of ours and no library.
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
		$this->assertFalse( wp_script_is( self::_API_HANDLE, 'enqueued' ), 'The api script loaded on somebody else\'s admin page.' );
		$this->assertFalse( wp_script_is( self::_REVERT_HANDLE, 'enqueued' ), 'The revert script loaded on somebody else\'s admin page.' );

		foreach ( self::_PREVIEW_SCRIPTS as $handle ) {
			$this->assertFalse( wp_script_is( $handle, 'enqueued' ), sprintf( 'The preview\'s %s loaded on somebody else\'s admin page.', $handle ) );
		}

		$admin->enqueue_assets( Admin::PAGE_HOOK );

		$this->assertTrue( wp_script_is( self::_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( self::_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_script_is( self::_NOTICES_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( self::_NOTICES_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_script_is( self::_API_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_script_is( self::_REVERT_HANDLE, 'enqueued' ) );

		$this->assertSame( [ self::_NOTICES_HANDLE, self::_API_HANDLE ], wp_scripts()->registered[ self::_HANDLE ]->deps );
		$this->assertSame( [ self::_NOTICES_HANDLE ], wp_styles()->registered[ self::_HANDLE ]->deps );

		// The revert tool shares the transport and the page lock, and nothing else.
		$this->assertSame( [ self::_API_HANDLE ], wp_scripts()->registered[ self::_REVERT_HANDLE ]->deps );

		// The api script and the notice stack know nothing about this screen, so they ask for nothing.
		$this->assertSame( [], wp_scripts()->registered[ self::_API_HANDLE ]->deps );
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
	 * Every one of the screen's compiled assets is where it is enqueued from.
	 *
	 * `assets/build/` is generated and git-ignored, so a path that
	 * has gone stale is a 404 in wp-admin and nothing else.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_enqueues_the_screen_assets_from_paths_which_exist(): void {

		$root = untrailingslashit( IG_SYNTAX_HILITER_ROOT );

		foreach ( [ 'css/admin.css', 'css/notices.css', 'js/admin-api.js', 'js/admin.js', 'js/notices.js', 'js/revert.js' ] as $asset ) {
			$this->assertFileExists(
				sprintf( '%s/assets/build/%s', $root, $asset ),
				sprintf( '`assets/build/%s` is enqueued but is not there. Run `make build`.', $asset )
			);
		}

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

		$answer = $this->_save_as_administrator( 'rainbow_braces', 'yes' );
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

		$answer = $this->_save_as_administrator( 'match_braces', 'no' );
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

			$answer = $this->_save_as_administrator( 'rainbow_braces', 'no' );
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

		$answer = $this->_save_as_administrator( 'match_braces', 'yes' );
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

		$answer = $this->_save_as_administrator( 'copy_code', 'yes' );
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

		$answer = $this->_save_as_administrator( 'toolbar', 'no' );
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

		$answer = $this->_save_as_administrator( 'rainbow_braces', 'yes' );
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

	/**
	 * The settings screen's five registrations.
	 *
	 * `plugin_action_links` is the plugin's only multi-argument registration:
	 * `get_action_links()` takes two parameters, so a registration asking for one
	 * fatals on every admin screen with the hook and the priority both correct.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_registers_the_admins_hooks(): void {

		$admin = Admin::get_instance();

		$this->_assert_hooked(
			'rest_api_init',
			[ $admin, 'register_rest_routes' ],
			null,
			'The settings routes exist, which is what the screen saves through.'
		);

		$this->_assert_hooked(
			'admin_menu',
			[ $admin, 'add_menu' ],
			null,
			'The settings page is reachable under Settings.'
		);

		$this->_assert_hooked(
			'admin_enqueue_scripts',
			[ $admin, 'enqueue_assets' ],
			null,
			'The screen gets its CSS and its JavaScript.'
		);

		$this->_assert_hooked(
			'admin_notices',
			[ $admin, 'maybe_show_migration_message' ],
			null,
			'A site which has just migrated is told so.'
		);

		$this->_assert_hooked(
			'plugin_action_links',
			[ $admin, 'get_action_links' ],
			10,
			'The Settings link appears beside the plugin on the plugins screen.'
		);

		$this->assertSame(
			2,
			$this->_hooked_accepted_args( 'plugin_action_links', [ $admin, 'get_action_links' ], 10 ),
			'get_action_links() takes the links and the plugin file, so registering for one argument fatals.'
		);

	}

	/**
	 * The site owner is told about the migration on the first admin page they open,
	 * whichever one it is, and told once.
	 *
	 * A notice printed on this plugin's settings page alone never reaches an owner who
	 * does not open that page, and reads as the migration happening only then.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_shows_the_migration_notice_on_whichever_admin_page_comes_first(): void {

		set_current_screen( 'dashboard' );

		update_option( Base::PLUGIN_ID . '-version', 5.1 );
		update_option( Base::PLUGIN_ID . '-options', static::_V5_OPTIONS );

		$this->_migrate();

		$this->assertSame( '5.1.0', get_option( Base::PLUGIN_ID . '-migrated-from' ) );

		ob_start();
		Admin::get_instance()->maybe_show_migration_message();
		$notice = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-success', $notice, 'The dashboard showed nothing.' );
		$this->assertStringContainsString( '5.1.0', $notice );

		// Shown once: the option it reads is deleted as it prints.
		ob_start();
		Admin::get_instance()->maybe_show_migration_message();
		$again = (string) ob_get_clean();

		$this->assertSame( '', $again, 'The notice printed a second time.' );
		$this->assertFalse( get_option( Base::PLUGIN_ID . '-migrated-from', false ) );

		set_current_screen( 'front' );

	}

	/**
	 * Method to run the migration against whatever is currently in the DB.
	 *
	 * Both singletons are dropped first, because the options object reads the DB
	 * once when it is built and the migration holds on to it.
	 *
	 * @return void
	 */
	protected function _migrate(): void {

		$this->_set_singleton( Option::class, null );
		$this->_set_singleton( Migrate::class, null );

		Migrate::get_instance()->settings();

	}

	/**
	 * The list is in order by name with "None" on the front, and nothing was lost
	 * in the sorting.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_puts_none_first_in_the_font_dropdown_and_sorts_the_rest(): void {

		$choices = Admin::get_font_choices();

		$this->assertSame( Fonts::FONT_NONE, array_key_first( $choices ) );
		$this->assertCount( count( Fonts::get_fonts() ) + 1, $choices );

		$names = array_values( $choices );

		array_shift( $names );

		$sorted = $names;

		usort( $sorted, 'strnatcasecmp' );

		$this->assertSame( $sorted, $names, 'The fonts are in order by name.' );

	}

	/**
	 * Every offered font is in exactly one group, and `None` is in neither.
	 *
	 * A font in neither group would not be offered and one in both would be offered
	 * twice; `choices` stays the flat allowlist, so nothing else would show either.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_puts_every_offered_font_in_exactly_one_group(): void {

		$groups = Admin::get_font_groups();

		$this->assertCount( 2, $groups, 'With Ligature and Without Ligature, and nothing else.' );

		$listed = array_merge( [], ...array_values( $groups ) );

		$this->assertNotContains( Fonts::FONT_NONE, $listed, 'None is not a font and sits above both groups.' );

		$this->assertSame(
			count( $listed ),
			count( array_unique( $listed ) ),
			'A font in both groups would be offered twice.'
		);

		$offered = array_keys( Fonts::get_fonts() );

		sort( $offered );
		sort( $listed );

		$this->assertSame( $offered, $listed, 'The groups between them hold exactly the fonts the plugin offers.' );

	}

	/**
	 * Each group is in order by name, the same order the flat list is in.
	 *
	 * This is what is left of the single flat sort once the list is grouped: the
	 * dropdown no longer reads as one sorted run, and each group has to earn that
	 * on its own.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_sorts_each_font_group_by_name(): void {

		$choices = Admin::get_font_choices();

		foreach ( Admin::get_font_groups() as $label => $slugs ) {

			$names = array_map(
				static fn ( string $slug ): string => $choices[ $slug ],
				$slugs
			);

			$this->assertNotEmpty( $names, sprintf( 'The %s group offers something.', $label ) );

			$sorted = $names;

			usort( $sorted, 'strnatcasecmp' );

			$this->assertSame( $sorted, $names, sprintf( 'The %s group is in order by name.', $label ) );

		}

	}

	/**
	 * The screen must not offer a value storage would refuse. `Admin` and `Validate`
	 * build their lists separately, and they are compared as sets because only the
	 * screen's order means anything.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_offers_exactly_what_can_be_stored_on_the_settings_screen(): void {

		$validate = Validate::get_instance();

		foreach ( Admin::get_settings_schema() as $name => $setting ) {

			$this->assertEqualsCanonicalizing(
				$validate->get_option_values( $name ),
				array_keys( $setting['choices'] ),
				sprintf( 'The %s setting offers a different set of values than it accepts.', $name )
			);

		}

	}

} // end of class

// EOF
