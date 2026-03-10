<?php

namespace Unit\Event;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

class ObjectSpecialEventsTest extends TestCase
{
    public function testLockedEventExtendsEvent(): void
    {
        $object = new ObjectEntity();
        $event = new ObjectLockedEvent($object);
        $this->assertInstanceOf(Event::class, $event);
    }

    public function testLockedEventGetObject(): void
    {
        $object = new ObjectEntity();
        $event = new ObjectLockedEvent($object);
        $this->assertSame($object, $event->getObject());
    }

    public function testUnlockedEventExtendsEvent(): void
    {
        $object = new ObjectEntity();
        $event = new ObjectUnlockedEvent($object);
        $this->assertInstanceOf(Event::class, $event);
    }

    public function testUnlockedEventGetObject(): void
    {
        $object = new ObjectEntity();
        $event = new ObjectUnlockedEvent($object);
        $this->assertSame($object, $event->getObject());
    }

    public function testRevertedEventExtendsEvent(): void
    {
        $object = new ObjectEntity();
        $event = new ObjectRevertedEvent($object);
        $this->assertInstanceOf(Event::class, $event);
    }

    public function testRevertedEventGetObject(): void
    {
        $object = new ObjectEntity();
        $event = new ObjectRevertedEvent($object);
        $this->assertSame($object, $event->getObject());
    }

    public function testRevertedEventUntilNullByDefault(): void
    {
        $object = new ObjectEntity();
        $event = new ObjectRevertedEvent($object);
        $this->assertNull($event->getRevertPoint());
    }

    public function testRevertedEventWithDateTimeUntil(): void
    {
        $object = new ObjectEntity();
        $until = new DateTime('2024-01-15 10:30:00');
        $event = new ObjectRevertedEvent($object, $until);
        $this->assertSame($until, $event->getRevertPoint());
    }

    public function testRevertedEventWithStringUntil(): void
    {
        $object = new ObjectEntity();
        $auditId = 'audit-123-abc';
        $event = new ObjectRevertedEvent($object, $auditId);
        $this->assertSame($auditId, $event->getRevertPoint());
    }

    public function testRevertedEventWithEmptyStringUntil(): void
    {
        $object = new ObjectEntity();
        $event = new ObjectRevertedEvent($object, '');
        $this->assertSame('', $event->getRevertPoint());
    }
}
