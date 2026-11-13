<?php

/**
 * Unit tests for a schema's classification and catalogue block: vocabulary, refusals, anonymous view and storage.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/modelling-type-catalogue-metadata/specs/schema-catalogue-metadata/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Formats\EmailFormat;
use OCA\OpenRegister\Service\Schemas\SchemaCatalogue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Closed vocabularies, a 400 naming the field, no contact for an anonymous reader, and set-only serialisation.
 *
 * @covers \OCA\OpenRegister\Service\Schemas\SchemaCatalogue
 * @covers \OCA\OpenRegister\Formats\EmailFormat
 * @covers \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValueRules
 * @uses   \OCA\OpenRegister\Db\Schema\TopLevelAnnotationFold
 * @uses   \OCA\OpenRegister\Db\Schema\AuthorizationRulesValidator
 * @uses   \OCA\OpenRegister\Db\Schema\DeclaredActions
 */
final class SchemaCatalogueTest extends TestCase {

	private const CATALOGUE = [
		'maintainer'       => ['organisation' => 'Gemeente Voorbeeld', 'department' => 'Openbare ruimte'],
		'contact'          => ['name' => 'Team data', 'email' => 'data@voorbeeld.nl'],
		'sourceSystem'     => 'Meldingen app',
		'updateFrequency'  => 'daily',
		'documentationUrl' => 'https://voorbeeld.nl/docs',
		'labels'           => ['meldingen'],
	];

	public function testAFullBlockAndEveryClassificationAreAccepted(): void {
		foreach (SchemaCatalogue::CLASSIFICATIONS as $classification) {
			$this->assertNull(SchemaCatalogue::violation(['classification' => $classification, 'catalogue' => self::CATALOGUE]));
		}

		$this->assertNull(SchemaCatalogue::violation([]), 'absent fields are not checked');
		$this->assertNull(SchemaCatalogue::violation(['classification' => null, 'catalogue' => null]), 'null clears');
		$this->assertNull(SchemaCatalogue::violation(['catalogue' => []]), 'an empty block is an object');
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusals(): array {
		return [
			'unknown classification'  => [['classification' => 'secret'], 'classification'],
			'classification not text' => [['classification' => 1], 'classification'],
			'catalogue a list'        => [['catalogue' => ['a', 'b']], 'catalogue'],
			'catalogue a string'      => [['catalogue' => 'x'], 'catalogue'],
			'maintainer a string'     => [['catalogue' => ['maintainer' => 'Gemeente']], 'catalogue.maintainer'],
			'contact a list'          => [['catalogue' => ['contact' => ['x']]], 'catalogue.contact'],
			'unknown frequency'       => [['catalogue' => ['updateFrequency' => 'hourly']], 'catalogue.updateFrequency'],
			'malformed e-mail'        => [['catalogue' => ['contact' => ['email' => 'not-an-address']]], 'catalogue.contact.email'],
			'e-mail not text'         => [['catalogue' => ['contact' => ['email' => 42]]], 'catalogue.contact.email'],
		];
	}

	#[DataProvider('refusals')]
	public function testARefusalNamesTheField(array $data, string $field): void {
		$violation = SchemaCatalogue::violation($data);

		$this->assertNotNull($violation);
		$this->assertSame($field, $violation['field']);
		$this->assertStringStartsWith($field, $violation['message']);
	}

	public function testTheClassificationMessageListsTheVocabulary(): void {
		$this->assertSame(
			['field' => 'classification', 'message' => 'classification must be one of: open, internal, confidential, strictly-confidential.'],
			SchemaCatalogue::violation(['classification' => 'secret'])
		);
	}

	public function testAnAnonymousViewDropsOnlyTheContact(): void {
		$view = SchemaCatalogue::withoutContact(['classification' => 'open', 'catalogue' => self::CATALOGUE, 'title' => 'Meldingen']);

		$this->assertArrayNotHasKey('contact', $view['catalogue']);
		$this->assertSame('Meldingen app', $view['catalogue']['sourceSystem']);
		$this->assertSame('open', $view['classification']);
		$this->assertSame(['title' => 'T'], SchemaCatalogue::withoutContact(['title' => 'T']), 'a schema without a block is untouched');
	}

	public function testTheEmailFormat(): void {
		$this->assertTrue((new EmailFormat())->validate('data@voorbeeld.nl'));
		$this->assertFalse((new EmailFormat())->validate('data@'));
		$this->assertFalse((new EmailFormat())->validate(['data@voorbeeld.nl']));
	}

	public function testASchemaSerialisesBothFieldsOnlyWhenSet(): void {
		$plain = new Schema();
		$plain->setTitle('Meldingen');
		$this->assertArrayNotHasKey('classification', $plain->jsonSerialize());
		$this->assertArrayNotHasKey('catalogue', $plain->jsonSerialize());

		$described = new Schema();
		$described->hydrate(['title' => 'Meldingen', 'classification' => 'internal', 'catalogue' => self::CATALOGUE]);
		$json = $described->jsonSerialize();

		$this->assertSame('internal', $json['classification']);
		$this->assertSame(self::CATALOGUE, $json['catalogue']);
	}

	public function testAnExportedSchemaHydratesBackUnchanged(): void {
		$source = new Schema();
		$source->hydrate(['title' => 'Meldingen', 'classification' => 'strictly-confidential', 'catalogue' => self::CATALOGUE]);

		$copy = new Schema();
		$copy->hydrate($source->jsonSerialize());

		$this->assertSame('strictly-confidential', $copy->getClassification());
		$this->assertSame(self::CATALOGUE, $copy->getCatalogue());
	}
}
