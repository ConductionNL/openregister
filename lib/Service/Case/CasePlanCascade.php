<?php

/**
 * The fixpoint: evaluate one case plan until nothing more can move.
 *
 * Every pass reads the plan fresh and applies, in order: the realisations
 * that have ended (a task completed, a run stopped) drive their items; exit
 * sentries terminate entered items; entry sentries admit available items
 * whose parent is active (a milestone completes on the spot, a work item
 * becomes active and is realised); enabled discretionary items start; active
 * stages whose completion rule holds complete; and a completed repeating
 * item grows its next realisation row. A pass that changed nothing ends the
 * loop.
 *
 * The loop is BOUNDED (`MAX_CASCADE_DEPTH`, the reference's figure at
 * `PlanItemCascade.php:64`). At the bound it fails loudly, naming the bound,
 * and rolls the whole evaluation back rather than leaving a half-cascaded
 * plan, the same posture as the engine's `MAX_TRANSITIONS`.
 *
 * The case layer never touches a run's marking, status or log. It reads a
 * run's terminal status and it asks `FlowRunService::queue()` for a new run;
 * nothing else crosses that line.
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
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\CaseItemAudit;
use OCA\OpenRegister\Db\CaseItemMapper;
use OCA\OpenRegister\Exception\CaseCascadeBoundException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Bounded fixpoint evaluation of one plan.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The loop coordinates the
 * mapper, the machine, the evaluator, the realiser and the anchor reader
 * inside one transaction; that is the whole of its job.
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
 */
class CasePlanCascade {

	/**
	 * The named bound. A definition whose sentries admit each other in a
	 * cycle hits it and fails; a real plan settles in a handful of passes.
	 *
	 * @var integer
	 */
	public const MAX_CASCADE_DEPTH = 50;

	/**
	 * Objects currently being evaluated, so a write-through's own object
	 * event cannot re-enter the loop it came from.
	 *
	 * @var array<string, bool>
	 */
	private array $evaluating = [];

	/**
	 * Constructor.
	 *
	 * @param CaseItemMapper $items The plan-item table.
	 * @param CasePlanStateMachine $machine The one transition path.
	 * @param CaseSentryEvaluator $sentries Entry and exit criteria.
	 * @param CaseRealisationService $realiser Reads how realisations ended.
	 * @param CaseAnchorReader $anchor Reads the anchoring object.
	 * @param IDBConnection $db Holds the evaluation's transaction.
	 * @param LoggerInterface $logger Failure reporting.
	 */
	public function __construct(
		private readonly CaseItemMapper $items,
		private readonly CasePlanStateMachine $machine,
		private readonly CaseSentryEvaluator $sentries,
		private readonly CaseRealisationService $realiser,
		private readonly CaseAnchorReader $anchor,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Evaluate a plan to its fixpoint, in one transaction.
	 *
	 * @param string $objectUuid The anchoring object.
	 * @param string|null $event The event being handled (an object event), or null.
	 * @param array<string, mixed> $payload The event's payload.
	 * @param string|null $actor The identity whose act triggered this, or null for the system.
	 *
	 * @return array{passes: int, transitions: int, skipped: bool} What happened.
	 *
	 * @throws CaseCascadeBoundException At the bound, after rolling back.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	public function evaluate(string $objectUuid, ?string $event = null, array $payload = [], ?string $actor = null): array {
		if (isset($this->evaluating[$objectUuid]) === true) {
			// Re-entered from inside our own transaction (a write-through's
			// object event): the outer loop's next pass sees the change.
			return ['passes' => 0, 'transitions' => 0, 'skipped' => true];
		}

		$this->evaluating[$objectUuid] = true;
		$outermost = $this->db->inTransaction() === false;
		$this->db->beginTransaction();
		$passes = 0;
		$transitions = 0;
		try {
			while (true) {
				$passes++;
				$changed = $this->pass(objectUuid: $objectUuid, event: $event, payload: $payload, actor: $actor);
				$transitions += $changed;
				if ($changed === 0) {
					break;
				}

				// Only the event being handled admits an object-event on-part,
				// and only in the pass it arrived in.
				$event = null;
				$payload = [];

				if ($passes >= self::MAX_CASCADE_DEPTH) {
					throw new CaseCascadeBoundException(
						message: sprintf(
							'Case-plan evaluation of object %s did not settle within %d passes (MAX_CASCADE_DEPTH); '
							. 'the plan was rolled back to its state before evaluation.',
							$objectUuid,
							self::MAX_CASCADE_DEPTH
						)
					);
				}
			}

			$this->db->commit();
		} catch (Throwable $failure) {
			$this->db->rollBack();
			$this->machine->discardEvents();
			unset($this->evaluating[$objectUuid]);
			$this->logger->warning(
				'[CasePlanCascade] Evaluation rolled back: ' . $failure->getMessage(),
				['object' => $objectUuid, 'passes' => $passes]
			);
			throw $failure;
		}

		unset($this->evaluating[$objectUuid]);
		if ($outermost === true) {
			$this->machine->flushEvents();
		}

		return ['passes' => $passes, 'transitions' => $transitions, 'skipped' => false];
	}//end evaluate()

	/**
	 * One pass over a fresh read of the plan.
	 *
	 * @param string $objectUuid The anchoring object.
	 * @param string|null $event The event being handled.
	 * @param array<string, mixed> $payload Its payload.
	 * @param string|null $actor The triggering identity.
	 *
	 * @return int How many transitions (or new rows) this pass produced.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private function pass(string $objectUuid, ?string $event, array $payload, ?string $actor): int {
		$rows = $this->items->findByObject(objectUuid: $objectUuid);
		if ($rows === []) {
			return 0;
		}

		$tree = new CasePlanTree(items: $rows);
		$first = $rows[0];
		$object = $this->anchor->read(
			objectUuid: $objectUuid,
			registerId: $first->getRegisterId(),
			schemaId: $first->getSchemaId()
		);

		$changed = 0;
		$changed += $this->syncRealisations(tree: $tree);
		$changed += $this->applyExits(tree: $tree, object: $object, event: $event, payload: $payload);
		$changed += $this->applyEntries(tree: $tree, object: $object, event: $event, payload: $payload);
		$changed += $this->startEnabled(tree: $tree, actor: $actor);
		$changed += $this->completeStages(tree: $tree);
		$changed += $this->repeat(tree: $tree);

		return $changed;
	}//end pass()

	/**
	 * A realisation that ended drives its item: completed -> completed,
	 * anything else -> terminated, cause `realisation`, ref the realisation.
	 *
	 * @param CasePlanTree $tree The plan.
	 *
	 * @return int Transitions made.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	private function syncRealisations(CasePlanTree $tree): int {
		$count = 0;
		foreach ($tree->all() as $row) {
			if ($row->getState() !== CaseItem::STATE_ACTIVE || $row->getRealisationKind() === CaseItem::REALISATION_NONE) {
				continue;
			}

			$outcome = $this->realiser->terminalOutcome(item: $row);
			if ($outcome === null) {
				continue;
			}

			$this->machine->transition(
				item: $row,
				to: $outcome,
				cause: CaseItemAudit::CAUSE_REALISATION,
				causeRef: $row->getRealisationUuid(),
				actor: null,
				reason: sprintf("Realisation %s '%s' ended as '%s'.", (string)$row->getRealisationKind(), (string)$row->getRealisationUuid(), $outcome),
				tree: $tree
			);
			$count++;
		}

		return $count;
	}//end syncRealisations()

	/**
	 * Exit sentries terminate entered, non-terminal items.
	 *
	 * @param CasePlanTree $tree The plan.
	 * @param array<string, mixed> $object The anchor's data.
	 * @param string|null $event The event being handled.
	 * @param array<string, mixed> $payload Its payload.
	 *
	 * @return int Transitions made.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	private function applyExits(CasePlanTree $tree, array $object, ?string $event, array $payload): int {
		$count = 0;
		foreach ($tree->all() as $row) {
			if ($row->isEntered() === false || $row->isInTerminalState() === true) {
				continue;
			}

			$sentry = $this->sentries->exitSentry(item: $row, tree: $tree, object: $object, event: $event, payload: $payload);
			if ($sentry === null) {
				continue;
			}

			$this->machine->transition(
				item: $row,
				to: CaseItem::STATE_TERMINATED,
				cause: CaseItemAudit::CAUSE_SENTRY,
				causeRef: $sentry,
				actor: null,
				reason: sprintf("Exit criterion '%s' fired.", $sentry),
				tree: $tree
			);
			$count++;
		}

		return $count;
	}//end applyExits()

	/**
	 * Entry sentries admit available, non-discretionary items whose parent
	 * is active. A milestone completes at once; a work item becomes active.
	 * A discretionary item waits for its explicit enable.
	 *
	 * @param CasePlanTree $tree The plan.
	 * @param array<string, mixed> $object The anchor's data.
	 * @param string|null $event The event being handled.
	 * @param array<string, mixed> $payload Its payload.
	 *
	 * @return int Transitions made.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	private function applyEntries(CasePlanTree $tree, array $object, ?string $event, array $payload): int {
		$count = 0;
		foreach ($tree->all() as $row) {
			if ($row->getState() !== CaseItem::STATE_AVAILABLE || $row->getDiscretionary() === true) {
				continue;
			}

			if ($tree->isParentActive(item: $row) === false) {
				continue;
			}

			$sentry = $this->sentries->entrySentry(item: $row, tree: $tree, object: $object, event: $event, payload: $payload);
			if ($sentry === null) {
				continue;
			}

			$target = CaseItem::STATE_ACTIVE;
			if ($row->getPlanItemType() === CaseItem::TYPE_MILESTONE) {
				$target = CaseItem::STATE_COMPLETED;
			}

			$this->machine->transition(
				item: $row,
				to: $target,
				cause: CaseItemAudit::CAUSE_SENTRY,
				causeRef: $sentry,
				actor: null,
				reason: null,
				tree: $tree
			);
			$count++;
		}

		return $count;
	}//end applyEntries()

	/**
	 * An enabled item under an active parent starts.
	 *
	 * @param CasePlanTree $tree The plan.
	 * @param string|null $actor The identity that enabled it, when known.
	 *
	 * @return int Transitions made.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function startEnabled(CasePlanTree $tree, ?string $actor): int {
		$count = 0;
		foreach ($tree->all() as $row) {
			if ($row->getState() !== CaseItem::STATE_ENABLED || $tree->isParentActive(item: $row) === false) {
				continue;
			}

			$this->machine->transition(
				item: $row,
				to: CaseItem::STATE_ACTIVE,
				cause: CaseItemAudit::CAUSE_USER,
				causeRef: 'enable',
				actor: $actor,
				reason: null,
				tree: $tree
			);
			$count++;
		}

		return $count;
	}//end startEnabled()

	/**
	 * Active stages whose completion rule holds complete. A stage realised by
	 * a flow run is driven by that run instead (syncRealisations).
	 *
	 * @param CasePlanTree $tree The plan.
	 *
	 * @return int Transitions made.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private function completeStages(CasePlanTree $tree): int {
		$count = 0;
		foreach ($tree->all() as $row) {
			if ($row->getPlanItemType() !== CaseItem::TYPE_STAGE || $row->getState() !== CaseItem::STATE_ACTIVE) {
				continue;
			}

			if ($row->getRealisationKind() === CaseItem::REALISATION_RUN || $tree->stageMayComplete(stage: $row) === false) {
				continue;
			}

			$this->machine->transition(
				item: $row,
				to: CaseItem::STATE_COMPLETED,
				cause: CaseItemAudit::CAUSE_CASCADE,
				causeRef: 'children',
				actor: null,
				reason: 'Every required child is terminal and no child is active.',
				tree: $tree
			);
			$count++;
		}

		return $count;
	}//end completeStages()

	/**
	 * A completed repeating row whose rule is not exhausted grows its next
	 * realisation: a new row of the same key, `realisation_count` + 1, in
	 * `available`. ONE plan item, N realisations, each its own row.
	 *
	 * @param CasePlanTree $tree The plan.
	 *
	 * @return int Rows created.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private function repeat(CasePlanTree $tree): int {
		$count = 0;
		foreach ($tree->all() as $row) {
			if ($row->getState() !== CaseItem::STATE_COMPLETED || $tree->repetitionExhausted(item: $row) === true) {
				continue;
			}

			$next = (int)$row->getRealisationCount() + 1;
			$exists = false;
			foreach ($tree->rowsForKey(key: (string)$row->getItemKey()) as $sibling) {
				if ((int)$sibling->getRealisationCount() === $next) {
					$exists = true;
					break;
				}
			}

			if ($exists === true) {
				continue;
			}

			$clone = $this->nextRealisation(previous: $row, count: $next);
			$inserted = $this->items->insert($clone);
			$this->machine->recordCreation(
				item: $inserted,
				cause: CaseItemAudit::CAUSE_REALISATION,
				causeRef: (string)$row->getUuid(),
				actor: null
			);
			$count++;
		}

		return $count;
	}//end repeat()

	/**
	 * The next realisation row of a repeating item: same definition, fresh
	 * lifecycle.
	 *
	 * @param CaseItem $previous The completed row.
	 * @param int $count The next realisation number.
	 *
	 * @return CaseItem The unsaved row.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private function nextRealisation(CaseItem $previous, int $count): CaseItem {
		$clone = new CaseItem();
		$clone->setItemKey($previous->getItemKey());
		$clone->setName($previous->getName());
		$clone->setDescription($previous->getDescription());
		$clone->setObjectUuid($previous->getObjectUuid());
		$clone->setRegisterId($previous->getRegisterId());
		$clone->setSchemaId($previous->getSchemaId());
		$clone->setFlowUuid($previous->getFlowUuid());
		$clone->setFlowVersion($previous->getFlowVersion());
		$clone->setDefinitionItemKey($previous->getDefinitionItemKey());
		$clone->setOrigin($previous->getOrigin());
		$clone->setParentItemId($previous->getParentItemId());
		$clone->setPlanItemType($previous->getPlanItemType());
		$clone->setPosition($previous->getPosition());
		$clone->setState(CaseItem::STATE_AVAILABLE);
		$clone->setIsTerminal(false);
		$clone->setEntryCriteria($previous->getEntryCriteria());
		$clone->setExitCriteria($previous->getExitCriteria());
		$clone->setRequired($previous->getRequired());
		$clone->setDiscretionary($previous->getDiscretionary());
		$clone->setRepetition($previous->getRepetition());
		$clone->setRealisationCount($count);
		$clone->setAuthorizationRules($previous->getAuthorizationRules());
		$clone->setCandidateUsers($previous->getCandidateUsers());
		$clone->setCandidateGroups($previous->getCandidateGroups());
		$clone->setCandidateRole($previous->getCandidateRole());
		$clone->setDueAt($previous->getDueAt());
		$clone->setExpiresAt($previous->getExpiresAt());
		$clone->setDoorlooptijd($previous->getDoorlooptijd());
		$clone->setServicenorm($previous->getServicenorm());
		$clone->setPlanSettings($previous->getPlanSettings());
		$clone->setCreatedBy($previous->getCreatedBy());

		return $clone;
	}//end nextRealisation()
}//end class
