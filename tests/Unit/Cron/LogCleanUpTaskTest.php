<?php

declare(strict_types=1);

namespace Unit\Cron;

use OCA\OpenRegister\Cron\LogCleanUpTask;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LogCleanUpTaskTest extends TestCase
{
    private LogCleanUpTask $task;
    private AuditTrailMapper&MockObject $auditTrailMapper;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $timeFactory = $this->createMock(ITimeFactory::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->task = new LogCleanUpTask(
            $timeFactory,
            $this->auditTrailMapper,
            $this->logger,
        );
    }

    private function runTask($argument = null): void
    {
        $reflection = new \ReflectionClass($this->task);
        $method = $reflection->getMethod('run');
        $method->setAccessible(true);
        $method->invoke($this->task, $argument);
    }

    public function testConstructorSetsInterval(): void
    {
        $reflection = new \ReflectionClass($this->task);
        $property = $reflection->getProperty('interval');
        $property->setAccessible(true);

        $this->assertEquals(3600, $property->getValue($this->task));
    }

    public function testConstructorSetsTimeSensitivity(): void
    {
        $reflection = new \ReflectionClass($this->task);
        $property = $reflection->getProperty('timeSensitivity');
        $property->setAccessible(true);

        $this->assertEquals(IJob::TIME_INSENSITIVE, $property->getValue($this->task));
    }

    public function testConstructorDisablesParallelRuns(): void
    {
        $reflection = new \ReflectionClass($this->task);
        $property = $reflection->getProperty('allowParallelRuns');
        $property->setAccessible(true);

        $this->assertFalse($property->getValue($this->task));
    }

    public function testRunClearsLogsSuccessfully(): void
    {
        $this->auditTrailMapper->expects($this->once())
            ->method('clearLogs')
            ->willReturn(true);

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('Successfully cleared expired audit trail logs'),
                $this->anything()
            );

        $this->logger->expects($this->never())->method('debug');
        $this->logger->expects($this->never())->method('error');

        $this->runTask(null);
    }

    public function testRunNoExpiredLogs(): void
    {
        $this->auditTrailMapper->expects($this->once())
            ->method('clearLogs')
            ->willReturn(false);

        $this->logger->expects($this->never())->method('info');

        $this->logger->expects($this->once())
            ->method('debug')
            ->with(
                $this->stringContains('No expired audit trail logs found'),
                $this->anything()
            );

        $this->runTask(null);
    }

    public function testRunHandlesException(): void
    {
        $exception = new \Exception('Database connection failed');

        $this->auditTrailMapper->expects($this->once())
            ->method('clearLogs')
            ->willThrowException($exception);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Failed to clear expired audit trail logs: Database connection failed'),
                $this->callback(function (array $context) use ($exception) {
                    return $context['exception'] === $exception
                        && $context['app'] === 'openregister';
                })
            );

        $this->runTask(null);
    }

    public function testRunHandlesRuntimeException(): void
    {
        $this->auditTrailMapper->expects($this->once())
            ->method('clearLogs')
            ->willThrowException(new \RuntimeException('Timeout'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Timeout'),
                $this->anything()
            );

        $this->runTask(null);
    }

    public function testRunWithNullArgument(): void
    {
        $this->auditTrailMapper->expects($this->once())
            ->method('clearLogs')
            ->willReturn(true);

        // Should not throw regardless of argument value
        $this->runTask(null);
    }

    public function testRunWithArrayArgument(): void
    {
        $this->auditTrailMapper->expects($this->once())
            ->method('clearLogs')
            ->willReturn(false);

        // Should not throw regardless of argument value
        $this->runTask(['some' => 'data']);
    }
}
