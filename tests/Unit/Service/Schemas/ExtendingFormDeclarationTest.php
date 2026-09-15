<?php

/**
 * Unit tests for ExtendingFormDeclaration: a narrowing that is stated.
 *
 * The failure mode this guards is an app quietly offering six of twenty keys.
 * A narrow editor is a legitimate product decision. A narrow editor nobody
 * declared is indistinguishable from an oversight, and one of those has cost
 * ten capability rows on a single form.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Schemas;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Schemas\ExtendingFormDeclaration;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCA\OpenRegister\Service\Schemas\PropertyVocabulary;
use PHPUnit\Framework\TestCase;

/**
 * The extending-form declaration, checked against the published vocabulary.
 */
class ExtendingFormDeclarationTest extends TestCase {

	/**
	 * The declaration reader under test.
	 *
	 * @var ExtendingFormDeclaration
	 */
	private ExtendingFormDeclaration $declarations;

	/**
	 * The published vocabulary the declaration is checked against.
	 *
	 * @var PropertyVocabulary
	 */
	private PropertyVocabulary $vocabulary;

	/**
	 * Wire the collaborators this suite needs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->vocabulary = new PropertyVocabulary();
		$this->declarations = new ExtendingFormDeclaration($this->vocabulary);
	}

	/**
	 * The map the shipped consumer actually reads.
	 *
	 * Copied from `DEFAULT_MAP` in `propertiesFromDefinitions`
	 * (`@conduction/nextcloud-vue`, `src/utils/dynamicProperties.js`): the
	 * vocabulary role on the LEFT, the app's own field name on the right.
	 * Written the other way round, five of these six are refused by name.
	 *
	 * @return array<string, mixed> The declaration.
	 */
	private function shippedForm(): array {
		return [
			'app' => 'dossiq',
			'form' => 'property-definition-management',
			'definitions' => 'caseTypeFieldDefinition',
			'map' => [
				'title' => 'name',
				'description' => 'description',
				'type' => 'propertyType',
				'enum' => 'enumValues',
				'required' => 'isRequired',
				'default' => 'defaultValue',
			],
		];
	}

	/**
	 * A narrower editor is a stated narrowing, and the difference is countable.
	 *
	 * @return void
	 */
	public function testANarrowerEditorIsAStatedNarrowing(): void {
		$declaration = $this->shippedForm();
		$described = $this->declarations->describe(annotation: $declaration);

		$this->assertSame(expected: 6, actual: $described['counts']['forwards']);
		$this->assertSame(
			expected: ['title', 'description', 'type', 'enum', 'required', 'default'],
			actual: $described['forwards']
		);

		// The keys it does not forward are derivable, which is the whole point.
		$this->assertSame(
			expected: (count($this->vocabulary->keys()) - 6),
			actual: $described['counts']['narrows']
		);
		$this->assertContains(needle: 'pattern', haystack: $described['narrows']);
		$this->assertNotContains(needle: 'type', haystack: $described['narrows']);
		$this->assertTrue(condition: $described['forwardsType']);
		$this->assertSame(expected: [], actual: $described['errors']);
	}

	/**
	 * Forwarding a key nobody defines is refused naming the key.
	 *
	 * @return void
	 */
	public function testForwardingAKeyNobodyDefinesIsRefused(): void {
		$declaration = [
			'app' => 'dossiq',
			'definitions' => 'caseTypeFieldDefinition',
			'map' => ['fieldKind' => 'propertyType'],
		];

		$errors = $this->declarations->validate(annotation: $declaration);

		$this->assertCount(expectedCount: 1, haystack: $errors);
		$this->assertSame(expected: 'extends-form-unknown-key', actual: $errors[0]['code']);
		$this->assertSame(expected: 'fieldKind', actual: $errors[0]['key']);
		$this->assertStringContainsString(needle: 'fieldKind', haystack: $errors[0]['message']);
	}

	/**
	 * A declaration that never says what it forwards is refused.
	 *
	 * @return void
	 */
	public function testADeclarationThatForwardsNothingIsRefused(): void {
		$errors = $this->declarations->validate(annotation: ['app' => 'dossiq']);

		$this->assertCount(expectedCount: 1, haystack: $errors);
		$this->assertSame(expected: 'extends-form-forwards-nothing', actual: $errors[0]['code']);
	}

	/**
	 * Two spellings of the same thing are refused rather than merged.
	 *
	 * @return void
	 */
	public function testTwoSpellingsOfWhatItForwardsAreRefused(): void {
		$errors = $this->declarations->validate(
			annotation: ['definitions' => 'd', 'map' => ['title' => 'name'], 'forwards' => ['title']]
		);

		$this->assertSame(expected: 'extends-form-two-spellings', actual: $errors[0]['code']);
	}

	/**
	 * A declaration that never says where its records come from is refused.
	 *
	 * `propertiesFromDefinitions` skips a declaration with no `definitions`,
	 * so accepting one would store an annotation that reads as configured and
	 * renders no field at all.
	 *
	 * @return void
	 */
	public function testADeclarationWithNoDefinitionsIsRefused(): void {
		$errors = $this->declarations->validate(annotation: ['map' => ['title' => 'name']]);

		$this->assertSame(expected: 'extends-form-no-definitions', actual: $errors[0]['code']);
	}

	/**
	 * The short spelling works for a form whose fields carry vocabulary names.
	 *
	 * @return void
	 */
	public function testTheShortSpellingForwardsTheSameWay(): void {
		$short = ['definitions' => 'caseTypeFieldDefinition', 'forwards' => ['type', 'title', 'pattern']];

		$this->assertSame(expected: [], actual: $this->declarations->validate(annotation: $short));
		$this->assertSame(
			expected: ['type', 'title', 'pattern'],
			actual: $this->declarations->forwards(annotation: $short)
		);
	}

	/**
	 * A property authored through the form is validated exactly like a hand-written one.
	 *
	 * Not "similarly". The same object goes into the same method, so there is
	 * no second class of property with a second set of rules.
	 *
	 * @return void
	 */
	public function testAForwardedPropertyIsValidatedLikeAHandWrittenOne(): void {
		$validator = new PropertyValidatorHandler();
		$declaration = $this->shippedForm();

		$good = $this->declarations->toProperty(
			annotation: $declaration,
			formValues: ['name' => 'Naam', 'propertyType' => 'string', 'isRequired' => true]
		);
		$this->assertSame(expected: ['title' => 'Naam', 'type' => 'string', 'required' => true], actual: $good);
		$this->assertTrue(condition: $validator->validateProperty(property: $good, path: '/naam'));

		$bad = $this->declarations->toProperty(
			annotation: $declaration,
			formValues: ['name' => 'Naam', 'propertyType' => 'sting']
		);
		$this->expectExceptionMessage(message: 'sting');
		$validator->validateProperty(property: $bad, path: '/naam');
	}

	/**
	 * A field the form did not fill in does not become a null property key.
	 *
	 * @return void
	 */
	public function testAnUnansweredFormFieldIsLeftOutOfTheProperty(): void {
		$property = $this->declarations->toProperty(
			annotation: $this->shippedForm(),
			formValues: ['propertyType' => 'string']
		);

		$this->assertSame(expected: ['type' => 'string'], actual: $property);
	}

	/**
	 * The `definition` alias supplies a description, and never beats one.
	 *
	 * The shipped consumer reads `definition` as the fallback source for a
	 * description. A role the vocabulary does not hold, refused by name, would
	 * break a form that already works, so it is declared rather than refused.
	 *
	 * @return void
	 */
	public function testTheDefinitionAliasSuppliesADescription(): void {
		$declaration = [
			'definitions' => 'caseTypeFieldDefinition',
			'map' => ['type' => 'propertyType', 'definition' => 'shortText'],
		];

		$this->assertSame(expected: [], actual: $this->declarations->validate(annotation: $declaration));
		$this->assertSame(
			expected: ['type', 'description'],
			actual: $this->declarations->forwards(annotation: $declaration)
		);
		$this->assertSame(
			expected: ['type' => 'string', 'description' => 'Korte toelichting'],
			actual: $this->declarations->toProperty(
				annotation: $declaration,
				formValues: ['propertyType' => 'string', 'shortText' => 'Korte toelichting']
			)
		);
	}

	/**
	 * Both places the annotation may sit are found.
	 *
	 * @return void
	 */
	public function testTheDeclarationIsFoundOnTheSchemaAndOnTheProperty(): void {
		$found = $this->declarations->fromSchema(
			configuration: [ExtendingFormDeclaration::ANNOTATION => ['forwards' => ['type']]],
			properties: [
				'caseType' => ['type' => 'string', ExtendingFormDeclaration::ANNOTATION => $this->shippedForm()],
				'naam' => ['type' => 'string'],
			]
		);

		$this->assertSame(
			expected: [
				'configuration/x-openregister-extends-form',
				'properties/caseType/x-openregister-extends-form',
			],
			actual: array_keys($found)
		);
	}

	/**
	 * A declaration stored on the schema configuration survives the save.
	 *
	 * `setConfiguration()` DROPS any `x-openregister-*` key outside
	 * `Schema::ANNOTATION_VOCABULARY`, silently. An annotation whose whole
	 * purpose is to make a narrowing visible, dropped on the way in, would be
	 * invisible again and every read of it would answer an empty list. The
	 * entity's own comments record that same bug four times, so this test
	 * round-trips through the entity rather than reading the list.
	 *
	 * @return void
	 */
	public function testTheDeclarationSurvivesTheSchemaSave(): void {
		$schema = new Schema();
		$schema->setConfiguration([ExtendingFormDeclaration::ANNOTATION => $this->shippedForm()]);

		$stored = ($schema->getConfiguration() ?? []);

		$this->assertArrayHasKey(
			key: ExtendingFormDeclaration::ANNOTATION,
			array: $stored,
			message: 'the declaration was dropped on save, so the narrowing it states is invisible'
		);
		$this->assertSame(
			expected: 6,
			actual: count($this->declarations->forwards(annotation: $stored[ExtendingFormDeclaration::ANNOTATION]))
		);
	}

	/**
	 * A declaration that is not an object at all is refused rather than ignored.
	 *
	 * @return void
	 */
	public function testANonObjectDeclarationIsRefused(): void {
		$errors = $this->declarations->validate(annotation: 'type,title');

		$this->assertSame(expected: 'extends-form-not-an-object', actual: $errors[0]['code']);
	}
}//end class
