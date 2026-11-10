<?php

/**
 * Golden test for UnaryTestEvaluator::matches().
 *
 * Pins the answer, or the refusal code and details, for every grammar form
 * (wildcard, quoted literal, set, range, every comparison operator, bare
 * literal) across the four declared types, ahead of a parameter refactor of
 * the private branches. Date values are coerced through the evaluator itself,
 * so the pins hold in any default time zone.
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
use OCA\OpenRegister\Service\Dmn\UnaryTestEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden pins for the DMN cell grammar.
 */
class UnaryTestEvaluatorGrammarGoldenTest extends TestCase {

	/**
	 * Cells with a raw runtime value and the pinned answer.
	 *
	 * The answer is `true`/`false`, or `code|json-details` for a refusal.
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: string, 3: bool|string}>
	 */
	public static function cellProvider(): array {
		return [
			'empty wildcard'           => ['', 'x', 'string', true],
			'dash wildcard'            => ['  -  ', 5, 'number', true],
			'quoted dash'              => ['"-"', '-', 'string', true],
			'quoted dash no match'     => ['"-"', 'x', 'string', false],
			'quoted escaped'           => ['"a\"b"', 'a"b', 'string', true],
			'quoted number'            => ['"10"', 10, 'number', true],
			'set hit'                  => ['in (a, "b,c", d)', 'b,c', 'string', true],
			'set miss'                 => ['IN(a,b)', 'c', 'string', false],
			'set trailing comma'       => ['in (a,)', 'a', 'string', true],
			'set number epsilon'       => ['in (1, 2.0000000001)', 2, 'number', true],
			'set bad member after hit' => ['in (1, x)', 1, 'number', true],
			'set bad member'           => ['in (x, 1)', 1, 'number', 'type_mismatch|{"expected":"number","value":"x"}'],
			'range closed low'         => ['[0..10]', 0, 'number', true],
			'range closed high'        => ['[0..10]', 10, 'number', true],
			'range open low'           => ['(0..10]', 0, 'number', false],
			'range open high'          => ['[0..10)', 10, 'number', false],
			'range inside'             => ['( 0 .. 10 )', 5, 'number', true],
			'range string'             => ['[b..d]', 'c', 'string', true],
			'range string out'         => ['[b..d]', 'e', 'string', false],
			'range missing low'        => ['[..10]', 1, 'number', 'invalid_expression|{"reason":"missing_range_bound"}'],
			'range missing high'       => ['[1..]', 1, 'number', 'invalid_expression|{"reason":"missing_range_bound"}'],
			'range bad bound'          => ['[a..10]', 1, 'number', 'type_mismatch|{"expected":"number","value":"a"}'],
			'range date'               => ['[2024-01-01..2024-12-31]', '2024-06-01', 'date', true],
			'range date out'           => ['[2024-01-01..2024-12-31)', '2024-12-31', 'date', false],
			'lt'                       => ['< 5', 4, 'number', true],
			'lt equal'                 => ['<5', 5, 'number', false],
			'le'                       => ['<= 5', 5, 'number', true],
			'gt'                       => ['> 5', 5, 'number', false],
			'ge'                       => ['>=5', 5, 'number', true],
			'eq number epsilon'        => ['= 0.1', 0.1000000000001, 'number', true],
			'eq string'                => ['= "a b"', 'a b', 'string', true],
			'ne'                       => ['!= a', 'a', 'string', false],
			'ne other'                 => ['!=a', 'b', 'string', true],
			'eq then lt'               => ['=<5', '<5', 'string', true],
			'bang alone is literal'    => ['!a', '!a', 'string', true],
			'missing operand'          => ['>=  ', 1, 'number', 'invalid_expression|{"reason":"missing_operand","operator":">="}'],
			'missing operand single'   => ['<', 1, 'number', 'invalid_expression|{"reason":"missing_operand","operator":"<"}'],
			'bad operand'              => ['> abc', 1, 'number', 'type_mismatch|{"expected":"number","value":"abc"}'],
			'boolean literal'          => ['TRUE', true, 'boolean', true],
			'boolean literal miss'     => ['false', true, 'boolean', false],
			'boolean bad'              => ['yes', true, 'boolean', 'type_mismatch|{"expected":"boolean","value":"yes"}'],
			'date literal'             => ['2024-02-03', '2024-02-03', 'date', true],
			'date compare'             => ['> 2024-02-03', '2024-02-04', 'date', true],
			'bare string'              => ['abc', 'abc', 'string', true],
			'bare string strict'       => ['10', 10, 'string', true],
			'unsupported type'         => ['x', 'x', 'colour', 'type_mismatch|{"reason":"unsupported_type","type":"colour"}'],
		];
	}//end cellProvider()

	/**
	 * Every cell keeps its pinned answer or refusal.
	 *
	 * @param string      $expression The cell.
	 * @param mixed       $raw        The raw runtime value, coerced here for its type.
	 * @param string      $type       The declared type.
	 * @param bool|string $expected   The pinned answer.
	 *
	 * @return void
	 */
	#[DataProvider('cellProvider')]
	public function testMatchesKeepsItsPinnedAnswer(string $expression, mixed $raw, string $type, bool|string $expected): void {
		$evaluator = new UnaryTestEvaluator();
		$value = $raw;
		if (in_array($type, UnaryTestEvaluator::VALID_TYPES, true) === true) {
			$value = $evaluator->coerce(value: $raw, type: $type);
		}

		try {
			$actual = $evaluator->matches(expression: $expression, value: $value, type: $type);
		} catch (DecisionEvaluationException $exception) {
			$actual = $exception->getErrorCode() . '|' . json_encode($exception->getDetails());
		}

		$this->assertSame($expected, $actual);
	}//end testMatchesKeepsItsPinnedAnswer()
}//end class
