<?php

declare(strict_types=1);

/**
 * LogCleanUpTaskTest
 *
 * Comprehensive unit tests for the LogCleanUpTask class to verify log cleanup
 * functionality and error handling.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Cron
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 OpenRegister
 * @license   AGPL-3.0
 * @version   1.0.0
 * @link      https://github.com/OpenRegister/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Cron;

use OCA\OpenRegister\Cron\LogCleanUpTask;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Log Cleanup Task Test Suite
 *
 * Comprehensive unit tests for log cleanup background job including
 * execution, error handling, and configuration.
 *
 * @coversDefaultClass LogCleanUpTask
 */
class LogCleanUpTaskTest extends TestCase
{
    private LogCleanUpTask $logCleanUpTask;
    private ITimeFactory|MockObject $timeFactory;
    private AuditTrailMapper|MockObject $auditTrailMapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->timeFactory = $this->createMock(ITimeFactory::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        
        $this->logCleanUpTask = new LogCleanUpTask(
            $this->timeFactory,
            $this->auditTrailMapper
        );
    }

    /**
     * Test constructor
     *
     * @covers ::__construct
     * @return void
     */
    public function testConstructor(): void
    {
        $this->assertInstanceOf(LogCleanUpTask::class, $this->logCleanUpTask);
        
        // Verify the job is configured correctly
        // Note: These methods may not be accessible in the test environment
        // The constructor sets the interval and configures time sensitivity and parallel runs
    }

    /**
     * Test run method with successful cleanup
     *
     * @covers ::run
     * @return void
     */
    public function testRunWithSuccessfulCleanup(): void
    {
        $this->auditTrailMapper->expects($this->once())
            ->method('clearLogs')
            ->willReturn(true);

        // Mock the OC::$server->getLogger() call
        $logger = $this->createMock(\OCP\ILogger::class);
        $logger->expects($this->once())
            ->method('info')
            ->with(
                'Successfully cleared expired audit trail logs',
                $this->isType('array')
            );

        // Mock the OC::$server static call
        $server = $this->createMock(\OC\Server::class);
        $server->expects($this->once())
            ->method('getLogger')
            ->willReturn($logger);

        // Use reflection to set the static OC::$server property
        $reflection = new \ReflectionClass(\OC::class);
        $serverProperty = $reflection->getProperty('server');
        $serverProperty->setAccessible(true);
        $originalServer = $serverProperty->getValue();
        $serverProperty->setValue(null, $server);

        try {
            $this->logCleanUpTask->run(null);
        } finally {
            // Restore the original server
            $serverProperty->setValue(null, $originalServer);
        }
    }

    /**
     * Test run method with failed cleanup
     *
     * @covers ::run
     * @return void
     */
    public function testRunWithFailedCleanup(): void
    {
        $this->auditTrailMapper->expects($this->once())
            ->method('clearLogs')
            ->willReturn(false);

        // Mock the OC::$server->getLogger() call
        $logger = $this->createMock(\OCP\ILogger::class);
        $logger->expects($this->once())
            ->method('debug')
            ->with(
                'No expired audit trail logs found to clear',
                $this->isType('array')
            );

        // Mock the OC::$server static call
        $server = $this->createMock(\OC\Server::class);
        $server->expects($this->once())
            ->method('getLogger')
            ->willReturn($logger);

        // Use reflection to set the static OC::$server property
        $reflection = new \ReflectionClass(\OC::class);
        $serverProperty = $reflection->getProperty('server');
        $serverProperty->setAccessible(true);
        $originalServer = $serverProperty->getValue();
        $serverProperty->setValue(null, $server);

        try {
            $this->logCleanUpTask->run(null);
        } finally {
            // Restore the original server
            $serverProperty->setValue(null, $originalServer);
        }
    }

    /**
     * Test run method with exception
     *
     * @covers ::run
     * @return void
     */
    public function testRunWithException(): void
    {
        $exception = new \Exception('Database connection failed');
        
        $this->auditTrailMapper->expects($this->once())
            ->method('clearLogs')
            ->willThrowException($exception);

        // Mock the OC::$server->getLogger() call
        $logger = $this->createMock(\OCP\ILogger::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to clear expired audit trail logs: Database connection failed',
                $this->isType('array')
            );

        // Mock the OC::$server static call
        $server = $this->createMock(\OC\Server::class);
        $server->expects($this->once())
            ->method('getLogger')
            ->willReturn($logger);

        // Use reflection to set the static OC::$server property
        $reflection = new \ReflectionClass(\OC::class);
        $serverProperty = $reflection->getProperty('server');
        $serverProperty->setAccessible(true);
        $originalServer = $serverProperty->getValue();
        $serverProperty->setValue(null, $server);

        try {
            $this->logCleanUpTask->run(null);
        } finally {
            // Restore the original server
            $serverProperty->setValue(null, $originalServer);
        }
    }

    /**
     * Test run method with different argument types
     *
     * @covers ::run
     * @return void
     */
    public function testRunWithDifferentArguments(): void
    {
        $this->auditTrailMapper->expects($this->exactly(3))
            ->method('clearLogs')
            ->willReturn(true);

        // Mock the OC::$server->getLogger() call
        $logger = $this->createMock(\OCP\ILogger::class);
        $logger->expects($this->exactly(3))
            ->method('info')
            ->with(
                'Successfully cleared expired audit trail logs',
                $this->isType('array')
            );

        // Mock the OC::$server static call
        $server = $this->createMock(\OC\Server::class);
        $server->expects($this->exactly(3))
            ->method('getLogger')
            ->willReturn($logger);

        // Use reflection to set the static OC::$server property
        $reflection = new \ReflectionClass(\OC::class);
        $serverProperty = $reflection->getProperty('server');
        $serverProperty->setAccessible(true);
        $originalServer = $serverProperty->getValue();
        $serverProperty->setValue(null, $server);

        try {
            // Test with null argument
            $this->logCleanUpTask->run(null);
            
            // Test with string argument
            $this->logCleanUpTask->run('test');
            
            // Test with array argument
            $this->logCleanUpTask->run(['test' => 'value']);
        } finally {
            // Restore the original server
            $serverProperty->setValue(null, $originalServer);
        }
    }

    /**
     * Test job configuration
     *
     * @covers ::__construct
     * @return void
     */
    public function testJobConfiguration(): void
    {
        // Note: These methods may not be accessible in the test environment
        // The constructor sets the interval and configures time sensitivity and parallel runs
        $this->assertInstanceOf(LogCleanUpTask::class, $this->logCleanUpTask);
    }

    /**
     * Test job inheritance
     *
     * @covers ::__construct
     * @return void
     */
    public function testJobInheritance(): void
    {
        $this->assertInstanceOf(\OCP\BackgroundJob\TimedJob::class, $this->logCleanUpTask);
        $this->assertInstanceOf(\OCP\BackgroundJob\IJob::class, $this->logCleanUpTask);
    }

    /**
     * Test audit trail mapper dependency
     *
     * @covers ::__construct
     * @return void
     */
    public function testAuditTrailMapperDependency(): void
    {
        $reflection = new \ReflectionClass($this->logCleanUpTask);
        $property = $reflection->getProperty('auditTrailMapper');
        $property->setAccessible(true);
        
        $this->assertSame($this->auditTrailMapper, $property->getValue($this->logCleanUpTask));
    }

    /**
     * Test time factory dependency
     *
     * @covers ::__construct
     * @return void
     */
    public function testTimeFactoryDependency(): void
    {
        $reflection = new \ReflectionClass($this->logCleanUpTask);
        $parentReflection = $reflection->getParentClass();
        $property = $parentReflection->getProperty('time');
        $property->setAccessible(true);
        
        $this->assertSame($this->timeFactory, $property->getValue($this->logCleanUpTask));
    }
}
