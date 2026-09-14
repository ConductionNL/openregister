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
	 * The eight keys dossiq's case-type form forwards today.
	 *
	 * Taken from the shape the study found in the wild:
	 * `schemas.case.properties.caseType.x-openregister-extends-form.map`.
	 *
	 * @return array<string, mixed> The declaration.
	 */
	private function eightKeyForm(): array {
		return [
			'app' => 'dossiq',
			'form' => 'property-definition-management',
			'map' => [
				'propertyType' => 'type',
				'label' => 'title',
				'helpText' => 'description',
				'isRequired' => 'required',
				'choices' => 'enum',
				'defaultValue' => 'default',
				'displayOrder' => 'order',
				'isSearchable' => 'facetable',
			],
		];
	}

	/**
	 * A narrower editor is a stated narrowing, and the difference is countable.
	 *
	 * @return void
	 */
	public function testANarrowerEditorIsAStatedNarrowing(): void {
		$declaration = $this->eightKeyForm();
		$described = $this->declarations->describe(annotation: $declaration);

		$this->assertSame(expected: 8, actual: $described['counts']['forwards']);
		$this->assertSame(
			expected: ['type', 'title', 'description', 'required', 'enum', 'default', 'order', 'facetable'],
			actual: $described['forwards']
		);

		// The keys it does not forward are derivable, which is the whole point.
		$this->assertSame(
			expected: (count($this->vocabulary->keys()) - 8),
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
		$declaration = ['app' => 'dossiq', 'map' => ['fieldKind' => 'propertyType']];

		$errors = $this->declarations->validate(annotation: $declaration);

		$this->assertCount(expectedCount: 1, haystack: $errors);
		$this->assertSame(expected: 'extends-form-unknown-key', actual: $errors[0]['code']);
		$this->assertSame(expected: 'propertyType', actual: $errors[0]['key']);
		$this->assertStringContainsString(needle: 'propertyType', haystack: $errors[0]['message']);
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
			annotation: ['map' => ['label' => 'title'], 'forwards' => ['title']]
		);

		$this->assertSame(expected: 'extends-form-two-spellings', actual: $errors[0]['code']);
	}

	/**
	 * Two form fields writing one property key are refused, naming the second.
	 *
	 * @return void
	 */
	public function testTwoFieldsForwardingOneKeyAreRefused(): void {
		$errors = $this->declarations->validate(
			annotation: ['map' => ['label' => 'title', 'heading' => 'title']]
		);

		$this->assertSame(expected: 'extends-form-duplicate-key', actual: $errors[0]['code']);
		$this->assertStringContainsString(needle: 'heading', haystack: $errors[0]['message']);
	}

	/**
	 * The short spelling works for a form whose fields carry vocabulary names.
	 *
	 * @return void
	 */
	public function testTheShortSpellingForwardsTheSameWay(): void {
		$errors = $this->declarations->validate(annotation: ['forwards' => ['type', 'title', 'pattern']]);
		$this->assertSame(expected: [], actual: $errors);

		$this->assertSame(
			expected: ['type', 'title', 'pattern'],
			actual: $this->declarations->forwards(annotation: ['forwards' => ['type', 'title', 'pattern']])
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
		$declaration = $this->eightKeyForm();

		$good = $this->declarations->toProperty(
			annotation: $declaration,
			formValues: ['propertyType' => 'string', 'label' => 'Naam', 'isRequired' => true]
		);
		$this->assertSame(expected: ['type' => 'string', 'title' => 'Naam', 'required' => true], actual: $good);
		$this->assertTrue(condition: $validator->validateProperty(property: $good, path: '/naam'));

		$bad = $this->declarations->toProperty(
			annotation: $declaration,
			formValues: ['propertyType' => 'sting', 'label' => 'Naam']
		);
		$this->expectExceptionMessage('sting');
		$validator->validateProperty(property: $bad, path: '/naam');
	}

	/**
	 * A field the form did not fill in does not become a null property key.
	 *
	 * @return void
	 */
	public function testAnUnansweredFormFieldIsLeftOutOfTheProperty(): void {
		$property = $this->declarations->toProperty(
			annotation: $this->eightKeyForm(),
			formValues: ['propertyType' => 'string']
		);

		$this->assertSame(expected: ['type' => 'string'], actual: $property);
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
				'caseType' => ['type' => 'string', ExtendingFormDeclaration::ANNOTATION => $this->eightKeyForm()],
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
	 * A declaration that is not an object at all is refused rather than ignored.
	 *
	 * @return void
	 */
	public function testANonObjectDeclarationIsRefused(): void {
		$errors = $this->declarations->validate(annotation: 'type,title');

		$this->assertSame(expected: 'extends-form-not-an-object', actual: $errors[0]['code']);
	}
}//end class
