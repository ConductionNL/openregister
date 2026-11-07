<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\LanguageService;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for LanguageService: pins Accept-Language parsing, register
 * language resolution (with the fallback flag) and the request-scoped setters.
 *
 * @covers \OCA\OpenRegister\Service\LanguageService
 */
final class LanguageServiceGoldenTest extends TestCase {

	public function testParseAcceptLanguageHeader(): void {
		$cases = [
			'' => [],
			'   ' => [],
			'*' => [],
			'nl' => ['nl'],
			'en-US,en;q=0.9,nl;q=0.8' => ['en-US', 'en', 'nl'],
			'nl;q=0.5, de , fr;q=0.9' => ['de', 'fr', 'nl'],
			'a;q=0.5,b;q=0.5,c,d;q=0.5' => ['c', 'a', 'b', 'd'],
			'en, *, ;q=1, ,fr' => ['en', 'fr'],
			'*;q=0.1, de' => ['de'],
			'de;level=1;q=0.3,en;foo;bar' => ['en', 'de'],
			'de; q=0.4 ,en;q=' => ['de', 'en'],
			'x;q=abc,y;q=0.1' => ['y', 'x'],
			'x;Q=0.1,y;q=0.2' => ['x', 'y'],
			'x;q=0.2;q=0.9,y;q=0.5' => ['y', 'x'],
			'x;q=1e999,y' => ['x', 'y'],
			'x;q=-1,y;q=0' => ['y', 'x'],
			' * ' => [],
			'  de-AT  ;  q=0.7 , en' => ['en', 'de-AT'],
		];
		foreach ($cases as $header => $expected) {
			$this->assertSame($expected, LanguageService::parseAcceptLanguageHeader((string)$header), '"' . $header . '"');
		}
	}

	public function testResolveLanguageForRegister(): void {
		$cases = [
			[['en-US', 'nl'], [], 'nl', false],
			[['en-US', 'nl'], ['de', 'en-US'], 'en-US', false],
			[['en-US', 'nl'], ['nl', 'en'], 'en', false],
			[['EN-gb'], ['de', 'en'], 'en', false],
			[['fr-CA', 'de'], ['de', 'fr'], 'fr', false],
			[['fr', 'de'], ['it', 'es'], 'it', true],
			[[], ['it', 'es'], 'it', true],
			[['zz'], ['nl'], 'nl', true],
		];
		foreach ($cases as $index => [$accepted, $available, $expected, $fallback]) {
			$service = new LanguageService();
			$service->setPreferredLanguage('nl');
			$service->setAcceptedLanguages($accepted);
			$this->assertSame($expected, $service->resolveLanguageForRegister($available), (string)$index);
			$this->assertSame($fallback, $service->isFallbackUsed(), (string)$index);
		}
	}

	public function testEmptyRegisterLanguagesReturnPreferredWithoutFallback(): void {
		$service = new LanguageService();
		$service->setPreferredLanguage('de');
		$this->assertSame('de', $service->resolveLanguageForRegister([]));
		$this->assertFalse($service->isFallbackUsed());
	}

	public function testFallbackFlagIsNeverClearedByResolution(): void {
		$service = new LanguageService();
		$service->setAcceptedLanguages(['en']);
		$service->resolveLanguageForRegister(['nl']);
		$this->assertSame('en', $service->resolveLanguageForRegister(['en']));
		$this->assertTrue($service->isFallbackUsed());
	}

	public function testDefaultsAndSetters(): void {
		$service = new LanguageService();
		$this->assertSame('nl', $service->getPreferredLanguage());
		$this->assertSame([], $service->getAcceptedLanguages());
		$this->assertFalse($service->shouldReturnAllTranslations());
		$this->assertFalse($service->isFallbackUsed());
		$this->assertSame('default', $service->getRequestedLanguageSource());
		$this->assertNull($service->getTargetLanguage());

		$service->setPreferredLanguage('en');
		$service->setAcceptedLanguages(['en', 'nl']);
		$service->setReturnAllTranslations(true);
		$service->setFallbackUsed(true);
		$this->assertSame('en', $service->getPreferredLanguage());
		$this->assertSame(['en', 'nl'], $service->getAcceptedLanguages());
		$this->assertTrue($service->shouldReturnAllTranslations());
		$this->assertTrue($service->isFallbackUsed());
	}

	public function testRequestedLanguageSourceAcceptsOnlyKnownSources(): void {
		$service = new LanguageService();
		$service->setRequestedLanguageSource('query');
		$this->assertSame('query', $service->getRequestedLanguageSource());
		$service->setRequestedLanguageSource('cookie');
		$this->assertSame('query', $service->getRequestedLanguageSource());
		$service->setRequestedLanguageSource('Header');
		$this->assertSame('query', $service->getRequestedLanguageSource());
		$service->setRequestedLanguageSource('header');
		$this->assertSame('header', $service->getRequestedLanguageSource());
	}

	public function testTargetLanguageIsTrimmedAndBlankClears(): void {
		$service = new LanguageService();
		$service->setTargetLanguage('  en-GB ');
		$this->assertSame('en-GB', $service->getTargetLanguage());
		$service->setTargetLanguage('   ');
		$this->assertNull($service->getTargetLanguage());
		$service->setTargetLanguage('de');
		$service->setTargetLanguage(null);
		$this->assertNull($service->getTargetLanguage());
	}
}
