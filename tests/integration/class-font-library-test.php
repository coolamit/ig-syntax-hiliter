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
use iG\Syntax_Hiliter\Fonts;
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
	protected const string _FONT_HOST = 'fonts.bunny.net';

	/**
	 * The four families which are programming ligature faces.
	 *
	 * Read from the `liga` and `calt` lookups in each family's `GSUB` table, in the files
	 * the service serves. Google Sans Code has none; Azeret Mono has three against the
	 * others' 89 to 138, so the classification is a judgment and the constant is a list.
	 *
	 * @var array
	 */
	protected const array _FONTS_WITH_LIGATURES = [
		'cascadia-code',
		'fira-code',
		'jetbrains-mono',
		'victor-mono',
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

		// The options object holds the stored array for the request; the database is rolled back after each test, so the object goes with it.
		$this->_set_singleton( Option::class, null );

		parent::tear_down();

	}

	/**
	 * Method to read the font catalogue.
	 *
	 * @return array
	 */
	protected function _get_declared_fonts(): array {

		return (array) ( new ReflectionMethod( Fonts::class, '_get_font_titles' ) )->invoke( null );

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
	 * The promise of the default: a page with code on it, and the font setting left
	 * alone, reaches out to nobody.
	 *
	 * Every other asset the plugin loads is a file it ships; a font is not, and a
	 * plugin which quietly fetched one from a third party would be making a decision
	 * that belongs to the site owner.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_fetches_no_font_unless_one_is_chosen(): void {

		$this->assertSame(
			Fonts::FONT_NONE,
			Option::get_instance()->get( 'font' ),
			'The shipped default loads no font.'
		);

		$this->_render_page( "[php]\necho 1;\n[/php]" );

		$this->assertTrue( Asset_Manager::get_instance()->has_snippets(), 'The page really did render a code box.' );

		foreach ( $this->_all_asset_urls() as $asset ) {
			$this->assertStringNotContainsString( static::_FONT_HOST, $asset );
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
	 * @test
	 *
	 * @return void
	 */
	public function it_fetches_a_chosen_font_once_and_applies_it(): void {

		Option::get_instance()->save( 'font', 'jetbrains-mono' );

		$this->_render_page( "[php]\necho 1;\n[/php]" );

		$found = [];

		foreach ( $this->_all_asset_urls() as $asset ) {

			if ( ! str_contains( $asset, static::_FONT_HOST ) ) {
				continue;
			}

			$found[] = $asset;

		}

		$this->assertCount( 1, $found, 'Exactly one asset is fetched from the font service.' );

		$style = wp_styles()->registered['ig-syntax-hiliter-font'] ?? null;

		$this->assertNotNull( $style, 'The webfont stylesheet is registered.' );
		$this->assertSame( Fonts::get_font_url( 'jetbrains-mono' ), (string) $style->src );

		// No version on a URL which belongs to somebody else: `wp_enqueue_style()` is passed NULL.
		$this->assertStringNotContainsString( 'ver=', (string) $style->src );

		$rules = $this->_inline_chrome_rules();

		$this->assertStringContainsString( '"JetBrains Mono"', $rules );
		$this->assertStringContainsString( '--igsh-code-font', $rules );

		// Values and not a rule: the selectors live in the stylesheet.
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
	 * @test
	 *
	 * @return void
	 */
	public function it_gives_every_offered_font_a_stylesheet_and_a_rule(): void {

		$choices = Admin::get_font_choices();

		$this->assertGreaterThan( 1, count( $choices ), 'There are fonts to offer.' );
		$this->assertSame( Fonts::FONT_NONE, array_key_first( $choices ), 'None is the first choice.' );

		foreach ( array_keys( $choices ) as $slug ) {

			$url = Fonts::get_font_url( $slug );
			$css = Fonts::get_font_css( $slug );

			if ( Fonts::FONT_NONE === $slug ) {

				$this->assertSame( '', $url, 'None fetches nothing.' );
				$this->assertSame( '', $css, 'None applies nothing.' );

				continue;

			}

			$this->assertSame(
				static::_FONT_HOST,
				(string) wp_parse_url( $url, PHP_URL_HOST ),
				sprintf( '%s is fetched from the font service and nowhere else.', $slug )
			);

			$this->assertSame( 'https', (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
			$this->assertStringContainsString( sprintf( 'family=%s:', $slug ), $url );
			$this->assertStringContainsString( 'display=swap', $url );

			$this->assertStringContainsString( sprintf( '"%s"', $choices[ $slug ] ), $css );
			$this->assertStringContainsString( Fonts::FONT_STACK, $css );

		}

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
	 * Ligatures are asked for by the four families which have them, and by nothing
	 * else.
	 *
	 * A declaration on a family with no such lookups does nothing at all, which is
	 * worse than useless: it reads as though the font supports something it does not.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_asks_for_ligatures_only_for_the_fonts_which_have_them(): void {

		$asking = [];

		foreach ( array_keys( $this->_get_declared_fonts() ) as $slug ) {

			if ( ! str_contains( Fonts::get_font_css( $slug ), '--igsh-code-ligatures' ) ) {
				continue;
			}

			$asking[] = $slug;

		}

		sort( $asking );

		$this->assertSame( static::_FONTS_WITH_LIGATURES, $asking );

	}

	/**
	 * A font which asks for ligatures also zeroes the letter spacing, and one which
	 * does not asks for no letter spacing at all.
	 *
	 * A non-zero `letter-spacing` suppresses ligatures outright, and the property is
	 * inherited, so a theme setting it on its article text switches off the ligatures
	 * the font was picked for. A font without ligatures keeps the theme's letter spacing.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_zeroes_the_letter_spacing_for_ligature_fonts_and_no_others(): void {

		foreach ( array_keys( $this->_get_declared_fonts() ) as $slug ) {

			$css       = Fonts::get_font_css( $slug );
			$ligatures = in_array( $slug, static::_FONTS_WITH_LIGATURES, true );

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
	 * code box with no colours looks broken. There is no equivalent here: a wrong
	 * typeface is not worth a request to another host.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_loads_nothing_for_a_font_this_plugin_does_not_offer(): void {

		$this->assertSame( '', Fonts::get_font_url( 'comic-sans-ms' ) );
		$this->assertSame( '', Fonts::get_font_css( 'comic-sans-ms' ) );

		Option::get_instance()->save( 'font', 'comic-sans-ms' );

		$this->assertSame(
			Fonts::FONT_NONE,
			Option::get_instance()->get( 'font' ),
			'A font outside the list is stored as the default, which is None.'
		);

		$this->_render_page( "[php]\necho 1;\n[/php]" );

		foreach ( $this->_all_asset_urls() as $asset ) {
			$this->assertStringNotContainsString( static::_FONT_HOST, $asset );
		}

	}

	/**
	 * Every slug goes into a URL as it stands, so every slug has to be safe there.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_keeps_every_font_slug_safe_in_a_url(): void {

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
	 * @test
	 *
	 * @return void
	 */
	public function it_asks_for_a_weight_every_fonts_family_ships(): void {

		$weights = [
			'azeret-mono'       => 300,
			'cascadia-code'     => 300,
			'fira-code'         => 400,
			'fira-mono'         => 400,
			'google-sans-code'  => 400,
			'ibm-plex-mono'     => 400,
			'inconsolata'       => 400,
			'jetbrains-mono'    => 400,
			'm-plus-code-latin' => 400,
			'nova-mono'         => 400,
			'roboto-mono'       => 400,
			'source-code-pro'   => 400,
			'space-mono'        => 400,
			'ubuntu-mono'       => 400,
			'victor-mono'       => 400,
		];

		$declared = [];

		foreach ( $this->_get_declared_fonts() as $slug => $font ) {
			$declared[ $slug ] = (int) $font['weight'];
		}

		$this->assertSame( $weights, $declared );

	}

} // end of class

// EOF
