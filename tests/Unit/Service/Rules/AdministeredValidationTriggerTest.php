<?php

/**
 * A validation that reads the value before the write declares it, runs only
 * on updates, and its run row says which side of the write decided
 * (tasks 2.2b and 2.3b).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Rules\AdministeredValidations;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCA\OpenRegister\Service\Rules\NamedConditionEvaluator;
use OCA\OpenRegister\Service\Rules\NamedConditionLibrary;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use OCA\OpenRegister\Service\Rules\TransitionDocument;
use OCA\OpenRegister\Service\Rules\ValidationInput;
use OCA\OpenRegister\Service\Rules\ValidationVerdict;
use PHPUnit\Framework\TestCase;

class AdministeredValidationTriggerTest extends TestCase {

	private AdministeredValidations $validations;

	protected function setUp(): void {
		$this->validations = new AdministeredValidations(
			evaluator: new NamedConditionEvaluator(
				dialect: new ConditionDialect(ast: $this->createMock(CalculationEvaluator::class)),
				library: new NamedConditionLibrary()
			)
		);
	}

	/**
	 * "Once set, the reference may not change": reads the value before the write.
	 *
	 * @param array<string, mixed> $extra
	 *
	 * @return array<string, mixed>
	 */
	private function frozenReference(array $extra = []): array {
		return ['bevroren' => $extra + [
			'severity' => AdministeredValidations::REFUSE,
			'message' => 'The reference cannot change once set.',
			'properties' => ['kenmerk'],
			'condition' => [
				'and' => [
					['!=' => [['var' => '$before.kenmerk'], null]],
					['!=' => [['var' => '$before.kenmerk'], ['var' => '$after.kenmerk']]],
				],
			],
		]];
	}

	public function testReadingThePriorValueWithoutDeclaringItIsRefusedNamingTheValidation(): void {
		$refusal = $this->validations->refusalFor($this->frozenReference(['on' => 'update']));

		$this->assertNotNull($refusal);
		$this->assertStringContainsString('"bevroren"', $refusal);
		$this->assertStringContainsString(TransitionDocument::REQUIRES_PRIOR, $refusal);
	}

	public function testDeclaringThePriorValueOnEverySaveIsRefusedBecauseCreatesHaveNone(): void {
		$refusal = $this->validations->refusalFor($this->frozenReference([TransitionDocument::REQUIRES_PRIOR => true]));

		$this->assertNotNull($refusal);
		$this->assertStringContainsString('"bevroren"', $refusal);
		$this->assertStringContainsString('"create"', $refusal);
	}

	public function testDeclaredAndUpdateOnlyIsAccepted(): void {
		$this->assertNull($this->validations->refusalFor($this->frozenReference([TransitionDocument::REQUIRES_PRIOR => true, 'on' => 'update'])));
	}

	public function testAnUnknownTriggerIsRefused(): void {
		$refusal = $this->validations->refusalFor($this->frozenReference([TransitionDocument::REQUIRES_PRIOR => true, 'on' => 'delete']));

		$this->assertStringContainsString('"bevroren" declares "on"', (string)$refusal);
	}

	public function testAnUpdateOnlyValidationSitsOutACreateAndRunsOnAnUpdate(): void {
		// Without the before-guard, so on a create (no `$before`) it WOULD fire if it ran.
		$annotation = $this->frozenReference([
			TransitionDocument::REQUIRES_PRIOR => true,
			'on' => 'update',
			'condition' => ['!=' => [['var' => '$before.kenmerk'], ['var' => '$after.kenmerk']]],
		]);
		$documents = new TransitionDocument();

		$create = $this->validations->evaluate($annotation, new ValidationInput($documents->build(['kenmerk' => 'B'], null), [], 'en'));
		$update = $this->validations->evaluate($annotation, new ValidationInput($documents->build(['kenmerk' => 'B'], ['kenmerk' => 'A']), [], 'en'));

		$this->assertSame([], $create['refusals']);
		$this->assertSame('bevroren', $update['refusals'][0]['validation']);
		$this->assertSame('The reference cannot change once set.', $update['refusals'][0]['message']);
	}

	public function testTheRunRowRecordsWhichSideDecided(): void {
		$entry = ['validation' => 'bevroren', 'properties' => ['kenmerk'], 'message' => 'm', 'unevaluable' => false];

		$trace = (new ValidationVerdict(entry: $entry, verdict: RuleVocabulary::VERDICT_REFUSED, decidedBy: 'transition'))->trace();

		$this->assertSame('kenmerk', $trace->getOperand());
		$this->assertSame('transition', $trace->getOperandValue());
		$this->assertNull((new ValidationVerdict(entry: $entry, verdict: RuleVocabulary::VERDICT_REFUSED))->trace()->getOperandValue());
	}
}
