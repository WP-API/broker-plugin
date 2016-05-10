<?php

namespace AuthBroker\Endpoint;

abstract class Base {
	protected function emit_response( $data ) {
		flush();

		echo json_encode( $data );
		flush();
	}

	protected function log_event( $message ) {
		if ( ! defined( 'AUTHBROKER_LOG' ) ) {
			return;
		}

		error_log( '[' . $this->key . '] ' . $message . "\n", 3, AUTHBROKER_LOG );
	}

	protected function get_state() {
		return get_transient( 'broker_request:' . $this->key );
	}

	protected function get_uncached_state() {
		global $wpdb;

		// MANUAL DB ACCESS: FIXME TODO FUCKTHIS
		$value = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT option_value FROM sz_options WHERE option_name=%s LIMIT 1',
				'_transient_broker_request:' . $this->key
			)
		);

		return maybe_unserialize( $value[0] );
	}

	protected function set_state( $state ) {
		set_transient( 'broker_request:' . $this->key, $state );
	}

	protected function get_data() {
		return get_transient( 'broker_data:' . $this->key );
	}

	protected function set_data( $data ) {
		set_transient( 'broker_data:' . $this->key, $data );
	}
}
