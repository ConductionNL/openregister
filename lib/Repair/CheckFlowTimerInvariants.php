<?php

/**
 * CheckFlowTimerInvariants: COUNTS timer-store defects and reports them; it
 * never repairs them quietly.
 *
 * Four invariants (design.md, Migration Plan step 5 and D-9): every armed
 * timer has a non-NULL `fire_at`; every suspended timer has a NULL `fire_at`
 * and a NULL `running_since`; and no armed or suspended timer's task subject
 * is terminal or absent. A non-zero count is a defect in whoever completed
 * the subject or wrote the budget — cancelling the orphan here would hide
 * exactly the bug the count exists to surface.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerMapper;
use OCA\OpenRegister\Db\TaskMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reports armed-without-fire_at, suspended-with-fire_at and orphaned timers.
 */
class CheckFlowTimerInvariants implements IRepairStep {

	/**
	 * Rows read per page.
	 *
	 * @var int
	 */
	private const PAGE = 500;

	/**
	 * Constructor.
	 *
	 * @param FlowTimerMapper $timers The timer table.
	 * @param TaskMapper $tasks The task table, for subject existence and terminality.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly FlowTimerMapper $timers,
		private readonly TaskMapper $tasks,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function getName(): string {
		return 'Check business-timer invariants (armed fire_at, suspended NULLs, orphaned timers) and report defects';
	}//end getName()

	/**
	 * Run the check. Reports counts; mutates nothing.
	 *
	 * @param IOutput $output Output interface for status messages.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function run(IOutput $output): void {
		try {
			$counts = $this->measure();
		} catch (Throwable $failure) {
			// A fresh install has no table yet when this runs pre-seed; say so rather than fail the upgrade.
			$this->logger->warning('[CheckFlowTimerInvariants] Check skipped: ' . $failure->getMessage());
			$output->warning('Business-timer invariant check skipped: ' . $failure->getMessage());

			return;
		}

		$defects = array_sum($counts);
		$message = sprintf(
			'Business-timer invariants: %d armed without fire_at, %d suspended with fire_at or running_since, %d orphaned (subject terminal or absent).',
			$counts['armedWithoutFireAt'],
			$counts['suspendedWithClock'],
			$counts['orphaned']
		);

		if ($defects === 0) {
			$output->info($message);

			return;
		}

		$output->warning($message . ' These are DEFECTS in whoever completed the subject; they are reported, not cancelled.');
		$this->logger->warning('[CheckFlowTimerInvariants] ' . $message, $counts);
	}//end run()

	/**
	 * Count the defects across the armed and suspended timers.
	 *
	 * @return array{armedWithoutFireAt: int, suspendedWithClock: int, orphaned: int} The counts.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function measure(): array {
		$counts = ['armedWithoutFireAt' => 0, 'suspendedWithClock' => 0, 'orphaned' => 0];

		foreach ([FlowTimer::STATE_ARMED, FlowTimer::STATE_SUSPENDED] as $state) {
			$afterId = 0;
			$more = true;
			while ($more === true) {
				$page = $this->timers->findByStatePaged(state: $state, afterId: $afterId, limit: self::PAGE);
				foreach ($page as $timer) {
					$afterId = (int)$timer->getId();
					$this->inspect(timer: $timer, counts: $counts);
				}

				$more = (count($page) === self::PAGE);
			}
		}

		return $counts;
	}//end measure()

	/**
	 * Inspect one open timer against the invariants.
	 *
	 * @param FlowTimer $timer The timer.
	 * @param array{armedWithoutFireAt: int, suspendedWithClock: int, orphaned: int} $counts The running counts, by reference.
	 *
	 * @return void
	 */
	private function inspect(FlowTimer $timer, array &$counts): void {
		if ($timer->getState() === FlowTimer::STATE_ARMED && $timer->getFireAt() === null) {
			$counts['armedWithoutFireAt']++;
		}

		if ($timer->getState() === FlowTimer::STATE_SUSPENDED && ($timer->getFireAt() !== null || $timer->getRunningSince() !== null)) {
			$counts['suspendedWithClock']++;
		}

		if ($timer->getSubjectType() === 'task' && $this->taskIsTerminalOrAbsent(uuid: (string)$timer->getSubjectUuid()) === true) {
			$counts['orphaned']++;
		}
	}//end inspect()

	/**
	 * Whether a task subject is terminal or does not exist.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return boolean True when the timer is orphaned.
	 */
	private function taskIsTerminalOrAbsent(string $uuid): bool {
		try {
			return $this->tasks->findByUuid(uuid: $uuid)->isInTerminalState();
		} catch (DoesNotExistException) {
			return true;
		}
	}//end taskIsTerminalOrAbsent()
}//end class
