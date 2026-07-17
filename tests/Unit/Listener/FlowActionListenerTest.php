<?php

/**
 * Unit tests for FlowActionListener — routes object-lifecycle events to the
 * flow runner with the matching trigger.
 *
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\FlowActionListener;
use OCA\OpenRegister\Service\Flow\FlowActionService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

class FlowActionListenerTest extends TestCase
{
    private FlowActionListener $listener;
    private $flowService;

    protected function setUp(): void
    {
        $this->flowService = $this->createMock(FlowActionService::class);
        $this->listener    = new FlowActionListener($this->flowService);
    }

    public function testCreatedEventRunsCreatedTrigger(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $event  = $this->createMock(ObjectCreatedEvent::class);
        $event->method('getObject')->willReturn($object);

        $this->flowService->expects($this->once())
            ->method('run')
            ->with($object, 'created');

        $this->listener->handle($event);
    }

    public function testUpdatedEventRunsUpdatedTriggerWithNewObject(): void
    {
        $newObject = $this->createMock(ObjectEntity::class);
        $event     = $this->createMock(ObjectUpdatedEvent::class);
        $event->method('getNewObject')->willReturn($newObject);

        $this->flowService->expects($this->once())
            ->method('run')
            ->with($newObject, 'updated');

        $this->listener->handle($event);
    }

    public function testDeletedEventRunsDeletedTrigger(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $event  = $this->createMock(ObjectDeletedEvent::class);
        $event->method('getObject')->willReturn($object);

        $this->flowService->expects($this->once())
            ->method('run')
            ->with($object, 'deleted');

        $this->listener->handle($event);
    }

    public function testUnrelatedEventIsIgnored(): void
    {
        $this->flowService->expects($this->never())->method('run');
        $this->listener->handle($this->createMock(Event::class));
    }
}
