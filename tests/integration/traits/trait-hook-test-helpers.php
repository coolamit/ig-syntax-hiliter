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
 * Exists for what `has_action()`/`has_filter()` cannot answer: every priority
 * rather than the first; a priority-0 registration told apart from an absent one;
 * how many arguments a callback asked for. Reads `$GLOBALS['wp_filter']` and
 * nothing else.
 */
trait Hook_Test_Helpers {

	/**
	 * Method to list every priority a callback is registered at on one hook.
	 *
	 * Compared with `===`, which is exact for the `[ $object, 'method' ]` pairs this
	 * plugin registers.
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
	 * Method to read how many arguments a registered callback asked for.
	 *
	 * A registration right about hook and priority is still broken if it asked for
	 * one argument where the callback takes two.
	 *
	 * @param string $hook     Name of the action or filter.
	 * @param mixed  $callback The callback, exactly as it was handed to `add_action()`.
	 * @param int    $priority Priority the callback is registered at.
	 *
	 * @return int|null Accepted argument count, or NULL where the callback is not
	 *                  registered on that hook at that priority.
	 */
	protected function _hooked_accepted_args( string $hook, mixed $callback, int $priority ): ?int {

		$registry = $GLOBALS['wp_filter'][ $hook ] ?? null;

		if ( ! $registry instanceof WP_Hook ) {
			return null;
		}

		foreach ( ( $registry->callbacks[ $priority ] ?? [] ) as $registered ) {

			if ( ( $registered['function'] ?? null ) !== $callback ) {
				continue;
			}

			return (int) $registered['accepted_args'];

		}

		return null;

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
				( ! empty( $message ) ) ? $message : sprintf( 'The callback is registered on %s.', $hook )
			);

			return;

		}

		$this->assertContains(
			$priority,
			$priorities,
			( ! empty( $message ) ) ? $message : sprintf( 'The callback is registered on %1$s at priority %2$d.', $hook, $priority )
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
			( ! empty( $message ) ) ? $message : sprintf( 'The callback is not registered on %s.', $hook )
		);

	}

} // end of trait

// EOF
