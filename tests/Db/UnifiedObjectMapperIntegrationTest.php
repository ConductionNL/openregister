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
        // Insert directly into blob storage
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'phpunit-test-' . uniqid()]);

        $inserted = $this->objectEntityMapper->insertEntity($entity);
        $this->createdObjectIds[] = $inserted->getId();

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

    // =========================================================================
    // Search tests
    // =========================================================================

    public function testSearchObjectsReturnsArray(): void
    {
        $results = $this->mapper->searchObjects([], null, false, false);
        $this->assertIsArray($results);
    }

    public function testCountSearchObjectsReturnsInt(): void
    {
        $count = $this->mapper->countSearchObjects([], null, false, false);
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testSearchObjectsPaginatedReturnsExpectedStructure(): void
    {
        // searchObjectsPaginated signature: (array $searchQuery, array $countQuery, ...)
        $result = $this->mapper->searchObjectsPaginated([], [], null, false, false);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total', $result);
    }

    // =========================================================================
    // Lock / Unlock tests
    // =========================================================================

    public function testLockAndUnlockObject(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        // lockObject delegates to ObjectEntityMapper::lockObject which calls
        // ObjectEntityMapper::find() — that queries the blob storage table
        // (openregister_objects) with deleted IS NULL. We must insert directly
        // into blob storage so the object can be found there.
        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'phpunit-test-lock-' . uniqid(), 'score' => 42]);

        $object = $this->objectEntityMapper->insertEntity($entity);
        $this->createdObjectIds[] = $object->getId();

        $lockResult = $this->mapper->lockObject($object->getUuid(), 300);
        $this->assertIsArray($lockResult);
        $this->assertArrayHasKey('uuid', $lockResult);

        $unlocked = $this->mapper->unlockObject($object->getUuid());
        $this->assertTrue($unlocked);
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
}
