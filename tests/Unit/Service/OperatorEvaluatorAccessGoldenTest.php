<?php

/**
 * Golden test pinning every allowed and refused OperatorEvaluator verdict.
 *
 * OperatorEvaluator decides RBAC match conditions (row/field-level security,
 * MemberOfResolver, ConditionMatcher), so each operator's grant AND deny is
 * pinned here before its dispatch and helpers are restructured.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\OperatorEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Allowed and refused verdicts of every supported operator, plus the fail-closed default.
 */
class OperatorEvaluatorAccessGoldenTest extends TestCase {

	/**
	 * Value, operators, expected verdict.
	 *
	 * @return array<string, array{0: mixed, 1: array, 2: bool}>
	 */
	public static function verdicts(): array {
		return [
			'eq allows identical' => [5, ['$eq' => 5], true],
			'eq refuses loose' => ['5', ['$eq' => 5], false],
			'eq allows null on null' => [null, ['$eq' => null], true],
			'ne allows different' => [4, ['$ne' => 5], true],
			'ne refuses same' => [5, ['$ne' => 5], false],
			'ne refuses null value' => [null, ['$ne' => 5], false],
			'ne null operand refuses null' => [null, ['$ne' => null], false],
			'ne null operand allows value' => ['x', ['$ne' => null], true],
			'in allows member' => ['a', ['$in' => ['a', 'b']], true],
			'in refuses non-member' => ['c', ['$in' => ['a', 'b']], false],
			'in refuses loose member' => ['1', ['$in' => [1, 2]], false],
			'in refuses null value' => [null, ['$in' => [null, 'a']], false],
			'in refuses scalar operand' => ['a', ['$in' => 'a'], false],
			'in refuses map operand' => ['a', ['$in' => ['k' => 'a']], false],
			'in refuses empty list' => ['a', ['$in' => []], false],
			'nin allows non-member' => ['c', ['$nin' => ['a', 'b']], true],
			'nin refuses member' => ['a', ['$nin' => ['a', 'b']], false],
			'nin refuses null value' => [null, ['$nin' => ['a']], false],
			'nin refuses scalar operand' => ['c', ['$nin' => 'a'], false],
			'nin refuses map operand' => ['c', ['$nin' => ['k' => 'a']], false],
			'nin allows empty list' => ['c', ['$nin' => []], true],
			'contains allows member' => [['u1', 'u2'], ['$contains' => 'u2'], true],
			'contains refuses non-member' => [['u1'], ['$contains' => 'u2'], false],
			'contains refuses loose member' => [['1'], ['$contains' => 1], false],
			'contains refuses scalar value' => ['u1', ['$contains' => 'u1'], false],
			'contains refuses null value' => [null, ['$contains' => 'u1'], false],
			'contains refuses null operand' => [[null, 'a'], ['$contains' => null], false],
			'contains any allows intersection' => [['g1', 'g2'], ['$contains' => ['g9', 'g2']], true],
			'contains any refuses disjoint' => [['g1'], ['$contains' => ['g9']], false],
			'contains any refuses empty operand' => [['g1'], ['$contains' => []], false],
			'contains any skips null candidate' => [[null], ['$contains' => [null]], false],
			'exists true allows value' => ['x', ['$exists' => true], true],
			'exists true refuses null' => [null, ['$exists' => true], false],
			'exists false allows null' => [null, ['$exists' => false], true],
			'exists false refuses value' => ['x', ['$exists' => false], false],
			'exists non-bool allows' => [null, ['$exists' => 'yes'], true],
			'gt allows greater' => [6, ['$gt' => 5], true],
			'gt refuses equal' => [5, ['$gt' => 5], false],
			'gt refuses null value' => [null, ['$gt' => 5], false],
			'gt refuses null operand' => [6, ['$gt' => null], false],
			'gte allows equal' => [5, ['$gte' => 5], true],
			'gte refuses smaller' => [4, ['$gte' => 5], false],
			'gte refuses null value' => [null, ['$gte' => 5], false],
			'gte refuses null operand' => [5, ['$gte' => null], false],
			'lt allows smaller' => [4, ['$lt' => 5], true],
			'lt refuses equal' => [5, ['$lt' => 5], false],
			'lt refuses null value' => [null, ['$lt' => 5], false],
			'lt refuses null operand' => [4, ['$lt' => null], false],
			'lte allows equal' => [5, ['$lte' => 5], true],
			'lte refuses greater' => [6, ['$lte' => 5], false],
			'lte refuses null value' => [null, ['$lte' => 'b'], false],
			'lte refuses null operand' => [5, ['$lte' => null], false],
			'lte compares strings' => ['a', ['$lte' => 'b'], true],
			'all operators must hold' => [6, ['$gt' => 5, '$lt' => 6], false],
			'range allows inside' => [6, ['$gt' => 5, '$lt' => 7], true],
			'no operators allow' => ['x', [], true],
			'unknown operator refuses' => ['x', ['$regex' => 'x'], false],
			'unknown after allowed refuses' => [5, ['$eq' => 5, '$like' => 5], false],
		];
	}//end verdicts()

	/**
	 * Every verdict is unchanged.
	 *
	 * @param mixed $value The object value.
	 * @param array $operators The operator conditions.
	 * @param bool $expected Whether the match is allowed.
	 *
	 * @return void
	 */
	#[DataProvider('verdicts')]
	public function testVerdict(mixed $value, array $operators, bool $expected): void {
		$evaluator = new OperatorEvaluator($this->createMock(LoggerInterface::class));

		$this->assertSame($expected, $evaluator->valueMatchesOperator($value, $operators));
	}//end testVerdict()

	/**
	 * An unknown operator is logged once as a warning naming the operator, then refused.
	 *
	 * @return void
	 */
	public function testUnknownOperatorIsLoggedAndRefused(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('Unknown operator'),
				$this->callback(static fn (array $ctx): bool => $ctx['operator'] === '$regex')
			);

		$evaluator = new OperatorEvaluator($logger);

		$this->assertFalse($evaluator->valueMatchesOperator('x', ['$regex' => 'x', '$eq' => 'x']));
	}//end testUnknownOperatorIsLoggedAndRefused()

	/**
	 * A known operator logs nothing; evaluation stops at the first refusal.
	 *
	 * @return void
	 */
	public function testRefusalShortCircuitsBeforeUnknownOperator(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$evaluator = new OperatorEvaluator($logger);

		$this->assertFalse($evaluator->valueMatchesOperator(4, ['$eq' => 5, '$regex' => 'x']));
	}//end testRefusalShortCircuitsBeforeUnknownOperator()
}//end class
