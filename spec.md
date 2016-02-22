
## Introduction

The Brokered Authentication protocol is designed as a way for client applications to dynamically register themselves as an application with a server via a third-party broker. This protocol is designed to allow applications to register once to access a distributed networks of servers. The protocol is designed to work with OAuth 1.0a to facilitate initial client registration and issuing of client credentials.

The protocol is designed to allow secure transmission of client credentials without requiring TLS on the server by using a verification request. This process is based on the process used by the [Pingback][pingback] and [Webmention][webmention] protocols for verification.

[pingback]: http://www.hixie.ch/specs/pingback/pingback
[webmention]: https://www.w3.org/TR/webmention/


## Terminology

* **Broker**: The application registry which connects the Client to the Server.
* **Client**: The end-user application which wishes to authenticate with the Server (in OAuth terminology, the agent of the resource owner).
* **Server**: An HTTP server containing the resources the client wishes to access.

## Notational Conventions

The key words "MUST", "MUST NOT", "REQUIRED", "SHALL", "SHALL NOT", "SHOULD", "SHOULD NOT", "RECOMMENDED", "MAY", and "OPTIONAL" in this document are to be interpreted as described in RFC2119.


# Connection Process

The connection process follows five steps, using three HTTP requests:

1. The initial step is the **Broker Connection**, where the Client connects to the Broker to request credentials for a Server. The Broker does not complete this HTTP request ("Broker Connection Request") immediately.
2. The Broker then issues a **Connection Request** to the Server to request the Server issue credentials for an application.
3. The Server relays a set of client crendentials to the Broker in the **Verification Request**. The Server generates the client credentials optimistically, but does not activate them until the request has been verified by the Broker.
4. The Broker verifies the connection request with the Server, and the Server activates the credentials.
5. The Broker provides the credentials to the Client via the Broker Connection Request and closes the request.

The Client is responsible for beginning the process by connecting to the Broker via a HTTP request using TLS. This HTTP request remains open

This process requires the Broker to provide two endpoints:

* **Initialization Endpoint**: The endpoint used by the Client to start the process.
* **Verification Endpoint**: The endpoint used by the Server to verify the Connection Request is valid.

The Server is also required to provide one endpoint:

* **Connection Request Endpoint**: The endpoint used by the Broker to request credentials.

This Connection Request endpoint MUST be advertised using the WordPress REST API index (see [Autodiscovery](#autodiscovery)).


## Broker Connection

The Client starts the connection process by requesting credentials for a Server from the Broker. This is issued by a HTTP "POST" request to the Initialization Endpoint on the Broker, authenticated using the OAuth 1.0 protocol. The request body is constructed by using the "application/x-www-form-urlencoded" content type with the following REQUIRED parameter:

* `server_url`: The URL to connect to the Server.

The Broker MAY specify other parameters.

The `server_url` parameter MUST either specify the Connection Request Endpoint directly, or specify a URL where the Connection Request Endpoint can be discovered from. The Broker SHOULD apply the autodiscovery process to this URL before using as the Connection Request Endpoint.

The request parameters are constructed similarly to the Temporary Credential Request in OAuth 1.0. The empty `oauth_token` parameter MAY be omitted from the request, and the empty string MUST be used as the token secret value.

The request MUST be verified using OAuth 1.0. The Broker Connection Request MUST NOT be terminated until after the verification step or until an error occurs. The response is not complete until the Broker Connection Response stage.

The Broker responds to the request using the [NDJSON][] content type. This is newline-delimited JSON data. The response uses the "application/x-ndjson" content-type header, and consists of a number of partial responses. Each partial response is appended to the response body as a new line of JSON data. The Broker MAY use chunked encoding to send this data, but is not required to.

[NDJSON]: http://dataprotocols.org/ndjson/

The Broker immediately issues a partial response with a 200 status code ("OK"). The partial response is sent as a JSON-formatted object appended to the NDJSON response, and contains the following REQUIRED parameters:

* `status`: The literal string `processing`

The Broker MAY include other parameters in the partial response.

The Broker retrieves the pre-registered data for the Client using a Client Identifier. Brokers SHOULD use the identifier portion of the OAuth client credentials ("consumer key") as the client identifier. This Identifier MUST be publically available to allow Server configuration to whitelist or blacklist certain clients.

Credentials MAY be cached by the Broker. If the Broker has credentials for the client for the site, the Broker MAY return these immediately using the Broker Connection Response process. The cache check MUST use the server URL after autodiscovery has been applied.


### Error Handling

The Broker Connection is kept open until verification is completed. The Broker MAY cancel the connection without verification if the verification fails to complete in time, or a fatal error occurs on the Broker. In the event of an error, the Broker MUST send a partial response before closing the connection. The partial response is a JSON-formatted Error object appended to the NDJSON response, with the additional REQUIRED parameters:

* `status`: The literal string `error`.

The Broker MUST close the connection after sending an error response.


## Connection Request

The Broker is responsible for the Connection Request to the Server to request credentials on behalf of the Client. The Broker uses the Client data obtained in the Broker Connection step.

Before the Broker issues the request, it MUST generate a verification token: an unguessable value passed to the Server and REQUIRED during the Verification Request. This ensures third-parties cannot generate requests independently.

The Broker then requests the credentials by a HTTP "POST" request to the Connection Request Endpoint. The request body is constructed by using the "application/x-www-form-urlencoded" content type with the following REQUIRED parameters:

* `client_id`: The Client Identifier.
* `broker`: A URI representing a unique identifier for the broker.
* `verifier`: The verification token.

The `client_id` MUST NOT be a string containing between one and 255 characters.

The `broker` parameter MUST be a URI which uniquely identifies the Broker. The Server SHOULD match the Broker identifier against a list of known Brokers and reject the request if the Broker is unknown.

The `verifier` parameter MUST be an alphanumeric string containing between one and 255 characters.

The following OPTIONAL parameters may be included:

* `client_name`: A human-readable client name.
* `client_description`: A human-readable client description.
* `client_details`: A URL to access a HTML page describing the client.

These OPTIONAL parameters allow server administrators to identify new clients for security and privacy consideration.

Upon receipt of a Connection Request, the Server SHOULD queue and process the request asynchronously to prevent DoS attacks. However, the Server SHOULD check the `client_id` for validity synchronously.

For successful requests, the Server MUST respond with a 202 Accepted response. The response MAY contain a body for future extension. If a body is included, the body SHOULD be JSON-formatted data.


### Error Handling

For unsuccessful requests, the Server MUST respond with an appropriate HTTP error code. The Server SHOULD include a JSON response body containing an Error object.

If the verifier is invalid, the Server SHOULD issue a 400 Bad Request response with the machine-readable error code `ba.invalid_verifier`.

If the client ID is semantically invalid (for example, an empty string), the Server SHOULD issue a 400 Bad Request response with the machine-readable error code `ba.invalid_client_id`.

If a Server is using a whitelist or blacklist for client identifiers, and the client identifier is rejected by this process, the Server SHOULD issue a 400 Bad Request response with the machine-readable error code `ba.rejected_client`.

If the Broker is not known to the Server, the Server SHOULD issue a 400 Bad Request response with the machine-readable error code `ba.unknown_broker`.


## Verification Request

The Server verfies that the Connection Request was valid by sending a request to the Verification Endpoint of the Broker. This serves a dual purpose of verifying that the request was valid, as well as passing the client credentials to the Broker.

Before sending the request, the Server MUST generate a new set of client credentials local to the server, which MUST NOT be active until after verification is complete. The credentials MUST consist of a unique identifier and a matching shared secret. These credentials SHOULD be the credentials for a new OAuth client on the Server. The credentials SHOULD be linked to the client identifier provided by the Broker.

To avoid resource exhaustion attacks, the generated credentials SHOULD be temporary and expire immediately after receiving a response to the Verification Request. Server implementations are encouraged to keep these credentials in runtime memory and only save them permanently after verification is complete.

The Server requests verification by a HTTP "POST" request to the Verification Endpoint. The request body is constructed by using the "application/x-www-form-urlencoded" content type with the following REQUIRED parameters:

* `verifier`: The verification token received from the Broker in the Connection Request step.
* `client_id`: The client identifier received from the Broker in the Connection Request.
* `client_token`: The generated credentials identifier.
* `client_secret`: The generated credentials shared-secret.

The Server MAY include other parameters in the request.

Since the request transmits plain text credentials in the request, the Broker MUST provide the Verification Endpoint over a transport-layer security mechanism such as TLS or SSL.

After receiving the Verification Request, the Broker first verifies that the verification token matches a request for the given client identifier, then responds to the Verification request with a 200 OK response. The response MAY include a body. If a body is included, the body SHOULD be JSON-formatted data.

Once the Server receives a 200 OK response from the Broker, it SHOULD mark the credentials as active. If the Server had not stored the credentials permanently, it SHOULD now store the credentials.


### Error Handling

For unsuccessful requests, the Broker MUST respond with an appropriate HTTP error code. The Broker SHOULD include a JSON response body containing an Error object.

If the Server receives an error from the Broker, it MUST NOT mark the credentials as active. It SHOULD discard the credentials to avoid resource exhaustion attacks.

If the verification token does not match an outstanding request, or does not match the client identifier, the Broker SHOULD issue a 400 Bad Request response with the machine-readable error code `ba.invalid_verifier`.

If the process has exceeded a time limit set by the Broker, the Broker SHOULD issue a 409 Conflict response with the machine-readable error code `ba.timed_out`.


## Broker Connection Response

Once the Broker has received the client credentials from the Server and verified them, it then passes these back to the Client.

The Broker sends a partial response to the Broker Connection. This response is a JSON-formatted object appended to the NDJSON response, and encodes the following REQUIRED parameters:

* `client_token`: The generated credentials identifier.
* `client_secret`: The generated credentials shared-secret.

The Broker MAY include other parameters in the partial response. The Broker SHOULD terminate the request after sending this partial response.


# Data Formats

## Errors

An Error is a JSON-formatted object encoding a map of parameters. An Error object contains the following REQUIRED parameters:

* `code`: A machine-readable code as a JSON string used to identify the type of error that occurred.
* `message`: A human-readable description of the error that occurred, suitable for display to the end-user.

Codes beginning with `ba.` are reserved for this specification and future extensions and MUST NOT be used by vendor-specific extensions.

The partial response MAY contain the following OPTIONAL parameter:

* `data`: Additional data related to the error for use in processing or debugging.

Unless otherwise specified, Error objects MAY contain other parameters.

# Autodiscovery

As the Server URL is typically supplied by an end-user, the autodiscovery process SHOULD be applied to this URL to find the Connection Request Endpoint. Broker implementations SHOULD NOT assume the supplied URL is the Connection Request Endpoint itself.

Given a Server URL, the process to discover the Connection Request Endpoint is as follows:

1. Set the Connection Request Endpoint to the Server URL.
1. Issue a HTTP "HEAD" request to the Server URL and obtain the HTTP response headers. If an error occurs during the request, or a HTTP error code is returned, mark the Server URL as invalid, and stop the discovery process.
2. If the headers contain `X-BA-Endpoint: connection-request`, stop the discovery process.
3. If the headers do not contain a `Link` header or the `Link` header does not contain a link with relation `https://api.w.org/`, stop the discovery process.
4. Issue a HTTP "GET" request to the URL specified in the link with relation `https://api.w.org/` and obtain the HTTP response body. If an error occurs during the request, or a HTTP error code is returned, mark the Server URL as invalid, and stop the discovery process.
5. If the response body is not JSON-formatted, mark the Server URL as invalid, and stop the discovery process.
6. Decode the response body. If the response body does not contain the `authentication.broker` key, or the value of the key is not a valid URL, mark the Server URL as invalid, and stop the discovery process.
7. Set the Connection Request Endpoint to the value of the `authentication.broker` parameter in the response body.

In pseudo-JavaScript code:

    function discover ( server_url ) {
    	let connection_request_endpoint = server_url

    	let response = send_http_request( 'HEAD', server_url )
    	if ( ! response or response.status !== 200 ) {
    		throw Error
    	}
    	if ( response.headers['X-BA-Endpoint'] == 'connection-request' ) {
    		return server_url
    	}
    	let links = parse_links( response.headers['Link'] )
    	if ( ! links or ! links['https://api.w.org/'] ) {
    		return server_url
    	}

    	let response = send_http_request( links['https://api.w.org/'] )
    	if ( ! response or response.status !== 200 ) {
    		throw Error
    	}
    	let data = JSON.parse( response.body )
    	if ( ! data or ! data.authentication or ! data.authentication.broker ) {
    		throw Error
    	}

    	return data.authentication.broker
    }

To facilitate quicker discovery, the Server SHOULD issue a `X-BA-Endpoint: connection-request` header from the Connection Request Endpoint. This allows the Broker to return immediately from the discovery process rather than checking the index.