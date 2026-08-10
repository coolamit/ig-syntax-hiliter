<?php
/**
 * Class for caching data in the options table.
 *
 * @package iG_Syntax_Hiliter
 *
 * @author Amit Gupta <https://amitgupta.in/>
 */

namespace iG\Syntax_Hiliter;

use Throwable;
use ErrorException;

/**
 * A cache which keeps one dataset in one option, with an expiry and a callback
 * which refills it.
 *
 * It is built by chaining, and nothing is read or written until `get()` is called:
 * `Cache::create( $key )->expires_in( $seconds )->updates_with( $callback )->get()`.
 * `delete()` is the exception — it removes the option the moment it is called.
 * The data and the timestamp it expires at are stored together, in an option named
 * after an MD5 of the cache key, which is not autoloaded.
 */
class Cache {

	/**
	 * Prefix of the option name every cached dataset is stored under.
	 *
	 * @var string
	 */
	const KEY_PREFIX = 'igsh-cache-';

	/**
	 * Shortest expiry, in seconds, that `expires_in()` will set. Two minutes.
	 *
	 * @var int
	 */
	const MIN_EXPIRY = 120;

	/**
	 * Name of the option this dataset is stored under.
	 *
	 * @var string
	 */
	protected $_key;

	/**
	 * How long a cached dataset stays fresh, in seconds. Half an hour by default.
	 *
	 * @var int
	 */
	protected $_expiry = 1800;

	/**
	 * Callable which produces a fresh dataset once the cached one has expired.
	 *
	 * @var callable|null
	 */
	protected $_callback;

	/**
	 * Arguments passed to the callback.
	 *
	 * @var array
	 */
	protected $_params = [];

	/**
	 * In-memory copy of the stored cache entry. Whatever storage handed back, so
	 * it is only an array once an entry has been read or built.
	 *
	 * @var mixed
	 */
	protected $_cache;

	/**
	 * Shape of a stored cache entry. Merged in before the entry is saved.
	 *
	 * @var array
	 */
	protected $_default_storage_format = [
		'expiry' => 0,
		'data'   => '',
	];

	/**
	 * Class constructor
	 *
	 * @param string $cache_key A string for use as unique identifier for current dataset stored in cache.
	 *
	 * @throws \ErrorException If the cache key is empty by `empty()`, so both '' and '0', since there is then no option name to store the dataset under.
	 */
	public function __construct( string $cache_key ) {

		if ( empty( $cache_key ) ) {

			throw new ErrorException(
				sprintf(
					'Cache key is required to create %s object',
					__CLASS__
				)
			);

		}

		$this->_key = self::KEY_PREFIX . md5( $cache_key );

	}

	/**
	 * Factory method to facilitate single call data fetch using method chaining
	 *
	 * @param string $cache_key A string for use as unique identifier for current dataset stored in cache.
	 *
	 * @return \iG\Syntax_Hiliter\Cache
	 *
	 * @throws \ErrorException If the cache key is empty by `empty()`, so both '' and '0'. Raised by the constructor.
	 */
	public static function create( string $cache_key ): self {
		return new self( $cache_key );
	}

	/**
	 * This function is for deleting the cache
	 *
	 * @return \iG\Syntax_Hiliter\Cache
	 */
	public function delete(): self {
		delete_option( $this->_key );

		return $this;
	}

	/**
	 * This function accepts the cache expiry
	 *
	 * @param int $expiry How long the dataset stays fresh, in seconds. Anything below `self::MIN_EXPIRY` is raised to it, and zero or less is ignored, leaving whatever expiry is in place.
	 *
	 * @return \iG\Syntax_Hiliter\Cache
	 */
	public function expires_in( int $expiry ): self {

		if ( 0 < $expiry ) {
			$this->_expiry = max( $expiry, self::MIN_EXPIRY );
		}

		return $this;

	}

	/**
	 * This function accepts the callback from which data is to be received
	 *
	 * @param callable $callback Callable which returns the dataset to cache.
	 * @param array    $params   Optional. Arguments to pass to the callback.
	 *
	 * @return \iG\Syntax_Hiliter\Cache
	 */
	public function updates_with( callable $callback, array $params = [] ): self {

		$this->_callback = $callback;
		$this->_params   = $params;

		return $this;

	}

	/**
	 * This function returns the data from cache if it exists or returns the
	 * data it gets back from the callback and caches it as well
	 *
	 * @return mixed Returns data stored in cache or FALSE if no data/cache found. If the dataset had expired and the callback failed to produce a new one, whatever was stored before is returned, stale, and FALSE when there was nothing.
	 *
	 * @throws \ErrorException If the cached dataset has expired and no usable callback has been set. Raised by `_refresh_cache()`.
	 */
	public function get() {

		if ( $this->_has_expired() ) {
			$this->_refresh_cache();
		}

		$cache = $this->_get_cache();

		if ( isset( $cache['data'] ) ) {
			return $cache['data'];
		}

		return false;

	}

	/**
	 * Method to grab the cache array from storage
	 *
	 * @return array
	 */
	protected function _get_cache(): array {

		if ( is_array( $this->_cache ) && ! empty( $this->_cache ) ) {
			return $this->_cache;
		}

		$this->_cache = get_option( $this->_key );

		if ( is_array( $this->_cache ) ) {
			return $this->_cache;
		}

		return [];

	}

	/**
	 * Method to save cache array in storage
	 *
	 * @return void
	 */
	protected function _set_cache(): void {

		if ( ! is_array( $this->_cache ) || empty( $this->_cache ) ) {
			return;
		}

		//delete existing cache
		$this->delete();

		//not autoloaded: the language registry alone is ~33KB and is only read when a snippet renders
		update_option( $this->_key, $this->_cache, false );

	}

	/**
	 * Method to check if cache has expired or not
	 *
	 * @return bool
	 */
	protected function _has_expired(): bool {

		$cache = $this->_get_cache();

		if ( isset( $cache['expiry'] ) && time() < intval( $cache['expiry'] ) ) {
			//cache has not expired, yet
			return false;
		}

		//cache has expired
		return true;

	}

	/**
	 * Method which refreshes cached data
	 *
	 * A callback which throws anything at all leaves storage untouched and is not an
	 * error here; only a missing callback is.
	 *
	 * @return void
	 *
	 * @throws \ErrorException If no usable callback has been set, ie. `updates_with()` was not called before `get()`.
	 */
	protected function _refresh_cache(): void {
		/*
		 * If we don't have a callback to get data from or if it's not a valid
		 * callback then throw an exception. This will happen in the case when
		 * updates_with() is not called before get()
		 */
		if ( empty( $this->_callback ) || ! is_callable( $this->_callback ) ) {
			throw new ErrorException( 'No valid callback set' );
		}

		try {

			$data = call_user_func_array( $this->_callback, $this->_params );

		} catch ( Throwable $e ) {
			/*
			 * Throwable, not Exception: a TypeError raised inside somebody else's hook
			 * is every bit as likely as an exception, and letting one out of here puts
			 * a fatal on whatever page was being rendered.
			 *
			 * Nothing is stored, either. The callback produced no dataset, and writing
			 * the empty one down would leave every read until the expiry ran out being
			 * served a failure that has already stopped happening. Leaving the store
			 * alone means the caller gets whatever was there before, and the next
			 * request tries again.
			 */
			return;

		}

		$this->_cache = wp_parse_args(
			[
				'expiry' => ( time() + $this->_expiry ),
				'data'   => $data,
			],
			$this->_default_storage_format
		);

		$this->_set_cache();

	}

}    //end of class

//EOF
