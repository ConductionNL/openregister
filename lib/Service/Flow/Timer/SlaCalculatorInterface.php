<?php

/**
 * SlaCalculatorInterface: business-time arithmetic, as its callers ask for it.
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

/**
 * The unit and roll vocabulary and the SLA operations of {@see SlaCalculator}.
 *
 * Callers type against this, so the calculator itself is named only where it
 * is built.
 */
interface SlaCalculatorInterface {

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
	 * The accepted SLA value range, inclusive.
	 */
	public const MIN_VALUE = 1;

	public const MAX_VALUE = 10000;

	/**
	 * Validate an SLA of shape `{value, unit}`.
	 *
	 * The refusals live in {@see SlaDeclaration}; this is the published
	 * surface the timer service and the diagnostic already ask.
	 *
	 * @param mixed $sla The declared SLA.
	 *
	 * @return array{value: int, unit: string} The normalised SLA.
	 *
	 * @throws \OCA\OpenRegister\Exception\FlowTimerValidationException When the shape, the range or the unit is refused.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function validateSla(mixed $sla): array;

	/**
	 * Validate a unit name.
	 *
	 * @param mixed $unit The declared unit.
	 *
	 * @return string The unit.
	 *
	 * @throws \OCA\OpenRegister\Exception\FlowTimerValidationException On an unknown unit.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function validateUnit(mixed $unit): string;

	/**
	 * Move a moment off a non-working day, and say what moved it.
	 *
	 * @param DateTimeInterface    $moment   The computed moment.
	 * @param string               $roll     One of ROLLS.
	 * @param WorkingCalendar|null $calendar The resolved calendar.
	 *
	 * @return array{at: DateTimeImmutable, unrolledAt: ?DateTimeImmutable, rolledBy: ?string} Where it ended up.
	 *
	 * @spec openspec/changes/end-date-roll-on-the-calendar/specs/flow-business-timers/spec.md#requirement-a-budget-may-roll-its-end-date-to-a-working-day
	 */
	public function roll(DateTimeInterface $moment, string $roll, ?WorkingCalendar $calendar): array;

	/**
	 * Add an amount of business time to an instant.
	 *
	 * @param DateTimeInterface $from The start instant.
	 * @param float $value The amount; negative subtracts.
	 * @param string $unit The unit.
	 * @param WorkingCalendar|null $calendar The resolved calendar; required for business units and
	 *        refused when absent, ignored for hours and calendar days.
	 * @param WalkCollector|null $collector Records the walk when a diagnostic is asking; the arm path passes none.
	 *
	 * @return DateTimeImmutable The resulting instant.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
	 */
	public function add(
		DateTimeInterface $from, float $value, string $unit,
		?WorkingCalendar $calendar, ?WalkCollector $collector = null
	): DateTimeImmutable;

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
	public function sub(DateTimeInterface $from, float $value, string $unit, ?WorkingCalendar $calendar): DateTimeImmutable;

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
	public function measure(DateTimeInterface $from, DateTimeInterface $to, string $unit, WorkingCalendar $calendar): float;

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
	 * @throws \OCA\OpenRegister\Exception\FlowTimerValidationException When the interval is longer than the walk allows.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public function elapsedBusinessHours(DateTimeInterface $from, DateTimeInterface $to, WorkingCalendar $calendar): float;

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
	public function convert(float $value, string $fromUnit, string $toUnit, WorkingCalendar $calendar): float;
}//end interface
