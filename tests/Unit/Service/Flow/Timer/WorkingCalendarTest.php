<?php

/**
 * The seeded national calendar is computed, not tabulated: correct for years
 * no table was ever written for, with Koningsdag observed on the Saturday
 * when the 27th is a Sunday, and refusing a calendar that is only a list of
 * dates.
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
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar
 */
class WorkingCalendarTest extends TestCase {

	/**
	 * The seeded `nl-national` definition, read from the shipped descriptor.
	 *
	 * @return array<string, mixed> The definition.
	 */
	public static function nlNational(): array {
		$data = json_decode((string)file_get_contents(__DIR__ . '/../../../../../lib/Settings/flow_timer_register.json'), true);
		foreach ($data['components']['objects'] as $object) {
			if (($object['slug'] ?? '') === 'nl-national') {
				unset($object['@self']);
				return $object;
			}
		}

		self::fail('nl-national is not in the descriptor');
	}//end nlNational()

	/**
	 * Easter Sunday for years well past any table anyone wrote.
	 *
	 * @return array<string, array{int, string}> Year and expected date.
	 */
	public static function easterProvider(): array {
		return [
			'2026' => [2026, '2026-04-05'],
			'2030' => [2030, '2030-04-21'],
			'2035' => [2035, '2035-03-25'],
			'2038' => [2038, '2038-04-25'],
			'2049' => [2049, '2049-04-18'],
		];
	}//end easterProvider()

	/**
	 * @dataProvider easterProvider
	 */
	public function testEasterIsComputedForFutureYears(int $year, string $expected): void {
		self::assertSame($expected, WorkingCalendar::easterSunday(year: $year)->format('Y-m-d'));
	}//end testEasterIsComputedForFutureYears()

	public function testNationalCalendarIsCorrectMoreThanFiveYearsOut(): void {
		$calendar = WorkingCalendar::fromArray(definition: self::nlNational());
		$dates = $calendar->nonWorkingDates(year: 2035);

		// Easter 2035 is 25 March: Goede Vrijdag 23 March, Tweede Paasdag 26 March,
		// Hemelvaart 3 May, Tweede Pinksterdag 14 May.
		self::assertArrayHasKey('2035-03-23', $dates);
		self::assertArrayHasKey('2035-03-26', $dates);
		self::assertArrayHasKey('2035-05-03', $dates);
		self::assertArrayHasKey('2035-05-14', $dates);
		self::assertArrayHasKey('2035-01-01', $dates);
		self::assertArrayHasKey('2035-04-27', $dates);
		self::assertArrayHasKey('2035-12-25', $dates);
		self::assertArrayHasKey('2035-12-26', $dates);
		self::assertCount(8, $dates, 'no year resolves to weekends-only through an exhausted table');
	}//end testNationalCalendarIsCorrectMoreThanFiveYearsOut()

	public function testKoningsdagOnASundayIsObservedOnTheSaturday(): void {
		$calendar = WorkingCalendar::fromArray(definition: self::nlNational());
		// 27 April 2025 is a Sunday.
		self::assertSame('7', (new DateTimeImmutable('2025-04-27'))->format('N'));
		$dates = $calendar->nonWorkingDates(year: 2025);
		self::assertArrayHasKey('2025-04-26', $dates);
		self::assertSame('Koningsdag', $dates['2025-04-26']);
		self::assertArrayNotHasKey('2025-04-27', $dates);

		// 27 April 2026 is a Monday: observed on the day itself.
		self::assertArrayHasKey('2026-04-27', $calendar->nonWorkingDates(year: 2026));
	}//end testKoningsdagOnASundayIsObservedOnTheSaturday()

	public function testWorkingDayHonoursWeekendsAndHolidays(): void {
		$calendar = WorkingCalendar::fromArray(definition: self::nlNational());
		$tz = new DateTimeZone('Europe/Amsterdam');
		self::assertTrue($calendar->isWorkingDay(new DateTimeImmutable('2026-09-01 10:00', $tz)), 'Tuesday');
		self::assertFalse($calendar->isWorkingDay(new DateTimeImmutable('2026-09-05 10:00', $tz)), 'Saturday');
		self::assertFalse($calendar->isWorkingDay(new DateTimeImmutable('2026-09-06 10:00', $tz)), 'Sunday');
		self::assertFalse($calendar->isWorkingDay(new DateTimeImmutable('2026-12-25 10:00', $tz)), 'Eerste Kerstdag');
		self::assertFalse($calendar->isWorkingDay(new DateTimeImmutable('2026-05-14 10:00', $tz)), 'Hemelvaart 2026');
		self::assertSame(8.0, $calendar->getHoursPerWorkingDay());
		self::assertSame('nl-national', $calendar->getSlug());
		self::assertNull($calendar->getOrganisation());
	}//end testWorkingDayHonoursWeekendsAndHolidays()

	public function testEnumeratedOnlyCalendarIsRefused(): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage('would expire after \'2027-12-26\'');
		WorkingCalendar::fromArray(
			definition: [
				'slug' => 'tabulated',
				'workingWeekdays' => [1, 2, 3, 4, 5],
				'hoursPerWorkingDay' => 8,
				'rules' => [],
				'exceptions' => ['2027-12-25', '2027-12-26', '2026-01-01'],
			]
		);
	}//end testEnumeratedOnlyCalendarIsRefused()

	public function testExceptionsAlongsideRulesAreAccepted(): void {
		$definition = self::nlNational();
		$definition['exceptions'] = [['date' => '2026-10-05', 'name' => 'Lokale sluitingsdag']];
		$definition['organisation'] = 'org-1';
		$calendar = WorkingCalendar::fromArray(definition: $definition);
		self::assertArrayHasKey('2026-10-05', $calendar->nonWorkingDates(year: 2026));
		self::assertArrayNotHasKey('2026-10-05', $calendar->nonWorkingDates(year: 2027));
		self::assertSame('org-1', $calendar->getOrganisation());
	}//end testExceptionsAlongsideRulesAreAccepted()

	public function testHoursPerWorkingDayIsRequired(): void {
		$definition = self::nlNational();
		unset($definition['hoursPerWorkingDay']);
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage('hoursPerWorkingDay');
		WorkingCalendar::fromArray(definition: $definition);
	}//end testHoursPerWorkingDayIsRequired()

	public function testUnknownRuleKindIsRefused(): void {
		$definition = self::nlNational();
		$definition['rules'][] = ['kind' => 'lunar', 'name' => 'x'];
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage("unknown kind 'lunar'");
		WorkingCalendar::fromArray(definition: $definition);
	}//end testUnknownRuleKindIsRefused()

	public function testWeekdaysMustBeDeclared(): void {
		$definition = self::nlNational();
		unset($definition['workingWeekdays']);
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage('workingWeekdays');
		WorkingCalendar::fromArray(definition: $definition);
	}//end testWeekdaysMustBeDeclared()

	public function testObservedShiftKindAndMalformedShiftAreHandled(): void {
		$definition = self::nlNational();
		$definition['rules'] = [['kind' => 'observedShift', 'month' => 4, 'day' => 27, 'whenWeekday' => 7, 'days' => -1, 'name' => 'K']];
		$calendar = WorkingCalendar::fromArray(definition: $definition);
		self::assertArrayHasKey('2025-04-26', $calendar->nonWorkingDates(year: 2025));

		$definition['rules'] = [['kind' => 'fixed', 'month' => 4, 'day' => 27, 'observedShift' => ['whenWeekday' => 'funday', 'days' => -1]]];
		$this->expectException(FlowTimerValidationException::class);
		WorkingCalendar::fromArray(definition: $definition);
	}//end testObservedShiftKindAndMalformedShiftAreHandled()
}//end class
