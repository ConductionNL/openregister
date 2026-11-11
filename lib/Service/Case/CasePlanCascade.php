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
 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\CaseItemAudit;
use OCA\OpenRegister\Exception\CaseCascadeBoundException;
use Throwable;

/**
 * Bounded fixpoint evaluation of one plan.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The loop coordinates the
 * mapper, the machine, the evaluator, the realiser and the anchor reader
 * inside one transaction; that is the whole of its job.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
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
	 * The plan-item table, the transaction and the failure log the machine writes through.
	 *
	 * @var CasePlanStore
	 */
	private readonly CasePlanStore $store;

	/**
	 * Constructor.
	 *
	 * @param CasePlanStateMachine $machine The one transition path, and through its store the plan-item
	 *                                      table, the evaluation's transaction and the failure log.
	 * @param CaseCascadeReaders $readers The entry and exit criteria, how realisations ended, and the anchoring object.
	 */
	public function __construct(
		private readonly CasePlanStateMachine $machine,
		private readonly CaseCascadeReaders $readers,
	) {
		$this->store = $machine->store();
	}//end __construct()

	/**
	 * Evaluate a plan to its fixpoint, in one transaction.
	 *
	 * Re-entered from inside our own transaction (a write-through's object
	 * event), the evaluation is skipped: the outer loop's next pass sees the
	 * change.
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
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	public function evaluate(string $objectUuid, ?string $event = null, array $payload = [], ?string $actor = null): array {
		return $this->evaluateOn(trigger: new CasePlanTrigger(objectUuid: $objectUuid, event: $event, payload: $payload), actor: $actor);
	}//end evaluate()

	/**
	 * Evaluate one object's plan to a fixpoint on one trigger: see {@see evaluate()}.
	 *
	 * @param CasePlanTrigger $trigger The anchoring object, and the event with its payload when an event caused this.
	 * @param string|null     $actor   The identity whose act triggered this, or null for the system.
	 *
	 * @return array{passes: int, transitions: int, skipped: bool} What happened.
	 *
	 * @throws CaseCascadeBoundException At the bound, after rolling back.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	public function evaluateOn(CasePlanTrigger $trigger, ?string $actor = null): array {
		$objectUuid = $trigger->objectUuid;
		if (isset($this->evaluating[$objectUuid]) === true) {
			return ['passes' => 0, 'transitions' => 0, 'skipped' => true];
		}

		$this->evaluating[$objectUuid] = true;
		$outermost = $this->store->db->inTransaction() === false;
		$run = new CaseCascadeRun(objectUuid: $objectUuid, event: $trigger->event, payload: $trigger->payload, actor: $actor);
		$this->evaluateInTransaction(run: $run);
		$this->settle(objectUuid: $objectUuid, outermost: $outermost);

		return ['passes' => $run->passes, 'transitions' => $run->transitions, 'skipped' => false];
	}//end evaluateOn()

	/**
	 * Run the passes inside a transaction; on any failure roll back, drop the
	 * buffered events, report, and rethrow.
	 *
	 * @param CaseCascadeRun $run The evaluation.
	 *
	 * @return void
	 *
	 * @throws Throwable Whatever failed, after rolling back.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private function evaluateInTransaction(CaseCascadeRun $run): void {
		$this->store->db->beginTransaction();
		try {
			$this->runToFixpoint(run: $run);
			$this->store->db->commit();
		} catch (Throwable $failure) {
			$this->rollBack(run: $run, failure: $failure);
			throw $failure;
		}
	}//end evaluateInTransaction()

	/**
	 * Undo a failed evaluation: roll back, drop the buffered events, release
	 * the object and report how far it got.
	 *
	 * @param CaseCascadeRun $run The evaluation.
	 * @param Throwable $failure What failed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private function rollBack(CaseCascadeRun $run, Throwable $failure): void {
		$this->store->db->rollBack();
		$this->machine->discardEvents();
		unset($this->evaluating[$run->objectUuid]);
		$this->store->logger->warning(
			'[CasePlanCascade] Evaluation rolled back: ' . $failure->getMessage(),
			['object' => $run->objectUuid, 'passes' => $run->passes]
		);
	}//end rollBack()

	/**
	 * Release a committed evaluation; the outermost one flushes the events.
	 *
	 * @param string $objectUuid The anchoring object.
	 * @param bool $outermost Whether the evaluation opened the transaction.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private function settle(string $objectUuid, bool $outermost): void {
		unset($this->evaluating[$objectUuid]);
		if ($outermost === true) {
			$this->machine->flushEvents();
		}
	}//end settle()

	/**
	 * Pass until a pass changes nothing, or the bound is reached.
	 *
	 * @param CaseCascadeRun $run The evaluation.
	 *
	 * @return void
	 *
	 * @throws CaseCascadeBoundException At the bound.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private function runToFixpoint(CaseCascadeRun $run): void {
		while (true) {
			$run->passes++;
			$changed = $this->pass(run: $run);
			$run->transitions += $changed;
			if ($changed === 0) {
				return;
			}

			$run->consumeEvent();
			self::assertWithinBound(run: $run);
		}
	}//end runToFixpoint()

	/**
	 * Refuse to pass beyond MAX_CASCADE_DEPTH.
	 *
	 * @param CaseCascadeRun $run The evaluation.
	 *
	 * @return void
	 *
	 * @throws CaseCascadeBoundException At the bound.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private static function assertWithinBound(CaseCascadeRun $run): void {
		if ($run->passes < self::MAX_CASCADE_DEPTH) {
			return;
		}

		throw new CaseCascadeBoundException(
			message: sprintf(
				'Case-plan evaluation of object %s did not settle within %d passes (MAX_CASCADE_DEPTH); '
				. 'the plan was rolled back to its state before evaluation.',
				$run->objectUuid,
				self::MAX_CASCADE_DEPTH
			)
		);
	}//end assertWithinBound()

	/**
	 * One pass over a fresh read of the plan.
	 *
	 * @param CaseCascadeRun $run The evaluation.
	 *
	 * @return int How many transitions (or new rows) this pass produced.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private function pass(CaseCascadeRun $run): int {
		$rows = $this->store->items->findByObject(objectUuid: $run->objectUuid);
		if ($rows === []) {
			return 0;
		}

		$tree = new CasePlanTree(items: $rows);
		$object = $this->readers->anchor->read(
			anchor: new CaseAnchor(objectUuid: $run->objectUuid, registerId: $rows[0]->getRegisterId(), schemaId: $rows[0]->getSchemaId())
		);
		$state = new CasePassState(tree: $tree, object: $object, run: $run);

		return $this->applyRules(state: $state);
	}//end pass()

	/**
	 * The rules, in order, each over the whole plan: realisations, exits,
	 * entries, enabled items, stage completion, repetition.
	 *
	 * @param CasePassState $state The pass.
	 *
	 * @return int Transitions and new rows made.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private function applyRules(CasePassState $state): int {
		$tree = $state->tree;
		$changed = $this->drive(tree: $tree, stepFor: $this->realisationStep(...));
		$changed += $this->drive(tree: $tree, stepFor: fn (CaseItem $row): ?CaseCascadeStep => $this->exitStep(row: $row, state: $state));
		$changed += $this->drive(tree: $tree, stepFor: fn (CaseItem $row): ?CaseCascadeStep => $this->entryStep(row: $row, state: $state));
		$changed += $this->drive(tree: $tree, stepFor: fn (CaseItem $row): ?CaseCascadeStep => self::enabledStep(row: $row, state: $state));
		$changed += $this->drive(tree: $tree, stepFor: fn (CaseItem $row): ?CaseCascadeStep => self::stageStep(row: $row, tree: $tree));

		return $changed + $this->repeat(tree: $tree);
	}//end applyRules()

	/**
	 * Apply one rule to every row of the plan: each step it yields is taken.
	 *
	 * @param CasePlanTree $tree The plan.
	 * @param callable(CaseItem): ?CaseCascadeStep $stepFor The rule.
	 *
	 * @return int Transitions made.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private function drive(CasePlanTree $tree, callable $stepFor): int {
		$count = 0;
		foreach ($tree->all() as $row) {
			$step = $stepFor($row);
			if ($step !== null) {
				$this->take(step: $step, tree: $tree);
				$count++;
			}
		}

		return $count;
	}//end drive()

	/**
	 * Take one step through the state machine.
	 *
	 * @param CaseCascadeStep $step The step.
	 * @param CasePlanTree $tree The plan.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private function take(CaseCascadeStep $step, CasePlanTree $tree): void {
		$cause = $step->why;
		$why = new CaseTransitionCause(cause: $cause->cause, causeRef: $cause->causeRef, actor: $cause->actor, reason: $cause->reason);
		$this->machine->commit(move: new CasePlanMove(item: $step->item, to: $step->to, why: $why, tree: $tree));
	}//end take()

	/**
	 * A realisation that ended drives its active item.
	 *
	 * @param CaseItem $row The row.
	 *
	 * @return CaseCascadeStep|null The step, or null when the row is not driven now.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	private function realisationStep(CaseItem $row): ?CaseCascadeStep {
		if ($row->getState() !== CaseItem::STATE_ACTIVE || $row->getRealisationKind() === CaseItem::REALISATION_NONE) {
			return null;
		}

		$outcome = $this->readers->realiser->terminalOutcome(item: $row);
		if ($outcome === null) {
			return null;
		}

		return CaseCascadeStep::realisationEnded(item: $row, outcome: $outcome);
	}//end realisationStep()

	/**
	 * Exit sentries terminate entered, non-terminal items.
	 *
	 * @param CaseItem $row The row.
	 * @param CasePassState $state The pass.
	 *
	 * @return CaseCascadeStep|null The step, or null when no exit sentry fires.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	private function exitStep(CaseItem $row, CasePassState $state): ?CaseCascadeStep {
		if ($row->isEntered() === false || $row->isInTerminalState() === true) {
			return null;
		}

		$sentries = $this->readers->sentries;
		$sentry = $sentries->exitSentryOn(item: $row, occasion: CaseSentryOccasion::fromPass(state: $state));
		if ($sentry === null) {
			return null;
		}

		return CaseCascadeStep::exitFired(item: $row, sentry: $sentry);
	}//end exitStep()

	/**
	 * Entry sentries admit available, non-discretionary items whose parent
	 * is active. A discretionary item waits for its explicit enable.
	 *
	 * @param CaseItem $row The row.
	 * @param CasePassState $state The pass.
	 *
	 * @return CaseCascadeStep|null The step, or null when the row is not admitted.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	private function entryStep(CaseItem $row, CasePassState $state): ?CaseCascadeStep {
		if (self::awaitsEntry(row: $row, state: $state) === false) {
			return null;
		}

		$sentries = $this->readers->sentries;
		$sentry = $sentries->entrySentryOn(item: $row, occasion: CaseSentryOccasion::fromPass(state: $state));
		if ($sentry === null) {
			return null;
		}

		return CaseCascadeStep::entryFired(item: $row, sentry: $sentry);
	}//end entryStep()

	/**
	 * Whether a row waits for its entry sentry: available, not discretionary
	 * (that one waits for its explicit enable), under an active parent.
	 *
	 * @param CaseItem $row The row.
	 * @param CasePassState $state The pass.
	 *
	 * @return bool True when its entry criteria are what admits it.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	private static function awaitsEntry(CaseItem $row, CasePassState $state): bool {
		return $row->getState() === CaseItem::STATE_AVAILABLE
			&& $row->getDiscretionary() !== true
			&& $state->tree->isParentActive(item: $row) === true;
	}//end awaitsEntry()

	/**
	 * Whether a row is a stage that is active.
	 *
	 * @param CaseItem $row The row.
	 *
	 * @return bool True for an active stage.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private static function isActiveStage(CaseItem $row): bool {
		return $row->getPlanItemType() === CaseItem::TYPE_STAGE && $row->getState() === CaseItem::STATE_ACTIVE;
	}//end isActiveStage()

	/**
	 * An enabled item under an active parent starts.
	 *
	 * @param CaseItem $row The row.
	 * @param CasePassState $state The pass; its actor enabled the item, when known.
	 *
	 * @return CaseCascadeStep|null The step, or null when the row does not start.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private static function enabledStep(CaseItem $row, CasePassState $state): ?CaseCascadeStep {
		if ($row->getState() !== CaseItem::STATE_ENABLED || $state->tree->isParentActive(item: $row) === false) {
			return null;
		}

		return CaseCascadeStep::enabledStarts(item: $row, actor: $state->actor);
	}//end enabledStep()

	/**
	 * Active stages whose completion rule holds complete. A stage realised by
	 * a flow run is driven by that run instead (realisationStep).
	 *
	 * @param CaseItem $row The row.
	 * @param CasePlanTree $tree The plan.
	 *
	 * @return CaseCascadeStep|null The step, or null when the row is not a completing stage.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private static function stageStep(CaseItem $row, CasePlanTree $tree): ?CaseCascadeStep {
		$runDriven = $row->getRealisationKind() === CaseItem::REALISATION_RUN;
		if (self::isActiveStage(row: $row) === false || $runDriven === true || $tree->stageMayComplete(stage: $row) === false) {
			return null;
		}

		return CaseCascadeStep::stageCompletes(stage: $row);
	}//end stageStep()

	/**
	 * A completed repeating row whose rule is not exhausted grows its next
	 * realisation: a new row of the same key, `realisation_count` + 1, in
	 * `available`. ONE plan item, N realisations, each its own row.
	 *
	 * @param CasePlanTree $tree The plan.
	 *
	 * @return int Rows created.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private function repeat(CasePlanTree $tree): int {
		$count = 0;
		foreach ($tree->all() as $row) {
			if ($row->getState() !== CaseItem::STATE_COMPLETED || $tree->repetitionExhausted(item: $row) === true) {
				continue;
			}

			if (self::nextRealisationExists(row: $row, tree: $tree) === false) {
				$this->createNextRealisation(previous: $row);
				$count++;
			}
		}

		return $count;
	}//end repeat()

	/**
	 * Whether the row's next realisation already exists.
	 *
	 * @param CaseItem $row The completed row.
	 * @param CasePlanTree $tree The plan.
	 *
	 * @return bool True when a row of the same key carries the next count.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private static function nextRealisationExists(CaseItem $row, CasePlanTree $tree): bool {
		$next = (int)$row->getRealisationCount() + 1;
		foreach ($tree->rowsForKey(key: (string)$row->getItemKey()) as $sibling) {
			if ((int)$sibling->getRealisationCount() === $next) {
				return true;
			}
		}

		return false;
	}//end nextRealisationExists()

	/**
	 * Insert the next realisation row and record its creation.
	 *
	 * @param CaseItem $previous The completed row.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private function createNextRealisation(CaseItem $previous): void {
		$clone = self::nextRealisation(previous: $previous, count: (int)$previous->getRealisationCount() + 1);
		$inserted = $this->store->items->insert($clone);
		$why = new CaseTransitionCause(cause: CaseItemAudit::CAUSE_REALISATION, causeRef: (string)$previous->getUuid(), actor: null, reason: null);
		$this->machine->recordCreationOf(item: $inserted, why: $why);
	}//end createNextRealisation()

	/**
	 * The next realisation row of a repeating item: same definition, fresh
	 * lifecycle.
	 *
	 * @param CaseItem $previous The completed row.
	 * @param int $count The next realisation number.
	 *
	 * @return CaseItem The unsaved row.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private static function nextRealisation(CaseItem $previous, int $count): CaseItem {
		$clone = new CaseItem();
		self::copyPlacement(previous: $previous, clone: $clone);
		$clone->setState(CaseItem::STATE_AVAILABLE);
		$clone->setIsTerminal(false);
		self::copyRules(previous: $previous, clone: $clone);
		$clone->setRealisationCount($count);
		self::copyAssignment(previous: $previous, clone: $clone);

		return $clone;
	}//end nextRealisation()

	/**
	 * Copy what the row is and where it sits in the plan.
	 *
	 * @param CaseItem $previous The completed row.
	 * @param CaseItem $clone The new row.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private static function copyPlacement(CaseItem $previous, CaseItem $clone): void {
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
	}//end copyPlacement()

	/**
	 * Copy the row's criteria and repetition rule.
	 *
	 * @param CaseItem $previous The completed row.
	 * @param CaseItem $clone The new row.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private static function copyRules(CaseItem $previous, CaseItem $clone): void {
		$clone->setEntryCriteria($previous->getEntryCriteria());
		$clone->setExitCriteria($previous->getExitCriteria());
		$clone->setRequired($previous->getRequired());
		$clone->setDiscretionary($previous->getDiscretionary());
		$clone->setRepetition($previous->getRepetition());
	}//end copyRules()

	/**
	 * Copy who may act on the row, its deadlines, settings and creator.
	 *
	 * @param CaseItem $previous The completed row.
	 * @param CaseItem $clone The new row.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private static function copyAssignment(CaseItem $previous, CaseItem $clone): void {
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
	}//end copyAssignment()
}//end class
