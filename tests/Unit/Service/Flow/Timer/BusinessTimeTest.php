<?php

/**
 * BusinessTime: the Kernel business-time port served by the timers' calculator and calendars.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

use DateTimeImmutable;
use OCA\OpenRegister\Kernel\Contracts\BusinessCalendarInterface;
use OCA\OpenRegister\Service\Flow\Timer\BusinessTime;
use OCA\OpenRegister\Service\Flow\Timer\SlaAddition;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculatorInterface;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/notificatie-engine/spec.md
 */
class BusinessTimeTest extends TestCase {

	private static function calendar(): WorkingCalendar {
		return WorkingCalendar::fromArray(
			definition: ['slug' => 'werkdagen', 'hoursPerWorkingDay' => 8, 'workingWeekdays' => [1, 2, 3, 4, 5], 'dayStartsAt' => '09:00', 'timezone' => 'Europe/Amsterdam']
		);
	}//end calendar()

	public function testTheCalendarIsResolvedForTheOrganisation(): void {
		$calendar  = self::calendar();
		$calendars = $this->createMock(WorkingCalendarService::class);
		$calendars->expects($this->once())->method('resolve')->with(null, 'org-1')->willReturn($calendar);

		$time = new BusinessTime(calendars: $calendars, calculator: new SlaCalculator());

		$this->assertSame($calendar, $time->calendarFor(organisation: 'org-1'));
	}//end testTheCalendarIsResolvedForTheOrganisation()

	public function testBusinessDaysAreCountedOnTheWorkingCalendar(): void {
		$time = new BusinessTime(calendars: $this->createMock(WorkingCalendarService::class), calculator: new SlaCalculator());

		// Friday 12:00 plus one business day skips the weekend.
		$result = $time->add(start: new DateTimeImmutable('2027-03-12T12:00:00+01:00'), value: 1, unit: 'businessDays', calendar: self::calendar());

		$this->assertSame('2027-03-15', $result->setTimezone(new \DateTimeZone('Europe/Amsterdam'))->format('Y-m-d'));
	}//end testBusinessDaysAreCountedOnTheWorkingCalendar()

	public function testTheAmountIsHandedToTheCalculatorUnchanged(): void {
		$start      = new DateTimeImmutable('2027-03-01T00:00:00Z');
		$expected   = new DateTimeImmutable('2027-03-08T00:00:00Z');
		$calendar   = self::calendar();
		$calculator = $this->createMock(SlaCalculatorInterface::class);
		$calculator->expects($this->once())->method('add')->with(
			$this->callback(static fn (SlaAddition $a): bool => $a->start === $start && $a->value === 7.0 && $a->unit === 'calendarDays')
		)->willReturn($expected);

		$time = new BusinessTime(calendars: $this->createMock(WorkingCalendarService::class), calculator: $calculator);

		$this->assertSame($expected, $time->add(start: $start, value: 7, unit: 'calendarDays', calendar: $calendar));
	}//end testTheAmountIsHandedToTheCalculatorUnchanged()

	public function testAForeignCalendarCountsAsNone(): void {
		$time = new BusinessTime(calendars: $this->createMock(WorkingCalendarService::class), calculator: new SlaCalculator());

		$this->expectException(\Throwable::class);
		$time->add(start: new DateTimeImmutable('2027-03-12T12:00:00Z'), value: 1, unit: 'businessDays', calendar: $this->createMock(BusinessCalendarInterface::class));
	}//end testAForeignCalendarCountsAsNone()
}//end class
