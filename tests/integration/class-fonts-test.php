<?php
/**
 * Tests for the fonts the plugin offers.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Admin;
use iG\Syntax_Hiliter\Fonts;
use WP_UnitTestCase;

/**
 * The half of the font catalogue which needs WordPress: the choices the screen offers,
 * read through `Admin`. The catalogue itself is tested in the unit tier.
 */
class Fonts_Test extends WP_UnitTestCase {

	/**
	 * Host every webfont is fetched from, and the only host this plugin may reach.
	 *
	 * @var string
	 */
	protected const string _FONT_HOST = 'fonts.bunny.net';

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

			$url = Fonts::get_instance()->get_font_url( $slug );
			$css = Fonts::get_instance()->get_font_css( $slug );

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

} // end of class

// EOF
