<?php

/**
 * Golden test for ServiceHours::fromDeclaration(): which declarations it accepts,
 * the windows and minutes it derives from them, and every refusal message
 * with its fault order. Re-record with UPDATE_GOLDEN=1.
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

use OCA\OpenRegister\Service\Flow\Timer\ServiceHours;
use OCA\OpenRegister\Service\Flow\Timer\ServiceHoursDeclaration;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\ServiceHours
 * @covers \OCA\OpenRegister\Service\Flow\Timer\ServiceHoursDeclaration
 */
class ServiceHoursGoldenTest extends TestCase {

	/**
	 * @var string
	 */
	private const FIXTURE = __DIR__ . '/fixtures/service-hours-golden.json';

	/**
	 * @return array<string, array{0: mixed, 1: array<int, int>}>
	 */
	private static function declarations(): array {
		$week = [1, 2, 3, 4, 5];
		$w = static fn (mixed $start, mixed $end): array => ['start' => $start, 'end' => $end];
		return [
			'null' => [null, $week],
			'empty' => [[], $week],
			'not array' => ['09:00-17:00', $week],
			'names and numbers' => [['Monday' => [$w('09:00', '12:00'), $w('13:00', '17:30')], 2 => [$w('8:00', '16:00')], '3' => [$w(480, 960)], ' friday ' => [$w('09:00', '24:00')]], $week],
			'unsorted windows' => [['monday' => [$w('13:00', '17:00'), $w('08:00', '12:00')]], $week],
			'touching windows' => [['monday' => [$w('08:00', '12:00'), $w('12:00', '13:00')]], $week],
			'weekday keys unsorted' => [['friday' => [$w('09:00', '10:00')], 'monday' => [$w('09:00', '17:00')]], $week],
			'weekday duplicated by number and name' => [['monday' => [$w('09:00', '10:00')], 1 => [$w('11:00', '12:00')]], $week],
			'unknown weekday name' => [['funday' => [$w('09:00', '10:00')]], $week],
			'weekday zero' => [[0 => [$w('09:00', '10:00')]], $week],
			'weekday eight string' => [['8' => [$w('09:00', '10:00')]], $week],
			'off day' => [['saturday' => [$w('09:00', '10:00')]], $week],
			'off day by number' => [[7 => [$w('09:00', '10:00')]], [1, 2, 3, 4, 5, 6]],
			'day windows empty' => [['monday' => []], $week],
			'day windows not array' => [['monday' => 'x'], $week],
			'window not array' => [['monday' => ['09:00']], $week],
			'window missing end' => [['monday' => [['start' => '09:00']]], $week],
			'window bad text' => [['monday' => [$w('9am', '17:00')]], $week],
			'window minute 60' => [['monday' => [$w('09:60', '17:00')]], $week],
			'window hour 25' => [['monday' => [$w('09:00', '25:00')]], $week],
			'window 24:30' => [['monday' => [$w('09:00', '24:30')]], $week],
			'window int negative' => [['monday' => [$w(-1, 600)]], $week],
			'window int 1441' => [['monday' => [$w(600, 1441)]], $week],
			'window int 1440' => [['monday' => [$w(600, 1440)]], $week],
			'window float' => [['monday' => [$w(600.0, 700)]], $week],
			'window padded text' => [['monday' => [$w(' 09:00 ', '17:00 ')]], $week],
			'window ends at start' => [['monday' => [$w('09:00', '09:00')]], $week],
			'window ends before start' => [['monday' => [$w('17:00', '09:00')]], $week],
			'overlap' => [['tuesday' => [$w('09:00', '12:00'), $w('11:00', '14:00')]], $week],
			'overlap after sort' => [['tuesday' => [$w('11:00', '14:00'), $w('09:00', '12:00')]], $week],
			'contained' => [['tuesday' => [$w('09:00', '17:00'), $w('10:00', '11:00')]], $week],
			'fault order: weekday before windows' => [['funday' => 'x', 'monday' => []], $week],
			'fault order: unreadable before order' => [['monday' => [$w('17:00', '09:00'), $w('x', '10:00')]], $week],
			'fault order: per window before overlap' => [['monday' => [$w('09:00', '12:00'), $w('10:00', '11:00'), $w('15:00', '14:00')]], $week],
			'fault order: first weekday first' => [['monday' => [$w('17:00', '09:00')], 'saturday' => []], $week],
		];
	}//end declarations()

	public function testServiceHoursMatchGolden(): void {
		$actual = [];
		foreach (self::declarations() as $name => [$value, $weekdays]) {
			try {
				$hours = ServiceHours::fromDeclaration(value: $value, declaration: new ServiceHoursDeclaration(slug: 'cal', workingWeekdays: $weekdays));
				$minutes = [];
				foreach (range(0, 8) as $iso) {
					$minutes[$iso] = [$hours->minutesOn(iso: $iso), $hours->forWeekday(iso: $iso)];
				}

				$actual[$name] = [
					'declared' => $hours->areDeclared(),
					'all' => $hours->all(),
					'derivedHours' => $hours->derivedHoursPerWorkingDay(),
					'perWeekday' => $minutes,
				];
			} catch (Throwable $error) {
				$actual[$name] = ['error' => get_class($error), 'message' => $error->getMessage()];
			}
		}

		$none = ServiceHours::none();
		$actual['none'] = [$none->areDeclared(), $none->all(), $none->derivedHoursPerWorkingDay(), $none->minutesOn(iso: 1)];

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
	}//end testServiceHoursMatchGolden()
}//end class
