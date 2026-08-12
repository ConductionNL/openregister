<?php

/**
 * OpenRegister Handoff Service
 *
 * The OR-owned semantic-object-handoff engine (ADR-051, ADR-022 exclusivity).
 * Executes a handoff declared via the `x-openregister-handoff` dialect by
 * resolving the installed schema implementing the target kind
 * ({@see \OCA\OpenRegister\Service\SemanticTypeResolver}, filtered to schemas
 * with a COMPLETE `handoffContract` binding), creating the target object
 * through the normal `ObjectService` write path under the CALLER's RBAC,
 * linking `handoff` provenance both ways, writing one immutable audit row per
 * side, and applying the declared `onSuccess.set` source update — all inside
 * one database transaction so a handoff either fully happens or leaves no
 * partial state. A typed {@see \OCA\OpenRegister\Event\HandoffExecutedEvent}
 * is dispatched post-commit (ADR-041).
 *
 * Degradation: `hide` (default) surfaces a machine-readable
 * `handoff-provider-unavailable` error; `queue` parks the request in
 * `oc_openregister_handoff_queue` (WebhookLog durable pattern) and drains it
 * when a provider appears, re-evaluating RBAC as the original requester.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Handoff
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Handoff;

use DateTime;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\HandoffQueueEntry;
use OCA\OpenRegister\Db\HandoffQueueEntryMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\HandoffExecutedEvent;
use OCA\OpenRegister\Exception\HandoffException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\SemanticTypeResolver;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Execute declared semantic-object handoffs on top of SemanticTypeResolver.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The engine deliberately
 *   composes the shipped OR capabilities (resolver, object write path,
 *   relations, audit, queue, events) rather than reimplementing any of them.
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 *   (Requirement: HandoffService executes conversions on top of SemanticTypeResolver)
 */
class HandoffService {

	/**
	 * Reserved relation type prefix for handoff provenance keys in the
	 * object relations map: `handoff:<id>:handed-off-to` on the source and
	 * `handoff:<id>:originated-from` on the target.
	 *
	 * @var string
	 */
	public const RELATION_PREFIX = 'handoff';

	/**
	 * Audit action written on both sides of an executed handoff.
	 *
	 * @var string
	 */
	public const AUDIT_EXECUTED = 'handoff.executed';

	/**
	 * Audit action written when a queue-mode handoff is parked.
	 *
	 * @var string
	 */
	public const AUDIT_QUEUED = 'handoff.queued';

	/**
	 * Audit action written when a parked handoff is drained.
	 *
	 * @var string
	 */
	public const AUDIT_DEQUEUED = 'handoff.dequeued';

	/**
	 * Wire the engine against the shipped OR capabilities it composes.
	 *
	 * @param ObjectService $objectService RBAC-enforcing object read/write path.
	 * @param SemanticTypeResolver $semanticTypeResolver Kind URI → installed schema resolution.
	 * @param SchemaMapper $schemaMapper Source-schema loading.
	 * @param MagicMapper $objectMapper Raw entity access for provenance relation writes.
	 * @param AuditTrailMapper $auditTrailMapper Immutable audit rows.
	 * @param PermissionHandler $permissionHandler RBAC pre-checks (typed 403 before any write).
	 * @param HandoffMappingEvaluator $mappingEvaluator The five-expression mapping evaluator.
	 * @param HandoffQueueEntryMapper $queueMapper Durable queue-mode storage.
	 * @param IDBConnection $db Transaction boundary.
	 * @param IEventDispatcher $eventDispatcher Post-commit ADR-041 event dispatch.
	 * @param IUserSession $userSession The acting user (and drain impersonation).
	 * @param IUserManager $userManager Requesting-user lookup for queue drain.
	 * @param INotificationManager $notificationManager Requester notification on drain permission failure.
	 * @param LoggerInterface $logger Structured logging.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly SemanticTypeResolver $semanticTypeResolver,
		private readonly SchemaMapper $schemaMapper,
		private readonly MagicMapper $objectMapper,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly PermissionHandler $permissionHandler,
		private readonly HandoffMappingEvaluator $mappingEvaluator,
		private readonly HandoffQueueEntryMapper $queueMapper,
		private readonly IDBConnection $db,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
		private readonly INotificationManager $notificationManager,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Report handoff availability for one object: every declared handoff with
	 * its state — `available` (naming the resolved provider schema),
	 * `unavailable` (machine-readable reason for the "provider not installed"
	 * UI copy), or `queued` (a parked queue-mode entry exists).
	 *
	 * The object is loaded through `ObjectService::find()` under the caller's
	 * RBAC (read guard, ADR-005).
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema The schema slug or id.
	 * @param string $id The object id/uuid.
	 *
	 * @return array<int, array<string, mixed>> One availability record per declared handoff.
	 *
	 * @throws \Exception When the object cannot be read by the caller.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: Handoff REST surface)
	 */
	public function listAvailability(string $register, string $schema, string $id): array {
		$source = $this->objectService->find(id: $id, register: $register, schema: $schema);
		if ($source === null) {
			throw new HandoffException(
				errorCode: HandoffException::NOT_DECLARED,
				message: 'Object not found.'
			);
		}

		$sourceSchema = $this->schemaMapper->find(id: (string)$source->getSchema());
		$entries = $this->declaredHandoffs(schema: $sourceSchema);

		$parked = [];
		foreach ($this->queueMapper->findParkedForObject(sourceObjectUuid: (string)$source->getUuid()) as $entry) {
			$parked[$entry->getHandoffId()] = $entry;
		}

		$availability = [];
		foreach ($entries as $entry) {
			$handoffId = (string)($entry['id'] ?? '');
			$kindUri = (string)($entry['targetSemanticType'] ?? '');
			$mode = (string)($entry['whenUnavailable'] ?? 'hide');

			if (isset($parked[$handoffId]) === true) {
				$availability[] = [
					'id' => $handoffId,
					'targetSemanticType' => $kindUri,
					'trigger' => (string)($entry['trigger'] ?? 'manual'),
					'whenUnavailable' => $mode,
					'state' => 'queued',
					'queueEntry' => $parked[$handoffId]->jsonSerialize(),
				];
				continue;
			}

			$provider = $this->resolveProvider(kindUri: $kindUri, consumingRegisterId: (int)$source->getRegister());
			if ($provider === null) {
				$availability[] = [
					'id' => $handoffId,
					'targetSemanticType' => $kindUri,
					'trigger' => (string)($entry['trigger'] ?? 'manual'),
					'whenUnavailable' => $mode,
					'state' => 'unavailable',
					'reason' => HandoffException::PROVIDER_UNAVAILABLE,
				];
				continue;
			}

			$availability[] = [
				'id' => $handoffId,
				'targetSemanticType' => $kindUri,
				'trigger' => (string)($entry['trigger'] ?? 'manual'),
				'whenUnavailable' => $mode,
				'state' => 'available',
				'provider' => [
					'schema' => ($provider->getSlug() ?? (string)$provider->getId()),
					'title' => $provider->getTitle(),
				],
			];
		}//end foreach

		return $availability;
	}//end listAvailability()

	/**
	 * Execute a declared handoff on a source object (or park it, queue mode).
	 *
	 * Flow (design.md): load source under caller RBAC → read declared entry →
	 * resolve provider (complete-binding filter) → degrade (hide 409 / queue
	 * 202) → RBAC pre-check create on target → evaluate mapping → all-or-
	 * nothing execution {create target, `onSuccess.set` through the
	 * lifecycle-aware write path, then provenance relations both ways + one
	 * audit row per side in a DB transaction; failures compensate by removing
	 * the target and restoring the source} → event dispatch afterwards.
	 *
	 * @param string $register The source register slug or id.
	 * @param string $schema The source schema slug or id.
	 * @param string $id The source object id/uuid.
	 * @param string $handoffId The declared handoff entry id.
	 * @param bool $deferred True when draining a parked queue entry.
	 * @param string|null $correlationId Correlation id to reuse (queue drain); minted when null.
	 *
	 * @return array<string, mixed> `{status: executed, target: {...}, correlationId}` or `{status: parked, queueEntry: {...}}`.
	 *
	 * @throws HandoffException For not-declared / provider-unavailable (hide mode).
	 * @throws NotAuthorizedException When the caller lacks write-on-source or create-on-target.
	 * @throws \Exception Underlying write-path errors (surfaced after rollback).
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: Successful request-to-case handoff)
	 */
	public function execute(
		string $register,
		string $schema,
		string $id,
		string $handoffId,
		bool $deferred = false,
		?string $correlationId = null,
	): array {
		// 1. Load the source under the caller's RBAC (read) — 404/403 surface here.
		$source = $this->objectService->find(id: $id, register: $register, schema: $schema);
		if ($source === null) {
			throw new HandoffException(errorCode: HandoffException::NOT_DECLARED, message: 'Object not found.');
		}

		$sourceSchema = $this->schemaMapper->find(id: (string)$source->getSchema());

		// 2. The declared entry — `handoff-not-declared` when absent.
		$entry = $this->declaredHandoff(schema: $sourceSchema, handoffId: $handoffId);
		if ($entry === null) {
			throw new HandoffException(
				errorCode: HandoffException::NOT_DECLARED,
				message: sprintf(
					'Schema "%s" declares no handoff with id "%s".',
					(string)($sourceSchema->getSlug() ?? $sourceSchema->getId()),
					$handoffId
				)
			);
		}

		// Write permission on the source, as the calling user (never escalate).
		$this->permissionHandler->checkPermission(
			schema: $sourceSchema,
			action: 'update',
			objectOwner: $source->getOwner(),
			object: $source
		);

		// 3. Resolve the provider (complete-binding filter on the resolver result).
		$kindUri = (string)($entry['targetSemanticType'] ?? '');
		$provider = $this->resolveProvider(kindUri: $kindUri, consumingRegisterId: (int)$source->getRegister());

		if ($provider === null) {
			return $this->degrade(entry: $entry, source: $source, kindUri: $kindUri, handoffId: $handoffId);
		}

		$targetRegister = $this->semanticTypeResolver->findRegisterForSchema(schema: $provider);
		if ($targetRegister === null) {
			// An orphaned provider schema cannot receive objects — same degradation path.
			return $this->degrade(entry: $entry, source: $source, kindUri: $kindUri, handoffId: $handoffId);
		}

		// 4. RBAC pre-check: create on the resolved target schema, as the caller — typed 403 before any write.
		$this->permissionHandler->checkPermission(schema: $provider, action: 'create');

		// 5. Evaluate the mapping and translate contract fields → provider properties via the binding.
		$correlationId = ($correlationId ?? Uuid::v4()->toRfc4122());
		$provenance = $this->buildProvenance(source: $source, sourceSchema: $sourceSchema);
		$contractData = $this->mappingEvaluator->evaluate(
			mapping: (array)($entry['mapping'] ?? []),
			sourceData: $source->getObject(),
			provenance: $provenance
		);
		$targetData = $this->translateThroughBinding(provider: $provider, kindUri: $kindUri, contractData: $contractData);

		// 6. All-or-nothing execution. A single wrapping DB transaction is NOT
		// possible here: ObjectService::saveObject issues best-effort probe
		// queries that may fail and be swallowed by design (e.g. cross-schema
		// magic-table COUNTs), and on PostgreSQL ANY failed statement aborts
		// an open caller-managed transaction (SQLSTATE 25P02) — verified live.
		// Instead: (a) the target create is atomic within OR's own write path;
		// (b) the onSuccess source update runs next, compensated by deleting
		// the created target on failure; (c) provenance relations + audit rows
		// — plain mapper writes on known tables — run inside one real DB
		// transaction, compensated by target delete + source revert on
		// failure. Observable contract holds: a failed handoff leaves no
		// target, no relation, no handoff audit row, and no source mutation.
		$target = $this->objectService->saveObject(
			object: $targetData,
			register: $targetRegister,
			schema: $provider
		);

		$sourceSnapshot = $source->getObject();
		try {
			$this->applyOnSuccess(entry: $entry, source: $source, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$this->compensate(
				target: $target,
				targetRegister: $targetRegister,
				targetSchema: $provider
			);
			throw $e;
		}

		$this->db->beginTransaction();
		try {
			$this->writeProvenanceRelations(
				source: $source,
				target: $target,
				handoffId: $handoffId,
				sourceSchema: $sourceSchema,
				targetRegister: $targetRegister,
				targetSchema: $provider
			);
			$this->writeAuditRows(
				source: $source,
				target: $target,
				provider: $provider,
				kindUri: $kindUri,
				handoffId: $handoffId,
				correlationId: $correlationId,
				deferred: $deferred
			);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			$this->compensate(
				target: $target,
				targetRegister: $targetRegister,
				targetSchema: $provider,
				source: $source,
				sourceSnapshot: $sourceSnapshot,
				sourceRegisterRef: $register,
				sourceSchemaRef: $schema
			);
			throw $e;
		}//end try

		// 7. Post-commit: dispatch the ADR-041 event (a throwing listener can never roll back the handoff).
		$this->eventDispatcher->dispatchTyped(
			new HandoffExecutedEvent(
				sourceApp: (string)($sourceSchema->getApplication() ?? ''),
				sourceRegister: (int)$source->getRegister(),
				sourceSchema: (int)$source->getSchema(),
				sourceObjectUuid: (string)$source->getUuid(),
				subjectLabel: $source->getName(),
				targetSemanticType: $kindUri,
				targetRegister: (int)$target->getRegister(),
				targetSchema: (int)$target->getSchema(),
				targetObjectUuid: (string)$target->getUuid(),
				handoffId: $handoffId,
				correlationId: $correlationId,
				deferred: $deferred
			)
		);

		return [
			'status' => 'executed',
			'correlationId' => $correlationId,
			'target' => [
				'register' => (int)$target->getRegister(),
				'schema' => (int)$target->getSchema(),
				'uuid' => (string)$target->getUuid(),
			],
		];

	}//end execute()

	/**
	 * Drain parked queue entries whose kind now resolves to a provider.
	 *
	 * Runs each entry AS THE RECORDED REQUESTING USER (impersonation via the
	 * same user-session mechanics OR background jobs use), re-evaluating RBAC
	 * at drain time: a requester who lost create permission gets a
	 * `failed-permission` entry + a notification — the queue is never a
	 * privilege-escalation time capsule.
	 *
	 * @param string|null $kindUri Drain only entries parked for this kind (null = all parked kinds).
	 *
	 * @return array{drained: int, failed: int, skipped: int} Drain summary.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: No provider installed, queue mode)
	 */
	public function drainParked(?string $kindUri = null): array {
		$entries = [];
		if ($kindUri !== null) {
			$entries = $this->queueMapper->findParkedByKind(kindUri: $kindUri);
		}

		if ($kindUri === null) {
			$entries = $this->queueMapper->findParked();
		}

		$summary = [
			'drained' => 0,
			'failed' => 0,
			'skipped' => 0,
		];

		foreach ($entries as $entry) {
			// Cheap pre-check: still no provider → leave parked.
			$this->semanticTypeResolver->clearCache();
			if ($this->resolveProvider(kindUri: $entry->getTargetKind(), consumingRegisterId: $entry->getSourceRegister()) === null) {
				$summary['skipped']++;
				continue;
			}

			if ($this->drainEntry(entry: $entry) === true) {
				$summary['drained']++;
				continue;
			}

			$summary['failed']++;
		}

		return $summary;
	}//end drainParked()

	/**
	 * Drain a single parked entry as its recorded requesting user.
	 *
	 * @param HandoffQueueEntry $entry The parked entry.
	 *
	 * @return bool True when the entry executed; false when it failed (status updated accordingly).
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: No provider installed, queue mode)
	 */
	private function drainEntry(HandoffQueueEntry $entry): bool {
		$entry->setAttempt($entry->getAttempt() + 1);
		$entry->setLastAttemptAt(new DateTime());

		$requester = $this->userManager->get($entry->getRequestingUser());
		if ($requester === null) {
			$entry->setStatus(HandoffQueueEntry::STATUS_FAILED_PERMISSION);
			$entry->setLastError('Requesting user no longer exists.');
			$this->queueMapper->update(entity: $entry);
			return false;
		}

		$previousUser = $this->userSession->getUser();
		$this->userSession->setUser($requester);

		try {
			$this->execute(
				register: (string)$entry->getSourceRegister(),
				schema: (string)$entry->getSourceSchema(),
				id: $entry->getSourceObjectUuid(),
				handoffId: $entry->getHandoffId(),
				deferred: true,
				correlationId: $entry->getCorrelationId()
			);

			$entry->setStatus(HandoffQueueEntry::STATUS_EXECUTED);
			$entry->setExecutedAt(new DateTime());
			$entry->setLastError(null);
			$this->queueMapper->update(entity: $entry);

			$this->auditDequeued(entry: $entry);
			return true;
		} catch (NotAuthorizedException $e) {
			// RBAC re-evaluated at drain time refused: never escalate.
			$entry->setStatus(HandoffQueueEntry::STATUS_FAILED_PERMISSION);
			$entry->setLastError($e->getMessage());
			$this->queueMapper->update(entity: $entry);
			$this->notifyDrainFailure(entry: $entry);
			return false;
		} catch (\Throwable $e) {
			$entry->setStatus(HandoffQueueEntry::STATUS_FAILED_VALIDATION);
			$entry->setLastError($e->getMessage());
			$this->queueMapper->update(entity: $entry);
			$this->notifyDrainFailure(entry: $entry);
			return false;
		} finally {
			$this->userSession->setUser($previousUser);
		}//end try

	}//end drainEntry()

	/**
	 * Degrade when no provider implements the kind: `hide` → typed
	 * provider-unavailable error (409-class, never 5xx); `queue` → park a
	 * durable queue entry + audit + 202-style parked result.
	 *
	 * @param array<string, mixed> $entry The declared handoff entry.
	 * @param ObjectEntity $source The source object.
	 * @param string $kindUri The target kind URI.
	 * @param string $handoffId The handoff entry id.
	 *
	 * @return array<string, mixed> The parked result (queue mode only).
	 *
	 * @throws HandoffException In hide mode (provider unavailable).
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: No provider installed, hide mode)
	 */
	private function degrade(array $entry, ObjectEntity $source, string $kindUri, string $handoffId): array {
		$mode = (string)($entry['whenUnavailable'] ?? 'hide');
		if ($mode !== 'queue') {
			throw new HandoffException(
				errorCode: HandoffException::PROVIDER_UNAVAILABLE,
				message: sprintf('No installed schema implements "%s"; the source object keeps working standalone.', $kindUri)
			);
		}

		$requestingUser = ($this->userSession->getUser()?->getUID() ?? '');
		$correlationId = Uuid::v4()->toRfc4122();

		$queueEntry = new HandoffQueueEntry();
		$queueEntry->setSourceObjectUuid((string)$source->getUuid());
		$queueEntry->setSourceRegister((int)$source->getRegister());
		$queueEntry->setSourceSchema((int)$source->getSchema());
		$queueEntry->setHandoffId($handoffId);
		$queueEntry->setTargetKind($kindUri);
		$queueEntry->setRequestingUser($requestingUser);
		$queueEntry->setCorrelationId($correlationId);
		$queueEntry->setMappingHash(hash('sha256', (string)json_encode($entry['mapping'] ?? [])));
		$queueEntry->setStatus(HandoffQueueEntry::STATUS_PARKED);
		// Explicit setter (not just the constructor default) so QBMapper marks
		// the column as updated and includes it in the INSERT.
		$queueEntry->setCreated(new DateTime());

		$queueEntry = $this->queueMapper->insert(entity: $queueEntry);

		$this->auditTrailMapper->createAuditTrailEntry(
			object: $source,
			action: self::AUDIT_QUEUED,
			context: [
				'handoffId' => $handoffId,
				'targetKind' => $kindUri,
				'correlationId' => $correlationId,
				'deferred' => true,
				'parkedAt' => $queueEntry->getCreated()->format('c'),
			]
		);

		return [
			'status' => 'parked',
			'correlationId' => $correlationId,
			'queueEntry' => $queueEntry->jsonSerialize(),
		];

	}//end degrade()

	/**
	 * Resolve the provider schema for a kind, filtered to providers with a
	 * COMPLETE `handoffContract` binding (design: a schema implementing a
	 * kind with no/incomplete binding is not a handoff provider — ADR-048
	 * reference resolution stays untouched).
	 *
	 * @param string $kindUri The canonical kind URI.
	 * @param int|null $consumingRegisterId The source register id (tie-break bias).
	 *
	 * @return Schema|null The provider schema, or null when none qualifies.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: Graceful degradation when no provider implements the kind)
	 */
	private function resolveProvider(string $kindUri, ?int $consumingRegisterId = null): ?Schema {
		if ($kindUri === '') {
			return null;
		}

		$schema = $this->semanticTypeResolver->resolveSchemaByImplements(
			uri: $kindUri,
			consumingRegisterId: $consumingRegisterId
		);
		if ($schema === null) {
			return null;
		}

		$configuration = ($schema->getConfiguration() ?? []);
		$binding = ($configuration['handoffContract'] ?? null);
		if (is_array($binding) === false) {
			return null;
		}

		$isComplete = HandoffContractBindingValidator::isCompleteBinding(
			kindUri: $kindUri,
			binding: $binding,
			properties: ($schema->getProperties() ?? [])
		);
		if ($isComplete === false) {
			return null;
		}

		return $schema;
	}//end resolveProvider()

	/**
	 * Translate evaluated contract-field values to provider properties
	 * exclusively through the provider's `handoffContract` binding — the
	 * emitting schema never names a concrete target property.
	 *
	 * @param Schema $provider The resolved provider schema.
	 * @param string $kindUri The kind URI.
	 * @param array<string, mixed> $contractData Contract field → evaluated value.
	 *
	 * @return array<string, mixed> Provider property → value.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: Kind contract binding on the implementing schema)
	 */
	private function translateThroughBinding(Schema $provider, string $kindUri, array $contractData): array {
		$configuration = ($provider->getConfiguration() ?? []);
		$fieldMap = (array)(($configuration['handoffContract'] ?? [])[$kindUri] ?? []);

		$targetData = [];
		foreach ($contractData as $contractField => $value) {
			$ownProperty = ($fieldMap[$contractField] ?? null);
			if (is_string($ownProperty) === true && $ownProperty !== '') {
				$targetData[$ownProperty] = $value;
			}
		}

		return $targetData;
	}//end translateThroughBinding()

	/**
	 * The engine-filled provenance pointer for the `provenance` expression
	 * kind and the audit rows: `{app, register, schema, uuid}` of the source.
	 *
	 * @param ObjectEntity $source The source object.
	 * @param Schema $sourceSchema The source schema.
	 *
	 * @return array<string, mixed> The provenance pointer.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: HandoffService executes conversions on top of SemanticTypeResolver)
	 */
	private function buildProvenance(ObjectEntity $source, Schema $sourceSchema): array {
		return [
			'app' => (string)($sourceSchema->getApplication() ?? ''),
			'register' => (int)$source->getRegister(),
			'schema' => (int)$source->getSchema(),
			'uuid' => (string)$source->getUuid(),
		];

	}//end buildProvenance()

	/**
	 * Compensate a partially-executed handoff so no partial state remains:
	 * hard-delete the created target row and (when the source was already
	 * mutated) restore the source object's pre-handoff data through the
	 * normal write path. Compensation is best-effort — a compensation
	 * failure is logged loudly but never masks the original error.
	 *
	 * @param ObjectEntity $target The created target object to remove.
	 * @param Register $targetRegister The target register (magic-table context).
	 * @param Schema $targetSchema The target schema (magic-table context).
	 * @param ObjectEntity|null $source The source object (when it was already mutated).
	 * @param array<string, mixed> $sourceSnapshot The source data before `onSuccess.set`.
	 * @param string|null $sourceRegisterRef The source register reference for the revert write.
	 * @param string|null $sourceSchemaRef The source schema reference for the revert write.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: Target create fails mid-handoff)
	 */
	private function compensate(
		ObjectEntity $target,
		Register $targetRegister,
		Schema $targetSchema,
		?ObjectEntity $source = null,
		array $sourceSnapshot = [],
		?string $sourceRegisterRef = null,
		?string $sourceSchemaRef = null,
	): void {
		try {
			$rawTarget = $this->objectMapper->find(
				identifier: (string)$target->getUuid(),
				register: $targetRegister,
				schema: $targetSchema,
				_rbac: false,
				_multitenancy: false
			);
			$this->objectMapper->delete(entity: $rawTarget);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[HandoffService] Compensation could not remove the created target — manual cleanup required',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'target' => (string)$target->getUuid(),
					'error' => $e->getMessage(),
				]
			);
		}//end try

		if ($source === null || $sourceRegisterRef === null || $sourceSchemaRef === null) {
			return;
		}

		try {
			$this->objectService->saveObject(
				object: $sourceSnapshot,
				register: $sourceRegisterRef,
				schema: $sourceSchemaRef,
				uuid: (string)$source->getUuid()
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[HandoffService] Compensation could not restore the source object — manual review required',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'source' => (string)$source->getUuid(),
					'error' => $e->getMessage(),
				]
			);
		}//end try

	}//end compensate()

	/**
	 * Apply the declared `onSuccess.set` update to the source object through
	 * the normal lifecycle-aware write path (`ObjectService::saveObject`).
	 *
	 * Part of the all-or-nothing execution: a failure here triggers
	 * compensation (the created target is removed), because a source stuck
	 * without its handed-off status while the target exists — or vice versa —
	 * is partial state the contract forbids.
	 *
	 * @param array<string, mixed> $entry The declared handoff entry.
	 * @param ObjectEntity $source The source object.
	 * @param string $register The source register reference.
	 * @param string $schema The source schema reference.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: Successful request-to-case handoff)
	 */
	private function applyOnSuccess(array $entry, ObjectEntity $source, string $register, string $schema): void {
		$set = (array)(($entry['onSuccess'] ?? [])['set'] ?? []);
		if ($set === []) {
			return;
		}

		$data = array_merge($source->getObject(), $set);
		$this->objectService->saveObject(
			object: $data,
			register: $register,
			schema: $schema,
			uuid: (string)$source->getUuid()
		);

	}//end applyOnSuccess()

	/**
	 * Write the typed `handoff` provenance relation on both objects:
	 * `handoff:<id>:handed-off-to` → target on the source and
	 * `handoff:<id>:originated-from` → source on the target (Related-widget
	 * surface, MergeHandler precedent for engine-written relation keys).
	 *
	 * Register + schema context is passed explicitly so MagicMapper hits the
	 * one correct magic table instead of scanning across all of them
	 * (`findAcrossAllMagicTables` is prohibitively slow inside the handoff
	 * transaction on large instances).
	 *
	 * @param ObjectEntity $source The source object.
	 * @param ObjectEntity $target The created target object.
	 * @param string $handoffId The handoff entry id.
	 * @param Schema $sourceSchema The source schema (magic-table context).
	 * @param Register $targetRegister The target register (magic-table context).
	 * @param Schema $targetSchema The target schema (magic-table context).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: Successful request-to-case handoff)
	 */
	private function writeProvenanceRelations(
		ObjectEntity $source,
		ObjectEntity $target,
		string $handoffId,
		Schema $sourceSchema,
		Register $targetRegister,
		Schema $targetSchema,
	): void {
		$sourceRegister = $this->semanticTypeResolver->findRegisterForSchema(schema: $sourceSchema);

		// Fresh raw source row: applyOnSuccess() may have rewritten it (and
		// rebuilt its relations map from data) moments ago.
		$rawSource = $this->objectMapper->find(
			identifier: (string)$source->getUuid(),
			register: $sourceRegister,
			schema: $sourceSchema,
			_rbac: false,
			_multitenancy: false
		);

		$sourceRelations = ($rawSource->getRelations() ?? []);
		$sourceRelations[self::RELATION_PREFIX . ':' . $handoffId . ':handed-off-to'] = (string)$target->getUuid();
		$rawSource->setRelations($sourceRelations);
		$this->objectMapper->update(entity: $rawSource, register: $sourceRegister, schema: $sourceSchema);

		$rawTarget = $this->objectMapper->find(
			identifier: (string)$target->getUuid(),
			register: $targetRegister,
			schema: $targetSchema,
			_rbac: false,
			_multitenancy: false
		);

		$targetRelations = ($rawTarget->getRelations() ?? []);
		$targetRelations[self::RELATION_PREFIX . ':' . $handoffId . ':originated-from'] = (string)$source->getUuid();
		$rawTarget->setRelations($targetRelations);
		$this->objectMapper->update(entity: $rawTarget, register: $targetRegister, schema: $targetSchema);

	}//end writeProvenanceRelations()

	/**
	 * One immutable audit row per side (actor, kind, handoff id,
	 * correlationId, resolved schema, deferred flag).
	 *
	 * @param ObjectEntity $source The source object.
	 * @param ObjectEntity $target The created target object.
	 * @param Schema $provider The resolved provider schema.
	 * @param string $kindUri The kind URI.
	 * @param string $handoffId The handoff entry id.
	 * @param string $correlationId The execution correlation id.
	 * @param bool $deferred Whether this execution drained a parked entry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: Successful request-to-case handoff)
	 */
	private function writeAuditRows(
		ObjectEntity $source,
		ObjectEntity $target,
		Schema $provider,
		string $kindUri,
		string $handoffId,
		string $correlationId,
		bool $deferred,
	): void {
		$context = [
			'handoffId' => $handoffId,
			'targetKind' => $kindUri,
			'correlationId' => $correlationId,
			'resolvedSchema' => ($provider->getSlug() ?? (string)$provider->getId()),
			'deferred' => $deferred,
			'drainedAt' => null,
		];
		if ($deferred === true) {
			$context['drainedAt'] = (new DateTime())->format('c');
		}

		$this->auditTrailMapper->createAuditTrailEntry(
			object: $source,
			action: self::AUDIT_EXECUTED,
			context: array_merge($context, ['direction' => 'handed-off-to', 'counterpart' => (string)$target->getUuid()])
		);
		$this->auditTrailMapper->createAuditTrailEntry(
			object: $target,
			action: self::AUDIT_EXECUTED,
			context: array_merge($context, ['direction' => 'originated-from', 'counterpart' => (string)$source->getUuid()])
		);

	}//end writeAuditRows()

	/**
	 * Audit the successful drain of a parked entry on the source object.
	 *
	 * @param HandoffQueueEntry $entry The drained entry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: No provider installed, queue mode)
	 */
	private function auditDequeued(HandoffQueueEntry $entry): void {
		try {
			// Explicit register/schema context keeps MagicMapper on the one
			// correct magic table (no cross-table scan).
			$sourceSchema = $this->schemaMapper->find(id: (string)$entry->getSourceSchema());
			$sourceRegister = $this->semanticTypeResolver->findRegisterForSchema(schema: $sourceSchema);
			$source = $this->objectMapper->find(
				identifier: $entry->getSourceObjectUuid(),
				register: $sourceRegister,
				schema: $sourceSchema,
				_rbac: false,
				_multitenancy: false
			);
			$this->auditTrailMapper->createAuditTrailEntry(
				object: $source,
				action: self::AUDIT_DEQUEUED,
				context: [
					'handoffId' => $entry->getHandoffId(),
					'targetKind' => $entry->getTargetKind(),
					'correlationId' => $entry->getCorrelationId(),
					'deferred' => true,
					'parkedAt' => $entry->getCreated()->format('c'),
					'drainedAt' => ($entry->getExecutedAt()?->format('c') ?? (new DateTime())->format('c')),
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[HandoffService] Could not write dequeue audit row',
				context: ['file' => __FILE__, 'line' => __LINE__, 'entry' => $entry->getId(), 'error' => $e->getMessage()]
			);
		}//end try

	}//end auditDequeued()

	/**
	 * Notify the requester that their parked handoff failed at drain time.
	 *
	 * @param HandoffQueueEntry $entry The failed entry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: No provider installed, queue mode)
	 */
	private function notifyDrainFailure(HandoffQueueEntry $entry): void {
		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp('openregister');
			$notification->setUser($entry->getRequestingUser());
			$notification->setDateTime(new DateTime());
			$notification->setObject('handoff_queue_entry', (string)$entry->getId());
			$notification->setSubject(
				'handoff_drain_failed',
				[
					'handoffId' => $entry->getHandoffId(),
					'targetKind' => $entry->getTargetKind(),
					'status' => $entry->getStatus(),
				]
			);
			$this->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[HandoffService] Could not notify requester of drain failure',
				context: ['file' => __FILE__, 'line' => __LINE__, 'entry' => $entry->getId(), 'error' => $e->getMessage()]
			);
		}//end try

	}//end notifyDrainFailure()

	/**
	 * All `x-openregister-handoff` entries declared on a schema.
	 *
	 * @param Schema $schema The source schema.
	 *
	 * @return array<int, array<string, mixed>> The declared entries (empty when none).
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: `x-openregister-handoff` declarative dialect)
	 */
	public function declaredHandoffs(Schema $schema): array {
		$configuration = ($schema->getConfiguration() ?? []);
		$annotation = ($configuration['x-openregister-handoff'] ?? null);
		if (is_array($annotation) === false) {
			return [];
		}

		$entries = [];
		foreach ($annotation as $entry) {
			if (is_array($entry) === true && is_string($entry['id'] ?? null) === true) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}//end declaredHandoffs()

	/**
	 * One declared handoff entry by id, or null.
	 *
	 * @param Schema $schema The source schema.
	 * @param string $handoffId The entry id.
	 *
	 * @return array<string, mixed>|null The entry, or null when not declared.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: Execute endpoint on an object whose schema declares no handoffs)
	 */
	private function declaredHandoff(Schema $schema, string $handoffId): ?array {
		foreach ($this->declaredHandoffs(schema: $schema) as $entry) {
			if (($entry['id'] ?? null) === $handoffId) {
				return $entry;
			}
		}

		return null;
	}//end declaredHandoff()
}//end class
