<?php

/**
 * ObjectTaskReader: the published read of the tasks on one object.
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
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Contract\ObjectTaskReaderInterface;
use OCA\OpenRegister\Contract\ObjectTaskRecord;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;

/**
 * Reads an object's tasks from the task store and hands them out as records.
 *
 * @spec openspec/changes/object-task-reader/specs/flow-tasks/spec.md#requirement-a-consuming-app-reads-the-tasks-on-its-object
 */
class ObjectTaskReader implements ObjectTaskReaderInterface {

	/**
	 * Constructor.
	 *
	 * @param TaskMapper $tasks The task store.
	 */
	public function __construct(
		private readonly TaskMapper $tasks
	) {
	}//end __construct()

	/**
	 * The tasks on an object, open and finished, oldest first.
	 *
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return array<int,ObjectTaskRecord> The tasks.
	 *
	 * @spec openspec/changes/object-task-reader/specs/flow-tasks/spec.md#requirement-a-consuming-app-reads-the-tasks-on-its-object
	 */
	public function tasksOnObject(string $objectUuid): array {
		if (trim($objectUuid) === '') {
			return [];
		}

		return array_map(
			fn (Task $task): ObjectTaskRecord => self::record(task: $task),
			$this->tasks->findByObjectUuid(objectUuid: $objectUuid)
		);
	}//end tasksOnObject()

	/**
	 * One task as a record.
	 *
	 * @param Task $task The task.
	 *
	 * @return ObjectTaskRecord
	 */
	private static function record(Task $task): ObjectTaskRecord {
		return new ObjectTaskRecord(
			uuid: (string) $task->getUuid(),
			title: (string) $task->getTitle(),
			workflowStepId: $task->getWorkflowStepId(),
			state: (string) $task->getState(),
			isTerminal: $task->getIsTerminal() === true,
			outcome: $task->getOutcome(),
			assignee: $task->getAssignee(),
			decidedBy: $task->getCompletedBy(),
			onBehalfOf: $task->getOnBehalfOf(),
			mandate: $task->getMandate(),
			decidedAt: self::moment(value: $task->getCompletedAt()),
			createdAt: self::moment(value: $task->getCreated())
		);
	}//end record()

	/**
	 * An immutable copy of a stored moment.
	 *
	 * @param DateTimeInterface|null $value The stored moment.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function moment(?DateTimeInterface $value): ?DateTimeImmutable {
		if ($value === null) {
			return null;
		}

		return DateTimeImmutable::createFromInterface($value);
	}//end moment()
}//end class
