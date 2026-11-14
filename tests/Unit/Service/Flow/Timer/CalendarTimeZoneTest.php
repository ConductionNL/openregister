<?php

/**
 * Working calendars count their days in their own time zone.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Timer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTimeImmutable;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\CalendarSpan;
use OCA\OpenRegister\Service\Flow\Timer\SlaAddition;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar
 * @covers \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarDefinition
 * @covers \OCA\OpenRegister\Service\Flow\Timer\SlaCalculator
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\CalendarSpan
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\MomentShift
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\ServiceHours
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\ServiceHoursDeclaration
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\SlaAddition
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\SlaDeclaration
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarHolidays
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarRules
 */
class CalendarTimeZoneTest extends TestCase {

	private static function amsterdam(): WorkingCalendar {
		return WorkingCalendar::fromArray(WorkingCalendarTest::nlNational());
	}

	private static function add(string $start, float $days, WorkingCalendar $calendar): DateTimeImmutable {
		return (new SlaCalculator())->add(new SlaAddition(start: new DateTimeImmutable($start), value: $days, unit: 'businessDays', calendar: $calendar));
	}

	public function testALateEveningAnchorCountsFromTheLocalDate(): void {
		$due = self::add('2027-03-12T23:30:00+01:00', 1, self::amsterdam());

		$this->assertSame('2027-03-15T23:30:00+01:00', $due->format(DATE_ATOM));
	}

	public function testTheSameInstantGivenInUtcLandsOnTheSameMoment(): void {
		$due = self::add('2027-03-12T22:30:00+00:00', 1, self::amsterdam());

		$this->assertSame('2027-03-15T22:30:00+00:00', $due->format(DATE_ATOM));
	}

	public function testADstBoundaryInsideTheWalkKeepsTheLocalTime(): void {
		// Summer time ends on Sunday 31 October 2027, a 25-hour day the walk crosses.
		$due = self::add('2027-10-29T23:30:00+02:00', 1, self::amsterdam());

		$this->assertSame('2027-11-01T22:30:00+00:00', $due->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM));
		$this->assertSame('2027-11-01 23:30', $due->setTimezone(new \DateTimeZone('Europe/Amsterdam'))->format('Y-m-d H:i'));
	}

	public function testAWorkingDayIsReadOnTheCalendarsClock(): void {
		$calendar = self::amsterdam();

		$this->assertTrue($calendar->isWorkingDay(new DateTimeImmutable('2027-03-12T22:30:00+00:00')));
		$this->assertFalse($calendar->isWorkingDay(new DateTimeImmutable('2027-03-12T23:30:00+00:00')));
		$this->assertSame('2027-03-13T00:30:00+01:00', $calendar->local(new DateTimeImmutable('2027-03-12T23:30:00+00:00'))->format(DATE_ATOM));
	}

	public function testACalendarWithoutAZoneReadsTheMomentsOwnClock(): void {
		$calendar = WorkingCalendar::fromArray(['slug' => 'plain', 'hoursPerWorkingDay' => 8, 'workingWeekdays' => [1, 2, 3, 4, 5]]);
		$saturday = new DateTimeImmutable('2026-10-17T00:00:00+02:00');

		$this->assertSame('UTC', $calendar->getTimezone());
		$this->assertFalse($calendar->isWorkingDay($saturday));
		$this->assertSame('2026-10-17T00:00:00+02:00', $calendar->local($saturday)->format(DATE_ATOM));
	}

	public function testBusinessDaysAreMeasuredBetweenLocalMidnights(): void {
		$days = (new SlaCalculator())->measure(
			new CalendarSpan(from: new DateTimeImmutable('2027-03-12T22:30:00+00:00'), to: new DateTimeImmutable('2027-03-15T22:30:00+00:00'), calendar: self::amsterdam()),
			'businessDays'
		);

		$this->assertEqualsWithDelta(1.0, $days, 0.0001);
	}

	public function testAnUnknownZoneIsRefused(): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage("declares timezone 'Europe/Nowhere', which is not an IANA zone name.");

		WorkingCalendar::fromArray(['slug' => 'x', 'hoursPerWorkingDay' => 8, 'workingWeekdays' => [1, 2, 3, 4, 5], 'timezone' => 'Europe/Nowhere']);
	}
}
