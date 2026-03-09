<?php

namespace Unit\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\StoppableEventInterface;

class ObjectStoppableEventsTest extends TestCase
{
    public static function stoppableEventProvider(): array
    {
        return [
            'ObjectCreatingEvent' => [ObjectCreatingEvent::class],
            'ObjectUpdatingEvent' => [ObjectUpdatingEvent::class],
            'ObjectDeletingEvent' => [ObjectDeletingEvent::class],
        ];
    }

    #[DataProvider('stoppableEventProvider')]
    public function testExtendsEventAndImplementsStoppable(string $eventClass): void
    {
        $object = new ObjectEntity();
        $event = new $eventClass($object);
        $this->assertInstanceOf(Event::class, $event);
        $this->assertInstanceOf(StoppableEventInterface::class, $event);
    }

    #[DataProvider('stoppableEventProvider')]
    public function testPropagationNotStoppedByDefault(string $eventClass): void
    {
        $object = new ObjectEntity();
        $event = new $eventClass($object);
        $this->assertFalse($event->isPropagationStopped());
    }

    #[DataProvider('stoppableEventProvider')]
    public function testStopPropagation(string $eventClass): void
    {
        $object = new ObjectEntity();
        $event = new $eventClass($object);
        $event->stopPropagation();
        $this->assertTrue($event->isPropagationStopped());
    }

    #[DataProvider('stoppableEventProvider')]
    public function testErrorsEmptyByDefault(string $eventClass): void
    {
        $object = new ObjectEntity();
        $event = new $eventClass($object);
        $this->assertSame([], $event->getErrors());
    }

    #[DataProvider('stoppableEventProvider')]
    public function testSetAndGetErrors(string $eventClass): void
    {
        $object = new ObjectEntity();
        $event = new $eventClass($object);
        $errors = ['field' => 'required', 'message' => 'Name is required'];
        $event->setErrors($errors);
        $this->assertSame($errors, $event->getErrors());
    }

    #[DataProvider('stoppableEventProvider')]
    public function testModifiedDataEmptyByDefault(string $eventClass): void
    {
        $object = new ObjectEntity();
        $event = new $eventClass($object);
        $this->assertSame([], $event->getModifiedData());
    }

    #[DataProvider('stoppableEventProvider')]
    public function testSetAndGetModifiedData(string $eventClass): void
    {
        $object = new ObjectEntity();
        $event = new $eventClass($object);
        $data = ['name' => 'Modified Name', 'status' => 'approved'];
        $event->setModifiedData($data);
        $this->assertSame($data, $event->getModifiedData());
    }

    public function testCreatingEventGetObject(): void
    {
        $object = new ObjectEntity();
        $event = new ObjectCreatingEvent($object);
        $this->assertSame($object, $event->getObject());
    }

    public function testUpdatingEventGetNewAndOldObject(): void
    {
        $newObject = new ObjectEntity();
        $oldObject = new ObjectEntity();
        $event = new ObjectUpdatingEvent($newObject, $oldObject);
        $this->assertSame($newObject, $event->getNewObject());
        $this->assertSame($oldObject, $event->getOldObject());
    }

    public function testUpdatingEventOldObjectNullByDefault(): void
    {
        $newObject = new ObjectEntity();
        $event = new ObjectUpdatingEvent($newObject);
        $this->assertSame($newObject, $event->getNewObject());
        $this->assertNull($event->getOldObject());
    }

    public function testDeletingEventGetObject(): void
    {
        $object = new ObjectEntity();
        $event = new ObjectDeletingEvent($object);
        $this->assertSame($object, $event->getObject());
    }

    #[DataProvider('stoppableEventProvider')]
    public function testSetErrorsOverwritesPrevious(string $eventClass): void
    {
        $object = new ObjectEntity();
        $event = new $eventClass($object);
        $event->setErrors(['first' => 'error']);
        $event->setErrors(['second' => 'error']);
        $this->assertSame(['second' => 'error'], $event->getErrors());
    }

    #[DataProvider('stoppableEventProvider')]
    public function testSetModifiedDataOverwritesPrevious(string $eventClass): void
    {
        $object = new ObjectEntity();
        $event = new $eventClass($object);
        $event->setModifiedData(['key1' => 'val1']);
        $event->setModifiedData(['key2' => 'val2']);
        $this->assertSame(['key2' => 'val2'], $event->getModifiedData());
    }
}
