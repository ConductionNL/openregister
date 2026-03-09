<?php

namespace Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Service\HookExecutor;
use OCA\OpenRegister\Service\WorkflowEngineRegistry;
use OCA\OpenRegister\Service\Webhook\CloudEventFormatter;
use OCA\OpenRegister\WorkflowEngine\WorkflowEngineInterface;
use OCA\OpenRegister\WorkflowEngine\WorkflowResult;
use OCA\OpenRegister\Db\WorkflowEngine;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HookExecutorTest extends TestCase
{
    private WorkflowEngineRegistry&MockObject $engineRegistry;
    private CloudEventFormatter&MockObject $cloudEventFormatter;
    private SchemaMapper&MockObject $schemaMapper;
    private IJobList&MockObject $jobList;
    private LoggerInterface&MockObject $logger;
    private HookExecutor $service;

    protected function setUp(): void
    {
        $this->engineRegistry = $this->createMock(WorkflowEngineRegistry::class);
        $this->cloudEventFormatter = $this->createMock(CloudEventFormatter::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->jobList = $this->createMock(IJobList::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new HookExecutor(
            $this->engineRegistry,
            $this->cloudEventFormatter,
            $this->schemaMapper,
            $this->jobList,
            $this->logger
        );
    }

    private function createObjectEntity(string $uuid = 'test-uuid', string $register = '1', string $schema = '1', array $object = []): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setRegister($register);
        $entity->setSchema($schema);
        $entity->setObject($object);
        $ref = new \ReflectionClass($entity);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($entity, 42);
        return $entity;
    }

    private function createSchema(array $hooks = []): Schema
    {
        $schema = new Schema();
        $schema->setTitle('TestSchema');
        $schema->setHooks($hooks);
        $ref = new \ReflectionClass($schema);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($schema, 1);
        return $schema;
    }

    public function testExecuteHooksReturnsEarlyForUnknownEventType(): void
    {
        $event = $this->createMock(Event::class);
        $schema = $this->createSchema();

        // Should not interact with engine registry at all
        $this->engineRegistry->expects($this->never())->method('getEnginesByType');

        $this->service->executeHooks($event, $schema);
    }

    public function testExecuteHooksReturnsEarlyWhenNoHooksMatch(): void
    {
        $object = $this->createObjectEntity();
        $event = $this->createMock(ObjectCreatingEvent::class);
        $event->method('getObject')->willReturn($object);

        $schema = $this->createSchema([
            ['event' => 'updated', 'enabled' => true, 'engine' => 'n8n', 'workflowId' => 'wf1'],
        ]);

        $this->engineRegistry->expects($this->never())->method('getEnginesByType');

        $this->service->executeHooks($event, $schema);
    }

    public function testExecuteHooksSkipsDisabledHooks(): void
    {
        $object = $this->createObjectEntity();
        $event = $this->createMock(ObjectCreatingEvent::class);
        $event->method('getObject')->willReturn($object);
        $event->method('isPropagationStopped')->willReturn(false);

        $schema = $this->createSchema([
            ['event' => 'creating', 'enabled' => false, 'engine' => 'n8n', 'workflowId' => 'wf1'],
        ]);

        $this->engineRegistry->expects($this->never())->method('getEnginesByType');

        $this->service->executeHooks($event, $schema);
    }

    public function testExecuteHooksCallsEngineForMatchingHook(): void
    {
        $object = $this->createObjectEntity();
        $event = $this->createMock(ObjectCreatingEvent::class);
        $event->method('getObject')->willReturn($object);
        $event->method('isPropagationStopped')->willReturn(false);

        $schema = $this->createSchema([
            ['id' => 'hook1', 'event' => 'creating', 'enabled' => true, 'engine' => 'n8n', 'workflowId' => 'wf1', 'mode' => 'sync'],
        ]);

        $this->cloudEventFormatter->method('formatAsCloudEvent')->willReturn([
            'type' => 'nl.openregister.object.creating',
            'openregister' => [],
        ]);

        $workflowEngine = new WorkflowEngine();
        $adapter = $this->createMock(WorkflowEngineInterface::class);

        $result = $this->createMock(WorkflowResult::class);
        $result->method('isApproved')->willReturn(true);

        $this->engineRegistry->method('getEnginesByType')->willReturn([$workflowEngine]);
        $this->engineRegistry->method('resolveAdapter')->willReturn($adapter);
        $adapter->method('executeWorkflow')->willReturn($result);

        $this->logger->expects($this->atLeastOnce())->method('info');

        $this->service->executeHooks($event, $schema);
    }

    public function testExecuteHooksStopsOnPropagationStopped(): void
    {
        $object = $this->createObjectEntity();
        $event = $this->createMock(ObjectCreatingEvent::class);
        $event->method('getObject')->willReturn($object);
        $event->method('isPropagationStopped')->willReturn(true);

        $schema = $this->createSchema([
            ['id' => 'hook1', 'event' => 'creating', 'enabled' => true, 'engine' => 'n8n', 'workflowId' => 'wf1'],
            ['id' => 'hook2', 'event' => 'creating', 'enabled' => true, 'engine' => 'n8n', 'workflowId' => 'wf2'],
        ]);

        $this->engineRegistry->expects($this->never())->method('getEnginesByType');

        $this->service->executeHooks($event, $schema);
    }

    public function testExecuteHooksRejectsWhenNoEngineFound(): void
    {
        $object = $this->createObjectEntity();
        $event = $this->createMock(ObjectCreatingEvent::class);
        $event->method('getObject')->willReturn($object);
        $event->method('isPropagationStopped')->willReturn(false);

        $schema = $this->createSchema([
            ['id' => 'hook1', 'event' => 'creating', 'enabled' => true, 'engine' => 'missing', 'workflowId' => 'wf1', 'onEngineDown' => 'allow'],
        ]);

        $this->cloudEventFormatter->method('formatAsCloudEvent')->willReturn([
            'type' => 'test',
            'openregister' => [],
        ]);

        $this->engineRegistry->method('getEnginesByType')->willReturn([]);

        // Should log warning for allow mode
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $this->service->executeHooks($event, $schema);
    }

    public function testExecuteHooksHandlesExceptionWithTimeoutFailureMode(): void
    {
        $object = $this->createObjectEntity();
        $event = $this->createMock(ObjectCreatingEvent::class);
        $event->method('getObject')->willReturn($object);
        $event->method('isPropagationStopped')->willReturn(false);

        $schema = $this->createSchema([
            [
                'id' => 'hook1',
                'event' => 'creating',
                'enabled' => true,
                'engine' => 'n8n',
                'workflowId' => 'wf1',
                'mode' => 'sync',
                'onTimeout' => 'allow',
            ],
        ]);

        $this->cloudEventFormatter->method('formatAsCloudEvent')->willReturn([
            'type' => 'test',
            'openregister' => [],
        ]);

        $workflowEngine = new WorkflowEngine();
        $adapter = $this->createMock(WorkflowEngineInterface::class);

        $this->engineRegistry->method('getEnginesByType')->willReturn([$workflowEngine]);
        $this->engineRegistry->method('resolveAdapter')->willReturn($adapter);
        $adapter->method('executeWorkflow')->willThrowException(new \Exception('Request timed out'));

        // Allow mode: should log warning, not error for reject
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $this->service->executeHooks($event, $schema);
    }

    public function testExecuteHooksRejectedResult(): void
    {
        $object = $this->createObjectEntity();
        $event = $this->createMock(ObjectCreatingEvent::class);
        $event->method('getObject')->willReturn($object);
        $event->method('isPropagationStopped')->willReturn(false);

        $schema = $this->createSchema([
            [
                'id' => 'hook1',
                'event' => 'creating',
                'enabled' => true,
                'engine' => 'n8n',
                'workflowId' => 'wf1',
                'mode' => 'sync',
                'onFailure' => 'reject',
            ],
        ]);

        $this->cloudEventFormatter->method('formatAsCloudEvent')->willReturn([
            'type' => 'test',
            'openregister' => [],
        ]);

        $workflowEngine = new WorkflowEngine();
        $adapter = $this->createMock(WorkflowEngineInterface::class);
        $result = $this->createMock(WorkflowResult::class);
        $result->method('isApproved')->willReturn(false);
        $result->method('isModified')->willReturn(false);
        $result->method('isRejected')->willReturn(true);
        $result->method('getErrors')->willReturn([['message' => 'Validation failed']]);

        $this->engineRegistry->method('getEnginesByType')->willReturn([$workflowEngine]);
        $this->engineRegistry->method('resolveAdapter')->willReturn($adapter);
        $adapter->method('executeWorkflow')->willReturn($result);

        $event->expects($this->once())->method('stopPropagation');
        $event->expects($this->once())->method('setErrors');

        $this->service->executeHooks($event, $schema);
    }

    public function testExecuteHooksModifiedResult(): void
    {
        $object = $this->createObjectEntity();
        $event = $this->createMock(ObjectCreatingEvent::class);
        $event->method('getObject')->willReturn($object);
        $event->method('isPropagationStopped')->willReturn(false);

        $schema = $this->createSchema([
            [
                'id' => 'hook1',
                'event' => 'creating',
                'enabled' => true,
                'engine' => 'n8n',
                'workflowId' => 'wf1',
                'mode' => 'sync',
            ],
        ]);

        $this->cloudEventFormatter->method('formatAsCloudEvent')->willReturn([
            'type' => 'test',
            'openregister' => [],
        ]);

        $workflowEngine = new WorkflowEngine();
        $adapter = $this->createMock(WorkflowEngineInterface::class);
        $result = $this->createMock(WorkflowResult::class);
        $result->method('isApproved')->willReturn(false);
        $result->method('isModified')->willReturn(true);
        $result->method('getData')->willReturn(['name' => 'modified']);

        $this->engineRegistry->method('getEnginesByType')->willReturn([$workflowEngine]);
        $this->engineRegistry->method('resolveAdapter')->willReturn($adapter);
        $adapter->method('executeWorkflow')->willReturn($result);

        $event->expects($this->once())->method('setModifiedData');

        $this->service->executeHooks($event, $schema);
    }

    public function testExecuteHooksWithFilterConditionNotMet(): void
    {
        $object = $this->createObjectEntity('test-uuid', '1', '1', ['status' => 'draft']);
        $event = $this->createMock(ObjectCreatingEvent::class);
        $event->method('getObject')->willReturn($object);
        $event->method('isPropagationStopped')->willReturn(false);

        $schema = $this->createSchema([
            [
                'id' => 'hook1',
                'event' => 'creating',
                'enabled' => true,
                'engine' => 'n8n',
                'workflowId' => 'wf1',
                'filterCondition' => ['status' => 'published'],
            ],
        ]);

        // Filter doesn't match, so engine should never be called
        $this->engineRegistry->expects($this->never())->method('getEnginesByType');

        $this->service->executeHooks($event, $schema);
    }

    public function testExecuteHooksWithFilterConditionMet(): void
    {
        $object = $this->createObjectEntity('test-uuid', '1', '1', ['status' => 'published']);
        $event = $this->createMock(ObjectCreatingEvent::class);
        $event->method('getObject')->willReturn($object);
        $event->method('isPropagationStopped')->willReturn(false);

        $schema = $this->createSchema([
            [
                'id' => 'hook1',
                'event' => 'creating',
                'enabled' => true,
                'engine' => 'n8n',
                'workflowId' => 'wf1',
                'filterCondition' => ['status' => 'published'],
                'mode' => 'sync',
            ],
        ]);

        $this->cloudEventFormatter->method('formatAsCloudEvent')->willReturn([
            'type' => 'test',
            'openregister' => [],
        ]);

        $workflowEngine = new WorkflowEngine();
        $adapter = $this->createMock(WorkflowEngineInterface::class);
        $result = $this->createMock(WorkflowResult::class);
        $result->method('isApproved')->willReturn(true);

        $this->engineRegistry->method('getEnginesByType')->willReturn([$workflowEngine]);
        $this->engineRegistry->method('resolveAdapter')->willReturn($adapter);
        $adapter->expects($this->once())->method('executeWorkflow')->willReturn($result);

        $this->service->executeHooks($event, $schema);
    }

    public function testExecuteHooksQueueFailureMode(): void
    {
        $object = $this->createObjectEntity();
        $event = $this->createMock(ObjectCreatingEvent::class);
        $event->method('getObject')->willReturn($object);
        $event->method('isPropagationStopped')->willReturn(false);

        $schema = $this->createSchema([
            [
                'id' => 'hook1',
                'event' => 'creating',
                'enabled' => true,
                'engine' => 'missing',
                'workflowId' => 'wf1',
                'onEngineDown' => 'queue',
            ],
        ]);

        $this->cloudEventFormatter->method('formatAsCloudEvent')->willReturn([
            'type' => 'test',
            'openregister' => [],
        ]);

        $this->engineRegistry->method('getEnginesByType')->willReturn([]);

        $this->jobList->expects($this->once())->method('add');

        $this->service->executeHooks($event, $schema);
    }

    public function testExecuteHooksSortsHooksByOrder(): void
    {
        $object = $this->createObjectEntity();
        $event = $this->createMock(ObjectCreatingEvent::class);
        $event->method('getObject')->willReturn($object);
        $event->method('isPropagationStopped')->willReturn(false);

        $schema = $this->createSchema([
            ['id' => 'hook-b', 'event' => 'creating', 'enabled' => true, 'engine' => 'n8n', 'workflowId' => 'wf2', 'order' => 2, 'mode' => 'sync'],
            ['id' => 'hook-a', 'event' => 'creating', 'enabled' => true, 'engine' => 'n8n', 'workflowId' => 'wf1', 'order' => 1, 'mode' => 'sync'],
        ]);

        $this->cloudEventFormatter->method('formatAsCloudEvent')->willReturn([
            'type' => 'test',
            'openregister' => [],
        ]);

        $workflowEngine = new WorkflowEngine();
        $adapter = $this->createMock(WorkflowEngineInterface::class);
        $result = $this->createMock(WorkflowResult::class);
        $result->method('isApproved')->willReturn(true);

        $this->engineRegistry->method('getEnginesByType')->willReturn([$workflowEngine]);
        $this->engineRegistry->method('resolveAdapter')->willReturn($adapter);

        $callOrder = [];
        $adapter->method('executeWorkflow')->willReturnCallback(
            function (string $workflowId) use ($result, &$callOrder) {
                $callOrder[] = $workflowId;
                return $result;
            }
        );

        $this->service->executeHooks($event, $schema);

        $this->assertSame(['wf1', 'wf2'], $callOrder);
    }

    public function testExecuteHooksWithUpdatingEvent(): void
    {
        $object = $this->createObjectEntity();
        $event = $this->createMock(ObjectUpdatingEvent::class);
        $event->method('getNewObject')->willReturn($object);
        $event->method('isPropagationStopped')->willReturn(false);

        $schema = $this->createSchema([
            ['id' => 'hook1', 'event' => 'updating', 'enabled' => true, 'engine' => 'n8n', 'workflowId' => 'wf1', 'mode' => 'sync'],
        ]);

        $this->cloudEventFormatter->method('formatAsCloudEvent')->willReturn([
            'type' => 'test',
            'openregister' => [],
        ]);

        $workflowEngine = new WorkflowEngine();
        $adapter = $this->createMock(WorkflowEngineInterface::class);
        $result = $this->createMock(WorkflowResult::class);
        $result->method('isApproved')->willReturn(true);

        $this->engineRegistry->method('getEnginesByType')->willReturn([$workflowEngine]);
        $this->engineRegistry->method('resolveAdapter')->willReturn($adapter);
        $adapter->expects($this->once())->method('executeWorkflow')->willReturn($result);

        $this->service->executeHooks($event, $schema);
    }

    public function testExecuteHooksAsyncMode(): void
    {
        $object = $this->createObjectEntity();
        $event = $this->createMock(ObjectCreatingEvent::class);
        $event->method('getObject')->willReturn($object);
        $event->method('isPropagationStopped')->willReturn(false);

        $schema = $this->createSchema([
            ['id' => 'hook1', 'event' => 'creating', 'enabled' => true, 'engine' => 'n8n', 'workflowId' => 'wf1', 'mode' => 'async'],
        ]);

        $this->cloudEventFormatter->method('formatAsCloudEvent')->willReturn([
            'type' => 'test',
            'openregister' => [],
        ]);

        $workflowEngine = new WorkflowEngine();
        $adapter = $this->createMock(WorkflowEngineInterface::class);

        $this->engineRegistry->method('getEnginesByType')->willReturn([$workflowEngine]);
        $this->engineRegistry->method('resolveAdapter')->willReturn($adapter);
        $adapter->expects($this->once())->method('executeWorkflow');

        $this->logger->expects($this->atLeastOnce())->method('info');

        $this->service->executeHooks($event, $schema);
    }

    public function testExecuteHooksConnectionRefusedUsesEngineDownMode(): void
    {
        $object = $this->createObjectEntity();
        $event = $this->createMock(ObjectCreatingEvent::class);
        $event->method('getObject')->willReturn($object);
        $event->method('isPropagationStopped')->willReturn(false);

        $schema = $this->createSchema([
            [
                'id' => 'hook1',
                'event' => 'creating',
                'enabled' => true,
                'engine' => 'n8n',
                'workflowId' => 'wf1',
                'mode' => 'sync',
                'onEngineDown' => 'allow',
            ],
        ]);

        $this->cloudEventFormatter->method('formatAsCloudEvent')->willReturn([
            'type' => 'test',
            'openregister' => [],
        ]);

        $workflowEngine = new WorkflowEngine();
        $adapter = $this->createMock(WorkflowEngineInterface::class);

        $this->engineRegistry->method('getEnginesByType')->willReturn([$workflowEngine]);
        $this->engineRegistry->method('resolveAdapter')->willReturn($adapter);
        $adapter->method('executeWorkflow')->willThrowException(new \Exception('Connection refused'));

        // Allow mode logs warning, not error rejection
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $this->service->executeHooks($event, $schema);
    }
}
