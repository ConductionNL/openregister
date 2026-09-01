<?php

/**
 * SchemaDeletionService tests — schema-wide object deletion and the teardown cascade.
 *
 * Spec REQ (runtime-schema-api):
 *   "Runtime schema deletion is guarded by object count" (cascade disposition)
 *   "Schema-wide object deletion is available as a bulk operation"
 * Spec REQ (deletion-audit-trail):
 *   "Schema-teardown cascade MUST audit every object before hard-deleting it"
 *   "Bulk schema-wide object deletion MUST produce per-object audit entries"
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\SchemaDeletionService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Unit tests for SchemaDeletionService.
 */
class SchemaDeletionServiceTest extends TestCase {

	private const REGISTER_ID = 7;

	private const SCHEMA_ID = 42;

	private const TABLE_NAME = 'openregister_table_7_42';

	/**
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection $db;

	/**
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper $magicMapper;

	/**
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper $registerMapper;

	/**
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper $schemaMapper;

	/**
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper $auditTrailMapper;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	private SchemaDeletionService $service;

	private Register $register;

	private Schema $schema;

	/**
	 * Wire the service up with every dependency mocked.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new SchemaDeletionService(
			$this->db,
			$this->magicMapper,
			$this->registerMapper,
			$this->schemaMapper,
			$this->auditTrailMapper,
			$this->logger
		);

		$this->register = $this->makeEntity(new Register(), self::REGISTER_ID);
		$this->schema = $this->makeEntity(new Schema(), self::SCHEMA_ID);
		$this->schema->setSlug('cow');
		$this->schema->setTitle('Cow');

	}//end setUp()

	/**
	 * Inject an id into an entity (Entity::$id is protected).
	 *
	 * @param object $entity The entity.
	 * @param int $id The id to inject.
	 *
	 * @return mixed The same entity.
	 */
	private function makeEntity(object $entity, int $id): mixed {
		$property = (new ReflectionClass($entity))->getProperty('id');
		$property->setAccessible(true);
		$property->setValue($entity, $id);

		return $entity;
	}//end makeEntity()

	/**
	 * Build an ObjectEntity with a uuid and some payload.
	 *
	 * @param string $uuid The object uuid.
	 *
	 * @return ObjectEntity The object.
	 */
	private function makeObject(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister((string)self::REGISTER_ID);
		$object->setSchema((string)self::SCHEMA_ID);
		$object->setObject(['name' => 'Bessie ' . $uuid]);

		return $object;
	}//end makeObject()

	/**
	 * Wire the mappers up so the schema has exactly one existing magic table.
	 *
	 * Deliberately does NOT stub findAllInRegisterSchemaTable() or
	 * deleteObjectsBySchema(): tests set those themselves, so no test ever stubs the
	 * same mock method twice.
	 *
	 * @return void
	 */
	private function stubOneMagicTable(): void {
		$this->magicMapper->method('getAllRegisterSchemaPairs')->willReturn(
			[
				[
					'registerId' => self::REGISTER_ID,
					'schemaId' => self::SCHEMA_ID,
				],
				// A pair for a DIFFERENT schema — must be ignored entirely.
				[
					'registerId' => self::REGISTER_ID,
					'schemaId' => 999,
				],
			]
		);

		$this->registerMapper->method('find')->willReturn($this->register);
		$this->schemaMapper->method('find')->willReturn($this->schema);
		$this->magicMapper->method('tableExistsForRegisterSchema')->willReturn(true);

	}//end stubOneMagicTable()

	/**
	 * Wire the mappers up for a schema whose single magic table holds $objects.
	 *
	 * @param array<int, ObjectEntity> $objects The objects the magic table holds.
	 *
	 * @return void
	 */
	private function stubOneTableWith(array $objects): void {
		$this->stubOneMagicTable();

		$this->magicMapper->method('findAllInRegisterSchemaTable')->willReturn($objects);
		$this->magicMapper->method('deleteObjectsBySchema')->willReturn(count($objects));

	}//end stubOneTableWith()

	/**
	 * REQ + SCENARIO: "Cascade — delete a schema and its objects".
	 *
	 * Every object is hard-deleted, the magic table is dropped, the schema is
	 * removed, and the response reports the count, the UUIDs and tableDropped: true.
	 */
	public function testCascadeDeletesObjectsDropsTableAndDeletesSchema(): void {
		$this->stubOneMagicTable();
		$this->magicMapper
			->method('findAllInRegisterSchemaTable')
			->willReturn([$this->makeObject('uuid-1'), $this->makeObject('uuid-2')]);

		// Rows are HARD-deleted (not soft-deleted): the table is about to be dropped.
		$this->magicMapper
			->expects($this->once())
			->method('deleteObjectsBySchema')
			->with($this->register, $this->schema, true)
			->willReturn(2);

		// The schema entity itself is deleted — without a force bypass, because the
		// rows are already gone and the mapper guard therefore counts 0.
		$this->schemaMapper->expects($this->once())->method('delete')->with($this->schema);

		// The now-empty magic table is dropped.
		$this->magicMapper->expects($this->once())->method('dropTable')->with(self::TABLE_NAME);

		// Phase 1 is transactional and commits.
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$result = $this->service->cascadeDeleteSchema($this->schema);

		$this->assertSame(2, $result['deletedCount']);
		$this->assertSame(['uuid-1', 'uuid-2'], $result['deletedUuids']);
		$this->assertTrue($result['tableDropped']);

	}//end testCascadeDeletesObjectsDropsTableAndDeletesSchema()

	/**
	 * REQ + SCENARIO: "Cascade on a schema with zero objects".
	 *
	 * The schema is removed and its empty magic table is dropped; deletedCount is 0
	 * and no audit entry is written (there is nothing to audit).
	 */
	public function testCascadeOnSchemaWithZeroObjects(): void {
		$this->stubOneTableWith([]);

		$this->auditTrailMapper->expects($this->never())->method('createAuditTrailEntry');
		$this->schemaMapper->expects($this->once())->method('delete')->with($this->schema);
		$this->magicMapper->expects($this->once())->method('dropTable')->with(self::TABLE_NAME);

		$result = $this->service->cascadeDeleteSchema($this->schema);

		$this->assertSame(0, $result['deletedCount']);
		$this->assertSame([], $result['deletedUuids']);
		$this->assertTrue($result['tableDropped']);

	}//end testCascadeOnSchemaWithZeroObjects()

	/**
	 * REQ + SCENARIO: "Cascade rolls back when object deletion fails".
	 *
	 * Any phase-1 failure rolls the whole transaction back — no commit, and crucially
	 * NO table drop, because the objects and the schema are still there.
	 */
	public function testPhaseOneFailureRollsEverythingBack(): void {
		$this->stubOneTableWith([$this->makeObject('uuid-1')]);

		$this->schemaMapper
			->method('delete')
			->willThrowException(new RuntimeException('schema delete blew up'));

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		// Nothing is reclaimed: the data is still there, so the table must survive.
		$this->magicMapper->expects($this->never())->method('dropTable');

		$this->expectException(RuntimeException::class);

		$this->service->cascadeDeleteSchema($this->schema);

	}//end testPhaseOneFailureRollsEverythingBack()

	/**
	 * REQ + SCENARIO: "Cascade succeeds but the table drop fails".
	 *
	 * Phase 2 is post-commit and best effort. The user's intent is already satisfied,
	 * so the request still succeeds — it reports tableDropped: false and logs a
	 * WARNING. No rollback is attempted (DDL is not rollbackable on MySQL/MariaDB).
	 */
	public function testPhaseTwoDropFailureStillReturnsSuccess(): void {
		$this->stubOneTableWith([$this->makeObject('uuid-1')]);

		$this->magicMapper
			->method('dropTable')
			->willThrowException(new RuntimeException('DROP TABLE denied'));

		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$this->logger->expects($this->atLeastOnce())->method('warning');

		$result = $this->service->cascadeDeleteSchema($this->schema);

		$this->assertSame(1, $result['deletedCount']);
		$this->assertFalse($result['tableDropped'], 'A failed drop must be reported honestly, not hidden.');

	}//end testPhaseTwoDropFailureStillReturnsSuccess()

	/**
	 * REQ (deletion-audit-trail): one audit entry per cascaded object, carrying the
	 * full pre-delete snapshot and the cascade trigger context.
	 *
	 * The entries go through AuditTrailMapper::createAuditTrailEntry(), which is the
	 * hash-chaining insert path (ADR-003) — writing them any other way would break the
	 * chain. They are written BEFORE the rows are removed and BEFORE the table is
	 * dropped, which is what makes the objects reconstructible afterwards; that
	 * ordering is asserted here.
	 */
	public function testEachCascadedObjectProducesItsOwnAuditEntryWithSnapshot(): void {
		$this->stubOneMagicTable();
		$this->magicMapper
			->method('findAllInRegisterSchemaTable')
			->willReturn([$this->makeObject('uuid-1'), $this->makeObject('uuid-2')]);

		$callOrder = [];

		$this->auditTrailMapper
			->expects($this->exactly(2))
			->method('createAuditTrailEntry')
			->willReturnCallback(
				function (ObjectEntity $object, string $action, array $context) use (&$callOrder): AuditTrail {
					$callOrder[] = 'audit:' . $object->getUuid();

					$this->assertSame('schema.cascade_delete', $action);
					$this->assertSame('schema_deletion', $context['triggeredBy']);
					$this->assertSame('cow', $context['cascadeContext']['triggerSchema']);
					$this->assertSame(self::SCHEMA_ID, $context['cascadeContext']['triggerSchemaId']);
					$this->assertSame(self::REGISTER_ID, $context['cascadeContext']['registerId']);

					// The FULL pre-deletion snapshot, not an empty shell.
					$this->assertSame($object->jsonSerialize(), $context['snapshot']);
					$this->assertSame('Bessie ' . $object->getUuid(), $context['snapshot']['name']);

					return new AuditTrail();
				}
			);

		$this->magicMapper
			->method('deleteObjectsBySchema')
			->willReturnCallback(
				function () use (&$callOrder): int {
					$callOrder[] = 'delete-rows';
					return 2;
				}
			);

		$this->magicMapper
			->method('dropTable')
			->willReturnCallback(
				function () use (&$callOrder): void {
					$callOrder[] = 'drop-table';
				}
			);

		$this->service->cascadeDeleteSchema($this->schema);

		$this->assertSame(
			['audit:uuid-1', 'audit:uuid-2', 'delete-rows', 'drop-table'],
			$callOrder,
			'Snapshots must be captured before the rows go, and the rows before the table.'
		);

	}//end testEachCascadedObjectProducesItsOwnAuditEntryWithSnapshot()

	/**
	 * REQ (deletion-audit-trail): "Already soft-deleted objects are also removed by the
	 * cascade" — so the read that feeds the audit MUST include soft-deleted rows, and
	 * must not be narrowed by RBAC/multitenancy, or it would audit fewer objects than
	 * the delete removes.
	 */
	public function testCascadeReadsEveryRowIncludingSoftDeletedOnes(): void {
		$this->stubOneMagicTable();
		$this->magicMapper->method('deleteObjectsBySchema')->willReturn(1);

		$this->magicMapper
			->expects($this->atLeastOnce())
			->method('findAllInRegisterSchemaTable')
			->willReturnCallback(
				function (Register $register, Schema $schema, ?int $limit, ?int $offset, ?array $filters, array $sort = []): array {
					$this->assertTrue($filters['_includeDeleted']);
					$this->assertFalse($filters['_rbac']);
					$this->assertFalse($filters['_multitenancy']);

					return [$this->makeObject('uuid-1')];
				}
			);

		$this->service->cascadeDeleteSchema($this->schema);

	}//end testCascadeReadsEveryRowIncludingSoftDeletedOnes()

	/**
	 * REQ + SCENARIO: "Bulk-delete every object of a schema" — the endpoint behind
	 * POST /api/bulk/{register}/{schema}/delete-objects, which used to be a HTTP 500.
	 *
	 * The schema itself is NOT deleted and no table is dropped.
	 */
	public function testBulkDeleteObjectsBySchemaReturnsCountAndUuids(): void {
		$this->stubOneTableWith([$this->makeObject('uuid-1'), $this->makeObject('uuid-2')]);

		$this->auditTrailMapper->expects($this->exactly(2))->method('createAuditTrailEntry');
		$this->schemaMapper->expects($this->never())->method('delete');
		$this->magicMapper->expects($this->never())->method('dropTable');

		$result = $this->service->deleteObjectsBySchema(
			registerId: self::REGISTER_ID,
			schemaId: self::SCHEMA_ID
		);

		$this->assertSame(2, $result['deleted_count']);
		$this->assertSame(['uuid-1', 'uuid-2'], $result['deleted_uuids']);
		$this->assertSame(self::SCHEMA_ID, $result['schema_id']);

	}//end testBulkDeleteObjectsBySchemaReturnsCountAndUuids()

	/**
	 * REQ + SCENARIO: "Bulk delete on a schema with no objects".
	 */
	public function testBulkDeleteOnSchemaWithNoObjects(): void {
		$this->stubOneTableWith([]);

		$result = $this->service->deleteObjectsBySchema(
			registerId: self::REGISTER_ID,
			schemaId: self::SCHEMA_ID
		);

		$this->assertSame(0, $result['deleted_count']);
		$this->assertSame([], $result['deleted_uuids']);

	}//end testBulkDeleteOnSchemaWithNoObjects()

	/**
	 * A schema with no magic table at all is a no-op, not a crash.
	 */
	public function testBulkDeleteWhenNoMagicTableExists(): void {
		$this->registerMapper->method('find')->willReturn($this->register);
		$this->schemaMapper->method('find')->willReturn($this->schema);
		$this->magicMapper->method('tableExistsForRegisterSchema')->willReturn(false);

		$this->magicMapper->expects($this->never())->method('deleteObjectsBySchema');
		$this->auditTrailMapper->expects($this->never())->method('createAuditTrailEntry');

		$result = $this->service->deleteObjectsBySchema(
			registerId: self::REGISTER_ID,
			schemaId: self::SCHEMA_ID
		);

		$this->assertSame(0, $result['deleted_count']);

	}//end testBulkDeleteWhenNoMagicTableExists()

	/**
	 * TASK 2.5: a plain (zero-object) schema delete reclaims the empty magic table, so
	 * the no-flag path stops leaving orphan tables behind.
	 */
	public function testDropEmptyTablesDropsAnEmptyTable(): void {
		$this->magicMapper->method('getAllRegisterSchemaPairs')->willReturn(
			[
				[
					'registerId' => self::REGISTER_ID,
					'schemaId' => self::SCHEMA_ID,
				],
			]
		);
		$this->registerMapper->method('find')->willReturn($this->register);

		$this->stubRowCount(0);

		$this->magicMapper->expects($this->once())->method('dropTable')->with(self::TABLE_NAME);

		$this->assertTrue($this->service->dropEmptyTablesForSchema($this->schema));

	}//end testDropEmptyTablesDropsAnEmptyTable()

	/**
	 * The emptiness check counts EVERY row, including soft-deleted ones — the
	 * controller's object count excludes them, so a "0 object" schema can still have
	 * tombstones. Dropping that table would destroy real rows with no audit entry, so
	 * the table is kept instead. The cascade is the audited way to remove them.
	 */
	public function testDropEmptyTablesKeepsATableThatStillHasSoftDeletedRows(): void {
		$this->magicMapper->method('getAllRegisterSchemaPairs')->willReturn(
			[
				[
					'registerId' => self::REGISTER_ID,
					'schemaId' => self::SCHEMA_ID,
				],
			]
		);
		$this->registerMapper->method('find')->willReturn($this->register);

		$this->stubRowCount(3);

		$this->magicMapper->expects($this->never())->method('dropTable');
		$this->logger->expects($this->atLeastOnce())->method('warning');

		$this->assertFalse($this->service->dropEmptyTablesForSchema($this->schema));

	}//end testDropEmptyTablesKeepsATableThatStillHasSoftDeletedRows()

	/**
	 * Stub IDBConnection so the magic table exists and holds $rowCount rows.
	 *
	 * @param int $rowCount The number of rows the table reports.
	 *
	 * @return void
	 */
	private function stubRowCount(int $rowCount): void {
		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetchOne')->willReturn($rowCount);

		$function = $this->createMock(\OCP\DB\QueryBuilder\IFunctionBuilder::class);
		$function->method('count')->willReturn(
			$this->createMock(\OCP\DB\QueryBuilder\IQueryFunction::class)
		);

		$queryBuilder = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$queryBuilder->method('func')->willReturn($function);
		$queryBuilder->method('select')->willReturnSelf();
		$queryBuilder->method('from')->willReturnSelf();
		$queryBuilder->method('executeQuery')->willReturn($result);

		$this->db->method('tableExists')->willReturn(true);
		$this->db->method('getQueryBuilder')->willReturn($queryBuilder);

	}//end stubRowCount()

}//end class
