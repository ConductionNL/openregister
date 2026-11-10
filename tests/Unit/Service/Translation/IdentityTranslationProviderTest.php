<?php

/**
 * IdentityTranslationProvider: the fallback provider returns the source text unchanged.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Translation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Translation;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Translation\IdentityTranslationProvider;
use OCA\OpenRegister\Service\Translation\LanguagePair;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Translation\IdentityTranslationProvider
 */
class IdentityTranslationProviderTest extends TestCase {

	public function testTheTextComesBackUntranslatedWhateverTheLanguages(): void {
		$provider = new IdentityTranslationProvider();

		$this->assertSame('Bouwvergunning', $provider->translate('Bouwvergunning', new LanguagePair('nl', 'en')));
		$this->assertSame('', $provider->translate('', new LanguagePair('en', 'de')));
	}

	public function testItIdentifiesAsTheIdentityProvider(): void {
		$this->assertSame('identity', (new IdentityTranslationProvider())->getIdentifier());
	}
}
