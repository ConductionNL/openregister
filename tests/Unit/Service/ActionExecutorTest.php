<?php

namespace Unit\Service;

use OCA\OpenRegister\Db\Action;
use OCA\OpenRegister\Db\ActionLogMapper;
use OCA\OpenRegister\Service\ActionExecutor;
use OCA\OpenRegister\Service\ActionService;
use OCA\OpenRegister\Service\Webhook\CloudEventFormatter;
use OCA\OpenRegister\Service\WorkflowEngineRegistry;
use OCA\OpenRegister\WorkflowEngine\WorkflowResult;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ActionExecutorTest extends TestCase
{
    private ActionExecutor $executor;
    private $engineRegistry;
    private $cloudEventFormatter;
    private $actionLogMapper;
    private $actionService;
    private $jobList;
    private $logger;

    protected function setUp(): void
    {
        $this->engineRegistry      = $this->createMock(WorkflowEngineRegistry::class);
        $this->cloudEventFormatter = $this->createMock(CloudEventFormatter::class);
        $this->actionLogMapper     = $this->createMock(ActionLogMapper::class);
        $this->actionService       = $this->createMock(ActionService::class);
        $this->jobList             = $this->createMock(IJobList::class);
        $this->logger              = $this->createMock(LoggerInterface::class);

        $this->executor = new ActionExecutor(
            $this->engineRegistry,
            $this->cloudEventFormatter,
            $this->actionLogMapper,
            $this->actionService,
            $this->jobList,
            $this->logger
        );
    }

    public function testBuildCloudEventPayloadStructure(): void
    {
        $action = new Action();
        $action->setId(1);
        $action->setUuid('test-uuid');
        $action->setName('Test Action');
        $action->setEngine('n8n');
        $action->setWorkflowId('wf-123');
        $action->setMode('sync');

        $payload = $this->executor->buildCloudEventPayload(
            $action,
            ['key' => 'value'],
            'ObjectCreatingEvent'
        );

        $this->assertSame('1.0', $payload['specversion']);
        $this->assertStringContains('nl.openregister.action.ObjectCreatingEvent', $payload['type']);
        $this->assertStringContains('/openregister/actions/test-uuid', $payload['source']);
        $this->assertSame('application/json', $payload['datacontenttype']);
        $this->assertSame(['key' => 'value'], $payload['data']);
        $this->assertSame('test-uuid', $payload['action']['uuid']);
        $this->assertSame('n8n', $payload['action']['engine']);
    }

    public function testExecuteActionsStopsOnPropagationStopped(): void
    {
        $action1 = new Action();
        $action1->setId(1);
        $action1->setUuid('uuid-1');
        $action1->setName('Action 1');
        $action1->setEngine('n8n');
        $action1->setWorkflowId('wf-1');
        $action1->setMode('sync');

        $action2 = new Action();
        $action2->setId(2);
        $action2->setUuid('uuid-2');
        $action2->setName('Action 2');
        $action2->setEngine('n8n');
        $action2->setWorkflowId('wf-2');
        $action2->setMode('sync');

        // Create event that has propagation stopped.
        $event = new class extends Event {
            private bool $stopped = false;
            public function isPropagationStopped(): bool { return $this->stopped; }
            public function stopPropagation(): void { $this->stopped = true; }
        };

        // Pre-stop propagation.
        $event->stopPropagation();

        // Engine should never be called.
        $this->engineRegistry
            ->expects($this->never())
            ->method('getEngine');

        $this->executor->executeActions(
            [$action1, $action2],
            $event,
            ['data' => 'test'],
            'ObjectCreatingEvent'
        );
    }

    public function testExecuteActionsEngineNotAvailableLogsFailure(): void
    {
        $action = new Action();
        $action->setId(1);
        $action->setUuid('uuid-1');
        $action->setName('Action 1');
        $action->setEngine('nonexistent');
        $action->setWorkflowId('wf-1');
        $action->setMode('sync');
        $action->setOnFailure('allow');
        $action->setOnEngineDown('allow');

        $this->engineRegistry
            ->method('getEngine')
            ->willReturn(null);

        // Log entry should be created with failure status.
        $this->actionLogMapper
            ->expects($this->once())
            ->method('insert');

        $this->actionService
            ->expects($this->once())
            ->method('updateStatistics')
            ->with(1, 'failure');

        $event = new Event();

        $this->executor->executeActions(
            [$action],
            $event,
            ['data' => 'test'],
            'ObjectCreatingEvent'
        );
    }

    /**
     * Custom string contains assertion for compatibility
     */
    private static function assertStringContains(string $needle, string $haystack): void
    {
        self::assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'"
        );
    }
}
