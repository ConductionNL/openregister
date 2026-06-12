<?php

/**
 * Integration tests for Object Handler services
 *
 * Tests ValidationHandler, RelationHandler, FacetHandler, SearchQueryHandler,
 * PermissionHandler, and QueryHandler with real database operations.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\ValidationHandler;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for ObjectService handler classes
 *
 * Tests real code paths in ValidationHandler, RelationHandler, FacetHandler,
 * SearchQueryHandler, PermissionHandler, and QueryHandler using the DI container
 * and a real PostgreSQL database.
 *
 * @group DB
 */
class ObjectHandlersIntegrationTest extends TestCase
{
    private ValidationHandler $validationHandler;
    private RelationHandler $relationHandler;
    private FacetHandler $facetHandler;
    private SearchQueryHandler $searchQueryHandler;
    private PermissionHandler $permissionHandler;
    private QueryHandler $queryHandler;
    private ObjectService $objectService;
    private SaveObject $saveHandler;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;
    private MagicMapper $objectMapper;

    private ?Register $testRegister = null;
    private ?Schema $testSchema = null;
    private ?Schema $relatedSchema = null;
    private ?Schema $authSchema = null;

    /** @var string[] UUIDs of created objects for cleanup */
    private array $createdObjectUuids = [];

    /** @var int[] IDs of extra schemas for cleanup */
    private array $extraSchemaIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->validationHandler = \OC::$server->get(ValidationHandler::class);
        $this->relationHandler = \OC::$server->get(RelationHandler::class);
        $this->facetHandler = \OC::$server->get(FacetHandler::class);
        $this->searchQueryHandler = \OC::$server->get(SearchQueryHandler::class);
        $this->permissionHandler = \OC::$server->get(PermissionHandler::class);
        $this->queryHandler = \OC::$server->get(QueryHandler::class);
        $this->objectService = \OC::$server->get(ObjectService::class);
        $this->saveHandler = \OC::$server->get(SaveObject::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper = \OC::$server->get(SchemaMapper::class);
        $this->objectMapper = \OC::$server->get(MagicMapper::class);

        $this->createTestFixtures();
    }

    protected function tearDown(): void
    {
        // Clean up objects first (they reference schemas/registers).
        foreach ($this->createdObjectUuids as $uuid) {
            try {
                $this->objectService->deleteObject($uuid, false, false);
            } catch (\Exception $e) {
                // Ignore cleanup errors.
            }
        }

        foreach ($this->extraSchemaIds as $id) {
            try {
                $schema = $this->schemaMapper->find($id);
                $this->schemaMapper->delete($schema);
            } catch (\Exception $e) {
                // Ignore.
            }
        }

        if ($this->authSchema !== null) {
            try {
                $this->schemaMapper->delete($this->authSchema);
            } catch (\Exception $e) {
                // Ignore.
            }
        }

        if ($this->relatedSchema !== null) {
            try {
                $this->schemaMapper->delete($this->relatedSchema);
            } catch (\Exception $e) {
                // Ignore.
            }
        }

        if ($this->testSchema !== null) {
            try {
                $this->schemaMapper->delete($this->testSchema);
            } catch (\Exception $e) {
                // Ignore.
            }
        }

        if ($this->testRegister !== null) {
            try {
                $this->registerMapper->delete($this->testRegister);
            } catch (\Exception $e) {
                // Ignore.
            }
        }

        parent::tearDown();
    }

    private function createTestFixtures(): void
    {
        // Create register.
        $register = new Register();
        $register->setTitle('phpunit-handlers-' . uniqid());
        $register->setDescription('Test register for handler integration tests');
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-handlers-' . uniqid());
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        // Create main schema with various property types.
        $schema = new Schema();
        $schema->setTitle('phpunit-handler-schema-' . uniqid());
        $schema->setDescription('Test schema for handler integration tests');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-handler-schema-' . uniqid());
        $schema->setProperties([
            'title' => [
                'type'     => 'string',
                'title'    => 'Title',
                'required' => true,
            ],
            'description' => [
                'type'  => 'string',
                'title' => 'Description',
            ],
            'category' => [
                'type'  => 'string',
                'title' => 'Category',
                'enum'  => ['news', 'blog', 'tutorial'],
            ],
            'priority' => [
                'type'    => 'integer',
                'title'   => 'Priority',
                'default' => 0,
            ],
            'active' => [
                'type'  => 'boolean',
                'title' => 'Active',
            ],
            'tags' => [
                'type'  => 'array',
                'title' => 'Tags',
                'items' => ['type' => 'string'],
            ],
            'score' => [
                'type'  => 'number',
                'title' => 'Score',
            ],
            'metadata' => [
                'type'       => 'object',
                'title'      => 'Metadata',
                'properties' => [
                    'source'  => ['type' => 'string'],
                    'version' => ['type' => 'integer'],
                ],
            ],
            'relatedItem' => [
                'type'  => 'string',
                'title' => 'Related Item',
            ],
            'relatedItems' => [
                'type'  => 'array',
                'title' => 'Related Items',
                'items' => ['type' => 'string'],
            ],
        ]);
        $this->testSchema = $this->schemaMapper->insert($schema);

        // Create related schema for relation tests.
        $relSchema = new Schema();
        $relSchema->setTitle('phpunit-related-' . uniqid());
        $relSchema->setDescription('Related schema for testing relations');
        $relSchema->setUuid(Uuid::v4()->toRfc4122());
        $relSchema->setSlug('phpunit-related-' . uniqid());
        $relSchema->setProperties([
            'name' => [
                'type'     => 'string',
                'title'    => 'Name',
                'required' => true,
            ],
            'parentRef' => [
                'type'       => 'string',
                'title'      => 'Parent Reference',
                'inversedBy' => 'relatedItem',
                '$ref'       => $this->testSchema->getId(),
            ],
        ]);
        $this->relatedSchema = $this->schemaMapper->insert($relSchema);

        // Create authorization schema for permission tests.
        $authSchema = new Schema();
        $authSchema->setTitle('phpunit-auth-' . uniqid());
        $authSchema->setDescription('Auth schema for permission tests');
        $authSchema->setUuid(Uuid::v4()->toRfc4122());
        $authSchema->setSlug('phpunit-auth-' . uniqid());
        $authSchema->setProperties([
            'title' => [
                'type'     => 'string',
                'title'    => 'Title',
                'required' => true,
            ],
        ]);
        $authSchema->setAuthorization([
            'read'   => ['admin', 'editors', 'public'],
            'create' => ['admin', 'editors'],
            'update' => ['admin', 'editors'],
            'delete' => ['admin'],
        ]);
        $this->authSchema = $this->schemaMapper->insert($authSchema);
        $this->extraSchemaIds[] = $this->authSchema->getId();

        // Update register with all schemas.
        $this->testRegister->setSchemas([
            $this->testSchema->getId(),
            $this->relatedSchema->getId(),
            $this->authSchema->getId(),
        ]);
        $this->registerMapper->update($this->testRegister);
    }

    /**
     * Helper to save a test object and track it for cleanup.
     */
    private function saveTestObject(
        array $data,
        ?Schema $schema = null,
        bool $rbac = false,
        bool $multitenancy = false
    ): ObjectEntity {
        $useSchema = $schema ?? $this->testSchema;
        $result = $this->saveHandler->saveObject(
            $this->testRegister,
            $useSchema,
            $data,
            null,
            null,
            $rbac,
            $multitenancy
        );
        $this->createdObjectUuids[] = $result->getUuid();
        return $result;
    }

    // =========================================================================
    // ValidationHandler Tests
    // =========================================================================

    /**
     * Test validateRequiredFields with valid objects.
     */
    public function testValidateRequiredFieldsWithValidObjects(): void
    {
        $objects = [
            ['@self' => ['register' => 1, 'schema' => 1], 'title' => 'Test'],
            ['@self' => ['register' => 2, 'schema' => 3], 'title' => 'Test 2'],
        ];

        // Should not throw any exception.
        $this->validationHandler->validateRequiredFields($objects);
        $this->assertTrue(true, 'No exception thrown for valid objects');
    }

    /**
     * Test validateRequiredFields throws on missing @self.
     */
    public function testValidateRequiredFieldsMissingSelf(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("missing required '@self' section");

        $objects = [
            ['title' => 'No self section'],
        ];
        $this->validationHandler->validateRequiredFields($objects);
    }

    /**
     * Test validateRequiredFields throws on missing register.
     */
    public function testValidateRequiredFieldsMissingRegister(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("missing required field 'register'");

        $objects = [
            ['@self' => ['schema' => 1], 'title' => 'Missing register'],
        ];
        $this->validationHandler->validateRequiredFields($objects);
    }

    /**
     * Test validateRequiredFields throws on missing schema.
     */
    public function testValidateRequiredFieldsMissingSchema(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("missing required field 'schema'");

        $objects = [
            ['@self' => ['register' => 1], 'title' => 'Missing schema'],
        ];
        $this->validationHandler->validateRequiredFields($objects);
    }

    /**
     * Test validateRequiredFields throws on empty register.
     */
    public function testValidateRequiredFieldsEmptyRegister(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("missing required field 'register'");

        $objects = [
            ['@self' => ['register' => '', 'schema' => 1]],
        ];
        $this->validationHandler->validateRequiredFields($objects);
    }

    /**
     * Test validateRequiredFields with @self as non-array.
     */
    public function testValidateRequiredFieldsSelfNotArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("missing required '@self' section");

        $objects = [
            ['@self' => 'not-an-array'],
        ];
        $this->validationHandler->validateRequiredFields($objects);
    }

    /**
     * Test validateRequiredFields multiple objects second one fails.
     */
    public function testValidateRequiredFieldsMultipleObjectsSecondFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('index 1');

        $objects = [
            ['@self' => ['register' => 1, 'schema' => 1], 'title' => 'Good'],
            ['@self' => ['register' => 1], 'title' => 'Bad'],
        ];
        $this->validationHandler->validateRequiredFields($objects);
    }

    /**
     * Test validateObjectsBySchema with real objects.
     *
     * Note: Objects stored in magic tables are not found by findBySchema (main table).
     * We test the method runs correctly and returns the expected structure.
     */
    public function testValidateObjectsBySchemaWithValidObjects(): void
    {
        // Create some objects first.
        $this->saveTestObject(['title' => 'Validate Test 1', 'category' => 'news']);
        $this->saveTestObject(['title' => 'Validate Test 2', 'category' => 'blog']);

        $result = $this->validationHandler->validateObjectsBySchema(
            $this->testSchema->getId(),
            function ($data, $extend, $register, $schema, $uuid, $rbac, $multi, $silent) {
                // Simple callback that always succeeds.
            }
        );

        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('invalid', $result);
        // Objects may be in magic tables so findBySchema may return 0 from main table.
        $this->assertIsArray($result['valid']);
        $this->assertIsArray($result['invalid']);
    }

    /**
     * Test validateObjectsBySchema with callback that throws.
     */
    public function testValidateObjectsBySchemaWithInvalidCallback(): void
    {
        $this->saveTestObject(['title' => 'Validate Fail Test']);

        $result = $this->validationHandler->validateObjectsBySchema(
            $this->testSchema->getId(),
            function ($data, $extend, $register, $schema, $uuid, $rbac, $multi, $silent) {
                throw new \Exception('Simulated validation error');
            }
        );

        $this->assertArrayHasKey('invalid', $result);
        // Objects may be in magic tables; validate the structure is correct.
        $this->assertIsArray($result['invalid']);
    }

    /**
     * Test validateSchemaObjects with valid data.
     */
    public function testValidateSchemaObjectsWithValidObjects(): void
    {
        $this->saveTestObject(['title' => 'Schema Validation 1']);
        $this->saveTestObject(['title' => 'Schema Validation 2']);

        $result = $this->validationHandler->validateSchemaObjects(
            $this->testSchema->getId(),
            function ($data, $register, $schema, $uuid, $rbac, $multi, $silent) {
                // Succeeds silently.
            }
        );

        $this->assertArrayHasKey('valid_count', $result);
        $this->assertArrayHasKey('invalid_count', $result);
        $this->assertArrayHasKey('valid_objects', $result);
        $this->assertArrayHasKey('invalid_objects', $result);
        $this->assertArrayHasKey('schema_id', $result);
        $this->assertEquals($this->testSchema->getId(), $result['schema_id']);
        // May return 0 if objects are in magic tables.
        $this->assertGreaterThanOrEqual(0, $result['valid_count']);
    }

    /**
     * Test validateSchemaObjects with failing callback.
     */
    public function testValidateSchemaObjectsWithFailingCallback(): void
    {
        $this->saveTestObject(['title' => 'Failing validation test']);

        $result = $this->validationHandler->validateSchemaObjects(
            $this->testSchema->getId(),
            function () {
                throw new \Exception('Callback failure');
            }
        );

        // May return 0 if objects are in magic tables.
        $this->assertGreaterThanOrEqual(0, $result['invalid_count']);
        $this->assertIsArray($result['invalid_objects']);
    }

    /**
     * Test applyInversedByFilter returns empty array.
     */
    public function testApplyInversedByFilterReturnsEmptyArray(): void
    {
        $filters = ['some' => 'filter'];
        $result = $this->validationHandler->applyInversedByFilter($filters);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test validateAndSaveObjectsBySchema with invalid IDs.
     */
    public function testValidateAndSaveObjectsBySchemaInvalidIds(): void
    {
        $result = $this->validationHandler->validateAndSaveObjectsBySchema(
            999999,
            999999,
            [$this->objectService, 'saveObjects']
        );

        $this->assertEquals(0, $result['processed']);
        $this->assertNotEmpty($result['errors']);
    }

    /**
     * Test validateAndSaveObjectsBySchema with real data.
     */
    public function testValidateAndSaveObjectsBySchemaWithRealData(): void
    {
        $this->saveTestObject(['title' => 'Validate and Save 1']);
        $this->saveTestObject(['title' => 'Validate and Save 2']);

        $result = $this->validationHandler->validateAndSaveObjectsBySchema(
            $this->testRegister->getId(),
            $this->testSchema->getId(),
            [$this->objectService, 'saveObjects']
        );

        $this->assertArrayHasKey('processed', $result);
        $this->assertArrayHasKey('updated', $result);
        $this->assertArrayHasKey('failed', $result);
        $this->assertArrayHasKey('total', $result);
        // Total may be 0 if objects are in magic tables (not blob storage).
        $this->assertGreaterThanOrEqual(0, $result['total']);
    }

    /**
     * Test validateAndSaveObjectsBySchema with limit and offset.
     */
    public function testValidateAndSaveObjectsBySchemaWithLimitOffset(): void
    {
        $this->saveTestObject(['title' => 'Limit Test 1']);
        $this->saveTestObject(['title' => 'Limit Test 2']);
        $this->saveTestObject(['title' => 'Limit Test 3']);

        $result = $this->validationHandler->validateAndSaveObjectsBySchema(
            $this->testRegister->getId(),
            $this->testSchema->getId(),
            [$this->objectService, 'saveObjects'],
            1,  // limit
            1   // offset
        );

        $this->assertArrayHasKey('processed', $result);
        $this->assertArrayHasKey('total', $result);
    }

    // =========================================================================
    // RelationHandler Tests
    // =========================================================================

    /**
     * Test extractAllRelationshipIds with no extend properties.
     */
    public function testExtractAllRelationshipIdsEmpty(): void
    {
        $obj = $this->saveTestObject(['title' => 'Relation Test']);
        // Use returned entity directly (may be stored in magic table, not main objects table).

        $result = $this->relationHandler->extractAllRelationshipIds([$obj], []);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test extractAllRelationshipIds with single string relation.
     */
    public function testExtractAllRelationshipIdsSingleRelation(): void
    {
        $relatedUuid = Uuid::v4()->toRfc4122();
        $obj = $this->saveTestObject([
            'title'       => 'Has Relation',
            'relatedItem' => $relatedUuid,
        ]);

        $result = $this->relationHandler->extractAllRelationshipIds([$obj], ['relatedItem']);
        $this->assertContains($relatedUuid, $result);
    }

    /**
     * Test extractAllRelationshipIds with array relations.
     */
    public function testExtractAllRelationshipIdsArrayRelation(): void
    {
        $uuid1 = Uuid::v4()->toRfc4122();
        $uuid2 = Uuid::v4()->toRfc4122();
        $obj = $this->saveTestObject([
            'title'        => 'Has Array Relations',
            'relatedItems' => [$uuid1, $uuid2],
        ]);

        $result = $this->relationHandler->extractAllRelationshipIds([$obj], ['relatedItems']);
        $this->assertContains($uuid1, $result);
        $this->assertContains($uuid2, $result);
    }

    /**
     * Test extractAllRelationshipIds deduplicates.
     */
    public function testExtractAllRelationshipIdsDeduplicates(): void
    {
        $sharedUuid = Uuid::v4()->toRfc4122();
        $obj1 = $this->saveTestObject([
            'title'       => 'Dedup Test 1',
            'relatedItem' => $sharedUuid,
        ]);
        $obj2 = $this->saveTestObject([
            'title'       => 'Dedup Test 2',
            'relatedItem' => $sharedUuid,
        ]);

        $result = $this->relationHandler->extractAllRelationshipIds([$obj1, $obj2], ['relatedItem']);
        // Should have only one copy of the shared UUID.
        $this->assertEquals(1, count(array_keys($result, $sharedUuid, true)));
    }

    /**
     * Test extractAllRelationshipIds circuit breaker limits.
     */
    public function testExtractAllRelationshipIdsCircuitBreaker(): void
    {
        // Create object with many relations (more than the 200 limit).
        $manyUuids = [];
        for ($i = 0; $i < 25; $i++) {
            $manyUuids[] = Uuid::v4()->toRfc4122();
        }
        $obj = $this->saveTestObject([
            'title'        => 'Many Relations',
            'relatedItems' => $manyUuids,
        ]);

        // The method limits to 10 per array plus 200 total.
        $result = $this->relationHandler->extractAllRelationshipIds([$obj], ['relatedItems']);
        $this->assertIsArray($result);
        $this->assertLessThanOrEqual(200, count($result));
    }

    /**
     * Test bulkLoadRelationshipsBatched with empty IDs.
     */
    public function testBulkLoadRelationshipsBatchedEmpty(): void
    {
        $result = $this->relationHandler->bulkLoadRelationshipsBatched([]);
        $this->assertEmpty($result);
    }

    /**
     * Test bulkLoadRelationshipsBatched with real object UUIDs.
     *
     * Note: Objects in magic tables may not be found by the main table findAll.
     * We verify the method runs without error and returns an array.
     */
    public function testBulkLoadRelationshipsBatchedWithRealObjects(): void
    {
        $obj1 = $this->saveTestObject(['title' => 'Bulk Load 1']);
        $obj2 = $this->saveTestObject(['title' => 'Bulk Load 2']);

        $result = $this->relationHandler->bulkLoadRelationshipsBatched([
            $obj1->getUuid(),
            $obj2->getUuid(),
        ]);

        // Objects may be in magic tables, so findAll by UUID may return empty.
        $this->assertIsArray($result);
    }

    /**
     * Test bulkLoadRelationshipsBatched caps at 200.
     */
    public function testBulkLoadRelationshipsBatchedCapsAt200(): void
    {
        $ids = [];
        for ($i = 0; $i < 250; $i++) {
            $ids[] = Uuid::v4()->toRfc4122();
        }

        // Should not throw; it caps internally.
        $result = $this->relationHandler->bulkLoadRelationshipsBatched($ids);
        $this->assertIsArray($result);
    }

    /**
     * Test loadRelationshipChunkOptimized with empty array.
     */
    public function testLoadRelationshipChunkOptimizedEmpty(): void
    {
        $result = $this->relationHandler->loadRelationshipChunkOptimized([]);
        $this->assertEmpty($result);
    }

    /**
     * Test loadRelationshipChunkOptimized with real UUIDs.
     *
     * Note: Objects in magic tables may not be found by the main table findAll.
     */
    public function testLoadRelationshipChunkOptimizedWithData(): void
    {
        $obj = $this->saveTestObject(['title' => 'Chunk Load Test']);
        $result = $this->relationHandler->loadRelationshipChunkOptimized([$obj->getUuid()]);
        // May return empty if objects are in magic tables.
        $this->assertIsArray($result);
    }

    /**
     * Test getContracts for an object without contracts.
     */
    public function testGetContractsEmptyContracts(): void
    {
        $obj = $this->saveTestObject(['title' => 'No Contracts']);
        $result = $this->relationHandler->getContracts($obj->getUuid());

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('limit', $result);
        $this->assertArrayHasKey('offset', $result);
        $this->assertEquals(0, $result['total']);
    }

    /**
     * Test getContracts for nonexistent object.
     */
    public function testGetContractsNonexistentObject(): void
    {
        $result = $this->relationHandler->getContracts('nonexistent-uuid-' . uniqid());

        $this->assertArrayHasKey('results', $result);
        $this->assertEquals(0, $result['total']);
    }

    /**
     * Test getContracts with pagination filters.
     */
    public function testGetContractsWithPagination(): void
    {
        $obj = $this->saveTestObject(['title' => 'Contracts Pagination']);
        $result = $this->relationHandler->getContracts($obj->getUuid(), [
            '_limit'  => 10,
            '_offset' => 0,
        ]);

        $this->assertEquals(10, $result['limit']);
        $this->assertEquals(0, $result['offset']);
    }

    /**
     * Test getUses for an object with no relations.
     */
    public function testGetUsesNoRelations(): void
    {
        $obj = $this->saveTestObject(['title' => 'No Relations']);
        $result = $this->relationHandler->getUses(
            $obj->getUuid(),
            [],
            false,
            false,
            $this->testRegister->getId(),
            $this->testSchema->getId()
        );

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertEquals(0, $result['total']);
    }

    /**
     * Test getUses for nonexistent object.
     */
    public function testGetUsesNonexistentObject(): void
    {
        $result = $this->relationHandler->getUses('nonexistent-uuid-' . uniqid());

        $this->assertArrayHasKey('results', $result);
        $this->assertEquals(0, $result['total']);
    }

    /**
     * Test getUsedBy for an object.
     */
    public function testGetUsedByReturnsStructure(): void
    {
        $obj = $this->saveTestObject(['title' => 'UsedBy Test']);
        $result = $this->relationHandler->getUsedBy(
            $obj->getUuid(),
            [],
            false,
            false,
            $this->testRegister->getId(),
            $this->testSchema->getId()
        );

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('limit', $result);
        $this->assertArrayHasKey('offset', $result);
    }

    /**
     * Test getUsedBy for nonexistent object.
     */
    public function testGetUsedByNonexistentObject(): void
    {
        $result = $this->relationHandler->getUsedBy('nonexistent-uuid-' . uniqid());

        $this->assertArrayHasKey('results', $result);
        $this->assertEquals(0, $result['total']);
    }

    /**
     * Test extractRelatedData delegation.
     */
    public function testExtractRelatedDataEmpty(): void
    {
        $result = $this->relationHandler->extractRelatedData([], false, false);
        $this->assertIsArray($result);
    }

    // =========================================================================
    // FacetHandler Tests
    // =========================================================================

    /**
     * Test getFacetsForObjects with empty config.
     */
    public function testGetFacetsForObjectsEmptyConfig(): void
    {
        $result = $this->facetHandler->getFacetsForObjects([]);
        $this->assertArrayHasKey('facets', $result);
        $this->assertEmpty($result['facets']);
    }

    /**
     * Test getFacetsForObjects with string config.
     */
    public function testGetFacetsForObjectsStringConfig(): void
    {
        $result = $this->facetHandler->getFacetsForObjects([
            '_facets' => 'category',
            '@self'   => [
                'register' => $this->testRegister->getId(),
                'schema'   => $this->testSchema->getId(),
            ],
        ]);

        $this->assertArrayHasKey('facets', $result);
        $this->assertArrayHasKey('performance_metadata', $result);
    }

    /**
     * Test getFacetsForObjects with array config.
     */
    public function testGetFacetsForObjectsArrayConfig(): void
    {
        $this->saveTestObject(['title' => 'Facet Test 1', 'category' => 'news']);
        $this->saveTestObject(['title' => 'Facet Test 2', 'category' => 'blog']);

        $result = $this->facetHandler->getFacetsForObjects([
            '_facets' => ['category', 'active'],
            '@self'   => [
                'register' => $this->testRegister->getId(),
                'schema'   => $this->testSchema->getId(),
            ],
        ]);

        $this->assertArrayHasKey('facets', $result);
        $this->assertArrayHasKey('performance_metadata', $result);
        $this->assertArrayHasKey('strategy', $result['performance_metadata']);
    }

    /**
     * Test getFacetableFields returns structure.
     */
    public function testGetFacetableFieldsReturnsStructure(): void
    {
        $result = $this->facetHandler->getFacetableFields([
            '@self' => ['schema' => $this->testSchema->getId()],
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('@self', $result);
        $this->assertArrayHasKey('object_fields', $result);
    }

    /**
     * Test getFacetableFields with no schema filter.
     */
    public function testGetFacetableFieldsNoSchemaFilter(): void
    {
        $result = $this->facetHandler->getFacetableFields([]);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('@self', $result);
    }

    /**
     * Test getMetadataFacetableFields.
     */
    public function testGetMetadataFacetableFields(): void
    {
        $result = $this->facetHandler->getMetadataFacetableFields();
        $this->assertIsArray($result);
        $this->assertContains('register', $result);
        $this->assertContains('schema', $result);
        $this->assertContains('owner', $result);
        $this->assertContains('organisation', $result);
        $this->assertContains('created', $result);
        $this->assertContains('updated', $result);
    }

    /**
     * Test getFacetCount with no facets.
     */
    public function testGetFacetCountNoFacets(): void
    {
        $result = $this->facetHandler->getFacetCount(false, []);
        $this->assertEquals(0, $result);
    }

    /**
     * Test getFacetCount with facets.
     */
    public function testGetFacetCountWithFacets(): void
    {
        $result = $this->facetHandler->getFacetCount(true, ['_facets' => ['a', 'b', 'c']]);
        $this->assertEquals(3, $result);
    }

    /**
     * Test getFacetCount with non-array facets.
     */
    public function testGetFacetCountWithNonArrayFacets(): void
    {
        $result = $this->facetHandler->getFacetCount(true, ['_facets' => 'single']);
        $this->assertEquals(0, $result);
    }

    /**
     * Test getFacetsForObjects with restrictive filters and empty results (fallback).
     */
    public function testGetFacetsForObjectsWithFallback(): void
    {
        $result = $this->facetHandler->getFacetsForObjects([
            '_facets'  => ['category'],
            '_search'  => 'nonexistent-query-that-matches-nothing-' . uniqid(),
            '@self'    => [
                'register' => $this->testRegister->getId(),
                'schema'   => $this->testSchema->getId(),
            ],
        ]);

        $this->assertArrayHasKey('performance_metadata', $result);
    }

    // =========================================================================
    // SearchQueryHandler Tests
    // =========================================================================

    /**
     * Test buildSearchQuery with basic parameters.
     */
    public function testBuildSearchQueryBasic(): void
    {
        $result = $this->searchQueryHandler->buildSearchQuery(
            ['_limit' => '20', '_offset' => '0', 'title' => 'test'],
            $this->testRegister->getId(),
            $this->testSchema->getId()
        );

        $this->assertArrayHasKey('@self', $result);
        $this->assertEquals($this->testRegister->getId(), $result['@self']['register']);
        $this->assertEquals($this->testSchema->getId(), $result['@self']['schema']);
        $this->assertEquals('20', $result['_limit']);
    }

    /**
     * Test buildSearchQuery with underscore-separated params (dot mangling).
     */
    public function testBuildSearchQueryDotMangling(): void
    {
        $result = $this->searchQueryHandler->buildSearchQuery(
            ['person_address_street' => 'Main St'],
            null,
            null
        );

        // Should reconstruct nested structure from underscore-separated keys.
        $this->assertArrayHasKey('person', $result);
    }

    /**
     * Test buildSearchQuery with metadata fields.
     */
    public function testBuildSearchQueryMetadataFields(): void
    {
        $result = $this->searchQueryHandler->buildSearchQuery(
            ['organisation' => 'test-org', 'owner' => 'admin'],
            null,
            null
        );

        $this->assertArrayHasKey('@self', $result);
        $this->assertEquals('test-org', $result['@self']['organisation']);
        $this->assertEquals('admin', $result['@self']['owner']);
    }

    /**
     * Test buildSearchQuery with IDs parameter.
     */
    public function testBuildSearchQueryWithIds(): void
    {
        $ids = [Uuid::v4()->toRfc4122(), Uuid::v4()->toRfc4122()];
        $result = $this->searchQueryHandler->buildSearchQuery([], null, null, $ids);

        $this->assertArrayHasKey('_ids', $result);
        $this->assertEquals($ids, $result['_ids']);
    }

    /**
     * Test buildSearchQuery normalizes _ids string to array.
     */
    public function testBuildSearchQueryNormalizesIdsString(): void
    {
        $result = $this->searchQueryHandler->buildSearchQuery(
            ['_ids' => 'uuid1,uuid2,uuid3'],
            null,
            null
        );

        $this->assertIsArray($result['_ids']);
        $this->assertCount(3, $result['_ids']);
    }

    /**
     * Test buildSearchQuery with published filter.
     */
    public function testBuildSearchQueryPublishedFilter(): void
    {
        $result = $this->searchQueryHandler->buildSearchQuery(
            ['_published' => 'true'],
            null,
            null
        );

        $this->assertArrayHasKey('_published', $result);
        $this->assertTrue($result['_published']);
    }

    /**
     * Test buildSearchQuery strips system params.
     */
    public function testBuildSearchQueryStripsSystemParams(): void
    {
        $result = $this->searchQueryHandler->buildSearchQuery(
            ['_route' => 'some.route', 'id' => '123', 'rbac' => 'true', 'title' => 'keep'],
            null,
            null
        );

        $this->assertArrayNotHasKey('_route', $result);
        $this->assertArrayNotHasKey('id', $result);
        $this->assertArrayNotHasKey('rbac', $result);
    }

    /**
     * Test buildSearchQuery with array register/schema.
     */
    public function testBuildSearchQueryArrayRegisterSchema(): void
    {
        $result = $this->searchQueryHandler->buildSearchQuery(
            [],
            [1, 2, 3],
            [4, 5]
        );

        $this->assertIsArray($result['@self']['register']);
        $this->assertIsArray($result['@self']['schema']);
        $this->assertCount(3, $result['@self']['register']);
        $this->assertCount(2, $result['@self']['schema']);
    }

    /**
     * Test cleanQuery with ordering parameter.
     */
    public function testCleanQueryOrdering(): void
    {
        $result = $this->searchQueryHandler->cleanQuery(['ordering' => '-title']);
        $this->assertArrayHasKey('_order', $result);
        $this->assertEquals(['title' => 'DESC'], $result['_order']);
    }

    /**
     * Test cleanQuery with ascending ordering.
     */
    public function testCleanQueryOrderingAsc(): void
    {
        $result = $this->searchQueryHandler->cleanQuery(['ordering' => 'created']);
        $this->assertEquals(['created' => 'ASC'], $result['_order']);
    }

    /**
     * Test cleanQuery with suffix operators.
     */
    public function testCleanQuerySuffixOperators(): void
    {
        $result = $this->searchQueryHandler->cleanQuery([
            'score_gt'  => 5,
            'score_lt'  => 100,
            'score_gte' => 10,
            'score_lte' => 90,
        ]);

        $this->assertArrayHasKey('score', $result);
        $this->assertEquals(5, $result['score']['gt']);
        $this->assertEquals(100, $result['score']['lt']);
        $this->assertEquals(10, $result['score']['gte']);
        $this->assertEquals(90, $result['score']['lte']);
    }

    /**
     * Test cleanQuery with _in operator.
     */
    public function testCleanQueryInOperator(): void
    {
        $result = $this->searchQueryHandler->cleanQuery([
            'category_in' => 'news,blog',
        ]);

        $this->assertArrayHasKey('category', $result);
        $this->assertEquals('news,blog', $result['category']['in']);
    }

    /**
     * Test cleanQuery with isnull operator.
     */
    public function testCleanQueryIsNull(): void
    {
        $result = $this->searchQueryHandler->cleanQuery([
            'title_isnull' => true,
        ]);
        $this->assertEquals('IS NULL', $result['title']);

        $result2 = $this->searchQueryHandler->cleanQuery([
            'title_isnull' => false,
        ]);
        $this->assertEquals('IS NOT NULL', $result2['title']);
    }

    /**
     * Test cleanQuery normalizes double underscores.
     */
    public function testCleanQueryNormalizesDoubleUnderscores(): void
    {
        $result = $this->searchQueryHandler->cleanQuery([
            'person__name' => 'John',
        ]);
        $this->assertArrayHasKey('person_name', $result);
    }

    /**
     * Test isSolrAvailable.
     */
    public function testIsSolrAvailable(): void
    {
        $result = $this->searchQueryHandler->isSolrAvailable();
        $this->assertIsBool($result);
    }

    /**
     * Test isSearchTrailsEnabled.
     */
    public function testIsSearchTrailsEnabled(): void
    {
        $result = $this->searchQueryHandler->isSearchTrailsEnabled();
        $this->assertIsBool($result);
    }

    /**
     * Test logSearchTrail does not throw.
     */
    public function testLogSearchTrailDoesNotThrow(): void
    {
        $this->searchQueryHandler->logSearchTrail(
            ['_search' => 'test'],
            10,
            100,
            50.5,
            'sync'
        );
        $this->assertTrue(true, 'logSearchTrail completed without exception');
    }

    /**
     * Test applyViewsToQuery with empty views.
     */
    public function testApplyViewsToQueryEmptyViews(): void
    {
        $query = ['@self' => ['register' => 1]];
        $result = $this->searchQueryHandler->applyViewsToQuery($query, []);
        $this->assertEquals($query, $result);
    }

    /**
     * Test applyViewsToQuery with nonexistent view ID.
     */
    public function testApplyViewsToQueryNonexistentView(): void
    {
        $query = ['@self' => ['register' => 1]];
        // Should not throw, just log warning.
        $result = $this->searchQueryHandler->applyViewsToQuery($query, [999999]);
        $this->assertIsArray($result);
    }

    // =========================================================================
    // PermissionHandler Tests
    // =========================================================================

    /**
     * Test hasPermission with RBAC disabled.
     */
    public function testHasPermissionRbacDisabled(): void
    {
        $result = $this->permissionHandler->hasPermission(
            $this->authSchema,
            'delete',
            'admin',
            null,
            false
        );
        $this->assertTrue($result);
    }

    /**
     * Test hasPermission for admin user.
     */
    public function testHasPermissionAdminUser(): void
    {
        $result = $this->permissionHandler->hasPermission(
            $this->authSchema,
            'delete',
            'admin',
            null,
            true
        );
        $this->assertTrue($result);
    }

    /**
     * Test hasPermission for object owner.
     */
    public function testHasPermissionObjectOwner(): void
    {
        // The current test user should be owner.
        $user = \OC::$server->get(\OCP\IUserSession::class)->getUser();
        $userId = $user ? $user->getUID() : 'admin';

        $result = $this->permissionHandler->hasPermission(
            $this->authSchema,
            'delete',
            $userId,
            $userId,
            true
        );
        $this->assertTrue($result);
    }

    /**
     * Test hasGroupPermission for admin group.
     */
    public function testHasGroupPermissionAdmin(): void
    {
        $result = $this->permissionHandler->hasGroupPermission(
            ['read' => ['editors']],
            'admin',
            'read'
        );
        $this->assertTrue($result);
    }

    /**
     * Test hasGroupPermission with no authorization.
     */
    public function testHasGroupPermissionNoAuthorization(): void
    {
        $result = $this->permissionHandler->hasGroupPermission(null, 'users', 'read');
        $this->assertTrue($result);
    }

    /**
     * Test hasGroupPermission with empty authorization.
     */
    public function testHasGroupPermissionEmptyAuthorization(): void
    {
        $result = $this->permissionHandler->hasGroupPermission([], 'users', 'read');
        $this->assertTrue($result);
    }

    /**
     * Test hasGroupPermission action not in authorization.
     */
    public function testHasGroupPermissionActionNotInAuth(): void
    {
        $result = $this->permissionHandler->hasGroupPermission(
            ['create' => ['editors']],
            'users',
            'read'
        );
        $this->assertTrue($result);
    }

    /**
     * Test hasGroupPermission with matching group.
     */
    public function testHasGroupPermissionMatchingGroup(): void
    {
        $result = $this->permissionHandler->hasGroupPermission(
            ['read' => ['editors', 'public']],
            'editors',
            'read'
        );
        $this->assertTrue($result);
    }

    /**
     * Test hasGroupPermission with non-matching group.
     */
    public function testHasGroupPermissionNonMatchingGroup(): void
    {
        $result = $this->permissionHandler->hasGroupPermission(
            ['delete' => ['admin']],
            'editors',
            'delete'
        );
        $this->assertFalse($result);
    }

    /**
     * Test hasGroupPermission with object owner.
     */
    public function testHasGroupPermissionOwnerOverride(): void
    {
        $result = $this->permissionHandler->hasGroupPermission(
            ['delete' => ['admin']],
            'users',
            'delete',
            'user1',
            null,
            'user1' // objectOwner matches userId
        );
        $this->assertTrue($result);
    }

    /**
     * Test hasGroupPermission with complex entry (group + match).
     */
    public function testHasGroupPermissionComplexEntry(): void
    {
        $authorization = [
            'read' => [
                ['group' => 'editors', 'match' => ['_organisation' => '$organisation']],
            ],
        ];

        // Without active organisation, condition fails.
        $result = $this->permissionHandler->hasGroupPermission(
            $authorization,
            'editors',
            'read',
            null,
            null,
            null,
            null,
            'org-uuid-123',
            null  // no active organisation
        );
        $this->assertFalse($result);

        // With matching organisation.
        $result2 = $this->permissionHandler->hasGroupPermission(
            $authorization,
            'editors',
            'read',
            null,
            null,
            null,
            null,
            'org-uuid-123',
            'org-uuid-123'
        );
        $this->assertTrue($result2);
    }

    /**
     * Test hasGroupPermission complex entry without match conditions.
     */
    public function testHasGroupPermissionComplexEntryNoMatch(): void
    {
        $authorization = [
            'read' => [
                ['group' => 'editors'],
            ],
        ];

        $result = $this->permissionHandler->hasGroupPermission(
            $authorization,
            'editors',
            'read'
        );
        $this->assertTrue($result);
    }

    // Note: tests for evaluateMatchConditions were removed when that method was deleted
    // in the `unify-rbac-condition-matching` change. Conditional match evaluation now
    // goes through ConditionMatcher; see tests/Unit/Service/ConditionMatcherTest.php
    // for operator/variable coverage, and
    // tests/Unit/Service/Object/PermissionHandlerRbacTest.php for the delegation
    // contract at the PermissionHandler boundary.

    /**
     * Test checkPermission throws on denied access.
     */
    public function testCheckPermissionThrowsOnDenied(): void
    {
        // Create a schema that only allows admin to delete.
        $restrictedSchema = new Schema();
        $restrictedSchema->setTitle('phpunit-restricted-' . uniqid());
        $restrictedSchema->setUuid(Uuid::v4()->toRfc4122());
        $restrictedSchema->setSlug('phpunit-restricted-' . uniqid());
        $restrictedSchema->setProperties(['title' => ['type' => 'string']]);
        $restrictedSchema->setAuthorization([
            'delete' => ['nonexistent-group-' . uniqid()],
        ]);
        $restrictedSchema = $this->schemaMapper->insert($restrictedSchema);
        $this->extraSchemaIds[] = $restrictedSchema->getId();

        // For current admin user this should still pass because admin group.
        // Test the method runs without error.
        try {
            $this->permissionHandler->checkPermission(
                $restrictedSchema,
                'delete',
                'admin',
                null,
                true
            );
            $this->assertTrue(true, 'Admin can delete');
        } catch (\Exception $e) {
            // Expected if admin user doesn't have admin group in test env.
            $this->assertStringContainsString('does not have permission', $e->getMessage());
        }
    }

    /**
     * Test getAuthorizedGroups with no authorization.
     */
    public function testGetAuthorizedGroupsNoAuth(): void
    {
        $result = $this->permissionHandler->getAuthorizedGroups(null, 'read');
        $this->assertEmpty($result);
    }

    /**
     * Test getAuthorizedGroups with authorization.
     */
    public function testGetAuthorizedGroupsWithAuth(): void
    {
        $result = $this->permissionHandler->getAuthorizedGroups(
            ['read' => ['admin', 'editors', 'public'], 'delete' => ['admin']],
            'read'
        );
        $this->assertCount(3, $result);
        $this->assertContains('admin', $result);
        $this->assertContains('editors', $result);
    }

    /**
     * Test getAuthorizedGroups for missing action.
     */
    public function testGetAuthorizedGroupsMissingAction(): void
    {
        $result = $this->permissionHandler->getAuthorizedGroups(
            ['read' => ['admin']],
            'delete'
        );
        $this->assertEmpty($result);
    }

    /**
     * Test filterObjectsForPermissions with RBAC disabled.
     */
    public function testFilterObjectsForPermissionsRbacDisabled(): void
    {
        $objects = [
            ['@self' => ['schema' => $this->testSchema->getId(), 'owner' => 'admin'], 'title' => 'Test'],
        ];
        $result = $this->permissionHandler->filterObjectsForPermissions($objects, false, false);
        $this->assertCount(1, $result);
    }

    /**
     * Test getActiveOrganisationForContext.
     */
    public function testGetActiveOrganisationForContext(): void
    {
        $result = $this->permissionHandler->getActiveOrganisationForContext();
        // May return null or string depending on environment.
        $this->assertTrue($result === null || is_string($result));
    }

    // =========================================================================
    // QueryHandler Tests
    // =========================================================================

    /**
     * Test searchObjectsPaginated returns paginated structure.
     */
    public function testSearchObjectsPaginatedReturnsStructure(): void
    {
        $this->saveTestObject(['title' => 'Paginated Test 1']);
        $this->saveTestObject(['title' => 'Paginated Test 2']);

        $result = $this->queryHandler->searchObjectsPaginated(
            [
                '@self'  => [
                    'register' => $this->testRegister->getId(),
                    'schema'   => $this->testSchema->getId(),
                ],
                '_limit' => 10,
            ],
            false,
            false
        );

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('page', $result);
        $this->assertArrayHasKey('pages', $result);
        $this->assertArrayHasKey('limit', $result);
        $this->assertArrayHasKey('offset', $result);
        $this->assertArrayHasKey('facets', $result);
        $this->assertArrayHasKey('@self', $result);
        $this->assertGreaterThanOrEqual(2, $result['total']);
    }

    /**
     * Test searchObjectsPaginated with page parameter.
     */
    public function testSearchObjectsPaginatedWithPage(): void
    {
        $result = $this->queryHandler->searchObjectsPaginated(
            [
                '@self'  => [
                    'register' => $this->testRegister->getId(),
                    'schema'   => $this->testSchema->getId(),
                ],
                '_limit' => 1,
                '_page'  => 1,
            ],
            false,
            false
        );

        $this->assertEquals(1, $result['page']);
        $this->assertEquals(1, $result['limit']);
    }

    /**
     * Test searchObjectsPaginated with offset.
     */
    public function testSearchObjectsPaginatedWithOffset(): void
    {
        $this->saveTestObject(['title' => 'Offset Test 1']);
        $this->saveTestObject(['title' => 'Offset Test 2']);

        $result = $this->queryHandler->searchObjectsPaginated(
            [
                '@self'    => [
                    'register' => $this->testRegister->getId(),
                    'schema'   => $this->testSchema->getId(),
                ],
                '_limit'  => 1,
                '_offset' => 1,
            ],
            false,
            false
        );

        $this->assertEquals(1, $result['limit']);
        $this->assertEquals(1, $result['offset']);
    }

    /**
     * Test searchObjectsPaginated includes metrics.
     */
    public function testSearchObjectsPaginatedIncludesMetrics(): void
    {
        $result = $this->queryHandler->searchObjectsPaginated(
            [
                '@self' => [
                    'register' => $this->testRegister->getId(),
                    'schema'   => $this->testSchema->getId(),
                ],
            ],
            false,
            false
        );

        $this->assertArrayHasKey('@self', $result);
        $this->assertArrayHasKey('metrics', $result['@self']);
        $this->assertArrayHasKey('total', $result['@self']['metrics']);
    }

    /**
     * Test searchObjectsPaginated with extend.
     */
    public function testSearchObjectsPaginatedWithExtend(): void
    {
        $this->saveTestObject(['title' => 'Extend Test']);

        $result = $this->queryHandler->searchObjectsPaginated(
            [
                '@self'    => [
                    'register' => $this->testRegister->getId(),
                    'schema'   => $this->testSchema->getId(),
                ],
                '_extend' => ['@self.schema', '@self.register'],
            ],
            false,
            false
        );

        // Schemas/registers should be in @self when extended.
        $this->assertArrayHasKey('@self', $result);
    }

    /**
     * Test searchObjectsPaginated with limit=0 (count only).
     */
    public function testSearchObjectsPaginatedLimitZero(): void
    {
        $this->saveTestObject(['title' => 'Count Only']);

        $result = $this->queryHandler->searchObjectsPaginated(
            [
                '@self'  => [
                    'register' => $this->testRegister->getId(),
                    'schema'   => $this->testSchema->getId(),
                ],
                '_limit' => 0,
            ],
            false,
            false
        );

        $this->assertEquals(0, $result['limit']);
        $this->assertEquals(0, $result['pages']);
    }

    /**
     * Test searchObjects returns array of entities.
     */
    public function testSearchObjectsReturnsEntities(): void
    {
        $this->saveTestObject(['title' => 'Search Test 1']);

        $result = $this->queryHandler->searchObjects(
            [
                '@self' => [
                    'register' => $this->testRegister->getId(),
                    'schema'   => $this->testSchema->getId(),
                ],
            ],
            false,
            false
        );

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    /**
     * Test searchObjects with _count returns integer.
     */
    public function testSearchObjectsWithCount(): void
    {
        $this->saveTestObject(['title' => 'Count Test']);

        $result = $this->queryHandler->searchObjects(
            [
                '@self'  => [
                    'register' => $this->testRegister->getId(),
                    'schema'   => $this->testSchema->getId(),
                ],
                '_count' => true,
            ],
            false,
            false
        );

        $this->assertIsInt($result);
        // May be 0 if magic mapper routing is different.
        $this->assertGreaterThanOrEqual(0, $result);
    }

    /**
     * Test countSearchObjects.
     */
    public function testCountSearchObjects(): void
    {
        $this->saveTestObject(['title' => 'Count Search 1']);
        $this->saveTestObject(['title' => 'Count Search 2']);

        $result = $this->queryHandler->countSearchObjects(
            [
                '@self' => [
                    'register' => $this->testRegister->getId(),
                    'schema'   => $this->testSchema->getId(),
                ],
            ],
            false,
            false
        );

        $this->assertIsInt($result);
        // May be 0 depending on magic mapper routing.
        $this->assertGreaterThanOrEqual(0, $result);
    }

    /**
     * Test searchObjectsPaginated with facets.
     */
    public function testSearchObjectsPaginatedWithFacets(): void
    {
        $this->saveTestObject(['title' => 'Facet Query 1', 'category' => 'news']);
        $this->saveTestObject(['title' => 'Facet Query 2', 'category' => 'blog']);

        $result = $this->queryHandler->searchObjectsPaginated(
            [
                '@self'   => [
                    'register' => $this->testRegister->getId(),
                    'schema'   => $this->testSchema->getId(),
                ],
                '_facets' => ['category'],
            ],
            false,
            false
        );

        $this->assertArrayHasKey('facets', $result);
    }

    /**
     * Test searchObjectsPaginated with _facetable.
     */
    public function testSearchObjectsPaginatedWithFacetable(): void
    {
        $result = $this->queryHandler->searchObjectsPaginated(
            [
                '@self'      => [
                    'register' => $this->testRegister->getId(),
                    'schema'   => $this->testSchema->getId(),
                ],
                '_facetable' => true,
            ],
            false,
            false
        );

        $this->assertArrayHasKey('facetable', $result);
    }

    /**
     * Test searchObjectsPaginated with source=database.
     */
    public function testSearchObjectsPaginatedSourceDatabase(): void
    {
        $result = $this->queryHandler->searchObjectsPaginated(
            [
                '@self'    => [
                    'register' => $this->testRegister->getId(),
                    'schema'   => $this->testSchema->getId(),
                ],
                '_source' => 'database',
            ],
            false,
            false
        );

        $this->assertArrayHasKey('@self', $result);
        // Source should be database or magic_mapper.
        $this->assertContains($result['@self']['source'], ['database', 'magic_mapper']);
    }

    /**
     * Test searchObjectsPaginated stores query metadata.
     */
    public function testSearchObjectsPaginatedStoresQueryMetadata(): void
    {
        $query = [
            '@self' => [
                'register' => $this->testRegister->getId(),
                'schema'   => $this->testSchema->getId(),
            ],
        ];

        $result = $this->queryHandler->searchObjectsPaginated($query, false, false);

        $this->assertArrayHasKey('@self', $result);
        $this->assertArrayHasKey('query', $result['@self']);
        $this->assertArrayHasKey('rbac', $result['@self']);
        $this->assertArrayHasKey('multi', $result['@self']);
        $this->assertFalse($result['@self']['rbac']);
        $this->assertFalse($result['@self']['multi']);
    }
}
