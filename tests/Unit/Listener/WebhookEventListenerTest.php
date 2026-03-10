<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Listener\WebhookEventListener;
use OCA\OpenRegister\Service\WebhookService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WebhookEventListenerTest extends TestCase
{
    private WebhookEventListener $listener;
    private WebhookService&MockObject $webhookService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->webhookService = $this->createMock(WebhookService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->listener = new WebhookEventListener(
            $this->webhookService,
            $this->logger,
        );
    }

    public function testUnknownEventLogsWarningAndReturns(): void
    {
        $event = $this->createMock(Event::class);

        $this->logger->expects($this->atLeastOnce())->method('warning');
        $this->webhookService->expects($this->never())->method('dispatchEvent');

        $this->listener->handle($event);
    }

    public function testObjectCreatedEventDispatchesWebhook(): void
    {
        $object = new ObjectEntity();
        $event = new ObjectCreatedEvent($object);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent');

        $this->listener->handle($event);
    }

    public function testObjectDeletedEventDispatchesWebhook(): void
    {
        $object = new ObjectEntity();
        $event = new ObjectDeletedEvent($object);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent');

        $this->listener->handle($event);
    }

    public function testObjectLockedEventDispatchesWebhook(): void
    {
        $object = new ObjectEntity();
        $event = new ObjectLockedEvent($object);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent');

        $this->listener->handle($event);
    }

    public function testObjectUnlockedEventDispatchesWebhook(): void
    {
        $object = new ObjectEntity();
        $event = new ObjectUnlockedEvent($object);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent');

        $this->listener->handle($event);
    }

    public function testObjectRevertedEventDispatchesWebhook(): void
    {
        $object = new ObjectEntity();
        $event = new ObjectRevertedEvent($object);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent');

        $this->listener->handle($event);
    }

    public function testRegisterCreatedEventDispatchesWebhook(): void
    {
        $register = new Register();
        $event = new RegisterCreatedEvent($register);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent');

        $this->listener->handle($event);
    }

    public function testSchemaDeletedEventDispatchesWebhook(): void
    {
        $schema = new Schema();
        $event = new SchemaDeletedEvent($schema);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent');

        $this->listener->handle($event);
    }
}
