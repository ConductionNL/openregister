<?php

/**
 * The flow surface apps consume.
 *
 * This is to flows what `ObjectService` is to objects: the one entry point an
 * app uses, so no app needs its own flow service, controller or store (ADR-022).
 * Every method here is REQUEST-facing and organisation-scoped. Engine-internal
 * resolution — the queue worker loading a flow it was handed the id of, the
 * trigger matcher listing candidates — goes through `FlowMapper` directly,
 * because those paths run with no session and must not be forced through a
 * scoping check they can never satisfy.
 *
 * Keeping the two apart is deliberate: a single service that took an "internal"
 * flag would make the guard opt-out, and an opt-out guard is one forgotten
 * argument away from being no guard.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunStepMapper;
use OCA\OpenRegister\Db\FlowStateMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads, writes and runs flows on behalf of a request.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The service is the single seam
 * between the flow API and the five stores a flow touches — flows, runs, steps,
 * state and the resolvers — plus the advancer that executes one. Splitting it to
 * satisfy the count would only move the same collaborators behind a facade that
 * itself depends on all of them.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Every constructor parameter is
 * an injected collaborator; Nextcloud's container wires them by type.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */
class FlowService {
	/**
	 * The app id a flow is attributed to when the caller names none.
	 *
	 * @var string
	 */
	private const DEFAULT_APP = 'openregister';

	/**
	 * Constructor.
	 *
	 * @param FlowMapper $mapper Reads and writes flow definitions.
	 * @param FlowTriggerIndex $triggerIndex Keeps the trigger index derived from the nodes.
	 * @param FlowRunService $runner Queues and executes runs.
	 * @param FlowRunAdvancer $advancer Advances a run inline for a synchronous caller.
	 * @param FlowRunMapper $runs Sweeps a deleted flow's runs.
	 * @param FlowRunStepMapper $steps Sweeps those runs' step rows.
	 * @param FlowStateMapper $state Sweeps a deleted flow's carried state.
	 * @param IUserSession $userSession Identifies the acting user.
	 * @param LoggerInterface $logger Records refusals and failures.
	 * @param ContainerInterface $container Resolves OrganisationService lazily.
	 */
	public function __construct(
		private readonly FlowMapper $mapper,
		private readonly FlowTriggerIndex $triggerIndex,
		private readonly FlowRunService $runner,
		private readonly FlowRunAdvancer $advancer,
		private readonly FlowRunMapper $runs,
		private readonly FlowRunStepMapper $steps,
		private readonly FlowStateMapper $state,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ContainerInterface $container,
	) {

	}//end __construct()

	/**
	 * List the caller's flows, newest first.
	 *
	 * @param string|null $app Restrict to one owning app id.
	 * @param string|null $applicationSlug Restrict to one OpenBuild virtual-app slug.
	 * @param boolean|null $enabled Restrict to enabled or disabled flows.
	 * @param integer $limit Page size.
	 * @param integer $offset Page offset.
	 *
	 * @return array<int, Flow> The flows visible to the caller.
	 *
	 * @spec openspec/changes/flow-application-slug/specs/flow-engine/spec.md
	 */
	public function findAll(
		?string $app = null,
		?string $applicationSlug = null,
		?bool $enabled = null,
		int $limit = 100,
		int $offset = 0,
	): array {
		$organisation = $this->activeOrganisation();
		if ($organisation === null) {
			// No resolvable tenant means no flows, never every tenant's flows.
			return [];
		}

		return $this->mapper->findAllFlows(
			app: $app,
			applicationSlug: $applicationSlug,
			organisation: $organisation,
			enabled: $enabled,
			limit: $limit,
			offset: $offset
		);

	}//end findAll()

	/**
	 * Count the flows visible to the caller.
	 *
	 * @param string|null $app Restrict to one owning app id.
	 * @param string|null $applicationSlug Restrict to one OpenBuild virtual-app slug.
	 *
	 * @return integer The number of matching flows.
	 *
	 * @spec openspec/changes/flow-application-slug/specs/flow-engine/spec.md
	 */
	public function count(?string $app = null, ?string $applicationSlug = null): int {
		$organisation = $this->activeOrganisation();
		if ($organisation === null) {
			return 0;
		}

		return $this->mapper->countFlows(app: $app, applicationSlug: $applicationSlug, organisation: $organisation);
	}//end count()

	/**
	 * The ids of the flows the acting user owns.
	 *
	 * Used by the run-history visibility rule, which shows a caller the runs
	 * they triggered PLUS the runs of flows they own — the second half matters
	 * because `triggered_by` is null for cron- and trigger-fired runs.
	 *
	 * @return array<int, string> The flow uuids.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	public function idsOwnedByCaller(): array {
		$uid = $this->actingUser();
		if ($uid === null) {
			return [];
		}

		try {
			return $this->mapper->findIdsOwnedBy($uid);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FlowService] Could not list the caller\'s owned flows: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return [];
		}

	}//end idsOwnedByCaller()

	/**
	 * Load one flow the caller is allowed to see.
	 *
	 * A flow the caller may not see raises the SAME exception as a flow that
	 * does not exist. Distinguishing them would turn every read into an oracle
	 * for enumerating other tenants' flow ids.
	 *
	 * @param string $uuid The flow uuid.
	 *
	 * @return Flow The flow.
	 *
	 * @throws DoesNotExistException When no such flow exists, or it is not the caller's.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	public function find(string $uuid): Flow {
		$flow = $this->mapper->findByUuid($uuid);

		if ($flow->belongsTo($this->activeOrganisation()) === false) {
			throw new DoesNotExistException('No such flow');
		}

		return $flow;
	}//end find()

	/**
	 * Create or update a flow.
	 *
	 * `owner` and `organisation` are SERVER-STAMPED on create and never taken
	 * from the payload. They are ownership of the DEFINITION — who may edit this
	 * flow and which tenant it belongs to — and accepting a client-supplied one
	 * would let an author hand their flow to another tenant, or claim another
	 * user's. For the same reason an update carries the stored owner forward
	 * rather than re-reading it from the request.
	 *
	 * 🔴 Owner is NOT the identity a triggered run executes as. It was, and
	 * ADR-099 removed that: whose rights a run uses now comes from its TRIGGER
	 * node, because authoring a flow is not consent to unattended execution as
	 * the author. Do not restore the old reading — it is the reason a scheduled
	 * run could act as somebody who never asked.
	 *
	 * A CREATE is refused outright when either cannot be resolved. See
	 * flowToSave(): a flow with no organisation belongs to nobody and can never
	 * be listed, found, edited or run again, so accepting the write only buys a
	 * silent orphan.
	 *
	 * Trigger nodes are validated here, before the write — see
	 * {@see FlowTriggerValidator}. Connectivity still only WARNS, per `flow-engine`.
	 *
	 * @param array<string, mixed> $data The flow's fields.
	 * @param string|null $uuid The flow to update, or null to create.
	 *
	 * @return Flow The stored flow.
	 *
	 * @throws DoesNotExistException When updating a flow that is not the caller's,
	 *                               or creating one with no owner / organisation.
	 * @throws \InvalidArgumentException When a trigger node rejects its own config.
	 * @throws FlowLifecycleRefused When the definition changes and the head is not a draft.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	public function save(array $data, ?string $uuid = null): Flow {
		$flow = $this->flowToSave(data: $data, uuid: $uuid);

		// 🔴 THE GRAPH BEFORE THE WRITE, captured while the entity still holds
		// what is stored. `applyEditableFields()` mutates it in place, so
		// reading afterwards would compare the incoming graph with itself and
		// the refusal would never fire.
		$graphBefore = $this->graphSignature(flow: $flow);

		$this->applyEditableFields(flow: $flow, data: $data);
		(new FlowTriggerValidator($this->container, $this->logger))->validate(flow: $flow);

		// A published version is immutable. Only a DEFINITION change is
		// refused, deliberately: renaming a flow, editing its description or
		// switching it off are not changes to the process, and refusing them
		// would make a published flow unmanageable rather than merely
		// uneditable.
		if ($this->graphSignature(flow: $flow) !== $graphBefore) {
			(new FlowLifecycleGuard(
				$this->runs,
				$this->container->get(FlowNodePreflight::class),
				$this->logger
			))->refuseEditUnlessDraft(
				flowId: (string)$flow->getUuid(),
				state: $flow->getLifecycleStatus()
			);
		}

		$flow->setUpdated(new DateTime());

		$stored = $this->persistFlow(flow: $flow, uuid: $uuid);

		// 🔑 THE TRIGGER SET FOLLOWS THE PUBLISHED VERSION, NOT THE HEAD. This
		// used to re-derive from whatever was just saved, which under
		// versioning would subscribe a DRAFT's trigger nodes and unsubscribe
		// the published version's — the opposite of both rules. Deriving from
		// the published version is also what keeps an `enabled` toggle working
		// while a draft is open.
		$this->triggerIndex->reindex(flow: $stored);

		return $stored;
	}//end save()

	/**
	 * A value that changes exactly when the flow's definition changes.
	 *
	 * Compared, not stored. Only the four keys that make up the graph are
	 * included — the same four `FlowDefinitionPin` pins — so that metadata
	 * edits do not read as definition edits.
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return string The signature.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	private function graphSignature(Flow $flow): string {
		return (string)json_encode([
			'nodes' => ($flow->getNodes() ?? []),
			'edges' => ($flow->getEdges() ?? []),
			'limits' => ($flow->getLimits() ?? []),
			'executionMode' => (string)($flow->getExecutionMode() ?? Flow::MODE_ASYNC),
		]);
	}//end graphSignature()



	/**
	 * Insert a new flow, or update the existing one.
	 *
	 * Extracted from `save()` so the create/update branch is expressed as two
	 * returns rather than an if/else — the `ElseExpression` rule is on in
	 * phpmd.xml, and the alternatives were worse: assigning in both arms of an
	 * `if`/`if` leaves `$stored` possibly-undefined for Psalm and PHPStan, and a
	 * ternary trips the `Inline IF statements are not allowed` sniff in
	 * phpcs.xml. It takes the uuid rather than a boolean so it does not
	 * introduce a `BooleanArgumentFlag` finding in place of the one it removes.
	 *
	 * @param Flow $flow The flow to write.
	 * @param string|null $uuid The flow being updated, or null/empty to create.
	 *
	 * @return Flow The stored flow, as the mapper returned it.
	 */
	private function persistFlow(Flow $flow, ?string $uuid): Flow {
		if ($uuid === null || $uuid === '') {
			return $this->mapper->insert($flow);
		}

		return $this->mapper->update($flow);
	}//end persistFlow()

	/**
	 * The flow a save() will write to: a fresh one, or the caller's existing one.
	 *
	 * `owner` and `organisation` are stamped from the server here and are not in
	 * `applyEditableFields`'s allowlist, so a create cannot claim another user's
	 * identity by putting `owner` in the payload.
	 *
	 * @param array<string, mixed> $data The incoming fields.
	 * @param string|null $uuid The flow uuid, or null to create.
	 *
	 * @return Flow The flow to apply fields to.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	private function flowToSave(array $data, ?string $uuid): Flow {
		if ($uuid !== null && $uuid !== '') {
			// Goes through find(), so an update to a flow the caller cannot see
			// is refused with the same "no such flow" as a missing one.
			return $this->find(uuid: $uuid);
		}

		['owner' => $owner, 'organisation' => $organisation] = $this->callerOwnership();

		// REFUSE rather than stamp nulls. `Flow::belongsTo()` is fail-closed on
		// both sides, so a flow with no organisation belongs to nobody: it does
		// not appear in index(), find() refuses it, and it can never be run or
		// edited again. Accepting the write produced a permanent orphan and
		// reported success — the caller had no way to tell that from a flow
		// that saved. index() and count() already refuse a null organisation;
		// this is the same rule on the write side, where it costs more.
		//
		// It also holds the invariant the run side depends on: a flow is where
		// a scheduled or event-fired run gets its identity from, so a flow
		// without one makes every run it fires unattributable.
		if ($owner === null || $organisation === null) {
			throw new DoesNotExistException(
				'A flow needs a signed-in owner and an active organisation; refusing to create one that belongs to nobody.'
			);
		}

		$flow = new Flow();
		$flow->setUuid($this->newUuid());
		$flow->setCreated(new DateTime());
		$flow->setApp((string)($data['app'] ?? self::DEFAULT_APP));
		$flow->setOwner($owner);
		$flow->setOrganisation($organisation);

		return $flow;
	}//end flowToSave()

	/**
	 * Copy the client-editable fields onto a flow.
	 *
	 * The allowlist IS the security boundary: `owner`, `organisation`, `uuid`
	 * and the timestamps are deliberately absent, so no payload can reach them.
	 * Only keys actually present are touched, so a partial update does not null
	 * the fields it omitted.
	 *
	 * @param Flow $flow The flow to mutate.
	 * @param array<string, mixed> $data The incoming fields.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	private function applyEditableFields(Flow $flow, array $data): void {
		$strings = [
			'name' => 'setName',
			'description' => 'setDescription',
			'trigger' => 'setTrigger',
			'triggerRegister' => 'setTriggerRegister',
			'triggerSchema' => 'setTriggerSchema',
			'cron' => 'setCron',
			'executionMode' => 'setExecutionMode',
			'notes' => 'setNotes',
			'comment' => 'setComment',
			'applicationSlug' => 'setApplicationSlug',
		];

		// A flow authored as a definition file carries its rationale under the
		// top-level `$comment` key — the JSON-Schema convention for "a human
		// wrote this, no reader should interpret it". That key cannot be a
		// column name, so it is normalised here rather than at every call site.
		//
		// `comment` wins when both are present: the explicit API field is a
		// deliberate write, `$comment` is whatever the file happened to carry.
		if (array_key_exists('$comment', $data) === true && array_key_exists('comment', $data) === false) {
			$data['comment'] = $data['$comment'];
		}

		foreach ($strings as $key => $setter) {
			if (array_key_exists($key, $data) === true) {
				$value = $data[$key];
				if ($value === null) {
					$flow->$setter(null);
					continue;
				}

				$flow->$setter((string)$value);
			}
		}

		$arrays = [
			'nodes' => 'setNodes',
			'edges' => 'setEdges',
			'limits' => 'setLimits',
		];

		foreach ($arrays as $key => $setter) {
			if (array_key_exists($key, $data) === true) {
				$flow->$setter((array)($data[$key] ?? []));
			}
		}

		if (array_key_exists('enabled', $data) === true) {
			$flow->setEnabled((bool)$data['enabled']);
		}

		$this->applyInheritableSettings(flow: $flow, data: $data);

	}//end applyEditableFields()

	/**
	 * Apply the three settings a flow may inherit from the administrator default.
	 *
	 * An explicit null is meaningful here — it is how a flow returns to following
	 * the instance default — so null is STORED rather than skipped, and each
	 * value is tested for null BEFORE casting. `(int) null` and `(bool) null`
	 * would quietly turn "inherit the default" into a hard 0/false on the row,
	 * which reads identically to a deliberate choice.
	 *
	 * @param Flow $flow The flow to mutate.
	 * @param array<string, mixed> $data The incoming fields.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	private function applyInheritableSettings(Flow $flow, array $data): void {
		$inheritable = [
			'retentionDays' => ['setRetentionDays', static fn ($value): int => max(1, (int)$value)],
			'auditEnabled' => ['setAuditEnabled', static fn ($value): bool => (bool)$value],
			'oversightEnabled' => ['setOversightEnabled', static fn ($value): bool => (bool)$value],
		];

		foreach ($inheritable as $key => [$setter, $cast]) {
			if (array_key_exists($key, $data) === false) {
				continue;
			}

			$value = $data[$key];
			if ($value === null) {
				$flow->$setter(null);
				continue;
			}

			$flow->$setter($cast($value));
		}

	}//end applyInheritableSettings()

	/**
	 * Delete a flow the caller owns.
	 *
	 * @param string $uuid The flow uuid.
	 *
	 * @return void
	 *
	 * @throws DoesNotExistException When no such flow exists, or it is not the caller's.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	public function delete(string $uuid): void {
		$this->mapper->delete($this->find(uuid: $uuid));

		// Drop the flow's trigger rows too. An orphaned row is read on every
		// matching object event and names a flow that can no longer be loaded,
		// so it costs a failed lookup per event, forever.
		$this->triggerIndex->forget(flowUuid: $uuid);

		// And its execution history, by the same reasoning. `flow_runs` and
		// `flow_state` key on the flow UUID, so once the flow row is gone every
		// one of those rows is unreachable through any read path the app has —
		// there is no endpoint that lists or deletes a run except by its flow.
		// Left behind they are pure landfill: measured on the dev instance,
		// 493 runs across 80 already-deleted flows, plus 4 state rows.
		//
		// Steps are removed BEFORE nothing else can name them: `deleteByFlow()`
		// returns the run uuids precisely because `flow_run_steps` keys on the
		// run, not the flow, so dropping the runs first would strand the steps
		// with no way left to find them.
		try {
			$runUuids = $this->runs->deleteByFlow(flowId: $uuid);
			foreach ($runUuids as $runUuid) {
				$this->steps->deleteByRun(runUuid: $runUuid);
			}

			$this->state->deleteByFlow(flowId: $uuid);
		} catch (Throwable $e) {
			// The flow itself is already gone, so this must not turn a
			// successful delete into an error the caller has to retry — a retry
			// would 404 on the flow and never reach this cascade anyway.
			$this->logger->warning(
				message: '[FlowService] Deleted flow ' . $uuid . ' but could not sweep its history: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}

	}//end delete()

	/**
	 * Queue a manual run of a flow the caller owns.
	 *
	 * A manual run is attributed to the ACTING user rather than the flow's
	 * owner: the caller is present and authenticated, so there is a real
	 * identity to run as, and using the stored owner instead would let anyone
	 * who can reach the flow borrow that owner's privileges. `canDispatch()`
	 * governs the other direction — a trigger firing with nobody present.
	 *
	 * @param string $uuid The flow uuid.
	 * @param array<string, mixed> $subject `{uuid, register, schema}` of the object.
	 * @param array<string, mixed> $context Run-level metadata.
	 * @param boolean $sync Execute inline and return the finished run.
	 * @param string $trigger The dispatch trigger recorded on the run.
	 *
	 * `$trigger` is what the version pin reads to decide whether this run may
	 * walk a DRAFT. `FlowRunVersionPin::TRIGGER_TEST` is the interactive
	 * draft test run — the one documented exception to "a draft cannot back a
	 * run" (see `FlowPublishedGraph::overlayOnto`). Every other trigger
	 * requires a published version, which is the point of versioning.
	 *
	 * @return FlowRun The queued run, or the finished run when $sync is true.
	 *
	 * @throws DoesNotExistException When no such flow exists, or it is not the caller's.
	 * @throws FlowDeadEnd When a node's token has nowhere to go, so the run is refused.
	 * @throws FlowLifecycleRefused When the trigger requires a published version and there is none.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $sync chooses WHO advances the
	 * run, not what running means: the same run row is queued either way, and
	 * synchronously this method simply advances it itself instead of leaving it
	 * for the cron worker. Two methods would be two names for one operation.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	public function run(
		string $uuid,
		array $subject = [],
		array $context = [],
		bool $sync = false,
		string $trigger = Flow::TRIGGER_MANUAL
	): FlowRun {
		$flow = $this->find(uuid: $uuid);

		$run = $this->runner->queue(
			flowId: (string)$flow->getUuid(),
			subject: $subject,
			trigger: $trigger,
			context: $context,
			user: $this->actingUser()
		);

		if ($sync === false) {
			return $run;
		}

		// Queue FIRST, then advance the queued row — never execute instead of
		// queueing. The run record is what the retry endpoint, the run log and
		// every observability surface read; a synchronous run that skipped it
		// would execute invisibly and leave a person who pressed Run with
		// nothing to look at afterwards.
		//
		// `rethrow: true` because this call is answering a request ABOUT this
		// run: a caller who asked to wait has earned the error. The worker
		// swallows for the opposite reason — one bad run must not stop a queue.
		return $this->advancer->advance(run: $run, rethrow: true);
	}//end run()

	/**
	 * The owner and organisation a flow written by THIS caller must carry.
	 *
	 * 🔴 PUBLIC BECAUSE IT HAS A SECOND WRITER. `flowToSave()` is not the only
	 * path that inserts a Flow: `FlowShareableConfigType::deserialise()` writes
	 * one when a federated bundle is installed, and it used to stamp nulls —
	 * reproducing, on that path, the permanent orphan the refusal below exists
	 * to prevent. Two writers each deriving ownership their own way is how the
	 * rule came to hold on one of them and not the other; this is the one place
	 * that decides it.
	 *
	 * @return array{owner: string|null, organisation: string|null} The caller's ownership, either field null when it does not resolve.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	public function callerOwnership(): array {
		return [
			'owner'        => $this->actingUser(),
			'organisation' => $this->activeOrganisation(),
		];
	}//end callerOwnership()

	/**
	 * The acting user's uid, or null when there is no session.
	 *
	 * @return string|null The uid.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	private function actingUser(): ?string {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null || $uid === '') {
			return null;
		}

		return (string)$uid;
	}//end actingUser()

	/**
	 * The caller's active organisation uuid, or null when none resolves.
	 *
	 * Resolved lazily through the container for the same reason
	 * `FlowRunService` does it: this service is reachable from paths that run
	 * without a session, and dragging the whole organisation/RBAC graph in to
	 * read a value that will be null there is wasted work.
	 *
	 * @return string|null The organisation uuid.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	private function activeOrganisation(): ?string {
		try {
			$organisationService = $this->container->get('OCA\OpenRegister\Service\OrganisationService');
			$uuid = $organisationService->getActiveOrganisation()?->getUuid();
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[FlowService] Could not resolve the active organisation: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}

		if ($uuid === null || $uuid === '') {
			return null;
		}

		return (string)$uuid;
	}//end activeOrganisation()

	/**
	 * Mint a v4 uuid.
	 *
	 * @return string The uuid.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	private function newUuid(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}//end newUuid()
}//end class
