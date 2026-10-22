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

namespace Cacti\PluginCloudflare\Http;

use Cacti\PluginCloudflare\Support\Redactor;

/**
 * The plugin's only network I/O. The policy check runs before curl_init(),
 * so a refused URL never opens a socket or resolves a name.
 */
final class CurlTransport implements HttpTransport {
	private const ERRNO_NAMES = array(
		1  => 'CURLE_UNSUPPORTED_PROTOCOL',
		3  => 'CURLE_URL_MALFORMAT',
		5  => 'CURLE_COULDNT_RESOLVE_PROXY',
		6  => 'CURLE_COULDNT_RESOLVE_HOST',
		7  => 'CURLE_COULDNT_CONNECT',
		8  => 'CURLE_WEIRD_SERVER_REPLY',
		16 => 'CURLE_HTTP2',
		18 => 'CURLE_PARTIAL_FILE',
		23 => 'CURLE_WRITE_ERROR',
		28 => 'CURLE_OPERATION_TIMEDOUT',
		35 => 'CURLE_SSL_CONNECT_ERROR',
		47 => 'CURLE_TOO_MANY_REDIRECTS',
		52 => 'CURLE_GOT_NOTHING',
		55 => 'CURLE_SEND_ERROR',
		56 => 'CURLE_RECV_ERROR',
		58 => 'CURLE_SSL_CERTPROBLEM',
		59 => 'CURLE_SSL_CIPHER',
		60 => 'CURLE_PEER_FAILED_VERIFICATION',
		77 => 'CURLE_SSL_CACERT_BADFILE',
		92 => 'CURLE_HTTP2_STREAM',
	);

	/** @var OutboundPolicy */
	private $policy;

	/** @var int */
	private $maxBytes;

	public function __construct(OutboundPolicy $p, int $maxBytes = 5242880) {
		$this->policy   = $p;
		$this->maxBytes = $maxBytes;
	}

	public function send(Request $r, int $connectTimeout, int $totalTimeout): Response {
		$this->policy->assertAllowed($r->url());

		if ($connectTimeout < 1 || $totalTimeout < 1) {
			throw new TransportException(TransportException::CONFIG, 'invalid_timeout');
		}

		$ch = curl_init();
		if ($ch === false) {
			throw new TransportException(TransportException::TRANSIENT, 'curl_init_failed');
		}

		$body     = '';
		$headers  = array();
		$tooLarge = false;
		$maxBytes = $this->maxBytes;

		$options = self::options($r, $connectTimeout, $totalTimeout);
		$options[CURLOPT_HEADERFUNCTION] = function ($ch, string $line) use (&$headers): int {
			// A new status line starts a new response (100 Continue), so
			// headers from the interim one must not leak into the final.
			if (strncmp($line, 'HTTP/', 5) === 0) {
				$headers = array();
			} elseif (($pos = strpos($line, ':')) !== false) {
				$headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
			}

			return strlen($line);
		};
		$options[CURLOPT_WRITEFUNCTION] = function ($ch, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
			if (strlen($body) + strlen($chunk) > $maxBytes) {
				$tooLarge = true;

				// Any return value other than the chunk length aborts the
				// transfer with CURLE_WRITE_ERROR.
				return 0;
			}
			$body .= $chunk;

			return strlen($chunk);
		};

		if (!curl_setopt_array($ch, $options)) {
			unset($ch);
			throw new TransportException(TransportException::CONFIG, 'curl_setopt_failed');
		}

		$ok     = curl_exec($ch);
		$errno  = curl_errno($ch);
		$error  = curl_error($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		unset($ch);

		if ($tooLarge) {
			throw new TransportException(
				TransportException::TRANSIENT,
				'CURLE_WRITE_ERROR',
				'response too large: body exceeded ' . $maxBytes . ' bytes'
			);
		}

		if ($ok === false || $errno !== 0) {
			$name = self::ERRNO_NAMES[$errno] ?? 'CURLE_' . $errno;

			throw new TransportException(TransportException::TRANSIENT, $name, self::clean($error, $r));
		}

		return new Response($status, $headers, $body);
	}

	/**
	 * Every option except the response callbacks, kept separate so the
	 * security-relevant settings can be asserted without a network call.
	 *
	 * @return array<int, mixed>
	 */
	private static function options(Request $r, int $connectTimeout, int $totalTimeout): array {
		$protocol = strtolower((string) parse_url($r->url(), PHP_URL_SCHEME)) === 'https' ? CURLPROTO_HTTPS : CURLPROTO_HTTP;

		$httpHeaders = array();
		foreach ($r->headers() as $name => $value) {
			$httpHeaders[] = $name . ': ' . $value;
		}

		$options = array(
			CURLOPT_URL            => $r->url(),
			CURLOPT_HTTPHEADER     => $httpHeaders,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_MAXREDIRS      => 0,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_CONNECTTIMEOUT => $connectTimeout,
			CURLOPT_TIMEOUT        => $totalTimeout,
			CURLOPT_NOPROXY        => '*',
			CURLOPT_NOSIGNAL       => true,
		);
		if (defined('CURLOPT_PROTOCOLS')) {
			$options[CURLOPT_PROTOCOLS] = $protocol;
		}
		if (defined('CURLOPT_REDIR_PROTOCOLS')) {
			$options[CURLOPT_REDIR_PROTOCOLS] = $protocol;
		}

		if ($r->method() === 'GET') {
			$options[CURLOPT_HTTPGET] = true;
		} elseif ($r->method() === 'POST') {
			$options[CURLOPT_POST] = true;
			$options[CURLOPT_POSTFIELDS] = (string) $r->body();
		} else {
			$options[CURLOPT_CUSTOMREQUEST] = $r->method();
			if ($r->body() !== null) {
				$options[CURLOPT_POSTFIELDS] = $r->body();
			}
		}

		return $options;
	}

	/**
	 * curl error text can quote hosts and, on some builds, request lines.
	 * The Authorization value and its bare credential are scrubbed, along
	 * with control bytes, and the result is capped for log hygiene.
	 */
	private static function clean(string $error, Request $r): string {
		$secrets = array();
		foreach ($r->headers() as $name => $value) {
			if (strcasecmp((string) $name, 'Authorization') === 0) {
				$secrets[] = $value;
				$parts = preg_split('/\s+/', trim($value), 2);
				if (is_array($parts) && isset($parts[1])) {
					$secrets[] = $parts[1];
				}
			}
		}

		$text = (string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', Redactor::redact($error, $secrets));

		return substr($text, 0, 200);
	}
}
