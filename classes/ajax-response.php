<?php
/**
 * Class for creating and sending an AJAX response
 *
 * @author Amit Gupta <http://amitgupta.in/>
 *
 * @since 2015-07-22
 */

namespace iG\Syntax_Hiliter;

class Ajax_Response {

	/**
	 * @var string HTML template for messages sent to browser
	 */
	const MESSAGE_TEMPLATE = '<span class="ig-sh-%s">%s</span>';

	/**
	 * @var array Array of data to be sent to browser
	 */
	protected $_response = array(
		'nonce' => '',
		'error' => 1,	//lets assume there's error
		'msg'   => '',
	);

	/**
	 * constructor
	 */
	public function __construct() {}

	/**
	 * Function to add data to be sent to browser
	 *
	 * @param string $key Key name to identify data
	 * @param string $value Data value
	 * @return void
	 */
	public function add( $key, $value ) {
		$this->_response[ sanitize_key( $key ) ] = $value;
	}

	/**
	 * Function to nonce to be sent to browser
	 *
	 * @param string $key Key to generate nonce
	 * @return void
	 */
	public function add_nonce( $key ) {
		if ( empty( $key ) || ! is_string( $key ) ) {
			return;
		}

		$this->add( 'nonce', wp_create_nonce( $key ) );
	}

	/**
	 * Function to add a message to be sent to browser
	 *
	 * @param string $message
	 * @param string $type Type of message, either SUCCESS or ERROR
	 * @return boolean Returns TRUE if message added successfully else FALSE
	 */
	public function add_message( $message, $type = 'success' ) {
		if ( empty( $message ) || ! is_string( $message ) ) {
			return false;
		}

		$type = ( strtolower( trim( $type ) ) === 'success' ) ? 'success' : 'error';	//type can only be either one

		$this->add( 'msg', sprintf( self::MESSAGE_TEMPLATE, esc_attr( $type ), esc_html( $message ) ) );

		return true;
	}

	/**
	 * Function to add error message to be sent to browser
	 *
	 * @param string $message
	 * @return void
	 */
	public function add_error( $message ) {
		if ( $this->add_message( $message, 'error' ) ) {
			$this->add( 'error', 1 );
		}
	}

	/**
	 * Function to add success message to be sent to browser
	 *
	 * @param string $message
	 * @return void
	 */
	public function add_success( $message ) {
		if ( $this->add_message( $message, 'success' ) ) {
			$this->add( 'error', 0 );
		}
	}

	/**
	 * Function to send data to browser
	 *
	 * @return void
	 */
	public function send() {
		header( "Content-Type: application/json" );

		echo json_encode( $this->_response );		//we want json

		wp_die( '', '', 200 );	//send 200 HTTP Status back
	}

}	//end of class


//EOF