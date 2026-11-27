<?php

/**
 * An approval sequence ended because a position rejected.
 *
 * Dispatched once the sequence is closed as `rejected` and its remaining tasks
 * are terminated. Nothing was approved, so {@see TaskSequenceCompletedEvent} is
 * not dispatched; what waited on the approval (a held change) is discarded by
 * its listener.
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
 * @spec openspec/changes/records-change-held-for-approval/tasks.md#3.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskSequence;

/**
 * Carries the rejected sequence and the position that rejected it.
 *
 * @spec openspec/changes/records-change-held-for-approval/tasks.md#3.2
 */
class TaskSequenceRejectedEvent extends AbstractTaskSequenceEvent {

	/**
	 * Constructor.
	 *
	 * @param TaskSequence $sequence The closed sequence, as persisted.
	 * @param Task $task The position that rejected.
	 */
	public function __construct(
		private readonly TaskSequence $sequence,
		private readonly Task $task,
	) {
		parent::__construct(sequence: $sequence);

	}//end __construct()

	/**
	 * The sequence the event is about, as the TaskSequence row it was dispatched with.
	 *
	 * @return TaskSequence The sequence as persisted.
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-the-four-approval-events-are-replaced-by-a-named-complete-mapping
	 */
	public function getSequence(): TaskSequence {
		return $this->sequence;
	}//end getSequence()

	/**
	 * The position that completed without a decision.
	 *
	 * @return Task The task, carrying its outcome and decider.
	 *
	 * @spec openspec/changes/records-change-held-for-approval/tasks.md#3.2
	 */
	public function getTask(): Task {
		return $this->task;
	}//end getTask()
}//end class
