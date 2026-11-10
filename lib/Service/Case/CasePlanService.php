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
use OCA\OpenRegister\Db\ListQuery;
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
	 * Convergent bring-over, once built (or injected through the bring-over bundle).
	 *
	 * @var CasePlanEnsurer|null
	 */
	private ?CasePlanEnsurer $ensurer = null;

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
		private readonly CasePlanBringOver $bringOver,
	) {
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
	 * @param CasePlanCreation $creation The anchor, the definition, the creating identity and the provenance.
	 *
	 * @return array<string, mixed> The plan as {@see getPlan()} returns it.
	 *
	 * @throws CaseValidationException When the definition is refused, or the object already has a plan.
	 * @throws CaseAccessDeniedException When the caller may not administer it.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	public function createPlan(CasePlanCreation $creation): array {
		$this->createAdministered(creation: $creation);

		return $this->getPlan(objectUuid: $creation->anchor->objectUuid, uid: $creation->actor);
	}//end createPlan()

	/**
	 * Validate a definition, require the creator to administer the plan it
	 * describes, then insert and evaluate it.
	 *
	 * @param CasePlanCreation $creation The anchor, the definition, the creator and the provenance.
	 *
	 * @return void
	 *
	 * @throws CaseValidationException When the definition is refused, or the object already has a plan.
	 * @throws CaseAccessDeniedException When the caller may not administer it.
	 */
	private function createAdministered(CasePlanCreation $creation): void {
		$normalised = $this->mechanics->definitions->validate(definition: $creation->definition);
		$act = new CasePlanAct(verb: 'create-plan', actor: $creation->actor);
		$this->anchorAccess->authorization->assertMayAdminister(act: $act, settings: $normalised['settings']);
		$this->insertNormalised(creation: $creation, normalised: $normalised);
	}//end createAdministered()

	/**
	 * Insert and evaluate a validated definition.
	 *
	 * @param CasePlanCreation $creation The anchor, the creator and the provenance.
	 * @param array{settings: array<string, mixed>, items: array<int, array<string, mixed>>} $normalised The validated definition.
	 *
	 * @return void
	 *
	 * @throws CasePlanExistsException When the object already has a plan.
	 */
	private function insertNormalised(CasePlanCreation $creation, array $normalised): void {
		$insertion = new CasePlanInsertion(
			anchor: $creation->anchor,
			settings: $normalised['settings'],
			actor: $creation->actor,
			provenance: $creation->provenance
		);
		$this->insertPlan(insertion: $insertion, items: $normalised['items']);
	}//end insertNormalised()

	/**
	 * Create a plan as an app's own in-process code, without a user session
	 * (a listener, a command, a repair step). Same validation and the same
	 * evaluation as {@see createPlan()}; no authorization, because only PHP
	 * running inside the server reaches it, and it can already write every
	 * row. The audit names `system:<app>`. Never routed.
	 *
	 * @param AppPlanWrite $write The anchor, the definition and the acting app id.
	 *
	 * @return array<string, mixed> The plan as {@see getPlan()} returns it.
	 *
	 * @throws CaseValidationException When the app id or the definition is refused, or a plan exists.
	 *
	 * @orphaned-write-capability exclude cross-app entry point; caller is dossiq's CasePlanProjectionService (retire-cmmn-caseplanstate)
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-in-process-callers-act-as-a-named-app
	 */
	public function createPlanAsSystem(AppPlanWrite $write): array {
		$creation = new CasePlanCreation(anchor: $write->anchor, definition: $write->definition, actor: $this->systemActor(app: $write->app));
		$this->insertNormalised(creation: $creation, normalised: $this->mechanics->definitions->validate(definition: $write->definition));

		return $this->getPlanAsSystem(objectUuid: $write->anchor->objectUuid, app: $write->app);
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
		$anchor = new CaseAnchor(objectUuid: $objectUuid, registerId: $registerId, schemaId: $schemaId);
		$request = new CaseItemsEnsureRequest(anchor: $anchor, definition: $definition, history: $history, actor: $this->systemActor(app: $app));

		return $this->ensurer()->ensure(request: $request);
	}//end ensureItems()

	/**
	 * A user-driven transition of one item (terminate a stage, reach a
	 * milestone by hand, complete a work item).
	 *
	 * @param CaseItemTransition $transition The item, the target state, the acting identity and the reason.
	 *
	 * @return CaseItem The item as persisted.
	 *
	 * @throws CaseAccessDeniedException When denied (audited).
	 * @throws \OCA\OpenRegister\Exception\CaseTransitionException When illegal.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
	 */
	public function transition(CaseItemTransition $transition): CaseItem {
		$item = $this->store->items->findByUuid(uuid: $transition->itemUuid);
		$why = new CaseTransitionCause(cause: CaseItemAudit::CAUSE_USER, causeRef: null, actor: $transition->actor, reason: $transition->reason);
		$this->moveByHand(move: new CaseItemMove(item: $item, to: $transition->to, tree: $this->treeFor(item: $item)), why: $why);

		return $this->store->items->findByUuid(uuid: (string)$item->getUuid());
	}//end transition()

	/**
	 * Authorize a user's move (the denial is audited), refuse a target that is
	 * no plan-item state, then move and settle.
	 *
	 * @param CaseItemMove        $move The item, the target state and the plan.
	 * @param CaseTransitionCause $why  The user's cause, naming the acting identity and the reason.
	 *
	 * @return void
	 *
	 * @throws CaseAccessDeniedException When denied (audited).
	 * @throws CaseValidationException When the target is not a plan-item state.
	 */
	private function moveByHand(CaseItemMove $move, CaseTransitionCause $why): void {
		$this->authorizeOrRecord(act: new CasePlanAct(verb: 'transition', actor: $why->actor), move: $move);

		if (in_array($move->to, CaseItem::STATES, true) === false) {
			throw new CaseValidationException(message: sprintf("'%s' is not a plan-item state.", $move->to));
		}

		$this->moveAndSettle(move: $move, why: $why);
	}//end moveByHand()

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
		$move = new CaseItemMove(item: $item, to: CaseItem::STATE_ENABLED, tree: $tree);
		$this->authorizeOrRecord(act: new CasePlanAct(verb: 'enable', actor: $uid), move: $move);
		$this->assertEnableable(item: $item, tree: $tree);

		$why = new CaseTransitionCause(cause: CaseItemAudit::CAUSE_USER, causeRef: 'enable', actor: $uid, reason: null);
		$this->moveAndSettle(move: $move, why: $why);

		return $this->store->items->findByUuid(uuid: $itemUuid);
	}//end enableDiscretionary()

	/**
	 * Attach an ad-hoc item to a live case: work that appears in no definition.
	 *
	 * Authorization derives from the parent stage (or the plan root); the item
	 * cannot declare its own. Creating one modifies no flow definition and
	 * creates no definition version: it is a row.
	 *
	 * @param CaseAdHocRequest $request The anchoring object, the item and the acting identity.
	 *
	 * @return CaseItem The item after attachment and evaluation.
	 *
	 * @throws CaseAccessDeniedException When denied (audited on the parent, when any).
	 * @throws CaseValidationException When the item is refused.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	public function attachAdHoc(CaseAdHocRequest $request): CaseItem {
		$tree = new CasePlanTree(items: $this->rowsOf(objectUuid: $request->objectUuid, missing: 'Object %s has no case plan to attach to.'));
		$inserted = $this->attach(attachment: new CaseAdHocAttachment(tree: $tree, data: $request->data, actor: $request->actor));
		$this->mechanics->cascade->evaluate(objectUuid: $request->objectUuid, actor: $request->actor);

		return $this->store->items->findByUuid(uuid: (string)$inserted->getUuid());
	}//end attachAdHoc()

	/**
	 * Authorize an ad-hoc attachment against its parent (or the plan root),
	 * refuse a bad item, and insert it under its parent.
	 *
	 * @param CaseAdHocAttachment $attachment The plan, the item and the acting identity.
	 *
	 * @return CaseItem The inserted row.
	 *
	 * @throws CaseAccessDeniedException When denied (audited on the parent, when any).
	 * @throws CaseValidationException When the item is refused.
	 */
	private function attach(CaseAdHocAttachment $attachment): CaseItem {
		$tree = $attachment->tree;
		$parent = $this->parentFor(data: $attachment->data, tree: $tree);
		$this->authorizeAttach(parent: $parent, attachment: $attachment);

		$node = $this->mechanics->definitions->validateAdHoc(node: $attachment->data);
		$this->assertNewKey(key: (string)$node['key'], tree: $tree);
		$this->assertLiveParent(parent: $parent);

		$parentId = $parent?->getId();
		$placed = new CasePlanPlacement(node: $node, parentId: $parentId, position: count($tree->children(parentId: $parentId)));
		$insertion = $this->adHocInsertion(tree: $tree, uid: $attachment->actor);

		return $this->transactional(mutation: fn (): CaseItem => $this->insertAdHoc(placed: $placed, insertion: $insertion));
	}//end attach()

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
	 * @param ListQuery   $query The page, and the `type` and `state` filters (either null for any).
	 * @param string|null $uid   The reading identity.
	 *
	 * @return array<string, mixed> results, total, limit, offset.
	 *
	 * @throws CaseAccessDeniedException For a non-administrator.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function findStuck(ListQuery $query, ?string $uid): array {
		$this->assertListsAcrossCases(uid: $uid);

		return $this->stuckPage(query: $query);
	}//end findStuck()

	/**
	 * Refuse a caller who is not an administrator: listing plan items across cases is an administrator's read.
	 *
	 * @param string|null $uid The reading identity.
	 *
	 * @return void
	 *
	 * @throws CaseAccessDeniedException For an anonymous caller or a non-administrator.
	 */
	private function assertListsAcrossCases(?string $uid): void {
		$identity = $this->anchorAccess->authorization->assertIdentified(uid: $uid, verb: 'list-items');
		if ($this->anchorAccess->authorization->isAdministrator(uid: $identity) === false) {
			throw new CaseAccessDeniedException(message: "Verb 'list-items' denied: listing plan items across cases is an administrator's read.");
		}
	}//end assertListsAcrossCases()

	/**
	 * One page of items by type and state, with the total computed in the datastore.
	 *
	 * @param ListQuery $query The page, and the `type` and `state` filters (either null for any).
	 *
	 * @return array<string, mixed> results, total, limit, offset.
	 */
	private function stuckPage(ListQuery $query): array {
		$type = ($query->filters['type'] ?? null);
		$state = ($query->filters['state'] ?? null);
		$offset = (int)$query->offset;
		$rows = $this->store->items->findByTypeAndState(query: $query);

		return [
			'results' => array_map(static fn (CaseItem $row): array => $row->jsonSerialize(), $rows),
			'total' => $this->store->items->countByTypeAndState(type: $type, state: $state),
			'limit' => $query->limit,
			'offset' => $offset,
		];
	}//end stuckPage()

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
	 * @param CaseCompletion $completion The anchoring object, the result and the acting identity.
	 *
	 * @return array<string, mixed> The plan, plus `result`.
	 *
	 * @throws CaseValidationException When the result is outside the set, or the plan is not finished.
	 * @throws CaseAccessDeniedException When the caller may not administer the plan.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	public function completeCase(CaseCompletion $completion): array {
		return $this->finish(completion: $completion);
	}//end completeCase()

	/**
	 * Require the finisher to administer the plan, refuse a result outside the
	 * allowed set and an unfinished plan, then mirror the result onto the object.
	 *
	 * @param CaseCompletion $completion The object, the result and the acting identity.
	 *
	 * @return array<string, mixed> The plan, plus `result`.
	 *
	 * @throws CaseValidationException When the result is outside the set, or the plan is not finished.
	 * @throws CaseAccessDeniedException When the caller may not administer the plan.
	 */
	private function finish(CaseCompletion $completion): array {
		$rows = $this->rowsOf(objectUuid: $completion->objectUuid);
		$tree = new CasePlanTree(items: $rows);
		$settings = $tree->settings();
		$act = new CasePlanAct(verb: 'complete-case', actor: $completion->actor);
		$this->anchorAccess->authorization->assertMayAdminister(act: $act, settings: $settings);
		$this->assertAllowedResult(result: $completion->result, settings: $settings);
		$this->assertFinished(tree: $tree);

		$this->anchorAccess->writer->mirrorResult(anyRow: $rows[0], result: $completion->result);
		$plan = $this->getPlan(objectUuid: $completion->objectUuid, uid: $completion->actor);
		$plan['result'] = $completion->result;

		return $plan;
	}//end finish()

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
		$act = new CasePlanAct(verb: 'delete-plan', actor: $uid);
		$this->anchorAccess->authorization->assertMayAdminister(act: $act, settings: (new CasePlanTree(items: $rows))->settings());

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
			$this->evaluateQuietly(trigger: new CasePlanTrigger(objectUuid: (string)$objectUuid));
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

		if ($item->isInTerminalState() === true || $this->terminateExpired(item: $item, timerUuid: $timerUuid) === false) {
			return false;
		}

		$this->evaluateQuietly(trigger: new CasePlanTrigger(objectUuid: (string)$item->getObjectUuid()));

		return true;
	}//end onTimerExpired()

	/**
	 * An object changed: evaluate its plan, if it has a live one, with the
	 * event in hand so object on-parts can fire.
	 *
	 * @param CasePlanTrigger $trigger The object, the catalog event id and the object's data after the change.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-business-state-is-written-through-to-the-register-never-owned-by-the-engine
	 */
	public function onObjectEvent(CasePlanTrigger $trigger): void {
		$this->evaluateWhileOpen(trigger: $trigger);
	}//end onObjectEvent()

	/**
	 * Re-evaluate a plan that still has open items; a plan without any is left alone.
	 *
	 * @param CasePlanTrigger $trigger The object, the event and its payload.
	 *
	 * @return void
	 */
	private function evaluateWhileOpen(CasePlanTrigger $trigger): void {
		if ($this->store->items->countOpenByObject(objectUuid: $trigger->objectUuid) === 0) {
			return;
		}

		$this->evaluateQuietly(trigger: $trigger);
	}//end evaluateWhileOpen()

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

		$anchor = new CaseAnchor(objectUuid: (string)$item->getObjectUuid(), registerId: $item->getRegisterId(), schemaId: $item->getSchemaId());
		$object = $this->anchorAccess->anchor->read(anchor: $anchor);

		return $this->mechanics->sentries->entrySentryOn(item: $item, occasion: new CaseSentryOccasion(tree: $tree, object: $object)) !== null;
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
				foreach ($items as $position => $node) {
					$this->insertNode(placed: new CasePlanPlacement(node: $node, parentId: null, position: (int)$position), insertion: $insertion);
				}
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
				anchor: new CaseAnchor(objectUuid: $objectUuid, registerId: $first->getRegisterId(), schemaId: $first->getSchemaId())
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
	 * Authorize a verb on an item, and AUDIT a denial before rethrowing it.
	 *
	 * @param CasePlanAct  $act  The verb and the acting identity.
	 * @param CaseItemMove $move The item, the requested state (for the audit row) and the plan.
	 *
	 * @return void
	 *
	 * @throws CaseAccessDeniedException The original denial, always rethrown.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function authorizeOrRecord(CasePlanAct $act, CaseItemMove $move): void {
		$uid = $act->actor;
		try {
			$this->anchorAccess->authorization->assertMayAct(act: $act, item: $move->item, tree: $move->tree);
		} catch (CaseAccessDeniedException $denial) {
			$refusal = new CaseTransitionCause(cause: CaseItemAudit::CAUSE_USER, causeRef: null, actor: $uid, reason: $denial->getMessage());
			$this->mechanics->machine->recordRefusal(attempt: new CasePlanMove(item: $move->item, to: $move->to, why: $refusal, tree: null));

			throw $denial;
		}
	}//end authorizeOrRecord()

	/**
	 * Authorize attaching under a parent stage, auditing a denial on it; at the
	 * root there is no item to audit the denial on, so it is only rethrown.
	 *
	 * @param CaseItem|null       $parent     The parent stage, or null for the root.
	 * @param CaseAdHocAttachment $attachment The plan and the acting identity.
	 *
	 * @return void
	 *
	 * @throws CaseAccessDeniedException When denied.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function authorizeAttach(?CaseItem $parent, CaseAdHocAttachment $attachment): void {
		$tree = $attachment->tree;
		if ($parent === null) {
			$act = new CasePlanAct(verb: 'attach', actor: $attachment->actor);
			$this->anchorAccess->authorization->assertMayAct(act: $act, item: null, tree: $tree);
			return;
		}

		$move = new CaseItemMove(item: $parent, to: CaseItem::STATE_AVAILABLE, tree: $tree);
		$this->authorizeOrRecord(act: new CasePlanAct(verb: 'attach', actor: $attachment->actor), move: $move);
	}//end authorizeAttach()

/**
	 * Insert one placed normalised node, audit its creation, and recurse into a stage's children.
	 *
	 * @param CasePlanPlacement $placed    The node, its containing stage's row id and its position.
	 * @param CasePlanInsertion $insertion The anchor, settings, creating identity and provenance.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	private function insertNode(CasePlanPlacement $placed, CasePlanInsertion $insertion): void {
		$persisted = $this->store->items->insert($this->mechanics->definitions->rowFrom(placed: $placed, insertion: $insertion));
		$flowUuid = $insertion->provenance->flowUuid;
		$why = new CaseTransitionCause(cause: CaseItemAudit::CAUSE_IMPORT, causeRef: $flowUuid, actor: $insertion->actor, reason: null);
		$this->mechanics->machine->recordCreationOf(item: $persisted, why: $why);

		$parentId = (int)$persisted->getId();
		foreach (($placed->node['children'] ?? []) as $position => $child) {
			$this->insertNode(placed: new CasePlanPlacement(node: $child, parentId: $parentId, position: (int)$position), insertion: $insertion);
		}
	}//end insertNode()

	/**
	 * What an ad-hoc row shares with its plan: the anchor of the plan's first
	 * row, the plan's settings, the attaching identity and the `adhoc` origin.
	 *
	 * @param CasePlanTree $tree The plan, its first row the anchor.
	 * @param string|null $uid The attaching identity.
	 *
	 * @return CasePlanInsertion The shared part of the insertion.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function adHocInsertion(CasePlanTree $tree, ?string $uid): CasePlanInsertion {
		$first = array_values($tree->all())[0];
		$anchor = new CaseAnchor(objectUuid: (string)$first->getObjectUuid(), registerId: $first->getRegisterId(), schemaId: $first->getSchemaId());

		$provenance = new CasePlanProvenance(origin: CaseItem::ORIGIN_ADHOC);

		return new CasePlanInsertion(anchor: $anchor, settings: $tree->settings(), actor: $uid, provenance: $provenance);
	}//end adHocInsertion()

	/**
	 * Insert one ad-hoc row under its parent (or the root) and audit its creation by the attaching actor.
	 *
	 * @param CasePlanPlacement $placed The validated ad-hoc node, its parent's row id and its position after its siblings.
	 * @param CasePlanInsertion $insertion The anchor, the plan's settings, the attaching identity and the `adhoc` origin.
	 *
	 * @return CaseItem The inserted row.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function insertAdHoc(CasePlanPlacement $placed, CasePlanInsertion $insertion): CaseItem {
		$row = $this->mechanics->definitions->rowFrom(placed: $placed, insertion: $insertion);
		$persisted = $this->store->items->insert($row);
		$why = new CaseTransitionCause(cause: CaseItemAudit::CAUSE_USER, causeRef: 'attach', actor: $insertion->actor, reason: null);
		$this->mechanics->machine->recordCreationOf(item: $persisted, why: $why);

		return $persisted;
	}//end insertAdHoc()

	/**
	 * Evaluate from a listener: a failure is logged, never rethrown into the
	 * event that caused it.
	 *
	 * @param CasePlanTrigger $trigger The object, and the event with its payload when any.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private function evaluateQuietly(CasePlanTrigger $trigger): void {
		try {
			$this->mechanics->cascade->evaluateOn(trigger: $trigger);
		} catch (Throwable $failure) {
			$this->store->logger->error(
				'[CasePlanService] Event-driven evaluation failed: ' . $failure->getMessage(),
				['object' => $trigger->objectUuid, 'event' => $trigger->event, 'exception' => $failure]
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
			$this->ensurer = ($this->bringOver->ensurer ?? new CasePlanEnsurer(
				store: $this->store,
				definitions: $this->mechanics->definitions,
				transitions: new CasePlanTransitions(),
				timers: $this->bringOver->timers
			));
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
	 * Move one item, then evaluate its plan.
	 *
	 * @param CaseItemMove $move The item, its target state and its plan.
	 * @param CaseTransitionCause $why The cause, its reference, the acting identity and the reason.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
	 */
	private function moveAndSettle(CaseItemMove $move, CaseTransitionCause $why): void {
		$this->move(move: $move, why: $why);
		$this->mechanics->cascade->evaluate(objectUuid: (string)$move->item->getObjectUuid(), actor: $why->actor);
	}//end moveAndSettle()

	/**
	 * Move one item through the one transition path.
	 *
	 * @param CaseItemMove $move The item, its target state and its plan.
	 * @param CaseTransitionCause $why The cause, its reference, the acting identity and the reason.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
	 */
	private function move(CaseItemMove $move, CaseTransitionCause $why): void {
		$this->mechanics->machine->commit(move: new CasePlanMove(item: $move->item, to: $move->to, why: $why, tree: $move->tree));
	}//end move()

	/**
	 * Terminate an item whose enforcing expiry fired; a failure is logged, never rethrown into the sweep.
	 *
	 * @param CaseItem $item The open item, found by the uuid the timer named.
	 * @param string $timerUuid The timer that fired.
	 *
	 * @return bool True when the item was terminated.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	private function terminateExpired(CaseItem $item, string $timerUuid): bool {
		$why = self::expiryCause(timerUuid: $timerUuid);
		try {
			$this->move(move: new CaseItemMove(item: $item, to: CaseItem::STATE_TERMINATED, tree: $this->treeFor(item: $item)), why: $why);
		} catch (Throwable $failure) {
			$this->store->logger->error(
				'[CasePlanService] An expired term could not terminate its plan item: ' . $failure->getMessage(),
				['item' => (string)$item->getUuid(), 'timer' => $timerUuid, 'exception' => $failure]
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
