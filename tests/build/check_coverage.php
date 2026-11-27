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

if (PHP_SAPI !== 'cli') {
	exit(1);
}

$cloverPath = isset($argv[1]) ? (string) $argv[1] : 'build/coverage/clover.xml';
$minPercent = isset($argv[2]) ? (float) $argv[2] : 85.0;

if (!is_file($cloverPath) || !is_readable($cloverPath)) {
	fwrite(STDERR, 'FAIL: Clover coverage report not found: ' . $cloverPath . "\n");
	exit(1);
}

$xml = @simplexml_load_file($cloverPath);
if ($xml === false) {
	fwrite(STDERR, 'FAIL: Could not parse Clover XML: ' . $cloverPath . "\n");
	exit(1);
}

$metricsList = $xml->xpath('/coverage/project/metrics');
if (!is_array($metricsList) || !isset($metricsList[0])) {
	fwrite(STDERR, 'FAIL: Missing /coverage/project/metrics element in ' . $cloverPath . "\n");
	exit(1);
}

$metrics    = $metricsList[0];
$statements = (int) $metrics['statements'];
$covered    = (int) $metrics['coveredstatements'];

if ($statements <= 0) {
	fwrite(STDERR, "FAIL: Clover report recorded 0 statements\n");
	exit(1);
}

$percent = ($covered / $statements) * 100.0;
fwrite(STDOUT, sprintf("Line coverage: %d / %d (%.2f%%), minimum required: %.2f%%\n", $covered, $statements, $percent, $minPercent));

if ($percent < $minPercent) {
	fwrite(STDERR, sprintf("FAIL: Line coverage %.2f%% is below minimum threshold %.2f%%\n", $percent, $minPercent));
	exit(1);
}

exit(0);
