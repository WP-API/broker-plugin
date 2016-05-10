<?php
/**
 * Plugin Name: WP Authentication Broker
 * Description: Acts as a central broker for OAuth.
 */

namespace AuthBroker;

use WP_Error;

const QUERY_VAR = 'auth_broker';

require __DIR__ . '/autodiscovery/namespace.php';
require __DIR__ . '/autodiscovery/Site.php';

add_action( 'init', __NAMESPACE__ . '\\register_rewrites' );
add_action( 'template_redirect', __NAMESPACE__ . '\\dispatch', -200 );

/**
 * Register our rewrite rules.
 */
function register_rewrites() {
	global $wp;
	$wp->add_query_var( QUERY_VAR );

	add_rewrite_rule( '^broker/(\w+)/?$', 'index.php?' . QUERY_VAR . '=$matches[1]', 'top' );
}

/**
 * Dispatch a broker endpoint request to the endpoint.
 */
function dispatch() {
	global $wp;

	if ( empty( $wp->query_vars[ QUERY_VAR ] ) )
		return;

	// Register autoloader.
	spl_autoload_register( __NAMESPACE__ . '\\autoload' );

	// Ensure our output isn't buffered
	header( 'Content-Encoding: none' );
	ob_end_flush();

	switch ( $wp->query_vars[ QUERY_VAR ] ) {
		case 'connect':
			$endpoint = new Endpoint\Connect();
			break;

		case 'trigger_verification':
			$endpoint = new Endpoint\Trigger();
			break;

		case 'confirm':
			$endpoint = new Endpoint\Confirm();
			break;

		default:
			return;
	}

	$params = wp_unslash( $_POST );
	$endpoint->run( $params );

	// Finish off our request
	die();
}

function autoload( $class ) {
	if ( strpos( $class, __NAMESPACE__ ) !== 0 ) {
		return;
	}

	// Remove namespace
	$class = substr( $class, strlen( __NAMESPACE__ ) );
	$class = strtolower( $class );

	$parts = explode( '\\', trim( $class, '\\') );
	$num = count( $parts );
	$parts[ $num - 1 ] = 'class-' . str_replace( '_', '-', $parts[ $num - 1 ] );

	$path = __DIR__ . '/inc/' . implode( '/', $parts ) . '.php';
	include $path;
}
