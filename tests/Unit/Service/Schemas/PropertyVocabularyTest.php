<?php

/**
 * Unit tests for PropertyVocabulary: the published list is the validator's own.
 *
 * The failure mode this guards is drift. A catalogue maintained beside the
 * validator is a lie within one release, and it lies in the most expensive
 * place there is: the contract other apps generate their editors from. So
 * these tests do not read the table and agree with it. They ask the validator
 * what it accepts and compare.
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

use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCA\OpenRegister\Service\Schemas\PropertyVocabulary;
use OCA\OpenRegister\Service\Schemas\PropertyVocabularyException;
use PHPUnit\Framework\TestCase;

/**
 * The vocabulary, checked against the validator that produced it.
 */
class PropertyVocabularyTest extends TestCase {

	/**
	 * The published vocabulary under test.
	 *
	 * @var PropertyVocabulary
	 */
	private PropertyVocabulary $vocabulary;

	/**
	 * The save-time validator the vocabulary claims to be generated from.
	 *
	 * @var PropertyValidatorHandler
	 */
	private PropertyValidatorHandler $validator;

	/**
	 * Wire the collaborators this suite needs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->vocabulary = new PropertyVocabulary();
		$this->validator = new PropertyValidatorHandler();
	}

	/**
	 * Every published type is one the validator actually accepts.
	 *
	 * Covers the scenario "the published list cannot drift from the validator",
	 * which the spec excludes from e2e for exactly this reason.
	 *
	 * @return void
	 */
	public function testEveryPublishedTypeIsAcceptedByTheValidator(): void {
		$published = $this->vocabulary->typeNames();
		$this->assertNotEmpty(actual: $published, message: 'the vocabulary published no types at all');

		foreach ($published as $type) {
			$this->assertTrue(
				condition: $this->validator->validateProperty(property: ['type' => $type], path: '/probe'),
				message: "the vocabulary publishes '{$type}' but the validator refuses it"
			);
		}
	}

	/**
	 * A type the vocabulary does not publish is refused by the validator.
	 *
	 * The other direction of the same claim. Together with the test above it
	 * pins the two sets to each other rather than to a hand-copied list.
	 *
	 * @return void
	 */
	public function testATypeTheVocabularyDoesNotPublishIsRefused(): void {
		$this->expectException(exception: PropertyVocabularyException::class);
		$this->expectExceptionMessage(message: 'sting');

		$this->validator->validateProperty(property: ['type' => 'sting'], path: '/probe');
	}

	/**
	 * Every type row carries its constraints, its conversion answer and a sentence.
	 *
	 * @return void
	 */
	public function testEveryTypeRowCarriesItsConstraintsConversionAndASentence(): void {
		foreach ($this->vocabulary->types() as $row) {
			$this->assertNotSame(expected: '', actual: $row['type']);
			$this->assertNotSame(expected: '', actual: $row['category']);
			$this->assertNotSame(expected: '', actual: $row['description'], message: "type '{$row['type']}' has no sentence");
			$this->assertContains(
				needle: $row['conversion'],
				haystack: ['supported', 'conditional', 'unsupported'],
				message: "type '{$row['type']}' does not say whether converting a populated property to it works"
			);
			$this->assertNotSame(
				expected: '',
				actual: $row['conversionNote'],
				message: "type '{$row['type']}' states a conversion answer with no reason"
			);
			$this->assertNotEmpty(actual: $row['constraints'], message: "type '{$row['type']}' takes no constraint keys at all");
		}
	}

	/**
	 * Only string carries formats, and the formats are the ones the validator takes.
	 *
	 * @return void
	 */
	public function testStringCarriesTheFormatsTheValidatorAccepts(): void {
		$formats = $this->vocabulary->formatsFor(type: 'string');
		$this->assertNotEmpty(actual: $formats);
		$this->assertNotContains(needle: '', haystack: $formats, message: 'the empty format is an implementation detail, not a choice a form offers');

		foreach ($formats as $format) {
			$this->assertTrue(
				condition: $this->validator->validateProperty(property: ['type' => 'string', 'format' => $format], path: '/probe'),
				message: "the vocabulary publishes format '{$format}' but the validator refuses it"
			);
		}

		foreach ($this->vocabulary->typeNames() as $type) {
			if ($type === 'string') {
				continue;
			}

			$this->assertSame(
				expected: [],
				actual: $this->vocabulary->formatsFor(type: $type),
				message: "type '{$type}' publishes formats, but the save path only checks format on string"
			);
		}
	}

	/**
	 * A format the vocabulary does not publish is refused naming itself.
	 *
	 * @return void
	 */
	public function testAFormatTheVocabularyDoesNotPublishIsRefused(): void {
		$this->expectException(exception: PropertyVocabularyException::class);
		$this->expectExceptionMessage(message: 'burgerservicenummertje');

		$this->validator->validateProperty(
			property: ['type' => 'string', 'format' => 'burgerservicenummertje'],
			path: '/probe'
		);
	}

	/**
	 * Adding a format is published as breaking, because it is.
	 *
	 * Our own notes keep rediscovering this one, and D-4 says the caveat
	 * travels with the entry instead of living in a wiki nobody reads.
	 *
	 * @return void
	 */
	public function testAddingAFormatIsPublishedAsBreaking(): void {
		$rows = [];
		foreach ($this->vocabulary->constraints() as $row) {
			$rows[$row['key']] = $row;
		}

		$this->assertArrayHasKey(key: 'format', array: $rows);
		$this->assertTrue(
			condition: $rows['format']['breaking'],
			message: 'adding a format to a populated property is breaking and the vocabulary has to say so'
		);
		$this->assertSame(expected: ['string'], actual: $rows['format']['appliesTo']);
	}

	/**
	 * A key the vocabulary does not hold fails the save naming the key.
	 *
	 * @return void
	 */
	public function testAnUnknownConstraintKeyIsRefusedNamingIt(): void {
		try {
			$this->validator->validateProperty(property: ['type' => 'string', 'maxLenght' => 5], path: '/naam');
			$this->fail(message: 'a misspelled constraint key was accepted, so it constrains nothing');
		} catch (PropertyVocabularyException $refusal) {
			$this->assertStringContainsString(needle: 'maxLenght', haystack: $refusal->getMessage());
			$this->assertSame(expected: 'property-vocabulary-unknown-key', actual: $refusal->getErrors()[0]['code']);
			$this->assertSame(expected: 'maxLenght', actual: $refusal->getErrors()[0]['key']);
			$this->assertSame(expected: '/naam', actual: $refusal->getErrors()[0]['path']);
		}
	}

	/**
	 * A vendor extension passes through, which is what lets an app annotate.
	 *
	 * @return void
	 */
	public function testAVendorExtensionKeyPassesThrough(): void {
		$this->assertTrue(
			condition: $this->validator->validateProperty(
				property: ['type' => 'string', 'x-openregister-encrypted' => true],
				path: '/bsn'
			)
		);
	}

	/**
	 * Every key the vocabulary publishes survives a save on a type that takes it.
	 *
	 * The guard against publishing a key the save path then refuses, which
	 * would make the contract worse than no contract.
	 *
	 * @return void
	 */
	public function testEveryPublishedKeySurvivesASave(): void {
		// Null is the neutral probe: every check in the validator is guarded on
		// the key being present AND non-null, so a null says "this key is
		// spelled correctly" without also asserting a value shape. The two
		// exceptions read presence rather than value, so they get a real one.
		$samples = ['translatable' => true, 'sourceLanguage' => 'nl'];

		foreach ($this->vocabulary->keys() as $key) {
			if ($key === 'type') {
				continue;
			}

			$property = ['type' => 'string', 'translatable' => true];
			$property[$key] = ($samples[$key] ?? null);

			$this->assertTrue(
				condition: $this->validator->validateProperty(property: $property, path: '/probe'),
				message: "the vocabulary publishes '{$key}' but the save path refuses it"
			);
		}
	}

	/**
	 * The payload the endpoint answers with carries its own counts.
	 *
	 * @return void
	 */
	public function testThePublishedPayloadCountsItself(): void {
		$all = $this->vocabulary->all();

		$this->assertSame(expected: count($all['types']), actual: $all['counts']['types']);
		$this->assertSame(expected: count($all['constraints']), actual: $all['counts']['constraints']);
		$this->assertSame(expected: count($all['modifiers']), actual: $all['counts']['modifiers']);
		$this->assertSame(expected: count($all['keys']), actual: $all['counts']['keys']);
		$this->assertSame(expected: 'x-', actual: $all['vendorExtensionPrefix']);
		$this->assertNotEmpty(actual: $all['categories']);
	}

	/**
	 * Pass-through keys say they are not enforced.
	 *
	 * A contract that hides which half it checks is the expensive kind of lie.
	 *
	 * @return void
	 */
	public function testPassthroughKeysSayTheyAreNotEnforced(): void {
		$rows = $this->vocabulary->passthrough();
		$this->assertNotEmpty(actual: $rows);

		foreach ($rows as $row) {
			$this->assertFalse(condition: $row['enforced'], message: "'{$row['key']}' claims to be enforced but nothing checks it");
			$this->assertNotSame(expected: '', actual: $row['description']);
		}
	}
}//end class
