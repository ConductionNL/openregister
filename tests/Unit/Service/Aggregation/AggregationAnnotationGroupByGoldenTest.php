<?php

/**
 * Golden test for the groupBy-member and cross-schema paths of
 * AggregationAnnotationValidator.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Aggregation;

use OCA\OpenRegister\Search\Service\Aggregation\AggregationAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * Pins the full error list (codes, messages, order) for groupBy member
 * checks, cross-schema checks and mixed entry lists. Compared with
 * fixtures/aggregation-annotation-groupby-golden.json; set UPDATE_GOLDEN=1
 * to rewrite it.
 *
 * @covers \OCA\OpenRegister\Search\Service\Aggregation\AggregationAnnotationValidator
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationJoinAnnotationValidator
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationMetricsAnnotationValidator
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationSpecEntry
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\MetricsDeclaration
 */
class AggregationAnnotationGroupByGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/aggregation-annotation-groupby-golden.json';

	private const PROPS = ['status' => [], 'amount' => [], 'owner' => [], 'kind' => []];

	/**
	 * Case name => aggregations annotation.
	 *
	 * @return array<string, mixed>
	 */
	private static function cases(): array {
		$count = ['metric' => 'count'];
		return [
			'groupBy mixed members' => ['g' => $count + ['groupBy' => ['status', 5, '', 'status', 'colour', 'colour', 'kind', null]]],
			'groupBy fields empty' => ['g' => $count + ['groupBy' => ['fields' => []]]],
			'groupBy empty list' => ['g' => $count + ['groupBy' => []]],
			'groupBy bucket only' => ['g' => $count + ['groupBy' => ['bucket' => 'month']]],
			'groupBy field null' => ['g' => $count + ['groupBy' => ['field' => null]]],
			'groupBy field empty' => ['g' => $count + ['groupBy' => ['field' => '']]],
			'groupBy fields not list, field wins' => ['g' => $count + ['groupBy' => ['fields' => 'x', 'field' => 'status']]],
			'groupBy fields assoc' => ['g' => $count + ['groupBy' => ['fields' => ['a' => 'kind', 'b' => 'kind', 'c' => 'size']]]],
			'groupBy explicit null' => ['g' => $count + ['groupBy' => null]],
			'groupBy integer' => ['g' => $count + ['groupBy' => 3]],
			'groupBy false' => ['g' => $count + ['groupBy' => false]],
			'metrics entry groupBy members' => ['m' => ['metrics' => [$count], 'groupBy' => ['owner', 'owner', 1]]],
			'cross from integer' => ['c' => ['from' => 5]],
			'cross from false' => ['c' => ['from' => false, 'metric' => 'count']],
			'cross metric null falls to select' => ['c' => ['from' => 'Order', 'metric' => null, 'select' => 'median']],
			'cross metric integer' => ['c' => ['from' => 'Order', 'metric' => 7]],
			'cross metric true' => ['c' => ['from' => 'Order', 'metric' => true]],
			'cross metric empty string' => ['c' => ['from' => 'Order', 'metric' => '']],
			'cross where null, filter bad' => ['c' => ['from' => 'Order', 'where' => null, 'filter' => 'x']],
			'cross filter empty map' => ['c' => ['from' => 'Order', 'filter' => []]],
			'cross join' => ['c' => ['from' => 'Order', 'join' => null]],
			'cross join set' => ['c' => ['from' => 'Order', 'join' => ['through' => 'X']]],
			'cross everything wrong' => ['c' => ['from' => '', 'select' => 'avg2', 'where' => 1, 'join' => 'x']],
			'list of entries' => [
				['metric' => 'count'],
				'ok' => $count + ['groupBy' => ['status']],
				'' => $count,
				'bad' => 4,
				'cross' => ['from' => 'Order', 'metric' => 'sum'],
				'tail' => ['metric' => 'max', 'groupBy' => ['nope']],
			],
		];
	}//end cases()

	public function testValidateMatchesGolden(): void {
		$validator = new AggregationAnnotationValidator();
		$actual    = [];
		foreach (self::cases() as $name => $aggregations) {
			$actual[$name] = $validator->validate(['properties' => self::PROPS, 'x-openregister-aggregations' => $aggregations]);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($expected as $name => $errors) {
			$this->assertSame($errors, $actual[$name], $name);
		}
	}//end testValidateMatchesGolden()
}//end class
