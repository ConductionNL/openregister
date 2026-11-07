<?php

/**
 * One request to add business time to an instant: where it starts, how much,
 * in which unit, against which calendar, and who is watching the walk.
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
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTimeImmutable;
use OCA\OpenRegister\Exception\FlowTimerValidationException;

/**
 * The parameters of {@see SlaCalculator::add()}, carried through the walk.
 */
final class SlaAddition {

	/**
	 * Constructor.
	 *
	 * @param DateTimeImmutable    $start     The start instant.
	 * @param float                $value     The amount; negative subtracts.
	 * @param string               $unit      The unit.
	 * @param WorkingCalendar|null $calendar  The resolved calendar, when one was given.
	 */
	public function __construct(
		public readonly DateTimeImmutable $start,
		public readonly float $value,
		public readonly string $unit,
		private readonly ?WorkingCalendar $calendar,
	) {

	}//end __construct()

	/**
	 * The calendar a business unit is counted against.
	 *
	 * 🔴 A BUSINESS UNIT WITHOUT A CALENDAR IS REFUSED, not counted as
	 * wall-clock time. The parameter is nullable because `hours` and
	 * `calendarDays` genuinely need no calendar and a caller should not
	 * have to invent one to say "two days"; the units that DO need one
	 * refuse here rather than quietly computing a different deadline.
	 *
	 * @return WorkingCalendar The calendar.
	 *
	 * @throws FlowTimerValidationException When none was given.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function calendar(): WorkingCalendar {
		if ($this->calendar === null) {
			throw new FlowTimerValidationException(
				message: sprintf(
					"Unit '%s' is counted against a working calendar and none was given; it would silently become wall-clock time.",
					$this->unit
				)
			);
		}

		return $this->calendar;
	}//end calendar()

	/**
	 * The calendar whose service hours an hours term advances through, when it applies.
	 *
	 * Only forward and only on a calendar that declares service hours. A
	 * negative hours term is an offset read BACK from a moment, and walking
	 * windows backwards is a second walk with its own edge cases; until an
	 * escalation rung needs it, the backward path keeps the wall clock it has
	 * always had, and says so rather than pretending to be window-aware.
	 *
	 * @return WorkingCalendar|null The calendar, or null for wall-clock hours.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function serviceHoursCalendar(): ?WorkingCalendar {
		if ($this->calendar !== null && $this->value > 0 && $this->calendar->getServiceHours()->areDeclared() === true) {
			return $this->calendar;
		}

		return null;
	}//end serviceHoursCalendar()

	/**
	 * Whether the walk goes forward (zero included).
	 *
	 * @return boolean True for a non-negative amount.
	 */
	public function isForward(): bool {
		return $this->value >= 0;
	}//end isForward()

	/**
	 * The amount to walk, as a non-negative number.
	 *
	 * @return float The amount.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function amount(): float {
		if ($this->isForward() === true) {
			return $this->value;
		}

		return -$this->value;
	}//end amount()

	/**
	 * The sign of the walk: +1 forward, -1 backward.
	 *
	 * @return integer The sign.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function sign(): int {
		if ($this->isForward() === true) {
			return 1;
		}

		return -1;
	}//end sign()

	/**
	 * What the walk is doing, for the refusal when it runs too long.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function describe(): string {
		$verb = 'Subtracting';
		if ($this->isForward() === true) {
			$verb = 'Adding';
		}

		return sprintf('%s %s business days from %s', $verb, (string)$this->amount(), $this->start->format('c'));
	}//end describe()

	/**
	 * Whether a day counts, handing it to the collector with the rule that skipped it.
	 *
	 * The rule NAME comes from the calendar's own `nonWorkingDates()`, the same
	 * map `isWorkingDay()` consults, so the diagnostic cannot name a rule the
	 * engine did not apply. A day that is non-working because the working week
	 * does not include it has no rule, and the collector calls that `weekend`.
	 *
	 * @param DateTimeImmutable  $day       The day examined.
	 * @param WalkCollector|null $collector Records the walk when a diagnostic is asking.
	 *
	 * @return boolean True when it is a working day.
	 *
	 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
	 */
	public function examine(DateTimeImmutable $day, ?WalkCollector $collector): bool {
		$working = $this->calendar()->isWorkingDay($day);
		$collector?->examine(day: $day, counted: $working, rule: $this->skipRule(day: $day, counted: $working));

		return $working;
	}//end examine()

	/**
	 * The calendar rule that made a day non-working, if any.
	 *
	 * @param DateTimeImmutable $day     The day.
	 * @param bool              $counted Whether it counted.
	 *
	 * @return string|null The rule name, or null for a working day or a weekend.
	 */
	private function skipRule(DateTimeImmutable $day, bool $counted): ?string {
		if ($counted === true) {
			return null;
		}

		return ($this->calendar()->nonWorkingDates(year: (int)$day->format('Y'))[$day->format('Y-m-d')] ?? null);
	}//end skipRule()
}//end class
