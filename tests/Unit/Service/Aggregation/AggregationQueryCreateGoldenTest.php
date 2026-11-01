<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Aggregation;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for AggregationQuery::create().
 *
 * Each case is a set of named factory arguments. The built query (every
 * public property) or the error message is compared with
 * fixtures/aggregation-query-create-golden.json. Set UPDATE_GOLDEN=1 to
 * rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Aggregation\AggregationQuery
 */
class AggregationQueryCreateGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/aggregation-query-create-golden.json';

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$bucket = ['field' => 'day', 'start' => '2026-01-01', 'end' => '2026-02-01', 'gap' => 'month'];

		return [
			'count' => [['metric' => 'count']],
			'count with field and filter' => [['metric' => 'count', 'field' => 'amount', 'filter' => ['status' => 'open']]],
			'unknown metric' => [['metric' => 'median', 'field' => 'amount']],
			'upper-case metric' => [['metric' => 'SUM', 'field' => 'amount']],
			'sum without field' => [['metric' => 'sum']],
			'avg empty field' => [['metric' => 'avg', 'field' => '']],
			'max with field' => [['metric' => 'max', 'field' => 'amount']],
			'metrics empty' => [['metric' => 'count', 'metrics' => []]],
			'metrics entry not an array' => [['metric' => 'count', 'metrics' => ['count']]],
			'metrics entry without metric' => [['metric' => 'count', 'metrics' => [['field' => 'amount']]]],
			'metrics entry bad metric' => [['metric' => 'count', 'metrics' => [['metric' => 'count'], ['metric' => 'p95']]]],
			'metrics entry sum without field' => [['metric' => 'count', 'metrics' => [['metric' => 'sum']]]],
			'metrics entry min empty field' => [['metric' => 'count', 'metrics' => [['metric' => 'min', 'field' => '']]]],
			'metrics valid' => [['metric' => 'count', 'metrics' => [['metric' => 'count'], ['metric' => 'sum', 'field' => 'amount']], 'groupBy' => ['field' => 'status']]],
			'metrics with dateBucket' => [['metric' => 'count', 'metrics' => [['metric' => 'count']], 'dateBucket' => $bucket]],
			'metrics bad entry beats dateBucket' => [['metric' => 'count', 'metrics' => [['metric' => 'x']], 'dateBucket' => $bucket]],
			'groupBy field' => [['metric' => 'count', 'groupBy' => ['field' => 'status']]],
			'groupBy fields' => [['metric' => 'count', 'groupBy' => ['fields' => ['status', 'kind']]]],
			'groupBy list' => [['metric' => 'count', 'groupBy' => ['status', 'kind']]],
			'groupBy empty field' => [['metric' => 'count', 'groupBy' => ['field' => '']]],
			'groupBy non-string member' => [['metric' => 'count', 'groupBy' => ['fields' => ['status', 3]]]],
			'groupBy empty' => [['metric' => 'count', 'groupBy' => []]],
			'groupBy empty fields' => [['metric' => 'count', 'groupBy' => ['fields' => []]]],
			'groupBy duplicate' => [['metric' => 'count', 'groupBy' => ['status', 'status']]],
			'dateBucket valid' => [['metric' => 'count', 'dateBucket' => $bucket]],
			'dateBucket missing gap' => [['metric' => 'count', 'dateBucket' => ['field' => 'day', 'start' => 'a', 'end' => 'b']]],
			'dateBucket empty start' => [['metric' => 'count', 'dateBucket' => ['start' => ''] + $bucket]],
			'dateBucket bad gap' => [['metric' => 'count', 'dateBucket' => ['gap' => 'decade'] + $bucket]],
			'groupBy with dateBucket' => [['metric' => 'count', 'groupBy' => ['field' => 'status'], 'dateBucket' => $bucket]],
			'bad groupBy beats dateBucket combination' => [['metric' => 'count', 'groupBy' => ['field' => ''], 'dateBucket' => $bucket]],
			'bad dateBucket beats groupBy combination' => [['metric' => 'count', 'groupBy' => ['field' => 'status'], 'dateBucket' => ['gap' => 'decade'] + $bucket]],
			'cumulative without dateBucket' => [['metric' => 'count', 'cumulative' => true]],
			'cumulative with groupBy' => [['metric' => 'count', 'groupBy' => ['field' => 'status'], 'cumulative' => true]],
			'cumulative with dateBucket' => [['metric' => 'sum', 'field' => 'amount', 'dateBucket' => $bucket, 'cumulative' => true]],
			'groupBy and dateBucket beat cumulative' => [['metric' => 'count', 'groupBy' => ['field' => 'status'], 'dateBucket' => $bucket, 'cumulative' => true]],
			'metric error beats everything' => [['metric' => 'x', 'metrics' => [], 'groupBy' => [], 'cumulative' => true]],
			'field error beats metrics' => [['metric' => 'sum', 'metrics' => []]],
		];
	}//end cases()

	#[DataProvider('cases')]
	public function testCreate(array $args): void {
		try {
			$actual = ['query' => get_object_vars(AggregationQuery::create(...$args))];
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
	}//end testCreate()
}//end class
