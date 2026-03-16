<?php

namespace Unit\Service;

use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImportServiceTest extends TestCase
{
    private ObjectEntityMapper&MockObject $objectEntityMapper;
    private SchemaMapper&MockObject $schemaMapper;
    private ObjectService&MockObject $objectService;
    private LoggerInterface&MockObject $logger;
    private IGroupManager&MockObject $groupManager;
    private IJobList&MockObject $jobList;
    private ImportService $service;

    protected function setUp(): void
    {
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->jobList = $this->createMock(IJobList::class);

        $this->service = new ImportService(
            $this->objectEntityMapper,
            $this->schemaMapper,
            $this->objectService,
            $this->logger,
            $this->groupManager,
            $this->jobList
        );
    }

    private function createRegister(int $id): Register
    {
        $register = new Register();
        $register->setTitle('TestRegister');
        $ref = new \ReflectionClass($register);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($register, $id);
        return $register;
    }

    private function createSchema(int $id, array $properties = []): Schema
    {
        $schema = new Schema();
        $schema->setTitle('TestSchema');
        $schema->setProperties($properties);
        $ref = new \ReflectionClass($schema);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($schema, $id);
        return $schema;
    }

    public function testClearCaches(): void
    {
        $this->service->clearCaches();
        // Should not throw
        $this->assertTrue(true);
    }

    public function testGetRecommendedWarmupModeSmall(): void
    {
        $result = $this->service->getRecommendedWarmupMode(5);
        $this->assertSame('safe', $result);
    }

    public function testGetRecommendedWarmupModeMedium(): void
    {
        $result = $this->service->getRecommendedWarmupMode(5000);
        $this->assertSame('balanced', $result);
    }

    public function testGetRecommendedWarmupModeLarge(): void
    {
        $result = $this->service->getRecommendedWarmupMode(20000);
        $this->assertSame('fast', $result);
    }

    public function testGetRecommendedWarmupModeZero(): void
    {
        $result = $this->service->getRecommendedWarmupMode(0);
        $this->assertSame('safe', $result);
    }

    public function testGetRecommendedWarmupModeBoundary1000(): void
    {
        $result = $this->service->getRecommendedWarmupMode(1000);
        $this->assertSame('safe', $result);
    }

    public function testGetRecommendedWarmupModeBoundary1001(): void
    {
        $result = $this->service->getRecommendedWarmupMode(1001);
        $this->assertSame('balanced', $result);
    }

    public function testGetRecommendedWarmupModeBoundary10000(): void
    {
        $result = $this->service->getRecommendedWarmupMode(10000);
        $this->assertSame('balanced', $result);
    }

    public function testGetRecommendedWarmupModeBoundary10001(): void
    {
        $result = $this->service->getRecommendedWarmupMode(10001);
        $this->assertSame('fast', $result);
    }

    public function testScheduleSolrWarmupWithData(): void
    {
        $summary = [
            ['created' => ['obj1', 'obj2'], 'updated' => ['obj3']],
        ];

        $this->jobList->expects($this->once())->method('scheduleAfter');

        $result = $this->service->scheduleSolrWarmup($summary);

        $this->assertTrue($result);
    }

    public function testScheduleSolrWarmupWithNoImportedObjects(): void
    {
        $summary = [
            ['created' => [], 'updated' => []],
        ];

        $this->jobList->expects($this->never())->method('scheduleAfter');

        $result = $this->service->scheduleSolrWarmup($summary);

        $this->assertFalse($result);
    }

    public function testScheduleSolrWarmupEmptySummary(): void
    {
        $result = $this->service->scheduleSolrWarmup([]);

        $this->assertFalse($result);
    }

    public function testScheduleSolrWarmupHandlesException(): void
    {
        $summary = [
            ['created' => ['obj1'], 'updated' => []],
        ];

        $this->jobList->method('scheduleAfter')
            ->willThrowException(new \Exception('Job scheduling failed'));

        $this->logger->expects($this->atLeastOnce())->method('error');

        $result = $this->service->scheduleSolrWarmup($summary);

        $this->assertFalse($result);
    }

    public function testScheduleSmartSolrWarmupWithData(): void
    {
        $summary = [
            ['created' => ['obj1', 'obj2'], 'updated' => []],
        ];

        $this->jobList->expects($this->once())->method('scheduleAfter');

        $result = $this->service->scheduleSmartSolrWarmup($summary);

        $this->assertTrue($result);
    }

    public function testScheduleSmartSolrWarmupEmpty(): void
    {
        $result = $this->service->scheduleSmartSolrWarmup([]);

        $this->assertFalse($result);
    }

    public function testScheduleSmartSolrWarmupImmediate(): void
    {
        $summary = [
            ['created' => ['obj1'], 'updated' => []],
        ];

        $this->jobList->expects($this->once())->method('scheduleAfter');

        $result = $this->service->scheduleSmartSolrWarmup($summary, true);

        $this->assertTrue($result);
    }

    public function testImportFromExcelWithInvalidPath(): void
    {
        $this->expectException(\Exception::class);

        $this->service->importFromExcel('/nonexistent/path.xlsx');
    }

    public function testImportFromCsvWithInvalidPath(): void
    {
        $this->expectException(\Exception::class);

        $this->service->importFromCsv('/nonexistent/path.csv');
    }

    public function testImportFromCsvWithValidFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
        file_put_contents($tmpFile, "name,email\nJohn,john@test.nl\nJane,jane@test.nl\n");

        $register = $this->createRegister(1);
        $schema = $this->createSchema(2, [
            'name' => ['type' => 'string'],
            'email' => ['type' => 'string'],
        ]);

        $this->objectService->method('saveObject')->willReturnCallback(
            function () {
                $entity = new \OCA\OpenRegister\Db\ObjectEntity();
                $entity->setObject(['name' => 'test']);
                $entity->setUuid('test-uuid');
                return $entity;
            }
        );

        try {
            $result = $this->service->importFromCsv($tmpFile, $register, $schema);
            $this->assertIsArray($result);
        } finally {
            @unlink($tmpFile);
        }
    }
}
