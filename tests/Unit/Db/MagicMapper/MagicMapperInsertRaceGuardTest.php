<?php

/**
 * MagicMapper: a create that finds its identifier taken runs the update guard before it updates.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicRowWrite;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\ObjectExistsException;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableCreator
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumnHealer
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicTableHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicRowWrite
 * @uses \OCA\OpenRegister\Service\Query\PropertyReadGuard
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowAccess
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowQueryApplier
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Event\ObjectCreatedEvent
 * @uses   \OCA\OpenRegister\Event\AbstractPreWriteObjectEvent
 * @uses \OCA\OpenRegister\Event\ObjectCreatingEvent
 * @uses \OCA\OpenRegister\Event\ObjectUpdatedEvent
 * @uses \OCA\OpenRegister\Service\Object\ObjectEventGate
 * @uses \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses \OCA\OpenRegister\Service\SystemOperationContext
 * @uses \OCA\OpenRegister\Service\WritePhaseProbe
 * @uses \OCA\OpenRegister\Event\ObjectUpdatingEvent
 * @uses \OCA\OpenRegister\Exception\HookStoppedException
 * @uses \OCA\OpenRegister\Exception\ObjectExistsException
 * @uses   \OCA\OpenRegister\Service\Rbac\RuleColumns
 * @uses   \OCA\OpenRegister\Service\Rbac\UnknownRuleKeyFindings
 * @uses \OCA\OpenRegister\Service\Rbac\StoredRuleBlocks
 *
 * @spec openspec/changes/insert-race-runs-update-guard/specs/object-lifecycle/spec.md
 */
class MagicMapperInsertRaceGuardTest extends TestCase {

	private const UUID = '11111111-2222-3333-4444-555555555555';

	private const REFUSAL = 'The transition is not allowed';

	private const NOT_INITIAL = 'A new object starts in "open"';

	/**
	 * Events in the order they were dispatched.
	 *
	 * @var list<object>
	 */
	private array $dispatched = [];

	/**
	 * The failIfExists flag of every table write, in order.
	 *
	 * @var list<bool>
	 */
	private array $writes = [];

	/**
	 * Whether the uuid is already in the table.
	 *
	 * @var bool
	 */
	private bool $taken = false;

	/**
	 * The object data of every table write, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $writtenData = [];

	/**
	 * Whether the stored row is soft-deleted.
	 *
	 * @var bool
	 */
	private bool $storedDeleted = false;

	/**
	 * Raw rows the next table reads answer with, before falling back to $taken.
	 *
	 * @var list<array<string, mixed>|null>
	 */
	private array $rowQueue = [];

	/**
	 * Whether the stored row was soft-deleted after it was read, as the write would find it.
	 *
	 * @var bool
	 */
	private bool $deletedNow = false;

	/**
	 * The mapper under test, for the table-write stub to read its live-row-only writes.
	 *
	 * @var MagicMapper|null
	 */
	private ?MagicMapper $liveMapper = null;

	/**
	 * Run once inside the next ObjectCreatingEvent, before it is recorded.
	 *
	 * @var callable|null
	 */
	private $onCreating = null;

	/**
	 * Whether the row is gone once the write was applied as an update.
	 *
	 * @var bool
	 */
	private bool $goneAfterWrite = false;

	/**
	 * Run once inside the next ObjectUpdatingEvent, before it is recorded.
	 *
	 * @var callable|null
	 */
	private $onUpdating = null;

	/**
	 * The mapper with its table I/O stubbed.
	 *
	 * @param bool $taken Whether the uuid is already in the table.
	 *
	 * @return MagicMapper&MockObject
	 */
	private function mapper(bool $taken): MagicMapper&MockObject {
		$this->dispatched = [];
		$this->writes     = [];
		$this->taken      = $taken;
		$this->rowQueue   = [];

		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				if ($event instanceof ObjectCreatingEvent && $this->onCreating !== null) {
					$hook             = $this->onCreating;
					$this->onCreating = null;
					$hook();
				}

				if ($event instanceof ObjectUpdatingEvent && $this->onUpdating !== null) {
					$hook             = $this->onUpdating;
					$this->onUpdating = null;
					$hook();
				}

				if ($event instanceof ObjectCreatingEvent && (($event->getObject()->getObject()['status'] ?? null) === 'numbered')) {
					$event->setModifiedData(['number' => 'N-1']);
				}

				if ($event instanceof ObjectCreatingEvent && (($event->getObject()->getObject()['status'] ?? null) === 'archived')) {
					$event->setModifiedData(['number' => 'from-a-refused-create']);
					$event->setErrors(['message' => self::NOT_INITIAL]);
					$event->stopPropagation();
				}

				if ($event instanceof ObjectUpdatingEvent && (($event->getNewObject()->getObject()['status'] ?? null) === 'in-deliberation')) {
					$event->setErrors(['message' => self::REFUSAL]);
					$event->stopPropagation();
				}

				$this->dispatched[] = $event;
			}
		);

		$mapper = $this->getMockBuilder(MagicMapper::class)
			->setConstructorArgs(
				[
					$this->createMock(IDBConnection::class),
					$this->createMock(SchemaMapper::class),
					$this->createMock(RegisterMapper::class),
					$this->createMock(IConfig::class),
					$events,
					$this->createMock(IUserSession::class),
					$this->createMock(IGroupManager::class),
					$this->createMock(IUserManager::class),
					$this->createMock(IAppConfig::class),
					$this->createMock(LoggerInterface::class),
					$this->createMock(AppSettingsInterface::class),
					$this->container(),
				]
			)
			->onlyMethods(
				[
					'ensureTableForRegisterSchema',
					'getTableNameForRegisterSchema',
					'saveObjectToRegisterSchemaTable',
					'findObjectInRegisterSchemaTable',
					'convertRowToObjectEntity',
				]
			)
			->getMock();

		$this->liveMapper = $mapper;
		$this->deletedNow = false;
		$mapper->method('ensureTableForRegisterSchema')->willReturn(true);
		$mapper->method('getTableNameForRegisterSchema')->willReturn('openregister_table_3_7');
		$mapper->method('saveObjectToRegisterSchemaTable')->willReturnCallback(
			function (MagicRowWrite $write): string {
				$objectData = $write->objectData;
				$failIfExists = $write->failIfExists;
				$liveOnly = null;
				if (property_exists(MagicMapper::class, 'liveRowOnlyWrites') === true) {
					$liveOnly = ((new \ReflectionProperty(MagicMapper::class, 'liveRowOnlyWrites'))->getValue($this->liveMapper)[self::UUID] ?? null);
				}

				if ($liveOnly !== null && ($this->taken === false || $this->deletedNow === true)) {
					throw $liveOnly;
				}

				$this->writes[] = $failIfExists;
				$this->writtenData[] = $objectData;
				if ($this->taken === true && $failIfExists === true) {
					throw new ObjectExistsException(message: 'taken', uuid: self::UUID);
				}

				if ($this->taken === false) {
					$write->insertedId = 41;
				}

				if ($this->goneAfterWrite === true) {
					$this->taken = false;
				}

				return self::UUID;
			}
		);
		$mapper->method('findObjectInRegisterSchemaTable')->willReturnCallback(
			fn (): ?array => ($this->rowQueue !== [] ? array_shift($this->rowQueue) : ($this->taken === true ? ['_id' => 5, '_uuid' => self::UUID] : null))
		);
		$mapper->method('convertRowToObjectEntity')->willReturnCallback(
			function (array $row): ObjectEntity {
				$stored = new ObjectEntity();
				$stored->setId(5);
				$stored->setUuid(self::UUID);
				$stored->setObject(['status' => 'open']);
				if ($this->storedDeleted === true || empty($row['_deleted']) === false) {
					$stored->setDeleted(['deletedBy' => 'agent']);
				}

				return $stored;
			}
		);

		return $mapper;
	}//end mapper()

	/**
	 * A container that hands the mapper's handlers the collaborators they need.
	 *
	 * @return ContainerInterface
	 */
	private function container(): ContainerInterface {
		$services = [
			DateTimeNormalizer::class => $this->createMock(DateTimeNormalizer::class),
			ConditionMatcher::class => $this->createMock(ConditionMatcher::class),
			SchemaTypeConverter::class => $this->createMock(SchemaTypeConverter::class),
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): ?object => ($services[$id] ?? null));
		return $container;
	}//end container()

	/**
	 * An object to create with the given status.
	 *
	 * @param string $status The status it carries.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $status): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid(self::UUID);
		$entity->setRegister('3');
		$entity->setSchema('7');
		$entity->setObject(['status' => $status]);
		return $entity;
	}//end entity()

	/**
	 * A register and a schema with ids.
	 *
	 * @return array{0: Register, 1: Schema}
	 */
	private function target(): array {
		$register = new Register();
		$register->setId(3);
		$schema = new Schema();
		$schema->setId(7);
		return [$register, $schema];
	}//end target()

	/**
	 * The classes of the dispatched events, in order.
	 *
	 * @return list<string>
	 */
	private function eventClasses(): array {
		return array_map(static fn (object $e): string => $e::class, $this->dispatched);
	}//end eventClasses()

	public function testALostInsertRunsTheUpdateGuard(): void {
		[$register, $schema] = $this->target();
		$mapper = $this->mapper(taken: true);

		try {
			$mapper->insertObjectEntity(entity: $this->entity('in-deliberation'), register: $register, schema: $schema);
			$this->fail('The update guard should have refused the write.');
		} catch (HookStoppedException $e) {
			$this->assertSame(self::REFUSAL, $e->getMessage());
		}

		$this->assertSame([true], $this->writes);
		$this->assertSame([ObjectCreatingEvent::class, ObjectUpdatingEvent::class], $this->eventClasses());
	}//end testALostInsertRunsTheUpdateGuard()

	public function testALostInsertTheGuardAllowsIsWrittenAsAnUpdate(): void {
		[$register, $schema] = $this->target();
		$mapper = $this->mapper(taken: true);

		$saved = $mapper->insertObjectEntity(entity: $this->entity('closed'), register: $register, schema: $schema);

		$this->assertSame([true, false], $this->writes);
		$this->assertSame(5, $saved->getId());
		$updating = $this->dispatched[1];
		$this->assertInstanceOf(ObjectUpdatingEvent::class, $updating);
		$this->assertSame('open', $updating->getOldObject()?->getObject()['status']);
		$this->assertSame('closed', $updating->getNewObject()->getObject()['status']);
	}//end testALostInsertTheGuardAllowsIsWrittenAsAnUpdate()

	public function testAnInsertThatMustNotUpdateStillRefuses(): void {
		[$register, $schema] = $this->target();
		$mapper = $this->mapper(taken: true);

		$this->expectException(ObjectExistsException::class);
		try {
			$mapper->insertObjectEntity(entity: $this->entity('closed'), register: $register, schema: $schema, failIfExists: true);
		} finally {
			$this->assertSame([true], $this->writes);
			$this->assertSame([ObjectCreatingEvent::class], $this->eventClasses());
		}
	}//end testAnInsertThatMustNotUpdateStillRefuses()

	public function testAnInsertThatWinsWritesOnce(): void {
		[$register, $schema] = $this->target();
		$mapper = $this->mapper(taken: false);

		$saved = $mapper->insertObjectEntity(entity: $this->entity('in-deliberation'), register: $register, schema: $schema);

		$this->assertSame([true], $this->writes);
		$this->assertSame(41, $saved->getId());
		$this->assertSame([ObjectCreatingEvent::class], $this->eventClasses());
	}//end testAnInsertThatWinsWritesOnce()
	public function testALostInsertAnnouncesAnUpdateNotACreate(): void {
		[$register, $schema] = $this->target();
		$mapper = $this->mapper(taken: true);

		$mapper->insert(entity: $this->entity('closed'), register: $register, schema: $schema);

		$this->assertSame([ObjectCreatingEvent::class, ObjectUpdatingEvent::class, ObjectUpdatedEvent::class], $this->eventClasses());
		$updated = $this->dispatched[2];
		$this->assertInstanceOf(ObjectUpdatedEvent::class, $updated);
		$this->assertSame('open', $updated->getOldObject()?->getObject()['status']);
		$this->assertSame('closed', $updated->getNewObject()->getObject()['status']);
	}//end testALostInsertAnnouncesAnUpdateNotACreate()

	public function testAWonInsertAnnouncesACreate(): void {
		[$register, $schema] = $this->target();
		$mapper = $this->mapper(taken: false);

		$mapper->insert(entity: $this->entity('closed'), register: $register, schema: $schema);

		$this->assertSame([ObjectCreatingEvent::class, ObjectCreatedEvent::class], $this->eventClasses());
	}//end testAWonInsertAnnouncesACreate()

	public function testAnInsertAfterALostOneAnnouncesACreateAgain(): void {
		[$register, $schema] = $this->target();
		$mapper = $this->mapper(taken: true);
		$mapper->insertObjectEntity(entity: $this->entity('closed'), register: $register, schema: $schema);

		$this->taken      = false;
		$this->dispatched = [];
		$mapper->insert(entity: $this->entity('closed'), register: $register, schema: $schema);

		$this->assertSame([ObjectCreatingEvent::class, ObjectCreatedEvent::class], $this->eventClasses());
	}//end testAnInsertAfterALostOneAnnouncesACreateAgain()
	public function testANestedLostInsertDoesNotTurnTheOuterCreateIntoAnUpdate(): void {
		[$register, $schema] = $this->target();
		$mapper = $this->mapper(taken: false);
		$this->onCreating = function () use ($mapper, $register, $schema): void {
			$this->taken = true;
			$mapper->insert(entity: $this->entity('inner'), register: $register, schema: $schema);
			$this->taken = false;
		};

		$mapper->insert(entity: $this->entity('closed'), register: $register, schema: $schema);

		$this->assertSame(
			[ObjectCreatingEvent::class, ObjectUpdatingEvent::class, ObjectUpdatedEvent::class, ObjectCreatingEvent::class, ObjectCreatedEvent::class],
			$this->eventClasses()
		);
	}//end testANestedLostInsertDoesNotTurnTheOuterCreateIntoAnUpdate()

	public function testAStoppedUpdateLeavesNoReplacedRowBehind(): void {
		[$register, $schema] = $this->target();
		$mapper = $this->mapper(taken: true);
		try {
			$mapper->insert(entity: $this->entity('in-deliberation'), register: $register, schema: $schema);
		} catch (HookStoppedException $e) {
			// The refusal is the point of the first insert.
		}

		$this->taken      = false;
		$this->dispatched = [];
		$mapper->insertObjectEntity(entity: $this->entity('closed'), register: $register, schema: $schema);
		$this->dispatched = [];
		$mapper->insert(entity: $this->entity('closed'), register: $register, schema: $schema);

		$this->assertSame([ObjectCreatingEvent::class, ObjectCreatedEvent::class], $this->eventClasses());
	}//end testAStoppedUpdateLeavesNoReplacedRowBehind()

	public function testAWonInsertNestedInAnUpdatingListenerKeepsTheOuterUpdate(): void {
		[$register, $schema] = $this->target();
		$mapper = $this->mapper(taken: true);
		$this->onUpdating = function () use ($mapper, $register, $schema): void {
			$this->taken = false;
			$mapper->insert(entity: $this->entity('inner'), register: $register, schema: $schema);
			$this->taken = true;
		};

		$mapper->insert(entity: $this->entity('closed'), register: $register, schema: $schema);

		$last = end($this->dispatched);
		$this->assertInstanceOf(ObjectUpdatedEvent::class, $last);
		$this->assertSame('closed', $last->getNewObject()->getObject()['status']);
		$this->assertSame('open', $last->getOldObject()?->getObject()['status']);
	}//end testAWonInsertNestedInAnUpdatingListenerKeepsTheOuterUpdate()

	public function testARefusedCreateOverATakenIdentifierIsJudgedAsAnUpdate(): void {
		[$register, $schema] = $this->target();
		$mapper = $this->mapper(taken: true);

		$saved = $mapper->insert(entity: $this->entity('archived'), register: $register, schema: $schema);

		$this->assertSame(5, $saved->getId());
		$this->assertSame([false], $this->writes);
		$this->assertSame([ObjectCreatingEvent::class, ObjectUpdatingEvent::class, ObjectUpdatedEvent::class], $this->eventClasses());
		$this->assertSame('open', $this->dispatched[2]->getOldObject()?->getObject()['status']);
	}//end testARefusedCreateOverATakenIdentifierIsJudgedAsAnUpdate()

	public function testARefusedCreateOfAFreeIdentifierStaysRefused(): void {
		[$register, $schema] = $this->target();
		$mapper = $this->mapper(taken: false);

		try {
			$mapper->insert(entity: $this->entity('archived'), register: $register, schema: $schema);
			$this->fail('The creating refusal should stand.');
		} catch (HookStoppedException $e) {
			$this->assertSame(self::NOT_INITIAL, $e->getMessage());
		}

		$this->assertSame([], $this->writes);
		$this->assertSame([ObjectCreatingEvent::class], $this->eventClasses());
	}//end testARefusedCreateOfAFreeIdentifierStaysRefused()

	public function testARefusedClaimStaysRefused(): void {
		[$register, $schema] = $this->target();
		$mapper = $this->mapper(taken: true);

		try {
			$mapper->insertObjectEntity(entity: $this->entity('archived'), register: $register, schema: $schema, failIfExists: true);
			$this->fail('The creating refusal should stand.');
		} catch (HookStoppedException $e) {
			$this->assertSame(self::NOT_INITIAL, $e->getMessage());
		}

		$this->assertSame([], $this->writes);
	}//end testARefusedClaimStaysRefused()

	public function testWhatACreatingHookSetsIsWritten(): void {
		[$register, $schema] = $this->target();
		$mapper = $this->mapper(taken: false);
		$this->writtenData = [];

		$mapper->insertObjectEntity(entity: $this->entity('numbered'), register: $register, schema: $schema);

		$this->assertSame('N-1', ($this->writtenData[0]['number'] ?? $this->writtenData[0]['object']['number'] ?? null));
	}//end testWhatACreatingHookSetsIsWritten()

	public function testARefusedCreateOverASoftDeletedRowStaysRefused(): void {
		[$register, $schema] = $this->target();
		$mapper = $this->mapper(taken: true);
		$this->storedDeleted = true;

		try {
			$mapper->insert(entity: $this->entity('archived'), register: $register, schema: $schema);
			$this->fail('A refused create must not bring a soft-deleted object back.');
		} catch (HookStoppedException $e) {
			$this->assertSame(self::NOT_INITIAL, $e->getMessage());
		}

		$this->assertSame([], $this->writes);
		$this->assertSame([ObjectCreatingEvent::class], $this->eventClasses());
	}//end testARefusedCreateOverASoftDeletedRowStaysRefused()

	public function testWhatARefusedCreatingHookSetsIsNotWritten(): void {
		[$register, $schema] = $this->target();
		$mapper = $this->mapper(taken: true);
		$this->writtenData = [];

		$mapper->insert(entity: $this->entity('archived'), register: $register, schema: $schema);

		$this->assertCount(1, $this->writtenData);
		$this->assertArrayNotHasKey('number', $this->writtenData[0]);
		$this->assertArrayNotHasKey('number', ($this->writtenData[0]['object'] ?? []));
	}//end testWhatARefusedCreatingHookSetsIsNotWritten()

	public function testARefusedCreateIsNotWrittenWhenItsRowGoesAway(): void {
		[$register, $schema] = $this->target();
		$mapper = $this->mapper(taken: true);
		$this->rowQueue = [['_id' => 5, '_uuid' => self::UUID], null];

		try {
			$mapper->insert(entity: $this->entity('archived'), register: $register, schema: $schema);
			$this->fail('A refused create whose row went away must not be written.');
		} catch (HookStoppedException $e) {
			$this->assertSame(self::NOT_INITIAL, $e->getMessage());
		}

		$this->assertSame([], $this->writes);
	}//end testARefusedCreateIsNotWrittenWhenItsRowGoesAway()

	public function testARefusedCreateIsNotWrittenWhenItsRowIsDeletedBeforeTheUpdate(): void {
		[$register, $schema] = $this->target();
		$mapper = $this->mapper(taken: true);
		$this->rowQueue = [
			['_id' => 5, '_uuid' => self::UUID],
			['_id' => 5, '_uuid' => self::UUID, '_deleted' => ['deletedBy' => 'agent']],
		];

		try {
			$mapper->insert(entity: $this->entity('archived'), register: $register, schema: $schema);
			$this->fail('A refused create must not write over a row deleted before the update.');
		} catch (HookStoppedException $e) {
			$this->assertSame(self::NOT_INITIAL, $e->getMessage());
		}

		$this->assertSame([], $this->writes);
		$this->assertSame([ObjectCreatingEvent::class], $this->eventClasses());
	}//end testARefusedCreateIsNotWrittenWhenItsRowIsDeletedBeforeTheUpdate()

	public function testALostClaimStillUpdatesASoftDeletedRow(): void {
		[$register, $schema] = $this->target();
		$mapper = $this->mapper(taken: true);
		$this->storedDeleted = true;

		$mapper->insert(entity: $this->entity('closed'), register: $register, schema: $schema);

		$this->assertSame([true, false], $this->writes);
	}//end testALostClaimStillUpdatesASoftDeletedRow()

	public function testARefusedCreateIsNotWrittenWhenAnUpdatingListenerSoftDeletesItsRow(): void {
		[$register, $schema] = $this->target();
		$mapper = $this->mapper(taken: true);
		$this->onUpdating = function (): void {
			$this->deletedNow = true;
		};

		try {
			$mapper->insert(entity: $this->entity('archived'), register: $register, schema: $schema);
			$this->fail('A refused create must not write over a row deleted during its update.');
		} catch (HookStoppedException $e) {
			$this->assertSame(self::NOT_INITIAL, $e->getMessage());
		}

		$this->assertSame([], $this->writes);
	}//end testARefusedCreateIsNotWrittenWhenAnUpdatingListenerSoftDeletesItsRow()

	public function testARefusedCreateIsNotWrittenWhenAnUpdatingListenerHardDeletesItsRow(): void {
		[$register, $schema] = $this->target();
		$mapper = $this->mapper(taken: true);
		$this->onUpdating = function (): void {
			$this->taken = false;
		};

		try {
			$mapper->insert(entity: $this->entity('archived'), register: $register, schema: $schema);
			$this->fail('A refused create must not re-insert a row deleted during its update.');
		} catch (HookStoppedException $e) {
			$this->assertSame(self::NOT_INITIAL, $e->getMessage());
		}

		$this->assertSame([], $this->writes);
	}//end testARefusedCreateIsNotWrittenWhenAnUpdatingListenerHardDeletesItsRow()

	public function testALostClaimIsWrittenEvenWhenAnUpdatingListenerDeletesItsRow(): void {
		[$register, $schema] = $this->target();
		$mapper = $this->mapper(taken: true);
		$this->onUpdating = function (): void {
			$this->deletedNow = true;
		};

		$mapper->insert(entity: $this->entity('closed'), register: $register, schema: $schema);

		$this->assertSame([true, false], $this->writes);
	}//end testALostClaimIsWrittenEvenWhenAnUpdatingListenerDeletesItsRow()

	public function testALiveRowOnlyWriteEndsWithItsUpdate(): void {
		[$register, $schema] = $this->target();
		$mapper = $this->mapper(taken: true);
		$mapper->insert(entity: $this->entity('archived'), register: $register, schema: $schema);

		$this->writes     = [];
		$this->deletedNow = true;
		$mapper->insert(entity: $this->entity('closed'), register: $register, schema: $schema);

		$this->assertSame([true, false], $this->writes);
	}//end testALiveRowOnlyWriteEndsWithItsUpdate()

	/**
	 * An insert fills in what the object does not carry yet: register, schema and uuid.
	 *
	 * @return void
	 */
	public function testAnInsertFillsInRegisterSchemaAndUuid(): void {
		[$register, $schema] = $this->target();
		$entity = new ObjectEntity();
		$entity->setObject(['status' => 'open']);

		$inserted = $this->mapper(taken: false)->insertObjectEntity(entity: $entity, register: $register, schema: $schema);

		$this->assertSame('3', $inserted->getRegister());
		$this->assertSame('7', $inserted->getSchema());
		$this->assertNotEmpty($inserted->getUuid());
		$this->assertSame($inserted->getUuid(), $this->writtenData[0]['@self']['id']);
	}//end testAnInsertFillsInRegisterSchemaAndUuid()

	/**
	 * What the object already carries stays.
	 *
	 * @return void
	 */
	public function testAnInsertKeepsTheRegisterSchemaAndUuidItCarries(): void {
		[$register, $schema] = $this->target();
		$entity = $this->entity('open');
		$entity->setRegister('30');
		$entity->setSchema('70');

		$inserted = $this->mapper(taken: false)->insertObjectEntity(entity: $entity, register: $register, schema: $schema);

		$this->assertSame(['30', '70', self::UUID], [$inserted->getRegister(), $inserted->getSchema(), $inserted->getUuid()]);
	}//end testAnInsertKeepsTheRegisterSchemaAndUuidItCarries()

	/**
	 * A won insert takes the id the database generated.
	 *
	 * @return void
	 */
	public function testAWonInsertTakesTheGeneratedId(): void {
		[$register, $schema] = $this->target();

		$inserted = $this->mapper(taken: false)->insertObjectEntity(entity: $this->entity('open'), register: $register, schema: $schema);

		$this->assertSame(41, $inserted->getId());
	}//end testAWonInsertTakesTheGeneratedId()

	/**
	 * A lost insert reads the id of the row it became.
	 *
	 * @return void
	 */
	public function testALostInsertReadsTheStoredId(): void {
		[$register, $schema] = $this->target();

		$inserted = $this->mapper(taken: true)->insertObjectEntity(entity: $this->entity('open'), register: $register, schema: $schema);

		$this->assertSame(5, $inserted->getId());
	}//end testALostInsertReadsTheStoredId()

	/**
	 * A write that is not in the table afterwards is never reported as a success.
	 *
	 * @return void
	 */
	public function testAWriteThatIsGoneAfterwardsIsRefused(): void {
		[$register, $schema] = $this->target();
		$mapper = $this->mapper(taken: true);
		$this->goneAfterWrite = true;

		$this->expectExceptionMessage('is not there afterwards');

		$mapper->insertObjectEntity(entity: $this->entity('open'), register: $register, schema: $schema);
	}//end testAWriteThatIsGoneAfterwardsIsRefused()

	/**
	 * Without events the creating hooks stay silent.
	 *
	 * @return void
	 */
	public function testAnInsertWithoutEventsRunsNoHooks(): void {
		[$register, $schema] = $this->target();

		$this->mapper(taken: false)->insertObjectEntity(entity: $this->entity('open'), register: $register, schema: $schema, dispatchEvents: false);

		$this->assertSame([], $this->eventClasses());
	}//end testAnInsertWithoutEventsRunsNoHooks()
}//end class
