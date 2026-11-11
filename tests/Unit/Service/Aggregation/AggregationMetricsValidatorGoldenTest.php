<?php

/**
 * Golden test for the aggregation metrics annotation validator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Aggregation;

use OCA\OpenRegister\Search\Service\Aggregation\AggregationMetricsAnnotationValidator;
use OCA\OpenRegister\Search\Service\Aggregation\MetricsDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * Every error the validator can give, and the inputs it accepts, against a golden
 * recorded before validateEntry() was split.
 *
 * @covers \OCA\OpenRegister\Search\Service\Aggregation\AggregationMetricsAnnotationValidator
 * @covers \OCA\OpenRegister\Search\Service\Aggregation\MetricEntrySite
 * @covers \OCA\OpenRegister\Search\Service\Aggregation\MetricsDeclaration
 */
class AggregationMetricsValidatorGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/Golden/aggregation-metrics-validator.json';

	/**
	 * Metric lists covering every branch.
	 *
	 * @return array<string, mixed>
	 */
	private static function inputs(): array {
		return [
			'not a list' => ['metric' => 'count'],
			'empty' => [],
			'scalar' => 'count',
			'entry not an object' => ['count'],
			'unknown metric' => [['metric' => 'median']],
			'metric not a string' => [['metric' => ['count']]],
			'count' => [['metric' => 'count']],
			'count with alias' => [['metric' => 'count', 'as' => 'n']],
			'empty alias' => [['metric' => 'count', 'as' => '']],
			'alias not a string' => [['metric' => 'count', 'as' => 3]],
			'condition string' => [['metric' => 'count', 'condition' => 'a = 1']],
			'condition empty' => [['metric' => 'count', 'condition' => []]],
			'condition unknown prop' => [['metric' => 'count', 'condition' => ['ghost' => 1]]],
			'condition known prop' => [['metric' => 'count', 'condition' => ['amount' => 1]]],
			'sum no field' => [['metric' => 'sum']],
			'sum unknown field' => [['metric' => 'sum', 'field' => 'ghost']],
			'sum known field' => [['metric' => 'sum', 'field' => 'amount']],
			'avg field not scalar' => [['metric' => 'avg', 'field' => ['amount']]],
			'bad alias and bad condition' => [['metric' => 'sum', 'as' => '', 'condition' => 'x']],
			'bad condition and no field' => [['metric' => 'sum', 'condition' => ['ghost' => 1]]],
			'min with bad alias and no field' => [['metric' => 'min', 'as' => '']],
			'expression empty' => [['metric' => 'expression', 'expression' => '  ', 'as' => 'x']],
			'expression unnamed' => [['metric' => 'expression', 'expression' => 'a/b']],
			'expression with field' => [['metric' => 'expression', 'expression' => 'a/b', 'as' => 'r', 'field' => 'amount']],
			'expression good' => [['metric' => 'expression', 'expression' => 'a/b', 'as' => 'r', 'condition' => 'ignored']],
			'several' => [['metric' => 'count'], ['metric' => 'sum'], 'x', ['metric' => 'max', 'field' => 'amount']],
			'string keys' => ['a' => ['metric' => 'count']],
		];
	}

	/**
	 * The validator answers every input as recorded.
	 *
	 * @return void
	 */
	public function testErrorsMatchTheGolden(): void {
		$validator = new AggregationMetricsAnnotationValidator();
		$actual = [];
		foreach (self::inputs() as $case => $metrics) {
			$actual[$case] = $validator->validate(new MetricsDeclaration(name: 'totals', metrics: $metrics, propKeys: ['amount', 'status']));
		}

		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		$this->assertSame(file_get_contents(self::GOLDEN), $json);
	}
}
