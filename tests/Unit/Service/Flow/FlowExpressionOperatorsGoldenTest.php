<?php

/**
 * Golden: every custom JSONLogic operator a flow expression may use.
 *
 * Pinned on the code before the operators moved out of
 * FlowExpression::registerOperators(). Dates are checked only through
 * outputs that do not depend on the server's zone or clock.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowExpression;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowExpression
 */
final class FlowExpressionOperatorsGoldenTest extends TestCase {

	/**
	 * Expression => expected result.
	 *
	 * @return array<string, array{mixed, mixed}>
	 */
	public static function operators(): array {
		return [
			'upper' => [['upper' => ['abé']], 'ABÉ'],
			'lower' => [['lower' => ['AbÉ']], 'abé'],
			'trim' => [['trim' => ['  a ']], 'a'],
			'split default' => [['split' => ['a,b']], ['a', 'b']],
			'split sep' => [['split' => ['a|b', '|']], ['a', 'b']],
			'join default' => [['join' => [['a', 'b']]], 'a,b'],
			'join sep' => [['join' => [['a', 'b'], '-']], 'a-b'],
			'replace' => [['replace' => ['aXbX', 'X', 'y']], 'ayby'],
			'matches yes' => [['matches' => ['abc', '/^a/']], true],
			'matches no' => [['matches' => ['abc', '/^z/']], false],
			'matches bad pattern' => [['matches' => ['abc', '/(']], false],
			'dateFormat' => [['dateFormat' => ['2024-06-15T12:00:00Z', 'Y']], '2024'],
			'dateFormat bad' => [['dateFormat' => ['not a date', 'Y']], null],
			'dateAdd' => [['dateFormat' => [['dateAdd' => ['2024-06-15T12:00:00Z', '+1 day']], 'Y-m-d']], '2024-06-16'],
			'dateAdd bad' => [['dateAdd' => ['2024-06-15T12:00:00Z', 'nonsense modifier']], null],
			'unique' => [['unique' => [['a', 'b', 'a']]], ['a', 'b']],
			'sort' => [['sort' => [['c', 'a', 'b']]], ['a', 'b', 'c']],
			'length list' => [['length' => [['a', 'b']]], 2],
			'length string' => [['length' => ['abé']], 3],
			'coalesce' => [['coalesce' => [null, '', 0, 'x']], 0],
			'coalesce none' => [['coalesce' => [null, '']], null],
			'toJson' => [['toJson' => [['x', 'y']]], '["x","y"]'],
			'fromJson' => [['fromJson' => ['{"a":1}']], ['a' => 1]],
		];
	}//end operators()

	/**
	 * One operator.
	 *
	 * @param mixed $logic    The expression.
	 * @param mixed $expected The result.
	 *
	 * @return void
	 */
	#[DataProvider('operators')]
	public function testTheOperator(mixed $logic, mixed $expected): void {
		$this->assertSame($expected, FlowExpression::evaluate(logic: $logic, data: []));
	}//end testTheOperator()

	/**
	 * `now` is registered and answers an ISO string.
	 *
	 * @return void
	 */
	public function testNowIsRegistered(): void {
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string)FlowExpression::evaluate(logic: ['now' => []], data: []));
		$this->assertTrue(FlowExpression::isValid(logic: ['upper' => ['a']]));
	}//end testNowIsRegistered()
}//end class
