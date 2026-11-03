<?php

/**
 * OpenRegister DecisionVisibility
 *
 * Masks another reviewer's decision on an entity relation when the subject's
 * schema declares `x-openregister-review.hideOthers`, so a second reviewer
 * reviews blind: the relation is still listed with its type and position,
 * but carries `decision: "hidden"` and neither its ground nor its verdict.
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
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-schema-can-hide-one-reviewers-decisions-from-another-req-rod-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Decision;

use OCA\OpenRegister\Db\Decision;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Event\DecisionVisibilityRequestedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The serialised relation a caller may see.
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-schema-can-hide-one-reviewers-decisions-from-another-req-rod-002
 */
class DecisionVisibility {

	/**
	 * What a masked relation carries in place of its decision.
	 */
	public const HIDDEN = 'hidden';

	/**
	 * The serialised fields that carry the decision's ground or verdict.
	 *
	 * `bases` is the ground; `skipAnonymization` is the release-or-redact
	 * verdict itself, so leaving it would tell the second reviewer what the
	 * first one decided. `decisionRecord` is the embedded decision.
	 * `anonymized` and `anonymizedValue` are what an anonymise pass wrote from
	 * that verdict: a released occurrence stays `anonymized: false` while a
	 * redacted one reads `true` with its placeholder, so left in they would
	 * give the verdict away in a blind second review that follows a pass.
	 */
	public const MASKED_FIELDS = ['bases', 'skipAnonymization', 'decisionRecord', 'anonymized', 'anonymizedValue'];

	/**
	 * Constructor.
	 *
	 * @param ReviewPolicyResolver $resolver        The policy of the relation's subject.
	 * @param DecisionService      $decisions       The current decision.
	 * @param IGroupManager        $groupManager    Administrators and supervisors.
	 * @param IEventDispatcher     $eventDispatcher Asks the app that owns the subject (REQ-DEC-003).
	 * @param LoggerInterface      $logger          Records a listener that failed.
	 */
	public function __construct(
		private readonly ReviewPolicyResolver $resolver,
		private readonly DecisionService $decisions,
		private readonly IGroupManager $groupManager,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The relation as the caller may see it.
	 *
	 * @param EntityRelation       $relation   The relation.
	 * @param string               $userId     The caller ('' for nobody signed in).
	 * @param array<string, mixed> $serialised The serialisation to mask; the relation's own when omitted.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-schema-can-hide-one-reviewers-decisions-from-another-req-rod-002
	 */
	public function present(EntityRelation $relation, string $userId, ?array $serialised=null): array {
		$data = ($serialised ?? $relation->jsonSerialize());
		if ($this->isHidden(relation: $relation, userId: $userId) === false) {
			return $data;
		}

		$data['decision'] = self::HIDDEN;
		foreach (self::MASKED_FIELDS as $field) {
			if (array_key_exists($field, $data) === true) {
				$data[$field] = null;
			}
		}

		return $data;
	}//end present()

	/**
	 * Every relation as the caller may see it.
	 *
	 * @param EntityRelation[] $relations The relations.
	 * @param string           $userId    The caller.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-schema-can-hide-one-reviewers-decisions-from-another-req-rod-002
	 */
	public function presentAll(array $relations, string $userId): array {
		$presented = [];
		foreach ($relations as $relation) {
			$presented[] = $this->present(relation: $relation, userId: $userId);
		}

		return $presented;
	}//end presentAll()

	/**
	 * Whether the caller may not see this relation's decision.
	 *
	 * Hidden when the relation carries a decision, its policy hides others,
	 * someone else took it, the caller neither supervises nor administers,
	 * and the app that owns the subject does not show it to them (REQ-DEC-003).
	 * A decision whose record is gone is hidden too: its taker is unknown.
	 *
	 * @param EntityRelation $relation The relation.
	 * @param string         $userId   The caller.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-schema-can-hide-one-reviewers-decisions-from-another-req-rod-002
	 */
	public function isHidden(EntityRelation $relation, string $userId): bool {
		$decisionUuid = (string)($relation->getDecisionUuid() ?? '');
		if ($decisionUuid === '') {
			return false;
		}

		$resolved = $this->resolver->forRelation(relation: $relation);
		$policy = $resolved['policy'];
		if ($policy->hidesOthers() === false) {
			return false;
		}

		if ($userId === '') {
			return true;
		}

		$decision = $this->decisions->find(uuid: $decisionUuid);
		if ($decision !== null && $decision->getDecidedBy() === $userId) {
			return false;
		}

		if ($this->supervises(userId: $userId, policy: $policy) === true) {
			return false;
		}

		return $this->shownByEvent(decision: $decision, userId: $userId, policy: $policy, subject: $resolved['subject']) === false;
	}//end isHidden()

	/**
	 * Whether the app that owns the subject shows the decision to the caller.
	 *
	 * A listener that throws keeps the decision hidden: the question fails closed.
	 *
	 * @param Decision|null        $decision The hidden decision.
	 * @param string               $userId   The caller.
	 * @param ReviewPolicy         $policy   The policy.
	 * @param array<string, mixed> $subject  What the decision is on.
	 *
	 * @return bool True when a listener allowed it.
	 */
	private function shownByEvent(?Decision $decision, string $userId, ReviewPolicy $policy, array $subject): bool {
		$event = new DecisionVisibilityRequestedEvent(decision: $decision, actorId: $userId, policy: $policy, subject: $subject);

		try {
			$this->eventDispatcher->dispatchTyped($event);
		} catch (Throwable $error) {
			$this->logger->warning(
				message: '[DecisionVisibility] A listener failed; the decision stays hidden.',
				context: ['decision' => $decision?->getUuid(), 'actor' => $userId, 'error' => $error->getMessage()]
			);
			return false;
		}

		return $event->isAllowed();
	}//end shownByEvent()

	/**
	 * Whether the caller is an administrator or in one of the policy's supervisor groups.
	 *
	 * @param string       $userId The caller.
	 * @param ReviewPolicy $policy The policy.
	 *
	 * @return bool
	 */
	private function supervises(string $userId, ReviewPolicy $policy): bool {
		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		foreach ($policy->getSupervisors() as $group) {
			if ($this->groupManager->isInGroup($userId, $group) === true) {
				return true;
			}
		}

		return false;
	}//end supervises()
}//end class
