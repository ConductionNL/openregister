<?php

/**
 * What the decision request events share: who asks, under which policy, about what.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Kernel\Contracts\ReviewPolicyInterface;
use OCP\EventDispatcher\Event;

/**
 * A question about a decision that a listening app may answer.
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-show-a-hidden-decision-req-dec-003
 */
abstract class AbstractDecisionRequestEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param string                $actorId The user asking.
	 * @param ReviewPolicyInterface $policy  The review policy that applies.
	 * @param array<string, mixed>  $subject What the decision is about.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-show-a-hidden-decision-req-dec-003
	 */
	public function __construct(
		private readonly string $actorId,
		private readonly ReviewPolicyInterface $policy,
		private readonly array $subject,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The user asking.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-show-a-hidden-decision-req-dec-003
	 */
	public function getActorId(): string {
		return $this->actorId;
	}//end getActorId()

	/**
	 * The review policy that applies.
	 *
	 * @return ReviewPolicyInterface
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-show-a-hidden-decision-req-dec-003
	 */
	public function getPolicy(): ReviewPolicyInterface {
		return $this->policy;
	}//end getPolicy()

	/**
	 * What the decision is about.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-show-a-hidden-decision-req-dec-003
	 */
	public function getSubject(): array {
		return $this->subject;
	}//end getSubject()
}//end class
