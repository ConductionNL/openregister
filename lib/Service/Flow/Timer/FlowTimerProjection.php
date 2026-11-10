<?php

/**
 * Keeps a task's deadline columns in step with its timers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTime;
use DateTimeInterface;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerMapperInterface;
use OCA\OpenRegister\Db\FlowTimer\TimerSubject;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Projects a subject task's open timers onto the task.
 */
final class FlowTimerProjection {

	/**
	 * Shared conversions.
	 *
	 * @var FlowTimerValues
	 */
	private readonly FlowTimerValues $values;

	/**
	 * Constructor.
	 *
	 * @param FlowTimerMapperInterface $timers The timer table.
	 * @param TaskMapper      $tasks  The task table.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly FlowTimerMapperInterface $timers,
		private readonly TaskMapper $tasks,
		private readonly LoggerInterface $logger,
	) {
		$this->values = new FlowTimerValues();
	}//end __construct()

	/**
	 * Maintain the task's `due_at`/`expires_at` projection (design D-10):
	 * the earliest OPEN due timer and the earliest OPEN expiry timer of the
	 * subject. Only task subjects have a projection.
	 *
	 * @param FlowTimer $timer A timer of the subject just mutated.
	 * @param DateTimeInterface|null $suspendedUntil Display-only expected resume, when suspending.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md
	 */
	public function project(FlowTimer $timer, ?DateTimeInterface $suspendedUntil = null): void {
		$task = $this->subjectTask(timer: $timer);
		if ($timer->getSubjectType() !== 'task') {
			return;
		}

		if ($task === null) {
			$this->logger->warning('[FlowTimerService] No task to project onto.', ['subject' => $timer->getSubjectUuid()]);

			return;
		}

		$earliest = $this->earliestOpenDeadlines(subjectUuid: (string)$timer->getSubjectUuid());
		$task->setDueAt($this->values->mutableOrNull(value: $earliest[FlowTimer::PURPOSE_DUE]));
		$task->setExpiresAt($this->values->mutableOrNull(value: $earliest[FlowTimer::PURPOSE_EXPIRY]));
		$task->setSuspendedUntil($this->values->mutableOrNull(value: $suspendedUntil));
		$this->tasks->update($task);
	}//end project()

	/**
	 * The subject task, when the subject is a task that exists.
	 *
	 * @param FlowTimer $timer The timer.
	 *
	 * @return Task|null The task.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md
	 */
	public function subjectTask(FlowTimer $timer): ?Task {
		if ($timer->getSubjectType() !== 'task') {
			return null;
		}

		try {
			return $this->tasks->findByUuid(uuid: (string)$timer->getSubjectUuid());
		} catch (DoesNotExistException) {
			return null;
		}
	}//end subjectTask()

	/**
	 * The earliest fire moment per purpose across a task's OPEN timers.
	 *
	 * @param string $subjectUuid The task uuid.
	 *
	 * @return array<string, DateTime|null> Keyed by purpose; null when no open timer of that purpose has a fire moment.
	 */
	private function earliestOpenDeadlines(string $subjectUuid): array {
		$earliest = [FlowTimer::PURPOSE_DUE => null, FlowTimer::PURPOSE_EXPIRY => null];
		$states = [FlowTimer::STATE_ARMED, FlowTimer::STATE_SUSPENDED];
		foreach ($this->timers->findBySubject(subject: new TimerSubject(type: 'task', uuid: $subjectUuid), states: $states) as $candidate) {
			$purpose = (string)$candidate->getPurpose();
			$fireAt = $candidate->getFireAt();
			if ($fireAt !== null && array_key_exists($purpose, $earliest) === true) {
				$earliest[$purpose] = $this->earlier(current: $earliest[$purpose], candidate: $fireAt);
			}
		}

		return $earliest;
	}//end earliestOpenDeadlines()

	/**
	 * The earlier of the earliest so far, if any, and a fire moment.
	 *
	 * @param DateTime|null $current   The earliest so far.
	 * @param DateTime      $candidate The next fire moment.
	 *
	 * @return DateTime The earlier one.
	 */
	private function earlier(?DateTime $current, DateTime $candidate): DateTime {
		if ($current === null || $candidate < $current) {
			return $candidate;
		}

		return $current;
	}//end earlier()
}//end class
