<?php

/**
 * The computed rules and enumerated exceptions of a working calendar: each
 * malformed rule, shift or exception is refused naming the calendar, and a
 * flat observed-shift rule and a weekday-named shift are accepted and move
 * the holiday they describe.
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
 * WorkingCalendarRules through WorkingCalendar::fromArray().
 */
class WorkingCalendarRulesBehaviourTest extends TestCase {

	private const BASE = ['slug' => 'office', 'workingWeekdays' => [1, 2, 3, 4, 5], 'hoursPerWorkingDay' => 8];

	private const NEW_YEAR = ['kind' => 'fixed', 'month' => 1, 'day' => 1, 'name' => 'Nieuwjaarsdag'];

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function refused(): array {
		return [
			'rules not a list' => [['rules' => 'none'], "Working calendar 'office' rules must be an array."],
			'rule not a map' => [['rules' => ['fixed']], "Working calendar 'office' has a rule of unknown kind ''"],
			'unknown kind' => [['rules' => [['kind' => 'lunar']]], "has a rule of unknown kind 'lunar'; known kinds:"],
			'easter without offset' => [['rules' => [['kind' => 'easter', 'offset' => '1']]], "Working calendar 'office': an easter rule requires an integer offset."],
			'month 13' => [['rules' => [['kind' => 'fixed', 'month' => 13, 'day' => 1]]], "Working calendar 'office': a fixed rule requires an integer month 1..12 and day 1..31."],
			'day zero' => [['rules' => [['kind' => 'fixed', 'month' => 1, 'day' => 0]]], 'a fixed rule requires an integer month 1..12 and day 1..31.'],
			'shift without days' => [['rules' => [self::NEW_YEAR + ['observedShift' => ['whenWeekday' => 'sunday']]]], "Working calendar 'office': an observed shift requires whenWeekday and an integer days."],
			'shift not a map' => [['rules' => [self::NEW_YEAR + ['observedShift' => 'sunday']]], 'an observed shift requires whenWeekday and an integer days.'],
			'shift on an unknown weekday' => [['rules' => [self::NEW_YEAR + ['observedShift' => ['whenWeekday' => 'funday', 'days' => 1]]]], "Working calendar 'office': observed shift whenWeekday must be a weekday name or ISO 1..7."],
			'shift on weekday 9' => [['rules' => [self::NEW_YEAR + ['observedShift' => ['whenWeekday' => 9, 'days' => 1]]]], 'observed shift whenWeekday must be a weekday name or ISO 1..7.'],
			'flat shift without days' => [['rules' => [['kind' => 'observedShift', 'month' => 4, 'day' => 27, 'whenWeekday' => 'sunday']]], 'an observed shift requires whenWeekday and an integer days.'],
			'exceptions not a list' => [['rules' => [self::NEW_YEAR], 'exceptions' => 'none'], "Working calendar 'office' exceptions must be an array."],
			'exception not a date' => [['rules' => [self::NEW_YEAR], 'exceptions' => ['05-10-2026']], "Working calendar 'office' has an exception that is not a Y-m-d date: ''05-10-2026''."],
			'exception map without date' => [['rules' => [self::NEW_YEAR], 'exceptions' => [['name' => 'x']]], "has an exception that is not a Y-m-d date: 'NULL'."],
		];
	}//end refused()

	/**
	 * @param array<string, mixed> $override The rules or exceptions.
	 * @param string               $message  What the refusal states.
	 */
	#[DataProvider('refused')]
	public function testAMalformedRuleOrExceptionIsRefused(array $override, string $message): void {
		try {
			WorkingCalendar::fromArray(array_merge(self::BASE, $override));
			$this->fail('A malformed rule was accepted.');
		} catch (FlowTimerValidationException $e) {
			$this->assertStringContainsString($message, $e->getMessage());
		}
	}//end testAMalformedRuleOrExceptionIsRefused()

	public function testAFlatObservedShiftRuleMovesTheHolidayOffASunday(): void {
		// 27 April 2025 is a Sunday; the shift moves it back to Saturday 26.
		$calendar = WorkingCalendar::fromArray(
			self::BASE + ['rules' => [['kind' => 'observedShift', 'month' => 4, 'day' => 27, 'whenWeekday' => 'Sunday', 'days' => -1, 'name' => 'Koningsdag']]]
		);

		$dates = $calendar->nonWorkingDates(2025);

		$this->assertSame('Koningsdag', $dates['2025-04-26'] ?? null);
		$this->assertArrayNotHasKey('2025-04-27', $dates);
	}//end testAFlatObservedShiftRuleMovesTheHolidayOffASunday()

	public function testAnEasterRuleWithoutANameIsCalledEaster(): void {
		$calendar = WorkingCalendar::fromArray(self::BASE + ['rules' => [['kind' => 'easter', 'offset' => 1]]]);

		// Easter Sunday 2026 is 5 April; offset +1 is Easter Monday.
		$this->assertSame('easter', $calendar->nonWorkingDates(2026)['2026-04-06'] ?? null);
	}//end testAnEasterRuleWithoutANameIsCalledEaster()
}//end class
