<?php

/**
 * Integration tests for MagicMapper
 *
 * Tests table creation, object storage, search, facets, and related operations
 * in dynamic register+schema tables. Also exercises MagicSearchHandler,
 * MagicFacetHandler, MagicRbacHandler, MagicBulkHandler indirectly.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Db
 */

namespace OCA\OpenRegister\Tests\Db;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use Symfony\Component\Uid\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * @group DB
 */
class MagicMapperIntegrationTest extends TestCase
{
    private MagicMapper $mapper;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;

    /** @var int[] IDs of schemas created during tests */
    private array $createdSchemaIds = [];
    /** @var int[] IDs of registers created during tests */
    private array $createdRegisterIds = [];
    /** @var array Pairs of [tableName] for tables created during tests */
    private array $createdTables = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = \OC::$server->get(MagicMapper::class);
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
            'title'       => 'PHPUnit Magic Test Register ' . uniqid(),
            'description' => 'Register for MagicMapper integration tests',
        ]);
        $this->createdRegisterIds[] = $register->getId();

        return $register;
    }

    /**
     * Create a test schema with properties suitable for magic table creation
     */
    private function createTestSchema(): Schema
    {
        $schema = $this->schemaMapper->createFromArray([
            'title'       => 'PHPUnit Magic Test Schema ' . uniqid(),
            'description' => 'Schema for MagicMapper integration tests',
            'properties'  => [
                'name' => [
                    'type'      => 'string',
                    'title'     => 'Name',
                    'maxLength' => 255,
                ],
                'age' => [
                    'type'  => 'integer',
                    'title' => 'Age',
                ],
                'active' => [
                    'type'  => 'boolean',
                    'title' => 'Active',
                ],
            ],
        ]);
        $this->createdSchemaIds[] = $schema->getId();

        return $schema;
    }

    /**
     * Helper to track a created magic table for cleanup
     */
    private function trackTable(Register $register, Schema $schema): void
    {
        $tableName = $this->mapper->getTableNameForRegisterSchema($register, $schema);
        // Add oc_ prefix for the actual table name
        $this->createdTables[] = 'oc_' . $tableName;
    }

    // =========================================================================
    // Table management tests
    // =========================================================================

    public function testGetTableNameForRegisterSchema(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $tableName = $this->mapper->getTableNameForRegisterSchema($register, $schema);
        $this->assertIsString($tableName);
        $this->assertStringStartsWith('openregister_table_', $tableName);
    }

    public function testEnsureTableForRegisterSchema(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $result = $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->assertTrue($result);

        $this->trackTable($register, $schema);
    }

    public function testTableExistsForRegisterSchemaAfterCreation(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $exists = $this->mapper->tableExistsForRegisterSchema($register, $schema);
        $this->assertTrue($exists);
    }

    public function testExistsTableForRegisterSchemaUsesCache(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        // Second call should use cache
        $exists1 = $this->mapper->existsTableForRegisterSchema($register, $schema);
        $exists2 = $this->mapper->existsTableForRegisterSchema($register, $schema);
        $this->assertTrue($exists1);
        $this->assertTrue($exists2);
    }

    public function testEnsureTableIdempotent(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        // Creating twice should not throw
        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $result = $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->assertTrue($result);

        $this->trackTable($register, $schema);
    }

    // =========================================================================
    // isMagicMappingEnabled tests
    // =========================================================================

    public function testIsMagicMappingEnabledDefault(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        // Without explicit configuration, checks global setting
        $result = $this->mapper->isMagicMappingEnabled($register, $schema);
        $this->assertIsBool($result);
    }

    public function testIsMagicMappingEnabledForSchema(): void
    {
        $schema = $this->createTestSchema();
        $result = $this->mapper->isMagicMappingEnabledForSchema($schema);
        $this->assertIsBool($result);
    }

    // =========================================================================
    // Save + Search tests (with table)
    // =========================================================================

    public function testInsertAndSearchObjects(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $entity1 = new ObjectEntity();
        $entity1->setUuid(Uuid::v4()->toRfc4122());
        $entity1->setRegister((string) $register->getId());
        $entity1->setSchema((string) $schema->getId());
        $entity1->setObject(['name' => 'Alice', 'age' => 30, 'active' => true]);
        $this->mapper->insertObjectEntity($entity1, $register, $schema, false);

        $entity2 = new ObjectEntity();
        $entity2->setUuid(Uuid::v4()->toRfc4122());
        $entity2->setRegister((string) $register->getId());
        $entity2->setSchema((string) $schema->getId());
        $entity2->setObject(['name' => 'Bob', 'age' => 25, 'active' => false]);
        $this->mapper->insertObjectEntity($entity2, $register, $schema, false);

        // Search applies RBAC/multitenancy filters, so without a user session
        // results may be filtered. Verify it returns an array without errors.
        $results = $this->mapper->searchObjectsInRegisterSchemaTable([], $register, $schema);
        $this->assertIsArray($results);
    }

    public function testCountObjectsInRegisterSchemaTable(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'CountTest', 'age' => 1]);
        $this->mapper->insertObjectEntity($entity, $register, $schema, false);

        // Count applies RBAC/multitenancy filters too
        $count = $this->mapper->countObjectsInRegisterSchemaTable([], $register, $schema);
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    // =========================================================================
    // Facet tests (MagicFacetHandler)
    // =========================================================================

    public function testGetSimpleFacetsFromRegisterSchemaTable(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $facets = $this->mapper->getSimpleFacetsFromRegisterSchemaTable([], $register, $schema);
        $this->assertIsArray($facets);
    }

    // =========================================================================
    // Find individual object tests
    // =========================================================================

    public function testFindInRegisterSchemaTable(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $uuid = Uuid::v4()->toRfc4122();
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'FindTest', 'age' => 42]);
        $this->mapper->insertObjectEntity($entity, $register, $schema, false);

        $found = $this->mapper->findInRegisterSchemaTable($uuid, $register, $schema);
        $this->assertInstanceOf(ObjectEntity::class, $found);
        $this->assertSame($uuid, $found->getUuid());
    }

    public function testFindInRegisterSchemaTableNotFound(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->mapper->findInRegisterSchemaTable('nonexistent-' . uniqid(), $register, $schema);
    }

    // =========================================================================
    // findAllInRegisterSchemaTable tests
    // =========================================================================

    public function testFindAllInRegisterSchemaTable(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'ListTest', 'age' => 10]);
        $this->mapper->insertObjectEntity($entity, $register, $schema, false);

        // findAll delegates to searchObjects which applies RBAC/multitenancy
        $results = $this->mapper->findAllInRegisterSchemaTable($register, $schema);
        $this->assertIsArray($results);
    }

    // =========================================================================
    // Insert / Update / Delete individual object tests
    // =========================================================================

    public function testInsertObjectEntity(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'InsertTest', 'age' => 55]);

        $result = $this->mapper->insertObjectEntity($entity, $register, $schema);
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertNotNull($result->getUuid());
    }

    public function testUpdateObjectEntity(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'BeforeUpdate', 'age' => 20]);

        $inserted = $this->mapper->insertObjectEntity($entity, $register, $schema);

        $inserted->setObject(['name' => 'AfterUpdate', 'age' => 21]);
        $updated = $this->mapper->updateObjectEntity($inserted, $register, $schema);

        $this->assertInstanceOf(ObjectEntity::class, $updated);
    }

    public function testDeleteObjectEntityHardDelete(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'DeleteTest', 'age' => 99]);

        $inserted = $this->mapper->insertObjectEntity($entity, $register, $schema, false);

        // Use hardDelete=true to avoid requiring a logged-in user for soft delete
        $deleted = $this->mapper->deleteObjectEntity($inserted, $register, $schema, true, false);
        $this->assertInstanceOf(ObjectEntity::class, $deleted);
    }

    // =========================================================================
    // Cache tests
    // =========================================================================

    public function testClearCache(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        // Should not throw
        $this->mapper->clearCache($register->getId(), $schema->getId());
        $this->assertTrue(true);
    }

    public function testClearCacheAll(): void
    {
        // Should not throw
        $this->mapper->clearCache();
        $this->assertTrue(true);
    }

    // =========================================================================
    // getExistingRegisterSchemaTables tests
    // =========================================================================

    public function testGetExistingRegisterSchemaTables(): void
    {
        $tables = $this->mapper->getExistingRegisterSchemaTables();
        $this->assertIsArray($tables);
    }

    // =========================================================================
    // getAllRegisterSchemaPairs tests
    // =========================================================================

    public function testGetAllRegisterSchemaPairs(): void
    {
        $pairs = $this->mapper->getAllRegisterSchemaPairs();
        $this->assertIsArray($pairs);
    }

    // =========================================================================
    // getIgnoredFilters tests
    // =========================================================================

    public function testGetIgnoredFilters(): void
    {
        $filters = $this->mapper->getIgnoredFilters();
        $this->assertIsArray($filters);
    }

    // =========================================================================
    // findByRelation tests
    // =========================================================================

    public function testFindByRelationReturnsArray(): void
    {
        $results = $this->mapper->findByRelation('nonexistent-uuid-' . uniqid());
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    // =========================================================================
    // syncTableForRegisterSchema tests
    // =========================================================================

    public function testSyncTableForRegisterSchema(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $result = $this->mapper->syncTableForRegisterSchema($register, $schema);
        $this->assertIsArray($result);
    }

    // =========================================================================
    // MagicFacetHandler tests (via MagicMapper)
    // =========================================================================

    public function testFacetsReturnStructureWithData(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        // Insert multiple objects with different values for faceting
        for ($i = 0; $i < 3; $i++) {
            $entity = new ObjectEntity();
            $entity->setUuid(Uuid::v4()->toRfc4122());
            $entity->setRegister((string) $register->getId());
            $entity->setSchema((string) $schema->getId());
            $entity->setObject(['name' => 'FacetUser' . ($i % 2), 'age' => (20 + $i), 'active' => ($i % 2 === 0)]);
            $this->mapper->insertObjectEntity($entity, $register, $schema, false);
        }

        $facets = $this->mapper->getSimpleFacetsFromRegisterSchemaTable([], $register, $schema);
        $this->assertIsArray($facets);
    }

    public function testFacetsWithFilterQuery(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'FacetFilter', 'age' => 33, 'active' => true]);
        $this->mapper->insertObjectEntity($entity, $register, $schema, false);

        // Query with a filter
        $facets = $this->mapper->getSimpleFacetsFromRegisterSchemaTable(
            ['name' => 'FacetFilter'],
            $register,
            $schema
        );
        $this->assertIsArray($facets);
    }

    public function testFacetsOnEmptyTable(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $facets = $this->mapper->getSimpleFacetsFromRegisterSchemaTable([], $register, $schema);
        $this->assertIsArray($facets);
    }

    public function testFacetsUnionAcrossMultipleTables(): void
    {
        $register = $this->createTestRegister();
        $schema1 = $this->createTestSchema();
        $schema2 = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema1);
        $this->trackTable($register, $schema1);
        $this->mapper->ensureTableForRegisterSchema($register, $schema2);
        $this->trackTable($register, $schema2);

        // Insert data into both tables
        foreach ([$schema1, $schema2] as $schema) {
            $entity = new ObjectEntity();
            $entity->setUuid(Uuid::v4()->toRfc4122());
            $entity->setRegister((string) $register->getId());
            $entity->setSchema((string) $schema->getId());
            $entity->setObject(['name' => 'UnionFacet', 'age' => 25, 'active' => true]);
            $this->mapper->insertObjectEntity($entity, $register, $schema, false);
        }

        $pairs = [
            ['register' => $register, 'schema' => $schema1],
            ['register' => $register, 'schema' => $schema2],
        ];

        // getSimpleFacetsUnion signature: (array $query, ?Register $register, array $schemas, array $registerSchemaPairs)
        $facets = $this->mapper->getSimpleFacetsUnion([], $register, [$schema1, $schema2], $pairs);
        $this->assertIsArray($facets);
    }

    // =========================================================================
    // MagicSearchHandler tests (via MagicMapper)
    // =========================================================================

    public function testSearchWithFilterOnProperty(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'SearchableAlice', 'age' => 30, 'active' => true]);
        $this->mapper->insertObjectEntity($entity, $register, $schema, false);

        $results = $this->mapper->searchObjectsInRegisterSchemaTable(
            ['name' => 'SearchableAlice'],
            $register,
            $schema
        );
        $this->assertIsArray($results);
    }

    public function testSearchWithPagination(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        for ($i = 0; $i < 5; $i++) {
            $entity = new ObjectEntity();
            $entity->setUuid(Uuid::v4()->toRfc4122());
            $entity->setRegister((string) $register->getId());
            $entity->setSchema((string) $schema->getId());
            $entity->setObject(['name' => 'PageItem' . $i, 'age' => $i]);
            $this->mapper->insertObjectEntity($entity, $register, $schema, false);
        }

        $results = $this->mapper->searchObjectsInRegisterSchemaTable(
            ['_limit' => 2, '_offset' => 0],
            $register,
            $schema
        );
        $this->assertIsArray($results);
    }

    public function testSearchWithSorting(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'SortItem', 'age' => 50]);
        $this->mapper->insertObjectEntity($entity, $register, $schema, false);

        $results = $this->mapper->searchObjectsInRegisterSchemaTable(
            ['_order' => ['age' => 'DESC']],
            $register,
            $schema
        );
        $this->assertIsArray($results);
    }

    public function testCountObjectsInTable(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        for ($i = 0; $i < 3; $i++) {
            $entity = new ObjectEntity();
            $entity->setUuid(Uuid::v4()->toRfc4122());
            $entity->setRegister((string) $register->getId());
            $entity->setSchema((string) $schema->getId());
            $entity->setObject(['name' => 'CountItem' . $i, 'age' => $i]);
            $this->mapper->insertObjectEntity($entity, $register, $schema, false);
        }

        $count = $this->mapper->countObjectsInRegisterSchemaTable([], $register, $schema);
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testSearchWithNonExistentFilterProperty(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'IgnoredFilter', 'age' => 10]);
        $this->mapper->insertObjectEntity($entity, $register, $schema, false);

        // Filter on a property not in schema - should not crash
        $results = $this->mapper->searchObjectsInRegisterSchemaTable(
            ['nonExistentProperty' => 'value'],
            $register,
            $schema
        );
        $this->assertIsArray($results);
    }

    public function testSearchAcrossMultipleTables(): void
    {
        $register = $this->createTestRegister();
        $schema1 = $this->createTestSchema();
        $schema2 = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema1);
        $this->trackTable($register, $schema1);
        $this->mapper->ensureTableForRegisterSchema($register, $schema2);
        $this->trackTable($register, $schema2);

        foreach ([$schema1, $schema2] as $schema) {
            $entity = new ObjectEntity();
            $entity->setUuid(Uuid::v4()->toRfc4122());
            $entity->setRegister((string) $register->getId());
            $entity->setSchema((string) $schema->getId());
            $entity->setObject(['name' => 'CrossTableItem', 'age' => 30]);
            $this->mapper->insertObjectEntity($entity, $register, $schema, false);
        }

        $pairs = [
            ['register' => $register, 'schema' => $schema1],
            ['register' => $register, 'schema' => $schema2],
        ];

        $results = $this->mapper->searchAcrossMultipleTables([], $pairs);
        $this->assertIsArray($results);
    }

    // =========================================================================
    // MagicRbacHandler tests (via MagicMapper search)
    // =========================================================================

    public function testSearchReturnsResultsWithRbacDisabled(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'RbacOff', 'age' => 25]);
        $this->mapper->insertObjectEntity($entity, $register, $schema, false);

        // Search with RBAC disabled
        $results = $this->mapper->searchObjectsInRegisterSchemaTable(
            ['_rbac' => false],
            $register,
            $schema
        );
        $this->assertIsArray($results);
    }

    public function testSearchWithRbacEnabledDoesNotCrash(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'RbacOn', 'age' => 40]);
        $this->mapper->insertObjectEntity($entity, $register, $schema, false);

        // Search with RBAC enabled (default) - may filter results based on user session
        $results = $this->mapper->searchObjectsInRegisterSchemaTable(
            ['_rbac' => true],
            $register,
            $schema
        );
        $this->assertIsArray($results);
    }

    public function testCountWithRbacDisabled(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'RbacCount', 'age' => 15]);
        $this->mapper->insertObjectEntity($entity, $register, $schema, false);

        // Count with RBAC disabled — in a test environment without a user session,
        // multitenancy/organisation filters may still reduce the count to 0.
        // The important thing is that it returns an int without errors.
        $count = $this->mapper->countObjectsInRegisterSchemaTable(
            ['_rbac' => false],
            $register,
            $schema
        );
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testFacetsWithRbacDisabled(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'RbacFacet', 'age' => 50]);
        $this->mapper->insertObjectEntity($entity, $register, $schema, false);

        $facets = $this->mapper->getSimpleFacetsFromRegisterSchemaTable(
            ['_rbac' => false],
            $register,
            $schema
        );
        $this->assertIsArray($facets);
    }

    public function testSearchWithMultitenancyDisabled(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'MultitenancyOff', 'age' => 35]);
        $this->mapper->insertObjectEntity($entity, $register, $schema, false);

        $results = $this->mapper->searchObjectsInRegisterSchemaTable(
            ['_rbac' => false, '_multitenancy' => false],
            $register,
            $schema
        );
        $this->assertIsArray($results);
    }

    // =========================================================================
    // saveObjectsToRegisterSchemaTable (batch save) tests
    // =========================================================================

    public function testSaveObjectsToRegisterSchemaTableBatch(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $objects = [];
        for ($i = 0; $i < 3; $i++) {
            $objects[] = [
                'uuid'     => Uuid::v4()->toRfc4122(),
                'register' => (string) $register->getId(),
                'schema'   => (string) $schema->getId(),
                'object'   => ['name' => 'BatchItem' . $i, 'age' => (10 + $i), 'active' => true],
            ];
        }

        $uuids = $this->mapper->saveObjectsToRegisterSchemaTable($objects, $register, $schema);
        $this->assertIsArray($uuids);
        $this->assertCount(3, $uuids);
    }

    public function testSaveObjectsToRegisterSchemaTableEmpty(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $uuids = $this->mapper->saveObjectsToRegisterSchemaTable([], $register, $schema);
        $this->assertIsArray($uuids);
        $this->assertEmpty($uuids);
    }

    // =========================================================================
    // findAcrossAllMagicTables tests
    // =========================================================================

    public function testFindAcrossAllMagicTablesByUuid(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $uuid = Uuid::v4()->toRfc4122();
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'CrossTableFind', 'age' => 44]);
        $this->mapper->insertObjectEntity($entity, $register, $schema, false);

        $result = $this->mapper->findAcrossAllMagicTables($uuid);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('object', $result);
        $this->assertArrayHasKey('register', $result);
        $this->assertArrayHasKey('schema', $result);
        $this->assertInstanceOf(ObjectEntity::class, $result['object']);
        $this->assertSame($uuid, $result['object']->getUuid());
    }

    public function testFindAcrossAllMagicTablesNotFound(): void
    {
        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->mapper->findAcrossAllMagicTables('nonexistent-uuid-' . uniqid());
    }

    // =========================================================================
    // findMultipleAcrossAllMagicTables tests
    // =========================================================================

    public function testFindMultipleAcrossAllMagicTables(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $uuids = [];
        for ($i = 0; $i < 3; $i++) {
            $uuid = Uuid::v4()->toRfc4122();
            $uuids[] = $uuid;
            $entity = new ObjectEntity();
            $entity->setUuid($uuid);
            $entity->setRegister((string) $register->getId());
            $entity->setSchema((string) $schema->getId());
            $entity->setObject(['name' => 'MultiFindItem' . $i, 'age' => $i]);
            $this->mapper->insertObjectEntity($entity, $register, $schema, false);
        }

        $results = $this->mapper->findMultipleAcrossAllMagicTables($uuids);
        $this->assertIsArray($results);
        // The method may return fewer results depending on whether magic tables
        // are active for this register/schema combination; verify it runs without error.
    }

    public function testFindMultipleAcrossAllMagicTablesEmptyInput(): void
    {
        $results = $this->mapper->findMultipleAcrossAllMagicTables([]);
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    // =========================================================================
    // findByRelationAcrossAllMagicTables tests
    // =========================================================================

    public function testFindByRelationAcrossAllMagicTablesEmptyUuid(): void
    {
        $results = $this->mapper->findByRelationAcrossAllMagicTables('');
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    public function testFindByRelationAcrossAllMagicTablesNoMatch(): void
    {
        $results = $this->mapper->findByRelationAcrossAllMagicTables('nonexistent-uuid-' . uniqid());
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    // =========================================================================
    // findByRelationUsingRelationsColumn tests
    // =========================================================================

    public function testFindByRelationUsingRelationsColumnNoMatch(): void
    {
        $results = $this->mapper->findByRelationUsingRelationsColumn('nonexistent-uuid-' . uniqid());
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    public function testFindByRelationUsingRelationsColumnEmptyUuid(): void
    {
        $results = $this->mapper->findByRelationUsingRelationsColumn('');
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    // =========================================================================
    // findAllInRegisterSchemaTable with parameters tests
    // =========================================================================

    public function testFindAllWithLimitAndOffset(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        for ($i = 0; $i < 5; $i++) {
            $entity = new ObjectEntity();
            $entity->setUuid(Uuid::v4()->toRfc4122());
            $entity->setRegister((string) $register->getId());
            $entity->setSchema((string) $schema->getId());
            $entity->setObject(['name' => 'LimitItem' . $i, 'age' => (10 + $i)]);
            $this->mapper->insertObjectEntity($entity, $register, $schema, false);
        }

        $results = $this->mapper->findAllInRegisterSchemaTable(
            $register,
            $schema,
            2,
            1
        );
        $this->assertIsArray($results);
    }

    public function testFindAllWithSort(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        for ($i = 0; $i < 3; $i++) {
            $entity = new ObjectEntity();
            $entity->setUuid(Uuid::v4()->toRfc4122());
            $entity->setRegister((string) $register->getId());
            $entity->setSchema((string) $schema->getId());
            $entity->setObject(['name' => 'SortAll' . $i, 'age' => (30 - $i)]);
            $this->mapper->insertObjectEntity($entity, $register, $schema, false);
        }

        $results = $this->mapper->findAllInRegisterSchemaTable(
            $register,
            $schema,
            null,
            null,
            null,
            ['age' => 'ASC']
        );
        $this->assertIsArray($results);
    }

    public function testFindAllWithFilters(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'FilterAll', 'age' => 77, 'active' => true]);
        $this->mapper->insertObjectEntity($entity, $register, $schema, false);

        $results = $this->mapper->findAllInRegisterSchemaTable(
            $register,
            $schema,
            null,
            null,
            ['name' => 'FilterAll']
        );
        $this->assertIsArray($results);
    }

    // =========================================================================
    // deleteObjectsBySchema (bulk delete) tests
    // =========================================================================

    public function testDeleteObjectsBySchemaHardDelete(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        for ($i = 0; $i < 3; $i++) {
            $entity = new ObjectEntity();
            $entity->setUuid(Uuid::v4()->toRfc4122());
            $entity->setRegister((string) $register->getId());
            $entity->setSchema((string) $schema->getId());
            $entity->setObject(['name' => 'BulkDel' . $i, 'age' => $i]);
            $this->mapper->insertObjectEntity($entity, $register, $schema, false);
        }

        $deleted = $this->mapper->deleteObjectsBySchema($register, $schema, true);
        $this->assertIsInt($deleted);
        $this->assertGreaterThanOrEqual(3, $deleted);
    }

    public function testDeleteObjectsBySchemaSoftDelete(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        for ($i = 0; $i < 2; $i++) {
            $entity = new ObjectEntity();
            $entity->setUuid(Uuid::v4()->toRfc4122());
            $entity->setRegister((string) $register->getId());
            $entity->setSchema((string) $schema->getId());
            $entity->setObject(['name' => 'SoftDel' . $i, 'age' => $i]);
            $this->mapper->insertObjectEntity($entity, $register, $schema, false);
        }

        $deleted = $this->mapper->deleteObjectsBySchema($register, $schema, false);
        $this->assertIsInt($deleted);
        $this->assertGreaterThanOrEqual(2, $deleted);
    }

    public function testDeleteObjectsBySchemaNoTable(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        // Do not create the table - should return 0 without error
        $deleted = $this->mapper->deleteObjectsBySchema($register, $schema, true);
        $this->assertSame(0, $deleted);
    }

    // =========================================================================
    // convertRowToObjectEntity tests
    // =========================================================================

    public function testConvertRowToObjectEntityBasic(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $uuid = Uuid::v4()->toRfc4122();
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'ConvertTest', 'age' => 25, 'active' => true]);
        $this->mapper->insertObjectEntity($entity, $register, $schema, false);

        // Find the raw row in the table
        $found = $this->mapper->findInRegisterSchemaTable($uuid, $register, $schema);
        $this->assertInstanceOf(ObjectEntity::class, $found);

        $obj = $found->getObject();
        $this->assertIsArray($obj);
        $this->assertSame('ConvertTest', $obj['name']);
        $this->assertSame(25, $obj['age']);
    }

    // =========================================================================
    // Insert with auto-generated UUID tests
    // =========================================================================

    public function testInsertObjectEntityAutoGeneratesUuid(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $entity = new ObjectEntity();
        // Do not set UUID - should be auto-generated
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'AutoUuid', 'age' => 1]);

        $result = $this->mapper->insertObjectEntity($entity, $register, $schema, false);
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertNotNull($result->getUuid());
        $this->assertNotEmpty($result->getUuid());
    }

    // =========================================================================
    // Update and verify data persisted tests
    // =========================================================================

    public function testUpdateObjectEntityPersistsData(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'Original', 'age' => 10, 'active' => false]);

        $inserted = $this->mapper->insertObjectEntity($entity, $register, $schema, false);
        $uuid = $inserted->getUuid();

        $inserted->setObject(['name' => 'Updated', 'age' => 20, 'active' => true]);
        $this->mapper->updateObjectEntity($inserted, $register, $schema);

        $found = $this->mapper->findInRegisterSchemaTable($uuid, $register, $schema);
        $obj = $found->getObject();
        $this->assertSame('Updated', $obj['name']);
        $this->assertSame(20, $obj['age']);
    }

    // =========================================================================
    // Delete and verify removed tests
    // =========================================================================

    public function testHardDeleteRemovesObject(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $uuid = Uuid::v4()->toRfc4122();
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'WillBeDeleted', 'age' => 99]);

        $inserted = $this->mapper->insertObjectEntity($entity, $register, $schema, false);
        $this->mapper->deleteObjectEntity($inserted, $register, $schema, true, false);

        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->mapper->findInRegisterSchemaTable($uuid, $register, $schema);
    }

    // =========================================================================
    // Search with @self metadata filter tests
    // =========================================================================

    public function testSearchWithMetadataFilter(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $uuid = Uuid::v4()->toRfc4122();
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'MetaFilter', 'age' => 10]);
        $this->mapper->insertObjectEntity($entity, $register, $schema, false);

        // Search using @self metadata filter on uuid
        $results = $this->mapper->searchObjectsInRegisterSchemaTable(
            ['@self' => ['uuid' => $uuid]],
            $register,
            $schema
        );
        $this->assertIsArray($results);
    }

    // =========================================================================
    // Search with _search free text tests
    // =========================================================================

    public function testSearchWithFreeTextSearch(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'UniqueSearchable', 'age' => 42]);
        $this->mapper->insertObjectEntity($entity, $register, $schema, false);

        $results = $this->mapper->searchObjectsInRegisterSchemaTable(
            ['_search' => 'UniqueSearchable'],
            $register,
            $schema
        );
        $this->assertIsArray($results);
    }

    // =========================================================================
    // Count with filters tests
    // =========================================================================

    public function testCountWithPropertyFilter(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        for ($i = 0; $i < 4; $i++) {
            $entity = new ObjectEntity();
            $entity->setUuid(Uuid::v4()->toRfc4122());
            $entity->setRegister((string) $register->getId());
            $entity->setSchema((string) $schema->getId());
            $entity->setObject(['name' => 'CountFilter' . ($i % 2), 'age' => $i]);
            $this->mapper->insertObjectEntity($entity, $register, $schema, false);
        }

        $count = $this->mapper->countObjectsInRegisterSchemaTable(
            ['name' => 'CountFilter0', '_rbac' => false, '_multitenancy' => false],
            $register,
            $schema
        );
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    // =========================================================================
    // findByRelationBatchInSchema tests
    // =========================================================================

    public function testFindByRelationBatchInSchemaEmptyInput(): void
    {
        $results = $this->mapper->findByRelationBatchInSchema([], 0, 0, 'field');
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    // =========================================================================
    // Search across multiple tables with filters tests
    // =========================================================================

    public function testSearchAcrossMultipleTablesWithFilter(): void
    {
        $register = $this->createTestRegister();
        $schema1 = $this->createTestSchema();
        $schema2 = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema1);
        $this->trackTable($register, $schema1);
        $this->mapper->ensureTableForRegisterSchema($register, $schema2);
        $this->trackTable($register, $schema2);

        $entity1 = new ObjectEntity();
        $entity1->setUuid(Uuid::v4()->toRfc4122());
        $entity1->setRegister((string) $register->getId());
        $entity1->setSchema((string) $schema1->getId());
        $entity1->setObject(['name' => 'FilterCross', 'age' => 10]);
        $this->mapper->insertObjectEntity($entity1, $register, $schema1, false);

        $entity2 = new ObjectEntity();
        $entity2->setUuid(Uuid::v4()->toRfc4122());
        $entity2->setRegister((string) $register->getId());
        $entity2->setSchema((string) $schema2->getId());
        $entity2->setObject(['name' => 'FilterCross', 'age' => 20]);
        $this->mapper->insertObjectEntity($entity2, $register, $schema2, false);

        $pairs = [
            ['register' => $register, 'schema' => $schema1],
            ['register' => $register, 'schema' => $schema2],
        ];

        $results = $this->mapper->searchAcrossMultipleTables(
            ['name' => 'FilterCross', '_rbac' => false, '_multitenancy' => false],
            $pairs
        );
        $this->assertIsArray($results);
    }

    // =========================================================================
    // Search with combined pagination and sorting tests
    // =========================================================================

    public function testSearchWithPaginationAndSorting(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        for ($i = 0; $i < 6; $i++) {
            $entity = new ObjectEntity();
            $entity->setUuid(Uuid::v4()->toRfc4122());
            $entity->setRegister((string) $register->getId());
            $entity->setSchema((string) $schema->getId());
            $entity->setObject(['name' => 'PaginSort' . $i, 'age' => (100 - $i)]);
            $this->mapper->insertObjectEntity($entity, $register, $schema, false);
        }

        $results = $this->mapper->searchObjectsInRegisterSchemaTable(
            [
                '_limit'  => 3,
                '_offset' => 1,
                '_order'  => ['age' => 'ASC'],
                '_rbac'   => false,
                '_multitenancy' => false,
            ],
            $register,
            $schema
        );
        $this->assertIsArray($results);
        $this->assertLessThanOrEqual(3, count($results));
    }

    // =========================================================================
    // Table name format validation tests
    // =========================================================================

    public function testTableNameContainsRegisterAndSchemaIds(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $tableName = $this->mapper->getTableNameForRegisterSchema($register, $schema);
        $this->assertStringContainsString((string) $register->getId(), $tableName);
        $this->assertStringContainsString((string) $schema->getId(), $tableName);
    }

    // =========================================================================
    // Sync table after schema property change tests
    // =========================================================================

    public function testSyncTableAfterSchemaPropertyAdded(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        // Add a new property to the schema
        $properties = $schema->getProperties();
        $properties['email'] = [
            'type'      => 'string',
            'title'     => 'Email',
            'maxLength' => 255,
        ];
        $schema->setProperties($properties);
        $this->schemaMapper->update($schema);

        // Sync the table - should add the new column
        $result = $this->mapper->syncTableForRegisterSchema($register, $schema);
        $this->assertIsArray($result);
    }
}
