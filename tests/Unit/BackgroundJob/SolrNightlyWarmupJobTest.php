<?php

declare(strict_types=1);

/**
 * SolrNightlyWarmupJob Unit Tests
 *
 * Tests the recurring nightly background job that performs a comprehensive SOLR index warmup.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\SolrNightlyWarmupJob;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Test class for SolrNightlyWarmupJob
 *
 * NOTE: getWarmupConfiguration() calls \OC::$server->get(\OCP\IConfig::class).
 * Since IConfig is a core NC service, the container always returns the real
 * implementation regardless of registerService() overrides. Config-value tests
 * therefore write to the real IConfig and restore values in tearDown().
 */
class SolrNightlyWarmupJobTest extends TestCase
{
    private IndexService&MockObject $indexService;
    private SettingsService&MockObject $settingsService;
    private SchemaMapper&MockObject $schemaMapper;
    private LoggerInterface&MockObject $logger;
    /** @var IConfig Real Nextcloud IConfig instance */
    private IConfig $realConfig;
    private SolrNightlyWarmupJob $job;

    /** @var array<string, string> Config values to restore in tearDown */
    private array $originalConfigValues = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->indexService    = $this->createMock(IndexService::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->schemaMapper    = $this->createMock(SchemaMapper::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->realConfig      = \OC::$server->get(IConfig::class);

        // Register mocks in the Nextcloud DI container.
        \OC::$server->registerService(IndexService::class, function () {
            return $this->indexService;
        });
        \OC::$server->registerService(SettingsService::class, function () {
            return $this->settingsService;
        });
        \OC::$server->registerService(SchemaMapper::class, function () {
            return $this->schemaMapper;
        });
        \OC::$server->registerService(LoggerInterface::class, function () {
            return $this->logger;
        });

        $timeFactory = $this->createMock(ITimeFactory::class);
        $this->job   = new SolrNightlyWarmupJob($timeFactory);
    }

    protected function tearDown(): void
    {
        // Restore any app config values changed during tests.
        foreach ($this->originalConfigValues as $key => $value) {
            if ($value === '') {
                $this->realConfig->deleteAppValue('openregister', $key);
            } else {
                $this->realConfig->setAppValue('openregister', $key, $value);
            }
        }
        parent::tearDown();
    }

    /**
     * Save current config value and set a new one for the test.
     */
    private function setAppConfigValue(string $key, string $newValue): void
    {
        // Only save original once per key per test.
        if (!array_key_exists($key, $this->originalConfigValues)) {
            $this->originalConfigValues[$key] = $this->realConfig->getAppValue('openregister', $key, '');
        }
        $this->realConfig->setAppValue('openregister', $key, $newValue);
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
    // Early exit: SOLR disabled in settings
    // -------------------------------------------------------------------------

    public function testRunSkipsWhenSolrDisabledInSettings(): void
    {
        $this->settingsService
            ->method('getSolrSettings')
            ->willReturn(['enabled' => false]);

        $this->indexService
            ->expects($this->never())
            ->method('isAvailable');

        $this->indexService
            ->expects($this->never())
            ->method('warmupIndex');

        $this->runJob();
    }

    public function testRunSkipsWhenSolrEnabledKeyMissing(): void
    {
        // 'enabled' missing → defaults to false.
        $this->settingsService
            ->method('getSolrSettings')
            ->willReturn([]);

        $this->indexService
            ->expects($this->never())
            ->method('warmupIndex');

        $this->runJob();
    }

    // -------------------------------------------------------------------------
    // Early exit: SOLR service not reachable
    // -------------------------------------------------------------------------

    public function testRunSkipsWhenSolrServiceNotAvailable(): void
    {
        $this->settingsService
            ->method('getSolrSettings')
            ->willReturn(['enabled' => true]);

        $this->indexService
            ->method('isAvailable')
            ->willReturn(false);

        $this->indexService
            ->expects($this->never())
            ->method('warmupIndex');

        $this->runJob();
    }

    // -------------------------------------------------------------------------
    // Configuration parsing via real IConfig
    // -------------------------------------------------------------------------

    public function testRunUsesDefaultMaxObjectsWhenNotConfigured(): void
    {
        $this->settingsService->method('getSolrSettings')->willReturn(['enabled' => true]);
        $this->indexService->method('isAvailable')->willReturn(true);
        $this->schemaMapper->method('findAll')->willReturn([]);

        // Ensure the key is not set so defaults apply.
        $this->realConfig->deleteAppValue('openregister', 'solr_nightly_max_objects');

        // positional: schemas, maxObjects, mode, collectErrors
        $this->indexService
            ->expects($this->once())
            ->method('warmupIndex')
            ->with([], 10000, 'parallel', false)
            ->willReturn(['success' => true, 'operations' => []]);

        $this->runJob();
    }

    public function testRunUsesCustomMaxObjectsFromConfig(): void
    {
        $this->settingsService->method('getSolrSettings')->willReturn(['enabled' => true]);
        $this->indexService->method('isAvailable')->willReturn(true);
        $this->schemaMapper->method('findAll')->willReturn([]);

        $this->setAppConfigValue('solr_nightly_max_objects', '25000');

        $this->indexService
            ->expects($this->once())
            ->method('warmupIndex')
            ->with([], 25000, $this->anything(), $this->anything())
            ->willReturn(['success' => true, 'operations' => []]);

        $this->runJob();
    }

    public function testRunUsesCustomModeFromConfig(): void
    {
        $this->settingsService->method('getSolrSettings')->willReturn(['enabled' => true]);
        $this->indexService->method('isAvailable')->willReturn(true);
        $this->schemaMapper->method('findAll')->willReturn([]);

        $this->setAppConfigValue('solr_nightly_mode', 'hyper');

        $this->indexService
            ->expects($this->once())
            ->method('warmupIndex')
            ->with([], $this->anything(), 'hyper', $this->anything())
            ->willReturn(['success' => true, 'operations' => []]);

        $this->runJob();
    }

    public function testRunEnablesCollectErrorsFromConfig(): void
    {
        $this->settingsService->method('getSolrSettings')->willReturn(['enabled' => true]);
        $this->indexService->method('isAvailable')->willReturn(true);
        $this->schemaMapper->method('findAll')->willReturn([]);

        $this->setAppConfigValue('solr_nightly_collect_errors', 'true');

        $this->indexService
            ->expects($this->once())
            ->method('warmupIndex')
            ->with([], $this->anything(), $this->anything(), true)
            ->willReturn(['success' => true, 'operations' => []]);

        $this->runJob();
    }

    // -------------------------------------------------------------------------
    // Happy path — success result
    // -------------------------------------------------------------------------

    public function testRunLogsSuccessOnCompletedWarmup(): void
    {
        $this->settingsService->method('getSolrSettings')->willReturn(['enabled' => true]);
        $this->indexService->method('isAvailable')->willReturn(true);
        $this->schemaMapper->method('findAll')->willReturn([]);

        $this->indexService->method('warmupIndex')->willReturn([
            'success'            => true,
            'operations'         => [
                'objects_indexed'    => 500,
                'schemas_processed'  => 5,
                'fields_created'     => 20,
                'conflicts_resolved' => 2,
            ],
            'execution_time_ms'  => 3500,
        ]);

        $infoMessages = [];
        $this->logger
            ->method('info')
            ->willReturnCallback(static function (string $message) use (&$infoMessages): void {
                $infoMessages[] = $message;
            });

        $this->runJob();

        $successLogs = array_filter($infoMessages, static fn(string $m): bool => str_contains($m, 'Completed Successfully'));
        $this->assertNotEmpty($successLogs, 'Expected completion success log was not emitted');
    }

    public function testRunLogsPerformanceStatsOnSuccess(): void
    {
        $this->settingsService->method('getSolrSettings')->willReturn(['enabled' => true]);
        $this->indexService->method('isAvailable')->willReturn(true);
        $this->schemaMapper->method('findAll')->willReturn([]);

        $this->indexService->method('warmupIndex')->willReturn([
            'success'    => true,
            'operations' => ['objects_indexed' => 200],
        ]);

        $infoMessages = [];
        $this->logger
            ->method('info')
            ->willReturnCallback(static function (string $message) use (&$infoMessages): void {
                $infoMessages[] = $message;
            });

        $this->runJob();

        $perfLogs = array_filter($infoMessages, static fn(string $m): bool => str_contains($m, 'Performance Stats'));
        $this->assertNotEmpty($perfLogs, 'Performance stats log was not emitted');
    }

    // -------------------------------------------------------------------------
    // Failed warmup result (success === false)
    // -------------------------------------------------------------------------

    public function testRunLogsErrorWhenWarmupReturnsFailure(): void
    {
        $this->settingsService->method('getSolrSettings')->willReturn(['enabled' => true]);
        $this->indexService->method('isAvailable')->willReturn(true);
        $this->schemaMapper->method('findAll')->willReturn([]);

        $this->indexService->method('warmupIndex')->willReturn([
            'success' => false,
            'error'   => 'Index rebuild failed',
        ]);

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('error');

        $this->runJob();
    }

    // -------------------------------------------------------------------------
    // Exception handling — does NOT rethrow for recurring jobs
    // -------------------------------------------------------------------------

    public function testRunDoesNotPropagateException(): void
    {
        $this->settingsService->method('getSolrSettings')->willReturn(['enabled' => true]);
        $this->indexService->method('isAvailable')->willReturn(true);
        $this->schemaMapper->method('findAll')->willReturn([]);

        $this->indexService
            ->method('warmupIndex')
            ->willThrowException(new \Exception('SOLR node unreachable'));

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('error');

        // Recurring jobs swallow exceptions to retry next night.
        $this->runJob();
        $this->assertTrue(true);
    }

    public function testRunLogsExceptionDetailsOnFailure(): void
    {
        $this->settingsService->method('getSolrSettings')->willReturn(['enabled' => true]);
        $this->indexService->method('isAvailable')->willReturn(true);
        $this->schemaMapper->method('findAll')->willReturn([]);

        $this->indexService
            ->method('warmupIndex')
            ->willThrowException(new \RuntimeException('Connection timeout after 30s'));

        $errorContext = null;
        $this->logger
            ->method('error')
            ->willReturnCallback(static function (string $message, array $context = []) use (&$errorContext): void {
                if (isset($context['exception'])) {
                    $errorContext = $context;
                }
            });

        $this->runJob();

        $this->assertNotNull($errorContext);
        $this->assertSame('Connection timeout after 30s', $errorContext['exception']);
    }

    // -------------------------------------------------------------------------
    // Schema processing
    // -------------------------------------------------------------------------

    public function testRunPassesSchemasToWarmupIndex(): void
    {
        $this->settingsService->method('getSolrSettings')->willReturn(['enabled' => true]);
        $this->indexService->method('isAvailable')->willReturn(true);

        $schema1 = new Schema();
        $schema2 = new Schema();
        $this->schemaMapper->method('findAll')->willReturn([$schema1, $schema2]);

        $this->indexService
            ->expects($this->once())
            ->method('warmupIndex')
            ->with([$schema1, $schema2], $this->anything(), $this->anything(), $this->anything())
            ->willReturn(['success' => true, 'operations' => []]);

        $this->runJob();
    }
}
