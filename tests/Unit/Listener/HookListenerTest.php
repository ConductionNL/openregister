<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\HookListener;
use OCA\OpenRegister\Service\HookExecutor;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HookListenerTest extends TestCase
{
    private HookListener $listener;
    private HookExecutor&MockObject $hookExecutor;
    private SchemaMapper&MockObject $schemaMapper;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hookExecutor = $this->createMock(HookExecutor::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->listener = new HookListener(
            $this->hookExecutor,
            $this->schemaMapper,
            $this->logger,
        );
    }

    public function testEarlyReturnForUnrelatedEvent(): void
    {
        $event = $this->createMock(Event::class);
        $this->hookExecutor->expects($this->never())->method('executeHooks');
        $this->listener->handle($event);
    }

    public function testEarlyReturnWhenSchemaIdIsNull(): void
    {
        $object = new ObjectEntity();
        // schema is null by default
        $event = new ObjectCreatedEvent($object);

        $this->hookExecutor->expects($this->never())->method('executeHooks');
        $this->listener->handle($event);
    }

    public function testEarlyReturnWhenSchemaHasNoHooks(): void
    {
        $object = new ObjectEntity();
        $object->setSchema('5');
        $event = new ObjectCreatedEvent($object);

        $schema = new Schema();
        // hooks defaults to null/empty
        $this->schemaMapper->method('find')->with(5)->willReturn($schema);

        $this->hookExecutor->expects($this->never())->method('executeHooks');
        $this->listener->handle($event);
    }

    public function testExecutesHooksWhenSchemaHasHooks(): void
    {
        $object = new ObjectEntity();
        $object->setSchema('5');
        $event = new ObjectCreatedEvent($object);

        $schema = new Schema();
        $schema->setHooks(json_encode([['type' => 'webhook', 'url' => 'http://example.com']]));

        $this->schemaMapper->method('find')->with(5)->willReturn($schema);

        $this->hookExecutor->expects($this->once())
            ->method('executeHooks')
            ->with($event, $schema);

        $this->listener->handle($event);
    }

    public function testSchemaMapperExceptionLogsDebug(): void
    {
        $object = new ObjectEntity();
        $object->setSchema('999');
        $event = new ObjectCreatedEvent($object);

        $this->schemaMapper->method('find')
            ->willThrowException(new \Exception('Not found'));

        $this->logger->expects($this->atLeastOnce())->method('debug');
        $this->hookExecutor->expects($this->never())->method('executeHooks');

        $this->listener->handle($event);
    }

    public function testHandlesObjectCreatingEvent(): void
    {
        $object = new ObjectEntity();
        $object->setSchema('5');
        $event = new ObjectCreatingEvent($object);

        $schema = new Schema();
        $schema->setHooks(json_encode([['type' => 'test']]));
        $this->schemaMapper->method('find')->willReturn($schema);

        $this->hookExecutor->expects($this->once())->method('executeHooks');
        $this->listener->handle($event);
    }

    public function testHandlesObjectUpdatingEvent(): void
    {
        $newObject = new ObjectEntity();
        $newObject->setSchema('5');
        $event = new ObjectUpdatingEvent($newObject);

        $schema = new Schema();
        $schema->setHooks(json_encode([['type' => 'test']]));
        $this->schemaMapper->method('find')->willReturn($schema);

        $this->hookExecutor->expects($this->once())->method('executeHooks');
        $this->listener->handle($event);
    }

    public function testHandlesObjectDeletingEvent(): void
    {
        $object = new ObjectEntity();
        $object->setSchema('5');
        $event = new ObjectDeletingEvent($object);

        $schema = new Schema();
        $schema->setHooks(json_encode([['type' => 'test']]));
        $this->schemaMapper->method('find')->willReturn($schema);

        $this->hookExecutor->expects($this->once())->method('executeHooks');
        $this->listener->handle($event);
    }

    public function testHandlesObjectUpdatedEvent(): void
    {
        $newObject = new ObjectEntity();
        $newObject->setSchema('5');
        $event = new ObjectUpdatedEvent($newObject);

        $schema = new Schema();
        $schema->setHooks(json_encode([['type' => 'test']]));
        $this->schemaMapper->method('find')->willReturn($schema);

        $this->hookExecutor->expects($this->once())->method('executeHooks');
        $this->listener->handle($event);
    }

    public function testHandlesObjectDeletedEvent(): void
    {
        $object = new ObjectEntity();
        $object->setSchema('5');
        $event = new ObjectDeletedEvent($object);

        $schema = new Schema();
        $schema->setHooks(json_encode([['type' => 'test']]));
        $this->schemaMapper->method('find')->willReturn($schema);

        $this->hookExecutor->expects($this->once())->method('executeHooks');
        $this->listener->handle($event);
    }
}
