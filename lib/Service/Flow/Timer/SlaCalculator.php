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

		return ['value' => $value, 'unit' => $this->validateUnit(unit: $sla['unit'])];
	}//end validateSla()

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
	 * @param WorkingCalendar $calendar The resolved calendar.
	 *
	 * @return DateTimeImmutable The resulting instant.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function add(DateTimeInterface $from, float $value, string $unit, WorkingCalendar $calendar): DateTimeImmutable {
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

		if ($value >= 0) {
			return $this->walkForward(start: $start, days: $value, calendar: $calendar);
		}

		return $this->walkBackward(start: $start, days: -$value, calendar: $calendar);
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
	public function sub(DateTimeInterface $from, float $value, string $unit, WorkingCalendar $calendar): DateTimeImmutable {
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
	private function walkForward(DateTimeImmutable $start, float $days, WorkingCalendar $calendar): DateTimeImmutable {
		$cursor = $start;
		$remaining = $days;
		for ($walked = 0; $walked <= self::MAX_WALK_DAYS; $walked++) {
			$nextMidnight = $this->shift(moment: $cursor->setTime(0, 0, 0), modifier: '+1 day');
			if ($calendar->isWorkingDay($cursor) === true) {
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
	private function walkBackward(DateTimeImmutable $start, float $days, WorkingCalendar $calendar): DateTimeImmutable {
		$cursor = $start;
		$remaining = $days;
		for ($walked = 0; $walked <= self::MAX_WALK_DAYS; $walked++) {
			$dayStart = $cursor->setTime(0, 0, 0);
			// An instant exactly at midnight belongs to the END of the previous day when walking back.
			if ($cursor->getTimestamp() === $dayStart->getTimestamp()) {
				$dayStart = $this->shift(moment: $dayStart, modifier: '-1 day');
			}

			if ($calendar->isWorkingDay($dayStart) === true) {
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
