<?php
/**
 * Shared helpers for the tests which assert on the front end enqueue state.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Asset_Manager;
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

		$manager = Asset_Manager::get_instance();

		$defaults = [
			'_has_snippets'         => false,
			'_needs_line_numbers'   => false,
			'_needs_line_highlight' => false,
			'_languages'            => [],
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
	 * Method to run the asset manager's footer pass.
	 *
	 * Everything else on `wp_footer` is taken off first: the WordPress install under
	 * test brings its own callbacks, which print markup and raise deprecations that
	 * have nothing to do with this plugin. The assertion above them is what keeps
	 * this honest — the manager really is wired to the hook it is being run through.
	 *
	 * @return void
	 */
	protected function _fire_footer(): void {

		$manager = Asset_Manager::get_instance();

		$this->assertSame( 1, has_action( 'wp_footer', [ $manager, 'enqueue' ] ), 'The asset manager decides at wp_footer priority 1.' );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook the plugin registers on.

		remove_all_actions( 'wp_footer' );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook the plugin registers on.
		add_action( 'wp_footer', [ $manager, 'enqueue' ], 1 );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook the plugin registers on.

		do_action( 'wp_footer' );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook the plugin registers on.

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

}    //end of trait


//EOF
