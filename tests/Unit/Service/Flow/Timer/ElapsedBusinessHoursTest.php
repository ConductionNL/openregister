<?php

/**
 * Elapsed working hours: how much of an interval the organisation was open.
 *
 * The one question the timer vocabulary could not answer. `measure()` in
 * hours answers wall clock, and in business days it answers fractions of a
 * calendar day on working days. Both are right for a deadline and neither is
 * elapsed working time, so a report built on either compares two teams on a
 * number that rewards whoever draws the Friday afternoon cases.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

use DateTimeImmutable;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use PHPUnit\Framework\TestCase;

/**
 * The working-hours measurement, and the window it reads.
 *
 * @covers \OCA\OpenRegister\Service\Flow\Timer\SlaCalculator
 * @covers \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar
 */
class ElapsedBusinessHoursTest extends TestCase {
	/**
	 * The seeded Dutch calendar: Monday to Friday, eight hours, 09:00.
	 *
	 * @return WorkingCalendar The calendar.
	 */
	private function calendar(): WorkingCalendar {
		return WorkingCalendar::fromArray(definition: WorkingCalendarTest::nlNational());
	}

	/**
	 * The scenario the whole change exists for.
	 *
	 * Friday 16:00 to Monday 09:00 is 65 hours on the wall and one working
	 * hour: the last hour of Friday, then a closed weekend, then Monday up to
	 * the moment the doors open.
	 *
	 * @return void
	 */
	public function testAWeekendIsNotWorkingTime(): void {
		$sla = new SlaCalculator();
		$calendar = $this->calendar();
		$from = new DateTimeImmutable('2026-09-11T16:00:00+00:00');
		$to = new DateTimeImmutable('2026-09-14T09:00:00+00:00');

		$this->assertSame(
			1.0,
			$sla->elapsedBusinessHours(from: $from, to: $to, calendar: $calendar)
		);

		// The control, and the reason this method had to exist: the two
		// measurements the calculator already had answer something else.
		$this->assertSame(
			65.0,
			$sla->measure(from: $from, to: $to, unit: SlaCalculator::UNIT_HOURS, calendar: $calendar)
		);
		$this->assertGreaterThan(
			5.0,
			$sla->convert(
				value: $sla->measure(from: $from, to: $to, unit: SlaCalculator::UNIT_BUSINESS_DAYS, calendar: $calendar),
				fromUnit: SlaCalculator::UNIT_BUSINESS_DAYS,
				toUnit: SlaCalculator::UNIT_HOURS,
				calendar: $calendar
			)
		);
	}

	/**
	 * A whole working day is the calendar's own day length, no more.
	 *
	 * @return void
	 */
	public function testAWholeWorkingDayIsTheCalendarsDay(): void {
		$sla = new SlaCalculator();

		$this->assertSame(
			8.0,
			$sla->elapsedBusinessHours(
				from: new DateTimeImmutable('2026-09-08T00:00:00+00:00'),
				to: new DateTimeImmutable('2026-09-09T00:00:00+00:00'),
				calendar: $this->calendar()
			)
		);
	}

	/**
	 * Time outside the window contributes nothing at all.
	 *
	 * The assertion that separates a real window from a day-fraction count:
	 * an interval entirely inside a working day but entirely before it opens
	 * is zero, and a day-fraction measurement would call it 0.375 of a day.
	 *
	 * @return void
	 */
	public function testAnIntervalOutsideTheWindowIsZero(): void {
		$sla = new SlaCalculator();

		$this->assertSame(
			0.0,
			$sla->elapsedBusinessHours(
				from: new DateTimeImmutable('2026-09-08T00:00:00+00:00'),
				to: new DateTimeImmutable('2026-09-08T09:00:00+00:00'),
				calendar: $this->calendar()
			)
		);
		$this->assertSame(
			0.0,
			$sla->elapsedBusinessHours(
				from: new DateTimeImmutable('2026-09-08T17:00:00+00:00'),
				to: new DateTimeImmutable('2026-09-08T23:00:00+00:00'),
				calendar: $this->calendar()
			)
		);
	}

	/**
	 * A closed day the calendar names is skipped like a weekend.
	 *
	 * Second Christmas Day 2026 is a Saturday, so Christmas Day itself, the
	 * Friday, is the closure that shows. Reading the calendar's own answer
	 * rather than hard-coding one keeps the fixture honest if the rules move.
	 *
	 * @return void
	 */
	public function testANonWorkingDateIsSkipped(): void {
		$sla = new SlaCalculator();
		$calendar = $this->calendar();
		$christmas = new DateTimeImmutable('2026-12-25T12:00:00+00:00');
		$this->assertFalse($calendar->isWorkingDay($christmas), 'Christmas Day is a closure on this calendar');

		// Thursday 24th 16:00 to Monday 28th 10:00. The 24th gives one hour,
		// the 25th is closed, the weekend is closed, and the 28th gives one.
		$this->assertSame(
			2.0,
			$sla->elapsedBusinessHours(
				from: new DateTimeImmutable('2026-12-24T16:00:00+00:00'),
				to: new DateTimeImmutable('2026-12-28T10:00:00+00:00'),
				calendar: $calendar
			)
		);
	}

	/**
	 * Backwards is the negative of forwards, as `measure()` already is.
	 *
	 * @return void
	 */
	public function testTheMeasurementIsSigned(): void {
		$sla = new SlaCalculator();

		$this->assertSame(
			-1.0,
			$sla->elapsedBusinessHours(
				from: new DateTimeImmutable('2026-09-14T09:00:00+00:00'),
				to: new DateTimeImmutable('2026-09-11T16:00:00+00:00'),
				calendar: $this->calendar()
			)
		);
	}

	/**
	 * The window moves with the calendar, and closes a day length later.
	 *
	 * @return void
	 */
	public function testTheWindowFollowsTheDeclaredOpeningTime(): void {
		$early = WorkingCalendar::fromArray(
			definition: array_merge(WorkingCalendarTest::nlNational(), ['dayStartsAt' => '07:30'])
		);

		$this->assertSame((7 * 60) + 30, $early->getDayStartsAtMinute());
		$this->assertSame((15 * 60) + 30, $early->getDayEndsAtMinute());

		// 07:00 to 08:00 is half an hour of work on a calendar that opens at
		// 07:30, and none at all on one that opens at 09:00.
		$sla = new SlaCalculator();
		$from = new DateTimeImmutable('2026-09-08T07:00:00+00:00');
		$to = new DateTimeImmutable('2026-09-08T08:00:00+00:00');

		$this->assertSame(0.5, $sla->elapsedBusinessHours(from: $from, to: $to, calendar: $early));
		$this->assertSame(0.0, $sla->elapsedBusinessHours(from: $from, to: $to, calendar: $this->calendar()));
	}

	/**
	 * The default is 09:00, stated rather than derived.
	 *
	 * @return void
	 */
	public function testTheOpeningTimeDefaultsToNine(): void {
		$definition = WorkingCalendarTest::nlNational();
		unset($definition['dayStartsAt']);

		$this->assertSame((9 * 60), WorkingCalendar::fromArray(definition: $definition)->getDayStartsAtMinute());
	}

	/**
	 * A malformed opening time is refused by name, never coerced.
	 *
	 * A calendar read as midnight because somebody wrote `9am` would move
	 * every elapsed hour on the instance by nine and say nothing.
	 *
	 * @return void
	 */
	public function testAMalformedOpeningTimeIsRefused(): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessageMatches('/dayStartsAt/');

		WorkingCalendar::fromArray(
			definition: array_merge(WorkingCalendarTest::nlNational(), ['dayStartsAt' => '9am'])
		);
	}

	/**
	 * A day longer than the hours left in it closes at midnight.
	 *
	 * @return void
	 */
	public function testALateLongDayIsClampedToItsOwnDay(): void {
		$late = WorkingCalendar::fromArray(
			definition: array_merge(
				WorkingCalendarTest::nlNational(),
				['dayStartsAt' => '18:00', 'hoursPerWorkingDay' => 12]
			)
		);

		$this->assertSame((24 * 60), $late->getDayEndsAtMinute());
	}
}//end class
