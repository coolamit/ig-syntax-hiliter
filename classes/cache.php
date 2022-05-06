<?php
/**
 * Cache Class
 *
 * @author Amit Gupta <http://amitgupta.in/>
 */

namespace iG\Syntax_Hiliter;

use \Exception;
use \ErrorException;

class Cache {

	const KEY_PREFIX = 'igsh-cache-';

	const MIN_EXPIRY = 120;    // 2 minutes

	protected $_key;

	protected $_expiry = 1800;    // 30 minutes, default expiry

	protected $_callback;

	protected $_params = [];

	protected $_cache;

	protected $_default_storage_format = [
		'expiry' => 0,
		'data'   => '',
	];

	/**
	 * Class constructor
	 *
	 * @param string $cache_key A string for use as unique identifier for current dataset stored in cache
	 *
	 * @throws \ErrorException
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
	 * @param string $cache_key A string for use as unique identifier for current dataset stored in cache
	 *
	 * @return \iG\Syntax_Hiliter\Cache
	 *
	 * @throws \ErrorException
	 */
	public static function create( string $cache_key ) : self {
		return new self( $cache_key );
	}

	/**
	 * This function is for deleting the cache
	 *
	 * @return \iG\Syntax_Hiliter\Cache
	 */
	public function delete() : self {
		delete_option( $this->_key );

		return $this;
	}

	/**
	 * This function accepts the cache expiry
	 *
	 * @return \iG\Syntax_Hiliter\Cache
	 */
	public function expires_in( int $expiry ) : self {

		if ( 0 < $expiry ) {
			$this->_expiry = max( $expiry, self::MIN_EXPIRY );
		}

		return $this;

	}

	/**
	 * This function accepts the callback from which data is to be received
	 *
	 * @param callable $callback
	 * @param array    $params
	 *
	 * @return \iG\Syntax_Hiliter\Cache
	 *
	 * @throws \ErrorException
	 */
	public function updates_with( callable $callback, array $params = [] ) : self {

		if ( empty( $callback ) ) {
			throw new ErrorException( 'Callback passed is not callable' );
		}

		$this->_callback = $callback;
		$this->_params   = $params;

		return $this;

	}

	/**
	 * This function returns the data from cache if it exists or returns the
	 * data it gets back from the callback and caches it as well
	 *
	 * @return mixed Returns data stored in cache or FALSE if no data/cache found
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
	protected function _get_cache() : array {

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
	protected function _set_cache() : void {

		if ( ! is_array( $this->_cache ) || empty( $this->_cache ) ) {
			return;
		}

		//delete existing cache
		$this->delete();

		//set new cache array
		//we want it autoloaded hence the use of update_option()
		update_option( $this->_key, $this->_cache );

	}

	/**
	 * Method to check if cache has expired or not
	 *
	 * @return bool
	 */
	protected function _has_expired() : bool {

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
	 * @return void
	 *
	 * @throws \ErrorException
	 */
	protected function _refresh_cache() : void {

		$cache = [
			'expiry' => ( time() + $this->_expiry ),
			'data'   => '',
		];

		/*
		 * If we don't have a callback to get data from or if it's not a valid
		 * callback then throw an exception. This will happen in the case when
		 * updates_with() is not called before get()
		 */
		if ( empty( $this->_callback ) || ! is_callable( $this->_callback ) ) {
			throw new ErrorException( 'No valid callback set' );
		}

		try {
			$cache['data'] = call_user_func_array( $this->_callback, $this->_params );
		} catch( Exception $e ) {
			$cache['data'] = '';
		}

		$this->_cache = wp_parse_args( $cache, $this->_default_storage_format );

		$this->_set_cache();

	}

}    //end of class

//EOF
