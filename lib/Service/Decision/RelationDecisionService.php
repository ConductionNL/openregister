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
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\DecisionConflictException;
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
class RelationDecisionService implements RelationDecisionServiceInterface {

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
	 * @param RelationDecisionStores $stores    The relation store, the decision store and the transaction.
	 * @param DecisionOwnership      $ownership The ownership rule.
	 * @param ReviewPolicyResolver   $policies  The relation's policy.
	 */
	public function __construct(
		private readonly RelationDecisionStores $stores,
		private readonly DecisionOwnership $ownership,
		private readonly ReviewPolicyResolver $policies,
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
		$given      = $this->givenOf(body: $body);
		$linkedUuid = $relation->getDecisionUuid();
		$current    = $this->stores->decisions->find(uuid: $linkedUuid);
		$run        = new DecisionRun(relation: $relation, actor: $actor, linkedUuid: $linkedUuid, current: $current);
		$this->assertMayChange(run: $run);

		return $this->record(run: $run, given: $given);
	}//end decide()

	/**
	 * What a body gives, its note validated before anything is read.
	 *
	 * @param array<string, mixed> $body bases, skipAnonymization and decisionNote.
	 *
	 * @return GivenDecision The decision fields and the note.
	 *
	 * @throws CustomValidationException When the note is not a string.
	 */
	private function givenOf(array $body): GivenDecision {
		$hasNote = array_key_exists(self::NOTE_FIELD, $body);
		$note    = $this->noteFrom(body: $body);
		unset($body[self::NOTE_FIELD]);

		return new GivenDecision(fields: $body, hasNote: $hasNote, note: $note);
	}//end givenOf()

	/**
	 * Write the change and, when it is a new decision, record and link it, in one transaction.
	 *
	 * @param DecisionRun   $run   The decide pass, its relation replaced by each write.
	 * @param GivenDecision $given What the body gives.
	 *
	 * @return array{relation: EntityRelation, decision: Decision|null} The relation and its current decision.
	 *
	 * @throws DecisionOwnedException When another reviewer linked a first decision first.
	 */
	private function record(DecisionRun $run, GivenDecision $given): array {
		$before = [$run->relation->getBases(), $run->relation->getSkipAnonymization()];

		$this->stores->db->beginTransaction();
		try {
			$run->relation = $this->stores->relationMapper->updateDecisionMetadata(relation: $run->relation, fields: $given->fields, actingUser: $run->actor);
			// On a decided relation a change of verdict or grounds is always a new decision.
			$changed = ($run->current !== null && $before !== [$run->relation->getBases(), $run->relation->getSkipAnonymization()]);
			if ($changed === false && $this->isNewDecision(current: $run->current, given: $given) === false) {
				$this->stores->db->commit();
				return ['relation' => $run->relation, 'decision' => $run->current];
			}

			$decision = $this->stores->decisions->record(decision: $this->successor(run: $run, given: $given));
			// Compare-and-swap on the link read at the start: two reviewers taking a
			// first decision at once cannot both link theirs (the second finds
			// the link taken and is refused).
			$run->relation = $this->stores->relationMapper->linkDecision(
				relation: $run->relation,
				decisionUuid: (string)$decision->getUuid(),
				expectedUuid: $run->linkedUuid,
				guarded: true
			);
			$this->stores->db->commit();
		} catch (DecisionConflictException) {
			$this->stores->db->rollBack();
			throw new DecisionOwnedException(refusal: ['error' => 'decision-owned', 'decision' => null]);
		} catch (Throwable $error) {
			$this->stores->db->rollBack();
			throw $error;
		}//end try

		return ['relation' => $run->relation, 'decision' => $decision];
	}//end record()

	/**
	 * The decision that supersedes the current one, from the relation as written.
	 *
	 * @param DecisionRun   $run   The decide pass, its relation already written.
	 * @param GivenDecision $given What the body gives.
	 *
	 * @return Decision The successor, not yet stored.
	 */
	private function successor(DecisionRun $run, GivenDecision $given): Decision {
		$actorId = $run->actor->getUID();
		$note    = $given->note;
		if ($given->hasNote === false && $run->current !== null && $run->current->getDecidedBy() === $actorId) {
			// The same reviewer changing their verdict keeps their note.
			$note = $run->current->getNote();
		}

		$successor = new Decision();
		$successor->setSubjectType(ReviewPolicyResolver::SUBJECT_TYPE);
		$successor->setSubjectId((string)$run->relation->getId());
		$successor->setVerdict($this->verdictOf(relation: $run->relation));
		$successor->setDecidedBy($actorId);
		$successor->setNote($note);
		$successor->setSupersedes($run->current?->getUuid());

		return $successor;
	}//end successor()

	/**
	 * Refuse a write on a decision the actor does not own.
	 *
	 * A relation that names a decision whose record cannot be found belongs to
	 * nobody but a supervisor: DecisionVisibility hides that state, and a
	 * reviewer must not take it over through the gap.
	 *
	 * @param DecisionRun $run The decide pass, before any write.
	 *
	 * @return void
	 *
	 * @throws DecisionOwnedException When the decision belongs to someone else.
	 */
	private function assertMayChange(DecisionRun $run): void {
		$owned = $run->current;
		if ($owned === null && $run->linkedUuid !== null && $run->linkedUuid !== '') {
			$owned = new Decision();
			$owned->setUuid($run->linkedUuid);
			$owned->setDecidedBy('');
		}

		if ($owned === null) {
			return;
		}

		$resolved = $this->policies->forRelation(relation: $run->relation);
		$question = new DecisionQuestion(actorId: $run->actor->getUID(), policy: $resolved['policy'], subject: $resolved['subject']);
		$refusal  = $this->ownership->refusal(decision: $owned, question: $question);
		if ($refusal !== null) {
			throw new DecisionOwnedException(refusal: $refusal);
		}
	}//end assertMayChange()

	/**
	 * Whether a write that left the verdict and grounds as they were is still a decision worth recording.
	 *
	 * On an undecided relation every write is one, including a reviewer
	 * confirming the default: that is a decision too. On a decided relation
	 * only a change of verdict, grounds or note is (the caller checks the
	 * verdict and grounds); repeating the same decision records nothing and
	 * does not take it over.
	 *
	 * @param Decision|null $current The current decision.
	 * @param GivenDecision $given   What the body gives.
	 *
	 * @return bool True when a new decision is recorded.
	 */
	private function isNewDecision(?Decision $current, GivenDecision $given): bool {
		if ($current === null) {
			return $given->given() !== [];
		}

		return $given->hasNote === true && $given->note !== $current->getNote();
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
