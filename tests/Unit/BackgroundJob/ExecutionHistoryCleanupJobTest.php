<?php

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ExecutionHistoryCleanupJob;
use OCA\OpenRegister\Db\WorkflowExecutionMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ExecutionHistoryCleanupJobTest extends TestCase
{
    private ExecutionHistoryCleanupJob $job;
    private WorkflowExecutionMapper $executionMapper;
    private IAppConfig $appConfig;

    protected function setUp(): void
    {
        $time = $this->createMock(ITimeFactory::class);
        $this->executionMapper = $this->createMock(WorkflowExecutionMapper::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->job = new ExecutionHistoryCleanupJob(
            $time,
            $this->executionMapper,
            $this->appConfig,
            $logger
        );
    }

    public function testDeletesOlderThanRetentionPeriod(): void
    {
        $this->appConfig->method('getValueString')
            ->with('openregister', 'workflow_execution_retention_days', '90')
            ->willReturn('30');

        $this->executionMapper->expects($this->once())
            ->method('deleteOlderThan')
            ->willReturn(15);

        $reflection = new \ReflectionMethod($this->job, 'run');
        $reflection->setAccessible(true);
        $reflection->invoke($this->job, null);
    }

    public function testUsesDefaultRetentionWhenNotConfigured(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('90');

        $this->executionMapper->expects($this->once())
            ->method('deleteOlderThan')
            ->willReturnCallback(function ($cutoff) {
                // Cutoff should be approximately 90 days ago.
                $diff = (new \DateTime())->diff($cutoff);
                $this->assertGreaterThanOrEqual(89, $diff->days);
                $this->assertLessThanOrEqual(91, $diff->days);
                return 0;
            });

        $reflection = new \ReflectionMethod($this->job, 'run');
        $reflection->setAccessible(true);
        $reflection->invoke($this->job, null);
    }

    public function testHandlesZeroRetentionGracefully(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('0');

        // Should fall back to 90 days.
        $this->executionMapper->expects($this->once())
            ->method('deleteOlderThan')
            ->willReturn(0);

        $reflection = new \ReflectionMethod($this->job, 'run');
        $reflection->setAccessible(true);
        $reflection->invoke($this->job, null);
    }
}
