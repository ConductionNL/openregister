<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Aggregation;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Aggregation\TimeseriesRequestValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for TimeseriesRequestValidator::validate().
 *
 * Each case is a raw request against one fixed schema. The validated query
 * (every public property) or the client-facing error message is compared
 * with fixtures/timeseries-request-golden.json. Set UPDATE_GOLDEN=1 to
 * rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Aggregation\TimeseriesRequestValidator
 * @uses   \OCA\OpenRegister\Service\Aggregation\AggregationQuery
 * @uses   \OCA\OpenRegister\Service\Aggregation\AggregationMetricsAnnotationValidator
 * @uses   \OCA\OpenRegister\Service\Aggregation\TimeseriesDateBucketValidator
 */
class TimeseriesRequestValidatorGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/timeseries-request-golden.json';

	private const PROPERTIES = [
		'status' => ['type' => 'string'],
		'amount' => ['type' => 'number'],
		'day' => ['type' => 'string', 'format' => 'date'],
		'stamp' => ['type' => 'string', 'format' => 'date-time'],
		'note' => 'not-an-array',
		'kind' => ['type' => 'string', 'format' => 7],
	];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$range = ['from' => '2026-01-01', 'to' => '2026-02-01'];

		return [
			'field missing' => [[]],
			'field empty' => [['field' => '']],
			'field undeclared' => [['field' => 'colour']],
			'categorical count' => [['field' => 'status']],
			'metadata field' => [['field' => '_created']],
			'metric upper-case count' => [['field' => 'status', 'metric' => 'COUNT', 'metricField' => 'amount']],
			'sum without metricField' => [['field' => 'status', 'metric' => 'sum']],
			'sum empty metricField' => [['field' => 'status', 'metric' => 'Sum', 'metricField' => '']],
			'sum undeclared metricField' => [['field' => 'status', 'metric' => 'sum', 'metricField' => 'height']],
			'sum declared metricField' => [['field' => 'status', 'metric' => 'SUM', 'metricField' => 'amount']],
			'unknown metric with field' => [['field' => 'status', 'metric' => 'median', 'metricField' => 'amount']],
			'interval unknown' => [['field' => 'day', 'interval' => 'fortnight'] + $range],
			'interval empty is no interval' => [['field' => 'day', 'interval' => ''] + $range],
			'interval day' => [['field' => 'day', 'interval' => 'day'] + $range],
			'interval MONTH upper-case' => [['field' => 'day', 'interval' => 'MONTH'] + $range],
			'interval hour on date field' => [['field' => 'day', 'interval' => 'hour'] + $range],
			'interval minute on date-time field' => [['field' => 'stamp', 'interval' => 'minute'] + $range],
			'interval hour on metadata field' => [['field' => '_updated', 'interval' => 'Hour'] + $range],
			'interval hour on field without format' => [['field' => 'status', 'interval' => 'hour'] + $range],
			'interval hour on non-array property' => [['field' => 'note', 'interval' => 'hour'] + $range],
			'interval hour on non-string format' => [['field' => 'kind', 'interval' => 'hour'] + $range],
			'interval without from' => [['field' => 'day', 'interval' => 'week', 'to' => '2026-02-01']],
			'interval without to' => [['field' => 'day', 'interval' => 'week', 'from' => '2026-01-01']],
			'interval unparseable from' => [['field' => 'day', 'interval' => 'week', 'from' => 'yesterday-ish', 'to' => '2026-02-01']],
			'interval unparseable to' => [['field' => 'day', 'interval' => 'year', 'from' => '2026-01-01', 'to' => 'soon!']],
			'interval with fields ignores fields' => [['field' => 'day', 'interval' => 'quarter', 'fields' => ['status']] + $range],
			'composite fields' => [['field' => 'status', 'fields' => ['status', 'day']]],
			'composite fields empty' => [['field' => 'status', 'fields' => []]],
			'composite fields not a list' => [['field' => 'status', 'fields' => 'status']],
			'composite fields undeclared' => [['field' => 'status', 'fields' => ['status', 'colour']]],
			'metrics valid' => [['field' => 'status', 'metrics' => [['metric' => 'COUNT'], ['metric' => 'sum', 'field' => 'amount']]]],
			'metrics entry not an object' => [['field' => 'status', 'metrics' => ['count']]],
			'metrics entry bad field' => [['field' => 'status', 'metrics' => [['metric' => 'avg', 'field' => 'height']]]],
			'metrics empty' => [['field' => 'status', 'metrics' => []]],
			'filter passes through' => [['field' => 'status', 'filter' => ['status' => 'open']]],
			'filter scalar is cast' => [['field' => 'status', 'filter' => 'open']],
			'cumulative without interval' => [['field' => 'status', 'cumulative' => true]],
			'cumulative string true without interval' => [['field' => 'status', 'cumulative' => 'TRUE']],
			'cumulative string 0 without interval' => [['field' => 'status', 'cumulative' => '0']],
			'cumulative int 1 with interval' => [['field' => 'day', 'interval' => 'day', 'cumulative' => 1] + $range],
			'cumulative int 2 with interval' => [['field' => 'day', 'interval' => 'day', 'cumulative' => 2] + $range],
			'cumulative string 1 with interval' => [['field' => 'day', 'interval' => 'day', 'cumulative' => '1'] + $range],
			'cumulative float with interval' => [['field' => 'day', 'interval' => 'day', 'cumulative' => 1.0] + $range],
			'interval with metric, filter and cumulative' => [['field' => 'stamp', 'metric' => 'max', 'metricField' => 'amount', 'interval' => 'hour', 'filter' => ['status' => 'x'], 'cumulative' => 'true'] + $range],
			'everything at once' => [['field' => 'stamp', 'metric' => 'max', 'metricField' => 'amount', 'interval' => 'hour', 'filter' => ['status' => 'x'], 'metrics' => [['metric' => 'count']], 'cumulative' => true] + $range],
		];
	}//end cases()

	#[DataProvider('cases')]
	public function testValidate(array $input): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn(self::PROPERTIES);

		try {
			$actual = ['query' => get_object_vars((new TimeseriesRequestValidator())->validate($input, $schema))];
		} catch (InvalidArgumentException $e) {
			$actual = ['error' => $e->getMessage()];
		}

		$case = $this->dataName();
		$golden = [];
		if (file_exists(self::FIXTURE) === true) {
			$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$case] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertArrayHasKey($case, $golden, 'No golden entry; record with UPDATE_GOLDEN=1.');
		$this->assertSame($golden[$case], $actual);
	}//end testValidate()
}//end class
