<?php

/**
 * The case layer's public surface.
 *
 * Every verb here authorizes FIRST, through {@see CasePlanAuthorizationService},
 * and a denial is audited (`authorized: false`) before it is rethrown. Every
 * write goes through {@see CasePlanStateMachine} (one transition) or the
 * definition compiler (row creation) and is followed by one bounded
 * {@see CasePlanCascade::evaluate()} so the plan settles. No verb is
 * reachable by knowing a uuid alone: reads require the caller to be able to
 * read the anchoring object, and every verb requires the item's effective
 * authorization.
 *
 * Nothing here touches a run's marking, status or log, and nothing here is
 * reachable from `Service\Flow\`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\CaseItemAudit;
use OCA\OpenRegister\Exception\CaseAccessDeniedException;
use OCA\OpenRegister\Exception\CasePlanExistsException;
use OCA\OpenRegister\Exception\CaseValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use Throwable;

/**
 * Reads, creates, transitions, enables, attaches, completes and deletes.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One method per verb the
 * spec names plus the three reads and the three event entry points. Folding
 * verbs into a mode parameter is how per-verb authorization gets lost.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The service owns the
 * transaction across the mapper, the machine, the cascade, the evaluator,
 * the authorization, the anchor reader, the writer and the compiler; that
 * IS its job description.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) Scales with the verb count;
 * each verb is short and single-purpose.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The sum of the verbs'
 * guards: authorize, then precondition, then the conditional write.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
 */
class CasePlanService implements CasePlanServiceInterface {

	/**
	 * What an in-process caller's actor id starts with (`system:<app>`): a label for the audit, not a Nextcloud account.
	 */
	public const SYSTEM_ACTOR_PREFIX = 'system:';

	/**
	 * Convergent bring-over; built from the same collaborators on first use when not injected.
	 *
	 * @var CasePlanEnsurer|null
	 */
	private ?CasePlanEnsurer $ensurer;

	/**
	 * Arms the deadlines of items the ensurer creates as active, when it is built here.
	 *
	 * @var CasePlanItemTimers|null
	 */
	private readonly ?CasePlanItemTimers $timers;

	/**
	 * Constructor.
	 *
	 * @param CasePlanStore $store The plan-item table, the audit, the transaction and the failure log.
	 * @param CasePlanMechanics $mechanics The transition path, the cascade, the entry criteria and the definition compiler.
	 * @param CasePlanAnchorAccess $anchorAccess The fail-closed decisions, the anchor reader and the result write-through.
	 * @param CasePlanBringOver $bringOver The convergent bring-over and the timers it arms, either possibly absent.
	 */
	public function __construct(
		private readonly CasePlanStore $store,
		private readonly CasePlanMechanics $mechanics,
		private readonly CasePlanAnchorAccess $anchorAccess,
		CasePlanBringOver $bringOver,
	) {
		$this->ensurer = $bringOver->ensurer;
		$this->timers = $bringOver->timers;
	}//end __construct()

	/**
	 * The plan of one object: every item with its state, type and parent,
	 * plus the audit trail. Needs no run uuid.
	 *
	 * @param string $objectUuid The anchoring object.
	 * @param string|null $uid The reading identity.
	 *
	 * @return array<string, mixed> objectUuid, items, audit, settings.
	 *
	 * @throws DoesNotExistException When there is no plan, OR the caller may not see the object.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	public function getPlan(string $objectUuid, ?string $uid): array {
		return $this->planOf(objectUuid: $objectUuid, rows: $this->visibleRows(objectUuid: $objectUuid, uid: $uid));
	}//end getPlan()

	/**
	 * Create a plan on an object from a definition, then evaluate it.
	 *
	 * Validation is at THIS boundary (unknown event, invalid if-part, unknown
	 * type, duplicate key), before anything is written. The caller must hold
	 * the plan's root authorization: nobody may create a plan they could not
	 * administer.
	 *
	 * @param string $objectUuid The anchoring object.
	 * @param int|null $registerId Its register.
	 * @param int|null $schemaId Its schema.
	 * @param array<string, mixed> $definition The definition (`settings`, `items`).
	 * @param string|null $uid The creating identity.
	 * @param string|null $flowUuid Definition provenance, when any.
	 * @param int|null $flowVersion Definition provenance, when any.
	 *
	 * @return array<string, mixed> The plan as {@see getPlan()} returns it.
	 *
	 * @throws CaseValidationException When the definition is refused, or the object already has a plan.
	 * @throws CaseAccessDeniedException When the caller may not administer it.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	public function createPlan(
		string $objectUuid,
		?int $registerId,
		?int $schemaId,
		array $definition,
		?string $uid,
		?string $flowUuid = null,
		?int $flowVersion = null,
	): array {
		$normalised = $this->mechanics->definitions->validate(definition: $definition);
		$this->anchorAccess->authorization->assertMayAdminister(verb: 'create-plan', settings: $normalised['settings'], uid: $uid);

		$anchor = new CaseAnchor(objectUuid: $objectUuid, registerId: $registerId, schemaId: $schemaId);
		$provenance = ['flowUuid' => $flowUuid, 'flowVersion' => $flowVersion];
		$insertion = new CasePlanInsertion(anchor: $anchor, settings: $normalised['settings'], actor: $uid, provenance: $provenance);
		$this->insertPlan(insertion: $insertion, items: $normalised['items']);

		return $this->getPlan(objectUuid: $objectUuid, uid: $uid);
	}//end createPlan()

	/**
	 * Create a plan as an app's own in-process code, without a user session
	 * (a listener, a command, a repair step). Same validation and the same
	 * evaluation as {@see createPlan()}; no authorization, because only PHP
	 * running inside the server reaches it, and it can already write every
	 * row. The audit names `system:<app>`. Never routed.
	 *
	 * @param string $objectUuid The anchoring object.
	 * @param int|null $registerId Its register.
	 * @param int|null $schemaId Its schema.
	 * @param array<string, mixed> $definition The definition (`settings`, `items`).
	 * @param string $app The acting app id.
	 *
	 * @return array<string, mixed> The plan as {@see getPlan()} returns it.
	 *
	 * @throws CaseValidationException When the app id or the definition is refused, or a plan exists.
	 *
	 * @orphaned-write-capability exclude cross-app entry point; caller is dossiq's CasePlanProjectionService (retire-cmmn-caseplanstate)
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-in-process-callers-act-as-a-named-app
	 */
	public function createPlanAsSystem(string $objectUuid, ?int $registerId, ?int $schemaId, array $definition, string $app): array {
		$actor = $this->systemActor(app: $app);
		$normalised = $this->mechanics->definitions->validate(definition: $definition);
		$anchor = new CaseAnchor(objectUuid: $objectUuid, registerId: $registerId, schemaId: $schemaId);
		$this->insertPlan(
			insertion: new CasePlanInsertion(anchor: $anchor, settings: $normalised['settings'], actor: $actor),
			items: $normalised['items']
		);

		return $this->getPlanAsSystem(objectUuid: $objectUuid, app: $app);
	}//end createPlanAsSystem()

	/**
	 * Read a plan as an app's own in-process code: no visibility check.
	 * Never routed.
	 *
	 * @param string $objectUuid The anchoring object.
	 * @param string $app The acting app id.
	 *
	 * @return array<string, mixed> objectUuid, items, audit, settings.
	 *
	 * @throws CaseValidationException When the app id is refused.
	 * @throws DoesNotExistException When the object has no plan.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-in-process-callers-act-as-a-named-app
	 */
	public function getPlanAsSystem(string $objectUuid, string $app): array {
		$this->systemActor(app: $app);

		return $this->planOf(objectUuid: $objectUuid, rows: $this->rowsOf(objectUuid: $objectUuid));
	}//end getPlanAsSystem()

	/**
	 * Bring plan items over from another engine, convergently, as an app's
	 * own in-process code: see {@see CasePlanEnsurer}. Never routed.
	 *
	 * @param string $objectUuid The anchoring object.
	 * @param int|null $registerId Its register.
	 * @param int|null $schemaId Its schema.
	 * @param array<string, mixed> $definition `settings` + nested `items`, a node MAY carry `state`.
	 * @param array<int, mixed> $history Entries `{item, from?, to, at, actor?, reason?}`.
	 * @param string $app The acting app id.
	 *
	 * @return array{created: array<int, string>, existing: array<int, string>, items: array<int, array<string, mixed>>}
	 *
	 * @throws CaseValidationException On the first refused value, before anything is written.
	 *
	 * @orphan-auth exclude cross-app entry point, not an access check; caller is dossiq's retire-cmmn-caseplanstate drain
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	public function ensureItems(string $objectUuid, ?int $registerId, ?int $schemaId, array $definition, array $history, string $app): array {
		$actor = $this->systemActor(app: $app);

		return $this->ensurer()->ensure(
			objectUuid: $objectUuid,
			registerId: $registerId,
			schemaId: $schemaId,
			definition: $definition,
			history: $history,
			actor: $actor
		);
	}//end ensureItems()

	/**
	 * A user-driven transition of one item (terminate a stage, reach a
	 * milestone by hand, complete a work item).
	 *
	 * @param string $itemUuid The item.
	 * @param string $to The target state.
	 * @param string|null $uid The acting identity.
	 * @param string|null $reason Free text.
	 *
	 * @return CaseItem The item as persisted.
	 *
	 * @throws CaseAccessDeniedException When denied (audited).
	 * @throws \OCA\OpenRegister\Exception\CaseTransitionException When illegal.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
	 */
	public function transition(string $itemUuid, string $to, ?string $uid, ?string $reason = null): CaseItem {
		$item = $this->store->items->findByUuid(uuid: $itemUuid);
		$tree = $this->treeFor(item: $item);
		$this->authorizeOrRecord(verb: 'transition', item: $item, tree: $tree, uid: $uid, requested: $to);

		if (in_array($to, CaseItem::STATES, true) === false) {
			throw new CaseValidationException(message: sprintf("'%s' is not a plan-item state.", $to));
		}

		$why = new CaseTransitionCause(cause: CaseItemAudit::CAUSE_USER, causeRef: null, actor: $uid, reason: $reason);
		$this->moveAndSettle(item: $item, to: $to, why: $why, tree: $tree);

		return $this->store->items->findByUuid(uuid: (string)$item->getUuid());
	}//end transition()

	/**
	 * Enable a discretionary item: the explicit act it waits for.
	 *
	 * Refused unless the item is discretionary, `available`, under an active
	 * parent, and its entry criteria are satisfied. Authorized against the
	 * item's effective rules before anything is written; the denial is audited.
	 *
	 * @param string $itemUuid The item.
	 * @param string|null $uid The acting identity.
	 *
	 * @return CaseItem The item after enabling and evaluation (normally `active`).
	 *
	 * @throws CaseAccessDeniedException When denied (audited).
	 * @throws CaseValidationException When not enableable.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	public function enableDiscretionary(string $itemUuid, ?string $uid): CaseItem {
		$item = $this->store->items->findByUuid(uuid: $itemUuid);
		$tree = $this->treeFor(item: $item);
		$this->authorizeOrRecord(verb: 'enable', item: $item, tree: $tree, uid: $uid, requested: CaseItem::STATE_ENABLED);
		$this->assertEnableable(item: $item, tree: $tree);

		$why = new CaseTransitionCause(cause: CaseItemAudit::CAUSE_USER, causeRef: 'enable', actor: $uid, reason: null);
		$this->moveAndSettle(item: $item, to: CaseItem::STATE_ENABLED, why: $why, tree: $tree);

		return $this->store->items->findByUuid(uuid: $itemUuid);
	}//end enableDiscretionary()

	/**
	 * Attach an ad-hoc item to a live case: work that appears in no definition.
	 *
	 * Authorization derives from the parent stage (or the plan root); the item
	 * cannot declare its own. Creating one modifies no flow definition and
	 * creates no definition version: it is a row.
	 *
	 * @param string $objectUuid The anchoring object.
	 * @param array<string, mixed> $data The item (`key`, `type`, `name`, `parent` uuid, criteria, candidates, deadlines).
	 * @param string|null $uid The acting identity.
	 *
	 * @return CaseItem The item after attachment and evaluation.
	 *
	 * @throws CaseAccessDeniedException When denied (audited on the parent, when any).
	 * @throws CaseValidationException When the item is refused.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	public function attachAdHoc(string $objectUuid, array $data, ?string $uid): CaseItem {
		$rows = $this->rowsOf(objectUuid: $objectUuid, missing: 'Object %s has no case plan to attach to.');
		$tree = new CasePlanTree(items: $rows);
		$parent = $this->parentFor(data: $data, tree: $tree);
		$this->authorizeOrRecord(verb: 'attach', item: $parent, tree: $tree, uid: $uid, requested: CaseItem::STATE_AVAILABLE);

		$node = $this->mechanics->definitions->validateAdHoc(node: $data);
		$this->assertNewKey(key: (string)$node['key'], tree: $tree);
		$this->assertLiveParent(parent: $parent);

		$insertion = $this->adHocInsertion(objectUuid: $objectUuid, first: $rows[0], tree: $tree, uid: $uid);
		$inserted = $this->transactional(
			mutation: fn (): CaseItem => $this->insertAdHoc(node: $node, insertion: $insertion, parent: $parent, tree: $tree)
		);
		$this->mechanics->cascade->evaluate(objectUuid: $objectUuid, actor: $uid);

		return $this->store->items->findByUuid(uuid: (string)$inserted->getUuid());
	}//end attachAdHoc()

	/**
	 * Which discretionary items may be enabled right now: discretionary,
	 * `available`, parent active, entry criteria satisfied.
	 *
	 * @param string $objectUuid The anchoring object.
	 * @param string|null $uid The reading identity.
	 *
	 * @return array<int, array<string, mixed>> The enableable items.
	 *
	 * @throws DoesNotExistException When there is no plan or the caller may not see it.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	public function enableableItems(string $objectUuid, ?string $uid): array {
		$rows = $this->visibleRows(objectUuid: $objectUuid, uid: $uid);
		$tree = new CasePlanTree(items: $rows);
		$enableable = [];
		foreach ($rows as $row) {
			if ($this->isEnableable(item: $row, tree: $tree) === true) {
				$enableable[] = $row->jsonSerialize();
			}
		}

		return $enableable;
	}//end enableableItems()

	/**
	 * "Which cases are stuck where": items by type and state, paged, with the
	 * total computed in the datastore. Administrators only: it spans cases.
	 *
	 * @param string|null $type The plan-item type filter.
	 * @param string|null $state The state filter.
	 * @param int $limit Page size.
	 * @param int $offset Page offset.
	 * @param string|null $uid The reading identity.
	 *
	 * @return array<string, mixed> results, total, limit, offset.
	 *
	 * @throws CaseAccessDeniedException For a non-administrator.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function findStuck(?string $type, ?string $state, int $limit, int $offset, ?string $uid): array {
		$identity = $this->anchorAccess->authorization->assertIdentified(uid: $uid, verb: 'list-items');
		if ($this->anchorAccess->authorization->isAdministrator(uid: $identity) === false) {
			throw new CaseAccessDeniedException(message: "Verb 'list-items' denied: listing plan items across cases is an administrator's read.");
		}

		return [
			'results' => array_map(
				static fn (CaseItem $row): array => $row->jsonSerialize(),
				$this->store->items->findByTypeAndState(type: $type, state: $state, limit: $limit, offset: $offset)
			),
			'total' => $this->store->items->countByTypeAndState(type: $type, state: $state),
			'limit' => $limit,
			'offset' => $offset,
		];
	}//end findStuck()

	/**
	 * Re-evaluate a plan: idempotent, derives only from facts already
	 * recorded. Readable-by-the-caller is the check, since the verb chooses
	 * nothing.
	 *
	 * @param string $objectUuid The anchoring object.
	 * @param string|null $uid The acting identity.
	 *
	 * @return array{passes: int, transitions: int, skipped: bool} What happened.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	public function evaluate(string $objectUuid, ?string $uid): array {
		$this->visibleRows(objectUuid: $objectUuid, uid: $uid);

		return $this->mechanics->cascade->evaluate(objectUuid: $objectUuid, actor: $uid);
	}//end evaluate()

	/**
	 * Finish the case with a result: refused outside the plan's constrained
	 * end-state set (naming the set) and while a required root item is open;
	 * otherwise mirrored onto the object through the ordinary write path.
	 *
	 * @param string $objectUuid The anchoring object.
	 * @param string $result The result.
	 * @param string|null $uid The acting identity.
	 *
	 * @return array<string, mixed> The plan, plus `result`.
	 *
	 * @throws CaseValidationException When the result is outside the set, or the plan is not finished.
	 * @throws CaseAccessDeniedException When the caller may not administer the plan.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	public function completeCase(string $objectUuid, string $result, ?string $uid): array {
		$rows = $this->rowsOf(objectUuid: $objectUuid);
		$tree = new CasePlanTree(items: $rows);
		$settings = $tree->settings();
		$this->anchorAccess->authorization->assertMayAdminister(verb: 'complete-case', settings: $settings, uid: $uid);
		$this->assertAllowedResult(result: $result, settings: $settings);
		$this->assertFinished(tree: $tree);

		$this->anchorAccess->writer->mirrorResult(anyRow: $rows[0], result: $result);
		$plan = $this->getPlan(objectUuid: $objectUuid, uid: $uid);
		$plan['result'] = $result;

		return $plan;
	}//end completeCase()

	/**
	 * Delete a plan's items. The audit stays; the mirrored business state on
	 * the object is not touched.
	 *
	 * @param string $objectUuid The anchoring object.
	 * @param string|null $uid The acting identity.
	 *
	 * @return int Rows deleted.
	 *
	 * @throws CaseAccessDeniedException When the caller may not administer the plan.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-business-state-is-written-through-to-the-register-never-owned-by-the-engine
	 */
	public function deletePlan(string $objectUuid, ?string $uid): int {
		$rows = $this->rowsOf(objectUuid: $objectUuid);
		$this->anchorAccess->authorization->assertMayAdminister(verb: 'delete-plan', settings: (new CasePlanTree(items: $rows))->settings(), uid: $uid);

		return $this->store->items->deleteByObject(objectUuid: $objectUuid);
	}//end deletePlan()

	/**
	 * A task reached a terminal state: evaluate the plan of the item it realised.
	 *
	 * @param string $taskUuid The task.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	public function onRealisationTerminal(string $taskUuid): void {
		$objects = [];
		foreach ($this->store->items->findByRealisation(realisationUuid: $taskUuid) as $row) {
			$objects[(string)$row->getObjectUuid()] = true;
		}

		foreach (array_keys($objects) as $objectUuid) {
			$this->evaluateQuietly(objectUuid: (string)$objectUuid, event: null, payload: []);
		}
	}//end onRealisationTerminal()

	/**
	 * An enforcing expiry reached its deadline: terminate the item through
	 * the one transition path, cause `realisation`, the timer as reference,
	 * then evaluate the plan. An item that is already terminal or gone is
	 * left alone; a failure is logged, never rethrown into the sweep.
	 *
	 * @param string $itemUuid The item.
	 * @param string $timerUuid The timer that fired.
	 *
	 * @return boolean True when the item was terminated.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	public function onTimerExpired(string $itemUuid, string $timerUuid): bool {
		try {
			$item = $this->store->items->findByUuid(uuid: $itemUuid);
		} catch (DoesNotExistException) {
			return false;
		}

		if ($item->isInTerminalState() === true || $this->terminateExpired(item: $item, itemUuid: $itemUuid, timerUuid: $timerUuid) === false) {
			return false;
		}

		$this->evaluateQuietly(objectUuid: (string)$item->getObjectUuid(), event: null, payload: []);

		return true;
	}//end onTimerExpired()

	/**
	 * An object changed: evaluate its plan, if it has a live one, with the
	 * event in hand so object on-parts can fire.
	 *
	 * @param string $objectUuid The object.
	 * @param string $event The catalog event id.
	 * @param array<string, mixed> $payload The object's data after the change.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-business-state-is-written-through-to-the-register-never-owned-by-the-engine
	 */
	public function onObjectEvent(string $objectUuid, string $event, array $payload): void {
		if ($this->store->items->countOpenByObject(objectUuid: $objectUuid) === 0) {
			return;
		}

		$this->evaluateQuietly(objectUuid: $objectUuid, event: $event, payload: $payload);
	}//end onObjectEvent()

	/**
	 * Whether an item is enableable now.
	 *
	 * @param CaseItem $item The item.
	 * @param CasePlanTree $tree The plan.
	 *
	 * @return boolean True when discretionary, available, parent active, entry satisfied.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function isEnableable(CaseItem $item, CasePlanTree $tree): bool {
		if ($item->getDiscretionary() !== true || $item->getState() !== CaseItem::STATE_AVAILABLE) {
			return false;
		}

		if ($tree->isParentActive(item: $item) === false) {
			return false;
		}

		$object = $this->anchorAccess->anchor->read(
			objectUuid: (string)$item->getObjectUuid(),
			registerId: $item->getRegisterId(),
			schemaId: $item->getSchemaId()
		);

		return $this->mechanics->sentries->entrySentry(item: $item, tree: $tree, object: $object) !== null;
	}//end isEnableable()

	/**
	 * The read shape of a plan: settings, rows and their audit.
	 *
	 * @param string $objectUuid The object.
	 * @param array<int, CaseItem> $rows Its rows.
	 *
	 * @return array<string, mixed> objectUuid, settings, items, audit.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	private function planOf(string $objectUuid, array $rows): array {
		$ids = [];
		foreach ($rows as $row) {
			$ids[] = (int)$row->getId();
		}

		return [
			'objectUuid' => $objectUuid,
			'settings' => (new CasePlanTree(items: $rows))->settings(),
			'items' => array_map(static fn (CaseItem $row): array => $row->jsonSerialize(), $rows),
			'audit' => array_map(static fn (CaseItemAudit $entry): array => $entry->jsonSerialize(), $this->store->audits->findForItems(caseItemIds: $ids)),
		];
	}//end planOf()

/**
	 * Insert a validated plan in one transaction, then evaluate it.
	 *
	 * @param CasePlanInsertion $insertion The anchor, settings, creating identity and provenance.
	 * @param array<int, array<string, mixed>> $items The validated top-level nodes.
	 *
	 * @return void
	 *
	 * @throws CasePlanExistsException When the object already has a plan.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	private function insertPlan(CasePlanInsertion $insertion, array $items): void {
		$objectUuid = $insertion->anchor->objectUuid;
		if ($this->store->items->findByObject(objectUuid: $objectUuid) !== []) {
			throw new CasePlanExistsException(message: sprintf('Object %s already has a case plan; delete it before creating another.', $objectUuid));
		}

		$this->transactional(
			mutation: function () use ($items, $insertion): void {
				$this->insertNodes(nodes: $items, parentId: null, insertion: $insertion);
			}
		);

		$this->mechanics->cascade->evaluate(objectUuid: $objectUuid, actor: $insertion->actor);
	}//end insertPlan()

	/**
	 * The audit identity of an app's in-process code. A Nextcloud uid cannot
	 * contain `:`, so it never collides with a user.
	 *
	 * @param string $app The app id.
	 *
	 * @return string `system:<app>`.
	 *
	 * @throws CaseValidationException When the app id is not one.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-in-process-callers-act-as-a-named-app
	 */
	private function systemActor(string $app): string {
		if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $app) !== 1) {
			throw new CaseValidationException(
				message: sprintf("'%s' is not an app id; a system caller names its app in lower case letters, digits and `_`.", $app)
			);
		}

		return self::SYSTEM_ACTOR_PREFIX . $app;
	}//end systemActor()

	/**
	 * The plan's rows, only if the caller may read the anchoring object.
	 *
	 * @param string $objectUuid The object.
	 * @param string|null $uid The reading identity.
	 *
	 * @return array<int, CaseItem> The rows.
	 *
	 * @throws DoesNotExistException When there is no plan, or it is invisible (same answer, deliberately).
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	private function visibleRows(string $objectUuid, ?string $uid): array {
		$rows = $this->store->items->findByObject(objectUuid: $objectUuid);
		if ($rows === []) {
			throw new DoesNotExistException(sprintf('Object %s has no case plan.', $objectUuid));
		}

		$first = $rows[0];
		$visible = $this->anchorAccess->authorization->isAdministrator(uid: $uid)
			|| ($uid !== null && $this->anchorAccess->anchor->mayRead(
				objectUuid: $objectUuid,
				registerId: $first->getRegisterId(),
				schemaId: $first->getSchemaId()
			) === true);
		if ($visible === false) {
			throw new DoesNotExistException(sprintf('Object %s has no case plan.', $objectUuid));
		}

		return $rows;
	}//end visibleRows()

	/**
	 * The plan an item belongs to.
	 *
	 * @param CaseItem $item The item.
	 *
	 * @return CasePlanTree The tree.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private function treeFor(CaseItem $item): CasePlanTree {
		return new CasePlanTree(items: $this->store->items->findByObject(objectUuid: (string)$item->getObjectUuid()));
	}//end treeFor()

	/**
	 * The parent stage an ad-hoc item names, or null for the plan root.
	 *
	 * @param array<string, mixed> $data The submitted item.
	 * @param CasePlanTree $tree The plan.
	 *
	 * @return CaseItem|null The parent stage.
	 *
	 * @throws CaseValidationException When the named parent is not a stage of this plan.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function parentFor(array $data, CasePlanTree $tree): ?CaseItem {
		$ref = trim((string)($data['parent'] ?? ''));
		if ($ref === '') {
			return null;
		}

		$row = $this->rowNamed(ref: $ref, tree: $tree);
		if ($row === null) {
			throw new CaseValidationException(message: sprintf("No stage '%s' exists in this plan.", $ref));
		}

		if ($row->getPlanItemType() !== CaseItem::TYPE_STAGE) {
			throw new CaseValidationException(message: sprintf("'%s' is not a stage; an ad-hoc item is attached to a stage or to the plan root.", $ref));
		}

		return $row;
	}//end parentFor()

	/**
	 * Authorize a verb on an item (or the root), and AUDIT a denial before
	 * rethrowing it.
	 *
	 * @param string $verb The verb.
	 * @param CaseItem|null $item The item, or null for the root.
	 * @param CasePlanTree $tree The plan.
	 * @param string|null $uid The acting identity.
	 * @param string $requested The requested state, for the audit row.
	 *
	 * @return void
	 *
	 * @throws CaseAccessDeniedException The original denial, always rethrown.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function authorizeOrRecord(string $verb, ?CaseItem $item, CasePlanTree $tree, ?string $uid, string $requested): void {
		try {
			$this->anchorAccess->authorization->assertMayAct(verb: $verb, item: $item, tree: $tree, uid: $uid);
		} catch (CaseAccessDeniedException $denial) {
			if ($item !== null) {
				$this->mechanics->machine->recordDenial(item: $item, to: $requested, actor: $uid, reason: $denial->getMessage());
			}

			throw $denial;
		}
	}//end authorizeOrRecord()

/**
	 * Insert a level of normalised nodes and recurse into stages' children.
	 *
	 * @param array<int, array<string, mixed>> $nodes The nodes.
	 * @param int|null $parentId The containing stage's row id.
	 * @param CasePlanInsertion $insertion The anchor, settings, creating identity and provenance.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	private function insertNodes(array $nodes, ?int $parentId, CasePlanInsertion $insertion): void {
		foreach ($nodes as $position => $node) {
			$row = $this->rowFor(node: $node, insertion: $insertion, parentId: $parentId, position: (int)$position);
			$persisted = $this->store->items->insert($row);
			$this->mechanics->machine->recordCreation(
				item: $persisted,
				cause: CaseItemAudit::CAUSE_IMPORT,
				causeRef: $insertion->provenance['flowUuid'],
				actor: $insertion->actor
			);

			$this->insertNodes(nodes: ($node['children'] ?? []), parentId: (int)$persisted->getId(), insertion: $insertion);
		}
	}//end insertNodes()

	/**
	 * The unsaved row of one definition node, compiled by the one compiler.
	 *
	 * @param array<string, mixed> $node The node.
	 * @param CasePlanInsertion $insertion The anchor, settings, creating identity and provenance.
	 * @param int|null $parentId The containing stage's row id.
	 * @param int $position Its order among its siblings.
	 *
	 * @return CaseItem The unsaved row.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	private function rowFor(array $node, CasePlanInsertion $insertion, ?int $parentId, int $position): CaseItem {
		return $this->mechanics->definitions->rowFrom(
			node: $node,
			objectUuid: $insertion->anchor->objectUuid,
			registerId: $insertion->anchor->registerId,
			schemaId: $insertion->anchor->schemaId,
			parentId: $parentId,
			position: $position,
			settings: $insertion->settings,
			origin: ($insertion->origin ?? $this->mechanics->definitions->originOf(node: $node)),
			actor: $insertion->actor,
			flowUuid: $insertion->provenance['flowUuid'],
			flowVersion: $insertion->provenance['flowVersion']
		);
	}//end rowFor()

	/**
	 * What an ad-hoc row shares with its plan: the anchor of the plan's first
	 * row, the plan's settings, the attaching identity and the `adhoc` origin.
	 *
	 * @param string $objectUuid The anchoring object.
	 * @param CaseItem $first The plan's first row.
	 * @param CasePlanTree $tree The plan.
	 * @param string|null $uid The attaching identity.
	 *
	 * @return CasePlanInsertion The shared part of the insertion.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function adHocInsertion(string $objectUuid, CaseItem $first, CasePlanTree $tree, ?string $uid): CasePlanInsertion {
		$anchor = new CaseAnchor(objectUuid: $objectUuid, registerId: $first->getRegisterId(), schemaId: $first->getSchemaId());

		return new CasePlanInsertion(anchor: $anchor, settings: $tree->settings(), actor: $uid, origin: CaseItem::ORIGIN_ADHOC);
	}//end adHocInsertion()

	/**
	 * Insert one ad-hoc row under its parent (or the root) and audit its creation by the attaching actor.
	 *
	 * @param array<string, mixed> $node The validated ad-hoc node.
	 * @param CasePlanInsertion $insertion The anchor, the plan's settings, the attaching identity and the `adhoc` origin.
	 * @param CaseItem|null $parent The parent stage, or null for the root.
	 * @param CasePlanTree $tree The plan, for the position among the siblings.
	 *
	 * @return CaseItem The inserted row.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function insertAdHoc(array $node, CasePlanInsertion $insertion, ?CaseItem $parent, CasePlanTree $tree): CaseItem {
		$parentId = $parent?->getId();
		$row = $this->rowFor(node: $node, insertion: $insertion, parentId: $parentId, position: count($tree->children(parentId: $parentId)));
		$persisted = $this->store->items->insert($row);
		$this->mechanics->machine->recordCreation(item: $persisted, cause: CaseItemAudit::CAUSE_USER, causeRef: 'attach', actor: $insertion->actor);

		return $persisted;
	}//end insertAdHoc()

	/**
	 * Evaluate from a listener: a failure is logged, never rethrown into the
	 * event that caused it.
	 *
	 * @param string $objectUuid The object.
	 * @param string|null $event The event, when any.
	 * @param array<string, mixed> $payload Its payload.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private function evaluateQuietly(string $objectUuid, ?string $event, array $payload): void {
		try {
			$this->mechanics->cascade->evaluate(objectUuid: $objectUuid, event: $event, payload: $payload);
		} catch (Throwable $failure) {
			$this->store->logger->error(
				'[CasePlanService] Event-driven evaluation failed: ' . $failure->getMessage(),
				['object' => $objectUuid, 'event' => $event, 'exception' => $failure]
			);
		}
	}//end evaluateQuietly()

	/**
	 * Run a mutation in one transaction.
	 *
	 * @param callable $mutation The mutation.
	 *
	 * @return mixed The mutation's result.
	 *
	 * @throws Throwable Whatever the mutation threw, after rollback.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	private function transactional(callable $mutation): mixed {
		$this->store->db->beginTransaction();
		try {
			$result = $mutation();
			$this->store->db->commit();

			return $result;
		} catch (Throwable $failure) {
			$this->store->db->rollBack();
			throw $failure;
		}
	}//end transactional()

	/**
	 * The ensurer, built from the plan's own collaborators on first use when it was not injected.
	 *
	 * @return CasePlanEnsurer The ensurer.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function ensurer(): CasePlanEnsurer {
		if ($this->ensurer === null) {
			$this->ensurer = new CasePlanEnsurer(
				store: $this->store,
				definitions: $this->mechanics->definitions,
				transitions: new CasePlanTransitions(),
				timers: $this->timers
			);
		}

		return $this->ensurer;
	}//end ensurer()

	/**
	 * The rows of a plan, which must exist.
	 *
	 * @param string $objectUuid The anchoring object.
	 * @param string $missing The message when it has none (`%s` is the object).
	 *
	 * @return array<int, CaseItem> The rows.
	 *
	 * @throws DoesNotExistException When the object has no plan.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	private function rowsOf(string $objectUuid, string $missing = 'Object %s has no case plan.'): array {
		$rows = $this->store->items->findByObject(objectUuid: $objectUuid);
		if ($rows === []) {
			throw new DoesNotExistException(sprintf($missing, $objectUuid));
		}

		return $rows;
	}//end rowsOf()

	/**
	 * Move one item through the one transition path, then let the plan settle.
	 *
	 * @param CaseItem $item The item.
	 * @param string $to The target state.
	 * @param CaseTransitionCause $why The cause, its reference, the acting identity and the reason.
	 * @param CasePlanTree $tree The plan.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
	 */
	private function moveAndSettle(CaseItem $item, string $to, CaseTransitionCause $why, CasePlanTree $tree): void {
		$this->move(item: $item, to: $to, why: $why, tree: $tree);
		$this->mechanics->cascade->evaluate(objectUuid: (string)$item->getObjectUuid(), actor: $why->actor);
	}//end moveAndSettle()

	/**
	 * Move one item through the one transition path.
	 *
	 * @param CaseItem $item The item.
	 * @param string $to The target state.
	 * @param CaseTransitionCause $why The cause, its reference, the acting identity and the reason.
	 * @param CasePlanTree $tree The plan.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
	 */
	private function move(CaseItem $item, string $to, CaseTransitionCause $why, CasePlanTree $tree): void {
		$this->mechanics->machine->transition(
			item: $item,
			to: $to,
			cause: $why->cause,
			causeRef: $why->causeRef,
			actor: $why->actor,
			reason: $why->reason,
			tree: $tree
		);
	}//end move()

	/**
	 * Terminate an item whose enforcing expiry fired; a failure is logged, never rethrown into the sweep.
	 *
	 * @param CaseItem $item The open item.
	 * @param string $itemUuid Its uuid, as the timer named it.
	 * @param string $timerUuid The timer that fired.
	 *
	 * @return bool True when the item was terminated.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	private function terminateExpired(CaseItem $item, string $itemUuid, string $timerUuid): bool {
		$why = self::expiryCause(timerUuid: $timerUuid);
		try {
			$this->move(item: $item, to: CaseItem::STATE_TERMINATED, why: $why, tree: $this->treeFor(item: $item));
		} catch (Throwable $failure) {
			$this->store->logger->error(
				'[CasePlanService] An expired term could not terminate its plan item: ' . $failure->getMessage(),
				['item' => $itemUuid, 'timer' => $timerUuid, 'exception' => $failure]
			);

			return false;
		}

		return true;
	}//end terminateExpired()

	/**
	 * The cause an enforcing expiry terminates its item with: the realisation, the timer as reference, no actor.
	 *
	 * @param string $timerUuid The timer that fired.
	 *
	 * @return CaseTransitionCause The cause.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	private static function expiryCause(string $timerUuid): CaseTransitionCause {
		return new CaseTransitionCause(
			cause: CaseItemAudit::CAUSE_REALISATION,
			causeRef: 'flow-timer:' . $timerUuid,
			actor: null,
			reason: 'Its statutory term expired.'
		);
	}//end expiryCause()

	/**
	 * Refuse to enable an item that is not enableable now.
	 *
	 * @param CaseItem $item The item.
	 * @param CasePlanTree $tree The plan.
	 *
	 * @return void
	 *
	 * @throws CaseValidationException When it is not discretionary, available, under an active parent and admitted.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function assertEnableable(CaseItem $item, CasePlanTree $tree): void {
		if ($this->isEnableable(item: $item, tree: $tree) === false) {
			throw new CaseValidationException(
				message: sprintf(
					"Plan item '%s' is not enableable: it must be discretionary, available, under an active parent, with its entry criteria satisfied.",
					(string)$item->getItemKey()
				)
			);
		}
	}//end assertEnableable()

	/**
	 * An ad-hoc item's key must be new to the plan.
	 *
	 * @param string $key The key.
	 * @param CasePlanTree $tree The plan.
	 *
	 * @return void
	 *
	 * @throws CaseValidationException When a row already carries it.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function assertNewKey(string $key, CasePlanTree $tree): void {
		foreach ($tree->rowsForKey(key: $key) as $existing) {
			if ($existing !== null) {
				throw new CaseValidationException(message: sprintf("Item key '%s' already exists in this plan.", $key));
			}
		}
	}//end assertNewKey()

	/**
	 * An ad-hoc item is attached to a live stage, or to the root.
	 *
	 * @param CaseItem|null $parent The parent stage, or null for the root.
	 *
	 * @return void
	 *
	 * @throws CaseValidationException When the stage is not active.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function assertLiveParent(?CaseItem $parent): void {
		if ($parent !== null && $parent->getState() !== CaseItem::STATE_ACTIVE) {
			throw new CaseValidationException(
				message: sprintf("Stage '%s' is not active; an ad-hoc item is attached to a live stage.", (string)$parent->getItemKey())
			);
		}
	}//end assertLiveParent()

	/**
	 * A case finishes only with a result in its constrained end-state set.
	 *
	 * @param string $result The result.
	 * @param array<string, mixed> $settings The plan settings.
	 *
	 * @return void
	 *
	 * @throws CaseValidationException Naming the set.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	private function assertAllowedResult(string $result, array $settings): void {
		$allowed = ($settings['results'] ?? []);
		if (is_array($allowed) === false) {
			$allowed = [];
		}

		if (in_array($result, $allowed, true) === false) {
			throw new CaseValidationException(
				message: sprintf("Result '%s' is not in the case's allowed set [%s].", $result, implode(', ', $allowed))
			);
		}
	}//end assertAllowedResult()

	/**
	 * A case finishes only when no required root item is still open.
	 *
	 * @param CasePlanTree $tree The plan.
	 *
	 * @return void
	 *
	 * @throws CaseValidationException Naming the first open required item.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	private function assertFinished(CasePlanTree $tree): void {
		foreach ($tree->children(parentId: null) as $root) {
			if ($root->getRequired() === true && $tree->isItemTerminal(item: $root) === false) {
				throw new CaseValidationException(
					message: sprintf("The case cannot finish while required item '%s' is '%s'.", (string)$root->getItemKey(), (string)$root->getState())
				);
			}
		}
	}//end assertFinished()

	/**
	 * The first row whose uuid or key is the reference.
	 *
	 * @param string $ref The uuid or key.
	 * @param CasePlanTree $tree The plan.
	 *
	 * @return CaseItem|null The row, or null when none matches.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function rowNamed(string $ref, CasePlanTree $tree): ?CaseItem {
		foreach ($tree->all() as $row) {
			if ($row->getUuid() === $ref || $row->getItemKey() === $ref) {
				return $row;
			}
		}

		return null;
	}//end rowNamed()
}//end class
