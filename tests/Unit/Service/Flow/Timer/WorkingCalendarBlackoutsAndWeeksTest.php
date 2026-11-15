<?php

/**
 * Blackout periods and the declared first week of the year on a working
 * calendar: a blackout refuses a booking of its kind, naming the period, and
 * never stops a term; week numbers follow the declared first week; malformed
 * declarations are refused when the calendar is read.
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
 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-calendar-declares-blackout-periods-and-its-first-week-of-the-year-req-wca-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\CalendarBlackoutRefusal;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\CalendarBlackouts
 * @covers \OCA\OpenRegister\Service\Flow\Timer\CalendarBlackoutRefusal
 * @covers \OCA\OpenRegister\Service\Flow\Timer\CalendarWeekNumbering
 * @covers \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar
 * @covers \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarDefinition
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\ServiceHours
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\ServiceHoursDeclaration
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarHolidays
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarRules
 */
class WorkingCalendarBlackoutsAndWeeksTest extends TestCase {

	private const STEMBUS = ['name' => 'Stembusperiode', 'from' => '2027-03-08', 'to' => '2027-03-19', 'refuses' => ['hearing']];

	/**
	 * A five-day calendar, with extra declarations.
	 *
	 * @param array<string, mixed> $extra Extra keys.
	 *
	 * @return WorkingCalendar
	 */
	private function calendar(array $extra = []): WorkingCalendar {
		return WorkingCalendar::fromArray(
			array_merge(
				['slug' => 'gemeente-x', 'workingWeekdays' => [1, 2, 3, 4, 5], 'hoursPerWorkingDay' => 8, 'timezone' => 'Europe/Amsterdam'],
				$extra
			)
		);
	}//end calendar()

	private function at(string $moment): DateTimeImmutable {
		return new DateTimeImmutable($moment, new DateTimeZone('Europe/Amsterdam'));
	}//end at()

	public function testAHearingInsideTheBlackoutIsRefusedNamingThePeriod(): void {
		$calendar = $this->calendar(['blackouts' => [self::STEMBUS]]);

		try {
			$calendar->assertBookable($this->at('2027-03-10 10:00'), 'hearing');
			$this->fail('a hearing was booked inside the blackout');
		} catch (CalendarBlackoutRefusal $refused) {
			$this->assertSame("A hearing cannot be booked in 'Stembusperiode' (2027-03-08 to 2027-03-19) on working calendar 'gemeente-x'.", $refused->getMessage());
			$this->assertSame('Stembusperiode', $refused->period['name']);
			$this->assertSame('hearing', $refused->kind);
		}
	}

	public function testBothBoundsAreInsideAndTheDaysAroundAreNot(): void {
		$calendar = $this->calendar(['blackouts' => [self::STEMBUS]]);

		$this->assertNotNull($calendar->blackoutRefusing($this->at('2027-03-08 00:00'), 'hearing'));
		$this->assertNotNull($calendar->blackoutRefusing($this->at('2027-03-19 23:59'), 'hearing'));
		$this->assertNull($calendar->blackoutRefusing($this->at('2027-03-07 23:59'), 'hearing'));
		$this->assertNull($calendar->blackoutRefusing($this->at('2027-03-20 00:00'), 'hearing'));
	}

	public function testTheDayIsTheCalendarsDayNotTheCallers(): void {
		$calendar = $this->calendar(['blackouts' => [self::STEMBUS]]);

		// 23:30 UTC on the 7th is already the 8th in Amsterdam.
		$this->assertNotNull($calendar->blackoutRefusing(new DateTimeImmutable('2027-03-07 23:30', new DateTimeZone('UTC')), 'hearing'));
	}

	public function testOtherKindsOfBookingAreNotRefused(): void {
		$calendar = $this->calendar(['blackouts' => [self::STEMBUS]]);

		$calendar->assertBookable($this->at('2027-03-10 10:00'), 'counter-appointment');
		$this->assertNull($calendar->blackoutRefusing($this->at('2027-03-10 10:00'), 'counter-appointment'));
	}

	public function testABlackoutDoesNotStopTheClock(): void {
		$with    = $this->calendar(['blackouts' => [self::STEMBUS]]);
		$without = $this->calendar();

		for ($day = 8; $day <= 19; $day++) {
			$moment = $this->at(sprintf('2027-03-%02d 12:00', $day));
			$this->assertSame($without->isWorkingDay($moment), $with->isWorkingDay($moment), 'day ' . $day);
		}

		$this->assertTrue($with->isWorkingDay($this->at('2027-03-10 12:00')));
		$this->assertSame($without->nonWorkingDates(2027), $with->nonWorkingDates(2027));
	}

	public function testACalendarWithoutBlackoutsRefusesNothing(): void {
		$calendar = $this->calendar();

		$this->assertSame([], $calendar->getBlackouts());
		$calendar->assertBookable($this->at('2027-03-10 10:00'), 'hearing');
		$this->assertNull($calendar->blackoutRefusing($this->at('2027-03-10 10:00'), 'hearing'));
	}

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function malformedBlackouts(): array {
		return [
			'not a list' => [['x' => self::STEMBUS], 'not a list'],
			'no name' => [[array_merge(self::STEMBUS, ['name' => ' '])], 'has no name'],
			'bad date' => [[array_merge(self::STEMBUS, ['from' => '2027-02-30'])], "'from' that is not a Y-m-d date"],
			'ends before it starts' => [[array_merge(self::STEMBUS, ['to' => '2027-03-01'])], 'ends (2027-03-01) before it starts (2027-03-08)'],
			'refuses nothing' => [[array_merge(self::STEMBUS, ['refuses' => []])], 'names no kind of booking'],
			'refuses a blank kind' => [[array_merge(self::STEMBUS, ['refuses' => ['']])], 'not a non-empty string'],
		];
	}

	#[DataProvider('malformedBlackouts')]
	public function testAMalformedBlackoutIsRefused(mixed $blackouts, string $message): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage($message);
		$this->calendar(['blackouts' => $blackouts]);
	}

	/**
	 * Day, declaration, expected week-year and week.
	 *
	 * 2027 starts on a Friday: under ISO 1 January is in week 53 of 2026; when
	 * week 1 holds 1 January it is week 1; when week 1 must be whole it starts
	 * on Monday 4 January.
	 *
	 * @return array<string, array{0: string, 1: string|null, 2: int, 3: int}>
	 */
	public static function weeks(): array {
		return [
			'iso default, 1 Jan 2027' => ['2027-01-01', null, 2026, 53],
			'iso, 4 Jan 2027' => ['2027-01-04', 'iso', 2027, 1],
			'iso, 31 Dec 2024 is week 1 of 2025' => ['2024-12-31', 'iso', 2025, 1],
			'containsJanuary1, 1 Jan 2027' => ['2027-01-01', 'containsJanuary1', 2027, 1],
			'containsJanuary1, 28 Dec 2026 is week 1 of 2027' => ['2026-12-28', 'containsJanuary1', 2027, 1],
			'containsJanuary1, 4 Jan 2027' => ['2027-01-04', 'containsJanuary1', 2027, 2],
			'firstFullWeek, 3 Jan 2027 is last week of 2026' => ['2027-01-03', 'firstFullWeek', 2026, 52],
			'firstFullWeek, 4 Jan 2027' => ['2027-01-04', 'firstFullWeek', 2027, 1],
			'firstFullWeek, 1 Jan 2024 is a Monday' => ['2024-01-01', 'firstFullWeek', 2024, 1],
			'iso, mid-year' => ['2027-07-14', 'iso', 2027, 28],
		];
	}

	#[DataProvider('weeks')]
	public function testWeekNumbersFollowTheDeclaredFirstWeek(string $day, ?string $declared, int $year, int $week): void {
		$extra = [];
		if ($declared !== null) {
			$extra['firstWeekOfYear'] = $declared;
		}

		$calendar = $this->calendar($extra);

		$this->assertSame(['year' => $year, 'week' => $week], $calendar->weekOf($this->at($day . ' 12:00')));
		$this->assertSame($declared ?? 'iso', $calendar->getFirstWeekOfYear());
	}

	public function testIsoAgreesWithPhpsOwnIsoWeekAllYear(): void {
		$calendar = $this->calendar(['firstWeekOfYear' => 'iso']);
		$day      = $this->at('2026-12-20 12:00');
		for ($i = 0; $i < 400; $i++) {
			$this->assertSame(['year' => (int) $day->format('o'), 'week' => (int) $day->format('W')], $calendar->weekOf($day), $day->format('Y-m-d'));
			$day = $day->modify('+1 day');
		}
	}

	public function testAnUnknownFirstWeekIsRefused(): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage("declares firstWeekOfYear 'sunday'");
		$this->calendar(['firstWeekOfYear' => 'sunday']);
	}
}
