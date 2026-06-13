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
 */
class SolrNightlyWarmupJobTest extends TestCase
{
    private IndexService&MockObject $indexService;
    private SettingsService&MockObject $settingsService;
    private SchemaMapper&MockObject $schemaMapper;
    private LoggerInterface&MockObject $logger;
    private IConfig&MockObject $config;
    private SolrNightlyWarmupJob $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->indexService    = $this->createMock(IndexService::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->schemaMapper    = $this->createMock(SchemaMapper::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->config          = $this->createMock(IConfig::class);

        $timeFactory = $this->createMock(ITimeFactory::class);
        $this->job   = new SolrNightlyWarmupJob(
            time: $timeFactory,
            indexService: $this->indexService,
            settingsService: $this->settingsService,
            schemaMapper: $this->schemaMapper,
            logger: $this->logger,
            config: $this->config,
        );
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
    // Configuration parsing via mock IConfig
    // -------------------------------------------------------------------------

    public function testRunUsesDefaultMaxObjectsWhenNotConfigured(): void
    {
        $this->settingsService->method('getSolrSettings')->willReturn(['enabled' => true]);
        $this->indexService->method('isAvailable')->willReturn(true);
        $this->schemaMapper->method('findAll')->willReturn([]);

        $this->config->method('getAppValue')
            ->willReturnCallback(static function (string $app, string $key, string $default = ''): string {
                return $default;
            });

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

        $this->config->method('getAppValue')
            ->willReturnCallback(static function (string $app, string $key, string $default = ''): string {
                if ($key === 'solr_nightly_max_objects') {
                    return '25000';
                }
                return $default;
            });

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

        $this->config->method('getAppValue')
            ->willReturnCallback(static function (string $app, string $key, string $default = ''): string {
                if ($key === 'solr_nightly_mode') {
                    return 'hyper';
                }
                return $default;
            });

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

        $this->config->method('getAppValue')
            ->willReturnCallback(static function (string $app, string $key, string $default = ''): string {
                if ($key === 'solr_nightly_collect_errors') {
                    return 'true';
                }
                return $default;
            });

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
        $this->config->method('getAppValue')->willReturnCallback(static fn($a, $k, $d = '') => $d);

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
        $this->config->method('getAppValue')->willReturnCallback(static fn($a, $k, $d = '') => $d);

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
        $this->config->method('getAppValue')->willReturnCallback(static fn($a, $k, $d = '') => $d);

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
        $this->config->method('getAppValue')->willReturnCallback(static fn($a, $k, $d = '') => $d);

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
        $this->config->method('getAppValue')->willReturnCallback(static fn($a, $k, $d = '') => $d);

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
        $this->config->method('getAppValue')->willReturnCallback(static fn($a, $k, $d = '') => $d);

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
