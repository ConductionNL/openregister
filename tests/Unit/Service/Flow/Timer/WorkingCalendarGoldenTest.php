<?php

/**
 * Golden test for WorkingCalendar: what fromArray() accepts, how it
 * normalises, what it refuses (with the exact message), and which dates
 * each accepted calendar computes as non-working. Every date is fixed;
 * nothing reads the clock. Re-record with UPDATE_GOLDEN=1.
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

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar
 * @uses \OCA\OpenRegister\Service\Flow\Timer\ServiceHours
 * @covers \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarDefinition
 * @covers \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarRules
 * @uses \OCA\OpenRegister\Service\Flow\Timer\ServiceHoursDeclaration
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarHolidays
 */
class WorkingCalendarGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/working-calendar-golden.json';

	private const BASE = ['slug' => 'g', 'hoursPerWorkingDay' => 8, 'workingWeekdays' => [1, 2, 3, 4, 5]];

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function definitions(): array {
		$base = self::BASE;
		$fixed = ['kind' => 'fixed', 'month' => 12, 'day' => 25, 'name' => 'Kerst'];
		return [
			'nl-national' => WorkingCalendarTest::nlNational(),
			'minimal' => $base,
			'slug trimmed, organisation trimmed' => ($base + ['organisation' => '  org-1  ']) + ['slug' => ' x '],
			'blank slug' => ['slug' => '  '] + $base,
			'missing slug' => ['hoursPerWorkingDay' => 8, 'workingWeekdays' => [1]],
			'organisation blank' => $base + ['organisation' => '   '],
			'hours string' => ['hoursPerWorkingDay' => '7.5'] + $base,
			'hours zero' => ['hoursPerWorkingDay' => 0] + $base,
			'hours 24' => ['hoursPerWorkingDay' => 24] + $base,
			'hours 25' => ['hoursPerWorkingDay' => 25] + $base,
			'hours text' => ['hoursPerWorkingDay' => 'eight'] + $base,
			'hours missing' => ['slug' => 'g', 'workingWeekdays' => [1]],
			'weekdays missing' => ['slug' => 'g', 'hoursPerWorkingDay' => 8],
			'weekdays empty' => ['workingWeekdays' => []] + $base,
			'weekdays not array' => ['workingWeekdays' => '1,2'] + $base,
			'weekdays duplicates' => ['workingWeekdays' => [5, 1, 5, 3, 1]] + $base,
			'weekday zero' => ['workingWeekdays' => [0, 1]] + $base,
			'weekday eight' => ['workingWeekdays' => [1, 8]] + $base,
			'weekday string' => ['workingWeekdays' => ['1']] + $base,
			'rules not array' => $base + ['rules' => 'x'],
			'rule unknown kind' => $base + ['rules' => [['kind' => 'lunar']]],
			'rule no kind' => $base + ['rules' => [['month' => 1, 'day' => 1]]],
			'rule not array' => $base + ['rules' => ['fixed']],
			'easter rule' => $base + ['rules' => [['kind' => 'easter', 'offset' => -2, 'name' => 'Goede Vrijdag'], ['kind' => 'easter', 'offset' => 39]]],
			'easter no offset' => $base + ['rules' => [['kind' => 'easter']]],
			'easter string offset' => $base + ['rules' => [['kind' => 'easter', 'offset' => '1']]],
			'fixed rule' => $base + ['rules' => [$fixed, ['kind' => 'fixed', 'month' => 1, 'day' => 1]]],
			'fixed month 13' => $base + ['rules' => [['kind' => 'fixed', 'month' => 13, 'day' => 1]]],
			'fixed month 0' => $base + ['rules' => [['kind' => 'fixed', 'month' => 0, 'day' => 1]]],
			'fixed day 32' => $base + ['rules' => [['kind' => 'fixed', 'month' => 1, 'day' => 32]]],
			'fixed day 0' => $base + ['rules' => [['kind' => 'fixed', 'month' => 1, 'day' => 0]]],
			'fixed day string' => $base + ['rules' => [['kind' => 'fixed', 'month' => 1, 'day' => '1']]],
			'fixed with shift by name' => $base + ['rules' => [['kind' => 'fixed', 'month' => 4, 'day' => 27, 'name' => 'Koningsdag', 'observedShift' => ['whenWeekday' => 'Sunday', 'days' => -1]]]],
			'fixed with shift by number' => $base + ['rules' => [['kind' => 'fixed', 'month' => 4, 'day' => 27, 'observedShift' => ['whenWeekday' => 6, 'days' => 2]]]],
			'fixed with non-array shift' => $base + ['rules' => [['kind' => 'fixed', 'month' => 4, 'day' => 27, 'observedShift' => 'sunday']]],
			'observedShift flat' => $base + ['rules' => [['kind' => 'observedShift', 'month' => 5, 'day' => 5, 'whenWeekday' => 'saturday', 'days' => -1]]],
			'observedShift nested' => $base + ['rules' => [['kind' => 'observedShift', 'month' => 5, 'day' => 5, 'observedShift' => ['whenWeekday' => 7, 'days' => 1]]]],
			'observedShift missing days' => $base + ['rules' => [['kind' => 'observedShift', 'month' => 5, 'day' => 5, 'whenWeekday' => 'saturday']]],
			'observedShift string days' => $base + ['rules' => [['kind' => 'observedShift', 'month' => 5, 'day' => 5, 'whenWeekday' => 'saturday', 'days' => '1']]],
			'observedShift bad weekday name' => $base + ['rules' => [['kind' => 'observedShift', 'month' => 5, 'day' => 5, 'whenWeekday' => 'funday', 'days' => 1]]],
			'observedShift weekday 8' => $base + ['rules' => [['kind' => 'observedShift', 'month' => 5, 'day' => 5, 'whenWeekday' => 8, 'days' => 1]]],
			'observedShift weekday 0' => $base + ['rules' => [['kind' => 'observedShift', 'month' => 5, 'day' => 5, 'whenWeekday' => 0, 'days' => 1]]],
			'observedShift weekday missing' => $base + ['rules' => [['kind' => 'observedShift', 'month' => 5, 'day' => 5, 'days' => 1]]],
			'exceptions only' => $base + ['exceptions' => ['2027-03-01', '2026-01-02']],
			'exceptions with rules' => $base + ['rules' => [$fixed], 'exceptions' => ['2027-03-01', ['date' => '2026-12-28', 'name' => 'Sluiting'], ['date' => '2025-06-06']]],
			'exceptions not array' => $base + ['rules' => [$fixed], 'exceptions' => '2026-01-01'],
			'exception malformed' => $base + ['rules' => [$fixed], 'exceptions' => ['1-1-2026']],
			'exception object no date' => $base + ['rules' => [$fixed], 'exceptions' => [['name' => 'x']]],
			'exception int' => $base + ['rules' => [$fixed], 'exceptions' => [20260101]],
			'exception duplicate date' => $base + ['rules' => [$fixed], 'exceptions' => [['date' => '2026-05-01', 'name' => 'a'], ['date' => '2026-05-01', 'name' => 'b']]],
			'exception on rule date' => $base + ['rules' => [$fixed], 'exceptions' => [['date' => '2026-12-25', 'name' => 'own']]],
			'dayStartsAt 08:30' => $base + ['dayStartsAt' => '08:30'],
			'dayStartsAt padded' => $base + ['dayStartsAt' => ' 23:59 '],
			'dayStartsAt blank' => $base + ['dayStartsAt' => '  '],
			'dayStartsAt 9' => $base + ['dayStartsAt' => '9'],
			'dayStartsAt 24:00' => $base + ['dayStartsAt' => '24:00'],
			'dayStartsAt int' => $base + ['dayStartsAt' => 9],
			'dayStartsAt array' => $base + ['dayStartsAt' => ['09:00']],
			'dayStartsAt late clamps end' => ['hoursPerWorkingDay' => 12] + $base + ['dayStartsAt' => '18:00'],
			'timezone amsterdam' => $base + ['timezone' => ' Europe/Amsterdam '],
			'timezone blank' => $base + ['timezone' => ''],
			'timezone unknown' => $base + ['timezone' => 'Mars/Olympus'],
			'timezone int' => $base + ['timezone' => 1],
			'service hours' => $base + ['serviceHours' => ['monday' => [['start' => '09:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:30']]]],
			'fault order: rules before exceptions' => $base + ['rules' => 'x', 'exceptions' => 'y'],
			'fault order: service hours before dayStartsAt' => $base + ['serviceHours' => 'x', 'dayStartsAt' => 'x'],
			'fault order: dayStartsAt before timezone' => $base + ['dayStartsAt' => 'x', 'timezone' => 'x'],
			'fault order: weekdays before rules' => ['workingWeekdays' => []] + $base + ['rules' => 'x'],
			'fixed 31 February' => $base + ['rules' => [['kind' => 'fixed', 'month' => 2, 'day' => 31]]],
			'service hours off-day' => $base + ['serviceHours' => ['saturday' => [['start' => '09:00', 'end' => '12:00']]]],
		];
	}//end definitions()

	/**
	 * @param WorkingCalendar $calendar The calendar under test.
	 *
	 * @return array<string, mixed>
	 */
	private static function describe(WorkingCalendar $calendar): array {
		$years = [];
		foreach ([2024, 2025, 2026, 2027, 2028, 2031] as $year) {
			$years[$year] = $calendar->nonWorkingDates(year: $year);
		}

		$days = [];
		foreach (['2026-04-27', '2026-04-25', '2026-12-25', '2027-01-01', '2026-05-04', '2026-05-05', '2026-05-06', '2027-03-01', '2028-04-26'] as $day) {
			$days[$day] = $calendar->isWorkingDay(new DateTimeImmutable($day . ' 12:00:00', new DateTimeZone('UTC')));
		}

		return [
			'slug' => $calendar->getSlug(),
			'organisation' => $calendar->getOrganisation(),
			'weekdays' => $calendar->getWorkingWeekdays(),
			'hours' => $calendar->getHoursPerWorkingDay(),
			'start' => $calendar->getDayStartsAtMinute(),
			'end' => $calendar->getDayEndsAtMinute(),
			'timezone' => $calendar->getTimezone(),
			'serviceHoursDeclared' => $calendar->getServiceHours()->areDeclared(),
			'nonWorking' => $years,
			'isWorkingDay' => $days,
			'nonWorkingAgain2026' => $calendar->nonWorkingDates(year: 2026),
		];
	}//end describe()

	public function testCalendarsMatchGolden(): void {
		$actual = [];
		foreach (self::definitions() as $name => $definition) {
			try {
				$actual[$name] = self::describe(WorkingCalendar::fromArray(definition: $definition));
			} catch (Throwable $error) {
				$actual[$name] = ['error' => get_class($error), 'message' => $error->getMessage()];
			}
		}

		foreach ([1583, 1900, 2000, 2026, 2100, 2400, 4099] as $year) {
			$actual['easter ' . $year] = WorkingCalendar::easterSunday(year: $year)->format('Y-m-d H:i:s e');
		}

		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testCalendarsMatchGolden()
}//end class
