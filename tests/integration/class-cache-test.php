<?php
/**
 * What the cache does when the callback which fills it does not come back with a
 * dataset.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Cache;
use Error;
use RuntimeException;
use WP_UnitTestCase;

/**
 * The cache sits underneath the language registry, which sits underneath the
 * renderer, which runs on `the_content`. A failure that escapes it, or one it
 * writes down and then serves, is a front end failure, so both are pinned here.
 */
class Cache_Test extends WP_UnitTestCase {

	/**
	 * Cache key these tests store under.
	 *
	 * @var string
	 */
	protected const string _KEY = 'ig-syntax-hiliter-cache-failure-test';

	/**
	 * Takes away everything a test stored.
	 *
	 * @return void
	 */
	public function tear_down(): void {

		Cache::create( self::_KEY )->delete();

		parent::tear_down();

	}

	/**
	 * Method to get the option name a cache key is stored under.
	 *
	 * @param string $key Cache key.
	 *
	 * @return string
	 */
	protected function _option_name( string $key ): string {
		return Cache::KEY_PREFIX . md5( $key );
	}

	/**
	 * An `Error` raised while the cache is being filled does not escape it.
	 *
	 * The cache catches `Throwable`, not `Exception`: a type error in somebody else's
	 * callback would otherwise travel out through `the_content` and white-screen the page.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_does_not_let_an_error_while_filling_the_cache_escape(): void {

		$result = Cache::create( self::_KEY )
						->expires_in( DAY_IN_SECONDS )
						->updates_with(
							static function (): array {
								throw new Error( 'the build blew up' );
							}
						)
						->get();

		$this->assertFalse( $result, 'A cache which could not be filled says so; it does not take the page down with it.' );

	}

	/**
	 * A build which produced nothing is not written down as though it had.
	 *
	 * A stored failure carries the full expiry, so an empty registry would be served
	 * until it ran out instead of being rebuilt on the next request.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_does_not_write_a_failed_build_down(): void {

		Cache::create( self::_KEY )
			->expires_in( DAY_IN_SECONDS )
			->updates_with(
				static function (): array {
					throw new RuntimeException( 'no dataset today' );
				}
			)
			->get();

		$this->assertFalse( get_option( $this->_option_name( self::_KEY ) ), 'Nothing was left behind to be served.' );

		// So the next request builds it, and gets the real thing.
		$data = Cache::create( self::_KEY )
					->expires_in( DAY_IN_SECONDS )
					->updates_with(
						static function (): array {
							return [ 'languages' => [ 'php' ] ];
						}
					)
					->get();

		$this->assertSame( [ 'languages' => [ 'php' ] ], $data, 'A failure does not outlive itself.' );

	}

	/**
	 * A refresh which fails leaves the dataset that was already there alone.
	 *
	 * Overwriting it with the failure would lose a good cached registry the first time
	 * a rebuild went wrong.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_leaves_the_last_good_dataset_in_place_after_a_failed_refresh(): void {

		$good   = [ 'languages' => [ 'php', 'ruby' ] ];
		$option = $this->_option_name( self::_KEY );

		Cache::create( self::_KEY )
			->expires_in( DAY_IN_SECONDS )
			->updates_with(
				static function () use ( $good ): array {
					return $good;
				}
			)
			->get();

		// Age the stored entry, so that the next read has to go and refresh it.
		$stored = get_option( $option );

		$this->assertIsArray( $stored, 'The good dataset was cached to begin with.' );

		$stored['expiry'] = ( time() - MINUTE_IN_SECONDS );

		update_option( $option, $stored, false );

		$result = Cache::create( self::_KEY )
						->expires_in( DAY_IN_SECONDS )
						->updates_with(
							static function (): array {
								throw new RuntimeException( 'no dataset today' );
							}
						)
						->get();

		$this->assertSame( $good, $result, 'What was there before is served, stale, rather than being replaced with nothing.' );

	}

} // end of class

// EOF
