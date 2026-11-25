<?php

/**
 * The deadline warning: a timer's pre-breach rung becomes `due-soon` on its task.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-task-nearing-its-deadline-warns-its-assignee-once
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Kernel\Contracts\Value\TaskAuditEntry;
use OCA\OpenRegister\Service\Flow\Timer\EscalationLadderService;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Records the `due-soon` action on an open task when the warning moment of
 * its deadline passes, once per deadline value.
 *
 * The warning moment is the one the flow already declares: a `preBreach`
 * rung on the task's due timer, `offset` before the deadline on the timer's
 * own working calendar. The timer sweep places and fires that rung exactly
 * once (flow-business-timers), so this class only translates the fired rung
 * into the task action the `taskDueSoon` notification rule addresses; it
 * computes no moment of its own, and so cannot disagree with the ladder.
 *
 * 🔑 ONCE PER DEADLINE VALUE, NOT ONCE PER RUNG. A ladder may declare two
 * preBreach rungs (fourteen and seven days); the person hears "due soon" once
 * for a deadline. The deadline value is written into the audit reason and a
 * later fire for the SAME value records nothing. A moved deadline is a new
 * value: the timer is superseded, its successor's rungs that now lie in the
 * future fire again, and the task is warned again for the new deadline.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-task-nearing-its-deadline-warns-its-assignee-once
 */
final class TaskDueSoonWarning {

	/**
	 * The task action the `taskDueSoon` rule is triggered by.
	 *
	 * @var string
	 */
	public const ACTION = 'due-soon';

	/**
	 * Constructor.
	 *
	 * @param TaskService $tasks The task, its trail and the one recording path.
	 */
	public function __construct(
		private readonly TaskService $tasks,
	) {
	}//end __construct()

	/**
	 * Record `due-soon` for a fired pre-breach rung of a task's due timer.
	 *
	 * @param FlowTimerFiredEvent $event The fired timer.
	 *
	 * @return bool True when `due-soon` was recorded; false when the fire is
	 *              not a deadline warning, the task is gone, terminal or has no
	 *              deadline, or this deadline value was already warned for.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-task-nearing-its-deadline-warns-its-assignee-once
	 */
	public function onRungFired(FlowTimerFiredEvent $event): bool {
		$deadline = $this->warnedDeadline(event: $event);
		if ($deadline === null) {
			return false;
		}

		$timer = $event->getTimer();
		$uuid = (string)$timer->getSubjectUuid();

		try {
			$task = $this->tasks->get(uuid: $uuid);
		} catch (DoesNotExistException) {
			return false;
		}

		if ($task->isInTerminalState() === true || $task->getDueAt() === null) {
			return false;
		}

		$marker = self::deadlineMarker(deadline: $deadline);
		if ($this->alreadyWarned(uuid: $uuid, marker: $marker) === true) {
			return false;
		}

		$entry = new TaskAuditEntry(
			uuid: $uuid,
			action: self::ACTION,
			actor: 'flow-timer:' . (string)$timer->getUuid(),
			reason: sprintf('The deadline %s is near (escalation rung %s).', $marker, (string)$event->getRungKey())
		);

		try {
			$this->tasks->recordAction(entry: $entry);
		} catch (TaskConflictException) {
			// Closed between the read and the write: a finished task is not warned.
			return false;
		}

		return true;
	}//end onRungFired()

	/**
	 * The deadline a fire warns about, or null when the fire is not a
	 * pre-breach rung of a task's due timer with a fire moment.
	 *
	 * @param FlowTimerFiredEvent $event The fired timer.
	 *
	 * @return DateTimeInterface|null The deadline.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-task-nearing-its-deadline-warns-its-assignee-once
	 */
	private function warnedDeadline(FlowTimerFiredEvent $event): ?DateTimeInterface {
		if ($event->getKind() !== FlowTimerFiredEvent::KIND_RUNG
			|| $event->getRungTrigger() !== EscalationLadderService::TRIGGER_PRE_BREACH
		) {
			return null;
		}

		$timer = $event->getTimer();
		if ($timer->getSubjectType() !== 'task'
			|| $timer->getPurpose() !== FlowTimer::PURPOSE_DUE
			|| (string)$timer->getSubjectUuid() === ''
		) {
			return null;
		}

		return $timer->getFireAt();
	}//end warnedDeadline()

	/**
	 * Whether the task's trail already holds a `due-soon` for this deadline value.
	 *
	 * @param string $uuid   The task uuid.
	 * @param string $marker The deadline, as written into the reason.
	 *
	 * @return bool True when it was warned for this deadline.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-task-nearing-its-deadline-warns-its-assignee-once
	 */
	private function alreadyWarned(string $uuid, string $marker): bool {
		foreach ($this->tasks->auditTrail(uuid: $uuid) as $entry) {
			if ($entry->getAction() === self::ACTION && str_contains((string)$entry->getReason(), $marker) === true) {
				return true;
			}
		}

		return false;
	}//end alreadyWarned()

	/**
	 * The deadline as one stable string, in UTC to the second, so the same
	 * moment read back in another zone compares equal.
	 *
	 * @param DateTimeInterface $deadline The deadline.
	 *
	 * @return string The marker, e.g. `2027-03-12T16:00:00+00:00`.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-task-nearing-its-deadline-warns-its-assignee-once
	 */
	public static function deadlineMarker(DateTimeInterface $deadline): string {
		return DateTimeImmutable::createFromInterface($deadline)->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
	}//end deadlineMarker()
}//end class
