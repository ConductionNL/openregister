<?php

/**
 * Golden test: the exact refusals and shapes of the schema declaration readers.
 *
 * Pins, before their units are split, what the property-source, reference-filter,
 * coded-choice, repeating-group, semantic-role, extending-form and vocabulary
 * readers answer: every refusal sentence, the per-value error each exception
 * carries, and the shapes the endpoints hand on.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Schemas\CodedChoiceDeclaration;
use OCA\OpenRegister\Service\Schemas\CodedChoiceException;
use OCA\OpenRegister\Service\Schemas\ExtendingFormDeclaration;
use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierException;
use OCA\OpenRegister\Service\Schemas\PropertySourceDeclaration;
use OCA\OpenRegister\Service\Schemas\PropertySourceException;
use OCA\OpenRegister\Service\Schemas\PropertyVocabulary;
use OCA\OpenRegister\Service\Schemas\PropertyVocabularyException;
use OCA\OpenRegister\Service\Schemas\ReferenceFilterDeclaration;
use OCA\OpenRegister\Service\Schemas\ReferenceFilterException;
use OCA\OpenRegister\Service\Schemas\ReferenceOptionsReader;
use OCA\OpenRegister\Service\Schemas\RepeatingGroupDeclarationValidator;
use OCA\OpenRegister\Service\Schemas\SemanticRoleHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the refusals and shapes of the declaration readers under lib/Service/Schemas.
 */
class SchemaDeclarationsGoldenTest extends TestCase {

	private const SOURCE = PropertySourceDeclaration::ANNOTATION;

	/**
	 * Every property-source refusal and its sentence.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}> Property and message.
	 */
	public static function sourceRefusals(): array {
		$at = "'x-openregister-property-source' at 'p'";
		return [
			'not an object'    => [[self::SOURCE => 'x'], "$at must be an object naming a provider, and it is not."],
			'empty'            => [[self::SOURCE => []], "$at must be an object naming a provider, and it is not."],
			'no provider'      => [[self::SOURCE => ['mode' => 'live']], "$at must name a provider. Without one there is nothing to ask for the values."],
			'bad provider'     => [
				[self::SOURCE => ['provider' => ' a/b ']],
				"$at names the provider 'a/b', which is not a provider id. A typo here becomes an empty list in a form, with nothing to say why.",
			],
			'bad mode'         => [
				[self::SOURCE => ['provider' => 'kvk', 'mode' => 'often']],
				"$at has mode 'often'. It must be one of: live, default. A mode nobody knows would be read as a guess, "
				. 'and the two modes differ in whether a person may change what the provider returned.',
			],
			'non-scalar mode'  => [
				[self::SOURCE => ['provider' => 'kvk', 'mode' => ['x']]],
				"$at has mode 'array'. It must be one of: live, default. A mode nobody knows would be read as a guess, "
				. 'and the two modes differ in whether a person may change what the provider returned.',
			],
			'bad config'       => [[self::SOURCE => ['provider' => 'kvk', 'config' => 'x']], "$at has a config that is not an object."],
			'both source keys' => [
				[self::SOURCE => ['provider' => 'kvk'], 'x-openregister-object-source' => []],
				"$at carries both 'x-openregister-property-source' and 'x-openregister-object-source'. The first binds this one "
				. "property to a provider; the second serves the whole schema's objects from one. Keep the one that was meant.",
			],
		];
	}//end sourceRefusals()

	/**
	 * A malformed property source is refused with its sentence.
	 *
	 * @param array<string, mixed> $property The property.
	 * @param string               $message  The sentence.
	 *
	 * @return void
	 */
	#[DataProvider('sourceRefusals')]
	public function testAPropertySourceRefusalNamesWhy(array $property, string $message): void {
		$this->expectException(PropertySourceException::class);
		$this->expectExceptionMessage($message);
		PropertySourceDeclaration::fromProperty(property: $property, path: 'p');
	}//end testAPropertySourceRefusalNamesWhy()

	/**
	 * A sound property source is read with its defaults.
	 *
	 * @return void
	 */
	public function testAPropertySourceIsRead(): void {
		$this->assertNull(PropertySourceDeclaration::fromProperty(property: []));
		$read = PropertySourceDeclaration::fromProperty(property: [self::SOURCE => ['provider' => ' kvk ', 'config' => ['a' => 1]]]);
		$this->assertSame(['kvk', 'live', ['a' => 1]], [$read->provider, $read->mode, $read->config]);
	}//end testAPropertySourceIsRead()

	/**
	 * Every reference-filter refusal, its sentence and its per-value error.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}> Property, message, error path.
	 */
	public static function filterRefusals(): array {
		$key = ReferenceFilterDeclaration::ANNOTATION;
		return [
			'not a list'       => [[$key => 'x', '$ref' => 's'], "$key at 'p' must be a non-empty list of conditions.", 'p'],
			'references none'  => [
				[$key => [['field' => 'a', 'from' => 'b']], 'type' => 'string'],
				"$key at 'p' is on a property that references nothing. Put it on a `\$ref` property.",
				'p',
			],
			'not an object'    => [[$key => ['x'], '$ref' => 's'], "The condition at 'p/0' must be an object.", 'p/0'],
			'missing operand'  => [
				[$key => [['field' => 'a', 'from' => ' ']], 'type' => 'array'],
				"The condition at 'p/0' needs a 'field' on the referenced schema and a 'from' on this one.",
				'p/0',
			],
			'unknown operator' => [
				[$key => [['field' => 'a', 'from' => 'b', 'op' => 'gt']], '$ref' => 's'],
				"The condition at 'p/0' uses operator 'gt'. It must be one of: eq, neq, in.",
				'p/0',
			],
		];
	}//end filterRefusals()

	/**
	 * A malformed reference filter is refused with its sentence and its error entry.
	 *
	 * @param array<string, mixed> $property The property.
	 * @param string               $message  The sentence.
	 * @param string               $path     The path the error names.
	 *
	 * @return void
	 */
	#[DataProvider('filterRefusals')]
	public function testAReferenceFilterRefusalNamesWhy(array $property, string $message, string $path): void {
		try {
			ReferenceFilterDeclaration::fromProperty(property: $property, path: 'p');
			$this->fail('admitted a malformed filter');
		} catch (ReferenceFilterException $e) {
			$this->assertSame($message, $e->getMessage());
			$this->assertSame(
				[['code' => 'reference-filter-invalid', 'key' => ReferenceFilterDeclaration::ANNOTATION, 'path' => $path, 'message' => $message]],
				$e->getErrors()
			);
		}
	}//end testAReferenceFilterRefusalNamesWhy()

	/**
	 * Operands are checked on both sides, and a filter resolves whole or not at all.
	 *
	 * @return void
	 */
	public function testAReferenceFilterChecksOperandsAndResolvesWhole(): void {
		$filter = ReferenceFilterDeclaration::fromProperty(
			property: [
				ReferenceFilterDeclaration::ANNOTATION => [['field' => 'org', 'from' => 'own'], ['field' => 'kind', 'from' => 'k', 'op' => 'in']],
				'$ref' => 's',
			]
		);

		$messages = [];
		foreach ([[['k' => 1], ['org' => 1, 'kind' => 1]], [['own' => 1, 'k' => 1], ['org' => 1]]] as [$own, $far]) {
			try {
				$filter->assertOperandsExist(ownProperties: $own, farProperties: $far, path: 'p');
			} catch (ReferenceFilterException $e) {
				$messages[] = $e->getMessage();
			}
		}

		$filter->assertOperandsExist(ownProperties: ['own' => 1, 'k' => 1], farProperties: [], path: 'p');
		$this->assertSame(
			[
				"The filter at 'p' reads 'own' off this record, and this schema does not declare it.",
				"The filter at 'p' matches on 'kind', and the referenced schema does not declare it.",
			],
			$messages
		);
		$this->assertSame(['filter' => ['org' => 'o1', 'kind' => ['in' => ['a']]], 'needs' => []], $filter->resolve(record: ['own' => 'o1', 'k' => ['a']]));
		$this->assertSame(['filter' => [], 'needs' => ['own', 'k']], $filter->resolve(record: ['own' => '', 'k' => []]));
	}//end testAReferenceFilterChecksOperandsAndResolvesWhole()

	/**
	 * The options plan names its target through `$ref`, `schema` or `items`, filtered or not.
	 *
	 * @return void
	 */
	public function testTheOptionsPlanNamesItsTarget(): void {
		$schema = new Schema();
		$schema->setProperties(
			[
				'plain'  => ['schema' => 'people', 'register' => 'r1'],
				'many'   => ['type' => 'array', 'items' => ['$ref' => ''], 'register' => null],
				'nested' => ['type' => 'array', 'items' => ['items' => ['schema' => 'deep']]],
				'none'   => ['type' => 'string'],
				'narrow' => [
					'$ref' => 'orgs',
					ReferenceFilterDeclaration::ANNOTATION => [['field' => 'org', 'from' => 'own', 'op' => 'neq']],
				],
			]
		);
		$reader = new ReferenceOptionsReader();

		$this->assertSame(
			['filtered' => false, 'needs' => [], 'filter' => [], 'target' => ['schema' => 'people', 'register' => 'r1']],
			$reader->plan(schema: $schema, property: 'plain', record: [])
		);
		$this->assertNull($reader->plan(schema: $schema, property: 'many', record: [])['target']['schema']);
		$this->assertSame('deep', $reader->plan(schema: $schema, property: 'nested', record: [])['target']['schema']);
		$this->assertNull($reader->plan(schema: $schema, property: 'none', record: [])['target']['schema']);
		$this->assertSame(
			['filtered' => true, 'needs' => [], 'filter' => ['org' => ['neq' => 7]], 'target' => ['schema' => 'orgs', 'register' => null]],
			$reader->plan(schema: $schema, property: 'narrow', record: ['own' => 7])
		);
		$this->assertSame(['own'], $reader->plan(schema: $schema, property: 'narrow', record: [])['needs']);

		$this->expectException(ReferenceFilterException::class);
		$this->expectExceptionMessage("There is no property 'missing' on this schema to read options for.");
		$reader->plan(schema: $schema, property: 'missing', record: []);
	}//end testTheOptionsPlanNamesItsTarget()

	/**
	 * Both coded-choice refusals carry their own code.
	 *
	 * @return void
	 */
	public function testCodedChoiceRefusalsNameWhy(): void {
		$cases = [
			[
				['x-openregister-concepts' => ['scheme' => 'a'], 'conceptScheme' => 'a'],
				"The property at 'p' declares its code list twice, as 'x-openregister-concepts' and as 'conceptScheme'. "
				. 'They are one binding: keep the one naming the scheme you mean.',
				'coded-choice-two-spellings',
			],
			[
				['conceptScheme' => 'https://example.org/scheme', 'enum' => ['a']],
				"The property at 'p' takes its choices from a concept scheme AND lists them in 'enum'. "
				. 'Two sources is one too many: drop the list, or drop the scheme.',
				'coded-choice-and-enum',
			],
		];
		foreach ($cases as [$property, $message, $code]) {
			try {
				CodedChoiceDeclaration::assert(property: $property, path: 'p');
				$this->fail('admitted ' . $code);
			} catch (CodedChoiceException $e) {
				$this->assertSame([['code' => $code, 'key' => 'conceptScheme', 'path' => 'p', 'message' => $message]], $e->getErrors());
			}
		}

		CodedChoiceDeclaration::assert(property: ['conceptScheme' => 'https://example.org/scheme', 'enum' => []], path: 'p');
	}//end testCodedChoiceRefusalsNameWhy()

	/**
	 * The generated-identifier refusal carries its fixed code and key.
	 *
	 * @return void
	 */
	public function testAGeneratedIdentifierRefusalCarriesOneError(): void {
		$e = new GeneratedIdentifierException('Nope.', 'p/q');
		$this->assertSame(
			[['code' => 'generated-identifier-invalid', 'key' => 'x-openregister-generated', 'path' => 'p/q', 'message' => 'Nope.']],
			$e->getErrors()
		);
		$this->assertSame(
			[['code' => 'reference-filter-invalid', 'key' => 'x-openregister-reference-filter', 'path' => '', 'message' => 'M']],
			(new ReferenceFilterException('M'))->getErrors()
		);
	}//end testAGeneratedIdentifierRefusalCarriesOneError()

	/**
	 * Each repeating-group refusal, in order of the rules.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string|null}> Property and refusal code, null when admitted.
	 */
	public static function repeatingGroups(): array {
		$members = ['items' => ['properties' => ['a' => [], 'b' => []]]];
		return [
			'plain'           => [['type' => 'string', 'groupLabel' => null], null],
			'keys, no group'  => [['groupOrdered' => false], 'repeating-group-not-declared'],
			'not an array'    => [['repeatingGroup' => true, 'type' => 'object'] + $members, 'repeating-group-not-an-array'],
			'no members'      => [['repeatingGroup' => true, 'type' => 'array', 'items' => []], 'repeating-group-without-members'],
			'unknown label'   => [['repeatingGroup' => true, 'type' => 'array', 'groupLabel' => 'c'] + $members, 'repeating-group-unknown-label-member'],
			'sound'           => [['repeatingGroup' => true, 'type' => 'array', 'groupLabel' => 'b', 'groupOrdered' => true] + $members, null],
		];
	}//end repeatingGroups()

	/**
	 * A repeating group is admitted or refused by the first rule it breaks.
	 *
	 * @param array<string, mixed> $property The property.
	 * @param string|null          $code     The refusal code, or null when admitted.
	 *
	 * @return void
	 */
	#[DataProvider('repeatingGroups')]
	public function testARepeatingGroupBreaksItsFirstRule(array $property, ?string $code): void {
		$caught = null;
		try {
			(new RepeatingGroupDeclarationValidator())->validate(property: $property, path: 'g');
		} catch (PropertyVocabularyException $e) {
			$caught = $e->getErrors()[0]['code'];
			$this->assertSame($e->getMessage(), $e->getErrors()[0]['message']);
		}

		$this->assertSame($code, $caught);
	}//end testARepeatingGroupBreaksItsFirstRule()

	/**
	 * Unknown roles come before duplicate roles, and help text falls back by language.
	 *
	 * @return void
	 */
	public function testRolesAndHelpTexts(): void {
		$handler = new SemanticRoleHandler();
		$role    = SemanticRoleHandler::ROLE_ANNOTATION;
		$this->assertSame(
			[
				'The property "c" declares the role "boss", which is not one of: title, status, assignee, term.',
				'The properties "a" and "b" both declare the role "title", and a schema may declare each role once.',
			],
			$handler->violations(properties: ['a' => [$role => 'title'], 'b' => [$role => 'title'], 'c' => [$role => 'boss'], 'd' => [$role => 'status']])
		);

		$help = SemanticRoleHandler::HELP_ANNOTATION;
		$this->assertSame(
			['s' => 'plain', 'exact' => 'Hallo', 'primary' => 'de', 'dutch' => 'nl', 'any' => 'fr'],
			$handler->helpTexts(
				properties: [
					's'       => [$help => ' plain '],
					'blank'   => [$help => '  '],
					'exact'   => [$help => ['de-AT' => ' Hallo ', 'de' => 'de']],
					'primary' => [$help => ['de' => 'de', 'nl' => 'nl']],
					'dutch'   => [$help => ['nl' => 'nl', 'en' => 'en']],
					'any'     => [$help => ['fr' => 'fr', 'xx' => ' ']],
					'empty'   => [$help => ['de' => ' ', 'nl' => 5]],
					'number'  => [$help => 5],
				],
				language: 'de-AT'
			)
		);
	}//end testRolesAndHelpTexts()

	/**
	 * Two spellings and none are the extending-form shape refusals.
	 *
	 * @return void
	 */
	public function testExtendingFormSpellings(): void {
		$form  = new ExtendingFormDeclaration();
		$codes = static fn (array $errors): array => array_column($errors, 'code');
		$base  = ['app' => 'a', 'form' => 'f', 'definitions' => 'd'];

		$this->assertContains('extends-form-two-spellings', $codes($form->validate(annotation: $base + ['map' => [], 'forwards' => []])));
		$this->assertContains('extends-form-forwards-nothing', $codes($form->validate(annotation: $base)));
		$this->assertNotContains('extends-form-two-spellings', $codes($form->validate(annotation: $base + ['map' => []])));
		$this->assertNotContains('extends-form-forwards-nothing', $codes($form->validate(annotation: $base + ['forwards' => []])));
	}//end testExtendingFormSpellings()

	/**
	 * The vocabulary endpoint keeps its sections, order and counts.
	 *
	 * @return void
	 */
	public function testTheVocabularyShape(): void {
		$vocabulary = new PropertyVocabulary();
		$all        = $vocabulary->all();

		$this->assertSame(
			['types', 'categories', 'constraints', 'modifiers', 'passthrough', 'keys', 'localisation', 'vendorExtensionPrefix', 'counts'],
			array_keys($all)
		);
		$this->assertSame(
			[
				'types'       => count($all['types']),
				'constraints' => count($all['constraints']),
				'modifiers'   => count($all['modifiers']),
				'passthrough' => count($all['passthrough']),
				'formats'     => count($vocabulary->formatsFor(type: 'string')),
				'keys'        => count($all['keys']),
			],
			$all['counts']
		);
		$this->assertSame(['type', 'category', 'constraints', 'formats', 'conversion', 'conversionNote', 'description'], array_keys($all['types'][0]));
		$this->assertSame(['key', 'value', 'enforced', 'description'], array_slice(array_keys($all['modifiers'][0]), 0, 4));
		$this->assertSame(md5(json_encode($all['modifiers'])), md5(json_encode($vocabulary->modifiers())));
	}//end testTheVocabularyShape()
}//end class
