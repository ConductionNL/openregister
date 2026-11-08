<?php

/**
 * Golden test: what SchemaDeletionService deletes, refuses, and the trace it leaves.
 *
 * Pins, on the code as it was before the A21 refactor, the refusals (archival
 * schema before any transaction, a locked row inside the transaction with a
 * rollback), and on success the exact order of transaction, read, audit,
 * delete, schema-delete and drop calls, the audit context, the result shapes,
 * the log lines (without file/line), the cascade count and the empty-table
 * reclaim.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\ArchivalImmutableException;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Service\SchemaDeletionService;
use OCA\OpenRegister\Service\SchemaTeardownOverride;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\SchemaDeletionService
 */
final class SchemaDeletionServiceGoldenTest extends TestCase {

	/**
	 * Every collaborator call, in order.
	 *
	 * @var list<string>
	 */
	private array $trace = [];

	/**
	 * Objects per "registerId_schemaId".
	 *
	 * @var array<string, list<ObjectEntity>>
	 */
	private array $rows = [];

	/**
	 * Register-schema pairs that have a magic table.
	 *
	 * @var list<array{registerId: int, schemaId: int}>
	 */
	private array $pairs = [];

	/**
	 * Registers that resolve, by id.
	 *
	 * @var array<int, Register>
	 */
	private array $registers = [];

	/**
	 * Schemas that resolve, by id.
	 *
	 * @var array<int, Schema>
	 */
	private array $schemas = [];

	/**
	 * Tables whose drop fails.
	 *
	 * @var list<string>
	 */
	private array $undroppable = [];

	/**
	 * Raw row counts per table name (absent: table missing).
	 *
	 * @var array<string, int>
	 */
	private array $rawCounts = [];

	/**
	 * Pairs whose count throws.
	 *
	 * @var list<string>
	 */
	private array $uncountable = [];

	/**
	 * Audit entries written: [action, uuid, context].
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $audits = [];

	/**
	 * The service under test, over recording collaborators.
	 *
	 * @return SchemaDeletionService
	 */
	private function service(): SchemaDeletionService {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_SQLITE);
		foreach (['beginTransaction', 'commit', 'rollBack'] as $op) {
			$db->method($op)->willReturnCallback(function () use ($op) {
				$this->trace[] = $op;
				return null;
			});
		}

		$db->method('tableExists')->willReturnCallback(fn (string $t): bool => isset($this->rawCounts[$t]));
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->countQuery());

		$magic = $this->createMock(MagicMapperInterface::class);
		$magic->method('getAllRegisterSchemaPairs')->willReturnCallback(fn () => $this->pairs);
		$magic->method('tableExistsForRegisterSchema')->willReturnCallback(
			fn (Register $r, Schema $s): bool => isset($this->rows[$r->getId() . '_' . $s->getId()])
		);
		$magic->method('findAllInRegisterSchemaTable')->willReturnCallback(function (Register $register, Schema $schema, $limit, $offset, $filters) {
			$this->trace[] = 'findAll ' . $register->getId() . '_' . $schema->getId() . ' ' . $offset . ' ' . json_encode($filters);
			return ($offset === 0 ? ($this->rows[$register->getId() . '_' . $schema->getId()] ?? []) : []);
		});
		$magic->method('deleteObjectsBySchema')->willReturnCallback(function (Register $register, Schema $schema, bool $hardDelete): int {
			$key = $register->getId() . '_' . $schema->getId();
			$this->trace[] = 'deleteObjects ' . $key . ' ' . ($hardDelete ? 'hard' : 'soft');
			return count($this->rows[$key] ?? []);
		});
		$magic->method('countObjectsInRegisterSchemaTable')->willReturnCallback(function (array $query, Register $register, Schema $schema): int {
			$key = $register->getId() . '_' . $schema->getId();
			$this->trace[] = 'count ' . $key . ' ' . json_encode($query);
			if (in_array($key, $this->uncountable, true) === true) {
				throw new RuntimeException('unreadable');
			}

			return count($this->rows[$key] ?? []);
		});
		$magic->method('dropTable')->willReturnCallback(function (string $tableName) {
			$this->trace[] = 'drop ' . $tableName;
			if (in_array($tableName, $this->undroppable, true) === true) {
				throw new RuntimeException('ddl failed');
			}

			return null;
		});

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturnCallback(function ($id) {
			if (isset($this->registers[(int) $id]) === false) {
				throw new RuntimeException('no register ' . $id);
			}

			return $this->registers[(int) $id];
		});
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(function ($id) {
			if (isset($this->schemas[(int) $id]) === false) {
				throw new RuntimeException('no schema ' . $id);
			}

			return $this->schemas[(int) $id];
		});
		$schemas->method('delete')->willReturnCallback(function ($entity) {
			$this->trace[] = 'deleteSchema ' . $entity->getId();
			return $entity;
		});

		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$audit->method('createAuditTrailEntry')->willReturnCallback(function ($object, $action, $context = []) {
			$this->trace[] = 'audit ' . $action . ' ' . $object->getUuid();
			unset($context['snapshot']);
			$this->audits[] = [$action, $object->getUuid(), $context];
			return new AuditTrail();
		});

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message, array $context = []) use ($level): void {
				unset($context['file'], $context['line']);
				$this->trace[] = $level . ' ' . $message . ' ' . json_encode($context);
			});
		}

		return new SchemaDeletionService($db, $magic, $registers, $schemas, $audit, $logger);
	}//end service()

	/**
	 * A query builder whose count query answers the raw count of the table it reads.
	 *
	 * @return IQueryBuilder
	 */
	private function countQuery(): IQueryBuilder {
		$table  = null;
		$qb     = $this->createMock(IQueryBuilder::class);
		$func   = $this->createMock(IFunctionBuilder::class);
		$qb->method('func')->willReturn($func);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnCallback(function (string $name) use (&$table, $qb) {
			$table = $name;
			return $qb;
		});
		$qb->method('executeQuery')->willReturnCallback(function () use (&$table) {
			$result = $this->createMock(IResult::class);
			$result->method('fetchOne')->willReturn($this->rawCounts[$table] ?? 0);
			return $result;
		});
		return $qb;
	}//end countQuery()

	/**
	 * Give an entity an id.
	 *
	 * @param object $entity The entity.
	 * @param int    $id     The id.
	 *
	 * @return mixed
	 */
	private function withId(object $entity, int $id): mixed {
		(new ReflectionProperty($entity, 'id'))->setValue($entity, $id);
		return $entity;
	}//end withId()

	/**
	 * Set up register 7 holding schemas 42 and 43, with rows in 7_42.
	 *
	 * @param bool $archival Whether schema 42 is archival.
	 *
	 * @return array{Register, Schema}
	 */
	private function world(bool $archival = false): array {
		$register = $this->withId(new Register(), 7);
		$register->setSchemas([42, 43, 99]);
		$schema = $this->withId(new Schema(), 42);
		$schema->setSlug('cow');
		$schema->setUuid('schema-uuid');
		if ($archival === true) {
			$schema->setConfiguration(['x-openregister-archival' => []]);
		}

		$other = $this->withId(new Schema(), 43);
		$other->setSlug('pig');
		$this->registers[7] = $register;
		$this->schemas = [42 => $schema, 43 => $other];
		$this->rows['7_42'] = [$this->object('a'), $this->object('b')];
		return [$register, $schema];
	}//end world()

	/**
	 * An object row.
	 *
	 * @param string     $uuid   The uuid.
	 * @param array|null $locked The lock.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, ?array $locked = null): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('7');
		$object->setSchema('42');
		$object->setObject(['n' => $uuid]);
		if ($locked !== null) {
			$object->setLocked($locked);
		}

		return $object;
	}//end object()

	/**
	 * The exception a call throws.
	 *
	 * @param callable $call The call.
	 *
	 * @return Throwable
	 */
	private function thrown(callable $call): Throwable {
		try {
			$call();
		} catch (Throwable $e) {
			return $e;
		}

		$this->fail('was allowed');
	}//end thrown()

	/**
	 * Deleting a schema's objects reads, audits, deletes and commits, in this order.
	 *
	 * @return void
	 */
	public function testDeletingASchemasObjects(): void {
		$this->world();
		$filters = '{"_includeDeleted":true,"_rbac":false,"_multitenancy":false}';
		$result = $this->service()->deleteObjectsBySchema(7, 42);
		$this->assertSame(['deleted_count' => 2, 'deleted_uuids' => ['a', 'b'], 'schema_id' => 42], $result);
		$this->assertSame(
			[
				'beginTransaction',
				'findAll 7_42 0 ' . $filters,
				'findAll 7_42 0 ' . $filters,
				'audit schema.cascade_delete a',
				'audit schema.cascade_delete b',
				'deleteObjects 7_42 soft',
				'commit',
			],
			$this->trace
		);
		$this->assertSame(
			['triggeredBy' => 'bulk_schema_delete', 'hardDelete' => false, 'cascadeContext' => ['triggerSchema' => 'cow', 'triggerSchemaId' => 42, 'registerId' => 7]],
			$this->audits[0][2]
		);
	}//end testDeletingASchemasObjects()

	/**
	 * An archival schema is refused before any transaction starts.
	 *
	 * @return void
	 */
	public function testAnArchivalSchemaIsRefusedBeforeAnyTransaction(): void {
		$this->world(archival: true);
		$e = $this->thrown(fn () => $this->service()->deleteObjectsBySchema(7, 42, true));
		$this->assertInstanceOf(ArchivalImmutableException::class, $e);
		$this->assertSame(
			['warning [SchemaDeletionService] Refused a schema-wide delete on an archival schema {"schemaId":42,"schemaSlug":"cow","operation":"delete"}'],
			$this->trace
		);

		$this->trace = [];
		$e = $this->thrown(fn () => $this->service()->deleteObjectsByRegister($this->registers[7]));
		$this->assertInstanceOf(ArchivalImmutableException::class, $e);
		$this->assertNotContains('beginTransaction', $this->trace);

		$this->trace = [];
		$e = $this->thrown(fn () => $this->service()->cascadeDeleteSchema($this->schemas[42]));
		$this->assertInstanceOf(ArchivalImmutableException::class, $e);
		$this->assertNotContains('beginTransaction', $this->trace);
	}//end testAnArchivalSchemaIsRefusedBeforeAnyTransaction()

	/**
	 * A locked row refuses the whole-table delete inside the transaction, which rolls back.
	 *
	 * @return void
	 */
	public function testALockedRowRollsTheDeleteBack(): void {
		$this->world();
		$this->rows['7_42'][] = $this->object('c', ['user' => 'bob']);
		$e = $this->thrown(fn () => $this->service()->deleteObjectsBySchema(7, 42, true, 'manual'));
		$this->assertInstanceOf(LockedException::class, $e);
		$this->assertSame('beginTransaction', $this->trace[0]);
		$this->assertSame('rollBack', $this->trace[2]);
		$this->assertStringStartsWith('error [SchemaDeletionService] Whole-table delete rolled back {"registerId":7,', $this->trace[3]);
		$this->assertCount(4, $this->trace);
		$this->assertSame([], $this->audits);
	}//end testALockedRowRollsTheDeleteBack()

	/**
	 * A register delete skips an unresolvable listed schema and deletes the rest in one transaction.
	 *
	 * @return void
	 */
	public function testDeletingARegistersObjects(): void {
		[$register] = $this->world();
		$this->rows['7_43'] = [$this->object('p')];
		$result = $this->service()->deleteObjectsByRegister($register, true);
		$this->assertSame(['deleted_count' => 3, 'deleted_uuids' => ['a', 'b', 'p'], 'register_id' => 7], $result);
		$this->assertStringStartsWith('warning [SchemaDeletionService] Register lists a schema that does not resolve; skipped {"registerId":7,"schemaId":"99","error":"no schema 99"}', $this->trace[0]);
		$this->assertSame(
			['beginTransaction', 'deleteObjects 7_42 hard', 'deleteObjects 7_43 hard', 'commit'],
			array_values(array_filter($this->trace, static fn (string $t): bool => in_array(explode(' ', $t)[0], ['beginTransaction', 'deleteObjects', 'commit'], true)))
		);
		$this->assertSame('bulk_register_delete', $this->audits[2][2]['triggeredBy']);
		$this->assertCount(2, $this->service()->resolveSchemasOfRegister($register));
	}//end testDeletingARegistersObjects()

	/**
	 * A cascade delete deletes the rows and the schema in one transaction, then drops every table.
	 *
	 * @return void
	 */
	public function testACascadeDelete(): void {
		[, $schema] = $this->world();
		$this->pairs = [['registerId' => 7, 'schemaId' => 42], ['registerId' => 8, 'schemaId' => 42], ['registerId' => 7, 'schemaId' => 43]];
		$this->undroppable = ['openregister_table_8_42'];
		$result = $this->service()->cascadeDeleteSchema($schema);
		$this->assertSame(['deletedCount' => 2, 'deletedUuids' => ['a', 'b'], 'tableDropped' => false], $result);
		$trace = array_values(array_filter($this->trace, static fn (string $t): bool => str_starts_with($t, 'findAll') === false));
		$this->assertSame(
			[
				'warning [SchemaDeletionService] Magic table has no register entity {"registerId":8,"schemaId":42,"error":"no register 8"}',
				'beginTransaction',
				'warning [SchemaDeletionService] Magic table has no register entity {"registerId":8,"schemaId":42,"error":"no register 8"}',
				'audit schema.cascade_delete a',
				'audit schema.cascade_delete b',
				'deleteObjects 7_42 hard',
				'deleteSchema 42',
				'commit',
				'drop openregister_table_7_42',
				'drop openregister_table_8_42',
				'warning [SchemaDeletionService] Could not drop magic table after schema deletion {"schemaId":42,"tableName":"openregister_table_8_42","error":"ddl failed"}',
				'info [SchemaDeletionService] Cascade deleted schema {"schemaId":42,"schemaSlug":"cow","deletedCount":2,"tableDropped":false}',
			],
			$trace
		);
		$this->assertSame('schema_deletion', $this->audits[0][2]['triggeredBy']);
	}//end testACascadeDelete()

	/**
	 * A kept row stops a cascade unless lifted; a failure in the transaction rolls back and rethrows.
	 *
	 * @return void
	 */
	public function testACascadeWithAKeptRow(): void {
		[, $schema] = $this->world();
		$this->pairs = [['registerId' => 7, 'schemaId' => 42]];
		$this->rows['7_42'][] = $this->object('c', ['user' => 'bob']);
		$e = $this->thrown(fn () => $this->service()->cascadeDeleteSchema($schema));
		$this->assertInstanceOf(LockedException::class, $e);
		$this->assertContains('rollBack', $this->trace);
		$this->assertNotContains('deleteSchema 42', $this->trace);
		$this->assertStringStartsWith('error [SchemaDeletionService] Cascade delete rolled back {"schemaId":42,', end($this->trace));

		$this->trace = [];
		$result = $this->service()->cascadeDeleteSchema($schema, new SchemaTeardownOverride(keptRows: true));
		$this->assertSame(['deletedCount' => 3, 'deletedUuids' => ['a', 'b', 'c'], 'tableDropped' => true], $result);
		$this->assertSame('audit schema.teardown_forced schema-uuid', $this->trace[array_search('beginTransaction', $this->trace, true) + 2]);
	}//end testACascadeWithAKeptRow()

	/**
	 * The cascade count counts rows, and counts a stale or unreadable table as one.
	 *
	 * @return void
	 */
	public function testTheCascadeCountErrsTowardsKeepingData(): void {
		[, $schema] = $this->world();
		$this->registers[9] = $this->withId(new Register(), 9);
		$this->rows['9_42'] = [];
		$this->pairs = [['registerId' => 7, 'schemaId' => 42], ['registerId' => 8, 'schemaId' => 42], ['registerId' => 9, 'schemaId' => 42]];
		$this->assertSame(3, $this->service()->countObjectsCascadeWouldDelete($schema));
		$this->assertContains('count 7_42 {"_rbac":false,"_multitenancy":false,"_includeDeleted":true}', $this->trace);
		$this->uncountable = ['9_42'];
		$this->assertSame(4, $this->service()->countObjectsCascadeWouldDelete($schema));
	}//end testTheCascadeCountErrsTowardsKeepingData()

	/**
	 * Reclaiming empty tables keeps a non-empty one and reports false; all empty and dropped is true.
	 *
	 * @return void
	 */
	public function testReclaimingEmptyTables(): void {
		[, $schema] = $this->world();
		$this->pairs = [['registerId' => 7, 'schemaId' => 42], ['registerId' => 8, 'schemaId' => 42]];
		$this->rawCounts = ['openregister_table_7_42' => 3, 'openregister_table_8_42' => 0];
		$this->assertFalse($this->service()->dropEmptyTablesForSchema($schema));
		$this->assertContains('warning [SchemaDeletionService] Keeping non-empty magic table of a deleted schema {"schemaId":42,"tableName":"openregister_table_7_42","rowCount":3}', $this->trace);
		$this->assertContains('drop openregister_table_8_42', $this->trace);
		$this->assertNotContains('drop openregister_table_7_42', $this->trace);

		$this->rawCounts = ['openregister_table_7_42' => 0];
		$this->assertTrue($this->service()->dropEmptyTablesForSchema($schema));
		$this->undroppable = ['openregister_table_8_42'];
		$this->assertFalse($this->service()->dropEmptyTablesForSchema($schema));
	}//end testReclaimingEmptyTables()
}//end class
