<?php

namespace OCA\OpenRegister\Tests\Unit\Event;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

class ObjectCreatedEventTest extends TestCase
{
    public function testConstructor(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $event = new ObjectCreatedEvent($object);
        
        $this->assertInstanceOf(ObjectCreatedEvent::class, $event);
        $this->assertEquals($object, $event->getObject());
    }

    public function testGetObject(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $event = new ObjectCreatedEvent($object);
        
        $this->assertEquals($object, $event->getObject());
    }

    public function testEventInheritance(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $event = new ObjectCreatedEvent($object);
        
        $this->assertInstanceOf(\OCP\EventDispatcher\Event::class, $event);
    }
}
