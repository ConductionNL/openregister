<?php

/**
 * Unit tests for ArchivalRetentionTask.
 *
 * Verifies the two key sweep behaviours described in task 5.7:
 *   - A row whose `_created` is backdated past the schema's retention period
 *     is scheduled for deletion by the sweep.
 *   - A row whose `_created` is within the retention period is left untouched.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5-7
 */

declare(strict_types=1);

namespace Unit\Cron;

use OCA\OpenRegister\Cron\ArchivalRetentionTask;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\RetentionEvaluator;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Tests for the hourly archival retention sweep cron.
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5-7
 */
class ArchivalRetentionTaskTest extends TestCase
{

    private ITimeFactory&MockObject $timeFactory;
    private IDBConnection&MockObject $db;
    private RegisterMapper&MockObject $registerMapper;
    private SchemaMapper&MockObject $schemaMapper;
    private MagicMapper&MockObject $magicMapper;
    private RetentionEvaluator&MockObject $retentionEvaluator;
    private ObjectService&MockObject $objectService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->timeFactory        = $this->createMock(ITimeFactory::class);
        $this->db                 = $this->createMock(IDBConnection::class);
        $this->registerMapper     = $this->createMock(RegisterMapper::class);
        $this->schemaMapper       = $this->createMock(SchemaMapper::class);
        $this->magicMapper        = $this->createMock(MagicMapper::class);
        $this->retentionEvaluator = $this->createMock(RetentionEvaluator::class);
        $this->objectService      = $this->createMock(ObjectService::class);
        $this->logger             = $this->createMock(LoggerInterface::class);

    }//end setUp()

    /**
     * Create the task under test with all mocked dependencies injected.
     *
     * @return ArchivalRetentionTask
     */
    private function makeTask(): ArchivalRetentionTask
    {
        return new ArchivalRetentionTask(
            time: $this->timeFactory,
            registerMapper: $this->registerMapper,
            schemaMapper: $this->schemaMapper,
            magicMapper: $this->magicMapper,
            db: $this->db,
            retentionEvaluator: $this->retentionEvaluator,
            objectService: $this->objectService,
            logger: $this->logger
        );

    }//end makeTask()

    /**
     * Invoke the protected run() method via reflection.
     *
     * @param ArchivalRetentionTask $task     Task instance.
     * @param mixed                 $argument Argument to pass (default null).
     *
     * @return void
     */
    private function runTask(ArchivalRetentionTask $task, mixed $argument=null): void
    {
        $reflection = new ReflectionClass($task);
        $method     = $reflection->getMethod('run');
        $method->setAccessible(true);
        $method->invoke($task, $argument);

    }//end runTask()

    /**
     * Build a real Register entity with the given numeric ID and schema list.
     *
     * @param int   $registerId Numeric ID.
     * @param int[] $schemaIds  IDs of schemas belonging to this register.
     *
     * @return Register
     */
    private function makeRegister(int $registerId, array $schemaIds): Register
    {
        $register = new Register();
        // phpcs:disable CustomSn.Functions.NamedParameters -- Entity magic setter uses __call.
        $register->setId($registerId);
        $register->setSchemas($schemaIds);
        // phpcs:enable CustomSn.Functions.NamedParameters

        return $register;

    }//end makeRegister()

    /**
     * Build a real Schema entity declaring `x-openregister-archival`.
     *
     * @param int    $schemaId  Numeric ID.
     * @param string $slug      Human-readable slug.
     * @param string $retention ISO-8601 default retention duration.
     *
     * @return Schema
     */
    private function makeArchivalSchema(int $schemaId, string $slug='test-schema', string $retention='P30D'): Schema
    {
        $schema = new Schema();
        // phpcs:disable CustomSn.Functions.NamedParameters -- Entity magic setter uses __call.
        $schema->setId($schemaId);
        $schema->setSlug($slug);
        $schema->setConfiguration([
            'x-openregister-archival' => [
                'retention' => ['default' => $retention],
            ],
        ]);
        // phpcs:enable CustomSn.Functions.NamedParameters

        return $schema;

    }//end makeArchivalSchema()

    /**
     * Build a real Schema entity with NO archival annotation.
     *
     * @param int $schemaId Numeric ID.
     *
     * @return Schema
     */
    private function makeNonArchivalSchema(int $schemaId): Schema
    {
        $schema = new Schema();
        // phpcs:disable CustomSn.Functions.NamedParameters -- Entity magic setter uses __call.
        $schema->setId($schemaId);
        $schema->setSlug('plain-schema');
        $schema->setConfiguration([]);
        // phpcs:enable CustomSn.Functions.NamedParameters

        return $schema;

    }//end makeNonArchivalSchema()

    /**
     * Wire the DB mock so getQueryBuilder() returns a builder that yields
     * the given rows via fetchAllAssociative().
     *
     * @param array<int, array<string, mixed>> $rows Rows the fake result emits.
     *
     * @return void
     */
    private function stubDbRows(array $rows): void
    {
        // fetchAllAssociative() is not on IResult but the cron calls it.
        // Build an anonymous implementation that satisfies IResult + the extra method.
        $result = new class($rows) implements IResult {
            public function __construct(private readonly array $rows)
            {
            }

            public function closeCursor(): bool
            {
                return true;
            }

            public function fetch(int $fetchMode=\PDO::FETCH_ASSOC): mixed
            {
                return false;
            }

            public function fetchAll(int $fetchMode=\PDO::FETCH_ASSOC): array
            {
                return $this->rows;
            }

            public function fetchColumn(): mixed
            {
                return false;
            }

            public function fetchOne(): mixed
            {
                return false;
            }

            public function rowCount(): int
            {
                return count($this->rows);
            }

            public function fetchAllAssociative(): array
            {
                return $this->rows;
            }
        };

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('executeQuery')->willReturn($result);

        $this->db->method('getQueryBuilder')->willReturn($qb);

    }//end stubDbRows()

    // ─── Constructor gate tests ───────────────────────────────────────────────

    /**
     * The timed-job interval must be exactly one hour.
     *
     * @return void
     */
    public function testConstructorSetsOneHourInterval(): void
    {
        $task = $this->makeTask();

        $ref      = new ReflectionClass($task);
        $property = $ref->getProperty('interval');
        $property->setAccessible(true);

        $this->assertSame(3600, $property->getValue($task));

    }//end testConstructorSetsOneHourInterval()

    /**
     * The time-sensitivity flag must be TIME_INSENSITIVE.
     *
     * @return void
     */
    public function testConstructorSetsTimeSensitivity(): void
    {
        $task = $this->makeTask();

        $ref      = new ReflectionClass($task);
        $property = $ref->getProperty('timeSensitivity');
        $property->setAccessible(true);

        $this->assertSame(IJob::TIME_INSENSITIVE, $property->getValue($task));

    }//end testConstructorSetsTimeSensitivity()

    /**
     * Parallel runs must be disabled to prevent double-deletions.
     *
     * @return void
     */
    public function testConstructorDisablesParallelRuns(): void
    {
        $task = $this->makeTask();

        $ref      = new ReflectionClass($task);
        $property = $ref->getProperty('allowParallelRuns');
        $property->setAccessible(true);

        $this->assertFalse($property->getValue($task));

    }//end testConstructorDisablesParallelRuns()

    // ─── Core sweep behaviour ─────────────────────────────────────────────────

    /**
     * A row whose `_created` is backdated past the retention window must be
     * deleted via `ObjectService::deleteObject(..., _retentionSweep: true)`.
     *
     * @return void
     *
     * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5-7
     */
    public function testExpiredRowIsDeleted(): void
    {
        $register = $this->makeRegister(registerId: 1, schemaIds: [1]);
        $schema   = $this->makeArchivalSchema(schemaId: 1, slug: 'call-log', retention: 'P30D');

        $this->registerMapper->method('findAll')->willReturn([$register]);
        $this->schemaMapper->method('find')->willReturn($schema);

        // Magic table exists and has a name.
        $this->magicMapper->method('existsTableForRegisterSchema')->willReturn(true);
        $this->magicMapper->method('getTableNameForRegisterSchema')->willReturn('openregister_table_1_1');

        // Backdated row: created 2020-01-01 → expires 2020-01-31 → long past now.
        $expiredCreated = '2020-01-01T00:00:00+00:00';
        $this->stubDbRows([
            [
                '_uuid'    => 'aaaa-bbbb-cccc-dddd',
                '_created' => $expiredCreated,
            ],
        ]);

        // RetentionEvaluator confirms row is expired (expiresAt in the past).
        $this->retentionEvaluator->method('evaluate')->willReturn([
            'effectiveRetention' => 'P30D',
            'matchedRule'        => null,
            'expiresAt'          => '2020-01-31T00:00:00+00:00',
        ]);

        // ObjectService must be anchored then deleteObject called once with sweep flag.
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        // Positional: uuid, _rbac=false, _multitenancy=false, _retentionSweep=true.
        $this->objectService->expects($this->once())
            ->method('deleteObject')
            ->with('aaaa-bbbb-cccc-dddd', false, false, true)
            ->willReturn(true);

        $this->runTask($this->makeTask());

    }//end testExpiredRowIsDeleted()

    /**
     * A row whose `_created` falls within the retention window must NOT be
     * deleted — `deleteObject` must never be called.
     *
     * @return void
     *
     * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5-7
     */
    public function testRowWithinRetentionIsKept(): void
    {
        $register = $this->makeRegister(registerId: 1, schemaIds: [1]);
        $schema   = $this->makeArchivalSchema(schemaId: 1, slug: 'call-log', retention: 'P30D');

        $this->registerMapper->method('findAll')->willReturn([$register]);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->magicMapper->method('existsTableForRegisterSchema')->willReturn(true);
        $this->magicMapper->method('getTableNameForRegisterSchema')->willReturn('openregister_table_1_1');

        // Row created yesterday — well within the 30-day window.
        $recentCreated = (new \DateTimeImmutable())->modify('-1 day')->format(\DateTimeInterface::ATOM);
        $futureExpiry  = (new \DateTimeImmutable())->modify('+29 days')->format(\DateTimeInterface::ATOM);

        $this->stubDbRows([
            [
                '_uuid'    => '1111-2222-3333-4444',
                '_created' => $recentCreated,
            ],
        ]);

        // RetentionEvaluator says expiry is in the future.
        $this->retentionEvaluator->method('evaluate')->willReturn([
            'effectiveRetention' => 'P30D',
            'matchedRule'        => null,
            'expiresAt'          => $futureExpiry,
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();

        // deleteObject must never fire for a fresh row.
        $this->objectService->expects($this->never())->method('deleteObject');

        $this->runTask($this->makeTask());

    }//end testRowWithinRetentionIsKept()

    // ─── Short-circuit paths ──────────────────────────────────────────────────

    /**
     * When the schema carries no archival annotation the sweep skips it
     * without touching the DB or ObjectService.
     *
     * @return void
     */
    public function testNonArchivalSchemaIsSkipped(): void
    {
        $register = $this->makeRegister(registerId: 2, schemaIds: [5]);
        $schema   = $this->makeNonArchivalSchema(schemaId: 5);

        $this->registerMapper->method('findAll')->willReturn([$register]);
        $this->schemaMapper->method('find')->willReturn($schema);

        // Table check and DB must never be reached.
        $this->magicMapper->expects($this->never())->method('existsTableForRegisterSchema');
        $this->db->expects($this->never())->method('getQueryBuilder');
        $this->objectService->expects($this->never())->method('deleteObject');

        $this->runTask($this->makeTask());

    }//end testNonArchivalSchemaIsSkipped()

    /**
     * When the magic table has not been materialised yet the sweep skips
     * the schema without running a SELECT.
     *
     * @return void
     */
    public function testMissingMagicTableIsSkipped(): void
    {
        $register = $this->makeRegister(registerId: 1, schemaIds: [1]);
        $schema   = $this->makeArchivalSchema(schemaId: 1);

        $this->registerMapper->method('findAll')->willReturn([$register]);
        $this->schemaMapper->method('find')->willReturn($schema);

        // Table does not exist yet.
        $this->magicMapper->method('existsTableForRegisterSchema')->willReturn(false);

        $this->db->expects($this->never())->method('getQueryBuilder');
        $this->objectService->expects($this->never())->method('deleteObject');

        $this->runTask($this->makeTask());

    }//end testMissingMagicTableIsSkipped()

    /**
     * When findAll() throws, the sweep logs an error and exits cleanly.
     *
     * @return void
     */
    public function testRegisterLoadFailureIsHandledGracefully(): void
    {
        $this->registerMapper
            ->method('findAll')
            ->willThrowException(new \Exception('DB connection lost'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error')
            ->with(
                $this->stringContains('Failed to load registers'),
                $this->anything()
            );

        $this->objectService->expects($this->never())->method('deleteObject');

        $this->runTask($this->makeTask());

    }//end testRegisterLoadFailureIsHandledGracefully()

    /**
     * A row missing both `_uuid` and `uuid` is skipped — nothing to delete.
     *
     * @return void
     */
    public function testRowWithMissingUuidIsSkippedForDeletion(): void
    {
        $register = $this->makeRegister(registerId: 1, schemaIds: [1]);
        $schema   = $this->makeArchivalSchema(schemaId: 1, retention: 'P30D');

        $this->registerMapper->method('findAll')->willReturn([$register]);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->magicMapper->method('existsTableForRegisterSchema')->willReturn(true);
        $this->magicMapper->method('getTableNameForRegisterSchema')->willReturn('openregister_table_1_1');

        // Row without any uuid fields.
        $this->stubDbRows([['_created' => '2020-01-01T00:00:00+00:00']]);

        $this->objectService->expects($this->never())->method('deleteObject');

        $this->runTask($this->makeTask());

    }//end testRowWithMissingUuidIsSkippedForDeletion()

    /**
     * Summary log entry is emitted per schema regardless of whether rows
     * were deleted.
     *
     * @return void
     */
    public function testSummaryLogIsEmittedPerSchema(): void
    {
        $register = $this->makeRegister(registerId: 1, schemaIds: [1]);
        $schema   = $this->makeArchivalSchema(schemaId: 1, slug: 'my-log');

        $this->registerMapper->method('findAll')->willReturn([$register]);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->magicMapper->method('existsTableForRegisterSchema')->willReturn(true);
        $this->magicMapper->method('getTableNameForRegisterSchema')->willReturn('openregister_table_1_1');

        // No rows — still emits a summary.
        $this->stubDbRows([]);

        $this->logger->expects($this->atLeastOnce())
            ->method('info')
            ->with(
                $this->stringContains('[ArchivalRetentionTask]'),
                $this->anything()
            );

        $this->runTask($this->makeTask());

    }//end testSummaryLogIsEmittedPerSchema()
}//end class
