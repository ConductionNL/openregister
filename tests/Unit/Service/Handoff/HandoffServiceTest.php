<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Handoff\HandoffService}.
 *
 * Covers the engine contract: happy-path execution (target create +
 * provenance relations both ways + one audit row per side + onSuccess.set +
 * post-commit event), `handoff-not-declared`, hide-mode degradation
 * (typed provider-unavailable, no writes), queue-mode parking, RBAC refusal
 * without escalation (typed 403 before any write), atomic rollback on
 * target-create failure, and queue drain semantics (skip-unresolvable,
 * failed-permission on drain with requester notification, deferred success).
 *
 * Uses a REAL SemanticTypeResolver (wired to mocked mappers) so provider
 * resolution — including the complete-binding filter — is exercised end to
 * end rather than stubbed.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Handoff
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Handoff;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\HandoffQueueEntry;
use OCA\OpenRegister\Db\HandoffQueueEntryMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\HandoffExecutedEvent;
use OCA\OpenRegister\Exception\HandoffException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Handoff\HandoffLedger;
use OCA\OpenRegister\Service\Handoff\HandoffMappingEvaluator;
use OCA\OpenRegister\Service\Handoff\HandoffObjectAccess;
use OCA\OpenRegister\Service\Handoff\HandoffProviders;
use OCA\OpenRegister\Service\Handoff\HandoffRequesters;
use OCA\OpenRegister\Service\Handoff\HandoffService;
use OCA\OpenRegister\Service\Handoff\HandoffSourceRef;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\SemanticTypeResolver;
use OCA\OpenRegister\Tests\Support\AuditTrailEvents;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * HandoffServiceTest.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The engine composes many
 *   shipped OR capabilities; its test wires the same surface.
 */
class HandoffServiceTest extends TestCase {

	private const CASE_URI = 'https://openregister.app/ns#Case';

	private ObjectService&MockObject $objectService;

	private SchemaMapper&MockObject $schemaMapper;

	private RegisterMapper&MockObject $registerMapper;

	private MagicMapper&MockObject $objectMapper;

	private AuditTrailMapper&MockObject $auditTrailMapper;

	private PermissionHandler&MockObject $permissionHandler;

	private HandoffQueueEntryMapper&MockObject $queueMapper;

	private IDBConnection&MockObject $db;

	private IEventDispatcher&MockObject $eventDispatcher;

	private IUserSession&MockObject $userSession;

	private IUserManager&MockObject $userManager;

	private INotificationManager&MockObject $notificationManager;

	private HandoffService $service;

	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->permissionHandler = $this->createMock(PermissionHandler::class);
		$this->queueMapper = $this->createMock(HandoffQueueEntryMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(true);
		$appManager->method('isInstalled')->willReturn(true);

		$resolver = new SemanticTypeResolver(
			catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->registerMapper, schemas: $this->schemaMapper, tables: $this->createMock(\OCA\OpenRegister\Db\MagicMapperInterface::class)),
			jsonLdContextService: new JsonLdContextService($this->createMock(IURLGenerator::class)),
			logger: $this->createMock(LoggerInterface::class),
			appManager: $appManager
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->service = new HandoffService(
			objects: new HandoffObjectAccess(
				objectService: $this->objectService,
				schemaMapper: $this->schemaMapper,
				objectMapper: $this->objectMapper,
				permissionHandler: $this->permissionHandler,
			),
			providers: new HandoffProviders(
				semanticTypeResolver: $resolver,
				mappingEvaluator: new HandoffMappingEvaluator(),
			),
			ledger: new HandoffLedger(
				auditTrailMapper: $this->auditTrailMapper,
				queueMapper: $this->queueMapper,
				db: $this->db,
				logger: $this->createMock(LoggerInterface::class),
			),
			requesters: new HandoffRequesters(
				userSession: $this->userSession,
				userManager: $this->userManager,
				notificationManager: $this->notificationManager,
				eventDispatcher: $this->eventDispatcher,
			),
		);

	}//end setUp()

	/**
	 * Happy path: target created via the binding, provenance relations both
	 * ways, one audit row per side, onSuccess.set applied, event post-commit.
	 *
	 * @return void
	 */
	public function testExecuteHappyPath(): void {
		$this->wireSourceSchema();
		$this->wireProvider();
		$source = $this->sourceObject();
		$this->objectService->method('find')->willReturn($source);

		$target = $this->targetObject();
		$savedSourceData = null;
		$savedTargetData = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (
				$object,
				$extend = [],
				$register = null,
				$schema = null,
				$uuid = null,
			) use ($target, $source, &$savedSourceData, &$savedTargetData) {
				if ($uuid === 'src-uuid') {
					$savedSourceData = $object;
					return $source;
				}

				$savedTargetData = $object;
				return $target;
			}
		);

		$rawEntities = [
			'src-uuid' => $this->rawEntity('src-uuid'),
			'tgt-uuid' => $this->rawEntity('tgt-uuid'),
		];
		$this->objectMapper->method('find')->willReturnCallback(
			static fn ($identifier) => $rawEntities[$identifier]
		);

		$updatedRelations = [];
		$this->objectMapper->method('update')->willReturnCallback(
			function (ObjectEntity $entity) use (&$updatedRelations) {
				$updatedRelations[(string)$entity->getUuid()] = $entity->getRelations();
				return $entity;
			}
		);

		$auditActions = [];
		$this->auditTrailMapper->method('recordObjectEvent')->willReturnCallback(AuditTrailEvents::positional(
			function (ObjectEntity $object, string $action, array $context = []) use (&$auditActions) {
				$auditActions[] = [
					'uuid' => (string)$object->getUuid(),
					'action' => $action,
					'context' => $context,
				];
				return $this->createMock(\OCA\OpenRegister\Db\AuditTrail::class);
			}
		));

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$dispatched = null;
		$this->eventDispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event) use (&$dispatched) {
				$dispatched = $event;
			}
		);

		$result = $this->service->execute(register: '7', schema: '12', id: 'src-uuid', handoffId: 'request-to-case');

		// Result shape.
		$this->assertSame('executed', $result['status']);
		$this->assertSame('tgt-uuid', $result['target']['uuid']);
		$this->assertNotEmpty($result['correlationId']);

		// Target populated through the provider's binding (contract field →
		// own property), semantic ref carried as UUID, provenance filled.
		$this->assertSame('Kapotte lantaarnpaal', $savedTargetData['onderwerp']);
		$this->assertSame('11111111-2222-3333-4444-555555555555', $savedTargetData['aanvrager']);
		$this->assertSame('telefoon', $savedTargetData['kanaal']);
		$this->assertSame('src-uuid', $savedTargetData['herkomst']['uuid']);
		$this->assertSame('pipelinq', $savedTargetData['herkomst']['app']);

		// onSuccess.set applied to the source through the write path.
		$this->assertSame('handed-off', $savedSourceData['status']);

		// Provenance relations both ways.
		$this->assertSame('tgt-uuid', $updatedRelations['src-uuid']['handoff:request-to-case:handed-off-to']);
		$this->assertSame('src-uuid', $updatedRelations['tgt-uuid']['handoff:request-to-case:originated-from']);

		// One immutable audit row per side.
		$this->assertCount(2, $auditActions);
		$this->assertSame(HandoffService::AUDIT_EXECUTED, $auditActions[0]['action']);
		$this->assertSame(HandoffService::AUDIT_EXECUTED, $auditActions[1]['action']);
		$this->assertSame('src-uuid', $auditActions[0]['uuid']);
		$this->assertSame('tgt-uuid', $auditActions[1]['uuid']);
		$this->assertSame($result['correlationId'], $auditActions[0]['context']['correlationId']);

		// Post-commit ADR-041 event with full provenance.
		$this->assertInstanceOf(HandoffExecutedEvent::class, $dispatched);
		$this->assertSame('pipelinq', $dispatched->getSourceApp());
		$this->assertSame('src-uuid', $dispatched->getSourceObjectUuid());
		$this->assertSame('tgt-uuid', $dispatched->getTargetObjectUuid());
		$this->assertSame(self::CASE_URI, $dispatched->getTargetSemanticType());
		$this->assertSame('request-to-case', $dispatched->getHandoffId());
		$this->assertSame($result['correlationId'], $dispatched->getCorrelationId());
		$this->assertFalse($dispatched->isDeferred());

	}//end testExecuteHappyPath()

	/**
	 * Unknown handoff id → typed handoff-not-declared, no writes.
	 *
	 * @return void
	 */
	public function testExecuteNotDeclared(): void {
		$this->wireSourceSchema();
		$this->objectService->method('find')->willReturn($this->sourceObject());
		$this->db->expects($this->never())->method('beginTransaction');

		try {
			$this->service->execute(register: '7', schema: '12', id: 'src-uuid', handoffId: 'ghost');
			$this->fail('Expected HandoffException');
		} catch (HandoffException $e) {
			$this->assertSame(HandoffException::NOT_DECLARED, $e->getErrorCode());
		}

	}//end testExecuteNotDeclared()

	/**
	 * Hide mode without a provider → typed provider-unavailable (409-class,
	 * never 5xx), nothing parked, nothing written.
	 *
	 * @return void
	 */
	public function testHideModeDegradesWithTypedError(): void {
		$this->wireSourceSchema();
		// No provider schemas installed at all.
		$this->schemaMapper->method('findAll')->willReturn([]);
		$this->objectService->method('find')->willReturn($this->sourceObject());

		$this->queueMapper->expects($this->never())->method('insert');
		$this->db->expects($this->never())->method('beginTransaction');

		try {
			$this->service->execute(register: '7', schema: '12', id: 'src-uuid', handoffId: 'request-to-case');
			$this->fail('Expected HandoffException');
		} catch (HandoffException $e) {
			$this->assertSame(HandoffException::PROVIDER_UNAVAILABLE, $e->getErrorCode());
		}

	}//end testHideModeDegradesWithTypedError()

	/**
	 * Queue mode without a provider → durable park + handoff.queued audit +
	 * parked result; no target creation.
	 *
	 * @return void
	 */
	public function testQueueModeParks(): void {
		$this->wireSourceSchema(whenUnavailable: 'queue');
		$this->schemaMapper->method('findAll')->willReturn([]);
		$source = $this->sourceObject();
		$this->objectService->method('find')->willReturn($source);

		$this->queueMapper->expects($this->once())->method('insert')->willReturnCallback(
			static function (HandoffQueueEntry $entry) {
				$entry->setId(101);
				return $entry;
			}
		);

		$auditActions = [];
		$this->auditTrailMapper->method('recordObjectEvent')->willReturnCallback(AuditTrailEvents::positional(
			function (ObjectEntity $object, string $action, array $context = []) use (&$auditActions) {
				$auditActions[] = $action;
				return $this->createMock(\OCA\OpenRegister\Db\AuditTrail::class);
			}
		));

		$this->db->expects($this->never())->method('beginTransaction');

		$result = $this->service->execute(register: '7', schema: '12', id: 'src-uuid', handoffId: 'request-to-case');

		$this->assertSame('parked', $result['status']);
		$this->assertSame('parked', $result['queueEntry']['status']);
		$this->assertSame('alice', $result['queueEntry']['requestingUser']);
		$this->assertSame(self::CASE_URI, $result['queueEntry']['targetKind']);
		$this->assertSame([HandoffService::AUDIT_QUEUED], $auditActions);

	}//end testQueueModeParks()

	/**
	 * RBAC denies create on the resolved target schema → typed 403 BEFORE
	 * any write, never escalated.
	 *
	 * @return void
	 */
	public function testCreateRefusalHappensBeforeAnyWrite(): void {
		$this->wireSourceSchema();
		$this->wireProvider();
		$this->objectService->method('find')->willReturn($this->sourceObject());

		$this->permissionHandler->method('checkPermission')->willReturnCallback(
			static function (Schema $schema, string $action, ...$rest): void {
				if ($action === 'create') {
					throw new NotAuthorizedException(message: 'create denied');
				}
			}
		);

		$this->db->expects($this->never())->method('beginTransaction');
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(NotAuthorizedException::class);
		$this->service->execute(register: '7', schema: '12', id: 'src-uuid', handoffId: 'request-to-case');

	}//end testCreateRefusalHappensBeforeAnyWrite()

	/**
	 * Target-create failure mid-handoff → no relations, no audit, no source
	 * mutation, nothing to compensate; the underlying error surfaces.
	 *
	 * @return void
	 */
	public function testAtomicRollbackOnTargetCreateFailure(): void {
		$this->wireSourceSchema();
		$this->wireProvider();
		$this->objectService->method('find')->willReturn($this->sourceObject());

		$this->objectService->method('saveObject')->willThrowException(
			new ValidationException(message: 'target schema rejected the object')
		);

		$this->db->expects($this->never())->method('beginTransaction');
		$this->db->expects($this->never())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->objectMapper->expects($this->never())->method('update');
		$this->objectMapper->expects($this->never())->method('delete');
		$this->auditTrailMapper->expects($this->never())->method('recordObjectEvent');
		$this->eventDispatcher->expects($this->never())->method('dispatchTyped');

		$this->expectException(ValidationException::class);
		$this->service->execute(register: '7', schema: '12', id: 'src-uuid', handoffId: 'request-to-case');

	}//end testAtomicRollbackOnTargetCreateFailure()

	/**
	 * A relations/audit failure AFTER the target was created rolls the
	 * transaction back and COMPENSATES: the created target is removed and the
	 * source's pre-handoff data is restored — no partial state survives.
	 *
	 * @return void
	 */
	public function testCompensationRemovesTargetOnLateFailure(): void {
		$this->wireSourceSchema();
		$this->wireProvider();
		$source = $this->sourceObject();
		$this->objectService->method('find')->willReturn($source);

		$target = $this->targetObject();
		$sourceSaves = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			static function ($object, $extend = [], $register = null, $schema = null, $uuid = null) use ($target, $source, &$sourceSaves) {
				if ($uuid === 'src-uuid') {
					$sourceSaves[] = $object;
					return $source;
				}

				return $target;
			}
		);

		$rawEntities = [
			'src-uuid' => $this->rawEntity('src-uuid'),
			'tgt-uuid' => $this->rawEntity('tgt-uuid'),
		];
		$this->objectMapper->method('find')->willReturnCallback(
			static fn ($identifier) => $rawEntities[$identifier]
		);

		// The provenance-relation write blows up mid-transaction.
		$this->objectMapper->method('update')->willThrowException(
			new \RuntimeException('relations write failed')
		);

		$deleted = [];
		$this->objectMapper->method('delete')->willReturnCallback(
			static function (ObjectEntity $entity) use (&$deleted) {
				$deleted[] = (string)$entity->getUuid();
				return $entity;
			}
		);

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->eventDispatcher->expects($this->never())->method('dispatchTyped');

		try {
			$this->service->execute(register: '7', schema: '12', id: 'src-uuid', handoffId: 'request-to-case');
			$this->fail('Expected RuntimeException');
		} catch (\RuntimeException $e) {
			$this->assertSame('relations write failed', $e->getMessage());
		}

		// Compensation removed the created target...
		$this->assertSame(['tgt-uuid'], $deleted);
		// ...and restored the source's pre-handoff data (status back to "new").
		$this->assertCount(2, $sourceSaves);
		$this->assertSame('handed-off', $sourceSaves[0]['status']);
		$this->assertSame('new', $sourceSaves[1]['status']);

	}//end testCompensationRemovesTargetOnLateFailure()

	/**
	 * Drain leaves entries parked while their kind stays unresolvable.
	 *
	 * @return void
	 */
	public function testDrainSkipsUnresolvableKind(): void {
		$this->schemaMapper->method('findAll')->willReturn([]);
		$this->queueMapper->method('findParked')->willReturn([$this->queueEntry()]);
		$this->queueMapper->expects($this->never())->method('update');

		$summary = $this->service->drainParked();

		$this->assertSame(['drained' => 0, 'failed' => 0, 'skipped' => 1], $summary);

	}//end testDrainSkipsUnresolvableKind()

	/**
	 * Drain re-evaluates RBAC as the recorded requester: lost create
	 * permission → failed-permission + requester notification, never
	 * escalation.
	 *
	 * @return void
	 */
	public function testDrainFailedPermissionNotifiesRequester(): void {
		$this->wireSourceSchema(whenUnavailable: 'queue');
		$this->wireProvider();
		$this->objectService->method('find')->willReturn($this->sourceObject());

		$entry = $this->queueEntry();
		$this->queueMapper->method('findParked')->willReturn([$entry]);

		$requester = $this->createMock(IUser::class);
		$requester->method('getUID')->willReturn('alice');
		$this->userManager->method('get')->with('alice')->willReturn($requester);

		$this->permissionHandler->method('checkPermission')->willThrowException(
			new NotAuthorizedException(message: 'permission revoked since parking')
		);

		$updatedEntry = null;
		$this->queueMapper->method('update')->willReturnCallback(
			static function (HandoffQueueEntry $updated) use (&$updatedEntry) {
				$updatedEntry = $updated;
				return $updated;
			}
		);

		$this->notificationManager->expects($this->once())->method('notify');

		$summary = $this->service->drainParked();

		$this->assertSame(1, $summary['failed']);
		$this->assertSame(HandoffQueueEntry::STATUS_FAILED_PERMISSION, $updatedEntry->getStatus());
		$this->assertSame(1, $updatedEntry->getAttempt());
		$this->assertNotNull($updatedEntry->getLastError());

	}//end testDrainFailedPermissionNotifiesRequester()

	/**
	 * Deferred drain success: entry goes executed, the event carries
	 * deferred=true and the parked correlation id.
	 *
	 * @return void
	 */
	public function testDrainSuccessIsDeferredExecution(): void {
		$this->wireSourceSchema(whenUnavailable: 'queue');
		$this->wireProvider();
		$source = $this->sourceObject();
		$this->objectService->method('find')->willReturn($source);

		$target = $this->targetObject();
		$this->objectService->method('saveObject')->willReturnCallback(
			static function ($object, $extend = [], $register = null, $schema = null, $uuid = null) use ($target, $source) {
				if ($uuid === 'src-uuid') {
					return $source;
				}

				return $target;
			}
		);

		$rawEntities = [
			'src-uuid' => $this->rawEntity('src-uuid'),
			'tgt-uuid' => $this->rawEntity('tgt-uuid'),
		];
		$this->objectMapper->method('find')->willReturnCallback(
			static fn ($identifier) => $rawEntities[$identifier]
		);
		$this->objectMapper->method('update')->willReturnArgument(0);
		$this->auditTrailMapper->method('recordObjectEvent')->willReturnCallback(AuditTrailEvents::positional(
			fn () => $this->createMock(\OCA\OpenRegister\Db\AuditTrail::class)
		));

		$entry = $this->queueEntry();
		$this->queueMapper->method('findParked')->willReturn([$entry]);

		$requester = $this->createMock(IUser::class);
		$requester->method('getUID')->willReturn('alice');
		$this->userManager->method('get')->with('alice')->willReturn($requester);

		$updatedEntry = null;
		$this->queueMapper->method('update')->willReturnCallback(
			static function (HandoffQueueEntry $updated) use (&$updatedEntry) {
				$updatedEntry = $updated;
				return $updated;
			}
		);

		$dispatched = null;
		$this->eventDispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use (&$dispatched) {
				$dispatched = $event;
			}
		);

		$summary = $this->service->drainParked();

		$this->assertSame(1, $summary['drained']);
		$this->assertSame(HandoffQueueEntry::STATUS_EXECUTED, $updatedEntry->getStatus());
		$this->assertNotNull($updatedEntry->getExecutedAt());
		$this->assertInstanceOf(HandoffExecutedEvent::class, $dispatched);
		$this->assertTrue($dispatched->isDeferred());
		$this->assertSame('corr-park-1', $dispatched->getCorrelationId());

	}//end testDrainSuccessIsDeferredExecution()

	/**
	 * Availability lists an installed provider as `available`, with the
	 * record keys in their documented order.
	 *
	 * @return void
	 */
	public function testListAvailabilityReportsAvailableProvider(): void {
		$this->wireSourceSchema();
		$this->wireProvider();
		$this->objectService->method('find')->willReturn($this->sourceObject());
		$this->queueMapper->method('findParkedForObject')->willReturn([]);

		$this->assertSame(
			[
				[
					'id' => 'request-to-case',
					'targetSemanticType' => self::CASE_URI,
					'trigger' => 'manual',
					'whenUnavailable' => 'hide',
					'state' => 'available',
					'provider' => ['schema' => 'case', 'title' => 'Case'],
				],
			],
			$this->service->listAvailability(new HandoffSourceRef(register: '7', schema: '12', id: 'src-uuid'))
		);

	}//end testListAvailabilityReportsAvailableProvider()

	/**
	 * Availability lists a kind without a provider as `unavailable` with the
	 * machine-readable reason.
	 *
	 * @return void
	 */
	public function testListAvailabilityReportsUnavailableProvider(): void {
		$this->wireSourceSchema(whenUnavailable: 'queue');
		$this->schemaMapper->method('findAll')->willReturn([]);
		$this->objectService->method('find')->willReturn($this->sourceObject());
		$this->queueMapper->method('findParkedForObject')->willReturn([]);

		$this->assertSame(
			[
				[
					'id' => 'request-to-case',
					'targetSemanticType' => self::CASE_URI,
					'trigger' => 'manual',
					'whenUnavailable' => 'queue',
					'state' => 'unavailable',
					'reason' => HandoffException::PROVIDER_UNAVAILABLE,
				],
			],
			$this->service->listAvailability(new HandoffSourceRef(register: '7', schema: '12', id: 'src-uuid'))
		);

	}//end testListAvailabilityReportsUnavailableProvider()

	/**
	 * A parked entry wins over provider resolution: the record is `queued`
	 * and carries the serialised queue entry.
	 *
	 * @return void
	 */
	public function testListAvailabilityReportsQueuedEntry(): void {
		$this->wireSourceSchema(whenUnavailable: 'queue');
		$this->wireProvider();
		$this->objectService->method('find')->willReturn($this->sourceObject());
		$parked = $this->queueEntry();
		$this->queueMapper->method('findParkedForObject')->with('src-uuid')->willReturn([$parked]);

		$this->assertSame(
			[
				[
					'id' => 'request-to-case',
					'targetSemanticType' => self::CASE_URI,
					'trigger' => 'manual',
					'whenUnavailable' => 'queue',
					'state' => 'queued',
					'queueEntry' => $parked->jsonSerialize(),
				],
			],
			$this->service->listAvailability(new HandoffSourceRef(register: '7', schema: '12', id: 'src-uuid'))
		);

	}//end testListAvailabilityReportsQueuedEntry()

	/**
	 * A source the caller cannot find is refused as not-declared by both
	 * the availability listing and execution, before anything is read or written.
	 *
	 * @return void
	 */
	public function testMissingSourceIsRefusedByListingAndExecution(): void {
		$this->objectService->method('find')->willReturn(null);
		$this->schemaMapper->expects($this->never())->method('find');
		$this->permissionHandler->expects($this->never())->method('checkPermission');
		$this->objectService->expects($this->never())->method('saveObject');

		foreach (['list', 'execute'] as $call) {
			try {
				if ($call === 'list') {
					$this->service->listAvailability(new HandoffSourceRef(register: '7', schema: '12', id: 'gone'));
				} else {
					$this->service->execute(register: '7', schema: '12', id: 'gone', handoffId: 'request-to-case');
				}

				$this->fail('Expected HandoffException from '.$call);
			} catch (HandoffException $e) {
				$this->assertSame(HandoffException::NOT_DECLARED, $e->getErrorCode());
				$this->assertSame('Object not found.', $e->getMessage());
			}
		}

	}//end testMissingSourceIsRefusedByListingAndExecution()

	/**
	 * Not-declared names the schema and the handoff id in its message.
	 *
	 * @return void
	 */
	public function testNotDeclaredMessageNamesSchemaAndHandoff(): void {
		$this->wireSourceSchema();
		$this->objectService->method('find')->willReturn($this->sourceObject());
		$this->permissionHandler->expects($this->never())->method('checkPermission');

		try {
			$this->service->execute(register: '7', schema: '12', id: 'src-uuid', handoffId: 'ghost');
			$this->fail('Expected HandoffException');
		} catch (HandoffException $e) {
			$this->assertSame('Schema "request" declares no handoff with id "ghost".', $e->getMessage());
		}

	}//end testNotDeclaredMessageNamesSchemaAndHandoff()

	/**
	 * Refused: the caller may not update the source → typed 403 before the
	 * provider is resolved and before any write or park.
	 *
	 * @return void
	 */
	public function testSourceUpdateRefusalHappensBeforeAnyWrite(): void {
		$this->wireSourceSchema(whenUnavailable: 'queue');
		$this->objectService->method('find')->willReturn($this->sourceObject());

		$this->permissionHandler->method('checkPermission')->willReturnCallback(
			static function (Schema $schema, string $action, ...$rest): void {
				if ($action === 'update') {
					throw new NotAuthorizedException(message: 'update denied');
				}
			}
		);

		$this->schemaMapper->expects($this->never())->method('findAll');
		$this->queueMapper->expects($this->never())->method('insert');
		$this->objectService->expects($this->never())->method('saveObject');
		$this->db->expects($this->never())->method('beginTransaction');

		$this->expectException(NotAuthorizedException::class);
		$this->expectExceptionMessage('update denied');
		$this->service->execute(register: '7', schema: '12', id: 'src-uuid', handoffId: 'request-to-case');

	}//end testSourceUpdateRefusalHappensBeforeAnyWrite()

	/**
	 * Allowed: both checks run as the caller, update-on-source (with owner and
	 * object) first, then create-on-target, and both before the first write.
	 *
	 * @return void
	 */
	public function testPermissionChecksRunInOrderBeforeTheFirstWrite(): void {
		$this->wireSourceSchema();
		$this->wireProvider();
		$source = $this->sourceObject();
		$this->objectService->method('find')->willReturn($source);

		$log = [];
		$this->permissionHandler->method('checkPermission')->willReturnCallback(
			static function (Schema $schema, string $action, ?string $userId = null, ?string $objectOwner = null, bool $rbac = true, ?ObjectEntity $object = null) use (&$log): bool {
				$log[] = ['check', $action, (int)$schema->getId(), $objectOwner, $object?->getUuid()];
				return true;
			}
		);

		$target = $this->targetObject();
		$this->objectService->method('saveObject')->willReturnCallback(
			static function ($object, $extend = [], $register = null, $schema = null, $uuid = null) use ($target, $source, &$log) {
				$log[] = ['save', $uuid];
				if ($uuid === 'src-uuid') {
					return $source;
				}

				return $target;
			}
		);

		$rawEntities = [
			'src-uuid' => $this->rawEntity('src-uuid'),
			'tgt-uuid' => $this->rawEntity('tgt-uuid'),
		];
		$this->objectMapper->method('find')->willReturnCallback(
			static fn ($identifier) => $rawEntities[$identifier]
		);
		$this->objectMapper->method('update')->willReturnArgument(0);
		$this->auditTrailMapper->method('recordObjectEvent')->willReturnCallback(AuditTrailEvents::positional(
			fn () => $this->createMock(\OCA\OpenRegister\Db\AuditTrail::class)
		));

		$result = $this->service->execute(register: '7', schema: '12', id: 'src-uuid', handoffId: 'request-to-case', correlationId: 'corr-fixed');

		$this->assertSame(
			[
				['check', 'update', 12, 'alice', 'src-uuid'],
				['check', 'create', 42, null, null],
				['save', null],
				['save', 'src-uuid'],
			],
			$log
		);
		$this->assertSame(
			[
				'status' => 'executed',
				'correlationId' => 'corr-fixed',
				'target' => ['register' => 9, 'schema' => 42, 'uuid' => 'tgt-uuid'],
			],
			$result
		);

	}//end testPermissionChecksRunInOrderBeforeTheFirstWrite()

	/**
	 * A provider schema no register holds cannot receive objects: it takes
	 * the same degradation path as a missing provider (hide → typed error).
	 *
	 * @return void
	 */
	public function testOrphanedProviderDegradesLikeAMissingOne(): void {
		$this->wireSourceSchema();
		$this->wireProvider(withRegister: false);
		$this->objectService->method('find')->willReturn($this->sourceObject());

		$this->objectService->expects($this->never())->method('saveObject');
		$this->queueMapper->expects($this->never())->method('insert');

		try {
			$this->service->execute(register: '7', schema: '12', id: 'src-uuid', handoffId: 'request-to-case');
			$this->fail('Expected HandoffException');
		} catch (HandoffException $e) {
			$this->assertSame(HandoffException::PROVIDER_UNAVAILABLE, $e->getErrorCode());
			$this->assertSame(
				'No installed schema implements "'.self::CASE_URI.'"; the source object keeps working standalone.',
				$e->getMessage()
			);
		}

	}//end testOrphanedProviderDegradesLikeAMissingOne()

	/**
	 * Queue mode parks an entry carrying the source, kind, requester and
	 * mapping hash, and audits the park on the source.
	 *
	 * @return void
	 */
	public function testQueueModeParkedEntryAndAuditContext(): void {
		$this->wireSourceSchema(whenUnavailable: 'queue');
		$this->schemaMapper->method('findAll')->willReturn([]);
		$source = $this->sourceObject();
		$this->objectService->method('find')->willReturn($source);

		$inserted = null;
		$this->queueMapper->method('insert')->willReturnCallback(
			static function (HandoffQueueEntry $entry) use (&$inserted) {
				$inserted = $entry;
				return $entry;
			}
		);

		$audits = [];
		$this->auditTrailMapper->method('recordObjectEvent')->willReturnCallback(AuditTrailEvents::positional(
			function (ObjectEntity $object, string $action, array $context = []) use (&$audits) {
				$audits[] = [(string)$object->getUuid(), $action, array_keys($context), $context['deferred'], $context['handoffId']];
				return $this->createMock(\OCA\OpenRegister\Db\AuditTrail::class);
			}
		));

		$result = $this->service->execute(register: '7', schema: '12', id: 'src-uuid', handoffId: 'request-to-case', correlationId: 'ignored-on-park');

		$this->assertSame('src-uuid', $inserted->getSourceObjectUuid());
		$this->assertSame(7, $inserted->getSourceRegister());
		$this->assertSame(12, $inserted->getSourceSchema());
		$this->assertSame('request-to-case', $inserted->getHandoffId());
		$this->assertSame(self::CASE_URI, $inserted->getTargetKind());
		$this->assertSame('alice', $inserted->getRequestingUser());
		$this->assertSame(HandoffQueueEntry::STATUS_PARKED, $inserted->getStatus());
		$mapping = $this->schemaMapper->find(id: '12')->getConfiguration()['x-openregister-handoff'][0]['mapping'];
		$this->assertSame(hash('sha256', (string)json_encode($mapping)), $inserted->getMappingHash());
		// Parking mints its own correlation id; a passed one is not reused.
		$this->assertNotSame('ignored-on-park', $inserted->getCorrelationId());
		$this->assertSame($inserted->getCorrelationId(), $result['correlationId']);
		$this->assertSame(['status', 'correlationId', 'queueEntry'], array_keys($result));
		$this->assertSame(
			[['src-uuid', HandoffService::AUDIT_QUEUED, ['handoffId', 'targetKind', 'correlationId', 'deferred', 'parkedAt'], true, 'request-to-case']],
			$audits
		);

	}//end testQueueModeParkedEntryAndAuditContext()

	/**
	 * A failing `onSuccess.set` source update removes the created target and
	 * surfaces the error; no transaction, no relations, no event.
	 *
	 * @return void
	 */
	public function testOnSuccessFailureRemovesTheTarget(): void {
		$this->wireSourceSchema();
		$this->wireProvider();
		$this->objectService->method('find')->willReturn($this->sourceObject());

		$target = $this->targetObject();
		$this->objectService->method('saveObject')->willReturnCallback(
			static function ($object, $extend = [], $register = null, $schema = null, $uuid = null) use ($target) {
				if ($uuid === 'src-uuid') {
					throw new \RuntimeException('source update failed');
				}

				return $target;
			}
		);
		$this->objectMapper->method('find')->willReturnCallback(fn ($identifier) => $this->rawEntity($identifier));

		$deleted = [];
		$this->objectMapper->method('delete')->willReturnCallback(
			static function (ObjectEntity $entity) use (&$deleted) {
				$deleted[] = (string)$entity->getUuid();
				return $entity;
			}
		);

		$this->db->expects($this->never())->method('beginTransaction');
		$this->objectMapper->expects($this->never())->method('update');
		$this->eventDispatcher->expects($this->never())->method('dispatchTyped');

		try {
			$this->service->execute(register: '7', schema: '12', id: 'src-uuid', handoffId: 'request-to-case');
			$this->fail('Expected RuntimeException');
		} catch (\RuntimeException $e) {
			$this->assertSame('source update failed', $e->getMessage());
		}

		$this->assertSame(['tgt-uuid'], $deleted);

	}//end testOnSuccessFailureRemovesTheTarget()

	/**
	 * A schema implementing the kind whose `handoffContract` is not an array
	 * is not a provider.
	 *
	 * @return void
	 */
	public function testProviderWithNonArrayBindingIsUnavailable(): void {
		$this->assertSame('unavailable', $this->availabilityWithBinding(binding: 'not an array'));

	}//end testProviderWithNonArrayBindingIsUnavailable()

	/**
	 * A schema implementing the kind with an incomplete binding is not a
	 * provider; the same schema with the complete binding is.
	 *
	 * @return void
	 */
	public function testProviderWithIncompleteBindingIsUnavailable(): void {
		$this->assertSame('unavailable', $this->availabilityWithBinding(binding: [self::CASE_URI => ['title' => 'onderwerp']]));

	}//end testProviderWithIncompleteBindingIsUnavailable()

	/**
	 * The availability state of the canonical handoff when the only schema
	 * implementing the kind carries the given binding.
	 *
	 * @param mixed $binding The `handoffContract` configuration value.
	 *
	 * @return string The reported state.
	 */
	private function availabilityWithBinding(mixed $binding): string {
		$this->wireSourceSchema();
		$this->objectService->method('find')->willReturn($this->sourceObject());
		$this->queueMapper->method('findParkedForObject')->willReturn([]);

		$candidate = new Schema();
		$candidate->setId(50);
		$candidate->setSlug('candidate');
		$candidate->setProperties(['onderwerp' => ['type' => 'string']]);
		$candidate->setConfiguration(['implements' => [self::CASE_URI], 'handoffContract' => $binding]);
		$this->schemaMapper->method('findAll')->willReturn([$candidate]);

		$register = new Register();
		$register->setId(9);
		$register->setSchemas([50]);
		$this->registerMapper->method('findAll')->willReturn([$register]);

		return $this->service->listAvailability(new HandoffSourceRef(register: '7', schema: '12', id: 'src-uuid'))[0]['state'];
	}//end availabilityWithBinding()

	/**
	 * Wire the source schema (id 12) into the schema mapper's find().
	 *
	 * @param string $whenUnavailable The degradation mode to declare.
	 *
	 * @return Schema The source schema.
	 */
	private function wireSourceSchema(string $whenUnavailable = 'hide'): Schema {
		$schema = new Schema();
		$schema->setId(12);
		$schema->setSlug('request');
		$schema->setUuid('uuid-12');
		$schema->setApplication('pipelinq');
		$schema->setProperties(
			[
				'subject' => ['type' => 'string'],
				'details' => ['type' => 'string'],
				'client' => ['type' => 'string'],
				'channel' => ['type' => 'string'],
				'priority' => ['type' => 'string'],
				'status' => [
					'type' => 'string',
					'enum' => ['new', 'handed-off'],
				],
			]
		);
		$schema->setConfiguration(
			[
				'x-openregister-handoff' => [
					[
						'id' => 'request-to-case',
						'targetSemanticType' => self::CASE_URI,
						'trigger' => 'manual',
						'whenUnavailable' => $whenUnavailable,
						'mapping' => [
							'title' => ['from' => 'subject'],
							'summary' => ['template' => '{{subject}} — {{details}}'],
							'requester' => ['semanticRef' => 'client'],
							'channel' => ['from' => 'channel'],
							'priority' => [
								'from' => 'priority',
								'default' => 'normal',
							],
							'source' => ['provenance' => true],
						],
						'onSuccess' => ['set' => ['status' => 'handed-off']],
					],
				],
			]
		);

		$this->schemaMapper->method('find')->willReturn($schema);

		return $schema;
	}//end wireSourceSchema()

	/**
	 * Wire an installed ns#Case provider (schema 42 in register 9, app
	 * procest) with a complete handoffContract binding into the resolver's
	 * enumeration path.
	 *
	 * @param bool $withRegister False to leave the provider in no register (orphaned).
	 *
	 * @return Schema The provider schema.
	 */
	private function wireProvider(bool $withRegister = true): Schema {
		$provider = new Schema();
		$provider->setId(42);
		$provider->setSlug('case');
		$provider->setUuid('uuid-42');
		$provider->setTitle('Case');
		$provider->setApplication('procest');
		$provider->setProperties(
			[
				'onderwerp' => ['type' => 'string'],
				'omschrijving' => ['type' => 'string'],
				'aanvrager' => ['type' => 'string'],
				'kanaal' => ['type' => 'string'],
				'prioriteit' => ['type' => 'string'],
				'herkomst' => ['type' => 'object'],
			]
		);
		$provider->setConfiguration(
			[
				'implements' => [self::CASE_URI],
				'handoffContract' => [
					self::CASE_URI => [
						'title' => 'onderwerp',
						'summary' => 'omschrijving',
						'requester' => 'aanvrager',
						'channel' => 'kanaal',
						'priority' => 'prioriteit',
						'source' => 'herkomst',
					],
				],
			]
		);

		$this->schemaMapper->method('findAll')->willReturn([$provider]);

		$register = new Register();
		$register->setId(9);
		$register->setSlug('procest-register');
		$register->setApplication('procest');
		$register->setSchemas([42]);
		$this->registerMapper->method('findAll')->willReturn($withRegister === true ? [$register] : []);

		return $provider;
	}//end wireProvider()

	/**
	 * The canonical source object (pipelinq request).
	 *
	 * @return ObjectEntity
	 */
	private function sourceObject(): ObjectEntity {
		$source = new ObjectEntity();
		$source->setUuid('src-uuid');
		$source->setRegister('7');
		$source->setSchema('12');
		$source->setOwner('alice');
		$source->setName('Kapotte lantaarnpaal');
		$source->setObject(
			[
				'subject' => 'Kapotte lantaarnpaal',
				'details' => 'Voor de deur',
				'client' => '11111111-2222-3333-4444-555555555555',
				'channel' => 'telefoon',
				'priority' => 'hoog',
				'status' => 'new',
			]
		);

		return $source;
	}//end sourceObject()

	/**
	 * The created target object (procest case).
	 *
	 * @return ObjectEntity
	 */
	private function targetObject(): ObjectEntity {
		$target = new ObjectEntity();
		$target->setUuid('tgt-uuid');
		$target->setRegister('9');
		$target->setSchema('42');

		return $target;
	}//end targetObject()

	/**
	 * A raw entity as MagicMapper returns it for relation writes.
	 *
	 * @param string $uuid The entity uuid.
	 *
	 * @return ObjectEntity
	 */
	private function rawEntity(string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister('7');
		$entity->setSchema('12');
		$entity->setRelations([]);

		return $entity;
	}//end rawEntity()

	/**
	 * A parked queue entry for the canonical handoff.
	 *
	 * @return HandoffQueueEntry
	 */
	private function queueEntry(): HandoffQueueEntry {
		$entry = new HandoffQueueEntry();
		$entry->setId(101);
		$entry->setSourceObjectUuid('src-uuid');
		$entry->setSourceRegister(7);
		$entry->setSourceSchema(12);
		$entry->setHandoffId('request-to-case');
		$entry->setTargetKind(self::CASE_URI);
		$entry->setRequestingUser('alice');
		$entry->setCorrelationId('corr-park-1');
		$entry->setStatus(HandoffQueueEntry::STATUS_PARKED);

		return $entry;
	}//end queueEntry()
}//end class
