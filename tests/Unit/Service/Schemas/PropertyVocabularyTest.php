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

use OCA\OpenRegister\Service\Schemas\CodedChoiceException;
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
	 * `x-openregister-property-source` is now defined, so it is published.
	 *
	 * 🔑 THIS TEST USED TO ASSERT THE OPPOSITE, AND IT WAS RIGHT AT THE TIME.
	 * It said the key must save but stay unpublished, because publishing it
	 * would have been this layer inventing semantics for a key whose meaning
	 * another change was still defining. That reasoning has been honoured
	 * rather than overruled: integriq's `registry-backed-field-source` has now
	 * DEFINED the shape (`provider`, `config`, `mode`), its resolver half is
	 * built, and it was waiting on this side. So the key is adopted with the
	 * meaning that change gave it, not with one invented here.
	 *
	 * Its old fixture, `{registry: 'kvk'}`, was never a shipped shape: no
	 * register file in openregister or integriq carries this key at all, which
	 * is what made it safe to enforce the defined one.
	 *
	 * @return void
	 */
	public function testThePropertySourceKeyIsPublishedNowThatItIsDefined(): void {
		$this->assertTrue(
			condition: $this->validator->validateProperty(
				property: [
					'type' => 'string',
					'x-openregister-property-source' => ['provider' => 'kvk', 'mode' => 'live'],
				],
				path: '/kvkNummer'
			),
			message: 'the defined shape must save'
		);

		$this->assertContains(
			needle: 'x-openregister-property-source',
			haystack: $this->vocabulary->keys(),
			message: 'a key nothing publishes cannot be discovered, which is what kept integriq waiting'
		);
	}

	/**
	 * A vendor extension whose meaning nobody has defined still saves.
	 *
	 * The half of the old test that has NOT changed, kept deliberately: an
	 * `x-` key this layer does not define must not be refused, or a shipped
	 * schema carrying somebody else's annotation stops saving.
	 *
	 * @return void
	 */
	public function testAnUndefinedVendorKeyStillSaves(): void {
		$this->assertTrue(
			condition: $this->validator->validateProperty(
				property: ['type' => 'string', 'x-someotherapp-whatever' => ['anything' => true]],
				path: '/kvkNummer'
			)
		);

		$this->assertNotContains(
			needle: 'x-someotherapp-whatever',
			haystack: $this->vocabulary->keys()
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
		// 'scope' needs a sample because it is refused when null: a scope that
		// cannot name a group matches nobody, and publishing one would deny
		// everybody silently. This prober assigns null to any key without a
		// sample, which is what caught it.
		$samples = [
			'translatable' => true,
			'sourceLanguage' => 'nl',
			'scope' => 'team-a',
			// The prober assigns null to any key without a sample, and this key
			// refuses null: a binding with no provider has nothing to ask for
			// the values. It caught the key the moment it was published, which
			// is the second time this prober has caught one of mine.
			'x-openregister-property-source' => ['provider' => 'kvk', 'mode' => 'live'],
		];

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
	 * A field can say its choices come from a concept scheme.
	 *
	 * 🔑 THE VOCABULARY IS THE PUBLICATION, AND PUBLISHING IS THE WHOLE POINT.
	 * The save path already accepted this binding, because
	 * `assertKeysAreInTheVocabulary()` skips every `x-` prefixed key and the
	 * fleet was spelling it `x-openregister-concept-scheme`. What it could not
	 * do was FORWARD it: `ExtendingFormDeclaration` may only carry a key the
	 * vocabulary holds and refuses the rest by name, so a case type could store
	 * the binding and never hand it to the form that renders the field.
	 * Measured on dossiq 2026-09-18, where it sat in `PENDING_PLATFORM_KEYS`
	 * with `owner: openregister` waiting for exactly this line.
	 *
	 * 🔴 THE PUBLISHED SPELLING IS BARE, NOT PREFIXED. Every other modifier in
	 * this table is bare, an `x-` key is skipped by the validator rather than
	 * checked, and dossiq's own `propertyDefinition` already stores it as
	 * `conceptScheme`. Publishing the prefixed spelling would have put a key in
	 * the vocabulary that the thing enforcing the vocabulary refuses to look at.
	 *
	 * @return void
	 */
	public function testAFieldDeclaresTheConceptSchemeItsChoicesComeFrom(): void {
		$this->assertTrue(
			condition: $this->vocabulary->hasKey(key: 'conceptScheme'),
			message: 'a case type cannot forward a binding the vocabulary does not hold'
		);

		$modifiers = array_column($this->vocabulary->modifiers(), null, 'key');
		$this->assertArrayHasKey('conceptScheme', $modifiers, 'it is a modifier, like widget and facetable');
		$this->assertSame('string', $modifiers['conceptScheme']['value'], 'a scheme is named by its slug');
		$this->assertGreaterThan(
			60,
			strlen((string)$modifiers['conceptScheme']['description']),
			'a published key says what it does, or nobody can use it without reading this file'
		);
	}//end testAFieldDeclaresTheConceptSchemeItsChoicesComeFrom()

	/**
	 * The binding survives a save, which publishing alone does not prove.
	 *
	 * The control the test above cannot give, and the same one
	 * `testTheKeysTheFleetAlreadyWritesAreHeld` needed beside it: a key the
	 * vocabulary publishes and the save path refuses is the contract lying in
	 * the expensive direction, because the author is told it is supported.
	 *
	 * @return void
	 */
	public function testTheConceptSchemeBindingSavesWithARealSchemeName(): void {
		// `testEveryPublishedKeySurvivesASave` above sweeps every published key
		// with a NULL value, which proves the spelling is accepted and nothing
		// about the value. A binding is a slug, and a slug is the value an
		// author actually writes, so this is the probe that shape survives.
		$this->assertTrue(
			condition: $this->validator->validateProperty(
				property: ['type' => 'string', 'title' => 'Wijk', 'conceptScheme' => 'wijken'],
				path: '/properties/wijk'
			),
			message: 'the vocabulary publishes conceptScheme and the save path must accept a real scheme slug'
		);

		// 🔴 THIS ASSERTION IS THE OPPOSITE OF WHAT IT SAID IN #3883, AND THE
		// FIRST VERSION WAS MINE AND WRONG. It read "a competing source is a
		// reportable authoring mistake, not a refused save", reasoning from
		// dossiq's `code-lists-from-concepts`, which resolves a scheme against
		// an inline list by precedence. Those are two different objects: dossiq
		// resolves it on its own `propertyDefinition` ROW, where an author is
		// editing and can be shown a warning. This is the compiled SCHEMA
		// PROPERTY, and `property-code-list-from-concept-scheme` says of it, in
		// its own words, "Declaring both is refused."
		//
		// Refusing here is also the only place it can be refused usefully: by
		// the time a value is validated, precedence has already silently picked
		// one, and whichever it picked the author meant the other half the time.
		$this->expectException(CodedChoiceException::class);
		$this->validator->validateProperty(
			property: [
				'type' => 'string',
				'conceptScheme' => 'wijken',
				'enum' => ['Centrum', 'Noord'],
			],
			path: '/properties/wijk'
		);
	}//end testTheConceptSchemeBindingSavesWithARealSchemeName()

	/**
	 * The keys the fleet already writes are in the vocabulary.
	 *
	 * 🔴 These four are named rather than derived on purpose. The strict key
	 * check shipped with them missing, and the cost was not a warning: eight
	 * apps' CI seeding failed at schema import because their shipped schemas
	 * were refused. `authorization` is the sharpest of the four, because
	 * OpenRegister itself reads it in `Schema::hasPropertyAuthorization()`, so
	 * the layer refused a key its own model acts on. A derived assertion here
	 * would pass again the moment somebody removes one.
	 *
	 * @return void
	 */
	public function testTheKeysTheFleetAlreadyWritesAreHeld(): void {
		foreach (['authorization', 'table', 'widget', 'defaultBehavior'] as $key) {
			$this->assertTrue(
				condition: $this->vocabulary->hasKey(key: $key),
				message: "'{$key}' is written in shipped schemas across the fleet and the vocabulary drops it"
			);
		}
	}//end testTheKeysTheFleetAlreadyWritesAreHeld()

	/**
	 * A property authorization saves, because the model reads it back.
	 *
	 * The control the test above cannot give: publishing a key and refusing it
	 * at save time would be the contract lying in the expensive direction.
	 *
	 * @return void
	 */
	public function testAPropertyAuthorizationSaves(): void {
		$this->assertTrue(
			condition: $this->validator->validateProperty(
				property: [
					'type' => 'string',
					'authorization' => ['read' => ['authenticated'], 'update' => ['admin']],
				],
				path: '/interneAnnotatie'
			)
		);
	}//end testAPropertyAuthorizationSaves()

	/**
	 * Prose keys may be written per language, and only prose keys may.
	 *
	 * `title:en` is the same key as `title`, written for one audience. Waving
	 * through anything with a colon would make `title:englisch` a fourteenth
	 * language instead of the typo it is, and `order:en` a setting nothing
	 * reads. Both halves are asserted so the rule cannot quietly widen.
	 *
	 * @return void
	 */
	public function testAProseKeyTakesALanguageSuffixAndNothingElseDoes(): void {
		$this->assertTrue(
			condition: $this->validator->validateProperty(
				property: [
					'type' => 'string',
					'title:en' => 'Time entry',
					'title:nl' => 'Urenregel',
					'description:en' => 'The hours booked on this entry.',
					'title:pt-BR' => 'Lancamento de horas',
				],
				path: '/timeEntry'
			),
			message: 'a localised title was refused, so a multilingual schema cannot be imported'
		);

		foreach (['title:englisch', 'titel:en', 'order:en'] as $key) {
			try {
				$this->validator->validateProperty(property: ['type' => 'string', $key => 'x'], path: '/probe');
				$this->fail(message: "'{$key}' was accepted, so the language suffix is waving anything through");
			} catch (PropertyVocabularyException $refusal) {
				$this->assertStringContainsString(needle: $key, haystack: $refusal->getMessage());
			}
		}
	}//end testAProseKeyTakesALanguageSuffixAndNothingElseDoes()

	/**
	 * The localisation rule is published, not left to be guessed.
	 *
	 * An editor generated from this endpoint has to know both which keys take
	 * a suffix and what the suffix may look like.
	 *
	 * @return void
	 */
	public function testTheLocalisationRuleIsPublished(): void {
		$rule = $this->vocabulary->all()['localisation'];

		$this->assertSame(expected: ['title', 'description'], actual: $rule['keys']);
		$this->assertSame(expected: ':', actual: $rule['separator']);
		$this->assertSame(expected: 1, actual: preg_match($rule['languageTagPattern'], 'nl'));
		$this->assertSame(expected: 0, actual: preg_match($rule['languageTagPattern'], 'englisch'));

		foreach ($rule['keys'] as $key) {
			$this->assertContains(
				needle: $key,
				haystack: $this->vocabulary->keys(),
				message: "'{$key}' takes a language suffix but is not itself a published key"
			);
		}
	}//end testTheLocalisationRuleIsPublished()

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
