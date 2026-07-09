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
class RestApiSourceFetcherTest extends TestCase
{
    private Client&MockObject $httpClient;
    private ICrypto&MockObject $crypto;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->httpClient = $this->createMock(Client::class);
        $this->crypto      = $this->createMock(ICrypto::class);
        $this->logger       = $this->createMock(LoggerInterface::class);
    }

    private function makeFetcher(): RestApiSourceFetcher
    {
        return new RestApiSourceFetcher($this->httpClient, $this->crypto, $this->logger);
    }

    private function makeSource(): Source&MockObject
    {
        // Source's real setConfiguration()/getConfiguration() throw
        // BadFunctionCallException — the entity has no `configuration`
        // property/type registered despite the @method docblock (a
        // pre-existing bug unrelated to this task). A mock sidesteps the
        // magic __call() entirely so the fetcher's own logic can be
        // exercised in isolation.
        // Source's getters are magic (via Entity::__call), so createMock()
        // can't auto-detect them as configurable methods — addMethods()
        // registers them explicitly on the mock's generated class.
        $source = $this->getMockBuilder(Source::class)
            ->addMethods(['getDatabaseUrl', 'getAuthType', 'getAuthConfig', 'getConfiguration'])
            ->getMock();
        $source->method('getDatabaseUrl')->willReturn('https://example.test/api/records');
        $source->method('getAuthType')->willReturn('none');
        $source->method('getAuthConfig')->willReturn([]);
        $source->method('getConfiguration')->willReturn([]);

        return $source;
    }

    public function testGatherPassesConnectAndReadTimeoutOptions(): void
    {
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
        $ids     = $fetcher->gather(source: $source, since: null);

        $this->assertSame([], $ids);
    }

    public function testFetchPassesConnectAndReadTimeoutOptions(): void
    {
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
        $body    = $fetcher->fetch(source: $source, externalId: 'rec-1');

        $this->assertSame(['id' => 'rec-1', 'name' => 'Example'], $body);
    }

    public function testGatherWithoutTimeoutOptionsWouldFailThisAssertion(): void
    {
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
}//end class
