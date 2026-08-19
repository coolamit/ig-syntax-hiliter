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
use iG\Syntax_Hiliter\Tests\Integration\Traits\Asset_Test_Helpers;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * Themes come from two directories now — Prism's own dist themes and the separate
 * PrismJS/prism-themes collection — and the settings dropdown, the allowlist the REST
 * route validates against and the stylesheet the page loads are all built from one
 * map. These are the things that map has to keep true.
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
	 * Puts the singleton back, so that a saved theme cannot leak into the next test.
	 *
	 * The theme list outlives a test in two places now — a static in front of an
	 * option — and the tests below plant one of their own in both. Neither is rolled
	 * back by the transaction the test case runs in: the static is memory, and the
	 * option was written before the assertions rather than by them.
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
	 * @test
	 *
	 * @return void
	 */
	public function it_keeps_every_declared_theme_on_disk(): void {

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
	 * No stylesheet the plugin ships may fetch anything from another host.
	 *
	 * This is the permanent guard on a decision taken when the prism-themes
	 * collection was bundled: Hopscotch was left out because its first line is an
	 * `@import` of a Google font, so every page carrying a code box would have called
	 * Google. Its stylesheet was the only one of the collection's with an external
	 * reference of any kind, and no theme added later may bring one back.
	 *
	 * **The engine plugins' own stylesheets are scanned here too**, although they are
	 * not themes and this file is about themes. They are vendored out of the same
	 * upstream release, they are enqueued onto the same page, and a `@import` in one
	 * of them would call another host exactly as a theme's would — and until the
	 * brace matching plugin was vendored there were only two of them and nothing had
	 * ever looked. One scan over every stylesheet this plugin ships is the guard;
	 * splitting it by which directory the file came out of would leave the newest
	 * directory unwatched, which is precisely how this one nearly shipped unwatched.
	 *
	 * A `url()` pointing at a data URI is fine and one theme has one: Pojoaque
	 * carries its background as base64, which needs no request at all.
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
	 * A theme slug carrying a dot has to survive being saved and read back.
	 *
	 * `prism-base16-ateliersulphurpool.light` is the only slug of that shape in either
	 * directory, and it is exactly the sort of value a sanitiser reshapes into
	 * something that no longer names a file.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_keeps_a_theme_slug_with_a_dot_through_a_round_trip(): void {

		$option = Option::get_instance();

		$this->assertArrayHasKey(
			static::_DOTTED_SLUG,
			Asset_Manager::get_themes(),
			'The dotted slug is a theme the plugin ships.'
		);

		$this->assertTrue( $option->save( 'theme', static::_DOTTED_SLUG ) );
		$this->assertSame( static::_DOTTED_SLUG, $option->get( 'theme' ) );

		$this->assertSame(
			'lib/prism-themes/' . static::_DOTTED_SLUG . '.min.css',
			Asset_Manager::get_theme_file( static::_DOTTED_SLUG )
		);

	}

	/**
	 * A slug the plugin does not ship gets no path at all.
	 *
	 * An empty string is what makes the caller's mistake visible. A path built anyway
	 * would be enqueued and would 404.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_gives_an_unknown_theme_no_file(): void {

		$this->assertSame( '', Asset_Manager::get_theme_file( 'prism-not-a-theme' ) );
		$this->assertSame( '', Asset_Manager::get_theme_file( '' ) );

	}

	/**
	 * The list is read from the cache, and a forced rebuild goes back to the disk.
	 *
	 * The theme list is a directory reading, and it was being taken afresh on every
	 * front end page which rendered a code box, twice, and on every REST request the
	 * site served. Both halves are asserted here: that the stored list is what a
	 * caller is handed, which is the saving; and that `yes` throws it away, which is
	 * the refresh button and the only way out of a cache with a week to run.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_caches_the_theme_list_and_goes_back_to_the_disk_on_a_forced_rebuild(): void {

		$real = Asset_Manager::build_themes();

		$this->_plant_cached_themes( [ 'prism-not-a-theme' => 'Planted' ] );

		$this->assertSame(
			[ 'prism-not-a-theme' => 'Planted' ],
			Asset_Manager::get_themes(),
			'The cached list is what a caller gets, so the disk is not read again.'
		);

		$this->assertSame(
			$real,
			Asset_Manager::get_themes( 'yes' ),
			'A forced rebuild reads the disk and answers with what is really there.'
		);

		$this->assertSame(
			$real,
			get_option( $this->_cache_option_name() )['data'] ?? null,
			'And what it read is written back, so the next request pays nothing.'
		);

	}

	/**
	 * An empty cached list is a failure and is never served.
	 *
	 * `Cache` writes `[]` down as readily as it writes a real list, and `Cache::get()`
	 * hands it back — `isset( $cache['data'] )` is true for an empty array — so the
	 * one request in which nothing on disk happened to be readable used to be served
	 * for the whole seven days the entry lives. What a site owner sees then is a theme
	 * dropdown holding nothing but "None", and a settings screen answering 400 for
	 * every real theme slug, with the refresh button the only way out.
	 *
	 * The other half of the fix — that an empty *rebuild* is not written down either —
	 * has no seam to drive it through from here: `build_themes()` reads two constant
	 * directories and takes nothing this test could point elsewhere. It is two lines
	 * beside the ones asserted below and is stated in the docblock there.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_never_serves_an_empty_cached_list(): void {

		$this->_plant_cached_themes( [] );

		$this->assertSame(
			Asset_Manager::build_themes(),
			Asset_Manager::get_themes(),
			'An empty cached list is not an answer, so the disk is read again.'
		);

		$this->assertFalse(
			get_option( $this->_cache_option_name(), false ),
			'And the unusable entry is gone, so it is not stepped over again on every request for a week.'
		);

	}

	/**
	 * Only the word `yes` forces a rebuild.
	 *
	 * The value arrives over REST, so it is a string of somebody else's choosing.
	 * Anything which is not a yes/no flag reads as `no`, which is what stops a
	 * rebuild being triggered by a typo or by a caller passing something else
	 * entirely.
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
				Asset_Manager::get_themes( $value ),
				sprintf( '"%s" is not the word yes and rebuilt the list anyway.', $value )
			);

		}

	}

}    //end of class

//EOF
