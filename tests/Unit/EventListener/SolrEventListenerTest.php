<?php

namespace Unit\EventListener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\EventListener\SolrEventListener;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SolrEventListenerTest extends TestCase
{
    private CacheHandler&MockObject $cacheHandler;
    private LoggerInterface&MockObject $logger;
    private SolrEventListener $listener;

    protected function setUp(): void
    {
        $this->cacheHandler = $this->createMock(CacheHandler::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->listener = new SolrEventListener($this->cacheHandler, $this->logger);
    }

    /**
     * Create a real ObjectEntity with values set via Entity's __call magic.
     */
    private function createObjectEntity(int $id, string $uuid, string $name): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setId($id);
        $object->setUuid($uuid);
        $object->setName($name);
        return $object;
    }

    /**
     * Create a real Schema with values set via Entity's __call magic.
     */
    private function createSchema(int $id, string $title, array $properties = []): Schema
    {
        $schema = new Schema();
        $schema->setId($id);
        $schema->setTitle($title);
        $schema->setProperties($properties);
        return $schema;
    }

    public function testImplementsIEventListener(): void
    {
        $this->assertInstanceOf(IEventListener::class, $this->listener);
    }

    // --- ObjectCreatedEvent ---

    public function testHandleObjectCreatedEvent(): void
    {
        $object = $this->createObjectEntity(1, 'uuid-1', 'Test Object');

        $event = $this->createMock(ObjectCreatedEvent::class);
        $event->method('getObject')->willReturn($object);

        $this->cacheHandler->expects($this->once())
            ->method('invalidateForObjectChange')
            ->with($object, 'create');

        $this->listener->handle($event);
    }

    public function testHandleObjectCreatedEventCacheException(): void
    {
        $object = $this->createObjectEntity(1, 'uuid-1', 'Test');

        $event = $this->createMock(ObjectCreatedEvent::class);
        $event->method('getObject')->willReturn($object);

        $this->cacheHandler->method('invalidateForObjectChange')
            ->willThrowException(new \RuntimeException('Solr not available'));

        // Should not throw, just log
        $this->logger->expects($this->atLeastOnce())->method('debug');

        $this->listener->handle($event);
    }

    // --- ObjectUpdatedEvent ---

    public function testHandleObjectUpdatedEvent(): void
    {
        $newObject = $this->createObjectEntity(2, 'uuid-2', 'Updated');
        $oldObject = $this->createObjectEntity(2, 'uuid-2', 'Original');

        $event = $this->createMock(ObjectUpdatedEvent::class);
        $event->method('getNewObject')->willReturn($newObject);
        $event->method('getOldObject')->willReturn($oldObject);

        $this->cacheHandler->expects($this->once())
            ->method('invalidateForObjectChange')
            ->with($newObject, 'update');

        $this->listener->handle($event);
    }

    public function testHandleObjectUpdatedEventCacheException(): void
    {
        $newObject = $this->createObjectEntity(2, 'uuid-2', 'Updated');
        $oldObject = $this->createObjectEntity(2, 'uuid-2', 'Old');

        $event = $this->createMock(ObjectUpdatedEvent::class);
        $event->method('getNewObject')->willReturn($newObject);
        $event->method('getOldObject')->willReturn($oldObject);

        $this->cacheHandler->method('invalidateForObjectChange')
            ->willThrowException(new \RuntimeException('Solr down'));

        $this->listener->handle($event);
        // Should not throw
        $this->assertTrue(true);
    }

    public function testHandleObjectUpdatedEventNullOldObject(): void
    {
        $newObject = $this->createObjectEntity(3, 'uuid-3', 'New');

        $event = $this->createMock(ObjectUpdatedEvent::class);
        $event->method('getNewObject')->willReturn($newObject);
        $event->method('getOldObject')->willReturn(null);

        $this->cacheHandler->expects($this->once())
            ->method('invalidateForObjectChange')
            ->with($newObject, 'update');

        $this->listener->handle($event);
    }

    // --- ObjectDeletedEvent ---

    public function testHandleObjectDeletedEvent(): void
    {
        $object = $this->createObjectEntity(5, 'uuid-5', 'Deleted');

        $event = $this->createMock(ObjectDeletedEvent::class);
        $event->method('getObject')->willReturn($object);

        $this->cacheHandler->expects($this->once())
            ->method('invalidateForObjectChange')
            ->with($object, 'delete');

        $this->listener->handle($event);
    }

    public function testHandleObjectDeletedEventCacheException(): void
    {
        $object = $this->createObjectEntity(5, 'uuid-5', 'Deleted');

        $event = $this->createMock(ObjectDeletedEvent::class);
        $event->method('getObject')->willReturn($object);

        $this->cacheHandler->method('invalidateForObjectChange')
            ->willThrowException(new \RuntimeException('Solr error'));

        $this->listener->handle($event);
        $this->assertTrue(true);
    }

    // --- SchemaCreatedEvent ---

    public function testHandleSchemaCreatedEvent(): void
    {
        $schema = $this->createSchema(10, 'Test Schema');

        $event = $this->createMock(SchemaCreatedEvent::class);
        $event->method('getSchema')->willReturn($schema);

        // Should log info about reindex
        $this->logger->expects($this->atLeastOnce())->method('info');

        $this->listener->handle($event);
    }

    // --- SchemaUpdatedEvent ---

    public function testHandleSchemaUpdatedEventFieldsChanged(): void
    {
        $oldSchema = $this->createSchema(11, 'Schema', ['field1' => ['type' => 'string']]);
        $newSchema = $this->createSchema(11, 'Updated Schema', ['field1' => ['type' => 'string'], 'field2' => ['type' => 'int']]);

        $event = $this->createMock(SchemaUpdatedEvent::class);
        $event->method('getNewSchema')->willReturn($newSchema);
        $event->method('getOldSchema')->willReturn($oldSchema);

        // Should trigger reindex logging
        $this->logger->expects($this->atLeastOnce())->method('info');

        $this->listener->handle($event);
    }

    public function testHandleSchemaUpdatedEventFieldsUnchanged(): void
    {
        $properties = ['field1' => ['type' => 'string']];

        $oldSchema = $this->createSchema(11, 'Same Schema', $properties);
        $newSchema = $this->createSchema(11, 'Same Schema', $properties);

        $event = $this->createMock(SchemaUpdatedEvent::class);
        $event->method('getNewSchema')->willReturn($newSchema);
        $event->method('getOldSchema')->willReturn($oldSchema);

        $this->listener->handle($event);
        // No reindex triggered, just logging
        $this->assertTrue(true);
    }

    // --- SchemaDeletedEvent ---

    public function testHandleSchemaDeletedEvent(): void
    {
        $schema = $this->createSchema(12, 'Deleted Schema');

        $event = $this->createMock(SchemaDeletedEvent::class);
        $event->method('getSchema')->willReturn($schema);

        $this->logger->expects($this->atLeastOnce())->method('info');

        $this->listener->handle($event);
    }

    // --- Unknown event ---

    public function testHandleUnknownEventLogsDebug(): void
    {
        $event = $this->createMock(Event::class);

        $this->logger->expects($this->atLeastOnce())
            ->method('debug');

        // Should not call cacheHandler
        $this->cacheHandler->expects($this->never())
            ->method('invalidateForObjectChange');

        $this->listener->handle($event);
    }

    // --- Exception in handler ---

    public function testHandleExceptionLogsErrorAndContinues(): void
    {
        // Create a mock that throws when getObject is called
        $event = $this->getMockBuilder(ObjectCreatedEvent::class)
            ->disableOriginalConstructor()
            ->getMock();
        $event->method('getObject')->willThrowException(new \RuntimeException('Unexpected DB error'));

        $this->logger->expects($this->atLeastOnce())->method('error');

        // Should not propagate exception
        $this->listener->handle($event);
    }
}
