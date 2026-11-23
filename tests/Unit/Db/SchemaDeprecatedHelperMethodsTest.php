<?php

/**
 * The deprecated Schema helper methods answer what their Core replacements answer.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/objects-crud/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Schema\VocabularyEntries;
use OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * `getUpsertKeys()`, `getLinkRoles()`, `getPartyKinds()` and `validateAuthorization()` keep
 * working for other apps while every openregister caller asks the Core class directly.
 *
 * @covers \OCA\OpenRegister\Db\Schema
 * @covers \OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator
 * @uses   \OCA\OpenRegister\Db\Schema\VocabularyEntries
 */
final class SchemaDeprecatedHelperMethodsTest extends TestCase {

	/**
	 * Configurations that exercise the upsert keys and the vocabularies.
	 *
	 * @return array<string, array{0: array<string, mixed>|null}>
	 */
	public static function configurations(): array {
		return [
			'none' => [null],
			'empty' => [[]],
			'refuse, report and legacy' => [
				[
					'unique' => ['zaaknummer', 'gemeentecode'],
					'uniqueConstraints' => [
						'nummer' => ['properties' => ['zaaknummer'], 'action' => 'refuse'],
						'melding' => ['properties' => ['gemeentecode'], 'action' => 'report'],
					],
				],
			],
			'vocabularies' => [
				[
					'linkRoles' => ['behandelaar', ['key' => 'aanvrager', 'label' => 'Aanvrager', 'description' => 'Wie het vroeg']],
					'partyKinds' => [['key' => 'persoon', 'label' => 'Persoon', 'roles' => ['aanvrager']], 'organisatie'],
				],
			],
		];
	}//end configurations()

	/**
	 * A schema with this configuration.
	 *
	 * @param array<string, mixed>|null $configuration The configuration.
	 *
	 * @return Schema
	 */
	private function schema(?array $configuration): Schema {
		$schema = new Schema();
		$schema->setProperties(['zaaknummer' => ['type' => 'string'], 'gemeentecode' => ['type' => 'string']]);
		$schema->setConfiguration($configuration);

		return $schema;
	}//end schema()

	/**
	 * The entity's upsert keys are the evaluator's upsert keys.
	 *
	 * @dataProvider configurations
	 *
	 * @param array<string, mixed>|null $configuration The configuration.
	 *
	 * @return void
	 */
	public function testUpsertKeysMatchTheEvaluator(?array $configuration): void {
		$schema = $this->schema(configuration: $configuration);

		$this->assertSame(
			expected: (new UniqueConstraintEvaluator())->upsertKeys(configuration: $schema->getConfiguration()),
			actual: $schema->getUpsertKeys()
		);
	}//end testUpsertKeysMatchTheEvaluator()

	/**
	 * The entity's vocabularies are the configured vocabularies.
	 *
	 * @dataProvider configurations
	 *
	 * @param array<string, mixed>|null $configuration The configuration.
	 *
	 * @return void
	 */
	public function testVocabulariesMatchVocabularyEntries(?array $configuration): void {
		$schema  = $this->schema(configuration: $configuration);
		$entries = new VocabularyEntries();

		$this->assertSame(
			expected: $entries->configuredVocabulary(configuration: $schema->getConfiguration(), config: 'linkRoles'),
			actual: $schema->getLinkRoles()
		);
		$this->assertSame(
			expected: $entries->configuredVocabulary(configuration: $schema->getConfiguration(), config: 'partyKinds'),
			actual: $schema->getPartyKinds()
		);
	}//end testVocabulariesMatchVocabularyEntries()

	/**
	 * Only `refuse` constraints are upsert keys, legacy first, in declaration order.
	 *
	 * @return void
	 */
	public function testUpsertKeysAreTheRefuseConstraints(): void {
		$keys = (new UniqueConstraintEvaluator())->upsertKeys(configuration: self::configurations()['refuse, report and legacy'][0]);

		$this->assertSame(expected: ['zaaknummer+gemeentecode', 'nummer'], actual: $keys);
		$this->assertSame(expected: [], actual: (new UniqueConstraintEvaluator())->upsertKeys(configuration: null));
	}//end testUpsertKeysAreTheRefuseConstraints()

	/**
	 * The four entity methods are deprecated, and still public.
	 *
	 * @return void
	 */
	public function testTheEntityMethodsAreDeprecated(): void {
		foreach (['getUpsertKeys', 'getLinkRoles', 'getPartyKinds', 'validateAuthorization'] as $name) {
			$method = new ReflectionMethod(Schema::class, $name);
			$this->assertTrue(condition: $method->isPublic(), message: $name);
			$this->assertStringContainsString(needle: '@deprecated', haystack: (string)$method->getDocComment(), message: $name);
		}
	}//end testTheEntityMethodsAreDeprecated()
}//end class
