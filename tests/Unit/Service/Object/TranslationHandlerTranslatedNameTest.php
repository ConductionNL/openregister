<?php

/**
 * Tests for the object name a reader sees when the name comes from a translatable property.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * TranslationHandler::translatedName().
 */
class TranslationHandlerTranslatedNameTest extends TestCase {

	/**
	 * A handler whose language service returns all translations or not.
	 *
	 * @param bool $all Whether all translations are requested.
	 *
	 * @return TranslationHandler The handler.
	 */
	private function handler(bool $all = false): TranslationHandler {
		$languages = $this->createMock(originalClassName: LanguageService::class);
		$languages->method('shouldReturnAllTranslations')->willReturn($all);

		return new TranslationHandler(languageService: $languages, logger: $this->createMock(originalClassName: LoggerInterface::class));
	}//end handler()

	/**
	 * A schema with a translatable title and a plain code.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setProperties(
			[
				'title' => ['type' => 'string', 'translatable' => true],
				'code' => ['type' => 'string'],
			]
		);
		return $schema;
	}//end schema()

	/**
	 * A name taken from a translatable property reads in the resolved language.
	 *
	 * @return void
	 */
	public function testANameFromATranslatablePropertyFollowsTheReader(): void {
		$stored = ['title' => ['nl' => 'Klacht behandeling', 'en' => 'Complaint handling'], 'code' => 'KL'];
		$resolved = ['title' => 'Complaint handling', 'code' => 'KL'];

		self::assertSame(
			expected: 'Complaint handling',
			actual: $this->handler()->translatedName(stored: $stored, resolved: $resolved, schema: $this->schema(), name: 'Klacht behandeling')
		);
	}//end testANameFromATranslatablePropertyFollowsTheReader()

	/**
	 * A name that is not one of the property's values stays as stored.
	 *
	 * @return void
	 */
	public function testANameFromElsewhereStays(): void {
		$stored = ['title' => ['nl' => 'Klacht behandeling', 'en' => 'Complaint handling'], 'code' => 'KL'];
		$resolved = ['title' => 'Complaint handling', 'code' => 'KL'];

		self::assertNull(
			actual: $this->handler()->translatedName(stored: $stored, resolved: $resolved, schema: $this->schema(), name: 'KL')
		);
	}//end testANameFromElsewhereStays()

	/**
	 * Without a name, a language map or a resolved string there is nothing to replace.
	 *
	 * @return void
	 */
	public function testNothingToReplace(): void {
		$map = ['title' => ['nl' => 'Klacht behandeling', 'en' => 'Complaint handling']];

		$handler = $this->handler();
		$schema  = $this->schema();

		self::assertNull(actual: $handler->translatedName(stored: $map, resolved: ['title' => 'Complaint handling'], schema: $schema, name: null));
		self::assertNull(actual: $handler->translatedName(stored: ['title' => 'Klacht'], resolved: ['title' => 'Klacht'], schema: $schema, name: 'Klacht'));
		self::assertNull(actual: $handler->translatedName(stored: $map, resolved: $map, schema: $schema, name: 'Klacht behandeling'));
	}//end testNothingToReplace()

	/**
	 * An untranslated property that also carries the name does not hide the translated one.
	 *
	 * @return void
	 */
	public function testAnUntranslatedPropertyWithTheSameTextDoesNotWin(): void {
		$schema = new Schema();
		$schema->setProperties(
			[
				'subject' => ['type' => 'string', 'translatable' => true],
				'title' => ['type' => 'string', 'translatable' => true],
			]
		);
		$stored = ['subject' => ['nl' => 'Omgevingsvergunning'], 'title' => ['nl' => 'Omgevingsvergunning', 'en' => 'Environmental permit']];
		$resolved = ['subject' => 'Omgevingsvergunning', 'title' => 'Environmental permit'];

		self::assertSame(
			expected: 'Environmental permit',
			actual: $this->handler()->translatedName(stored: $stored, resolved: $resolved, schema: $schema, name: 'Omgevingsvergunning')
		);
	}//end testAnUntranslatedPropertyWithTheSameTextDoesNotWin()

	/**
	 * The configured name field decides between properties that both carry the name.
	 *
	 * @return void
	 */
	public function testTheConfiguredNameFieldDecides(): void {
		$schema = new Schema();
		$schema->setProperties(
			[
				'subject' => ['type' => 'string', 'translatable' => true],
				'title' => ['type' => 'string', 'translatable' => true],
			]
		);
		$schema->setConfiguration(['objectNameField' => 'title']);
		$stored = ['subject' => ['nl' => 'Vergunning', 'en' => 'Permit topic'], 'title' => ['nl' => 'Vergunning', 'en' => 'Permit']];
		$resolved = ['subject' => 'Permit topic', 'title' => 'Permit'];

		self::assertSame(
			expected: 'Permit',
			actual: $this->handler()->translatedName(stored: $stored, resolved: $resolved, schema: $schema, name: 'Vergunning')
		);
	}//end testTheConfiguredNameFieldDecides()

	/**
	 * A request for all translations keeps the stored name.
	 *
	 * @return void
	 */
	public function testAllTranslationsKeepTheStoredName(): void {
		$stored = ['title' => ['nl' => 'Klacht behandeling', 'en' => 'Complaint handling']];

		self::assertNull(
			actual: $this->handler(all: true)->translatedName(stored: $stored, resolved: $stored, schema: $this->schema(), name: 'Klacht behandeling')
		);
	}//end testAllTranslationsKeepTheStoredName()
}//end class
