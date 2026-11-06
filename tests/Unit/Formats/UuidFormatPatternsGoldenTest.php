<?php

/**
 * The inline uuid patterns that UuidFormat replaces, against UuidFormat itself.
 *
 * Each pattern below was written inline at the sites listed with it. A site
 * moves to UuidFormat only when its pattern accepts and rejects exactly what the
 * method does, on every string of the corpus. Patterns that answer differently
 * stay inline at their sites; `keptPatterns()` names one string that proves why.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Formats
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec exclude Pins inline patterns against UuidFormat; adds no behaviour.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Formats;

use OCA\OpenRegister\Formats\UuidFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Formats\UuidFormat
 */
class UuidFormatPatternsGoldenTest extends TestCase {

	private const UUID = '550e8400-e29b-41d4-a716-446655440000';

	private const HEX = '550e8400e29b41d4a716446655440000';

	/**
	 * Strings on both sides of every pattern, and on their edges.
	 *
	 * @return list<string>
	 */
	private static function corpus(): array {
		return [
			'',
			' ',
			'0',
			'42',
			'not-a-uuid',
			self::UUID,
			strtoupper(self::UUID),
			'550E8400-e29b-41D4-a716-446655440000',
			' ' . self::UUID,
			self::UUID . ' ',
			self::UUID . "\n",
			"\n" . self::UUID,
			self::UUID . "\n\n",
			self::UUID . '0',
			substr(self::UUID, 1),
			substr(self::UUID, 0, -1),
			'{' . self::UUID . '}',
			'urn:uuid:' . self::UUID,
			'550e8400e29b-41d4-a716-446655440000',
			'g50e8400-e29b-41d4-a716-446655440000',
			'00000000-0000-0000-0000-000000000000',
			'ffffffff-ffff-ffff-ffff-ffffffffffff',
			'٥٥٠e8400-e29b-41d4-a716-446655440000',
			self::HEX,
			strtoupper(self::HEX),
			self::HEX . "\n",
			self::HEX . '0',
			substr(self::HEX, 1),
			'id-' . self::UUID,
			'ID-' . self::UUID,
			'Ref-' . strtoupper(self::UUID),
			'id-' . self::UUID . "\n",
			'id2-' . self::UUID,
			'-' . self::UUID,
			'a-b-' . self::UUID,
			'id-' . self::HEX,
			'ID-' . strtoupper(self::HEX),
			"id\n-" . self::UUID,
		];
	}//end corpus()

	/**
	 * Each replaced pattern, the method that replaces it, and where it was written.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function replacedPatterns(): array {
		$canonical = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

		return [
			'canonical, case-insensitive flag' => [$canonical, 'isCanonical'],
			'canonical, both cases spelled out (ObjectShareLinkController, ShareGrantAttributes)' => [
				'/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
				'isCanonical',
			],
			'32 hex (SaveObject::isReference)' => ['/^[0-9a-f]{32}$/i', 'isHex32'],
			'prefixed (SaveObject::isReference)' => [
				'/^[a-z]+-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
				'isPrefixed',
			],
		];
	}//end replacedPatterns()

	/**
	 * A replaced pattern and its method agree on the whole corpus.
	 *
	 * @param string $pattern The inline pattern.
	 * @param string $method  The UuidFormat method.
	 *
	 * @return void
	 */
	#[DataProvider('replacedPatterns')]
	public function testAPatternAndItsMethodAcceptAndRejectTheSameStrings(string $pattern, string $method): void {
		$accepted = 0;
		foreach (self::corpus() as $value) {
			$inline = (preg_match($pattern, $value) === 1);
			$accepted += (int)$inline;
			$this->assertSame($inline, UuidFormat::$method(value: $value), json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE));
		}

		$this->assertGreaterThan(1, $accepted);
	}//end testAPatternAndItsMethodAcceptAndRejectTheSameStrings()

	/**
	 * Why a pattern stays inline: a string it answers differently from every method.
	 *
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public static function keptPatterns(): array {
		$uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
		$any = '/^([a-z]+-)?(' . $uuid . '|[0-9a-f]{32})$/i';

		return [
			'UuidPattern::ANY takes a prefix on 32 hex' => [$any, 'id-' . self::HEX, true],
			'SaveObject::isReference takes a prefix on 32 hex' => ['/^[a-z]+-[0-9a-f]{32}$/i', 'id-' . self::HEX, true],
			'SaveObject::isObjectIdentifier takes a prefix on either form' => ['/^[a-z]+-(' . $uuid . '|[0-9a-f]{32})$/i', 'id-' . self::HEX, true],
			'SaveObject::referenceKey is lowercase only' => ['/^[a-z]+-(' . $uuid . '|[0-9a-f]{32})$/', 'ID-' . self::UUID, false],
			'ApiCallRecorder takes digits and long hex' => ['/^(?:[0-9]+|' . $uuid . '|[0-9a-f]{24,})$/i', '42', true],
			'ValidateObject::UUID_REFERENCE_PATTERN takes digits' => ['/^([a-z]+-)?(' . $uuid . '|[0-9a-f]{32}|[0-9]+)$/', '42', true],
			'FormUploadStore token is lowercase only' => ['/^[a-f0-9]{32}$/', strtoupper(self::HEX), false],
		];
	}//end keptPatterns()

	/**
	 * A kept pattern gives this answer, and no UuidFormat method gives it alone.
	 *
	 * @param string $pattern The inline pattern.
	 * @param string $value   A string that separates it.
	 * @param bool   $accepts What the pattern answers.
	 *
	 * @return void
	 */
	#[DataProvider('keptPatterns')]
	public function testAKeptPatternAnswersDifferentlyFromUuidFormat(string $pattern, string $value, bool $accepts): void {
		$this->assertSame($accepts, preg_match($pattern, $value) === 1);
		$this->assertNotSame($accepts, UuidFormat::isAny(value: $value));
	}//end testAKeptPatternAnswersDifferentlyFromUuidFormat()
}//end class
