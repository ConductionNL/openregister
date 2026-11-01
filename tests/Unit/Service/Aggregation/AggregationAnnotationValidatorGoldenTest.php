<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Aggregation;

use OCA\OpenRegister\Service\Aggregation\AggregationAnnotationValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for AggregationAnnotationValidator::validate().
 *
 * Each case is a schema with an `x-openregister-aggregations` annotation; the
 * full error list (codes, messages and order) is compared with
 * fixtures/aggregation-annotation-golden.json. Set UPDATE_GOLDEN=1 to rewrite
 * the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Aggregation\AggregationAnnotationValidator
 * @uses   \OCA\OpenRegister\Service\Aggregation\AggregationJoinAnnotationValidator
 * @uses   \OCA\OpenRegister\Service\Aggregation\AggregationMetricsAnnotationValidator
 * @uses   \OCA\OpenRegister\Service\Aggregation\AggregationQuery
 * @uses   \OCA\OpenRegister\Service\Aggregation\AggregationSpecEntry
 */
class AggregationAnnotationValidatorGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/aggregation-annotation-golden.json';

	private const PROPS = ['status' => [], 'amount' => [], 'owner' => [], 'kind' => []];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$with = static fn (mixed $aggregations, mixed $props = self::PROPS): array => ['properties' => $props, 'x-openregister-aggregations' => $aggregations];

		return [
			'no annotation' => [['properties' => self::PROPS]],
			'annotation not an array' => [$with('count')],
			'annotation empty' => [$with([])],
			'numeric name' => [$with([0 => ['metric' => 'count']])],
			'empty name' => [$with(['' => ['metric' => 'count']])],
			'spec not an object' => [$with(['a' => 'count', 'b' => null])],
			'plain count' => [$with(['total' => ['metric' => 'count']])],
			'select alias' => [$with(['total' => ['select' => 'count']])],
			'metric wins over select' => [$with(['total' => ['metric' => 'nope', 'select' => 'count']])],
			'missing metric' => [$with(['total' => []])],
			'array metric' => [$with(['total' => ['metric' => ['sum']]])],
			'unknown metric' => [$with(['total' => ['metric' => 'median', 'filter' => 'x']])],
			'numeric metric' => [$with(['total' => ['metric' => 7]])],
			'sum without field' => [$with(['s' => ['metric' => 'sum']])],
			'avg empty field' => [$with(['s' => ['metric' => 'avg', 'field' => '']])],
			'max unknown field' => [$with(['s' => ['metric' => 'max', 'field' => 'height']])],
			'count_distinct known field' => [$with(['s' => ['metric' => 'count_distinct', 'field' => 'owner']])],
			'count ignores field' => [$with(['s' => ['metric' => 'count', 'field' => 'height']])],
			'filter not a map' => [$with(['f' => ['metric' => 'count', 'filter' => 'status=open']])],
			'where alias not a map' => [$with(['f' => ['metric' => 'count', 'where' => 5]])],
			'filter unknown fields' => [$with(['f' => ['metric' => 'count', 'filter' => ['status' => 'open', 'colour' => 'red', 3 => 'x']]])],
			'where known fields' => [$with(['f' => ['metric' => 'count', 'where' => ['status' => 'open', 'kind' => 'a']]])],
			'filter wins over where' => [$with(['f' => ['metric' => 'count', 'filter' => [], 'where' => 'bad']])],
			'groupBy field' => [$with(['g' => ['metric' => 'count', 'groupBy' => ['field' => 'status']]])],
			'groupBy unknown' => [$with(['g' => ['metric' => 'sum', 'field' => 'amount', 'groupBy' => ['field' => 'colour']]])],
			'groupBy list duplicate' => [$with(['g' => ['metric' => 'count', 'groupBy' => ['status', 'status']]])],
			'groupBy malformed' => [$with(['g' => ['metric' => 'count', 'groupBy' => 'status']])],
			'groupBy fields form' => [$with(['g' => ['metric' => 'count', 'groupBy' => ['fields' => ['status', 'kind']]]])],
			'join malformed' => [$with(['j' => ['metric' => 'count', 'join' => ['x']]])],
			'join through missing' => [$with(['j' => ['metric' => 'count', 'groupBy' => ['field' => 'status'], 'join' => ['on' => ['owner' => 'id']]]])],
			'join valid' => [$with(['j' => ['metric' => 'sum', 'field' => 'amount', 'groupBy' => ['field' => 'status'], 'join' => ['through' => 'Budget', 'on' => ['owner' => 'id'], 'select' => ['total' => 'amount']]]])],
			'everything wrong at once' => [$with(['w' => ['metric' => 'sum', 'field' => 'height', 'filter' => ['colour' => 1], 'groupBy' => ['field' => 'size'], 'join' => 'x']])],
			'cross-schema valid' => [$with(['c' => ['from' => 'Order', 'metric' => 'sum', 'field' => 'anything']])],
			'cross-schema empty from' => [$with(['c' => ['from' => '', 'metric' => 'count']])],
			'cross-schema bad metric and filter' => [$with(['c' => ['from' => 'Order', 'select' => 'median', 'where' => 'x']])],
			'cross-schema skips field checks' => [$with(['c' => ['from' => 'Order', 'metric' => 'count', 'filter' => ['colour' => 1], 'groupBy' => 'x']])],
			'from null is intra-schema' => [$with(['c' => ['from' => null, 'metric' => 'count', 'filter' => ['colour' => 1]]])],
			'metrics valid' => [$with(['m' => ['metrics' => [['metric' => 'count'], ['metric' => 'sum', 'field' => 'amount']], 'groupBy' => ['field' => 'status']]])],
			'metrics invalid skips groupBy' => [$with(['m' => ['metrics' => [['metric' => 'sum', 'field' => 'height']], 'groupBy' => ['field' => 'colour']]])],
			'metrics valid, groupBy unknown, filter ignored' => [$with(['m' => ['metrics' => [['metric' => 'count']], 'groupBy' => ['field' => 'colour'], 'filter' => 'x', 'join' => 'x']])],
			'metrics not a list' => [$with(['m' => ['metrics' => 'count']])],
			'metrics empty list' => [$with(['m' => ['metrics' => []]])],
			'metrics wins over metric' => [$with(['m' => ['metrics' => [['metric' => 'count']], 'metric' => 'nope']])],
			'properties missing' => [['x-openregister-aggregations' => ['s' => ['metric' => 'sum', 'field' => 'amount', 'filter' => ['status' => 1]]]]],
			'properties not an array' => [$with(['s' => ['metric' => 'sum', 'field' => 'amount']], 'nope')],
			'several entries keep order' => [$with([
				'one' => ['metric' => 'median'],
				7 => ['metric' => 'count'],
				'two' => ['metric' => 'sum'],
				'three' => 'x',
				'four' => ['from' => '', 'metric' => 'x'],
				'five' => ['metric' => 'count', 'filter' => ['colour' => 1]],
			])],
		];
	}//end cases()

	#[DataProvider('cases')]
	public function testValidate(array $schema): void {
		$actual = (new AggregationAnnotationValidator())->validate($schema);

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
