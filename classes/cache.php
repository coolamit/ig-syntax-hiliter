<?php
/**
 * Cache Class
 *
 * @author Amit Gupta <http://amitgupta.in/>
 */

namespace iG\Syntax_Hiliter;

use \ErrorException;

class Cache {

	const KEY_PREFIX = 'igsh-cache-';

	protected $_key;
	protected $_expiry = 1800;	//30 minutes, default expiry
	protected $_callback;
	protected $_params = array();

	protected $_cache;

	protected $_default_storage_format = array(
		'expiry'   => 0,
		'callback' => '',
		'params'   => array(),
		'data'     => '',
	);

	/**
	 * @param String $cache_key A mixed character string for use as unique identifier for current dataset stored in cache
	 */
	public function __construct( $cache_key ) {

		if ( empty( $cache_key ) || ! is_string( $cache_key ) ) {

			throw new ErrorException( 'Cache key is required to create ' . __CLASS__ . ' object' );

		}

		$this->_key = self::KEY_PREFIX . md5( $cache_key );

	}

	/**
	 * Factory method to facilitate single call data fetch using method chaining
	 *
	 * @param String $cache_key A mixed character string for use as unique identifier for current dataset stored in cache
	 * @return iG\Syntax_Hiliter\Cache
	 */
	public static function create( $cache_key ) {
		$class = __CLASS__;

		return new $class( $cache_key );
	}

	/**
	 * This function is for deleting the cache
	 *
	 * @return iG\Syntax_Hiliter\Cache
	 */
	public function delete() {
		delete_option( $this->_key );

		return $this;
	}

	/**
	 * This function accepts the cache expiry
	 *
	 * @return iG\Syntax_Hiliter\Cache
	 */
	public function expires_in( $expiry ) {
		$expiry = intval( $expiry );

		if ( $expiry > 0 ) {
			$this->_expiry = $expiry;
		}

		unset( $expiry );

		return $this;
	}

	/**
	 * This function accepts the callback from which data is to be received
	 *
	 * @return iG\Syntax_Hiliter\Cache
	 */
	public function updates_with( $callback, array $params = array() ) {

		if ( empty( $callback ) || ! is_callable( $callback ) ) {

			throw new ErrorException( 'Callback passed is not callable' );

		}

		$this->_callback = $callback;
		$this->_params = $params;

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

	protected function _get_cache() {

		if ( is_bool( $this->_cache ) || ( is_array( $this->_cache ) && ! empty( $this->_cache ) ) ) {
			return $this->_cache;
		}

		$this->_cache = get_option( $this->_key );

		if ( is_array( $this->_cache ) ) {
			return $this->_cache;
		}

		return false;

	}

	protected function _set_cache() {
		//delete existing cache
		$this->delete();

		//set new cache array
		//we want it autoloaded hence the use of update_option()
		update_option( $this->_key, $this->_cache );
	}

	protected function _has_expired() {
		$cache = $this->_get_cache();

		if ( is_array( $cache ) && ! empty( $cache ) ) {

			if ( ! empty( $cache['expiry'] ) && time() < intval( $cache['expiry'] ) ) {
				//cache has not expired, yet
				return false;
			}

		}

		//cache has expired
		return true;
	}

	protected function _refresh_cache() {
		$cache = array(
			'expiry'   => ( time() + $this->_expiry ),
			'callback' => $this->_callback,
			'params'   => $this->_params,
			'data'     => '',
		);

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
		} catch( \Exception $e ) {
			$cache['data'] = false;
		}

		$this->_cache = wp_parse_args( $cache, $this->_default_storage_format );

		$this->_set_cache();

		unset( $cache );
	}

}	//end of class


//EOF