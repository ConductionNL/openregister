<?php

/**
 * Business days are walked in the calendar's own zone (calendar-time-zone).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/calendar-time-zone/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\Flow\Timer\SlaAddition;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\SlaCalculator
 * @covers \OCA\OpenRegister\Service\Flow\Timer\SlaAddition
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar
 * @uses \OCA\OpenRegister\Service\Flow\Timer\ServiceHours
 * @uses \OCA\OpenRegister\Service\Flow\Timer\SlaDeclaration
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingDayRoll
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarDefinition
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarRules
 * @uses \OCA\OpenRegister\Service\Flow\Timer\CalendarRoll
 * @uses \OCA\OpenRegister\Service\Flow\Timer\MomentShift
 * @uses \OCA\OpenRegister\Service\Flow\Timer\RollRule
 * @uses \OCA\OpenRegister\Service\Flow\Timer\ServiceHoursDeclaration
 * @uses \OCA\OpenRegister\Service\Flow\Timer\UnitConversion
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarHolidays
 * @uses \OCA\OpenRegister\Service\Flow\Timer\CalendarSpan
 */
class SlaCalculatorTimeZoneTest extends TestCase {

	private SlaCalculator $calculator;

	private WorkingCalendar $calendar;

	protected function setUp(): void {
		parent::setUp();
		$this->calculator = new SlaCalculator();
		$this->calendar   = WorkingCalendar::fromArray(definition: WorkingCalendarTest::nlNational());
	}//end setUp()

	/**
	 * Add business days to an instant given in UTC.
	 *
	 * @param string    $utc   The start instant, in UTC.
	 * @param int|float $value The business days.
	 *
	 * @return DateTimeImmutable The landing instant.
	 */
	private function addBusinessDays(string $utc, int|float $value): DateTimeImmutable {
		$start = new DateTimeImmutable($utc, new DateTimeZone('UTC'));

		return DateTimeImmutable::createFromInterface(
			$this->calculator->add(new SlaAddition(start: $start, value: $value, unit: 'businessDays', calendar: $this->calendar))
		);
	}//end addBusinessDays()

	public function testALateFridayEveningAnchorCountsFromFridayInAmsterdamNotFromTheUtcDate(): void {
		// 2027-03-12 23:30 in Amsterdam is a Friday; in UTC it is still 22:30 that Friday,
		// and walking the UTC date would land a day early, on Sunday.
		$landing = $this->addBusinessDays(utc: '2027-03-12T22:30:00Z', value: 1);

		self::assertSame('2027-03-15T22:30:00+00:00', $landing->setTimezone(new DateTimeZone('UTC'))->format('c'));
		self::assertSame('2027-03-15 23:30 Monday', $landing->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('Y-m-d H:i l'));
	}//end testALateFridayEveningAnchorCountsFromFridayInAmsterdamNotFromTheUtcDate()

	public function testAWalkAcrossTheEndOfSummerTimeKeepsTheLocalClockTime(): void {
		// Friday 23 October 2026 10:00 CEST (08:00Z); summer time ends on Sunday 25 October,
		// so Monday 10:00 is CET and 09:00Z.
		$landing = $this->addBusinessDays(utc: '2026-10-23T08:00:00Z', value: 1);

		self::assertSame('2026-10-26 10:00 Monday', $landing->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('Y-m-d H:i l'));
		self::assertSame('2026-10-26T09:00:00+00:00', $landing->setTimezone(new DateTimeZone('UTC'))->format('c'));
	}//end testAWalkAcrossTheEndOfSummerTimeKeepsTheLocalClockTime()

	public function testTheCalendarsZoneDecidesWhichDayAnInstantFallsOn(): void {
		// Monday 2027-03-15 00:30 in Amsterdam is Sunday 23:30 in UTC.
		$landing = $this->addBusinessDays(utc: '2027-03-14T23:30:00Z', value: 1);
		self::assertSame('2027-03-16 00:30 Tuesday', $landing->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('Y-m-d H:i l'));

		// The same calendar read in UTC sees a Sunday, rolls to Monday and lands a day later.
		$this->calendar = WorkingCalendar::fromArray(definition: ['timezone' => 'UTC'] + WorkingCalendarTest::nlNational());
		$utcLanding     = $this->addBusinessDays(utc: '2027-03-14T23:30:00Z', value: 1);
		self::assertNotSame($landing->getTimestamp(), $utcLanding->getTimestamp());
	}//end testTheCalendarsZoneDecidesWhichDayAnInstantFallsOn()
}//end class
