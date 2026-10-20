<?php

/**
 * OpenRegister DecisionVisibilityRequestedEvent
 *
 * Asked before another reviewer's decision is hidden from a caller under
 * `hideOthers`, so the app that owns the subject can show it to someone it
 * supervises the subject for: a case type's supervisor group, a team's
 * coordinator.
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
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-show-a-hidden-decision-req-dec-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\Decision;
use OCA\OpenRegister\Service\Decision\ReviewPolicy;
use OCP\EventDispatcher\Event;

/**
 * A question with one answer: may this user see this hidden decision?
 *
 * Dispatched only when the decision would otherwise be hidden: the policy
 * hides others, someone else took the decision, and the caller is signed in
 * but neither an administrator nor in a supervisor group the policy names.
 * A listener may allow; nothing else can make a decision visible. A
 * listener that throws leaves the decision hidden.
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-show-a-hidden-decision-req-dec-003
 */
class DecisionVisibilityRequestedEvent extends Event {

	/**
	 * Whether a listener let the caller see the decision.
	 *
	 * @var boolean
	 */
	private bool $allowed = false;

	/**
	 * Constructor.
	 *
	 * @param Decision|null        $decision The hidden decision, or null when its record is gone.
	 * @param string               $actorId  The user who reads it.
	 * @param ReviewPolicy         $policy   The policy that applies.
	 * @param array<string, mixed> $subject  What the decision is on: subjectType,
	 *                                       subjectId and, when known, register,
	 *                                       schema, objectUuid and fileId.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-show-a-hidden-decision-req-dec-003
	 */
	public function __construct(
		private readonly ?Decision $decision,
		private readonly string $actorId,
		private readonly ReviewPolicy $policy,
		private readonly array $subject,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The hidden decision.
	 *
	 * @return Decision|null The decision, or null when its record is gone.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-show-a-hidden-decision-req-dec-003
	 */
	public function getDecision(): ?Decision {
		return $this->decision;
	}//end getDecision()

	/**
	 * The user who reads it.
	 *
	 * @return string The user id.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-show-a-hidden-decision-req-dec-003
	 */
	public function getActorId(): string {
		return $this->actorId;
	}//end getActorId()

	/**
	 * The policy that applies.
	 *
	 * @return ReviewPolicy The policy.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-show-a-hidden-decision-req-dec-003
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
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-show-a-hidden-decision-req-dec-003
	 */
	public function getSubject(): array {
		return $this->subject;
	}//end getSubject()

	/**
	 * Let the caller see the decision.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-show-a-hidden-decision-req-dec-003
	 */
	public function allow(): void {
		$this->allowed = true;
	}//end allow()

	/**
	 * The answer after every listener has had its say.
	 *
	 * @return bool True when the caller may see the decision.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-show-a-hidden-decision-req-dec-003
	 */
	public function isAllowed(): bool {
		return $this->allowed;
	}//end isAllowed()
}//end class
