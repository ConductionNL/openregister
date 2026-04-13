<?php

namespace Unit\BackgroundJob;

use DateTime;
use OCA\OpenRegister\BackgroundJob\ScheduledWorkflowJob;
use OCA\OpenRegister\Db\ScheduledWorkflow;
use OCA\OpenRegister\Db\ScheduledWorkflowMapper;
use OCA\OpenRegister\Db\WorkflowExecutionMapper;
use OCA\OpenRegister\Service\WorkflowEngineRegistry;
use OCA\OpenRegister\WorkflowEngine\WorkflowResult;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ScheduledWorkflowJobTest extends TestCase
{
    private ScheduledWorkflowJob $job;
    private ScheduledWorkflowMapper $workflowMapper;
    private WorkflowEngineRegistry $engineRegistry;
    private WorkflowExecutionMapper $executionMapper;

    protected function setUp(): void
    {
        $time = $this->createMock(ITimeFactory::class);
        $this->workflowMapper = $this->createMock(ScheduledWorkflowMapper::class);
        $this->engineRegistry = $this->createMock(WorkflowEngineRegistry::class);
        $this->executionMapper = $this->createMock(WorkflowExecutionMapper::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->job = new ScheduledWorkflowJob(
            $time,
            $this->workflowMapper,
            $this->engineRegistry,
            $this->executionMapper,
            $logger
        );
    }

    public function testSkipsScheduleNotYetDue(): void
    {
        $schedule = new ScheduledWorkflow();
        $schedule->hydrate([
            'name' => 'Test', 'engine' => 'n8n', 'workflowId' => 'wf-1',
            'intervalSec' => 86400, 'enabled' => true,
        ]);
        $schedule->setLastRun(new DateTime('-1 hour'));

        $this->workflowMapper->method('findAllEnabled')
            ->willReturn([$schedule]);

        // Should NOT call executeWorkflow since interval hasn't elapsed.
        $this->engineRegistry->expects($this->never())
            ->method('getEnginesByType');

        // Use reflection to call the protected run method.
        $reflection = new \ReflectionMethod($this->job, 'run');
        $reflection->setAccessible(true);
        $reflection->invoke($this->job, null);
    }

    public function testHandlesNoEngineFoundGracefully(): void
    {
        $schedule = new ScheduledWorkflow();
        $schedule->hydrate([
            'uuid' => 's-1', 'name' => 'Test', 'engine' => 'n8n',
            'workflowId' => 'wf-1', 'intervalSec' => 60, 'enabled' => true,
        ]);
        // No last run - should be due immediately.

        $this->workflowMapper->method('findAllEnabled')
            ->willReturn([$schedule]);

        $this->engineRegistry->expects($this->once())
            ->method('getEnginesByType')
            ->with('n8n')
            ->willReturn([]);

        // Should still attempt to update schedule with error status.
        $this->workflowMapper->expects($this->once())
            ->method('update');

        $reflection = new \ReflectionMethod($this->job, 'run');
        $reflection->setAccessible(true);
        $reflection->invoke($this->job, null);
    }
}
