<?php

/**
 * A migrated object ends up in the target table only, with everything the source row held.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\ObjectMigration;
use Exception;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\DataManipulationHandler;
use OCA\OpenRegister\Service\Object\MigrationHandler;
use OCA\OpenRegister\Service\Object\MigrationMapping;
use OCA\OpenRegister\Service\Object\MigrationStorage;
use OCA\OpenRegister\Service\Object\MoveObject;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Event\ObjectLeavingEvent;
use OCP\EventDispatcher\IEventDispatcher;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Info\DataInfo;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The doubles keep one array of rows per table, as the magic tables do: the batch load returns
 * the slim entity MagicMapper::findMultiple() returns, and a write lands in one table only.
 *
 * @covers \OCA\OpenRegister\Service\Object\MigrationHandler
 * @covers \OCA\OpenRegister\Service\Object\MigrationRun
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\MigrationSide
 * @uses   \OCA\OpenRegister\Service\Object\MoveAddress
 * @uses   \OCA\OpenRegister\Service\Object\MoveObject
 * @uses   \OCA\OpenRegister\Service\Object\ObjectMove
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MigrationHandlerRemovesSourceRowTest extends TestCase {

	private const UUID = '11111111-1111-1111-1111-111111111111';

	private const SOURCE = '1/10';

	private const TARGET = '2/20';

	/** @var array<string, array<string, ObjectEntity>> */
	private array $tables = [];

	/** @var list<array{0: string, 1: mixed}> */
	private array $auditEntries = [];

	private bool $refuseAtTarget = false;

	private bool $removalFails = false;

	private bool $auditFails = false;

	private ?IEventDispatcher $guards = null;

	/** @var list<ObjectEntity|null> */
	private array $oldObjects = [];

	/**
	 * Build the source table.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$stored = new ObjectEntity();
		$stored->setId(5);
		$stored->setUuid(self::UUID);
		$stored->setRegister('1');
		$stored->setSchema('10');
		$stored->setOwner('anna');
		$stored->setOrganisation('org-1');
		$stored->setRelations(['old' => 'to-be-replaced']);
		$stored->setObject(['title' => 'T', 'note' => 'N']);
		$this->tables = [self::SOURCE => [self::UUID => $stored], self::TARGET => []];
	}//end setUp()

	/**
	 * The object leaves the source table and is in the target table.
	 *
	 * @return void
	 */
	public function testTheSourceRowIsGoneAndTheTargetRowIsThere(): void {
		$report = $this->migrate();

		$this->assertSame(1, $report['statistics']['objectsMigrated'], (string) json_encode($report));
		$this->assertSame(self::UUID, $report['details'][0]['newObjectId']);
		$this->assertSame([], $this->tables[self::SOURCE]);
		$this->assertSame([self::UUID], array_keys($this->tables[self::TARGET]));
		$this->assertSame(['id' => self::UUID, 'headline' => 'T'], $this->tables[self::TARGET][self::UUID]->getObject());
	}//end testTheSourceRowIsGoneAndTheTargetRowIsThere()

	/**
	 * Owner and organisation come from the stored row, not from the slim batch load.
	 *
	 * @return void
	 */
	public function testTheTargetRowKeepsOwnerAndOrganisation(): void {
		$this->migrate();

		$written = $this->tables[self::TARGET][self::UUID];
		$this->assertSame('anna', $written->getOwner());
		$this->assertSame('org-1', $written->getOrganisation());
	}//end testTheTargetRowKeepsOwnerAndOrganisation()

	/**
	 * Relations are what the mapped data holds, read against the target schema.
	 *
	 * @return void
	 */
	public function testRelationsAreRebuiltFromTheMappedData(): void {
		$this->migrate();

		$this->assertSame(['headline' => 'scanned'], $this->tables[self::TARGET][self::UUID]->getRelations());
	}//end testRelationsAreRebuiltFromTheMappedData()

	/**
	 * The guards read what the row holds, not what the mapping turns it into.
	 *
	 * @return void
	 */
	public function testTheGuardsReadTheStoredDataAndKeepTheObject(): void {
		$heard = [];
		$this->guards = $this->createMock(IEventDispatcher::class);
		$this->guards->method('dispatchTyped')->willReturnCallback(
			static function (ObjectLeavingEvent $event) use (&$heard): void {
				$heard[] = $event->getObject()->getObject();
				$event->setErrors(['message' => 'Records hold this value.']);
				$event->stopPropagation();
			}
		);

		$report = $this->migrate();

		$this->assertSame([['id' => self::UUID, 'title' => 'T', 'note' => 'N']], $heard);
		$this->assertSame(1, $report['statistics']['objectsFailed']);
		$this->assertStringContainsString('Records hold this value.', $report['errors'][0]);
		$this->assertSame([self::UUID], array_keys($this->tables[self::SOURCE]));
		$this->assertSame([], $this->tables[self::TARGET]);
	}//end testTheGuardsReadTheStoredDataAndKeepTheObject()

	/**
	 * The target write names the row as stored as its old object, so the update guards see what the mapping changed.
	 *
	 * @return void
	 */
	public function testTheTargetWriteJudgesTheChangeFromTheStoredRow(): void {
		$this->migrate();

		$this->assertCount(1, $this->oldObjects);
		$this->assertSame(['id' => self::UUID, 'title' => 'T', 'note' => 'N'], $this->oldObjects[0]?->getObject());
		$this->assertSame(['1', '10'], [$this->oldObjects[0]?->getRegister(), $this->oldObjects[0]?->getSchema()]);
	}//end testTheTargetWriteJudgesTheChangeFromTheStoredRow()

	/**
	 * An object that does not fit the target stays where it is and is reported.
	 *
	 * @return void
	 */
	public function testAnObjectThatDoesNotFitStaysInTheSource(): void {
		$this->refuseAtTarget = true;

		$report = $this->migrate();

		$this->assertSame(0, $report['statistics']['objectsMigrated']);
		$this->assertSame(1, $report['statistics']['objectsFailed']);
		$this->assertStringContainsString('headline: is required', $report['errors'][0]);
		$this->assertSame([self::UUID], array_keys($this->tables[self::SOURCE]));
		$this->assertSame([], $this->tables[self::TARGET]);
		$this->assertSame([], $this->auditEntries);
	}//end testAnObjectThatDoesNotFitStaysInTheSource()

	/**
	 * A source row that survives counts as migrated and is reported as a warning.
	 *
	 * @return void
	 */
	public function testAStrandedSourceRowIsAWarning(): void {
		$this->removalFails = true;

		$report = $this->migrate();

		$this->assertSame(1, $report['statistics']['objectsMigrated']);
		$this->assertCount(1, $report['warnings']);
		$this->assertStringContainsString(self::UUID, $report['warnings'][0]);
		$this->assertStringContainsString('readable at both addresses', $report['warnings'][0]);
	}//end testAStrandedSourceRowIsAWarning()

	/**
	 * The move is recorded, and so is what the mapping did to the data.
	 *
	 * @return void
	 */
	public function testTheMoveAndTheMappingAreBothInTheAuditTrail(): void {
		$this->migrate();

		$this->assertSame('moved', $this->auditEntries[0][0]);
		$this->assertSame(['register' => 1, 'schema' => 10], $this->auditEntries[0][1]['from']);
		$this->assertSame(
			['update', [['id' => self::UUID, 'title' => 'T', 'note' => 'N'], ['id' => self::UUID, 'headline' => 'T']]],
			$this->auditEntries[1]
		);
	}//end testTheMoveAndTheMappingAreBothInTheAuditTrail()

	/**
	 * A failed mapping entry does not turn a finished move into a failure.
	 *
	 * @return void
	 */
	public function testAFailedMappingEntryKeepsTheObjectMigrated(): void {
		$this->auditFails = true;

		$report = $this->migrate();

		$this->assertSame(1, $report['statistics']['objectsMigrated']);
		$this->assertSame(0, $report['statistics']['objectsFailed']);
	}//end testAFailedMappingEntryKeepsTheObjectMigrated()

	/**
	 * Run the migration.
	 *
	 * @return array<string, mixed> The report.
	 */
	private function migrate(): array {
		return $this->handler()->migrateObjects(
			migration: new ObjectMigration(
				source: new RegisterSchemaRef(register: 1, schema: 10),
				target: new RegisterSchemaRef(register: 2, schema: 20),
				objectIds: [self::UUID],
				mapping: ['headline' => 'title']
			)
		);
	}//end migrate()

	/**
	 * The handler over the tables.
	 *
	 * @return MigrationHandler
	 */
	private function handler(): MigrationHandler {
		$mapper = $this->mapper();
		$logger = new NullLogger();
		$audit = $this->audit();

		$manipulation = $this->createMock(DataManipulationHandler::class);
		$manipulation->method('mapObjectProperties')->willReturn(['headline' => 'T']);

		$scanner = $this->createMock(SaveObject::class);
		$scanner->method('scanForRelations')->willReturnCallback(
			static fn (array $data, string $prefix, Schema $schema): array => ($schema->getId() === 20) ? ['headline' => 'scanned'] : []
		);

		return new MigrationHandler(
			storage: new MigrationStorage(objectMapper: $mapper, mover: new MoveObject(recording: new \OCA\OpenRegister\Service\Object\ObjectChangeRecording($mapper, $audit, $logger, ($this->guards ?? $this->createStub(IEventDispatcher::class))), validator: $this->validator()), auditTrail: $audit),
			mapping: new MigrationMapping(utilityHandler: $this->utility(), dataManipHandler: $manipulation, relationScanner: $scanner),
			logger: $logger
		);
	}//end handler()

	/**
	 * The object store: one array of rows per table.
	 *
	 * @return MagicMapper
	 */
	private function mapper(): MagicMapper {
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('findMultiple')->willReturnCallback(fn (): array => [$this->slim()]);
		$mapper->method('findInRegisterSchemaTable')->willReturnCallback(
			fn (string $uuid, Register $register, Schema $schema): ObjectEntity => clone $this->tables[$this->table($register, $schema)][$uuid]
		);
		$mapper->method('update')->willReturnCallback(
			function (ObjectEntity $entity, Register $register, Schema $schema, ?ObjectEntity $oldEntity = null): ObjectEntity {
				$this->oldObjects[] = $oldEntity;
				$this->tables[$this->table($register, $schema)][$entity->getUuid()] = clone $entity;
				return $entity;
			}
		);
		$mapper->method('deleteObjectEntity')->willReturnCallback(
			function (ObjectEntity $entity, Register $register, Schema $schema): ObjectEntity {
				if ($this->removalFails === true) {
					throw new RuntimeException('row locked');
				}

				unset($this->tables[$this->table($register, $schema)][$entity->getUuid()]);
				return $entity;
			}
		);
		return $mapper;
	}//end mapper()

	/**
	 * The entity the batch load returns: address and data, no other metadata.
	 *
	 * @return ObjectEntity
	 */
	private function slim(): ObjectEntity {
		$slim = new ObjectEntity();
		$slim->setUuid(self::UUID);
		$slim->setRegister('1');
		$slim->setSchema('10');
		$slim->setObject(['title' => 'T', 'note' => 'N']);
		return $slim;
	}//end slim()

	/**
	 * The audit trail double, recording the entries.
	 *
	 * @return AuditTrailMapper
	 */
	private function audit(): AuditTrailMapper {
		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('createAuditTrailEntry')->willReturnCallback(
			function (ObjectEntity $object, string $action, array $context): AuditTrail {
				$this->auditEntries[] = [$action, $context];
				return new AuditTrail();
			}
		);
		$audit->method('createAuditTrail')->willReturnCallback(
			function (ObjectEntity $old, ObjectEntity $new, string $action): AuditTrail {
				if ($this->auditFails === true) {
					throw new Exception('audit down');
				}

				$this->auditEntries[] = [$action, [$old->getObject(), $new->getObject()]];
				return new AuditTrail();
			}
		);
		return $audit;
	}//end audit()

	/**
	 * The validator double: refuses when the test says so.
	 *
	 * @return ValidateObject
	 */
	private function validator(): ValidateObject {
		$result = new ValidationResult(null);
		if ($this->refuseAtTarget === true) {
			$info = $this->createMock(DataInfo::class);
			$info->method('path')->willReturn(['headline']);
			$error = $this->createMock(ValidationError::class);
			$error->method('subErrors')->willReturn([]);
			$error->method('message')->willReturn('is required');
			$error->method('data')->willReturn($info);
			$result = new ValidationResult($error);
		}

		$validator = $this->createMock(ValidateObject::class);
		$validator->method('validateObject')->willReturn($result);
		return $validator;
	}//end validator()

	/**
	 * Loads the registers and schemas by id.
	 *
	 * @return UtilityHandler
	 */
	private function utility(): UtilityHandler {
		$utility = $this->createMock(UtilityHandler::class);
		$utility->method('normalizeEntity')->willReturnCallback(
			static function (int $id, string $type): Register|Schema {
				$entity = ($type === 'register') ? new Register() : new Schema();
				$entity->setId($id);
				return $entity;
			}
		);
		return $utility;
	}//end utility()

	/**
	 * The table of one address.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 *
	 * @return string The key in the tables.
	 */
	private function table(Register $register, Schema $schema): string {
		return $register->getId() . '/' . $schema->getId();
	}//end table()
}//end class
