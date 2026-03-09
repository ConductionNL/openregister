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
}
