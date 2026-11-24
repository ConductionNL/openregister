<?php

/**
 * AcceptLanguageHeader: the ranges of an Accept-Language header, best first, the
 * same answer the language negotiation gives, and the schema read's language.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Service\AcceptLanguageHeader;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Schemas\PropertyTitles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\AcceptLanguageHeader
 * @uses   \OCA\OpenRegister\Service\LanguageService
 * @uses   \OCA\OpenRegister\Service\Schemas\PropertyTitles
 *
 * @spec openspec/specs/register-i18n/spec.md#the-api-must-support-language-negotiation-via-accept-language-header
 */
final class AcceptLanguageHeaderTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: array<int, string>}>
	 */
	public static function headers(): array {
		return [
			'empty' => ['', []],
			'blank' => ['   ', []],
			'wildcard' => ['*', []],
			'one' => ['nl', ['nl']],
			'by quality' => ['en-US,en;q=0.9,nl;q=0.8', ['en-US', 'en', 'nl']],
			'unordered' => ['nl;q=0.5, de , fr;q=0.9', ['de', 'fr', 'nl']],
			'ties keep their order' => ['a;q=0.5,b;q=0.5,c,d;q=0.5', ['c', 'a', 'b', 'd']],
			'empty and wildcard ranges' => ['en, *, ;q=1, ,fr', ['en', 'fr']],
			'other parameters' => ['de;level=1;q=0.3,en;foo;bar', ['en', 'de']],
			'first q wins' => ['x;q=0.2;q=0.9,y;q=0.5', ['y', 'x']],
			'padded' => ['  de-AT  ;  q=0.7 , en', ['en', 'de-AT']],
		];
	}

	/**
	 * @param array<int, string> $expected
	 */
	#[DataProvider('headers')]
	public function testTheRangesComeBestFirst(string $header, array $expected): void {
		self::assertSame($expected, AcceptLanguageHeader::ranked(headerValue: $header));
	}

	/**
	 * @param array<int, string> $expected
	 */
	#[DataProvider('headers')]
	public function testTheLanguageNegotiationGivesTheSameAnswer(string $header, array $expected): void {
		self::assertSame($expected, LanguageService::parseAcceptLanguageHeader($header));
	}

	public function testASchemaReadAsksForTheBestRange(): void {
		self::assertSame('de', PropertyTitles::requestedLanguage(langParam: null, acceptLanguage: 'nl;q=0.5, de , fr;q=0.9'));
		self::assertNull(PropertyTitles::requestedLanguage(langParam: null, acceptLanguage: '*'));
		self::assertSame('fr', PropertyTitles::requestedLanguage(langParam: ' fr ', acceptLanguage: 'de'));
	}
}
