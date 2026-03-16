<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\UnifiedObjectMapper;
use OCA\OpenRegister\Listener\CommentsEntityListener;
use OCP\Comments\CommentsEntityEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CommentsEntityListenerTest extends TestCase
{
    private CommentsEntityListener $listener;
    private UnifiedObjectMapper&MockObject $objectMapper;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->objectMapper = $this->createMock(UnifiedObjectMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->listener = new CommentsEntityListener(
            $this->objectMapper,
            $this->logger,
        );
    }

    public function testEarlyReturnForNonCommentsEntityEvent(): void
    {
        $event = $this->createMock(Event::class);
        $this->listener->handle($event);
        // No exception = pass
        $this->assertTrue(true);
    }

    public function testRegistersOpenregisterEntityCollection(): void
    {
        $event = $this->createMock(CommentsEntityEvent::class);

        $event->expects($this->once())
            ->method('addEntityCollection')
            ->with(
                'openregister',
                $this->isInstanceOf(\Closure::class)
            );

        $this->listener->handle($event);
    }
}
