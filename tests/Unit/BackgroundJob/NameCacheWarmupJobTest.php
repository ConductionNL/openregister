<?php

declare(strict_types=1);

/**
 * NameCacheWarmupJob Unit Tests
 *
 * Tests the nightly recurring background job that pre-populates the UUID-to-name cache.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\NameCacheWarmupJob;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Test class for NameCacheWarmupJob
 */
class NameCacheWarmupJobTest extends TestCase
{
    private CacheHandler&MockObject $cacheHandler;
    private LoggerInterface&MockObject $logger;
    private NameCacheWarmupJob $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheHandler = $this->createMock(CacheHandler::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        // Register mocks into the container so run() can resolve them.
        \OC::$server->registerService(CacheHandler::class, function () {
            return $this->cacheHandler;
        });
        \OC::$server->registerService(LoggerInterface::class, function () {
            return $this->logger;
        });

        $timeFactory = $this->createMock(ITimeFactory::class);
        $this->job   = new NameCacheWarmupJob($timeFactory);
    }

    /**
     * Invoke the protected run() method via reflection.
     */
    private function runJob(mixed $argument = []): void
    {
        $ref    = new ReflectionClass($this->job);
        $method = $ref->getMethod('run');
        $method->setAccessible(true);
        $method->invoke($this->job, $argument);
    }

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function testIntervalIsSetToTwentyFourHours(): void
    {
        $ref      = new ReflectionClass($this->job);
        $property = $ref->getProperty('interval');
        $property->setAccessible(true);

        $this->assertSame(24 * 60 * 60, $property->getValue($this->job));
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function testRunCallsWarmupNameCache(): void
    {
        $this->cacheHandler
            ->expects($this->once())
            ->method('warmupNameCache')
            ->willReturn(150);

        $this->runJob();
    }

    public function testRunLogsStartAndCompletion(): void
    {
        $this->cacheHandler->method('warmupNameCache')->willReturn(10);

        $infoCalls = 0;
        $this->logger
            ->method('info')
            ->willReturnCallback(static function () use (&$infoCalls): void {
                $infoCalls++;
            });

        $this->runJob();

        // At minimum: one start log + one completion log.
        $this->assertGreaterThanOrEqual(2, $infoCalls);
    }

    public function testRunLogsNamesLoadedInCompletionContext(): void
    {
        $this->cacheHandler
            ->method('warmupNameCache')
            ->willReturn(77);

        $completionContext = null;
        $this->logger
            ->method('info')
            ->willReturnCallback(static function (string $message, array $context = []) use (&$completionContext): void {
                if (isset($context['names_loaded'])) {
                    $completionContext = $context;
                }
            });

        $this->runJob();

        $this->assertNotNull($completionContext, 'Completion log with names_loaded was not emitted');
        $this->assertSame(77, $completionContext['names_loaded']);
    }

    public function testRunWithZeroNamesLoadedCompletesNormally(): void
    {
        $this->cacheHandler->method('warmupNameCache')->willReturn(0);

        // Should not throw.
        $this->runJob();
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Exception handling
    // -------------------------------------------------------------------------

    public function testRunLogsErrorWhenCacheHandlerThrows(): void
    {
        $this->cacheHandler
            ->method('warmupNameCache')
            ->willThrowException(new \Exception('APCu not available'));

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('error');

        $this->runJob();
    }

    public function testRunDoesNotPropagateException(): void
    {
        $this->cacheHandler
            ->method('warmupNameCache')
            ->willThrowException(new \RuntimeException('Database offline'));

        // PHPUnit will catch any uncaught exception and fail the test.
        $this->runJob();
        $this->assertTrue(true);
    }

    public function testRunLogsExceptionMessageInErrorContext(): void
    {
        $this->cacheHandler
            ->method('warmupNameCache')
            ->willThrowException(new \Exception('Cache backend down'));

        $errorContext = null;
        $this->logger
            ->method('error')
            ->willReturnCallback(static function (string $message, array $context = []) use (&$errorContext): void {
                $errorContext = $context;
            });

        $this->runJob();

        $this->assertNotNull($errorContext);
        $this->assertSame('Cache backend down', $errorContext['error']);
    }
}
