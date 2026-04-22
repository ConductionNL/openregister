<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\GraphQLSubscriptionListener;
use OCA\OpenRegister\Service\GraphQL\SubscriptionService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GraphQLSubscriptionListenerTest extends TestCase
{
    private GraphQLSubscriptionListener $listener;
    private SubscriptionService&MockObject $subscriptionService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subscriptionService = $this->createMock(SubscriptionService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->listener = new GraphQLSubscriptionListener(
            $this->subscriptionService,
            $this->logger,
        );
    }

    public function testHandleObjectCreatedEvent(): void
    {
        $object = new ObjectEntity();
        $event = new ObjectCreatedEvent($object);

        $this->subscriptionService->expects($this->once())
            ->method('pushEvent')
            ->with('create', $object);

        $this->listener->handle($event);
    }

    public function testHandleObjectUpdatedEvent(): void
    {
        $object = new ObjectEntity();
        $event = new ObjectUpdatedEvent($object);

        $this->subscriptionService->expects($this->once())
            ->method('pushEvent')
            ->with('update', $object);

        $this->listener->handle($event);
    }

    public function testHandleObjectDeletedEvent(): void
    {
        $object = new ObjectEntity();
        $event = new ObjectDeletedEvent($object);

        $this->subscriptionService->expects($this->once())
            ->method('pushEvent')
            ->with('delete', $object);

        $this->listener->handle($event);
    }

    public function testHandleNonObjectEventIgnored(): void
    {
        $event = $this->createMock(Event::class);

        $this->subscriptionService->expects($this->never())
            ->method('pushEvent');

        $this->listener->handle($event);
    }

    public function testHandleExceptionLogsWarning(): void
    {
        $object = new ObjectEntity();
        $event = new ObjectCreatedEvent($object);

        $this->subscriptionService->expects($this->once())
            ->method('pushEvent')
            ->willThrowException(new \Exception('Push failed'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('subscription event push failed'));

        $this->listener->handle($event);
    }
}
