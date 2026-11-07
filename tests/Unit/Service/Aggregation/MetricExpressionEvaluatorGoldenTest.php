<?php

/**
 * Golden test for MetricExpressionEvaluator::evaluate().
 *
 * Pins the exact value for every grammar production and the exact refusal
 * message for every way an expression can be refused, ahead of a unit-size
 * refactor of the recursive-descent parser.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Aggregation;

use OCA\OpenRegister\Service\Aggregation\MetricExpressionEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Golden pins for the derived-metric evaluator.
 */
class MetricExpressionEvaluatorGoldenTest extends TestCase {

	/**
	 * The scope every pinned expression is evaluated against.
	 *
	 * @var array<string, mixed>
	 */
	private const SCOPE = [
		'a'    => 10,
		'b'    => 4,
		'half' => 0.5,
		'zero' => 0,
		'none' => null,
		'text' => 'x',
	];

	/**
	 * Expressions with their pinned result.
	 *
	 * @return array<string, array{0: string, 1: float|null}>
	 */
	public static function valueProvider(): array {
		return [
			'number'                  => ['7', 7.0],
			'alias int'               => ['a', 10.0],
			'alias float'             => ['half', 0.5],
			'addition chain'          => ['a + b + 1', 15.0],
			'subtraction is left'     => ['a - b - 1', 5.0],
			'mixed add sub'           => ['a - b + 1', 7.0],
			'multiplication'          => ['a * b', 40.0],
			'division is left'        => ['a / b / 2', 1.25],
			'precedence'              => ['1 + a * b', 41.0],
			'parentheses'             => ['(1 + a) * b', 44.0],
			'unary minus'             => ['-a', -10.0],
			'double unary minus'      => ['--a', 10.0],
			'unary minus in product'  => ['b * -half', -2.0],
			'min'                     => ['min(a, b)', 4.0],
			'max'                     => ['max(a, b)', 10.0],
			'nested min max'          => ['max(min(a, b), half * 2)', 4.0],
			'division by zero'        => ['a / zero', null],
			'zero divided'            => ['zero / a', 0.0],
			'division by zero sum'    => ['a / zero + 1', null],
			'null left of plus'       => ['none + 1', null],
			'null right of minus'     => ['1 - none', null],
			'null in product'         => ['none * 0', null],
			'null left of division'   => ['none / zero', null],
			'null under unary minus'  => ['-none', null],
			'null in min'             => ['min(none, a)', null],
			'null in max right'       => ['max(a, none)', null],
			'null alias alone'        => ['none', null],
			'null in parentheses'     => ['(none)', null],
			'poison then continues'   => ['a / zero * b - 3', null],
		];
	}//end valueProvider()

	/**
	 * Every expression evaluates to its pinned result.
	 *
	 * @param string     $expression The source.
	 * @param float|null $expected   The pinned result.
	 *
	 * @return void
	 */
	#[DataProvider('valueProvider')]
	public function testEvaluatesToThePinnedValue(string $expression, ?float $expected): void {
		$result = (new MetricExpressionEvaluator())->evaluate(expression: $expression, scope: self::SCOPE);

		$this->assertSame($expected, $result);
	}//end testEvaluatesToThePinnedValue()

	/**
	 * Refused expressions with their pinned message.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
	 */
	public static function refusalProvider(): array {
		$grammar = 'The grammar is arithmetic over metric aliases: + - * / parentheses, and min()/max() of two arguments.';
		$unknown = 'which is not a metric in this aggregation. Available: %s. A derived metric can only read figures '
			.'computed BEFORE it, so order the `metrics` list accordingly.';
		return [
			'empty'               => ['', self::SCOPE, 'Metric expression is empty.'],
			'blank'               => ['   ', self::SCOPE, 'Metric expression is empty.'],
			'leftover number'     => ['a 1', self::SCOPE, 'Unexpected "1" in metric expression "a 1". '.$grammar],
			'leftover paren'      => ['(a))', self::SCOPE, 'Unexpected ")" in metric expression "(a))". '.$grammar],
			'leftover comma'      => ['a, b', self::SCOPE, 'Unexpected "," in metric expression "a, b". '.$grammar],
			'ended after plus'    => ['a +', self::SCOPE, 'Metric expression ended where a value was expected.'],
			'ended after minus'   => ['-', self::SCOPE, 'Metric expression ended where a value was expected.'],
			'ended in min'        => ['min(a,', self::SCOPE, 'Metric expression ended where a value was expected.'],
			'operator as value'   => ['* a', self::SCOPE, 'Unexpected "*" where a value was expected in a metric expression.'],
			'comma as value'      => ['(,)', self::SCOPE, 'Unexpected "," where a value was expected in a metric expression.'],
			'close as value'      => ['a + )', self::SCOPE, 'Unexpected ")" where a value was expected in a metric expression.'],
			'unclosed paren'      => ['(a + b', self::SCOPE, 'Expected ")" in a metric expression, found "end of expression".'],
			'min without paren'   => ['min a', self::SCOPE, 'Expected "(" in a metric expression, found "a".'],
			'min one argument'    => ['min(a)', self::SCOPE, 'Expected "," in a metric expression, found ")".'],
			'max three arguments' => ['max(a, b, 1)', self::SCOPE, 'Expected ")" in a metric expression, found ",".'],
			'bare min'            => ['min', self::SCOPE, 'Expected "(" in a metric expression, found "end of expression".'],
			'unknown alias'       => [
				'a - typo',
				self::SCOPE,
				'Metric expression references "typo", '.sprintf($unknown, 'a, b, half, none, text, zero'),
			],
			'unknown empty scope' => ['x', [], 'Metric expression references "x", '.sprintf($unknown, '(none)')],
			'non numeric alias'   => [
				'text + 1',
				self::SCOPE,
				'Metric expression references "text", whose value is string rather than a number.',
			],
			'array alias'         => [
				'arr',
				['arr' => [1]],
				'Metric expression references "arr", whose value is array rather than a number.',
			],
			'numeric string'      => [
				's',
				['s' => '5'],
				'Metric expression references "s", whose value is string rather than a number.',
			],
			'lexer refusal'       => [
				'a % b',
				self::SCOPE,
				'Metric expression contains "%", which is not part of the grammar. '
				.'Allowed: metric aliases, numbers, + - * / ( ) and min()/max().',
			],
		];
	}//end refusalProvider()

	/**
	 * Every refusal keeps its pinned message.
	 *
	 * @param string               $expression The source.
	 * @param array<string, mixed> $scope      The scope.
	 * @param string               $message    The pinned message.
	 *
	 * @return void
	 */
	#[DataProvider('refusalProvider')]
	public function testRefusesWithThePinnedMessage(string $expression, array $scope, string $message): void {
		try {
			(new MetricExpressionEvaluator())->evaluate(expression: $expression, scope: $scope);
		} catch (RuntimeException $e) {
			$this->assertSame($message, $e->getMessage());
			return;
		}

		$this->fail('Expected a refusal for "'.$expression.'".');
	}//end testRefusesWithThePinnedMessage()

	/**
	 * One evaluator instance carries no state between evaluations.
	 *
	 * @return void
	 */
	public function testReuseCarriesNoStateBetweenCalls(): void {
		$evaluator = new MetricExpressionEvaluator();

		try {
			$evaluator->evaluate(expression: '(a +', scope: self::SCOPE);
		} catch (RuntimeException) {
			// The refusal itself is pinned above; this test is about the next call.
		}

		$this->assertSame(3.0, $evaluator->evaluate(expression: '1 + 2', scope: []));
	}//end testReuseCarriesNoStateBetweenCalls()
}//end class
