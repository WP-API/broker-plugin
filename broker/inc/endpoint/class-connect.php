<?php

namespace AuthBroker\Endpoint;

use WordPress\Discovery;
use WP_Error;
use WP_REST_OAuth1_Client;

class Connect extends Base {
	const SEC_IN_USEC = 1e6;

	/**
	 * How often should we check for a response?
	 *
	 * @var int Frequency in Hertz (iterations per second)
	 */
	protected $check_frequency = 10;

	/**
	 * How long should we wait for?
	 *
	 * @var int Maximum waiting time in seconds, measured since start of the request.
	 */
	protected $timeout = 25;

	protected function check_client_authentication() {
		$authenticator = $GLOBALS['wp_json_authentication_oauth1'];
		$params = $authenticator->get_parameters( false );

		$consumer = WP_REST_OAuth1_Client::get_by_key( $params['oauth_consumer_key'] );
		if ( is_wp_error( $consumer ) ) {
			return $consumer;
		}

		// Check the OAuth request signature against the current request
		$result = $authenticator->check_oauth_signature( $consumer, $params );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$error = $authenticator->check_oauth_timestamp_and_nonce(
			$consumer,
			$params['oauth_timestamp'],
			$params['oauth_nonce']
		);

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		return $consumer;
	}

	public function run( $params ) {
		// Step 1: Check authentication
		$client = $this->check_client_authentication();
		if ( is_wp_error( $client ) ) {
			// TODO: error
			echo 'Client authentication failed';
			return;
		}

		// Authentication succeeded, send headers.
		header('Content-Type: application/json');
		if ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
		flush();

		$this->key = wp_generate_password( 8, false, false );
		$this->log_event( 'Processing' );

		// Step 2: Run autodiscovery
		$site = Discovery\discover( $params['server_url'] );
		if ( empty( $site ) ) {
			// TODO: error
			echo 'Could not discover API';
			return;
		}
		if ( ! $site->supportsAuthentication( 'broker' ) ) {
			echo 'Site does not support broker authentication.';
			return;
		}

		// Get the broker connection endpoint from the index
		$url = $site->getAuthenticationData( 'broker' );

		// Step 3: Trigger non-blocking request to Server
		$this->set_state( 'connect' );
		$data = array(
			'client_id'  => $client->key,
			'server_url' => $url,
		);
		$this->set_data( $data );

		wp_remote_post( home_url( '/broker/trigger_verification/' ), array(
			'body' => array(
				'verifier' => $this->key,
			),
			'blocking' => false,
		));

		// Step 4: Wait for Server response
		$value = $this->await_response();

		if ( is_wp_error( $value ) ) {
			$this->emit_response([
				'status' => 'error',
				'type'   => $value->get_error_code(),
			]);
			return;
		}

		// Step 5: Complete.
		$this->emit_response([
			'client_token'  => $value['client_token'],
			'client_secret' => $value['client_secret'],
		]);
	}

	protected function await_response() {
		global $timestart;

		$timeout = $this->timeout;
		$delay = ( 1 / $this->check_frequency ) * static::SEC_IN_USEC;

		while ( true ) {
			$elapsed = microtime( true ) - $timestart;
			if ( $elapsed > $timeout ) {
				// Time to bail.
				return new WP_Error( 'ba.timed_out', 'The Broker did not receive a response from the Server.', array( 'status' => 406 ) );
			}

			$value = $this->get_uncached_state();
			if ( $value === 'connect' ) {
				// Wait for 100ms then try again.
				usleep( $delay );
				continue;
			}

			if ( is_array( $value ) ) {
				// Good response.
				return $value;
			}

			return new WP_Error( 'broker.connect.failure' );
		}
	}
}