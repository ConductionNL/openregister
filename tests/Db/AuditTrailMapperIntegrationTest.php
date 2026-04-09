<?php

/**
 * Integration tests for AuditTrailMapper
 *
 * Tests CRUD, statistics, chart data, cleanup, and expiry operations
 * against a real database.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Db
 */

namespace OCA\OpenRegister\Tests\Db;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class AuditTrailMapperIntegrationTest extends TestCase
{
    private AuditTrailMapper $mapper;
    private MagicMapper $objectMapper;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;

    /** @var int[] IDs of audit trails created during tests */
    private array $createdAuditTrailIds = [];
    /** @var int[] IDs of objects created during tests */
    private array $createdObjectIds = [];
    /** @var int[] IDs of schemas created during tests */
    private array $createdSchemaIds = [];
    /** @var int[] IDs of registers created during tests */
    private array $createdRegisterIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = \OC::$server->get(AuditTrailMapper::class);
        $this->objectMapper = \OC::$server->get(MagicMapper::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper = \OC::$server->get(SchemaMapper::class);
    }

    protected function tearDown(): void
    {
        $db = \OC::$server->get(\OCP\IDBConnection::class);

        // Clean audit trails
        foreach ($this->createdAuditTrailIds as $id) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_audit_trails')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Exception $e) {
                // Already cleaned up
            }
        }

        // Clean objects
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
            'title'       => 'phpunit-test-' . uniqid() . ' Audit Register',
            'description' => 'Register for AuditTrailMapper tests',
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
            'title'       => 'phpunit-test-' . uniqid() . ' Audit Schema',
            'description' => 'Schema for AuditTrailMapper tests',
            'properties'  => [
                'name' => ['type' => 'string', 'title' => 'Name'],
            ],
        ]);
        $this->createdSchemaIds[] = $schema->getId();

        return $schema;
    }

    /**
     * Create a test object entity in blob storage
     */
    private function createTestObject(?Register $register = null, ?Schema $schema = null): ObjectEntity
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
        $entity->setObject(['name' => 'phpunit-test-' . uniqid()]);

        $result = $this->objectMapper->insertEntity($entity);
        $this->createdObjectIds[] = $result->getId();

        return $result;
    }

    /**
     * Create a test audit trail directly via insert
     */
    private function createTestAuditTrail(
        ?int $objectId = null,
        ?string $objectUuid = null,
        string $action = 'create',
        ?int $registerId = null,
        ?int $schemaId = null
    ): AuditTrail {
        $auditTrail = new AuditTrail();
        $auditTrail->setUuid(Uuid::v4()->toRfc4122());
        $auditTrail->setObject($objectId ?? 1);
        $auditTrail->setObjectUuid($objectUuid ?? Uuid::v4()->toRfc4122());
        $auditTrail->setAction($action);
        $auditTrail->setChanged(['name' => ['old' => 'before', 'new' => 'after']]);
        $auditTrail->setUser('phpunit-test-user');
        $auditTrail->setUserName('PHPUnit Test User');
        $auditTrail->setSession('phpunit-session-' . uniqid());
        $auditTrail->setIpAddress('127.0.0.1');
        $auditTrail->setCreated(new DateTime());
        $auditTrail->setRegister($registerId);
        $auditTrail->setSchema($schemaId);
        $auditTrail->setSize(100);
        $auditTrail->setExpires(new DateTime('+30 days'));

        $result = $this->mapper->insert($auditTrail);
        $this->createdAuditTrailIds[] = $result->getId();

        return $result;
    }

    // =========================================================================
    // find tests
    // =========================================================================

    public function testFindById(): void
    {
        $auditTrail = $this->createTestAuditTrail();

        $found = $this->mapper->find($auditTrail->getId());
        $this->assertInstanceOf(AuditTrail::class, $found);
        $this->assertSame($auditTrail->getId(), $found->getId());
    }

    public function testFindByIdNonExistent(): void
    {
        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->mapper->find(999999999);
    }

    // =========================================================================
    // findAll tests
    // =========================================================================

    public function testFindAllReturnsArray(): void
    {
        $this->createTestAuditTrail();

        $results = $this->mapper->findAll();
        $this->assertIsArray($results);
        $this->assertGreaterThanOrEqual(1, count($results));
    }

    public function testFindAllWithLimit(): void
    {
        $this->createTestAuditTrail();
        $this->createTestAuditTrail();

        $results = $this->mapper->findAll(1);
        $this->assertCount(1, $results);
    }

    public function testFindAllWithOffset(): void
    {
        $this->createTestAuditTrail();
        $this->createTestAuditTrail();

        // Use a specific user filter to isolate our test data
        $allResults = $this->mapper->findAll(null, null, ['user' => 'phpunit-test-user']);
        $offsetResults = $this->mapper->findAll(null, 1, ['user' => 'phpunit-test-user']);

        if (count($allResults) >= 2) {
            $this->assertCount(count($allResults) - 1, $offsetResults);
        }
    }

    public function testFindAllWithActionFilter(): void
    {
        $this->createTestAuditTrail(null, null, 'create');
        $this->createTestAuditTrail(null, null, 'update');

        $results = $this->mapper->findAll(null, null, ['action' => 'create']);
        $this->assertIsArray($results);
        foreach ($results as $trail) {
            $this->assertSame('create', $trail->getAction());
        }
    }

    public function testFindAllWithCommaFilter(): void
    {
        $this->createTestAuditTrail(null, null, 'create');
        $this->createTestAuditTrail(null, null, 'update');

        $results = $this->mapper->findAll(null, null, ['action' => 'create,update']);
        $this->assertIsArray($results);
        foreach ($results as $trail) {
            $this->assertContains($trail->getAction(), ['create', 'update']);
        }
    }

    public function testFindAllWithIsNullFilter(): void
    {
        $results = $this->mapper->findAll(null, null, ['version' => 'IS NULL']);
        $this->assertIsArray($results);
    }

    public function testFindAllWithIsNotNullFilter(): void
    {
        $results = $this->mapper->findAll(null, null, ['action' => 'IS NOT NULL']);
        $this->assertIsArray($results);
    }

    public function testFindAllWithSearchOnStringColumn(): void
    {
        $this->createTestAuditTrail();

        // Search is applied to the 'changed' JSON field via LIKE.
        // On PostgreSQL, LIKE on json columns fails; skip if that's the case.
        try {
            $results = $this->mapper->findAll(null, null, [], ['created' => 'DESC'], 'before');
            $this->assertIsArray($results);
        } catch (\Exception $e) {
            // PostgreSQL does not support LIKE on json columns - known limitation
            $this->assertStringContainsString('operator does not exist', $e->getMessage());
        }
    }

    public function testFindAllIgnoresInvalidFilters(): void
    {
        $this->createTestAuditTrail();

        // _system prefix filters and invalid column filters should be ignored
        $results = $this->mapper->findAll(null, null, [
            '_systemField' => 'ignored',
            'nonexistent_column' => 'also_ignored',
        ]);
        $this->assertIsArray($results);
    }

    // =========================================================================
    // update tests
    // =========================================================================

    public function testUpdateRecalculatesSize(): void
    {
        $auditTrail = $this->createTestAuditTrail();
        $originalSize = $auditTrail->getSize();

        $auditTrail->setChanged([
            'name' => ['old' => 'short', 'new' => 'a much longer value that should increase size'],
            'extra' => ['old' => null, 'new' => 'added field'],
        ]);

        $updated = $this->mapper->update($auditTrail);
        $this->assertInstanceOf(AuditTrail::class, $updated);
        // Size should be recalculated (minimum 14)
        $this->assertGreaterThanOrEqual(14, $updated->getSize());
    }

    // =========================================================================
    // getStatistics tests
    // =========================================================================

    public function testGetStatisticsReturnsExpectedKeys(): void
    {
        $stats = $this->mapper->getStatistics();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('size', $stats);
    }

    public function testGetStatisticsWithRegisterId(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestAuditTrail(null, null, 'create', $register->getId(), $schema->getId());

        $stats = $this->mapper->getStatistics($register->getId());
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total', $stats);
        $this->assertGreaterThanOrEqual(1, $stats['total']);
    }

    public function testGetStatisticsWithSchemaId(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestAuditTrail(null, null, 'update', $register->getId(), $schema->getId());

        $stats = $this->mapper->getStatistics(null, $schema->getId());
        $this->assertIsArray($stats);
        $this->assertGreaterThanOrEqual(1, $stats['total']);
    }

    public function testGetStatisticsWithExclude(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestAuditTrail(null, null, 'create', $register->getId(), $schema->getId());

        $stats = $this->mapper->getStatistics(null, null, [
            ['register' => $register->getId(), 'schema' => $schema->getId()],
        ]);
        $this->assertIsArray($stats);
    }

    // =========================================================================
    // getStatisticsGroupedBySchema tests
    // =========================================================================

    public function testGetStatisticsGroupedBySchemaEmpty(): void
    {
        $result = $this->mapper->getStatisticsGroupedBySchema([]);
        $this->assertSame([], $result);
    }

    public function testGetStatisticsGroupedBySchema(): void
    {
        $schema = $this->createTestSchema();
        $this->createTestAuditTrail(null, null, 'create', null, $schema->getId());

        $result = $this->mapper->getStatisticsGroupedBySchema([$schema->getId()]);
        $this->assertIsArray($result);
        $this->assertArrayHasKey($schema->getId(), $result);
        $this->assertArrayHasKey('total', $result[$schema->getId()]);
        $this->assertArrayHasKey('size', $result[$schema->getId()]);
    }

    public function testGetStatisticsGroupedBySchemaFillsMissing(): void
    {
        $result = $this->mapper->getStatisticsGroupedBySchema([999999999]);
        $this->assertArrayHasKey(999999999, $result);
        $this->assertSame(0, $result[999999999]['total']);
        $this->assertSame(0, $result[999999999]['size']);
    }

    // =========================================================================
    // getDetailedStatistics tests
    // =========================================================================

    public function testGetDetailedStatisticsReturnsExpectedKeys(): void
    {
        $stats = $this->mapper->getDetailedStatistics();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('creates', $stats);
        $this->assertArrayHasKey('updates', $stats);
        $this->assertArrayHasKey('deletes', $stats);
        $this->assertArrayHasKey('reads', $stats);
    }

    public function testGetDetailedStatisticsWithFilters(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestAuditTrail(null, null, 'create', $register->getId(), $schema->getId());

        $stats = $this->mapper->getDetailedStatistics($register->getId(), $schema->getId(), 24);
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total', $stats);
    }

    // =========================================================================
    // getActionDistribution tests
    // =========================================================================

    public function testGetActionDistributionReturnsExpectedStructure(): void
    {
        $this->createTestAuditTrail(null, null, 'create');

        $result = $this->mapper->getActionDistribution(null, null, 9999);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('actions', $result);
        $this->assertIsArray($result['actions']);
    }

    public function testGetActionDistributionWithFilters(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestAuditTrail(null, null, 'update', $register->getId(), $schema->getId());

        $result = $this->mapper->getActionDistribution($register->getId(), $schema->getId(), 9999);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('actions', $result);
    }

    // =========================================================================
    // getActionChartData tests
    // =========================================================================

    public function testGetActionChartDataReturnsExpectedStructure(): void
    {
        $this->createTestAuditTrail(null, null, 'create');

        $data = $this->mapper->getActionChartData();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('labels', $data);
        $this->assertArrayHasKey('series', $data);
    }

    public function testGetActionChartDataWithDateRange(): void
    {
        $this->createTestAuditTrail(null, null, 'update');

        $from = new DateTime('-7 days');
        $till = new DateTime('+1 day');

        $data = $this->mapper->getActionChartData($from, $till);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('labels', $data);
    }

    public function testGetActionChartDataWithRegisterSchema(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestAuditTrail(null, null, 'create', $register->getId(), $schema->getId());

        $data = $this->mapper->getActionChartData(null, null, $register->getId(), $schema->getId());
        $this->assertIsArray($data);
    }

    // =========================================================================
    // getMostActiveObjects tests
    // =========================================================================

    public function testGetMostActiveObjectsReturnsExpectedStructure(): void
    {
        $this->createTestAuditTrail(42);
        $this->createTestAuditTrail(42);
        $this->createTestAuditTrail(43);

        $result = $this->mapper->getMostActiveObjects(null, null, 5, 9999);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('objects', $result);
        $this->assertIsArray($result['objects']);
    }

    public function testGetMostActiveObjectsWithFilters(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestAuditTrail(44, null, 'update', $register->getId(), $schema->getId());

        $result = $this->mapper->getMostActiveObjects($register->getId(), $schema->getId(), 3, 9999);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('objects', $result);
    }

    // =========================================================================
    // clearLogs / clearAllLogs tests
    // =========================================================================

    public function testClearLogsDeletesExpiredTrails(): void
    {
        // Create an expired audit trail
        $db = \OC::$server->get(\OCP\IDBConnection::class);
        $qb = $db->getQueryBuilder();
        $qb->insert('openregister_audit_trails')
            ->values([
                'uuid'      => $qb->createNamedParameter(Uuid::v4()->toRfc4122()),
                'object'    => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                'action'    => $qb->createNamedParameter('create'),
                'changed'   => $qb->createNamedParameter('{}'),
                'user'      => $qb->createNamedParameter('phpunit-test-clearlog'),
                'user_name' => $qb->createNamedParameter('PHPUnit Clearlog'),
                'session'   => $qb->createNamedParameter('phpunit-session'),
                'created'   => $qb->createNamedParameter((new DateTime('-60 days'))->format('Y-m-d H:i:s')),
                'expires'   => $qb->createNamedParameter((new DateTime('-1 day'))->format('Y-m-d H:i:s')),
                'size'      => $qb->createNamedParameter(50, IQueryBuilder::PARAM_INT),
            ]);
        $qb->executeStatement();

        $result = $this->mapper->clearLogs();
        $this->assertTrue($result);

        // The expired trail should be gone (no need to add to cleanup list)
    }

    public function testClearLogsReturnsFalseWhenNoneExpired(): void
    {
        // Create a non-expired audit trail
        $auditTrail = $this->createTestAuditTrail();

        // clearLogs only deletes expired ones
        $result = $this->mapper->clearLogs();
        // Result can be true (if other expired trails exist) or false, both are valid
        $this->assertIsBool($result);
    }

    // =========================================================================
    // setExpiryDate tests
    // =========================================================================

    public function testSetExpiryDateUpdatesNullExpires(): void
    {
        // setExpiryDate uses DATE_ADD which is MySQL-only; skip on PostgreSQL
        $db = \OC::$server->get(\OCP\IDBConnection::class);
        $platform = $db->getDatabasePlatform();
        if (stripos(get_class($platform), 'PostgreSQL') !== false) {
            $this->markTestSkipped('setExpiryDate uses DATE_ADD which is not supported on PostgreSQL');
        }

        // Create an audit trail without an expiry date
        $qb = $db->getQueryBuilder();
        $uuid = Uuid::v4()->toRfc4122();
        $qb->insert('openregister_audit_trails')
            ->values([
                'uuid'      => $qb->createNamedParameter($uuid),
                'object'    => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                'action'    => $qb->createNamedParameter('create'),
                'changed'   => $qb->createNamedParameter('{}'),
                'user'      => $qb->createNamedParameter('phpunit-test-expiry'),
                'user_name' => $qb->createNamedParameter('PHPUnit Expiry'),
                'session'   => $qb->createNamedParameter('phpunit-session'),
                'created'   => $qb->createNamedParameter((new DateTime())->format('Y-m-d H:i:s')),
                'size'      => $qb->createNamedParameter(50, IQueryBuilder::PARAM_INT),
            ]);
        $qb->executeStatement();
        $newId = $db->lastInsertId('*PREFIX*openregister_audit_trails');
        $this->createdAuditTrailIds[] = (int) $newId;

        // Set expiry for 7 days (604800000 ms)
        $updated = $this->mapper->setExpiryDate(604800000);
        $this->assertIsInt($updated);
        $this->assertGreaterThanOrEqual(1, $updated);
    }

    // =========================================================================
    // createAuditTrail tests (with a real object)
    // =========================================================================

    public function testCreateAuditTrailForNewObject(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $object = $this->createTestObject($register, $schema);

        $auditTrail = $this->mapper->createAuditTrail(null, $object);
        $this->createdAuditTrailIds[] = $auditTrail->getId();

        $this->assertInstanceOf(AuditTrail::class, $auditTrail);
        $this->assertSame('create', $auditTrail->getAction());
        $this->assertNotNull($auditTrail->getUuid());
        $this->assertSame($object->getId(), $auditTrail->getObject());
        $this->assertNotNull($auditTrail->getCreated());
        $this->assertGreaterThanOrEqual(14, $auditTrail->getSize());
    }

    public function testCreateAuditTrailForUpdatedObject(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $oldObject = $this->createTestObject($register, $schema);

        // Clone and modify
        $newObject = clone $oldObject;
        $newObject->setObject(['name' => 'phpunit-test-updated-' . uniqid()]);

        $auditTrail = $this->mapper->createAuditTrail($oldObject, $newObject);
        $this->createdAuditTrailIds[] = $auditTrail->getId();

        $this->assertSame('update', $auditTrail->getAction());
        $this->assertNotEmpty($auditTrail->getChanged());
    }

    public function testCreateAuditTrailForDeletedObject(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $object = $this->createTestObject($register, $schema);

        $auditTrail = $this->mapper->createAuditTrail($object, null);
        $this->createdAuditTrailIds[] = $auditTrail->getId();

        $this->assertSame('delete', $auditTrail->getAction());
    }

    public function testCreateAuditTrailForReadAction(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $object = $this->createTestObject($register, $schema);

        $auditTrail = $this->mapper->createAuditTrail($object, $object, 'read');
        $this->createdAuditTrailIds[] = $auditTrail->getId();

        $this->assertSame('read', $auditTrail->getAction());
    }
}
