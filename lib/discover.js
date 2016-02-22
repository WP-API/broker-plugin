const request = require('request');
const EventEmitter = require('events');
const url = require('url');

const wpdiscovery = require('./wp/discover');

function fetch_from_index(remote) {
	var options = {
		uri: remote,
		method: 'GET',
	};

	var events = new EventEmitter();

	var req = request(options, (error, response, body) => {
		if (error || response.statusCode !== 200) {
			events.emit('error', error);
			return;
		}

		var data = JSON.parse(body);
		if (!('authentication' in data) || !('broker' in data.authentication)) {
			events.emit('error', new Error('Brokered Authentication not available for site.'));
			return;
		}

		events.emit('success', data.authentication.broker);
	});

	return events;
}

module.exports = function (remote) {
	var events = new EventEmitter();

	var disco = wpdiscovery(remote);
	disco.on('error', e => events.emit('error', e));
	disco.on('success', index => {
		var indexable = fetch_from_index(index);
		indexable.on('error', e => events.emit('error', e));
		indexable.on('success', url => events.emit('success', url));
	});

	return events;
}