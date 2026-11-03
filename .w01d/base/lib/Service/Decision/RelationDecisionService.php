<?php

/**
 * OpenRegister RelationDecisionService
 *
 * A reviewer's redaction decision on one entity relation, taken as a
 * decision object the relation links (decision 197), under the ownership
 * rule of the relation's schema.
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

use OCA\OpenRegister\Db\Decision;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\DecisionConflictException;
use OCP\IDBConnection;
use OCP\IUser;
use Throwable;

/**
 * Applies a decision to a relation and records it as a decision object.
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The decision flow meets the relation, its mapper, the decision
 * store, the policies and the ownership check; the conflict exception is the compare-and-swap's one extra collaborator.
 */
class RelationDecisionService {

	/**
	 * The occurrence stays readable.
	 */
	public const VERDICT_RELEASE = 'release';

	/**
	 * The occurrence is redacted.
	 */
	public const VERDICT_REDACT = 'redact';

	/**
	 * The body key that carries the decider's note.
	 */
	public const NOTE_FIELD = 'decisionNote';

	/**
	 * Constructor.
	 *
	 * @param EntityRelationMapper $relationMapper The relation store and its audited decision write.
	 * @param DecisionService      $decisions      Records decision objects.
	 * @param DecisionOwnership    $ownership      The ownership rule.
	 * @param ReviewPolicyResolver $policies       The relation's policy.
	 * @param IDBConnection        $db             One transaction for the write and its decision.
	 */
	public function __construct(
		private readonly EntityRelationMapper $relationMapper,
		private readonly DecisionService $decisions,
		private readonly DecisionOwnership $ownership,
		private readonly ReviewPolicyResolver $policies,
		private readonly IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * Apply a reviewer's decision to a relation.
	 *
	 * The subject write check runs before this (the caller's job). Here: the
	 * relation's current decision is read; under `decider` ownership someone
	 * else is refused; the change is written through the audited mapper; and
	 * when anything changed a new decision object is recorded, superseding
	 * the old one, and linked from the relation, all in one transaction.
	 *
	 * @param EntityRelation       $relation The relation.
	 * @param array<string, mixed> $body     bases, skipAnonymization and decisionNote.
	 * @param IUser                $actor    The reviewer.
	 *
	 * @return array{relation: EntityRelation, decision: Decision|null} The relation and its current decision.
	 *
	 * @throws DecisionOwnedException When the decision belongs to someone else, its record is missing, or another reviewer decided first.
	 * @throws CustomValidationException When the body is malformed.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
	 */
	public function decide(EntityRelation $relation, array $body, IUser $actor): array {
		$hasNote = array_key_exists(self::NOTE_FIELD, $body);
		$note = $this->noteFrom(body: $body);
		unset($body[self::NOTE_FIELD]);

		$actorId = $actor->getUID();
		$linkedUuid = $relation->getDecisionUuid();
		$current = $this->decisions->find(uuid: $linkedUuid);
		$this->assertMayChange(relation: $relation, current: $current, linkedUuid: $linkedUuid, actorId: $actorId);

		$before = [$relation->getBases(), $relation->getSkipAnonymization()];

		$given = [];
		if ($hasNote === true) {
			$given = [$note];
		}

		$this->db->beginTransaction();
		try {
			$updated = $this->relationMapper->updateDecisionMetadata(relation: $relation, fields: $body, actingUser: $actor);

			if ($this->isNewDecision(current: $current, before: $before, updated: $updated, body: $body, note: $given) === false) {
				$this->db->commit();
				return ['relation' => $updated, 'decision' => $current];
			}

			if ($hasNote === false && $current !== null && $current->getDecidedBy() === $actorId) {
				// The same reviewer changing their verdict keeps their note.
				$note = $current->getNote();
			}

			$decision = $this->decisions->record(
				subjectType: ReviewPolicyResolver::SUBJECT_TYPE,
				subjectId: (string)$updated->getId(),
				verdict: $this->verdictOf(relation: $updated),
				decidedBy: $actorId,
				note: $note,
				supersedes: $current?->getUuid()
			);
			// Compare-and-swap on the link read above: two reviewers taking a
			// first decision at once cannot both link theirs (the second finds
			// the link taken and is refused).
			$updated = $this->relationMapper->linkDecision(
				relation: $updated,
				decisionUuid: (string)$decision->getUuid(),
				expectedUuid: $linkedUuid,
				guarded: true
			);
			$this->db->commit();
		} catch (DecisionConflictException) {
			$this->db->rollBack();
			throw new DecisionOwnedException(refusal: ['error' => 'decision-owned', 'decision' => null]);
		} catch (Throwable $error) {
			$this->db->rollBack();
			throw $error;
		}//end try

		return ['relation' => $updated, 'decision' => $decision];
	}//end decide()

	/**
	 * Refuse a write on a decision the actor does not own.
	 *
	 * A relation that names a decision whose record cannot be found belongs to
	 * nobody but a supervisor: DecisionVisibility hides that state, and a
	 * reviewer must not take it over through the gap.
	 *
	 * @param EntityRelation $relation   The relation.
	 * @param Decision|null  $current    The decision record the relation links, when it exists.
	 * @param string|null    $linkedUuid The decision uuid the relation names.
	 * @param string         $actorId    The reviewer.
	 *
	 * @return void
	 *
	 * @throws DecisionOwnedException When the decision belongs to someone else.
	 */
	private function assertMayChange(EntityRelation $relation, ?Decision $current, ?string $linkedUuid, string $actorId): void {
		$owned = $current;
		if ($owned === null && $linkedUuid !== null && $linkedUuid !== '') {
			$owned = new Decision();
			$owned->setUuid($linkedUuid);
			$owned->setDecidedBy('');
		}

		if ($owned === null) {
			return;
		}

		$resolved = $this->policies->forRelation(relation: $relation);
		$refusal = $this->ownership->refusal(decision: $owned, actorId: $actorId, policy: $resolved['policy'], subject: $resolved['subject']);
		if ($refusal !== null) {
			throw new DecisionOwnedException(refusal: $refusal);
		}
	}//end assertMayChange()

	/**
	 * Whether this write is a decision worth recording.
	 *
	 * On an undecided relation every write is one, including a reviewer
	 * confirming the default: that is a decision too. On a decided relation
	 * only a change of verdict, grounds or note is; repeating the same
	 * decision records nothing and does not take it over.
	 *
	 * @param Decision|null        $current The current decision.
	 * @param array{0: mixed, 1: mixed} $before  Bases and skipAnonymization before the write.
	 * @param EntityRelation       $updated The relation after the write.
	 * @param array<string, mixed> $body    The decision fields written.
	 * @param array<int, string|null> $note    The note given, as a one-item list, or empty when none was given.
	 *
	 * @return bool True when a new decision is recorded.
	 */
	private function isNewDecision(?Decision $current, array $before, EntityRelation $updated, array $body, array $note): bool {
		if ($current === null) {
			return $body !== [] || $note !== [];
		}

		if ($before !== [$updated->getBases(), $updated->getSkipAnonymization()]) {
			return true;
		}

		return $note !== [] && $note[0] !== $current->getNote();
	}//end isNewDecision()

	/**
	 * The verdict a relation's state expresses.
	 *
	 * @param EntityRelation $relation The relation.
	 *
	 * @return string `release` when it is skipped from the anonymise pass, else `redact`.
	 */
	private function verdictOf(EntityRelation $relation): string {
		if ($relation->getSkipAnonymization() === true) {
			return self::VERDICT_RELEASE;
		}

		return self::VERDICT_REDACT;
	}//end verdictOf()

	/**
	 * The note from a body: a string, or null to clear it.
	 *
	 * @param array<string, mixed> $body The body.
	 *
	 * @return string|null The note, trimmed; null when absent, empty or null.
	 *
	 * @throws CustomValidationException When the note is not a string.
	 */
	private function noteFrom(array $body): ?string {
		$note = ($body[self::NOTE_FIELD] ?? null);
		if ($note !== null && is_string($note) === false) {
			throw new CustomValidationException(
				message: 'Invalid decisionNote shape: must be a string or null',
				errors: ['field' => self::NOTE_FIELD, 'reason' => 'must_be_string_or_null']
			);
		}

		if ($note === null || trim($note) === '') {
			return null;
		}

		return trim($note);
	}//end noteFrom()
}//end class
