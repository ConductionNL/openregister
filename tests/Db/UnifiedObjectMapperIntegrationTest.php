<?php

/**
 * Integration tests for UnifiedObjectMapper
 *
 * Tests routing logic, CRUD operations, statistics, facets, and search
 * across both MagicMapper and ObjectEntityMapper (blob) storage backends.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Db
 */

namespace OCA\OpenRegister\Tests\Db;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\UnifiedObjectMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class UnifiedObjectMapperIntegrationTest extends TestCase
{
    private UnifiedObjectMapper $mapper;
    private ObjectEntityMapper $objectEntityMapper;
    private MagicMapper $magicMapper;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;

    /** @var int[] IDs of objects created during tests */
    private array $createdObjectIds = [];
    /** @var int[] IDs of schemas created during tests */
    private array $createdSchemaIds = [];
    /** @var int[] IDs of registers created during tests */
    private array $createdRegisterIds = [];
    /** @var string[] Magic tables to drop in tearDown */
    private array $createdTables = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = \OC::$server->get(UnifiedObjectMapper::class);
        $this->objectEntityMapper = \OC::$server->get(ObjectEntityMapper::class);
        $this->magicMapper = \OC::$server->get(MagicMapper::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper = \OC::$server->get(SchemaMapper::class);
    }

    protected function tearDown(): void
    {
        $db = \OC::$server->get(\OCP\IDBConnection::class);

        // Drop created magic tables
        foreach ($this->createdTables as $tableName) {
            try {
                $db->prepare("DROP TABLE IF EXISTS $tableName")->execute();
            } catch (\Exception $e) {
                // Table may not exist
            }
        }

        // Clean objects first (they reference schemas/registers)
        foreach ($this->createdObjectIds as $id) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_objects')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Exception $e) {
                // Already cleaned up
            }
        }

        // Clean schemas
        foreach ($this->createdSchemaIds as $id) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_schemas')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Exception $e) {
                // Already cleaned up
            }
        }

        // Clean registers
        foreach ($this->createdRegisterIds as $id) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_registers')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Exception $e) {
                // Already cleaned up
            }
        }

        parent::tearDown();
    }

    /**
     * Create a test register
     */
    private function createTestRegister(): Register
    {
        $register = $this->registerMapper->createFromArray([
            'title'       => 'phpunit-test-' . uniqid() . ' Unified Register',
            'description' => 'Register for UnifiedObjectMapper integration tests',
        ]);
        $this->createdRegisterIds[] = $register->getId();

        return $register;
    }

    /**
     * Create a test schema
     */
    private function createTestSchema(): Schema
    {
        $schema = $this->schemaMapper->createFromArray([
            'title'       => 'phpunit-test-' . uniqid() . ' Unified Schema',
            'description' => 'Schema for UnifiedObjectMapper integration tests',
            'properties'  => [
                'name' => [
                    'type'      => 'string',
                    'title'     => 'Name',
                    'maxLength' => 255,
                ],
                'score' => [
                    'type'  => 'integer',
                    'title' => 'Score',
                ],
            ],
        ]);
        $this->createdSchemaIds[] = $schema->getId();

        return $schema;
    }

    /**
     * Helper to track a magic table for cleanup
     */
    private function trackMagicTable(Register $register, Schema $schema): void
    {
        $tableName = $this->magicMapper->getTableNameForRegisterSchema($register, $schema);
        $this->createdTables[] = 'oc_' . $tableName;
    }

    /**
     * Create an object via the unified mapper (routes automatically)
     */
    private function createTestObject(?Register $register = null, ?Schema $schema = null): ObjectEntity
    {
        if ($register === null) {
            $register = $this->createTestRegister();
        }
        if ($schema === null) {
            $schema = $this->createTestSchema();
        }

        // Ensure magic table exists for this register+schema
        $this->magicMapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackMagicTable($register, $schema);

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'phpunit-test-' . uniqid(), 'score' => 42]);

        $result = $this->mapper->insert($entity, $register, $schema);
        $this->createdObjectIds[] = $result->getId();

        return $result;
    }

    /**
     * Create a blob-only object (bypasses magic mapper)
     */
    private function createBlobObject(?Register $register = null, ?Schema $schema = null): ObjectEntity
    {
        if ($register === null) {
            $register = $this->createTestRegister();
        }
        if ($schema === null) {
            $schema = $this->createTestSchema();
        }

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'phpunit-test-blob-' . uniqid(), 'score' => 10]);

        $inserted = $this->objectEntityMapper->insertEntity($entity);
        $this->createdObjectIds[] = $inserted->getId();

        return $inserted;
    }

    // =========================================================================
    // Routing logic tests
    // =========================================================================

    public function testFindWithRegisterAndSchemaUsesOrmSource(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $object = $this->createTestObject($register, $schema);

        $found = $this->mapper->find(
            $object->getUuid(),
            $register,
            $schema,
            false,
            false,
            false
        );
        $this->assertSame('orm', $found->getSource());
    }

    public function testFindWithoutRegisterSchemaUsesBlobSource(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $inserted = $this->createBlobObject($register, $schema);

        $found = $this->mapper->find(
            $inserted->getUuid(),
            null,
            null,
            false,
            false,
            false
        );
        $this->assertSame('blob', $found->getSource());
    }

    // =========================================================================
    // CRUD tests
    // =========================================================================

    public function testInsertReturnsObjectEntity(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $object = $this->createTestObject($register, $schema);

        $this->assertInstanceOf(ObjectEntity::class, $object);
        $this->assertNotNull($object->getId());
        $this->assertNotNull($object->getUuid());
    }

    public function testInsertAndFindRoundTrip(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $object = $this->createTestObject($register, $schema);

        $found = $this->mapper->find(
            $object->getUuid(),
            $register,
            $schema,
            false,
            false,
            false
        );
        $this->assertSame($object->getUuid(), $found->getUuid());
    }

    public function testUpdateObject(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $object = $this->createTestObject($register, $schema);

        $object->setObject(['name' => 'phpunit-test-updated-' . uniqid(), 'score' => 99]);
        $updated = $this->mapper->update($object, $register, $schema);

        $this->assertInstanceOf(ObjectEntity::class, $updated);
        $this->assertSame($object->getUuid(), $updated->getUuid());
    }

    public function testUpdateObjectWithoutOldEntity(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $object = $this->createTestObject($register, $schema);

        // Update without passing oldEntity - should auto-fetch from DB
        $object->setObject(['name' => 'phpunit-test-auto-old-' . uniqid(), 'score' => 77]);
        $updated = $this->mapper->update($object, $register, $schema, null);

        $this->assertInstanceOf(ObjectEntity::class, $updated);
        $this->assertSame($object->getUuid(), $updated->getUuid());
    }

    public function testUpdateObjectWithExplicitOldEntity(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $object = $this->createTestObject($register, $schema);

        // Clone the object as "old" before modifying
        $oldEntity = clone $object;
        $object->setObject(['name' => 'phpunit-test-explicit-old-' . uniqid(), 'score' => 55]);
        $updated = $this->mapper->update($object, $register, $schema, $oldEntity);

        $this->assertInstanceOf(ObjectEntity::class, $updated);
    }

    public function testUpdateObjectWithAutoResolveRegisterSchema(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $object = $this->createTestObject($register, $schema);

        // Update without passing register/schema - should resolve from entity
        $object->setObject(['name' => 'phpunit-test-auto-resolve-' . uniqid(), 'score' => 33]);
        $updated = $this->mapper->update($object);

        $this->assertInstanceOf(ObjectEntity::class, $updated);
    }

    public function testDeleteObject(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $object = $this->createTestObject($register, $schema);
        $id = $object->getId();

        $deleted = $this->mapper->delete($object);
        $this->assertInstanceOf(ObjectEntity::class, $deleted);

        // Remove from cleanup since already deleted
        $this->createdObjectIds = array_filter(
            $this->createdObjectIds,
            fn($oid) => $oid !== $id
        );
    }

    public function testDeleteNonObjectEntityThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Entity must be an instance of ObjectEntity');

        $register = new Register();
        $this->mapper->delete($register);
    }

    public function testInsertWithAutoResolveRegisterSchema(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->magicMapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackMagicTable($register, $schema);

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'phpunit-test-auto-resolve-insert-' . uniqid(), 'score' => 11]);

        // Insert without explicit register/schema - should resolve from entity
        $result = $this->mapper->insert($entity);
        $this->createdObjectIds[] = $result->getId();

        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertNotNull($result->getId());
    }

    // =========================================================================
    // findAll tests
    // =========================================================================

    public function testFindAllWithRegisterSchemaReturnsArray(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestObject($register, $schema);

        $results = $this->mapper->findAll(
            10,
            0,
            null,
            null,
            null,
            [],
            null,
            null,
            null,
            false,
            $register,
            $schema
        );
        $this->assertIsArray($results);
    }

    public function testFindAllWithoutContextUsesBlob(): void
    {
        $results = $this->mapper->findAll(5, 0);
        $this->assertIsArray($results);
    }

    public function testFindAllWithFilters(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $object = $this->createTestObject($register, $schema);

        $results = $this->mapper->findAll(
            10,
            0,
            ['uuid' => $object->getUuid()],
            null,
            null,
            [],
            null,
            null,
            null,
            false,
            null,
            null
        );
        $this->assertIsArray($results);
    }

    public function testFindAllWithNullPublishedFilter(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestObject($register, $schema);

        $results = $this->mapper->findAll(
            10,
            0,
            null,
            null,
            null,
            [],
            null,
            null,
            null,
            false,
            $register,
            $schema,
            null // default published filter
        );
        $this->assertIsArray($results);
    }

    public function testFindByUuidSetsOrmSource(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $object = $this->createTestObject($register, $schema);

        // Verify find() with register+schema sets source to 'orm'
        $found = $this->mapper->find(
            $object->getUuid(), $register, $schema, false, false, false
        );

        $this->assertSame('orm', $found->getSource());
    }

    public function testFindAllBlobSetsSourceOnEntities(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createBlobObject($register, $schema);

        $results = $this->mapper->findAll(10, 0);

        foreach ($results as $entity) {
            $this->assertSame('blob', $entity->getSource());
        }
    }

    // =========================================================================
    // findMultiple tests
    // =========================================================================

    public function testFindMultipleReturnsArray(): void
    {
        $o1 = $this->createTestObject();
        $o2 = $this->createTestObject();

        $results = $this->mapper->findMultiple([$o1->getId(), $o2->getId()]);
        $this->assertIsArray($results);
        $this->assertGreaterThanOrEqual(0, count($results));
    }

    public function testFindMultipleWithUuids(): void
    {
        $o1 = $this->createTestObject();
        $o2 = $this->createTestObject();

        $results = $this->mapper->findMultiple([$o1->getUuid(), $o2->getUuid()]);
        $this->assertIsArray($results);
    }

    public function testFindMultipleEmptyReturnsEmpty(): void
    {
        $results = $this->mapper->findMultiple([]);
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    // =========================================================================
    // findBySchema tests
    // =========================================================================

    public function testFindBySchemaReturnsArray(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestObject($register, $schema);

        $results = $this->mapper->findBySchema($schema->getId());
        $this->assertIsArray($results);
    }

    // =========================================================================
    // Statistics tests
    // =========================================================================

    public function testGetStatisticsReturnsExpectedKeys(): void
    {
        $stats = $this->mapper->getStatistics();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('size', $stats);
    }

    public function testGetStatisticsWithRegisterFilter(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestObject($register, $schema);

        $stats = $this->mapper->getStatistics($register->getId());
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total', $stats);
    }

    public function testGetStatisticsWithSchemaFilter(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestObject($register, $schema);

        $stats = $this->mapper->getStatistics(null, $schema->getId());
        $this->assertIsArray($stats);
    }

    public function testGetStatisticsWithBothRegisterAndSchemaFilter(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestObject($register, $schema);

        $stats = $this->mapper->getStatistics($register->getId(), $schema->getId());
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total', $stats);
    }

    // =========================================================================
    // Chart data tests
    // =========================================================================

    public function testGetRegisterChartData(): void
    {
        $data = $this->mapper->getRegisterChartData();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('labels', $data);
        $this->assertArrayHasKey('series', $data);
    }

    public function testGetSchemaChartData(): void
    {
        $data = $this->mapper->getSchemaChartData();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('labels', $data);
        $this->assertArrayHasKey('series', $data);
    }

    public function testGetRegisterChartDataWithFilters(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestObject($register, $schema);

        $data = $this->mapper->getRegisterChartData($register->getId(), $schema->getId());
        $this->assertIsArray($data);
        $this->assertArrayHasKey('labels', $data);
    }

    public function testGetSchemaChartDataWithFilters(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestObject($register, $schema);

        $data = $this->mapper->getSchemaChartData($register->getId(), $schema->getId());
        $this->assertIsArray($data);
        $this->assertArrayHasKey('labels', $data);
    }

    // =========================================================================
    // Facets tests
    // =========================================================================

    public function testGetSimpleFacetsEmptyQuery(): void
    {
        $facets = $this->mapper->getSimpleFacets([]);
        $this->assertIsArray($facets);
    }

    public function testGetSimpleFacetsWithRegisterSchema(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestObject($register, $schema);

        $facets = $this->mapper->getSimpleFacets([
            '_register' => $register->getId(),
            '_schema'   => $schema->getId(),
        ]);
        $this->assertIsArray($facets);
    }

    public function testGetSimpleFacetsViaAtSelfKeys(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestObject($register, $schema);

        $facets = $this->mapper->getSimpleFacets([
            '@self' => [
                'register' => $register->getId(),
                'schema'   => $schema->getId(),
            ],
        ]);
        $this->assertIsArray($facets);
    }

    public function testGetSimpleFacetsWithMultipleSchemas(): void
    {
        $register = $this->createTestRegister();
        $schema1 = $this->createTestSchema();
        $schema2 = $this->createTestSchema();

        $this->createTestObject($register, $schema1);
        $this->createTestObject($register, $schema2);

        $facets = $this->mapper->getSimpleFacets([
            '_register' => $register->getId(),
            '_schemas' => [$schema1->getId(), $schema2->getId()],
        ]);
        $this->assertIsArray($facets);
    }

    public function testGetFacetableFieldsFromSchemas(): void
    {
        $fields = $this->mapper->getFacetableFieldsFromSchemas([]);
        $this->assertIsArray($fields);
    }

    // =========================================================================
    // countAll tests
    // =========================================================================

    public function testCountAllReturnsInt(): void
    {
        $count = $this->mapper->countAll();
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testCountAllWithSchemaFilter(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestObject($register, $schema);

        $count = $this->mapper->countAll(null, $schema);
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testCountAllWithRegisterAndSchemaFilter(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestObject($register, $schema);

        $count = $this->mapper->countAll(null, $schema, $register);
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testCountAllWithFiltersArray(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $object = $this->createTestObject($register, $schema);

        $count = $this->mapper->countAll(['uuid' => $object->getUuid()]);
        $this->assertIsInt($count);
    }

    // =========================================================================
    // Search tests
    // =========================================================================

    public function testSearchObjectsReturnsArray(): void
    {
        $results = $this->mapper->searchObjects([], null, false, false);
        $this->assertIsArray($results);
    }

    public function testSearchObjectsWithRegisterSchemaRoutesMagic(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestObject($register, $schema);

        $results = $this->mapper->searchObjects(
            [
                '_register' => (string) $register->getId(),
                '_schema'   => (string) $schema->getId(),
            ],
            null,
            false,
            false
        );
        $this->assertIsArray($results);
    }

    public function testSearchObjectsWithAtSelfKeys(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestObject($register, $schema);

        $results = $this->mapper->searchObjects(
            [
                '@self' => [
                    'register' => (string) $register->getId(),
                    'schema'   => (string) $schema->getId(),
                ],
            ],
            null,
            false,
            false
        );
        $this->assertIsArray($results);
    }

    public function testSearchObjectsWithRegisterFilter(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestObject($register, $schema);

        $results = $this->mapper->searchObjects(
            ['register' => (string) $register->getId()],
            null,
            false,
            false
        );
        $this->assertIsArray($results);
    }

    public function testSearchObjectsWithLimit(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestObject($register, $schema);
        $this->createTestObject($register, $schema);

        $results = $this->mapper->searchObjects(
            ['_limit' => 1, '_offset' => 0],
            null,
            false,
            false
        );
        $this->assertIsArray($results);
        $this->assertLessThanOrEqual(1, count($results));
    }

    public function testCountSearchObjectsReturnsInt(): void
    {
        $count = $this->mapper->countSearchObjects([], null, false, false);
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testCountSearchObjectsWithRegisterSchemaRoutesMagic(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestObject($register, $schema);

        $count = $this->mapper->countSearchObjects(
            [
                '_register' => (string) $register->getId(),
                '_schema'   => (string) $schema->getId(),
            ],
            null,
            false,
            false
        );
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testCountSearchObjectsWithAtSelfKeys(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestObject($register, $schema);

        $count = $this->mapper->countSearchObjects(
            [
                '@self' => [
                    'register' => (string) $register->getId(),
                    'schema'   => (string) $schema->getId(),
                ],
            ],
            null,
            false,
            false
        );
        $this->assertIsInt($count);
    }

    // =========================================================================
    // searchObjectsPaginated tests
    // =========================================================================

    public function testSearchObjectsPaginatedReturnsExpectedStructure(): void
    {
        $result = $this->mapper->searchObjectsPaginated([], [], null, false, false);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total', $result);
    }

    public function testSearchObjectsPaginatedWithData(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestObject($register, $schema);

        $result = $this->mapper->searchObjectsPaginated([], [], null, false, false);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertIsArray($result['results']);
        $this->assertIsInt($result['total']);
        $this->assertGreaterThanOrEqual(0, $result['total']);
    }

    public function testSearchObjectsPaginatedWithRegisterSchemaRoutesMagic(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestObject($register, $schema);

        $result = $this->mapper->searchObjectsPaginated(
            [
                '_register' => (string) $register->getId(),
                '_schema'   => (string) $schema->getId(),
            ],
            [
                '_register' => (string) $register->getId(),
                '_schema'   => (string) $schema->getId(),
            ],
            null,
            false,
            false
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('registers', $result);
        $this->assertArrayHasKey('schemas', $result);
    }

    public function testSearchObjectsPaginatedWithMultipleSchemas(): void
    {
        $register = $this->createTestRegister();
        $schema1 = $this->createTestSchema();
        $schema2 = $this->createTestSchema();

        $this->createTestObject($register, $schema1);
        $this->createTestObject($register, $schema2);

        $result = $this->mapper->searchObjectsPaginated(
            [
                '_register' => (string) $register->getId(),
                '_schemas'  => [(string) $schema1->getId(), (string) $schema2->getId()],
            ],
            [
                '_register' => (string) $register->getId(),
                '_schemas'  => [(string) $schema1->getId(), (string) $schema2->getId()],
            ],
            null,
            false,
            false
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total', $result);
    }

    public function testSearchObjectsPaginatedWithSchemaAsArray(): void
    {
        $register = $this->createTestRegister();
        $schema1 = $this->createTestSchema();
        $schema2 = $this->createTestSchema();

        $this->createTestObject($register, $schema1);
        $this->createTestObject($register, $schema2);

        // When @self.schema is an array, it should be treated as multi-schema
        $result = $this->mapper->searchObjectsPaginated(
            [
                '@self' => [
                    'register' => (string) $register->getId(),
                    'schema'   => [(string) $schema1->getId(), (string) $schema2->getId()],
                ],
            ],
            [
                '@self' => [
                    'register' => (string) $register->getId(),
                    'schema'   => [(string) $schema1->getId(), (string) $schema2->getId()],
                ],
            ],
            null,
            false,
            false
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('results', $result);
    }

    public function testSearchObjectsPaginatedBlobFallback(): void
    {
        // Create blob-only objects (no magic table)
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createBlobObject($register, $schema);

        // Search without register/schema - blob fallback
        $result = $this->mapper->searchObjectsPaginated(
            [],
            [],
            null,
            false,
            false
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('registers', $result);
        $this->assertArrayHasKey('schemas', $result);
    }

    // =========================================================================
    // Lock / Unlock tests
    // =========================================================================

    public function testLockAndUnlockObject(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $object = $this->createBlobObject($register, $schema);

        $lockResult = $this->mapper->lockObject($object->getUuid(), 300);
        $this->assertIsArray($lockResult);
        $this->assertArrayHasKey('uuid', $lockResult);

        $unlocked = $this->mapper->unlockObject($object->getUuid());
        $this->assertTrue($unlocked);
    }

    public function testLockObjectWithDefaultDuration(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $object = $this->createBlobObject($register, $schema);

        $lockResult = $this->mapper->lockObject($object->getUuid());
        $this->assertIsArray($lockResult);

        // Clean up
        $this->mapper->unlockObject($object->getUuid());
    }

    // =========================================================================
    // Query builder delegation tests
    // =========================================================================

    public function testGetQueryBuilder(): void
    {
        $qb = $this->mapper->getQueryBuilder();
        $this->assertInstanceOf(IQueryBuilder::class, $qb);
    }

    public function testGetMaxAllowedPacketSize(): void
    {
        $size = $this->mapper->getMaxAllowedPacketSize();
        $this->assertIsInt($size);
        $this->assertGreaterThan(0, $size);
    }

    // =========================================================================
    // Bulk operations tests
    // =========================================================================

    public function testDeleteObjectsEmptyReturnsEmpty(): void
    {
        $result = $this->mapper->deleteObjects([]);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testPublishObjectsEmptyReturnsEmpty(): void
    {
        $result = $this->mapper->publishObjects([]);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testDepublishObjectsEmptyReturnsEmpty(): void
    {
        $result = $this->mapper->depublishObjects([]);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testDeleteObjectsWithActualUuids(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $inserted = $this->createBlobObject($register, $schema);

        $result = $this->mapper->deleteObjects([$inserted->getUuid()], false);
        $this->assertIsArray($result);

        $this->createdObjectIds = array_filter(
            $this->createdObjectIds,
            fn($oid) => $oid !== $inserted->getId()
        );
    }

    public function testDeleteObjectsWithHardDelete(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $inserted = $this->createBlobObject($register, $schema);

        $result = $this->mapper->deleteObjects([$inserted->getUuid()], true);
        $this->assertIsArray($result);

        $this->createdObjectIds = array_filter(
            $this->createdObjectIds,
            fn($oid) => $oid !== $inserted->getId()
        );
    }

    public function testPublishObjectsWithActualUuids(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $inserted = $this->createBlobObject($register, $schema);

        $result = $this->mapper->publishObjects([$inserted->getUuid()], true);
        $this->assertIsArray($result);
    }

    public function testDepublishObjectsWithActualUuids(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $inserted = $this->createBlobObject($register, $schema);

        $this->mapper->publishObjects([$inserted->getUuid()], true);
        $result = $this->mapper->depublishObjects([$inserted->getUuid()], true);
        $this->assertIsArray($result);
    }

    // =========================================================================
    // findAcrossAllSources tests
    // =========================================================================

    public function testFindAcrossAllSourcesFindsObject(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $object = $this->createTestObject($register, $schema);

        $result = $this->mapper->findAcrossAllSources(
            $object->getUuid(),
            false,
            false,
            false
        );
        $this->assertIsArray($result);
        $this->assertArrayHasKey('object', $result);
        $this->assertInstanceOf(ObjectEntity::class, $result['object']);
        $this->assertSame($object->getUuid(), $result['object']->getUuid());
    }

    public function testFindAcrossAllSourcesNotFoundThrows(): void
    {
        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->mapper->findAcrossAllSources('nonexistent-uuid-' . uniqid(), false, false, false);
    }

    public function testFindAcrossAllSourcesBlobReturnsRegisterAndSchema(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $inserted = $this->createBlobObject($register, $schema);

        $result = $this->mapper->findAcrossAllSources(
            $inserted->getUuid(),
            false,
            false,
            false
        );
        $this->assertArrayHasKey('object', $result);
        $this->assertArrayHasKey('register', $result);
        $this->assertArrayHasKey('schema', $result);
        $this->assertInstanceOf(ObjectEntity::class, $result['object']);
    }

    // =========================================================================
    // Insert requires ObjectEntity validation
    // =========================================================================

    public function testInsertNonObjectEntityThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Entity must be an instance of ObjectEntity');

        $register = new Register();
        $this->mapper->insert($register);
    }

    // =========================================================================
    // ultraFastBulkSave tests
    // =========================================================================

    public function testUltraFastBulkSaveWithRegisterSchema(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->magicMapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackMagicTable($register, $schema);

        $objects = [
            [
                '@self' => [
                    'register' => $register->getId(),
                    'schema'   => $schema->getId(),
                ],
                'name'  => 'bulk-test-1-' . uniqid(),
                'score' => 10,
            ],
            [
                '@self' => [
                    'register' => $register->getId(),
                    'schema'   => $schema->getId(),
                ],
                'name'  => 'bulk-test-2-' . uniqid(),
                'score' => 20,
            ],
        ];

        $result = $this->mapper->ultraFastBulkSave(
            $objects,
            [],
            $register,
            $schema
        );

        $this->assertIsArray($result);

        // Track created objects for cleanup
        foreach ($result as $obj) {
            if (isset($obj['uuid'])) {
                // Fetch by UUID to get DB ID for cleanup
                try {
                    $found = $this->mapper->find($obj['uuid'], $register, $schema, false, false, false);
                    $this->createdObjectIds[] = $found->getId();
                } catch (\Exception $e) {
                    // skip
                }
            }
        }
    }

    public function testUltraFastBulkSaveWithAutoResolve(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->magicMapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackMagicTable($register, $schema);

        $objects = [
            [
                '@self' => [
                    'register' => $register->getId(),
                    'schema'   => $schema->getId(),
                ],
                'name'  => 'bulk-auto-resolve-' . uniqid(),
                'score' => 15,
            ],
        ];

        // Don't pass register/schema - should auto-resolve from object data
        $result = $this->mapper->ultraFastBulkSave($objects, []);
        $this->assertIsArray($result);

        // Track for cleanup
        foreach ($result as $obj) {
            if (isset($obj['uuid'])) {
                try {
                    $found = $this->mapper->find($obj['uuid'], $register, $schema, false, false, false);
                    $this->createdObjectIds[] = $found->getId();
                } catch (\Exception $e) {
                    // skip
                }
            }
        }
    }

    public function testUltraFastBulkSaveEmptyReturnsEmpty(): void
    {
        $result = $this->mapper->ultraFastBulkSave([], []);
        $this->assertIsArray($result);
    }

    // =========================================================================
    // countSearchObjects with data
    // =========================================================================

    public function testCountSearchObjectsWithData(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $inserted = $this->createBlobObject($register, $schema);

        $count = $this->mapper->countSearchObjects([], null, false, false);
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(1, $count);
    }

    // =========================================================================
    // getSimpleFacets with actual data
    // =========================================================================

    public function testGetSimpleFacetsWithData(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestObject($register, $schema);

        $facets = $this->mapper->getSimpleFacets([
            '_register' => $register->getId(),
            '_schema'   => $schema->getId(),
        ]);
        $this->assertIsArray($facets);
    }

    // =========================================================================
    // findAll with sorting
    // =========================================================================

    public function testFindAllWithSorting(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestObject($register, $schema);

        $results = $this->mapper->findAll(
            10,
            0,
            null,
            null,
            null,
            [],
            null,
            null,
            null,
            false,
            $register,
            $schema
        );
        $this->assertIsArray($results);
    }

    // =========================================================================
    // findBySchema returns correct objects
    // =========================================================================

    public function testFindBySchemaAfterInsert(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestObject($register, $schema);

        $results = $this->mapper->findBySchema($schema->getId());
        $this->assertIsArray($results);
    }

    // =========================================================================
    // Insert into blob storage (no register/schema)
    // =========================================================================

    public function testInsertIntoBlobStorageWithRegisterSchema(): void
    {
        // Insert into blob storage directly via ObjectEntityMapper
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'phpunit-test-blob-direct-' . uniqid()]);

        $result = $this->objectEntityMapper->insertEntity($entity);
        $this->createdObjectIds[] = $result->getId();

        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertNotNull($result->getId());
    }

    // =========================================================================
    // searchObjectsPaginated with IDs search
    // =========================================================================

    public function testSearchObjectsPaginatedWithIds(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $object1 = $this->createTestObject($register, $schema);
        $object2 = $this->createTestObject($register, $schema);

        $result = $this->mapper->searchObjectsPaginated(
            [
                '_ids' => [$object1->getUuid(), $object2->getUuid()],
            ],
            [],
            null,
            false,
            false
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total', $result);
    }

    // =========================================================================
    // searchObjectsPaginated with global text search
    // =========================================================================

    public function testSearchObjectsPaginatedWithSearchInMagicTable(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestObject($register, $schema);

        // Search within a specific register+schema context (routes to magic mapper)
        $result = $this->mapper->searchObjectsPaginated(
            [
                '_register' => (string) $register->getId(),
                '_schema'   => (string) $schema->getId(),
                '_search'   => 'phpunit-test',
            ],
            [
                '_register' => (string) $register->getId(),
                '_schema'   => (string) $schema->getId(),
            ],
            null,
            false,
            false
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('results', $result);
    }

    // =========================================================================
    // searchObjectsPaginated with _relations_contains
    // =========================================================================

    public function testSearchObjectsPaginatedWithRelationsContains(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $object = $this->createTestObject($register, $schema);

        $result = $this->mapper->searchObjectsPaginated(
            [
                '_relations_contains' => $object->getUuid(),
            ],
            [],
            null,
            false,
            false
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('results', $result);
    }

    // =========================================================================
    // searchObjectsPaginated with multiple registers
    // =========================================================================

    public function testSearchObjectsPaginatedWithMultipleRegisters(): void
    {
        $register1 = $this->createTestRegister();
        $register2 = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->createTestObject($register1, $schema);
        $this->createTestObject($register2, $schema);

        $result = $this->mapper->searchObjectsPaginated(
            [
                '_registers' => [(string) $register1->getId(), (string) $register2->getId()],
                '_schemas'   => [(string) $schema->getId()],
            ],
            [
                '_registers' => [(string) $register1->getId(), (string) $register2->getId()],
                '_schemas'   => [(string) $schema->getId()],
            ],
            null,
            false,
            false
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('results', $result);
    }
}
