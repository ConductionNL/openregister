<?php

/**
 * An approval sequence completed with an approving outcome.
 *
 * Dispatched at exactly the moment the retired `ApprovalStepCompletedEvent`
 * was: the FINAL position completing with an approving outcome. Carries the
 * sequence, the final task, the deciding identity and the resolved approving
 * status, which is everything the retired event's consumers read off its
 * chain-and-step pair.
 *
 * Replacement mapping for the four retired events (normative in the spec,
 * published for consumers in docs/development/approval-events-migration.md):
 * a position becoming enabled replaces Initiated; a task completing with an
 * approving outcome replaces Approved; one completing with a rejecting
 * outcome replaces Rejected; THIS event replaces Completed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-the-four-approval-events-are-replaced-by-a-named-complete-mapping
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskSequence;
use OCP\EventDispatcher\Event;

/**
 * Carries the completed sequence, its final task and the resolved status.
 *
 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-the-four-approval-events-are-replaced-by-a-named-complete-mapping
 */
class TaskSequenceCompletedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param TaskSequence $sequence The completed sequence, as persisted.
	 * @param Task $finalTask The final position's task.
	 * @param string|null $decider The identity that decided the final position.
	 * @param string $statusOnApprove The resolved approving status.
	 */
	public function __construct(
		private readonly TaskSequence $sequence,
		private readonly Task $finalTask,
		private readonly ?string $decider,
		private readonly string $statusOnApprove,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The completed sequence.
	 *
	 * @return TaskSequence The sequence as persisted.
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-the-four-approval-events-are-replaced-by-a-named-complete-mapping
	 */
	public function getSequence(): TaskSequence {
		return $this->sequence;
	}//end getSequence()

	/**
	 * The final position's task.
	 *
	 * @return Task The task whose completion completed the sequence.
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-the-four-approval-events-are-replaced-by-a-named-complete-mapping
	 */
	public function getFinalTask(): Task {
		return $this->finalTask;
	}//end getFinalTask()

	/**
	 * The deciding identity.
	 *
	 * @return string|null Who decided the final position.
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-the-four-approval-events-are-replaced-by-a-named-complete-mapping
	 */
	public function getDecider(): ?string {
		return $this->decider;
	}//end getDecider()

	/**
	 * The resolved approving status.
	 *
	 * @return string The `statusOnApprove` the frozen declaration resolves to.
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-the-four-approval-events-are-replaced-by-a-named-complete-mapping
	 */
	public function getStatusOnApprove(): string {
		return $this->statusOnApprove;
	}//end getStatusOnApprove()
}//end class
