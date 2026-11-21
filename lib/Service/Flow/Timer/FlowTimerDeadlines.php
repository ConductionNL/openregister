<?php

/**
 * Derives a timer's deadline and what is left of its budget.
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
 * @spec openspec/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\FlowTimer;

/**
 * THE one derivation of `fire_at` and `next_rung_at`.
 */
final class FlowTimerDeadlines {

	/**
	 * Shared conversions.
	 *
	 * @var FlowTimerValues
	 */
	private readonly FlowTimerValues $values;

	/**
	 * Constructor.
	 *
	 * @param SlaCalculatorInterface           $calculator Business-time arithmetic.
	 * @param EscalationLadderService $ladder     Rung resolution.
	 */
	public function __construct(
		private readonly SlaCalculatorInterface $calculator,
		private readonly EscalationLadderService $ladder,
	) {
		$this->values = new FlowTimerValues();
	}//end __construct()

	/**
	 * Derive `fire_at` and `next_rung_at`.
	 *
	 * Armed: `fire_at = add(running_since, budget - consumed)` and
	 * `next_rung_at` = the earliest unfired rung. Any other state: both NULL.
	 * Every mutating operation ends here and nothing else writes these columns.
	 *
	 * @param FlowTimerScope     $scope     The timer, mutated in place, and its calendar.
	 * @param array<int, string> $firedKeys Rung keys already in the ledger.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
	 */
	public function recompute(FlowTimerScope $scope, array $firedKeys): void {
		$timer = $scope->timer;
		if ($timer->getState() !== FlowTimer::STATE_ARMED || $timer->getRunningSince() === null) {
			$this->clear(timer: $timer);

			return;
		}

		$fireAt = $this->applyRoll(scope: $scope);
		$rungs = $this->ladder->resolveLadder(timer: $timer)['rungs'];
		$next = $this->ladder->nextRungAt(timeline: new RungTimeline(rungs: $rungs, fireAt: $fireAt, firedKeys: $firedKeys, calendar: $scope->calendar));
		$timer->setNextRungAt($this->values->mutableOrNull(value: $next));
	}//end recompute()

	/**
	 * The fire moment before any roll: the running start plus the unconsumed budget.
	 *
	 * @param FlowTimerScope $scope The timer and its calendar.
	 *
	 * @return DateTimeInterface The moment.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public function landed(FlowTimerScope $scope): DateTimeInterface {
		$timer = $scope->timer;
		return $this->calculator->add(
			addition: new SlaAddition(
				start: DateTimeImmutable::createFromInterface($timer->getRunningSince()),
				value: max(0.0, ((float)$timer->getBudgetValue() - (float)$timer->getConsumedValue())),
				unit: (string)$timer->getBudgetUnit(),
				calendar: $scope->calendar
			)
		);
	}//end landed()

	/**
	 * Remaining budget in the timer's own unit, answerable while suspended.
	 *
	 * @param FlowTimerScope    $scope The timer and its calendar.
	 * @param DateTimeImmutable $now   The clock.
	 *
	 * @return float The remainder; negative once overdue.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public function remaining(FlowTimerScope $scope, DateTimeImmutable $now): float {
		$timer = $scope->timer;
		$running = 0.0;
		if ($timer->getRunningSince() !== null && $timer->getState() === FlowTimer::STATE_ARMED) {
			$running = $this->calculator->measure(
				span: new CalendarSpan(from: $timer->getRunningSince(), to: $now, calendar: $scope->calendar),
				unit: (string)$timer->getBudgetUnit()
			);
		}

		return ((float)$timer->getBudgetValue() - (float)$timer->getConsumedValue() - $running);
	}//end remaining()

	/**
	 * Clear the deadline of a timer that is not running.
	 *
	 * A suspended term has no deadline, so it has no rolled deadline either.
	 * Leaving the explanation behind would describe a move that no longer applies
	 * to anything.
	 *
	 * @param FlowTimer $timer The timer.
	 *
	 * @return void
	 */
	private function clear(FlowTimer $timer): void {
		$timer->setFireAt(null);
		$timer->setNextRungAt(null);
		$timer->setUnrolledAt(null);
		$timer->setRolledBy(null);
	}//end clear()

	/**
	 * Set the deadline, rolled onto a working day when the timer says so.
	 *
	 * 🔑 THE ROLL IS APPLIED HERE AND ONLY HERE. Every path that moves a deadline —
	 * arm, extend, supersede, suspend, resume — comes through this method, so the
	 * roll cannot be forgotten on one of them, and the escalation ladder is measured
	 * against the ROLLED moment because that is the deadline the term actually has.
	 *
	 * @param FlowTimerScope $scope The timer and its calendar.
	 *
	 * @return DateTimeInterface The rolled fire moment.
	 */
	private function applyRoll(FlowTimerScope $scope): DateTimeInterface {
		$timer = $scope->timer;
		$rolled = $this->calculator->roll(
			moment: $this->landed(scope: $scope),
			rule: new RollRule(roll: (string)($timer->getRollToWorkingDay() ?? SlaCalculatorInterface::ROLL_NONE), calendar: $scope->calendar)
		);
		$timer->setFireAt($this->values->mutable(value: $rolled['at']));
		$timer->setUnrolledAt($this->values->mutableOrNull(value: $rolled['unrolledAt']));
		$timer->setRolledBy($rolled['rolledBy']);

		return $rolled['at'];
	}//end applyRoll()
}//end class
