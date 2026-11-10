<?php

/**
 * One sweep pass: three bounded range scans, each row acted on.
 *
 * `findDueExpiries(now, batch)` is `state = armed AND purpose = expiry AND
 * fire_at <= now ORDER BY fire_at LIMIT batch`; `findDueRungs(now, batch)`
 * is `state = armed AND next_rung_at <= now ORDER BY next_rung_at LIMIT
 * batch`; `findDueTimeouts(now, batch)` is `is_terminal = false AND
 * on_timeout IS NOT NULL AND expires_at <= now ORDER BY expires_at LIMIT
 * batch` (task-expiry-and-outcomes D-3). None reads a page of open rows and filters it in PHP: past the
 * page size that shape never reaches a due row while reporting a clean pass
 * (design D-8). Each timer is processed on its own; a failure on one is
 * logged and counted and does not stop the pass. The counts returned are
 * work PERFORMED, and `truncated` says a scan hit the batch limit.
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
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTimeInterface;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\Task;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The bounded, re-entrant sweep.
 */
class FlowTimerSweep implements FlowTimerSweepInterface {

	/**
	 * The actor a swept task timeout records itself as.
	 *
	 * @var string
	 */
	public const TIMEOUT_SOURCE = 'task-expiry-sweep';






	/**
	 * Constructor.
	 *
	 * @param FlowTimerSweepTimers     $timers The timer range scans, the timer service and the calendars.
	 * @param LoggerInterface          $logger Per-timer failure reporting.
	 * @param FlowTimerSweepTasks|null $tasks  The task-timeout scan and task service. Nullable so
	 *                                         a sweep without them skips the task pass; the
	 *                                         container wires both.
	 */
	public function __construct(
		private readonly FlowTimerSweepTimers $timers,
		private readonly LoggerInterface $logger,
		private readonly ?FlowTimerSweepTasks $tasks = null,
	) {
	}//end __construct()

	/**
	 * Run one pass.
	 *
	 * @param DateTimeInterface $now The sweep instant.
	 * @param int $batch The per-scan batch limit.
	 *
	 * @return array{expiriesFired: int, rungsFired: int, taskTimeouts: int, truncated: bool, errors: int} Work performed.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
	 */
	public function run(DateTimeInterface $now, int $batch): array {
		$tally = new FlowTimerSweepTally(now: $now, batch: max(1, $batch));
		$this->timers->calendars->reset();

		$this->fireExpiries(tally: $tally);
		$this->fireRungs(tally: $tally);
		$this->sweepTaskTimeouts(tally: $tally);

		return $tally->result();
	}//end run()

	/**
	 * Fire every due expiry timer in one batch.
	 *
	 * @param FlowTimerSweepTally $tally The pass.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
	 */
	private function fireExpiries(FlowTimerSweepTally $tally): void {
		$expiries = $this->timers->timers->findDueExpiries(now: $tally->now, limit: $tally->batch);
		$tally->scanned(found: count($expiries));
		foreach ($expiries as $timer) {
			$this->fireExpiry(timer: $timer, tally: $tally);
		}

	}//end fireExpiries()

	/**
	 * Fire one expiry; count it when it fired, and a failure when it threw.
	 *
	 * @param FlowTimer           $timer The due timer.
	 * @param FlowTimerSweepTally $tally The pass.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
	 */
	private function fireExpiry(FlowTimer $timer, FlowTimerSweepTally $tally): void {
		try {
			if ($this->timers->service->fireExpiry(timer: $timer, now: $tally->now) === true) {
				$tally->add(counter: 'expiriesFired', amount: 1);
			}
		} catch (Throwable $failure) {
			$tally->add(counter: 'errors', amount: 1);
			$this->logger->error(
				'[FlowTimerSweep] Expiry fire failed: ' . $failure->getMessage(),
				['timer' => $timer->getUuid(), 'exception' => $failure]
			);
		}

	}//end fireExpiry()

	/**
	 * Fire every timer with a due escalation rung, in one batch.
	 *
	 * @param FlowTimerSweepTally $tally The pass.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
	 */
	private function fireRungs(FlowTimerSweepTally $tally): void {
		$rungs = $this->timers->timers->findDueRungs(now: $tally->now, limit: $tally->batch);
		$tally->scanned(found: count($rungs));
		foreach ($rungs as $timer) {
			$this->fireRungsOf(timer: $timer, tally: $tally);
		}

	}//end fireRungs()

	/**
	 * Fire one timer's due rungs; count each, and a failure when it threw.
	 *
	 * @param FlowTimer           $timer The timer.
	 * @param FlowTimerSweepTally $tally The pass.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
	 */
	private function fireRungsOf(FlowTimer $timer, FlowTimerSweepTally $tally): void {
		try {
			$tally->add(counter: 'rungsFired', amount: $this->timers->service->fireRungs(timer: $timer, now: $tally->now));
		} catch (Throwable $failure) {
			$tally->add(counter: 'errors', amount: 1);
			$this->logger->error(
				'[FlowTimerSweep] Rung fire failed: ' . $failure->getMessage(),
				['timer' => $timer->getUuid(), 'exception' => $failure]
			);
		}

	}//end fireRungsOf()

	/**
	 * Apply the declared timeout behaviour of every due task, in one batch.
	 *
	 * Skipped when the task scan or service is not wired, so a pre-existing
	 * positional instantiation keeps sweeping timers only.
	 *
	 * @param FlowTimerSweepTally $tally The pass.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
	 */
	private function sweepTaskTimeouts(FlowTimerSweepTally $tally): void {
		$tasks = $this->tasks?->tasks;
		if ($tasks === null || $this->tasks?->taskService === null) {
			return;
		}

		$due = $tasks->findDueTimeouts(now: $tally->now, limit: $tally->batch);
		$tally->scanned(found: count($due));
		foreach ($due as $task) {
			$this->applyTimeout(task: $task, tally: $tally);
		}

	}//end sweepTaskTimeouts()

	/**
	 * Apply one task's declared timeout; count it, or a failure when it threw.
	 *
	 * @param Task                $task  The due task.
	 * @param FlowTimerSweepTally $tally The pass.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
	 */
	private function applyTimeout(Task $task, FlowTimerSweepTally $tally): void {
		try {
			$this->tasks?->taskService?->applyTimerOutcome(
				uuid: (string)$task->getUuid(),
				outcome: (string)$task->getOnTimeout(),
				source: self::TIMEOUT_SOURCE,
				reason: $this->timeoutReason(task: $task)
			);
			$tally->add(counter: 'taskTimeouts', amount: 1);
		} catch (Throwable $failure) {
			$tally->add(counter: 'errors', amount: 1);
			$this->logger->error('[FlowTimerSweep] Task timeout failed: ' . $failure->getMessage(), ['task' => $task->getUuid(), 'exception' => $failure]);
		}

	}//end applyTimeout()

	/**
	 * The reason a swept timeout records against the task.
	 *
	 * @param Task $task The due task.
	 *
	 * @return string The reason.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
	 */
	private function timeoutReason(Task $task): string {
		return sprintf(
			"Task expired at %s; declared onTimeout '%s' applied.",
			(string)$task->getExpiresAt()?->format('c'),
			(string)$task->getOnTimeout()
		);

	}//end timeoutReason()
}//end class
