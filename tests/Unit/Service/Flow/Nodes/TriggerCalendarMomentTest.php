<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow\Nodes;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerCalendarMoment;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use PHPUnit\Framework\TestCase;

/**
 * A calendar trigger's moment: the date plus the offset, counted in working time when a calendar is named.
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/specs/flow-engine/spec.md
 */
class TriggerCalendarMomentTest extends TestCase {

	/**
	 * `gemeente-standaard`: Monday to Friday from 09:00 to 17:00 in Amsterdam, Monday 5 April a holiday.
	 *
	 * @return WorkingCalendar The calendar.
	 */
	private static function calendar(): WorkingCalendar {
		return WorkingCalendar::fromArray(
			definition: [
				'slug' => 'gemeente-standaard',
				'hoursPerWorkingDay' => 8,
				'workingWeekdays' => [1, 2, 3, 4, 5],
				'dayStartsAt' => '09:00',
				'timezone' => 'Europe/Amsterdam',
				'rules' => [['kind' => 'fixed', 'month' => 4, 'day' => 5, 'name' => 'Holiday']],
			]
		);
	}//end calendar()

	public function testWallClockOffsetWithoutCalendar(): void {
		$moment = (new TriggerCalendarMoment())->moment('2027-03-31', '-P30D', null);

		$this->assertSame('2027-03-01T00:00:00+00:00', $moment?->format(DATE_ATOM));
	}//end testWallClockOffsetWithoutCalendar()

	public function testPositiveOffsetWithoutCalendar(): void {
		$moment = (new TriggerCalendarMoment())->moment('2027-03-31T10:15:00+00:00', 'PT2H', null);

		$this->assertSame('2027-03-31T12:15:00+00:00', $moment?->format(DATE_ATOM));
	}//end testPositiveOffsetWithoutCalendar()

	public function testWorkingDaysSkipTheWeekendAndAHoliday(): void {
		$moment = (new TriggerCalendarMoment())->moment('2027-04-09', '-P5D', self::calendar());

		$this->assertSame('2027-04-01T09:00:00+02:00', $moment?->format(DATE_ATOM));
	}//end testWorkingDaysSkipTheWeekendAndAHoliday()

	public function testForwardWorkingDaysSkipTheWeekendAndAHoliday(): void {
		// Thursday 1 April plus two working days: Friday 2, then Tuesday 6 (weekend and holiday skipped).
		$moment = (new TriggerCalendarMoment())->moment('2027-04-01', 'P2D', self::calendar());

		$this->assertSame('2027-04-06T09:00:00+02:00', $moment?->format(DATE_ATOM));
	}//end testForwardWorkingDaysSkipTheWeekendAndAHoliday()

	public function testAMomentOnAWeekendMovesToTheNextOpening(): void {
		// Saturday 3 April with no offset: the next opening is Tuesday 6 April, Monday being the holiday.
		$moment = (new TriggerCalendarMoment())->moment('2027-04-03T11:00:00+02:00', 'P0D', self::calendar());

		$this->assertSame('2027-04-06T09:00:00+02:00', $moment?->format(DATE_ATOM));
	}//end testAMomentOnAWeekendMovesToTheNextOpening()

	public function testAMomentInWorkingHoursStays(): void {
		$moment = (new TriggerCalendarMoment())->moment('2027-04-07T11:30:00+02:00', 'P0D', self::calendar());

		$this->assertSame('2027-04-07T11:30:00+02:00', $moment?->format(DATE_ATOM));
	}//end testAMomentInWorkingHoursStays()

	public function testAMomentAfterClosingMovesToTheNextMorning(): void {
		$moment = (new TriggerCalendarMoment())->moment('2027-04-07T18:00:00+02:00', 'P0D', self::calendar());

		$this->assertSame('2027-04-08T09:00:00+02:00', $moment?->format(DATE_ATOM));
	}//end testAMomentAfterClosingMovesToTheNextMorning()

	public function testMonthsStayCalendarArithmetic(): void {
		// 1 May 2027 minus one month is Thursday 1 April, a working day.
		$moment = (new TriggerCalendarMoment())->moment('2027-05-01', '-P1M', self::calendar());

		$this->assertSame('2027-04-01T09:00:00+02:00', $moment?->format(DATE_ATOM));
	}//end testMonthsStayCalendarArithmetic()

	public function testAnEmptyOrInvalidValueHasNoMoment(): void {
		$moments = new TriggerCalendarMoment();

		$this->assertNull($moments->moment(null, '-P30D', null));
		$this->assertNull($moments->moment('', '-P30D', null));
		$this->assertNull($moments->moment('2027-02-30', '-P30D', null));
		$this->assertNull($moments->moment('not a date', '-P30D', null));
		$this->assertNull($moments->moment(42, '-P30D', self::calendar()));
	}//end testAnEmptyOrInvalidValueHasNoMoment()

	public function testAnOffsetThatIsNoDurationIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		(new TriggerCalendarMoment())->moment('2027-03-31', '30 days', null);
	}//end testAnOffsetThatIsNoDurationIsRefused()
}//end class
