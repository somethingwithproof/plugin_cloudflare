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

namespace Cacti\PluginCloudflare\Tests\Unit\Templates;

use Cacti\PluginCloudflare\Metrics\Catalog;
use Cacti\PluginCloudflare\Metrics\Metric;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * Checks the committed Cacti templates against the generator and the
 * catalog. Cacti imports them with import_xml_data() (lib/import.php:25).
 */
final class TemplatesTest extends TestCase {
	private const ROOT = __DIR__ . '/../../..';

	private const TEMPLATES = self::ROOT . '/templates/cloudflare_templates.xml';

	private const RESOURCE = self::ROOT . '/templates/resource/cloudflare_tunnels.xml';

	/* Stock Cacti objects the templates reference but do not carry: the
	 * "Get Script Server Data (Indexed)" method and its index_type,
	 * index_value and output_type fields (cacti.sql:1111 and :1242-1244 in
	 * 1.2.25), and the "5 Minute Collection" profile, which the importer
	 * replaces with the profile it is given (lib/import.php:1078 in 1.2.31). */
	private const STOCK_HASHES = array(
		'332111d8b54ac8ce939af87a7eac0c06',
		'172b4b0eacee4948c6479f587b62e512',
		'30fb5d5bcf3d66bb5abe88596f357c26',
		'31112c85ae4ff821d3b288336288818c',
		'd62c52891f4f9688729a5bc9fad91b18',
	);

	public static function setUpBeforeClass(): void {
		require_once self::ROOT . '/tests/Support/CactiGlueStubs.php';
		require_once self::ROOT . '/includes/script_server.php';
	}

	public function testGeneratedFilesMatchCommitted(): void {
		$out = sys_get_temp_dir() . '/cf-templates-' . bin2hex(random_bytes(6));

		try {
			$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::ROOT . '/tests/build/generate_templates.php')
				. ' ' . escapeshellarg($out) . ' 2>&1';

			exec($command, $output, $status);

			$this->assertSame(0, $status, implode("\n", $output));
			$this->assertSame((string) @file_get_contents(self::TEMPLATES), (string) file_get_contents($out . '/cloudflare_templates.xml'));
			$this->assertSame(
				(string) @file_get_contents(self::RESOURCE),
				(string) file_get_contents($out . '/resource/cloudflare_tunnels.xml')
			);
		} finally {
			@unlink($out . '/resource/cloudflare_tunnels.xml');
			@unlink($out . '/cloudflare_templates.xml');
			@rmdir($out . '/resource');
			@rmdir($out);
		}
	}

	public function testBothXmlFilesParse(): void {
		$this->assertSame('cacti', $this->templates()->getName());
		$this->assertSame('interface', $this->resource()->getName());
	}

	/* Cacti finds every object again by these hashes on reimport, and the
	 * plugin resolves its IDs by them, so a change here orphans installs. */
	public function testHashesStable(): void {
		$hashes = $this->elementHashes();

		$this->assertSame('a19125c4ae4555e8018d5490770cf44c', md5(implode("\n", $hashes)), implode("\n", $hashes));
		$this->assertContains('hash_020103' . md5('plugin_cloudflare/host_template'), $hashes);
		$this->assertContains('hash_040103' . md5('plugin_cloudflare/data_query'), $hashes);
		$this->assertContains('hash_010103' . md5('plugin_cloudflare/data_template'), $hashes);

		foreach (array('connectors', 'health', 'collection') as $graph) {
			$this->assertContains('hash_000103' . md5('plugin_cloudflare/graph_template/' . $graph), $hashes);
		}
	}

	/* include/global_arrays.php:134-146 maps 1.2.19 to 1.2.31 to 0103, and
	 * check_hash_version() rejects a code newer than the running Cacti. */
	public function testHashVersionIs0103(): void {
		preg_match_all('/hash_[0-9a-f]+/', (string) file_get_contents(self::TEMPLATES), $matches);

		$this->assertNotEmpty($matches[0]);

		foreach ($matches[0] as $hash) {
			$this->assertMatchesRegularExpression('/^hash_[0-9]{2}0103[0-9a-f]{32}$/', $hash);
		}
	}

	/* resolve_hash_to_id() stores 0 for an unknown hash and only logs it
	 * (lib/import.php:2469 in 1.2.31), so a broken reference would import silently. */
	public function testEveryReferenceResolves(): void {
		$known = array_map(static function (string $hash): string {
			return substr($hash, -32);
		}, $this->elementHashes());

		preg_match_all('/>([^<]*hash_[^<]*)</', (string) file_get_contents(self::TEMPLATES), $matches);

		$this->assertNotEmpty($matches[1]);

		foreach ($matches[1] as $value) {
			foreach (explode('|', $value) as $reference) {
				$hash = substr($reference, -32);

				$this->assertTrue(in_array($hash, $known, true) || in_array($hash, self::STOCK_HASHES, true), $reference);
			}
		}
	}

	public function testOutputFieldsMatchCatalogAndScript(): void {
		$inputs  = array();
		$outputs = array();

		foreach ($this->resource()->fields->children() as $field) {
			$this->assertSame($field->getName(), (string) $field->query_name);

			if ((string) $field->direction === 'output') {
				$outputs[] = $field->getName();
			} else {
				$this->assertSame('input', (string) $field->direction);
				$inputs[] = $field->getName();
			}
		}

		$fields = array_map(static function (Metric $m): string {
			return $m->queryField();
		}, Catalog::all());

		$this->assertSame(array('tunnelId', 'tunnelName'), $inputs);
		$this->assertSame($fields, $outputs);

		$tunnels = array('0b0e3f43-63a8-4f0c-bb4c-0d6b2b5a2f10' => 'edge');

		foreach (array_merge($inputs, $outputs) as $field) {
			$this->assertNotSame('', cloudflare_ss_query($tunnels, $field), $field);
		}

		$this->assertSame('', cloudflare_ss_query($tunnels, 'collect_age'));
	}

	public function testDsNamesMatchCatalog(): void {
		$template = $this->object('data_template');

		$this->assertSame('Cloudflare Tunnel', (string) $template->name);
		$this->assertSame('hash_030103332111d8b54ac8ce939af87a7eac0c06', (string) $template->ds->data_input_id);

		$items = array();

		foreach ($template->items->children() as $item) {
			$items[(string) $item->data_source_name] = $item;
		}

		$metrics = Catalog::all();

		$this->assertSame(array_map(static function (Metric $m): string {
			return $m->dsName();
		}, $metrics), array_keys($items));

		foreach ($metrics as $metric) {
			$item = $items[$metric->dsName()];

			$this->assertSame(Metric::GAUGE, $metric->type());
			$this->assertSame('1', (string) $item->data_source_type_id, $metric->dsName());
			$this->assertSame('600', (string) $item->rrd_heartbeat, $metric->dsName());
			$this->assertSame((string) $metric->min(), (string) $item->rrd_minimum, $metric->dsName());
			$this->assertSame($metric->max() === null ? 'U' : (string) $metric->max(), (string) $item->rrd_maximum, $metric->dsName());
		}
	}

	/* get_script_query_path() refuses a script path containing '..'
	 * (lib/data_query.php:2389 in 1.2.31). */
	public function testScriptPathHasNoDotDot(): void {
		$resource = $this->resource();

		$this->assertSame('|path_cacti|/plugins/cloudflare/ss_cloudflare.php', (string) $resource->script_path);
		$this->assertStringNotContainsString('..', (string) $resource->script_path);
		$this->assertSame('ss_cloudflare', (string) $resource->script_function);
		$this->assertSame('php', (string) $resource->script_server);
		$this->assertSame('|host_id|', (string) $resource->arg_prepend);
		$this->assertSame('num_indexes', (string) $resource->arg_num_indexes);
		$this->assertSame('!', (string) $resource->output_delimiter);
		$this->assertSame('tunnelId', (string) $resource->index_order);
		$this->assertSame('alphabetic', (string) $resource->index_order_type);
		$this->assertSame(
			'<path_cacti>/resource/script_server/cloudflare_tunnels.xml',
			(string) $this->object('data_query')->xml_path
		);
	}

	public function testGraphTitlesNameTheTunnel(): void {
		$suffixes = array('connectors' => 'Connectors', 'health' => 'Health', 'collection' => 'Collection');

		foreach ($suffixes as $key => $suffix) {
			$graph = $this->object('graph_template/' . $key);

			$this->assertSame('Cloudflare Tunnel - ' . $suffix, (string) $graph->name);
			$this->assertSame('|host_description| - |query_tunnelName| - ' . $suffix, (string) $graph->graph->title);
		}

		$host = $this->object('host_template');

		$this->assertSame('Cloudflare Account', (string) $host->name);
		$this->assertSame('hash_040103' . md5('plugin_cloudflare/data_query'), (string) $host->data_queries);
	}

	/**
	 * @return array<int, string> element names that are object hashes, sorted
	 */
	private function elementHashes(): array {
		preg_match_all('/<(hash_[0-9a-f]{38})>/', (string) file_get_contents(self::TEMPLATES), $matches);

		$hashes = array_values(array_unique($matches[1]));
		sort($hashes);

		return $hashes;
	}

	private function object(string $key): SimpleXMLElement {
		$types = array('host_template' => '02', 'data_query' => '04', 'data_template' => '01', 'graph_template' => '00');
		$type  = $types[explode('/', $key)[0]];
		$name  = 'hash_' . $type . '0103' . md5('plugin_cloudflare/' . $key);
		$found = $this->templates()->{$name};

		$this->assertCount(1, $found, $name);

		return $found[0];
	}

	private function templates(): SimpleXMLElement {
		return $this->load(self::TEMPLATES);
	}

	private function resource(): SimpleXMLElement {
		return $this->load(self::RESOURCE);
	}

	private function load(string $path): SimpleXMLElement {
		$this->assertFileExists($path);

		$xml = simplexml_load_string((string) file_get_contents($path));

		$this->assertInstanceOf(SimpleXMLElement::class, $xml, $path);

		return $xml;
	}
}
