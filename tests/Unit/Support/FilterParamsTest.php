<?php

/**
 * Unit tests for the filter-spelling grammar (openregister#3611).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-both-filter-spellings-mean-the-same-filter-on-object-search-and-the-aggregations
 */

declare(strict_types=1);

namespace Unit\Support;

use OCA\OpenRegister\Support\FilterParams;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The two spellings of a property filter, `x=v` and `filter[x]=v`, and the
 * rules that keep a control parameter out of both.
 *
 * @coversDefaultClass \OCA\OpenRegister\Support\FilterParams
 */
class FilterParamsTest extends TestCase {

	/**
	 * The bracket spelling lifts to the bare one, which is the grammar object
	 * search reads. Before this it stayed under `filter` and became a filter on
	 * a property no schema declares, so the query returned the empty set.
	 *
	 * @return void
	 */
	public function testBracketFilterLiftsToBareKeys(): void {
		$lifted = FilterParams::liftBracketFilter(
			params: [
				'filter' => ['origin' => 'manual', 'hours' => ['gte' => '2']],
				'_limit' => '200',
			]
		);

		$this->assertSame('manual', $lifted['origin'] ?? null);
		$this->assertSame(['gte' => '2'], $lifted['hours'] ?? null);
		$this->assertArrayNotHasKey('filter', $lifted, 'the lifted map must not stay behind as a property filter');
		$this->assertSame('200', $lifted['_limit'], 'a control parameter passes through untouched');
	}//end testBracketFilterLiftsToBareKeys()

	/**
	 * Both spellings of one key: the bracket value wins, and there is exactly
	 * one filter left, not two.
	 *
	 * @return void
	 */
	public function testBracketWinsOverTheBareSpellingOfTheSameKey(): void {
		$lifted = FilterParams::liftBracketFilter(
			params: ['origin' => 'migration', 'filter' => ['origin' => 'manual']]
		);

		$this->assertSame('manual', $lifted['origin']);
	}//end testBracketWinsOverTheBareSpellingOfTheSameKey()

	/**
	 * The bracket spelling is not a back door to a control parameter. An
	 * underscore-prefixed or context key stays under `filter`, which keeps the
	 * answer such a request already got.
	 *
	 * @return void
	 */
	public function testControlParamsAreNeverLiftedOutOfTheBracketFilter(): void {
		$lifted = FilterParams::liftBracketFilter(
			params: ['filter' => ['_limit' => '1', 'schemas' => '2,3', 'origin' => 'manual']]
		);

		$this->assertSame('manual', $lifted['origin'] ?? null);
		$this->assertSame(['_limit' => '1', 'schemas' => '2,3'], $lifted['filter'] ?? null);
	}//end testControlParamsAreNeverLiftedOutOfTheBracketFilter()

	/**
	 * A schema that really declares a `filter` property keeps the old meaning,
	 * and the caller decides that; the lift itself leaves a non-map `filter`
	 * (a scalar, or a plain list) exactly as it found it.
	 *
	 * @return void
	 */
	public function testANonMapFilterParamIsLeftAlone(): void {
		$this->assertSame(['filter' => 'draft'], FilterParams::liftBracketFilter(params: ['filter' => 'draft']));
		$this->assertSame(['filter' => ['a', 'b']], FilterParams::liftBracketFilter(params: ['filter' => ['a', 'b']]));
	}//end testANonMapFilterParamIsLeftAlone()

	/**
	 * The aggregation side of the same rule: a bare key that names a declared
	 * property joins the filter map, and the bracket spelling of the same query
	 * produces the identical map.
	 *
	 * @return void
	 */
	public function testBothSpellingsProduceTheSameAggregationFilter(): void {
		$properties = ['origin' => ['type' => 'string']];

		$bare = FilterParams::forAggregation(
			bracket: [],
			params: ['register' => 'humaniq', 'schema' => 'TimeEntry', 'metric' => 'count', 'origin' => 'manual'],
			controlParams: FilterParams::AGGREGATION_CONTROL_PARAMS['value'],
			properties: $properties
		);
		$bracketed = FilterParams::forAggregation(
			bracket: ['origin' => 'manual'],
			params: ['register' => 'humaniq', 'schema' => 'TimeEntry', 'metric' => 'count'],
			controlParams: FilterParams::AGGREGATION_CONTROL_PARAMS['value'],
			properties: $properties
		);

		$this->assertSame(['origin' => 'manual'], $bare['filter']);
		$this->assertSame($bare['filter'], $bracketed['filter']);
		$this->assertSame([], $bare['unknown']);
	}//end testBothSpellingsProduceTheSameAggregationFilter()

	/**
	 * Two different queries stay different. This is the half that keeps the
	 * cache key honest: the normalised map is what the key is built from, so a
	 * map that collapsed `manual` and `migration` into one would serve one
	 * caller the other's figure.
	 *
	 * @return void
	 */
	public function testDifferentValuesProduceDifferentFilterMaps(): void {
		$properties = ['origin' => ['type' => 'string']];
		$controlParams = FilterParams::AGGREGATION_CONTROL_PARAMS['value'];

		$manual = FilterParams::forAggregation(bracket: [], params: ['origin' => 'manual'], controlParams: $controlParams, properties: $properties);
		$migration = FilterParams::forAggregation(bracket: [], params: ['origin' => 'migration'], controlParams: $controlParams, properties: $properties);

		$this->assertNotSame($manual['filter'], $migration['filter']);
	}//end testDifferentValuesProduceDifferentFilterMaps()

	/**
	 * A control parameter is never a filter, even when the schema declares a
	 * property of that name. `limit` steers the top-N of a grouped
	 * aggregation; only `filter[limit]` can filter on the property.
	 *
	 * @return void
	 */
	public function testAggregationControlParamsAreNeverFilters(): void {
		$properties = [
			'limit' => ['type' => 'integer'],
			'name' => ['type' => 'string'],
			'status' => ['type' => 'string'],
		];

		$normalised = FilterParams::forAggregation(
			bracket: [],
			params: [
				'register' => 'reg',
				'schema' => 'sch',
				'groupBy' => 'status',
				'metric' => 'count',
				'sort' => 'desc',
				'limit' => '5',
				'_route' => 'openregister.aggregation.grouped',
			],
			controlParams: FilterParams::AGGREGATION_CONTROL_PARAMS['grouped'],
			properties: $properties
		);

		$this->assertSame([], $normalised['filter']);
		$this->assertSame([], $normalised['unknown'], 'a control parameter is not an unknown filter key either');
	}//end testAggregationControlParamsAreNeverFilters()

	/**
	 * `name` is a control parameter on the declared-aggregation route only,
	 * because that route has a `{name}` placeholder. On `value` it is an
	 * ordinary property filter.
	 *
	 * @return void
	 */
	public function testNameIsAControlParamOnlyOnTheDeclaredAggregationRoute(): void {
		$properties = ['name' => ['type' => 'string']];

		$onAggregate = FilterParams::forAggregation(
			bracket: [],
			params: ['name' => 'totalOpen'],
			controlParams: FilterParams::AGGREGATION_CONTROL_PARAMS['aggregate'],
			properties: $properties
		);
		$onValue = FilterParams::forAggregation(
			bracket: [],
			params: ['name' => 'totalOpen'],
			controlParams: FilterParams::AGGREGATION_CONTROL_PARAMS['value'],
			properties: $properties
		);

		$this->assertSame([], $onAggregate['filter']);
		$this->assertSame(['name' => 'totalOpen'], $onValue['filter']);
	}//end testNameIsAControlParamOnlyOnTheDeclaredAggregationRoute()

	/**
	 * A bare key that names no property is NOT promoted to a filter: it keeps
	 * being ignored, as it always was, so a cache-buster cannot start scoping a
	 * query to nothing. It is reported for the log.
	 *
	 * @return void
	 */
	public function testAnUnknownBareKeyIsReportedAndNotFiltered(): void {
		$normalised = FilterParams::forAggregation(
			bracket: [],
			params: ['origni' => 'manual', 'v' => '2'],
			controlParams: FilterParams::AGGREGATION_CONTROL_PARAMS['value'],
			properties: ['origin' => ['type' => 'string']]
		);

		$this->assertSame([], $normalised['filter'], 'an unknown bare key must not change the rows the query sees');
		$this->assertSame(['origni', 'v'], $normalised['unknown']);
	}//end testAnUnknownBareKeyIsReportedAndNotFiltered()

	/**
	 * A bracket key that names no property still reaches the filter map, which
	 * is what it always did, and is reported too.
	 *
	 * @return void
	 */
	public function testAnUnknownBracketKeyKeepsItsOldEffectAndIsReported(): void {
		$normalised = FilterParams::forAggregation(
			bracket: ['origni' => 'manual'],
			params: [],
			controlParams: FilterParams::AGGREGATION_CONTROL_PARAMS['value'],
			properties: ['origin' => ['type' => 'string']]
		);

		$this->assertSame(['origni' => 'manual'], $normalised['filter']);
		$this->assertSame(['origni'], $normalised['unknown']);
	}//end testAnUnknownBracketKeyKeepsItsOldEffectAndIsReported()

	/**
	 * The object-search side of "names no property": `@self`, every
	 * underscore-prefixed key and every context parameter are not filters, so
	 * they are never reported.
	 *
	 * @return void
	 */
	public function testUnknownObjectFilterKeysSkipsMetadataAndContext(): void {
		$unknown = FilterParams::unknownObjectFilterKeys(
			query: [
				'@self' => ['register' => 1],
				'_limit' => '20',
				'registers' => '1,2',
				'origin' => 'manual',
				'origni' => 'manual',
			],
			properties: ['origin' => ['type' => 'string']]
		);

		$this->assertSame(['origni'], $unknown);
	}//end testUnknownObjectFilterKeysSkipsMetadataAndContext()

	/**
	 * One warning per request, naming every key, the endpoint and the schema.
	 *
	 * @return void
	 */
	public function testWarnUnknownKeysLogsOnceNamingKeyEndpointAndSchema(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				$this->logicalAnd(
					$this->stringContains('origni'),
					$this->stringContains('objects#index'),
					$this->stringContains('TimeEntry')
				),
				$this->callback(
					static function (array $context): bool {
						return ($context['keys'] ?? null) === ['origni', 'hoursx']
							&& ($context['endpoint'] ?? null) === 'objects#index'
							&& ($context['schema'] ?? null) === 'TimeEntry';
					}
				)
			);

		FilterParams::warnUnknownKeys(
			logger: $logger,
			keys: ['origni', 'hoursx'],
			endpoint: 'objects#index',
			register: 'humaniq',
			schema: 'TimeEntry'
		);
	}//end testWarnUnknownKeysLogsOnceNamingKeyEndpointAndSchema()

	/**
	 * Nothing to report, nothing logged: a correct request must not produce
	 * noise, or the warning stops being read.
	 *
	 * @return void
	 */
	public function testWarnUnknownKeysIsSilentWithoutFindings(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		FilterParams::warnUnknownKeys(logger: $logger, keys: [], endpoint: 'objects#index', register: 'humaniq', schema: 'TimeEntry');
	}//end testWarnUnknownKeysIsSilentWithoutFindings()

	/**
	 * The lookup that lets the aggregation controller skip resolving the
	 * schema when nothing in the request could be a filter.
	 *
	 * @return void
	 */
	public function testHasBareCandidatesIgnoresControlAndRouteParams(): void {
		$controlParams = FilterParams::AGGREGATION_CONTROL_PARAMS['value'];

		$this->assertFalse(
			FilterParams::hasBareCandidates(
				params: ['register' => 'reg', 'schema' => 'sch', 'metric' => 'count', 'field' => 'hours', '_route' => 'x'],
				controlParams: $controlParams
			)
		);
		$this->assertTrue(
			FilterParams::hasBareCandidates(params: ['metric' => 'count', 'origin' => 'manual'], controlParams: $controlParams)
		);
	}//end testHasBareCandidatesIgnoresControlAndRouteParams()
}//end class
