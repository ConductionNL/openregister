<?php

/**
 * OpenRegister DecisionOwnership
 *
 * Who may change a decision: under `decider` ownership, the person who took
 * it, a member of a supervisor group, an administrator, or whoever the app
 * that owns the subject allows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Decision
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Decision;

use DateTimeInterface;
use OCA\OpenRegister\Db\Decision;
use OCA\OpenRegister\Event\DecisionChangeRequestedEvent;
use OCP\IGroupManager;
use Throwable;

/**
 * The ownership rule on one decision.
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
 */
class DecisionOwnership {

	/**
	 * Constructor.
	 *
	 * @param IGroupManager              $groupManager Administrator and supervisor membership.
	 * @param DecisionVisibilityPlatform $platform     The event dispatcher that asks the app that owns
	 *                                                 the subject, and the logger for refusals by a listener.
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly DecisionVisibilityPlatform $platform,
	) {
	}//end __construct()

	/**
	 * Why a user may not change a decision, or null when they may.
	 *
	 * The refusal names who decided and when, never the verdict or the note.
	 * Under `hideOthers` it names neither who decided nor when, only that the
	 * decision belongs to another reviewer (REQ-ROD-002).
	 *
	 * @param Decision         $decision The current decision.
	 * @param DecisionQuestion $question Who wants to change it, under which policy, on which subject.
	 *
	 * @return array{error: string, decision: string|null, decidedBy?: string|null, decidedAt?: string|null}|null
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
	 */
	public function refusal(Decision $decision, DecisionQuestion $question): ?array {
		if ($this->mayChange(decision: $decision, question: $question) === true) {
			return null;
		}

		$refusal = [
			'error' => 'decision-owned',
			'decision' => $decision->getUuid(),
		];
		if ($question->policy->hidesOthers() === true) {
			return $refusal;
		}

		$refusal['decidedBy'] = $decision->getDecidedBy();
		$refusal['decidedAt'] = $decision->getDecidedAt()?->format(DateTimeInterface::ATOM);

		return $refusal;
	}//end refusal()

	/**
	 * Whether the change may go through: open ownership, the decider, an
	 * administrator, then a supervisor group or the app that owns the subject.
	 *
	 * @param Decision         $decision The current decision.
	 * @param DecisionQuestion $question The question.
	 *
	 * @return bool True when the change may go through.
	 */
	private function mayChange(Decision $decision, DecisionQuestion $question): bool {
		if ($question->policy->isDeciderOwned() === false
			|| $decision->getDecidedBy() === $question->actorId
			|| $this->groupManager->isAdmin($question->actorId) === true
		) {
			return true;
		}

		return $this->mayByEvent(decision: $decision, question: $question);
	}//end mayChange()

	/**
	 * Whether a supervisor group, then the app that owns the subject, lets the user through.
	 *
	 * A listener that throws refuses: the question fails closed.
	 *
	 * @param Decision         $decision The current decision.
	 * @param DecisionQuestion $question The question.
	 *
	 * @return bool True when the change may go through.
	 */
	private function mayByEvent(Decision $decision, DecisionQuestion $question): bool {
		$actorId = $question->actorId;
		$event   = new DecisionChangeRequestedEvent(
			decision: $decision,
			actorId: $actorId,
			policy: $question->policy,
			subject: $question->subject,
			allowed: $this->isSupervisor(actorId: $actorId, policy: $question->policy)
		);

		try {
			$this->platform->eventDispatcher->dispatchTyped($event);
		} catch (Throwable $error) {
			$this->platform->logger->warning(
				message: '[DecisionOwnership] A listener failed; the change is refused.',
				context: ['decision' => $decision->getUuid(), 'actor' => $actorId, 'error' => $error->getMessage()]
			);
			return false;
		}

		if ($event->isAllowed() === false && $event->getReason() !== null) {
			$this->platform->logger->info(
				message: '[DecisionOwnership] Refused by the app that owns the subject.',
				context: ['decision' => $decision->getUuid(), 'actor' => $actorId, 'reason' => $event->getReason()]
			);
		}

		return $event->isAllowed();
	}//end mayByEvent()

	/**
	 * Whether the user is in one of the policy's supervisor groups.
	 *
	 * @param string       $actorId The user.
	 * @param ReviewPolicy $policy  The policy.
	 *
	 * @return bool True for a supervisor.
	 */
	private function isSupervisor(string $actorId, ReviewPolicy $policy): bool {
		foreach ($policy->getSupervisors() as $group) {
			if ($this->groupManager->isInGroup($actorId, $group) === true) {
				return true;
			}
		}

		return false;
	}//end isSupervisor()
}//end class
