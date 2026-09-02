<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Dmn;

use OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator;
use OCA\OpenRegister\Service\Dmn\DecisionTableValidator;
use PHPUnit\Framework\TestCase;

/**
 * The save-time table validator.
 *
 * The property under test is single: the accepted grammar is the executable
 * grammar. Every refusal here is a run-time failure moved to the editor, and
 * every acceptance is backed by the evaluator executing the same cell —
 * because the validator PROBES the evaluator rather than parsing anything
 * itself, the two cannot disagree.
 *
 * @covers \OCA\OpenRegister\Service\Dmn\DecisionTableValidator
 * @uses \OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator
 * @uses \OCA\OpenRegister\Service\Dmn\UnaryTestEvaluator
 * @uses \OCA\OpenRegister\Service\Dmn\DecisionEvaluationException
 */
class DecisionTableValidatorTest extends TestCase {

	/**
	 * The validator under test.
	 *
	 * @return DecisionTableValidator The validator.
	 */
	private function validator(): DecisionTableValidator {
		return new DecisionTableValidator();

	}//end validator()

	/**
	 * A minimal well-formed table.
	 *
	 * @param array<string, mixed> $overrides Keys to replace.
	 *
	 * @return array<string, mixed> The table.
	 */
	private function table(array $overrides = []): array {
		return array_merge(
			[
				'hitPolicy' => 'UNIQUE',
				'inputs' => [['name' => 'severity', 'type' => 'string']],
				'outputs' => [['name' => 'intervention', 'type' => 'string']],
				'rules' => [
					[
						'id' => 'r1',
						'inputEntries' => ['ernstig'],
						'outputEntries' => ['boete'],
					],
				],
			],
			$overrides
		);

	}//end table()

	/**
	 * A clean table passes under every implemented hit policy.
	 *
	 * @return void
	 */
	public function testCleanTablePassesUnderEveryImplementedPolicy(): void {
		foreach (DecisionTableEvaluator::IMPLEMENTED_HIT_POLICIES as $policy) {
			$problems = $this->validator()->validate(table: $this->table(['hitPolicy' => $policy]));
			$this->assertSame([], $problems, 'policy ' . $policy);
		}

	}//end testCleanTablePassesUnderEveryImplementedPolicy()

	/**
	 * An unimplemented hit policy is refused BY NAME, so the author learns
	 * which spelling the engine rejected rather than that "something" did.
	 *
	 * @return void
	 */
	public function testUnimplementedHitPolicyIsRefusedByName(): void {
		$problems = $this->validator()->validate(table: $this->table(['hitPolicy' => 'OUTPUT ORDER']));

		$this->assertCount(1, $problems);
		$this->assertStringContainsString('OUTPUT ORDER', $problems[0]);
		$this->assertStringContainsString('not implemented', $problems[0]);

	}//end testUnimplementedHitPolicyIsRefusedByName()

	/**
	 * A table without inputs, outputs or rules can never decide anything and
	 * is refused rather than saved as a silent no-op.
	 *
	 * @return void
	 */
	public function testEmptySidesAndEmptyRulesAreRefused(): void {
		$this->assertNotSame([], $this->validator()->validate(table: $this->table(['inputs' => []])));
		$this->assertNotSame([], $this->validator()->validate(table: $this->table(['outputs' => []])));
		$this->assertNotSame([], $this->validator()->validate(table: $this->table(['rules' => []])));

	}//end testEmptySidesAndEmptyRulesAreRefused()

	/**
	 * Results are keyed by name, so a duplicate column name would silently
	 * collapse two columns into one answer.
	 *
	 * @return void
	 */
	public function testDuplicateColumnNamesAreRefused(): void {
		$problems = $this->validator()->validate(
			table: $this->table(
				[
					'outputs' => [
						['name' => 'intervention', 'type' => 'string'],
						['name' => 'intervention', 'type' => 'string'],
					],
					'rules' => [
						['id' => 'r1', 'inputEntries' => ['ernstig'], 'outputEntries' => ['boete', 'boete']],
					],
				]
			)
		);

		$this->assertNotSame([], $problems);
		$this->assertStringContainsString('declared twice', $problems[0]);

	}//end testDuplicateColumnNamesAreRefused()

	/**
	 * A short input row would be read as wildcards for the missing tail; a
	 * short output row as nulls. Both are refused with the counts named.
	 *
	 * @return void
	 */
	public function testEntryCountMismatchesAreRefused(): void {
		$problems = $this->validator()->validate(
			table: $this->table(
				[
					'rules' => [
						['id' => 'kort', 'inputEntries' => [], 'outputEntries' => ['boete']],
						['id' => 'leeg', 'inputEntries' => ['ernstig'], 'outputEntries' => []],
					],
				]
			)
		);

		$this->assertCount(2, $problems);
		$this->assertStringContainsString('kort', $problems[0]);
		$this->assertStringContainsString('wildcard', $problems[0]);
		$this->assertStringContainsString('leeg', $problems[1]);

	}//end testEntryCountMismatchesAreRefused()

	/**
	 * PRIORITY ranks by an integer; a string that looks like one is an
	 * authoring error, not a coercion opportunity.
	 *
	 * @return void
	 */
	public function testNonIntegerPriorityIsRefused(): void {
		$problems = $this->validator()->validate(
			table: $this->table(
				[
					'rules' => [
						['id' => 'r1', 'inputEntries' => ['ernstig'], 'outputEntries' => ['boete'], 'priority' => 'hoog'],
					],
				]
			)
		);

		$this->assertCount(1, $problems);
		$this->assertStringContainsString('non-integer priority', $problems[0]);

	}//end testNonIntegerPriorityIsRefused()

	/**
	 * Grammar refusals come from the evaluator executing the cell, and the
	 * problem names the rule and the column so the author can find it.
	 *
	 * @return void
	 */
	public function testMalformedCellsAreRefusedWithRuleAndColumn(): void {
		$problems = $this->validator()->validate(
			table: $this->table(
				[
					'inputs' => [['name' => 'bedrag', 'type' => 'number']],
					'rules' => [
						['id' => 'r1', 'inputEntries' => ['[5..'], 'outputEntries' => ['boete']],
						['id' => 'r2', 'inputEntries' => ['>='], 'outputEntries' => ['boete']],
						['id' => 'r3', 'inputEntries' => ['abc'], 'outputEntries' => ['boete']],
					],
				]
			)
		);

		$this->assertCount(3, $problems);
		$this->assertStringContainsString('r1', $problems[0]);
		$this->assertStringContainsString('bedrag', $problems[0]);
		$this->assertStringContainsString('r2', $problems[1]);
		// `abc` on a number column is a literal that can never be coerced, so
		// the rule could never match anything: an authoring error, not data.
		$this->assertStringContainsString('r3', $problems[2]);
		$this->assertStringContainsString('type_mismatch', $problems[2]);

	}//end testMalformedCellsAreRefusedWithRuleAndColumn()

	/**
	 * The fleet's tables spell types in more than one vocabulary; the
	 * validator honours the evaluator's aliases so a table the evaluator
	 * would run is not refused over a spelling.
	 *
	 * @return void
	 */
	public function testAliasedAndUnknownTypesFollowTheEvaluator(): void {
		$problems = $this->validator()->validate(
			table: $this->table(
				[
					'inputs' => [
						['name' => 'leeftijd', 'type' => 'integer'],
						['name' => 'akkoord', 'type' => 'bool'],
						['name' => 'vrij', 'type' => 'onbekend'],
					],
					'rules' => [
						['id' => 'r1', 'inputEntries' => ['>=18', 'true', 'x'], 'outputEntries' => ['boete']],
					],
				]
			)
		);

		$this->assertSame([], $problems);

	}//end testAliasedAndUnknownTypesFollowTheEvaluator()

	/**
	 * Wildcards, quoted literals, ranges, comparisons and sets all execute:
	 * the grammar the editor accepts is the one dossiq's tables already use.
	 *
	 * @return void
	 */
	public function testTheFullUnaryGrammarIsAccepted(): void {
		$problems = $this->validator()->validate(
			table: $this->table(
				[
					'inputs' => [
						['name' => 'soort', 'type' => 'string'],
						['name' => 'bedrag', 'type' => 'number'],
					],
					'rules' => [
						['id' => 'r1', 'inputEntries' => ['-', '[0..25000]'], 'outputEntries' => ['boete']],
						['id' => 'r2', 'inputEntries' => ['"-"', '> 25000'], 'outputEntries' => ['dwangsom']],
						['id' => 'r3', 'inputEntries' => ['in (bouw, milieu)', '!= 0'], 'outputEntries' => ['waarschuwing']],
					],
				]
			)
		);

		$this->assertSame([], $problems);

	}//end testTheFullUnaryGrammarIsAccepted()
}//end class
