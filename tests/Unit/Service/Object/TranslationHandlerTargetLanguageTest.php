<?php

/**
 * TranslationHandler tests for the `X-Translation-Target-Language` write-side contract.
 *
 * Covers:
 *  - Scalar body + header → wrap under the target language.
 *  - Full language-keyed body + header → throw `TranslationTargetConflictException`.
 *  - Scalar body without header → wrap under register default (legacy).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/i18n-api-language-negotiation/tasks.md#phase-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\TranslationTargetConflictException;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Verifies the write-side wrap behaviour.
 */
class TranslationHandlerTargetLanguageTest extends TestCase {

	private Schema $schema;
	private Register $register;

	protected function setUp(): void {
		parent::setUp();
		$this->schema = new Schema();
		$this->schema->setProperties([
			'title' => ['type' => 'string', 'translatable' => true],
		]);
		$this->register = new Register();
		$this->register->setLanguages(['nl', 'en']);
	}//end setUp()

	public function testScalarBodyAndHeaderWrapsUnderTarget(): void {
		$languageService = new LanguageService();
		$languageService->setTargetLanguage('en');

		$handler = new TranslationHandler($languageService, new NullLogger());

		$normalised = $handler->normalizeTranslationsForSave(
			objectData: ['title' => 'Welcome'],
			schema: $this->schema,
			register: $this->register
		);

		$this->assertSame(['en' => 'Welcome'], $normalised['title']);
	}//end testScalarBodyAndHeaderWrapsUnderTarget()

	public function testFullLanguageBodyAndHeaderThrowsConflict(): void {
		$languageService = new LanguageService();
		$languageService->setTargetLanguage('en');

		$handler = new TranslationHandler($languageService, new NullLogger());

		$this->expectException(TranslationTargetConflictException::class);

		$handler->normalizeTranslationsForSave(
			objectData: ['title' => ['nl' => 'Hallo', 'en' => 'Hello']],
			schema: $this->schema,
			register: $this->register
		);
	}//end testFullLanguageBodyAndHeaderThrowsConflict()

	public function testScalarBodyWithoutHeaderWrapsUnderRegisterDefault(): void {
		$languageService = new LanguageService();
		// No setTargetLanguage call — legacy behaviour.

		$handler = new TranslationHandler($languageService, new NullLogger());

		$normalised = $handler->normalizeTranslationsForSave(
			objectData: ['title' => 'Welkom'],
			schema: $this->schema,
			register: $this->register
		);

		$this->assertSame(['nl' => 'Welkom'], $normalised['title']);
	}//end testScalarBodyWithoutHeaderWrapsUnderRegisterDefault()

	public function testConflictExceptionCarriesPropertyAndTarget(): void {
		$exception = new TranslationTargetConflictException('title', 'en');

		$this->assertSame('title', $exception->getProperty());
		$this->assertSame('en', $exception->getTargetLanguage());

		$body = $exception->toErrorBody();
		$this->assertSame('TRANSLATION_TARGET_CONFLICT', $body['error']['code']);
		$this->assertSame('title', $body['error']['property']);
		$this->assertSame('en', $body['error']['targetLanguage']);

		// CustomValidationException carries structured errors via getErrors().
		$errors = $exception->getErrors();
		$this->assertSame('TRANSLATION_TARGET_CONFLICT', $errors['code']);
		$this->assertSame('title', $errors['property']);
		$this->assertSame('en', $errors['targetLanguage']);
	}//end testConflictExceptionCarriesPropertyAndTarget()
}//end class
