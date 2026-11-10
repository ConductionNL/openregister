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

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Exception\FlowTimerValidationException;

/**
 * SLA arithmetic: measure, add and subtract in hours, business days or calendar days.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) DateTimeImmutable::createFromInterface
 * is PHP's own conversion; there is no instance form.
 */
final class SlaCalculator implements SlaCalculatorInterface {

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
	 * The walk over a calendar's declared service hours.
	 *
	 * @var ServiceHoursClock
	 */
	private readonly ServiceHoursClock $hoursClock;

	/**
	 * The declaration-time refusals.
	 *
	 * @var SlaDeclaration
	 */
	private SlaDeclaration $declarations;

	/**
	 * The roll off a non-working day.
	 *
	 * @var WorkingDayRoll
	 */
	private WorkingDayRoll $workingDayRoll;

	/**
	 * Constructor.
	 *
	 * The clock defaults rather than being required, because this class is
	 * also constructed directly in a dozen tests and in two consumers that
	 * predate it, and a required argument would turn a wiring change into a
	 * fatal error on an upgraded instance.
	 *
	 * @param ServiceHoursClock|null $hoursClock The service-hours walk, or null for the default.
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/flow-business-timers/spec.md
	 */
	public function __construct(?ServiceHoursClock $hoursClock = null) {
		$this->hoursClock = ($hoursClock ?? new ServiceHoursClock());
		$this->declarations = new SlaDeclaration();
		$this->workingDayRoll = new WorkingDayRoll();
	}//end __construct()

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
	 * @throws FlowTimerValidationException When the shape, the range or the unit is refused.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function validateSla(mixed $sla): array {
		return $this->declarations->validateSla(sla: $sla);
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
		return $this->declarations->validateUnit(unit: $unit);
	}//end validateUnit()

	/**
	 * Move a moment off a non-working day, and say what moved it.
	 *
	 * @param DateTimeInterface $moment The computed moment.
	 * @param RollRule          $rule   Which way to roll, and the resolved calendar.
	 *
	 * @return array{at: DateTimeImmutable, unrolledAt: ?DateTimeImmutable, rolledBy: ?string} Where it ended up.
	 *
	 * @spec openspec/changes/end-date-roll-on-the-calendar/specs/flow-business-timers/spec.md#requirement-a-budget-may-roll-its-end-date-to-a-working-day
	 */
	public function roll(DateTimeInterface $moment, RollRule $rule): array {
		return $this->workingDayRoll->apply(moment: $moment, roll: $rule->roll, calendar: $rule->calendar);
	}//end roll()

	/**
	 * Add an amount of business time to an instant.
	 *
	 * @param SlaAddition $addition The start instant, the amount (negative subtracts), the unit, and the
	 *                              resolved calendar: required for business units and refused when absent,
	 *                              ignored for hours and calendar days.
	 *
	 * @return DateTimeImmutable The resulting instant.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function add(SlaAddition $addition): DateTimeImmutable {
		return $this->addWatched(addition: $addition, collector: null);
	}//end add()

	/**
	 * Add an amount of business time to an instant, recording the walk when someone is watching.
	 *
	 * @param SlaAddition        $addition  The start, the amount, the unit and the calendar.
	 * @param WalkCollector|null $collector Records the walk when a diagnostic is asking; the arm path passes none.
	 *
	 * @return DateTimeImmutable The resulting instant.
	 *
	 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
	 */
	public function addWatched(SlaAddition $addition, ?WalkCollector $collector): DateTimeImmutable {
		$this->validateUnit(unit: $addition->unit);

		if ($addition->unit === self::UNIT_HOURS) {
			return $this->addHours(addition: $addition);
		}

		if ($addition->unit === self::UNIT_CALENDAR_DAYS) {
			return $this->addCalendarDays(start: $addition->start, value: $addition->value);
		}

		return $this->walk(addition: $addition, collector: $collector);
	}//end addWatched()

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
		return $this->add(
			addition: new SlaAddition(
				start: DateTimeImmutable::createFromInterface($from),
				value: -$value,
				unit: $unit,
				calendar: $calendar
			)
		);
	}//end sub()

	/**
	 * How much business time lies between two instants, signed.
	 *
	 * @param CalendarSpan $span The start, the end (before the start yields a negative amount) and the resolved calendar.
	 * @param string       $unit The unit.
	 *
	 * @return float The amount in the unit.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-suspended-deadline-holds-elapsed-time-not-a-moment
	 */
	public function measure(CalendarSpan $span, string $unit): float {
		return $this->measureSpan(span: $span, unit: $unit);
	}//end measure()

	/**
	 * The amount of one unit across a span; a span that runs backwards yields a negative amount.
	 *
	 * @param CalendarSpan $span The instants and the resolved calendar.
	 * @param string       $unit The unit.
	 *
	 * @return float The amount in the unit.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-suspended-deadline-holds-elapsed-time-not-a-moment
	 */
	private function measureSpan(CalendarSpan $span, string $unit): float {
		$this->validateUnit(unit: $unit);
		if ($unit === self::UNIT_HOURS) {
			return (($span->to->getTimestamp() - $span->from->getTimestamp()) / 3600);
		}

		if ($unit === self::UNIT_CALENDAR_DAYS) {
			return self::calendarDaysBetween(from: $span->from, to: $span->to);
		}

		if ($span->to->getTimestamp() < $span->from->getTimestamp()) {
			return -$this->measureSpan(span: new CalendarSpan(from: $span->to, to: $span->from, calendar: $span->calendar), unit: $unit);
		}

		return $this->sumPerDay(span: $span, perDay: fn (DateTimeImmutable $cursor): float => $this->workingFraction(span: $span, cursor: $cursor))
			?? throw $this->walkLimit(subject: $span->describe(what: 'business days'));
	}//end measureSpan()

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
	 * Negative when `to` precedes `from`, like `measure()`. Declared service hours are measured by the service-hours clock; otherwise
	 * each working day contributes the overlap with its working window.
	 *
	 * @param CalendarSpan $span The instants and the resolved calendar.
	 *
	 * @return float The working hours between the two instants.
	 *
	 * @throws FlowTimerValidationException When the interval is longer than the walk allows.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public function elapsedBusinessHours(CalendarSpan $span): float {
		if ($span->to->getTimestamp() < $span->from->getTimestamp()) {
			return -$this->elapsedBusinessHours(span: new CalendarSpan(from: $span->to, to: $span->from, calendar: $span->calendar));
		}

		$windows = $span->calendar->getServiceHours();
		if ($windows->areDeclared() === true) {
			return $this->hoursClock->frame(calendar: $span->calendar, windows: $windows)->elapsed(from: $span->from, to: $span->to);
		}

		return $this->sumPerDay(span: $span, perDay: fn (DateTimeImmutable $cursor): float => $this->workingHours(span: $span, cursor: $cursor))
			?? throw $this->walkLimit(subject: $span->describe(what: 'working hours'));
	}//end elapsedBusinessHours()

	/**
	 * Convert an amount between units, through hours as the pivot: one business
	 * day is the calendar's working hours, one calendar day is 24 hours.
	 *
	 * @param UnitConversion $conversion The amount, the unit it is in, the unit wanted, and the calendar that supplies hoursPerWorkingDay.
	 *
	 * @return float The converted amount.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-an-extension-is-bounded-and-may-only-be-granted-before-expiry
	 */
	public function convert(UnitConversion $conversion): float {
		$fromHours = $this->hoursPer(unit: $conversion->fromUnit, calendar: $conversion->calendar);
		$toHours = $this->hoursPer(unit: $conversion->toUnit, calendar: $conversion->calendar);
		// An identical unit returns the amount untouched rather than through
		// the pivot, so no rounding is introduced where nothing changed.
		return match ($conversion->fromUnit) { $conversion->toUnit => $conversion->value, default => (($conversion->value * $fromHours) / $toHours) };
	}//end convert()

	/**
	 * How many hours one of a unit stands for on this calendar, once the unit
	 * is validated; the only unit left after hours and calendar days is
	 * business days.
	 *
	 * @param string          $unit     The unit.
	 * @param WorkingCalendar $calendar Supplies hoursPerWorkingDay.
	 *
	 * @return float
	 *
	 * @throws FlowTimerValidationException When the unit is not one of the three.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-an-extension-is-bounded-and-may-only-be-granted-before-expiry
	 */
	private function hoursPer(string $unit, WorkingCalendar $calendar): float {
		$this->validateUnit(unit: $unit);

		return match ($unit) {
			self::UNIT_HOURS => 1.0,
			self::UNIT_CALENDAR_DAYS => 24.0,
			default => $calendar->getHoursPerWorkingDay(),
		};
	}//end hoursPer()

	/**
	 * Add hours: through the calendar's service hours when it declares any,
	 * else on the wall clock.
	 *
	 * Service hours, when the administrator declared any. An hours term then
	 * advances only while the organisation is open, which is the difference
	 * between a four-hour answer owed on Friday evening and one owed on
	 * Monday morning. See {@see SlaAddition::serviceHoursCalendar()} for why
	 * only forward.
	 *
	 * @param SlaAddition $addition The request.
	 *
	 * @return DateTimeImmutable The resulting instant.
	 */
	private function addHours(SlaAddition $addition): DateTimeImmutable {
		$calendar = $addition->serviceHoursCalendar();
		if ($calendar !== null) {
			$frame = $this->hoursClock->frame(calendar: $calendar, windows: $calendar->getServiceHours());

			return $frame->due(from: $addition->start, hours: $addition->value);
		}

		return $this->shift(moment: $addition->start, modifier: sprintf('%+d seconds', (int)round($addition->value * 3600)));
	}//end addHours()

	/**
	 * Add calendar days.
	 *
	 * Calendar days are DATES, not 86400-second spans: a term of N days
	 * lands at the same wall-clock time across a DST change.
	 *
	 * @param DateTimeImmutable $start The start instant.
	 * @param float             $value The amount; negative subtracts.
	 *
	 * @return DateTimeImmutable The resulting instant.
	 */
	private function addCalendarDays(DateTimeImmutable $start, float $value): DateTimeImmutable {
		$whole = (int)floor(abs($value));
		$fraction = (abs($value) - $whole);
		$sign = 1;
		if ($value < 0) {
			$sign = -1;
		}

		$landed = $this->shift(moment: $start, modifier: sprintf('%+d days', $sign * $whole));

		return $this->shift(moment: $landed, modifier: sprintf('%+d seconds', $sign * (int)round($fraction * self::DAY)));
	}//end addCalendarDays()

	/**
	 * Walk over working days in the request's direction, consuming fractions of each.
	 *
	 * @param SlaAddition        $addition  The request; its calendar is required.
	 * @param WalkCollector|null $collector Records each day the walk examines, or null when nobody is watching.
	 *
	 * @return DateTimeImmutable The landing instant.
	 *
	 * @throws FlowTimerValidationException Without a calendar, or when the walk exceeds its bound.
	 */
	private function walk(SlaAddition $addition, ?WalkCollector $collector): DateTimeImmutable {
		$addition->calendar();
		$cursor = $addition->start;
		$remaining = $addition->amount();
		for ($walked = 0; $walked <= self::MAX_WALK_DAYS; $walked++) {
			$next = $this->boundary(addition: $addition, cursor: $cursor);
			$available = (abs($next->getTimestamp() - $cursor->getTimestamp()) / self::DAY);
			if ($addition->examine(day: self::earlier(cursor: $cursor, next: $next), collector: $collector) === true) {
				if ($remaining <= ($available + self::EPSILON)) {
					return $this->shift(moment: $cursor, modifier: sprintf('%+d seconds', $addition->sign() * (int)round($remaining * self::DAY)));
				}

				$remaining -= $available;
			}

			$cursor = $next;
		}

		throw $this->walkLimit(subject: $addition->describe());
	}//end walk()

	/**
	 * The edge of the cursor's day in the walk's direction: the next midnight
	 * going forward, the day's own midnight going back.
	 *
	 * @param SlaAddition       $addition The request.
	 * @param DateTimeImmutable $cursor   Where the walk stands.
	 *
	 * @return DateTimeImmutable The boundary.
	 */
	private function boundary(SlaAddition $addition, DateTimeImmutable $cursor): DateTimeImmutable {
		if ($addition->isForward() === true) {
			return $this->nextMidnight(cursor: $cursor);
		}

		$dayStart = $cursor->setTime(0, 0, 0);
		// An instant exactly at midnight belongs to the END of the previous day when walking back.
		if ($cursor->getTimestamp() === $dayStart->getTimestamp()) {
			return $this->shift(moment: $dayStart, modifier: '-1 day');
		}

		return $dayStart;
	}//end boundary()

	/**
	 * The earlier of the cursor and the boundary: the day a walk step examines.
	 *
	 * @param DateTimeImmutable $cursor Where the walk stands.
	 * @param DateTimeImmutable $next   The boundary it moves to.
	 *
	 * @return DateTimeImmutable The earlier instant.
	 */
	private static function earlier(DateTimeImmutable $cursor, DateTimeImmutable $next): DateTimeImmutable {
		if ($next < $cursor) {
			return $next;
		}

		return $cursor;
	}//end earlier()

	/**
	 * Midnight after the cursor's day.
	 *
	 * @param DateTimeImmutable $cursor The instant.
	 *
	 * @return DateTimeImmutable The next midnight.
	 */
	private function nextMidnight(DateTimeImmutable $cursor): DateTimeImmutable {
		return $this->shift(moment: $cursor->setTime(0, 0, 0), modifier: '+1 day');
	}//end nextMidnight()

	/**
	 * Wall-clock calendar days between two instants: whole dates plus the fraction of a day.
	 *
	 * @param DateTimeInterface $from The start.
	 * @param DateTimeInterface $to   The end; before the start yields a negative amount.
	 *
	 * @return float The days.
	 */
	private static function calendarDaysBetween(DateTimeInterface $from, DateTimeInterface $to): float {
		$diff = $from->diff($to);
		$days = ((int)$diff->days + (($diff->h * 3600 + $diff->i * 60 + $diff->s) / self::DAY));
		if ($diff->invert === 1) {
			return -$days;
		}

		return $days;
	}//end calendarDaysBetween()

	/**
	 * Sum a per-day amount over each calendar day of a span.
	 *
	 * @param CalendarSpan                       $span   The span.
	 * @param Closure(DateTimeImmutable): float $perDay The amount the day starting at the cursor contributes.
	 *
	 * @return float|null The total, or null when the span is longer than the walk allows.
	 */
	private function sumPerDay(CalendarSpan $span, Closure $perDay): ?float {
		$cursor = $span->from;
		$total = 0.0;
		for ($walked = 0; $walked <= self::MAX_WALK_DAYS; $walked++) {
			if ($cursor >= $span->to) {
				return $total;
			}

			$total += $perDay($cursor);
			$cursor = $this->nextMidnight(cursor: $cursor);
		}

		return null;
	}//end sumPerDay()

	/**
	 * The fraction of a calendar day the span covers on the cursor's day, when it is a working day.
	 *
	 * @param CalendarSpan      $span   The span.
	 * @param DateTimeImmutable $cursor The start of the covered part of the day.
	 *
	 * @return float The fraction of a day, 0 on a non-working day.
	 */
	private function workingFraction(CalendarSpan $span, DateTimeImmutable $cursor): float {
		if ($span->calendar->isWorkingDay($cursor) === false) {
			return 0.0;
		}

		$segmentEnd = min($span->to->getTimestamp(), $this->nextMidnight(cursor: $cursor)->getTimestamp());

		return (($segmentEnd - $cursor->getTimestamp()) / self::DAY);
	}//end workingFraction()

	/**
	 * The working hours the span covers on the cursor's day.
	 *
	 * The overlap of [cursor, min(end, nextMidnight)] with the day's window.
	 * An interval entirely outside it contributes nothing, which is how a
	 * Monday 00:00 to 09:00 stretch adds zero rather than nine.
	 *
	 * @param CalendarSpan      $span   The span.
	 * @param DateTimeImmutable $cursor The start of the covered part of the day.
	 *
	 * @return float The hours, 0 on a non-working day or outside the window.
	 */
	private function workingHours(CalendarSpan $span, DateTimeImmutable $cursor): float {
		if ($span->calendar->isWorkingDay($cursor) === false) {
			return 0.0;
		}

		$midnight = $cursor->setTime(0, 0, 0)->getTimestamp();
		$opens = ($midnight + ($span->calendar->getDayStartsAtMinute() * 60));
		$closes = ($midnight + ($span->calendar->getDayEndsAtMinute() * 60));
		$segmentEnd = min($span->to->getTimestamp(), $this->nextMidnight(cursor: $cursor)->getTimestamp());
		$overlap = (min($segmentEnd, $closes) - max($cursor->getTimestamp(), $opens));
		if ($overlap <= 0) {
			return 0.0;
		}

		return ($overlap / 3600);
	}//end workingHours()

	/**
	 * The refusal for a walk longer than its bound.
	 *
	 * @param string $subject What the walk was doing.
	 *
	 * @return FlowTimerValidationException The exception to throw.
	 */
	private function walkLimit(string $subject): FlowTimerValidationException {
		return new FlowTimerValidationException(message: sprintf('%s exceeds %d calendar days.', $subject, self::MAX_WALK_DAYS));
	}//end walkLimit()

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
		return MomentShift::by(moment: $moment, modifier: $modifier);
	}//end shift()
}//end class
