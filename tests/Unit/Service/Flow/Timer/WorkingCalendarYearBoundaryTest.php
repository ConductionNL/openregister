<?php

/**
 * A Dutch working calendar across the 2026/2027 year boundary.
 *
 * 🔴 WHY THIS TEST EXISTS AS ITS OWN FILE. The competitor corpus measured five
 * separate term engines in the fleet and found them DISAGREEING on exactly the
 * moving feasts: Goede Vrijdag, Tweede Paasdag, Hemelvaartsdag and Tweede
 * Pinksterdag. They agree on Nieuwjaarsdag and Kerst, which is why the
 * disagreement survived: the dates that are the same every year test nothing.
 *
 * A term that starts in December and lands in January is the case a tabulated
 * calendar gets wrong in the most expensive way. The engine has to consult TWO
 * years' non-working dates in one walk, and a table that ran out at the end of
 * the year it was written for reports a January full of working days, silently,
 * with no error anywhere. Every case below therefore crosses the boundary or
 * turns on a 2027 feast that only the computus can place.
 *
 * Koningsdag 2026 is 27 April, a MONDAY: the Sunday rule must NOT fire and
 * move it to the 26th. That direction of the rule is the one an implementation
 * gets wrong by applying the shift unconditionally, and nothing in a 2025
 * fixture would catch it, because 27 April 2025 IS a Sunday.
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
use DateTimeZone;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar
 * @covers \OCA\OpenRegister\Service\Flow\Timer\SlaCalculator
 */
class WorkingCalendarYearBoundaryTest extends TestCase {

	/**
	 * A municipal Dutch calendar: the national feasts plus Bevrijdingsdag,
	 * which a municipality that closes on 5 May every year declares as its
	 * own rule rather than as a list of dates.
	 *
	 * @return array<string, mixed> The definition.
	 */
	public static function dutchMunicipal(): array {
		return [
			'slug' => 'gemeente-boundary',
			'title' => 'Gemeente, nationale feestdagen en Bevrijdingsdag',
			'workingWeekdays' => [1, 2, 3, 4, 5],
			'hoursPerWorkingDay' => 8,
			'rules' => [
				['kind' => 'fixed', 'month' => 1, 'day' => 1, 'name' => 'Nieuwjaarsdag'],
				['kind' => 'easter', 'offset' => -2, 'name' => 'Goede Vrijdag'],
				['kind' => 'easter', 'offset' => 1, 'name' => 'Tweede Paasdag'],
				[
					'kind' => 'fixed',
					'month' => 4,
					'day' => 27,
					'name' => 'Koningsdag',
					'observedShift' => ['whenWeekday' => 'sunday', 'days' => -1],
				],
				['kind' => 'fixed', 'month' => 5, 'day' => 5, 'name' => 'Bevrijdingsdag'],
				['kind' => 'easter', 'offset' => 39, 'name' => 'Hemelvaartsdag'],
				['kind' => 'easter', 'offset' => 50, 'name' => 'Tweede Pinksterdag'],
				['kind' => 'fixed', 'month' => 12, 'day' => 25, 'name' => 'Eerste Kerstdag'],
				['kind' => 'fixed', 'month' => 12, 'day' => 26, 'name' => 'Tweede Kerstdag'],
			],
			'exceptions' => [],
		];
	}//end dutchMunicipal()

	/**
	 * The calendar under test.
	 *
	 * @return WorkingCalendar The validated calendar.
	 */
	private static function calendar(): WorkingCalendar {
		return WorkingCalendar::fromArray(definition: self::dutchMunicipal());
	}//end calendar()

	/**
	 * 2026, named date by named date.
	 *
	 * @return void
	 */
	public function testTheDutchFeastsOf2026AreComputedFromEaster(): void {
		$dates = self::calendar()->nonWorkingDates(year: 2026);

		// Easter 2026 is 5 April.
		self::assertSame(expected: '2026-04-05', actual: WorkingCalendar::easterSunday(year: 2026)->format('Y-m-d'));
		self::assertSame(expected: 'Goede Vrijdag', actual: ($dates['2026-04-03'] ?? null));
		self::assertSame(expected: 'Tweede Paasdag', actual: ($dates['2026-04-06'] ?? null));
		self::assertSame(expected: 'Hemelvaartsdag', actual: ($dates['2026-05-14'] ?? null));
		self::assertSame(expected: 'Tweede Pinksterdag', actual: ($dates['2026-05-25'] ?? null));
		self::assertSame(expected: 'Bevrijdingsdag', actual: ($dates['2026-05-05'] ?? null));
		self::assertSame(expected: 'Nieuwjaarsdag', actual: ($dates['2026-01-01'] ?? null));
		self::assertSame(expected: 'Eerste Kerstdag', actual: ($dates['2026-12-25'] ?? null));
		self::assertSame(expected: 'Tweede Kerstdag', actual: ($dates['2026-12-26'] ?? null));
		self::assertCount(expectedCount: 9, haystack: $dates);
	}//end testTheDutchFeastsOf2026AreComputedFromEaster()

	/**
	 * Koningsdag 2026 is the 27th, NOT the 26th: the shift is conditional.
	 *
	 * @return void
	 */
	public function testKoningsdag2026StaysOnTheTwentySeventh(): void {
		self::assertSame(expected: '1', actual: (new DateTimeImmutable('2026-04-27'))->format('N'), message: '27 April 2026 is a Monday');

		$dates = self::calendar()->nonWorkingDates(year: 2026);
		self::assertSame(expected: 'Koningsdag', actual: ($dates['2026-04-27'] ?? null));
		self::assertArrayNotHasKey(key: '2026-04-26', array: $dates, message: 'the Sunday rule must not fire on a Monday');
	}//end testKoningsdag2026StaysOnTheTwentySeventh()

	/**
	 * 2027, whose Easter is eight days earlier than 2026's. A table written
	 * for 2026 and reused would place every moving feast wrong.
	 *
	 * @return void
	 */
	public function testTheDutchFeastsOf2027MoveWithEaster(): void {
		$dates = self::calendar()->nonWorkingDates(year: 2027);

		// Easter 2027 is 28 March.
		self::assertSame(expected: '2027-03-28', actual: WorkingCalendar::easterSunday(year: 2027)->format('Y-m-d'));
		self::assertSame(expected: 'Goede Vrijdag', actual: ($dates['2027-03-26'] ?? null));
		self::assertSame(expected: 'Tweede Paasdag', actual: ($dates['2027-03-29'] ?? null));
		self::assertSame(expected: 'Hemelvaartsdag', actual: ($dates['2027-05-06'] ?? null));
		self::assertSame(expected: 'Tweede Pinksterdag', actual: ($dates['2027-05-17'] ?? null));
		self::assertSame(expected: 'Bevrijdingsdag', actual: ($dates['2027-05-05'] ?? null));
		self::assertSame(expected: 'Koningsdag', actual: ($dates['2027-04-27'] ?? null), message: '27 April 2027 is a Tuesday');
		self::assertCount(expectedCount: 9, haystack: $dates);
	}//end testTheDutchFeastsOf2027MoveWithEaster()

	/**
	 * A term armed in December 2026 and landing in January 2027.
	 *
	 * @return void
	 */
	public function testATermCrossesTheYearBoundary(): void {
		$calculator = new SlaCalculator();
		$calendar = self::calendar();
		$utc = new DateTimeZone('UTC');

		// Wed 23 Dec 09:00 + 8 working days. Christmas takes two days, New
		// Year's Day one, and four weekend days fall in between.
		$landing = $calculator->add(
			from: new DateTimeImmutable('2026-12-23 09:00:00', $utc),
			value: 8.0,
			unit: SlaCalculator::UNIT_BUSINESS_DAYS,
			calendar: $calendar
		);
		self::assertSame(expected: '2027-01-06 09:00:00', actual: $landing->format('Y-m-d H:i:s'));

		// And the measurement is its own inverse across the boundary.
		$measured = $calculator->measure(
			from: new DateTimeImmutable('2026-12-23 09:00:00', $utc),
			to: $landing,
			unit: SlaCalculator::UNIT_BUSINESS_DAYS,
			calendar: $calendar
		);
		self::assertEqualsWithDelta(expected: 8.0, actual: $measured, delta: 0.0001);

		// Wed 30 Dec 09:00 + 5, which consumes New Year's Day.
		$overNewYear = $calculator->add(
			from: new DateTimeImmutable('2026-12-30 09:00:00', $utc),
			value: 5.0,
			unit: SlaCalculator::UNIT_BUSINESS_DAYS,
			calendar: $calendar
		);
		self::assertSame(expected: '2027-01-07 09:00:00', actual: $overNewYear->format('Y-m-d H:i:s'));
	}//end testATermCrossesTheYearBoundary()

	/**
	 * Each 2027 moving feast, pinned by a term that has to step over it.
	 *
	 * A wrong Easter shows up here as a landing one working day early, which
	 * is precisely the size of error that reaches a citizen as a term missed
	 * by a day and reaches nobody as a test failure anywhere else.
	 *
	 * @return array<string, array{string, float, string}> Start, days, landing.
	 */
	public static function feastProvider(): array {
		return [
			'over Goede Vrijdag and Tweede Paasdag 2027' => ['2027-03-24 09:00:00', 5.0, '2027-04-02 09:00:00'],
			'over Koningsdag 2027' => ['2027-04-26 09:00:00', 4.0, '2027-05-03 09:00:00'],
			'over Bevrijdingsdag and Hemelvaartsdag 2027' => ['2027-05-03 09:00:00', 5.0, '2027-05-12 09:00:00'],
			'over Tweede Pinksterdag 2027' => ['2027-05-14 09:00:00', 2.0, '2027-05-19 09:00:00'],
		];
	}//end feastProvider()

	/**
	 * One term, one feast it must step over.
	 *
	 * @param string $start The start instant.
	 * @param float $days Working days to add.
	 * @param string $expected The landing instant.
	 *
	 * @return void
	 *
	 * @dataProvider feastProvider
	 */
	public function testATermStepsOverEach2027Feast(string $start, float $days, string $expected): void {
		$landing = (new SlaCalculator())->add(
			from: new DateTimeImmutable($start, new DateTimeZone('UTC')),
			value: $days,
			unit: SlaCalculator::UNIT_BUSINESS_DAYS,
			calendar: self::calendar()
		);

		self::assertSame(expected: $expected, actual: $landing->format('Y-m-d H:i:s'));
	}//end testATermStepsOverEach2027Feast()

	/**
	 * A one-off closure applies to its own year and no other.
	 *
	 * @return void
	 */
	public function testAOneOffClosureDoesNotBleedIntoTheNextYear(): void {
		$definition = self::dutchMunicipal();
		$definition['exceptions'] = [['date' => '2027-05-05', 'name' => 'Lokale sluitingsdag']];
		$calendar = WorkingCalendar::fromArray(definition: $definition);

		// 5 May is already a rule here, so the exception names the day rather
		// than adding one: the count stays at nine.
		self::assertCount(expectedCount: 9, haystack: $calendar->nonWorkingDates(year: 2027));

		$definition['exceptions'] = [['date' => '2027-11-02', 'name' => 'Lokale sluitingsdag']];
		$calendar = WorkingCalendar::fromArray(definition: $definition);
		self::assertArrayHasKey(key: '2027-11-02', array: $calendar->nonWorkingDates(year: 2027));
		self::assertArrayNotHasKey(key: '2027-11-02', array: $calendar->nonWorkingDates(year: 2026));
		self::assertCount(expectedCount: 9, haystack: $calendar->nonWorkingDates(year: 2026));
	}//end testAOneOffClosureDoesNotBleedIntoTheNextYear()
}//end class
