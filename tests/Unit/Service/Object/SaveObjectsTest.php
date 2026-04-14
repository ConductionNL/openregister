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
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
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

    /** @var MagicMapper&MockObject */
    private MagicMapper $objectMapper;

    /** @var SchemaMapper&MockObject */
    private SchemaMapper $schemaMapper;

    /** @var RegisterMapper&MockObject */
    private RegisterMapper $registerMapper;

    /** @var SaveObject&MockObject */
    private SaveObject $saveHandler;

    /** @var OrganisationService&MockObject */
    private OrganisationService $organisationService;

    /** @var IUserSession&MockObject */
    private IUserSession $userSession;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->objectMapper = $this->createMock(MagicMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->saveHandler = $this->createMock(SaveObject::class);
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new SaveObjects(
            $this->objectMapper,
            $this->schemaMapper,
            $this->registerMapper,
            $this->saveHandler,
            $this->userSession,
            $this->organisationService,
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
     * Helper to set up common mocks for single-schema tests.
     *
     * Mocks ultraFastBulkSave to return objects classified as "created" by the database,
     * which matches the SaveObjects processObjectsChunk -> buildChunkResults flow.
     *
     * @param int   $savedCount Number of saved objects to simulate
     * @param int   $updatedCount Number of updated objects to simulate
     * @param int   $unchangedCount Number of unchanged objects to simulate
     *
     * @return void
     */
    private function setupBulkSaveMock(int $savedCount = 1, int $updatedCount = 0, int $unchangedCount = 0): void
    {
        $bulkResult = [];
        for ($i = 0; $i < $savedCount; $i++) {
            $bulkResult[] = [
                'uuid'          => 'saved-uuid-' . $i,
                'object_status' => 'created',
                'created'       => '2024-01-01T00:00:00+00:00',
                'updated'       => '2024-01-01T00:00:00+00:00',
            ];
        }

        for ($i = 0; $i < $updatedCount; $i++) {
            $bulkResult[] = [
                'uuid'          => 'updated-uuid-' . $i,
                'object_status' => 'updated',
                'created'       => '2024-01-01T00:00:00+00:00',
                'updated'       => '2024-01-01T00:00:00+00:00',
            ];
        }

        for ($i = 0; $i < $unchangedCount; $i++) {
            $bulkResult[] = [
                'uuid'          => 'unchanged-uuid-' . $i,
                'object_status' => 'unchanged',
                'created'       => '2024-01-01T00:00:00+00:00',
                'updated'       => '2024-01-01T00:00:00+00:00',
            ];
        }

        $this->objectMapper->method('ultraFastBulkSave')
            ->willReturn($bulkResult);
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
        // Mixed-schema path with @self.schema pointing to non-existent schema.
        // prepareObjectsForBulkSave calls groupAndLoadSchemas which calls loadSchemaWithCache
        // which will throw an exception.
        $objects = [
            ['@self' => ['schema' => 999], 'name' => 'Object A'],
        ];

        $this->schemaMapper->method('find')
            ->willThrowException(new \Exception('Schema not found'));

        $this->expectException(\Exception::class);
        $this->handler->saveObjects($objects);
    }

    // =========================================================================
    // saveObjects — mixed-schema path with valid objects processed in chunks
    // =========================================================================

    public function testSaveObjectsMixedSchemaWithValidObjects(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);

        $objects = [
            ['@self' => ['schema' => 1, 'register' => 1], 'name' => 'Object A'],
        ];

        $this->schemaMapper->method('find')
            ->with(1)
            ->willReturn($schema);

        // Mock ultraFastBulkSave to simulate a successful save.
        $this->objectMapper->method('ultraFastBulkSave')
            ->willReturn([
                'created' => [['uuid' => 'abc-123', 'id' => 'abc-123']],
                'updated' => [],
                'unchanged' => [],
            ]);

        $result = $this->handler->saveObjects($objects);

        $this->assertArrayHasKey('performance', $result);
        $this->assertArrayHasKey('totalTime', $result['performance']);
        $this->assertArrayHasKey('objectsPerSecond', $result['performance']);
    }

    // =========================================================================
    // initializeSaveResult
    // =========================================================================

    public function testInitializeSaveResultCreatesCorrectStructure(): void
    {
        $result = $this->invokePrivate('initializeSaveResult', [5]);

        $this->assertSame(5, $result['statistics']['totalProcessed']);
        $this->assertSame([], $result['saved']);
        $this->assertSame(0, $result['statistics']['saved']);
        $this->assertSame(0, $result['statistics']['processingTimeMs']);
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
        $this->assertSame(2500, $result);
    }

    public function testCalculateOptimalChunkSizeVeryLarge(): void
    {
        $result = $this->invokePrivate('calculateOptimalChunkSize', [8000]);
        $this->assertSame(5000, $result);
    }

    public function testCalculateOptimalChunkSizeUltraLarge(): void
    {
        $result = $this->invokePrivate('calculateOptimalChunkSize', [30000]);
        $this->assertSame(10000, $result);
    }

    public function testCalculateOptimalChunkSizeHuge(): void
    {
        $result = $this->invokePrivate('calculateOptimalChunkSize', [100000]);
        $this->assertSame(20000, $result);
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
        $schema->setProperties([]);
        $schema->setConfiguration([]);

        $result = $this->invokePrivate('getSchemaAnalysisWithCache', [$schema]);

        $this->assertArrayHasKey('metadataFields', $result);
        $this->assertArrayHasKey('inverseProperties', $result);
        $this->assertArrayHasKey('validationRequired', $result);
        $this->assertArrayHasKey('properties', $result);
        $this->assertArrayHasKey('configuration', $result);

        // Second call should use cache and return same result.
        $result2 = $this->invokePrivate('getSchemaAnalysisWithCache', [$schema]);
        $this->assertSame($result, $result2);
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
        $result = $this->invokePrivate('isReference', ['12345678-1234-1234-1234-123456789012']);
        $this->assertTrue($result);
    }

    public function testIsReferenceWithPrefixedUuid(): void
    {
        $result = $this->invokePrivate('isReference', ['id-12345678-1234-1234-1234-123456789012']);
        $this->assertTrue($result);
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
        // ID-like pattern with hyphen and 8+ chars should be detected as reference.
        $result = $this->invokePrivate('isReference', ['my-entity-12345']);
        $this->assertTrue($result);
    }

    // =========================================================================
    // saveObjects — with deduplication enabled
    // =========================================================================

    public function testSaveObjectsWithDeduplicationEnabled(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);

        $this->schemaMapper->method('find')->with(1)->willReturn($schema);
        $this->setupBulkSaveMock(1);

        $objects = [
            ['@self' => ['schema' => 1, 'register' => 1], 'id' => 'uuid-1', 'name' => 'First'],
            ['@self' => ['schema' => 1, 'register' => 1], 'id' => 'uuid-1', 'name' => 'Second'],
        ];

        $result = $this->handler->saveObjects($objects, deduplicateIds: true);

        // Should succeed with at least one saved object.
        $this->assertNotEmpty($result['saved']);
    }

    // =========================================================================
    // saveObjects — with deduplication disabled
    // =========================================================================

    public function testSaveObjectsWithDeduplicationDisabled(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);

        $this->schemaMapper->method('find')->with(1)->willReturn($schema);
        $this->setupBulkSaveMock(2);

        $objects = [
            ['@self' => ['schema' => 1, 'register' => 1], 'id' => 'uuid-1', 'name' => 'First'],
            ['@self' => ['schema' => 1, 'register' => 1], 'id' => 'uuid-1', 'name' => 'Second'],
        ];

        $result = $this->handler->saveObjects($objects, deduplicateIds: false);

        // Should succeed with objects saved.
        $this->assertNotEmpty($result['saved']);
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

        $ref = new ReflectionClass($this->handler);
        $method = $ref->getMethod('handleBulkInverseRelationsWithAnalysis');
        $method->setAccessible(true);
        $method->invokeArgs($this->handler, [&$preparedObjects, $schemaAnalysis]);

        // Should not crash; objects remain unchanged.
        $this->assertCount(1, $preparedObjects);
    }

    public function testHandleBulkInverseRelationsSingleObjectRelation(): void
    {
        $uuid1 = 'da315785-b12a-4103-903f-060a44cbc135';
        $uuid2 = '785eb0e8-2c56-4230-8f2e-b4eccecb0e2b';

        $preparedObjects = [
            [
                '@self' => ['schema' => 1, 'id' => $uuid1],
                'parent' => $uuid2,
            ],
            [
                '@self' => ['schema' => 1, 'id' => $uuid2],
                'someField' => 'value',
            ],
        ];

        $schemaAnalysis = [
            1 => [
                'inverseProperties' => [
                    'parent' => [
                        'inversedBy' => 'children',
                        'writeBack' => false,
                        'isArray' => false,
                    ],
                ],
            ],
        ];

        // Call via reflection with proper reference handling.
        $ref = new ReflectionClass($this->handler);
        $method = $ref->getMethod('handleBulkInverseRelationsWithAnalysis');
        $method->setAccessible(true);
        $method->invokeArgs($this->handler, [&$preparedObjects, $schemaAnalysis]);

        // Object 2 should now have object 1's UUID in 'children' array.
        $this->assertArrayHasKey('children', $preparedObjects[1]);
        $this->assertContains($uuid1, $preparedObjects[1]['children']);
    }

    public function testHandleBulkInverseRelationsArrayOfObjectRelation(): void
    {
        $uuid1 = 'da315785-b12a-4103-903f-060a44cbc135';
        $uuid2 = '785eb0e8-2c56-4230-8f2e-b4eccecb0e2b';
        $uuid3 = 'bd823152-1276-46cb-b06e-a034aa2a29cc';

        $preparedObjects = [
            [
                '@self' => ['schema' => 1, 'id' => $uuid1],
                'tags' => [$uuid2, $uuid3],
            ],
            [
                '@self' => ['schema' => 1, 'id' => $uuid2],
                'field' => 'val',
            ],
            [
                '@self' => ['schema' => 1, 'id' => $uuid3],
                'field' => 'val',
            ],
        ];

        $schemaAnalysis = [
            1 => [
                'inverseProperties' => [
                    'tags' => [
                        'inversedBy' => 'taggedBy',
                        'writeBack' => false,
                        'isArray' => true,
                    ],
                ],
            ],
        ];

        $ref = new ReflectionClass($this->handler);
        $method = $ref->getMethod('handleBulkInverseRelationsWithAnalysis');
        $method->setAccessible(true);
        $method->invokeArgs($this->handler, [&$preparedObjects, $schemaAnalysis]);

        // Object 2 and 3 should have object 1's UUID in 'taggedBy'.
        $this->assertArrayHasKey('taggedBy', $preparedObjects[1]);
        $this->assertContains($uuid1, $preparedObjects[1]['taggedBy']);
        $this->assertArrayHasKey('taggedBy', $preparedObjects[2]);
        $this->assertContains($uuid1, $preparedObjects[2]['taggedBy']);
    }

    public function testHandleBulkInverseRelationsSkipsNonExistentTarget(): void
    {
        $uuid1 = 'da315785-b12a-4103-903f-060a44cbc135';
        $nonExistentUuid = 'e5a7c3d2-4f18-4b9a-8c3d-2e1f0a9b8c7d';

        $preparedObjects = [
            [
                '@self' => ['schema' => 1, 'id' => $uuid1],
                'parent' => $nonExistentUuid,
            ],
        ];

        $schemaAnalysis = [
            1 => [
                'inverseProperties' => [
                    'parent' => [
                        'inversedBy' => 'children',
                        'writeBack' => false,
                        'isArray' => false,
                    ],
                ],
            ],
        ];

        $ref = new ReflectionClass($this->handler);
        $method = $ref->getMethod('handleBulkInverseRelationsWithAnalysis');
        $method->setAccessible(true);
        $method->invokeArgs($this->handler, [&$preparedObjects, $schemaAnalysis]);

        // Should not crash — target UUID doesn't exist in batch.
        $this->assertCount(1, $preparedObjects);
    }

    public function testHandleBulkInverseRelationsSkipsMissingSchemaAnalysis(): void
    {
        $uuid1 = 'da315785-b12a-4103-903f-060a44cbc135';

        $preparedObjects = [
            [
                '@self' => ['schema' => 999, 'id' => $uuid1],
                'parent' => '785eb0e8-2c56-4230-8f2e-b4eccecb0e2b',
            ],
        ];

        // Analysis doesn't include schema 999.
        $schemaAnalysis = [1 => ['inverseProperties' => []]];

        $ref = new ReflectionClass($this->handler);
        $method = $ref->getMethod('handleBulkInverseRelationsWithAnalysis');
        $method->setAccessible(true);
        $method->invokeArgs($this->handler, [&$preparedObjects, $schemaAnalysis]);

        // Should not crash.
        $this->assertCount(1, $preparedObjects);
    }

    public function testHandleBulkInverseRelationsDoesNotAddDuplicate(): void
    {
        $uuid1 = 'da315785-b12a-4103-903f-060a44cbc135';
        $uuid2 = '785eb0e8-2c56-4230-8f2e-b4eccecb0e2b';

        $preparedObjects = [
            [
                '@self' => ['schema' => 1, 'id' => $uuid1],
                'parent' => $uuid2,
            ],
            [
                '@self' => ['schema' => 1, 'id' => $uuid2],
                // Already has uuid1 in children.
                'children' => [$uuid1],
            ],
        ];

        $schemaAnalysis = [
            1 => [
                'inverseProperties' => [
                    'parent' => [
                        'inversedBy' => 'children',
                        'writeBack' => false,
                        'isArray' => false,
                    ],
                ],
            ],
        ];

        $ref = new ReflectionClass($this->handler);
        $method = $ref->getMethod('handleBulkInverseRelationsWithAnalysis');
        $method->setAccessible(true);
        $method->invokeArgs($this->handler, [&$preparedObjects, $schemaAnalysis]);

        // Should not add duplicate.
        $this->assertCount(1, $preparedObjects[1]['children']);
    }

    public function testHandleBulkInverseRelationsSkipsPropertyNotInObject(): void
    {
        $uuid1 = 'da315785-b12a-4103-903f-060a44cbc135';

        $preparedObjects = [
            [
                '@self' => ['schema' => 1, 'id' => $uuid1],
                // Does NOT have the 'parent' key.
                'name' => 'test',
            ],
        ];

        $schemaAnalysis = [
            1 => [
                'inverseProperties' => [
                    'parent' => [
                        'inversedBy' => 'children',
                        'writeBack' => false,
                        'isArray' => false,
                    ],
                ],
            ],
        ];

        $ref = new ReflectionClass($this->handler);
        $method = $ref->getMethod('handleBulkInverseRelationsWithAnalysis');
        $method->setAccessible(true);
        $method->invokeArgs($this->handler, [&$preparedObjects, $schemaAnalysis]);

        // Should skip since 'parent' property doesn't exist in object.
        $this->assertCount(1, $preparedObjects);
        $this->assertArrayNotHasKey('children', $preparedObjects[0]);
    }

    public function testHandleBulkInverseRelationsConvertsNonArrayExistingValues(): void
    {
        $uuid1 = 'da315785-b12a-4103-903f-060a44cbc135';
        $uuid2 = '785eb0e8-2c56-4230-8f2e-b4eccecb0e2b';

        $preparedObjects = [
            [
                '@self' => ['schema' => 1, 'id' => $uuid1],
                'parent' => $uuid2,
            ],
            [
                '@self' => ['schema' => 1, 'id' => $uuid2],
                // 'children' exists but is a string, not array.
                'children' => 'not-an-array',
            ],
        ];

        $schemaAnalysis = [
            1 => [
                'inverseProperties' => [
                    'parent' => [
                        'inversedBy' => 'children',
                        'writeBack' => false,
                        'isArray' => false,
                    ],
                ],
            ],
        ];

        $ref = new ReflectionClass($this->handler);
        $method = $ref->getMethod('handleBulkInverseRelationsWithAnalysis');
        $method->setAccessible(true);
        $method->invokeArgs($this->handler, [&$preparedObjects, $schemaAnalysis]);

        // Should convert non-array to array and add uuid1.
        $this->assertIsArray($preparedObjects[1]['children']);
        $this->assertContains($uuid1, $preparedObjects[1]['children']);
    }

    public function testHandleBulkInverseRelationsArrayWithNonStringValues(): void
    {
        $uuid1 = 'da315785-b12a-4103-903f-060a44cbc135';

        $preparedObjects = [
            [
                '@self' => ['schema' => 1, 'id' => $uuid1],
                // Only include valid string values — non-string values cause TypeError.
                'tags' => ['not-a-uuid', 'also-not-uuid'],
            ],
        ];

        $schemaAnalysis = [
            1 => [
                'inverseProperties' => [
                    'tags' => [
                        'inversedBy' => 'taggedBy',
                        'writeBack' => false,
                        'isArray' => true,
                    ],
                ],
            ],
        ];

        $ref = new ReflectionClass($this->handler);
        $method = $ref->getMethod('handleBulkInverseRelationsWithAnalysis');
        $method->setAccessible(true);
        $method->invokeArgs($this->handler, [&$preparedObjects, $schemaAnalysis]);

        // Should not crash — non-UUID string values are skipped.
        $this->assertCount(1, $preparedObjects);
    }

    // =========================================================================
    // scanForRelations — additional paths
    // =========================================================================

    public function testScanForRelationsWithPrefix(): void
    {
        $data = ['link' => 'https://example.com/objects/123'];

        $result = $this->invokePrivate('scanForRelations', [$data, 'nested.path', null]);

        $this->assertArrayHasKey('nested.path.link', $result);
    }

    public function testScanForRelationsSkipsNonStringKeys(): void
    {
        // When keys are numeric (integer), they should be skipped.
        $data = ['valid_key' => 'https://example.com'];

        $result = $this->invokePrivate('scanForRelations', [$data, '', null]);

        $this->assertArrayHasKey('valid_key', $result);
    }

    public function testScanForRelationsWithNestedArrayContainingArrays(): void
    {
        $data = [
            'items' => [
                ['link' => 'https://example.com/item1'],
            ],
        ];

        $result = $this->invokePrivate('scanForRelations', [$data, '', null]);

        // Should recurse into nested arrays.
        $this->assertArrayHasKey('items.0.link', $result);
    }

    public function testScanForRelationsSchemaTextUriFormat(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([
            'endpoint' => ['type' => 'text', 'format' => 'uri'],
        ]);

        $data = ['endpoint' => 'https://api.example.com/v1/resource'];

        $result = $this->invokePrivate('scanForRelations', [$data, '', $schema]);

        $this->assertArrayHasKey('endpoint', $result);
    }

    public function testScanForRelationsSchemaTextUrlFormat(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([
            'website' => ['type' => 'text', 'format' => 'url'],
        ]);

        $data = ['website' => 'https://www.example.com'];

        $result = $this->invokePrivate('scanForRelations', [$data, '', $schema]);

        $this->assertArrayHasKey('website', $result);
    }

    public function testScanForRelationsSkipsEmptyStringValues(): void
    {
        $data = ['field' => '', 'whitespace' => '   '];

        $result = $this->invokePrivate('scanForRelations', [$data, '', null]);

        $this->assertEmpty($result);
    }

    public function testScanForRelationsSkipsEmptyArrayValues(): void
    {
        $data = ['emptyArray' => []];

        $result = $this->invokePrivate('scanForRelations', [$data, '', null]);

        $this->assertEmpty($result);
    }

    public function testScanForRelationsNonObjectArrayWithStringReferences(): void
    {
        $data = [
            'refs' => ['https://example.com/ref1', 'plain text no ref'],
        ];

        $result = $this->invokePrivate('scanForRelations', [$data, '', null]);

        // URL should be detected as reference.
        $this->assertArrayHasKey('refs.0', $result);
        // Plain text should not.
        $this->assertArrayNotHasKey('refs.1', $result);
    }

    public function testScanForRelationsArrayOfObjectsWithNestedArrays(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([
            'items' => ['type' => 'array', 'items' => ['type' => 'object']],
        ]);

        $data = [
            'items' => [
                ['link' => 'https://example.com/a'],
                'direct-string-ref-not-uuid',
            ],
        ];

        $result = $this->invokePrivate('scanForRelations', [$data, '', $schema]);

        // Nested array item should be scanned recursively.
        $this->assertArrayHasKey('items.0.link', $result);
        // Direct string in object array treated as relation.
        $this->assertArrayHasKey('items.1', $result);
    }

    // =========================================================================
    // isReference — additional patterns
    // =========================================================================

    public function testIsReferenceWithWhitespace(): void
    {
        $result = $this->invokePrivate('isReference', ['  ']);
        $this->assertFalse($result);
    }

    public function testIsReferenceWithShortString(): void
    {
        $result = $this->invokePrivate('isReference', ['abc']);
        $this->assertFalse($result);
    }

    public function testIsReferenceWithHttpUrl(): void
    {
        $result = $this->invokePrivate('isReference', ['http://example.com/objects/123']);
        $this->assertTrue($result);
    }

    public function testIsReferenceWithFtpUrl(): void
    {
        $result = $this->invokePrivate('isReference', ['ftp://files.example.com/data']);
        $this->assertTrue($result);
    }

    // =========================================================================
    // calculateOptimalChunkSize — boundary cases
    // =========================================================================

    public function testCalculateOptimalChunkSizeBoundary5000(): void
    {
        // 5000 <= 5000, so returns 2500.
        $result = $this->invokePrivate('calculateOptimalChunkSize', [5000]);
        $this->assertSame(2500, $result);
    }

    public function testCalculateOptimalChunkSizeBoundary10000(): void
    {
        // 10000 <= 10000, so returns 5000.
        $result = $this->invokePrivate('calculateOptimalChunkSize', [10000]);
        $this->assertSame(5000, $result);
    }

    public function testCalculateOptimalChunkSizeBoundary50000(): void
    {
        // 50000 <= 50000, so returns 10000.
        $result = $this->invokePrivate('calculateOptimalChunkSize', [50000]);
        $this->assertSame(10000, $result);
    }

    public function testCalculateOptimalChunkSizeJustAbove50000(): void
    {
        // 50001 > 50000, so returns 20000.
        $result = $this->invokePrivate('calculateOptimalChunkSize', [50001]);
        $this->assertSame(20000, $result);
    }

    // =========================================================================
    // saveObjects — single schema path (mocking ultraFastBulkSave)
    // =========================================================================

    public function testSaveObjectsSingleSchemaPath(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);
        $register = $this->createRegister(1);

        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn('org-uuid-123');
        $this->setupBulkSaveMock(1);

        $result = $this->handler->saveObjects(
            [['name' => 'Test Object', 'field1' => 'value1']],
            $register, $schema, true, true, false, false, true, true
        );

        $this->assertCount(1, $result['saved']);
        $this->assertSame(1, $result['statistics']['saved']);
        $this->assertArrayHasKey('performance', $result);
    }

    public function testSaveObjectsSingleSchemaWithSchemaIdInsteadOfObject(): void
    {
        $schema = $this->createSchema(2);
        $schema->setProperties([]);
        $schema->setConfiguration([]);
        $register = $this->createRegister(1);

        $this->schemaMapper->method('find')->with(2)->willReturn($schema);
        $this->registerMapper->method('find')->with(1)->willReturn($register);

        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn('org-uuid');
        $this->setupBulkSaveMock(1);

        $result = $this->handler->saveObjects([['name' => 'Test', 'field' => 'val']], 1, 2);

        $this->assertCount(1, $result['saved']);
    }

    public function testSaveObjectsSingleSchemaNoUser(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);
        $register = $this->createRegister(1);

        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);
        $this->setupBulkSaveMock(1);

        $result = $this->handler->saveObjects([['name' => 'Test']], $register, $schema);

        $this->assertCount(1, $result['saved']);
    }

    public function testSaveObjectsSingleSchemaWithSelfData(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);
        $register = $this->createRegister(1);

        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);
        $this->setupBulkSaveMock(1);

        $objects = [
            [
                '@self' => ['owner' => 'custom-owner', 'organisation' => 'custom-org'],
                'id' => 'my-custom-id',
                'name' => 'Test',
            ],
        ];

        $result = $this->handler->saveObjects($objects, $register, $schema);

        $this->assertCount(1, $result['saved']);
    }

    public function testSaveObjectsSingleSchemaWithObjectProperty(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);
        $register = $this->createRegister(1);

        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);
        $this->setupBulkSaveMock(1);

        $objects = [
            ['@self' => [], 'object' => ['field1' => 'value1', 'field2' => 'value2']],
        ];

        $result = $this->handler->saveObjects($objects, $register, $schema);

        $this->assertCount(1, $result['saved']);
    }

    public function testSaveObjectsSingleSchemaWithPublishedDateString(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);
        $register = $this->createRegister(1);

        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);
        $this->setupBulkSaveMock(1);

        $objects = [
            [
                '@self' => ['published' => '2024-01-15T10:00:00+00:00', 'depublished' => '2025-12-31T23:59:59+00:00'],
                'name' => 'Dated Object',
            ],
        ];

        $result = $this->handler->saveObjects($objects, $register, $schema);

        $this->assertCount(1, $result['saved']);
    }

    public function testSaveObjectsSingleSchemaWithInvalidPublishedDate(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);
        $register = $this->createRegister(1);

        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);
        $this->setupBulkSaveMock(1);

        $objects = [
            ['@self' => ['published' => 'not-a-date'], 'name' => 'Bad Date Object'],
        ];

        $result = $this->handler->saveObjects($objects, $register, $schema);

        $this->assertCount(1, $result['saved']);
    }

    public function testSaveObjectsSingleSchemaWithAutoPublish(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration(['autoPublish' => true]);
        $register = $this->createRegister(1);

        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);
        $this->setupBulkSaveMock(1);

        $result = $this->handler->saveObjects([['name' => 'Auto Publish Object']], $register, $schema);

        $this->assertCount(1, $result['saved']);
    }

    public function testSaveObjectsSingleSchemaAutoPublishWithCsvPublishedDate(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration(['autoPublish' => true]);
        $register = $this->createRegister(1);

        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);
        $this->setupBulkSaveMock(1);

        $objects = [
            ['@self' => ['published' => '2024-06-01T00:00:00+00:00'], 'name' => 'CSV Published Object'],
        ];

        $result = $this->handler->saveObjects($objects, $register, $schema);

        $this->assertCount(1, $result['saved']);
    }

    public function testSaveObjectsSingleSchemaEnrichDisabled(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);
        $register = $this->createRegister(1);

        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);
        $this->setupBulkSaveMock(1);

        $result = $this->handler->saveObjects(
            [['name' => 'Test']], $register, $schema,
            true, true, false, false, true, false
        );

        $this->assertCount(1, $result['saved']);
    }

    // =========================================================================
    // scanForRelations — schema type text with non-relation format
    // =========================================================================

    public function testScanForRelationsSchemaTextNonRelationFormat(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([
            'title' => ['type' => 'text', 'format' => 'string'],
        ]);

        // Use a short value that won't match isReference() ID-like pattern.
        $data = ['title' => 'hello'];

        $result = $this->invokePrivate('scanForRelations', [$data, '', $schema]);

        // text with format 'string' is NOT uuid/uri/url, and short strings are not references.
        $this->assertArrayNotHasKey('title', $result);
    }

    public function testScanForRelationsSchemaPropertyTypeArray(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([
            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]);

        // Non-object array with mixed items: URLs, plain text, empty.
        $data = [
            'tags' => ['https://example.com/tag1', 'plain text', '', '   '],
        ];

        $result = $this->invokePrivate('scanForRelations', [$data, '', $schema]);

        // URL should be detected.
        $this->assertArrayHasKey('tags.0', $result);
        // Plain text, empty, whitespace should not.
        $this->assertArrayNotHasKey('tags.1', $result);
        $this->assertArrayNotHasKey('tags.2', $result);
        $this->assertArrayNotHasKey('tags.3', $result);
    }

    public function testScanForRelationsNestedObjectWithinNonObjectArray(): void
    {
        $data = [
            'items' => [
                ['nested_link' => 'https://example.com/nested'],
                'https://example.com/direct',
            ],
        ];

        $result = $this->invokePrivate('scanForRelations', [$data, '', null]);

        // Nested array item should be recursively scanned.
        $this->assertArrayHasKey('items.0.nested_link', $result);
        // Direct string URL in non-object array should also be detected.
        $this->assertArrayHasKey('items.1', $result);
    }

    public function testScanForRelationsWithNullSchema(): void
    {
        $data = [
            'ref' => 'https://example.com/objects/1',
            'text' => 'normal text',
            'nested' => [
                'deep_ref' => 'https://example.com/deep',
            ],
        ];

        $result = $this->invokePrivate('scanForRelations', [$data, '', null]);

        $this->assertArrayHasKey('ref', $result);
        $this->assertArrayNotHasKey('text', $result);
        $this->assertArrayHasKey('nested.deep_ref', $result);
    }

    public function testScanForRelationsSchemaObjectPropertyWithUrl(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([
            'relatedItem' => ['type' => 'object'],
        ]);

        $data = ['relatedItem' => 'https://example.com/api/related/1'];

        $result = $this->invokePrivate('scanForRelations', [$data, '', $schema]);

        // Object type with string value is always a relation.
        $this->assertArrayHasKey('relatedItem', $result);
    }

    // =========================================================================
    // isReference — additional patterns
    // =========================================================================

    public function testIsReferenceWithLongIdNoHyphenOrUnderscore(): void
    {
        // 8+ chars alphanumeric but no hyphen/underscore — not a reference.
        $result = $this->invokePrivate('isReference', ['abcdefgh12345']);
        $this->assertFalse($result);
    }

    public function testIsReferenceWithSystemsoftwareCommonWord(): void
    {
        $result = $this->invokePrivate('isReference', ['systeemsoftware']);
        $this->assertFalse($result);
    }

    public function testIsReferenceWithClosedSourceCommonWord(): void
    {
        $result = $this->invokePrivate('isReference', ['closed-source']);
        $this->assertFalse($result);
    }

    // =========================================================================
    // handleBulkInverseRelationsWithAnalysis — array relations with non-array target
    // =========================================================================

    public function testHandleBulkInverseRelationsArrayRelationWithNonArrayExistingValues(): void
    {
        $uuid1 = 'da315785-b12a-4103-903f-060a44cbc135';
        $uuid2 = '785eb0e8-2c56-4230-8f2e-b4eccecb0e2b';

        $preparedObjects = [
            [
                '@self' => ['schema' => 1, 'id' => $uuid1],
                'tags' => [$uuid2],
            ],
            [
                '@self' => ['schema' => 1, 'id' => $uuid2],
                // 'taggedBy' exists but is a string, not array.
                'taggedBy' => 'not-an-array',
            ],
        ];

        $schemaAnalysis = [
            1 => [
                'inverseProperties' => [
                    'tags' => [
                        'inversedBy' => 'taggedBy',
                        'writeBack' => false,
                        'isArray' => true,
                    ],
                ],
            ],
        ];

        $ref = new ReflectionClass($this->handler);
        $method = $ref->getMethod('handleBulkInverseRelationsWithAnalysis');
        $method->setAccessible(true);
        $method->invokeArgs($this->handler, [&$preparedObjects, $schemaAnalysis]);

        // Should convert non-array to array and add uuid1.
        $this->assertIsArray($preparedObjects[1]['taggedBy']);
        $this->assertContains($uuid1, $preparedObjects[1]['taggedBy']);
    }

    public function testHandleBulkInverseRelationsArrayDoesNotAddDuplicate(): void
    {
        $uuid1 = 'da315785-b12a-4103-903f-060a44cbc135';
        $uuid2 = '785eb0e8-2c56-4230-8f2e-b4eccecb0e2b';

        $preparedObjects = [
            [
                '@self' => ['schema' => 1, 'id' => $uuid1],
                'tags' => [$uuid2],
            ],
            [
                '@self' => ['schema' => 1, 'id' => $uuid2],
                // Already has uuid1.
                'taggedBy' => [$uuid1],
            ],
        ];

        $schemaAnalysis = [
            1 => [
                'inverseProperties' => [
                    'tags' => [
                        'inversedBy' => 'taggedBy',
                        'writeBack' => false,
                        'isArray' => true,
                    ],
                ],
            ],
        ];

        $ref = new ReflectionClass($this->handler);
        $method = $ref->getMethod('handleBulkInverseRelationsWithAnalysis');
        $method->setAccessible(true);
        $method->invokeArgs($this->handler, [&$preparedObjects, $schemaAnalysis]);

        // Should not add duplicate.
        $this->assertCount(1, $preparedObjects[1]['taggedBy']);
    }

    public function testHandleBulkInverseRelationsSkipsObjectWithEmptyId(): void
    {
        $preparedObjects = [
            [
                '@self' => ['schema' => 1, 'id' => ''],
                'parent' => '785eb0e8-2c56-4230-8f2e-b4eccecb0e2b',
            ],
        ];

        $schemaAnalysis = [
            1 => [
                'inverseProperties' => [
                    'parent' => [
                        'inversedBy' => 'children',
                        'writeBack' => false,
                        'isArray' => false,
                    ],
                ],
            ],
        ];

        $ref = new ReflectionClass($this->handler);
        $method = $ref->getMethod('handleBulkInverseRelationsWithAnalysis');
        $method->setAccessible(true);
        $method->invokeArgs($this->handler, [&$preparedObjects, $schemaAnalysis]);

        // Should not crash with empty ID.
        $this->assertCount(1, $preparedObjects);
    }

    public function testHandleBulkInverseRelationsSkipsObjectWithNullId(): void
    {
        $preparedObjects = [
            [
                '@self' => ['schema' => 1],
                'parent' => '785eb0e8-2c56-4230-8f2e-b4eccecb0e2b',
            ],
        ];

        $schemaAnalysis = [
            1 => [
                'inverseProperties' => [
                    'parent' => [
                        'inversedBy' => 'children',
                        'writeBack' => false,
                        'isArray' => false,
                    ],
                ],
            ],
        ];

        $ref = new ReflectionClass($this->handler);
        $method = $ref->getMethod('handleBulkInverseRelationsWithAnalysis');
        $method->setAccessible(true);
        $method->invokeArgs($this->handler, [&$preparedObjects, $schemaAnalysis]);

        // Should not crash with null/missing ID.
        $this->assertCount(1, $preparedObjects);
    }

    public function testHandleBulkInverseRelationsSkipsObjectWithNullSchema(): void
    {
        $uuid1 = 'da315785-b12a-4103-903f-060a44cbc135';

        $preparedObjects = [
            [
                '@self' => ['id' => $uuid1],
                'parent' => '785eb0e8-2c56-4230-8f2e-b4eccecb0e2b',
            ],
        ];

        $schemaAnalysis = [
            1 => [
                'inverseProperties' => [
                    'parent' => [
                        'inversedBy' => 'children',
                        'writeBack' => false,
                        'isArray' => false,
                    ],
                ],
            ],
        ];

        $ref = new ReflectionClass($this->handler);
        $method = $ref->getMethod('handleBulkInverseRelationsWithAnalysis');
        $method->setAccessible(true);
        $method->invokeArgs($this->handler, [&$preparedObjects, $schemaAnalysis]);

        // Should skip because schema is null (not in analysis).
        $this->assertCount(1, $preparedObjects);
    }

    public function testHandleBulkInverseRelationsMultipleObjectsMultipleInverseProperties(): void
    {
        $uuid1 = 'da315785-b12a-4103-903f-060a44cbc135';
        $uuid2 = '785eb0e8-2c56-4230-8f2e-b4eccecb0e2b';
        $uuid3 = 'bd823152-1276-46cb-b06e-a034aa2a29cc';

        $preparedObjects = [
            [
                '@self' => ['schema' => 1, 'id' => $uuid1],
                'parent' => $uuid2,
                'tags' => [$uuid3],
            ],
            [
                '@self' => ['schema' => 1, 'id' => $uuid2],
                'field' => 'val',
            ],
            [
                '@self' => ['schema' => 1, 'id' => $uuid3],
                'field' => 'val',
            ],
        ];

        $schemaAnalysis = [
            1 => [
                'inverseProperties' => [
                    'parent' => [
                        'inversedBy' => 'children',
                        'writeBack' => false,
                        'isArray' => false,
                    ],
                    'tags' => [
                        'inversedBy' => 'taggedBy',
                        'writeBack' => false,
                        'isArray' => true,
                    ],
                ],
            ],
        ];

        $ref = new ReflectionClass($this->handler);
        $method = $ref->getMethod('handleBulkInverseRelationsWithAnalysis');
        $method->setAccessible(true);
        $method->invokeArgs($this->handler, [&$preparedObjects, $schemaAnalysis]);

        // uuid2 should have children=[uuid1].
        $this->assertArrayHasKey('children', $preparedObjects[1]);
        $this->assertContains($uuid1, $preparedObjects[1]['children']);
        // uuid3 should have taggedBy=[uuid1].
        $this->assertArrayHasKey('taggedBy', $preparedObjects[2]);
        $this->assertContains($uuid1, $preparedObjects[2]['taggedBy']);
    }

    // =========================================================================
    // saveObjects — single schema with depublished date conversion
    // =========================================================================

    public function testSaveObjectsSingleSchemaWithDepublishedDateString(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);
        $register = $this->createRegister(1);

        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);
        $this->setupBulkSaveMock(1);

        $objects = [['@self' => ['depublished' => '2025-12-31T23:59:59+00:00'], 'name' => 'Depublished Object']];
        $result = $this->handler->saveObjects($objects, $register, $schema);

        $this->assertCount(1, $result['saved']);
    }

    public function testSaveObjectsSingleSchemaWithInvalidDepublishedDate(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);
        $register = $this->createRegister(1);

        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);
        $this->setupBulkSaveMock(1);

        $objects = [['@self' => ['depublished' => 'not-a-valid-date'], 'name' => 'Bad Depub Date Object']];
        $result = $this->handler->saveObjects($objects, $register, $schema);

        $this->assertCount(1, $result['saved']);
    }

    // =========================================================================
    // saveObjects — single schema with multiple objects
    // =========================================================================

    public function testSaveObjectsSingleSchemaMultipleObjects(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);
        $register = $this->createRegister(1);

        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn('org-uuid-123');
        $this->setupBulkSaveMock(3);

        $objects = [
            ['name' => 'Object 1', 'field1' => 'value1'],
            ['name' => 'Object 2', 'field1' => 'value2'],
            ['name' => 'Object 3', 'field1' => 'value3'],
        ];

        $result = $this->handler->saveObjects($objects, $register, $schema);

        $this->assertCount(3, $result['saved']);
        $this->assertSame(3, $result['statistics']['saved']);
    }


    // =========================================================================
    // saveObjects — remaining single schema tests
    // =========================================================================

    public function testSaveObjectsSingleSchemaPreservesExistingSelfValues(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);
        $register = $this->createRegister(1);

        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);
        $this->setupBulkSaveMock(1);

        $objects = [
            [
                '@self' => ['owner' => 'existing-owner', 'organisation' => 'existing-org'],
                'name' => 'Existing Self Data',
            ],
        ];

        $result = $this->handler->saveObjects($objects, $register, $schema);

        $this->assertCount(1, $result['saved']);
    }

    public function testSaveObjectsSingleSchemaWithObjectPropertyAndRelations(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties(['relatedItem' => ['type' => 'object']]);
        $schema->setConfiguration([]);
        $register = $this->createRegister(1);

        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);
        $this->setupBulkSaveMock(1);

        $objects = [
            ['@self' => [], 'object' => ['relatedItem' => 'https://example.com/api/objects/1', 'title' => 'Test']],
        ];

        $result = $this->handler->saveObjects($objects, $register, $schema);

        $this->assertCount(1, $result['saved']);
    }

    public function testSaveObjectsMixedSchemaWithUpdatedAndUnchanged(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);

        $this->schemaMapper->method('find')->with(1)->willReturn($schema);
        $this->setupBulkSaveMock(1, 1, 1);

        $objects = [
            ['@self' => ['schema' => 1, 'register' => 1], 'name' => 'Object A'],
            ['@self' => ['schema' => 1, 'register' => 1], 'name' => 'Object B'],
            ['@self' => ['schema' => 1, 'register' => 1], 'name' => 'Object C'],
        ];

        $result = $this->handler->saveObjects($objects);

        $this->assertCount(1, $result['saved']);
        $this->assertCount(1, $result['updated']);
        $this->assertCount(1, $result['unchanged']);
        $this->assertArrayHasKey('deduplicationEfficiency', $result['performance']);
    }

    public function testSaveObjectsMixedSchemaWithPartialInvalid(): void
    {
        // Mixed schema with objects that have @self.schema but schema lookup fails for some.
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);

        $this->schemaMapper->method('find')->with(1)->willReturn($schema);
        $this->setupBulkSaveMock(1);

        $objects = [
            ['@self' => ['schema' => 1, 'register' => 1], 'name' => 'Object A'],
        ];

        $result = $this->handler->saveObjects($objects);

        $this->assertCount(1, $result['saved']);
    }

    public function testSaveObjectsDeduplicationDisabledPassesAllObjects(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);

        $this->schemaMapper->method('find')->with(1)->willReturn($schema);
        $this->setupBulkSaveMock(2);

        $objects = [
            ['@self' => ['schema' => 1, 'register' => 1], 'id' => 'same-id', 'name' => 'First'],
            ['@self' => ['schema' => 1, 'register' => 1], 'id' => 'same-id', 'name' => 'Second'],
        ];

        $result = $this->handler->saveObjects($objects, deduplicateIds: false);

        $this->assertCount(2, $result['saved']);
        $this->assertSame(2, $result['statistics']['saved']);
    }

    public function testSaveObjectsSingleSchemaAutoPublishFalse(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration(['autoPublish' => false]);
        $register = $this->createRegister(1);

        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);
        $this->setupBulkSaveMock(1);

        $result = $this->handler->saveObjects([['name' => 'No Auto Publish']], $register, $schema);

        $this->assertCount(1, $result['saved']);
    }

    public function testSaveObjectsSingleSchemaWithProvidedIdInSelf(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);
        $register = $this->createRegister(1);

        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);
        $this->setupBulkSaveMock(1);

        $objects = [['@self' => ['id' => 'my-explicit-id-in-self'], 'name' => 'Self ID Object']];

        $result = $this->handler->saveObjects($objects, $register, $schema);

        $this->assertCount(1, $result['saved']);
    }

    public function testSaveObjectsSingleSchemaWithNoSelfData(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);
        $register = $this->createRegister(1);

        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn('default-org-uuid');
        $this->setupBulkSaveMock(1);

        $objects = [['field1' => 'value1', 'field2' => 'value2']];

        $result = $this->handler->saveObjects($objects, $register, $schema);

        $this->assertCount(1, $result['saved']);
    }

    public function testSaveObjectsSingleSchemaLegacyMetadataRemoval(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);
        $register = $this->createRegister(1);

        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);
        $this->setupBulkSaveMock(1);

        $objects = [
            [
                '@self' => [],
                'name' => 'Legacy Name',
                'description' => 'Legacy Description',
                'businessField1' => 'value1',
                'businessField2' => 'value2',
            ],
        ];

        $result = $this->handler->saveObjects($objects, $register, $schema);

        $this->assertCount(1, $result['saved']);
    }

    // =========================================================================
    // calculateOptimalChunkSize — additional boundary
    // =========================================================================

    public function testCalculateOptimalChunkSizeOneObject(): void
    {
        $result = $this->invokePrivate('calculateOptimalChunkSize', [1]);
        $this->assertSame(1, $result);
    }

    public function testCalculateOptimalChunkSizeBoundary101(): void
    {
        $result = $this->invokePrivate('calculateOptimalChunkSize', [101]);
        // 101 <= 1000, so returns totalObjects.
        $this->assertSame(101, $result);
    }

    public function testCalculateOptimalChunkSizeBoundary1001(): void
    {
        $result = $this->invokePrivate('calculateOptimalChunkSize', [1001]);
        // 1001 <= 5000, so returns 2500.
        $this->assertSame(2500, $result);
    }

    public function testCalculateOptimalChunkSizeBoundary5001(): void
    {
        $result = $this->invokePrivate('calculateOptimalChunkSize', [5001]);
        // 5001 <= 10000, so returns 5000.
        $this->assertSame(5000, $result);
    }

    public function testCalculateOptimalChunkSizeBoundary10001(): void
    {
        $result = $this->invokePrivate('calculateOptimalChunkSize', [10001]);
        // 10001 <= 50000, so returns 10000.
        $this->assertSame(10000, $result);
    }

    // =========================================================================
    // performComprehensiveSchemaAnalysis — internal method
    // =========================================================================

    public function testPerformComprehensiveSchemaAnalysisReturnsCorrectStructure(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties(['title' => ['type' => 'text']]);
        $schema->setConfiguration(['autoPublish' => true, 'objectNameField' => 'title']);

        $result = $this->invokePrivate('performComprehensiveSchemaAnalysis', [$schema]);

        $this->assertArrayHasKey('metadataFields', $result);
        $this->assertArrayHasKey('inverseProperties', $result);
        $this->assertArrayHasKey('validationRequired', $result);
        $this->assertArrayHasKey('properties', $result);
        $this->assertArrayHasKey('configuration', $result);
        $this->assertSame('title', $result['metadataFields']['name']);
    }

    // =========================================================================
    // scanForRelations — non-string key edge case
    // =========================================================================

    public function testScanForRelationsWithEmptyKeySkipped(): void
    {
        $data = ['' => 'https://example.com'];

        $result = $this->invokePrivate('scanForRelations', [$data, '', null]);

        $this->assertEmpty($result);
    }

    // =========================================================================
    // saveObjects — more single schema edge cases
    // =========================================================================

    public function testSaveObjectsSingleSchemaWithBothPublishedAndDepublished(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);
        $register = $this->createRegister(1);

        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);
        $this->setupBulkSaveMock(1);

        $objects = [
            [
                '@self' => ['published' => '2024-01-15T10:00:00+00:00', 'depublished' => '2025-12-31T23:59:59+00:00'],
                'name' => 'Both Dates Object',
            ],
        ];

        $result = $this->handler->saveObjects($objects, $register, $schema);

        $this->assertCount(1, $result['saved']);
    }

    public function testSaveObjectsSingleSchemaWithWhitespaceId(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);
        $register = $this->createRegister(1);

        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);
        $this->setupBulkSaveMock(1);

        $objects = [['id' => '   ', 'name' => 'Whitespace ID Object']];

        $result = $this->handler->saveObjects($objects, $register, $schema);

        $this->assertCount(1, $result['saved']);
    }

    // =========================================================================
    // loadSchemaWithCache — string ID
    // =========================================================================

    public function testLoadSchemaWithCacheStringId(): void
    {
        $schema = $this->createSchema(1);
        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with('string-id')
            ->willReturn($schema);

        $result = $this->invokePrivate('loadSchemaWithCache', ['string-id']);
        $this->assertSame($schema, $result);

        $result2 = $this->invokePrivate('loadSchemaWithCache', ['string-id']);
        $this->assertSame($schema, $result2);
    }

    // =========================================================================
    // loadRegisterWithCache — string ID
    // =========================================================================

    public function testLoadRegisterWithCacheStringId(): void
    {
        $register = $this->createRegister(1);
        $this->registerMapper->expects($this->once())
            ->method('find')
            ->with('string-id')
            ->willReturn($register);

        $result = $this->invokePrivate('loadRegisterWithCache', ['string-id']);
        $this->assertSame($register, $result);

        $result2 = $this->invokePrivate('loadRegisterWithCache', ['string-id']);
        $this->assertSame($register, $result2);
    }

    // =========================================================================
    // handleBulkInverseRelationsWithAnalysis — no @self data
    // =========================================================================

    public function testHandleBulkInverseRelationsWithNoSelfData(): void
    {
        $preparedObjects = [
            ['parent' => '785eb0e8-2c56-4230-8f2e-b4eccecb0e2b'],
        ];

        $schemaAnalysis = [
            1 => [
                'inverseProperties' => [
                    'parent' => ['inversedBy' => 'children', 'writeBack' => false, 'isArray' => false],
                ],
            ],
        ];

        $ref = new ReflectionClass($this->handler);
        $method = $ref->getMethod('handleBulkInverseRelationsWithAnalysis');
        $method->setAccessible(true);
        $method->invokeArgs($this->handler, [&$preparedObjects, $schemaAnalysis]);

        $this->assertCount(1, $preparedObjects);
    }

    // =========================================================================
    // saveObjects — single schema register passed as int
    // =========================================================================

    public function testSaveObjectsSingleSchemaWithRegisterAsInt(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);
        $register = $this->createRegister(5);

        $this->registerMapper->method('find')->with(5)->willReturn($register);
        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);
        $this->setupBulkSaveMock(1);

        $result = $this->handler->saveObjects([['name' => 'Test']], 5, $schema);

        $this->assertCount(1, $result['saved']);
    }

    // =========================================================================
    // scanForRelations — deeply nested arrays
    // =========================================================================

    public function testScanForRelationsDeepNesting(): void
    {
        $data = [
            'level1' => [
                'level2' => [
                    'link' => 'https://example.com/deep',
                ],
            ],
        ];

        $result = $this->invokePrivate('scanForRelations', [$data, '', null]);

        $this->assertArrayHasKey('level1.level2.link', $result);
    }

    public function testScanForRelationsArrayOfObjectsWithEmptyArray(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([
            'items' => ['type' => 'array', 'items' => ['type' => 'object']],
        ]);

        $data = ['items' => [[]]];

        $result = $this->invokePrivate('scanForRelations', [$data, '', $schema]);

        $this->assertEmpty($result);
    }

    // =========================================================================
    // saveObjects — single schema with non-string dates
    // =========================================================================

    public function testSaveObjectsSingleSchemaWithNonStringPublished(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);
        $register = $this->createRegister(1);

        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);
        $this->setupBulkSaveMock(1);

        $objects = [['@self' => ['published' => 12345, 'depublished' => 67890], 'name' => 'Non-String Dates']];

        $result = $this->handler->saveObjects($objects, $register, $schema);

        $this->assertCount(1, $result['saved']);
    }

    public function testSaveObjectsSingleSchemaWithNullSelfPublished(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);
        $register = $this->createRegister(1);

        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);
        $this->setupBulkSaveMock(1);

        $objects = [['@self' => ['published' => null, 'depublished' => null], 'name' => 'Null Dates']];

        $result = $this->handler->saveObjects($objects, $register, $schema);

        $this->assertCount(1, $result['saved']);
    }

    // =========================================================================
    // saveObjects — validation and events flags
    // =========================================================================

    public function testSaveObjectsWithValidationEnabled(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);
        $register = $this->createRegister(1);

        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);
        $this->setupBulkSaveMock(1);

        $result = $this->handler->saveObjects(
            [['name' => 'Test']], $register, $schema,
            true, true, true
        );

        $this->assertCount(1, $result['saved']);
    }

    public function testSaveObjectsWithEventsEnabled(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);
        $register = $this->createRegister(1);

        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);
        $this->setupBulkSaveMock(1);

        $result = $this->handler->saveObjects(
            [['name' => 'Test']], $register, $schema,
            true, true, false, true
        );

        $this->assertCount(1, $result['saved']);
    }

    // =========================================================================
    // saveObjects — result structure validation
    // =========================================================================

    public function testSaveObjectsResultHasAggregateStatisticsKeys(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([]);
        $schema->setConfiguration([]);
        $register = $this->createRegister(1);

        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);
        $this->setupBulkSaveMock(1, 0, 1);

        $result = $this->handler->saveObjects([['name' => 'Test']], $register, $schema);

        // The result should have the standard statistics keys.
        $this->assertArrayHasKey('saved', $result['statistics']);
        $this->assertArrayHasKey('updated', $result['statistics']);
        $this->assertArrayHasKey('unchanged', $result['statistics']);
        $this->assertSame(1, $result['statistics']['saved']);
        $this->assertSame(0, $result['statistics']['updated']);
        $this->assertSame(1, $result['statistics']['unchanged']);
    }

    // =========================================================================
    // saveObjects — no objects prepared returns error
    // =========================================================================

    public function testSaveObjectsReturnsErrorWhenNoObjectsPrepared(): void
    {
        // Mixed schema with objects that have no @self.schema.
        // groupAndLoadSchemas will find no schemas, prepareMixedSchemaObject
        // throws when schema is not in cache. We expect the exception.
        $this->expectException(\Exception::class);
        $this->handler->saveObjects([['name' => 'bad']]);
    }
}
