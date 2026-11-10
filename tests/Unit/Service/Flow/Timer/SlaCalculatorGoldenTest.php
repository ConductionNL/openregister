<?php

/**
 * Golden test for SlaCalculator: add/sub in every unit and direction (with
 * and without a walk collector), measure, elapsedBusinessHours, convert,
 * roll and the refusals, all on fixed instants; nothing reads the clock.
 * Re-record with UPDATE_GOLDEN=1.
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

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Service\Flow\Timer\RollRule;
use OCA\OpenRegister\Service\Flow\Timer\CalendarSpan;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\UnitConversion;
use OCA\OpenRegister\Service\Flow\Timer\WalkCollector;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\SlaCalculator
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar
 * @uses \OCA\OpenRegister\Service\Flow\Timer\ServiceHours
 * @uses \OCA\OpenRegister\Service\Flow\Timer\ServiceHoursClock
 * @uses \OCA\OpenRegister\Service\Flow\Timer\SlaDeclaration
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WalkCollector
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingDayRoll
 * @covers \OCA\OpenRegister\Service\Flow\Timer\CalendarSpan
 * @covers \OCA\OpenRegister\Service\Flow\Timer\SlaAddition
 */
class SlaCalculatorGoldenTest extends TestCase {

	/**
	 * @var string
	 */
	private const FIXTURE = __DIR__ . '/fixtures/sla-calculator-golden.json';

	/**
	 * @return array<string, WorkingCalendar|null>
	 */
	private static function calendars(): array {
		$windows = [['start' => '08:30', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:00']];
		return [
			'none' => null,
			'nl' => WorkingCalendar::fromArray(definition: WorkingCalendarTest::nlNational()),
			'plain' => WorkingCalendar::fromArray(
				definition: ['slug' => 'plain', 'hoursPerWorkingDay' => 7.5, 'workingWeekdays' => [1, 2, 3, 4], 'dayStartsAt' => '08:00']
			),
			'service hours' => WorkingCalendar::fromArray(
				definition: [
					'slug' => 'sh',
					'hoursPerWorkingDay' => 8,
					'workingWeekdays' => [1, 2, 3, 4, 5],
					'rules' => [['kind' => 'fixed', 'month' => 12, 'day' => 25]],
					'serviceHours' => ['monday' => $windows, 'wednesday' => $windows, 'friday' => [['start' => '09:00', 'end' => '12:30']]],
				]
			),
		];
	}//end calendars()

	private static function at(string $when): DateTimeImmutable {
		return new DateTimeImmutable($when);
	}//end at()

	private static function capture(Closure $call): mixed {
		try {
			$value = $call();
			if ($value instanceof DateTimeInterface) {
				return $value->format('Y-m-d H:i:s P');
			}

			if (is_float($value) === true) {
				return round($value, 9);
			}

			return $value;
		} catch (Throwable $error) {
			return ['error' => get_class($error), 'message' => $error->getMessage()];
		}
	}//end capture()

	public function testCalculatorMatchesGolden(): void {
		$calc = new SlaCalculator();
		$actual = [];
		$starts = [
			'fri 16:00 ams' => '2026-04-24 16:00:00 Europe/Amsterdam',
			'sat noon utc' => '2026-04-25 12:00:00 UTC',
			'midnight mon' => '2026-04-27 00:00:00 Europe/Amsterdam',
			'dst eve' => '2026-03-28 22:30:00 Europe/Amsterdam',
			'christmas eve' => '2026-12-24 10:15:00 Europe/Amsterdam',
		];
		$amounts = [0.0, 0.25, 1.0, 2.5, 7.0, -0.5, -1.0, -3.75, 30.0];
		foreach (self::calendars() as $calName => $calendar) {
			foreach ($starts as $startName => $start) {
				foreach (SlaCalculator::UNITS as $unit) {
					foreach ($amounts as $amount) {
						$key = "add / {$calName} / {$startName} / {$unit} / {$amount}";
						$actual[$key] = self::capture(fn () => $calc->add(from: self::at($start), value: $amount, unit: $unit, calendar: $calendar));
					}

					$actual["sub / {$calName} / {$startName} / {$unit} / 2"] = self::capture(fn () => $calc->sub(from: self::at($start), value: 2.0, unit: $unit, calendar: $calendar));
				}
			}
		}

		foreach (['nl', 'plain', 'service hours'] as $calName) {
			$calendar = self::calendars()[$calName];
			foreach ([3.5, -3.5, 0.0] as $amount) {
				$collector = new WalkCollector();
				$landed = self::capture(fn () => $calc->addWatched(addition: new \OCA\OpenRegister\Service\Flow\Timer\SlaAddition(start: \DateTimeImmutable::createFromInterface(self::at('2026-04-24 16:00:00 Europe/Amsterdam')), value: $amount, unit: 'businessDays', calendar: $calendar), collector: $collector));
				$actual["collector / {$calName} / {$amount}"] = ['landed' => $landed, 'walk' => $collector->walk(), 'examined' => $collector->examinedCount()];
			}

			$spans = [
				'weekend' => ['2026-04-24 16:00:00 Europe/Amsterdam', '2026-04-27 09:00:00 Europe/Amsterdam'],
				'reverse' => ['2026-04-27 09:00:00 Europe/Amsterdam', '2026-04-24 16:00:00 Europe/Amsterdam'],
				'same' => ['2026-04-24 16:00:00 UTC', '2026-04-24 16:00:00 UTC'],
				'month' => ['2026-12-01 07:00:00 UTC', '2027-01-04 18:45:00 UTC'],
				'dst' => ['2026-03-27 10:00:00 Europe/Amsterdam', '2026-03-30 10:00:00 Europe/Amsterdam'],
				'night' => ['2026-04-28 20:00:00 UTC', '2026-04-29 06:00:00 UTC'],
				'too long' => ['2000-01-01 00:00:00 UTC', '2060-01-01 00:00:00 UTC'],
			];
			foreach ($spans as $spanName => [$from, $to]) {
				foreach (SlaCalculator::UNITS as $unit) {
					$actual["measure / {$calName} / {$spanName} / {$unit}"] = self::capture(fn () => $calc->measure(span: new CalendarSpan(from: self::at($from), to: self::at($to), calendar: $calendar), unit: $unit));
				}

				$actual["elapsed / {$calName} / {$spanName}"] = self::capture(fn () => $calc->elapsedBusinessHours(from: self::at($from), to: self::at($to), calendar: $calendar));
			}

			foreach (SlaCalculator::UNITS as $fromUnit) {
				foreach (SlaCalculator::UNITS as $toUnit) {
					$actual["convert / {$calName} / {$fromUnit} -> {$toUnit}"] = self::capture(fn () => $calc->convert(conversion: new UnitConversion(value: 3.0, fromUnit: $fromUnit, toUnit: $toUnit, calendar: $calendar)));
				}
			}

			$actual["convert / {$calName} / bad unit"] = self::capture(fn () => $calc->convert(conversion: new UnitConversion(value: 3.0, fromUnit: 'weeks', toUnit: 'hours', calendar: $calendar)));
			$actual["measure / {$calName} / bad unit"] = self::capture(fn () => $calc->measure(span: new CalendarSpan(from: self::at('2026-01-01'), to: self::at('2026-01-02'), calendar: $calendar), unit: 'weeks'));
			foreach (SlaCalculator::ROLLS as $roll) {
				$rolled = $calc->roll(moment: self::at('2026-04-27 15:00:00 Europe/Amsterdam'), rule: new RollRule(roll: $roll, calendar: $calendar));
				$actual["roll / {$calName} / {$roll}"] = array_map(static fn ($value) => $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s P') : $value, $rolled);
			}
		}

		$actual['add / bad unit'] = self::capture(fn () => $calc->add(from: self::at('2026-01-01'), value: 1.0, unit: 'weeks', calendar: null));
		$actual['add / too long forward'] = self::capture(fn () => $calc->add(from: self::at('2026-01-01 UTC'), value: 30000.0, unit: 'businessDays', calendar: self::calendars()['plain']));
		$actual['add / too long backward'] = self::capture(fn () => $calc->add(from: self::at('2026-01-01 UTC'), value: -30000.0, unit: 'businessDays', calendar: self::calendars()['plain']));
		$actual['add / negative zero'] = self::capture(fn () => $calc->add(from: self::at('2026-04-25 12:00:00 UTC'), value: -0.0, unit: 'businessDays', calendar: self::calendars()['nl']));
		foreach ([['value' => 5, 'unit' => 'businessDays'], ['value' => 0, 'unit' => 'hours'], ['value' => 5, 'unit' => 'weeks'], 'x'] as $index => $sla) {
			$actual["validateSla / {$index}"] = self::capture(fn () => $calc->validateSla(sla: $sla));
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
	}//end testCalculatorMatchesGolden()
}//end class
