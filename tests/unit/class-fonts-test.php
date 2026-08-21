<?php
/**
 * Tests for the font catalogue.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Unit;

use iG\Syntax_Hiliter\Fonts;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The font catalogue: what every offered font declares, and that the stylesheet reads
 * what the setting writes. In the unit tier because `Fonts` calls no WordPress function.
 */
class Fonts_Test extends TestCase {

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
	 * Method to read the font catalogue.
	 *
	 * @return array
	 */
	protected function _get_declared_fonts(): array {

		return (array) ( new ReflectionMethod( Fonts::class, '_get_font_titles' ) )->invoke( null );

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

	/**
	 * The editor and the front end name the same family, and only the front end asks
	 * for ligatures.
	 *
	 * Both rules are built from the same map, so the family cannot disagree. The
	 * ligatures differ on purpose: a caret cannot sit inside one glyph standing for two,
	 * so `__construct` in a textarea reads back as ` _construct` and invites a wrong fix.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_asks_for_ligatures_on_the_front_end_only(): void {

		foreach ( Fonts::get_fonts() as $slug => $title ) {

			$needle = sprintf( '"%s", %s', $title, Fonts::FONT_STACK );

			$this->assertStringContainsString( $needle, Fonts::get_font_css( $slug ) );
			$this->assertStringContainsString( $needle, Fonts::get_editor_font_css( $slug ) );

			$this->assertStringNotContainsString(
				'ligatures',
				Fonts::get_editor_font_css( $slug ),
				sprintf( 'The editor must say nothing about ligatures, and it does for %s.', $slug )
			);

		}

		// The control: the loop above would still pass if the front end stopped asking too.
		$this->assertStringContainsString(
			'--igsh-code-ligatures',
			Fonts::get_font_css( 'fira-code' ),
			'The front end still asks for ligatures where the family has them.'
		);

	}

	/**
	 * The stylesheet reads every custom property the font setting sets.
	 *
	 * The selectors live here and the values come from PHP, so either half can stop
	 * referring to the other without a word and picking a font would do nothing.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_reads_what_the_font_setting_sets_into_the_stylesheet(): void {

		$css = (string) file_get_contents( IG_SYNTAX_HILITER_ROOT . '/assets/build/css/frontend-chrome.css' );

		foreach ( [ '--igsh-code-font', '--igsh-code-ligatures', '--igsh-code-letter-spacing' ] as $property ) {

			$this->assertStringContainsString(
				sprintf( 'var(%s', $property ),
				$css,
				sprintf( 'Nothing in the stylesheet reads %s, so setting it does nothing.', $property )
			);

		}

	}

} // end of class

// EOF
