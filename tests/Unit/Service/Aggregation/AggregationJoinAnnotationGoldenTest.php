<?php

/**
 * Golden test for AggregationJoinAnnotationValidator::validate().
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

use OCA\OpenRegister\Search\Service\Aggregation\AggregationJoinAnnotationValidator;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationSpecEntry;
use PHPUnit\Framework\TestCase;

/**
 * Pins the full error list (codes, messages, order) of the `join` clause
 * validation. Compared with fixtures/aggregation-join-annotation-golden.json;
 * set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Search\Service\Aggregation\AggregationJoinAnnotationValidator
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery
 */
class AggregationJoinAnnotationGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/aggregation-join-annotation-golden.json';

	private const PROPS = ['status', 'amount', 'owner', 'kind'];

	/**
	 * Case name => aggregation spec.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function cases(): array {
		$group = ['groupBy' => ['field' => 'status']];
		$valid = ['through' => 'Budget', 'on' => 'Budget.code', 'select' => ['Budget.amount']];
		return [
			'no join' => $group,
			'join null' => $group + ['join' => null],
			'join string' => $group + ['join' => 'Budget'],
			'join list' => $group + ['join' => ['Budget', 'code']],
			'join empty array' => $group + ['join' => []],
			'join false' => $group + ['join' => false],
			'valid string on' => $group + ['join' => $valid],
			'valid map on' => $group + ['join' => ['on' => ['owner' => 'id', 'kind' => 'type']] + $valid],
			'map on unknown parents' => $group + ['join' => ['on' => ['colour' => 'id', 'owner' => 'x', 7 => 'y', 'size' => 'z']] + $valid],
			'on empty string' => $group + ['join' => ['on' => ''] + $valid],
			'on list' => $group + ['join' => ['on' => ['owner', 'id']] + $valid],
			'on empty map' => $group + ['join' => ['on' => []] + $valid],
			'on integer' => $group + ['join' => ['on' => 5] + $valid],
			'on missing' => $group + ['join' => ['through' => 'Budget', 'select' => ['x']]],
			'through empty' => $group + ['join' => ['through' => ''] + $valid],
			'through integer' => $group + ['join' => ['through' => 3] + $valid],
			'select missing' => $group + ['join' => ['through' => 'B', 'on' => 'c']],
			'select empty' => $group + ['join' => ['select' => []] + $valid],
			'select string' => $group + ['join' => ['select' => 'Budget.amount'] + $valid],
			'select mixed entries' => $group + ['join' => ['select' => ['a', '', 3, null, ['field' => 'b'], ['field' => ''], ['field' => 4], ['metric' => 'sum'], [], 'c', ['field' => 'd', 'metric' => 'avg']]] + $valid],
			'select map' => $group + ['join' => ['select' => ['total' => 'amount', 'bad' => 1]] + $valid],
			'filter map' => $group + ['join' => ['filter' => ['a' => 1]] + $valid],
			'filter string' => $group + ['join' => ['filter' => 'x'] + $valid],
			'where string' => $group + ['join' => ['where' => 'x'] + $valid],
			'filter null where bad' => $group + ['join' => ['filter' => null, 'where' => 2] + $valid],
			'filter wins over where' => $group + ['join' => ['filter' => [], 'where' => 'x'] + $valid],
			'no groupBy' => ['join' => $valid],
			'groupBy empty list' => ['groupBy' => [], 'join' => $valid],
			'groupBy string' => ['groupBy' => 'status', 'join' => $valid],
			'groupBy list' => ['groupBy' => ['status', 'kind'], 'join' => $valid],
			'groupBy fields form' => ['groupBy' => ['fields' => ['x']], 'join' => $valid],
			'everything wrong' => ['join' => ['through' => '', 'on' => 1, 'select' => [0], 'filter' => 'x']],
			'everything wrong, map on' => ['groupBy' => 'x', 'join' => ['on' => ['zz' => 'a'], 'select' => [], 'where' => true]],
		];
	}//end cases()

	public function testValidateMatchesGolden(): void {
		$validator = new AggregationJoinAnnotationValidator();
		$actual    = [];
		foreach (self::cases() as $name => $spec) {
			$actual[$name] = $validator->validate(new AggregationSpecEntry('agg-' . count($actual), $spec), self::PROPS);
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
