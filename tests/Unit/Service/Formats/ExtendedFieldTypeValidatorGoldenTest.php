<?php

/**
 * Golden pin for ExtendedFieldTypeValidator.
 *
 * Records the exact message (or null) every public method returned for a
 * broad table of inputs before the class was split into smaller methods, so
 * the refactor can be shown to change no result. Occurrence cases use a fixed
 * start instant, so no case depends on today's date.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Formats
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-003
 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Formats;

use OCA\OpenRegister\Formats\RecurrenceWindow;
use OCA\OpenRegister\Formats\FormattedProperty;
use DateTimeImmutable;
use OCA\OpenRegister\Formats\ExtendedFieldTypeValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins every ExtendedFieldTypeValidator result recorded on the pre-refactor code.
 */
class ExtendedFieldTypeValidatorGoldenTest extends TestCase {
	/**
	 * The fixed instant every occurrence case iterates from.
	 *
	 * @var string
	 */
	private const START = '2024-01-01T09:30:00+00:00';

	/**
	 * Recorded validateColor() results: value, format, message.
	 *
	 * @return array<string, array{mixed, string|null, string|null}> The cases.
	 */
	public static function recordedColors(): array {
		return [
			'color 01' => [123, null, 'Property \'tint\': color value must be a string'],
			'color 02' => [null, 'hex', 'Property \'tint\': color value must be a string'],
			'color 03' => ['#fff', null, null],
			'color 04' => ['#FFFFFF', '', null],
			'color 05' => ['#ffffff80', 'HEX', null],
			'color 06' => ['#ffff', null, 'Property \'tint\' declared format \'hex\'; \'#ffff\' does not match hex regex'],
			'color 07' => ['fff', 'hex', 'Property \'tint\' declared format \'hex\'; \'fff\' does not match hex regex'],
			'color 08' => ['#ggg', 'hex', 'Property \'tint\' declared format \'hex\'; \'#ggg\' does not match hex regex'],
			'color 09' => ['#fff', 'unknown', null],
			'color 10' => ['red', 'hsl', 'Property \'tint\' declared format \'hex\'; \'red\' does not match hex regex'],
			'color 11' => ['rgba(1,2,3,0.5)', 'rgba', null],
			'color 12' => ['RGBA( 1 , 2 , 3 , 0.5 )', 'RGBA', null],
			'color 13' => ['rgba(1,2,3)', 'rgba', 'Property \'tint\' format \'rgba\' requires 4 components; got 3'],
			'color 14' => ['rgba(1,,2,3)', 'rgba', 'Property \'tint\' format \'rgba\' requires 4 components; got 3'],
			'color 15' => ['rgba(1,2,3,4,5)', 'rgba', 'Property \'tint\' format \'rgba\' requires 4 components; got 5'],
			'color 16' => ['rgba()', 'rgba', 'Property \'tint\' format \'rgba\' requires 4 components; got 0'],
			'color 17' => ['rgb(1,2,3)', 'rgba', 'Property \'tint\' declared format \'rgba\'; \'rgb(1,2,3)\' does not match rgba regex'],
			'color 18' => ['#fff', 'rgba', 'Property \'tint\' declared format \'rgba\'; \'#fff\' does not match rgba regex'],
			'color 19' => ['rgba(0,0,0,0)', 'rgba', null],
			'color 20' => ['rgba(1,2,3,0,)', 'rgba', null],
			'color 21' => ['oklch(70% 0.1 200)', 'oklch', null],
			'color 22' => ['OKLCH(0.7 0.1 200deg / 50%)', 'OkLch', null],
			'color 23' => ['oklch(70% 0.1)', 'oklch', 'Property \'tint\' declared format \'oklch\'; \'oklch(70% 0.1)\' does not match oklch regex'],
			'color 24' => ['#fff', 'oklch', 'Property \'tint\' declared format \'oklch\'; \'#fff\' does not match oklch regex'],
			'color 25' => ['oklch(70% 0.1 200)', null, 'Property \'tint\' declared format \'hex\'; \'oklch(70% 0.1 200)\' does not match hex regex'],
		];
	}//end recordedColors()

	/**
	 * Recorded validateRecurrence() results: value, message.
	 *
	 * @return array<string, array{mixed, string|null}> The cases.
	 */
	public static function recordedRecurrences(): array {
		return [
			'rrule 01' => ['FREQ=DAILY', null],
			'rrule 02' => ['FREQ=WEEKLY;BYDAY=MO,WE', null],
			'rrule 03' => ['FREQ=MONTHLY;COUNT=3', null],
			'rrule 04' => ['FREQ=NOPE', 'Property \'when\': invalid RRULE \'FREQ=NOPE\' (sabre/vobject parse error)'],
			'rrule 05' => ['', null],
			'rrule 06' => ['garbage', 'Property \'when\': invalid RRULE \'garbage\' (sabre/vobject parse error)'],
			'rrule 07' => ['FREQ=DAILY;INTERVAL=0', 'Property \'when\': invalid RRULE \'FREQ=DAILY;INTERVAL=0\' (sabre/vobject parse error)'],
			'rrule 08' => ['FREQ=YEARLY;UNTIL=20200101T000000Z', null],
			'rrule 09' => [42, 'Property \'when\': recurrence value must be an RRULE string'],
			'rrule 10' => [null, 'Property \'when\': recurrence value must be an RRULE string'],
			'rrule 11' => [['FREQ=DAILY'], 'Property \'when\': recurrence value must be an RRULE string'],
		];
	}//end recordedRecurrences()

	/**
	 * Recorded materialiseOccurrences() results from START: rule, count, JSON.
	 *
	 * @return array<string, array{string, int, string}> The cases.
	 */
	public static function recordedOccurrences(): array {
		return [
			'occurrences 01' => ['FREQ=DAILY', 3, '["2024-01-01T09:30:00+00:00","2024-01-02T09:30:00+00:00","2024-01-03T09:30:00+00:00"]'],
			'occurrences 02' => ['FREQ=DAILY', 0, '["2024-01-01T09:30:00+00:00","2024-01-02T09:30:00+00:00","2024-01-03T09:30:00+00:00","2024-01-04T09:30:00+00:00","2024-01-05T09:30:00+00:00"]'],
			'occurrences 03' => ['FREQ=DAILY', -4, '["2024-01-01T09:30:00+00:00","2024-01-02T09:30:00+00:00","2024-01-03T09:30:00+00:00","2024-01-04T09:30:00+00:00","2024-01-05T09:30:00+00:00"]'],
			'occurrences 04' => ['FREQ=DAILY', 1, '["2024-01-01T09:30:00+00:00"]'],
			'occurrences 06' => ['FREQ=WEEKLY;BYDAY=MO,FR', 4, '["2024-01-01T09:30:00+00:00","2024-01-05T09:30:00+00:00","2024-01-08T09:30:00+00:00","2024-01-12T09:30:00+00:00"]'],
			'occurrences 07' => ['FREQ=MONTHLY;COUNT=2', 10, '["2024-01-01T09:30:00+00:00","2024-02-01T09:30:00+00:00"]'],
			'occurrences 08' => ['FREQ=NOPE', 3, '[]'],
			'occurrences 09' => ['', 3, '["2024-01-01T09:30:00+00:00","2024-01-01T09:30:00+00:00","2024-01-01T09:30:00+00:00"]'],
			'occurrences 10' => ['FREQ=DAILY;UNTIL=20240101T000000Z', 5, '["2024-01-01T09:30:00+00:00"]'],
			'occurrences 11' => ['FREQ=HOURLY;INTERVAL=6', 5, '["2024-01-01T09:30:00+00:00","2024-01-01T15:30:00+00:00","2024-01-01T21:30:00+00:00","2024-01-02T03:30:00+00:00","2024-01-02T09:30:00+00:00"]'],
		];
	}//end recordedOccurrences()

	/**
	 * Each colour still gets the recorded message.
	 *
	 * @param mixed       $value    The submitted colour.
	 * @param string|null $format   The declared format.
	 * @param string|null $expected The recorded message.
	 *
	 * @return void
	 */
	#[DataProvider('recordedColors')]
	public function testColorMatchesRecording(mixed $value, ?string $format, ?string $expected): void {
		$this->assertSame($expected, (new ExtendedFieldTypeValidator())->validateColorProperty($value, new FormattedProperty('tint', $format)));
	}//end testColorMatchesRecording()

	/**
	 * Each RRULE still gets the recorded message.
	 *
	 * @param mixed       $value    The submitted RRULE.
	 * @param string|null $expected The recorded message.
	 *
	 * @return void
	 */
	#[DataProvider('recordedRecurrences')]
	public function testRecurrenceMatchesRecording(mixed $value, ?string $expected): void {
		$this->assertSame($expected, (new ExtendedFieldTypeValidator())->validateRecurrence($value, 'when'));
	}//end testRecurrenceMatchesRecording()

	/**
	 * Each rule still materialises the recorded occurrences from START.
	 *
	 * @param string $rrule    The RRULE.
	 * @param int    $count    The requested count.
	 * @param string $expected The recorded occurrences, as JSON.
	 *
	 * @return void
	 */
	#[DataProvider('recordedOccurrences')]
	public function testOccurrencesMatchRecording(string $rrule, int $count, string $expected): void {
		$actual = (new ExtendedFieldTypeValidator())->occurrencesIn(new RecurrenceWindow($rrule, $count, new DateTimeImmutable(self::START)));

		$this->assertSame($expected, json_encode($actual));
	}//end testOccurrencesMatchRecording()

	/**
	 * A count above the maximum is clamped to exactly MAX_OCCURRENCES consecutive days.
	 *
	 * @return void
	 */
	public function testCountAboveMaximumIsClamped(): void {
		$actual = (new ExtendedFieldTypeValidator())->occurrencesIn(new RecurrenceWindow('FREQ=DAILY', 250, new DateTimeImmutable(self::START)));

		$this->assertCount(ExtendedFieldTypeValidator::MAX_OCCURRENCES, $actual);
		$this->assertSame('2024-01-01T09:30:00+00:00', $actual[0]);
		$this->assertSame('2024-04-09T09:30:00+00:00', $actual[99]);
	}//end testCountAboveMaximumIsClamped()

	/**
	 * Without a start the requested number of occurrences is still produced.
	 *
	 * @return void
	 */
	public function testMissingStartStillYieldsRequestedCount(): void {
		$this->assertCount(2, (new ExtendedFieldTypeValidator())->occurrencesIn(new RecurrenceWindow('FREQ=DAILY', 2)));
	}//end testMissingStartStillYieldsRequestedCount()
}//end class
