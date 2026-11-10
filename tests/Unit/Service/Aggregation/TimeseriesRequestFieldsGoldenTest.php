<?php

/**
 * Golden test for the composite-fields, metricField and metrics paths of
 * TimeseriesRequestValidator.
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

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Search\Service\Aggregation\TimeseriesRequestValidator;
use PHPUnit\Framework\TestCase;

/**
 * Pins the validated query (every public property) or the client-facing
 * error message. Compared with fixtures/timeseries-request-fields-golden.json;
 * set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Search\Service\Aggregation\TimeseriesRequestValidator
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationMetricsAnnotationValidator
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\MetricEntrySite
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\MetricsDeclaration
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\TimeseriesDateBucketValidator
 */
class TimeseriesRequestFieldsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/timeseries-request-fields-golden.json';

	private const PROPERTIES = [
		'status' => ['type' => 'string'],
		'amount' => ['type' => 'number'],
		'day' => ['type' => 'string', 'format' => 'date'],
		'12' => ['type' => 'string'],
	];

	/**
	 * Case name => raw request.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function cases(): array {
		$base = ['field' => 'status'];
		return [
			'fields duplicates collapse' => $base + ['fields' => ['day', 'status', 'day', 'status', '_created']],
			'fields first member bad type' => $base + ['fields' => [5, 'colour']],
			'fields undeclared before bad type' => $base + ['fields' => ['colour', 5]],
			'fields empty string member' => $base + ['fields' => ['status', '']],
			'fields null member' => $base + ['fields' => [null]],
			'fields assoc' => $base + ['fields' => ['a' => 'day', 'b' => 'amount']],
			'fields numeric string property' => $base + ['fields' => ['12']],
			'fields null' => $base + ['fields' => null],
			'field undeclared with fields' => ['field' => 'colour', 'fields' => ['status']],
			'metricField integer declared' => $base + ['metric' => 'avg', 'metricField' => 12],
			'metricField integer undeclared' => $base + ['metric' => 'avg', 'metricField' => 99],
			'metricField zero string' => $base + ['metric' => 'min', 'metricField' => '0'],
			'metricField false' => $base + ['metric' => 'min', 'metricField' => false],
			'metric numeric' => $base + ['metric' => 5, 'metricField' => 'amount'],
			'metrics not array' => $base + ['metrics' => 'count'],
			'metrics entry missing metric' => $base + ['metrics' => [['field' => 'amount']]],
			'metrics entry numeric metric' => $base + ['metrics' => [['metric' => 7]]],
			'metrics second entry scalar' => $base + ['metrics' => [['metric' => 'count'], 3]],
			'metrics keep extra keys' => $base + ['metrics' => [['metric' => 'MAX', 'field' => 'amount', 'alias' => 'top'], ['metric' => 'Count']]],
			'metrics assoc' => $base + ['metrics' => ['a' => ['metric' => 'count'], 'b' => ['metric' => 'sum', 'field' => 'amount']]],
			'metrics with fields' => $base + ['fields' => ['day'], 'metrics' => [['metric' => 'count']]],
		];
	}//end cases()

	public function testValidateMatchesGolden(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn(self::PROPERTIES);

		$actual = [];
		foreach (self::cases() as $name => $input) {
			try {
				$actual[$name] = ['query' => get_object_vars((new TimeseriesRequestValidator())->validate($input, $schema))];
			} catch (InvalidArgumentException $e) {
				$actual[$name] = ['error' => $e->getMessage()];
			}
		}

		$actual = json_decode((string) json_encode($actual), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name], $name);
		}
	}//end testValidateMatchesGolden()
}//end class
