<?php

/**
 * A calendar-range value is a start and an end, both present, in the declared format, the end not before the start.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Formats;

use OCA\OpenRegister\Formats\CalendarRangeValue;
use OCA\OpenRegister\Formats\FormattedProperty;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Formats\CalendarRangeValue
 * @uses \OCA\OpenRegister\Formats\FormattedProperty
 * @uses \OCA\OpenRegister\Formats\Iso8601DateTimeFormat
 */
class CalendarRangeValueTest extends TestCase {

	/**
	 * Valid ranges under each format.
	 *
	 * @return array<string, array{0: mixed, 1: string|null}>
	 */
	public static function validRanges(): array {
		return [
			'a working day' => [['start' => '2026-05-01T09:00:00Z', 'end' => '2026-05-01T17:00:00Z'], null],
			'an instant' => [['start' => '2026-05-01T09:00:00Z', 'end' => '2026-05-01T09:00:00Z'], 'date-time'],
			'offsets compared as instants' => [['start' => '2026-05-01T10:00:00+02:00', 'end' => '2026-05-01T09:30:00Z'], null],
			'a date range' => [['start' => '2026-05-01', 'end' => '2026-05-03'], 'date'],
		];
	}//end validRanges()

	#[DataProvider('validRanges')]
	public function testAValidRangePasses(mixed $value, ?string $format): void {
		$this->assertNull((new CalendarRangeValue())->validate($value, new FormattedProperty('period', $format)));
	}//end testAValidRangePasses()

	/**
	 * Refused values and the exact message.
	 *
	 * @return array<string, array{0: mixed, 1: string|null, 2: string}>
	 */
	public static function refusedRanges(): array {
		return [
			'end before start' => [
				['start' => '2026-05-01T17:00:00Z', 'end' => '2026-05-01T09:00:00Z'],
				null,
				"Property 'period': calendar-range 'end' (2026-05-01T09:00:00Z) must be >= 'start' (2026-05-01T17:00:00Z)",
			],
			'a date range refuses a time' => [
				['start' => '2026-05-01T09:00:00Z', 'end' => '2026-05-03'],
				'date',
				"Property 'period' declared format 'date'; 'start' must match YYYY-MM-DD",
			],
			'an impossible date' => [
				['start' => '2026-05-01', 'end' => '2026-02-30'],
				'date',
				"Property 'period' declared format 'date'; 'end' must match YYYY-MM-DD",
			],
			'a date-time range refuses a bare date' => [
				['start' => '2026-05-01', 'end' => '2026-05-03T00:00:00Z'],
				null,
				"Property 'period' declared format 'date-time'; 'start' must be an ISO 8601 date-time",
			],
			'missing end' => [
				['start' => '2026-05-01T09:00:00Z'],
				null,
				"Property 'period': calendar-range is missing 'end'",
			],
			'empty start' => [
				['start' => '', 'end' => '2026-05-01T09:00:00Z'],
				null,
				"Property 'period': calendar-range is missing 'start'",
			],
			'a string' => [
				'2026-05-01/2026-05-03',
				null,
				"Property 'period': calendar-range must be an object with 'start' and 'end'",
			],
			'a list' => [
				['2026-05-01T09:00:00Z', '2026-05-01T17:00:00Z'],
				null,
				"Property 'period': calendar-range must be an object with 'start' and 'end'",
			],
			'a date range ending before it starts' => [
				['start' => '2026-05-03', 'end' => '2026-05-01'],
				'date',
				"Property 'period': calendar-range 'end' (2026-05-01) must be >= 'start' (2026-05-03)",
			],
		];
	}//end refusedRanges()

	#[DataProvider('refusedRanges')]
	public function testARefusedRangeNamesThePropertyAndTheRule(mixed $value, ?string $format, string $message): void {
		$this->assertSame($message, (new CalendarRangeValue())->validate($value, new FormattedProperty('period', $format)));
	}//end testARefusedRangeNamesThePropertyAndTheRule()
}//end class
