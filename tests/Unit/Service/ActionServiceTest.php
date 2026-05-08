<?php

namespace Unit\Service;

use OCA\OpenRegister\Db\Action;
use OCA\OpenRegister\Db\ActionMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ActionCreatedEvent;
use OCA\OpenRegister\Event\ActionDeletedEvent;
use OCA\OpenRegister\Event\ActionUpdatedEvent;
use OCA\OpenRegister\Service\ActionService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ActionServiceTest extends TestCase
{
    private ActionService $service;
    private $actionMapper;
    private $schemaMapper;
    private $eventDispatcher;
    private $logger;

    protected function setUp(): void
    {
        $this->actionMapper    = $this->createMock(ActionMapper::class);
        $this->schemaMapper    = $this->createMock(SchemaMapper::class);
        $this->eventDispatcher = $this->createMock(IEventDispatcher::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->service = new ActionService(
            $this->actionMapper,
            $this->schemaMapper,
            $this->eventDispatcher,
            $this->logger
        );
    }

    public function testCreateActionSuccess(): void
    {
        $data = [
            'name'       => 'Test Action',
            'eventType'  => 'ObjectCreatingEvent',
            'engine'     => 'n8n',
            'workflowId' => 'wf-123',
        ];

        $this->actionMapper
            ->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function ($entity) {
                // Simulate DB insert setting an ID.
                $entity->setId(1);
                return $entity;
            });

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatchTyped')
            ->with($this->isInstanceOf(ActionCreatedEvent::class));

        $action = $this->service->createAction($data);

        $this->assertSame('Test Action', $action->getName());
        $this->assertSame('draft', $action->getStatus());
        $this->assertNotEmpty($action->getUuid());
    }

    public function testCreateActionMissingNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Action name is required');

        $this->service->createAction(['eventType' => 'X', 'engine' => 'n8n', 'workflowId' => 'w']);
    }

    public function testCreateActionMissingEventTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Action eventType is required');

        $this->service->createAction(['name' => 'X', 'engine' => 'n8n', 'workflowId' => 'w']);
    }

    public function testCreateActionMissingEngineThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Action engine is required');

        $this->service->createAction(['name' => 'X', 'eventType' => 'Y', 'workflowId' => 'w']);
    }

    public function testDeleteActionSoftDeletes(): void
    {
        $action = new Action();
        $action->setId(1);
        $action->setUuid('test-uuid');
        $action->setName('Test');
        $action->setStatus('active');

        $this->actionMapper
            ->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($action);

        $this->actionMapper
            ->expects($this->once())
            ->method('update')
            ->willReturnCallback(function ($entity) {
                return $entity;
            });

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatchTyped')
            ->with($this->isInstanceOf(ActionDeletedEvent::class));

        $deleted = $this->service->deleteAction(1);

        $this->assertSame('archived', $deleted->getStatus());
        $this->assertNotNull($deleted->getDeleted());
    }

    public function testUpdateActionDispatchesEvent(): void
    {
        $action = new Action();
        $action->setId(5);
        $action->setUuid('uuid-5');
        $action->setName('Original');
        $action->setTimeout(30);

        $this->actionMapper
            ->expects($this->once())
            ->method('find')
            ->with(5)
            ->willReturn($action);

        $this->actionMapper
            ->expects($this->once())
            ->method('update')
            ->willReturnCallback(function ($entity) {
                return $entity;
            });

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatchTyped')
            ->with($this->isInstanceOf(ActionUpdatedEvent::class));

        $updated = $this->service->updateAction(5, ['timeout' => 60]);

        $this->assertSame(60, $updated->getTimeout());
    }

    public function testTestActionMatchReturnsTrue(): void
    {
        $action = new Action();
        $action->setId(1);
        $action->setUuid('test-uuid');
        $action->setName('Test');
        $action->setEventType('ObjectCreatingEvent');
        $action->setEngine('n8n');
        $action->setWorkflowId('wf-1');

        $this->actionMapper
            ->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($action);

        $result = $this->service->testAction(1, [
            'eventType'  => 'ObjectCreatingEvent',
            'schemaUuid' => null,
        ]);

        $this->assertTrue($result['matched']);
        $this->assertTrue($result['eventMatch']);
        $this->assertTrue($result['schemaMatch']);
    }

    public function testTestActionFilterMismatch(): void
    {
        $action = new Action();
        $action->setId(1);
        $action->setUuid('test-uuid');
        $action->setName('Test');
        $action->setEventType('ObjectCreatingEvent');
        $action->setEngine('n8n');
        $action->setWorkflowId('wf-1');
        $action->setFilterConditionArray(['data.object.type' => 'person']);

        $this->actionMapper
            ->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($action);

        $result = $this->service->testAction(1, [
            'eventType' => 'ObjectCreatingEvent',
            'data'      => ['object' => ['type' => 'organization']],
        ]);

        $this->assertFalse($result['matched']);
        $this->assertFalse($result['filterMatch']);
        $this->assertNotEmpty($result['filterReasons']);
    }

    public function testMigrateFromHooksCreatesActions(): void
    {
        $schema = new Schema();
        $schema->setHooks([
            [
                'id'         => 'validate-bsn',
                'event'      => 'creating',
                'engine'     => 'n8n',
                'workflowId' => 'wf-123',
                'mode'       => 'sync',
                'order'      => 1,
                'timeout'    => 10,
                'onFailure'  => 'reject',
            ],
        ]);
        $schema->setUuid('schema-uuid-1');
        $schema->setTitle('Test Schema');

        $this->schemaMapper
            ->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($schema);

        // findAll returns empty (no duplicates).
        $this->actionMapper
            ->method('findAll')
            ->willReturn([]);

        $this->actionMapper
            ->method('insert')
            ->willReturnCallback(function ($entity) {
                $entity->setId(99);
                return $entity;
            });

        $this->eventDispatcher
            ->method('dispatchTyped');

        $report = $this->service->migrateFromHooks(1);

        $this->assertCount(1, $report['created']);
        $this->assertCount(0, $report['skipped']);
        $this->assertCount(0, $report['errors']);
    }

    public function testUpdateStatisticsIncrementsSuccess(): void
    {
        $action = new Action();
        $action->setId(1);
        $action->setUuid('test-uuid');
        $action->setExecutionCount(5);
        $action->setSuccessCount(4);
        $action->setFailureCount(1);

        $this->actionMapper
            ->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($action);

        $this->actionMapper
            ->expects($this->once())
            ->method('update')
            ->willReturnCallback(function ($entity) {
                return $entity;
            });

        $this->service->updateStatistics(1, 'success');

        $this->assertSame(6, $action->getExecutionCount());
        $this->assertSame(5, $action->getSuccessCount());
        $this->assertNotNull($action->getLastExecutedAt());
    }
}
