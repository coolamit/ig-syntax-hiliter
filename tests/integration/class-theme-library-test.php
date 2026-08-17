<?php
/**
 * Tests for the themes the plugin ships and where they are read from.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Asset_Manager;
use iG\Syntax_Hiliter\Helper;
use iG\Syntax_Hiliter\Option;
use ReflectionMethod;
use ReflectionProperty;
use WP_UnitTestCase;

/**
 * Themes come from two directories now — Prism's own dist themes and the separate
 * PrismJS/prism-themes collection — and the settings dropdown, the allowlist the REST
 * route validates against and the stylesheet the page loads are all built from one
 * map. These are the things that map has to keep true.
 */
class Theme_Library_Test extends WP_UnitTestCase {

	/**
	 * The one theme with a dot in its slug.
	 *
	 * @var string
	 */
	const DOTTED_SLUG = 'prism-base16-ateliersulphurpool.light';

	/**
	 * The options object as the plugin booted it.
	 *
	 * @var \iG\Syntax_Hiliter\Option|null
	 */
	protected ?Option $_original_option = null;

	/**
	 * Remembers the singleton to put back.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		$this->_original_option = Option::get_instance();

	}

	/**
	 * Puts the singleton back, so that a saved theme cannot leak into the next test.
	 *
	 * @return void
	 */
	public function tear_down(): void {

		( new ReflectionProperty( Option::class, '_instance' ) )->setValue( null, $this->_original_option );

		parent::tear_down();

	}

	/**
	 * Method to read the declared themes, by the directory each one lives in.
	 *
	 * @return array
	 */
	protected function _get_declared_themes(): array {

		return (array) ( new ReflectionMethod( Asset_Manager::class, '_get_theme_titles' ) )->invoke( null );

	}

	/**
	 * Every theme the plugin declares has to be on disk.
	 *
	 * `get_themes()` only offers a theme whose stylesheet is readable, which is what
	 * stops the dropdown offering a file that would 404. The cost of that is silence:
	 * a slug mistyped in the map, or a file left out of the vendored tree, simply is
	 * not offered and nothing says so. This is what says so.
	 *
	 * @return void
	 */
	public function test_every_declared_theme_is_on_disk(): void {

		$declared = $this->_get_declared_themes();
		$offered  = Asset_Manager::get_themes();

		$this->assertNotEmpty( $declared, 'The plugin declares at least one theme.' );

		foreach ( $declared as $directory => $titles ) {

			foreach ( array_keys( $titles ) as $slug ) {

				$this->assertArrayHasKey(
					$slug,
					$offered,
					sprintf( 'Theme %1$s is declared in %2$s but its stylesheet is not readable.', $slug, $directory )
				);

			}
		}

	}

	/**
	 * No theme the plugin ships may fetch anything from another host.
	 *
	 * This is the permanent guard on a decision taken when the prism-themes
	 * collection was bundled: Hopscotch was left out because its first line is an
	 * `@import` of a Google font, so every page carrying a code box would have called
	 * Google. Its stylesheet was the only one of the collection's with an external
	 * reference of any kind, and no theme added later may bring one back.
	 *
	 * A `url()` pointing at a data URI is fine and one theme has one: Pojoaque
	 * carries its background as base64, which needs no request at all.
	 *
	 * @return void
	 */
	public function test_no_bundled_theme_fetches_anything_from_another_host(): void {

		$files = [];

		foreach ( array_keys( $this->_get_declared_themes() ) as $directory ) {

			$found = glob( Helper::get_asset_path( $directory ) . '/*.min.css' );

			$files = array_merge( $files, (array) $found );

		}

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
	 * A theme slug carrying a dot has to survive being saved and read back.
	 *
	 * `prism-base16-ateliersulphurpool.light` is the only slug of that shape in either
	 * directory, and it is exactly the sort of value a sanitiser reshapes into
	 * something that no longer names a file.
	 *
	 * @return void
	 */
	public function test_a_theme_slug_with_a_dot_survives_a_round_trip(): void {

		$option = Option::get_instance();

		$this->assertArrayHasKey(
			static::DOTTED_SLUG,
			Asset_Manager::get_themes(),
			'The dotted slug is a theme the plugin ships.'
		);

		$this->assertTrue( $option->save( 'theme', static::DOTTED_SLUG ) );
		$this->assertSame( static::DOTTED_SLUG, $option->get( 'theme' ) );

		$this->assertSame(
			'lib/prism-themes/' . static::DOTTED_SLUG . '.min.css',
			Asset_Manager::get_theme_file( static::DOTTED_SLUG )
		);

	}

	/**
	 * A slug the plugin does not ship gets no path at all.
	 *
	 * An empty string is what makes the caller's mistake visible. A path built anyway
	 * would be enqueued and would 404.
	 *
	 * @return void
	 */
	public function test_an_unknown_theme_has_no_file(): void {

		$this->assertSame( '', Asset_Manager::get_theme_file( 'prism-not-a-theme' ) );
		$this->assertSame( '', Asset_Manager::get_theme_file( '' ) );

	}

}    //end of class

//EOF
