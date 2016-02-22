const http = require('http');
const EventEmitter = require('events');
const url = require('url');
const util = require('util');

const discover = require('./lib/discover.js');

var server = http.createServer();

function MyEventEmitter() {
	// Initialize necessary properties from `EventEmitter` in this instance
	EventEmitter.call(this);
}
util.inherits(MyEventEmitter, EventEmitter);

var requestsPending = {};
var requestNum = 0;
var requestsEmitter = new MyEventEmitter();

server.listen(8080, 'localhost', function () {
	console.log('Running on port 8080');
});

function handle_connect(request, response) {
	var id = requestNum;
	requestNum++;

	requestsPending[ requestNum ] = true;
	response.writeHead(200);
	response.write('');
	response.write(util.format('Your ID is %d\n', requestNum));
	requestsEmitter.once(util.format('complete:%d', requestNum), function () {
		response.write('Confirmed, thanks.');
		response.end();
	});
}

function handle_confirm(request, response) {
	var url_parts = url.parse(request.url, true);

	if (!('id' in url_parts.query)) {
		response.statusCode = 400;
		response.write('Missing ID parameter');
		response.end();
		return;
	}
	var id = parseInt(url_parts.query.id);
	requestsEmitter.emit(util.format('complete:%d', id));
	response.write('Confirmed!');
	response.end();
}

server.on('request', function(request, response) {
	console.log(util.format('[%s] %s %s', (new Date()).toISOString(), request.method, request.url));
	if (request.method !== 'POST') {
		response.statusCode = 405;
		response.write('Invalid method');
		response.end();
		return;
	}

	var url_parts = url.parse(request.url, true);

	switch (url_parts.pathname) {
		case '/connect':
			return handle_connect(request, response);

		case '/confirm':
			return handle_confirm(request, response);

		case '/discover':
			var url_parts = url.parse(request.url, true);
			if (!('url' in url_parts.query)) {
				response.statusCode = 400;
				response.write('Missing url parameter');
				response.end();
				return;
			}

			response.writeHead(200);
			response.write('');
			response.write('wait for it...\n');

			var remote_url = url_parts.query.url;
			var disco_events = discover(remote_url);
			disco_events.on('error', () => {
				response.write('Could not discover');
				response.end();
			});
			disco_events.on('success', url => {
				response.write('Discovered at ' + url);
				response.end();
			})
			return;

		default:
			// 404
			response.statusCode = 404;
			response.write('Invalid URL');
			response.end();
			return;
	}
});
