<?php

/**
 * A translation save target wraps in the target language when one is set, else in the default.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\TranslationSaveTarget;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Object\TranslationSaveTarget
 */
class TranslationSaveTargetTest extends TestCase {

	public function testTargetLanguageWinsWhenSet(): void {
		$target = new TranslationSaveTarget(defaultLanguage: 'nl', targetLanguage: 'en');

		$this->assertTrue($target->hasTarget());
		$this->assertSame('en', $target->wrappingLanguage());
	}//end testTargetLanguageWinsWhenSet()

	public function testNullOrEmptyTargetFallsBackToTheDefault(): void {
		$none  = new TranslationSaveTarget(defaultLanguage: 'nl', targetLanguage: null);
		$empty = new TranslationSaveTarget(defaultLanguage: 'nl', targetLanguage: '');

		$this->assertFalse($none->hasTarget());
		$this->assertFalse($empty->hasTarget());
		$this->assertSame('nl', $none->wrappingLanguage());
		$this->assertSame('nl', $empty->wrappingLanguage());
	}//end testNullOrEmptyTargetFallsBackToTheDefault()

	public function testForPropertyKeepsLanguagesAndLeavesTheOriginalUnchanged(): void {
		$target = new TranslationSaveTarget(defaultLanguage: 'nl', targetLanguage: 'de');

		$scoped = $target->forProperty('title');

		$this->assertNotSame($target, $scoped);
		$this->assertSame('', $target->property);
		$this->assertSame('title', $scoped->property);
		$this->assertSame('nl', $scoped->defaultLanguage);
		$this->assertSame('de', $scoped->targetLanguage);
	}//end testForPropertyKeepsLanguagesAndLeavesTheOriginalUnchanged()
}//end class
