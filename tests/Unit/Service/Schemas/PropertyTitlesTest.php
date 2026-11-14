<?php

/**
 * A property carries its name per language, the save refuses a bad map, and a read can answer in one language.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

use OCA\OpenRegister\Service\Schemas\PropertyTitles;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCA\OpenRegister\Service\Schemas\PropertyVocabulary;
use OCA\OpenRegister\Service\Schemas\PropertyVocabularyException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the `titles` modifier.
 */
class PropertyTitlesTest extends TestCase {

	public function testANameInEachLanguageIsAccepted(): void {
		$this->assertTrue(
			(new PropertyValidatorHandler())->validateProperties(
				['contractEnd' => ['type' => 'string', 'format' => 'date', 'title' => 'Contract end', 'titles' => ['nl' => 'Einddatum', 'en' => 'End date', 'pt-BR' => 'Data final']]],
				''
			)
		);
	}//end testANameInEachLanguageIsAccepted()

	/**
	 * Refused maps, the key the refusal names and its message.
	 *
	 * @return array<string, array{0: mixed, 1: string, 2: string}>
	 */
	public static function refusedTitles(): array {
		return [
			'not a language tag' => [
				['xx_bad key' => 'A'],
				'xx_bad key',
				"'titles' at '/contractEnd' has 'xx_bad key', which is not a language tag such as nl or pt-BR",
			],
			'a sentence as key' => [
				['dutch language' => 'Einddatum'],
				'dutch language',
				"'titles' at '/contractEnd' has 'dutch language', which is not a language tag such as nl or pt-BR",
			],
			'an empty name' => [['nl' => ''], 'nl', "'titles.nl' at '/contractEnd' must be a non-empty name"],
			'a blank name' => [['en' => 'End date', 'nl' => '  '], 'nl', "'titles.nl' at '/contractEnd' must be a non-empty name"],
			'a number as name' => [['nl' => 7], 'nl', "'titles.nl' at '/contractEnd' must be a non-empty name"],
			'a list' => [['Einddatum'], 'titles', "'titles' at '/contractEnd' must be an object of language tags to names"],
			'a string' => ['Einddatum', 'titles', "'titles' at '/contractEnd' must be an object of language tags to names"],
		];
	}//end refusedTitles()

	#[DataProvider('refusedTitles')]
	public function testABadMapIsRefusedNamingThePropertyAndTheKey(mixed $titles, string $key, string $message): void {
		try {
			(new PropertyValidatorHandler())->validateProperties(['contractEnd' => ['type' => 'string', 'titles' => $titles]], '');
			$this->fail('Expected a PropertyVocabularyException.');
		} catch (PropertyVocabularyException $e) {
			$this->assertSame($message, $e->getMessage());
			$this->assertSame(
				[['code' => 'property-vocabulary-invalid-titles', 'key' => $key, 'path' => '/contractEnd', 'message' => $message]],
				$e->getErrors()
			);
		}
	}//end testABadMapIsRefusedNamingThePropertyAndTheKey()

	public function testTheVocabularyPublishesTheModifier(): void {
		$modifiers = array_column((new PropertyVocabulary())->modifiers(), null, 'key');

		$this->assertArrayHasKey('titles', $modifiers);
		$this->assertSame('object', $modifiers['titles']['value']);
	}//end testTheVocabularyPublishesTheModifier()

	/**
	 * Requests and the language they name.
	 *
	 * @return array<string, array{0: mixed, 1: mixed, 2: string|null}>
	 */
	public static function requests(): array {
		return [
			'_lang wins' => ['en', 'nl-NL,nl;q=0.9', 'en'],
			'_lang trimmed' => [' nl-BE ', '', 'nl-BE'],
			'header by quality' => [null, 'de;q=0.5,nl-BE,nl;q=0.9', 'nl-BE'],
			'neither' => [null, '', null],
			'blank _lang, no header' => ['', null, null],
		];
	}//end requests()

	#[DataProvider('requests')]
	public function testTheRequestedLanguage(mixed $lang, mixed $header, ?string $expected): void {
		$this->assertSame($expected, PropertyTitles::requestedLanguage($lang, $header));
	}//end testTheRequestedLanguage()

	/**
	 * A stored schema with a named field, a nested one and one without names.
	 *
	 * @return array
	 */
	private static function schema(): array {
		return [
			'title' => 'Klant',
			'properties' => [
				'contractEnd' => ['type' => 'string', 'title' => 'Contract end', 'titles' => ['nl' => 'Einddatum contract', 'en' => 'Contract end date']],
				'address' => [
					'type' => 'object',
					'title' => 'Address',
					'properties' => ['street' => ['type' => 'string', 'title' => 'Street', 'titles' => ['nl' => 'Straat']]],
				],
				'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'title' => 'Tag', 'titles' => ['NL' => 'Label']]],
				'name' => ['type' => 'string', 'title' => 'Name'],
				'odd' => 'not a property',
			],
		];
	}//end schema()

	public function testAReadInALanguageAnswersTheTitlesAndKeepsTheMap(): void {
		$projected = PropertyTitles::projected(self::schema(), 'en');

		$this->assertSame('Contract end date', $projected['properties']['contractEnd']['title']);
		$this->assertSame(['nl' => 'Einddatum contract', 'en' => 'Contract end date'], $projected['properties']['contractEnd']['titles']);
		$this->assertSame('Street', $projected['properties']['address']['properties']['street']['title']);
		$this->assertSame('Name', $projected['properties']['name']['title']);
		$this->assertSame('Klant', $projected['title']);
	}//end testAReadInALanguageAnswersTheTitlesAndKeepsTheMap()

	public function testARegionalTagFallsBackToItsBaseLanguageAndNestedFieldsFollow(): void {
		$projected = PropertyTitles::projected(self::schema(), 'nl-BE');

		$this->assertSame('Einddatum contract', $projected['properties']['contractEnd']['title']);
		$this->assertSame('Straat', $projected['properties']['address']['properties']['street']['title']);
		$this->assertSame('Label', $projected['properties']['tags']['items']['title']);
		$this->assertSame('Address', $projected['properties']['address']['title']);
		$this->assertSame('not a property', $projected['properties']['odd']);
	}//end testARegionalTagFallsBackToItsBaseLanguageAndNestedFieldsFollow()

	public function testALanguageWithoutANameKeepsTheStoredSchema(): void {
		$this->assertSame(self::schema(), PropertyTitles::projected(self::schema(), 'de'));
		$this->assertSame(['title' => 'No properties'], PropertyTitles::projected(['title' => 'No properties'], 'nl'));
	}//end testALanguageWithoutANameKeepsTheStoredSchema()
}//end class
