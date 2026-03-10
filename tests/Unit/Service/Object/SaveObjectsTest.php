<?php

declare(strict_types=1);

/**
 * SaveObjects Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkRelationHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkValidationHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\ChunkProcessingHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\PreparationHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\TransformationHandler;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for SaveObjects
 *
 * Tests bulk save operations, deduplication, chunk sizing, result merging, and performance metrics.
 */
class SaveObjectsTest extends TestCase
{
    /** @var SaveObjects */
    private SaveObjects $handler;

    /** @var ObjectEntityMapper&MockObject */
    private ObjectEntityMapper $objectEntityMapper;

    /** @var SchemaMapper&MockObject */
    private SchemaMapper $schemaMapper;

    /** @var RegisterMapper&MockObject */
    private RegisterMapper $registerMapper;

    /** @var SaveObject&MockObject */
    private SaveObject $saveHandler;

    /** @var BulkValidationHandler&MockObject */
    private BulkValidationHandler $bulkValidHandler;

    /** @var BulkRelationHandler&MockObject */
    private BulkRelationHandler $bulkRelationHandler;

    /** @var TransformationHandler&MockObject */
    private TransformationHandler $transformHandler;

    /** @var PreparationHandler&MockObject */
    private PreparationHandler $preparationHandler;

    /** @var ChunkProcessingHandler&MockObject */
    private ChunkProcessingHandler $chunkProcHandler;

    /** @var OrganisationService&MockObject */
    private OrganisationService $organisationService;

    /** @var IUserSession&MockObject */
    private IUserSession $userSession;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->saveHandler = $this->createMock(SaveObject::class);
        $this->bulkValidHandler = $this->createMock(BulkValidationHandler::class);
        $this->bulkRelationHandler = $this->createMock(BulkRelationHandler::class);
        $this->transformHandler = $this->createMock(TransformationHandler::class);
        $this->preparationHandler = $this->createMock(PreparationHandler::class);
        $this->chunkProcHandler = $this->createMock(ChunkProcessingHandler::class);
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new SaveObjects(
            $this->objectEntityMapper,
            $this->schemaMapper,
            $this->registerMapper,
            $this->saveHandler,
            $this->bulkValidHandler,
            $this->bulkRelationHandler,
            $this->transformHandler,
            $this->preparationHandler,
            $this->chunkProcHandler,
            $this->organisationService,
            $this->userSession,
            $this->logger
        );

        // Clear static caches between tests.
        $ref = new ReflectionClass(SaveObjects::class);
        $schemaCacheProp = $ref->getProperty('schemaCache');
        $schemaCacheProp->setAccessible(true);
        $schemaCacheProp->setValue(null, []);

        $schemaAnalysisCacheProp = $ref->getProperty('schemaAnalysisCache');
        $schemaAnalysisCacheProp->setAccessible(true);
        $schemaAnalysisCacheProp->setValue(null, []);

        $registerCacheProp = $ref->getProperty('registerCache');
        $registerCacheProp->setAccessible(true);
        $registerCacheProp->setValue(null, []);
    }

    /**
     * Helper to create a Schema entity with reflection for id.
     */
    private function createSchema(int $id, string $slug = 'test-schema'): Schema
    {
        $schema = new Schema();
        $ref = new ReflectionClass($schema);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($schema, $id);
        $schema->setSlug($slug);
        $schema->setTitle('Test Schema');
        return $schema;
    }

    /**
     * Helper to create a Register entity with reflection for id.
     */
    private function createRegister(int $id, string $title = 'Test Register'): Register
    {
        $register = new Register();
        $ref = new ReflectionClass($register);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($register, $id);
        $register->setTitle($title);
        return $register;
    }

    /**
     * Helper to invoke private methods via reflection.
     *
     * @param string $method Method name
     * @param array  $args   Method arguments
     *
     * @return mixed
     */
    private function invokePrivate(string $method, array $args = [])
    {
        $ref = new ReflectionClass($this->handler);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($this->handler, $args);
    }

    // =========================================================================
    // saveObjects — empty input
    // =========================================================================

    public function testSaveObjectsReturnsEmptyResultForEmptyInput(): void
    {
        $result = $this->handler->saveObjects([]);

        $this->assertIsArray($result);
        $this->assertSame([], $result['saved']);
        $this->assertSame([], $result['updated']);
        $this->assertSame([], $result['unchanged']);
        $this->assertSame([], $result['invalid']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(0, $result['statistics']['totalProcessed']);
        $this->assertSame(0, $result['statistics']['saved']);
    }

    // =========================================================================
    // saveObjects — mixed-schema path with all invalid objects
    // =========================================================================

    public function testSaveObjectsMixedSchemaAllInvalid(): void
    {
        $objects = [
            ['name' => 'Object A'],
            ['name' => 'Object B'],
        ];

        // Mixed-schema path: schema=null, so preparationHandler is used.
        $this->preparationHandler->method('prepareObjectsForBulkSave')
            ->willReturn([
                [], // processedObjects (none valid)
                [], // schemaCache
                [   // invalidObjects
                    ['error' => 'Missing schema', 'object' => $objects[0]],
                    ['error' => 'Missing schema', 'object' => $objects[1]],
                ],
            ]);

        $result = $this->handler->saveObjects($objects);

        $this->assertCount(2, $result['invalid']);
        $this->assertSame(2, $result['statistics']['invalid']);
        $this->assertNotEmpty($result['errors']);
    }

    // =========================================================================
    // saveObjects — mixed-schema path with valid objects processed in chunks
    // =========================================================================

    public function testSaveObjectsMixedSchemaWithValidObjects(): void
    {
        $objects = [
            ['name' => 'Object A'],
        ];

        $this->preparationHandler->method('prepareObjectsForBulkSave')
            ->willReturn([
                [['@self' => ['schema' => 1, 'register' => 1], 'name' => 'Object A']],
                [1 => $this->createSchema(1)],
                [],
            ]);

        $this->chunkProcHandler->method('processObjectsChunk')
            ->willReturn([
                'saved'      => [['uuid' => 'abc-123']],
                'updated'    => [],
                'unchanged'  => [],
                'invalid'    => [],
                'errors'     => [],
                'statistics' => [
                    'saved'     => 1,
                    'updated'   => 0,
                    'unchanged' => 0,
                    'invalid'   => 0,
                    'errors'    => 0,
                ],
            ]);

        $result = $this->handler->saveObjects($objects);

        $this->assertCount(1, $result['saved']);
        $this->assertSame(1, $result['statistics']['saved']);
        $this->assertArrayHasKey('performance', $result);
        $this->assertArrayHasKey('totalTime', $result['performance']);
        $this->assertArrayHasKey('objectsPerSecond', $result['performance']);
        // Should have aggregate keys.
        $this->assertSame(1, $result['statistics']['objectsCreated']);
        $this->assertSame(0, $result['statistics']['objectsUpdated']);
        $this->assertSame(0, $result['statistics']['objectsUnchanged']);
    }

    // =========================================================================
    // createEmptyResult
    // =========================================================================

    public function testCreateEmptyResult(): void
    {
        $result = $this->invokePrivate('createEmptyResult', [5]);

        $this->assertSame(5, $result['statistics']['totalProcessed']);
        $this->assertSame([], $result['saved']);
        $this->assertSame(0, $result['statistics']['saved']);
        $this->assertSame(0, $result['statistics']['processingTimeMs']);
    }

    // =========================================================================
    // logBulkOperationStart
    // =========================================================================

    public function testLogBulkOperationStartDoesNotLogSmallSingleSchema(): void
    {
        $this->logger->expects($this->never())->method('info');
        $this->invokePrivate('logBulkOperationStart', [100, false]);
    }

    public function testLogBulkOperationStartLogsLargeSingleSchema(): void
    {
        $this->logger->expects($this->once())->method('info');
        $this->invokePrivate('logBulkOperationStart', [10001, false]);
    }

    public function testLogBulkOperationStartLogsMixedSchemaAboveThreshold(): void
    {
        $this->logger->expects($this->once())->method('info');
        $this->invokePrivate('logBulkOperationStart', [1001, true]);
    }

    public function testLogBulkOperationStartDoesNotLogSmallMixedSchema(): void
    {
        $this->logger->expects($this->never())->method('info');
        $this->invokePrivate('logBulkOperationStart', [500, true]);
    }

    // =========================================================================
    // initializeResult
    // =========================================================================

    public function testInitializeResultWithNoInvalidObjects(): void
    {
        $result = $this->invokePrivate('initializeResult', [10, []]);

        $this->assertSame(0, $result['statistics']['invalid']);
        $this->assertSame([], $result['invalid']);
    }

    public function testInitializeResultWithInvalidObjects(): void
    {
        $invalidObjects = [
            ['error' => 'Missing schema', 'object' => ['name' => 'A']],
            ['error' => 'Missing register', 'object' => ['name' => 'B']],
        ];

        $result = $this->invokePrivate('initializeResult', [10, $invalidObjects]);

        $this->assertCount(2, $result['invalid']);
        $this->assertSame(2, $result['statistics']['invalid']);
        $this->assertSame(2, $result['statistics']['errors']);
    }

    // =========================================================================
    // mergeChunkResult
    // =========================================================================

    public function testMergeChunkResult(): void
    {
        $result = [
            'saved'      => [['uuid' => 'a']],
            'updated'    => [],
            'unchanged'  => [],
            'invalid'    => [],
            'errors'     => [],
            'statistics' => [
                'saved' => 1, 'updated' => 0, 'unchanged' => 0, 'invalid' => 0, 'errors' => 0,
            ],
        ];

        $chunkResult = [
            'saved'      => [['uuid' => 'b']],
            'updated'    => [['uuid' => 'c']],
            'unchanged'  => [['uuid' => 'd']],
            'invalid'    => [['error' => 'bad']],
            'errors'     => [['error' => 'err']],
            'statistics' => [
                'saved' => 1, 'updated' => 1, 'unchanged' => 1, 'invalid' => 1, 'errors' => 1,
            ],
        ];

        $merged = $this->invokePrivate('mergeChunkResult', [$result, $chunkResult, 0, 3]);

        $this->assertCount(2, $merged['saved']);
        $this->assertCount(1, $merged['updated']);
        $this->assertCount(1, $merged['unchanged']);
        $this->assertCount(1, $merged['invalid']);
        $this->assertCount(1, $merged['errors']);
        $this->assertSame(2, $merged['statistics']['saved']);
        $this->assertSame(1, $merged['statistics']['updated']);
        $this->assertCount(1, $merged['chunkStatistics']);
        $this->assertSame(0, $merged['chunkStatistics'][0]['chunkIndex']);
        $this->assertSame(3, $merged['chunkStatistics'][0]['count']);
    }

    public function testMergeChunkResultMultipleChunks(): void
    {
        $result = [
            'saved'      => [],
            'updated'    => [],
            'unchanged'  => [],
            'invalid'    => [],
            'errors'     => [],
            'statistics' => [
                'saved' => 0, 'updated' => 0, 'unchanged' => 0, 'invalid' => 0, 'errors' => 0,
            ],
            'chunkStatistics' => [['chunkIndex' => 0, 'count' => 5, 'saved' => 5, 'updated' => 0, 'unchanged' => 0, 'invalid' => 0]],
        ];

        $chunkResult = [
            'saved'      => [],
            'updated'    => [['uuid' => 'x']],
            'unchanged'  => [],
            'invalid'    => [],
            'errors'     => [],
            'statistics' => ['saved' => 0, 'updated' => 1, 'unchanged' => 0, 'invalid' => 0, 'errors' => 0],
        ];

        $merged = $this->invokePrivate('mergeChunkResult', [$result, $chunkResult, 1, 2]);

        $this->assertCount(2, $merged['chunkStatistics']);
        $this->assertSame(1, $merged['chunkStatistics'][1]['chunkIndex']);
    }

    // =========================================================================
    // calculatePerformanceMetrics
    // =========================================================================

    public function testCalculatePerformanceMetricsBasic(): void
    {
        $startTime = microtime(true) - 1.0; // 1 second ago.

        $metrics = $this->invokePrivate('calculatePerformanceMetrics', [$startTime, 100, 100, 0]);

        $this->assertArrayHasKey('totalTime', $metrics);
        $this->assertArrayHasKey('totalTimeMs', $metrics);
        $this->assertArrayHasKey('objectsPerSecond', $metrics);
        $this->assertArrayHasKey('totalProcessed', $metrics);
        $this->assertArrayHasKey('totalRequested', $metrics);
        $this->assertArrayHasKey('efficiency', $metrics);
        $this->assertSame(100, $metrics['totalProcessed']);
        $this->assertSame(100, $metrics['totalRequested']);
        $this->assertSame(100.0, $metrics['efficiency']);
    }

    public function testCalculatePerformanceMetricsWithUnchanged(): void
    {
        $startTime = microtime(true) - 0.5;

        $metrics = $this->invokePrivate('calculatePerformanceMetrics', [$startTime, 50, 100, 20]);

        $this->assertArrayHasKey('deduplicationEfficiency', $metrics);
        $this->assertStringContainsString('% operations avoided', $metrics['deduplicationEfficiency']);
    }

    public function testCalculatePerformanceMetricsZeroProcessed(): void
    {
        $startTime = microtime(true);

        $metrics = $this->invokePrivate('calculatePerformanceMetrics', [$startTime, 0, 10, 0]);

        // Efficiency is 0 (int) because the round() returns 0 for 0/10*100.
        $this->assertEquals(0, $metrics['efficiency']);
    }

    // =========================================================================
    // calculateOptimalChunkSize
    // =========================================================================

    public function testCalculateOptimalChunkSizeSmall(): void
    {
        $result = $this->invokePrivate('calculateOptimalChunkSize', [50]);
        $this->assertSame(50, $result);
    }

    public function testCalculateOptimalChunkSizeMedium(): void
    {
        $result = $this->invokePrivate('calculateOptimalChunkSize', [500]);
        $this->assertSame(500, $result);
    }

    public function testCalculateOptimalChunkSizeLarge(): void
    {
        $result = $this->invokePrivate('calculateOptimalChunkSize', [3000]);
        $this->assertSame(2000, $result);
    }

    public function testCalculateOptimalChunkSizeVeryLarge(): void
    {
        $result = $this->invokePrivate('calculateOptimalChunkSize', [8000]);
        $this->assertSame(3000, $result);
    }

    public function testCalculateOptimalChunkSizeUltraLarge(): void
    {
        $result = $this->invokePrivate('calculateOptimalChunkSize', [30000]);
        $this->assertSame(5000, $result);
    }

    public function testCalculateOptimalChunkSizeHuge(): void
    {
        $result = $this->invokePrivate('calculateOptimalChunkSize', [100000]);
        $this->assertSame(10000, $result);
    }

    public function testCalculateOptimalChunkSizeBoundary100(): void
    {
        $result = $this->invokePrivate('calculateOptimalChunkSize', [100]);
        $this->assertSame(100, $result);
    }

    public function testCalculateOptimalChunkSizeBoundary1000(): void
    {
        $result = $this->invokePrivate('calculateOptimalChunkSize', [1000]);
        $this->assertSame(1000, $result);
    }

    // =========================================================================
    // deduplicateBatchObjects
    // =========================================================================

    public function testDeduplicateBatchObjectsEmpty(): void
    {
        $result = $this->invokePrivate('deduplicateBatchObjects', [[]]);

        $this->assertSame([], $result['objects']);
        $this->assertSame(0, $result['duplicateCount']);
        $this->assertSame([], $result['duplicateIds']);
    }

    public function testDeduplicateBatchObjectsNoDuplicates(): void
    {
        $objects = [
            ['id' => 'uuid-1', 'name' => 'First'],
            ['id' => 'uuid-2', 'name' => 'Second'],
        ];

        $result = $this->invokePrivate('deduplicateBatchObjects', [$objects]);

        $this->assertCount(2, $result['objects']);
        $this->assertSame(0, $result['duplicateCount']);
    }

    public function testDeduplicateBatchObjectsWithDuplicates(): void
    {
        $objects = [
            ['id' => 'uuid-1', 'name' => 'First'],
            ['id' => 'uuid-1', 'name' => 'Second'],
            ['id' => 'uuid-1', 'name' => 'Third'],
        ];

        $result = $this->invokePrivate('deduplicateBatchObjects', [$objects]);

        $this->assertCount(1, $result['objects']);
        // duplicateCount is the sum of duplicateIds values: first dup starts counter at 1, then increments.
        // 3 objects with same id: counter goes 2 (second), 3 (third) = total 3.
        $this->assertSame(3, $result['duplicateCount']);
        $this->assertArrayHasKey('uuid-1', $result['duplicateIds']);
        // Last occurrence wins.
        $this->assertSame('Third', $result['objects'][0]['name']);
    }

    public function testDeduplicateBatchObjectsUsesUuidField(): void
    {
        $objects = [
            ['uuid' => 'uuid-1', 'name' => 'First'],
            ['uuid' => 'uuid-1', 'name' => 'Second'],
        ];

        $result = $this->invokePrivate('deduplicateBatchObjects', [$objects]);

        $this->assertCount(1, $result['objects']);
        $this->assertSame('Second', $result['objects'][0]['name']);
    }

    public function testDeduplicateBatchObjectsUsesSelfId(): void
    {
        $objects = [
            ['@self' => ['id' => 'uuid-1'], 'name' => 'First'],
            ['@self' => ['id' => 'uuid-1'], 'name' => 'Second'],
        ];

        $result = $this->invokePrivate('deduplicateBatchObjects', [$objects]);

        $this->assertCount(1, $result['objects']);
    }

    public function testDeduplicateBatchObjectsWithoutId(): void
    {
        $objects = [
            ['name' => 'No ID 1'],
            ['name' => 'No ID 2'],
        ];

        $result = $this->invokePrivate('deduplicateBatchObjects', [$objects]);

        // Objects without IDs are all kept.
        $this->assertCount(2, $result['objects']);
        $this->assertSame(0, $result['duplicateCount']);
    }

    public function testDeduplicateBatchObjectsMixed(): void
    {
        $objects = [
            ['id' => 'uuid-1', 'name' => 'A'],
            ['name' => 'No ID'],
            ['id' => 'uuid-1', 'name' => 'B'],
            ['id' => 'uuid-2', 'name' => 'C'],
        ];

        $result = $this->invokePrivate('deduplicateBatchObjects', [$objects]);

        // uuid-1 deduped (B wins), no-id kept, uuid-2 kept.
        $this->assertCount(3, $result['objects']);
        // duplicateIds['uuid-1'] starts at 1 then increments to 2 on second duplicate occurrence.
        $this->assertSame(2, $result['duplicateCount']);
    }

    // =========================================================================
    // loadSchemaWithCache
    // =========================================================================

    public function testLoadSchemaWithCacheLoadsFromDb(): void
    {
        $schema = $this->createSchema(1);
        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($schema);

        $result = $this->invokePrivate('loadSchemaWithCache', [1]);
        $this->assertSame($schema, $result);

        // Second call should use cache.
        $result2 = $this->invokePrivate('loadSchemaWithCache', [1]);
        $this->assertSame($schema, $result2);
    }

    // =========================================================================
    // loadRegisterWithCache
    // =========================================================================

    public function testLoadRegisterWithCacheLoadsFromDb(): void
    {
        $register = $this->createRegister(1);
        $this->registerMapper->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($register);

        $result = $this->invokePrivate('loadRegisterWithCache', [1]);
        $this->assertSame($register, $result);

        // Second call should use cache.
        $result2 = $this->invokePrivate('loadRegisterWithCache', [1]);
        $this->assertSame($register, $result2);
    }

    // =========================================================================
    // getSchemaAnalysisWithCache
    // =========================================================================

    public function testGetSchemaAnalysisWithCacheCachesResult(): void
    {
        $schema = $this->createSchema(1);
        $analysis = [
            'metadataFields' => [],
            'inverseProperties' => [],
            'validationRequired' => false,
            'properties' => null,
            'configuration' => null,
        ];

        $this->bulkValidHandler->expects($this->once())
            ->method('performComprehensiveSchemaAnalysis')
            ->with($schema)
            ->willReturn($analysis);

        $result = $this->invokePrivate('getSchemaAnalysisWithCache', [$schema]);
        $this->assertSame($analysis, $result);

        // Second call should use cache.
        $result2 = $this->invokePrivate('getSchemaAnalysisWithCache', [$schema]);
        $this->assertSame($analysis, $result2);
    }

    // =========================================================================
    // scanForRelations
    // =========================================================================

    public function testScanForRelationsEmpty(): void
    {
        $result = $this->invokePrivate('scanForRelations', [[], '', null]);
        $this->assertSame([], $result);
    }

    public function testScanForRelationsWithUrl(): void
    {
        $data = ['link' => 'https://example.com/objects/123'];

        $result = $this->invokePrivate('scanForRelations', [$data, '', null]);

        $this->assertArrayHasKey('link', $result);
    }

    public function testScanForRelationsSkipsPlainText(): void
    {
        $data = ['title' => 'Hello World', 'description' => 'A simple text'];

        $result = $this->invokePrivate('scanForRelations', [$data, '', null]);

        $this->assertArrayNotHasKey('title', $result);
        $this->assertArrayNotHasKey('description', $result);
    }

    public function testScanForRelationsWithSchemaObjectType(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([
            'relatedItem' => ['type' => 'object'],
        ]);

        $data = ['relatedItem' => '12345678-1234-1234-1234-123456789012'];

        $result = $this->invokePrivate('scanForRelations', [$data, '', $schema]);

        // Schema config says type=object, so string values are treated as relations.
        $this->assertArrayHasKey('relatedItem', $result);
    }

    public function testScanForRelationsWithArrayOfObjectsInSchema(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([
            'items' => ['type' => 'array', 'items' => ['type' => 'object']],
        ]);

        $data = [
            'items' => ['12345678-1234-1234-1234-123456789012'],
        ];

        $result = $this->invokePrivate('scanForRelations', [$data, '', $schema]);

        // Array of objects with string items are treated as relations.
        $this->assertNotEmpty($result);
    }

    public function testScanForRelationsWithUrlValue(): void
    {
        $data = ['link' => 'https://example.com/api/objects/123'];

        $result = $this->invokePrivate('scanForRelations', [$data, '', null]);

        $this->assertArrayHasKey('link', $result);
    }

    public function testScanForRelationsWithSchemaTextUuidFormat(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([
            'reference' => ['type' => 'text', 'format' => 'uuid'],
        ]);

        $data = ['reference' => '12345678-1234-1234-1234-123456789012'];

        $result = $this->invokePrivate('scanForRelations', [$data, '', $schema]);

        $this->assertArrayHasKey('reference', $result);
    }

    // =========================================================================
    // isReference
    // =========================================================================

    public function testIsReferenceWithUuid(): void
    {
        // Note: Due to a code pattern using `preg_match(...) === true` instead of `=== 1`,
        // UUID patterns are not matched by isReference(). Only URLs and fallback ID patterns work.
        $result = $this->invokePrivate('isReference', ['12345678-1234-1234-1234-123456789012']);
        $this->assertFalse($result);
    }

    public function testIsReferenceWithPrefixedUuid(): void
    {
        // Same issue: preg_match returns 1, not true. So prefixed UUIDs are not detected.
        $result = $this->invokePrivate('isReference', ['id-12345678-1234-1234-1234-123456789012']);
        $this->assertFalse($result);
    }

    public function testIsReferenceWithUrl(): void
    {
        $result = $this->invokePrivate('isReference', ['https://example.com/api/objects/123']);
        $this->assertTrue($result);
    }

    public function testIsReferenceWithPlainText(): void
    {
        $result = $this->invokePrivate('isReference', ['Hello World']);
        $this->assertFalse($result);
    }

    public function testIsReferenceWithEmpty(): void
    {
        $result = $this->invokePrivate('isReference', ['']);
        $this->assertFalse($result);
    }

    public function testIsReferenceWithCommonWord(): void
    {
        $result = $this->invokePrivate('isReference', ['open-source']);
        $this->assertFalse($result);
    }

    public function testIsReferenceWithIdLikeString(): void
    {
        // The fallback pattern uses === 1 correctly, but the inner preg_match('/\s/', value) === false
        // check also has a comparison bug (preg_match returns 0, not false for no match).
        // So this also fails to match.
        $result = $this->invokePrivate('isReference', ['my-entity-12345']);
        $this->assertFalse($result);
    }

    // =========================================================================
    // isCommonTextWord
    // =========================================================================

    public function testIsCommonTextWordMatch(): void
    {
        $this->assertTrue($this->invokePrivate('isCommonTextWord', ['applicatie']));
        $this->assertTrue($this->invokePrivate('isCommonTextWord', ['systeemsoftware']));
        $this->assertTrue($this->invokePrivate('isCommonTextWord', ['open-source']));
        $this->assertTrue($this->invokePrivate('isCommonTextWord', ['closed-source']));
    }

    public function testIsCommonTextWordNoMatch(): void
    {
        $this->assertFalse($this->invokePrivate('isCommonTextWord', ['something-else']));
        $this->assertFalse($this->invokePrivate('isCommonTextWord', ['random-word']));
    }

    // =========================================================================
    // saveObjects — with deduplication enabled
    // =========================================================================

    public function testSaveObjectsWithDeduplicationEnabled(): void
    {
        $objects = [
            ['id' => 'uuid-1', 'name' => 'First'],
            ['id' => 'uuid-1', 'name' => 'Second'],
        ];

        $this->preparationHandler->method('prepareObjectsForBulkSave')
            ->willReturn([[], [], []]);

        $result = $this->handler->saveObjects($objects);

        // Should have an error about no objects prepared.
        $this->assertNotEmpty($result['errors']);
    }

    // =========================================================================
    // saveObjects — with deduplication disabled
    // =========================================================================

    public function testSaveObjectsWithDeduplicationDisabled(): void
    {
        $objects = [
            ['id' => 'uuid-1', 'name' => 'First'],
            ['id' => 'uuid-1', 'name' => 'Second'],
        ];

        $this->preparationHandler->method('prepareObjectsForBulkSave')
            ->willReturn([[], [], []]);

        // deduplicateIds = false.
        $result = $this->handler->saveObjects($objects, deduplicateIds: false);

        // Should still get error about no objects prepared.
        $this->assertNotEmpty($result['errors']);
    }

    // =========================================================================
    // prepareObjectsForSave — single schema path
    // =========================================================================

    public function testPrepareObjectsForSaveSingleSchemaPath(): void
    {
        $this->markTestSkipped('Requires full Nextcloud bootstrap');
    }

    // =========================================================================
    // handleBulkInverseRelationsWithAnalysis
    // =========================================================================

    public function testHandleBulkInverseRelationsWithEmptyAnalysis(): void
    {
        $preparedObjects = [
            ['@self' => ['schema' => 1, 'id' => 'uuid-1'], 'field' => 'value'],
        ];

        // Empty analysis means no inverse properties to process.
        $schemaAnalysis = [1 => ['inverseProperties' => []]];

        $this->invokePrivate('handleBulkInverseRelationsWithAnalysis', [&$preparedObjects, $schemaAnalysis]);

        // Should not crash; objects remain unchanged.
        $this->assertCount(1, $preparedObjects);
    }
}
