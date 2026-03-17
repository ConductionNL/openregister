<?php

declare(strict_types=1);

/**
 * CacheWarmupJob Unit Tests
 *
 * Tests the configurable recurring background job that warms up the UUID-to-name cache.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\CacheWarmupJob;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Test class for CacheWarmupJob
 */
class CacheWarmupJobTest extends TestCase
{
    private IAppConfig&MockObject $appConfig;
    private LoggerInterface&MockObject $logger;
    private CacheHandler&MockObject $cacheHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->appConfig    = $this->createMock(IAppConfig::class);
        $this->logger       = $this->createMock(LoggerInterface::class);
        $this->cacheHandler = $this->createMock(CacheHandler::class);
    }

    /**
     * Create the job, injecting the appConfig mock into \OC::$server so the
     * constructor can call getValueString(). The CacheHandler mock is registered
     * for use in run().
     */
    private function makeJob(string $intervalValue = '3600'): CacheWarmupJob
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturnCallback(static function (string $app, string $key, string $default = '') use ($intervalValue): string {
                if ($app === 'openregister' && $key === 'cache_warmup_interval') {
                    return $intervalValue;
                }
                return $default;
            });

        $timeFactory = $this->createMock(ITimeFactory::class);

        \OC::$server->registerService(CacheHandler::class, function () {
            return $this->cacheHandler;
        });

        return new CacheWarmupJob($timeFactory, $this->appConfig, $this->logger);
    }

    /**
     * Invoke the protected run() method via reflection.
     */
    private function runJob(CacheWarmupJob $job, mixed $argument = []): void
    {
        $ref    = new ReflectionClass($job);
        $method = $ref->getMethod('run');
        $method->setAccessible(true);
        $method->invoke($job, $argument);
    }

    // -------------------------------------------------------------------------
    // Constructor / interval tests
    // -------------------------------------------------------------------------

    public function testDefaultIntervalIsSet(): void
    {
        $job = $this->makeJob('3600');

        $ref      = new ReflectionClass($job);
        $property = $ref->getProperty('interval');
        $property->setAccessible(true);

        $this->assertSame(3600, $property->getValue($job));
    }

    public function testZeroIntervalDisablesJobBySettingYearlyInterval(): void
    {
        $job = $this->makeJob('0');

        $ref      = new ReflectionClass($job);
        $property = $ref->getProperty('interval');
        $property->setAccessible(true);

        // Expect 86400 * 365 = 31536000 (effectively disabled)
        $this->assertSame(86400 * 365, $property->getValue($job));
    }

    public function testCustomIntervalIsRespected(): void
    {
        $job = $this->makeJob('7200');

        $ref      = new ReflectionClass($job);
        $property = $ref->getProperty('interval');
        $property->setAccessible(true);

        $this->assertSame(7200, $property->getValue($job));
    }

    // -------------------------------------------------------------------------
    // run() early-exit when disabled
    // -------------------------------------------------------------------------

    public function testRunSkipsWhenIntervalIsZero(): void
    {
        $job = $this->makeJob('0');

        // Override getValueString to return 0 in run() as well.
        $this->appConfig
            ->expects($this->atLeastOnce())
            ->method('getValueString')
            ->willReturn('0');

        $this->cacheHandler
            ->expects($this->never())
            ->method('warmupNameCache');

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('info');

        $this->runJob($job);
    }

    // -------------------------------------------------------------------------
    // run() happy path
    // -------------------------------------------------------------------------

    public function testRunCallsWarmupNameCacheOnHappyPath(): void
    {
        $job = $this->makeJob('3600');

        $this->cacheHandler
            ->expects($this->once())
            ->method('warmupNameCache')
            ->willReturn(42);

        $this->appConfig
            ->expects($this->atLeastOnce())
            ->method('setValueString')
            ->with('openregister', 'cache_warmup_last_run', $this->isType('string'));

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('info');

        $this->runJob($job);
    }

    public function testRunLogsNamesLoaded(): void
    {
        $job = $this->makeJob('3600');

        $this->cacheHandler
            ->method('warmupNameCache')
            ->willReturn(99);

        $loggedContext = null;
        $this->logger
            ->method('info')
            ->willReturnCallback(static function (string $message, array $context = []) use (&$loggedContext): void {
                if (str_contains($message, 'completed')) {
                    $loggedContext = $context;
                }
            });

        $this->runJob($job);

        $this->assertNotNull($loggedContext);
        $this->assertSame(99, $loggedContext['names_loaded']);
    }

    // -------------------------------------------------------------------------
    // run() exception handling
    // -------------------------------------------------------------------------

    public function testRunLogsErrorOnException(): void
    {
        $job = $this->makeJob('3600');

        $this->cacheHandler
            ->method('warmupNameCache')
            ->willThrowException(new \Exception('Redis unavailable'));

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('error');

        // Must not propagate the exception.
        $this->runJob($job);
    }

    public function testRunDoesNotRethrowException(): void
    {
        $job = $this->makeJob('3600');

        $this->cacheHandler
            ->method('warmupNameCache')
            ->willThrowException(new \RuntimeException('Unexpected failure'));

        // If an exception escaped, PHPUnit would catch it and fail.
        $this->runJob($job);
        $this->assertTrue(true); // reached means no rethrow.
    }

    // -------------------------------------------------------------------------
    // setValueString stores last run timestamp
    // -------------------------------------------------------------------------

    public function testRunStoresLastRunTimestamp(): void
    {
        $job = $this->makeJob('3600');

        $this->cacheHandler->method('warmupNameCache')->willReturn(5);

        $this->appConfig
            ->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'cache_warmup_last_run', $this->matchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/'));

        $this->runJob($job);
    }
}
