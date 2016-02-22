<?php
/**
 * Plugin Name: WP REST API Authentication Broker
 * Description: Allow bootstrapping site authentication via a central registry.
 */

add_action( 'rest_api_init', 'rest_broker_register_routes' );

function rest_broker_register_routes() {
	if ( empty( $GLOBALS['wp_json_authentication_oauth1'] ) ) {
		// OAuth must be enabled for the broker to actually do anything
		return;
	}

	$broker = new WP_REST_AuthBroker();

	register_rest_route( 'broker/v1', '/connect', array(
		array(
			'methods' => 'GET',
			'callback' => array( $broker, 'get_connect_information' ),
		),
		array(
			'methods' => 'POST',
			'callback' => array( $broker, 'handle_connect' ),
			'args' => array(
				'client_id' => array(
					'required' => true,
				),
				'broker' => array(
					'required' => true,
				),
				'verifier' => array(
					'required' => true,
				),
				'client_name' => array(),
				'client_description' => array(),
				'client_details' => array(),
			),
		),
	));

	add_action( 'rest_index', array( $broker, 'add_index_link' ) );
}

class WP_REST_AuthBroker {
	protected $data;

	public function get_known_brokers() {
		$brokers = array(
			'https://w.org/' => 'https://api.wordpress.org/broker/1.0/verify',
		);

		/**
		 * Filter the known brokers on the server.
		 *
		 * @param array $brokers Map of Broker ID => verification endpoint
		 */
		return apply_filters( 'rest_broker_known_brokers', $brokers );
	}

	public function add_index_link( WP_REST_Response $index ) {
		$data = $index->get_data();
		$data['authentication']['broker'] = rest_url( 'broker/v1/connect' );
		$index->set_data( $data );

		return $index;
	}

	public function get_connect_information( WP_REST_Request $request ) {
		$response = new WP_REST_Response();
		$response->set_data('This endpoint is used for the Brokered Authentication protocol.');
		$response->header('X-BA-Endpoint', 'connection-request');
		return $response;
	}

	public function handle_connect( WP_REST_Request $request ) {
		$broker = $request['broker'];
		$client = $request['client_id'];
		$verifier = $request['verifier'];

		// TODO: move to arg validation
		if ( strlen( $client ) < 1 || strlen( $client ) > 255 ) {
			$data = array( 'status' => WP_Http::BAD_REQUEST );
			return new WP_Error( 'ba.invalid_client_id', __( 'Invalid client ID.', 'rest_broker' ), $data );
		}
		if ( strlen( $verifier ) < 1 || strlen( $verifier ) > 255 || preg_match( '/[a-zA-Z0-9]/', $verifier ) !== 0 ) {
			$data = array( 'status' => WP_Http::BAD_REQUEST );
			return new WP_Error( 'ba.invalid_verifier', __( 'Invalid verifier code.', 'rest_broker' ), $data );
		}

		// Step 1: Check the broker is known
		$known = $this->get_known_brokers();
		if ( ! isset( $known[ $broker ] ) ) {
			$data = array( 'status' => WP_Http::BAD_REQUEST );
			return new WP_Error( 'ba.unknown_broker', __( 'Unknown broker ID.', 'rest_broker' ), $data );
		}

		// Step 2: Check whether the client ID is whitelisted or blacklisted
		/**
		 * Filter whether a client is allowed to connect via a broker.
		 *
		 * @param bool $allow_client True if the client is allowed, false if the client is not allowed.
		 * @param string $client Client identifier.
		 */
		$allow_client = apply_filters( 'rest_broker_allow_client', true, $client );
		if ( ! $allow_client ) {
			$data = array( 'status' => WP_Http::BAD_REQUEST );
			return new WP_Error( 'ba.rejected_client', __( 'Client ID is not allowed for this site.', 'rest_broker' ), $data );
		}

		// Step 3: Enqueue action
		$this->data = array(
			'client'     => $client,
			'verifier'   => $verifier,
			'broker'     => $broker,
			'broker_url' => $known[ $broker ],
		);
		foreach ( array( 'client_name', 'client_description', 'client_details' ) as $key ) {
			if ( ! empty( $request[ $key ] ) ) {
				$this->data[ $key ] = $request[ $key ];
			} else {
				$this->data[ $key ] = null;
			}
		}

		add_action( 'shutdown', array( $this, 'issue_and_verify' ), -10 );

		// Step 3: Send response
		$response = new WP_REST_Response();
		$response->set_status( 202 );
		return $response;
	}

	public function issue_and_verify() {
		/** @var WP_REST_OAuth1 */
		$oauth = $GLOBALS['wp_json_authentication_oauth1'];

		// Generate a unique key and secret
		do {
			$key = wp_generate_password( self::CONSUMER_KEY_LENGTH, false );
			$secret = wp_generate_password( self::CONSUMER_SECRET_LENGTH, false );
		} while ( ! is_wp_error( $oauth->get_by_key( $key ) ) );

		$params = array(
			'verifier'      => $this->data['verifier'],
			'client_id'     => $this->data['client_id'],
			'client_token'  => $key,
			'client_secret' => $secret,
		);
		$url = $this->data['broker_url'];
		$options = array(
			'body' => build_query( $params ),
		);

		$response = wp_remote_post( $url, $options );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			// Could not verify, quit.
			return;
		}

		// Verified, time to create the client.
		$client_params = array(
			'key'         => $key,
			'secret'      => $secret,
			'name'        => $this->data['client_name'] ? $this->data['client_name'] : 'Unknown',
			'description' => $this->data['client_description'] ? $this->data['client_description'] : 'Unknown',
			'meta'        => array(
				'broker_detail_url' => $this->data['client_details'],
			),
		);
		$client = WP_REST_OAuth1_Client::create( $client_params );
	}
}
