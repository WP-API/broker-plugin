# Brokered Authentication for the WordPress REST API

This repository contains the Broker Authentication plugin for the REST API. This allows a partially-decentralised application registry system. Read about how it works [on the reference broker](https://apps.wp-api.org/), or [read the full specification](https://apps.wp-api.org/spec/).

This plugin is planned to be rolled into the [official OAuth plugin](https://github.com/WP-API/OAuth1) after peer review.

## Installation

Install this plugin onto your site to opt-in to the Broker Authentication system. This plugin requires [the OAuth Server plugin](https://github.com/WP-API/OAuth1).

## Specification

The Broker Authentication protocol has a [full specification](https://apps.wp-api.org/spec/), which is also available in source form in this repository as `spec.bs`.

This is a [Bikeshed file](https://github.com/tabatkins/bikeshed), and can be built via `bikeshed` on the command line, or via the [hosted Bikeshed tool](https://api.csswg.org/bikeshed/).
