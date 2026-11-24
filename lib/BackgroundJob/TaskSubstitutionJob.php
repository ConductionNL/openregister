<?php

/**
 * Moves an absent person's open tasks to their stand-in when the absence
 * starts, and gives untouched ones back when it ends.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-untouched-tasks-return-when-the-absence-ends
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One bounded run of the substitution for one absence (design D-5).
 *
 * Arguments: `mode` (start or end), `uid` (the absent person), `absenceId`,
 * `standIn` (the replacement the absence named) and `after` (the id
 * watermark). At most {@see self::BATCH} tasks per run; when a full page was
 * read the job queues itself again from the last id.
 *
 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-task-goes-to-the-stand-in-of-an-absent-assignee
 */
class TaskSubstitutionJob extends QueuedJob {

	/**
	 * Tasks per run (hydra ADR-058).
	 *
	 * @var int
	 */
	public const BATCH = 200;

	/**
	 * An absence began.
	 *
	 * @var string
	 */
	public const MODE_START = 'start';

	/**
	 * An absence ended.
	 *
	 * @var string
	 */
	public const MODE_END = 'end';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory    $time   The clock the job list schedules by.
	 * @param TaskMapper      $tasks  Pages through a person's open tasks.
	 * @param TaskService     $tasker Substitutes and returns, audited.
	 * @param IJobList        $jobs   Requeues the job with its watermark.
	 * @param LoggerInterface $logger Where a task that failed is recorded.
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-task-goes-to-the-stand-in-of-an-absent-assignee
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly TaskMapper $tasks,
		private readonly TaskService $tasker,
		private readonly IJobList $jobs,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Run one batch.
	 *
	 * @param mixed $argument The job arguments.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-task-goes-to-the-stand-in-of-an-absent-assignee
	 */
	protected function run($argument): void {
		$this->process(argument: $argument);
	}//end run()

	/**
	 * Run one batch: substitute on start, return on end, requeue when more remain.
	 *
	 * @param mixed $argument The job arguments.
	 *
	 * @return int The number of tasks read.
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-untouched-tasks-return-when-the-absence-ends
	 */
	public function process(mixed $argument): int {
		$args = $this->arguments(argument: $argument);
		if ($args === null) {
			return 0;
		}

		// On start the absent person's tasks are read; on end, the stand-in's,
		// keeping those handled for the absent person.
		$holder = $args['uid'];
		if ($args['mode'] === self::MODE_END) {
			$holder = $args['standIn'];
		}

		if ($holder === '') {
			return 0;
		}

		$page = $this->tasks->findOpenAssignedTo(uid: $holder, afterId: $args['after'], limit: self::BATCH);
		foreach ($page as $task) {
			$this->one(task: $task, args: $args);
		}

		if (count($page) === self::BATCH) {
			$last = end($page);
			$this->jobs->add(self::class, array_merge($args, ['after' => (int)$last->getId()]));
		}

		return count($page);
	}//end process()

	/**
	 * Substitute or return one task; a failure is logged and the rest go on.
	 *
	 * @param Task                                                                         $task The task.
	 * @param array{mode: string, uid: string, absenceId: string, standIn: string, after: int} $args The job arguments.
	 *
	 * @return void
	 */
	private function one(Task $task, array $args): void {
		try {
			if ($args['mode'] === self::MODE_START) {
				$this->tasker->substituteForAbsence(uuid: (string)$task->getUuid());
				return;
			}

			if ($task->getOnBehalfOf() === $args['uid']) {
				$this->tasker->returnFromStandIn(uuid: (string)$task->getUuid(), absenceId: $args['absenceId']);
			}
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[TaskSubstitutionJob] A task could not be substituted or returned: ' . $failure->getMessage(),
				['task' => $task->getUuid(), 'mode' => $args['mode'], 'exception' => $failure]
			);
		}
	}//end one()

	/**
	 * The arguments, or null when they do not describe an absence.
	 *
	 * @param mixed $argument The raw arguments.
	 *
	 * @return array{mode: string, uid: string, absenceId: string, standIn: string, after: int}|null The arguments.
	 */
	private function arguments(mixed $argument): ?array {
		if (is_array($argument) === false) {
			return null;
		}

		$mode = (string)($argument['mode'] ?? '');
		$uid = trim((string)($argument['uid'] ?? ''));
		if (in_array($mode, [self::MODE_START, self::MODE_END], true) === false || $uid === '') {
			return null;
		}

		return [
			'mode' => $mode,
			'uid' => $uid,
			'absenceId' => (string)($argument['absenceId'] ?? ''),
			'standIn' => trim((string)($argument['standIn'] ?? '')),
			'after' => (int)($argument['after'] ?? 0),
		];
	}//end arguments()
}//end class
