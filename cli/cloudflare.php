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

if (php_sapi_name() !== 'cli') {
	http_response_code(404);
	exit;
}

/*
 * Operator and CasC CLI for plugin_cloudflare.
 *
 *   cli/cloudflare.php export [--format=yaml|xml] [--file=PATH]
 *   cli/cloudflare.php validate --file=PATH [--format=yaml|xml]
 *   cli/cloudflare.php plan --file=PATH [--format=yaml|xml]
 *   cli/cloudflare.php apply --file=PATH --confirm=<PLAN_HASH> [--format=yaml|xml]
 *   cli/cloudflare.php health [--format=text|json]
 */

require(__DIR__ . '/../../../include/cli_check.php');
require_once(__DIR__ . '/../includes/ecosystem.php');

use Somethingwithproof\Cloudflare\Config\ConfigCodec;

$cloudflare_usage = "usage: cli/cloudflare.php export [--format=yaml|xml] [--file=PATH]\n"
	. "       cli/cloudflare.php validate --file=PATH [--format=yaml|xml]\n"
	. "       cli/cloudflare.php plan --file=PATH [--format=yaml|xml]\n"
	. "       cli/cloudflare.php apply --file=PATH --confirm=<PLAN_HASH> [--format=yaml|xml]\n"
	. "       cli/cloudflare.php health [--format=text|json]\n";

/**
 * @param array<int, string> $argv
 */
function cloudflare_cli_main(array $argv, string $usage) : int {
	$args    = array_slice($argv, 1);
	$command = array_shift($args);

	if (!is_string($command) || $command === '' || $command === '--help' || $command === '-h') {
		fwrite(STDERR, $usage);

		return $command === '--help' || $command === '-h' ? 0 : 1;
	}

	$format  = $command === 'health' ? 'text' : ConfigCodec::FORMAT_YAML;
	$file    = '';
	$confirm = '';

	foreach ($args as $arg) {
		if (strpos($arg, '--format=') === 0) {
			$format = substr($arg, 9);
		} elseif (strpos($arg, '--file=') === 0) {
			$file = substr($arg, 7);
		} elseif (strpos($arg, '--confirm=') === 0) {
			$confirm = substr($arg, 10);
		} else {
			fwrite(STDERR, 'ERROR: Unknown argument ' . $arg . "\n" . $usage);

			return 1;
		}
	}

	if ($command === 'health') {
		if ($format !== 'text' && $format !== 'json') {
			fwrite(STDERR, "ERROR: health --format must be text or json\n");

			return 1;
		}

		$checks = cloudflare_doctor_checks()->run();
		$worst  = 0;

		foreach ($checks as $check) {
			if ($check['status'] === 'fail') {
				$worst = 2;
			} elseif ($check['status'] === 'warn' && $worst < 1) {
				$worst = 1;
			}
		}

		if ($format === 'json') {
			print json_encode(array('status' => $worst === 0 ? 'ok' : ($worst === 1 ? 'warn' : 'fail'), 'checks' => $checks), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

			return $worst;
		}

		foreach ($checks as $check) {
			print sprintf("[%s] %s: %s -- %s\n", strtoupper($check['status']), $check['id'], $check['summary'], $check['detail']);
		}

		return $worst;
	}

	if (!ConfigCodec::isFormat($format)) {
		fwrite(STDERR, "ERROR: --format must be yaml or xml\n" . $usage);

		return 1;
	}

	$service = cloudflare_config_service();

	if ($command === 'export') {
		$output = $service->export($format);

		if ($file === '' || $file === '-') {
			fwrite(STDOUT, $output);

			return 0;
		}

		$handle = @fopen($file, 'x');
		if ($handle === false || fwrite($handle, $output) === false || !fclose($handle) || !chmod($file, 0600)) {
			fwrite(STDERR, 'ERROR: Cannot create ' . $file . " (file must not already exist)\n");

			return 1;
		}

		print 'Exported to ' . $file . "\n";

		return 0;
	}

	if (!in_array($command, array('validate', 'plan', 'apply'), true)) {
		fwrite(STDERR, 'ERROR: Unknown command ' . $command . "\n" . $usage);

		return 1;
	}

	if ($file === '') {
		fwrite(STDERR, 'ERROR: ' . $command . " requires --file=PATH\n" . $usage);

		return 1;
	}

	if ($file === '-') {
		$content = stream_get_contents(STDIN, ConfigCodec::MAX_BYTES + 1);
	} else {
		if (!is_file($file) || !is_readable($file)) {
			fwrite(STDERR, 'ERROR: Cannot read ' . $file . "\n");

			return 1;
		}
		$content = @file_get_contents($file, false, null, 0, ConfigCodec::MAX_BYTES + 1);
	}

	if (!is_string($content)) {
		fwrite(STDERR, "ERROR: Unable to read input\n");

		return 1;
	}

	if ($command === 'validate') {
		$res = $service->validate($content, $format);
		if ($res['valid']) {
			print "VALID\n";

			return 0;
		}
		foreach ($res['errors'] as $err) {
			fwrite(STDERR, 'ERROR: ' . $err . "\n");
		}

		return 2;
	}

	if ($command === 'plan') {
		$plan = $service->plan($content, $format);
		if (!$plan['valid']) {
			foreach ($plan['errors'] as $err) {
				fwrite(STDERR, 'ERROR: ' . $err . "\n");
			}

			return 2;
		}

		foreach ($plan['lines'] as $line) {
			print $line . "\n";
		}

		print 'PLAN_HASH=' . $plan['plan_hash'] . "\n";
		if ($plan['empty']) {
			print "No changes required.\n";
		} else {
			print 'Dry run; nothing written. Apply with: --confirm=' . $plan['plan_hash'] . "\n";
		}

		return 0;
	}

	if ($confirm === '') {
		fwrite(STDERR, "ERROR: apply requires --confirm=<PLAN_HASH>\n");

		return 1;
	}

	$res = $service->apply($content, $confirm, $format);
	if (!$res['applied']) {
		foreach ($res['errors'] as $err) {
			fwrite(STDERR, 'ERROR: ' . $err . "\n");
		}

		return 1;
	}

	foreach ($res['lines'] as $line) {
		print $line . "\n";
	}
	print 'Applied plan ' . $res['plan_hash'] . "\n";

	return 0;
}

exit(cloudflare_cli_main($_SERVER['argv'], $cloudflare_usage));
