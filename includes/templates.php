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

require_once __DIR__ . '/bootstrap.php';

use Cacti\PluginCloudflare\Metrics\Catalog;
use Cacti\PluginCloudflare\Metrics\MetricMappingRepository;
use Cacti\PluginCloudflare\Storage\Database;

if (!defined('CLOUDFLARE_TEMPLATES_LOCK')) {
	define('CLOUDFLARE_TEMPLATES_LOCK', 'plugin_cloudflare_templates');
	define('CLOUDFLARE_TEMPLATES_XML', dirname(__DIR__) . '/templates/cloudflare_templates.xml');
	define('CLOUDFLARE_TEMPLATES_RESOURCE', dirname(__DIR__) . '/templates/resource/cloudflare_tunnels.xml');
	// The graph keys of tests/build/generate_templates.php.
	define('CLOUDFLARE_TEMPLATES_GRAPHS', array('connectors', 'health', 'collection'));
}

// Every object hash in the shipped XML, as tests/build/generate_templates.php builds it.
function cloudflare_template_hash(string $key) : string {
	return md5('plugin_cloudflare/' . $key);
}

/**
 * The one file the plugin writes outside its own directory. 1.2.31 reads a
 * data query XML only from inside <path_cacti>/resource after realpath()
 * (lib/data_query.php:612-620), so neither the plugin path nor a symbolic
 * link works.
 */
function cloudflare_templates_resource_path() : string {
	global $config;

	$base = isset($config['base_path']) ? (string) $config['base_path'] : '';

	return ($base === '' || !is_dir($base)) ? '' : $base . '/resource/script_server/cloudflare_tunnels.xml';
}

/**
 * Copies the resource XML, imports the templates when the shipped XML
 * changed since the last successful import, and records the metric
 * mappings. Runs from install and upgrade, so it logs and returns false
 * rather than throwing: Cacti disables a plugin whose install throws.
 * The lock keeps two upgrading processes from importing at once, which
 * would create every object twice.
 */
function cloudflare_templates_install(int $lock_timeout = 10) : bool {
	$target = cloudflare_templates_resource_path();

	if ($target === '') {
		cacti_log(
			'CLOUDFLARE: templates not installed; the Cacti base path is unknown',
			false,
			'CLOUDFLARE',
			POLLER_VERBOSITY_LOW
		);

		return false;
	}

	try {
		$db = cloudflare_db();

		if (!$db->acquireLock(CLOUDFLARE_TEMPLATES_LOCK, $lock_timeout)) {
			cacti_log(
				'CLOUDFLARE: template install deferred; another process holds the template lock',
				false,
				'CLOUDFLARE',
				POLLER_VERBOSITY_LOW
			);

			return false;
		}

		try {
			return cloudflare_templates_install_locked($db, $target);
		} finally {
			$db->releaseLock(CLOUDFLARE_TEMPLATES_LOCK);
		}
	} catch (Throwable $e) {
		cacti_log('CLOUDFLARE: template install failed (' . get_class($e) . ')', false, 'CLOUDFLARE', POLLER_VERBOSITY_LOW);

		return false;
	}
}

function cloudflare_templates_install_locked(Database $db, string $target) : bool {
	if (!cloudflare_templates_copy_resource($target)) {
		return false;
	}

	$xml = file_get_contents(CLOUDFLARE_TEMPLATES_XML);

	if ($xml === false) {
		cacti_log('CLOUDFLARE: cannot read ' . CLOUDFLARE_TEMPLATES_XML, false, 'CLOUDFLARE', POLLER_VERBOSITY_LOW);

		return false;
	}

	$sha = hash('sha256', $xml);

	/* Forced: Cacti caches settings per request, and another process may
	 * have imported while this one waited for the lock. A missing object
	 * means an admin deleted it, and importing again restores it. */
	$recorded = (string) read_config_option('cloudflare_templates_sha256', true);

	if ($recorded !== $sha || cloudflare_templates_ready() === null) {
		if (!cloudflare_templates_import($xml)) {
			return false;
		}

		set_config_option('cloudflare_templates_sha256', $sha);
	}

	(new MetricMappingRepository($db))->sync(Catalog::all(), cloudflare_template_hash('data_template'));

	return true;
}

/**
 * Writes a temporary file beside the target and renames it over, so the
 * poller never reads a half-written XML. The 'x' mode refuses an existing
 * name, so a planted link at the temporary path is never followed.
 */
function cloudflare_templates_copy_resource(string $target) : bool {
	$sha = hash_file('sha256', CLOUDFLARE_TEMPLATES_RESOURCE);

	if ($sha === false) {
		cacti_log('CLOUDFLARE: cannot read ' . CLOUDFLARE_TEMPLATES_RESOURCE, false, 'CLOUDFLARE', POLLER_VERBOSITY_LOW);

		return false;
	}

	/* A link is always replaced: rename() swaps the link itself, and a link
	 * leaving resource/ would fail the realpath() check anyway. */
	if (!is_link($target) && is_file($target) && @hash_file('sha256', $target) === $sha) {
		return true;
	}

	$content = (string) file_get_contents(CLOUDFLARE_TEMPLATES_RESOURCE);
	$temp    = dirname($target) . '/.cloudflare_tunnels.xml.' . bin2hex(random_bytes(8));
	$handle  = @fopen($temp, 'xb');

	if ($handle === false) {
		cacti_log('CLOUDFLARE: cannot write ' . $target, false, 'CLOUDFLARE', POLLER_VERBOSITY_LOW);

		return false;
	}

	$written = fwrite($handle, $content);
	$closed  = fclose($handle);

	// fopen() honours the umask; the poller may run as another user.
	if ($written !== strlen($content) || !$closed || !@chmod($temp, 0644) || !@rename($temp, $target)) {
		@unlink($temp);

		cacti_log('CLOUDFLARE: cannot write ' . $target, false, 'CLOUDFLARE', POLLER_VERBOSITY_LOW);

		return false;
	}

	return true;
}

/**
 * import_xml_data() reports problems three ways, and none of them through
 * is_error_message(), which reads only form validation errors from the
 * session: it returns false or an empty array (lib/import.php:39, :56,
 * :177-227), it queues ids in the global $import_messages, and it marks an
 * object it could not save with result 'fail' (:978 and the other xml_to_*
 * functions). All three are checked, and the objects must then resolve.
 */
function cloudflare_templates_import(string $xml) : bool {
	global $config, $import_messages;

	/* templates_import.php loads the same three libraries; import.php calls
	 * retemplate_graphs() and update_replication_crc() from the other two. */
	$libraries = array(
		'import_xml_data'        => 'import.php',
		'retemplate_graphs'      => 'template.php',
		'update_replication_crc' => 'utility.php',
	);

	foreach ($libraries as $function => $file) {
		if (!function_exists($function)) {
			include_once($config['base_path'] . '/lib/' . $file);
		}
	}

	$profile_id = db_fetch_cell_prepared('SELECT id FROM data_source_profiles WHERE `default`=\'on\'');

	if (empty($profile_id)) {
		cacti_log(
			'CLOUDFLARE: template import skipped; no default data source profile',
			false,
			'CLOUDFLARE',
			POLLER_VERBOSITY_LOW
		);

		return false;
	}

	$import_messages = array();
	$results         = import_xml_data($xml, false, $profile_id);
	$failed          = '';

	if (!is_array($results) || $results === array() || cacti_sizeof($import_messages) > 0) {
		$failed = 'no result';
	} else {
		foreach ($results as $type => $items) {
			if ($type === 'files' || !is_array($items)) {
				continue;
			}

			foreach ($items as $item) {
				if (!isset($item['result']) || $item['result'] !== 'success') {
					$failed = (string) $type;

					break 2;
				}
			}
		}
	}

	if ($failed === '' && cloudflare_templates_ready() === null) {
		$failed = 'objects missing';
	}

	if ($failed !== '') {
		cacti_log('CLOUDFLARE: template import failed (' . $failed . ')', false, 'CLOUDFLARE', POLLER_VERBOSITY_LOW);

		return false;
	}

	return true;
}

/**
 * Resolves the imported objects by hash.
 *
 * @return array{
 *         host_template_id: int,
 *         data_query_id: int,
 *         data_template_id: int,
 *         graphs: array<string, array{graph_template_id: int, snmp_query_graph_id: int}>
 *         }|null null when the resource file or any object is missing
 */
function cloudflare_templates_ready() : ?array {
	$target = cloudflare_templates_resource_path();

	if ($target === '' || !is_file($target)) {
		return null;
	}

	$host_template_id = cloudflare_template_id('host_template', 'host_template');
	$data_query_id    = cloudflare_template_id('snmp_query', 'data_query');
	$data_template_id = cloudflare_template_id('data_template', 'data_template');

	if ($host_template_id === null || $data_query_id === null || $data_template_id === null) {
		return null;
	}

	$graphs = array();

	foreach (CLOUDFLARE_TEMPLATES_GRAPHS as $key) {
		$graph_template_id   = cloudflare_template_id('graph_templates', 'graph_template/' . $key);
		$snmp_query_graph_id = cloudflare_template_id('snmp_query_graph', 'data_query_graph/' . $key);

		if ($graph_template_id === null || $snmp_query_graph_id === null) {
			return null;
		}

		$graphs[$key] = array(
			'graph_template_id'   => $graph_template_id,
			'snmp_query_graph_id' => $snmp_query_graph_id,
		);
	}

	return array(
		'host_template_id' => $host_template_id,
		'data_query_id'    => $data_query_id,
		'data_template_id' => $data_template_id,
		'graphs'           => $graphs,
	);
}

// $table is one of the fixed names above, never input.
function cloudflare_template_id(string $table, string $key) : ?int {
	$id = db_fetch_cell_prepared('SELECT id FROM `' . $table . '` WHERE hash = ?', array(cloudflare_template_hash($key)));

	return (is_numeric($id) && (int) $id > 0) ? (int) $id : null;
}
