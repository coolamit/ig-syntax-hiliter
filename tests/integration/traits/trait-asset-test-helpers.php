<?php
/**
 * Shared helpers for the tests which assert on the front end enqueue state.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration\Traits;

use iG\Syntax_Hiliter\Admin;
use iG\Syntax_Hiliter\Asset_Manager;
use iG\Syntax_Hiliter\Cache;
use iG\Syntax_Hiliter\Themes;
use ReflectionProperty;

/**
 * The asset manager and the script/style registries both last the whole PHPUnit
 * process, while the thing under test is one page load. These helpers put both
 * back to the state a fresh request starts in.
 */
trait Asset_Test_Helpers {

	/**
	 * Method to put the asset manager and the script/style registries back to their
	 * start of request state.
	 *
	 * @return void
	 */
	protected function _reset_asset_state(): void {
		/*
		 * `Admin::$_settings_schema` goes with the theme list, because it embeds the
		 * theme choices and is memoised for the request. They are a pair now: clear
		 * one and not the other and the schema goes on describing a list which no
		 * longer exists, which is the arrangement that produces a green suite over a
		 * wrong answer.
		 */
		( new ReflectionProperty( Admin::class, '_settings_schema' ) )->setValue( null, null );

		$manager = Asset_Manager::get_instance();

		$defaults = [
			'_has_snippets'         => false,
			'_needs_line_numbers'   => false,
			'_needs_line_highlight' => false,
			'_languages'            => [],
			'_font_styled'          => false,
		];

		foreach ( $defaults as $name => $value ) {
			( new ReflectionProperty( Asset_Manager::class, $name ) )->setValue( $manager, $value );
		}

		/*
		 * Only this plugin's handles are taken out. Rebuilding the whole registry
		 * would re-run every other plugin's registration in the install under test,
		 * and their notices would land on whichever test happened to trigger it.
		 */
		foreach ( array_keys( wp_scripts()->registered ) as $handle ) {

			if ( ! str_starts_with( (string) $handle, Asset_Manager::HANDLE_PREFIX ) ) {
				continue;
			}

			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );

		}

		foreach ( array_keys( wp_styles()->registered ) as $handle ) {

			if ( ! str_starts_with( (string) $handle, Asset_Manager::HANDLE_PREFIX ) ) {
				continue;
			}

			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );

		}

	}

	/**
	 * Method to list the `wp_footer` priorities the asset manager is registered at.
	 *
	 * Read off the hook rather than asserted against a constant, so that this says
	 * "whenever the manager decides" and not "at the priorities it happens to use
	 * today".
	 *
	 * @return array Numerically indexed list of priorities, in the order they run.
	 */
	protected function _manager_footer_priorities(): array {

		$manager    = Asset_Manager::get_instance();
		$priorities = [];

		foreach ( (array) ( $GLOBALS['wp_filter']['wp_footer']->callbacks ?? [] ) as $priority => $callbacks ) {

			foreach ( $callbacks as $callback ) {

				if ( ! is_array( $callback['function'] ?? null ) || ( $callback['function'][0] ?? null ) !== $manager ) {
					continue;
				}

				$priorities[] = (int) $priority;

			}
		}

		return $priorities;

	}

	/**
	 * Method to render a post the way a single post view renders it, then run the
	 * footer pass over what that left behind.
	 *
	 * The two halves belong together: the assets are decided during `wp_footer`, and
	 * they are decided from a signal only rendering the content can raise. Rendering
	 * without the footer pass asserts nothing about what the page loads.
	 *
	 * @param string $content Post content.
	 *
	 * @return string The rendered markup, for a caller which has something to say about it.
	 */
	protected function _render_page( string $content ): string {

		$post_id = self::factory()->post->create(
			[
				'post_content' => wp_slash( $content ),
			]
		);

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		ob_start();
		the_content();
		$output = (string) ob_get_clean();

		$this->_fire_footer();

		return $output;

	}

	/**
	 * Method to run the asset manager's footer passes.
	 *
	 * Everything else on `wp_footer` is taken off first: the WordPress install under
	 * test brings its own callbacks, which print markup and raise deprecations that
	 * have nothing to do with this plugin. The assertion above them is what keeps
	 * this honest — the manager really is wired to the hook it is being run through.
	 *
	 * Every priority the manager sits at goes back on, not just the first. It decides
	 * more than once during a footer, and a helper which restored one of those passes
	 * would quietly stop the tests using it from seeing what the other one does.
	 *
	 * Callbacks of the caller's own can be wired in beside the manager's, which is how
	 * a test puts something on the hook that renders content from the footer, or
	 * prints at the moment core prints. Anything they print is in the return, so a
	 * caller can tell "enqueued" from "enqueued in time".
	 *
	 * @param array $extra Optional. Callbacks to add, each a `[ priority, callable ]` pair.
	 *
	 * @return string Everything the footer printed.
	 */
	protected function _fire_footer( array $extra = [] ): string {

		$manager    = Asset_Manager::get_instance();
		$priorities = $this->_manager_footer_priorities();

		$this->assertNotEmpty( $priorities, 'The asset manager decides during wp_footer.' );

		remove_all_actions( 'wp_footer' );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook the plugin registers on.

		foreach ( $priorities as $priority ) {
			add_action( 'wp_footer', [ $manager, 'enqueue' ], $priority );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook the plugin registers on.
		}

		foreach ( $extra as $callback ) {
			add_action( 'wp_footer', $callback[1], (int) $callback[0] );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook the plugin registers on.
		}

		ob_start();

		do_action( 'wp_footer' );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook the plugin registers on.

		return (string) ob_get_clean();

	}

	/**
	 * Method to list every script and style handle this plugin has put into play.
	 *
	 * Registered handles count as well as enqueued ones, because the plugin only
	 * ever registers by enqueuing — anything of ours in either list means assets
	 * were loaded.
	 *
	 * @return array Sorted list of `script:`/`style:` prefixed handles.
	 */
	protected function _plugin_asset_handles(): array {

		$handles = [];

		$sources = [
			'script' => wp_scripts(),
			'style'  => wp_styles(),
		];

		foreach ( $sources as $kind => $dependencies ) {

			$found = array_merge( array_keys( $dependencies->registered ), $dependencies->queue );

			foreach ( $found as $handle ) {

				if ( ! str_starts_with( (string) $handle, Asset_Manager::HANDLE_PREFIX ) ) {
					continue;
				}

				$handles[ sprintf( '%s:%s', $kind, $handle ) ] = sprintf( '%s:%s', $kind, $handle );

			}
		}

		$handles = array_values( $handles );

		sort( $handles );

		return $handles;

	}

	/**
	 * Method to list the URL of every script and style on the page, whoever put it there.
	 *
	 * @return array
	 */
	protected function _all_asset_urls(): array {

		$urls = [];

		foreach ( [ wp_scripts(), wp_styles() ] as $dependencies ) {
			foreach ( $dependencies->registered as $handle => $dependency ) {
				$urls[] = sprintf( '%s|%s', $handle, (string) $dependency->src );
			}
		}

		return $urls;

	}

	/**
	 * Method to name the option the built theme list is cached in.
	 *
	 * @return string
	 */
	protected function _cache_option_name(): string {

		return Cache::KEY_PREFIX . md5( Themes::THEMES_CACHE_KEY );

	}

	/**
	 * Method to make the class forget the theme list it read earlier in this request.
	 *
	 * The static memo sits in front of the option, so nothing planted in the option
	 * is seen until it is cleared. `Admin::$_settings_schema` goes with it for the
	 * reason given on `_reset_asset_state()` above.
	 *
	 * @return void
	 */
	protected function _forget_themes(): void {

		( new ReflectionProperty( Themes::class, '_themes' ) )->setValue( null, null );
		( new ReflectionProperty( Admin::class, '_settings_schema' ) )->setValue( null, null );

	}

	/**
	 * Method to put a theme list of the test's own into the cache.
	 *
	 * Written straight into the option rather than through `Cache`, because what is
	 * being proved is that the reader goes to the option at all — and a list built
	 * by the same code that reads it could not tell a cache hit from a rebuild.
	 *
	 * @param array $themes Theme list to plant.
	 *
	 * @return void
	 */
	protected function _plant_cached_themes( array $themes ): void {

		update_option(
			$this->_cache_option_name(),
			[
				'expiry' => ( time() + HOUR_IN_SECONDS ),
				'data'   => $themes,
			],
			false
		);

		$this->_forget_themes();

	}

	/**
	 * Method to throw the cached theme list away entirely, option and memo alike.
	 *
	 * What a test which warmed the list owes whatever runs after it. Neither half is
	 * rolled back by the transaction a test case runs in: the memo is memory, and the
	 * option is written before the assertions rather than by them.
	 *
	 * @return void
	 */
	protected function _reset_theme_cache(): void {

		delete_option( $this->_cache_option_name() );

		$this->_forget_themes();

	}

}    //end of trait


//EOF
