<?php
/**
 * Shared helpers for the tests which assert that a class wired itself to WordPress.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration\Traits;

use WP_Hook;

/**
 * Assertions about what is registered on a hook.
 *
 * WordPress already answers most of this with `has_action()`/`has_filter()`, and the
 * tests which only need "is it there, and at which priority" go on using those. These
 * helpers exist for the two questions core's pair cannot answer:
 *
 * - **Every priority, not the first.** `has_filter()` returns the priority of the first
 *   registration it finds and stops. `Asset_Manager` sits on `wp_footer` at both
 *   `PRIORITY_DECIDE` and `PRIORITY_DECIDE_AGAIN`, and `Gist_Embed` does the same, so a
 *   single `has_action()` is blind to the second one going missing.
 * - **Priority zero.** `has_filter()` returns `0` for a callback registered at priority
 *   0 and `false` for one that is absent. `Shortcode_Handler::PRIORITY_STRIP_BODY` is 0,
 *   so the two answers are one `if` away from being read as the same thing. A named
 *   assertion cannot make that mistake.
 *
 * Nothing here knows anything about this plugin. It reads `$GLOBALS['wp_filter']` and
 * nothing else, so any test class can use it.
 */
trait Hook_Test_Helpers {

	/**
	 * Method to list every priority a callback is registered at on one hook.
	 *
	 * The callback is compared with `===` against the one WordPress stored, which is
	 * exact for the `[ $object, 'method' ]` pair this plugin registers, for a plain
	 * function name and for a closure. Core's own `_wp_filter_build_unique_id()` would
	 * do the same job and would tie these tests to the shape of a private helper.
	 *
	 * @param string $hook     Name of the action or filter.
	 * @param mixed  $callback The callback, exactly as it was handed to `add_action()`.
	 *
	 * @return array Numerically indexed list of priorities, ascending. Empty when the
	 *               callback is not registered, or when nothing has ever used the hook.
	 */
	protected function _hooked_priorities( string $hook, mixed $callback ): array {

		$registry = $GLOBALS['wp_filter'][ $hook ] ?? null;

		if ( ! $registry instanceof WP_Hook ) {
			return [];
		}

		$priorities = [];

		foreach ( $registry->callbacks as $priority => $callbacks ) {

			foreach ( $callbacks as $registered ) {

				if ( ( $registered['function'] ?? null ) !== $callback ) {
					continue;
				}

				$priorities[] = (int) $priority;

			}
		}

		sort( $priorities, SORT_NUMERIC );

		return $priorities;

	}

	/**
	 * Method to assert that a callback is registered on a hook.
	 *
	 * @param string   $hook     Name of the action or filter.
	 * @param mixed    $callback The callback, exactly as it was handed to `add_action()`.
	 * @param int|null $priority Priority it must sit at, or NULL to accept any.
	 * @param string   $message  Message to show when the assertion fails.
	 *
	 * @return void
	 */
	protected function _assert_hooked( string $hook, mixed $callback, ?int $priority = null, string $message = '' ): void {

		$priorities = $this->_hooked_priorities( $hook, $callback );

		if ( null === $priority ) {

			$this->assertNotEmpty(
				$priorities,
				( '' !== $message ) ? $message : sprintf( 'The callback is registered on %s.', $hook )
			);

			return;

		}

		$this->assertContains(
			$priority,
			$priorities,
			( '' !== $message ) ? $message : sprintf( 'The callback is registered on %1$s at priority %2$d.', $hook, $priority )
		);

	}

	/**
	 * Method to assert that a callback is not registered on a hook.
	 *
	 * The priorities are compared rather than counted, so a failure says which
	 * priorities the callback was found at instead of only that it was found.
	 *
	 * @param string $hook     Name of the action or filter.
	 * @param mixed  $callback The callback, exactly as it was handed to `add_action()`.
	 * @param string $message  Message to show when the assertion fails.
	 *
	 * @return void
	 */
	protected function _assert_not_hooked( string $hook, mixed $callback, string $message = '' ): void {

		$this->assertSame(
			[],
			$this->_hooked_priorities( $hook, $callback ),
			( '' !== $message ) ? $message : sprintf( 'The callback is not registered on %s.', $hook )
		);

	}

}    //end of trait


//EOF
