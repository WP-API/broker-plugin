<?php
/**
 * Plugin Name: WP REST API Authentication Broker
 * Description: Allow bootstrapping site authentication via a Broker Registry.
 * Version: 0.1.0
 * Author: WP REST API Team
 * Author URI: http://wp-api.org/
 */

add_action( 'rest_api_init', 'rest_broker_register_routes' );

function rest_broker_register_routes() {
	if ( empty( $GLOBALS['wp_json_authentication_oauth1'] ) ) {
		// OAuth must be enabled for the broker to actually do anything
		return;
	}
	require_once( dirname( __FILE__ ) . '/inc/class-wp-rest-authbroker.php' );

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
