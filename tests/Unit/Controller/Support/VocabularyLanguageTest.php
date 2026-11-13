<?php

/**
 * Unit test for VocabularyLanguage.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\VocabularyLanguage;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Which language a vocabulary request asks for.
 *
 * @covers \OCA\OpenRegister\Controller\Support\VocabularyLanguage
 */
class VocabularyLanguageTest extends TestCase {
	private function language(string $param, string $header): VocabularyLanguage {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->with('language', '')->willReturn($param);
		$request->method('getHeader')->with('Accept-Language')->willReturn($header);

		return new VocabularyLanguage($request);
	}

	public function testAnExplicitParameterWinsTrimmed(): void {
		$this->assertSame('en', $this->language('  en ', 'de-DE,de;q=0.9')->tag());
	}

	public function testOtherwiseTheFirstAcceptedRangeWithoutItsWeight(): void {
		$this->assertSame('de-DE', $this->language('', ' de-DE ;q=0.9, en;q=0.8')->tag());
		$this->assertSame('fr', $this->language('   ', 'fr')->tag());
	}

	public function testWithoutEitherTheDefaultIsDutch(): void {
		$this->assertSame('nl', $this->language('', '')->tag());
		$this->assertSame('nl', $this->language('', ' ;q=1, en')->tag());
	}
}
