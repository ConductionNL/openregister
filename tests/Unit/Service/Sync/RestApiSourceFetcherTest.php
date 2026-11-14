<?php

declare(strict_types=1);

namespace Unit\Service\Sync;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Service\Sync\RestApiSourceFetcher;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies the harden-harvest-http timeout hardening: both the Gather
 * (collection) request and the Fetch (per-id) request must bound the
 * underlying Guzzle call with connect/read timeouts, so a hung upstream
 * cannot stall the harvest job indefinitely.
 *
 * The HTTP transport itself is mocked (GuzzleHttp\Client is not final),
 * so this only asserts the request() options this class builds — it does
 * not exercise real network I/O.
 */
class RestApiSourceFetcherTest extends TestCase {
	private Client&MockObject $httpClient;
	private ICrypto&MockObject $crypto;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->httpClient = $this->createMock(Client::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function makeFetcher(): RestApiSourceFetcher {
		return new RestApiSourceFetcher($this->httpClient, $this->crypto, $this->logger);
	}

	private function makeSource(): Source&MockObject {
		// Source's getters are magic (via Entity::__call), so createMock()
		// can't auto-detect them as configurable methods — addMethods()
		// registers them explicitly on the mock's generated class. Every name
		// here must be backed by a real property: getConfiguration() used to
		// be in this list and Source has no `configuration` property, so the
		// double invented an accessor that threw in production.
		$source = $this->getMockBuilder(Source::class)
			->addMethods(['getDatabaseUrl', 'getAuthType', 'getAuthConfig'])
			->getMock();
		$source->method('getDatabaseUrl')->willReturn('https://example.test/api/records');
		$source->method('getAuthType')->willReturn('none');
		$source->method('getAuthConfig')->willReturn([]);

		return $source;
	}

	public function testGatherPassesConnectAndReadTimeoutOptions(): void {
		$source = $this->makeSource();

		$this->httpClient->expects($this->once())
			->method('request')
			->with(
				'GET',
				'https://example.test/api/records',
				$this->callback(function (array $options) {
					return ($options['timeout'] ?? null) === 30
						&& ($options['connect_timeout'] ?? null) === 5;
				})
			)
			->willReturn(new Response(200, [], json_encode(['results' => []])));

		$fetcher = $this->makeFetcher();
		$ids = $fetcher->gather(source: $source, since: null);

		$this->assertSame([], $ids);
	}

	public function testFetchPassesConnectAndReadTimeoutOptions(): void {
		$source = $this->makeSource();

		$this->httpClient->expects($this->once())
			->method('request')
			->with(
				'GET',
				'https://example.test/api/records/rec-1',
				$this->callback(function (array $options) {
					return ($options['timeout'] ?? null) === 30
						&& ($options['connect_timeout'] ?? null) === 5;
				})
			)
			->willReturn(new Response(200, [], json_encode(['id' => 'rec-1', 'name' => 'Example'])));

		$fetcher = $this->makeFetcher();
		$body = $fetcher->fetch(source: $source, externalId: 'rec-1');

		$this->assertSame(['id' => 'rec-1', 'name' => 'Example'], $body);
	}

	public function testGatherWithoutTimeoutOptionsWouldFailThisAssertion(): void {
		// Regression guard: if a future refactor drops the timeout options
		// (e.g. reverts to the pre-hardening call), this test documents the
		// exact shape being enforced so the drop is caught immediately.
		$source = $this->makeSource();

		$capturedOptions = null;
		$this->httpClient->method('request')
			->willReturnCallback(function (string $method, string $url, array $options) use (&$capturedOptions) {
				$capturedOptions = $options;
				return new Response(200, [], json_encode(['results' => []]));
			});

		$fetcher = $this->makeFetcher();
		$fetcher->gather(source: $source, since: null);

		$this->assertArrayHasKey('timeout', $capturedOptions);
		$this->assertArrayHasKey('connect_timeout', $capturedOptions);
		$this->assertSame(30, $capturedOptions['timeout']);
		$this->assertSame(5, $capturedOptions['connect_timeout']);
	}

	public function testItDeclaresTheRestApiType(): void {
		$fetcher = $this->makeFetcher();

		$this->assertSame('rest-api', $fetcher->getType());
		$this->assertSame('REST API', $fetcher->getDisplayName());
		$this->assertSame('object', $fetcher->getConfigSchema()['type']);
		$this->assertTrue($fetcher->supports('openregister'));
	}

	/**
	 * The fetcher on the guarded harvest client, over a mocked transport and DNS.
	 */
	private function guardedFetcher(\GuzzleHttp\Handler\MockHandler $handler): RestApiSourceFetcher {
		$addresses = $this->getMockBuilder(\OCA\OpenRegister\Service\Security\OutboundAddressGuard::class)
			->onlyMethods(['addressesOf'])
			->getMock();
		$addresses->method('addressesOf')->willReturnCallback(
			static fn (string $host): array => (filter_var($host, FILTER_VALIDATE_IP) !== false) ? [$host] : ['93.184.216.34']
		);
		$http = new \OCA\OpenRegister\Service\Sync\HarvestHttpClient(
			new Client(['handler' => \GuzzleHttp\HandlerStack::create($handler)]),
			new \OCA\OpenRegister\Service\Http\OutboundUrlGuard($addresses),
			static function (int $seconds): void {
			}
		);

		// The bare client must not be used once the guarded one is there.
		$this->httpClient->expects($this->never())->method('request');

		return new RestApiSourceFetcher($this->httpClient, $this->crypto, $this->logger, $http);
	}

	public function testGatherGoesThroughTheGuardedClient(): void {
		$handler = new \GuzzleHttp\Handler\MockHandler([new Response(200, [], json_encode(['results' => [['id' => 'r1'], ['id' => 'r2']]]))]);

		$ids = $this->guardedFetcher($handler)->gather(source: $this->makeSource(), since: null);

		$this->assertSame(['r1', 'r2'], $ids);
	}

	public function testAnErrorStatusFromTheGuardedClientFailsTheFetch(): void {
		$handler = new \GuzzleHttp\Handler\MockHandler([new Response(404, [], json_encode(['error' => 'gone']))]);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('HTTP 404');
		$this->guardedFetcher($handler)->fetch(source: $this->makeSource(), externalId: 'r1');
	}

	public function testAnEndpointOnAPrivateAddressIsRefused(): void {
		$handler = new \GuzzleHttp\Handler\MockHandler([new Response(200, [], '[]')]);
		$source = $this->getMockBuilder(Source::class)->addMethods(['getDatabaseUrl', 'getAuthType', 'getAuthConfig'])->getMock();
		$source->method('getDatabaseUrl')->willReturn('http://169.254.169.254/latest/meta-data');
		$source->method('getAuthType')->willReturn('none');
		$source->method('getAuthConfig')->willReturn([]);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('link-local/metadata');
		$this->guardedFetcher($handler)->gather(source: $source, since: null);
	}
}//end class
