<?php
/**
 * Tests for the theme catalogue.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Unit;

use iG\Syntax_Hiliter\Helper;
use iG\Syntax_Hiliter\Themes;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The part of the theme map which needs nothing but the disk: what the vendored
 * stylesheets may reach, and what an unknown slug resolves to. The cached list and
 * the dropdown are integration cases.
 */
class Themes_Test extends TestCase {

	/**
	 * Method to read the declared themes, by the directory each one lives in.
	 *
	 * @return array
	 */
	protected function _get_declared_themes(): array {

		return (array) ( new ReflectionMethod( Themes::class, '_get_theme_titles' ) )->invoke( null );

	}

	/**
	 * No stylesheet the plugin ships may fetch anything from another host.
	 *
	 * Hopscotch was left out of the vendored collection because its first line is an
	 * `@import` of a Google font. The engine plugins' stylesheets are scanned too: they
	 * are enqueued onto the same page. A data URI `url()` is fine; Pojoaque has one.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_fetches_nothing_from_another_host_for_any_bundled_stylesheet(): void {

		$files = [];

		$directories = array_keys( $this->_get_declared_themes() );

		foreach ( $directories as $directory ) {

			$found = glob( Helper::get_asset_path( $directory ) . '/*.min.css' );

			$files = array_merge( $files, (array) $found );

		}

		$plugin_styles = glob( Helper::get_asset_path( 'lib/prism/plugins' ) . '/*/*.min.css' );

		$this->assertNotEmpty( $plugin_styles, 'The vendored engine plugins ship stylesheets of their own.' );

		$files = array_merge( $files, (array) $plugin_styles );

		$this->assertNotEmpty( $files, 'The vendored theme directories hold stylesheets.' );

		foreach ( $files as $file ) {

			$css = (string) file_get_contents( $file );    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a vendored file off disk, not a remote resource.

			$this->assertDoesNotMatchRegularExpression(
				'~@import~i',
				$css,
				sprintf( '%s imports another stylesheet.', basename( $file ) )
			);

			$this->assertDoesNotMatchRegularExpression(
				'~url\(\s*[\'"]?(?:https?:)?//~i',
				$css,
				sprintf( '%s fetches something from another host.', basename( $file ) )
			);

		}

	}

	/**
	 * A slug the plugin does not ship gets no path at all; a path built anyway would
	 * be enqueued and would 404.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_gives_an_unknown_theme_no_file(): void {

		$this->assertSame( '', Themes::get_theme_file( 'prism-not-a-theme' ) );
		$this->assertSame( '', Themes::get_theme_file( '' ) );

	}

} // end of class

// EOF
