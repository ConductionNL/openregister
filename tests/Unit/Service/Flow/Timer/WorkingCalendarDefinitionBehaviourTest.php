<?php

/**
 * What a working-calendar definition must declare, and what it reads back:
 * every refusal (slug, weekdays, hours, day start, timezone, enumerated-only
 * dates) names the calendar and the fault; defaults are the 09:00 start and
 * UTC; the organisation and weekdays are normalised.
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
 * WorkingCalendar::fromArray() over WorkingCalendarDefinition.
 */
class WorkingCalendarDefinitionBehaviourTest extends TestCase {

	private const BASE = ['slug' => 'office', 'workingWeekdays' => [1, 2, 3, 4, 5], 'hoursPerWorkingDay' => 8];

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function refusedDefinitions(): array {
		return [
			'blank slug' => [['slug' => '  '], 'A working calendar requires a non-empty slug.'],
			'no weekdays' => [['workingWeekdays' => []], "Working calendar 'office' must declare its workingWeekdays (ISO 1..7)."],
			'weekdays not a list' => [['workingWeekdays' => 'mon-fri'], "Working calendar 'office' must declare its workingWeekdays"],
			'weekday zero' => [['workingWeekdays' => [0, 1]], "Working calendar 'office' has an invalid weekday '0'; use ISO 1..7."],
			'weekday eight' => [['workingWeekdays' => [8]], "has an invalid weekday '8'"],
			'weekday as text' => [['workingWeekdays' => ['1']], "has an invalid weekday ''1''"],
			'no hours' => [['hoursPerWorkingDay' => null], "Working calendar 'office' requires hoursPerWorkingDay between 0 and 24"],
			'zero hours' => [['hoursPerWorkingDay' => 0], 'requires hoursPerWorkingDay between 0 and 24'],
			'too many hours' => [['hoursPerWorkingDay' => 24.5], 'requires hoursPerWorkingDay between 0 and 24'],
			'text hours' => [['hoursPerWorkingDay' => 'eight'], 'requires hoursPerWorkingDay between 0 and 24'],
			'day start 24:00' => [['dayStartsAt' => '24:00'], "Working calendar 'office' declares dayStartsAt '24:00'; it must be HH:MM in 24-hour form."],
			'day start 9:00' => [['dayStartsAt' => '9:00'], "declares dayStartsAt '9:00'"],
			'day start a list' => [['dayStartsAt' => ['09:00']], "declares dayStartsAt 'array'"],
			'zone not a string' => [['timezone' => 1], "Working calendar 'office' declares a timezone that is not a string."],
			'unknown zone' => [['timezone' => 'Mars/Olympus'], "Working calendar 'office' declares timezone 'Mars/Olympus', which is not an IANA zone name."],
			'only enumerated dates' => [
				['rules' => [], 'exceptions' => ['2027-12-25', '2026-01-01']],
				"Working calendar 'office' consists only of enumerated dates and would expire after",
			],
		];
	}//end refusedDefinitions()

	/**
	 * @param array<string, mixed> $override The field that breaks the definition.
	 * @param string               $message  A fragment the refusal states.
	 */
	#[DataProvider('refusedDefinitions')]
	public function testABrokenDefinitionIsRefusedNamingTheFault(array $override, string $message): void {
		try {
			WorkingCalendar::fromArray(array_merge(self::BASE, $override));
			$this->fail('A broken definition was accepted.');
		} catch (FlowTimerValidationException $e) {
			$this->assertStringContainsString($message, $e->getMessage());
		}
	}//end testABrokenDefinitionIsRefusedNamingTheFault()

	public function testAMinimalDefinitionTakesTheDefaults(): void {
		$calendar = WorkingCalendar::fromArray(self::BASE + ['dayStartsAt' => '  ', 'timezone' => ' ', 'organisation' => '  ']);

		$this->assertSame('office', $calendar->getSlug());
		$this->assertNull($calendar->getOrganisation());
		$this->assertSame(540, $calendar->getDayStartsAtMinute());
		$this->assertSame('UTC', $calendar->getTimezone());
		$this->assertSame(8.0, $calendar->getHoursPerWorkingDay());
	}//end testAMinimalDefinitionTakesTheDefaults()

	public function testDeclaredValuesAreReadBackNormalised(): void {
		$calendar = WorkingCalendar::fromArray(
			[
				'slug' => ' gemeente ',
				'organisation' => ' org-1 ',
				'workingWeekdays' => [1, 2, 2, 3],
				'hoursPerWorkingDay' => '7.5',
				'dayStartsAt' => ' 08:30 ',
				'timezone' => ' Europe/Amsterdam ',
			]
		);

		$this->assertSame('gemeente', $calendar->getSlug());
		$this->assertSame('org-1', $calendar->getOrganisation());
		$this->assertSame([1, 2, 3], $calendar->getWorkingWeekdays());
		$this->assertSame(7.5, $calendar->getHoursPerWorkingDay());
		$this->assertSame(510, $calendar->getDayStartsAtMinute());
		$this->assertSame('Europe/Amsterdam', $calendar->getTimezone());
	}//end testDeclaredValuesAreReadBackNormalised()
}//end class
