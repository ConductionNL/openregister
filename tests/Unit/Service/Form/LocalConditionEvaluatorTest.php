<?php

/**
 * The server reads a local `visibleWhen` condition the way the client does, and fails safe.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-journey-must-declare-steps-branching-writes-and-access
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Service\Form\LocalConditionEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every operator of `$defs.visibleWhen`, the all/any groups, and the modes it does not evaluate.
 *
 * @covers \OCA\OpenRegister\Service\Form\LocalConditionEvaluator
 */
class LocalConditionEvaluatorTest extends TestCase {

	/**
	 * The values the conditions read.
	 *
	 * @var array<string, mixed>
	 */
	private const VALUES = [
		'kind' => 'company',
		'count' => '3',
		'blank' => '',
		'none' => null,
		'list' => [],
		'tags' => ['health', 'travel'],
		'agreed' => true,
		'address' => ['city' => 'Tilburg'],
	];

	/**
	 * Conditions and whether each holds for VALUES.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 */
	public static function conditions(): array {
		return [
			'eq is the default' => [['field' => 'kind', 'value' => 'company'], true],
			'eq misses' => [['field' => 'kind', 'value' => 'person'], false],
			'eq normalises numbers' => [['field' => 'count', 'value' => 3], true],
			'eq normalises booleans' => [['field' => 'agreed', 'value' => 'true'], true],
			'neq' => [['field' => 'kind', 'op' => 'neq', 'value' => 'person'], true],
			'gt coerces to number' => [['field' => 'count', 'op' => 'gt', 'value' => 2], true],
			'gte at the bound' => [['field' => 'count', 'op' => 'gte', 'value' => 3], true],
			'lt' => [['field' => 'count', 'op' => 'lt', 'value' => 3], false],
			'lte at the bound' => [['field' => 'count', 'op' => 'lte', 'value' => '3'], true],
			'gt on a non-number fails safe' => [['field' => 'kind', 'op' => 'gt', 'value' => 1], false],
			'empty on blank' => [['field' => 'blank', 'op' => 'empty'], true],
			'empty on null' => [['field' => 'none', 'op' => 'empty'], true],
			'empty on an empty list' => [['field' => 'list', 'op' => 'empty'], true],
			'empty on a missing field' => [['field' => 'absent', 'op' => 'empty'], true],
			'notEmpty' => [['field' => 'kind', 'op' => 'notEmpty'], true],
			'in' => [['field' => 'kind', 'op' => 'in', 'value' => ['person', 'company']], true],
			'in without a list fails safe' => [['field' => 'kind', 'op' => 'in', 'value' => 'company'], false],
			'anyOf over a list value' => [['field' => 'tags', 'op' => 'anyOf', 'value' => ['ethnicity', 'health']], true],
			'anyOf misses' => [['field' => 'tags', 'op' => 'anyOf', 'value' => ['ethnicity']], false],
			'contains in a list' => [['field' => 'tags', 'op' => 'contains', 'value' => 'travel'], true],
			'contains on a string does not hold' => [['field' => 'kind', 'op' => 'contains', 'value' => 'comp'], false],
			'a dot-path' => [['field' => 'address.city', 'value' => 'Tilburg'], true],
			'all holds' => [['all' => [['field' => 'kind', 'value' => 'company'], ['field' => 'agreed', 'value' => true]]], true],
			'all misses one' => [['all' => [['field' => 'kind', 'value' => 'company'], ['field' => 'agreed', 'value' => false]]], false],
			'any holds' => [['any' => [['field' => 'kind', 'value' => 'person'], ['field' => 'agreed', 'value' => true]]], true],
			'any holds none' => [['any' => [['field' => 'kind', 'value' => 'person']]], false],
			'an unknown operator fails safe' => [['field' => 'kind', 'op' => 'like', 'value' => 'company'], false],
			'no field fails safe' => [['value' => 'company'], false],
			'the endpoint mode is not evaluated' => [['endpoint' => '/x', 'field' => 'kind', 'value' => 'company'], false],
			'the source mode is not evaluated' => [['source' => ['register' => 'r', 'schema' => 's'], 'field' => 'kind', 'value' => 'company'], false],
		];
	}//end conditions()

	/**
	 * Each condition holds exactly as the client would read it.
	 *
	 * @param array<string, mixed> $condition The condition.
	 * @param bool                 $expected  Whether it holds.
	 *
	 * @return void
	 */
	#[DataProvider('conditions')]
	public function testTheConditionHoldsAsTheClientReadsIt(array $condition, bool $expected): void {
		$this->assertSame($expected, (new LocalConditionEvaluator())->holds(condition: $condition, values: self::VALUES));
	}//end testTheConditionHoldsAsTheClientReadsIt()

	/**
	 * Unknown operators are named, including nested ones; accepted ones are not.
	 *
	 * @return void
	 */
	public function testUnknownOperatorsAreNamed(): void {
		$evaluator = new LocalConditionEvaluator();

		$this->assertSame([], $evaluator->unknownOperators(condition: ['field' => 'a', 'op' => 'contains', 'value' => 'x']));
		$this->assertSame(
			['like', 'between'],
			$evaluator->unknownOperators(condition: ['op' => 'like', 'all' => [['field' => 'a'], ['any' => [['op' => 'between']]]]])
		);
	}//end testUnknownOperatorsAreNamed()
}//end class
