<?php
/**
 * The fonts the plugin offers, and the promise that choosing none costs nothing.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Admin;
use iG\Syntax_Hiliter\Asset_Manager;
use iG\Syntax_Hiliter\Option;
use iG\Syntax_Hiliter\Renderer;
use iG\Syntax_Hiliter\Shortcode_Handler;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Asset_Test_Helpers;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * Fonts are fetched from another host, which no other asset this plugin loads is.
 * That is what these cases are about: what a page asks for, and of whom.
 */
class Font_Library_Test extends WP_UnitTestCase {

	use Pipeline_Test_Helpers;

	use Asset_Test_Helpers;

	/**
	 * Host every webfont is fetched from, and the only host this plugin may reach.
	 *
	 * @var string
	 */
	const FONT_HOST = 'fonts.bunny.net';

	/**
	 * The fonts whose family really does carry code ligatures.
	 *
	 * Read out of each family's `GSUB` table — `liga` and `calt` lookups — from the
	 * files the service actually serves, and not from anybody's catalogue. Google Sans
	 * Code is the trap: it has none, whatever its name suggests.
	 *
	 * @var array
	 */
	const FONTS_WITH_LIGATURES = [
		'azeret-mono',
		'fira-code',
		'jetbrains-mono',
	];

	/**
	 * Registers the pipeline once WordPress is up.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		Shortcode_Handler::get_instance();

		$this->_reset_asset_state();

	}

	/**
	 * Puts the asset state and the options object back for whatever runs next.
	 *
	 * @return void
	 */
	public function tear_down(): void {

		$this->_reset_asset_state();

		/*
		 * The options object reads the stored array once and holds it for the rest of
		 * the request. The database is rolled back after each test, so the object has
		 * to go with it or the next test reads a font nobody saved.
		 */
		$this->_set_singleton( Option::class, null );

		parent::tear_down();

	}

	/**
	 * Method to read the font catalogue.
	 *
	 * @return array
	 */
	protected function _get_declared_fonts(): array {

		return (array) ( new ReflectionMethod( Asset_Manager::class, '_get_font_titles' ) )->invoke( null );

	}

	/**
	 * Method to read the rules added inline against the plugin's own stylesheet.
	 *
	 * @return string
	 */
	protected function _inline_chrome_rules(): string {

		$rules = wp_styles()->get_data( 'ig-syntax-hiliter-chrome', 'after' );

		if ( ! is_array( $rules ) ) {
			return '';
		}

		return implode( '', $rules );

	}

	/**
	 * **The promise of the default.** A page with code on it, and the font setting
	 * left alone, reaches out to nobody.
	 *
	 * This is the case that matters most in this file. Every other asset the plugin
	 * loads is a file it ships; a font is not, and a plugin which quietly fetched one
	 * from a third party would be making a decision that belongs to the site owner.
	 *
	 * @return void
	 */
	public function test_a_page_fetches_no_font_unless_one_is_chosen(): void {

		$this->assertSame(
			Asset_Manager::FONT_NONE,
			Option::get_instance()->get( 'font' ),
			'The shipped default loads no font.'
		);

		$this->_render_page( "[php]\necho 1;\n[/php]" );

		$this->assertTrue( Asset_Manager::get_instance()->has_snippets(), 'The page really did render a code box.' );

		foreach ( $this->_all_asset_urls() as $asset ) {
			$this->assertStringNotContainsString( static::FONT_HOST, $asset );
		}

		$this->assertArrayNotHasKey(
			'ig-syntax-hiliter-font',
			wp_styles()->registered,
			'Nothing registers a webfont stylesheet when no font is chosen.'
		);

		$this->assertSame(
			'',
			$this->_inline_chrome_rules(),
			'No rule is added to the chrome stylesheet either.'
		);

	}

	/**
	 * A chosen font is fetched exactly once, from that host and no other, and the
	 * rule which applies it rides along with the plugin's own stylesheet.
	 *
	 * @return void
	 */
	public function test_a_chosen_font_is_fetched_once_and_applied(): void {

		Option::get_instance()->save( 'font', 'jetbrains-mono' );

		$this->_render_page( "[php]\necho 1;\n[/php]" );

		$found = [];

		foreach ( $this->_all_asset_urls() as $asset ) {

			if ( ! str_contains( $asset, static::FONT_HOST ) ) {
				continue;
			}

			$found[] = $asset;

		}

		$this->assertCount( 1, $found, 'Exactly one asset is fetched from the font service.' );

		$style = wp_styles()->registered['ig-syntax-hiliter-font'] ?? null;

		$this->assertNotNull( $style, 'The webfont stylesheet is registered.' );
		$this->assertSame( Asset_Manager::get_font_url( 'jetbrains-mono' ), (string) $style->src );

		/*
		 * No version on a URL which belongs to somebody else. `wp_enqueue_style()` was
		 * passed NULL, which is what stops WordPress appending the plugin's own.
		 */
		$this->assertStringNotContainsString( 'ver=', (string) $style->src );

		$rules = $this->_inline_chrome_rules();

		$this->assertStringContainsString( '"JetBrains Mono"', $rules );
		$this->assertStringContainsString( '--igsh-code-font', $rules );

		/*
		 * Values and not a rule: the selectors live in the stylesheet, which is what
		 * keeps the cascade readable and adding a font a one file job.
		 */
		$this->assertStringNotContainsString( Renderer::ID_PREFIX, $rules );
		$this->assertStringStartsWith( ':root {', $rules );

	}

	/**
	 * Every font the dropdown offers has both of the things the preview needs, and
	 * "None" has neither.
	 *
	 * A font offered without a stylesheet would be a dropdown entry which does
	 * nothing; one offered without a rule would fetch a family and then not use it.
	 *
	 * @return void
	 */
	public function test_every_offered_font_has_a_stylesheet_and_a_rule(): void {

		$choices = Admin::get_font_choices();

		$this->assertGreaterThan( 1, count( $choices ), 'There are fonts to offer.' );
		$this->assertSame( Asset_Manager::FONT_NONE, array_key_first( $choices ), 'None is the first choice.' );

		foreach ( array_keys( $choices ) as $slug ) {

			$url = Asset_Manager::get_font_url( $slug );
			$css = Asset_Manager::get_font_css( $slug );

			if ( Asset_Manager::FONT_NONE === $slug ) {

				$this->assertSame( '', $url, 'None fetches nothing.' );
				$this->assertSame( '', $css, 'None applies nothing.' );

				continue;

			}

			$this->assertSame(
				static::FONT_HOST,
				(string) wp_parse_url( $url, PHP_URL_HOST ),
				sprintf( '%s is fetched from the font service and nowhere else.', $slug )
			);

			$this->assertSame( 'https', (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
			$this->assertStringContainsString( sprintf( 'family=%s:', $slug ), $url );
			$this->assertStringContainsString( 'display=swap', $url );

			$this->assertStringContainsString( sprintf( '"%s"', $choices[ $slug ] ), $css );
			$this->assertStringContainsString( Asset_Manager::FONT_STACK, $css );

		}

	}

	/**
	 * The list is in order by name with "None" on the front, and nothing was lost
	 * in the sorting.
	 *
	 * @return void
	 */
	public function test_the_font_dropdown_puts_none_first_and_sorts_the_rest(): void {

		$choices = Admin::get_font_choices();

		$this->assertSame( Asset_Manager::FONT_NONE, array_key_first( $choices ) );
		$this->assertCount( count( Asset_Manager::get_fonts() ) + 1, $choices );

		$names = array_values( $choices );

		array_shift( $names );

		$sorted = $names;

		usort( $sorted, 'strnatcasecmp' );

		$this->assertSame( $sorted, $names, 'The fonts are in order by name.' );

	}

	/**
	 * Ligatures are asked for by the three families which have them, and by nothing
	 * else.
	 *
	 * A declaration on a family with no such lookups does nothing at all, which is
	 * worse than useless: it reads as though the font supports something it does not.
	 *
	 * @return void
	 */
	public function test_only_the_fonts_which_have_ligatures_ask_for_them(): void {

		$asking = [];

		foreach ( array_keys( $this->_get_declared_fonts() ) as $slug ) {

			if ( ! str_contains( Asset_Manager::get_font_css( $slug ), '--igsh-code-ligatures' ) ) {
				continue;
			}

			$asking[] = $slug;

		}

		sort( $asking );

		$this->assertSame( static::FONTS_WITH_LIGATURES, $asking );

	}

	/**
	 * A font which asks for ligatures also zeroes the letter spacing, and one which
	 * does not asks for no letter spacing at all.
	 *
	 * The two belong together and neither is any use alone. **A non-zero
	 * `letter-spacing` suppresses ligatures outright** — specified behaviour, not a
	 * quirk — the property is inherited, and a theme setting it on its article text is
	 * enough to switch off the ligatures a site owner picked the font for. That is
	 * exactly what happened: `letter-spacing: 0.013rem` on a theme's `.entry-content`
	 * meant the settings preview ligated and the published post did not.
	 *
	 * The other half matters as much. A site running one of the seven fonts without
	 * ligatures, or no font at all, keeps whatever letter spacing its theme asks for —
	 * this plugin has no business changing how a theme sets type where nothing of ours
	 * depends on it.
	 *
	 * @return void
	 */
	public function test_the_letter_spacing_is_zeroed_for_ligature_fonts_and_no_others(): void {

		foreach ( array_keys( $this->_get_declared_fonts() ) as $slug ) {

			$css       = Asset_Manager::get_font_css( $slug );
			$ligatures = in_array( $slug, static::FONTS_WITH_LIGATURES, true );

			if ( $ligatures ) {

				$this->assertStringContainsString(
					'--igsh-code-letter-spacing: 0',
					$css,
					sprintf( '%s draws ligatures, so it has to zero the letter spacing.', $slug )
				);

				continue;

			}

			$this->assertStringNotContainsString(
				'--igsh-code-letter-spacing',
				$css,
				sprintf( '%s has no ligatures, so it has no business touching the letter spacing.', $slug )
			);

		}

	}

	/**
	 * A font this plugin does not offer loads nothing, rather than falling back to
	 * some other font.
	 *
	 * The theme setting falls back the other way, to the default theme, because a
	 * code box with no colours looks broken. There is no equivalent here: the only
	 * thing worse than the wrong typeface is a request to another host that nobody
	 * asked for.
	 *
	 * @return void
	 */
	public function test_a_font_this_plugin_does_not_offer_loads_nothing(): void {

		$this->assertSame( '', Asset_Manager::get_font_url( 'comic-sans-ms' ) );
		$this->assertSame( '', Asset_Manager::get_font_css( 'comic-sans-ms' ) );

		Option::get_instance()->save( 'font', 'comic-sans-ms' );

		$this->assertSame(
			Asset_Manager::FONT_NONE,
			Option::get_instance()->get( 'font' ),
			'A font outside the list is stored as the default, which is None.'
		);

		$this->_render_page( "[php]\necho 1;\n[/php]" );

		foreach ( $this->_all_asset_urls() as $asset ) {
			$this->assertStringNotContainsString( static::FONT_HOST, $asset );
		}

	}

	/**
	 * Every slug goes into a URL as it stands, so every slug has to be safe there.
	 *
	 * @return void
	 */
	public function test_every_font_slug_is_safe_in_a_url(): void {

		foreach ( array_keys( $this->_get_declared_fonts() ) as $slug ) {

			$this->assertMatchesRegularExpression(
				'~^[a-z0-9]+(?:-[a-z0-9]+)*$~',
				$slug,
				sprintf( '%s is lowercase, digits and hyphens, and needs no encoding.', $slug )
			);

		}

	}

	/**
	 * Every declared weight is one the family really ships.
	 *
	 * The service drops a weight a family does not have without saying so, so asking
	 * for one that is not there fails silently and the browser synthesises the face.
	 * These are the weights read out of the served files.
	 *
	 * @return void
	 */
	public function test_every_font_asks_for_a_weight_its_family_ships(): void {

		$weights = [
			'azeret-mono'       => 300,
			'fira-code'         => 400,
			'fira-mono'         => 400,
			'google-sans-code'  => 400,
			'jetbrains-mono'    => 400,
			'm-plus-code-latin' => 400,
			'nova-mono'         => 400,
			'roboto-mono'       => 400,
			'source-code-pro'   => 400,
			'ubuntu-mono'       => 400,
		];

		$declared = [];

		foreach ( $this->_get_declared_fonts() as $slug => $font ) {
			$declared[ $slug ] = (int) $font['weight'];
		}

		$this->assertSame( $weights, $declared );

	}

}    //end of class


//EOF
