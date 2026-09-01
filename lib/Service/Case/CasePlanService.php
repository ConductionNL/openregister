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
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\CaseItemAudit;
use OCA\OpenRegister\Db\CaseItemAuditMapper;
use OCA\OpenRegister\Db\CaseItemMapper;
use OCA\OpenRegister\Exception\CaseAccessDeniedException;
use OCA\OpenRegister\Exception\CaseValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
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
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
 */
class CasePlanService {

	/**
	 * Constructor.
	 *
	 * @param CaseItemMapper $items The plan-item table.
	 * @param CaseItemAuditMapper $audits The append-only audit.
	 * @param CasePlanStateMachine $machine The one transition path.
	 * @param CasePlanCascade $cascade The bounded fixpoint.
	 * @param CaseSentryEvaluator $sentries Entry criteria for the enableable query.
	 * @param CasePlanAuthorizationService $authorization Fail-closed decisions.
	 * @param CaseAnchorReader $anchor Read visibility and the anchor's data.
	 * @param CaseBusinessStateWriter $writer Result write-through.
	 * @param CasePlanDefinition $definitions Validates and compiles definitions.
	 * @param IDBConnection $db Holds the creation transactions.
	 * @param LoggerInterface $logger Failure reporting.
	 */
	public function __construct(
		private readonly CaseItemMapper $items,
		private readonly CaseItemAuditMapper $audits,
		private readonly CasePlanStateMachine $machine,
		private readonly CasePlanCascade $cascade,
		private readonly CaseSentryEvaluator $sentries,
		private readonly CasePlanAuthorizationService $authorization,
		private readonly CaseAnchorReader $anchor,
		private readonly CaseBusinessStateWriter $writer,
		private readonly CasePlanDefinition $definitions,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	public function getPlan(string $objectUuid, ?string $uid): array {
		$rows = $this->visibleRows(objectUuid: $objectUuid, uid: $uid);
		$ids = [];
		foreach ($rows as $row) {
			$ids[] = (int)$row->getId();
		}

		return [
			'objectUuid' => $objectUuid,
			'settings' => (new CasePlanTree(items: $rows))->settings(),
			'items' => array_map(static fn (CaseItem $row): array => $row->jsonSerialize(), $rows),
			'audit' => array_map(static fn (CaseItemAudit $entry): array => $entry->jsonSerialize(), $this->audits->findForItems(caseItemIds: $ids)),
		];
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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
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
		$normalised = $this->definitions->validate(definition: $definition);
		$this->authorization->assertMayAdminister(verb: 'create-plan', settings: $normalised['settings'], uid: $uid);

		if ($this->items->findByObject(objectUuid: $objectUuid) !== []) {
			throw new CaseValidationException(message: sprintf('Object %s already has a case plan; delete it before creating another.', $objectUuid));
		}

		$this->transactional(
			mutation: function () use ($normalised, $objectUuid, $registerId, $schemaId, $uid, $flowUuid, $flowVersion): void {
				$this->insertNodes(
					nodes: $normalised['items'],
					objectUuid: $objectUuid,
					registerId: $registerId,
					schemaId: $schemaId,
					parentId: null,
					settings: $normalised['settings'],
					actor: $uid,
					flowUuid: $flowUuid,
					flowVersion: $flowVersion
				);
			}
		);

		$this->cascade->evaluate(objectUuid: $objectUuid, actor: $uid);

		return $this->getPlan(objectUuid: $objectUuid, uid: $uid);
	}//end createPlan()

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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
	 */
	public function transition(string $itemUuid, string $to, ?string $uid, ?string $reason = null): CaseItem {
		$item = $this->items->findByUuid(uuid: $itemUuid);
		$tree = $this->treeFor(item: $item);
		$this->authorizeOrRecord(verb: 'transition', item: $item, tree: $tree, uid: $uid, requested: $to);

		if (in_array($to, CaseItem::STATES, true) === false) {
			throw new CaseValidationException(message: sprintf("'%s' is not a plan-item state.", $to));
		}

		$persisted = $this->machine->transition(
			item: $item,
			to: $to,
			cause: CaseItemAudit::CAUSE_USER,
			causeRef: null,
			actor: $uid,
			reason: $reason,
			tree: $tree
		);
		$this->cascade->evaluate(objectUuid: (string)$item->getObjectUuid(), actor: $uid);

		return $this->items->findByUuid(uuid: (string)$persisted->getUuid());
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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	public function enableDiscretionary(string $itemUuid, ?string $uid): CaseItem {
		$item = $this->items->findByUuid(uuid: $itemUuid);
		$tree = $this->treeFor(item: $item);
		$this->authorizeOrRecord(verb: 'enable', item: $item, tree: $tree, uid: $uid, requested: CaseItem::STATE_ENABLED);

		if ($this->isEnableable(item: $item, tree: $tree) === false) {
			throw new CaseValidationException(
				message: sprintf(
					"Plan item '%s' is not enableable: it must be discretionary, available, under an active parent, with its entry criteria satisfied.",
					(string)$item->getItemKey()
				)
			);
		}

		$this->machine->transition(
			item: $item,
			to: CaseItem::STATE_ENABLED,
			cause: CaseItemAudit::CAUSE_USER,
			causeRef: 'enable',
			actor: $uid,
			reason: null,
			tree: $tree
		);
		$this->cascade->evaluate(objectUuid: (string)$item->getObjectUuid(), actor: $uid);

		return $this->items->findByUuid(uuid: $itemUuid);
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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	public function attachAdHoc(string $objectUuid, array $data, ?string $uid): CaseItem {
		$rows = $this->items->findByObject(objectUuid: $objectUuid);
		if ($rows === []) {
			throw new DoesNotExistException(sprintf('Object %s has no case plan to attach to.', $objectUuid));
		}

		$tree = new CasePlanTree(items: $rows);
		$parent = $this->parentFor(data: $data, tree: $tree);
		$this->authorizeOrRecord(verb: 'attach', item: $parent, tree: $tree, uid: $uid, requested: CaseItem::STATE_AVAILABLE);

		$node = $this->definitions->validateAdHoc(node: $data);
		foreach ($tree->rowsForKey(key: (string)$node['key']) as $existing) {
			if ($existing !== null) {
				throw new CaseValidationException(message: sprintf("Item key '%s' already exists in this plan.", (string)$node['key']));
			}
		}

		if ($parent !== null && $parent->getState() !== CaseItem::STATE_ACTIVE) {
			throw new CaseValidationException(message: sprintf("Stage '%s' is not active; an ad-hoc item is attached to a live stage.", (string)$parent->getItemKey()));
		}

		$first = $rows[0];
		$inserted = $this->transactional(
			mutation: function () use ($node, $objectUuid, $first, $parent, $tree, $uid): CaseItem {
				$row = $this->definitions->rowFrom(
					node: $node,
					objectUuid: $objectUuid,
					registerId: $first->getRegisterId(),
					schemaId: $first->getSchemaId(),
					parentId: $parent?->getId(),
					position: count($tree->children(parentId: $parent?->getId())),
					settings: $tree->settings(),
					origin: CaseItem::ORIGIN_ADHOC,
					actor: $uid
				);
				$persisted = $this->items->insert($row);
				$this->machine->recordCreation(item: $persisted, cause: CaseItemAudit::CAUSE_USER, causeRef: 'attach', actor: $uid);

				return $persisted;
			}
		);

		$this->cascade->evaluate(objectUuid: $objectUuid, actor: $uid);

		return $this->items->findByUuid(uuid: (string)$inserted->getUuid());
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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function findStuck(?string $type, ?string $state, int $limit, int $offset, ?string $uid): array {
		$identity = $this->authorization->assertIdentified(uid: $uid, verb: 'list-items');
		if ($this->authorization->isAdministrator(uid: $identity) === false) {
			throw new CaseAccessDeniedException(message: "Verb 'list-items' denied: listing plan items across cases is an administrator's read.");
		}

		return [
			'results' => array_map(
				static fn (CaseItem $row): array => $row->jsonSerialize(),
				$this->items->findByTypeAndState(type: $type, state: $state, limit: $limit, offset: $offset)
			),
			'total' => $this->items->countByTypeAndState(type: $type, state: $state),
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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	public function evaluate(string $objectUuid, ?string $uid): array {
		$this->visibleRows(objectUuid: $objectUuid, uid: $uid);

		return $this->cascade->evaluate(objectUuid: $objectUuid, actor: $uid);
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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	public function completeCase(string $objectUuid, string $result, ?string $uid): array {
		$rows = $this->items->findByObject(objectUuid: $objectUuid);
		if ($rows === []) {
			throw new DoesNotExistException(sprintf('Object %s has no case plan.', $objectUuid));
		}

		$tree = new CasePlanTree(items: $rows);
		$settings = $tree->settings();
		$this->authorization->assertMayAdminister(verb: 'complete-case', settings: $settings, uid: $uid);

		$allowed = ($settings['results'] ?? []);
		if (is_array($allowed) === false || in_array($result, $allowed, true) === false) {
			throw new CaseValidationException(
				message: sprintf("Result '%s' is not in the case's allowed set [%s].", $result, implode(', ', is_array($allowed) ? $allowed : []))
			);
		}

		foreach ($tree->children(parentId: null) as $root) {
			if ($root->getRequired() === true && $tree->isItemTerminal(item: $root) === false) {
				throw new CaseValidationException(
					message: sprintf("The case cannot finish while required item '%s' is '%s'.", (string)$root->getItemKey(), (string)$root->getState())
				);
			}
		}

		$this->writer->mirrorResult(anyRow: $rows[0], result: $result);
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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-business-state-is-written-through-to-the-register-never-owned-by-the-engine
	 */
	public function deletePlan(string $objectUuid, ?string $uid): int {
		$rows = $this->items->findByObject(objectUuid: $objectUuid);
		if ($rows === []) {
			throw new DoesNotExistException(sprintf('Object %s has no case plan.', $objectUuid));
		}

		$this->authorization->assertMayAdminister(verb: 'delete-plan', settings: (new CasePlanTree(items: $rows))->settings(), uid: $uid);

		return $this->items->deleteByObject(objectUuid: $objectUuid);
	}//end deletePlan()

	/**
	 * A task reached a terminal state: evaluate the plan of the item it realised.
	 *
	 * @param string $taskUuid The task.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	public function onRealisationTerminal(string $taskUuid): void {
		$objects = [];
		foreach ($this->items->findByRealisation(realisationUuid: $taskUuid) as $row) {
			$objects[(string)$row->getObjectUuid()] = true;
		}

		foreach (array_keys($objects) as $objectUuid) {
			$this->evaluateQuietly(objectUuid: (string)$objectUuid, event: null, payload: []);
		}
	}//end onRealisationTerminal()

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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-business-state-is-written-through-to-the-register-never-owned-by-the-engine
	 */
	public function onObjectEvent(string $objectUuid, string $event, array $payload): void {
		if ($this->items->countOpenByObject(objectUuid: $objectUuid) === 0) {
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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function isEnableable(CaseItem $item, CasePlanTree $tree): bool {
		if ($item->getDiscretionary() !== true || $item->getState() !== CaseItem::STATE_AVAILABLE) {
			return false;
		}

		if ($tree->isParentActive(item: $item) === false) {
			return false;
		}

		$object = $this->anchor->read(
			objectUuid: (string)$item->getObjectUuid(),
			registerId: $item->getRegisterId(),
			schemaId: $item->getSchemaId()
		);

		return $this->sentries->entrySentry(item: $item, tree: $tree, object: $object) !== null;
	}//end isEnableable()

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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	private function visibleRows(string $objectUuid, ?string $uid): array {
		$rows = $this->items->findByObject(objectUuid: $objectUuid);
		if ($rows === []) {
			throw new DoesNotExistException(sprintf('Object %s has no case plan.', $objectUuid));
		}

		$first = $rows[0];
		$visible = $this->authorization->isAdministrator(uid: $uid)
			|| ($uid !== null && $this->anchor->mayRead(objectUuid: $objectUuid, registerId: $first->getRegisterId(), schemaId: $first->getSchemaId()) === true);
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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private function treeFor(CaseItem $item): CasePlanTree {
		return new CasePlanTree(items: $this->items->findByObject(objectUuid: (string)$item->getObjectUuid()));
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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function parentFor(array $data, CasePlanTree $tree): ?CaseItem {
		$ref = trim((string)($data['parent'] ?? ''));
		if ($ref === '') {
			return null;
		}

		foreach ($tree->all() as $row) {
			if ($row->getUuid() === $ref || $row->getItemKey() === $ref) {
				if ($row->getPlanItemType() !== CaseItem::TYPE_STAGE) {
					throw new CaseValidationException(message: sprintf("'%s' is not a stage; an ad-hoc item is attached to a stage or to the plan root.", $ref));
				}

				return $row;
			}
		}

		throw new CaseValidationException(message: sprintf("No stage '%s' exists in this plan.", $ref));
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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function authorizeOrRecord(string $verb, ?CaseItem $item, CasePlanTree $tree, ?string $uid, string $requested): void {
		try {
			$this->authorization->assertMayAct(verb: $verb, item: $item, tree: $tree, uid: $uid);
		} catch (CaseAccessDeniedException $denial) {
			if ($item !== null) {
				$this->machine->recordDenial(item: $item, to: $requested, actor: $uid, reason: $denial->getMessage());
			}

			throw $denial;
		}
	}//end authorizeOrRecord()

	/**
	 * Insert a level of normalised nodes and recurse into stages' children.
	 *
	 * @param array<int, array<string, mixed>> $nodes The nodes.
	 * @param string $objectUuid The anchor.
	 * @param int|null $registerId Its register.
	 * @param int|null $schemaId Its schema.
	 * @param int|null $parentId The containing stage's row id.
	 * @param array<string, mixed> $settings The plan settings.
	 * @param string|null $actor The creating identity.
	 * @param string|null $flowUuid Provenance.
	 * @param int|null $flowVersion Provenance.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	private function insertNodes(
		array $nodes,
		string $objectUuid,
		?int $registerId,
		?int $schemaId,
		?int $parentId,
		array $settings,
		?string $actor,
		?string $flowUuid,
		?int $flowVersion,
	): void {
		foreach ($nodes as $position => $node) {
			$origin = CaseItem::ORIGIN_DEFINED;
			if (($node['discretionary'] ?? false) === true) {
				$origin = CaseItem::ORIGIN_DISCRETIONARY;
			}

			$row = $this->definitions->rowFrom(
				node: $node,
				objectUuid: $objectUuid,
				registerId: $registerId,
				schemaId: $schemaId,
				parentId: $parentId,
				position: (int)$position,
				settings: $settings,
				origin: $origin,
				actor: $actor,
				flowUuid: $flowUuid,
				flowVersion: $flowVersion
			);
			$persisted = $this->items->insert($row);
			$this->machine->recordCreation(item: $persisted, cause: CaseItemAudit::CAUSE_IMPORT, causeRef: $flowUuid, actor: $actor);

			$this->insertNodes(
				nodes: ($node['children'] ?? []),
				objectUuid: $objectUuid,
				registerId: $registerId,
				schemaId: $schemaId,
				parentId: (int)$persisted->getId(),
				settings: $settings,
				actor: $actor,
				flowUuid: $flowUuid,
				flowVersion: $flowVersion
			);
		}
	}//end insertNodes()

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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private function evaluateQuietly(string $objectUuid, ?string $event, array $payload): void {
		try {
			$this->cascade->evaluate(objectUuid: $objectUuid, event: $event, payload: $payload);
		} catch (Throwable $failure) {
			$this->logger->error(
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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	private function transactional(callable $mutation): mixed {
		$this->db->beginTransaction();
		try {
			$result = $mutation();
			$this->db->commit();

			return $result;
		} catch (Throwable $failure) {
			$this->db->rollBack();
			throw $failure;
		}
	}//end transactional()
}//end class
