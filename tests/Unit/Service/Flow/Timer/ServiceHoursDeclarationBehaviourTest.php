<?php

/**
 * The service hours a working calendar declares: weekdays by name or ISO
 * number (as int or digits), windows in HH:MM or minutes, and each refusal of
 * a malformed map, weekday, day, window or bound, naming the calendar.
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

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ServiceHoursDeclaration through WorkingCalendar::fromArray().
 */
class ServiceHoursDeclarationBehaviourTest extends TestCase {

	private const BASE = ['slug' => 'office', 'workingWeekdays' => [1, 2, 3, 4, 5], 'hoursPerWorkingDay' => 8];

	private const DAY = [['start' => '09:00', 'end' => '17:00']];

	/**
	 * @return array<string, array{mixed, string}>
	 */
	public static function refused(): array {
		return [
			'not a map' => ['mon 9-5', "Working calendar 'office' declares serviceHours that is not a map of weekday to windows."],
			'unknown weekday name' => [['funday' => self::DAY], "Working calendar 'office' declares serviceHours for 'funday', which is not a weekday."],
			'weekday number 8' => [[8 => self::DAY], "declares serviceHours for '8', which is not a weekday."],
			'weekday digits 0' => [['0' => self::DAY], "declares serviceHours for '0', which is not a weekday."],
			'non-working weekday' => [['saturday' => self::DAY], 'declares service hours on'],
			'empty day' => [['monday' => []], "Working calendar 'office' declares no usable window on"],
			'day not a list' => [['monday' => '09:00-17:00'], 'declares no usable window on'],
			'window not a map' => [['monday' => ['09:00']], 'without a readable start and end (HH:MM).'],
			'minute bound past midnight' => [['monday' => [['start' => 0, 'end' => 1441]]], 'without a readable start and end (HH:MM).'],
			'negative minute bound' => [['monday' => [['start' => -1, 'end' => 60]]], 'without a readable start and end (HH:MM).'],
			'hour 25' => [['monday' => [['start' => '09:00', 'end' => '25:00']]], 'without a readable start and end (HH:MM).'],
			'minute 60' => [['monday' => [['start' => '09:60', 'end' => '17:00']]], 'without a readable start and end (HH:MM).'],
			'bound as words' => [['monday' => [['start' => 'nine', 'end' => '17:00']]], 'without a readable start and end (HH:MM).'],
			'bound a float' => [['monday' => [['start' => 9.5, 'end' => '17:00']]], 'without a readable start and end (HH:MM).'],
			'ends before it starts' => [['monday' => [['start' => '17:00', 'end' => '09:00']]], 'that ends at or before it starts.'],
			'overlap' => [['monday' => [['start' => '12:00', 'end' => '17:00'], ['start' => '09:00', 'end' => '12:30']]], 'declares overlapping service hours on'],
		];
	}//end refused()

	#[DataProvider('refused')]
	public function testAMalformedDeclarationIsRefused(mixed $serviceHours, string $message): void {
		try {
			WorkingCalendar::fromArray(self::BASE + ['serviceHours' => $serviceHours]);
			$this->fail('A malformed service-hours declaration was accepted.');
		} catch (FlowTimerValidationException $e) {
			$this->assertStringContainsString($message, $e->getMessage());
		}
	}//end testAMalformedDeclarationIsRefused()

	public function testWeekdaysAndBoundsAreReadInEverySpelling(): void {
		$calendar = WorkingCalendar::fromArray(
			self::BASE + [
				'serviceHours' => [
					1 => [['start' => 540, 'end' => 720]],
					'2' => [['start' => '13:00', 'end' => '24:00']],
					' Wednesday ' => [['start' => 0, 'end' => 1440]],
				],
			]
		);

		$hours = $calendar->getServiceHours();
		$this->assertSame([180, 660, 1440, 0], [$hours->minutesOn(1), $hours->minutesOn(2), $hours->minutesOn(3), $hours->minutesOn(4)]);
		$this->assertSame([['start' => 780, 'end' => 1440]], $hours->forWeekday(2));
	}//end testWeekdaysAndBoundsAreReadInEverySpelling()
}//end class
