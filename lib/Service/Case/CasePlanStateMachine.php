<?php

/**
 * One plan-item transition, done properly.
 *
 * Every state change in the case layer passes through {@see transition()}:
 * legality against the table, `is_terminal` written in the same statement as
 * `state`, a conditional UPDATE so a concurrent mover loses loudly, the audit
 * row appended in the SAME transaction, the realisation created on entry or
 * closed on exit, the write-through of a milestone, and the stage-exit
 * cascade, each cascaded child individually audited with `cause: cascade`
 * and the parent uuid as `cause_ref`. Denials are audited too.
 *
 * Events are dispatched AFTER the transaction commits, never inside it.
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
 * @spec openspec/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use DateTime;
use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\CaseItemAudit;
use OCA\OpenRegister\Event\CaseItemTransitionedEvent;
use OCA\OpenRegister\Exception\CaseTransitionException;
use Throwable;

/**
 * The one write path for plan-item state.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The machine owns the
 * transaction across two mappers, the table, the realiser, the writer and
 * the dispatcher; that is its job description.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
 */
class CasePlanStateMachine {

	/**
	 * The actor recorded for transitions nobody asked for by hand.
	 */
	public const SYSTEM_ACTOR = 'case-plan';

	/**
	 * Events waiting for the outermost transaction to commit.
	 *
	 * @var array<int, CaseItemTransitionedEvent>
	 */
	private array $pending = [];

	/**
	 * Constructor.
	 *
	 * @param CasePlanStore $store The plan-item table, the audit, the one transaction per transition and the failure log.
	 * @param CasePlanTransitions $table The lifecycle table.
	 * @param CaseTransitionEffects $effects The realiser, the write-through and the event dispatcher.
	 */
	public function __construct(
		private readonly CasePlanStore $store,
		private readonly CasePlanTransitions $table,
		private readonly CaseTransitionEffects $effects,
	) {

	}//end __construct()

	/**
	 * Move one item, with everything that entails, in one transaction.
	 *
	 * @param CaseItem $item The item, as read.
	 * @param string $to The target state.
	 * @param string $cause sentry | user | realisation | cascade | import.
	 * @param string|null $causeRef The sentry id, task/run uuid or parent uuid.
	 * @param string|null $actor The acting identity, or null for the system.
	 * @param string|null $reason Free text; becomes `terminated_reason` on a termination.
	 * @param CasePlanTree|null $tree The plan, when the caller has it (for the cascade).
	 *
	 * @return CaseItem The item as persisted.
	 *
	 * @throws CaseTransitionException When illegal, or when another mover won.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
	 */
	public function transition(
		CaseItem $item,
		string $to,
		string $cause,
		?string $causeRef,
		?string $actor,
		?string $reason = null,
		?CasePlanTree $tree = null,
	): CaseItem {
		$this->table->assertLegal(item: $item, to: $to);

		$why = new CaseTransitionCause(cause: $cause, causeRef: $causeRef, actor: ($actor ?? self::SYSTEM_ACTOR), reason: $reason);
		$outermost = $this->store->db->inTransaction() === false;
		$persisted = $this->inTransaction(
			outermost: $outermost,
			mutation: fn (): CaseItem => $this->apply(item: $item, to: $to, why: $why, tree: $tree)
		);
		if ($outermost === true) {
			$this->flushEvents();
		}

		return $persisted;
	}//end transition()

	/**
	 * Record a refused attempt. Mutates nothing; appended outside any verb
	 * transaction, and a failure to record it is logged, not converted.
	 *
	 * @param CaseItem $item The item acted on.
	 * @param string $to The requested state (or the verb, for enable/attach).
	 * @param string|null $actor The acting identity.
	 * @param string $reason The denial message.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	public function recordDenial(CaseItem $item, string $to, ?string $actor, string $reason): void {
		if ($item->getId() === null) {
			return;
		}

		try {
			$this->store->audits->insert($this->denialOf(item: $item, to: $to, actor: $actor, reason: $reason));
		} catch (Throwable $failure) {
			$this->store->logger->warning(
				'[CasePlanStateMachine] Could not record an authorization denial: ' . $failure->getMessage(),
				['item' => $item->getUuid()]
			);
		}
	}//end recordDenial()

	/**
	 * The audit row of a refused attempt.
	 *
	 * @param CaseItem $item The item acted on.
	 * @param string $to The requested state (or the verb, for enable/attach).
	 * @param string|null $actor The acting identity.
	 * @param string $reason The denial message.
	 *
	 * @return CaseItemAudit The unsaved row, `authorized: false`.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function denialOf(CaseItem $item, string $to, ?string $actor, string $reason): CaseItemAudit {
		$denial = new CaseTransitionCause(cause: CaseItemAudit::CAUSE_USER, causeRef: null, actor: $actor, reason: $reason);
		$entry = $denial->auditFor(item: $item, fromState: (string)$item->getState(), toState: $to);
		$entry->setAuthorized(false);

		return $entry;
	}//end denialOf()

	/**
	 * Audit a row's creation: `from_state` null, `to_state` its initial state.
	 * Called inside the creator's transaction, right after the insert.
	 *
	 * @param CaseItem $item The inserted item.
	 * @param string $cause import | user | realisation (a repetition).
	 * @param string|null $causeRef The definition, the actor's verb, or the previous realisation.
	 * @param string|null $actor The acting identity.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function recordCreation(CaseItem $item, string $cause, ?string $causeRef, ?string $actor): void {
		$why = new CaseTransitionCause(cause: $cause, causeRef: $causeRef, actor: ($actor ?? self::SYSTEM_ACTOR), reason: null);
		$this->store->audits->insert($why->auditFor(item: $item, fromState: '', toState: (string)$item->getState()));
	}//end recordCreation()

	/**
	 * Dispatch every committed transition's event. Called by the outermost
	 * committer; a nested caller's events wait here for it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	public function flushEvents(): void {
		$events = $this->pending;
		$this->pending = [];
		if ($this->effects->dispatcher === null) {
			return;
		}

		foreach ($events as $event) {
			$this->dispatch(event: $event);
		}
	}//end flushEvents()

	/**
	 * Forget queued events after a rolled-back outer transaction.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	public function discardEvents(): void {
		$this->pending = [];
	}//end discardEvents()

	/**
	 * Dispatch one committed transition's event; a failing listener is logged, never rethrown.
	 *
	 * @param CaseItemTransitionedEvent $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	private function dispatch(CaseItemTransitionedEvent $event): void {
		try {
			$this->effects->dispatcher?->dispatchTyped($event);
		} catch (Throwable $failure) {
			$this->store->logger->warning(
				'[CasePlanStateMachine] A plan-item event listener failed; the transition itself is unaffected: ' . $failure->getMessage(),
				['item' => $event->getItem()->getUuid(), 'exception' => $failure]
			);
		}
	}//end dispatch()

	/**
	 * Run a transition's body in a (possibly nested) transaction; on failure
	 * roll back, and the outermost caller drops the events queued meanwhile.
	 *
	 * @param bool $outermost Whether no transaction was open before this one.
	 * @param callable $mutation The body.
	 *
	 * @return CaseItem The persisted item.
	 *
	 * @throws Throwable Whatever the body threw, after rolling back.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
	 */
	private function inTransaction(bool $outermost, callable $mutation): CaseItem {
		$this->store->db->beginTransaction();
		try {
			$persisted = $mutation();
			$this->store->db->commit();
		} catch (Throwable $failure) {
			$this->store->db->rollBack();
			if ($outermost === true) {
				$this->pending = [];
			}

			throw $failure;
		}

		return $persisted;
	}//end inTransaction()

	/**
	 * The body of a transition, inside the transaction.
	 *
	 * @param CaseItem $item The item, as read.
	 * @param string $to The target state.
	 * @param CaseTransitionCause $why The cause, its reference, the acting identity and the reason.
	 * @param CasePlanTree|null $tree The plan, when known.
	 *
	 * @return CaseItem The persisted item.
	 *
	 * @throws CaseTransitionException When another mover won the row.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
	 */
	private function apply(CaseItem $item, string $to, CaseTransitionCause $why, ?CasePlanTree $tree): CaseItem {
		$from = (string)$item->getState();
		$this->stamp(item: $item, to: $to, why: $why);

		// THE one place `state` and `is_terminal` change, in the same statement.
		$item->setState($to);
		$item->setIsTerminal($this->table->isTerminal(state: $to));

		if ($this->store->items->updateIfState(item: $item, expectedState: $from) === false) {
			throw new CaseTransitionException(
				message: sprintf("Plan item '%s' was moved concurrently out of '%s'; this transition was not applied.", (string)$item->getUuid(), $from)
			);
		}

		$this->afterUpdate(item: $item, from: $from, why: $why, tree: $tree);

		return $item;
	}//end apply()

	/**
	 * What follows a won update inside the transaction: the audit row, the
	 * effects outside the row, the queued event and a stage's cascade.
	 *
	 * @param CaseItem $item The item, already in its new state.
	 * @param string $from The state before.
	 * @param CaseTransitionCause $why The cause.
	 * @param CasePlanTree|null $tree The plan, when known.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
	 */
	private function afterUpdate(CaseItem $item, string $from, CaseTransitionCause $why, ?CasePlanTree $tree): void {
		$this->store->audits->insert($why->auditFor(item: $item, fromState: $from, toState: (string)$item->getState()));
		$this->closeRealisation(item: $item, why: $why);
		$this->mirrorMilestone(item: $item);
		$this->pending[] = new CaseItemTransitionedEvent(item: $item, fromState: $from);

		if ($item->getPlanItemType() === CaseItem::TYPE_STAGE && $item->isInTerminalState() === true) {
			$this->cascade(stage: $item, tree: $tree);
		}
	}//end afterUpdate()

	/**
	 * The side stamps of entering a state: realise and stamp `entered_at` on
	 * activation, stamp `entered_at` on a milestone's completion, record the
	 * reason on a termination.
	 *
	 * @param CaseItem $item The item.
	 * @param string $to The target state.
	 * @param CaseTransitionCause $why Its reason, and the acting identity the realisation is created by.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	private function stamp(CaseItem $item, string $to, CaseTransitionCause $why): void {
		if ($to === CaseItem::STATE_ACTIVE) {
			$this->effects->realiser->realise(item: $item, actor: (string)$why->actor);
			$item->setEnteredAt(new DateTime());
		}

		if ($to === CaseItem::STATE_COMPLETED && $item->getEnteredAt() === null) {
			$item->setEnteredAt(new DateTime());
		}

		if ($to === CaseItem::STATE_TERMINATED) {
			$item->setTerminatedReason($why->reason);
		}
	}//end stamp()

	/**
	 * An exited work item closes its realisation, UNLESS the realisation is
	 * what ended it: it is already closed, and terminating a completed task
	 * would be the drift the one-directional rule forbids.
	 *
	 * @param CaseItem $item The item, already in its new state.
	 * @param CaseTransitionCause $why The cause and its reason.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	private function closeRealisation(CaseItem $item, CaseTransitionCause $why): void {
		$hasRealisation = trim((string)$item->getRealisationUuid()) !== '';
		if ($item->isInTerminalState() === true && $why->cause !== CaseItemAudit::CAUSE_REALISATION && $hasRealisation === true) {
			$this->effects->realiser->terminate(
				item: $item,
				reason: ($why->reason ?? sprintf("Plan item '%s' reached '%s'.", (string)$item->getItemKey(), (string)$item->getState()))
			);
		}
	}//end closeRealisation()

	/**
	 * A reached milestone is mirrored onto the object.
	 *
	 * @param CaseItem $item The item, already in its new state.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-business-state-is-written-through-to-the-register-never-owned-by-the-engine
	 */
	private function mirrorMilestone(CaseItem $item): void {
		if ($item->getState() === CaseItem::STATE_COMPLETED && $item->getPlanItemType() === CaseItem::TYPE_MILESTONE) {
			$this->effects->writer->mirrorStatus(milestone: $item);
		}
	}//end mirrorMilestone()

	/**
	 * Stage exit: non-terminal entered children are terminated, unentered
	 * children disabled, each individually audited with the stage as cause.
	 * Nested stages cascade in turn through the same method.
	 *
	 * @param CaseItem $stage The exited stage.
	 * @param CasePlanTree|null $tree The plan, or null to read the children now.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private function cascade(CaseItem $stage, ?CasePlanTree $tree): void {
		if ($tree === null) {
			$tree = new CasePlanTree(items: $this->store->items->findByObject(objectUuid: (string)$stage->getObjectUuid()));
		}

		$why = self::cascadeCause(stage: $stage);
		foreach ($tree->children(parentId: (int)$stage->getId()) as $child) {
			if ($child->isInTerminalState() === false) {
				$this->apply(item: $child, to: self::cascadeTarget(child: $child), why: $why, tree: $tree);
			}
		}
	}//end cascade()

	/**
	 * The cause every child of an exited stage is moved with: the cascade, the stage as reference.
	 *
	 * @param CaseItem $stage The exited stage.
	 *
	 * @return CaseTransitionCause The cause.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private static function cascadeCause(CaseItem $stage): CaseTransitionCause {
		return new CaseTransitionCause(
			cause: CaseItemAudit::CAUSE_CASCADE,
			causeRef: (string)$stage->getUuid(),
			actor: self::SYSTEM_ACTOR,
			reason: sprintf("Stage '%s' exited to '%s'.", (string)$stage->getItemKey(), (string)$stage->getState())
		);
	}//end cascadeCause()

	/**
	 * Where a stage's exit sends an open child: an entered one is terminated,
	 * an unentered one disabled, and a milestone (which has no `disabled`
	 * edge) is terminated either way.
	 *
	 * @param CaseItem $child The open child.
	 *
	 * @return string The target state.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private static function cascadeTarget(CaseItem $child): string {
		if ($child->isEntered() === true || $child->getPlanItemType() === CaseItem::TYPE_MILESTONE) {
			return CaseItem::STATE_TERMINATED;
		}

		return CaseItem::STATE_DISABLED;
	}//end cascadeTarget()
}//end class
