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
use OCA\OpenRegister\Db\FlowTimerMapper;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Task\TaskService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The bounded, re-entrant sweep.
 */
class FlowTimerSweep {

	/**
	 * The actor a swept task timeout records itself as.
	 *
	 * @var string
	 */
	public const TIMEOUT_SOURCE = 'task-expiry-sweep';

	/**
	 * Constructor.
	 *
	 * @param FlowTimerMapper $timers The two timer range scans.
	 * @param FlowTimerService $service Fires expiries and rungs.
	 * @param WorkingCalendarService $calendars Reset once per pass so memoisation is per pass.
	 * @param LoggerInterface $logger Per-timer failure reporting.
	 * @param TaskMapper|null $tasks The task-timeout range scan
	 *                               (task-expiry-and-outcomes D-3). Nullable so
	 *                               pre-existing positional instantiations keep
	 *                               working; the container wires both.
	 * @param TaskService|null $taskService Applies a due task's declared behaviour.
	 */
	public function __construct(
		private readonly FlowTimerMapper $timers,
		private readonly FlowTimerService $service,
		private readonly WorkingCalendarService $calendars,
		private readonly LoggerInterface $logger,
		private readonly ?TaskMapper $tasks = null,
		private readonly ?TaskService $taskService = null,
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
		$batch = max(1, $batch);
		$this->calendars->reset();
		$result = ['expiriesFired' => 0, 'rungsFired' => 0, 'taskTimeouts' => 0, 'truncated' => false, 'errors' => 0];

		$expiries = $this->timers->findDueExpiries(now: $now, limit: $batch);
		$result['truncated'] = (count($expiries) >= $batch);
		foreach ($expiries as $timer) {
			try {
				if ($this->service->fireExpiry(timer: $timer, now: $now) === true) {
					$result['expiriesFired']++;
				}
			} catch (Throwable $failure) {
				$result['errors']++;
				$this->logger->error(
					'[FlowTimerSweep] Expiry fire failed: ' . $failure->getMessage(),
					['timer' => $timer->getUuid(), 'exception' => $failure]
				);
			}
		}

		$rungs = $this->timers->findDueRungs(now: $now, limit: $batch);
		$result['truncated'] = ($result['truncated'] === true || count($rungs) >= $batch);
		foreach ($rungs as $timer) {
			try {
				$result['rungsFired'] += $this->service->fireRungs(timer: $timer, now: $now);
			} catch (Throwable $failure) {
				$result['errors']++;
				$this->logger->error(
					'[FlowTimerSweep] Rung fire failed: ' . $failure->getMessage(),
					['timer' => $timer->getUuid(), 'exception' => $failure]
				);
			}
		}

		$this->sweepTaskTimeouts(now: $now, batch: $batch, result: $result);

		return $result;
	}//end run()

	/**
	 * The third scan (task-expiry-and-outcomes D-3): non-terminal tasks past
	 * their enforcing deadline that DECLARE a timeout behaviour, each closed
	 * through the existing timer-outcome path. A failure on one task is
	 * logged and counted and does not stop the pass; the row is retried next
	 * pass because only a successful apply makes it terminal.
	 *
	 * @param DateTimeInterface $now The sweep instant.
	 * @param int $batch The per-scan batch limit.
	 * @param array{expiriesFired: int, rungsFired: int, taskTimeouts: int, truncated: bool, errors: int} $result Mutated in place.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-expiry-and-outcomes/specs/task-expiry-and-outcomes/spec.md#requirement-the-timer-sweep-enforces-a-declared-task-expiry
	 */
	private function sweepTaskTimeouts(DateTimeInterface $now, int $batch, array &$result): void {
		if ($this->tasks === null || $this->taskService === null) {
			return;
		}

		$due = $this->tasks->findDueTimeouts(now: $now, limit: $batch);
		$result['truncated'] = ($result['truncated'] === true || count($due) >= $batch);
		foreach ($due as $task) {
			try {
				$this->taskService->applyTimerOutcome(
					uuid: (string)$task->getUuid(),
					outcome: (string)$task->getOnTimeout(),
					source: self::TIMEOUT_SOURCE,
					reason: sprintf(
						"Task expired at %s; declared onTimeout '%s' applied.",
						(string)$task->getExpiresAt()?->format('c'),
						(string)$task->getOnTimeout()
					)
				);
				$result['taskTimeouts']++;
			} catch (Throwable $failure) {
				$result['errors']++;
				$this->logger->error(
					'[FlowTimerSweep] Task timeout failed: ' . $failure->getMessage(),
					['task' => $task->getUuid(), 'exception' => $failure]
				);
			}
		}
	}//end sweepTaskTimeouts()
}//end class
