<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 Thomas Vincent                                       |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cloudflare plugin for Cacti                                             |
 | https://github.com/somethingwithproof/plugin_cloudflare                 |
 +-------------------------------------------------------------------------+
*/

/*
 * Stands in for api.cloudflare.com in the smoke stack:
 *   php server.php <port> <cert> <key> <fixtures dir> <control dir>
 * It answers the recorded fixtures for one account and 404 for every other
 * path. Control files live in <control dir>, which only this container can
 * write, so no request the plugin makes can change how the fake behaves:
 *   token      the bearer credential to accept; anything else gets 401
 *   fail-503   while present, every request gets 503
 * Each request appends "METHOD target status" to requests.log there. The
 * Authorization header is compared and never written anywhere.
 */

ini_set('default_socket_timeout', '5');

$port     = (int) ($argv[1] ?? 0);
$cert     = (string) ($argv[2] ?? '');
$key      = (string) ($argv[3] ?? '');
$fixtures = rtrim((string) ($argv[4] ?? ''), '/');
$control  = rtrim((string) ($argv[5] ?? ''), '/');

$account = '699d98642c564d2e855e9661899b7252';
$prefix  = '/client/v4/accounts/' . $account;

/*
 * path => array(status, fixture, parameter => accepted values). null accepts
 * an absent parameter; any parameter not listed makes the request a 404.
 * Verify probes the listing with per_page=1 and the zones endpoint; the
 * smoke token has no zone permission, which Cloudflare answers with 403.
 */
$routes = array(
	$prefix . '/tokens/verify' => array(200, 'token-verify-active.json', array()),
	$prefix . '/cfd_tunnel'    => array(200, 'tunnel-list.json', array(
		'is_deleted' => array(null, 'false'),
		'per_page'   => array('1', '1000'),
		'page'       => array(null, '1'),
	)),
	/* blog has one connector; staging has none, as Cloudflare answers for
	 * a tunnel that is not running; old-office gets the 401 with code 1001
	 * that Cloudflare was seen to return for a tunnel it no longer knows. */
	$prefix . '/cfd_tunnel/f70ff985-a4ef-4643-bbbc-4a0ed4fc8415/connections' => array(
		200,
		'tunnel-connections.json',
		array(),
	),
	$prefix . '/cfd_tunnel/0b6a8e4c-6c3b-4f64-9d6e-2f6c1f8a1d11/connections' => array(
		200,
		'tunnel-connections-empty.json',
		array(),
	),
	$prefix . '/cfd_tunnel/7d1c3f52-91a4-4a39-8a55-3e1b0c6f2b77/connections' => array(
		401,
		'connections-1001.json',
		array(),
	),
	'/client/v4/zones' => array(403, 'auth-error.json', array(
		'account.id' => array($account),
		'per_page'   => array('5'),
	)),
);

/**
 * @param resource $conn
 */
function fake_respond($conn, int $status, string $type, string $body): void {
	$reasons = array(200 => 'OK', 400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden',
		404 => 'Not Found', 405 => 'Method Not Allowed', 503 => 'Service Unavailable');

	fwrite($conn, 'HTTP/1.1 ' . $status . ' ' . ($reasons[$status] ?? 'Status') . "\r\n" .
		'Content-Type: ' . $type . "\r\n" .
		'Content-Length: ' . strlen($body) . "\r\n" .
		"Connection: close\r\n\r\n" . $body);
}

function fake_fixture(string $fixtures, string $name): string {
	$body = file_get_contents($fixtures . '/' . $name);
	if ($body === false) {
		fwrite(STDERR, 'missing fixture ' . $name . "\n");
		exit(1);
	}

	return $body;
}

/**
 * @param array<string, array{0: int, 1: string, 2: array<string, array<int, string|null>>}> $routes
 *
 * @return array{0: int, 1: string, 2: string} status, content type, body
 */
function fake_answer(
	string $method,
	string $target,
	?string $auth,
	array $routes,
	string $fixtures,
	string $control
): array {
	$json = 'application/json';

	if (file_exists($control . '/fail-503')) {
		return array(503, 'text/plain', "Service Unavailable\n");
	}

	$path  = (string) parse_url($target, PHP_URL_PATH);
	$query = array();
	/* Not parse_str(): it rewrites the dot in account.id to an underscore. */
	foreach (explode('&', (string) parse_url($target, PHP_URL_QUERY)) as $pair) {
		if ($pair !== '') {
			$kv = explode('=', $pair, 2);
			$query[rawurldecode($kv[0])] = rawurldecode($kv[1] ?? '');
		}
	}

	if (!isset($routes[$path]) || array_diff_key($query, $routes[$path][2]) !== array()) {
		return array(404, $json, fake_fixture($fixtures, 'error-envelope.json'));
	}
	foreach ($routes[$path][2] as $name => $accepted) {
		if (!in_array($query[$name] ?? null, $accepted, true)) {
			return array(404, $json, fake_fixture($fixtures, 'error-envelope.json'));
		}
	}
	if ($method !== 'GET') {
		return array(405, $json, fake_fixture($fixtures, 'error-envelope.json'));
	}

	if ($auth === null || preg_match('/^Bearer ([A-Za-z0-9_-]{40,})\z/', $auth, $m) !== 1) {
		return array(400, $json, fake_fixture($fixtures, 'malformed-token-6003.json'));
	}

	$expected = @file_get_contents($control . '/token');
	if (!is_string($expected) || $expected === '' || !hash_equals($expected, $m[1])) {
		return array(401, $json, fake_fixture($fixtures, 'auth-error.json'));
	}

	return array($routes[$path][0], $json, fake_fixture($fixtures, $routes[$path][1]));
}

/* PHP servers ask for a client certificate by default; the plugin sends none. */
$tls    = array('local_cert' => $cert, 'local_pk' => $key, 'verify_peer' => false);
$ctx    = stream_context_create(array('ssl' => $tls));
$flags  = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
$server = stream_socket_server('ssl://0.0.0.0:' . $port, $errno, $errstr, $flags, $ctx);
if ($server === false) {
	fwrite(STDERR, 'listen failed: ' . $errstr . "\n");
	exit(1);
}

for (;;) {
	/* A failed handshake, such as the healthcheck's bare connect, returns false. */
	$conn = @stream_socket_accept($server, 60);
	if ($conn === false) {
		continue;
	}
	stream_set_timeout($conn, 5);

	$request = '';
	while (strpos($request, "\r\n\r\n") === false && strlen($request) < 16384) {
		$line = fgets($conn, 8192);
		if ($line === false) {
			break;
		}
		$request .= $line;
	}

	$lines = explode("\r\n", $request);
	$parts = explode(' ', $lines[0]);
	if (
		count($parts) !== 3
		|| preg_match('/^[A-Z]{1,16}\z/', $parts[0]) !== 1
		|| preg_match('#^/[\x21-\x7e]{0,2048}\z#', $parts[1]) !== 1
	) {
		fake_respond($conn, 400, 'text/plain', "Bad Request\n");
		fclose($conn);

		continue;
	}

	$auth = null;
	foreach (array_slice($lines, 1) as $header) {
		if (stripos($header, 'Authorization:') === 0) {
			$auth = trim(substr($header, strlen('Authorization:')));
		}
	}

	list($status, $type, $body) = fake_answer($parts[0], $parts[1], $auth, $routes, $fixtures, $control);
	unset($auth);

	$entry = $parts[0] . ' ' . $parts[1] . ' ' . $status . "\n";
	file_put_contents($control . '/requests.log', $entry, FILE_APPEND | LOCK_EX);
	fake_respond($conn, $status, $type, $body);
	fclose($conn);
}
