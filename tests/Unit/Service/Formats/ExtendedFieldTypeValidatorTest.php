<?php

/**
 * ExtendedFieldTypeValidator Unit Test
 *
 * Pins the per-type contract defined in
 * openspec/specs/extended-field-types/spec.md (REQ-EFT-003, REQ-EFT-005).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Formats
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Formats;

use DateTimeImmutable;
use OCA\OpenRegister\Formats\ExtendedFieldTypeValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ExtendedFieldTypeValidator.
 *
 * Each test maps to a scenario in the extended-field-types spec.
 */
class ExtendedFieldTypeValidatorTest extends TestCase {

	private ExtendedFieldTypeValidator $validator;

	protected function setUp(): void {
		parent::setUp();
		$this->validator = new ExtendedFieldTypeValidator();
	}//end setUp()

	/*
		====================================================================
	 * color — REQ-EFT-005
	 * ==================================================================== */

	/**
	 * @dataProvider colorProvider
	 */
	public function testValidateColor(mixed $value, ?string $format, ?string $expected, string $message): void {
		$this->assertSame($expected, $this->validator->validateColor($value, $format, 'accent'), $message);
	}//end testValidateColor()

	public static function colorProvider(): array {
		return [
			'hex default 6-digit' => ['#a4b8ff', null, null, '6-digit hex passes under default format'],
			'hex default 8-digit' => ['#a4b8ffcc', null, null, '8-digit hex (alpha) passes under default format'],
			'hex default 3-digit' => ['#fff', null, null, '3-digit hex passes'],
			'hex explicit' => ['#000000', 'hex', null, 'explicit hex format accepts hex'],
			'hex rejects rgba' => [
				'rgba(0,0,0,1)',
				'hex',
				"Property 'accent' declared format 'hex'; 'rgba(0,0,0,1)' does not match hex regex",
				'declared hex rejects rgba with exact message',
			],
			'rgba 4 components' => ['rgba(164, 184, 255, 0.8)', 'rgba', null, 'rgba with 4 components passes'],
			'rgba 3 components' => [
				'rgba(164, 184, 255)',
				'rgba',
				"Property 'accent' format 'rgba' requires 4 components; got 3",
				'rgba with 3 components fails with exact message',
			],
			'oklch valid' => ['oklch(0.7 0.15 250)', 'oklch', null, 'oklch L C H passes'],
			'oklch with pct and alpha' => ['oklch(70% 0.15 250deg / 0.5)', 'oklch', null, 'oklch with percentage and alpha passes'],
			'oklch rejects hex' => [
				'#fff',
				'oklch',
				"Property 'accent' declared format 'oklch'; '#fff' does not match oklch regex",
				'declared oklch rejects hex',
			],
		];
	}//end colorProvider()

	/*
		====================================================================
	 * recurrence — REQ-EFT-003
	 * ==================================================================== */

	public function testValidRruleReturnsNull(): void {
		$this->assertNull(
			$this->validator->validateRecurrence('FREQ=WEEKLY;BYDAY=MO,WE;UNTIL=20261231T235959Z', 'pattern'),
			'a valid RRULE must validate cleanly'
		);
	}//end testValidRruleReturnsNull()

	public function testInvalidRruleReturnsExactMessage(): void {
		$this->assertSame(
			"Property 'pattern': invalid RRULE 'BANANA=DAILY' (sabre/vobject parse error)",
			$this->validator->validateRecurrence('BANANA=DAILY', 'pattern'),
			'an invalid RRULE must fail with the exact spec message'
		);
	}//end testInvalidRruleReturnsExactMessage()

	public function testMaterialiseOccurrencesReturnsRequestedCount(): void {
		// Anchor on a fixed Monday so the result is deterministic.
		$start = new DateTimeImmutable('2026-01-05T00:00:00Z');
		$occurrences = $this->validator->materialiseOccurrences('FREQ=WEEKLY;BYDAY=MO;COUNT=10', 3, $start);

		$this->assertCount(3, $occurrences, 'exactly 3 occurrences requested');
		$this->assertSame('2026-01-05T00:00:00+00:00', $occurrences[0], 'first Monday is the anchor date');
		$this->assertSame('2026-01-12T00:00:00+00:00', $occurrences[1], 'second Monday is one week later');
		$this->assertSame('2026-01-19T00:00:00+00:00', $occurrences[2], 'third Monday is two weeks later');
	}//end testMaterialiseOccurrencesReturnsRequestedCount()

	public function testMaterialiseOccurrencesClampsToMax(): void {
		$start = new DateTimeImmutable('2026-01-05T00:00:00Z');
		$occurrences = $this->validator->materialiseOccurrences('FREQ=DAILY', 1000, $start);

		$this->assertCount(
			ExtendedFieldTypeValidator::MAX_OCCURRENCES,
			$occurrences,
			'requested count above the max must be clamped to MAX_OCCURRENCES'
		);
	}//end testMaterialiseOccurrencesClampsToMax()

	public function testMaterialiseOccurrencesFallsBackToDefaultForZero(): void {
		$start = new DateTimeImmutable('2026-01-05T00:00:00Z');
		$occurrences = $this->validator->materialiseOccurrences('FREQ=DAILY', 0, $start);

		$this->assertCount(
			ExtendedFieldTypeValidator::DEFAULT_OCCURRENCES,
			$occurrences,
			'a zero count falls back to the default occurrence count'
		);
	}//end testMaterialiseOccurrencesFallsBackToDefaultForZero()
}//end class
