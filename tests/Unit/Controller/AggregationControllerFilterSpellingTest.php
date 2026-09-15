<?php

/**
 * Unit tests for the filter spelling the aggregation endpoints accept
 * (openregister#3611): `filter[x]=v` and a bare `x=v` must mean one filter,
 * and the cache key must follow the normalised map rather than the parameters
 * the endpoint happened to recognise.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
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

namespace Unit\Controller;

use OCA\OpenRegister\Controller\AggregationController;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Aggregation\TimeseriesRequestValidator;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \OCA\OpenRegister\Controller\AggregationController
 */
class AggregationControllerFilterSpellingTest extends TestCase {

	/**
	 * The schema under test. `limit` is declared on purpose: it is a
	 * grouped-aggregation control parameter AND a plausible property name, and
	 * only the bracket spelling may filter on it.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const PROPERTIES = [
		'origin' => ['type' => 'string'],
		'hours' => ['type' => 'number'],
		'limit' => ['type' => 'integer'],
	];

	/**
	 * A runner whose schema lookup answers with {@see PROPERTIES}, and whose
	 * `runAdhocByRef()` records the query it was asked to execute.
	 *
	 * @param AggregationQuery|null $captured Receives the executed query.
	 * @param array<string, mixed>  $envelope The result envelope to answer with.
	 *
	 * @return AggregationRunner
	 */
	private function makeRunner(?AggregationQuery &$captured, array $envelope = ['value' => 0, 'backend' => 'postgres', 'cached' => false]): AggregationRunner {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn(self::PROPERTIES);

		$runner = $this->createMock(AggregationRunner::class);
		$runner->method('findSchema')->willReturn($schema);
		$runner->method('runAdhocByRef')->willReturnCallback(
			function ($reg, $sch, AggregationQuery $query) use (&$captured, $envelope) {
				$captured = $query;
				return $envelope;
			}
		);

		return $runner;
	}//end makeRunner()

	/**
	 * A request serving one parameter map through both `getParam()` and
	 * `getParams()`, so the two readings cannot disagree.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return IRequest
	 */
	private function makeRequest(array $params): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $name, $default = null) use ($params) {
				return array_key_exists($name, $params) ? $params[$name] : $default;
			}
		);
		$request->method('getParams')->willReturn($params);

		return $request;
	}//end makeRequest()

	/**
	 * Build the controller.
	 *
	 * @param array<string, mixed>  $params The request parameters.
	 * @param AggregationRunner     $runner The runner double.
	 * @param LoggerInterface|null  $logger The logger double, when asserted on.
	 *
	 * @return AggregationController
	 */
	private function makeController(array $params, AggregationRunner $runner, ?LoggerInterface $logger = null): AggregationController {
		return new AggregationController(
			'openregister',
			$this->makeRequest($params),
			$runner,
			$this->createMock(TimeseriesRequestValidator::class),
			($logger ?? $this->createMock(LoggerInterface::class))
		);
	}//end makeController()

	/**
	 * Call `value()` and return the query the runner was asked to execute.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param LoggerInterface|null $logger The logger double, when asserted on.
	 *
	 * @return AggregationQuery|null
	 */
	private function captureValueQuery(array $params, ?LoggerInterface $logger = null): ?AggregationQuery {
		$captured = null;
		$params = ($params + ['register' => 'humaniq', 'schema' => 'TimeEntry']);
		$this->makeController($params, $this->makeRunner($captured), $logger)->value('humaniq', 'TimeEntry');

		return $captured;
	}//end captureValueQuery()

	/**
	 * The defect itself: a bare key was dropped, so the caller got the figure
	 * for the whole schema while believing the query was scoped.
	 *
	 * @return void
	 */
	public function testBareKeyReachesTheAggregationFilter(): void {
		$query = $this->captureValueQuery(['metric' => 'count', 'origin' => 'manual']);

		$this->assertSame(['origin' => 'manual'], $query?->filter);
	}//end testBareKeyReachesTheAggregationFilter()

	/**
	 * The bracket spelling keeps working and produces the same filter map as
	 * the bare one, which is the whole point of the fix.
	 *
	 * @return void
	 */
	public function testBracketKeyProducesTheSameFilterAsTheBareKey(): void {
		$bare = $this->captureValueQuery(['metric' => 'count', 'origin' => 'manual']);
		$bracketed = $this->captureValueQuery(['metric' => 'count', 'filter' => ['origin' => 'manual']]);

		$this->assertSame(['origin' => 'manual'], $bracketed?->filter);
		$this->assertSame($bare?->filter, $bracketed?->filter);
	}//end testBracketKeyProducesTheSameFilterAsTheBareKey()

	/**
	 * An operator filter reads the same in both spellings too.
	 *
	 * @return void
	 */
	public function testOperatorFilterReadsTheSameInBothSpellings(): void {
		$bare = $this->captureValueQuery(['metric' => 'count', 'hours' => ['gte' => '2']]);
		$bracketed = $this->captureValueQuery(['metric' => 'count', 'filter' => ['hours' => ['gte' => '2']]]);

		$this->assertSame(['hours' => ['gte' => '2']], $bare?->filter);
		$this->assertSame($bare?->filter, $bracketed?->filter);
	}//end testOperatorFilterReadsTheSameInBothSpellings()

	/**
	 * A control parameter never becomes a filter, even when the schema
	 * declares a property of that name. `limit` is the top-N of a grouped
	 * aggregation; `filter[limit]` is the only way to filter on the property.
	 *
	 * @return void
	 */
	public function testGroupedControlParamsAreNotFilters(): void {
		$captured = null;
		$params = [
			'register' => 'humaniq',
			'schema' => 'TimeEntry',
			'groupBy' => 'origin',
			'metric' => 'count',
			'sort' => 'desc',
			'limit' => '5',
		];
		$runner = $this->makeRunner($captured, ['groups' => [], 'backend' => 'postgres', 'cached' => false]);

		$this->makeController($params, $runner)->grouped('humaniq', 'TimeEntry');

		$this->assertSame([], $captured?->filter, 'sort/limit/groupBy/metric steer the query, they do not filter it');
		$this->assertSame(5, ($captured?->groupBy['limit'] ?? null), 'limit must still reach the groupBy spec');
	}//end testGroupedControlParamsAreNotFilters()

	/**
	 * The bracket spelling is how a property that shares a control-parameter
	 * name is filtered.
	 *
	 * @return void
	 */
	public function testBracketSpellingCanFilterAPropertyNamedLikeAControlParam(): void {
		$captured = null;
		$params = [
			'register' => 'humaniq',
			'schema' => 'TimeEntry',
			'groupBy' => 'origin',
			'metric' => 'count',
			'limit' => '5',
			'filter' => ['limit' => '3'],
		];
		$runner = $this->makeRunner($captured, ['groups' => [], 'backend' => 'postgres', 'cached' => false]);

		$this->makeController($params, $runner)->grouped('humaniq', 'TimeEntry');

		$this->assertSame(['limit' => '3'], $captured?->filter);
		$this->assertSame(5, ($captured?->groupBy['limit'] ?? null));
	}//end testBracketSpellingCanFilterAPropertyNamedLikeAControlParam()

	/**
	 * A bare key that names no property keeps being ignored, so a figure a
	 * widget already showed does not change, and it is logged exactly once
	 * naming the key, the endpoint and the schema.
	 *
	 * @return void
	 */
	public function testUnknownBareKeyIsWarnedAndDoesNotFilter(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				$this->logicalAnd(
					$this->stringContains('origni'),
					$this->stringContains('aggregation#value'),
					$this->stringContains('TimeEntry')
				),
				$this->anything()
			);

		$query = $this->captureValueQuery(['metric' => 'count', 'origni' => 'manual'], $logger);

		$this->assertSame([], $query?->filter, 'an unknown key must not silently scope the query to nothing');
	}//end testUnknownBareKeyIsWarnedAndDoesNotFilter()

	/**
	 * A correct request logs nothing. A warning that fires on correct requests
	 * is a warning nobody reads.
	 *
	 * @return void
	 */
	public function testAKnownKeyLogsNothing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$this->captureValueQuery(['metric' => 'count', 'filter' => ['origin' => 'manual']], $logger);
	}//end testAKnownKeyLogsNothing()

	/**
	 * The declared-aggregation route narrows on both spellings too, and its
	 * `{name}` placeholder is never read as a filter.
	 *
	 * @return void
	 */
	public function testNamedAggregationNarrowsOnTheBareSpelling(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn(self::PROPERTIES);
		$runner = $this->createMock(AggregationRunner::class);
		$runner->method('findSchema')->willReturn($schema);

		$captured = null;
		$runner->method('run')->willReturnCallback(
			function (...$args) use (&$captured) {
				$captured = ($args[5] ?? null);
				return ['value' => 1, 'backend' => 'postgres'];
			}
		);

		$params = ['register' => 'humaniq', 'schema' => 'TimeEntry', 'name' => 'totals', 'origin' => 'manual'];
		$this->makeController($params, $runner)->aggregate('humaniq', 'TimeEntry', 'totals');

		$this->assertSame(['origin' => 'manual'], $captured);
	}//end testNamedAggregationNarrowsOnTheBareSpelling()

	/**
	 * The cache key is derived from the normalised filter, so the two
	 * spellings of one query share an entry.
	 *
	 * @return void
	 */
	public function testTwoSpellingsOfOneQueryShareACacheKey(): void {
		$bare = $this->cacheKeyFor(['metric' => 'count', 'origin' => 'manual']);
		$bracketed = $this->cacheKeyFor(['metric' => 'count', 'filter' => ['origin' => 'manual']]);

		$this->assertSame($bare, $bracketed);
	}//end testTwoSpellingsOfOneQueryShareACacheKey()

	/**
	 * Two different queries never share one. This is the half that was broken:
	 * an unrecognised parameter left the key identical, so `?origin=manual`
	 * and `?origin=nonsense` both answered 9 with `cached: true`.
	 *
	 * @return void
	 */
	public function testTwoDifferentQueriesGetDifferentCacheKeys(): void {
		$manual = $this->cacheKeyFor(['metric' => 'count', 'origin' => 'manual']);
		$migration = $this->cacheKeyFor(['metric' => 'count', 'origin' => 'migration']);
		$unfiltered = $this->cacheKeyFor(['metric' => 'count']);

		$this->assertNotSame($manual, $migration);
		$this->assertNotSame($manual, $unfiltered);
	}//end testTwoDifferentQueriesGetDifferentCacheKeys()

	/**
	 * The cache key a request would read: the real {@see AggregationCache}
	 * over the query the controller builds. Going through the cache rather
	 * than comparing filter maps is deliberate, because the key is what
	 * decides whose figure a caller is served.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return string The cache key.
	 */
	private function cacheKeyFor(array $params): string {
		$query = $this->captureValueQuery($params);
		$this->assertInstanceOf(AggregationQuery::class, $query);

		$captured = '';
		$backend = $this->createMock(ICache::class);
		$backend->method('set')->willReturnCallback(
			static function (string $key) use (&$captured) {
				$captured = $key;
				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($backend);

		$cache = new AggregationCache(
			$factory,
			$this->createMock(IUserSession::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(OrganisationService::class)
		);
		$cache->setAdhoc('humaniq', 'TimeEntry', $query, ['value' => 1]);

		return $captured;
	}//end cacheKeyFor()
}//end class
