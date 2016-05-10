<?php

namespace WordPress\Discovery;

use Exception;
use Requests;

/**
 * Discover the WordPress API from a URI.
 *
 * @param string $uri URI to start the search from.
 * @param bool $legacy Should we check for the legacy API too?
 * @return Site|null Site data if available, null if not a WP site.
 */
function discover( $uri, $legacy = false ) {
	// Step 1: Find the API itself.
	$root = discover_api_root( $uri, $legacy );
	if ( empty( $root ) ) {
		return null;
	}

	// Step 2: Ask the API for information.
	return get_index_information( $root );
}

/**
 * Discover the API root from an address.
 *
 * @throws \Exception on HTTP error.
 *
 * @param string $uri URI to search for the API from.
 * @param bool $legacy Should we check for the legacy API too?
 * @return string|null API root URL if found, null if no API is available.
 */
function discover_api_root( $uri, $legacy = false ) {
	$response = wp_remote_request( $uri, array(
		'method' => 'HEAD',
	));
	if ( is_wp_error( $response ) ) {
		throw new Exception( $response->get_error_message() );
	}
	$code = wp_remote_retrieve_response_code( $response );
	if ( $code !== 200 ) {
		$body = wp_remote_retrieve_body( $response );
		throw new Exception( sprintf( 'Server returned error code %d: %s', $code, $body ) );
	}

	$links = wp_remote_retrieve_header( $response, 'link' );
	$links = explode( ',', $links );

	// Find the correct link by relation
	foreach ( $links as $link ) {
		$attrs = parse_link_header( $link );

		if ( empty( $attrs ) || empty( $attrs['rel'] ) ) {
			continue;
		}
		switch ( $attrs['rel'] ) {
			case 'https://api.w.org/':
				break;

			case 'https://github.com/WP-API/WP-API':
				// Only allow this if legacy mode is on.
				if ( $legacy ) {
					break;
				}

				// Fall-through.
			default:
				continue 2;
		}

		return $attrs['href'];
	}

	return null;
}

/**
 * Parse a Link header into attributes.
 *
 * @param string $link Link header from the response.
 * @return array Map of attribute key => attribute value, with link href in `href` key.
 */
function parse_link_header( $link ) {
	$parts = explode( ';', $link );
	$attrs = array(
		'href' => trim( array_shift( $parts ), '<>' ),
	);

	foreach ( $parts as $part ) {
		if ( ! strpos( $part, '=' ) ) {
			continue;
		}

		list( $key, $value ) = explode( '=', $part, 2 );
		$key = trim( $key );
		$value = trim( $value, '" ' );
		$attrs[ $key ] = $value;
	}

	return $attrs;
}

/**
 * Get the index information from a site.
 *
 * @param string $url URL for the API index.
 * @return Site Data from the index for the site.
 */
function get_index_information( $url ) {
	$response = wp_remote_get( $url );
	if ( is_wp_error( $response ) ) {
		throw new Exception( $response->get_error_message() );
	}
	$code = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );
	if ( $code !== 200 ) {
		throw new Exception( sprintf( 'Server returned error code %d: %s', $code, $body ) );
	}

	$index = json_decode( $body );
	if ( empty( $index ) && json_last_error() !== JSON_ERROR_NONE ) {
		throw new Exception( json_last_error_msg(), json_last_error() );
	}

	return new Site( $index, $url );
}
