<?php

/**
 * An approval sequence ended because a position completed without a decision.
 *
 * Dispatched when the enabled position completes with an outcome that neither
 * approves nor rejects. The sequence is closed as `undecided` and its
 * remaining tasks are terminated before this event fires. Nothing was
 * approved, so {@see TaskSequenceCompletedEvent} is not dispatched.
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
 * @spec openspec/changes/a-sequence-advances-only-on-an-approving-outcome/specs/flow-approval-consolidation/spec.md#requirement-a-completion-that-neither-approves-nor-rejects-ends-the-sequence-as-undecided
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Kernel\Contracts\TaskInterface;
use OCA\OpenRegister\Kernel\Contracts\TaskSequenceReadInterface;

/**
 * Carries the undecided sequence and the task whose outcome closed it.
 *
 * @spec openspec/changes/a-sequence-advances-only-on-an-approving-outcome/specs/flow-approval-consolidation/spec.md#requirement-a-completion-that-neither-approves-nor-rejects-ends-the-sequence-as-undecided
 */
class TaskSequenceUndecidedEvent extends AbstractTaskSequenceEvent {

	/**
	 * Constructor.
	 *
	 * @param TaskSequenceReadInterface $sequence The closed sequence, as persisted.
	 * @param TaskInterface $task The position that completed without a decision.
	 */
	public function __construct(
		TaskSequenceReadInterface $sequence,
		private readonly TaskInterface $task,
	) {
		parent::__construct(sequence: $sequence);

	}//end __construct()

	/**
	 * The position that completed without a decision.
	 *
	 * @return TaskInterface The task, carrying its outcome and decider.
	 *
	 * @spec openspec/changes/a-sequence-advances-only-on-an-approving-outcome/specs/flow-approval-consolidation/spec.md#requirement-a-completion-that-neither-approves-nor-rejects-ends-the-sequence-as-undecided
	 */
	public function getTask(): TaskInterface {
		return $this->task;
	}//end getTask()
}//end class
