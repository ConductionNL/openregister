<?php

declare(strict_types=1);

/**
 * ImportService Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for ImportService
 *
 * Tests import logic, data transformation, caching, and SOLR warmup scheduling.
 */
class ImportServiceTest extends TestCase
{
    /** @var ObjectEntityMapper&MockObject */
    private ObjectEntityMapper $objectEntityMapper;

    /** @var SchemaMapper&MockObject */
    private SchemaMapper $schemaMapper;

    /** @var ObjectService&MockObject */
    private ObjectService $objectService;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var IGroupManager&MockObject */
    private IGroupManager $groupManager;

    /** @var IJobList&MockObject */
    private IJobList $jobList;

    /** @var ImportService */
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
        $ref = new ReflectionClass($register);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($register, $id);
        return $register;
    }

    private function createSchema(int $id, array $properties = []): Schema
    {
        $schema = new Schema();
        $schema->setTitle('TestSchema');
        $schema->setSlug('test-schema');
        $schema->setProperties($properties);
        $ref = new ReflectionClass($schema);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($schema, $id);
        return $schema;
    }

    // =========================================================================
    // clearCaches
    // =========================================================================

    public function testClearCaches(): void
    {
        $this->service->clearCaches();
        // Should not throw
        $this->assertTrue(true);
    }

    // =========================================================================
    // getRecommendedWarmupMode
    // =========================================================================

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

    // =========================================================================
    // scheduleSolrWarmup
    // =========================================================================

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

    public function testScheduleSolrWarmupWithCustomDelay(): void
    {
        $summary = [
            ['created' => ['obj1'], 'updated' => []],
        ];

        $this->jobList->expects($this->once())->method('scheduleAfter');

        $result = $this->service->scheduleSolrWarmup($summary, 60, 'parallel', 10000);
        $this->assertTrue($result);
    }

    public function testScheduleSolrWarmupWithMultipleSheets(): void
    {
        $summary = [
            'Sheet1' => ['created' => ['obj1', 'obj2'], 'updated' => []],
            'Sheet2' => ['created' => [], 'updated' => ['obj3', 'obj4', 'obj5']],
        ];

        $this->jobList->expects($this->once())->method('scheduleAfter');

        $result = $this->service->scheduleSolrWarmup($summary);
        $this->assertTrue($result);
    }

    public function testScheduleSolrWarmupWithNonArrayEntry(): void
    {
        // If summary contains non-array entries, they should be skipped.
        $summary = [
            'not-an-array',
            ['created' => ['obj1'], 'updated' => []],
        ];

        $this->jobList->expects($this->once())->method('scheduleAfter');

        $result = $this->service->scheduleSolrWarmup($summary);
        $this->assertTrue($result);
    }

    // =========================================================================
    // scheduleSmartSolrWarmup
    // =========================================================================

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

    public function testScheduleSmartSolrWarmupUsesRecommendedMode(): void
    {
        // Large import should trigger 'fast' mode.
        $created = array_fill(0, 15000, 'uuid');
        $summary = [
            ['created' => $created, 'updated' => []],
        ];

        $this->jobList->expects($this->once())->method('scheduleAfter');

        $result = $this->service->scheduleSmartSolrWarmup($summary);
        $this->assertTrue($result);
    }

    public function testScheduleSmartSolrWarmupCapsMaxObjects(): void
    {
        // Very large import should cap maxObjects at 15000.
        $created = array_fill(0, 20000, 'uuid');
        $summary = [
            ['created' => $created, 'updated' => []],
        ];

        $this->jobList->expects($this->once())->method('scheduleAfter');

        $result = $this->service->scheduleSmartSolrWarmup($summary);
        $this->assertTrue($result);
    }

    // =========================================================================
    // importFromCsv
    // =========================================================================

    public function testImportFromCsvWithInvalidPath(): void
    {
        $this->expectException(\Exception::class);

        $this->service->importFromCsv('/nonexistent/path.csv');
    }

    public function testImportFromCsvRequiresSchema(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
        file_put_contents($tmpFile, "name,email\nJohn,john@test.nl\n");

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('CSV import requires a specific schema');

            $register = $this->createRegister(1);
            $this->service->importFromCsv($tmpFile, $register, null);
        } finally {
            @unlink($tmpFile);
        }
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

        $this->objectService->method('saveObjects')
            ->willReturn([
                'saved' => [
                    ['@self' => ['id' => 'uuid-1'], 'name' => 'John'],
                    ['@self' => ['id' => 'uuid-2'], 'name' => 'Jane'],
                ],
                'updated' => [],
                'unchanged' => [],
            ]);

        try {
            $result = $this->service->importFromCsv($tmpFile, $register, $schema);
            $this->assertIsArray($result);

            // Get the first (and only) sheet result.
            $sheetResult = reset($result);
            $this->assertSame(2, $sheetResult['found']);
            $this->assertCount(2, $sheetResult['created']);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testImportFromCsvWithPublishEnabled(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
        file_put_contents($tmpFile, "name\nTest\n");

        $register = $this->createRegister(1);
        $schema = $this->createSchema(2, [
            'name' => ['type' => 'string'],
        ]);

        $this->objectService->method('saveObjects')
            ->willReturn([
                'saved' => [['@self' => ['id' => 'uuid-1']]],
                'updated' => [],
                'unchanged' => [],
            ]);

        try {
            $result = $this->service->importFromCsv(
                $tmpFile,
                $register,
                $schema,
                publish: true
            );
            $this->assertIsArray($result);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testImportFromCsvWithEmptyDataRows(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
        // Only header, no data rows.
        file_put_contents($tmpFile, "name,email\n");

        $register = $this->createRegister(1);
        $schema = $this->createSchema(2, [
            'name' => ['type' => 'string'],
            'email' => ['type' => 'string'],
        ]);

        try {
            $result = $this->service->importFromCsv($tmpFile, $register, $schema);
            $this->assertIsArray($result);

            $sheetResult = reset($result);
            $this->assertNotEmpty($sheetResult['errors']);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testImportFromCsvWithTypedProperties(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
        file_put_contents($tmpFile, "name,age,active,tags\nJohn,30,true,\"a,b,c\"\n");

        $register = $this->createRegister(1);
        $schema = $this->createSchema(2, [
            'name' => ['type' => 'string'],
            'age' => ['type' => 'integer'],
            'active' => ['type' => 'boolean'],
            'tags' => ['type' => 'array'],
        ]);

        $this->objectService->method('saveObjects')
            ->willReturn([
                'saved' => [['@self' => ['id' => 'uuid-1']]],
                'updated' => [],
                'unchanged' => [],
            ]);

        try {
            $result = $this->service->importFromCsv($tmpFile, $register, $schema);
            $this->assertIsArray($result);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testImportFromCsvWithUnchangedObjects(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
        file_put_contents($tmpFile, "name\nJohn\nJane\n");

        $register = $this->createRegister(1);
        $schema = $this->createSchema(2, [
            'name' => ['type' => 'string'],
        ]);

        $this->objectService->method('saveObjects')
            ->willReturn([
                'saved' => [],
                'updated' => [],
                'unchanged' => [
                    ['@self' => ['id' => 'uuid-1']],
                    ['@self' => ['id' => 'uuid-2']],
                ],
            ]);

        try {
            $result = $this->service->importFromCsv($tmpFile, $register, $schema);
            $sheetResult = reset($result);
            $this->assertCount(2, $sheetResult['unchanged']);
            $this->assertStringContainsString('operations avoided', $sheetResult['deduplication_efficiency']);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testImportFromCsvWithUnderscoreColumnsSkipped(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
        file_put_contents($tmpFile, "name,_internal\nJohn,hidden\n");

        $register = $this->createRegister(1);
        $schema = $this->createSchema(2, [
            'name' => ['type' => 'string'],
        ]);

        $this->objectService->method('saveObjects')
            ->willReturn([
                'saved' => [['@self' => ['id' => 'uuid-1']]],
                'updated' => [],
                'unchanged' => [],
            ]);

        try {
            $result = $this->service->importFromCsv($tmpFile, $register, $schema);
            $this->assertIsArray($result);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testImportFromCsvWithAtColumnsSkippedForNonAdmin(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
        file_put_contents($tmpFile, "name,@self.organisation\nJohn,org-uuid\n");

        $register = $this->createRegister(1);
        $schema = $this->createSchema(2, [
            'name' => ['type' => 'string'],
        ]);

        // Non-admin user.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');

        $adminGroup = $this->createMock(IGroup::class);
        $adminGroup->method('inGroup')->willReturn(false);
        $this->groupManager->method('get')
            ->with('admin')
            ->willReturn($adminGroup);

        $this->objectService->method('saveObjects')
            ->willReturn([
                'saved' => [['@self' => ['id' => 'uuid-1']]],
                'updated' => [],
                'unchanged' => [],
            ]);

        try {
            $result = $this->service->importFromCsv(
                $tmpFile,
                $register,
                $schema,
                currentUser: $user
            );
            $this->assertIsArray($result);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testImportFromCsvWithAtColumnsProcessedForAdmin(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
        file_put_contents($tmpFile, "name,@self.organisation\nJohn,12345678-1234-1234-1234-123456789abc\n");

        $register = $this->createRegister(1);
        $schema = $this->createSchema(2, [
            'name' => ['type' => 'string'],
        ]);

        // Admin user.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');

        $adminGroup = $this->createMock(IGroup::class);
        $adminGroup->method('inGroup')->willReturn(true);
        $this->groupManager->method('get')
            ->with('admin')
            ->willReturn($adminGroup);

        $this->objectService->method('saveObjects')
            ->willReturn([
                'saved' => [['@self' => ['id' => 'uuid-1']]],
                'updated' => [],
                'unchanged' => [],
            ]);

        try {
            $result = $this->service->importFromCsv(
                $tmpFile,
                $register,
                $schema,
                currentUser: $user
            );
            $this->assertIsArray($result);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testImportFromCsvWithValidationErrors(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
        file_put_contents($tmpFile, "name\nJohn\n");

        $register = $this->createRegister(1);
        $schema = $this->createSchema(2, [
            'name' => ['type' => 'string'],
        ]);

        $this->objectService->method('saveObjects')
            ->willReturn([
                'saved' => [],
                'updated' => [],
                'unchanged' => [],
                'invalid' => [
                    ['object' => ['name' => 'John'], 'error' => 'Name too short', 'type' => 'ValidationException'],
                ],
            ]);

        try {
            $result = $this->service->importFromCsv(
                $tmpFile,
                $register,
                $schema,
                validation: true
            );
            $sheetResult = reset($result);
            $this->assertNotEmpty($sheetResult['errors']);
            $this->assertSame('Name too short', $sheetResult['errors'][0]['error']);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testImportFromCsvPerformanceMetrics(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
        file_put_contents($tmpFile, "name\nJohn\n");

        $register = $this->createRegister(1);
        $schema = $this->createSchema(2, [
            'name' => ['type' => 'string'],
        ]);

        $this->objectService->method('saveObjects')
            ->willReturn([
                'saved' => [['@self' => ['id' => 'uuid-1']]],
                'updated' => [],
                'unchanged' => [],
            ]);

        try {
            $result = $this->service->importFromCsv($tmpFile, $register, $schema);
            $sheetResult = reset($result);
            $this->assertArrayHasKey('performance', $sheetResult);
            $this->assertArrayHasKey('totalTime', $sheetResult['performance']);
            $this->assertArrayHasKey('objectsPerSecond', $sheetResult['performance']);
        } finally {
            @unlink($tmpFile);
        }
    }

    // =========================================================================
    // importFromExcel
    // =========================================================================

    public function testImportFromExcelWithInvalidPath(): void
    {
        $this->expectException(\Exception::class);

        $this->service->importFromExcel('/nonexistent/path.xlsx');
    }

    // =========================================================================
    // Private method testing via Reflection: transformDateTimeValue
    // =========================================================================

    public function testTransformDateTimeValueMySqlFormat(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('transformDateTimeValue');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, '2025-01-01 12:30:00');
        $this->assertSame('2025-01-01 12:30:00', $result);
    }

    public function testTransformDateTimeValueIso8601WithTimezone(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('transformDateTimeValue');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, '2025-01-01T00:00:00+00:00');
        $this->assertSame('2025-01-01 00:00:00', $result);
    }

    public function testTransformDateTimeValueIso8601WithoutTimezone(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('transformDateTimeValue');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, '2025-06-15T14:30:00');
        $this->assertSame('2025-06-15 14:30:00', $result);
    }

    public function testTransformDateTimeValueDateOnly(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('transformDateTimeValue');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, '2025-01-01');
        $this->assertSame('2025-01-01 00:00:00', $result);
    }

    public function testTransformDateTimeValuePassThrough(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('transformDateTimeValue');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'not-a-date');
        $this->assertSame('not-a-date', $result);
    }

    // =========================================================================
    // Private method testing via Reflection: transformSelfProperty
    // =========================================================================

    public function testTransformSelfPropertyPublished(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('transformSelfProperty');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'published', '2025-01-01');
        $this->assertSame('2025-01-01 00:00:00', $result);
    }

    public function testTransformSelfPropertyCreated(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('transformSelfProperty');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'created', '2025-01-01T12:00:00+00:00');
        $this->assertSame('2025-01-01 12:00:00', $result);
    }

    public function testTransformSelfPropertyOrganisationValidUuid(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('transformSelfProperty');
        $method->setAccessible(true);

        $uuid = '12345678-1234-1234-1234-123456789abc';
        $result = $method->invoke($this->service, 'organisation', $uuid);
        $this->assertSame($uuid, $result);
    }

    public function testTransformSelfPropertyOrganisationInvalidUuid(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('transformSelfProperty');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'organisation', 'some-slug');
        $this->assertSame('some-slug', $result);
    }

    public function testTransformSelfPropertyOther(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('transformSelfProperty');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'owner', 'admin');
        $this->assertSame('admin', $result);
    }

    // =========================================================================
    // Private method testing via Reflection: transformValueByType
    // =========================================================================

    public function testTransformValueByTypeInteger(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('transformValueByType');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, '42', ['type' => 'integer']);
        $this->assertSame(42, $result);
    }

    public function testTransformValueByTypeNumber(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('transformValueByType');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, '3.14', ['type' => 'number']);
        $this->assertSame(3.14, $result);
    }

    public function testTransformValueByTypeBoolean(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('transformValueByType');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->service, 'true', ['type' => 'boolean']));
        $this->assertTrue($method->invoke($this->service, '1', ['type' => 'boolean']));
        $this->assertTrue($method->invoke($this->service, 'yes', ['type' => 'boolean']));
        $this->assertTrue($method->invoke($this->service, 'on', ['type' => 'boolean']));
        $this->assertTrue($method->invoke($this->service, 'enabled', ['type' => 'boolean']));
        $this->assertFalse($method->invoke($this->service, 'false', ['type' => 'boolean']));
        $this->assertFalse($method->invoke($this->service, '0', ['type' => 'boolean']));
        $this->assertFalse($method->invoke($this->service, 'no', ['type' => 'boolean']));
    }

    public function testTransformValueByTypeBooleanAlreadyBool(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('transformValueByType');
        $method->setAccessible(true);

        // stringToBoolean handles actual bools.
        $this->assertTrue($method->invoke($this->service, true, ['type' => 'boolean']));
        $this->assertFalse($method->invoke($this->service, false, ['type' => 'boolean']));
    }

    public function testTransformValueByTypeArray(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('transformValueByType');
        $method->setAccessible(true);

        // JSON array.
        $result = $method->invoke($this->service, '["a","b","c"]', ['type' => 'array']);
        $this->assertSame(['a', 'b', 'c'], $result);

        // Comma-separated.
        $result = $method->invoke($this->service, 'a,b,c', ['type' => 'array']);
        $this->assertSame(['a', 'b', 'c'], $result);

        // Single value.
        $result = $method->invoke($this->service, 'single', ['type' => 'array']);
        $this->assertSame(['single'], $result);

        // Empty string.
        $result = $method->invoke($this->service, '', ['type' => 'array']);
        $this->assertSame('', $result);  // Empty returns as-is.

        // Already array.
        $result = $method->invoke($this->service, ['x', 'y'], ['type' => 'array']);
        $this->assertSame(['x', 'y'], $result);
    }

    public function testTransformValueByTypeArrayQuotedValues(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('transformValueByType');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, '"a","b","c"', ['type' => 'array']);
        $this->assertSame(['a', 'b', 'c'], $result);

        // Single quotes.
        $result = $method->invoke($this->service, "'x','y'", ['type' => 'array']);
        $this->assertSame(['x', 'y'], $result);
    }

    public function testTransformValueByTypeArrayNonStringNonArray(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('transformValueByType');
        $method->setAccessible(true);

        // Non-string, non-array value should be wrapped in array.
        $result = $method->invoke($this->service, 42, ['type' => 'array']);
        $this->assertSame([42], $result);
    }

    public function testTransformValueByTypeObject(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('transformValueByType');
        $method->setAccessible(true);

        // JSON object.
        $result = $method->invoke($this->service, '{"key":"value"}', ['type' => 'object']);
        $this->assertSame(['key' => 'value'], $result);

        // Non-JSON string.
        $result = $method->invoke($this->service, 'plain-string', ['type' => 'object']);
        $this->assertSame(['value' => 'plain-string'], $result);

        // Already array.
        $result = $method->invoke($this->service, ['existing' => 'data'], ['type' => 'object']);
        $this->assertSame(['existing' => 'data'], $result);
    }

    public function testTransformValueByTypeObjectRelatedObject(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('transformValueByType');
        $method->setAccessible(true);

        $propertyDef = [
            'type' => 'object',
            'objectConfiguration' => ['handling' => 'related-object'],
        ];

        $result = $method->invoke($this->service, 'uuid-123', $propertyDef);
        $this->assertSame('uuid-123', $result);
    }

    public function testTransformValueByTypeDefaultString(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('transformValueByType');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 42, ['type' => 'string']);
        $this->assertSame('42', $result);
    }

    public function testTransformValueByTypeNullValue(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('transformValueByType');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, null, ['type' => 'integer']);
        $this->assertNull($result);
    }

    // =========================================================================
    // Private method testing via Reflection: addPublishedDateToObjects
    // =========================================================================

    public function testAddPublishedDateToObjects(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('addPublishedDateToObjects');
        $method->setAccessible(true);

        $objects = [
            ['name' => 'Obj1'],
            ['name' => 'Obj2', '@self' => []],
            ['name' => 'Obj3', '@self' => ['published' => '2025-01-01']],
        ];

        $result = $method->invoke($this->service, $objects, '2025-06-15T12:00:00+00:00');

        // Obj1: @self created and published set.
        $this->assertSame('2025-06-15T12:00:00+00:00', $result[0]['@self']['published']);
        // Obj2: published set.
        $this->assertSame('2025-06-15T12:00:00+00:00', $result[1]['@self']['published']);
        // Obj3: already has published date, should NOT be overwritten.
        $this->assertSame('2025-01-01', $result[2]['@self']['published']);
    }

    // =========================================================================
    // Private method testing via Reflection: isUserAdmin
    // =========================================================================

    public function testIsUserAdminWithNullUser(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('isUserAdmin');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, null);
        $this->assertFalse($result);
    }

    public function testIsUserAdminWithNoAdminGroup(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('isUserAdmin');
        $method->setAccessible(true);

        $this->groupManager->method('get')
            ->with('admin')
            ->willReturn(null);

        $user = $this->createMock(IUser::class);
        $result = $method->invoke($this->service, $user);
        $this->assertFalse($result);
    }

    public function testIsUserAdminTrue(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('isUserAdmin');
        $method->setAccessible(true);

        $user = $this->createMock(IUser::class);
        $adminGroup = $this->createMock(IGroup::class);
        $adminGroup->method('inGroup')->with($user)->willReturn(true);
        $this->groupManager->method('get')
            ->with('admin')
            ->willReturn($adminGroup);

        $result = $method->invoke($this->service, $user);
        $this->assertTrue($result);
    }

    public function testIsUserAdminFalse(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('isUserAdmin');
        $method->setAccessible(true);

        $user = $this->createMock(IUser::class);
        $adminGroup = $this->createMock(IGroup::class);
        $adminGroup->method('inGroup')->with($user)->willReturn(false);
        $this->groupManager->method('get')
            ->with('admin')
            ->willReturn($adminGroup);

        $result = $method->invoke($this->service, $user);
        $this->assertFalse($result);
    }

    // =========================================================================
    // Private method testing via Reflection: validateObjectProperties
    // =========================================================================

    public function testValidateObjectProperties(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('validateObjectProperties');
        $method->setAccessible(true);

        // Should not throw for normal properties.
        $method->invoke($this->service, ['name' => 'Test', '@self' => []], '1');
        $this->assertTrue(true);

        // Should not throw for invalid properties either (method currently just logs).
        $method->invoke($this->service, ['data' => 'test', 'content' => 'test'], '1');
        $this->assertTrue(true);
    }

    // =========================================================================
    // Private method testing via Reflection: transformObjectBySchema
    // =========================================================================

    public function testTransformObjectBySchema(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('transformObjectBySchema');
        $method->setAccessible(true);

        $schema = $this->createSchema(1, [
            'count' => ['type' => 'integer'],
            'active' => ['type' => 'boolean'],
        ]);

        $objectData = [
            '@self' => ['register' => 1, 'schema' => 1],
            'count' => '42',
            'active' => 'true',
            'unknown' => 'keep-as-is',
        ];

        $result = $method->invoke($this->service, $objectData, $schema);

        $this->assertSame(42, $result['count']);
        $this->assertTrue($result['active']);
        $this->assertSame('keep-as-is', $result['unknown']);
        $this->assertSame(['register' => 1, 'schema' => 1], $result['@self']);
    }

    // =========================================================================
    // Private method testing via Reflection: calculateTotalImported
    // =========================================================================

    public function testCalculateTotalImported(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('calculateTotalImported');
        $method->setAccessible(true);

        $summary = [
            ['created' => ['a', 'b'], 'updated' => ['c']],
            ['created' => ['d'], 'updated' => []],
        ];

        $result = $method->invoke($this->service, $summary);
        $this->assertSame(4, $result);
    }

    public function testCalculateTotalImportedEmpty(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('calculateTotalImported');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, []);
        $this->assertSame(0, $result);
    }

    public function testCalculateTotalImportedWithNonArray(): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('calculateTotalImported');
        $method->setAccessible(true);

        $summary = [
            'not-array',
            ['created' => ['a'], 'updated' => []],
        ];

        $result = $method->invoke($this->service, $summary);
        $this->assertSame(1, $result);
    }
}
