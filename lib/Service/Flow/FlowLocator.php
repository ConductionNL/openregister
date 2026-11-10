<?php

/**
 * Loads flow documents and the objects their runs are about.
 *
 * This replaces the resolver layer. `IFlowResolver` + `FlowResolverRegistry`
 * existed for exactly one reason: flows were stored as OpenRegister objects, in
 * a different register per app, so something had to ask each app in turn "is
 * this one yours". With one native store that question has one answer, and the
 * indirection was buying nothing but a way for two apps to disagree about who
 * owned a flow id.
 *
 * The method names are unchanged from the registry deliberately, so the call
 * sites in the worker, the trigger service, the scheduler and the sub-flow node
 * change only their injected type.
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

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\Flow\TriggerLookup;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\WriteCause;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The engine's read path into the flow store.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */
class FlowLocator implements FlowLocatorInterface {

	/**
	 * Per-request memo of resolved flow documents, keyed by flow id.
	 *
	 * A single object write can fire several triggers that resolve the same
	 * flow, and each resolution is a database read on a path that is held to a
	 * sub-500ms budget. Null is memoised too, so a miss is not re-queried.
	 *
	 * @var array<string, array|null>
	 */
	private array $flowMemo = [];

	/**
	 * Constructor.
	 *
	 * @param FlowLocatorStores         $stores        The flow, trigger index and version mappers.
	 * @param ObjectOperationsInterface $objectService Loads the object a run is about.
	 * @param LoggerInterface           $logger        Records refusals and failures.
	 */
	public function __construct(
		private readonly FlowLocatorStores $stores,
		private readonly ObjectOperationsInterface $objectService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Load a flow document by its id.
	 *
	 * @param string $flowId The flow's uuid.
	 *
	 * @return array|null The flow document, or null when there is no such flow.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	public function resolveFlow(string $flowId): ?array {
		if (array_key_exists($flowId, $this->flowMemo) === true) {
			return $this->flowMemo[$flowId];
		}

		$this->flowMemo[$flowId] = null;

		try {
			$flow = $this->stores->mapper->findByUuid($flowId);
		} catch (Throwable $e) {
			return null;
		}

		$document = [
			'id' => (string)$flow->getUuid(),
			'name' => (string)$flow->getName(),
			'nodes' => ($flow->getNodes() ?? []),
			'edges' => ($flow->getEdges() ?? []),
			'limits' => ($flow->getLimits() ?? []),
			'executionMode' => (string)($flow->getExecutionMode() ?? Flow::MODE_ASYNC),
			'owner' => $flow->getOwner(),
			'organisation' => $flow->getOrganisation(),
		];

		$this->flowMemo[$flowId] = $document;

		return $document;
	}//end resolveFlow()

	/**
	 * Load the object a run walks against, by reference.
	 *
	 * Loads with RBAC and multitenancy OFF, which is correct here and ONLY
	 * here: a trigger fires with no acting user, so there is no session whose
	 * permissions could be applied, and refusing would mean an object-created
	 * trigger silently never fires for objects the (absent) user cannot read.
	 *
	 * This method is reachable from the queue worker and the trigger service —
	 * both background paths — and from no controller. That boundary is the
	 * whole safety argument: an unauthenticated load reached from a REQUEST is
	 * an IDOR, and the same call is only defensible because no request can get
	 * here. Anything that later routes a request into this method must apply
	 * its own authorisation first.
	 *
	 * @param ObjectRef $ref The subject's register, schema and uuid.
	 *
	 * @return object|null The subject, or null when it cannot be found.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
	 */
	public function resolveObject(ObjectRef $ref): ?object {
		if ($ref->id === '' || $ref->register === '' || $ref->schema === '') {
			return null;
		}

		try {
			$object = WriteCause::asLookup(fn () => $this->objectService->find(
				id: $ref->id,
				options: new ObjectFindOptions(register: $ref->register, schema: $ref->schema),
				_rbac: false,
				_multitenancy: false
			));
		} catch (Throwable $e) {
			return null;
		}

		if (($object instanceof ObjectEntity) === true) {
			return $object;
		}

		return null;
	}//end resolveObject()

	/**
	 * The ids of the flows an event fired on one register and schema should start.
	 *
	 * A flow that matches the event but cannot dispatch is REFUSED OUT LOUD
	 * rather than filtered away in SQL. The distinction matters: a flow that
	 * silently disappears from this list is indistinguishable from "no flow was
	 * interested", which is precisely how a misconfigured trigger reads as
	 * working. An ownerless flow is the common case — a trigger has no acting
	 * user, so the flow's owner is the only identity a run could execute as.
	 *
	 * NODES FIRST. A flow that has any row in the derived index has been
	 * converted, and its NODES decide entirely — including deciding that it
	 * does not want this event. The column match is kept ONLY for unconverted
	 * flows, and among those only for flows a published version can back — see
	 * {@see self::columnFallbackMatches()} for both halves of that rule.
	 *
	 * @param string            $event The event id.
	 * @param RegisterSchemaRef $table The register and schema the event fired on.
	 *
	 * @return array<int, string> The ids of the flows to run.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	public function flowsForEvent(string $event, RegisterSchemaRef $table): array {
		$index = $this->triggerIndex(event: $event, table: $table);
		$candidates = $this->columnCandidates(event: $event, table: $table);
		if ($candidates === null) {
			return [];
		}

		$matched = $this->withPublishedVersion(
			matched: $this->columnFallbackMatches(candidates: $candidates, converted: $index['converted']),
			event: $event
		);

		return $this->dispatchableUuids(matched: $this->withIndexed(matched: $matched, indexed: $index['indexed']), event: $event);
	}//end flowsForEvent()

	/**
	 * The derived trigger index: the flows whose nodes want this event, and
	 * every flow the index represents at all.
	 *
	 * An unreadable index — during the upgrade that creates it, or if it is
	 * dropped — leaves every flow on its columns, which is exactly the
	 * pre-cutover behaviour. Degrading to "no flow was interested" here would
	 * silently stop the whole engine.
	 *
	 * @param string            $event The event id.
	 * @param RegisterSchemaRef $table The register and schema the event fired on.
	 *
	 * @return array{indexed: array<int, string>, converted: array<string, int>}
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	private function triggerIndex(string $event, RegisterSchemaRef $table): array {
		$indexed = [];
		try {
			$indexed = $this->stores->triggerMapper->flowUuidsFor(event: $event, register: (string)$table->register, schema: (string)$table->schema);
			$converted = array_flip($this->stores->triggerMapper->representedFlowUuids());
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FlowLocator] Trigger index unreadable for "' . $event . '": ' . $e->getMessage()
					. '. Falling back to the flow trigger columns for every flow.',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			// Whatever the index already answered is kept; only the
			// conversion picture is lost, so every flow keeps its columns.
			return ['indexed' => $indexed, 'converted' => []];
		}

		return ['indexed' => $indexed, 'converted' => $converted];
	}//end triggerIndex()

	/**
	 * The flows whose trigger columns match the event, or null when they cannot be listed.
	 *
	 * @param string            $event The event id.
	 * @param RegisterSchemaRef $table The register and schema the event fired on.
	 *
	 * @return array<int, Flow>|null
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	private function columnCandidates(string $event, RegisterSchemaRef $table): ?array {
		try {
			$lookup = new TriggerLookup(trigger: $event, register: (string)$table->register, schema: (string)$table->schema);
			return $this->stores->mapper->findByTrigger(lookup: $lookup);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FlowLocator] Could not list flows for trigger "' . $event . '": ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}
	}//end columnCandidates()

	/**
	 * Add the flows whose NODES want this event to the column matches.
	 *
	 * The index answers in UUIDs; the dispatch check needs the row. An index
	 * row for a flow that no longer exists is reported rather than skipped
	 * silently: it means a delete did not reach the index, and the row will
	 * keep being read on every event.
	 *
	 * @param array<string, Flow> $matched The column matches, by uuid.
	 * @param array<int, string>  $indexed The uuids the index names.
	 *
	 * @return array<string, Flow>
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	private function withIndexed(array $matched, array $indexed): array {
		foreach ($indexed as $uuid) {
			if (isset($matched[$uuid]) === true) {
				continue;
			}

			try {
				$matched[$uuid] = $this->stores->mapper->findByUuid(uuid: $uuid);
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[FlowLocator] Trigger index names flow "' . $uuid . '" which could not be loaded: '
						. $e->getMessage() . '. Its index rows are stale.',
					context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $uuid]
				);
			}
		}

		return $matched;
	}//end withIndexed()

	/**
	 * The column-fallback matches: unconverted flows a published version can back.
	 *
	 * Two rules, one per failure mode this fallback has produced:
	 *
	 * - ONLY flows the index does not represent. A converted flow's columns
	 *   are stale by construction — they hold one trigger where its nodes may
	 *   hold several — so consulting them would resurrect a subscription its
	 *   author removed.
	 * - 🔴 ONLY flows with a published version (flow-definition-versioning:
	 *   a draft's trigger nodes SHALL NOT match anything). The index enforces
	 *   that by derivation from the published graph, but the fallback did not:
	 *   an enabled flow that was never published has zero index rows, so it is
	 *   "unrepresented", its columns match, and `queue()` then refuses it with
	 *   "no published version" — a refusal that used to abort the whole
	 *   fan-out for every healthy flow on the same event. Refused OUT LOUD
	 *   here instead. Legacy flows all hold a backfilled published version 1,
	 *   so the only thing filtered is a flow that could never have run.
	 *
	 * The second rule is {@see self::withPublishedVersion()}, applied to what
	 * this returns.
	 *
	 * @param array<int, Flow> $candidates The flows whose columns match the event.
	 * @param array<string, int> $converted Uuids the index represents, flipped for lookup.
	 *
	 * @return array<string, Flow> The unconverted candidates, by uuid.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md#requirement-trigger-matching-answers-which-flow-the-queue-path-answers-which-version
	 */
	private function columnFallbackMatches(array $candidates, array $converted): array {
		$matched = [];
		foreach ($candidates as $flow) {
			$uuid = (string)$flow->getUuid();
			if (isset($converted[$uuid]) === false) {
				$matched[$uuid] = $flow;
			}
		}

		return $matched;
	}//end columnFallbackMatches()

	/**
	 * The column-fallback matches a published version can back; the rest refused out loud.
	 *
	 * @param array<string, Flow> $matched The unconverted column matches, by uuid.
	 * @param string $event The trigger being resolved, for the log line.
	 *
	 * @return array<string, Flow> The matches, by uuid.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md#requirement-trigger-matching-answers-which-flow-the-queue-path-answers-which-version
	 */
	private function withPublishedVersion(array $matched, string $event): array {
		foreach (array_keys($matched) as $uuid) {
			if ($this->hasPublishedVersion(uuid: (string)$uuid) === true) {
				continue;
			}

			$this->logger->warning(
				message: '[FlowLocator] Flow "' . $uuid . '" matched trigger "' . $event
					. '" through its columns but has no published version, so it cannot back a run.'
					. ' Publish a version to make it fire.',
				context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $uuid]
			);
			unset($matched[$uuid]);
		}

		return $matched;
	}//end withPublishedVersion()

	/**
	 * Whether a flow has a published version to back a run.
	 *
	 * FAIL OPEN on an unreadable version table: silencing every fallback flow
	 * because one lookup failed would stop the engine without a word, and
	 * `FlowRunVersionPin` re-checks the same fact at queue time anyway — this
	 * filter only decides whether a flow is worth OFFERING to the queue.
	 *
	 * @param string $uuid The flow's uuid.
	 *
	 * @return bool True when a published version exists, or when the answer is unknowable.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md#requirement-trigger-matching-answers-which-flow-the-queue-path-answers-which-version
	 */
	private function hasPublishedVersion(string $uuid): bool {
		try {
			return $this->stores->versions->findPublished(flowUuid: $uuid) !== null;
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FlowLocator] Could not read the published version of flow "' . $uuid . '": '
					. $e->getMessage() . '. Keeping it in the match; the queue path decides.',
				context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $uuid]
			);

			return true;
		}
	}//end hasPublishedVersion()

	/**
	 * The uuids of the matched flows that can actually be dispatched.
	 *
	 * Extracted from `flowsForEvent()`, which sat exactly on the configured
	 * Cyclomatic Complexity threshold (10 of 10). Behaviour is unchanged: the
	 * ownerless flow is still skipped and still says out loud why, which is the
	 * point — a flow that matched and then vanished without a word is the
	 * silent-engine failure the cutover work exists to prevent.
	 *
	 * @param array<string, Flow> $matched The flows that matched, by uuid.
	 * @param string $event The trigger being resolved, for the log line.
	 *
	 * @return array<int, string> The dispatchable flow uuids.
	 */
	private function dispatchableUuids(array $matched, string $event): array {
		$ids = [];

		foreach ($matched as $uuid => $flow) {
			if ($flow->canDispatch() === false) {
				$this->logger->warning(
					message: '[FlowLocator] Flow "' . $uuid . '" matched trigger "' . $event
						. '" but was not dispatched: it has no owner, so there is no identity to run it as.',
					context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $uuid]
				);
				continue;
			}

			$ids[] = (string)$uuid;
		}//end foreach

		return $ids;
	}//end dispatchableUuids()

	/**
	 * The flows that run on a cron schedule.
	 *
	 * `since` is when the flow was last saved (its creation time when it was
	 * never updated), so the scheduler measures a never-fired flow's first
	 * occurrence from there.
	 *
	 * @return array<int, array{id: string, enabled: bool, trigger: string, cron: string, owner: string|null, since: string}>
	 *                                                                                                                        The candidate flows.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	public function scheduledFlows(): array {
		try {
			$flows = $this->stores->mapper->findScheduled();
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FlowLocator] Could not list scheduled flows: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return [];
		}

		$candidates = [];
		foreach ($flows as $flow) {
			if ($flow->canDispatch() === false) {
				$this->logger->warning(
					message: '[FlowLocator] Scheduled flow "' . $flow->getUuid()
						. '" was not dispatched: it is disabled, or its definition has no owner and is therefore '
						. 'an orphan that cannot be listed, found or edited either. This is not the identity '
						. 'check — whose rights a run uses comes from the schedule trigger node\'s runAs.',
					context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $flow->getUuid()]
				);
				continue;
			}

			$candidates[] = [
				'id' => (string)$flow->getUuid(),
				'enabled' => (bool)$flow->getEnabled(),
				'trigger' => (string)($flow->getTrigger() ?? ''),
				'cron' => (string)($flow->getCron() ?? ''),
				'owner' => $flow->getOwner(),
				'since' => $this->savedAt(flow: $flow),
			];
		}//end foreach

		return $candidates;
	}//end scheduledFlows()

	/**
	 * When a flow was last saved, as an ATOM timestamp, or the empty string.
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return string The timestamp.
	 */
	private function savedAt(Flow $flow): string {
		$saved = ($flow->getUpdated() ?? $flow->getCreated());
		if ($saved === null) {
			return '';
		}

		return $saved->format(DATE_ATOM);
	}//end savedAt()
}//end class
