<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Cacti Group                                      |
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
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/*
 * Minimal TLS listener for the certificate verification test:
 *   php tls-server.php <port> <pem with cert and key> <log file>
 * It appends the request line of every request that completes a handshake
 * to the log, so an empty log proves the client refused the certificate.
 * The test kills it; the 60 second cap only stops an orphan left behind
 * by a crashed run.
 */

$port = (int) ($argv[1] ?? 0);
$pem  = (string) ($argv[2] ?? '');
$log  = (string) ($argv[3] ?? '');

$ctx = stream_context_create(array('ssl' => array('local_cert' => $pem)));
$server = stream_socket_server('ssl://127.0.0.1:' . $port, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if ($server === false) {
	fwrite(STDERR, 'listen failed: ' . $errstr . "\n");
	exit(1);
}

$deadline = time() + 60;
while (time() < $deadline) {
	// A client that rejects the certificate aborts the handshake, and
	// accept() returns false; keep serving.
	$conn = @stream_socket_accept($server, 1);
	if ($conn === false) {
		continue;
	}

	$line = fgets($conn, 8192);
	if (is_string($line) && $line !== '') {
		file_put_contents($log, rtrim($line) . "\n", FILE_APPEND | LOCK_EX);
		fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
	}
	fclose($conn);
}
