<?php
/**
 * Class for creating and sending an AJAX response
 *
 * @author Amit Gupta <https://amitgupta.in/>
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
	protected $_response = [
		'nonce' => '',
		'error' => 1,    //lets assume there's error
		'msg'   => '',
	];

	/**
	 * Class constructor
	 */
	public function __construct() {}

	/**
	 * Method to add data to be sent to browser
	 *
	 * @param string $key   Key name to identify data
	 * @param mixed  $value Data value
	 *
	 * @return void
	 */
	public function add( string $key, $value ) : void {
		$this->_response[ sanitize_key( $key ) ] = $value;
	}

	/**
	 * Method to generate nonce to be sent to browser
	 *
	 * @param string $key Key to generate nonce
	 *
	 * @return void
	 */
	public function add_nonce( string $key ) : void {

		if ( empty( $key ) ) {
			return;
		}

		$this->add( 'nonce', wp_create_nonce( $key ) );

	}

	/**
	 * Method to add a message to be sent to browser
	 *
	 * @param string $message
	 * @param string $type    Type of message, either SUCCESS or ERROR
	 *
	 * @return bool Returns TRUE if message added successfully else FALSE
	 */
	public function add_message( string $message, string $type = 'success' ) : bool {

		if ( empty( $message ) ) {
			return false;
		}

		$type = ( strtolower( trim( $type ) ) === 'success' ) ? 'success' : 'error';    //type can only be either one

		$this->add(
			'msg',
			sprintf(
				self::MESSAGE_TEMPLATE,
				esc_attr( $type ),
				esc_html( $message ) )
		);

		return true;

	}

	/**
	 * Method to add error message to be sent to browser
	 *
	 * @param string $message
	 *
	 * @return void
	 */
	public function add_error( string $message ) : void {
		if ( $this->add_message( $message, 'error' ) ) {
			$this->add( 'error', 1 );
		}
	}

	/**
	 * Method to add success message to be sent to browser
	 *
	 * @param string $message
	 *
	 * @return void
	 */
	public function add_success( string $message ) : void {
		if ( $this->add_message( $message, 'success' ) ) {
			$this->add( 'error', 0 );
		}
	}

	/**
	 * Method to send data to browser
	 *
	 * @return void
	 */
	public function send() : void {

		header( "Content-Type: application/json" );

		echo wp_json_encode( $this->_response );    //we want json

		wp_die( '', '', 200 );    //send 200 HTTP Status back

	}

}    //end of class

//EOF
