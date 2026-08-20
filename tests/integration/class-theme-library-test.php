<?php
/**
 * Tests for the themes the plugin ships and where they are read from.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Helper;
use iG\Syntax_Hiliter\Option;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Asset_Test_Helpers;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use iG\Syntax_Hiliter\Themes;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * Themes come from two directories, Prism's own dist and the PrismJS/prism-themes
 * collection, and the dropdown, the REST allowlist and the enqueued stylesheet are
 * all built from one map. These are the things that map has to keep true.
 */
class Theme_Library_Test extends WP_UnitTestCase {

	use Asset_Test_Helpers;

	use Pipeline_Test_Helpers;

	/**
	 * The one theme with a dot in its slug.
	 *
	 * @var string
	 */
	protected const string _DOTTED_SLUG = 'prism-base16-ateliersulphurpool.light';

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
	 * Puts the singleton back and clears the theme cache, which lives in a static and
	 * an option the transaction does not roll back.
	 *
	 * @return void
	 */
	public function tear_down(): void {

		$this->_set_singleton( Option::class, $this->_original_option );

		$this->_reset_theme_cache();

		parent::tear_down();

	}

	/**
	 * Method to read the declared themes, by the directory each one lives in.
	 *
	 * @return array
	 */
	protected function _get_declared_themes(): array {

		return (array) ( new ReflectionMethod( Themes::class, '_get_theme_titles' ) )->invoke( null );

	}

	/**
	 * Every theme the plugin declares has to be on disk. `get_themes()` silently drops
	 * a theme whose stylesheet is not readable, so a mistyped slug or a missing file
	 * would otherwise go unnoticed.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_keeps_every_declared_theme_on_disk(): void {

		$declared = $this->_get_declared_themes();
		$offered  = Themes::get_themes();

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
	 * A theme slug carrying a dot has to survive being saved and read back: it is
	 * the sort of value a sanitiser reshapes into something that no longer names a file.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_keeps_a_theme_slug_with_a_dot_through_a_round_trip(): void {

		$option = Option::get_instance();

		$this->assertArrayHasKey(
			static::_DOTTED_SLUG,
			Themes::get_themes(),
			'The dotted slug is a theme the plugin ships.'
		);

		$this->assertTrue( $option->save( 'theme', static::_DOTTED_SLUG ) );
		$this->assertSame( static::_DOTTED_SLUG, $option->get( 'theme' ) );

		$this->assertSame(
			'lib/prism-themes/' . static::_DOTTED_SLUG . '.min.css',
			Themes::get_theme_file( static::_DOTTED_SLUG )
		);

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

	/**
	 * The list is read from the cache, and a forced rebuild goes back to the disk.
	 * `yes` is what the refresh button sends, and the only way out of a cache with a
	 * week to run.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_caches_the_theme_list_and_goes_back_to_the_disk_on_a_forced_rebuild(): void {

		$real = Themes::build_themes();

		$this->_plant_cached_themes( [ 'prism-not-a-theme' => 'Planted' ] );

		$this->assertSame(
			[ 'prism-not-a-theme' => 'Planted' ],
			Themes::get_themes(),
			'The cached list is what a caller gets, so the disk is not read again.'
		);

		$this->assertSame(
			$real,
			Themes::get_themes( 'yes' ),
			'A forced rebuild reads the disk and answers with what is really there.'
		);

		$this->assertSame(
			$real,
			get_option( $this->_cache_option_name() )['data'] ?? null,
			'And what it read is written back, so the next request pays nothing.'
		);

	}

	/**
	 * An empty cached list is a failure and is never served. `Cache` writes `[]` down
	 * as readily as a real list and hands it back, so a request in which nothing on
	 * disk was readable would otherwise be served for seven days.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_never_serves_an_empty_cached_list(): void {

		$this->_plant_cached_themes( [] );

		$this->assertSame(
			Themes::build_themes(),
			Themes::get_themes(),
			'An empty cached list is not an answer, so the disk is read again.'
		);

		$this->assertFalse(
			get_option( $this->_cache_option_name(), false ),
			'And the unusable entry is gone, so it is not stepped over again on every request for a week.'
		);

	}

	/**
	 * Only the word `yes` forces a rebuild. The value arrives over REST, and anything
	 * which is not a yes/no flag reads as `no`.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_leaves_the_cache_alone_for_anything_that_is_not_a_flag(): void {

		$planted = [ 'prism-not-a-theme' => 'Planted' ];

		foreach ( [ 'no', 'yes please', '1', '' ] as $value ) {

			$this->_plant_cached_themes( $planted );

			$this->assertSame(
				$planted,
				Themes::get_themes( $value ),
				sprintf( '"%s" is not the word yes and rebuilt the list anyway.', $value )
			);

		}

	}

} // end of class

// EOF
