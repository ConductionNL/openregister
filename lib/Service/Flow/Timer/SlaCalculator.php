<?php

/**
 * Business-time arithmetic over a resolved working calendar.
 *
 * Three units — `hours`, `businessDays`, `calendarDays` — and three
 * operations: `measure(from, to)` (how much of the unit lies between two
 * instants), `add(from, value)` and `sub(from, value)`. `businessDays` is
 * NEVER computed without a calendar: every signature takes one, and there is
 * no weekday-only path. A business day is counted fractionally by the part of
 * the working day covered, so a term suspended at 14:00 and resumed at 14:00
 * three working days later consumed exactly three.
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
use DateTimeInterface;
use OCA\OpenRegister\Exception\FlowTimerValidationException;

/**
 * SLA arithmetic: measure, add and subtract in hours, business days or calendar days.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) DateTimeImmutable::createFromInterface
 * is PHP's own conversion; there is no instance form.
 */
final class SlaCalculator {

	/**
	 * The unit vocabulary, shared by the SLA and the escalation offsets.
	 */
	public const UNIT_HOURS = 'hours';

	public const UNIT_BUSINESS_DAYS = 'businessDays';

	public const UNIT_CALENDAR_DAYS = 'calendarDays';

	/**
	 * Every accepted unit.
	 *
	 * @var array<int, string>
	 */
	public const UNITS = [self::UNIT_HOURS, self::UNIT_BUSINESS_DAYS, self::UNIT_CALENDAR_DAYS];

	/**
	 * The end date is left where the budget put it. THE DEFAULT, and it is the
	 * default deliberately: rolling changes a deadline, and a deadline that
	 * moved without anybody asking is worse than one that lands on a Sunday.
	 *
	 * @var string
	 */
	public const ROLL_NONE = 'none';

	/**
	 * Move the end date forward to the first working day. This is the rule the
	 * Algemene termijnenwet states for a statutory term; whether a given term
	 * is one, and whether the calendar it is measured against lists the right
	 * days, are both questions for the administrator, not for this class.
	 *
	 * @var string
	 */
	public const ROLL_NEXT = 'next';

	/**
	 * Move the end date back to the last working day.
	 *
	 * @var string
	 */
	public const ROLL_PREVIOUS = 'previous';

	/**
	 * The whole roll vocabulary.
	 *
	 * @var array<int, string>
	 */
	public const ROLLS = [self::ROLL_NONE, self::ROLL_NEXT, self::ROLL_PREVIOUS];

	/**
	 * Days a roll may walk before it gives up.
	 *
	 * A roll crosses a holiday cluster, not a season: the longest in any real
	 * calendar is a handful of days. A calendar that declares every day
	 * non-working would otherwise walk until the clock ran out, and the
	 * deadline would look like a hang.
	 *
	 * @var int
	 */
	private const MAX_ROLL_DAYS = 400;

	/**
	 * The accepted SLA value range, inclusive.
	 */
	public const MIN_VALUE = 1;

	public const MAX_VALUE = 10000;

	/**
	 * Seconds in a day, the fraction base for business days.
	 *
	 * @var int
	 */
	private const DAY = 86400;

	/**
	 * Upper bound on calendar days walked in one operation. 10000 business
	 * days over a five-day week is ~14000 calendar days; anything past this
	 * is a bug, not a term.
	 *
	 * @var int
	 */
	private const MAX_WALK_DAYS = 20000;

	/**
	 * Tolerance on fractional-day comparisons.
	 *
	 * @var float
	 */
	private const EPSILON = 0.0000001;

	/**
	 * Validate an SLA of shape `{value, unit}`.
	 *
	 * @param mixed $sla The declared SLA.
	 *
	 * @return array{value: int, unit: string} The normalised SLA.
	 *
	 * @throws FlowTimerValidationException When the shape, the range or the unit is refused.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function validateSla(mixed $sla): array {
		if (is_array($sla) === false || array_key_exists('value', $sla) === false || array_key_exists('unit', $sla) === false) {
			throw new FlowTimerValidationException(message: 'An SLA must have the shape {value, unit}.');
		}

		$value = $sla['value'];
		if (is_string($value) === true && preg_match('/^\d+$/', $value) === 1) {
			$value = (int)$value;
		}

		if (is_int($value) === false || $value < self::MIN_VALUE || $value > self::MAX_VALUE) {
			throw new FlowTimerValidationException(
				message: sprintf(
					"SLA value '%s' is refused: it must be an integer from %d to %d.",
					var_export($sla['value'], true),
					self::MIN_VALUE,
					self::MAX_VALUE
				)
			);
		}

		return [
			'value' => $value,
			'unit' => $this->validateUnit(unit: $sla['unit']),
			'rollToWorkingDay' => $this->validateRoll(roll: ($sla['rollToWorkingDay'] ?? self::ROLL_NONE)),
		];
	}//end validateSla()

	/**
	 * Validate a roll name.
	 *
	 * An absent roll is `none`, and an unknown one is REFUSED rather than
	 * defaulted. Read as `none`, a typed `nextWorkingDay` would save, arm and
	 * behave like a setting nobody made — on a deadline with legal effect,
	 * which is the worst place for a silent default.
	 *
	 * @param mixed $roll The declared roll.
	 *
	 * @return string The roll.
	 *
	 * @throws FlowTimerValidationException On an unknown roll.
	 *
	 * @spec openspec/changes/end-date-roll-on-the-calendar/specs/flow-business-timers/spec.md#requirement-a-budget-may-roll-its-end-date-to-a-working-day
	 */
	public function validateRoll(mixed $roll): string {
		if ($roll === null || $roll === '') {
			return self::ROLL_NONE;
		}

		if (is_string($roll) === false || in_array($roll, self::ROLLS, true) === false) {
			throw new FlowTimerValidationException(
				message: sprintf(
					"rollToWorkingDay '%s' is refused: use one of %s.",
					var_export($roll, true),
					implode(', ', self::ROLLS)
				)
			);
		}

		return $roll;
	}//end validateRoll()

	/**
	 * Move a moment off a non-working day, and say what moved it.
	 *
	 * 🔴 IT ANSWERS WHAT IT DID, not just where it landed. A handler looking at
	 * a term that ends on Tuesday has to be able to read that Monday was Tweede
	 * Paasdag; a rolled date with no explanation is a date somebody will
	 * challenge and nobody can defend.
	 *
	 * 🔑 THE NAME COMES FROM THE CALENDAR'S OWN RULE, never from a list in this
	 * class. `weekend` is the only name this code knows, because it is the only
	 * one it decides; every other name is whatever the administrator called the
	 * day they declared.
	 *
	 * @param DateTimeInterface    $moment   The computed moment.
	 * @param string               $roll     One of ROLLS.
	 * @param WorkingCalendar|null $calendar The resolved calendar.
	 *
	 * @return array{at: DateTimeImmutable, unrolledAt: ?DateTimeImmutable, rolledBy: ?string} Where it ended up.
	 *
	 * @spec openspec/changes/end-date-roll-on-the-calendar/specs/flow-business-timers/spec.md#requirement-a-budget-may-roll-its-end-date-to-a-working-day
	 */
	public function roll(DateTimeInterface $moment, string $roll, ?WorkingCalendar $calendar): array {
		$at = DateTimeImmutable::createFromInterface($moment);
		$unrolled = ['at' => $at, 'unrolledAt' => null, 'rolledBy' => null];

		if ($roll === self::ROLL_NONE || $calendar === null || $calendar->isWorkingDay(moment: $at) === true) {
			return $unrolled;
		}

		// The rule that stopped the FIRST day is the one that moved the term.
		// Reporting the last day walked past would name Easter Monday for a
		// term that was really stopped by the Saturday before it.
		$rolledBy = $this->nonWorkingReason(moment: $at, calendar: $calendar);

		$modifier = '+1 day';
		if ($roll === self::ROLL_PREVIOUS) {
			$modifier = '-1 day';
		}

		$walked = $at;
		for ($step = 0; $step < self::MAX_ROLL_DAYS; $step++) {
			$walked = $this->shift(moment: $walked, modifier: $modifier);
			if ($calendar->isWorkingDay(moment: $walked) === true) {
				return ['at' => $walked, 'unrolledAt' => $at, 'rolledBy' => $rolledBy];
			}
		}

		throw new FlowTimerValidationException(
			message: sprintf(
				'No working day within %d days of %s on calendar %s: the calendar declares no working days to roll to.',
				self::MAX_ROLL_DAYS,
				$at->format('Y-m-d'),
				$calendar->getSlug()
			)
		);
	}//end roll()

	/**
	 * Why a day is not a working day, in the calendar's own words.
	 *
	 * @param DateTimeImmutable $moment   The day.
	 * @param WorkingCalendar   $calendar The calendar.
	 *
	 * @return string The declared name, or `weekend`.
	 */
	private function nonWorkingReason(DateTimeImmutable $moment, WorkingCalendar $calendar): string {
		$named = ($calendar->nonWorkingDates(year: (int)$moment->format('Y'))[$moment->format('Y-m-d')] ?? null);
		if (is_string($named) === true && $named !== '') {
			return $named;
		}

		// Not a declared date, so it is a day the working WEEK excludes. This
		// is the one name this class decides, because it is the one rule it
		// knows without being told.
		return 'weekend';
	}//end nonWorkingReason()

	/**
	 * Validate a unit name.
	 *
	 * @param mixed $unit The declared unit.
	 *
	 * @return string The unit.
	 *
	 * @throws FlowTimerValidationException On an unknown unit.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function validateUnit(mixed $unit): string {
		if (is_string($unit) === false || in_array($unit, self::UNITS, true) === false) {
			throw new FlowTimerValidationException(
				message: sprintf("Unit '%s' is refused: use one of %s.", var_export($unit, true), implode(', ', self::UNITS))
			);
		}

		return $unit;
	}//end validateUnit()

	/**
	 * Add an amount of business time to an instant.
	 *
	 * @param DateTimeInterface $from The start instant.
	 * @param float $value The amount; negative subtracts.
	 * @param string $unit The unit.
	 * @param WorkingCalendar|null $calendar The resolved calendar; required for business units and refused when absent, ignored for hours and calendar days.
	 * @param WalkCollector|null $collector Records the walk when a diagnostic is asking; the arm path passes none.
	 *
	 * @return DateTimeImmutable The resulting instant.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
	 */
	public function add(
		DateTimeInterface $from,
		float $value,
		string $unit,
		?WorkingCalendar $calendar,
		?WalkCollector $collector = null
	): DateTimeImmutable {
		$start = DateTimeImmutable::createFromInterface($from);
		$this->validateUnit(unit: $unit);

		if ($unit === self::UNIT_HOURS) {
			return $this->shift(moment: $start, modifier: sprintf('%+d seconds', (int)round($value * 3600)));
		}

		if ($unit === self::UNIT_CALENDAR_DAYS) {
			// Calendar days are DATES, not 86400-second spans: a term of N days
			// lands at the same wall-clock time across a DST change.
			$whole = (int)floor(abs($value));
			$fraction = (abs($value) - $whole);
			$sign = 1;
			if ($value < 0) {
				$sign = -1;
			}

			$landed = $this->shift(moment: $start, modifier: sprintf('%+d days', $sign * $whole));

			return $this->shift(moment: $landed, modifier: sprintf('%+d seconds', $sign * (int)round($fraction * self::DAY)));
		}

		// 🔴 A BUSINESS UNIT WITHOUT A CALENDAR IS REFUSED, not counted as
		// wall-clock time. The parameter is nullable because `hours` and
		// `calendarDays` genuinely need no calendar and a caller should not
		// have to invent one to say "two days"; the units that DO need one
		// refuse here rather than quietly computing a different deadline.
		if ($calendar === null) {
			throw new FlowTimerValidationException(
				message: sprintf(
					"Unit '%s' is counted against a working calendar and none was given; it would silently become wall-clock time.",
					$unit
				)
			);
		}

		if ($value >= 0) {
			return $this->walkForward(start: $start, days: $value, calendar: $calendar, collector: $collector);
		}

		return $this->walkBackward(start: $start, days: -$value, calendar: $calendar, collector: $collector);
	}//end add()

	/**
	 * Subtract an amount of business time from an instant.
	 *
	 * @param DateTimeInterface $from The start instant.
	 * @param float $value The amount.
	 * @param string $unit The unit.
	 * @param WorkingCalendar $calendar The resolved calendar.
	 *
	 * @return DateTimeImmutable The resulting instant.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-an-escalation-rule-is-validated-against-its-sla-in-commensurable-units
	 */
	public function sub(DateTimeInterface $from, float $value, string $unit, ?WorkingCalendar $calendar): DateTimeImmutable {
		return $this->add(from: $from, value: -$value, unit: $unit, calendar: $calendar);
	}//end sub()

	/**
	 * How much business time lies between two instants, signed.
	 *
	 * @param DateTimeInterface $from The start.
	 * @param DateTimeInterface $to The end; before the start yields a negative amount.
	 * @param string $unit The unit.
	 * @param WorkingCalendar $calendar The resolved calendar.
	 *
	 * @return float The amount in the unit.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-suspended-deadline-holds-elapsed-time-not-a-moment
	 */
	public function measure(DateTimeInterface $from, DateTimeInterface $to, string $unit, WorkingCalendar $calendar): float {
		$this->validateUnit(unit: $unit);
		$seconds = ($to->getTimestamp() - $from->getTimestamp());

		if ($unit === self::UNIT_HOURS) {
			return ($seconds / 3600);
		}

		if ($unit === self::UNIT_CALENDAR_DAYS) {
			// Wall-clock difference: whole dates plus the fraction of a day.
			$diff = $from->diff($to);
			$days = ((int)$diff->days + (($diff->h * 3600 + $diff->i * 60 + $diff->s) / self::DAY));
			if ($diff->invert === 1) {
				return -$days;
			}

			return $days;
		}

		if ($seconds < 0) {
			return -$this->measure(from: $to, to: $from, unit: $unit, calendar: $calendar);
		}

		$cursor = DateTimeImmutable::createFromInterface($from);
		$end = DateTimeImmutable::createFromInterface($to);
		$total = 0.0;
		for ($walked = 0; $walked <= self::MAX_WALK_DAYS; $walked++) {
			if ($cursor >= $end) {
				return $total;
			}

			$nextMidnight = $this->shift(moment: $cursor->setTime(0, 0, 0), modifier: '+1 day');
			$segmentEnd = $nextMidnight;
			if ($end < $nextMidnight) {
				$segmentEnd = $end;
			}

			if ($calendar->isWorkingDay($cursor) === true) {
				$total += (($segmentEnd->getTimestamp() - $cursor->getTimestamp()) / self::DAY);
			}

			$cursor = $nextMidnight;
		}

		throw new FlowTimerValidationException(
			message: sprintf('Measuring business days between %s and %s exceeds %d calendar days.', $from->format('c'), $to->format('c'), self::MAX_WALK_DAYS)
		);
	}//end measure()

	/**
	 * How many WORKING hours lie between two instants.
	 *
	 * NOT THE SAME QUESTION AS `measure(..., UNIT_HOURS, ...)`, and the
	 * difference is the whole point of this method. That one answers wall
	 * clock: seconds divided by 3600, weekends and nights included, which is
	 * right for a deadline expressed in hours. This one answers how much of
	 * that interval the organisation was actually open, which is what a
	 * report comparing two teams has to count. A phase entered at 16:00 on
	 * Friday and left at 09:00 on Monday is 65 wall-clock hours and one
	 * working hour, and reporting the first rewards whoever draws the Friday
	 * afternoon cases.
	 *
	 * `measure(..., UNIT_BUSINESS_DAYS, ...)` cannot stand in for it either.
	 * It counts fractions of a CALENDAR day on working days, so the same
	 * interval reads 0.71 business days, and converting that at eight hours a
	 * day gives 5.67: a number that counts Friday evening and Monday before
	 * dawn as work. Both are defensible for a deadline and neither is
	 * elapsed working time.
	 *
	 * Negative when `to` precedes `from`, like `measure()`.
	 *
	 * @param DateTimeInterface $from The start.
	 * @param DateTimeInterface $to The end.
	 * @param WorkingCalendar $calendar The resolved calendar, which supplies the
	 *                                  working weekdays, the non-working dates
	 *                                  and the hours of the day.
	 *
	 * @return float The working hours between the two instants.
	 *
	 * @throws FlowTimerValidationException When the interval is longer than the walk allows.
	 *
	 * @spec openspec/changes/the-engine-measures-elapsed-business-hours/specs/flow-business-timers/spec.md
	 */
	public function elapsedBusinessHours(DateTimeInterface $from, DateTimeInterface $to, WorkingCalendar $calendar): float {
		if ($to->getTimestamp() < $from->getTimestamp()) {
			return -$this->elapsedBusinessHours(from: $to, to: $from, calendar: $calendar);
		}

		$cursor = DateTimeImmutable::createFromInterface($from);
		$end = DateTimeImmutable::createFromInterface($to);
		$opensAt = $calendar->getDayStartsAtMinute();
		$closesAt = $calendar->getDayEndsAtMinute();
		$total = 0.0;

		for ($walked = 0; $walked <= self::MAX_WALK_DAYS; $walked++) {
			if ($cursor >= $end) {
				return $total;
			}

			$midnight = $cursor->setTime(0, 0, 0);
			$nextMidnight = $this->shift(moment: $midnight, modifier: '+1 day');

			if ($calendar->isWorkingDay($cursor) === true) {
				$opens = $midnight->getTimestamp() + ($opensAt * 60);
				$closes = $midnight->getTimestamp() + ($closesAt * 60);

				// The overlap of [cursor, min(end, nextMidnight)] with the
				// day's window. An interval entirely outside it contributes
				// nothing, which is how a Monday 00:00 to 09:00 stretch adds
				// zero rather than nine.
				$segmentEnd = min($end->getTimestamp(), $nextMidnight->getTimestamp());
				$overlap = (min($segmentEnd, $closes) - max($cursor->getTimestamp(), $opens));
				if ($overlap > 0) {
					$total += ($overlap / 3600);
				}
			}

			$cursor = $nextMidnight;
		}

		throw new FlowTimerValidationException(
			message: sprintf('Measuring working hours between %s and %s exceeds %d calendar days.', $from->format('c'), $to->format('c'), self::MAX_WALK_DAYS)
		);
	}//end elapsedBusinessHours()

	/**
	 * Convert an amount between units, through hours as the pivot: one business
	 * day is the calendar's working hours, one calendar day is 24 hours.
	 *
	 * @param float $value The amount.
	 * @param string $fromUnit The unit it is in.
	 * @param string $toUnit The unit wanted.
	 * @param WorkingCalendar $calendar Supplies hoursPerWorkingDay.
	 *
	 * @return float The converted amount.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-an-extension-is-bounded-and-may-only-be-granted-before-expiry
	 */
	public function convert(float $value, string $fromUnit, string $toUnit, WorkingCalendar $calendar): float {
		$this->validateUnit(unit: $fromUnit);
		$this->validateUnit(unit: $toUnit);
		if ($fromUnit === $toUnit) {
			return $value;
		}

		$hoursPer = [
			self::UNIT_HOURS => 1.0,
			self::UNIT_BUSINESS_DAYS => $calendar->getHoursPerWorkingDay(),
			self::UNIT_CALENDAR_DAYS => 24.0,
		];

		return (($value * $hoursPer[$fromUnit]) / $hoursPer[$toUnit]);
	}//end convert()

	/**
	 * Walk forward over working days, consuming fractions of each.
	 *
	 * @param DateTimeImmutable $start The start.
	 * @param float $days Business days to add (>= 0).
	 * @param WorkingCalendar $calendar The calendar.
	 *
	 * @return DateTimeImmutable The landing instant.
	 */
	private function walkForward(
		DateTimeImmutable $start,
		float $days,
		WorkingCalendar $calendar,
		?WalkCollector $collector = null
	): DateTimeImmutable {
		$cursor = $start;
		$remaining = $days;
		for ($walked = 0; $walked <= self::MAX_WALK_DAYS; $walked++) {
			$nextMidnight = $this->shift(moment: $cursor->setTime(0, 0, 0), modifier: '+1 day');
			$working = $calendar->isWorkingDay($cursor);
			$this->record(collector: $collector, day: $cursor, counted: $working, calendar: $calendar);
			if ($working === true) {
				$available = (($nextMidnight->getTimestamp() - $cursor->getTimestamp()) / self::DAY);
				if ($remaining <= ($available + self::EPSILON)) {
					return $this->shift(moment: $cursor, modifier: sprintf('%+d seconds', (int)round($remaining * self::DAY)));
				}

				$remaining -= $available;
			}

			$cursor = $nextMidnight;
		}

		throw new FlowTimerValidationException(
			message: sprintf('Adding %s business days from %s exceeds %d calendar days.', (string)$days, $start->format('c'), self::MAX_WALK_DAYS)
		);
	}//end walkForward()

	/**
	 * Walk backward over working days, consuming fractions of each.
	 *
	 * @param DateTimeImmutable $start The start.
	 * @param float $days Business days to subtract (>= 0).
	 * @param WorkingCalendar $calendar The calendar.
	 *
	 * @return DateTimeImmutable The landing instant.
	 */
	private function walkBackward(
		DateTimeImmutable $start,
		float $days,
		WorkingCalendar $calendar,
		?WalkCollector $collector = null
	): DateTimeImmutable {
		$cursor = $start;
		$remaining = $days;
		for ($walked = 0; $walked <= self::MAX_WALK_DAYS; $walked++) {
			$dayStart = $cursor->setTime(0, 0, 0);
			// An instant exactly at midnight belongs to the END of the previous day when walking back.
			if ($cursor->getTimestamp() === $dayStart->getTimestamp()) {
				$dayStart = $this->shift(moment: $dayStart, modifier: '-1 day');
			}

			$working = $calendar->isWorkingDay($dayStart);
			$this->record(collector: $collector, day: $dayStart, counted: $working, calendar: $calendar);
			if ($working === true) {
				$available = (($cursor->getTimestamp() - $dayStart->getTimestamp()) / self::DAY);
				if ($remaining <= ($available + self::EPSILON)) {
					return $this->shift(moment: $cursor, modifier: sprintf('%+d seconds', -(int)round($remaining * self::DAY)));
				}

				$remaining -= $available;
			}

			$cursor = $dayStart;
		}

		throw new FlowTimerValidationException(
			message: sprintf('Subtracting %s business days from %s exceeds %d calendar days.', (string)$days, $start->format('c'), self::MAX_WALK_DAYS)
		);
	}//end walkBackward()

	/**
	 * Hand one examined day to the collector, with the rule that skipped it.
	 *
	 * The rule NAME comes from the calendar's own `nonWorkingDates()`, the same
	 * map `isWorkingDay()` consults, so the diagnostic cannot name a rule the
	 * engine did not apply. A day that is non-working because the working week
	 * does not include it has no rule, and the collector calls that `weekend`.
	 *
	 * @param WalkCollector|null $collector The collector, absent on the arm path.
	 * @param DateTimeImmutable  $day       The day examined.
	 * @param bool               $counted   Whether it counted.
	 * @param WorkingCalendar    $calendar  The calendar.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
	 */
	private function record(
		?WalkCollector $collector,
		DateTimeImmutable $day,
		bool $counted,
		WorkingCalendar $calendar
	): void {
		if ($collector === null) {
			return;
		}

		$rule = null;
		if ($counted === false) {
			$rule = ($calendar->nonWorkingDates(year: (int)$day->format('Y'))[$day->format('Y-m-d')] ?? null);
		}

		$collector->examine(day: $day, counted: $counted, rule: $rule);
	}//end record()

	/**
	 * Apply a relative modifier, refusing PHP's silent `false`.
	 *
	 * @param DateTimeImmutable $moment The instant.
	 * @param string $modifier A relative modifier such as `+1 day`.
	 *
	 * @return DateTimeImmutable The shifted instant.
	 *
	 * @throws FlowTimerValidationException When the modifier is unparseable.
	 */
	private function shift(DateTimeImmutable $moment, string $modifier): DateTimeImmutable {
		$shifted = $moment->modify($modifier);
		if ($shifted === false) {
			throw new FlowTimerValidationException(message: sprintf("Date modifier '%s' is not parseable.", $modifier));
		}

		return $shifted;
	}//end shift()
}//end class
