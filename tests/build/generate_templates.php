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
 * Writes templates/cloudflare_templates.xml and
 * templates/resource/cloudflare_tunnels.xml from Catalog::all():
 *
 *   php tests/build/generate_templates.php <out dir>
 *
 * The layout follows Cacti's own export (lib/export.php in 1.2.25 and
 * 1.2.31, which are identical) field for field, so the importer reads it
 * the way it reads a shipped package. Output is byte-for-byte reproducible:
 * every hash is md5('plugin_cloudflare/' . key) over a fixed key, and
 * nothing depends on time or ordering outside this file and the catalog.
 */

require_once dirname(__DIR__, 2) . '/includes/autoload.php';

use Cacti\PluginCloudflare\Metrics\Catalog;
use Cacti\PluginCloudflare\Metrics\Metric;

/* include/global_arrays.php:1342 and :134-146 (1.2.19 to 1.2.31). */
const CF_TPL_VERSION = '0103';

const CF_TPL_TYPES = array(
	'graph_template'            => '00',
	'data_template'             => '01',
	'host_template'             => '02',
	'data_input_method'         => '03',
	'data_query'                => '04',
	'gprint_preset'             => '06',
	'data_input_field'          => '07',
	'data_template_item'        => '08',
	'graph_template_input'      => '09',
	'graph_template_item'       => '10',
	'data_query_graph'          => '11',
	'data_query_sv_graph'       => '12',
	'data_query_sv_data_source' => '13',
	'data_source_profile'       => '20',
);

/* Stock objects from cacti.sql, identical in 1.2.25 and 1.2.31: "Get Script
 * Server Data (Indexed)" (:1111) with its index_type, index_value and
 * output_type fields (:1242-1244), "5 Minute Collection" (:1320) and the
 * "Normal" GPRINT preset (:1688). */
const CF_TPL_SCRIPT_SERVER_INDEXED = '332111d8b54ac8ce939af87a7eac0c06';
const CF_TPL_INDEX_FIELDS          = array(
	'172b4b0eacee4948c6479f587b62e512',
	'30fb5d5bcf3d66bb5abe88596f357c26',
	'31112c85ae4ff821d3b288336288818c',
);
const CF_TPL_PROFILE_5MIN = 'd62c52891f4f9688729a5bc9fad91b18';
const CF_TPL_GPRINT_NORMAL = 'e9c43831e54eca8069317a2ce8c6f751';

/* data_source_types, graph_item_types and consolidation_functions in
 * include/global_arrays.php and include/global_constants.php:87-104. */
const CF_TPL_DS_GAUGE      = '1';
const CF_TPL_ITEM_COMMENT  = '1';
const CF_TPL_ITEM_LINE1    = '4';
const CF_TPL_ITEM_GPRINT   = '9';
const CF_TPL_CF_AVERAGE    = '1';
const CF_TPL_CF_MAX        = '3';
const CF_TPL_CF_LAST       = '4';

const CF_TPL_LABELS = array(
	'health'          => 'Health',
	'connectors'      => 'Connectors',
	'connections'     => 'Connections',
	'versions'        => 'Connector Versions',
	'collectAge'      => 'Collection Age',
	'collectFailures' => 'Collection Failures',
);

/* Graph key => title suffix, vertical label, and DS names with line colours.
 * Colours are stock entries of the colors table in both versions. */
const CF_TPL_GRAPHS = array(
	'connectors' => array(
		'suffix' => 'Connectors',
		'label'  => 'count',
		'ds'     => array('connectors' => '4668E4', 'connections' => '00CF00', 'versions' => 'EA8F00'),
	),
	'health' => array(
		'suffix' => 'Health',
		'label'  => 'state',
		'ds'     => array('health' => '00CF00'),
	),
	'collection' => array(
		'suffix' => 'Collection',
		'label'  => 'seconds, failures',
		'ds'     => array('collect_age' => '4668E4', 'collect_fail' => 'F51D30'),
	),
);

/**
 * The XML element name or reference for one object, as get_hash_version()
 * builds it (lib/functions.php:5311).
 */
function cf_tpl_ref(string $type, string $md5): string {
	return 'hash_' . CF_TPL_TYPES[$type] . CF_TPL_VERSION . $md5;
}

function cf_tpl_hash(string $key): string {
	return md5('plugin_cloudflare/' . $key);
}

function cf_tpl_own(string $type, string $key): string {
	return cf_tpl_ref($type, cf_tpl_hash($key));
}

/**
 * Renders nested arrays as Cacti's exporter does: one element per line,
 * tab indents, values escaped like xml_character_encode() (lib/export.php).
 *
 * @param array<string, mixed> $nodes
 */
function cf_tpl_render(array $nodes, int $depth): string {
	$text   = '';
	$indent = str_repeat("\t", $depth);

	foreach ($nodes as $name => $value) {
		if (is_array($value)) {
			$text .= $indent . '<' . $name . ">\n" . cf_tpl_render($value, $depth + 1) . $indent . '</' . $name . ">\n";
		} else {
			$text .= $indent . '<' . $name . '>' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8')
				. '</' . $name . ">\n";
		}
	}

	return $text;
}

/**
 * @return array<string, Metric> keyed by DS name, in catalog order
 */
function cf_tpl_metrics(): array {
	$metrics = array();
	$graphed = array();

	foreach (CF_TPL_GRAPHS as $graph) {
		$graphed = array_merge($graphed, array_keys($graph['ds']));
	}

	foreach (Catalog::all() as $metric) {
		if ($metric->type() !== Metric::GAUGE || !isset(CF_TPL_LABELS[$metric->queryField()])) {
			throw new RuntimeException('no template mapping for ' . $metric->name());
		}

		$metrics[$metric->dsName()] = $metric;
	}

	$names = array_keys($metrics);
	sort($names);
	sort($graphed);

	if ($names !== $graphed) {
		throw new RuntimeException('every catalog DS must appear in exactly one graph');
	}

	return $metrics;
}

/**
 * @param array<string, Metric> $metrics
 * @return array<string, mixed>
 */
function cf_tpl_data_template(array $metrics): array {
	$items = array();

	foreach ($metrics as $ds => $metric) {
		$items[cf_tpl_own('data_template_item', 'data_template_item/' . $ds)] = array(
			't_data_source_name'    => '',
			'data_source_name'      => $ds,
			't_rrd_minimum'         => '',
			'rrd_minimum'           => (string) $metric->min(),
			't_rrd_maximum'         => '',
			'rrd_maximum'           => $metric->max() === null ? 'U' : (string) $metric->max(),
			't_data_source_type_id' => '',
			'data_source_type_id'   => CF_TPL_DS_GAUGE,
			't_rrd_heartbeat'       => '',
			'rrd_heartbeat'         => '600',
			't_data_input_field_id' => '',
			'data_input_field_id'   => '0',
		);
	}

	$data = array();

	foreach (CF_TPL_INDEX_FIELDS as $i => $field) {
		$data[sprintf('item_%03d', $i)] = array(
			'data_input_field_id' => cf_tpl_ref('data_input_field', $field),
			't_value'             => 'on',
			'value'               => '',
		);
	}

	return array(
		'name'  => 'Cloudflare Tunnel',
		'ds'    => array(
			't_name'                   => '',
			'name'                     => '|host_description| - Cloudflare Tunnel',
			'data_source_path'         => '',
			'data_input_id'            => cf_tpl_ref('data_input_method', CF_TPL_SCRIPT_SERVER_INDEXED),
			't_data_source_profile_id' => '',
			'data_source_profile_id'   => cf_tpl_ref('data_source_profile', CF_TPL_PROFILE_5MIN),
			't_rrd_step'               => '',
			'rrd_step'                 => '300',
			't_active'                 => '',
			'active'                   => 'on',
		),
		'items' => $items,
		'data'  => $data,
	);
}

/**
 * @param array<string, string> $overrides
 * @return array<string, string>
 */
function cf_tpl_graph_fields(string $title, string $label, array $overrides): array {
	$values = array_merge(array(
		'title'                => $title,
		'vertical_label'       => $label,
		'image_format_id'      => '3',
		'height'               => '200',
		'width'                => '700',
		'base_value'           => '1000',
		'slope_mode'           => 'on',
		'auto_scale'           => 'on',
		'auto_scale_opts'      => '2',
		'auto_scale_log'       => '',
		'scale_log_units'      => '',
		'auto_scale_rigid'     => '',
		'upper_limit'          => '100',
		'lower_limit'          => '0',
		'unit_value'           => '',
		'unit_exponent_value'  => '',
		'unit_length'          => '',
		'no_gridfit'           => '',
		'alt_y_grid'           => '',
		'right_axis'           => '',
		'right_axis_label'     => '',
		'right_axis_format'    => '0',
		'right_axis_formatter' => '0',
		'left_axis_formatter'  => '0',
		'auto_padding'         => 'on',
		'dynamic_labels'       => '',
		'force_rules_legend'   => '',
		'tab_width'            => '40',
		'legend_position'      => '0',
		'legend_direction'     => '0',
	), $overrides);

	$fields = array();

	foreach ($values as $name => $value) {
		$fields['t_' . $name] = '';
		$fields[$name]        = $value;
	}

	return $fields;
}

/**
 * @return array<string, string>
 */
function cf_tpl_graph_item(string $type, string $task, string $color, string $cf, string $text, bool $break, int $sequence): array {
	return array(
		'graph_type_id'             => $type,
		'task_item_id'              => $task,
		'color_id'                  => $color,
		'alpha'                     => 'FF',
		'consolidation_function_id' => $cf,
		'cdef_id'                   => '0',
		'vdef_id'                   => '0',
		'shift'                     => '',
		'value'                     => '',
		'gprint_id'                 => cf_tpl_ref('gprint_preset', CF_TPL_GPRINT_NORMAL),
		'textalign'                 => '',
		'text_format'               => $text,
		'hard_return'               => $break ? 'on' : '',
		'line_width'                => $type === CF_TPL_ITEM_LINE1 ? '1.00' : '0.00',
		'dashes'                    => '',
		'dash_offset'               => '0',
		'sequence'                  => (string) $sequence,
	);
}

/**
 * @param array{suffix: string, label: string, ds: array<string, string>} $graph
 * @param array<string, Metric> $metrics
 * @return array<string, mixed>
 */
function cf_tpl_graph_template(string $key, array $graph, array $metrics): array {
	$items    = array();
	$inputs   = array();
	$sequence = 0;

	foreach ($graph['ds'] as $ds => $color) {
		$task  = cf_tpl_own('data_template_item', 'data_template_item/' . $ds);
		$label = CF_TPL_LABELS[$metrics[$ds]->queryField()];
		$rows  = array(
			array(CF_TPL_ITEM_LINE1, $color, CF_TPL_CF_AVERAGE, $label, false),
			array(CF_TPL_ITEM_GPRINT, '0', CF_TPL_CF_LAST, 'Cur:', false),
			array(CF_TPL_ITEM_GPRINT, '0', CF_TPL_CF_AVERAGE, 'Avg:', false),
			array(CF_TPL_ITEM_GPRINT, '0', CF_TPL_CF_MAX, 'Max:', true),
		);
		$used  = array();

		foreach ($rows as $row) {
			$sequence++;

			$item         = 'graph_template_item/' . $key . '/' . $sequence;
			$items[cf_tpl_own('graph_template_item', $item)] = cf_tpl_graph_item($row[0], $task, $row[1], $row[2], $row[3], $row[4], $sequence);

			/* graph_template_to_xml() writes these with the graph template
			 * type code (lib/export.php:157), and the importer reads only
			 * the md5 (lib/import.php:950 in 1.2.31). Matching it keeps an export of
			 * the imported template identical to this file. */
			$used[] = cf_tpl_own('graph_template', $item);
		}

		$inputs[cf_tpl_own('graph_template_input', 'graph_template_input/' . $key . '/' . $ds)] = array(
			'name'        => 'Data Source [' . $ds . ']',
			'description' => '',
			'column_name' => 'task_item_id',
			'items'       => implode('|', $used),
		);
	}

	$overrides = array();

	if ($key === 'health') {
		$sequence++;

		$items[cf_tpl_own('graph_template_item', 'graph_template_item/' . $key . '/' . $sequence)] = cf_tpl_graph_item(
			CF_TPL_ITEM_COMMENT,
			'0',
			'0',
			CF_TPL_CF_AVERAGE,
			'3 healthy, 2 degraded, 1 down, 0 inactive',
			true,
			$sequence
		);

		$overrides = array('auto_scale' => '', 'auto_scale_rigid' => 'on', 'upper_limit' => (string) $metrics['health']->max());
	}

	return array(
		'name'        => 'Cloudflare Tunnel - ' . $graph['suffix'],
		'multiple'    => '',
		'test_source' => '',
		'graph'       => cf_tpl_graph_fields(
			'|host_description| - |query_tunnelName| - ' . $graph['suffix'],
			$graph['label'],
			$overrides
		),
		'items'       => $items,
		'inputs'      => $inputs,
	);
}

/**
 * Every query graph maps all six fields. data_source_exists() reuses a data
 * source only when the field lists match (lib/template.php:2194), so the
 * three graphs of a tunnel share one data source and one RRD file.
 *
 * @param array<string, Metric> $metrics
 * @return array<string, mixed>
 */
function cf_tpl_data_query(array $metrics): array {
	$template = cf_tpl_own('data_template', 'data_template');
	$graphs   = array();

	foreach (CF_TPL_GRAPHS as $key => $graph) {
		$rrd = array();
		$i   = 0;

		foreach ($metrics as $ds => $metric) {
			$rrd[sprintf('item_%03d', $i++)] = array(
				'snmp_field_name'      => $metric->queryField(),
				'data_template_id'     => $template,
				'data_template_rrd_id' => cf_tpl_own('data_template_item', 'data_template_item/' . $ds),
			);
		}

		$graphs[cf_tpl_own('data_query_graph', 'data_query_graph/' . $key)] = array(
			'graph_template_id' => cf_tpl_own('graph_template', 'graph_template/' . $key),
			'name'              => $graph['suffix'],
			'rrd'               => $rrd,
			'sv_graph'          => array(
				cf_tpl_own('data_query_sv_graph', 'data_query_sv_graph/' . $key . '/title') => array(
					'field_name' => 'title',
					'sequence'   => '1',
					'text'       => '|host_description| - |query_tunnelName| - ' . $graph['suffix'],
				),
			),
			'sv_data_source'    => array(
				cf_tpl_own('data_query_sv_data_source', 'data_query_sv_data_source/' . $key . '/name') => array(
					'field_name'       => 'name',
					'data_template_id' => $template,
					'sequence'         => '1',
					'text'             => '|host_description| - |query_tunnelName|',
				),
			),
		);
	}

	return array(
		'name'          => 'Cloudflare Tunnels',
		'description'   => 'Cloudflare Tunnels of the account, read from the plugin tables.',
		'xml_path'      => '<path_cacti>/resource/script_server/cloudflare_tunnels.xml',
		'data_input_id' => cf_tpl_ref('data_input_method', CF_TPL_SCRIPT_SERVER_INDEXED),
		'graphs'        => $graphs,
	);
}

/**
 * @param array<string, Metric> $metrics
 */
function cf_tpl_templates_xml(array $metrics): string {
	$objects = array(
		cf_tpl_own('host_template', 'host_template') => array(
			'name'            => 'Cloudflare Account',
			'class'           => 'application',
			'graph_templates' => '',
			'data_queries'    => cf_tpl_own('data_query', 'data_query'),
		),
	);

	foreach (CF_TPL_GRAPHS as $key => $graph) {
		$objects[cf_tpl_own('graph_template', 'graph_template/' . $key)] = cf_tpl_graph_template($key, $graph, $metrics);
	}

	$objects[cf_tpl_own('data_template', 'data_template')] = cf_tpl_data_template($metrics);

	/* Shipped packages carry the GPRINT presets they use; a stock install
	 * may have deleted "Normal", and resolve_hash_to_id() would store 0. */
	$objects[cf_tpl_ref('gprint_preset', CF_TPL_GPRINT_NORMAL)] = array(
		'name'        => 'Normal',
		'gprint_text' => '%8.2lf %s',
	);

	$objects[cf_tpl_own('data_query', 'data_query')] = cf_tpl_data_query($metrics);

	return cf_tpl_render(array('cacti' => $objects), 0);
}

/**
 * Mirrors resource/script_server/host_cpu.xml. Field names equal their
 * query_name, which is what cloudflare_ss() receives.
 *
 * @param array<string, Metric> $metrics
 */
function cf_tpl_resource_xml(array $metrics): string {
	$fields = array(
		'tunnelId'   => array('name' => 'Tunnel ID', 'direction' => 'input', 'query_name' => 'tunnelId'),
		'tunnelName' => array('name' => 'Tunnel Name', 'direction' => 'input', 'query_name' => 'tunnelName'),
	);

	foreach ($metrics as $metric) {
		$fields[$metric->queryField()] = array(
			'name'       => CF_TPL_LABELS[$metric->queryField()],
			'direction'  => 'output',
			'query_name' => $metric->queryField(),
		);
	}

	return cf_tpl_render(array('interface' => array(
		'name'               => 'Get Cloudflare Tunnels',
		'script_path'        => '|path_cacti|/plugins/cloudflare/ss_cloudflare.php',
		'script_function'    => 'ss_cloudflare',
		'script_server'      => 'php',
		'arg_prepend'        => '|host_id|',
		'arg_index'          => 'index',
		'arg_query'          => 'query',
		'arg_get'            => 'get',
		'arg_num_indexes'    => 'num_indexes',
		'output_delimiter'   => '!',
		'index_order'        => 'tunnelId',
		'index_order_type'   => 'alphabetic',
		'index_title_format' => '|chosen_order_field|',
		'fields'             => $fields,
	)), 0);
}

function cf_tpl_write(string $path, string $contents): void {
	if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) {
		throw new RuntimeException('cannot create ' . dirname($path));
	}

	if (file_put_contents($path, $contents) !== strlen($contents)) {
		throw new RuntimeException('cannot write ' . $path);
	}
}

if (PHP_SAPI !== 'cli' || !isset($argv[1]) || $argv[1] === '') {
	fwrite(STDERR, "usage: php tests/build/generate_templates.php <out dir>\n");
	exit(2);
}

$metrics = cf_tpl_metrics();

cf_tpl_write(rtrim($argv[1], '/') . '/cloudflare_templates.xml', cf_tpl_templates_xml($metrics));
cf_tpl_write(rtrim($argv[1], '/') . '/resource/cloudflare_tunnels.xml', cf_tpl_resource_xml($metrics));
