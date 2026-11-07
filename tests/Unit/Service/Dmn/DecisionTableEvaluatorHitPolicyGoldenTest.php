<?php

/**
 * Golden test for DecisionTableEvaluator::evaluate().
 *
 * Pins the complete result, or the refusal code and details (key order
 * included), for every hit policy and every refusal the evaluator raises,
 * ahead of a parameter refactor of the hit-policy branch.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Dmn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Dmn;

use OCA\OpenRegister\Service\Dmn\DecisionEvaluationException;
use OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden pins for hit policies and refusals.
 */
class DecisionTableEvaluatorHitPolicyGoldenTest extends TestCase {

	/**
	 * A two-input, two-output table under a hit policy.
	 *
	 * @param string            $hitPolicy The hit policy as authored.
	 * @param array<int, mixed> $rules     The rules.
	 *
	 * @return array<string, mixed> The table.
	 */
	private static function table(string $hitPolicy, array $rules): array {
		return [
			'hitPolicy' => $hitPolicy,
			'inputs'    => [['name' => 'age', 'type' => 'integer'], ['name' => 'kind', 'type' => 'string'], 'skipped', ['type' => 'string']],
			'outputs'   => [['name' => 'band'], ['name' => 'fee', 'type' => 'number']],
			'rules'     => $rules,
		];
	}//end table()

	/**
	 * Tables, inputs and the pinned JSON of the result or refusal.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}>
	 */
	public static function tableProvider(): array {
		$young = ['id' => 'young', 'inputEntries' => ['< 18', '-'], 'outputEntries' => ['minor', 0]];
		$adult = ['id' => 'adult', 'inputEntries' => ['>= 18', '-'], 'outputEntries' => ['adult', 10]];
		$any = ['inputEntries' => ['[0..120]'], 'outputEntries' => ['any', 5], 'priority' => 3];
		$kind = ['id' => 'kind', 'inputEntries' => ['-', 'in (a, b)'], 'outputEntries' => ['adult', 10], 'priority' => 7];
		$kindOther = ['id' => 'kind2', 'inputEntries' => ['-', 'a'], 'outputEntries' => ['adult'], 'priority' => 7];
		$ok = ['age' => 30, 'kind' => 'a'];

		return [
			'unique single'           => [self::table('unique', [$young, $adult]), $ok, '{"outputs":{"band":"adult","fee":10},"matchedRuleIds":["adult"],"hitPolicy":"UNIQUE"}'],
			'unique violation'        => [self::table('UNIQUE', [$adult, $kind]), $ok, 'hit_policy_violation|{"matchedRuleIds":["adult","kind"]}'],
			'unique no match'         => [self::table('UNIQUE', [$young]), $ok, 'no_rule_matched|[]'],
			'first'                   => [self::table('FIRST', ['junk', $any, $adult]), $ok, '{"outputs":{"band":"any","fee":5},"matchedRuleIds":["0"],"hitPolicy":"FIRST"}'],
			'first second without id' => [self::table('FIRST', [$young, $any]), $ok, '{"outputs":{"band":"any","fee":5},"matchedRuleIds":["0"],"hitPolicy":"FIRST"}'],
			'collect'                 => [self::table('COLLECT', [$young, $adult, $any, $kindOther]), $ok, '{"outputs":{"band":["adult","any","adult"],"fee":[10,5,null]},"matchedRuleIds":["adult","1","kind2"],"hitPolicy":"COLLECT"}'],
			'collect empty'           => [self::table('COLLECT', [$young]), $ok, '{"outputs":{"band":[],"fee":[]},"matchedRuleIds":[],"hitPolicy":"COLLECT"}'],
			'priority'                => [self::table('PRIORITY', [$adult, $any, $kind, $kindOther]), $ok, '{"outputs":{"band":"adult","fee":10},"matchedRuleIds":["kind"],"hitPolicy":"PRIORITY"}'],
			'priority none declared'  => [self::table('PRIORITY', [$adult, $young]), $ok, '{"outputs":{"band":"adult","fee":10},"matchedRuleIds":["adult"],"hitPolicy":"PRIORITY"}'],
			'any agreeing'            => [self::table('ANY', [$adult, $kind]), $ok, '{"outputs":{"band":"adult","fee":10},"matchedRuleIds":["adult"],"hitPolicy":"ANY"}'],
			'any disagreeing'         => [self::table('ANY', [$adult, $kind, $kindOther]), $ok, 'hit_policy_violation|{"hitPolicy":"ANY","matchedRuleIds":["adult","kind","kind2"]}'],
			'any no match'            => [self::table('ANY', []), $ok, 'no_rule_matched|[]'],
			'rules not a list'        => [array_merge(self::table('COLLECT', []), ['rules' => 'x']), $ok, '{"outputs":{"band":[],"fee":[]},"matchedRuleIds":[],"hitPolicy":"COLLECT"}'],
			'unknown hit policy'      => [self::table('rule order', [$adult]), $ok, 'hit_policy_not_implemented|{"hitPolicy":"RULE ORDER"}'],
			'unknown input'           => [self::table('UNIQUE', [$adult]), ['age' => 1, 'kind' => 'a', 'x' => 1], 'unknown_input|{"key":"x"}'],
			'missing input'           => [self::table('UNIQUE', [$adult]), ['age' => 1], 'missing_input|{"name":"kind"}'],
			'cell refusal'            => [self::table('UNIQUE', [['id' => 'bad', 'inputEntries' => ['-', '>=']]]), $ok, 'invalid_expression|{"reason":"missing_operand","operator":">=","ruleId":"bad","input":"kind"}'],
			'cell refusal no id'      => [self::table('UNIQUE', [$young, ['inputEntries' => ['> x']]]), $ok, 'type_mismatch|{"expected":"number","value":"x","ruleId":1,"input":"age"}'],
		];
	}//end tableProvider()

	/**
	 * Every table keeps its pinned result or refusal.
	 *
	 * @param array<string, mixed> $table    The decision table.
	 * @param array<string, mixed> $inputs   The inputs.
	 * @param string               $expected The pinned JSON, or `code|json-details`.
	 *
	 * @return void
	 */
	#[DataProvider('tableProvider')]
	public function testEvaluateKeepsItsPinnedResult(array $table, array $inputs, string $expected): void {
		try {
			$actual = json_encode((new DecisionTableEvaluator())->evaluate(decisionTable: $table, inputs: $inputs));
		} catch (DecisionEvaluationException $exception) {
			$actual = $exception->getErrorCode() . '|' . json_encode($exception->getDetails());
		}

		$this->assertSame($expected, $actual);
	}//end testEvaluateKeepsItsPinnedResult()
}//end class
