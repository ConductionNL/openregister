<?php

/**
 * Golden test for ServiceHoursClock: due(), elapsed() and diagnostic() across
 * calendars, zones, windows and the walk bound, on fixed instants only.
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
use OCA\OpenRegister\Service\Flow\Timer\ServiceHours;
use OCA\OpenRegister\Service\Flow\Timer\ServiceHoursClock;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\ServiceHoursClock
 * @uses \OCA\OpenRegister\Service\Flow\Timer\ServiceHours
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar
 * @uses \OCA\OpenRegister\Service\Flow\Timer\ServiceHoursWalk
 */
class ServiceHoursClockGoldenTest extends TestCase {

	/**
	 * @var string
	 */
	private const FIXTURE = __DIR__ . '/fixtures/service-hours-clock-golden.json';

	/**
	 * @return array<string, WorkingCalendar>
	 */
	private static function calendars(): array {
		$split = [['start' => '08:30', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:00']];
		$base = ['hoursPerWorkingDay' => 8, 'workingWeekdays' => [1, 2, 3, 4, 5], 'rules' => [['kind' => 'fixed', 'month' => 12, 'day' => 25], ['kind' => 'easter', 'offset' => 1]]];
		return [
			'ams split' => WorkingCalendar::fromArray(definition: $base + ['slug' => 'ams', 'timezone' => 'Europe/Amsterdam', 'serviceHours' => ['monday' => $split, 'tuesday' => $split, 'thursday' => $split, 'friday' => [['start' => '09:00', 'end' => '12:30']]]]),
			'utc to midnight' => WorkingCalendar::fromArray(definition: $base + ['slug' => 'utc', 'serviceHours' => ['monday' => [['start' => '22:00', 'end' => '24:00']], 'wednesday' => [['start' => 0, 'end' => 90], ['start' => '23:00', 'end' => '23:59']]]]),
		];
	}//end calendars()

	private static function capture(Closure $call): mixed {
		try {
			$value = $call();
			if ($value instanceof DateTimeInterface) {
				return $value->format('Y-m-d H:i:s T');
			}

			return $value;
		} catch (Throwable $error) {
			return ['error' => get_class($error), 'message' => $error->getMessage()];
		}
	}//end capture()

	public function testClockMatchesGolden(): void {
		$clock = new ServiceHoursClock();
		$actual = [];
		$moments = [
			'mon 07:00 utc' => '2026-03-30 07:00:00 UTC',
			'mon 10:59:59 ams' => '2026-03-30 10:59:59 Europe/Amsterdam',
			'fri 12:00 ams' => '2026-04-03 12:00:00 Europe/Amsterdam',
			'sat' => '2026-04-04 15:00:00 UTC',
			'mon 23:30 utc' => '2026-03-30 23:30:00 UTC',
			'xmas eve' => '2026-12-24 16:00:00 Europe/Amsterdam',
			'dst sunday' => '2026-10-25 01:30:00 UTC',
		];
		$hours = [0.0, -1.0, 0.004, 0.25, 1.0, 3.5, 4.0, 10.0, 40.0];
		foreach (self::calendars() as $calName => $calendar) {
			foreach ($moments as $momentName => $moment) {
				foreach ($hours as $amount) {
					$actual["due / {$calName} / {$momentName} / {$amount}"] = self::capture(
						fn () => $clock->frame(calendar: $calendar, windows: $calendar->getServiceHours())->due(from: new DateTimeImmutable($moment), hours: $amount)
					);
				}

				foreach ($moments as $toName => $to) {
					$actual["elapsed / {$calName} / {$momentName} -> {$toName}"] = self::capture(
						fn () => $clock->frame(calendar: $calendar, windows: $calendar->getServiceHours())->elapsed(from: new DateTimeImmutable($moment), to: new DateTimeImmutable($to))
					);
				}
			}

			$actual["elapsed / {$calName} / month"] = self::capture(
				fn () => $clock->frame(calendar: $calendar, windows: $calendar->getServiceHours())->elapsed(from: new DateTimeImmutable('2026-12-01 00:00 UTC'), to: new DateTimeImmutable('2027-01-15 12:00 UTC'))
			);
			$actual["elapsed / {$calName} / too long"] = self::capture(
				fn () => $clock->frame(calendar: $calendar, windows: $calendar->getServiceHours())->elapsed(from: new DateTimeImmutable('2020-01-01 UTC'), to: new DateTimeImmutable('2031-01-01 UTC'))
			);
			$actual["elapsed / {$calName} / no windows"] = self::capture(
				fn () => $clock->frame(calendar: $calendar, windows: ServiceHours::none())->elapsed(from: new DateTimeImmutable('2026-03-30 UTC'), to: new DateTimeImmutable('2026-04-30 UTC'))
			);
			$actual["due / {$calName} / never opens"] = self::capture(
				fn () => $clock->frame(calendar: $calendar, windows: ServiceHours::none())->due(from: new DateTimeImmutable('2026-03-30 UTC'), hours: 1.0)
			);
			$actual["due / {$calName} / beyond the bound"] = self::capture(
				fn () => $clock->frame(calendar: $calendar, windows: $calendar->getServiceHours())->due(from: new DateTimeImmutable('2026-03-30 UTC'), hours: 100000.0)
			);
			$actual["diagnostic / {$calName}"] = $clock->diagnostic(calendar: $calendar, windows: $calendar->getServiceHours());
			$actual["diagnostic / {$calName} / none"] = $clock->diagnostic(calendar: $calendar, windows: ServiceHours::none());
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
	}//end testClockMatchesGolden()
}//end class
