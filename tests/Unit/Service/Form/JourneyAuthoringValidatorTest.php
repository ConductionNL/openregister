<?php

/**
 * A journey is refused at save for missing access, bad steps, bad next rules and unknown write properties.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-journey-must-declare-steps-branching-writes-and-access
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Form\FormDestinationValidator;
use OCA\OpenRegister\Service\Form\FormFieldRules;
use OCA\OpenRegister\Service\Form\JourneyAuthoringValidator;
use OCA\OpenRegister\Service\Form\LocalConditionEvaluator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The author-time checks over the real destination validator and an in-memory schema.
 *
 * @covers \OCA\OpenRegister\Service\Form\JourneyAuthoringValidator
 * @uses \OCA\OpenRegister\Service\Form\JourneyDefinition
 * @uses \OCA\OpenRegister\Service\Form\LocalConditionEvaluator
 * @uses \OCA\OpenRegister\Service\Form\FormDestinationValidator
 * @uses \OCA\OpenRegister\Service\Form\FormDestination
 * @uses \OCA\OpenRegister\Service\Form\FormFieldRules
 * @uses \OCA\OpenRegister\Service\Form\FormFinding
 * @uses \OCA\OpenRegister\Service\Form\MappedField
 * @uses \OCA\OpenRegister\Service\Form\FixedValue
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValueRules
 */
class JourneyAuthoringValidatorTest extends TestCase {

	/**
	 * The validator, with `contact` the only schema the author can see.
	 *
	 * @return JourneyAuthoringValidator The validator.
	 */
	private function validator(): JourneyAuthoringValidator {
		$contact = new Schema();
		$contact->setSlug('contact');
		$contact->setRequired(['email']);
		$contact->setConfiguration([]);
		$contact->setProperties(['email' => ['type' => 'string'], 'name' => ['type' => 'string']]);

		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			static function (string|int $id, ?array $_extend=[], bool $_rbac=true, bool $_multitenancy=true) use ($contact): Schema {
				if ($id === 'contact' && $_rbac === true && $_multitenancy === true) {
					return $contact;
				}

				throw new DoesNotExistException('No schema.');
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters=[]): string => vsprintf(str_replace(['%1$s', '%2$s', '%3$s'], '%s', $text), $parameters)
		);

		return new JourneyAuthoringValidator(
			schemas: $schemas,
			destinations: new FormDestinationValidator(l10n: $l10n, rules: new FormFieldRules()),
			conditions: new LocalConditionEvaluator(),
			l10n: $l10n
		);
	}//end validator()

	/**
	 * A valid journey with one branching step and one writing step.
	 *
	 * @return array<string, mixed> The body.
	 */
	private function journey(): array {
		return [
			'access' => 'anonymous',
			'steps' => [
				['id' => 'about', 'type' => 'form', 'fields' => ['kind'], 'next' => [['when' => ['field' => 'kind', 'op' => 'in', 'value' => ['x']], 'step' => 'done']]],
				[
					'id' => 'contact',
					'type' => 'form',
					'writes' => [['register' => 'crm', 'schema' => 'contact', 'mapping' => ['fields' => [['field' => 'mail', 'property' => 'email', 'type' => 'email']]]]],
				],
				['id' => 'done', 'type' => 'confirmation'],
			],
		];
	}//end journey()

	/**
	 * The codes of the findings.
	 *
	 * @param list<array<string, mixed>> $findings The findings.
	 *
	 * @return list<string> Their codes.
	 */
	private function codes(array $findings): array {
		return array_column($findings, 'code');
	}//end codes()

	/**
	 * A well-formed journey has no findings.
	 *
	 * @return void
	 */
	public function testAValidJourneyHasNoFindings(): void {
		$this->assertSame([], $this->validator()->validate(body: $this->journey()));
	}//end testAValidJourneyHasNoFindings()

	/**
	 * A journey without access is refused, naming the accepted values; an unknown value is refused too.
	 *
	 * @return void
	 */
	public function testAJourneyWithoutAccessIsRefused(): void {
		$body = $this->journey();
		unset($body['access']);
		$findings = $this->validator()->validate(body: $body);

		$this->assertSame([JourneyAuthoringValidator::ACCESS_MISSING], $this->codes(findings: $findings));
		$this->assertStringContainsString('anonymous, authenticated, minTrust', $findings[0]['message']);

		$body['access'] = 'public';
		$this->assertSame([JourneyAuthoringValidator::ACCESS_MISSING], $this->codes(findings: $this->validator()->validate(body: $body)));
	}//end testAJourneyWithoutAccessIsRefused()

	/**
	 * A write mapping a property the schema lacks is refused, naming the property and the schema.
	 *
	 * @return void
	 */
	public function testAnUnknownWritePropertyIsRefusedNamingPropertyAndSchema(): void {
		$body = $this->journey();
		$body['steps'][1]['writes'][0]['mapping']['fields'][] = ['field' => 'shoe', 'property' => 'shoeSize', 'type' => 'text'];
		$findings = $this->validator()->validate(body: $body);

		$this->assertSame([FormDestinationValidator::PROPERTY_UNKNOWN], $this->codes(findings: $findings));
		$this->assertSame('contact', $findings[0]['step']);
		$this->assertSame('shoeSize', $findings[0]['property']);
		$this->assertStringContainsString('"shoeSize"', $findings[0]['message']);
		$this->assertStringContainsString('"contact"', $findings[0]['message']);
	}//end testAnUnknownWritePropertyIsRefusedNamingPropertyAndSchema()

	/**
	 * A write into a schema the author cannot see is refused.
	 *
	 * @return void
	 */
	public function testAWriteIntoAnUnknownSchemaIsRefused(): void {
		$body = $this->journey();
		$body['steps'][1]['writes'][0]['schema'] = 'secret';
		$findings = $this->validator()->validate(body: $body);

		$this->assertSame([JourneyAuthoringValidator::WRITE_SCHEMA], $this->codes(findings: $findings));
		$this->assertStringContainsString('secret', $findings[0]['message']);
	}//end testAWriteIntoAnUnknownSchemaIsRefused()

	/**
	 * A next rule using an unknown operator is refused, naming the accepted operators.
	 *
	 * @return void
	 */
	public function testAnUnknownNextOperatorIsRefusedNamingTheAcceptedOnes(): void {
		$body = $this->journey();
		$body['steps'][0]['next'][0]['when'] = ['field' => 'kind', 'op' => 'like', 'value' => 'x'];
		$findings = $this->validator()->validate(body: $body);

		$this->assertSame([JourneyAuthoringValidator::NEXT_OPERATOR], $this->codes(findings: $findings));
		$this->assertStringContainsString('like', $findings[0]['message']);
		$this->assertStringContainsString(implode(', ', LocalConditionEvaluator::OPERATORS), $findings[0]['message']);
	}//end testAnUnknownNextOperatorIsRefusedNamingTheAcceptedOnes()

	/**
	 * A next rule to an undeclared step, a bad step type, a missing and a repeated id are refused.
	 *
	 * @return void
	 */
	public function testStepShapeIsChecked(): void {
		$body = $this->journey();
		$body['steps'][0]['next'][0]['step'] = 'nowhere';
		$body['steps'][2]['type'] = 'payment';
		$body['steps'][] = ['type' => 'form'];
		$body['steps'][] = ['id' => 'about', 'type' => 'form'];
		$findings = $this->validator()->validate(body: $body);

		$this->assertSame(
			[JourneyAuthoringValidator::NEXT_TARGET, JourneyAuthoringValidator::STEP_TYPE, JourneyAuthoringValidator::STEP_ID, JourneyAuthoringValidator::STEP_ID],
			$this->codes(findings: $findings)
		);
		$this->assertSame(['about', 'done', '#4', 'about'], array_column($findings, 'step'));
	}//end testStepShapeIsChecked()

	/**
	 * A journey without steps is refused.
	 *
	 * @return void
	 */
	public function testAJourneyWithoutStepsIsRefused(): void {
		$this->assertSame(
			[JourneyAuthoringValidator::STEPS_MISSING],
			$this->codes(findings: $this->validator()->validate(body: ['access' => 'authenticated', 'steps' => []]))
		);
	}//end testAJourneyWithoutStepsIsRefused()
}//end class
