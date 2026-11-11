<?php

/**
 * BulkWriteEvents tests: the per-schema setting and the bulk save running the save events.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\BulkBatchDefaults;
use OCA\OpenRegister\Service\Object\BulkSaveOptions;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\Object\BulkRowChecks;
use OCA\OpenRegister\Service\Object\BulkRowRules;
use OCA\OpenRegister\Service\Object\BulkSaveReporting;
use OCA\OpenRegister\Service\Object\BulkSaveSession;
use OCA\OpenRegister\Service\Object\BulkSaveStores;
use OCA\OpenRegister\Service\Object\BulkWriteEvents;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Service\Object\BulkWriteEvents
 * @covers \OCA\OpenRegister\Service\Object\SaveObjects
 * @uses \OCA\OpenRegister\Service\Object\SaveObjects\BulkObjectDefaults
 * @uses \OCA\OpenRegister\Service\Object\SaveObjects\BulkObjectOwnership
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Event\ObjectCreatedEvent
 * @uses \OCA\OpenRegister\Event\AbstractPreWriteObjectEvent
 * @uses \OCA\OpenRegister\Event\ObjectCreatingEvent
 * @uses \OCA\OpenRegister\Event\ObjectUpdatedEvent
 * @uses \OCA\OpenRegister\Event\ObjectUpdatingEvent
 * @uses \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses \OCA\OpenRegister\Service\Object\BulkRowRules
 * @uses \OCA\OpenRegister\Service\Object\BulkSafeguardRun
 * @uses \OCA\OpenRegister\Service\Object\DraftCallerRule
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 * @uses \OCA\OpenRegister\Service\Object\ObjectEventGate
 * @uses \OCA\OpenRegister\Service\SystemOperationContext
 *
 * @spec openspec/changes/bulk-writes-fire-save-events/specs/object-lifecycle/spec.md
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\BulkObjectSlugs
 */
class BulkWriteEventsTest extends TestCase {

	private const CONFLICT = 'Panel member has a conflict of interest';

	private MagicMapper&MockObject $objectMapper;

	private RegisterMapper&MockObject $registerMapper;

	private IAppConfig&MockObject $appConfig;

	private IUserSession&MockObject $userSession;

	private IGroupManager&MockObject $groupManager;

	private PermissionHandler&MockObject $permissionHandler;

	private OrganisationService&MockObject $organisationService;

	/**
	 * Events in the order they were dispatched.
	 *
	 * @var list<object>
	 */
	private array $dispatched = [];

	/**
	 * The rows the bulk writer received.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * What the instance app config holds for the setting; '' is unset.
	 *
	 * @var string
	 */
	private string $instanceValue = '';

	protected function setUp(): void {
		parent::setUp();

		$this->objectMapper        = $this->createMock(MagicMapper::class);
		// The stored rows lie inside the caller's organisations.
		$this->objectMapper->method('callerOrganisationsHold')->willReturn(true);
		$this->appConfig           = $this->createMock(IAppConfig::class);
		$this->registerMapper      = $this->createMock(RegisterMapper::class);
		$this->userSession         = $this->createMock(IUserSession::class);
		$this->groupManager        = $this->createMock(IGroupManager::class);
		$this->permissionHandler   = $this->createMock(PermissionHandler::class);
		$this->organisationService = $this->createMock(OrganisationService::class);
		$this->dispatched          = [];
		$this->written             = [];
		$this->instanceValue       = '';

		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => (($app === 'openregister' && $key === 'fire_events_on_bulk_writes') ? $this->instanceValue : $default)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->permissionHandler->method('hasPermission')->willReturn(true);
		$this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);

		$ref = new ReflectionClass(SaveObjects::class);
		foreach (['schemaCache', 'schemaAnalysisCache', 'registerCache'] as $prop) {
			$property = $ref->getProperty($prop);
			$property->setAccessible(true);
			$property->setValue(null, []);
		}
	}//end setUp()

	/**
	 * A dispatcher that records every event and hands it to $listener first.
	 *
	 * @param callable|null $listener Called with each event before it is recorded.
	 *
	 * @return IEventDispatcher
	 */
	private function dispatcher(?callable $listener = null): IEventDispatcher {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event) use ($listener): void {
				if ($listener !== null) {
					$listener($event);
				}

				$this->dispatched[] = $event;
			}
		);

		return $dispatcher;
	}//end dispatcher()

	/**
	 * The policy under test, on the given dispatcher.
	 *
	 * @param IEventDispatcher $dispatcher The dispatcher.
	 *
	 * @return BulkWriteEvents
	 */
	private function policy(IEventDispatcher $dispatcher): BulkWriteEvents {
		return new BulkWriteEvents(events: $dispatcher, appConfig: $this->appConfig);
	}//end policy()

	/**
	 * The bulk save, wired as the container wires it.
	 *
	 * @param IEventDispatcher $dispatcher The dispatcher.
	 *
	 * @return SaveObjects
	 */
	private function saveObjects(IEventDispatcher $dispatcher): SaveObjects {
		return new SaveObjects(
			stores: new BulkSaveStores(catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->registerMapper, schemas: $this->createMock(SchemaMapper::class), tables: $this->objectMapper), saveHandler: $this->createMock(SaveObject::class)),
			session: new BulkSaveSession(userSession: $this->userSession, organisationService: $this->organisationService, groupManager: $this->groupManager),
			reporting: new BulkSaveReporting(logger: $this->createMock(LoggerInterface::class), eventDispatcher: $dispatcher, auditTrailMapper: $this->createMock(AuditTrailMapper::class)),
			checks: new BulkRowChecks(permissionHandler: $this->permissionHandler, rowRules: new BulkRowRules(bulkWriteEvents: $this->policy($dispatcher)))
		);
	}//end saveObjects()

	/**
	 * A schema with an id and the given configuration.
	 *
	 * @param array $configuration The configuration block.
	 *
	 * @return Schema
	 */
	private function schema(array $configuration = []): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('advice-request');
		$schema->setTitle('Advice request');
		$schema->setProperties([]);
		$schema->setConfiguration($configuration);
		return $schema;
	}//end schema()

	/**
	 * A register with an id and the given configuration.
	 *
	 * @param array $configuration The configuration block.
	 *
	 * @return Register
	 */
	private function register(array $configuration = []): Register {
		$register = new Register();
		$register->setId(3);
		$register->setTitle('Advice');
		$register->setConfiguration($configuration);
		return $register;
	}//end register()

	/**
	 * A stored object.
	 *
	 * @param string $uuid   Its uuid.
	 * @param string $status Its stored status.
	 *
	 * @return ObjectEntity
	 */
	private function stored(string $uuid, string $status): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setId(100);
		$entity->setUuid($uuid);
		$entity->setRegister('3');
		$entity->setSchema('7');
		$entity->setOwner('alice');
		$entity->setObject(['title' => 'stored ' . $uuid, 'status' => $status]);
		return $entity;
	}//end stored()

	/**
	 * Let the writer accept every row and answer with the given verdict.
	 *
	 * @param string $verdict `created` or `updated`.
	 *
	 * @return void
	 */
	private function writerAnswers(string $verdict): void {
		$this->objectMapper->method('ultraFastBulkSave')->willReturnCallback(
			function (array $insertObjects, array $_updateObjects = [], ?Register $_register = null, ?Schema $_schema = null, bool $needsPreUpdateState = true) use ($verdict): array {
				$this->written = array_merge($this->written, $insertObjects);
				return array_map(
					static function (array $row) use ($verdict, $needsPreUpdateState): array {
						$out = [
							'_uuid' => $row['uuid'],
							'_register' => 3,
							'_schema' => 7,
							'title' => ($row['object']['title'] ?? null),
							'object_status' => $verdict,
						];
						if ($verdict === 'updated' && $needsPreUpdateState === true) {
							$out['_pre_update_row'] = ['_uuid' => $row['uuid'], '_register' => 3, '_schema' => 7, 'title' => 'stored ' . $row['uuid']];
						}

						return $out;
					},
					$insertObjects
				);
			}
		);
		$this->objectMapper->method('convertRowToObjectEntity')->willReturnCallback(
			static function (array $row): ObjectEntity {
				$entity = new ObjectEntity();
				$entity->setUuid($row['_uuid']);
				$entity->setRegister('3');
				$entity->setSchema('7');
				$entity->setObject(['title' => $row['title']]);
				return $entity;
			}
		);
	}//end writerAnswers()

	/**
	 * Let the register mapper answer for register 3 (the route's) and register 9 (another one).
	 *
	 * @param array $otherConfiguration Register 9's configuration.
	 *
	 * @return void
	 */
	private function twoRegisters(array $otherConfiguration): void {
		$other = $this->register($otherConfiguration);
		$other->setId(9);
		$this->registerMapper->method('find')->willReturnCallback(
			fn (mixed $id): Register => ((string)$id === '9' ? $other : $this->register())
		);
	}//end twoRegisters()

	/**
	 * A listener that refuses a move into deliberation, the way an app guard does.
	 *
	 * @return callable
	 */
	private function deliberationGuard(): callable {
		return static function (object $event): void {
			if ($event instanceof ObjectUpdatingEvent && (($event->getNewObject()->getObject()['status'] ?? null) === 'in-deliberation')) {
				$event->setErrors(['message' => self::CONFLICT]);
				$event->stopPropagation();
			}
		};
	}//end deliberationGuard()

	/**
	 * Two stored objects, one moving into deliberation and one closing.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function twoUpdates(): array {
		$this->objectMapper->method('findObjectsByUuidsInRegisterSchema')->willReturnCallback(
			fn (Register $register): array => ((string)$register->getId() !== '3') ? [] : [
				'u-1' => $this->stored(uuid: 'u-1', status: 'open'),
				'u-2' => $this->stored(uuid: 'u-2', status: 'open'),
			]
		);

		return [
			['@self' => ['id' => 'u-1'], 'title' => 'one', 'status' => 'in-deliberation'],
			['@self' => ['id' => 'u-2'], 'title' => 'two', 'status' => 'closed'],
		];
	}//end twoUpdates()

	/**
	 * The uuids the bulk writer received.
	 *
	 * @return list<string>
	 */
	private function writtenUuids(): array {
		return array_map(static fn (array $row): string => (string)$row['uuid'], $this->written);
	}//end writtenUuids()

	/**
	 * The events of one class, in order.
	 *
	 * @param string $class The event class.
	 *
	 * @return list<object>
	 */
	private function eventsOf(string $class): array {
		return array_values(array_filter($this->dispatched, static fn (object $e): bool => $e instanceof $class));
	}//end eventsOf()

	public function testASchemaWithoutTheSettingFiresNothing(): void {
		$policy = $this->policy($this->dispatcher());

		$this->assertFalse($policy->appliesTo(schema: $this->schema(), register: $this->register()));
		$this->assertFalse($policy->appliesTo(schema: $this->schema(), register: null));
	}//end testASchemaWithoutTheSettingFiresNothing()

	public function testALifecycleSchemaFiresByDefault(): void {
		$schema = $this->schema(['x-openregister-lifecycle' => ['field' => 'status', 'initial' => 'open']]);

		$this->assertTrue($this->policy($this->dispatcher())->appliesTo(schema: $schema, register: $this->register()));
	}//end testALifecycleSchemaFiresByDefault()

	public function testTheSchemaValueWins(): void {
		$this->instanceValue = 'true';
		$policy = $this->policy($this->dispatcher());

		$this->assertFalse(
			$policy->appliesTo(
				schema: $this->schema([BulkWriteEvents::CONFIG_KEY => false, 'x-openregister-lifecycle' => ['field' => 'status']]),
				register: $this->register([BulkWriteEvents::CONFIG_KEY => true])
			)
		);
		$this->assertTrue($policy->appliesTo(schema: $this->schema([BulkWriteEvents::CONFIG_KEY => true]), register: $this->register([BulkWriteEvents::CONFIG_KEY => false])));
	}//end testTheSchemaValueWins()

	public function testTheRegisterValueWinsOverTheInstance(): void {
		$this->instanceValue = 'false';
		$policy = $this->policy($this->dispatcher());

		$this->assertTrue($policy->appliesTo(schema: $this->schema(), register: $this->register([BulkWriteEvents::CONFIG_KEY => true])));

		$this->instanceValue = 'true';
		$this->assertFalse($policy->appliesTo(schema: $this->schema(), register: $this->register([BulkWriteEvents::CONFIG_KEY => false])));
	}//end testTheRegisterValueWinsOverTheInstance()

	public function testTheInstanceValueDecidesWhenNothingElseIsSet(): void {
		$policy = $this->policy($this->dispatcher());
		$lifecycle = $this->schema(['x-openregister-lifecycle' => ['field' => 'status']]);

		$this->instanceValue = 'true';
		$this->assertTrue($policy->appliesTo(schema: $this->schema(), register: null));

		$this->instanceValue = 'false';
		$this->assertFalse($policy->appliesTo(schema: $lifecycle, register: $this->register()));

		$this->instanceValue = 'something else';
		$this->assertTrue($policy->appliesTo(schema: $lifecycle, register: $this->register()));
		$this->assertFalse($policy->appliesTo(schema: $this->schema(), register: $this->register()));
	}//end testTheInstanceValueDecidesWhenNothingElseIsSet()

	public function testANonBooleanValueIsNotADecision(): void {
		$this->instanceValue = 'false';
		$register = new Register();
		$register->setId(3);
		$register->setConfiguration([BulkWriteEvents::CONFIG_KEY => 'yes']);

		$this->assertFalse($this->policy($this->dispatcher())->appliesTo(schema: $this->schema(), register: $register));
	}//end testANonBooleanValueIsNotADecision()

	public function testTheSchemaKeepsTheSetting(): void {
		$schema = $this->schema([BulkWriteEvents::CONFIG_KEY => true]);

		$this->assertSame(true, ($schema->getConfiguration()[BulkWriteEvents::CONFIG_KEY] ?? null));
	}//end testTheSchemaKeepsTheSetting()

	public function testAStoppedUpdateRefusesOnlyThatRow(): void {
		$rows = $this->twoUpdates();
		$this->writerAnswers('updated');

		$result = $this->saveObjects($this->dispatcher($this->deliberationGuard()))->saveObjects(
			$rows,
			new BulkSaveOptions(new BulkBatchDefaults($this->register(), $this->schema([BulkWriteEvents::CONFIG_KEY => true])), validation: false, events: false, audit: true)
		);

		$this->assertSame(['u-2'], $this->writtenUuids());
		$this->assertCount(1, $result['invalid']);
		$this->assertSame(self::CONFLICT, $result['invalid'][0]['error']);
		$this->assertSame(0, $result['invalid'][0]['index']);
		$this->assertSame(1, $result['statistics']['updated']);

		$updating = $this->eventsOf(ObjectUpdatingEvent::class);
		$this->assertCount(2, $updating);
		$this->assertSame('open', $updating[0]->getOldObject()?->getObject()['status']);
		$this->assertSame('u-1', $updating[0]->getNewObject()->getUuid());
		$this->assertSame(100, $updating[0]->getNewObject()->getId());
		$this->assertSame('alice', $updating[0]->getNewObject()->getOwner());
		$this->assertSame('in-deliberation', $updating[0]->getNewObject()->getObject()['status']);

		$updated = $this->eventsOf(ObjectUpdatedEvent::class);
		$this->assertCount(1, $updated);
		$this->assertSame('u-2', $updated[0]->getNewObject()->getUuid());
	}//end testAStoppedUpdateRefusesOnlyThatRow()

	public function testTheUpdatedEventCarriesTheStoredObject(): void {
		$rows = $this->twoUpdates();
		$this->writerAnswers('updated');

		$this->saveObjects($this->dispatcher())->saveObjects(
			$rows,
			new BulkSaveOptions(new BulkBatchDefaults($this->register(), $this->schema([BulkWriteEvents::CONFIG_KEY => true])), validation: false, events: false, audit: false)
		);

		$updated = $this->eventsOf(ObjectUpdatedEvent::class);
		$this->assertCount(2, $updated);
		$this->assertSame('stored u-1', $updated[0]->getOldObject()?->getObject()['title']);
		$this->assertSame('one', $updated[0]->getNewObject()->getObject()['title']);
	}//end testTheUpdatedEventCarriesTheStoredObject()

	public function testARowNamingAnotherRegisterIsScreenedAgainstTheRoute(): void {
		$rows = $this->twoUpdates();
		$rows[0]['@self']['register'] = '9';
		$this->twoRegisters([]);
		$this->writerAnswers('updated');

		$result = $this->saveObjects($this->dispatcher($this->deliberationGuard()))->saveObjects(
			$rows,
			new BulkSaveOptions(new BulkBatchDefaults($this->register(), $this->schema([BulkWriteEvents::CONFIG_KEY => true])), validation: false, events: false, audit: true)
		);

		$this->assertSame(['u-2'], $this->writtenUuids());
		$this->assertSame(self::CONFLICT, $result['invalid'][0]['error']);
		$this->assertSame([], $this->eventsOf(ObjectCreatingEvent::class));
		$this->assertSame('open', $this->eventsOf(ObjectUpdatingEvent::class)[0]->getOldObject()?->getObject()['status']);
	}//end testARowNamingAnotherRegisterIsScreenedAgainstTheRoute()

	public function testAnotherRegistersSettingDoesNotDecide(): void {
		$rows = $this->twoUpdates();
		$rows[0]['@self']['register'] = '9';
		$this->twoRegisters([BulkWriteEvents::CONFIG_KEY => false]);
		$this->writerAnswers('updated');

		$result = $this->saveObjects($this->dispatcher($this->deliberationGuard()))->saveObjects(
			$rows,
			new BulkSaveOptions(new BulkBatchDefaults($this->register([BulkWriteEvents::CONFIG_KEY => true]), $this->schema()), validation: false, events: false, audit: true)
		);

		$this->assertSame(['u-2'], $this->writtenUuids());
		$this->assertSame(self::CONFLICT, $result['invalid'][0]['error']);
	}//end testAnotherRegistersSettingDoesNotDecide()

	public function testARowNamingAnotherRegisterGetsItsUpdatedEvent(): void {
		$rows = $this->twoUpdates();
		$row = $rows[0];
		$row['@self']['register'] = '9';
		$row['status'] = 'closed';
		$this->twoRegisters([]);
		$this->writerAnswers('updated');

		$this->saveObjects($this->dispatcher())->saveObjects(
			[$row],
			new BulkSaveOptions(new BulkBatchDefaults($this->register(), $this->schema([BulkWriteEvents::CONFIG_KEY => true])), validation: false, events: false, audit: true)
		);

		$this->assertSame(['u-1'], $this->writtenUuids());
		$this->assertCount(1, $this->eventsOf(ObjectUpdatingEvent::class));
		$updated = $this->eventsOf(ObjectUpdatedEvent::class);
		$this->assertSame(['u-1'], array_map(static fn (ObjectUpdatedEvent $e): string => (string)$e->getNewObject()->getUuid(), $updated));
	}//end testARowNamingAnotherRegisterGetsItsUpdatedEvent()

	public function testARowNamingAnotherRegisterGetsItsCreatedEvent(): void {
		$this->objectMapper->method('findObjectsByUuidsInRegisterSchema')->willReturn([]);
		$this->twoRegisters([BulkWriteEvents::CONFIG_KEY => false]);
		$this->writerAnswers('created');

		$this->saveObjects($this->dispatcher())->saveObjects(
			[['@self' => ['register' => '9'], 'title' => 'imported']],
			new BulkSaveOptions(new BulkBatchDefaults($this->register([BulkWriteEvents::CONFIG_KEY => true]), $this->schema()), validation: false, events: false, audit: true)
		);

		$this->assertCount(1, $this->written);
		$this->assertCount(1, $this->eventsOf(ObjectCreatingEvent::class));
		$this->assertCount(1, $this->eventsOf(ObjectCreatedEvent::class));
	}//end testARowNamingAnotherRegisterGetsItsCreatedEvent()

	public function testAnotherRegistersSettingDoesNotSwitchOnThePostStep(): void {
		$this->objectMapper->method('findObjectsByUuidsInRegisterSchema')->willReturn([]);
		$this->twoRegisters([BulkWriteEvents::CONFIG_KEY => true]);
		$this->writerAnswers('created');

		$this->saveObjects($this->dispatcher())->saveObjects(
			[['@self' => ['register' => '9'], 'title' => 'imported']],
			new BulkSaveOptions(new BulkBatchDefaults($this->register([BulkWriteEvents::CONFIG_KEY => false]), $this->schema()), validation: false, events: false, audit: true)
		);

		$this->assertCount(1, $this->written);
		$this->assertSame([], $this->dispatched);
	}//end testAnotherRegistersSettingDoesNotSwitchOnThePostStep()

	public function testARowNamingTheRouteRegisterIsUnchanged(): void {
		$rows = $this->twoUpdates();
		$rows[0]['@self']['register'] = '3';
		$this->twoRegisters([]);
		$this->writerAnswers('updated');

		$result = $this->saveObjects($this->dispatcher($this->deliberationGuard()))->saveObjects(
			$rows,
			new BulkSaveOptions(new BulkBatchDefaults($this->register(), $this->schema([BulkWriteEvents::CONFIG_KEY => true])), validation: false, events: false, audit: true)
		);

		$this->assertSame(['u-2'], $this->writtenUuids());
		$this->assertSame(self::CONFLICT, $result['invalid'][0]['error']);
		$this->assertCount(2, $this->eventsOf(ObjectUpdatingEvent::class));
	}//end testARowNamingTheRouteRegisterIsUnchanged()

	public function testCallerEventsAndTheSettingDispatchOnce(): void {
		$rows = $this->twoUpdates();
		$this->writerAnswers('updated');

		$this->saveObjects($this->dispatcher())->saveObjects(
			$rows,
			new BulkSaveOptions(new BulkBatchDefaults($this->register(), $this->schema([BulkWriteEvents::CONFIG_KEY => true])), validation: false, events: true, audit: true)
		);

		$this->assertCount(2, $this->eventsOf(ObjectUpdatingEvent::class));
		$this->assertCount(2, $this->eventsOf(ObjectUpdatedEvent::class));
	}//end testCallerEventsAndTheSettingDispatchOnce()

	public function testASystemOperationRunsTheGuardButWithholdsTheUpdatedEvents(): void {
		$rows = $this->twoUpdates();
		$this->writerAnswers('updated');
		$service = $this->saveObjects($this->dispatcher($this->deliberationGuard()));

		$result = SystemOperationContext::run(
			fn (): array => $service->saveObjects(
				$rows,
				new BulkSaveOptions(new BulkBatchDefaults($this->register(), $this->schema([BulkWriteEvents::CONFIG_KEY => true])), validation: false, events: false, audit: true)
			)
		);

		$this->assertSame(['u-2'], $this->writtenUuids());
		$this->assertSame(self::CONFLICT, $result['invalid'][0]['error']);
		$this->assertCount(2, $this->eventsOf(ObjectUpdatingEvent::class));
		$this->assertSame([], $this->eventsOf(ObjectUpdatedEvent::class));
	}//end testASystemOperationRunsTheGuardButWithholdsTheUpdatedEvents()

	public function testALifecycleSchemaRunsTheGuardWithoutASetting(): void {
		$rows = $this->twoUpdates();
		$this->writerAnswers('updated');

		$result = $this->saveObjects($this->dispatcher($this->deliberationGuard()))->saveObjects(
			$rows,
			new BulkSaveOptions(new BulkBatchDefaults($this->register(), $this->schema(['x-openregister-lifecycle' => ['field' => 'status']])), validation: false, events: false, audit: true)
		);

		$this->assertSame(['u-2'], $this->writtenUuids());
		$this->assertSame(self::CONFLICT, $result['invalid'][0]['error']);
	}//end testALifecycleSchemaRunsTheGuardWithoutASetting()

	public function testARegisterSettingFiresTheEvents(): void {
		$rows = $this->twoUpdates();
		$this->writerAnswers('updated');

		$result = $this->saveObjects($this->dispatcher())->saveObjects(
			$rows,
			new BulkSaveOptions(new BulkBatchDefaults($this->register([BulkWriteEvents::CONFIG_KEY => true]), $this->schema()), validation: false, events: false, audit: true)
		);

		$this->assertSame(['u-1', 'u-2'], $this->writtenUuids());
		$this->assertSame(2, $result['statistics']['updated']);
		$this->assertCount(2, $this->eventsOf(ObjectUpdatingEvent::class));
		$this->assertCount(2, $this->eventsOf(ObjectUpdatedEvent::class));
	}//end testARegisterSettingFiresTheEvents()

	public function testEventsOffWritesEveryRow(): void {
		$rows = $this->twoUpdates();
		$this->writerAnswers('updated');

		$result = $this->saveObjects($this->dispatcher($this->deliberationGuard()))->saveObjects(
			$rows,
			new BulkSaveOptions(new BulkBatchDefaults($this->register(), $this->schema([BulkWriteEvents::CONFIG_KEY => false, 'x-openregister-lifecycle' => ['field' => 'status']])), validation: false, events: false, audit: true)
		);

		$this->assertSame(['u-1', 'u-2'], $this->writtenUuids());
		$this->assertSame([], $result['invalid']);
		$this->assertSame([], $this->eventsOf(ObjectUpdatingEvent::class));
		$this->assertSame([], $this->eventsOf(ObjectUpdatedEvent::class));
	}//end testEventsOffWritesEveryRow()

	public function testACreatingHookValueIsWritten(): void {
		$this->objectMapper->method('findObjectsByUuidsInRegisterSchema')->willReturn([]);
		$this->writerAnswers('created');
		$numbering = static function (object $event): void {
			if ($event instanceof ObjectCreatingEvent) {
				$event->setModifiedData(['number' => 'ADV-1']);
				$data = $event->getObject()->getObject();
				$data['stamped'] = 'by-listener';
				$event->getObject()->setObject($data);
			}
		};

		$result = $this->saveObjects($this->dispatcher($numbering))->saveObjects(
			[['title' => 'new request']],
			new BulkSaveOptions(new BulkBatchDefaults($this->register(), $this->schema([BulkWriteEvents::CONFIG_KEY => true])), validation: false, events: false, audit: true)
		);

		$this->assertCount(1, $this->written);
		$this->assertSame('ADV-1', $this->written[0]['object']['number']);
		$this->assertSame('by-listener', $this->written[0]['object']['stamped']);
		$this->assertSame('new request', $this->written[0]['object']['title']);
		$this->assertSame(1, $result['statistics']['saved']);

		$creating = $this->eventsOf(ObjectCreatingEvent::class);
		$this->assertCount(1, $creating);
		$this->assertSame($this->written[0]['uuid'], $creating[0]->getObject()->getUuid());

		$classes = array_map(static fn (object $e): string => $e::class, $this->dispatched);
		$this->assertSame([ObjectCreatingEvent::class, ObjectCreatedEvent::class], $classes);
	}//end testACreatingHookValueIsWritten()

	public function testAStoppedCreateIsNotWritten(): void {
		$this->objectMapper->method('findObjectsByUuidsInRegisterSchema')->willReturn([]);
		$this->writerAnswers('created');
		$refuse = static function (object $event): void {
			if ($event instanceof ObjectCreatingEvent && ($event->getObject()->getObject()['title'] ?? null) === 'refuse') {
				$event->stopPropagation();
			}
		};

		$result = $this->saveObjects($this->dispatcher($refuse))->saveObjects(
			[['title' => 'refuse'], ['title' => 'keep']],
			new BulkSaveOptions(new BulkBatchDefaults($this->register(), $this->schema([BulkWriteEvents::CONFIG_KEY => true])), validation: false, events: false, audit: true)
		);

		$this->assertCount(1, $this->written);
		$this->assertSame('keep', $this->written[0]['object']['title']);
		$this->assertSame('Object creation rejected by hook', $result['invalid'][0]['error']);
		$this->assertSame(0, $result['invalid'][0]['index']);
	}//end testAStoppedCreateIsNotWritten()
}//end class
