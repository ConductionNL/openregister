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

use OCA\OpenRegister\Db\EntityRelation;
use OCP\IGroupManager;

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
	 */
	public const MASKED_FIELDS = ['bases', 'skipAnonymization', 'decisionRecord'];

	/**
	 * Constructor.
	 *
	 * @param ReviewPolicyResolver $resolver     The policy of the relation's subject.
	 * @param DecisionService      $decisions    The current decision.
	 * @param IGroupManager        $groupManager Administrators and supervisors.
	 */
	public function __construct(
		private readonly ReviewPolicyResolver $resolver,
		private readonly DecisionService $decisions,
		private readonly IGroupManager $groupManager,
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
	 * someone else took it, and the caller neither supervises nor administers.
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

		$policy = $this->resolver->forRelation(relation: $relation)['policy'];
		if ($policy->hidesOthers() === false) {
			return false;
		}

		$decision = $this->decisions->find(uuid: $decisionUuid);
		if ($decision !== null && $userId !== '' && $decision->getDecidedBy() === $userId) {
			return false;
		}

		return $this->supervises(userId: $userId, policy: $policy) === false;
	}//end isHidden()

	/**
	 * Whether the caller is an administrator or in one of the policy's supervisor groups.
	 *
	 * @param string       $userId The caller.
	 * @param ReviewPolicy $policy The policy.
	 *
	 * @return bool
	 */
	private function supervises(string $userId, ReviewPolicy $policy): bool {
		if ($userId === '') {
			return false;
		}

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
