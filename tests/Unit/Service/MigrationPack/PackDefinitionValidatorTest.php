<?php

/**
 * PackDefinitionValidatorTest
 *
 * Unit tests for `PackDefinitionValidator`: required-field checks, source
 * format / version / transform allow-lists, and the idStrategy shape.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\MigrationPack
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\MigrationPack;

use InvalidArgumentException;
use OCA\OpenRegister\Exception\InvalidPackDefinitionException;
use OCA\OpenRegister\Service\MigrationPack\PackDefinitionValidator;
use PHPUnit\Framework\TestCase;

class PackDefinitionValidatorTest extends TestCase {
	use PassThroughL10nTrait;

	private PackDefinitionValidator $validator;

	protected function setUp(): void {
		parent::setUp();
		$this->validator = new PackDefinitionValidator($this->passThroughL10n());
	}

	/**
	 * @param array<string, mixed> $overrides
	 *
	 * @return array<string, mixed>
	 */
	private function validPack(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'test-pack',
				'name' => 'Test Pack',
				'sourceFormat' => 'csv',
				'version' => '1.0.0',
				'idStrategy' => ['type' => 'generate'],
				'fieldMappings' => [
					['source' => 'Name', 'target' => 'title'],
				],
			],
			$overrides
		);
	}

	public function testValidPackHasNoErrors(): void {
		$this->assertSame([], $this->validator->validate($this->validPack()));
	}

	public function testMissingIdIsRejected(): void {
		$pack = $this->validPack();
		unset($pack['id']);
		$errors = $this->validator->validate($pack);
		$this->assertNotEmpty($errors);
		$this->assertStringContainsString('"id"', $errors[0]);
	}

	public function testNonSlugIdIsRejected(): void {
		$errors = $this->validator->validate($this->validPack(['id' => 'Not A Slug!']));
		$this->assertNotEmpty($errors);
	}

	public function testMissingNameIsRejected(): void {
		$pack = $this->validPack();
		unset($pack['name']);
		$this->assertNotEmpty($this->validator->validate($pack));
	}

	public function testUnsupportedSourceFormatIsRejected(): void {
		$errors = $this->validator->validate($this->validPack(['sourceFormat' => 'xml']));
		$this->assertNotEmpty($errors);
		$this->assertStringContainsString('sourceFormat', $errors[0]);
	}

	/**
	 * @dataProvider sourceFormatProvider
	 */
	public function testEachAllowedSourceFormatIsAccepted(string $format): void {
		$this->assertSame([], $this->validator->validate($this->validPack(['sourceFormat' => $format])));
	}

	public static function sourceFormatProvider(): array {
		return [['csv'], ['json'], ['excel']];
	}

	/**
	 * @dataProvider badVersionProvider
	 */
	public function testBadVersionIsRejected(mixed $version): void {
		$errors = $this->validator->validate($this->validPack(['version' => $version]));
		$this->assertNotEmpty($errors);
		$this->assertStringContainsString('version', $errors[0]);
	}

	public static function badVersionProvider(): array {
		return [
			'not-semver' => ['v1'],
			'missing-patch' => ['1.0'],
			'not-a-string' => [1.0],
			'null' => [null],
		];
	}

	public function testMissingFieldMappingsIsRejected(): void {
		$pack = $this->validPack();
		unset($pack['fieldMappings']);
		$errors = $this->validator->validate($pack);
		$this->assertNotEmpty($errors);
		$this->assertStringContainsString('fieldMappings', $errors[0]);
	}

	public function testEmptyFieldMappingsIsRejected(): void {
		$errors = $this->validator->validate($this->validPack(['fieldMappings' => []]));
		$this->assertNotEmpty($errors);
	}

	public function testFieldMappingWithoutSourceIsRejected(): void {
		$errors = $this->validator->validate($this->validPack(['fieldMappings' => [['target' => 'title']]]));
		$this->assertNotEmpty($errors);
		$this->assertContains('Mapping row 1: source is required and must be a non-empty string', $errors);
	}

	public function testFieldMappingWithoutTargetIsRejected(): void {
		$errors = $this->validator->validate($this->validPack(['fieldMappings' => [['source' => 'Name']]]));
		$this->assertNotEmpty($errors);
		$this->assertContains('Mapping row 1: target is required and must be a non-empty string', $errors);
	}

	public function testUnknownTransformTypeIsRejected(): void {
		$errors = $this->validator->validate(
			$this->validPack(
				[
					'fieldMappings' => [
						['source' => 'Name', 'target' => 'title', 'transform' => ['type' => 'reverse']],
					],
				]
			)
		);
		$this->assertNotEmpty($errors);
		$this->assertStringContainsString('Mapping row 1: transform.type', implode(' ', $errors));
	}

	public function testMappingRowsAreCountedFromOneAsTheEditorTableShowsThem(): void {
		$errors = $this->validator->validate(
			$this->validPack(
				[
					'fieldMappings' => [
						['source' => 'Name', 'target' => 'title'],
						['source' => 'Code', 'target' => 'code'],
						'not an object',
						['source' => 'Status', 'target' => 'status', 'transform' => ['type' => 'lookup']],
					],
				]
			)
		);

		$this->assertSame(
			[
				'Mapping row 3 must be an object',
				'Mapping row 4: transform.map is required and must be a non-empty object for a lookup transform',
			],
			$errors
		);
	}

	/**
	 * @dataProvider validTransformProvider
	 */
	public function testEachAllowedTransformTypeWithRequiredFieldsIsAccepted(array $transform): void {
		$errors = $this->validator->validate(
			$this->validPack(['fieldMappings' => [['source' => 'Name', 'target' => 'title', 'transform' => $transform]]])
		);
		$this->assertSame([], $errors);
	}

	public static function validTransformProvider(): array {
		return [
			'trim' => [['type' => 'trim']],
			'date' => [['type' => 'date', 'sourceFormat' => 'd-m-Y', 'targetFormat' => 'Y-m-d']],
			'bool-map' => [['type' => 'bool-map', 'map' => ['J' => true, 'N' => false]]],
			'lookup' => [['type' => 'lookup', 'map' => ['a' => 'A']]],
			'concat' => [['type' => 'concat', 'fields' => ['Other']]],
			'const' => [['type' => 'const', 'value' => 'fixed']],
		];
	}

	public function testBoolMapWithoutMapIsRejected(): void {
		$errors = $this->validator->validate(
			$this->validPack(['fieldMappings' => [['source' => 'A', 'target' => 'b', 'transform' => ['type' => 'bool-map']]]])
		);
		$this->assertNotEmpty($errors);
	}

	public function testLookupWithoutMapIsRejected(): void {
		$errors = $this->validator->validate(
			$this->validPack(['fieldMappings' => [['source' => 'A', 'target' => 'b', 'transform' => ['type' => 'lookup']]]])
		);
		$this->assertNotEmpty($errors);
	}

	public function testConcatWithoutFieldsIsRejected(): void {
		$errors = $this->validator->validate(
			$this->validPack(['fieldMappings' => [['source' => 'A', 'target' => 'b', 'transform' => ['type' => 'concat']]]])
		);
		$this->assertNotEmpty($errors);
	}

	public function testConstWithoutValueIsRejected(): void {
		$errors = $this->validator->validate(
			$this->validPack(['fieldMappings' => [['source' => 'A', 'target' => 'b', 'transform' => ['type' => 'const']]]])
		);
		$this->assertNotEmpty($errors);
	}

	public function testMissingIdStrategyIsRejected(): void {
		$pack = $this->validPack();
		unset($pack['idStrategy']);
		$errors = $this->validator->validate($pack);
		$this->assertNotEmpty($errors);
		$this->assertStringContainsString('idStrategy', $errors[0]);
	}

	public function testSourceFieldIdStrategyWithoutFieldIsRejected(): void {
		$errors = $this->validator->validate($this->validPack(['idStrategy' => ['type' => 'sourceField']]));
		$this->assertNotEmpty($errors);
	}

	public function testSourceFieldIdStrategyWithFieldIsAccepted(): void {
		$errors = $this->validator->validate(
			$this->validPack(['idStrategy' => ['type' => 'sourceField', 'field' => 'uuid']])
		);
		$this->assertSame([], $errors);
	}

	public function testSkipRowsMustBePositiveIntegers(): void {
		$errors = $this->validator->validate($this->validPack(['skipRows' => [0]]));
		$this->assertNotEmpty($errors);

		$errors = $this->validator->validate($this->validPack(['skipRows' => [1, 2]]));
		$this->assertSame([], $errors);
	}

	public function testDefaultsMustBeAnArray(): void {
		$errors = $this->validator->validate($this->validPack(['defaults' => 'not-an-array']));
		$this->assertNotEmpty($errors);
	}

	public function testAssertValidThrowsWithJoinedMessages(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/Invalid migration pack definition/');
		$this->validator->assertValid(['id' => '']);
	}

	public function testAssertValidCarriesEveryErrorSeparately(): void {
		$definition = $this->validPack(['name' => '', 'version' => '1.0']);

		try {
			$this->validator->assertValid($definition);
			$this->fail('An invalid pack must be refused');
		} catch (InvalidPackDefinitionException $e) {
			$this->assertSame($this->validator->validate($definition), $e->getErrors());
			$this->assertCount(2, $e->getErrors());
		}
	}

	public function testAssertValidDoesNotThrowForAValidPack(): void {
		$this->validator->assertValid($this->validPack());
		$this->addToAssertionCount(1);
	}

	/**
	 * Every finding goes through the app's translations: the pack editor lists them under the save error, and a
	 * Dutch administrator would otherwise read English findings on a Dutch page.
	 *
	 * @return void
	 */
	public function testEveryFindingIsTranslated(): void {
		$validator = new PackDefinitionValidator($this->passThroughL10n(prefix: 'NL:'));
		$errors = $validator->validate(
			[
				'id' => 'Not A Slug',
				'name' => '',
				'sourceFormat' => 'xml',
				'version' => '1',
				'fieldMappings' => [
					'not an object',
					['source' => '', 'target' => '', 'required' => 'yes', 'transform' => 'nope'],
					['source' => 'a', 'target' => 'b', 'transform' => ['type' => 'weird']],
					['source' => 'a', 'target' => 'b', 'transform' => ['type' => 'date', 'sourceFormat' => 5, 'targetFormat' => 5]],
					['source' => 'a', 'target' => 'b', 'transform' => ['type' => 'lookup']],
					['source' => 'a', 'target' => 'b', 'transform' => ['type' => 'concat', 'fields' => ['']]],
					['source' => 'a', 'target' => 'b', 'transform' => ['type' => 'const']],
				],
				'defaults' => 'x',
				'skipRows' => [0],
				'idStrategy' => ['type' => 'sourceField'],
			]
		);

		$this->assertGreaterThan(15, count($errors));
		foreach ($errors as $error) {
			$this->assertStringStartsWith('NL:', $error);
		}

		// The row label is part of the translated message, with the 1-based number the editor shows.
		$this->assertContains('NL:Mapping row 1 must be an object', $errors);
		$this->assertContains('NL:Mapping row 2: source is required and must be a non-empty string', $errors);
	}//end testEveryFindingIsTranslated()
}
