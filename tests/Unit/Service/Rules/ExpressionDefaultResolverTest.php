<?php

/**
 * A property default that is an expression: derived on create, refused when it
 * cannot be derived, and never written as empty.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Rules\ExpressionDefaultException;
use OCA\OpenRegister\Service\Rules\ExpressionDefaultResolver;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Task 4.4 and REQ-REO-006.
 *
 * The spec's own scenario: `uiterlijkeDatum` is six weeks after
 * `ontvangstdatum`, and a create without `ontvangstdatum` fails naming
 * `uiterlijkeDatum` rather than storing an empty date.
 *
 * @package OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
 */
class ExpressionDefaultResolverTest extends TestCase {

	/**
	 * Six weeks after the date the case came in.
	 *
	 * @var array<string, mixed>
	 */
	private const SIX_WEEKS = [
		'dateAdd' => ['date' => ['prop' => 'ontvangstdatum'], 'amount' => 42, 'unit' => 'days'],
	];

	/**
	 * The resolver over the real AST evaluator.
	 *
	 * @return ExpressionDefaultResolver The resolver.
	 */
	private function resolver(): ExpressionDefaultResolver {
		return new ExpressionDefaultResolver(
			evaluator: new CalculationEvaluator(
				placeholders: new PlaceholderResolver(
					userSession: $this->createMock(originalClassName: IUserSession::class)
				)
			)
		);
	}//end resolver()

	/**
	 * A schema declaring the six-week default.
	 *
	 * @param mixed $expression The expression to declare, or null for the sound one.
	 *
	 * @return array<string, mixed> The properties block.
	 */
	private function properties(mixed $expression = null): array {
		if ($expression === null) {
			$expression = self::SIX_WEEKS;
		}

		return [
			'ontvangstdatum' => ['type' => 'string', 'format' => 'date'],
			'uiterlijkeDatum' => [
				'type' => 'string',
				'format' => 'date',
				ExpressionDefaultResolver::ANNOTATION => $expression,
			],
		];
	}//end properties()

	/**
	 * A default is derived on create.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testADefaultIsDerivedOnCreate(): void {
		$result = $this->resolver()->apply(
			properties: $this->properties(),
			data: ['ontvangstdatum' => '2026-01-01']
		);

		$this->assertArrayHasKey('uiterlijkeDatum', $result);
		$this->assertStringStartsWith('2026-02-12', (string)$result['uiterlijkeDatum']);
	}//end testADefaultIsDerivedOnCreate()

	/**
	 * A supplied value is not overwritten by the default.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testASuppliedValueIsNotOverwritten(): void {
		$result = $this->resolver()->apply(
			properties: $this->properties(),
			data: ['ontvangstdatum' => '2026-01-01', 'uiterlijkeDatum' => '2026-03-01']
		);

		$this->assertSame('2026-03-01', $result['uiterlijkeDatum']);
	}//end testASuppliedValueIsNotOverwritten()

	/**
	 * 🔴 An unevaluable default refuses, and names the property.
	 *
	 * The failure mode this exists to prevent is the other one: the object is
	 * created, the property is empty, and nothing says the derivation failed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testAnUnevaluableDefaultRefusesTheCreateAndNamesTheProperty(): void {
		try {
			$this->resolver()->apply(properties: $this->properties(), data: []);
			$this->fail('A default that could not be derived did not refuse the create.');
		} catch (ExpressionDefaultException $refusal) {
			$this->assertSame('uiterlijkeDatum', $refusal->getProperty());
			$this->assertStringContainsString('uiterlijkeDatum', $refusal->getMessage());
			$this->assertSame(422, $refusal->getCode());
			$this->assertSame(
				ExpressionDefaultResolver::CODE_UNEVALUABLE,
				$refusal->toArray()['code']
			);
		}
	}//end testAnUnevaluableDefaultRefusesTheCreateAndNamesTheProperty()

	/**
	 * Every expression reads the submitted object, not another default's output.
	 *
	 * Declaration order is an accident of how the schema was typed, so a
	 * default that read one would change meaning when a property was moved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testAnExpressionCannotReadAnotherExpressionDefault(): void {
		$properties = [
			'basis' => ['type' => 'number', ExpressionDefaultResolver::ANNOTATION => ['lit' => 10]],
			'afgeleid' => [
				'type' => 'number',
				ExpressionDefaultResolver::ANNOTATION => ['+' => [['prop' => 'basis'], 1]],
			],
		];

		try {
			$this->resolver()->apply(properties: $properties, data: []);
			$this->fail('An expression read a value another expression default produced.');
		} catch (ExpressionDefaultException $refusal) {
			$this->assertSame('afgeleid', $refusal->getProperty());
		}
	}//end testAnExpressionCannotReadAnotherExpressionDefault()

	/**
	 * A schema declaring no expression default is untouched.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testASchemaWithNoExpressionDefaultIsUntouched(): void {
		$properties = ['ontvangstdatum' => ['type' => 'string'], 'uiterlijkeDatum' => ['type' => 'string']];
		$resolver = $this->resolver();

		$this->assertFalse($resolver->declaresAny(properties: $properties));
		$this->assertSame(
			['ontvangstdatum' => '2026-01-01'],
			$resolver->apply(properties: $properties, data: ['ontvangstdatum' => '2026-01-01'])
		);
		$this->assertSame([], ExpressionDefaultResolver::validateDeclarations(properties: $properties));
	}//end testASchemaWithNoExpressionDefaultIsUntouched()

	/**
	 * 🔴 THE CONTROL. A sound declaration produces no schema-save error, which
	 * is what makes the refusals below about the fault named.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testASoundDeclarationIsAccepted(): void {
		$this->assertSame([], ExpressionDefaultResolver::validateDeclarations(properties: $this->properties()));
	}//end testASoundDeclarationIsAccepted()

	/**
	 * An expression the evaluator cannot dispatch is refused at schema save.
	 *
	 * Not on the first create, one object at a time, for whoever happens to be
	 * using the register that day.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testAnUnknownOperatorIsRefusedAtSchemaSave(): void {
		$errors = ExpressionDefaultResolver::validateDeclarations(
			properties: $this->properties(['sixWeeksAfter' => ['date' => ['prop' => 'ontvangstdatum']]])
		);

		$this->assertCount(1, $errors);
		$this->assertSame(ExpressionDefaultResolver::CODE_MALFORMED, $errors[0]['code']);
		$this->assertStringContainsString('uiterlijkeDatum', $errors[0]['message']);
	}//end testAnUnknownOperatorIsRefusedAtSchemaSave()

	/**
	 * A scalar expression is refused: it would be a literal default written twice.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testAScalarExpressionIsRefused(): void {
		$errors = ExpressionDefaultResolver::validateDeclarations(properties: $this->properties('2026-01-01'));

		$this->assertSame(ExpressionDefaultResolver::CODE_MALFORMED, $errors[0]['code']);
	}//end testAScalarExpressionIsRefused()

	/**
	 * A literal default beside an expression is refused, because no rule says
	 * which wins.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testALiteralDefaultBesideAnExpressionIsRefused(): void {
		$properties = $this->properties();
		$properties['uiterlijkeDatum']['default'] = '2026-01-01';

		$errors = ExpressionDefaultResolver::validateDeclarations(properties: $properties);

		$this->assertCount(1, $errors);
		$this->assertSame(ExpressionDefaultResolver::CODE_TWO_DEFAULTS, $errors[0]['code']);
	}//end testALiteralDefaultBesideAnExpressionIsRefused()
}//end class
