<?php

/**
 * The pre-decision check a sequence position adds on top of task authorization.
 *
 * Consulted by {@see TaskService} BEFORE the performer check when the task
 * being completed carries a `sequence_uuid`, so a refused self-decision gets
 * an honest reason (separation of duties) rather than being masked by, or
 * coincidentally passing, the assignee check — the same ordering the retired
 * engine documented at `ApprovalService.php:165-168`.
 *
 * The policy is read from the sequence's FROZEN template snapshot, never
 * from the live schema: `separationOfDuties` defaults to ON when the
 * declarative entry exists, because an unstated policy on an approval is the
 * safe one (design D-8). The check runs against the acting identity AND the
 * task's `on_behalf_of` identity: a self-decision routed through a delegate
 * is the same self-decision.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/approval-workflow/spec.md#req-009
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskSequence;
use OCA\OpenRegister\Db\TaskSequenceMapperInterface;
use OCA\OpenRegister\Exception\TaskSeparationOfDutiesException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Refuses a self-decision on a sequence position, delegated or direct.
 *
 * @spec openspec/specs/approval-workflow/spec.md#req-009
 */
class TaskSequenceDecisionGuard {

	/**
	 * Constructor.
	 *
	 * @param TaskSequenceMapperInterface $sequences Resolves the task's sequence.
	 */
	public function __construct(
		private readonly TaskSequenceMapperInterface $sequences,
	) {

	}//end __construct()

	/**
	 * Refuse a decision the sequence's policy forbids.
	 *
	 * A task outside any sequence, a sequence that cannot be resolved, and a
	 * sequence with no recorded requester all pass: there is nothing to
	 * enforce. The refusal fires only on the one fact the policy names — the
	 * deciding identity, direct or acted-for, is the recorded requester.
	 *
	 * @param Task $task The task being decided.
	 * @param string|null $actor The deciding identity.
	 *
	 * @return void
	 *
	 * @throws TaskSeparationOfDutiesException On a refused self-decision.
	 *
	 * @spec openspec/specs/approval-workflow/spec.md#req-009
	 */
	public function assertDecidable(Task $task, ?string $actor): void {
		$requester = $this->bindingRequester(task: $task);
		if ($requester === '') {
			return;
		}

		$deciding = trim((string)$actor);

		if ($deciding === $requester) {
			throw new TaskSeparationOfDutiesException(
				message: sprintf(
					"Separation of duties: '%s' requested this approval and may not decide it. This is not an authorization failure.",
					$requester
				)
			);
		}

		$this->refuseDelegatedSelfDecision(task: $task, requester: $requester);
	}//end assertDecidable()

	/**
	 * Refuse a decision made on behalf of the requester: a delegated
	 * self-decision is refused on the same grounds as a direct one.
	 *
	 * @param Task   $task      The task about to be decided.
	 * @param string $requester The bound requester, not empty.
	 *
	 * @return void
	 *
	 * @throws TaskSeparationOfDutiesException When the decision acts for the requester.
	 */
	private function refuseDelegatedSelfDecision(Task $task, string $requester): void {
		$actedFor = trim((string)$task->getOnBehalfOf());
		if ($actedFor !== '' && $actedFor === $requester) {
			throw new TaskSeparationOfDutiesException(
				message: sprintf(
					"Separation of duties: this decision acts on behalf of '%s', who requested the approval."
					. ' A delegated self-decision is refused on the same grounds.',
					$requester
				)
			);
		}
	}//end refuseDelegatedSelfDecision()

	/**
	 * The requester a separation-of-duties policy binds this task to.
	 *
	 * Empty when there is nothing to enforce: the task is outside any
	 * sequence, the sequence reference dangles (it has no policy to read,
	 * and the performer check still applies in full), the policy is off, or
	 * the sequence names no requester.
	 *
	 * @param Task $task The task about to be decided.
	 *
	 * @return string The requester id, trimmed, or '' when none is bound.
	 */
	private function bindingRequester(Task $task): string {
		$sequenceUuid = trim((string)$task->getSequenceUuid());
		if ($sequenceUuid === '') {
			return '';
		}

		try {
			$sequence = $this->sequences->findByUuid(uuid: $sequenceUuid);
		} catch (DoesNotExistException) {
			return '';
		}

		if ($this->separationOfDutiesApplies(sequence: $sequence) === false) {
			return '';
		}

		return trim((string)$sequence->getRequesterId());
	}//end bindingRequester()

	/**
	 * Whether the frozen declaration requires separation of duties.
	 *
	 * ON unless the frozen snapshot explicitly says `false`. The snapshot is
	 * read, never the live schema, so an opt-out edited onto the schema
	 * mid-cycle does not loosen an approval that is already running.
	 *
	 * @param TaskSequence $sequence The sequence being decided.
	 *
	 * @return bool True when the policy applies.
	 *
	 * @spec openspec/specs/approval-workflow/spec.md#req-009
	 */
	private function separationOfDutiesApplies(TaskSequence $sequence): bool {
		$snapshot = ($sequence->getTemplateSnapshot() ?? []);

		return (($snapshot['separationOfDuties'] ?? true) !== false);
	}//end separationOfDutiesApplies()
}//end class
