<?php

/**
 * An absence that starts or ends queues the substitution job; nothing else
 * happens inside the event.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-task-goes-to-the-stand-in-of-an-absent-assignee
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\BackgroundJob\TaskSubstitutionJob;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\OutOfOfficeEndedEvent;
use OCP\User\Events\OutOfOfficeStartedEvent;
use OCP\User\IOutOfOfficeData;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queues {@see TaskSubstitutionJob} for an absence (hydra ADR-069, ADR-078).
 *
 * @template-implements IEventListener<OutOfOfficeStartedEvent|OutOfOfficeEndedEvent>
 *
 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-task-goes-to-the-stand-in-of-an-absent-assignee
 */
class TaskAbsenceListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param IJobList        $jobs   Where the job is queued.
	 * @param LoggerInterface $logger Where a failed queueing is recorded.
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-task-goes-to-the-stand-in-of-an-absent-assignee
	 */
	public function __construct(
		private readonly IJobList $jobs,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Queue the job for the absence that started or ended.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-untouched-tasks-return-when-the-absence-ends
	 */
	public function handle(Event $event): void {
		$mode = null;
		$data = null;
		if ($event instanceof OutOfOfficeStartedEvent) {
			$mode = TaskSubstitutionJob::MODE_START;
			$data = $event->getData();
		} else if ($event instanceof OutOfOfficeEndedEvent) {
			$mode = TaskSubstitutionJob::MODE_END;
			$data = $event->getData();
		}

		if ($mode === null || $data === null) {
			return;
		}

		try {
			$this->jobs->add(TaskSubstitutionJob::class, $this->arguments(mode: $mode, data: $data));
		} catch (Throwable $failure) {
			// The absence itself is saved either way; only the rerouting is lost.
			$this->logger->warning(
				'[TaskAbsenceListener] The task substitution could not be queued: ' . $failure->getMessage(),
				['exception' => $failure]
			);
		}
	}//end handle()

	/**
	 * The job arguments for one absence; never its message.
	 *
	 * @param string           $mode The job mode.
	 * @param IOutOfOfficeData $data The absence.
	 *
	 * @return array{mode: string, uid: string, absenceId: string, standIn: string, after: int} The arguments.
	 */
	private function arguments(string $mode, IOutOfOfficeData $data): array {
		return [
			'mode' => $mode,
			'uid' => $data->getUser()->getUID(),
			'absenceId' => $data->getId(),
			'standIn' => (string)$data->getReplacementUserId(),
			'after' => 0,
		];
	}//end arguments()
}//end class
