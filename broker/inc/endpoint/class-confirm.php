<?php

namespace AuthBroker\Endpoint;

class Confirm extends Base {
	public function run( $params ) {
		// Step 1: Check verification code.
		$this->key = $params['verifier'];
		$this->log_event( 'Confirming' );
		$state = $this->get_state();
		if ( empty( $state ) || $state !== 'connect' ) {
			status_header( 400 );
			$this->log_event( 'None found' );
			return;
		}

		// Step 2: Verify valid client
		$saved = $this->get_data();
		if ( empty( $saved ) ) {
			status_header( 400 );
			$this->log_event( 'No data found' );
			return;
		}

		if ( $saved['client_id'] !== $params['client_id'] ) {
			$this->log_event( 'Client ID mismatch' );
			status_header( 400 );
			return;
		}

		$this->log_event( 'Received: ' . json_encode( $params ) );

		// Step 3: Save credentials.
		$data = [
			'client_token'  => $params['client_token'],
			'client_secret' => $params['client_secret'],
		];
		$this->set_state( $data );
	}
}
