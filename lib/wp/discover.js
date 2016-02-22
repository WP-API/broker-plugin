const http = require('http');
const EventEmitter = require('events');
const parse = require('parse-link-header');
const url = require('url');

const relation = 'https://api.w.org/';

module.exports = function discover(remote) {
	var parts = url.parse(remote);
	var options = {
		method: 'HEAD',
	};
	Object.assign(options, parts);

	var events = new EventEmitter();

	var req = http.request(options);
	req.on('error', (res) => {
		events.emit('error');
	});
	req.on('response', (res) => {
		if (res.statusCode !== 200) {
			events.emit('error');
			return;
		}

		if (!('link' in res.headers)) {
			events.emit('error');
			return;
		}

		var links = parse(res.headers.link);
		if (!(relation in links)) {
			events.emit('error');
			return;
		}

		var url = links[relation].url;
		events.emit('success', url);
	});
	req.end();

	return events;
};
