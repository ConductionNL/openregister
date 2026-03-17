<?php

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\WebhookDeliveryJob;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\WebhookService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WebhookDeliveryJobTest extends TestCase
{
    private WebhookDeliveryJob $job;
    private WebhookMapper&MockObject $webhookMapper;
    private WebhookService&MockObject $webhookService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $timeFactory = $this->createMock(ITimeFactory::class);
        $this->webhookMapper = $this->createMock(WebhookMapper::class);
        $this->webhookService = $this->createMock(WebhookService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->job = new WebhookDeliveryJob(
            $timeFactory,
            $this->webhookMapper,
            $this->webhookService,
            $this->logger,
        );
    }

    private function runJob(array $argument): void
    {
        $reflection = new \ReflectionClass($this->job);
        $method = $reflection->getMethod('run');
        $method->setAccessible(true);
        $method->invoke($this->job, $argument);
    }

    public function testMissingWebhookIdLogsError(): void
    {
        $this->logger->expects($this->atLeastOnce())->method('error');
        $this->webhookService->expects($this->never())->method('deliverWebhook');

        $this->runJob(['event_name' => 'TestEvent']);
    }

    public function testMissingEventNameLogsError(): void
    {
        $this->logger->expects($this->atLeastOnce())->method('error');
        $this->webhookService->expects($this->never())->method('deliverWebhook');

        $this->runJob(['webhook_id' => 1]);
    }

    public function testSuccessfulDelivery(): void
    {
        $webhook = new Webhook();
        $webhook->setName('Test Webhook');

        $this->webhookMapper->method('find')->with(1)->willReturn($webhook);

        $this->webhookService->expects($this->once())
            ->method('deliverWebhook')
            ->with($webhook, 'TestEvent', ['key' => 'val'], 1)
            ->willReturn(true);

        $this->runJob([
            'webhook_id' => 1,
            'event_name' => 'TestEvent',
            'payload' => ['key' => 'val'],
        ]);
    }

    public function testFailedDeliveryLogsWarning(): void
    {
        $webhook = new Webhook();
        $webhook->setName('Test Webhook');

        $this->webhookMapper->method('find')->willReturn($webhook);

        $this->webhookService->method('deliverWebhook')->willReturn(false);

        $this->logger->expects($this->atLeastOnce())->method('warning');

        $this->runJob([
            'webhook_id' => 1,
            'event_name' => 'TestEvent',
        ]);
    }

    public function testMapperExceptionLogsError(): void
    {
        $this->webhookMapper->method('find')
            ->willThrowException(new \Exception('Webhook not found'));

        $this->logger->expects($this->atLeastOnce())->method('error');

        $this->runJob([
            'webhook_id' => 999,
            'event_name' => 'TestEvent',
        ]);
    }

    public function testDefaultAttemptIsOne(): void
    {
        $webhook = new Webhook();
        $webhook->setName('Test');

        $this->webhookMapper->method('find')->willReturn($webhook);

        $this->webhookService->expects($this->once())
            ->method('deliverWebhook')
            ->with($webhook, 'TestEvent', [], 1)
            ->willReturn(true);

        $this->runJob([
            'webhook_id' => 1,
            'event_name' => 'TestEvent',
        ]);
    }

    public function testCustomAttemptNumber(): void
    {
        $webhook = new Webhook();
        $webhook->setName('Test');

        $this->webhookMapper->method('find')->willReturn($webhook);

        $this->webhookService->expects($this->once())
            ->method('deliverWebhook')
            ->with($webhook, 'TestEvent', [], 3)
            ->willReturn(true);

        $this->runJob([
            'webhook_id' => 1,
            'event_name' => 'TestEvent',
            'attempt' => 3,
        ]);
    }
}
