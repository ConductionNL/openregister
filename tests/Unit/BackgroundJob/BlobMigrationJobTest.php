<?php

declare(strict_types=1);

/**
 * BlobMigrationJob Unit Tests
 *
 * Tests the recurring background job that migrates objects from the legacy blob
 * table (oc_openregister_objects) to schema-specific magic tables.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\BlobMigrationJob;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Test class for BlobMigrationJob
 */
class BlobMigrationJobTest extends TestCase
{
    private ITimeFactory&MockObject $timeFactory;
    private LoggerInterface&MockObject $logger;
    private IDBConnection&MockObject $db;
    private IAppConfig&MockObject $appConfig;
    private RegisterMapper&MockObject $registerMapper;
    private SchemaMapper&MockObject $schemaMapper;
    private MagicMapper&MockObject $magicMapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->timeFactory    = $this->createMock(ITimeFactory::class);
        $this->logger         = $this->createMock(LoggerInterface::class);
        $this->db             = $this->createMock(IDBConnection::class);
        $this->appConfig      = $this->createMock(IAppConfig::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper   = $this->createMock(SchemaMapper::class);
        $this->magicMapper    = $this->createMock(MagicMapper::class);
    }

    /**
     * Create the job instance and register mocks in \OC::$server.
     */
    private function makeJob(): BlobMigrationJob
    {
        \OC::$server->registerService(LoggerInterface::class, function () {
            return $this->logger;
        });
        \OC::$server->registerService(IDBConnection::class, function () {
            return $this->db;
        });
        \OC::$server->registerService(IAppConfig::class, function () {
            return $this->appConfig;
        });
        \OC::$server->registerService(RegisterMapper::class, function () {
            return $this->registerMapper;
        });
        \OC::$server->registerService(SchemaMapper::class, function () {
            return $this->schemaMapper;
        });
        \OC::$server->registerService(MagicMapper::class, function () {
            return $this->magicMapper;
        });

        return new BlobMigrationJob($this->timeFactory);
    }

    /**
     * Invoke the protected run() method via reflection.
     */
    private function runJob(BlobMigrationJob $job, mixed $argument = []): void
    {
        $ref    = new ReflectionClass($job);
        $method = $ref->getMethod('run');
        $method->setAccessible(true);
        $method->invoke($job, $argument);
    }

    /**
     * Invoke the private groupByRegisterSchema() method via reflection.
     *
     * @param BlobMigrationJob $job     The job instance.
     * @param array            $objects The objects to group.
     *
     * @return array The grouped result.
     */
    private function invokeGroupByRegisterSchema(BlobMigrationJob $job, array $objects): array
    {
        $ref    = new ReflectionClass($job);
        $method = $ref->getMethod('groupByRegisterSchema');
        $method->setAccessible(true);

        return $method->invoke($job, $objects, $this->logger);
    }

    /**
     * Invoke the private deleteBlobRows() method via reflection.
     *
     * @param BlobMigrationJob $job  The job instance.
     * @param array            $rows The rows to delete.
     */
    private function invokeDeleteBlobRows(BlobMigrationJob $job, array $rows): void
    {
        $ref    = new ReflectionClass($job);
        $method = $ref->getMethod('deleteBlobRows');
        $method->setAccessible(true);

        $method->invoke($job, $this->db, $rows);
    }

    /**
     * Create a mock query builder that returns an empty result set.
     */
    private function createEmptyQueryBuilder(): IQueryBuilder&MockObject
    {
        $result = $this->createMock(IResult::class);
        $result->method('fetchAll')->willReturn([]);
        $result->method('fetch')->willReturn(['count' => '0']);

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('executeQuery')->willReturn($result);
        $qb->method('createFunction')->willReturn('COUNT(*) as count');

        return $qb;
    }

    /**
     * Set up the appConfig mock to simulate a specific complete state.
     */
    private function setMigrationComplete(string $value): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturnCallback(static function (string $app, string $key, string $default = '') use ($value): string {
                if ($app === 'openregister' && $key === 'blob_migration_complete') {
                    return $value;
                }
                if ($app === 'openregister' && $key === 'blob_migration_processed') {
                    return '0';
                }
                return $default;
            });
    }

    // -------------------------------------------------------------------------
    // Constructor tests
    // -------------------------------------------------------------------------

    public function testIntervalIsSetToFiveMinutes(): void
    {
        $job = $this->makeJob();

        $ref      = new ReflectionClass($job);
        $property = $ref->getProperty('interval');
        $property->setAccessible(true);

        $this->assertSame(300, $property->getValue($job));
    }

    // -------------------------------------------------------------------------
    // run() with no blob objects remaining sets migration_complete
    // -------------------------------------------------------------------------

    public function testRunWithNoBlobObjectsSetsComplete(): void
    {
        $this->setMigrationComplete('false');

        // blobTableExists check: simulate table exists.
        $stmt = $this->createMock(\OCP\DB\IPreparedStatement::class);
        $stmt->method('fetch')->willReturn(['1' => 1]);

        $platform = $this->createMock(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class);
        $this->db->method('getDatabasePlatform')->willReturn($platform);
        $this->db->method('prepare')->willReturn($stmt);

        // fetchBlobObjects returns empty — no objects remaining.
        $qb = $this->createEmptyQueryBuilder();
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $completeCalled = false;
        $remainingCalled = false;
        $this->appConfig
            ->method('setValueString')
            ->willReturnCallback(static function (string $app, string $key, string $value) use (&$completeCalled, &$remainingCalled): bool {
                if ($app === 'openregister' && $key === 'blob_migration_complete' && $value === 'true') {
                    $completeCalled = true;
                }
                if ($app === 'openregister' && $key === 'blob_migration_remaining' && $value === '0') {
                    $remainingCalled = true;
                }
                return true;
            });

        $job = $this->makeJob();
        $this->runJob($job);

        $this->assertTrue($completeCalled, 'blob_migration_complete should be set to true');
        $this->assertTrue($remainingCalled, 'blob_migration_remaining should be set to 0');
    }

    // -------------------------------------------------------------------------
    // run() skips when already complete
    // -------------------------------------------------------------------------

    public function testRunSkipsWhenAlreadyComplete(): void
    {
        $this->setMigrationComplete('true');

        $this->db->expects($this->never())->method('getQueryBuilder');

        $job = $this->makeJob();
        $this->runJob($job);
    }

    // -------------------------------------------------------------------------
    // run() processes batch and updates progress counters
    // -------------------------------------------------------------------------

    public function testRunProcessesBatchAndUpdatesCounters(): void
    {
        // Simulate not complete.
        $this->appConfig
            ->method('getValueString')
            ->willReturnCallback(static function (string $app, string $key, string $default = ''): string {
                if ($key === 'blob_migration_complete') {
                    return 'false';
                }
                if ($key === 'blob_migration_processed') {
                    return '50';
                }
                return $default;
            });

        // blobTableExists: table exists.
        $stmt = $this->createMock(\OCP\DB\IPreparedStatement::class);
        $stmt->method('fetch')->willReturn(['1' => 1]);
        $platform = $this->createMock(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class);
        $this->db->method('getDatabasePlatform')->willReturn($platform);
        $this->db->method('prepare')->willReturn($stmt);

        // fetchBlobObjects returns 2 objects in same register+schema group.
        $blobRows = [
            ['id' => 1, 'uuid' => 'uuid-1', 'register' => '10', 'schema' => '20', 'object' => '{"name":"test1"}'],
            ['id' => 2, 'uuid' => 'uuid-2', 'register' => '10', 'schema' => '20', 'object' => '{"name":"test2"}'],
        ];

        // First getQueryBuilder call: fetchBlobObjects (returns rows).
        // Second call: countBlobRows (returns 5 remaining).
        // Third call: deleteBlobRows.
        $fetchResult = $this->createMock(IResult::class);
        $fetchResult->method('fetchAll')->willReturn($blobRows);

        $countResult = $this->createMock(IResult::class);
        $countResult->method('fetch')->willReturn(['count' => '5']);

        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('in')->willReturn('id IN (1, 2)');

        $deleteQb = $this->createMock(IQueryBuilder::class);
        $deleteQb->method('delete')->willReturnSelf();
        $deleteQb->method('where')->willReturnSelf();
        $deleteQb->method('expr')->willReturn($expr);
        $deleteQb->method('createNamedParameter')->willReturn('?');
        $deleteQb->method('executeStatement')->willReturn(2);

        $fetchQb = $this->createMock(IQueryBuilder::class);
        $fetchQb->method('select')->willReturnSelf();
        $fetchQb->method('from')->willReturnSelf();
        $fetchQb->method('setMaxResults')->willReturnSelf();
        $fetchQb->method('executeQuery')->willReturn($fetchResult);

        $countQb = $this->createMock(IQueryBuilder::class);
        $countQb->method('select')->willReturnSelf();
        $countQb->method('from')->willReturnSelf();
        $countQb->method('createFunction')->willReturn('COUNT(*) as count');
        $countQb->method('executeQuery')->willReturn($countResult);

        $this->db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
            $fetchQb,
            $deleteQb,
            $countQb
        );

        // Register and schema resolving.
        $register = $this->createMock(Register::class);
        $schema   = $this->createMock(Schema::class);
        $this->registerMapper->method('find')->willReturn($register);
        $this->schemaMapper->method('find')->willReturn($schema);

        // Magic mapper: saveObjectsToRegisterSchemaTable returns UUIDs.
        $this->magicMapper->method('saveObjectsToRegisterSchemaTable')->willReturn(['uuid-1', 'uuid-2']);

        // Verify progress counters are updated.
        $setValues = [];
        $this->appConfig
            ->method('setValueString')
            ->willReturnCallback(static function (string $app, string $key, string $value) use (&$setValues): bool {
                $setValues[$key] = $value;
                return true;
            });

        $job = $this->makeJob();
        $this->runJob($job);

        $this->assertSame('52', $setValues['blob_migration_processed'] ?? null, 'Processed should be 50 + 2');
        $this->assertSame('5', $setValues['blob_migration_remaining'] ?? null, 'Remaining should be 5');
        $this->assertArrayHasKey('blob_migration_last_run', $setValues, 'last_run should be set');
    }

    // -------------------------------------------------------------------------
    // groupByRegisterSchema() handles orphaned objects (null register/schema)
    // -------------------------------------------------------------------------

    public function testGroupByRegisterSchemaGroupsOrphanedObjects(): void
    {
        $objects = [
            ['uuid' => 'uuid-1', 'register' => '10', 'schema' => '20'],
            ['uuid' => 'uuid-2', 'register' => null, 'schema' => '20'],
            ['uuid' => 'uuid-3', 'register' => '10', 'schema' => null],
            ['uuid' => 'uuid-4', 'register' => '', 'schema' => '20'],
            ['uuid' => 'uuid-5', 'register' => '10', 'schema' => '20'],
            ['uuid' => 'uuid-6', 'register' => '11', 'schema' => '21'],
        ];

        $job    = $this->makeJob();
        $groups = $this->invokeGroupByRegisterSchema($job, $objects);

        // uuid-1 and uuid-5 grouped under "10_20".
        $this->assertCount(2, $groups['10_20']);
        // uuid-6 grouped under "11_21".
        $this->assertCount(1, $groups['11_21']);
        // uuid-2, uuid-3, uuid-4 are orphaned.
        $this->assertCount(3, $groups['orphaned']);

        // Verify orphan logging.
        $this->logger
            ->expects($this->never())
            ->method('error');
    }

    public function testGroupByRegisterSchemaWithAllValidObjects(): void
    {
        $objects = [
            ['uuid' => 'uuid-1', 'register' => '10', 'schema' => '20'],
            ['uuid' => 'uuid-2', 'register' => '10', 'schema' => '20'],
        ];

        $job    = $this->makeJob();
        $groups = $this->invokeGroupByRegisterSchema($job, $objects);

        $this->assertCount(1, $groups);
        $this->assertArrayHasKey('10_20', $groups);
        $this->assertArrayNotHasKey('orphaned', $groups);
    }

    public function testGroupByRegisterSchemaWithEmptyInput(): void
    {
        $job    = $this->makeJob();
        $groups = $this->invokeGroupByRegisterSchema($job, []);

        $this->assertEmpty($groups);
    }

    // -------------------------------------------------------------------------
    // deleteBlobRows() deletes by ID array
    // -------------------------------------------------------------------------

    public function testDeleteBlobRowsDeletesByIdArray(): void
    {
        $rows = [
            ['id' => 1, 'uuid' => 'uuid-1'],
            ['id' => 2, 'uuid' => 'uuid-2'],
            ['id' => 3, 'uuid' => 'uuid-3'],
        ];

        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->expects($this->once())
            ->method('in')
            ->with('id', $this->anything())
            ->willReturn('id IN (1, 2, 3)');

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->expects($this->once())->method('delete')->with('openregister_objects')->willReturnSelf();
        $qb->expects($this->once())->method('where')->willReturnSelf();
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturn('?');
        $qb->expects($this->once())->method('executeStatement')->willReturn(3);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $job = $this->makeJob();
        $this->invokeDeleteBlobRows($job, $rows);
    }

    public function testDeleteBlobRowsSkipsWhenNoIds(): void
    {
        $rows = [
            ['uuid' => 'uuid-1'],
            ['uuid' => 'uuid-2'],
        ];

        $this->db->expects($this->never())->method('getQueryBuilder');

        $job = $this->makeJob();
        $this->invokeDeleteBlobRows($job, $rows);
    }

    public function testDeleteBlobRowsWithEmptyArray(): void
    {
        $this->db->expects($this->never())->method('getQueryBuilder');

        $job = $this->makeJob();
        $this->invokeDeleteBlobRows($job, []);
    }

    // -------------------------------------------------------------------------
    // run() marks complete when blob table does not exist
    // -------------------------------------------------------------------------

    public function testRunMarksCompleteWhenBlobTableDoesNotExist(): void
    {
        $this->setMigrationComplete('false');

        // blobTableExists: table does not exist.
        $stmt = $this->createMock(\OCP\DB\IPreparedStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $platform = $this->createMock(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class);
        $this->db->method('getDatabasePlatform')->willReturn($platform);
        $this->db->method('prepare')->willReturn($stmt);

        $completeCalled = false;
        $this->appConfig
            ->method('setValueString')
            ->willReturnCallback(static function (string $app, string $key, string $value) use (&$completeCalled): bool {
                if ($app === 'openregister' && $key === 'blob_migration_complete' && $value === 'true') {
                    $completeCalled = true;
                }
                return true;
            });

        $job = $this->makeJob();
        $this->runJob($job);

        $this->assertTrue($completeCalled, 'Should mark complete when blob table does not exist');
    }

    // -------------------------------------------------------------------------
    // run() handles exceptions gracefully
    // -------------------------------------------------------------------------

    public function testRunLogsErrorOnException(): void
    {
        $this->setMigrationComplete('false');

        // blobTableExists succeeds (table exists).
        $stmt = $this->createMock(\OCP\DB\IPreparedStatement::class);
        $stmt->method('fetch')->willReturn(['1' => 1]);
        $platform = $this->createMock(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class);
        $this->db->method('getDatabasePlatform')->willReturn($platform);
        $this->db->method('prepare')->willReturn($stmt);

        // fetchBlobObjects throws via getQueryBuilder.
        $this->db->method('getQueryBuilder')
            ->willThrowException(new \Exception('Database unavailable'));

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('error');

        // Must not propagate the exception.
        $job = $this->makeJob();
        $this->runJob($job);
    }
}
