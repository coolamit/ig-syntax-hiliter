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
use iG\Syntax_Hiliter\Language_Registry;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use Error;
use ReflectionMethod;
use RuntimeException;
use WP_UnitTestCase;

/**
 * The cache sits underneath the language registry, which sits underneath the
 * renderer, which runs on `the_content`. A failure that escapes it, or one it
 * writes down and then serves, is a front end failure — so both are pinned here
 * rather than left to whichever caller happens to notice.
 */
class Cache_Failure_Test extends WP_UnitTestCase {

	use Pipeline_Test_Helpers;

	/**
	 * Cache key these tests store under.
	 *
	 * @var string
	 */
	protected const string _KEY = 'ig-syntax-hiliter-cache-failure-test';

	/**
	 * Registry cache key a test poisoned, cleared away afterwards.
	 *
	 * @var string
	 */
	protected string $_registry_cache_key = '';

	/**
	 * Takes away everything a test stored.
	 *
	 * @return void
	 */
	public function tear_down(): void {

		Cache::create( self::_KEY )->delete();

		if ( ! empty( $this->_registry_cache_key ) ) {

			Cache::create( $this->_registry_cache_key )->delete();

			$this->_registry_cache_key = '';

		}

		$this->_set_singleton( Language_Registry::class, null );

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
	 * The cache used to catch `Exception`, which an `Error` is not. A type error in
	 * somebody else's callback — or in the plugin's own build — therefore travelled
	 * out through the language registry, out through the renderer and out through
	 * `the_content`, and the page it was rendering became a white screen.
	 *
	 * @return void
	 */
	public function test_an_error_while_filling_the_cache_does_not_escape_it(): void {

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
	 * A failure used to be stored — an empty dataset carrying the full expiry — so a
	 * cache with a year on it, which is what the language registry asks for, served
	 * that emptiness for a year. Every request after the one that went wrong was
	 * answered with the failure rather than being allowed to try again.
	 *
	 * @return void
	 */
	public function test_a_failed_build_is_not_written_down(): void {

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
	 * Overwriting it with the failure is the same defect seen from the other side:
	 * a site with a perfectly good cached registry lost it the first time anything
	 * went wrong while it was being rebuilt.
	 *
	 * @return void
	 */
	public function test_a_failed_refresh_leaves_the_last_good_dataset_in_place(): void {

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

	/**
	 * A registry cache entry holding something which is not a registry is rebuilt.
	 *
	 * This is a control: it passes against the code as it was too. It is here
	 * because it is the state every site which hit the defect above is sitting in —
	 * an empty dataset under the registry's key with a year still to run — and
	 * reading that back as an empty registry would mean no language on the site
	 * resolving, every snippet rendering unhighlighted, until the year was up.
	 *
	 * @return void
	 */
	public function test_a_registry_cache_entry_which_is_not_a_registry_is_rebuilt(): void {

		$this->_set_singleton( Language_Registry::class, null );

		$this->_registry_cache_key = (string) ( new ReflectionMethod( Language_Registry::class, '_get_cache_key' ) )->invoke( null );

		update_option(
			$this->_option_name( $this->_registry_cache_key ),
			[
				'expiry' => ( time() + YEAR_IN_SECONDS ),
				'data'   => '',
			],
			false
		);

		$registry = Language_Registry::get_instance();

		$this->assertTrue( $registry->has( 'php' ), 'The registry is built from the bundled library rather than read back empty.' );
		$this->assertGreaterThan( 250, count( $registry->get_languages() ) );

	}

}    //end of class


//EOF
