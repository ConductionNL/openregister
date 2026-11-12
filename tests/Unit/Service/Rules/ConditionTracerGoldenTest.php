<?php

/**
 * Golden test for {@see \OCA\OpenRegister\Service\Rules\ConditionTracer}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Rules\ConditionJudgement;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCA\OpenRegister\Service\Rules\ConditionTracer;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each case traces one condition against one document with every verdict;
 * the traces are compared with fixtures/condition-tracer-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite it.
 */
class ConditionTracerGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/condition-tracer-golden.json';

	/**
	 * @return array<string, array{0: mixed, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		$doc = ['bedrag' => 120, 'status' => 'open', 'zaak' => ['type' => 'a', 'n' => ['x' => 1]], 'tags' => ['a'], 'leeg' => null];
		$conditions = [
			'empty' => [],
			'scalar' => 'x',
			'two keys' => ['>' => [1, 2], '<' => [1, 2]],
			'jsonlogic gt' => ['>' => [['var' => 'bedrag'], 500]],
			'jsonlogic gt literal first' => ['>' => [500, ['var' => 'bedrag']]],
			'jsonlogic two literals' => ['==' => [1, 2]],
			'jsonlogic var default' => ['==' => [['var' => ['status', 'x']], 'closed']],
			'jsonlogic var empty list' => ['==' => [['var' => []], 'closed']],
			'jsonlogic var empty string' => ['==' => [['var' => ''], ['var' => 'status']]],
			'jsonlogic var number' => ['==' => [['var' => 5], 1]],
			'jsonlogic dotted' => ['===' => [['var' => 'zaak.type'], 'b']],
			'jsonlogic deep dotted' => ['==' => [['var' => 'zaak.n.x'], 2]],
			'jsonlogic missing path' => ['==' => [['var' => 'zaak.missing.deep'], 2]],
			'jsonlogic through scalar' => ['==' => [['var' => 'status.x'], 2]],
			'jsonlogic in' => ['in' => [['var' => 'status'], ['closed', 'done']]],
			'jsonlogic nested arithmetic' => ['>' => [['-' => [['var' => 'bedrag'], 1]], 500]],
			'jsonlogic nested no reference' => ['>' => [['-' => [3, 1]], 500]],
			'jsonlogic nested scalar arg' => ['>' => [['abs' => 5], ['var' => 'bedrag']]],
			'jsonlogic arg with two keys' => ['>' => [['var' => 'a', 'x' => 1], ['var' => 'bedrag']]],
			'jsonlogic not list' => ['!' => [['==' => [['var' => 'status'], 'open']]]],
			'jsonlogic not bare' => ['!' => ['==' => [['var' => 'status'], 'open']]],
			'jsonlogic not empty' => ['!' => []],
			'jsonlogic not scalar' => ['!' => true],
			'jsonlogic and' => ['and' => [['==' => [['var' => 'status'], 'open']], ['>' => [['var' => 'bedrag'], 500]], ['==' => [['var' => 'leeg'], 1]]]],
			'jsonlogic and all hold' => ['and' => [['==' => [['var' => 'status'], 'open']]]],
			'jsonlogic and empty' => ['and' => []],
			'jsonlogic and scalar' => ['and' => 'x'],
			'jsonlogic or' => ['or' => [['==' => [['var' => 'tags'], 'b']], ['>' => [['var' => 'bedrag'], 500]]]],
			'jsonlogic or keyed' => ['or' => ['k' => ['<' => [['var' => 'bedrag'], 5]]]],
			'jsonlogic or empty' => ['or' => []],
			'jsonlogic or scalar clause' => ['or' => [5]],
			'jsonlogic unknown op' => ['custom' => [['var' => 'status']]],
			'jsonlogic unknown op scalar' => ['custom' => 'x'],
			'jsonlogic bare var' => ['var' => 'status'],
			'jsonlogic comparison scalar args' => ['>' => 5],
			'ast gt' => ['gt' => [['prop' => 'bedrag'], 500]],
			'ast eq dotted' => ['eq' => [['prop' => 'zaak.type'], 'b']],
			'ast not' => ['not' => [['eq' => [['prop' => 'status'], 'open']]]],
			'ast and' => ['and' => [['eq' => [['prop' => 'status'], 'open']], ['gte' => [['prop' => 'bedrag'], 1000]]]],
			'ast nested' => ['lt' => [['add' => [['prop' => 'bedrag'], 1]], 5]],
			'and with list args clause' => ['and' => [[1, 2]]],
		];
		$cases = [];
		foreach ($conditions as $name => $condition) {
			$cases[$name] = [$condition, $doc];
		}

		$cases['empty document'] = [['>' => [['var' => 'bedrag'], 500]], []];
		return $cases;
	}//end cases()

	/**
	 * @param array<string, mixed> $document
	 */
	#[DataProvider('cases')]
	public function testMatchesGolden(mixed $condition, array $document): void {
		$tracer = new ConditionTracer(
			dialect: new ConditionDialect(
				ast: new CalculationEvaluator(
					placeholders: new PlaceholderResolver(userSession: $this->createMock(IUserSession::class))
				)
			)
		);
		$actual = [];
		foreach (['fired', 'no_match', 'refused', 'error'] as $verdict) {
			$actual[$verdict] = $tracer->trace(new ConditionJudgement($condition, $document, $verdict, 'said'))->jsonSerialize();
		}

		$actual['no message'] = $tracer->trace(new ConditionJudgement($condition, $document, 'no_match'))->jsonSerialize();
		$this->assertGolden($actual);
	}//end testMatchesGolden()

	/**
	 * @param array<string, mixed> $actual
	 */
	private function assertGolden(array $actual): void {
		$actual = json_decode(json_encode($actual), true);
		$name = $this->dataName();
		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end assertGolden()
}//end class
