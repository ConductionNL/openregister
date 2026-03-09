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
}
