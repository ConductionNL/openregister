<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

use OCA\OpenRegister\Service\Rules\AdministeredValidations;
use OCA\OpenRegister\Service\Rules\ValidationInput;
use PHPUnit\Framework\TestCase;

/**
 * Built without an evaluator (the schema save path), the declaration checks work
 * and evaluation fails closed: every validation refuses, whatever its severity.
 *
 * @covers \OCA\OpenRegister\Service\Rules\AdministeredValidations
 * @uses   \OCA\OpenRegister\Service\Rules\ValidationInput
 * @uses   \OCA\OpenRegister\Service\Rules\ConditionRefusedException
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
 */
class AdministeredValidationsWithoutEvaluatorTest extends TestCase {

	private const ANNOTATION = [
		'mandaat' => [
			'condition'  => ['>' => [['var' => 'bedrag'], 50000]],
			'severity'   => 'warn',
			'message'    => 'Controleer het mandaat.',
			'properties' => ['bedrag'],
		],
	];

	/**
	 * The declaration checks answer without an evaluator.
	 *
	 * @return void
	 */
	public function testDeclarationChecksNeedNoEvaluator(): void {
		$validations = new AdministeredValidations();

		$this->assertNull($validations->refusalFor(self::ANNOTATION, ['bedrag']));
		$this->assertSame(
			'validation "mandaat" points at "bedrag", which this schema does not declare',
			$validations->refusalFor(self::ANNOTATION, ['mandaat'])
		);
	}

	/**
	 * Evaluating without an evaluator refuses, even a warning, rather than passing it.
	 *
	 * @return void
	 */
	public function testEvaluationWithoutAnEvaluatorFailsClosed(): void {
		$outcome = (new AdministeredValidations())->evaluate(self::ANNOTATION, new ValidationInput(document: ['bedrag' => 10], library: [], language: 'nl'));

		$this->assertSame([], $outcome['warnings']);
		$this->assertCount(1, $outcome['refusals']);
		$this->assertSame('mandaat', $outcome['refusals'][0]['validation']);
	}
}
