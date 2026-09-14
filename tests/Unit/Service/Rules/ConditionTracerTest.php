<?php

/**
 * The tracer that turns "did not match" into a fact.
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
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCA\OpenRegister\Service\Rules\ConditionTracer;
use OCA\OpenRegister\Service\Rules\RuleTrace;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the deciding-operand walk over both condition dialects.
 *
 * @package OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */
class ConditionTracerTest extends TestCase {

	/**
	 * The tracer under test.
	 *
	 * @var ConditionTracer|null
	 */
	private ?ConditionTracer $tracer = null;

	/**
	 * Build the tracer over a real AST evaluator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->tracer = new ConditionTracer(
			dialect: new ConditionDialect(
				ast: new CalculationEvaluator(
					placeholders: new PlaceholderResolver(
						userSession: $this->createMock(originalClassName: IUserSession::class)
					)
				)
			)
		);

	}//end setUp()

	/**
	 * The scenario the spec names: a rule requiring `bedrag` above 500 on an
	 * object whose `bedrag` is 120 says so, by name and by value.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testARuleThatDidNotFireNamesTheOperandAndTheValueItRead(): void {
		$trace = $this->tracer->trace(
			condition: ['>' => [['var' => 'bedrag'], 500]],
			document: ['bedrag' => 120],
			verdict: RuleVocabulary::VERDICT_NO_MATCH
		);

		$this->assertSame(expected: RuleVocabulary::VERDICT_NO_MATCH, actual: $trace->getVerdict());
		$this->assertSame(expected: 'bedrag', actual: $trace->getOperand());
		$this->assertSame(expected: '120', actual: $trace->getOperandValue());

	}//end testARuleThatDidNotFireNamesTheOperandAndTheValueItRead()

	/**
	 * The same question in the JSON AST reaches the same answer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testTheAstDialectTracesToTheSameOperand(): void {
		$trace = $this->tracer->trace(
			condition: ['gt' => [['prop' => 'bedrag'], 500]],
			document: ['bedrag' => 120],
			verdict: RuleVocabulary::VERDICT_NO_MATCH
		);

		$this->assertSame(expected: 'bedrag', actual: $trace->getOperand());
		$this->assertSame(expected: '120', actual: $trace->getOperandValue());

	}//end testTheAstDialectTracesToTheSameOperand()

	/**
	 * An `and` names the clause that failed, not the first clause written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testAnAndNamesTheClauseThatActuallyFailed(): void {
		$trace = $this->tracer->trace(
			condition: [
				'and' => [
					['>' => [['var' => 'bedrag'], 100]],
					['==' => [['var' => 'status'], 'open']],
				],
			],
			document: ['bedrag' => 400, 'status' => 'gesloten'],
			verdict: RuleVocabulary::VERDICT_NO_MATCH
		);

		$this->assertSame(expected: 'status', actual: $trace->getOperand());
		$this->assertSame(expected: 'gesloten', actual: $trace->getOperandValue());

	}//end testAnAndNamesTheClauseThatActuallyFailed()

	/**
	 * A missing operand reads as null rather than as an absent trace: "the
	 * field you are testing is empty" is the most common real answer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testAnAbsentPropertyIsReportedAsNull(): void {
		$trace = $this->tracer->trace(
			condition: ['==' => [['var' => 'object.outcome'], 'toegewezen']],
			document: ['object' => ['status' => 'closed']],
			verdict: RuleVocabulary::VERDICT_REFUSED
		);

		$this->assertSame(expected: 'object.outcome', actual: $trace->getOperand());
		$this->assertSame(expected: 'null', actual: $trace->getOperandValue());

	}//end testAnAbsentPropertyIsReportedAsNull()

	/**
	 * A rule that fired has no deciding operand, because nothing decided
	 * against it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testAFiredRuleCarriesNoOperand(): void {
		$trace = $this->tracer->trace(
			condition: ['>' => [['var' => 'bedrag'], 500]],
			document: ['bedrag' => 900],
			verdict: RuleVocabulary::VERDICT_FIRED
		);

		$this->assertNull(actual: $trace->getOperand());
		$this->assertNull(actual: $trace->getOperandValue());

	}//end testAFiredRuleCarriesNoOperand()

	/**
	 * A condition the walk cannot read costs the operand, never the verdict.
	 *
	 * The trace is an explanation of a decision already taken elsewhere, so a
	 * malformed condition must still come back carrying the refusal the caller
	 * actually received.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testAnUnreadableConditionKeepsTheVerdict(): void {
		$trace = $this->tracer->trace(
			condition: 'not a rule object',
			document: ['bedrag' => 120],
			verdict: RuleVocabulary::VERDICT_REFUSED,
			message: 'Refused by the engine.'
		);

		$this->assertSame(expected: RuleVocabulary::VERDICT_REFUSED, actual: $trace->getVerdict());
		$this->assertSame(expected: 'Refused by the engine.', actual: $trace->getMessage());
		$this->assertNull(actual: $trace->getOperand());

	}//end testAnUnreadableConditionKeepsTheVerdict()

	/**
	 * A long value is cut and says so, so a reader never compares a prefix
	 * against a full value believing it is the whole thing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testALongValueIsCutAndSaysSo(): void {
		$long = str_repeat('a', (RuleTrace::VALUE_LIMIT + 50));

		$trace = $this->tracer->trace(
			condition: ['==' => [['var' => 'toelichting'], 'kort']],
			document: ['toelichting' => $long],
			verdict: RuleVocabulary::VERDICT_NO_MATCH
		);

		$this->assertSame(expected: (RuleTrace::VALUE_LIMIT + 1), actual: mb_strlen((string)$trace->getOperandValue()));
		$this->assertStringEndsWith(suffix: '…', string: (string)$trace->getOperandValue());

	}//end testALongValueIsCutAndSaysSo()
}//end class
