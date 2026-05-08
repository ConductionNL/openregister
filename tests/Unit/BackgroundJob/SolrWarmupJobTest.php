<?php

declare(strict_types=1);

/**
 * SolrWarmupJob Unit Tests
 *
 * Tests the one-time queued background job that warms up the SOLR index after imports.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\SolrWarmupJob;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\IndexService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Test class for SolrWarmupJob
 */
class SolrWarmupJobTest extends TestCase
{
    private IndexService&MockObject $indexService;
    private SchemaMapper&MockObject $schemaMapper;
    private LoggerInterface&MockObject $logger;
    private SolrWarmupJob $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->indexService = $this->createMock(IndexService::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        // Register mocks in the Nextcloud DI container.
        \OC::$server->registerService(IndexService::class, function () {
            return $this->indexService;
        });
        \OC::$server->registerService(SchemaMapper::class, function () {
            return $this->schemaMapper;
        });
        \OC::$server->registerService(LoggerInterface::class, function () {
            return $this->logger;
        });

        $timeFactory = $this->createMock(ITimeFactory::class);
        $this->job   = new SolrWarmupJob($timeFactory);
    }

    /**
     * Invoke the protected run() method via reflection.
     *
     * @param array<string, mixed> $argument
     */
    private function runJob(array $argument = []): void
    {
        $ref    = new ReflectionClass($this->job);
        $method = $ref->getMethod('run');
        $method->setAccessible(true);
        $method->invoke($this->job, $argument);
    }

    // -------------------------------------------------------------------------
    // Early exit: SOLR not available
    // -------------------------------------------------------------------------

    public function testRunSkipsWhenSolrNotAvailable(): void
    {
        $this->indexService
            ->method('isAvailable')
            ->willReturn(false);

        $this->schemaMapper
            ->expects($this->never())
            ->method('findAll');

        $this->indexService
            ->expects($this->never())
            ->method('warmupIndex');

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('warning');

        $this->runJob();
    }

    // -------------------------------------------------------------------------
    // Argument defaults
    // -------------------------------------------------------------------------

    public function testRunUsesDefaultArguments(): void
    {
        $this->indexService->method('isAvailable')->willReturn(true);
        $this->schemaMapper->method('findAll')->willReturn([]);

        // positional: schemas, maxObjects, mode, collectErrors
        $this->indexService
            ->expects($this->once())
            ->method('warmupIndex')
            ->with([], 5000, 'serial', false)
            ->willReturn(['success' => true, 'operations' => []]);

        $this->runJob([]);
    }

    public function testRunRespectsCustomArguments(): void
    {
        $this->indexService->method('isAvailable')->willReturn(true);
        $this->schemaMapper->method('findAll')->willReturn([]);

        $this->indexService
            ->expects($this->once())
            ->method('warmupIndex')
            ->with([], 100, 'parallel', true)
            ->willReturn(['success' => true, 'operations' => []]);

        $this->runJob([
            'maxObjects'    => 100,
            'mode'          => 'parallel',
            'collectErrors' => true,
            'triggeredBy'   => 'import',
        ]);
    }

    // -------------------------------------------------------------------------
    // Happy path — success result
    // -------------------------------------------------------------------------

    public function testRunLogsSuccessWhenWarmupSucceeds(): void
    {
        $this->indexService->method('isAvailable')->willReturn(true);
        $this->schemaMapper->method('findAll')->willReturn([]);

        $this->indexService->method('warmupIndex')->willReturn([
            'success'           => true,
            'operations'        => ['objects_indexed' => 50, 'schemas_processed' => 3, 'fields_created' => 10],
            'execution_time_ms' => 1200,
        ]);

        $infoMessages = [];
        $this->logger
            ->method('info')
            ->willReturnCallback(static function (string $message) use (&$infoMessages): void {
                $infoMessages[] = $message;
            });

        $this->runJob();

        $found = array_filter($infoMessages, static fn(string $m): bool => str_contains($m, 'Completed Successfully'));
        $this->assertNotEmpty($found, 'Expected success log was not emitted');
    }

    // -------------------------------------------------------------------------
    // Failed warmup result (success === false)
    // -------------------------------------------------------------------------

    public function testRunLogsErrorWhenWarmupReturnsFailure(): void
    {
        $this->indexService->method('isAvailable')->willReturn(true);
        $this->schemaMapper->method('findAll')->willReturn([]);

        $this->indexService->method('warmupIndex')->willReturn([
            'success' => false,
            'error'   => 'SOLR connection refused',
        ]);

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('error');

        $this->runJob();
    }

    // -------------------------------------------------------------------------
    // Exception handling — rethrows
    // -------------------------------------------------------------------------

    public function testRunRethrowsExceptionAndLogsError(): void
    {
        $this->indexService->method('isAvailable')->willReturn(true);
        $this->schemaMapper->method('findAll')->willReturn([]);

        $this->indexService
            ->method('warmupIndex')
            ->willThrowException(new \Exception('SOLR timeout'));

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('error');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('SOLR timeout');

        $this->runJob();
    }

    // -------------------------------------------------------------------------
    // Schema processing
    // -------------------------------------------------------------------------

    public function testRunPassesSchemasToWarmupIndex(): void
    {
        $schema1 = new Schema();
        $schema2 = new Schema();

        $this->indexService->method('isAvailable')->willReturn(true);
        $this->schemaMapper->method('findAll')->willReturn([$schema1, $schema2]);

        $this->indexService
            ->expects($this->once())
            ->method('warmupIndex')
            ->with([$schema1, $schema2], $this->anything(), $this->anything(), $this->anything())
            ->willReturn(['success' => true, 'operations' => []]);

        $this->runJob();
    }

    public function testRunHandlesEmptySchemaList(): void
    {
        $this->indexService->method('isAvailable')->willReturn(true);
        $this->schemaMapper->method('findAll')->willReturn([]);

        $this->indexService
            ->expects($this->once())
            ->method('warmupIndex')
            ->willReturn(['success' => true, 'operations' => ['objects_indexed' => 0]]);

        // Should not throw or log an error.
        $this->runJob();
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // TriggeredBy argument is logged
    // -------------------------------------------------------------------------

    public function testRunIncludesTriggeredByInStartLog(): void
    {
        $this->indexService->method('isAvailable')->willReturn(true);
        $this->schemaMapper->method('findAll')->willReturn([]);
        $this->indexService->method('warmupIndex')->willReturn(['success' => true, 'operations' => []]);

        $startContext = null;
        $this->logger
            ->method('info')
            ->willReturnCallback(static function (string $message, array $context = []) use (&$startContext): void {
                if (str_contains($message, 'Started') && isset($context['triggered_by'])) {
                    $startContext = $context;
                }
            });

        $this->runJob(['triggeredBy' => 'import-service']);

        $this->assertNotNull($startContext);
        $this->assertSame('import-service', $startContext['triggered_by']);
    }
}
