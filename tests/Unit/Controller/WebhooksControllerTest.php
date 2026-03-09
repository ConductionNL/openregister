<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\WebhooksController;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\WebhookService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WebhooksControllerTest extends TestCase
{
    private WebhooksController $controller;
    private IRequest&MockObject $request;
    private WebhookMapper&MockObject $webhookMapper;
    private WebhookLogMapper&MockObject $webhookLogMapper;
    private WebhookService&MockObject $webhookService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->webhookMapper = $this->createMock(WebhookMapper::class);
        $this->webhookLogMapper = $this->createMock(WebhookLogMapper::class);
        $this->webhookService = $this->createMock(WebhookService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new WebhooksController(
            'openregister',
            $this->request,
            $this->webhookMapper,
            $this->webhookLogMapper,
            $this->webhookService,
            $this->logger
        );
    }

    private function createWebhookEntity(): Webhook
    {
        $webhook = new Webhook();
        $ref = new \ReflectionClass($webhook);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($webhook, 1);
        $webhook->setName('Test Webhook');
        $webhook->setUrl('https://example.com/hook');
        return $webhook;
    }

    public function testIndexSuccess(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $webhook = $this->createWebhookEntity();

        $this->webhookMapper->method('findAll')->willReturn([$webhook]);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total', $data);
    }

    public function testIndexException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->webhookMapper->method('findAll')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->index();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testShowSuccess(): void
    {
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->with(1)->willReturn($webhook);

        $result = $this->controller->show(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testShowNotFound(): void
    {
        $this->webhookMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->show(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testCreateSuccess(): void
    {
        $this->request->method('getParams')->willReturn([
            'name' => 'New Hook',
            'url' => 'https://example.com/hook',
        ]);
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('createFromArray')->willReturn($webhook);

        $result = $this->controller->create();

        $this->assertEquals(201, $result->getStatus());
    }

    public function testCreateMissingRequired(): void
    {
        $this->request->method('getParams')->willReturn([
            'name' => 'New Hook',
        ]);

        $result = $this->controller->create();

        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals('Name and URL are required', $result->getData()['error']);
    }

    public function testUpdateSuccess(): void
    {
        $this->request->method('getParams')->willReturn(['name' => 'Updated']);
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('updateFromArray')->willReturn($webhook);

        $result = $this->controller->update(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateNotFound(): void
    {
        $this->request->method('getParams')->willReturn(['name' => 'Updated']);
        $this->webhookMapper->method('updateFromArray')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->update(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testDestroySuccess(): void
    {
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);

        $result = $this->controller->destroy(1);

        $this->assertEquals(204, $result->getStatus());
    }

    public function testDestroyNotFound(): void
    {
        $this->webhookMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->destroy(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testTestWebhookSuccess(): void
    {
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);
        $this->webhookService->method('deliverWebhook')->willReturn(true);

        $result = $this->controller->test(1);

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
    }

    public function testTestWebhookNotFound(): void
    {
        $this->webhookMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->test(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testEventsReturnsArray(): void
    {
        $result = $this->controller->events();

        $this->assertEquals(200, $result->getStatus());
        $this->assertIsArray($result->getData());
        $this->assertNotEmpty($result->getData());
    }

    public function testLogsReturnsWebhookLogs(): void
    {
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);
        $this->request->method('getParam')->willReturn(null);
        $this->webhookLogMapper->method('findByWebhook')->willReturn([]);

        $result = $this->controller->logs(1);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertSame(0, $data['total']);
    }

    public function testLogsReturns404WhenWebhookNotFound(): void
    {
        $this->webhookMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->logs(999);

        $this->assertEquals(404, $result->getStatus());
        $this->assertSame('Webhook not found', $result->getData()['error']);
    }

    public function testLogsReturns500OnGenericException(): void
    {
        $this->webhookMapper->method('find')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->logs(1);

        $this->assertEquals(500, $result->getStatus());
    }

    public function testLogStatsReturnsStatistics(): void
    {
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);
        $this->webhookLogMapper->method('getStatistics')->willReturn([
            'total' => 50,
            'successful' => 45,
            'failed' => 5,
        ]);
        $this->webhookLogMapper->method('findFailedForRetry')->willReturn([]);

        $result = $this->controller->logStats(1);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(50, $data['total']);
        $this->assertSame(0, $data['pendingRetries']);
    }

    public function testLogStatsReturns404WhenWebhookNotFound(): void
    {
        $this->webhookMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->logStats(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testLogStatsReturns500OnGenericException(): void
    {
        $this->webhookMapper->method('find')
            ->willThrowException(new \Exception('Stats error'));

        $result = $this->controller->logStats(1);

        $this->assertEquals(500, $result->getStatus());
    }

    public function testAllLogsReturnsLogs(): void
    {
        $this->request->method('getParam')->willReturn(null);
        $this->webhookLogMapper->method('findAll')->willReturn([]);

        $result = $this->controller->allLogs();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total', $data);
    }

    public function testAllLogsReturns500OnException(): void
    {
        $this->request->method('getParam')->willReturn(null);
        $this->webhookLogMapper->method('findAll')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->allLogs();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testRetryReturns404WhenLogNotFound(): void
    {
        $this->webhookLogMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->retry(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testCreateRemovesInternalParams(): void
    {
        $webhook = $this->createWebhookEntity();

        $this->request->method('getParams')->willReturn([
            '_route' => 'test',
            'id' => 5,
            'name' => 'New Hook',
            'url' => 'https://example.com/hook',
        ]);
        $this->webhookMapper->expects($this->once())
            ->method('createFromArray')
            ->with($this->callback(function ($data) {
                return !isset($data['_route']) && !isset($data['id'])
                    && isset($data['name']) && isset($data['url']);
            }))
            ->willReturn($webhook);

        $result = $this->controller->create();
        $this->assertEquals(201, $result->getStatus());
    }

    public function testCreateReturns500OnException(): void
    {
        $this->request->method('getParams')->willReturn([
            'name' => 'Test',
            'url' => 'https://example.com',
        ]);
        $this->webhookMapper->method('createFromArray')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->create();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testUpdateReturns500OnException(): void
    {
        $this->request->method('getParams')->willReturn(['name' => 'Updated']);
        $this->webhookMapper->method('updateFromArray')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->update(1);

        $this->assertEquals(500, $result->getStatus());
    }

    public function testDestroyReturns500OnException(): void
    {
        $this->webhookMapper->method('find')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->destroy(1);

        $this->assertEquals(500, $result->getStatus());
    }

    public function testTestWebhookReturns500OnException(): void
    {
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);
        $this->webhookService->method('deliverWebhook')
            ->willThrowException(new \Exception('Delivery failed'));

        $result = $this->controller->test(1);

        $this->assertEquals(500, $result->getStatus());
    }

    public function testShowReturns500OnException(): void
    {
        $this->webhookMapper->method('find')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->show(1);

        $this->assertEquals(500, $result->getStatus());
    }
}
