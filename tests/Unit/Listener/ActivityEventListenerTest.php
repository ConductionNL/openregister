<?php

/**
 * ActivityEventListener Unit Test
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Listener\ActivityEventListener;
use OCA\OpenRegister\Service\ActivityService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ActivityEventListener.
 */
class ActivityEventListenerTest extends TestCase
{
    /** @var ActivityService&MockObject */
    private ActivityService $activityService;

    private ActivityEventListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activityService = $this->createMock(ActivityService::class);
        $this->listener        = new ActivityEventListener($this->activityService);
    }

    /**
     * Test: ObjectCreatedEvent dispatches to publishObjectCreated.
     */
    public function testHandleObjectCreatedEvent(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $event  = $this->createMock(ObjectCreatedEvent::class);
        $event->method('getObject')->willReturn($object);

        $this->activityService->expects($this->once())->method('publishObjectCreated')->with($object);
        $this->listener->handle($event);
    }

    /**
     * Test: ObjectUpdatedEvent dispatches to publishObjectUpdated with new and old objects.
     */
    public function testHandleObjectUpdatedEvent(): void
    {
        $newObj = $this->createMock(ObjectEntity::class);
        $oldObj = $this->createMock(ObjectEntity::class);
        $event  = $this->createMock(ObjectUpdatedEvent::class);
        $event->method('getNewObject')->willReturn($newObj);
        $event->method('getOldObject')->willReturn($oldObj);

        $this->activityService->expects($this->once())->method('publishObjectUpdated')->with($newObj, $oldObj);
        $this->listener->handle($event);
    }

    /**
     * Test: ObjectDeletedEvent dispatches to publishObjectDeleted.
     */
    public function testHandleObjectDeletedEvent(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $event  = $this->createMock(ObjectDeletedEvent::class);
        $event->method('getObject')->willReturn($object);

        $this->activityService->expects($this->once())->method('publishObjectDeleted')->with($object);
        $this->listener->handle($event);
    }

    /**
     * Test: RegisterCreatedEvent dispatches to publishRegisterCreated.
     */
    public function testHandleRegisterCreatedEvent(): void
    {
        $register = $this->createMock(Register::class);
        $event    = $this->createMock(RegisterCreatedEvent::class);
        $event->method('getRegister')->willReturn($register);

        $this->activityService->expects($this->once())->method('publishRegisterCreated')->with($register);
        $this->listener->handle($event);
    }

    /**
     * Test: RegisterUpdatedEvent dispatches to publishRegisterUpdated.
     */
    public function testHandleRegisterUpdatedEvent(): void
    {
        $register = $this->createMock(Register::class);
        $event    = $this->createMock(RegisterUpdatedEvent::class);
        $event->method('getNewRegister')->willReturn($register);

        $this->activityService->expects($this->once())->method('publishRegisterUpdated')->with($register);
        $this->listener->handle($event);
    }

    /**
     * Test: RegisterDeletedEvent dispatches to publishRegisterDeleted.
     */
    public function testHandleRegisterDeletedEvent(): void
    {
        $register = $this->createMock(Register::class);
        $event    = $this->createMock(RegisterDeletedEvent::class);
        $event->method('getRegister')->willReturn($register);

        $this->activityService->expects($this->once())->method('publishRegisterDeleted')->with($register);
        $this->listener->handle($event);
    }

    /**
     * Test: SchemaCreatedEvent dispatches to publishSchemaCreated.
     */
    public function testHandleSchemaCreatedEvent(): void
    {
        $schema = $this->createMock(Schema::class);
        $event  = $this->createMock(SchemaCreatedEvent::class);
        $event->method('getSchema')->willReturn($schema);

        $this->activityService->expects($this->once())->method('publishSchemaCreated')->with($schema);
        $this->listener->handle($event);
    }

    /**
     * Test: SchemaDeletedEvent dispatches to publishSchemaDeleted.
     */
    public function testHandleSchemaDeletedEvent(): void
    {
        $schema = $this->createMock(Schema::class);
        $event  = $this->createMock(SchemaDeletedEvent::class);
        $event->method('getSchema')->willReturn($schema);

        $this->activityService->expects($this->once())->method('publishSchemaDeleted')->with($schema);
        $this->listener->handle($event);
    }

    /**
     * Test: Unknown event type is silently ignored.
     */
    public function testHandleUnknownEventIsIgnored(): void
    {
        $event = $this->createMock(Event::class);

        $this->activityService->expects($this->never())->method($this->anything());
        $this->listener->handle($event);
    }
}
