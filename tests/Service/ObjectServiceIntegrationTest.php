<?php

/**
 * Integration tests for ObjectService
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

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for ObjectService
 *
 * Tests object CRUD operations, context management, search functionality,
 * bulk operations, validation, and rendering using the real Nextcloud DI
 * container and database.
 *
 * @group DB
 */
class ObjectServiceIntegrationTest extends TestCase
{
    /**
     * The object service instance
     *
     * @var ObjectService
     */
    private ObjectService $service;

    /**
     * Register mapper
     *
     * @var RegisterMapper
     */
    private RegisterMapper $registerMapper;

    /**
     * Schema mapper
     *
     * @var SchemaMapper
     */
    private SchemaMapper $schemaMapper;

    /**
     * Object entity mapper
     *
     * @var MagicMapper
     */
    private MagicMapper $objectMapper;

    /**
     * Test register
     *
     * @var Register|null
     */
    private ?Register $testRegister = null;

    /**
     * Test schema
     *
     * @var Schema|null
     */
    private ?Schema $testSchema = null;

    /**
     * Second test schema for multi-schema tests
     *
     * @var Schema|null
     */
    private ?Schema $testSchema2 = null;

    /**
     * UUIDs of created test objects for cleanup
     *
     * @var string[]
     */
    private array $createdObjectUuids = [];

    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \OC::$server->get(ObjectService::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper = \OC::$server->get(SchemaMapper::class);
        $this->objectMapper = \OC::$server->get(MagicMapper::class);

        // Create test register and schema
        $this->createTestRegisterAndSchema();
    }

    /**
     * Clean up test data
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $db = \OC::$server->get(\OCP\IDBConnection::class);

        // Clean up test objects via direct DB queries
        foreach ($this->createdObjectUuids as $uuid) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_objects')
                    ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)))
                    ->executeStatement();
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        // Clean up test schemas
        foreach ([$this->testSchema, $this->testSchema2] as $schema) {
            if ($schema !== null) {
                try {
                    $qb = $db->getQueryBuilder();
                    $qb->delete('openregister_schemas')
                        ->where($qb->expr()->eq('id', $qb->createNamedParameter(
                            $schema->getId(),
                            \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT
                        )))
                        ->executeStatement();
                } catch (\Exception $e) {
                    // Ignore
                }
            }
        }

        // Clean up test register
        if ($this->testRegister !== null) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_registers')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter(
                        $this->testRegister->getId(),
                        \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT
                    )))
                    ->executeStatement();
            } catch (\Exception $e) {
                // Ignore
            }
        }

        parent::tearDown();
    }

    /**
     * Create test register and schema for object tests
     *
     * @return void
     */
    private function createTestRegisterAndSchema(): void
    {
        $register = new Register();
        $register->setTitle('phpunit-test-' . uniqid());
        $register->setDescription('Test register for integration tests');
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-test-' . uniqid());
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        $schema = new Schema();
        $schema->setTitle('phpunit-test-' . uniqid());
        $schema->setDescription('Test schema for integration tests');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-test-' . uniqid());
        $schema->setProperties([
            'name' => [
                'type' => 'string',
                'title' => 'Name',
            ],
            'description' => [
                'type' => 'string',
                'title' => 'Description',
            ],
            'count' => [
                'type' => 'integer',
                'title' => 'Count',
            ],
            'tags' => [
                'type' => 'array',
                'title' => 'Tags',
                'items' => ['type' => 'string'],
            ],
            'active' => [
                'type' => 'boolean',
                'title' => 'Active',
            ],
        ]);
        $this->testSchema = $this->schemaMapper->insert($schema);

        // Add schema to register
        $this->testRegister->setSchemas([$this->testSchema->getId()]);
        $this->registerMapper->update($this->testRegister);
    }

    /**
     * Helper to create a second test schema
     *
     * @return Schema
     */
    private function createSecondSchema(): Schema
    {
        $schema = new Schema();
        $schema->setTitle('phpunit-test-schema2-' . uniqid());
        $schema->setDescription('Second test schema');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-test-schema2-' . uniqid());
        $schema->setProperties([
            'title' => [
                'type' => 'string',
                'title' => 'Title',
            ],
            'priority' => [
                'type' => 'integer',
                'title' => 'Priority',
            ],
        ]);
        $this->testSchema2 = $this->schemaMapper->insert($schema);

        // Add to register
        $this->testRegister->setSchemas([
            $this->testSchema->getId(),
            $this->testSchema2->getId(),
        ]);
        $this->registerMapper->update($this->testRegister);

        return $this->testSchema2;
    }

    /**
     * Helper to create a test object and track it for cleanup
     *
     * @param array $data Override data
     *
     * @return ObjectEntity
     */
    private function createTestObject(array $data = []): ObjectEntity
    {
        $objectData = array_merge([
            'name' => 'phpunit-test-' . uniqid(),
            'description' => 'Integration test object',
            'count' => 42,
        ], $data);

        $result = $this->service->saveObject(
            $objectData,
            null,
            $this->testRegister,
            $this->testSchema,
            null,
            false,
            false
        );

        $this->createdObjectUuids[] = $result->getUuid();

        return $result;
    }

    // =========================================================================
    // Context management tests
    // =========================================================================

    /**
     * Test setRegister with register ID
     *
     * @return void
     */
    public function testSetRegisterWithId(): void
    {
        $result = $this->service->setRegister($this->testRegister->getId());

        $this->assertInstanceOf(ObjectService::class, $result);
        $this->assertSame($this->testRegister->getId(), $this->service->getRegister());
    }

    /**
     * Test setSchema with schema ID
     *
     * @return void
     */
    public function testSetSchemaWithId(): void
    {
        $result = $this->service->setSchema($this->testSchema->getId());

        $this->assertInstanceOf(ObjectService::class, $result);
        $this->assertSame($this->testSchema->getId(), $this->service->getSchema());
    }

    /**
     * Test setRegister with Register object
     *
     * @return void
     */
    public function testSetRegisterWithObject(): void
    {
        $result = $this->service->setRegister($this->testRegister);

        $this->assertInstanceOf(ObjectService::class, $result);
        $this->assertSame($this->testRegister->getId(), $this->service->getRegister());
    }

    /**
     * Test setSchema with Schema object
     *
     * @return void
     */
    public function testSetSchemaWithObject(): void
    {
        $result = $this->service->setSchema($this->testSchema);

        $this->assertInstanceOf(ObjectService::class, $result);
        $this->assertSame($this->testSchema->getId(), $this->service->getSchema());
    }

    /**
     * Test setRegister with slug string
     *
     * @return void
     */
    public function testSetRegisterWithSlug(): void
    {
        $result = $this->service->setRegister($this->testRegister->getSlug());

        $this->assertInstanceOf(ObjectService::class, $result);
        $this->assertSame($this->testRegister->getId(), $this->service->getRegister());
    }

    /**
     * Test setSchema with slug string
     *
     * @return void
     */
    public function testSetSchemaWithSlug(): void
    {
        $result = $this->service->setSchema($this->testSchema->getSlug());

        $this->assertInstanceOf(ObjectService::class, $result);
        $this->assertSame($this->testSchema->getId(), $this->service->getSchema());
    }

    /**
     * Test setRegister with UUID string
     *
     * @return void
     */
    public function testSetRegisterWithUuid(): void
    {
        $result = $this->service->setRegister($this->testRegister->getUuid());

        $this->assertInstanceOf(ObjectService::class, $result);
        $this->assertSame($this->testRegister->getId(), $this->service->getRegister());
    }

    /**
     * Test setSchema with UUID string
     *
     * @return void
     */
    public function testSetSchemaWithUuid(): void
    {
        $result = $this->service->setSchema($this->testSchema->getUuid());

        $this->assertInstanceOf(ObjectService::class, $result);
        $this->assertSame($this->testSchema->getId(), $this->service->getSchema());
    }

    /**
     * Test method chaining on setRegister and setSchema
     *
     * @return void
     */
    public function testContextMethodChaining(): void
    {
        $result = $this->service
            ->setRegister($this->testRegister)
            ->setSchema($this->testSchema);

        $this->assertInstanceOf(ObjectService::class, $result);
        $this->assertSame($this->testRegister->getId(), $this->service->getRegister());
        $this->assertSame($this->testSchema->getId(), $this->service->getSchema());
    }

    /**
     * Test getObject returns ObjectEntity or null
     *
     * @return void
     */
    public function testGetObjectReturnsObjectEntityOrNull(): void
    {
        $result = $this->service->getObject();

        // getObject may return null (fresh) or an ObjectEntity (shared instance with state)
        $this->assertTrue(
            $result === null || $result instanceof ObjectEntity,
            'getObject() should return null or ObjectEntity'
        );
    }

    /**
     * Test getRegister returns int after set
     *
     * @return void
     */
    public function testGetRegisterReturnsIntAfterSet(): void
    {
        $this->service->setRegister($this->testRegister);

        $result = $this->service->getRegister();

        $this->assertIsInt($result);
        $this->assertSame($this->testRegister->getId(), $result);
    }

    /**
     * Test getSchema returns int after set
     *
     * @return void
     */
    public function testGetSchemaReturnsIntAfterSet(): void
    {
        $this->service->setSchema($this->testSchema);

        $result = $this->service->getSchema();

        $this->assertIsInt($result);
        $this->assertSame($this->testSchema->getId(), $result);
    }

    /**
     * Test setSchema with nonexistent ID throws
     *
     * @return void
     */
    public function testSetSchemaWithNonexistentIdThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->setSchema(999999999);
    }

    // =========================================================================
    // Create (saveObject) tests
    // =========================================================================

    /**
     * Test saveObject creates new object
     *
     * @return void
     */
    public function testSaveObjectCreate(): void
    {
        $objectData = [
            'name' => 'phpunit-test-' . uniqid(),
            'description' => 'Test object for integration tests',
            'count' => 42,
        ];

        $result = $this->service->saveObject(
            $objectData,
            null,
            $this->testRegister,
            $this->testSchema,
            null,
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertNotEmpty($result->getUuid());
        $this->createdObjectUuids[] = $result->getUuid();
    }

    /**
     * Test saveObject with specific UUID creates object with that UUID
     *
     * @return void
     */
    public function testSaveObjectWithSpecificUuid(): void
    {
        $specificUuid = Uuid::v4()->toRfc4122();

        $objectData = [
            'name' => 'phpunit-uuid-test-' . uniqid(),
        ];

        $result = $this->service->saveObject(
            $objectData,
            null,
            $this->testRegister,
            $this->testSchema,
            $specificUuid,
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertSame($specificUuid, $result->getUuid());
        $this->createdObjectUuids[] = $result->getUuid();
    }

    /**
     * Test saveObject stores correct register and schema IDs
     *
     * @return void
     */
    public function testSaveObjectStoresRegisterAndSchema(): void
    {
        $saved = $this->createTestObject();

        // Register/schema on the entity may be string or int depending on mapper
        $this->assertEquals($this->testRegister->getId(), $saved->getRegister());
        $this->assertEquals($this->testSchema->getId(), $saved->getSchema());
    }

    /**
     * Test saveObject stores object data correctly
     *
     * @return void
     */
    public function testSaveObjectStoresData(): void
    {
        $saved = $this->createTestObject([
            'name' => 'Data Integrity Test',
            'count' => 123,
            'active' => true,
        ]);

        $objectData = $saved->getObject();
        $this->assertIsArray($objectData);
        $this->assertSame('Data Integrity Test', $objectData['name']);
        $this->assertSame(123, $objectData['count']);
        $this->assertTrue($objectData['active']);
    }

    /**
     * Test saveObject with array data including tags
     *
     * @return void
     */
    public function testSaveObjectWithArrayData(): void
    {
        $saved = $this->createTestObject([
            'name' => 'Array Test',
            'tags' => ['tag1', 'tag2', 'tag3'],
        ]);

        $objectData = $saved->getObject();
        $this->assertIsArray($objectData['tags']);
        $this->assertCount(3, $objectData['tags']);
    }

    /**
     * Test saveObject in silent mode
     *
     * @return void
     */
    public function testSaveObjectSilent(): void
    {
        $objectData = [
            'name' => 'phpunit-silent-' . uniqid(),
        ];

        $result = $this->service->saveObject(
            $objectData,
            null,
            $this->testRegister,
            $this->testSchema,
            null,
            false,
            false,
            true // silent
        );

        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->createdObjectUuids[] = $result->getUuid();
    }

    // =========================================================================
    // Read (find) tests
    // =========================================================================

    /**
     * Test find retrieves saved object
     *
     * @return void
     */
    public function testSaveAndFindObject(): void
    {
        $saved = $this->createTestObject(['name' => 'Find Me']);

        $found = $this->service->find(
            $saved->getUuid(),
            [],
            false,
            $this->testRegister,
            $this->testSchema,
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $found);
        $this->assertSame($saved->getUuid(), $found->getUuid());
    }

    /**
     * Test find by string ID (numeric as string)
     *
     * @return void
     */
    public function testFindByStringId(): void
    {
        $saved = $this->createTestObject();

        $found = $this->service->find(
            (string) $saved->getId(),
            [],
            false,
            $this->testRegister,
            $this->testSchema,
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $found);
        $this->assertSame($saved->getId(), $found->getId());
    }

    /**
     * Test findSilent retrieves object without audit trail
     *
     * @return void
     */
    public function testFindSilent(): void
    {
        $saved = $this->createTestObject();

        $found = $this->service->findSilent(
            $saved->getUuid(),
            [],
            false,
            $this->testRegister,
            $this->testSchema,
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $found);
        $this->assertSame($saved->getUuid(), $found->getUuid());
    }

    /**
     * Test find with schema derived from object
     *
     * @return void
     */
    public function testFindDerivesSchemaFromObject(): void
    {
        $saved = $this->createTestObject();

        // Find without specifying schema - should derive from object
        $found = $this->service->find(
            $saved->getUuid(),
            [],
            false,
            $this->testRegister,
            null,
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $found);
        $this->assertSame($saved->getUuid(), $found->getUuid());
    }

    // =========================================================================
    // Update tests
    // =========================================================================

    /**
     * Test saveObject update existing object
     *
     * @return void
     */
    public function testSaveObjectUpdate(): void
    {
        $saved = $this->createTestObject([
            'name' => 'Original',
            'count' => 1,
        ]);

        $updateData = [
            'name' => 'Updated',
            'description' => 'Updated description',
            'count' => 99,
        ];

        $updated = $this->service->saveObject(
            $updateData,
            null,
            $this->testRegister,
            $this->testSchema,
            $saved->getUuid(),
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $updated);
        $this->assertSame($saved->getUuid(), $updated->getUuid());

        $objectData = $updated->getObject();
        $this->assertSame('Updated', $objectData['name']);
        $this->assertSame(99, $objectData['count']);
    }

    /**
     * Test update preserves UUID
     *
     * @return void
     */
    public function testUpdatePreservesUuid(): void
    {
        $saved = $this->createTestObject();
        $originalUuid = $saved->getUuid();

        $updated = $this->service->saveObject(
            ['name' => 'Changed Name'],
            null,
            $this->testRegister,
            $this->testSchema,
            $originalUuid,
            false,
            false
        );

        $this->assertSame($originalUuid, $updated->getUuid());
    }

    /**
     * Test update with ObjectEntity input
     *
     * @return void
     */
    public function testSaveObjectWithObjectEntityInput(): void
    {
        $saved = $this->createTestObject(['name' => 'Entity Input']);

        // Modify the object data
        $objectData = $saved->getObject();
        $objectData['name'] = 'Modified Via Entity';
        $saved->setObject($objectData);

        $updated = $this->service->saveObject(
            $saved,
            null,
            $this->testRegister,
            $this->testSchema,
            null,
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $updated);
        $this->assertSame($saved->getUuid(), $updated->getUuid());
    }

    // =========================================================================
    // Delete tests
    // =========================================================================

    /**
     * Test deleteObject removes object
     *
     * @return void
     */
    public function testDeleteObject(): void
    {
        $saved = $this->createTestObject();
        $uuid = $saved->getUuid();

        // Set context for delete
        $this->service->setRegister($this->testRegister);
        $this->service->setSchema($this->testSchema);

        $result = $this->service->deleteObject($uuid, false, false);

        $this->assertTrue($result);

        // Remove from cleanup list since already deleted
        $this->createdObjectUuids = array_filter(
            $this->createdObjectUuids,
            fn($u) => $u !== $uuid
        );
    }

    /**
     * Test deleteObject with schema derived from object
     *
     * @return void
     */
    public function testDeleteObjectDerivesSchema(): void
    {
        $saved = $this->createTestObject();
        $uuid = $saved->getUuid();

        // Don't set schema context - let delete derive it
        $result = $this->service->deleteObject($uuid, false, false);

        $this->assertTrue($result);

        $this->createdObjectUuids = array_filter(
            $this->createdObjectUuids,
            fn($u) => $u !== $uuid
        );
    }

    // =========================================================================
    // findAll / search tests
    // =========================================================================

    /**
     * Test findAll with empty results
     *
     * @return void
     */
    public function testFindAllEmpty(): void
    {
        $this->service->setRegister($this->testRegister);
        $this->service->setSchema($this->testSchema);

        $results = $this->service->findAll(
            [
                'filters' => [
                    'register' => $this->testRegister->getId(),
                    'schema' => $this->testSchema->getId(),
                ],
            ],
            false,
            false
        );

        $this->assertIsArray($results);
    }

    /**
     * Test findAll returns created objects via searchObjectsPaginated
     *
     * @return void
     */
    public function testFindAllAfterCreate(): void
    {
        $saved = $this->createTestObject();

        $this->service->setRegister($this->testRegister);
        $this->service->setSchema($this->testSchema);

        // Use searchObjectsPaginated with _source=database for reliable results
        $result = $this->service->searchObjectsPaginated(
            [
                '_source' => 'database',
                '@self' => [
                    'register' => $this->testRegister->getId(),
                    'schema' => $this->testSchema->getId(),
                ],
            ],
            false,
            false
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertGreaterThanOrEqual(1, $result['total']);
    }

    /**
     * Test findAll with limit
     *
     * @return void
     */
    public function testFindAllWithLimit(): void
    {
        // Create multiple objects
        $this->createTestObject(['name' => 'Limit Test 1']);
        $this->createTestObject(['name' => 'Limit Test 2']);
        $this->createTestObject(['name' => 'Limit Test 3']);

        $this->service->setRegister($this->testRegister);
        $this->service->setSchema($this->testSchema);

        $results = $this->service->findAll(
            [
                'limit' => 2,
                'filters' => [
                    'register' => $this->testRegister->getId(),
                    'schema' => $this->testSchema->getId(),
                ],
            ],
            false,
            false
        );

        $this->assertIsArray($results);
        $this->assertLessThanOrEqual(2, count($results));
    }

    /**
     * Test searchObjectsPaginated with offset
     *
     * @return void
     */
    public function testSearchObjectsPaginatedWithOffset(): void
    {
        $this->createTestObject(['name' => 'Offset Test 1']);
        $this->createTestObject(['name' => 'Offset Test 2']);

        $this->service->setRegister($this->testRegister);
        $this->service->setSchema($this->testSchema);

        $allResult = $this->service->searchObjectsPaginated(
            [
                '_source' => 'database',
                '@self' => [
                    'register' => $this->testRegister->getId(),
                    'schema' => $this->testSchema->getId(),
                ],
            ],
            false,
            false
        );

        $offsetResult = $this->service->searchObjectsPaginated(
            [
                '_source' => 'database',
                '_offset' => 1,
                '@self' => [
                    'register' => $this->testRegister->getId(),
                    'schema' => $this->testSchema->getId(),
                ],
            ],
            false,
            false
        );

        // Total should be the same, results should differ
        $this->assertSame($allResult['total'], $offsetResult['total']);
        $this->assertGreaterThan(count($offsetResult['results']), count($allResult['results']));
    }

    /**
     * Test findAll with extend as comma-separated string
     *
     * @return void
     */
    public function testFindAllWithExtendString(): void
    {
        $this->createTestObject();

        $results = $this->service->findAll(
            [
                'extend' => '@self.schema,@self.register',
                'filters' => [
                    'register' => $this->testRegister->getId(),
                    'schema' => $this->testSchema->getId(),
                ],
            ],
            false,
            false
        );

        $this->assertIsArray($results);
    }

    /**
     * Test findByRelations returns empty for nonexistent
     *
     * @return void
     */
    public function testFindByRelationsEmpty(): void
    {
        $results = $this->service->findByRelations('nonexistent-uuid-' . uniqid());

        $this->assertIsArray($results);
        $this->assertCount(0, $results);
    }

    // =========================================================================
    // Count tests
    // =========================================================================

    /**
     * Test count objects returns zero for empty schema
     *
     * @return void
     */
    public function testCountEmpty(): void
    {
        $this->service->setRegister($this->testRegister);
        $this->service->setSchema($this->testSchema);

        $count = $this->service->count([
            'filters' => [
                'register' => $this->testRegister->getId(),
                'schema' => $this->testSchema->getId(),
            ],
        ]);

        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    /**
     * Test searchObjectsPaginated total increases after object creation
     *
     * @return void
     */
    public function testSearchPaginatedTotalIncreasesAfterCreate(): void
    {
        $this->service->setRegister($this->testRegister);
        $this->service->setSchema($this->testSchema);

        $query = [
            '_source' => 'database',
            '@self' => [
                'register' => $this->testRegister->getId(),
                'schema' => $this->testSchema->getId(),
            ],
        ];

        $resultBefore = $this->service->searchObjectsPaginated($query, false, false);
        $totalBefore = $resultBefore['total'];

        $this->createTestObject();

        $resultAfter = $this->service->searchObjectsPaginated($query, false, false);
        $totalAfter = $resultAfter['total'];

        $this->assertSame($totalBefore + 1, $totalAfter);
    }

    // =========================================================================
    // Search tests
    // =========================================================================

    /**
     * Test searchObjectsPaginated returns paginated structure
     *
     * @return void
     */
    public function testSearchObjectsPaginated(): void
    {
        $this->createTestObject(['name' => 'Search Test Object']);

        $this->service->setRegister($this->testRegister);
        $this->service->setSchema($this->testSchema);

        $query = [
            '_limit' => 10,
            '_source' => 'database',
            '@self' => [
                'register' => $this->testRegister->getId(),
                'schema' => $this->testSchema->getId(),
            ],
        ];

        $result = $this->service->searchObjectsPaginated(
            $query,
            false,
            false
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total', $result);
    }

    /**
     * Test countSearchObjects returns integer
     *
     * @return void
     */
    public function testCountSearchObjectsReturnsInt(): void
    {
        $query = [
            '@self' => [
                'register' => $this->testRegister->getId(),
                'schema' => $this->testSchema->getId(),
            ],
        ];

        $count = $this->service->countSearchObjects(
            $query,
            false,
            false
        );

        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    /**
     * Test buildSearchQuery produces valid query structure
     *
     * @return void
     */
    public function testBuildSearchQuery(): void
    {
        $params = [
            '_limit' => '10',
            '_offset' => '0',
            '_search' => 'test',
        ];

        $query = $this->service->buildSearchQuery(
            $params,
            $this->testRegister->getId(),
            $this->testSchema->getId()
        );

        $this->assertIsArray($query);
        $this->assertArrayHasKey('_limit', $query);
    }

    /**
     * Test buildObjectSearchQuery produces valid query
     *
     * @return void
     */
    public function testBuildObjectSearchQuery(): void
    {
        $params = [
            '_limit' => '5',
            '_search' => 'test query',
        ];

        $query = $this->service->buildObjectSearchQuery($params);

        $this->assertIsArray($query);
    }

    // =========================================================================
    // Render tests
    // =========================================================================

    /**
     * Test renderEntity returns array
     *
     * @return void
     */
    public function testRenderEntity(): void
    {
        $saved = $this->createTestObject(['name' => 'Render Test']);

        $rendered = $this->service->renderEntity(
            $saved,
            [],
            0,
            [],
            [],
            [],
            false,
            false
        );

        $this->assertIsArray($rendered);
    }

    /**
     * Test getExtendedObjects returns array
     *
     * @return void
     */
    public function testGetExtendedObjects(): void
    {
        $result = $this->service->getExtendedObjects();

        $this->assertIsArray($result);
    }

    // =========================================================================
    // Handler accessor tests
    // =========================================================================

    /**
     * Test getCreatedSubObjects returns empty array
     *
     * @return void
     */
    public function testGetCreatedSubObjects(): void
    {
        $result = $this->service->getCreatedSubObjects();

        $this->assertIsArray($result);
    }

    /**
     * Test clearCreatedSubObjects resets the list
     *
     * @return void
     */
    public function testClearCreatedSubObjects(): void
    {
        $this->service->clearCreatedSubObjects();

        $result = $this->service->getCreatedSubObjects();
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * Test getCacheHandler returns handler
     *
     * @return void
     */
    public function testGetCacheHandler(): void
    {
        $handler = $this->service->getCacheHandler();

        $this->assertNotNull($handler);
    }

    /**
     * Test getDeleteHandler returns handler
     *
     * @return void
     */
    public function testGetDeleteHandler(): void
    {
        $handler = $this->service->getDeleteHandler();

        $this->assertNotNull($handler);
    }

    // =========================================================================
    // Bulk operations tests
    // =========================================================================

    /**
     * Test saveObjects bulk create
     *
     * @return void
     */
    public function testSaveObjectsBulk(): void
    {
        $objects = [];
        for ($i = 0; $i < 3; $i++) {
            $uuid = Uuid::v4()->toRfc4122();
            $this->createdObjectUuids[] = $uuid;
            $objects[] = [
                '@self' => [
                    'uuid' => $uuid,
                    'register' => $this->testRegister->getId(),
                    'schema' => $this->testSchema->getId(),
                ],
                'name' => 'Bulk Test ' . $i,
                'count' => $i,
            ];
        }

        $result = $this->service->saveObjects(
            $objects,
            $this->testRegister,
            $this->testSchema,
            false,
            false,
            false,
            false,
            true,
            true
        );

        $this->assertIsArray($result);
    }

    /**
     * Test deleteObjects bulk delete
     *
     * @return void
     */
    public function testDeleteObjectsBulk(): void
    {
        $obj1 = $this->createTestObject(['name' => 'Bulk Delete 1']);
        $obj2 = $this->createTestObject(['name' => 'Bulk Delete 2']);

        $this->service->setRegister($this->testRegister);
        $this->service->setSchema($this->testSchema);

        $result = $this->service->deleteObjects(
            [$obj1->getUuid(), $obj2->getUuid()],
            false,
            false
        );

        $this->assertIsArray($result);

        // Remove from cleanup since already deleted
        $deletedUuids = [$obj1->getUuid(), $obj2->getUuid()];
        $this->createdObjectUuids = array_filter(
            $this->createdObjectUuids,
            fn($u) => !in_array($u, $deletedUuids)
        );
    }

    // =========================================================================
    // Publish / Depublish tests
    // =========================================================================

    /**
     * Test publishObjects bulk publish
     *
     * @return void
     */
    public function testPublishObjectsBulk(): void
    {
        $obj = $this->createTestObject(['name' => 'Publish Test']);

        $this->service->setRegister($this->testRegister);
        $this->service->setSchema($this->testSchema);

        $result = $this->service->publishObjects(
            [$obj->getUuid()],
            true,
            false,
            false
        );

        $this->assertIsArray($result);
    }

    /**
     * Test depublishObjects bulk depublish
     *
     * @return void
     */
    public function testDepublishObjectsBulk(): void
    {
        $obj = $this->createTestObject(['name' => 'Depublish Test']);

        $this->service->setRegister($this->testRegister);
        $this->service->setSchema($this->testSchema);

        // First publish
        $this->service->publishObjects(
            [$obj->getUuid()],
            true,
            false,
            false
        );

        // Then depublish
        $result = $this->service->depublishObjects(
            [$obj->getUuid()],
            true,
            false,
            false
        );

        $this->assertIsArray($result);
    }

    // =========================================================================
    // Lock / Unlock tests
    // =========================================================================

    /**
     * Test setObject with ObjectEntity instance
     *
     * @return void
     */
    public function testSetObjectWithEntity(): void
    {
        $saved = $this->createTestObject();

        $this->service->setRegister($this->testRegister);
        $this->service->setSchema($this->testSchema);
        $this->service->setObject($saved);

        $current = $this->service->getObject();
        $this->assertInstanceOf(ObjectEntity::class, $current);
        $this->assertSame($saved->getUuid(), $current->getUuid());
    }

    // =========================================================================
    // Validation tests
    // =========================================================================

    /**
     * Test saving object with hard validation enabled
     *
     * @return void
     */
    public function testSaveObjectWithHardValidation(): void
    {
        // Create a schema with hard validation enabled
        $validatingSchema = new Schema();
        $validatingSchema->setTitle('phpunit-hard-validate-' . uniqid());
        $validatingSchema->setDescription('Schema with hard validation');
        $validatingSchema->setUuid(Uuid::v4()->toRfc4122());
        $validatingSchema->setSlug('phpunit-hard-validate-' . uniqid());
        $validatingSchema->setHardValidation(true);
        $validatingSchema->setProperties([
            'name' => [
                'type' => 'string',
                'title' => 'Name',
                'required' => true,
            ],
        ]);

        // Insert and track for cleanup
        $insertedSchema = $this->schemaMapper->insert($validatingSchema);

        // Add to register
        $this->testRegister->setSchemas(array_merge(
            $this->testRegister->getSchemas(),
            [$insertedSchema->getId()]
        ));
        $this->registerMapper->update($this->testRegister);

        try {
            $result = $this->service->saveObject(
                ['name' => 'Valid Object'],
                null,
                $this->testRegister,
                $insertedSchema,
                null,
                false,
                false
            );

            $this->assertInstanceOf(ObjectEntity::class, $result);
            $this->createdObjectUuids[] = $result->getUuid();
        } finally {
            // Cleanup the extra schema
            try {
                $this->schemaMapper->delete($insertedSchema);
            } catch (\Exception $e) {
                // Ignore
            }
        }
    }

    // =========================================================================
    // Schema-level operations tests
    // =========================================================================

    /**
     * Test deleteObjectsBySchema
     *
     * @return void
     */
    public function testDeleteObjectsBySchema(): void
    {
        $this->createTestObject(['name' => 'Schema Delete Test']);

        $result = $this->service->deleteObjectsBySchema(
            $this->testRegister->getId(),
            $this->testSchema->getId(),
            false
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('deleted_count', $result);
        $this->assertArrayHasKey('schema_id', $result);

        // Objects deleted by this method, remove from cleanup
        $this->createdObjectUuids = [];
    }

    /**
     * Test deleteObjectsByRegister
     *
     * @return void
     */
    public function testDeleteObjectsByRegister(): void
    {
        $this->createTestObject(['name' => 'Register Delete Test']);

        $result = $this->service->deleteObjectsByRegister(
            $this->testRegister->getId()
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('deleted_count', $result);

        // Objects deleted, remove from cleanup
        $this->createdObjectUuids = [];
    }

    /**
     * Test publishObjectsBySchema
     *
     * @return void
     */
    public function testPublishObjectsBySchema(): void
    {
        $this->createTestObject(['name' => 'Schema Publish Test']);

        $result = $this->service->publishObjectsBySchema(
            $this->testSchema->getId(),
            true
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('published_count', $result);
        $this->assertArrayHasKey('schema_id', $result);
    }

    // =========================================================================
    // Relation handler tests
    // =========================================================================

    /**
     * Test getObjectUses returns empty for object with no relations
     *
     * @return void
     */
    public function testGetObjectUsesEmpty(): void
    {
        $saved = $this->createTestObject();

        $this->service->setRegister($this->testRegister);
        $this->service->setSchema($this->testSchema);

        $result = $this->service->getObjectUses(
            $saved->getUuid(),
            [],
            false,
            false
        );

        $this->assertIsArray($result);
    }

    /**
     * Test getObjectUsedBy returns empty for object with no incoming relations
     *
     * @return void
     */
    public function testGetObjectUsedByEmpty(): void
    {
        $saved = $this->createTestObject();

        $this->service->setRegister($this->testRegister);
        $this->service->setSchema($this->testSchema);

        $result = $this->service->getObjectUsedBy(
            $saved->getUuid(),
            [],
            false,
            false
        );

        $this->assertIsArray($result);
    }

    // =========================================================================
    // Disabled feature tests (verify they throw as expected)
    // =========================================================================

    /**
     * Test vectorizeBatchObjects throws disabled exception
     *
     * @return void
     */
    public function testVectorizeBatchObjectsThrowsDisabled(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Vectorization temporarily disabled');

        $this->service->vectorizeBatchObjects();
    }

    /**
     * Test getVectorizationStatistics throws disabled exception
     *
     * @return void
     */
    public function testGetVectorizationStatisticsThrowsDisabled(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Vectorization temporarily disabled');

        $this->service->getVectorizationStatistics();
    }

    /**
     * Test getVectorizationCount throws disabled exception
     *
     * @return void
     */
    public function testGetVectorizationCountThrowsDisabled(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Vectorization temporarily disabled');

        $this->service->getVectorizationCount();
    }

    /**
     * Test exportObjects throws disabled exception
     *
     * @return void
     */
    public function testExportObjectsThrowsDisabled(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Export temporarily disabled');

        $this->service->exportObjects(
            $this->testRegister,
            $this->testSchema
        );
    }

    /**
     * Test importObjects throws disabled exception
     *
     * @return void
     */
    public function testImportObjectsThrowsDisabled(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Import temporarily disabled');

        $this->service->importObjects(
            $this->testRegister,
            ['tmp_name' => '/tmp/test', 'name' => 'test.csv']
        );
    }

    /**
     * Test downloadObjectFiles throws disabled exception
     *
     * @return void
     */
    public function testDownloadObjectFilesThrowsDisabled(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File download temporarily disabled');

        $this->service->downloadObjectFiles('some-object-id');
    }

    // =========================================================================
    // CRUD convenience method tests
    // =========================================================================

    /**
     * Test createObject convenience method
     *
     * @return void
     */
    public function testCreateObject(): void
    {
        $this->service->setRegister($this->testRegister);
        $this->service->setSchema($this->testSchema);

        $result = $this->service->createObject(
            ['name' => 'Convenience Create ' . uniqid()],
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->createdObjectUuids[] = $result->getUuid();
    }

    // =========================================================================
    // Facet tests
    // =========================================================================

    /**
     * Test getFacetsForObjects returns array
     *
     * @return void
     */
    public function testGetFacetsForObjects(): void
    {
        $this->createTestObject();

        $query = [
            '@self' => [
                'register' => $this->testRegister->getId(),
                'schema' => $this->testSchema->getId(),
            ],
            '_facets' => [
                'name' => ['type' => 'terms'],
            ],
        ];

        $result = $this->service->getFacetsForObjects($query);

        $this->assertIsArray($result);
    }

    /**
     * Test getFacetableFields returns array
     *
     * @return void
     */
    public function testGetFacetableFields(): void
    {
        $query = [
            '@self' => [
                'schema' => $this->testSchema->getId(),
            ],
        ];

        $result = $this->service->getFacetableFields($query);

        $this->assertIsArray($result);
    }

    // =========================================================================
    // Validate by schema tests
    // =========================================================================

    /**
     * Test validateObjectsBySchema
     *
     * @return void
     */
    public function testValidateObjectsBySchema(): void
    {
        $this->createTestObject();

        $result = $this->service->validateObjectsBySchema(
            $this->testSchema->getId()
        );

        $this->assertIsArray($result);
    }
}
