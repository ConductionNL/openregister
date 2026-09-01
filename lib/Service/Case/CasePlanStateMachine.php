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
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use DateTime;
use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\CaseItemAudit;
use OCA\OpenRegister\Db\CaseItemAuditMapper;
use OCA\OpenRegister\Db\CaseItemMapper;
use OCA\OpenRegister\Event\CaseItemTransitionedEvent;
use OCA\OpenRegister\Exception\CaseTransitionException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The one write path for plan-item state.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The machine owns the
 * transaction across two mappers, the table, the realiser, the writer and
 * the dispatcher; that is its job description.
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
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
	 * @param CaseItemMapper $items The plan-item table.
	 * @param CaseItemAuditMapper $audits The append-only audit.
	 * @param CasePlanTransitions $table The lifecycle table.
	 * @param CaseRealisationService $realiser Creates and closes realisations.
	 * @param CaseBusinessStateWriter $writer Mirrors a milestone onto the object.
	 * @param IDBConnection $db Holds the one transaction per transition.
	 * @param LoggerInterface $logger Failure reporting.
	 * @param IEventDispatcher|null $dispatcher Announces committed transitions.
	 */
	public function __construct(
		private readonly CaseItemMapper $items,
		private readonly CaseItemAuditMapper $audits,
		private readonly CasePlanTransitions $table,
		private readonly CaseRealisationService $realiser,
		private readonly CaseBusinessStateWriter $writer,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly ?IEventDispatcher $dispatcher = null,
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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
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

		$outermost = $this->db->inTransaction() === false;
		$this->db->beginTransaction();
		try {
			$persisted = $this->apply(item: $item, to: $to, cause: $cause, causeRef: $causeRef, actor: $actor, reason: $reason, tree: $tree);
			$this->db->commit();
		} catch (Throwable $failure) {
			$this->db->rollBack();
			if ($outermost === true) {
				$this->pending = [];
			}

			throw $failure;
		}

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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	public function recordDenial(CaseItem $item, string $to, ?string $actor, string $reason): void {
		if ($item->getId() === null) {
			return;
		}

		try {
			$this->appendAudit(
				item: $item,
				fromState: (string)$item->getState(),
				toState: $to,
				cause: CaseItemAudit::CAUSE_USER,
				causeRef: null,
				actor: $actor,
				reason: $reason,
				authorized: false
			);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[CasePlanStateMachine] Could not record an authorization denial: ' . $failure->getMessage(),
				['item' => $item->getUuid()]
			);
		}
	}//end recordDenial()

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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function recordCreation(CaseItem $item, string $cause, ?string $causeRef, ?string $actor): void {
		$this->appendAudit(
			item: $item,
			fromState: '',
			toState: (string)$item->getState(),
			cause: $cause,
			causeRef: $causeRef,
			actor: ($actor ?? self::SYSTEM_ACTOR),
			reason: null,
			authorized: true
		);
	}//end recordCreation()

	/**
	 * Dispatch every committed transition's event. Called by the outermost
	 * committer; a nested caller's events wait here for it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	public function flushEvents(): void {
		$events = $this->pending;
		$this->pending = [];
		if ($this->dispatcher === null) {
			return;
		}

		foreach ($events as $event) {
			try {
				$this->dispatcher->dispatchTyped($event);
			} catch (Throwable $failure) {
				$this->logger->warning(
					'[CasePlanStateMachine] A plan-item event listener failed; the transition itself is unaffected: ' . $failure->getMessage(),
					['item' => $event->getItem()->getUuid(), 'exception' => $failure]
				);
			}
		}
	}//end flushEvents()

	/**
	 * Forget queued events after a rolled-back outer transaction.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	public function discardEvents(): void {
		$this->pending = [];
	}//end discardEvents()

	/**
	 * The body of a transition, inside the transaction.
	 *
	 * @param CaseItem $item The item, as read.
	 * @param string $to The target state.
	 * @param string $cause The cause.
	 * @param string|null $causeRef The cause reference.
	 * @param string|null $actor The acting identity.
	 * @param string|null $reason Free text.
	 * @param CasePlanTree|null $tree The plan, when known.
	 *
	 * @return CaseItem The persisted item.
	 *
	 * @throws CaseTransitionException When another mover won the row.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
	 */
	private function apply(
		CaseItem $item,
		string $to,
		string $cause,
		?string $causeRef,
		?string $actor,
		?string $reason,
		?CasePlanTree $tree,
	): CaseItem {
		$from = (string)$item->getState();
		$identity = ($actor ?? self::SYSTEM_ACTOR);
		$this->stamp(item: $item, to: $to, reason: $reason, actor: $identity);

		// THE one place `state` and `is_terminal` change, in the same statement.
		$item->setState($to);
		$item->setIsTerminal($this->table->isTerminal(state: $to));

		if ($this->items->updateIfState(item: $item, expectedState: $from) === false) {
			throw new CaseTransitionException(
				message: sprintf("Plan item '%s' was moved concurrently out of '%s'; this transition was not applied.", (string)$item->getUuid(), $from)
			);
		}

		$this->appendAudit(
			item: $item,
			fromState: $from,
			toState: $to,
			cause: $cause,
			causeRef: $causeRef,
			actor: $identity,
			reason: $reason,
			authorized: true
		);

		$this->propagate(item: $item, cause: $cause, reason: $reason);
		$this->pending[] = new CaseItemTransitionedEvent(item: $item, fromState: $from);

		if ($item->getPlanItemType() === CaseItem::TYPE_STAGE && $item->isInTerminalState() === true) {
			$this->cascade(stage: $item, tree: $tree);
		}

		return $item;
	}//end apply()

	/**
	 * The side stamps of entering a state: realise and stamp `entered_at` on
	 * activation, stamp `entered_at` on a milestone's completion, record the
	 * reason on a termination.
	 *
	 * @param CaseItem $item The item.
	 * @param string $to The target state.
	 * @param string|null $reason Free text.
	 * @param string $actor The acting identity the realisation is created by.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	private function stamp(CaseItem $item, string $to, ?string $reason, string $actor): void {
		if ($to === CaseItem::STATE_ACTIVE) {
			$this->realiser->realise(item: $item, actor: $actor);
			$item->setEnteredAt(new DateTime());
		}

		if ($to === CaseItem::STATE_COMPLETED && $item->getEnteredAt() === null) {
			$item->setEnteredAt(new DateTime());
		}

		if ($to === CaseItem::STATE_TERMINATED) {
			$item->setTerminatedReason($reason);
		}
	}//end stamp()

	/**
	 * What a committed transition tells the world outside the row: an exited
	 * work item closes its realisation (UNLESS the realisation is what ended
	 * it: it is already closed, and terminating a completed task would be the
	 * drift the one-directional rule forbids), and a reached milestone is
	 * mirrored onto the object.
	 *
	 * @param CaseItem $item The item, already in its new state.
	 * @param string $cause The cause.
	 * @param string|null $reason Free text.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	private function propagate(CaseItem $item, string $cause, ?string $reason): void {
		$hasRealisation = trim((string)$item->getRealisationUuid()) !== '';
		if ($item->isInTerminalState() === true && $cause !== CaseItemAudit::CAUSE_REALISATION && $hasRealisation === true) {
			$this->realiser->terminate(
				item: $item,
				reason: ($reason ?? sprintf("Plan item '%s' reached '%s'.", (string)$item->getItemKey(), (string)$item->getState()))
			);
		}

		if ($item->getState() === CaseItem::STATE_COMPLETED && $item->getPlanItemType() === CaseItem::TYPE_MILESTONE) {
			$this->writer->mirrorStatus(milestone: $item);
		}
	}//end propagate()

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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private function cascade(CaseItem $stage, ?CasePlanTree $tree): void {
		if ($tree === null) {
			$tree = new CasePlanTree(items: $this->items->findByObject(objectUuid: (string)$stage->getObjectUuid()));
		}

		$reason = sprintf("Stage '%s' exited to '%s'.", (string)$stage->getItemKey(), (string)$stage->getState());
		foreach ($tree->children(parentId: (int)$stage->getId()) as $child) {
			if ($child->isInTerminalState() === true) {
				continue;
			}

			$target = CaseItem::STATE_DISABLED;
			if ($child->isEntered() === true) {
				$target = CaseItem::STATE_TERMINATED;
			}

			if ($child->getPlanItemType() === CaseItem::TYPE_MILESTONE) {
				// A milestone has no `disabled` edge: an unreached one is terminated.
				$target = CaseItem::STATE_TERMINATED;
			}

			$this->apply(
				item: $child,
				to: $target,
				cause: CaseItemAudit::CAUSE_CASCADE,
				causeRef: (string)$stage->getUuid(),
				actor: self::SYSTEM_ACTOR,
				reason: $reason,
				tree: $tree
			);
		}
	}//end cascade()

	/**
	 * Append one audit row.
	 *
	 * @param CaseItem $item The item.
	 * @param string $fromState The state before.
	 * @param string $toState The state after (or requested).
	 * @param string $cause The cause.
	 * @param string|null $causeRef The cause reference.
	 * @param string|null $actor The acting identity.
	 * @param string|null $reason Free text.
	 * @param bool $authorized False on a denial.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	private function appendAudit(
		CaseItem $item,
		string $fromState,
		string $toState,
		string $cause,
		?string $causeRef,
		?string $actor,
		?string $reason,
		bool $authorized,
	): void {
		$entry = new CaseItemAudit();
		$entry->setCaseItemId((int)$item->getId());
		$entry->setFromState($fromState);
		$entry->setToState($toState);
		$entry->setCause($cause);
		$entry->setCauseRef($causeRef);
		$entry->setActor($actor);
		$entry->setReason($reason);
		$entry->setAuthorized($authorized);
		$this->audits->insert($entry);
	}//end appendAudit()
}//end class
