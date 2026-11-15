<?php

/**
 * ValidationVerdict: an administered validation result read as a rule trace.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/unit-test-coverage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Rules\RuleTrace;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use OCA\OpenRegister\Service\Rules\ValidationVerdict;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rules\ValidationVerdict
 * @uses   \OCA\OpenRegister\Service\Rules\RuleTrace
 */
class ValidationVerdictTest extends TestCase {

	public function testTheTraceCarriesTheVerdictPropertiesAndMessage(): void {
		$entry   = ['validation' => 'adult', 'properties' => ['age', 'birthDate'], 'message' => 'Too young'];
		$verdict = new ValidationVerdict($entry, RuleVocabulary::VERDICT_REFUSED);

		$this->assertSame('adult', $verdict->validation());
		$trace = $verdict->trace();
		$this->assertInstanceOf(RuleTrace::class, $trace);
		$this->assertSame(RuleVocabulary::VERDICT_REFUSED, $trace->getVerdict());
		$this->assertSame('age, birthDate', $trace->getOperand());
		$this->assertNull($trace->getOperandValue());
		$this->assertSame('Too young', $trace->getMessage());
	}

	public function testAnUnevaluableEntryIsAnError(): void {
		$trace = (new ValidationVerdict(['unevaluable' => true], RuleVocabulary::VERDICT_FIRED))->trace();

		$this->assertSame(RuleVocabulary::VERDICT_ERROR, $trace->getVerdict());
	}

	public function testOnlyStrictTrueMarksAnEntryUnevaluable(): void {
		$trace = (new ValidationVerdict(['unevaluable' => 1], RuleVocabulary::VERDICT_NO_MATCH))->trace();

		$this->assertSame(RuleVocabulary::VERDICT_NO_MATCH, $trace->getVerdict());
	}

	public function testAnEmptyEntryReadsAsEmptyStrings(): void {
		$verdict = new ValidationVerdict([], RuleVocabulary::VERDICT_FIRED);

		$this->assertSame('', $verdict->validation());
		$this->assertSame('', $verdict->trace()->getOperand());
		$this->assertSame('', $verdict->trace()->getMessage());
	}
}
