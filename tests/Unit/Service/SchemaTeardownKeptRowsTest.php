<?php

/**
 * A schema teardown and frozen, archived or locked rows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
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

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\SchemaDeletionService;
use OCA\OpenRegister\Service\SchemaDeletionStores;
use OCA\OpenRegister\Service\SchemaTeardownOverride;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * The cascade reads its rows first and refuses, unless the operator forces it.
 *
 * @covers \OCA\OpenRegister\Service\SchemaDeletionService
 * @covers \OCA\OpenRegister\Service\SchemaTeardownGuard
 * @covers \OCA\OpenRegister\Service\SchemaTeardownOverride
 * @covers \OCA\OpenRegister\Service\WholeTableRows
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Exception\LockedException
 * @uses   \OCA\OpenRegister\Exception\ObjectStateWriteException
 * @uses   \OCA\OpenRegister\Exception\ValidationException
 * @uses   \OCA\OpenRegister\Service\Object\FileWriteGuard
 */
class SchemaTeardownKeptRowsTest extends TestCase {

	private const REGISTER_ID = 7;

	private const SCHEMA_ID = 42;

	private const FREEZE = ['by' => 'alice', 'at' => '2026-01-01T00:00:00+00:00', 'reason' => 'kept'];

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

	private SchemaDeletionService $service;

	private Schema $schema;

	private ObjectEntity $plainRow;

	private ObjectEntity $otherRow;

	/**
	 * Wire the real service up with mocked collaborators and one table of two rows.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);

		$this->db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_SQLITE);
		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);

		$this->service = new SchemaDeletionService(
			$this->db,
			new SchemaDeletionStores(new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->registerMapper, schemas: $this->schemaMapper, tables: $this->magicMapper), $this->auditTrailMapper),
			$this->createMock(LoggerInterface::class)
		);

		$this->schema = $this->withId(new Schema(), self::SCHEMA_ID);
		$this->schema->setUuid('5d3b5a54-0d2c-4a16-9a31-4a4a0a6c7a11');
		$this->schema->setSlug('held-case');

		$this->plainRow = $this->row('row-1');
		$this->otherRow = $this->row('row-2');

		$this->magicMapper->method('getAllRegisterSchemaPairs')->willReturn(
			[['registerId' => self::REGISTER_ID, 'schemaId' => self::SCHEMA_ID]]
		);
		$this->magicMapper->method('tableExistsForRegisterSchema')->willReturn(true);
		$this->magicMapper->method('findAllInRegisterSchemaTable')->willReturnCallback(
			fn (): array => [$this->plainRow, $this->otherRow]
		);
		$this->magicMapper->method('deleteObjectsBySchema')->willReturn(2);

	}//end setUp()

	/**
	 * Inject an id into an entity (Entity::$id is protected).
	 *
	 * @param object $entity The entity.
	 * @param int    $id     The id to inject.
	 *
	 * @return mixed The same entity.
	 */
	private function withId(object $entity, int $id): mixed {
		$property = (new ReflectionClass($entity))->getProperty('id');
		$property->setAccessible(true);
		$property->setValue($entity, $id);

		return $entity;
	}//end withId()

	/**
	 * One row of the table.
	 *
	 * @param string $uuid The row's uuid.
	 *
	 * @return ObjectEntity The row.
	 */
	private function row(string $uuid): ObjectEntity {
		$row = new ObjectEntity();
		$row->setUuid($uuid);
		$row->setRegister((string)self::REGISTER_ID);
		$row->setSchema((string)self::SCHEMA_ID);
		$row->setObject(['name' => $uuid]);

		return $row;
	}//end row()

	/**
	 * The register resolves, so the table's rows can be read.
	 *
	 * @return void
	 */
	private function givenTheRegisterExists(): void {
		$this->registerMapper->method('find')->willReturn($this->withId(new Register(), self::REGISTER_ID));
	}//end givenTheRegisterExists()

	/**
	 * The register is gone and its table holds the given number of rows.
	 *
	 * @param int $rows The rows the table holds.
	 *
	 * @return void
	 */
	private function givenAStaleTable(int $rows): void {
		$this->registerMapper->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->db->method('tableExists')->willReturn(true);

		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn($rows);

		$builder = $this->createMock(IQueryBuilder::class);
		$builder->method('func')->willReturn($this->createMock(IFunctionBuilder::class));
		$builder->method('select')->willReturnSelf();
		$builder->method('from')->willReturnSelf();
		$builder->method('executeQuery')->willReturn($result);

		$this->db->method('getQueryBuilder')->willReturn($builder);
	}//end givenAStaleTable()

	/**
	 * Nothing at all may have happened by the end of this test.
	 *
	 * @return void
	 */
	private function expectNothingTouched(): void {
		$this->db->expects($this->never())->method('commit');
		$this->magicMapper->expects($this->never())->method('deleteObjectsBySchema');
		$this->magicMapper->expects($this->never())->method('dropTable');
		$this->schemaMapper->expects($this->never())->method('delete');
		$this->auditTrailMapper->expects($this->never())->method('createAuditTrailEntry');
	}//end expectNothingTouched()

	/**
	 * Record the audit actions written, in order.
	 *
	 * @return \ArrayObject<int, array<string, mixed>> Filled as entries are written.
	 */
	private function recordAuditEntries(): \ArrayObject {
		$entries = new \ArrayObject();
		$this->auditTrailMapper->method('createAuditTrailEntry')->willReturnCallback(
			function (ObjectEntity $object, string $action, array $context, ?string $actorId = null, ?string $actorName = null) use ($entries) {
				$entries->append(
					['object' => $object, 'action' => $action, 'context' => $context, 'actor' => $actorId, 'name' => $actorName]
				);

				return new \OCA\OpenRegister\Db\AuditTrail();
			}
		);

		return $entries;
	}//end recordAuditEntries()

	/**
	 * The forced entries among the recorded ones.
	 *
	 * @param \ArrayObject<int, array<string, mixed>> $entries The recorded entries.
	 *
	 * @return array<int, array<string, mixed>> The forced entries.
	 */
	private function forcedEntries(\ArrayObject $entries): array {
		return array_values(
			array_filter(
				$entries->getArrayCopy(),
				static fn (array $entry): bool => $entry['action'] === SchemaDeletionService::ACTION_FORCED_TEARDOWN
			)
		);
	}//end forcedEntries()

	/**
	 * A row frozen while the teardown opens its transaction still refuses: the check reads inside it.
	 *
	 * @return void
	 */
	public function testARowFrozenAsTheTransactionOpensRefusesTheTeardown(): void {
		$this->givenTheRegisterExists();
		$this->db->method('beginTransaction')->willReturnCallback(
			function (): void {
				$this->otherRow->setFrozen(self::FREEZE);
			}
		);
		$this->db->expects($this->once())->method('rollBack');
		$this->expectNothingTouched();

		$this->expectException(ObjectStateWriteException::class);
		$this->service->cascadeDeleteSchema(schema: $this->schema);

	}//end testARowFrozenAsTheTransactionOpensRefusesTheTeardown()

	/**
	 * A frozen row stops the teardown before anything changes.
	 *
	 * @return void
	 */
	public function testAFrozenRowRefusesTheTeardown(): void {
		$this->givenTheRegisterExists();
		$this->otherRow->setFrozen(self::FREEZE);
		$this->expectNothingTouched();

		try {
			$this->service->cascadeDeleteSchema(schema: $this->schema);
			$this->fail('The teardown went through');
		} catch (ObjectStateWriteException $e) {
			$this->assertSame('frozen', $e->getState());
		}

	}//end testAFrozenRowRefusesTheTeardown()

	/**
	 * An archived row stops the teardown.
	 *
	 * @return void
	 */
	public function testAnArchivedRowRefusesTheTeardown(): void {
		$this->givenTheRegisterExists();
		$this->plainRow->setArchived(self::FREEZE);
		$this->expectNothingTouched();

		try {
			$this->service->cascadeDeleteSchema(schema: $this->schema);
			$this->fail('The teardown went through');
		} catch (ObjectStateWriteException $e) {
			$this->assertSame('archived', $e->getState());
		}

	}//end testAnArchivedRowRefusesTheTeardown()

	/**
	 * A locked row stops the teardown.
	 *
	 * @return void
	 */
	public function testALockedRowRefusesTheTeardown(): void {
		$this->givenTheRegisterExists();
		$this->otherRow->setLocked(['kind' => ObjectEntity::LOCK_KIND_USER, 'user' => 'bob']);
		$this->expectNothingTouched();

		$this->expectException(LockedException::class);

		$this->service->cascadeDeleteSchema(schema: $this->schema);

	}//end testALockedRowRefusesTheTeardown()

	/**
	 * The archival override says nothing about kept rows.
	 *
	 * @return void
	 */
	public function testTheArchivalOverrideDoesNotLiftKeptRows(): void {
		$this->givenTheRegisterExists();
		$this->otherRow->setFrozen(self::FREEZE);
		$this->expectNothingTouched();

		$this->expectException(ObjectStateWriteException::class);

		$this->service->cascadeDeleteSchema(
			schema: $this->schema,
			override: new SchemaTeardownOverride(archivalSchema: true)
		);

	}//end testTheArchivalOverrideDoesNotLiftKeptRows()

	/**
	 * Ordinary rows go as before, with no forced entry.
	 *
	 * @return void
	 */
	public function testOrdinaryRowsAreTornDownWithoutAForcedEntry(): void {
		$this->givenTheRegisterExists();
		$entries = $this->recordAuditEntries();

		$result = $this->service->cascadeDeleteSchema(schema: $this->schema);

		$this->assertSame(2, $result['deletedCount']);
		$this->assertCount(2, $entries);
		$this->assertSame([], $this->forcedEntries($entries));

	}//end testOrdinaryRowsAreTornDownWithoutAForcedEntry()

	/**
	 * The force flag with nothing to lift leaves no forced entry.
	 *
	 * @return void
	 */
	public function testAForceThatLiftsNothingLeavesNoForcedEntry(): void {
		$this->givenTheRegisterExists();
		$entries = $this->recordAuditEntries();

		$this->service->cascadeDeleteSchema(
			schema: $this->schema,
			override: new SchemaTeardownOverride(keptRows: true)
		);

		$this->assertSame([], $this->forcedEntries($entries));

	}//end testAForceThatLiftsNothingLeavesNoForcedEntry()

	/**
	 * A forced teardown writes one entry about the schema, inside the transaction.
	 *
	 * @return void
	 */
	public function testAForcedTeardownWritesOneAuditEntryInsideTheTransaction(): void {
		$this->givenTheRegisterExists();
		$this->otherRow->setFrozen(self::FREEZE);
		$this->plainRow->setLocked(['kind' => ObjectEntity::LOCK_KIND_USER, 'user' => 'bob']);

		$events = new \ArrayObject();
		$this->db->method('beginTransaction')->willReturnCallback(
			static function () use ($events): void {
				$events->append('begin');
			}
		);
		$this->db->method('commit')->willReturnCallback(
			static function () use ($events): void {
				$events->append('commit');
			}
		);
		$this->auditTrailMapper->method('createAuditTrailEntry')->willReturnCallback(
			static function (ObjectEntity $object, string $action) use ($events) {
				$events->append($action);

				return new \OCA\OpenRegister\Db\AuditTrail();
			}
		);

		$result = $this->service->cascadeDeleteSchema(
			schema: $this->schema,
			override: new SchemaTeardownOverride(keptRows: true)
		);

		$this->assertSame(2, $result['deletedCount']);
		$this->assertSame(
			[
				'begin',
				SchemaDeletionService::ACTION_FORCED_TEARDOWN,
				SchemaDeletionService::ACTION_CASCADE_DELETE,
				SchemaDeletionService::ACTION_CASCADE_DELETE,
				'commit',
			],
			$events->getArrayCopy()
		);

	}//end testAForcedTeardownWritesOneAuditEntryInsideTheTransaction()

	/**
	 * The forced entry names the schema, the count and the actor.
	 *
	 * @return void
	 */
	public function testTheForcedEntryNamesWhatWasForced(): void {
		$this->givenTheRegisterExists();
		$this->otherRow->setFrozen(self::FREEZE);
		$entries = $this->recordAuditEntries();

		$this->service->cascadeDeleteSchema(
			schema: $this->schema,
			override: new SchemaTeardownOverride(keptRows: true)
		);

		$forced = $this->forcedEntries($entries);
		$this->assertCount(1, $forced);
		$this->assertSame(
			['schemaSlug' => 'held-case', 'keptRows' => 1, 'archivalSchema' => false],
			$forced[0]['context']
		);
		$this->assertSame('occ', $forced[0]['actor']);
		$this->assertSame('occ', $forced[0]['name']);
		$this->assertSame($this->schema->getUuid(), $forced[0]['object']->getUuid());
		$this->assertSame((string)self::SCHEMA_ID, $forced[0]['object']->getSchema());

	}//end testTheForcedEntryNamesWhatWasForced()

	/**
	 * Destroying an archival schema's records is a forced removal too.
	 *
	 * @return void
	 */
	public function testAnArchivalOverrideWritesAForcedEntry(): void {
		$this->givenTheRegisterExists();
		$this->schema->setConfiguration(['x-openregister-archival' => ['retention' => ['default' => 'P10Y']]]);
		$entries = $this->recordAuditEntries();

		$this->service->cascadeDeleteSchema(
			schema: $this->schema,
			override: new SchemaTeardownOverride(archivalSchema: true)
		);

		$forced = $this->forcedEntries($entries);
		$this->assertCount(1, $forced);
		$this->assertSame(0, $forced[0]['context']['keptRows']);
		$this->assertTrue($forced[0]['context']['archivalSchema']);

	}//end testAnArchivalOverrideWritesAForcedEntry()

	/**
	 * A table that outlives its register and holds rows refuses.
	 *
	 * @return void
	 */
	public function testAStaleTableWithRowsRefusesTheTeardown(): void {
		$this->givenAStaleTable(rows: 3);
		$this->expectNothingTouched();

		$this->expectException(ValidationException::class);

		$this->service->cascadeDeleteSchema(schema: $this->schema);

	}//end testAStaleTableWithRowsRefusesTheTeardown()

	/**
	 * An empty stale table is still reclaimed.
	 *
	 * @return void
	 */
	public function testAnEmptyStaleTableDoesNotRefuse(): void {
		$this->givenAStaleTable(rows: 0);

		$this->schemaMapper->expects($this->once())->method('delete');
		$this->magicMapper->expects($this->once())->method('dropTable');

		$this->service->cascadeDeleteSchema(schema: $this->schema);

	}//end testAnEmptyStaleTableDoesNotRefuse()

	/**
	 * A forced teardown of a stale table records its rows as kept.
	 *
	 * @return void
	 */
	public function testAForcedStaleTableCountsItsRowsInTheEntry(): void {
		$this->givenAStaleTable(rows: 3);
		$entries = $this->recordAuditEntries();

		$this->service->cascadeDeleteSchema(
			schema: $this->schema,
			override: new SchemaTeardownOverride(keptRows: true)
		);

		$forced = $this->forcedEntries($entries);
		$this->assertCount(1, $forced);
		$this->assertSame(3, $forced[0]['context']['keptRows']);

	}//end testAForcedStaleTableCountsItsRowsInTheEntry()

	/**
	 * Every state counts, and a plain row does not.
	 *
	 * @return void
	 */
	public function testCountKeptRowsCountsEachState(): void {
		$this->givenTheRegisterExists();
		$this->assertSame(0, $this->service->countKeptRows(schema: $this->schema));

		$this->plainRow->setArchived(self::FREEZE);
		$this->assertSame(1, $this->service->countKeptRows(schema: $this->schema));

		$this->otherRow->setFrozen(self::FREEZE);
		$this->assertSame(2, $this->service->countKeptRows(schema: $this->schema));

		$this->otherRow->setFrozen([]);
		$this->otherRow->setLocked(['kind' => ObjectEntity::LOCK_KIND_USER, 'user' => 'bob']);
		$this->assertSame(2, $this->service->countKeptRows(schema: $this->schema));

	}//end testCountKeptRowsCountsEachState()

	/**
	 * A table that cannot be read counts as holding a kept row.
	 *
	 * @return void
	 */
	public function testAnUnreadableTableCountsAsKept(): void {
		$this->givenTheRegisterExists();

		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('getAllRegisterSchemaPairs')->willReturn(
			[['registerId' => self::REGISTER_ID, 'schemaId' => self::SCHEMA_ID]]
		);
		$mapper->method('tableExistsForRegisterSchema')->willThrowException(new RuntimeException('broken'));

		$service = new SchemaDeletionService(
			$this->db,
			new SchemaDeletionStores(new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->registerMapper, schemas: $this->schemaMapper, tables: $mapper), $this->auditTrailMapper),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame(1, $service->countKeptRows(schema: $this->schema));

	}//end testAnUnreadableTableCountsAsKept()

	/**
	 * A missing table holds nothing to keep.
	 *
	 * @return void
	 */
	public function testAMissingTableHoldsNoKeptRows(): void {
		$this->givenTheRegisterExists();

		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('getAllRegisterSchemaPairs')->willReturn(
			[['registerId' => self::REGISTER_ID, 'schemaId' => self::SCHEMA_ID]]
		);
		$mapper->method('tableExistsForRegisterSchema')->willReturn(false);
		$mapper->expects($this->never())->method('findAllInRegisterSchemaTable');

		$service = new SchemaDeletionService(
			$this->db,
			new SchemaDeletionStores(new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->registerMapper, schemas: $this->schemaMapper, tables: $mapper), $this->auditTrailMapper),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame(0, $service->countKeptRows(schema: $this->schema));

	}//end testAMissingTableHoldsNoKeptRows()
}//end class
