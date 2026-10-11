<?php

/**
 * A form's mapping judged against its destination schema: one case per finding code.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Form\FormDestinationValidator;
use OCA\OpenRegister\Service\Form\FormFieldRules;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * One case per code, plus a zero-findings control.
 *
 * @covers \OCA\OpenRegister\Service\Form\FormDestinationValidator
 * @uses \OCA\OpenRegister\Service\Form\FormFieldRules
 * @uses \OCA\OpenRegister\Db\Schema
 */
class FormDestinationValidatorTest extends TestCase {

	private FormDestinationValidator $validator;

	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => $parameters === [] ? $text : vsprintf($text, $parameters)
		);

		$this->validator = new FormDestinationValidator(l10n: $l10n, rules: new FormFieldRules());
	}//end setUp()

	/**
	 * The `case` destination the spec's scenarios use.
	 *
	 * @param array<string, mixed> $extraProperties Properties to add or replace.
	 * @param array<int, string>   $required        The required list.
	 * @param array<string, mixed> $configuration   Schema configuration.
	 */
	private function caseSchema(array $extraProperties = [], array $required = ['title', 'caseType'], array $configuration = []): Schema {
		$schema = new Schema();
		$schema->setSlug('case');
		$schema->setRequired($required);
		$schema->setConfiguration($configuration);
		$schema->setProperties(
			array_merge(
				[
					'title' => ['type' => 'string', 'maxLength' => 200],
					'caseType' => ['type' => 'string', 'format' => 'uuid'],
					'description' => ['type' => 'string'],
					'email' => ['type' => 'string', 'format' => 'email'],
					'channel' => ['type' => 'string', 'enum' => ['website', 'phone', 'mail']],
					'amount' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000],
					'identifier' => ['type' => 'string', 'readOnly' => true, 'x-openregister' => ['serverSet' => true]],
				],
				$extraProperties
			)
		);

		return $schema;
	}//end caseSchema()

	/**
	 * The codes of a finding list.
	 *
	 * @param array<int, array<string, mixed>> $findings The findings.
	 *
	 * @return array<int, string>
	 */
	private function codes(array $findings): array {
		return array_map(static fn (array $finding): string => (string)$finding['code'], $findings);
	}//end codes()

	/**
	 * A complete, well-typed mapping has nothing to report.
	 */
	public function testACompleteMappingHasNoFindings(): void {
		$findings = $this->validator->validate(
			mapping: [
				'fields' => [
					['field' => 'onderwerp', 'property' => 'title', 'type' => 'text', 'maxLength' => 120],
					['field' => 'mail', 'property' => 'email', 'type' => 'email'],
					['field' => 'kanaal', 'property' => 'channel', 'type' => 'choice', 'options' => ['website', 'phone']],
					['field' => 'bedrag', 'property' => 'amount', 'type' => 'integer', 'minimum' => 0, 'maximum' => 500],
				],
				'fixed' => ['caseType' => '0b5c1e9e-4f3a-4b8e-9c7d-2a1f0e6d5c4b'],
			],
			schema: $this->caseSchema()
		);

		$this->assertSame([], $findings);
	}//end testACompleteMappingHasNoFindings()

	/**
	 * Spec scenario: a required property with no source is reported.
	 */
	public function testARequiredPropertyWithNoSourceIsReported(): void {
		$findings = $this->validator->validate(
			mapping: ['fields' => [['field' => 'onderwerp', 'property' => 'title', 'type' => 'text', 'maxLength' => 100]]],
			schema: $this->caseSchema()
		);

		$this->assertCount(1, $findings);
		$this->assertSame('caseType', $findings[0]['property']);
		$this->assertSame(FormDestinationValidator::REQUIRED_UNMAPPED, $findings[0]['code']);
		$this->assertArrayNotHasKey('field', $findings[0]);
	}//end testARequiredPropertyWithNoSourceIsReported()

	/**
	 * Spec scenario: a property a listener fills is not reported; nor one with a default or a computation.
	 */
	public function testAServerSetDefaultedOrComputedPropertyIsNotReported(): void {
		$schema = $this->caseSchema(
			extraProperties: [
				'status' => ['type' => 'string', 'default' => 'new'],
				'deadline' => ['type' => 'string', 'computed' => ['expression' => 'startDate + 56d']],
			],
			required: ['title', 'identifier', 'status', 'deadline']
		);

		$findings = $this->validator->validate(
			mapping: ['fields' => [['field' => 'onderwerp', 'property' => 'title', 'type' => 'text', 'maxLength' => 100]]],
			schema: $schema
		);

		$this->assertSame([], $findings);
	}//end testAServerSetDefaultedOrComputedPropertyIsNotReported()

	/**
	 * A property-level `required: true` counts as required too.
	 */
	public function testAPropertyLevelRequiredFlagCounts(): void {
		$schema = $this->caseSchema(extraProperties: ['priority' => ['type' => 'string', 'required' => true]], required: []);

		$findings = $this->validator->validate(mapping: ['fields' => []], schema: $schema);

		$this->assertSame([FormDestinationValidator::REQUIRED_UNMAPPED], $this->codes($findings));
		$this->assertSame('priority', $findings[0]['property']);
	}//end testAPropertyLevelRequiredFlagCounts()

	/**
	 * A field into a property the schema lacks is reported, unless the schema explicitly allows extras.
	 *
	 * OpenRegister schemas carry no top-level `additionalProperties`, so "forbids extras" would never
	 * hold and the check would be hollow; the explicit opt-in is the only way out of the finding.
	 */
	public function testAnUnknownPropertyIsReportedUnlessExtrasAreAllowed(): void {
		$mapping = [
			'fields' => [['field' => 'x', 'property' => 'nonsense', 'type' => 'text']],
			'fixed' => ['caseType' => '0b5c1e9e-4f3a-4b8e-9c7d-2a1f0e6d5c4b'],
		];

		$closed = $this->validator->validate(mapping: $mapping, schema: $this->caseSchema(required: ['caseType']));
		$this->assertSame([FormDestinationValidator::PROPERTY_UNKNOWN], $this->codes($closed));
		$this->assertSame('x', $closed[0]['field']);
		$this->assertSame('nonsense', $closed[0]['property']);

		$open = $this->validator->validate(
			mapping: $mapping,
			schema: $this->caseSchema(required: ['caseType'], configuration: ['additionalProperties' => true])
		);
		$this->assertSame([], $open);
	}//end testAnUnknownPropertyIsReportedUnlessExtrasAreAllowed()

	/**
	 * A field into a read-only property is reported: the save would refuse the value.
	 */
	public function testAFieldIntoAReadOnlyPropertyIsReported(): void {
		$findings = $this->validator->validate(
			mapping: ['fields' => [['field' => 'nr', 'property' => 'identifier', 'type' => 'text']]],
			schema: $this->caseSchema(required: [])
		);

		$this->assertSame([FormDestinationValidator::PROPERTY_READ_ONLY], $this->codes($findings));
	}//end testAFieldIntoAReadOnlyPropertyIsReported()

	/**
	 * A field whose type cannot produce the property's type.
	 */
	public function testATypeThatCannotProduceThePropertyIsReported(): void {
		$findings = $this->validator->validate(
			mapping: ['fields' => [['field' => 'bedrag', 'property' => 'amount', 'type' => 'text']]],
			schema: $this->caseSchema(required: [])
		);

		$this->assertSame([FormDestinationValidator::TYPE_MISMATCH], $this->codes($findings));
		$this->assertSame('bedrag', $findings[0]['field']);
	}//end testATypeThatCannotProduceThePropertyIsReported()

	/**
	 * A decimal field cannot produce an integer property; an integer field can produce a number property.
	 */
	public function testNumberAndIntegerAreJudgedByWhatTheFieldProduces(): void {
		$schema = $this->caseSchema(extraProperties: ['ratio' => ['type' => 'number']], required: []);

		$findings = $this->validator->validate(
			mapping: [
				'fields' => [
					['field' => 'a', 'property' => 'amount', 'type' => 'number', 'minimum' => 0, 'maximum' => 10],
					['field' => 'r', 'property' => 'ratio', 'type' => 'integer'],
				],
			],
			schema: $schema
		);

		$this->assertSame([FormDestinationValidator::TYPE_MISMATCH], $this->codes($findings));
		$this->assertSame('a', $findings[0]['field']);
	}//end testNumberAndIntegerAreJudgedByWhatTheFieldProduces()

	/**
	 * A plain text field into an e-mail property is a format mismatch.
	 */
	public function testAFormatThatCannotProduceThePropertyIsReported(): void {
		$findings = $this->validator->validate(
			mapping: ['fields' => [['field' => 'mail', 'property' => 'email', 'type' => 'text']]],
			schema: $this->caseSchema(required: [])
		);

		$this->assertSame([FormDestinationValidator::FORMAT_MISMATCH], $this->codes($findings));
	}//end testAFormatThatCannotProduceThePropertyIsReported()

	/**
	 * A free-text field into an enum property is unconstrained.
	 */
	public function testAFreeTextFieldIntoAnEnumIsReported(): void {
		$findings = $this->validator->validate(
			mapping: ['fields' => [['field' => 'kanaal', 'property' => 'channel', 'type' => 'text']]],
			schema: $this->caseSchema(required: [])
		);

		$this->assertSame([FormDestinationValidator::ENUM_UNCONSTRAINED], $this->codes($findings));
	}//end testAFreeTextFieldIntoAnEnumIsReported()

	/**
	 * A choice option outside the enum is reported once per option.
	 */
	public function testAChoiceOptionOutsideTheEnumIsReported(): void {
		$findings = $this->validator->validate(
			mapping: ['fields' => [['field' => 'kanaal', 'property' => 'channel', 'type' => 'choice', 'options' => ['website', 'fax']]]],
			schema: $this->caseSchema(required: [])
		);

		$this->assertSame([FormDestinationValidator::ENUM_VALUE_UNKNOWN], $this->codes($findings));
		$this->assertStringContainsString('fax', $findings[0]['message']);
	}//end testAChoiceOptionOutsideTheEnumIsReported()

	/**
	 * Options given as `{value, label}` objects are read by their value.
	 */
	public function testChoiceOptionsAsObjectsAreReadByValue(): void {
		$findings = $this->validator->validate(
			mapping: ['fields' => [['field' => 'kanaal', 'property' => 'channel', 'type' => 'choice', 'options' => [['value' => 'phone', 'label' => 'Telefoon']]]]],
			schema: $this->caseSchema(required: [])
		);

		$this->assertSame([], $findings);
	}//end testChoiceOptionsAsObjectsAreReadByValue()

	/**
	 * A 500-character field into a 200-character property, and an unbounded number into a bounded one.
	 */
	public function testAConstraintLooserThanThePropertyIsReported(): void {
		$findings = $this->validator->validate(
			mapping: [
				'fields' => [
					['field' => 'onderwerp', 'property' => 'title', 'type' => 'text', 'maxLength' => 500],
					['field' => 'bedrag', 'property' => 'amount', 'type' => 'integer', 'minimum' => 0],
				],
			],
			schema: $this->caseSchema(required: [])
		);

		$this->assertSame([FormDestinationValidator::CONSTRAINT_LOOSER, FormDestinationValidator::CONSTRAINT_LOOSER], $this->codes($findings));
		$this->assertStringContainsString('maxLength', $findings[0]['message']);
		$this->assertStringContainsString('maximum', $findings[1]['message']);
	}//end testAConstraintLooserThanThePropertyIsReported()

	/**
	 * A fixed value the property refuses: wrong type, outside the enum.
	 */
	public function testAFixedValueThePropertyRefusesIsReported(): void {
		$findings = $this->validator->validate(
			mapping: ['fields' => [], 'fixed' => ['channel' => 'fax', 'amount' => 'veel']],
			schema: $this->caseSchema(required: [])
		);

		$this->assertSame([FormDestinationValidator::FIXED_VALUE_INVALID, FormDestinationValidator::FIXED_VALUE_INVALID], $this->codes($findings));
		$this->assertSame('channel', $findings[0]['property']);
		$this->assertSame('amount', $findings[1]['property']);
	}//end testAFixedValueThePropertyRefusesIsReported()

	/**
	 * A public audience into a schema that does not grant public create.
	 */
	public function testAPublicFormIntoANonPublicDestinationIsReported(): void {
		$schema = $this->caseSchema(required: []);
		$schema->setAuthorization(['create' => ['behandelaars'], 'read' => ['behandelaars']]);

		$findings = $this->validator->validate(mapping: ['fields' => []], schema: $schema, options: ['audience' => 'public']);
		$this->assertSame([FormDestinationValidator::DESTINATION_NOT_PUBLIC], $this->codes($findings));

		$schema->setAuthorization(['create' => ['public']]);
		$this->assertSame([], $this->validator->validate(mapping: ['fields' => []], schema: $schema, options: ['audience' => 'public']));

		$schema->setAuthorization([]);
		$this->assertSame([], $this->validator->validate(mapping: ['fields' => []], schema: $schema, options: ['audience' => 'public']));
	}//end testAPublicFormIntoANonPublicDestinationIsReported()

	/**
	 * An authenticated audience is served by a public or an authenticated grant.
	 */
	public function testAnAuthenticatedAudienceNeedsAnAuthenticatedOrPublicGrant(): void {
		$schema = $this->caseSchema(required: []);
		$schema->setAuthorization(['create' => [['group' => 'authenticated']]]);
		$this->assertSame([], $this->validator->validate(mapping: ['fields' => []], schema: $schema, options: ['audience' => 'authenticated']));

		$schema->setAuthorization(['create' => ['admin']]);
		$this->assertSame(
			[FormDestinationValidator::DESTINATION_NOT_PUBLIC],
			$this->codes($this->validator->validate(mapping: ['fields' => []], schema: $schema, options: ['audience' => 'authenticated']))
		);
	}//end testAnAuthenticatedAudienceNeedsAnAuthenticatedOrPublicGrant()

	/**
	 * A schema marked as staging is not a destination.
	 */
	public function testAStagingSchemaIsNotADestination(): void {
		$findings = $this->validator->validate(
			mapping: ['fields' => []],
			schema: $this->caseSchema(required: [], configuration: ['staging' => true])
		);

		$this->assertSame([FormDestinationValidator::DESTINATION_IS_STAGING], $this->codes($findings));
	}//end testAStagingSchemaIsNotADestination()

	/**
	 * The two markers are read from the `x-openregister` block of a property.
	 */
	public function testMarkersAreReadFromTheXOpenregisterBlock(): void {
		$this->assertTrue(FormDestinationValidator::marker(property: ['x-openregister' => ['confirmation' => true]], name: 'confirmation'));
		$this->assertFalse(FormDestinationValidator::marker(property: ['x-openregister' => ['confirmation' => 'yes']], name: 'confirmation'));
		$this->assertFalse(FormDestinationValidator::marker(property: ['type' => 'string'], name: 'serverSet'));
		$this->assertFalse(FormDestinationValidator::marker(property: ['x-openregister' => 'serverSet'], name: 'serverSet'));
	}//end testMarkersAreReadFromTheXOpenregisterBlock()

	/**
	 * The task-form reason a field cannot be rendered: absent, read-only, invisible, or renderable.
	 */
	public function testTheTaskFormRenderableReasonLivesHere(): void {
		$schema = $this->caseSchema(extraProperties: ['hidden' => ['type' => 'string', 'visible' => false]]);

		$this->assertSame('the schema has no such property.', $this->validator->unrenderableReason(schema: $schema, field: 'nope'));
		$this->assertStringContainsString('read-only', (string)$this->validator->unrenderableReason(schema: $schema, field: 'identifier'));
		$this->assertStringContainsString('not visible', (string)$this->validator->unrenderableReason(schema: $schema, field: 'hidden'));
		$this->assertNull($this->validator->unrenderableReason(schema: $schema, field: 'title'));
	}//end testTheTaskFormRenderableReasonLivesHere()

	/**
	 * A mapping entry without a property is refused as malformed, naming the field.
	 */
	public function testAFieldWithoutAPropertyIsReportedAsUnknown(): void {
		$findings = $this->validator->validate(
			mapping: ['fields' => [['field' => 'losse-vraag']]],
			schema: $this->caseSchema(required: [], configuration: ['additionalProperties' => true])
		);

		$this->assertSame([FormDestinationValidator::PROPERTY_UNKNOWN], $this->codes($findings));
		$this->assertSame('losse-vraag', $findings[0]['field']);
	}//end testAFieldWithoutAPropertyIsReportedAsUnknown()
}//end class
