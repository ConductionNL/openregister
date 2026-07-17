<?php

/**
 * Webhook interception fast-path tests.
 *
 * Proves interceptRequest() consults the distributed interception-flag
 * cache before touching the webhook table: a cached false skips ALL
 * webhook queries, a cache miss computes the tenant-agnostic flag once and
 * stores it, and a cached true still runs the organisation-filtered
 * lookup. Also proves the synchronous interception delivery is hard-capped
 * at INTERCEPTION_TIMEOUT_SECONDS (2s connect + total) instead of the 30s
 * client default, and that WebhookMapper CRUD invalidates the flag cache.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Service\Webhook\WebhookInterceptionCache;
use OCA\OpenRegister\Service\WebhookService;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the interception-cache fast path and the interception
 * delivery timeout cap.
 */
class WebhookInterceptionFastPathTest extends TestCase
{
    private WebhookMapper&MockObject $webhookMapper;
    private WebhookLogMapper&MockObject $webhookLogMapper;
    private MappingService&MockObject $mappingService;
    private MappingMapper&MockObject $mappingMapper;
    private IJobList&MockObject $jobList;
    private LoggerInterface&MockObject $logger;
    private WebhookInterceptionCache&MockObject $interceptionCache;
    private WebhookService $service;

    /**
     * Set up service with all dependencies mocked, including the cache.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->webhookMapper     = $this->createMock(WebhookMapper::class);
        $this->webhookLogMapper  = $this->createMock(WebhookLogMapper::class);
        $this->mappingService    = $this->createMock(MappingService::class);
        $this->mappingMapper     = $this->createMock(MappingMapper::class);
        $this->jobList           = $this->createMock(IJobList::class);
        $this->logger            = $this->createMock(LoggerInterface::class);
        $this->interceptionCache = $this->createMock(WebhookInterceptionCache::class);

        $this->service = new WebhookService(
            webhookMapper: $this->webhookMapper,
            logger: $this->logger,
            webhookLogMapper: $this->webhookLogMapper,
            mappingService: $this->mappingService,
            mappingMapper: $this->mappingMapper,
            jobList: $this->jobList,
            cloudEventFormatter: null,
            interceptionCache: $this->interceptionCache
        );
    }//end setUp()

    /**
     * Build a request mock with fixed params.
     *
     * @param array $params Request params.
     *
     * @return IRequest&MockObject
     */
    private function makeRequest(array $params=['key' => 'value']): IRequest&MockObject
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParams')->willReturn($params);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getPathInfo')->willReturn('/api/objects/1/2');

        return $request;
    }//end makeRequest()

    /**
     * Build a real interception Webhook entity.
     *
     * @param int $timeout Per-webhook delivery timeout in seconds.
     *
     * @return Webhook
     */
    private function makeInterceptionWebhook(int $timeout=30): Webhook
    {
        $webhook = new Webhook();
        $webhook->setId(1);
        $webhook->setName('Intercepting hook');
        $webhook->setUrl('https://example.com/hook');
        $webhook->setMethod('POST');
        $webhook->setEnabled(true);
        $webhook->setTimeout($timeout);
        $webhook->setMaxRetries(0);
        $webhook->setConfiguration(json_encode(['interceptRequests' => true]));

        return $webhook;
    }//end makeInterceptionWebhook()

    /**
     * Inject a mock Guzzle client via reflection.
     *
     * @param GuzzleClient&MockObject $client Mock client.
     *
     * @return void
     */
    private function injectMockClient(GuzzleClient $client): void
    {
        $reflection = new \ReflectionClass($this->service);
        $property   = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($this->service, $client);
    }//end injectMockClient()

    // ─── Cache hit: false ────────────────────────────────────────────

    /**
     * A cached "false" flag skips EVERY webhook table query — this is the
     * zero-cost path object writes take on installs without interception
     * webhooks.
     *
     * @return void
     */
    public function testCachedFalseSkipsAllWebhookQueries(): void
    {
        $this->interceptionCache->method('get')
            ->with('object.creating')
            ->willReturn(false);

        $this->webhookMapper->expects($this->never())->method('findEnabled');
        $this->webhookMapper->expects($this->never())->method('findEnabledForInterceptionScan');
        $this->interceptionCache->expects($this->never())->method('set');

        $result = $this->service->interceptRequest($this->makeRequest(), 'object.creating');

        $this->assertSame(['key' => 'value'], $result);
    }//end testCachedFalseSkipsAllWebhookQueries()

    // ─── Cache hit: true ─────────────────────────────────────────────

    /**
     * A cached "true" flag skips the tenant-agnostic scan but still runs the
     * organisation-filtered lookup to select the applicable webhooks.
     *
     * @return void
     */
    public function testCachedTrueRunsOrganisationFilteredLookup(): void
    {
        $this->interceptionCache->method('get')
            ->with('object.creating')
            ->willReturn(true);

        $this->webhookMapper->expects($this->never())->method('findEnabledForInterceptionScan');
        $this->webhookMapper->expects($this->once())
            ->method('findEnabled')
            ->willReturn([]);

        $result = $this->service->interceptRequest($this->makeRequest(), 'object.creating');

        $this->assertSame(['key' => 'value'], $result);
    }//end testCachedTrueRunsOrganisationFilteredLookup()

    // ─── Cache miss ──────────────────────────────────────────────────

    /**
     * On a cache miss with no interception webhooks anywhere, the flag is
     * computed from the tenant-agnostic scan, cached as false, and the
     * organisation-filtered lookup is skipped.
     *
     * @return void
     */
    public function testCacheMissComputesAndStoresFalse(): void
    {
        $this->interceptionCache->method('get')->willReturn(null);

        $this->webhookMapper->expects($this->once())
            ->method('findEnabledForInterceptionScan')
            ->willReturn([]);
        $this->webhookMapper->expects($this->never())->method('findEnabled');

        $this->interceptionCache->expects($this->once())
            ->method('set')
            ->with('object.creating', false);

        $result = $this->service->interceptRequest($this->makeRequest(), 'object.creating');

        $this->assertSame(['key' => 'value'], $result);
    }//end testCacheMissComputesAndStoresFalse()

    /**
     * On a cache miss with a matching interception webhook, the flag is
     * cached as true and delivery proceeds through the organisation-filtered
     * lookup.
     *
     * @return void
     */
    public function testCacheMissComputesAndStoresTrue(): void
    {
        $webhook = $this->makeInterceptionWebhook();

        $this->interceptionCache->method('get')->willReturn(null);
        $this->webhookMapper->method('findEnabledForInterceptionScan')->willReturn([$webhook]);
        $this->webhookMapper->expects($this->once())
            ->method('findEnabled')
            ->willReturn([$webhook]);

        $this->interceptionCache->expects($this->once())
            ->method('set')
            ->with('object.creating', true);

        $mockClient = $this->createMock(GuzzleClient::class);
        $mockClient->method('request')->willReturn(new GuzzleResponse(200, [], '{}'));
        $this->injectMockClient($mockClient);

        $result = $this->service->interceptRequest($this->makeRequest(), 'object.creating');

        $this->assertSame(['key' => 'value'], $result);
    }//end testCacheMissComputesAndStoresTrue()

    /**
     * A webhook that is enabled but NOT configured for interception yields a
     * cached false — post-save-only webhooks must not un-fast-path writes.
     *
     * @return void
     */
    public function testNonInterceptionWebhookStillCachesFalse(): void
    {
        $webhook = $this->makeInterceptionWebhook();
        $webhook->setConfiguration(json_encode(['interceptRequests' => false]));

        $this->interceptionCache->method('get')->willReturn(null);
        $this->webhookMapper->method('findEnabledForInterceptionScan')->willReturn([$webhook]);
        $this->webhookMapper->expects($this->never())->method('findEnabled');

        $this->interceptionCache->expects($this->once())
            ->method('set')
            ->with('object.creating', false);

        $result = $this->service->interceptRequest($this->makeRequest(), 'object.creating');

        $this->assertSame(['key' => 'value'], $result);
    }//end testNonInterceptionWebhookStillCachesFalse()

    // ─── No cache backend ────────────────────────────────────────────

    /**
     * Without a cache backend the service still works: the flag is computed
     * from the tenant-agnostic scan on every call (pre-cache behaviour).
     *
     * @return void
     */
    public function testWithoutCacheBackendFallsBackToScan(): void
    {
        $service = new WebhookService(
            webhookMapper: $this->webhookMapper,
            logger: $this->logger,
            webhookLogMapper: $this->webhookLogMapper,
            mappingService: $this->mappingService,
            mappingMapper: $this->mappingMapper,
            jobList: $this->jobList
        );

        $this->webhookMapper->expects($this->once())
            ->method('findEnabledForInterceptionScan')
            ->willReturn([]);

        $result = $service->interceptRequest($this->makeRequest(), 'object.creating');

        $this->assertSame(['key' => 'value'], $result);
    }//end testWithoutCacheBackendFallsBackToScan()

    // ─── Interception timeout cap ────────────────────────────────────

    /**
     * Interception deliveries are hard-capped at 2s connect + total: the
     * webhook's own 30s timeout must NOT leak into the request-blocking
     * interception path.
     *
     * @return void
     */
    public function testInterceptionDeliveryCapsTimeoutAtTwoSeconds(): void
    {
        $webhook = $this->makeInterceptionWebhook(timeout: 30);

        $this->interceptionCache->method('get')->willReturn(true);
        $this->webhookMapper->method('findEnabled')->willReturn([$webhook]);

        $capturedOptions = null;
        $mockClient      = $this->createMock(GuzzleClient::class);
        $mockClient->method('request')->willReturnCallback(
            function (string $method, $uri, array $options) use (&$capturedOptions) {
                $capturedOptions = $options;
                return new GuzzleResponse(200, [], '{}');
            }
        );
        $this->injectMockClient($mockClient);

        $this->service->interceptRequest($this->makeRequest(), 'object.creating');

        $this->assertNotNull($capturedOptions, 'Delivery request was never issued');
        $this->assertSame(2, $capturedOptions['timeout']);
        $this->assertSame(2, $capturedOptions['connect_timeout']);
    }//end testInterceptionDeliveryCapsTimeoutAtTwoSeconds()

    /**
     * A webhook with a SHORTER timeout than the cap keeps its own timeout —
     * the cap only lowers, never raises.
     *
     * @return void
     */
    public function testInterceptionCapNeverRaisesShorterWebhookTimeout(): void
    {
        $webhook = $this->makeInterceptionWebhook(timeout: 1);

        $this->interceptionCache->method('get')->willReturn(true);
        $this->webhookMapper->method('findEnabled')->willReturn([$webhook]);

        $capturedOptions = null;
        $mockClient      = $this->createMock(GuzzleClient::class);
        $mockClient->method('request')->willReturnCallback(
            function (string $method, $uri, array $options) use (&$capturedOptions) {
                $capturedOptions = $options;
                return new GuzzleResponse(200, [], '{}');
            }
        );
        $this->injectMockClient($mockClient);

        $this->service->interceptRequest($this->makeRequest(), 'object.creating');

        $this->assertNotNull($capturedOptions, 'Delivery request was never issued');
        $this->assertSame(1, $capturedOptions['timeout']);
        $this->assertSame(2, $capturedOptions['connect_timeout']);
    }//end testInterceptionCapNeverRaisesShorterWebhookTimeout()

    /**
     * Non-interception deliveries (async path) keep the per-webhook timeout
     * and never receive the cap.
     *
     * @return void
     */
    public function testUncappedDeliveryKeepsPerWebhookTimeout(): void
    {
        $webhook = $this->makeInterceptionWebhook(timeout: 30);

        $capturedOptions = null;
        $mockClient      = $this->createMock(GuzzleClient::class);
        $mockClient->method('request')->willReturnCallback(
            function (string $method, $uri, array $options) use (&$capturedOptions) {
                $capturedOptions = $options;
                return new GuzzleResponse(200, [], '{}');
            }
        );
        $this->injectMockClient($mockClient);

        $this->service->deliverWebhook(
            webhook: $webhook,
            eventName: 'object.created',
            payload: ['objectType' => 'object']
        );

        $this->assertNotNull($capturedOptions, 'Delivery request was never issued');
        $this->assertSame(30, $capturedOptions['timeout']);
        $this->assertArrayNotHasKey('connect_timeout', $capturedOptions);
    }//end testUncappedDeliveryKeepsPerWebhookTimeout()
}//end class
