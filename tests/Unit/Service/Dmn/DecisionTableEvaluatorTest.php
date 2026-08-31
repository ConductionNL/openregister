<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Dmn;

use OCA\OpenRegister\Service\Dmn\DecisionEvaluationException;
use OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * The consolidated decision-table evaluator.
 *
 * The fleet built decision tables TWICE — openbuild 2026-06-05, dossiq
 * 2026-07-15, six weeks apart, neither knowing the other existed — and the
 * newer one shipped FEWER hit policies. ADR-065 Decision 6 consolidates them
 * here, and says the consolidation must take openbuild's `priority` because
 * dropping it would be a capability regression.
 *
 * So these tests are mostly about the seams between the two dialects: the
 * policies that came from one side, and the ones whose meaning differed.
 */
class DecisionTableEvaluatorTest extends TestCase {

	/**
	 * The evaluator under test.
	 *
	 * @return DecisionTableEvaluator The evaluator.
	 */
	private function evaluator(): DecisionTableEvaluator {
		return new DecisionTableEvaluator();

	}//end evaluator()

	/**
	 * A table over one string input and one string output.
	 *
	 * @param string                      $hitPolicy The hit policy.
	 * @param array<int, array<string, mixed>> $rules The rules.
	 *
	 * @return array<string, mixed> The table.
	 */
	private function table(string $hitPolicy, array $rules): array {
		return [
			'hitPolicy' => $hitPolicy,
			'inputs' => [['name' => 'severity', 'type' => 'string']],
			'outputs' => [['name' => 'intervention', 'type' => 'string']],
			'rules' => $rules,
		];

	}//end table()

	/**
	 * A single rule.
	 *
	 * @param string       $id       Its id.
	 * @param string       $match    The unary test.
	 * @param string       $output   The output value.
	 * @param integer|null $priority Its priority, or null to omit the key.
	 *
	 * @return array<string, mixed> The rule.
	 */
	private function rule(string $id, string $match, string $output, ?int $priority = null): array {
		$rule = ['id' => $id, 'inputEntries' => [$match], 'outputEntries' => [$output]];
		if ($priority !== null) {
			$rule['priority'] = $priority;
		}

		return $rule;

	}//end rule()

	/**
	 * FIRST takes the earliest matching rule.
	 *
	 * @return void
	 */
	public function testFirstTakesTheEarliestMatch(): void {
		$table = $this->table('FIRST', [
			$this->rule('a', 'ernstig', 'bestuursdwang'),
			$this->rule('b', '-', 'waarschuwing'),
		]);

		$out = $this->evaluator()->evaluate($table, ['severity' => 'ernstig']);

		$this->assertSame('bestuursdwang', $out['outputs']['intervention']);
		$this->assertSame(['a'], $out['matchedRuleIds']);

	}//end testFirstTakesTheEarliestMatch()

	/**
	 * UNIQUE refuses an overlap rather than picking one.
	 *
	 * @return void
	 */
	public function testUniqueRefusesAnOverlap(): void {
		$table = $this->table('UNIQUE', [
			$this->rule('a', 'ernstig', 'bestuursdwang'),
			$this->rule('b', '-', 'waarschuwing'),
		]);

		$this->expectException(DecisionEvaluationException::class);

		$this->evaluator()->evaluate($table, ['severity' => 'ernstig']);

	}//end testUniqueRefusesAnOverlap()

	/**
	 * COLLECT returns every matching rule's output.
	 *
	 * @return void
	 */
	public function testCollectReturnsEveryMatch(): void {
		$table = $this->table('COLLECT', [
			$this->rule('a', 'ernstig', 'bestuursdwang'),
			$this->rule('b', '-', 'waarschuwing'),
		]);

		$out = $this->evaluator()->evaluate($table, ['severity' => 'ernstig']);

		$this->assertSame(['bestuursdwang', 'waarschuwing'], $out['outputs']['intervention']);

	}//end testCollectReturnsEveryMatch()

	/**
	 * 🔴 PRIORITY is openbuild's capability, and the reason this is a
	 * consolidation rather than a relocation. dossiq's engine did not have it.
	 *
	 * @return void
	 */
	public function testPriorityTakesTheHighest(): void {
		$table = $this->table('PRIORITY', [
			$this->rule('low', '-', 'waarschuwing', 1),
			$this->rule('high', 'ernstig', 'bestuursdwang', 10),
			$this->rule('mid', '-', 'herstelactie', 5),
		]);

		$out = $this->evaluator()->evaluate($table, ['severity' => 'ernstig']);

		$this->assertSame('bestuursdwang', $out['outputs']['intervention']);
		$this->assertSame(['high'], $out['matchedRuleIds']);

	}//end testPriorityTakesTheHighest()

	/**
	 * PRIORITY breaks a tie by declaration order, deterministically.
	 *
	 * @return void
	 */
	public function testPriorityBreaksTiesByDeclarationOrder(): void {
		$table = $this->table('PRIORITY', [
			$this->rule('first', '-', 'waarschuwing', 5),
			$this->rule('second', '-', 'herstelactie', 5),
		]);

		$out = $this->evaluator()->evaluate($table, ['severity' => 'gering']);

		$this->assertSame('waarschuwing', $out['outputs']['intervention']);

	}//end testPriorityBreaksTiesByDeclarationOrder()

	/**
	 * A rule with no priority counts as zero rather than as undefined.
	 *
	 * @return void
	 */
	public function testAnAbsentPriorityCountsAsZero(): void {
		$table = $this->table('PRIORITY', [
			$this->rule('unset', '-', 'waarschuwing'),
			$this->rule('set', '-', 'bestuursdwang', 3),
		]);

		$out = $this->evaluator()->evaluate($table, ['severity' => 'gering']);

		$this->assertSame('bestuursdwang', $out['outputs']['intervention']);

	}//end testAnAbsentPriorityCountsAsZero()

	/**
	 * 🔴 ANY requires the matching rules to AGREE.
	 *
	 * openbuild's evaluator treated `any` as `collect` and returned a list —
	 * a different answer of a different shape. DMN says a table declaring ANY
	 * asserts its overlapping rules produce the same output, so a disagreement
	 * is a fault in the table rather than a choice to make silently.
	 *
	 * @return void
	 */
	public function testAnyRefusesDisagreeingRules(): void {
		$table = $this->table('ANY', [
			$this->rule('a', '-', 'waarschuwing'),
			$this->rule('b', '-', 'bestuursdwang'),
		]);

		$this->expectException(DecisionEvaluationException::class);

		$this->evaluator()->evaluate($table, ['severity' => 'gering']);

	}//end testAnyRefusesDisagreeingRules()

	/**
	 * ANY accepts agreeing rules and returns the shared output.
	 *
	 * @return void
	 */
	public function testAnyAcceptsAgreeingRules(): void {
		$table = $this->table('ANY', [
			$this->rule('a', '-', 'waarschuwing'),
			$this->rule('b', 'gering', 'waarschuwing'),
		]);

		$out = $this->evaluator()->evaluate($table, ['severity' => 'gering']);

		$this->assertSame('waarschuwing', $out['outputs']['intervention']);

	}//end testAnyAcceptsAgreeingRules()

	/**
	 * An unimplemented hit policy is refused rather than treated as FIRST.
	 *
	 * @return void
	 */
	public function testAnUnknownHitPolicyIsRefused(): void {
		$table = $this->table('OUTPUT-ORDER', [$this->rule('a', '-', 'waarschuwing')]);

		$this->expectException(DecisionEvaluationException::class);

		$this->evaluator()->evaluate($table, ['severity' => 'gering']);

	}//end testAnUnknownHitPolicyIsRefused()

	/**
	 * The LHS matrix shape: three axes, one intervention, UNIQUE.
	 *
	 * This is the table dossiq's Landelijke Handhavingsstrategie becomes — a
	 * dense (ernst x gedrag x actorType) lookup — expressed in the shared
	 * vocabulary rather than in a bespoke matrix service.
	 *
	 * @return void
	 */
	public function testTheLhsMatrixShapeEvaluates(): void {
		$table = [
			'hitPolicy' => 'UNIQUE',
			'inputs' => [
				['name' => 'severity', 'type' => 'string'],
				['name' => 'behaviour', 'type' => 'string'],
				['name' => 'actorType', 'type' => 'string'],
			],
			'outputs' => [['name' => 'intervention', 'type' => 'string']],
			'rules' => [
				[
					'id' => 'gering-goedwillend-burger',
					'inputEntries' => ['gering', 'goedwillend', 'burger'],
					'outputEntries' => ['waarschuwing'],
				],
				[
					'id' => 'ernstig-crimineel-bedrijf',
					'inputEntries' => ['ernstig', 'crimineel', 'bedrijf'],
					'outputEntries' => ['bestuursdwang'],
				],
			],
		];

		$out = $this->evaluator()->evaluate(
			$table,
			['severity' => 'ernstig', 'behaviour' => 'crimineel', 'actorType' => 'bedrijf']
		);

		$this->assertSame('bestuursdwang', $out['outputs']['intervention']);
		$this->assertSame(['ernstig-crimineel-bedrijf'], $out['matchedRuleIds']);

	}//end testTheLhsMatrixShapeEvaluates()

	/**
	 * A numeric range still works, so the ported grammar came across intact.
	 *
	 * @return void
	 */
	public function testTheRangeGrammarSurvivedThePort(): void {
		$table = [
			'hitPolicy' => 'FIRST',
			'inputs' => [['name' => 'amount', 'type' => 'number']],
			'outputs' => [['name' => 'band', 'type' => 'string']],
			'rules' => [
				['id' => 'low', 'inputEntries' => ['[0..25000]'], 'outputEntries' => ['low']],
				['id' => 'high', 'inputEntries' => ['(25000..100000]'], 'outputEntries' => ['high']],
			],
		];

		$this->assertSame('low', $this->evaluator()->evaluate($table, ['amount' => 25000])['outputs']['band']);
		$this->assertSame('high', $this->evaluator()->evaluate($table, ['amount' => 25001])['outputs']['band']);

	}//end testTheRangeGrammarSurvivedThePort()

	/**
	 * Set membership survived the port too.
	 *
	 * @return void
	 */
	public function testSetMembershipSurvivedThePort(): void {
		$table = $this->table('FIRST', [
			$this->rule('set', 'in (gering, aanzienlijk)', 'licht'),
			$this->rule('rest', '-', 'zwaar'),
		]);

		$this->assertSame('licht', $this->evaluator()->evaluate($table, ['severity' => 'aanzienlijk'])['outputs']['intervention']);
		$this->assertSame('zwaar', $this->evaluator()->evaluate($table, ['severity' => 'ernstig'])['outputs']['intervention']);

	}//end testSetMembershipSurvivedThePort()

	/**
	 * No matching rule is an error, not an empty answer.
	 *
	 * @return void
	 */
	public function testNoMatchIsAnError(): void {
		$table = $this->table('UNIQUE', [$this->rule('a', 'ernstig', 'bestuursdwang')]);

		$this->expectException(DecisionEvaluationException::class);

		$this->evaluator()->evaluate($table, ['severity' => 'gering']);

	}//end testNoMatchIsAnError()

}//end class
