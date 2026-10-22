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

namespace Cacti\PluginCloudflare\Tests\Unit\Api;

use Cacti\PluginCloudflare\Api\ApiException;
use Cacti\PluginCloudflare\Api\CloudflareClient;
use Cacti\PluginCloudflare\Api\ErrorClass;
use Cacti\PluginCloudflare\Api\RetryPolicy;
use Cacti\PluginCloudflare\Http\Response;
use Cacti\PluginCloudflare\Http\TransportException;
use Cacti\PluginCloudflare\Tests\Support\FakeTransport;
use Cacti\PluginCloudflare\Tests\Support\FrozenClock;
use Cacti\PluginCloudflare\Tests\Support\RecordingSleeper;
use Cacti\PluginCloudflare\Tests\Support\SecretAssertions;
use PHPUnit\Framework\TestCase;

final class TunnelListingTest extends TestCase {
	use SecretAssertions;

	private const ACCOUNT = 'ed17574386854bf78a67040be0a770b0';
	private const TUNNEL  = 'f70ff985-a4ef-4643-bbbc-4a0ed4fc8415';
	private const BASE    = 'https://api.cloudflare.com/client/v4';

	/** @var FakeTransport */
	private $transport;

	/** @var string */
	private $token;

	protected function setUp(): void {
		$this->transport = new FakeTransport();
		$this->token     = 'cfut_' . bin2hex(random_bytes(16));
	}

	private function client(): CloudflareClient {
		return new CloudflareClient($this->transport, new RetryPolicy(new FrozenClock(), new RecordingSleeper()));
	}

	private function expectFailure(callable $call, string $class): ApiException {
		try {
			$call();
		} catch (ApiException $e) {
			$this->assertSame($class, $e->getErrorClass());
			$this->assertNotLeaked($this->token, $e->getMessage(), 'exception message');

			return $e;
		}

		$this->fail('expected ApiException of class ' . $class);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function item(int $n): array {
		return array(
			'id'                => sprintf('00000000-0000-4000-8000-%012x', $n),
			'name'              => 'tunnel-' . $n,
			'status'            => 'healthy',
			'tun_type'          => 'cfd_tunnel',
			'created_at'        => '2021-01-25T18:22:34.317854Z',
			'conns_active_at'   => '2009-11-10T23:00:00Z',
			'conns_inactive_at' => null,
			'connections'       => array(),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @param array<string, mixed>|null        $info
	 */
	private function page(array $items, ?array $info): Response {
		$body = array('success' => true, 'errors' => array(), 'messages' => array(), 'result' => $items);
		if ($info !== null) {
			$body['result_info'] = $info;
		}

		return new Response(200, array(), (string) json_encode($body));
	}

	public function testListsSinglePage(): void {
		$this->transport->queue(FakeTransport::fixture('tunnel-list'));

		$tunnels = $this->client()->listTunnels(self::ACCOUNT, $this->token);

		$this->assertCount(3, $tunnels);
		$this->assertSame('f70ff985-a4ef-4643-bbbc-4a0ed4fc8415', $tunnels[0]->id);
		$this->assertSame('blog', $tunnels[0]->name);
		$this->assertSame('healthy', $tunnels[0]->status);
		$this->assertSame('cfd_tunnel', $tunnels[0]->tunType);
		$this->assertSame(1611598954, $tunnels[0]->createdAt);
		$this->assertSame(1257894000, $tunnels[0]->connsActiveAt);
		$this->assertNull($tunnels[0]->connsInactiveAt);
		$this->assertSame(1767225600, $tunnels[2]->connsInactiveAt);
		$this->assertCount(1, $this->transport->requests());
		$this->assertSame(
			self::BASE . '/accounts/' . self::ACCOUNT . '/cfd_tunnel?is_deleted=false&per_page=1000&page=1',
			$this->transport->requests()[0]->url()
		);
	}

	public function testLargeAccountPaginates(): void {
		$all = array();
		for ($i = 1; $i <= 1500; $i++) {
			$all[] = $this->item($i);
		}
		$this->transport->queue($this->page(
			array_slice($all, 0, 1000),
			array('page' => 1, 'per_page' => 1000, 'count' => 1000, 'total_count' => 1500)
		));
		$this->transport->queue($this->page(
			array_slice($all, 1000),
			array('page' => 2, 'per_page' => 1000, 'count' => 500, 'total_count' => 1500)
		));

		$tunnels = $this->client()->listTunnels(self::ACCOUNT, $this->token);

		$this->assertCount(1500, $tunnels);
		$this->assertSame('tunnel-1500', $tunnels[1499]->name);
		$this->assertCount(2, $this->transport->requests());
		$this->assertStringEndsWith('&page=2', $this->transport->requests()[1]->url());
	}

	public function testEmptyAccountReturnsEmptyList(): void {
		$this->transport->queue($this->page(
			array(),
			array('page' => 1, 'per_page' => 1000, 'count' => 0, 'total_count' => 0)
		));

		$this->assertSame(array(), $this->client()->listTunnels(self::ACCOUNT, $this->token));
	}

	public function testShortPageBeforeTotalIsPartial(): void {
		$this->transport->queue(FakeTransport::fixture('tunnel-list-short'));
		$this->transport->queue($this->page(
			array(),
			array('page' => 2, 'per_page' => 1000, 'count' => 0, 'total_count' => 3)
		));

		$this->expectFailure(function (): void {
			$this->client()->listTunnels(self::ACCOUNT, $this->token);
		}, ErrorClass::MALFORMED);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>|null}>
	 */
	public function badResultInfo(): array {
		return array(
			'missing'          => array(null),
			'string total'     => array(array('page' => 1, 'total_count' => '3')),
			'no total'         => array(array('page' => 1, 'per_page' => 1000, 'count' => 3)),
			'negative total'   => array(array('total_count' => -1)),
		);
	}

	/**
	 * @dataProvider badResultInfo
	 *
	 * @param array<string, mixed>|null $info
	 */
	public function testMissingResultInfoIsMalformed(?array $info): void {
		$this->transport->queue($this->page(array($this->item(1)), $info));

		$this->expectFailure(function (): void {
			$this->client()->listTunnels(self::ACCOUNT, $this->token);
		}, ErrorClass::MALFORMED);
	}

	public function testBadItemFailsWholeListing(): void {
		$this->transport->queue(FakeTransport::fixture('tunnel-list-bad-item'));

		$e = $this->expectFailure(function (): void {
			$this->client()->listTunnels(self::ACCOUNT, $this->token);
		}, ErrorClass::MALFORMED);
		$this->assertStringNotContainsString('exploding', $e->getMessage());
	}

	public function testBadItemOnLaterPageDiscardsEarlierPages(): void {
		$bad       = $this->item(3);
		$bad['id'] = 'NOT-A-UUID';
		$this->transport->queue($this->page(
			array($this->item(1), $this->item(2)),
			array('page' => 1, 'per_page' => 2, 'count' => 2, 'total_count' => 3)
		));
		$this->transport->queue($this->page(
			array($bad),
			array('page' => 2, 'per_page' => 2, 'count' => 1, 'total_count' => 3)
		));

		$this->expectFailure(function (): void {
			$this->client()->listTunnels(self::ACCOUNT, $this->token);
		}, ErrorClass::MALFORMED);
	}

	public function testDuplicateTunnelIdIsMalformed(): void {
		$this->transport->queue($this->page(
			array($this->item(1), $this->item(1)),
			array('page' => 1, 'per_page' => 1000, 'count' => 2, 'total_count' => 2)
		));

		$this->expectFailure(function (): void {
			$this->client()->listTunnels(self::ACCOUNT, $this->token);
		}, ErrorClass::MALFORMED);
	}

	public function testPageCap(): void {
		for ($p = 1; $p <= 60; $p++) {
			$this->transport->queue($this->page(
				array($this->item($p)),
				array('page' => $p, 'per_page' => 1000, 'count' => 1, 'total_count' => 100000)
			));
		}

		$this->expectFailure(function (): void {
			$this->client()->listTunnels(self::ACCOUNT, $this->token);
		}, ErrorClass::MALFORMED);
		$this->assertCount(50, $this->transport->requests());
	}

	public function testTunnelNameControlCharsStripped(): void {
		$item         = $this->item(1);
		$item['name'] = "ed\x00ge\r\n\x1b[31m-\x7fa";
		$long         = $this->item(2);
		$long['name'] = str_repeat("\u{00e9}", 300);
		$this->transport->queue($this->page(
			array($item, $long),
			array('page' => 1, 'per_page' => 1000, 'count' => 2, 'total_count' => 2)
		));

		$tunnels = $this->client()->listTunnels(self::ACCOUNT, $this->token);

		$this->assertSame('edge[31m-a', $tunnels[0]->name);
		$length = function_exists('mb_strlen') ? mb_strlen($tunnels[1]->name, 'UTF-8') : strlen($tunnels[1]->name);
		$this->assertSame(255, $length);
	}

	public function testBadTimestampsBecomeNull(): void {
		$item                      = $this->item(1);
		$item['created_at']        = 'yesterday-ish';
		$item['conns_active_at']   = 12345;
		$item['conns_inactive_at'] = null;
		$this->transport->queue($this->page(
			array($item),
			array('page' => 1, 'per_page' => 1000, 'count' => 1, 'total_count' => 1)
		));

		$tunnels = $this->client()->listTunnels(self::ACCOUNT, $this->token);

		$this->assertNull($tunnels[0]->createdAt);
		$this->assertNull($tunnels[0]->connsActiveAt);
		$this->assertNull($tunnels[0]->connsInactiveAt);
	}

	public function testConnections1001IsNotFound(): void {
		$this->transport->queue(FakeTransport::fixture('connections-1001', 401));

		$e = $this->expectFailure(function (): void {
			$this->client()->listConnections(self::ACCOUNT, $this->token, self::TUNNEL);
		}, ErrorClass::NOT_FOUND);
		$this->assertSame(401, $e->getHttpStatus());
		$this->assertSame(
			self::BASE . '/accounts/' . self::ACCOUNT . '/cfd_tunnel/' . self::TUNNEL . '/connections',
			$this->transport->requests()[0]->url()
		);
	}

	public function testConnections404IsNotFound(): void {
		$this->transport->queue(FakeTransport::fixture('error-envelope', 404));

		$this->expectFailure(function (): void {
			$this->client()->listConnections(self::ACCOUNT, $this->token, self::TUNNEL);
		}, ErrorClass::NOT_FOUND);
	}

	public function testConnections401Code10000StaysAuth(): void {
		$this->transport->queue(FakeTransport::fixture('auth-error', 401));

		$this->expectFailure(function (): void {
			$this->client()->listConnections(self::ACCOUNT, $this->token, self::TUNNEL);
		}, ErrorClass::AUTH);
	}

	public function testConnectionsParsesClients(): void {
		$this->transport->queue(FakeTransport::fixture('tunnel-connections'));

		$connectors = $this->client()->listConnections(self::ACCOUNT, $this->token, self::TUNNEL);

		$this->assertCount(1, $connectors);
		$this->assertSame('1bedc50d-42b3-473c-b108-ff3d10c0d925', $connectors[0]->id);
		$this->assertSame('2022.7.1', $connectors[0]->version);
		$this->assertSame('linux_amd64', $connectors[0]->arch);
		$this->assertSame(1257894000, $connectors[0]->runAt);
		$this->assertSame(1, $connectors[0]->connectionCount);
		$this->assertSame(array('DFW'), $connectors[0]->colos);
		$this->assertSame(array('10.1.0.137'), $connectors[0]->originIps);
	}

	public function testConnectionsColosSortedUniqueAndBadIpsDropped(): void {
		$conn = function (string $colo, string $ip): array {
			return array('colo_name' => $colo, 'origin_ip' => $ip);
		};
		$client = array(
			'id'      => '1bedc50d-42b3-473c-b108-ff3d10c0d925',
			'version' => '2022.7.1',
			'arch'    => 'linux_amd64',
			'run_at'  => null,
			'conns'   => array(
				$conn('SJC', '10.0.0.2'),
				$conn('DFW', '10.0.0.1'),
				$conn('DFW', '10.0.0.2'),
				$conn('AMS', 'not-an-ip'),
			),
		);
		$this->transport->queue($this->page(array($client), null));

		$connectors = $this->client()->listConnections(self::ACCOUNT, $this->token, self::TUNNEL);

		$this->assertSame(4, $connectors[0]->connectionCount);
		$this->assertNull($connectors[0]->runAt);
		$this->assertSame(array('AMS', 'DFW', 'SJC'), $connectors[0]->colos);
		$this->assertSame(array('10.0.0.1', '10.0.0.2'), $connectors[0]->originIps);
	}

	public function testConnectionsBadClientIsMalformed(): void {
		$this->transport->queue($this->page(array(array('id' => 'nope', 'conns' => array())), null));

		$this->expectFailure(function (): void {
			$this->client()->listConnections(self::ACCOUNT, $this->token, self::TUNNEL);
		}, ErrorClass::MALFORMED);
	}

	public function testConnectionsRejectsInvalidTunnelId(): void {
		$this->expectFailure(function (): void {
			$this->client()->listConnections(self::ACCOUNT, $this->token, '../etc');
		}, ErrorClass::CONFIG);
		$this->assertCount(0, $this->transport->requests());
	}

	public function testTokenNeverInMessages(): void {
		$leaky = new Response(
			500,
			array(),
			(string) json_encode(array('errors' => array(array('code' => 9, 'message' => 'echo ' . $this->token))))
		);
		$cases = array(
			'transport' => function (): void {
				$this->transport->queue(new TransportException(TransportException::TRANSIENT, 'timeout', 'down ' . $this->token));
				$this->transport->queue(new TransportException(TransportException::TRANSIENT, 'timeout', 'down ' . $this->token));
				$this->transport->queue(new TransportException(TransportException::TRANSIENT, 'timeout', 'down ' . $this->token));
				$this->client()->listTunnels(self::ACCOUNT, $this->token);
			},
			'server error' => function () use ($leaky): void {
				$this->transport->queue($leaky);
				$this->transport->queue($leaky);
				$this->transport->queue($leaky);
				$this->client()->listTunnels(self::ACCOUNT, $this->token);
			},
			'bad item' => function (): void {
				$this->transport->queue(FakeTransport::fixture('tunnel-list-bad-item'));
				$this->client()->listTunnels(self::ACCOUNT, $this->token);
			},
			'no result_info' => function (): void {
				$this->transport->queue($this->page(array(), null));
				$this->client()->listTunnels(self::ACCOUNT, $this->token);
			},
			'connections 1001' => function (): void {
				$this->transport->queue(FakeTransport::fixture('connections-1001', 401));
				$this->client()->listConnections(self::ACCOUNT, $this->token, self::TUNNEL);
			},
			'connections leaky 403' => function (): void {
				$this->transport->queue(new Response(
					403,
					array(),
					(string) json_encode(array('errors' => array(array('code' => 1, 'message' => $this->token))))
				));
				$this->client()->listConnections(self::ACCOUNT, $this->token, self::TUNNEL);
			},
		);

		foreach ($cases as $name => $case) {
			try {
				$case();
				$this->fail('expected failure: ' . $name);
			} catch (ApiException $e) {
				$this->assertNotLeaked($this->token, $e->getMessage(), $name);
			}
		}
	}
}
