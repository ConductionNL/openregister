<?php

/**
 * AggregationThresholdListenerTest
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/notificatie-engine/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\AggregationThresholdListener;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for AggregationThresholdListener.
 */
class AggregationThresholdListenerTest extends TestCase
{

    private AnnotationNotificationDispatcher&MockObject $dispatcher;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->dispatcher = $this->createMock(AnnotationNotificationDispatcher::class);
        $this->logger     = $this->createMock(LoggerInterface::class);
    }

    private function makeListener(): AggregationThresholdListener
    {
        return new AggregationThresholdListener(
            dispatcher: $this->dispatcher,
            logger: $this->logger
        );
    }

    /**
     * ObjectCreatedEvent triggers dispatchThreshold.
     */
    public function testObjectCreatedEventTriggers(): void
    {
        $event    = $this->createMock(ObjectCreatedEvent::class);
        $this->dispatcher->expects(self::once())->method('dispatchThreshold')->with(event: $event);

        $this->makeListener()->handle(event: $event);
    }

    /**
     * ObjectUpdatedEvent triggers dispatchThreshold.
     */
    public function testObjectUpdatedEventTriggers(): void
    {
        $event    = $this->createMock(ObjectUpdatedEvent::class);
        $this->dispatcher->expects(self::once())->method('dispatchThreshold')->with(event: $event);

        $this->makeListener()->handle(event: $event);
    }

    /**
     * Unrelated event is ignored.
     */
    public function testUnrelatedEventIsIgnored(): void
    {
        $event = new Event();
        $this->dispatcher->expects(self::never())->method('dispatchThreshold');

        $this->makeListener()->handle(event: $event);
    }

    /**
     * Dispatch exception is caught and logged as warning.
     */
    public function testDispatchExceptionIsCaughtAndLogged(): void
    {
        $event = $this->createMock(ObjectCreatedEvent::class);
        $this->dispatcher->method('dispatchThreshold')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects(self::once())->method('warning');

        // Should not throw.
        $this->makeListener()->handle(event: $event);
    }

    /**
     * No uncaught exceptions escape the listener.
     */
    public function testNoUncaughtExceptions(): void
    {
        $event = $this->createMock(ObjectCreatedEvent::class);
        $this->dispatcher->method('dispatchThreshold')->willThrowException(new \RuntimeException('boom'));

        $this->expectNotToPerformAssertions();
        $this->makeListener()->handle(event: $event);
    }
}
