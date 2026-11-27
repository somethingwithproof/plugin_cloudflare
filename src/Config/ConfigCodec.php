<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_cloudflare, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_cloudflare
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

namespace Somethingwithproof\Cloudflare\Config;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * Deterministic YAML and XML codec for Cloudflare plugin CasC configuration.
 *
 * Enforces strict secret hygiene: raw Cloudflare API tokens and TokenVault
 * ciphertexts are refused during both encoding and decoding; only opaque
 * secret references (vault:<account_id> or file:<path>) are allowed.
 */
final class ConfigCodec {
	public const FORMAT_YAML    = 'yaml';
	public const FORMAT_XML     = 'xml';
	public const SCHEMA_VERSION = 1;
	public const MAX_BYTES      = 2097152;

	public static function isFormat(string $format): bool {
		return $format === self::FORMAT_YAML || $format === self::FORMAT_XML;
	}

	/**
	 * @param array<string, mixed> $document
	 */
	public static function encode(string $format, array $document): string {
		self::assertNoRawSecrets($document);

		if ($format === self::FORMAT_YAML) {
			return self::writeYaml($document, 0) . "\n";
		}

		if ($format === self::FORMAT_XML) {
			return self::writeXml($document);
		}

		throw new InvalidArgumentException('Unsupported format: ' . $format);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function decode(string $format, string $content): array {
		if (strlen($content) > self::MAX_BYTES) {
			throw new InvalidArgumentException('Configuration exceeds maximum size limit');
		}

		if ($format === self::FORMAT_YAML) {
			$data = self::parseYaml($content);
		} elseif ($format === self::FORMAT_XML) {
			$data = self::parseXml($content);
		} else {
			throw new InvalidArgumentException('Unsupported format: ' . $format);
		}

		self::assertNoRawSecrets($data);

		return $data;
	}

	/**
	 * Reject any raw API token or raw vault ciphertext anywhere in the tree.
	 *
	 * @param mixed $value
	 */
	public static function assertNoRawSecrets($value, string $path = 'root'): void {
		if (is_string($value)) {
			if (preg_match('/^(?:cfut_|cfat_)[A-Za-z0-9_-]{16,}/', $value) === 1 || strpos($value, 'v1:') === 0) {
				throw new InvalidArgumentException('Raw token or vault ciphertext is forbidden at ' . $path . '; use a secret reference (vault:<id> or file:<path>)');
			}

			return;
		}

		if (is_array($value)) {
			foreach ($value as $k => $v) {
				if (is_string($k) && in_array($k, array('token', 'api_token', 'token_ciphertext', 'secret_key'), true)) {
					throw new InvalidArgumentException('Forbidden raw secret key "' . $k . '" at ' . $path);
				}

				self::assertNoRawSecrets($v, $path . '.' . (string) $k);
			}
		}
	}

	/**
	 * @param array<mixed> $data
	 */
	private static function writeYaml(array $data, int $indent): string {
		$pad   = str_repeat('  ', $indent);
		$lines = array();
		$isList = self::isList($data);

		foreach ($data as $key => $value) {
			if ($isList) {
				if (is_array($value)) {
					if ($value === array()) {
						$lines[] = $pad . '- []';
					} else {
						$nested = explode("\n", self::writeYaml($value, $indent + 1));
						$first  = array_shift($nested);
						$lines[] = $pad . '- ' . ltrim((string) $first);
						foreach ($nested as $nline) {
							$lines[] = $nline;
						}
					}
				} else {
					$lines[] = $pad . '- ' . self::yamlScalar($value);
				}
			} else {
				$k = self::yamlKey((string) $key);
				if (is_array($value)) {
					if ($value === array()) {
						$lines[] = $pad . $k . ': []';
					} else {
						$lines[] = $pad . $k . ':';
						$lines[] = self::writeYaml($value, $indent + 1);
					}
				} else {
					$lines[] = $pad . $k . ': ' . self::yamlScalar($value);
				}
			}
		}

		return implode("\n", $lines);
	}

	/**
	 * @param mixed $value
	 */
	private static function yamlScalar($value): string {
		if ($value === null) {
			return 'null';
		}

		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}

		if (is_int($value) || is_float($value)) {
			return (string) $value;
		}

		$str = (string) $value;
		if ($str === '' || preg_match('/^[A-Za-z0-9_.\/:@-]+$/', $str) !== 1 || in_array(strtolower($str), array('true', 'false', 'null', 'yes', 'no', 'on', 'off'), true) || is_numeric($str)) {
			return json_encode($str, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}

		return $str;
	}

	private static function yamlKey(string $key): string {
		return preg_match('/^[A-Za-z0-9_-]+$/', $key) === 1 ? $key : json_encode($key);
	}

	/**
	 * Minimal, deterministic subset YAML parser covering all documents produced
	 * by encode() (maps, lists of maps/scalars, quoted/plain scalars, comments).
	 *
	 * @return array<string, mixed>
	 */
	private static function parseYaml(string $content): array {
		$rawLines = preg_split('/\r?\n/', $content);
		if (!is_array($rawLines)) {
			throw new InvalidArgumentException('Unable to split YAML input');
		}

		$lines = array();
		foreach ($rawLines as $idx => $line) {
			$trimmed = trim($line);
			if ($trimmed === '' || $trimmed[0] === '#' || $trimmed === '---' || $trimmed === '...') {
				continue;
			}

			if (strpos($line, "\t") !== false) {
				throw new InvalidArgumentException('Tabs are not allowed in YAML indentation on line ' . ($idx + 1));
			}

			$indent = strlen($line) - strlen(ltrim($line, ' '));
			$lines[] = array('indent' => $indent, 'text' => ltrim($line, ' '), 'line' => $idx + 1);
		}

		if ($lines === array()) {
			return array();
		}

		$pos    = 0;
		$parsed = self::parseYamlBlock($lines, $pos, $lines[0]['indent']);

		if (!is_array($parsed) || self::isList($parsed)) {
			throw new InvalidArgumentException('Top-level YAML document must be a mapping');
		}

		/** @var array<string, mixed> $parsed */
		return $parsed;
	}

	/**
	 * @param array<int, array{indent: int, text: string, line: int}> $lines
	 * @return array<mixed>
	 */
	private static function parseYamlBlock(array $lines, int &$pos, int $baseIndent): array {
		$count = count($lines);
		if ($pos >= $count) {
			return array();
		}

		if (strpos($lines[$pos]['text'], '- ') === 0 || $lines[$pos]['text'] === '-') {
			return self::parseYamlList($lines, $pos, $baseIndent);
		}

		$map = array();

		while ($pos < $count && $lines[$pos]['indent'] === $baseIndent) {
			$entry = $lines[$pos];
			$text  = $entry['text'];
			$colon = self::findKeyColon($text);

			if ($colon === false) {
				throw new InvalidArgumentException('Expected key: value on line ' . $entry['line']);
			}

			$key   = self::unquoteScalar(trim(substr($text, 0, $colon)));
			$rest  = trim(substr($text, $colon + 1));
			$pos++;

			if ($rest === '') {
				if ($pos < $count && $lines[$pos]['indent'] > $baseIndent) {
					$map[$key] = self::parseYamlBlock($lines, $pos, $lines[$pos]['indent']);
				} else {
					$map[$key] = array();
				}
			} elseif ($rest === '[]') {
				$map[$key] = array();
			} else {
				$map[$key] = self::parseYamlScalar($rest);
			}
		}

		return $map;
	}

	/**
	 * @param array<int, array{indent: int, text: string, line: int}> $lines
	 * @return array<int, mixed>
	 */
	private static function parseYamlList(array $lines, int &$pos, int $baseIndent): array {
		$list  = array();
		$count = count($lines);

		while ($pos < $count && $lines[$pos]['indent'] === $baseIndent) {
			$entry = $lines[$pos];
			$text  = $entry['text'];

			if ($text !== '-' && strpos($text, '- ') !== 0) {
				break;
			}

			$rest = $text === '-' ? '' : trim(substr($text, 2));
			$pos++;

			if ($rest === '') {
				if ($pos < $count && $lines[$pos]['indent'] > $baseIndent) {
					$list[] = self::parseYamlBlock($lines, $pos, $lines[$pos]['indent']);
				} else {
					$list[] = null;
				}
				continue;
			}

			if ($rest === '[]') {
				$list[] = array();
				continue;
			}

			$colon = self::findKeyColon($rest);
			if ($colon !== false) {
				$itemKey  = self::unquoteScalar(trim(substr($rest, 0, $colon)));
				$itemRest = trim(substr($rest, $colon + 1));
				$item     = array();

				if ($itemRest === '') {
					if ($pos < $count && $lines[$pos]['indent'] > $baseIndent + 2) {
						$item[$itemKey] = self::parseYamlBlock($lines, $pos, $lines[$pos]['indent']);
					} else {
						$item[$itemKey] = array();
					}
				} elseif ($itemRest === '[]') {
					$item[$itemKey] = array();
				} else {
					$item[$itemKey] = self::parseYamlScalar($itemRest);
				}

				if ($pos < $count && $lines[$pos]['indent'] === $baseIndent + 2 && strpos($lines[$pos]['text'], '- ') !== 0) {
					$more = self::parseYamlBlock($lines, $pos, $baseIndent + 2);
					$item = array_merge($item, $more);
				}

				$list[] = $item;
				continue;
			}

			$list[] = self::parseYamlScalar($rest);
		}

		return $list;
	}

	/**
	 * @return int|false
	 */
	private static function findKeyColon(string $text) {
		$inQuote = false;
		$quoteCh = '';
		$len     = strlen($text);

		for ($i = 0; $i < $len; $i++) {
			$ch = $text[$i];
			if ($inQuote) {
				if ($ch === '\\' && $quoteCh === '"') {
					$i++;
					continue;
				}
				if ($ch === $quoteCh) {
					$inQuote = false;
				}
				continue;
			}

			if ($ch === '"' || $ch === "'") {
				$inQuote = true;
				$quoteCh = $ch;
				continue;
			}

			if ($ch === ':' && ($i + 1 === $len || $text[$i + 1] === ' ')) {
				return $i;
			}
		}

		return false;
	}

	private static function unquoteScalar(string $raw): string {
		$len = strlen($raw);
		if ($len >= 2 && $raw[0] === '"' && $raw[$len - 1] === '"') {
			$decoded = json_decode($raw, true);

			return is_string($decoded) ? $decoded : substr($raw, 1, -1);
		}

		if ($len >= 2 && $raw[0] === "'" && $raw[$len - 1] === "'") {
			return str_replace("''", "'", substr($raw, 1, -1));
		}

		return $raw;
	}

	/**
	 * @return mixed
	 */
	private static function parseYamlScalar(string $raw) {
		$len = strlen($raw);
		if ($len >= 2 && (($raw[0] === '"' && $raw[$len - 1] === '"') || ($raw[0] === "'" && $raw[$len - 1] === "'"))) {
			return self::unquoteScalar($raw);
		}

		$lower = strtolower($raw);
		if ($lower === 'null' || $lower === '~') {
			return null;
		}
		if ($lower === 'true') {
			return true;
		}
		if ($lower === 'false') {
			return false;
		}
		if (preg_match('/^-?[0-9]+$/', $raw) === 1) {
			return (int) $raw;
		}
		if (preg_match('/^-?[0-9]+\.[0-9]+$/', $raw) === 1) {
			return (float) $raw;
		}

		return $raw;
	}

	/**
	 * @param array<string, mixed> $document
	 */
	private static function writeXml(array $document): string {
		$dom               = new DOMDocument('1.0', 'UTF-8');
		$dom->formatOutput = true;
		$root              = $dom->createElement('cloudflare_config');
		$dom->appendChild($root);

		self::appendXmlChildren($dom, $root, $document);

		$xml = $dom->saveXML();
		if (!is_string($xml)) {
			throw new InvalidArgumentException('Unable to serialize XML configuration');
		}

		return $xml;
	}

	/**
	 * @param array<mixed> $data
	 */
	private static function appendXmlChildren(DOMDocument $dom, DOMElement $parent, array $data): void {
		$isList = self::isList($data);

		foreach ($data as $key => $value) {
			$tag = $isList ? 'item' : preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $key);
			if (!is_string($tag) || $tag === '' || preg_match('/^[A-Za-z_]/', $tag) !== 1) {
				$tag = 'item';
			}

			$el = $dom->createElement($tag);

			if (is_array($value)) {
				$el->setAttribute('type', self::isList($value) ? 'list' : 'map');
				self::appendXmlChildren($dom, $el, $value);
			} elseif (is_bool($value)) {
				$el->setAttribute('type', 'bool');
				$el->appendChild($dom->createTextNode($value ? 'true' : 'false'));
			} elseif (is_int($value)) {
				$el->setAttribute('type', 'int');
				$el->appendChild($dom->createTextNode((string) $value));
			} elseif (is_float($value)) {
				$el->setAttribute('type', 'float');
				$el->appendChild($dom->createTextNode((string) $value));
			} elseif ($value === null) {
				$el->setAttribute('type', 'null');
			} else {
				$el->setAttribute('type', 'string');
				$el->appendChild($dom->createTextNode((string) $value));
			}

			$parent->appendChild($el);
		}
	}

	/**
	 * XXE-safe XML parser rejecting DOCTYPE declarations and entity expansion.
	 *
	 * @return array<string, mixed>
	 */
	private static function parseXml(string $content): array {
		if (stripos($content, '<!DOCTYPE') !== false || stripos($content, '<!ENTITY') !== false) {
			throw new InvalidArgumentException('XML DOCTYPE and ENTITY declarations are not permitted');
		}

		$previous = libxml_use_internal_errors(true);
		libxml_clear_errors();

		try {
			$dom = new DOMDocument();
			$ok  = $dom->loadXML($content, LIBXML_NONET | LIBXML_NOBLANKS);
			if (!$ok || $dom->documentElement === null || $dom->documentElement->tagName !== 'cloudflare_config') {
				throw new InvalidArgumentException('Invalid XML cloudflare_config document');
			}

			$result = self::readXmlNode($dom->documentElement, 0);
			if (!is_array($result) || self::isList($result)) {
				throw new InvalidArgumentException('Invalid XML cloudflare_config root element');
			}

			/** @var array<string, mixed> $result */
			return $result;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
	}

	/**
	 * @return mixed
	 */
	private static function readXmlNode(DOMElement $el, int $depth) {
		if ($depth > 12) {
			throw new InvalidArgumentException('XML nesting depth exceeded');
		}

		$type = $el->getAttribute('type');

		if ($type === 'null') {
			return null;
		}
		if ($type === 'bool') {
			return $el->textContent === 'true';
		}
		if ($type === 'int') {
			return (int) $el->textContent;
		}
		if ($type === 'float') {
			return (float) $el->textContent;
		}
		if ($type === 'string') {
			return $el->textContent;
		}

		$children = array();
		foreach ($el->childNodes as $child) {
			if ($child instanceof DOMElement) {
				$children[] = $child;
			}
		}

		if ($type === 'list') {
			$list = array();
			foreach ($children as $child) {
				$list[] = self::readXmlNode($child, $depth + 1);
			}

			return $list;
		}

		$map = array();
		foreach ($children as $child) {
			$map[$child->tagName] = self::readXmlNode($child, $depth + 1);
		}

		return $map;
	}

	/**
	 * PHP 7.4-compatible list check (array_is_list is PHP 8.1+).
	 *
	 * @param array<mixed> $array
	 */
	private static function isList(array $array): bool {
		if ($array === array()) {
			return true;
		}

		return array_keys($array) === range(0, count($array) - 1);
	}
}
