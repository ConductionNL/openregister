<?php

/**
 * A form mapping's fixed values judged against its destination schema, and the marked-property lookup.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Form\FormDestination;
use OCA\OpenRegister\Service\Form\FormDestinationValidator;
use OCA\OpenRegister\Service\Form\FormFieldRules;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * A fixed value into a property the schema lacks, a malformed fixed map, and markedProperties().
 *
 * @covers \OCA\OpenRegister\Service\Form\FormDestinationValidator
 * @uses \OCA\OpenRegister\Service\Form\FormFieldRules
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValueRules
 * @uses \OCA\OpenRegister\Service\Form\FixedValue
 * @uses \OCA\OpenRegister\Service\Form\FormDestination
 * @uses \OCA\OpenRegister\Service\Form\FormFinding
 * @uses \OCA\OpenRegister\Service\Form\MappedField
 */
class FormDestinationValidatorFixedTest extends TestCase {

	private FormDestinationValidator $validator;

	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => $parameters === [] ? $text : vsprintf($text, $parameters)
		);

		$this->validator = new FormDestinationValidator(l10n: $l10n, rules: new FormFieldRules());
	}//end setUp()

	/**
	 * A small `case` schema.
	 *
	 * @param array<int, string>   $required      The required list.
	 * @param array<string, mixed> $configuration Schema configuration.
	 * @param array<string, mixed> $properties    The properties.
	 *
	 * @return Schema The schema.
	 */
	private function schema(array $required = [], array $configuration = [], array $properties = []): Schema {
		$schema = new Schema();
		$schema->setSlug('case');
		$schema->setRequired($required);
		$schema->setConfiguration($configuration);
		$schema->setProperties(
			$properties === [] ? ['title' => ['type' => 'string'], 'caseType' => ['type' => 'string']] : $properties
		);

		return $schema;
	}//end schema()

	/**
	 * A fixed value into a property the schema does not have is reported, naming property and schema.
	 *
	 * @return void
	 */
	public function testAFixedValueIntoAnUnknownPropertyIsReported(): void {
		$findings = $this->validator->validate(
			mapping: ['fields' => [], 'fixed' => ['nonsense' => 'x']],
			destination: new FormDestination(schema: $this->schema())
		);

		$this->assertSame(
			[
				[
					'property' => 'nonsense',
					'code'     => FormDestinationValidator::PROPERTY_UNKNOWN,
					'message'  => 'A fixed value writes into "nonsense", which schema "case" does not have.',
				],
			],
			$findings
		);
	}//end testAFixedValueIntoAnUnknownPropertyIsReported()

	/**
	 * A schema that explicitly allows extras accepts a fixed value into a property it does not declare.
	 *
	 * @return void
	 */
	public function testAFixedValueIntoAnUnknownPropertyIsFineWhenExtrasAreAllowed(): void {
		$findings = $this->validator->validate(
			mapping: ['fields' => [], 'fixed' => ['nonsense' => 'x']],
			destination: new FormDestination(schema: $this->schema(configuration: ['additionalProperties' => true]))
		);

		$this->assertSame([], $findings);
	}//end testAFixedValueIntoAnUnknownPropertyIsFineWhenExtrasAreAllowed()

	/**
	 * A `fixed` entry that is not a map fixes nothing: a required property stays unmapped.
	 *
	 * @return void
	 */
	public function testAFixedEntryThatIsNotAMapFixesNothing(): void {
		$findings = $this->validator->validate(
			mapping: ['fields' => [], 'fixed' => 'caseType'],
			destination: new FormDestination(schema: $this->schema(required: ['caseType']))
		);

		$this->assertCount(1, $findings);
		$this->assertSame('caseType', $findings[0]['property']);
		$this->assertSame(FormDestinationValidator::REQUIRED_UNMAPPED, $findings[0]['code']);
	}//end testAFixedEntryThatIsNotAMapFixesNothing()

	/**
	 * The properties carrying a marker come back in declared order; unmarked and non-array ones do not.
	 *
	 * @return void
	 */
	public function testMarkedPropertiesAreListedInDeclaredOrder(): void {
		$schema = $this->schema(
			properties: [
				'mail'      => ['type' => 'string', 'x-openregister' => ['confirmation' => true]],
				'title'     => ['type' => 'string'],
				'odd'       => 'confirmation',
				'nope'      => ['type' => 'string', 'x-openregister' => ['confirmation' => false]],
				'secondary' => ['type' => 'string', 'x-openregister' => ['confirmation' => true, 'serverSet' => true]],
			]
		);

		$this->assertSame(['mail', 'secondary'], FormDestinationValidator::markedProperties(schema: $schema, name: FormDestinationValidator::MARKER_CONFIRMATION));
		$this->assertSame(['secondary'], FormDestinationValidator::markedProperties(schema: $schema, name: FormDestinationValidator::MARKER_SERVER_SET));
		$this->assertSame([], FormDestinationValidator::markedProperties(schema: $schema, name: FormDestinationValidator::MARKER_REFERENCE));
	}//end testMarkedPropertiesAreListedInDeclaredOrder()
}//end class
