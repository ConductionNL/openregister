<?php

/**
 * A whole-table delete holds its rows from the check to the commit.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Service\SchemaDeletionService;
use OCA\OpenRegister\Service\SchemaDeletionStores;
use OCA\OpenRegister\Service\SchemaTeardownGuard;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\SchemaDeletionService
 * @covers \OCA\OpenRegister\Service\WholeTableRows
 * @covers \OCA\OpenRegister\Service\SchemaTeardownGuard
 */
final class WholeTableDeleteHoldsRowsTest extends TestCase {

	private const REGISTER_ID = 7;

	private const TABLE = 'openregister_table_7_42';

	/**
	 * What happened, in order.
	 *
	 * @var array<int, string>
	 */
	private array $steps = [];

	/**
	 * The rows of the magic tables, by schema id.
	 *
	 * @var array<int, array<int, ObjectEntity>>
	 */
	private array $rows = [];

	/**
	 * The conditions the hold query was built with.
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $conditions = [];

	/**
	 * The hard delete flag of every mapper delete.
	 *
	 * @var array<int, bool>
	 */
	private array $hardDeletes = [];

	/**
	 * Runs when the hold is granted.
	 *
	 * @var callable|null
	 */
	private $onHold = null;

	/**
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection $db;

	/**
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper $mapper;

	/**
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper $audit;

	private RegisterMapper&MockObject $registers;

	private SchemaMapper&MockObject $schemas;

	private SchemaDeletionService $service;

	/**
	 * Wire the real service to doubles that write down what they are asked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$this->mapper = $this->createMock(MagicMapper::class);
		$this->audit = $this->createMock(AuditTrailMapper::class);
		$this->registers = $this->createMock(RegisterMapper::class);
		$this->schemas = $this->createMock(SchemaMapper::class);

		$this->db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());
		$this->db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_MARIADB);
		$this->db->method('beginTransaction')->willReturnCallback(fn () => $this->steps[] = 'begin');
		$this->db->method('commit')->willReturnCallback(fn () => $this->steps[] = 'commit');
		$this->db->method('rollBack')->willReturnCallback(fn () => $this->steps[] = 'rollback');
		$this->db->method('executeStatement')->willReturnCallback(
			function (string $sql): int {
				$this->steps[] = 'statement ' . $sql;
				return 0;
			}
		);
		$this->db->method('executeQuery')->willReturnCallback(
			function (string $sql): IResult {
				$this->steps[] = 'hold ' . $sql;
				if ($this->onHold !== null) {
					($this->onHold)();
				}

				return $this->createMock(IResult::class);
			}
		);

		$this->mapper->method('getTableNameForRegisterSchema')->willReturn(self::TABLE);
		$this->mapper->method('tableExistsForRegisterSchema')->willReturn(true);
		$this->mapper->method('findAllInRegisterSchemaTable')->willReturnCallback(
			function (RegisterSchema $table, ListQuery $listing): array {
				[$register, $schema] = [$table->register, $table->schema];
				$this->steps[] = 'read ' . $schema->getId();

				return $this->rows[(int)$schema->getId()] ?? [];
			}
		);
		$this->mapper->method('deleteObjectsBySchema')->willReturnCallback(
			function (Register $register, Schema $schema, bool $hardDelete): int {
				$this->steps[] = 'delete ' . $schema->getId();
				$this->hardDeletes[] = $hardDelete;

				return count($this->rows[(int)$schema->getId()]);
			}
		);
		$this->audit->method('createAuditTrailEntry')->willReturnCallback(
			function (): AuditTrail {
				$this->steps[] = 'audit';

				return new AuditTrail();
			}
		);

		$this->service = new SchemaDeletionService(
			$this->db,
			new SchemaDeletionStores(new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->registers, schemas: $this->schemas, tables: $this->mapper), $this->audit),
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * The delete by schema takes every step inside one transaction, the hold before the check.
	 *
	 * @return void
	 */
	public function testTheDeleteBySchemaHoldsTheRowsThenChecksAuditsAndDeletes(): void {
		$this->givenTable(schemaId: 42);

		$result = $this->deleteBySchema();

		self::assertSame(
			['begin', 'hold SELECT uuid FOR UPDATE', 'read 42', 'read 42', 'audit', 'delete 42', 'commit'],
			$this->steps
		);
		self::assertSame(['a-1'], $result['deleted_uuids']);
		self::assertSame([false], $this->hardDeletes);
		self::assertSame([['_register', self::REGISTER_ID], ['_schema', 42]], $this->conditions);
	}//end testTheDeleteBySchemaHoldsTheRowsThenChecksAuditsAndDeletes()

	/**
	 * A freeze committed while the hold waits is seen by the check.
	 *
	 * @return void
	 */
	public function testARowFrozenWhileTheHoldWaitsRefusesTheDelete(): void {
		$this->givenTable(schemaId: 42);
		$this->onHold = function (): void {
			$this->rows[42][0]->setFrozen(['by' => 'alice', 'at' => '2026-01-01T00:00:00+00:00', 'reason' => 'kept']);
		};

		try {
			$this->deleteBySchema();
			self::fail('The frozen row was deleted.');
		} catch (ObjectStateWriteException) {
			self::assertSame(['begin', 'hold SELECT uuid FOR UPDATE', 'read 42', 'rollback'], $this->steps);
		}
	}//end testARowFrozenWhileTheHoldWaitsRefusesTheDelete()

	/**
	 * A lock committed while the hold waits is seen by the check.
	 *
	 * @return void
	 */
	public function testARowLockedWhileTheHoldWaitsRefusesTheDelete(): void {
		$this->givenTable(schemaId: 42);
		$this->onHold = function (): void {
			$this->rows[42][0]->setLocked(['user' => 'alice', 'expiration' => '2999-01-01T00:00:00+00:00']);
		};

		$this->expectException(LockedException::class);

		$this->deleteBySchema();
	}//end testARowLockedWhileTheHoldWaitsRefusesTheDelete()

	/**
	 * A failing delete takes the audit entries back with it.
	 *
	 * @return void
	 */
	public function testAFailingDeleteRollsTheAuditBack(): void {
		$this->givenTable(schemaId: 42);
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('tableExistsForRegisterSchema')->willReturn(true);
		$mapper->method('getTableNameForRegisterSchema')->willReturn(self::TABLE);
		$mapper->method('findAllInRegisterSchemaTable')->willReturn($this->rows[42]);
		$mapper->method('deleteObjectsBySchema')->willThrowException(new RuntimeException('gone away'));
		$service = new SchemaDeletionService(
			$this->db,
			new SchemaDeletionStores(new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->registers, schemas: $this->schemas, tables: $mapper), $this->audit),
			$this->createMock(LoggerInterface::class)
		);

		try {
			$service->deleteObjectsBySchema(registerId: self::REGISTER_ID, schemaId: 42);
			self::fail('The failure was swallowed.');
		} catch (RuntimeException $failure) {
			self::assertSame('gone away', $failure->getMessage());
			self::assertSame(['begin', 'hold SELECT uuid FOR UPDATE', 'audit', 'rollback'], $this->steps);
		}
	}//end testAFailingDeleteRollsTheAuditBack()

	/**
	 * The delete by register holds and checks every schema before it deletes any.
	 *
	 * @return void
	 */
	public function testTheDeleteByRegisterHoldsEveryTableBeforeItDeletes(): void {
		$first = $this->givenTable(schemaId: 42);
		$second = $this->givenTable(schemaId: 43);
		$register = $this->register();
		$register->setSchemas([42, 43]);
		$this->schemas->method('find')->willReturnCallback(
			static fn (int|string $id): Schema => ((int)$id === 42) ? $first : $second
		);

		$this->service->deleteObjectsByRegister(register: $register);

		self::assertSame(
			[
				'begin',
				'hold SELECT uuid FOR UPDATE',
				'read 42',
				'hold SELECT uuid FOR UPDATE',
				'read 43',
				'read 42',
				'audit',
				'delete 42',
				'read 43',
				'audit',
				'delete 43',
				'commit',
			],
			$this->steps
		);
	}//end testTheDeleteByRegisterHoldsEveryTableBeforeItDeletes()

	/**
	 * The teardown guard of the cascade holds the rows of a table it checks.
	 *
	 * @return void
	 */
	public function testTheTeardownGuardHoldsTheRowsItChecks(): void {
		$schema = $this->givenTable(schemaId: 42);
		$guard = new SchemaTeardownGuard(mapper: $this->mapper, db: $this->db, audit: $this->audit);

		$guard->assertNoneKept(
			tables: [['registerId' => self::REGISTER_ID, 'register' => $this->register(), 'tableName' => self::TABLE]],
			schema: $schema
		);

		self::assertSame(['hold SELECT uuid FOR UPDATE', 'read 42'], $this->steps);
	}//end testTheTeardownGuardHoldsTheRowsItChecks()

	/**
	 * SQLite has no FOR UPDATE: the check runs without a hold.
	 *
	 * @return void
	 */
	public function testSqliteChecksWithoutAHold(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_SQLITE);
		$db->expects(self::never())->method('executeQuery');
		$schema = $this->givenTable(schemaId: 42);

		$guard = new SchemaTeardownGuard(mapper: $this->mapper, db: $db, audit: $this->audit);
		$guard->rows->assertNoneKept(register: $this->register(), schema: $schema);

		self::assertSame(['read 42'], $this->steps);
	}//end testSqliteChecksWithoutAHold()

	/**
	 * On PostgreSQL the hold waits at most the lock timeout, set for this transaction only, before it asks for the rows.
	 *
	 * @return void
	 */
	public function testPostgresBoundsTheWaitForTheRowsItHolds(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_POSTGRES);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql): int {
				$this->steps[] = 'statement ' . $sql;
				return 0;
			}
		);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql): IResult {
				$this->steps[] = 'hold ' . $sql;
				return $this->createMock(IResult::class);
			}
		);
		$schema = $this->givenTable(schemaId: 42);

		$guard = new SchemaTeardownGuard(mapper: $this->mapper, db: $db, audit: $this->audit);
		$guard->rows->assertNoneKept(register: $this->register(), schema: $schema);

		self::assertSame(
			["statement SET LOCAL lock_timeout = '50s'", 'hold SELECT uuid FOR UPDATE', 'read 42'],
			$this->steps
		);
	}//end testPostgresBoundsTheWaitForTheRowsItHolds()

	/**
	 * Run the delete by schema.
	 *
	 * @return array The result.
	 */
	private function deleteBySchema(): array {
		$this->registers->method('find')->willReturn($this->register());
		$this->schemas->method('find')->willReturn($this->schema(id: 42));

		return $this->service->deleteObjectsBySchema(registerId: self::REGISTER_ID, schemaId: 42);
	}//end deleteBySchema()

	/**
	 * A table with one row.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return Schema The schema of the table.
	 */
	private function givenTable(int $schemaId): Schema {
		$object = new ObjectEntity();
		$object->setUuid('a-' . ($schemaId - 41));
		$object->setRegister((string)self::REGISTER_ID);
		$object->setSchema((string)$schemaId);
		$this->rows[$schemaId] = [$object];

		return $this->schema(id: $schemaId);
	}//end givenTable()

	/**
	 * The register.
	 *
	 * @return Register The register.
	 */
	private function register(): Register {
		return $this->withId(entity: new Register(), id: self::REGISTER_ID);
	}//end register()

	/**
	 * A schema.
	 *
	 * @param int $id The id.
	 *
	 * @return Schema The schema.
	 */
	private function schema(int $id): Schema {
		$schema = $this->withId(entity: new Schema(), id: $id);
		$schema->setSlug('schema-' . $id);

		return $schema;
	}//end schema()

	/**
	 * Give an entity its id.
	 *
	 * @param object $entity The entity.
	 * @param int    $id     The id.
	 *
	 * @return mixed The entity.
	 */
	private function withId(object $entity, int $id): mixed {
		(new ReflectionProperty($entity, 'id'))->setValue($entity, $id);

		return $entity;
	}//end withId()

	/**
	 * A query builder that writes down its conditions and answers one fixed SELECT.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function queryBuilder(): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(
			function (string $column, mixed $value): string {
				$this->conditions[] = [$column, $value];

				return $column;
			}
		);

		$query = $this->createMock(IQueryBuilder::class);
		$query->method('expr')->willReturn($expr);
		$query->method('select')->willReturnSelf();
		$query->method('from')->willReturnCallback(
			function (string $table) use ($query): IQueryBuilder {
				self::assertSame(self::TABLE, $table);

				return $query;
			}
		);
		$query->method('where')->willReturnSelf();
		$query->method('andWhere')->willReturnSelf();
		$query->method('createNamedParameter')->willReturnArgument(0);
		$query->method('getSQL')->willReturn('SELECT uuid');
		$query->method('getParameters')->willReturn([]);
		$query->method('getParameterTypes')->willReturn([]);

		return $query;
	}//end queryBuilder()
}//end class
