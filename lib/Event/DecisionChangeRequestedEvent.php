<?php

/**
 * OpenRegister DecisionChangeRequestedEvent
 *
 * Asked before someone other than the decider changes a decider-owned
 * decision, so the app that owns the subject can answer who may: a case's
 * reviewer access, a team's coordinator, a procedure's supervisor group.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-answer-who-supervises-req-dec-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\Decision;
use OCA\OpenRegister\Service\Decision\ReviewPolicy;
use OCP\EventDispatcher\Event;

/**
 * A vetoable question: may this user change this decision?
 *
 * OpenRegister answers first from the declared policy (a member of a
 * supervisor group may). A listener may then allow or refuse. It is never
 * dispatched for the decider themselves or for an administrator, who always
 * may, and never under `open` ownership.
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-answer-who-supervises-req-dec-002
 */
class DecisionChangeRequestedEvent extends Event {

	/**
	 * Why a listener refused, when it did.
	 *
	 * @var string|null
	 */
	private ?string $reason = null;

	/**
	 * Whether a listener has refused: a refusal is sticky.
	 *
	 * @var bool
	 */
	private bool $refused = false;

	/**
	 * Constructor.
	 *
	 * @param Decision             $decision The decision someone wants to change.
	 * @param string               $actorId  The user who wants to change it.
	 * @param ReviewPolicy         $policy   The policy that applies.
	 * @param array<string, mixed> $subject  What the decision is on: subjectType,
	 *                                       subjectId and, when known, register,
	 *                                       schema, objectUuid and fileId.
	 * @param bool                 $allowed  OpenRegister's own answer from the policy.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-answer-who-supervises-req-dec-002
	 */
	public function __construct(
		private readonly Decision $decision,
		private readonly string $actorId,
		private readonly ReviewPolicy $policy,
		private readonly array $subject,
		private bool $allowed,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The decision someone wants to change.
	 *
	 * @return Decision The decision.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-answer-who-supervises-req-dec-002
	 */
	public function getDecision(): Decision {
		return $this->decision;
	}//end getDecision()

	/**
	 * The user who wants to change it.
	 *
	 * @return string The user id.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-answer-who-supervises-req-dec-002
	 */
	public function getActorId(): string {
		return $this->actorId;
	}//end getActorId()

	/**
	 * The policy that applies.
	 *
	 * @return ReviewPolicy The policy.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-answer-who-supervises-req-dec-002
	 */
	public function getPolicy(): ReviewPolicy {
		return $this->policy;
	}//end getPolicy()

	/**
	 * What the decision is on.
	 *
	 * @return array<string, mixed> subjectType, subjectId and, when known,
	 *                              register, schema, objectUuid and fileId.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-answer-who-supervises-req-dec-002
	 */
	public function getSubject(): array {
		return $this->subject;
	}//end getSubject()

	/**
	 * Let the change through, unless a listener has already refused it.
	 *
	 * A refusal is a veto and stays: with two apps listening, the order they
	 * are registered in must not turn one app's refusal into the other's allow.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-answer-who-supervises-req-dec-002
	 */
	public function allow(): void {
		if ($this->refused === true) {
			return;
		}

		$this->allowed = true;
		$this->reason = null;
	}//end allow()

	/**
	 * Refuse the change.
	 *
	 * @param string $reason Why, for the log; the caller is told only who decided.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-answer-who-supervises-req-dec-002
	 */
	public function refuse(string $reason): void {
		$this->refused = true;
		$this->allowed = false;
		$this->reason = $reason;
	}//end refuse()

	/**
	 * The answer after every listener has had its say.
	 *
	 * @return bool True when the change may go through.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-answer-who-supervises-req-dec-002
	 */
	public function isAllowed(): bool {
		return $this->allowed;
	}//end isAllowed()

	/**
	 * Why a listener refused.
	 *
	 * @return string|null The reason, or null.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-answer-who-supervises-req-dec-002
	 */
	public function getReason(): ?string {
		return $this->reason;
	}//end getReason()
}//end class
