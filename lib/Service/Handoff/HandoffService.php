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
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\HandoffQueueEntry;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\HandoffExecutedEvent;
use OCA\OpenRegister\Event\HandoffExecutedSource;
use OCA\OpenRegister\Event\HandoffExecutedTarget;
use OCA\OpenRegister\Exception\HandoffException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Object\PermissionCheckOptions;
use OCA\OpenRegister\Service\SemanticTypeResolver;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCA\OpenRegister\Service\WriteCause;
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
class HandoffService implements HandoffServiceInterface {

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
	 * @param HandoffObjectAccess $objects The source and target objects, under the caller's RBAC.
	 * @param HandoffProviders $providers The provider schema of a kind and the data mapping onto it.
	 * @param HandoffLedger $ledger The audit rows, the queue, the transaction and the log.
	 * @param HandoffRequesters $requesters The acting user, the requester lookup, notifications and events.
	 */
	public function __construct(
		private readonly HandoffObjectAccess $objects,
		private readonly HandoffProviders $providers,
		private readonly HandoffLedger $ledger,
		private readonly HandoffRequesters $requesters,
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
	 * @param HandoffSourceRef $ref The register and schema (slug or id) and the object id/uuid.
	 *
	 * @return array<int, array<string, mixed>> One availability record per declared handoff.
	 *
	 * @throws \Exception When the object cannot be read by the caller.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: Handoff REST surface)
	 */
	public function listAvailability(HandoffSourceRef $ref): array {
		return $this->availabilityOf(source: $this->loadSource(ref: $ref));
	}//end listAvailability()

	/**
	 * Load a source object under the caller's RBAC (read guard, ADR-005).
	 *
	 * @param HandoffSourceRef $ref The source object as the caller names it.
	 *
	 * @return ObjectEntity The source object.
	 *
	 * @throws HandoffException When the object does not exist.
	 * @throws \Exception       When the object cannot be read by the caller.
	 */
	private function loadSource(HandoffSourceRef $ref): ObjectEntity {
		$source = WriteCause::asLookup(fn () => $this->objects->objectService->find(id: $ref->id, register: $ref->register, schema: $ref->schema));
		if ($source === null) {
			throw new HandoffException(errorCode: HandoffException::NOT_DECLARED, message: 'Object not found.');
		}

		return $source;
	}//end loadSource()

	/**
	 * One availability record per handoff the source's schema declares.
	 *
	 * @param ObjectEntity $source The source object.
	 *
	 * @return array<int, array<string, mixed>> One availability record per declared handoff.
	 */
	private function availabilityOf(ObjectEntity $source): array {
		$sourceSchema = $this->objects->schemaMapper->find(id: (string)$source->getSchema());
		$entries = $this->declaredHandoffs(schema: $sourceSchema);

		$parked = [];
		foreach ($this->ledger->queueMapper->findParkedForObject(sourceObjectUuid: (string)$source->getUuid()) as $entry) {
			$parked[$entry->getHandoffId()] = $entry;
		}

		$availability = [];
		foreach ($entries as $entry) {
			$handoffId = (string)($entry['id'] ?? '');
			$kindUri = (string)($entry['targetSemanticType'] ?? '');
			$record = [
				'id' => $handoffId,
				'targetSemanticType' => $kindUri,
				'trigger' => (string)($entry['trigger'] ?? 'manual'),
				'whenUnavailable' => (string)($entry['whenUnavailable'] ?? 'hide'),
			];

			if (isset($parked[$handoffId]) === true) {
				$availability[] = $record + ['state' => 'queued', 'queueEntry' => $parked[$handoffId]->jsonSerialize()];
				continue;
			}

			$availability[] = $record + $this->providerState(kindUri: $kindUri, source: $source);
		}

		return $availability;
	}//end availabilityOf()

	/**
	 * The `available` / `unavailable` part of one availability record.
	 *
	 * @param string       $kindUri The target kind URI.
	 * @param ObjectEntity $source  The source object (its register biases the provider tie-break).
	 *
	 * @return array<string, mixed> The state and either the provider or the unavailability reason.
	 */
	private function providerState(string $kindUri, ObjectEntity $source): array {
		$provider = $this->resolveProvider(kindUri: $kindUri, consumingRegisterId: (int)$source->getRegister());
		if ($provider === null) {
			return [
				'state' => 'unavailable',
				'reason' => HandoffException::PROVIDER_UNAVAILABLE,
			];
		}

		return [
			'state' => 'available',
			'provider' => [
				'schema' => ($provider->getSlug() ?? (string)$provider->getId()),
				'title' => $provider->getTitle(),
			],
		];
	}//end providerState()

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
		$source = new HandoffSourceRef(register: $register, schema: $schema, id: $id);
		$correlationId = ($correlationId ?? Uuid::v4()->toRfc4122());
		return $this->run(request: new HandoffRequest(source: $source, handoffId: $handoffId, deferred: $deferred, correlationId: $correlationId));
	}//end execute()

	/**
	 * Run one handoff request: the flow documented on {@see self::execute()}.
	 *
	 * @param HandoffRequest $request The request.
	 *
	 * @return array<string, mixed> The executed or parked result.
	 *
	 * @throws HandoffException For not-declared / provider-unavailable (hide mode).
	 * @throws NotAuthorizedException When the caller lacks write-on-source or create-on-target.
	 * @throws \Exception Underlying write-path errors (surfaced after rollback).
	 */
	private function run(HandoffRequest $request): array {
		// 1-2. Load the source under the caller's RBAC (read) and read the declared entry.
		$plan = $this->plan(request: $request);

		// Write permission on the source, as the calling user (never escalate).
		$this->objects->permissionHandler->checkPermission(
			schema: $plan->sourceSchema,
			action: 'update',
			options: new PermissionCheckOptions(objectOwner: $plan->source->getOwner(), object: $plan->source)
		);

		// 3. Resolve the provider (complete-binding filter on the resolver result).
		$provider = $this->resolveProvider(kindUri: $plan->kindUri(), consumingRegisterId: (int)$plan->source->getRegister());
		if ($provider === null) {
			return $this->degrade(plan: $plan);
		}

		$targetRegister = $this->providers->semanticTypeResolver->findRegisterForSchema(schema: $provider);
		if ($targetRegister === null) {
			// An orphaned provider schema cannot receive objects — same degradation path.
			return $this->degrade(plan: $plan);
		}

		// 4. RBAC pre-check: create on the resolved target schema, as the caller — typed 403 before any write.
		$this->objects->permissionHandler->checkPermission(schema: $provider, action: 'create');

		// 5. Evaluate the mapping and translate contract fields → provider properties via the binding.
		$targetData = $this->targetData(plan: $plan, provider: $provider);

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
		$target = $this->objects->objectService->saveObject(
			object: $targetData,
			register: $targetRegister,
			schema: $provider
		);

		$handoff = new HandoffRun(plan: $plan, target: new HandoffTarget(object: $target, register: $targetRegister, schema: $provider));

		$sourceSnapshot = $plan->source->getObject();
		try {
			$this->applyOnSuccess(plan: $plan);
		} catch (\Throwable $e) {
			$this->compensate(target: $handoff->target);
			throw $e;
		}

		$this->recordProvenance(run: $handoff, sourceSnapshot: $sourceSnapshot);

		// 7. Post-commit: dispatch the ADR-041 event (a throwing listener can never roll back the handoff).
		$this->dispatchExecuted(run: $handoff);

		return [
			'status' => 'executed',
			'correlationId' => $request->correlationId,
			'target' => [
				'register' => (int)$target->getRegister(),
				'schema' => (int)$target->getSchema(),
				'uuid' => (string)$target->getUuid(),
			],
		];
	}//end run()

	/**
	 * Load the source and its schema and read the declared entry the request names.
	 *
	 * @param HandoffRequest $request The request.
	 *
	 * @return HandoffPlan The request with its source, source schema and declared entry.
	 *
	 * @throws HandoffException When the object does not exist or declares no such handoff.
	 */
	private function plan(HandoffRequest $request): HandoffPlan {
		$source = $this->loadSource(ref: $request->source);
		$sourceSchema = $this->objects->schemaMapper->find(id: (string)$source->getSchema());

		// The declared entry — `handoff-not-declared` when absent.
		$entry = $this->declaredHandoff(schema: $sourceSchema, handoffId: $request->handoffId);
		if ($entry === null) {
			throw new HandoffException(
				errorCode: HandoffException::NOT_DECLARED,
				message: sprintf(
					'Schema "%s" declares no handoff with id "%s".',
					(string)($sourceSchema->getSlug() ?? $sourceSchema->getId()),
					$request->handoffId
				)
			);
		}

		return new HandoffPlan(request: $request, source: $source, sourceSchema: $sourceSchema, entry: $entry);
	}//end plan()

	/**
	 * Evaluate the declared mapping and translate it onto the provider's properties.
	 *
	 * @param HandoffPlan $plan     The plan.
	 * @param Schema      $provider The resolved provider schema.
	 *
	 * @return array<string, mixed> The target object data.
	 */
	private function targetData(HandoffPlan $plan, Schema $provider): array {
		$provenance = $this->buildProvenance(source: $plan->source, sourceSchema: $plan->sourceSchema);
		$contractData = $this->providers->mappingEvaluator->evaluate(
			mapping: (array)($plan->entry['mapping'] ?? []),
			source: new HandoffMappingSource(data: $plan->source->getObject(), provenance: $provenance)
		);

		return $this->translateThroughBinding(
			fieldMap: $this->bindingFieldMap(provider: $provider, kindUri: $plan->kindUri()),
			contractData: $contractData
		);
	}//end targetData()

	/**
	 * Write the provenance relations and audit rows in one DB transaction;
	 * on failure roll back, remove the target and restore the source.
	 *
	 * @param HandoffRun           $run            The plan and the created target object.
	 * @param array<string, mixed> $sourceSnapshot The source data before `onSuccess.set`.
	 *
	 * @return void
	 *
	 * @throws \Throwable The original failure, after rollback and compensation.
	 */
	private function recordProvenance(HandoffRun $run, array $sourceSnapshot): void {
		$plan = $run->plan;
		$this->ledger->db->beginTransaction();
		try {
			$this->writeProvenanceRelations(run: $run);
			$this->writeAuditRows(run: $run);

			$this->ledger->db->commit();
		} catch (\Throwable $e) {
			$this->ledger->db->rollBack();
			$this->compensate(
				target: $run->target,
				restore: new HandoffSourceRestore(
					source: $plan->source,
					snapshot: $sourceSnapshot,
					registerRef: $plan->request->source->register,
					schemaRef: $plan->request->source->schema
				)
			);
			throw $e;
		}//end try
	}//end recordProvenance()

	/**
	 * Dispatch the ADR-041 handoff-executed event.
	 *
	 * @param HandoffRun $run The plan and the created target object.
	 *
	 * @return void
	 */
	private function dispatchExecuted(HandoffRun $run): void {
		$plan = $run->plan;
		$target = $run->target->object;
		$source = $plan->source;
		$this->requesters->eventDispatcher->dispatchTyped(
			new HandoffExecutedEvent(
				source: new HandoffExecutedSource(
					app: (string)($plan->sourceSchema->getApplication() ?? ''),
					object: new ObjectRef(
						register: (int)$source->getRegister(),
						schema: (int)$source->getSchema(),
						id: (string)$source->getUuid()
					),
					label: $source->getName()
				),
				target: new HandoffExecutedTarget(
					semanticType: $plan->kindUri(),
					object: new ObjectRef(
						register: (int)$target->getRegister(),
						schema: (int)$target->getSchema(),
						id: (string)$target->getUuid()
					)
				),
				request: $plan->request
			)
		);
	}//end dispatchExecuted()

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
		$entries = $this->parkedEntries(kindUri: $kindUri);

		$summary = [
			'drained' => 0,
			'failed' => 0,
			'skipped' => 0,
		];

		foreach ($entries as $entry) {
			$summary[$this->drainOutcome(entry: $entry)]++;
		}

		return $summary;
	}//end drainParked()

	/**
	 * The parked queue entries to drain.
	 *
	 * @param string|null $kindUri Only entries parked for this kind (null = all parked kinds).
	 *
	 * @return HandoffQueueEntry[] The parked entries.
	 */
	private function parkedEntries(?string $kindUri): array {
		if ($kindUri !== null) {
			return $this->ledger->queueMapper->findParkedByKind(kindUri: $kindUri);
		}

		return $this->ledger->queueMapper->findParked();
	}//end parkedEntries()

	/**
	 * Drain one parked entry and name its summary bucket.
	 *
	 * @param HandoffQueueEntry $entry The parked entry.
	 *
	 * @return string `skipped` (still no provider, or inside a system-operation scope), `drained` or `failed`.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SystemOperationContext::isActive is a process-scoped flag with no instance to inject.
	 */
	private function drainOutcome(HandoffQueueEntry $entry): string {
		// Inside a system-operation scope every permission check passes, so the
		// requester's own rights could not be re-evaluated: leave it parked.
		if (SystemOperationContext::isActive() === true) {
			return 'skipped';
		}

		// Cheap pre-check: still no provider → leave parked.
		$this->providers->semanticTypeResolver->clearCache();
		if ($this->resolveProvider(kindUri: $entry->getTargetKind(), consumingRegisterId: $entry->getSourceRegister()) === null) {
			return 'skipped';
		}

		if ($this->drainEntry(entry: $entry) === true) {
			return 'drained';
		}

		return 'failed';
	}//end drainOutcome()

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

		$requester = $this->requesters->userManager->get($entry->getRequestingUser());
		if ($requester === null) {
			$entry->setStatus(HandoffQueueEntry::STATUS_FAILED_PERMISSION);
			$entry->setLastError('Requesting user no longer exists.');
			$this->ledger->queueMapper->update(entity: $entry);
			return false;
		}

		$previousUser = $this->requesters->userSession->getUser();
		$this->requesters->userSession->setUser($requester);

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
			$this->ledger->queueMapper->update(entity: $entry);

			$this->auditDequeued(entry: $entry);
			return true;
		} catch (NotAuthorizedException $e) {
			// RBAC re-evaluated at drain time refused: never escalate.
			$entry->setStatus(HandoffQueueEntry::STATUS_FAILED_PERMISSION);
			$entry->setLastError($e->getMessage());
			$this->ledger->queueMapper->update(entity: $entry);
			$this->notifyDrainFailure(entry: $entry);
			return false;
		} catch (\Throwable $e) {
			$entry->setStatus(HandoffQueueEntry::STATUS_FAILED_VALIDATION);
			$entry->setLastError($e->getMessage());
			$this->ledger->queueMapper->update(entity: $entry);
			$this->notifyDrainFailure(entry: $entry);
			return false;
		} finally {
			$this->requesters->userSession->setUser($previousUser);
		}//end try

	}//end drainEntry()

	/**
	 * Degrade when no provider implements the kind: `hide` → typed
	 * provider-unavailable error (409-class, never 5xx); `queue` → park a
	 * durable queue entry + audit + 202-style parked result.
	 *
	 * @param HandoffPlan $plan The plan whose kind has no provider.
	 *
	 * @return array<string, mixed> The parked result (queue mode only).
	 *
	 * @throws HandoffException In hide mode (provider unavailable).
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: No provider installed, hide mode)
	 */
	private function degrade(HandoffPlan $plan): array {
		$entry = $plan->entry;
		$source = $plan->source;
		$kindUri = $plan->kindUri();
		$handoffId = $plan->request->handoffId;
		$mode = (string)($entry['whenUnavailable'] ?? 'hide');
		if ($mode !== 'queue') {
			throw new HandoffException(
				errorCode: HandoffException::PROVIDER_UNAVAILABLE,
				message: sprintf('No installed schema implements "%s"; the source object keeps working standalone.', $kindUri)
			);
		}

		$requestingUser = ($this->requesters->userSession->getUser()?->getUID() ?? '');
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

		$queueEntry = $this->ledger->queueMapper->insert(entity: $queueEntry);

		$this->ledger->auditTrailMapper->recordObjectEvent(
			event: new ObjectAuditEvent(
				object: $source,
				action: self::AUDIT_QUEUED,
				context: [
					'handoffId' => $handoffId,
					'targetKind' => $kindUri,
					'correlationId' => $correlationId,
					'deferred' => true,
					'parkedAt' => $queueEntry->getCreated()->format('c'),
				]
			)
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

		$schema = $this->providers->semanticTypeResolver->resolveSchemaByImplements(
			uri: $kindUri,
			consumingRegisterId: $consumingRegisterId
		);
		if ($schema === null) {
			return null;
		}

		if ($this->hasCompleteBinding(schema: $schema, kindUri: $kindUri) === false) {
			return null;
		}

		return $schema;
	}//end resolveProvider()

	/**
	 * Whether a schema carries a complete `handoffContract` binding for a kind.
	 *
	 * @param Schema $schema  The candidate provider schema.
	 * @param string $kindUri The canonical kind URI.
	 *
	 * @return bool True when the binding is an array and complete.
	 */
	private function hasCompleteBinding(Schema $schema, string $kindUri): bool {
		$configuration = ($schema->getConfiguration() ?? []);
		$binding = ($configuration['handoffContract'] ?? null);
		if (is_array($binding) === false) {
			return false;
		}

		return HandoffContractBindingValidator::isCompleteBinding(
			kind: new HandoffKindBinding(kindUri: $kindUri, fieldMap: ($binding[$kindUri] ?? null), properties: ($schema->getProperties() ?? []))
		);
	}//end hasCompleteBinding()

	/**
	 * Translate evaluated contract-field values to provider properties
	 * exclusively through the provider's `handoffContract` binding — the
	 * emitting schema never names a concrete target property.
	 *
	 * @param array<string, mixed> $fieldMap     Contract field → provider property, from {@see self::bindingFieldMap()}.
	 * @param array<string, mixed> $contractData Contract field → evaluated value.
	 *
	 * @return array<string, mixed> Provider property → value.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: Kind contract binding on the implementing schema)
	 */
	private function translateThroughBinding(array $fieldMap, array $contractData): array {
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
	 * The provider's `handoffContract` binding for one kind: contract field → provider property.
	 *
	 * @param Schema $provider The resolved provider schema.
	 * @param string $kindUri  The kind URI.
	 *
	 * @return array<string, mixed> The field map (empty when the provider binds nothing for the kind).
	 */
	private function bindingFieldMap(Schema $provider, string $kindUri): array {
		$configuration = ($provider->getConfiguration() ?? []);

		return (array)(($configuration['handoffContract'] ?? [])[$kindUri] ?? []);
	}//end bindingFieldMap()

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
	 * @param HandoffTarget $target The created target object to remove, in its magic-table context.
	 * @param HandoffSourceRestore|null $restore The source to restore, when it was already mutated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: Target create fails mid-handoff)
	 */
	private function compensate(
		HandoffTarget $target,
		?HandoffSourceRestore $restore = null,
	): void {
		try {
			$rawTarget = $this->objects->objectMapper->find(
				identifier: (string)$target->object->getUuid(),
				register: $target->register,
				schema: $target->schema,
				_rbac: false,
				_multitenancy: false
			);
			$this->objects->objectMapper->delete(entity: $rawTarget);
		} catch (\Throwable $e) {
			$this->ledger->logger->error(
				message: '[HandoffService] Compensation could not remove the created target — manual cleanup required',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'target' => (string)$target->object->getUuid(),
					'error' => $e->getMessage(),
				]
			);
		}//end try

		if ($restore === null) {
			return;
		}

		try {
			$this->objects->objectService->saveObject(
				object: $restore->snapshot,
				register: $restore->registerRef,
				schema: $restore->schemaRef,
				uuid: (string)$restore->source->getUuid()
			);
		} catch (\Throwable $e) {
			$this->ledger->logger->error(
				message: '[HandoffService] Compensation could not restore the source object — manual review required',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'source' => (string)$restore->source->getUuid(),
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
	 * @param HandoffPlan $plan The plan (declared entry, source and its register/schema references).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: Successful request-to-case handoff)
	 */
	private function applyOnSuccess(HandoffPlan $plan): void {
		$set = (array)(($plan->entry['onSuccess'] ?? [])['set'] ?? []);
		if ($set === []) {
			return;
		}

		$data = array_merge($plan->source->getObject(), $set);
		$this->objects->objectService->saveObject(
			object: $data,
			register: $plan->request->source->register,
			schema: $plan->request->source->schema,
			uuid: (string)$plan->source->getUuid()
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
	 * @param HandoffRun $run The plan (source, source schema as magic-table context, handoff id)
	 *                        and the created target object, in its magic-table context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: Successful request-to-case handoff)
	 */
	private function writeProvenanceRelations(HandoffRun $run): void {
		$plan = $run->plan;
		$target = $run->target;
		$source = $plan->source;
		$sourceSchema = $plan->sourceSchema;
		$handoffId = $plan->request->handoffId;
		$sourceRegister = $this->providers->semanticTypeResolver->findRegisterForSchema(schema: $sourceSchema);

		// Fresh raw source row: applyOnSuccess() may have rewritten it (and
		// rebuilt its relations map from data) moments ago.
		$rawSource = $this->objects->objectMapper->find(
			identifier: (string)$source->getUuid(),
			register: $sourceRegister,
			schema: $sourceSchema,
			_rbac: false,
			_multitenancy: false
		);

		$sourceRelations = ($rawSource->getRelations() ?? []);
		$sourceRelations[self::RELATION_PREFIX . ':' . $handoffId . ':handed-off-to'] = (string)$target->object->getUuid();
		$rawSource->setRelations($sourceRelations);
		$this->objects->objectMapper->update(entity: $rawSource, register: $sourceRegister, schema: $sourceSchema);

		$rawTarget = $this->objects->objectMapper->find(
			identifier: (string)$target->object->getUuid(),
			register: $target->register,
			schema: $target->schema,
			_rbac: false,
			_multitenancy: false
		);

		$targetRelations = ($rawTarget->getRelations() ?? []);
		$targetRelations[self::RELATION_PREFIX . ':' . $handoffId . ':originated-from'] = (string)$source->getUuid();
		$rawTarget->setRelations($targetRelations);
		$this->objects->objectMapper->update(entity: $rawTarget, register: $target->register, schema: $target->schema);

	}//end writeProvenanceRelations()

	/**
	 * One immutable audit row per side (actor, kind, handoff id,
	 * correlationId, resolved schema, deferred flag).
	 *
	 * @param HandoffRun $run The plan (source, kind, handoff id, correlation id, deferred flag) and
	 *                        the created target object; its schema is the resolved provider.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: Successful request-to-case handoff)
	 */
	private function writeAuditRows(HandoffRun $run): void {
		$plan = $run->plan;
		$target = $run->target;
		$source = $plan->source;
		$context = [
			'handoffId' => $plan->request->handoffId,
			'targetKind' => $plan->kindUri(),
			'correlationId' => $plan->request->correlationId,
			'resolvedSchema' => ($target->schema->getSlug() ?? (string)$target->schema->getId()),
			'deferred' => $plan->request->deferred,
			'drainedAt' => null,
		];
		if ($plan->request->deferred === true) {
			$context['drainedAt'] = (new DateTime())->format('c');
		}

		$this->ledger->auditTrailMapper->recordObjectEvent(
			event: new ObjectAuditEvent(
				object: $source,
				action: self::AUDIT_EXECUTED,
				context: array_merge($context, ['direction' => 'handed-off-to', 'counterpart' => (string)$target->object->getUuid()])
			)
		);
		$this->ledger->auditTrailMapper->recordObjectEvent(
			event: new ObjectAuditEvent(
				object: $target->object,
				action: self::AUDIT_EXECUTED,
				context: array_merge($context, ['direction' => 'originated-from', 'counterpart' => (string)$source->getUuid()])
			)
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
			$sourceSchema = $this->objects->schemaMapper->find(id: (string)$entry->getSourceSchema());
			$sourceRegister = $this->providers->semanticTypeResolver->findRegisterForSchema(schema: $sourceSchema);
			$source = $this->objects->objectMapper->find(
				identifier: $entry->getSourceObjectUuid(),
				register: $sourceRegister,
				schema: $sourceSchema,
				_rbac: false,
				_multitenancy: false
			);
			$this->ledger->auditTrailMapper->recordObjectEvent(
				event: new ObjectAuditEvent(
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
				)
			);
		} catch (\Throwable $e) {
			$this->ledger->logger->warning(
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
			$notification = $this->requesters->notificationManager->createNotification();
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
					// The shipped template names `{{target}}` and `{{reason}}`.
					// Supplied beside the existing keys, never instead of them:
					// the unedited rendering reads `targetKind` and `status`.
					'target' => $entry->getTargetKind(),
					'reason' => $entry->getStatus(),
				]
			);
			$this->requesters->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->ledger->logger->warning(
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
