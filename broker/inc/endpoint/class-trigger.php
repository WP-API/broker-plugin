<?php

namespace AuthBroker\Endpoint;

use WP_REST_OAuth1_Client;

class Trigger extends Base {
	public function run( $params ) {
		if ( empty( $params['verifier'] ) ) {
			$this->key = 'Unknown';
			$this->log_event( 'Missing verifier' );
			echo 'Missing verifier parameter.';

			return;
		}

		$this->key = $params['verifier'];

		$this->log_event( 'Starting verification' );

		// Check that the key exists and hasn't been completed already.
		$is_valid = $this->get_state();
		if ( empty( $is_valid ) || $is_valid !== 'connect' ) {
			$this->log_event( 'None found' );
			$this->log_event( var_export( $is_valid, true ) );
			return;
		}

		// Send the request to the Server
		$data = $this->get_data();
		$client = WP_REST_OAuth1_Client::get_by_key( $data['client_id'] );

		$this->log_event( 'Sending request: ' . $data['server_url'] );
		$response = wp_remote_post( $data['server_url'], array(
			'body' => array(
				'broker'             => 'http://broker.local/',
				'verifier'           => $key,
				'client_id'          => $data['client_id'],
				'client_name'        => $client->post_title,
				'client_description' => $client->post_content,
			),
		));
		if ( is_wp_error( $response ) ) {
			// Couldn't connect, mark request as failed.
			$this->set_state( 'failed' );

			$this->log_event( 'Errored' );
			$this->log_event( $response->get_error_message() );
			return;
		}

		$this->log_event( 'Response received' );
		$this->log_event( wp_remote_retrieve_response_code( $response ) );
		$this->log_event( json_encode( $response ) );

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 202 ) {
			$this->set_state( 'failed' );
			$this->log_event( sprintf( 'Response code not 202: %d', $code ) );
		}
	}
}
