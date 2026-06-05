<?php

/**
 * Tests for ExternalIntegrationRouter.
 *
 * @spec openspec/changes/integration-openproject/tasks.md#task-6
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\RequestScopedCache;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ExternalIntegrationRouter dispatch and auth-status logic.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author   Conduction Development Team <info@conduction.nl>
 */
class ExternalIntegrationRouterTest extends TestCase
{

    private Client&MockObject $httpClient;
    private RequestScopedCache&MockObject $cache;
    private IConfig&MockObject $config;
    private LoggerInterface&MockObject $logger;
    private ExternalIntegrationRouter $router;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(Client::class);
        $this->cache      = $this->createMock(RequestScopedCache::class);
        $this->config     = $this->createMock(IConfig::class);
        $this->logger     = $this->createMock(LoggerInterface::class);

        $this->config->method('getSystemValue')
            ->willReturn('http://localhost:8080');

        $this->router = new ExternalIntegrationRouter(
            httpClient: $this->httpClient,
            cache: $this->cache,
            config: $this->config,
            logger: $this->logger,
        );
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-3
     */
    public function testListItemsReturnsCachedResult(): void
    {
        $cached = ['items' => [['id' => 1]], 'total' => 1, 'authStatus' => 'configured'];

        $this->cache->method('has')
            ->with($this->anything(), $this->anything())
            ->willReturn(true);

        $this->cache->method('get')
            ->with($this->anything(), $this->anything())
            ->willReturn($cached);

        // HTTP client must NOT be called.
        $this->httpClient->expects($this->never())->method('post');

        $result = $this->router->listItems(sourceId: 'openproject', objectId: 'obj-123');

        $this->assertSame(expected: $cached, actual: $result);
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-3
     */
    public function testListItemsCallsOpenConnectorOnCacheMiss(): void
    {
        $this->cache->method('has')->willReturn(false);
        $this->cache->method('get')->willReturn(null);

        $responseBody = json_encode(value: [
            'items'      => [['id' => 42, 'subject' => 'Test WP']],
            'total'      => 1,
            'authStatus' => 'configured',
        ]);

        $response = new Response(status: 200, body: $responseBody);
        $this->httpClient->method('post')->willReturn($response);
        $this->cache->expects($this->once())->method('set');

        $result = $this->router->listItems(sourceId: 'openproject', objectId: 'obj-123');

        $this->assertSame(expected: 1, actual: $result['total']);
        $this->assertSame(expected: 'configured', actual: $result['authStatus']);
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-3
     */
    public function testListItemsReturnsExpiredOnHttp401(): void
    {
        $this->cache->method('has')->willReturn(false);

        $request  = new Request(method: 'POST', uri: 'http://test');
        $response = new Response(status: 401);
        $exception = new ClientException(message: 'Unauthorized', request: $request, response: $response);

        $this->httpClient->method('post')->willThrowException($exception);
        $this->logger->expects($this->once())->method('warning');

        $result = $this->router->listItems(sourceId: 'openproject', objectId: 'obj-123');

        $this->assertSame(expected: 'expired', actual: $result['authStatus']);
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-3
     */
    public function testListItemsReturnsDegradedOnHttp429(): void
    {
        $this->cache->method('has')->willReturn(false);

        $request  = new Request(method: 'POST', uri: 'http://test');
        $response = new Response(status: 429);
        $exception = new ClientException(message: 'Rate limited', request: $request, response: $response);

        $this->httpClient->method('post')->willThrowException($exception);
        $this->logger->expects($this->once())->method('warning');

        $result = $this->router->listItems(sourceId: 'openproject', objectId: 'obj-123');

        $this->assertSame(expected: 'degraded', actual: $result['authStatus']);
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-3
     */
    public function testCheckAuthStatusReturnsMissingOnHttp404(): void
    {
        $this->cache->method('has')->willReturn(false);

        $request  = new Request(method: 'GET', uri: 'http://test');
        $response = new Response(status: 404);
        $exception = new ClientException(message: 'Not Found', request: $request, response: $response);

        $this->httpClient->method('get')->willThrowException($exception);

        $status = $this->router->checkAuthStatus(sourceId: 'openproject');

        $this->assertSame(expected: 'missing', actual: $status);
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-3
     */
    public function testCheckAuthStatusReturnsConfiguredOnSuccess(): void
    {
        $this->cache->method('has')->willReturn(false);

        $responseBody = json_encode(value: ['status' => 'configured']);
        $response     = new Response(status: 200, body: $responseBody);

        $this->httpClient->method('get')->willReturn($response);
        $this->cache->expects($this->once())->method('set');

        $status = $this->router->checkAuthStatus(sourceId: 'openproject');

        $this->assertSame(expected: 'configured', actual: $status);
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-3
     */
    public function testLinkItemCallsOpenConnector(): void
    {
        $responseBody = json_encode(value: [
            'item'       => ['id' => 42],
            'authStatus' => 'configured',
        ]);
        $response = new Response(status: 200, body: $responseBody);

        $this->httpClient->expects($this->once())->method('post')->willReturn($response);

        $result = $this->router->linkItem(
            sourceId: 'openproject',
            objectId: 'obj-123',
            data: ['workPackageId' => 42]
        );

        $this->assertSame(expected: 'configured', actual: $result['authStatus']);
    }

    /**
     * @spec openspec/changes/integration-openproject/tasks.md#task-3
     */
    public function testUnlinkItemCallsOpenConnector(): void
    {
        $responseBody = json_encode(value: ['authStatus' => 'configured']);
        $response     = new Response(status: 200, body: $responseBody);

        $this->httpClient->expects($this->once())->method('post')->willReturn($response);

        $result = $this->router->unlinkItem(
            sourceId: 'openproject',
            objectId: 'obj-123',
            itemId: '42'
        );

        $this->assertSame(expected: 'configured', actual: $result['authStatus']);
    }

}//end class
