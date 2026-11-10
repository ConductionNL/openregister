<?php

/**
 * SearchTrailService records, pages, enriches, analyses and cleans search trails exactly as recorded.
 *
 * Golden (characterization) test: every expectation below was captured from the
 * implementation before it was split into small units, and pins statistics shapes
 * (including key order and int/float types), trends, ratings, config processing
 * (defaults, aliases, clamping, ignored dates, filtered keys), user-agent parsing,
 * name enrichment (with the mapper's default RBAC/multitenancy flags), and the
 * retention/cleanup outcomes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\SearchTrail;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCA\OpenRegister\Service\RegisterSchemaMappers;
use OCA\OpenRegister\Service\SearchMeasurement;
use OCA\OpenRegister\Service\SearchPeriod;
use OCA\OpenRegister\Service\SearchTrailService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Golden test for SearchTrailService.
 */
class SearchTrailServiceGoldenTest extends TestCase {
	private SearchTrailMapper&MockObject $trailMapper;
	private RegisterMapperInterface&MockObject $registerMapper;
	private SchemaMapperInterface&MockObject $schemaMapper;

	/** @var list<string> */
	private array $calls = [];

	protected function setUp(): void {
		$this->trailMapper = $this->createMock(SearchTrailMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapperInterface::class);
		$this->schemaMapper = $this->createMock(SchemaMapperInterface::class);
		$this->calls = [];
	}

	private function service(?int $retentionDays = null, ?bool $selfClearing = null): SearchTrailService {
		return new SearchTrailService(
			$this->trailMapper,
			new RegisterSchemaMappers($this->registerMapper, $this->schemaMapper),
			$retentionDays,
			$selfClearing
		);
	}

	// ── create + self-clearing ──

	/**
	 * What the service put on the unsaved trail it hands the mapper.
	 *
	 * @param SearchTrail $outcome The unsaved trail.
	 *
	 * @return array{0: int|null, 1: int|null}
	 */
	private static function outcomeOf(SearchTrail $outcome): array {
		return [$outcome->getResultCount(), $outcome->getTotalResults()];
	}

	public function testCreatePassesArgumentsAndDoesNotClearByDefault(): void {
		$trail = new SearchTrail();
		$this->trailMapper->expects($this->once())->method('createSearchTrail')
			->willReturnCallback(function (array $query, SearchTrail $outcome, float $responseTime, string $type) use ($trail) {
				$this->assertSame([['q' => 'x', '_limit' => 3], 4, 40, 12.5, 'async'], [$query, ...self::outcomeOf($outcome), $responseTime, $type]);
				return $trail;
			});
		$this->trailMapper->expects($this->never())->method('clearLogs');

		$this->assertSame($trail, $this->service(30, false)->createSearchTrail(['q' => 'x', '_limit' => 3], new SearchMeasurement(4, 40, 12.5, 'async')));
	}

	public function testCreateDefaultsAndSelfClearingRunsAfterCreate(): void {
		$trail = new SearchTrail();
		$this->trailMapper->method('createSearchTrail')->willReturnCallback(function (...$args) use ($trail) {
			$this->calls[] = 'create';
			$this->assertSame([[], 1, 2, 0.0, 'sync'], [$args[0], ...self::outcomeOf($args[1]), $args[2], $args[3]]);
			return $trail;
		});
		$this->trailMapper->expects($this->once())->method('clearLogs')->willReturnCallback(function () {
			$this->calls[] = 'clear';
			return true;
		});

		$this->assertSame($trail, $this->service(null, true)->createSearchTrail([], new SearchMeasurement(1, 2)));
		$this->assertSame(['create', 'clear'], $this->calls);
	}

	public function testSelfClearingFailureIsSwallowedAndTrailStillReturned(): void {
		$trail = new SearchTrail();
		$this->trailMapper->method('createSearchTrail')->willReturn($trail);
		$this->trailMapper->method('clearLogs')->willThrowException(new Exception('locked'));

		$this->assertSame($trail, $this->service(null, true)->createSearchTrail([], new SearchMeasurement(0, 0)));
	}

	public function testCreateWrapsMapperException(): void {
		$cause = new RuntimeException('db down', 42);
		$this->trailMapper->method('createSearchTrail')->willThrowException($cause);
		$this->trailMapper->expects($this->never())->method('clearLogs');

		try {
			$this->service(null, true)->createSearchTrail([], new SearchMeasurement(0, 0));
			$this->fail('expected exception');
		} catch (Exception $e) {
			$this->assertSame(Exception::class, get_class($e));
			$this->assertSame('Search trail creation failed: db down', $e->getMessage());
			$this->assertSame(0, $e->getCode());
			$this->assertSame($cause, $e->getPrevious());
		}
	}

	// ── retention / cleanup ──

	/**
	 * @return array<string, array{string, bool, int, string}>
	 */
	public static function clearOutcomes(): array {
		return [
			'expired: deleted' => ['clearExpiredSearchTrails', true, 1, 'Self-clearing: deleted expired search trail entries'],
			'expired: nothing' => ['clearExpiredSearchTrails', false, 0, 'Self-clearing: no expired entries to delete'],
			'cleanup: deleted' => ['cleanupSearchTrails', true, 1, 'Successfully deleted expired search trail entries'],
			'cleanup: nothing' => ['cleanupSearchTrails', false, 0, 'No expired entries to delete'],
		];
	}

	/**
	 * @dataProvider clearOutcomes
	 */
	public function testClearOutcomes(string $method, bool $cleared, int $deleted, string $message): void {
		$this->trailMapper->expects($this->once())->method('clearLogs')->willReturnCallback(function (...$args) use ($cleared) {
			$this->assertSame([], $args);
			return $cleared;
		});

		$result = $this->service()->$method();

		$this->assertSame(['success', 'deleted', 'cleanup_date', 'message'], array_keys($result));
		$this->assertTrue($result['success']);
		$this->assertSame($deleted, $result['deleted']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result['cleanup_date']);
		$this->assertSame($message, $result['message']);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function clearFailures(): array {
		return [
			'expired' => ['clearExpiredSearchTrails', 'Self-clearing operation failed'],
			'cleanup' => ['cleanupSearchTrails', 'Cleanup operation failed'],
		];
	}

	/**
	 * @dataProvider clearFailures
	 */
	public function testClearFailures(string $method, string $message): void {
		$this->trailMapper->method('clearLogs')->willThrowException(new Exception('no table'));

		$this->assertSame(
			['success' => false, 'deleted' => 0, 'error' => 'no table', 'message' => $message],
			$this->service()->$method()
		);
	}

	public function testCleanupIgnoresBeforeArgument(): void {
		$this->trailMapper->expects($this->once())->method('clearLogs')->willReturn(false);

		$this->assertSame('No expired entries to delete', $this->service()->cleanupSearchTrails(new DateTime('2020-01-01'))['message']);
	}

	// ── config processing / paging ──

	/**
	 * @return array<string, array{array<string, mixed>, array<string, mixed>, int, array<string, mixed>}>
	 */
	public static function configCases(): array {
		$defaults = ['limit' => 20, 'offset' => 0, 'filters' => [], 'sort' => ['created' => 'DESC'], 'search' => null, 'from' => null, 'to' => null];
		return [
			'defaults' => [[], $defaults, 0, ['page' => 1, 'pages' => 0, 'limit' => 20, 'offset' => 0]],
			'plain limit/offset wins over aliases' => [
				['limit' => '5', '_limit' => 9, 'offset' => '7', '_offset' => 1],
				['limit' => 5, 'offset' => 7] + $defaults,
				11,
				['page' => 2.0, 'pages' => 3, 'limit' => 5, 'offset' => 7],
			],
			'alias limit/offset' => [
				['_limit' => 4, '_offset' => 8],
				['limit' => 4, 'offset' => 8] + $defaults,
				9,
				['page' => 3.0, 'pages' => 3, 'limit' => 4, 'offset' => 8],
			],
			'null limit falls through to alias' => [
				['limit' => null, '_limit' => 6],
				['limit' => 6] + $defaults,
				6,
				['page' => 1, 'pages' => 1, 'limit' => 6, 'offset' => 0],
			],
			'page derives offset' => [
				['page' => 3, 'limit' => 10],
				['limit' => 10, 'offset' => 20] + $defaults,
				25,
				['page' => 3, 'pages' => 3, 'limit' => 10, 'offset' => 20],
			],
			'alias page derives offset' => [
				['_page' => '2', '_limit' => 15],
				['limit' => 15, 'offset' => 15] + $defaults,
				0,
				['page' => 2, 'pages' => 0, 'limit' => 15, 'offset' => 15],
			],
			'page and offset both kept' => [
				['page' => 5, 'offset' => 3],
				['limit' => 20, 'offset' => 3] + $defaults,
				100,
				['page' => 5, 'pages' => 5, 'limit' => 20, 'offset' => 3],
			],
			'clamps limit and page to one, offset not clamped' => [
				['limit' => 0, 'page' => -4],
				['limit' => 1, 'offset' => 0] + $defaults,
				3,
				['page' => 1, 'pages' => 3, 'limit' => 1, 'offset' => 0],
			],
			'negative offset kept' => [
				['_limit' => -3, 'offset' => -2],
				['limit' => 1, 'offset' => -2] + $defaults,
				2,
				['page' => -1.0, 'pages' => 2, 'limit' => 1, 'offset' => -2],
			],
			'search, sort and order' => [
				['search' => 'abc', '_search' => 'zzz', 'sort' => 'term', 'order' => 'ASC', '_order' => 'DESC'],
				['search' => 'abc', 'sort' => ['term' => 'ASC']] + $defaults,
				1,
				['page' => 1, 'pages' => 1, 'limit' => 20, 'offset' => 0],
			],
			'alias search, sort with default order' => [
				['_search' => 'zzz', '_sort' => 'created'],
				['search' => 'zzz', 'sort' => ['created' => 'DESC']] + $defaults,
				1,
				['page' => 1, 'pages' => 1, 'limit' => 20, 'offset' => 0],
			],
			'order alone does not change sort' => [
				['order' => 'ASC', '_order' => 'ASC'],
				$defaults,
				1,
				['page' => 1, 'pages' => 1, 'limit' => 20, 'offset' => 0],
			],
			'alias sort with alias order' => [
				['_sort' => 'user', '_order' => 'ASC'],
				['sort' => ['user' => 'ASC']] + $defaults,
				1,
				['page' => 1, 'pages' => 1, 'limit' => 20, 'offset' => 0],
			],
			'filters keep custom keys only' => [
				['register' => 3, 'schema' => '7', '_route' => 'x', '_secret' => 1, 'id' => 9, 'from' => null, 'to' => null, 'user' => null, 0 => 'numeric', '_' => 'u'],
				['filters' => ['register' => 3, 'schema' => '7', 'user' => null, 0 => 'numeric']] + $defaults,
				0,
				['page' => 1, 'pages' => 0, 'limit' => 20, 'offset' => 0],
			],
		];
	}

	/**
	 * @dataProvider configCases
	 *
	 * @param array<string, mixed> $config
	 * @param array<string, mixed> $expectedQuery
	 * @param array<string, mixed> $expectedPaging
	 */
	public function testConfigProcessingAndPaging(array $config, array $expectedQuery, int $total, array $expectedPaging): void {
		$queries = [];
		$this->trailMapper->expects($this->once())->method('findAll')
			->willReturnCallback(function (...$args) use (&$queries) {
				$queries['findAll'] = [$args[0]->limitOrNull(), $args[0]->offset, $args[1]->filters, $args[0]->sort, $args[1]->search, $args[1]->from, $args[1]->to];
				return [];
			});
		$this->trailMapper->expects($this->once())->method('count')
			->willReturnCallback(function (...$args) use (&$queries, $total) {
				$queries['count'] = [$args[0]->filters, $args[0]->search, $args[0]->from, $args[0]->to];
				return $total;
			});
		$this->registerMapper->expects($this->never())->method('find');
		$this->schemaMapper->expects($this->never())->method('find');

		$result = $this->service()->getSearchTrails($config);

		$q = $expectedQuery;
		$this->assertSame([$q['limit'], $q['offset'], $q['filters'], $q['sort'], $q['search'], $q['from'], $q['to']], $queries['findAll']);
		$this->assertSame([$q['filters'], $q['search'], $q['from'], $q['to']], $queries['count']);
		$this->assertSame(['results' => [], 'total' => $total] + $expectedPaging, $result);
		$this->assertSame(['results', 'total', 'page', 'pages', 'limit', 'offset'], array_keys($result));
	}

	public function testDateFiltersParsedOrIgnored(): void {
		$captured = [];
		$this->trailMapper->method('findAll')->willReturnCallback(function (...$args) use (&$captured) {
			$captured[] = [$args[1]->from, $args[1]->to];
			return [];
		});
		$this->trailMapper->method('count')->willReturn(0);

		$this->service()->getSearchTrails(['from' => '2024-01-02 03:04:05', 'to' => 'not a date']);
		$this->service()->getSearchTrails(['from' => 'garbage', '_from' => '2024-01-01', 'to' => '2024-12-31']);

		$this->assertInstanceOf(DateTime::class, $captured[0][0]);
		$this->assertSame('2024-01-02 03:04:05', $captured[0][0]->format('Y-m-d H:i:s'));
		$this->assertNull($captured[0][1]);
		$this->assertNull($captured[1][0]);
		$this->assertSame('2024-12-31 00:00:00', $captured[1][1]->format('Y-m-d H:i:s'));
	}

	// ── name enrichment ──

	private function trail(?int $register, ?int $schema): SearchTrail {
		$trail = new SearchTrail();
		$trail->setRegister($register);
		$trail->setSchema($schema);
		return $trail;
	}

	public function testEnrichmentLooksUpEachIdOnceWithDefaultFlagsAndFallsBack(): void {
		$trails = [$this->trail(1, 10), $this->trail(2, null), $this->trail(1, 11), $this->trail(null, 10), $this->trail(3, 12)];
		$this->trailMapper->method('findAll')->willReturn($trails);
		$this->trailMapper->method('count')->willReturn(5);

		$this->registerMapper->expects($this->exactly(3))->method('find')->willReturnCallback(function (...$args) {
			// Recorded, not asserted: the service swallows exceptions thrown here.
			$this->calls[] = 'register:' . json_encode($args);
			if ($args[0] === 2) {
				throw new DoesNotExistException('gone');
			}
			if ($args[0] === 3) {
				throw new RuntimeException('boom');
			}
			$register = new Register();
			$register->setTitle('Reg one');
			return $register;
		});
		$this->schemaMapper->expects($this->exactly(3))->method('find')->willReturnCallback(function (...$args) {
			$this->calls[] = 'schema:' . json_encode($args);
			if ($args[0] === 11) {
				throw new DoesNotExistException('gone');
			}
			if ($args[0] === 12) {
				throw new Exception('boom');
			}
			$schema = new Schema();
			$schema->setTitle('Schema ten');
			return $schema;
		});

		$result = $this->service()->getSearchTrails([]);

		// The lookups run with the mappers' default flags: RBAC and multitenancy stay ON.
		$this->assertSame(
			[
				'register:[1,true,true]',
				'register:[2,true,true]',
				'register:[3,true,true]',
				'schema:[10,[],true,true]',
				'schema:[11,[],true,true]',
				'schema:[12,[],true,true]',
			],
			$this->calls
		);
		$this->assertSame($trails, $result['results']);
		$names = array_map(fn (SearchTrail $t) => [$t->getRegisterName(), $t->getSchemaName()], $result['results']);
		$this->assertSame(
			[
				['Reg one', 'Schema ten'],
				['Register 2', null],
				['Reg one', 'Schema 11'],
				[null, 'Schema ten'],
				['Register 3', 'Schema 12'],
			],
			$names
		);
	}

	public function testNullTitleLeavesNameUnset(): void {
		$trail = $this->trail(4, 5);
		$trail->setRegisterName('kept');
		$trail->setSchemaName('kept too');
		$this->trailMapper->method('find')->willReturn($trail);
		$this->registerMapper->method('find')->willReturn(new Register());
		$this->schemaMapper->method('find')->willReturn(new Schema());

		$result = $this->service()->getSearchTrail(8);

		$this->assertSame($trail, $result);
		$this->assertSame('kept', $result->getRegisterName());
		$this->assertSame('kept too', $result->getSchemaName());
	}

	public function testGetSearchTrailWithoutIdsDoesNoLookups(): void {
		$trail = $this->trail(null, null);
		$this->trailMapper->expects($this->once())->method('find')->with(8)->willReturn($trail);
		$this->registerMapper->expects($this->never())->method('find');
		$this->schemaMapper->expects($this->never())->method('find');

		$this->assertSame($trail, $this->service()->getSearchTrail(8));
	}

	public function testGetSearchTrailPropagatesNotFound(): void {
		$this->trailMapper->method('find')->willThrowException(new DoesNotExistException('nope'));

		$this->expectException(DoesNotExistException::class);
		$this->service()->getSearchTrail(99);
	}

	// ── statistics ──

	private function stubStatistics(array $base): void {
		$record = function (string $name, $value) {
			return function (...$args) use ($name, $value) {
				$this->calls[] = $name . ':' . count($args);
				return $value;
			};
		};
		$this->trailMapper->method('getSearchStatistics')->willReturnCallback($record('stats', $base));
		$this->trailMapper->method('getUniqueSearchTermsCount')->willReturnCallback($record('terms', 7));
		$this->trailMapper->method('getUniqueUsersCount')->willReturnCallback($record('users', 3));
		$this->trailMapper->method('getAverageSearchesPerSession')->willReturnCallback($record('perSession', 2.5));
		$this->trailMapper->method('getAverageObjectViewsPerSession')->willReturnCallback($record('views', 1.25));
	}

	public function testSearchStatisticsWithPeriod(): void {
		$this->stubStatistics(['total_searches' => 30, 'total_results' => 100, 'non_empty_searches' => 20, 'avg_response_time' => 12.0]);

		$result = $this->service()->getSearchStatistics(new DateTime('2024-01-01 00:00:00'), new DateTime('2024-01-03 12:00:00'));

		$this->assertSame(['stats:2', 'terms:2', 'users:2', 'perSession:2', 'views:2'], $this->calls);
		$this->assertSame(
			[
				'total_searches' => 30,
				'total_results' => 100,
				'non_empty_searches' => 20,
				'avg_response_time' => 12.0,
				'searches_with_results' => 20,
				'searches_without_results' => 10,
				'success_rate' => 66.67,
				'unique_search_terms' => 7,
				'unique_users' => 3,
				'avg_searches_per_session' => 2.5,
				'avg_object_views_per_session' => 1.25,
				'unique_organizations' => 0,
				'query_complexity' => ['simple' => 18.0, 'medium' => 9.0, 'complex' => 3.0],
				'period' => ['from' => '2024-01-01 00:00:00', 'to' => '2024-01-03 12:00:00', 'days' => 3],
				'daily_averages' => ['searches_per_day' => 10.0, 'results_per_day' => 33.33],
			],
			$result
		);
	}

	public function testSearchStatisticsWithoutSearchesOrPeriod(): void {
		$this->stubStatistics(['success_rate' => 'mapper', 'total_searches' => 0, 'total_results' => 0, 'non_empty_searches' => 0]);

		$this->assertSame(
			[
				'success_rate' => 0,
				'total_searches' => 0,
				'total_results' => 0,
				'non_empty_searches' => 0,
				'searches_with_results' => 0,
				'searches_without_results' => 0,
				'unique_search_terms' => 7,
				'unique_users' => 3,
				'avg_searches_per_session' => 2.5,
				'avg_object_views_per_session' => 1.25,
				'unique_organizations' => 0,
				'query_complexity' => ['simple' => 0, 'medium' => 0, 'complex' => 0],
				'period' => ['from' => '2024-01-01 00:00:00', 'to' => null, 'days' => null],
			],
			$this->service()->getSearchStatistics(new DateTime('2024-01-01'))
		);
	}

	public function testPopularSearchTerms(): void {
		$this->trailMapper->expects($this->once())->method('getPopularSearchTerms')
			->willReturnCallback(function (...$args) {
				$this->assertSame(5, $args[0]);
				$this->assertNull($args[1]);
				$this->assertSame('2024-02-02 00:00:00', $args[2]->format('Y-m-d H:i:s'));
				return [
					['term' => 'a', 'count' => 3, 'avg_results' => 2.0],
					['term' => 'b', 'count' => 1, 'avg_results' => 0],
				];
			});

		$this->assertSame(
			[
				'terms' => [
					['term' => 'a', 'count' => 3, 'avg_results' => 2.0, 'percentage' => 75.0, 'effectiveness' => 'high'],
					['term' => 'b', 'count' => 1, 'avg_results' => 0, 'percentage' => 25.0, 'effectiveness' => 'low'],
				],
				'total_unique_terms' => 2,
				'total_searches' => 4,
				'period' => ['from' => null, 'to' => '2024-02-02 00:00:00'],
			],
			$this->service()->getPopularSearchTerms(new SearchPeriod(null, new DateTime('2024-02-02')), 5)
		);
	}

	public function testPopularSearchTermsWithZeroCounts(): void {
		$this->trailMapper->method('getPopularSearchTerms')->willReturn([['term' => 'z', 'count' => 0, 'avg_results' => -1]]);

		$result = $this->service()->getPopularSearchTerms();

		$this->assertSame([['term' => 'z', 'count' => 0, 'avg_results' => -1, 'percentage' => 0, 'effectiveness' => 'low']], $result['terms']);
		$this->assertSame(0, $result['total_searches']);
		$this->assertSame(['from' => null, 'to' => null], $result['period']);
	}

	/**
	 * @return array<string, array{list<array<string, mixed>>, array<string, mixed>}>
	 */
	public static function activityCases(): array {
		return [
			'empty' => [[], ['peak_period' => null, 'low_period' => null, 'trend' => 'no_data', 'average_searches_per_period' => 0]],
			'single' => [
				[['period' => 'p1', 'count' => 4]],
				['peak_period' => 'p1', 'peak_count' => 4, 'low_period' => 'p1', 'low_count' => 4, 'trend' => 'stable', 'average_searches_per_period' => 4.0, 'total_periods' => 1],
			],
			'increasing' => [
				[['period' => 'a', 'count' => 1], ['period' => 'b', 'count' => 5], ['period' => 'c', 'count' => 9]],
				['peak_period' => 'c', 'peak_count' => 9, 'low_period' => 'a', 'low_count' => 1, 'trend' => 'increasing', 'average_searches_per_period' => 5.0, 'total_periods' => 3],
			],
			'decreasing' => [
				[['period' => 'a', 'count' => 9], ['period' => 'b', 'count' => 2], ['period' => 'c', 'count' => 2]],
				['peak_period' => 'a', 'peak_count' => 9, 'low_period' => 'b', 'low_count' => 2, 'trend' => 'decreasing', 'average_searches_per_period' => 4.33, 'total_periods' => 3],
			],
			'stable within threshold' => [
				[['period' => 'a', 'count' => 5], ['period' => 'b', 'count' => 6], ['period' => 'c', 'count' => 5]],
				['peak_period' => 'b', 'peak_count' => 6, 'low_period' => 'a', 'low_count' => 5, 'trend' => 'stable', 'average_searches_per_period' => 5.33, 'total_periods' => 3],
			],
			'just above threshold' => [
				[['period' => 'a', 'count' => 10], ['period' => 'b', 'count' => 10], ['period' => 'c', 'count' => 11]],
				['peak_period' => 'c', 'peak_count' => 11, 'low_period' => 'a', 'low_count' => 10, 'trend' => 'increasing', 'average_searches_per_period' => 10.33, 'total_periods' => 3],
			],
			'missing period label' => [
				[['count' => 2], ['period' => 'b', 'count' => 1]],
				['peak_period' => null, 'peak_count' => 2, 'low_period' => 'b', 'low_count' => 1, 'trend' => 'decreasing', 'average_searches_per_period' => 1.5, 'total_periods' => 2],
			],
		];
	}

	/**
	 * @dataProvider activityCases
	 *
	 * @param list<array<string, mixed>> $activity
	 * @param array<string, mixed> $insights
	 */
	public function testSearchActivity(array $activity, array $insights): void {
		$this->trailMapper->expects($this->once())->method('getSearchActivityByTime')
			->willReturnCallback(function (...$args) use ($activity) {
				$this->assertSame(['week', null, null], $args);
				return $activity;
			});

		$this->assertSame(
			['activity' => $activity, 'insights' => $insights, 'interval' => 'week', 'period' => ['from' => null, 'to' => null]],
			$this->service()->getSearchActivity(new SearchPeriod(), 'week')
		);
	}

	public function testRegisterSchemaStatisticsRatingsAndOrder(): void {
		$this->trailMapper->method('getSearchStatisticsByRegisterSchema')->willReturn([
			['id' => 'poor-results', 'count' => 1, 'avg_results' => 0.5, 'avg_response_time' => 10],
			['id' => 'excellent', 'count' => 4, 'avg_results' => 10, 'avg_response_time' => 100],
			['id' => 'good-by-time', 'count' => 2, 'avg_results' => 10, 'avg_response_time' => 101],
			['id' => 'average', 'count' => 2, 'avg_results' => 1, 'avg_response_time' => 500],
			['id' => 'poor-time', 'count' => 1, 'avg_results' => 50, 'avg_response_time' => 501],
			['id' => 'good', 'count' => 0, 'avg_results' => 5, 'avg_response_time' => 200],
		]);

		$result = $this->service()->getRegisterSchemaStatistics(new DateTime('2024-03-01'), new DateTime('2024-03-02'));

		$this->assertSame(
			[
				['id' => 'excellent', 'count' => 4, 'avg_results' => 10, 'avg_response_time' => 100, 'percentage' => 40.0, 'performance_rating' => 'excellent'],
				['id' => 'good-by-time', 'count' => 2, 'avg_results' => 10, 'avg_response_time' => 101, 'percentage' => 20.0, 'performance_rating' => 'good'],
				['id' => 'average', 'count' => 2, 'avg_results' => 1, 'avg_response_time' => 500, 'percentage' => 20.0, 'performance_rating' => 'average'],
				['id' => 'poor-results', 'count' => 1, 'avg_results' => 0.5, 'avg_response_time' => 10, 'percentage' => 10.0, 'performance_rating' => 'poor'],
				['id' => 'poor-time', 'count' => 1, 'avg_results' => 50, 'avg_response_time' => 501, 'percentage' => 10.0, 'performance_rating' => 'poor'],
				['id' => 'good', 'count' => 0, 'avg_results' => 5, 'avg_response_time' => 200, 'percentage' => 0.0, 'performance_rating' => 'good'],
			],
			$result['statistics']
		);
		$this->assertSame(6, $result['total_combinations']);
		$this->assertSame(10, $result['total_searches']);
		$this->assertSame(['from' => '2024-03-01 00:00:00', 'to' => '2024-03-02 00:00:00'], $result['period']);
		$this->assertSame(['statistics', 'total_combinations', 'total_searches', 'period'], array_keys($result));
	}

	public function testRegisterSchemaStatisticsWithNoSearches(): void {
		$this->trailMapper->method('getSearchStatisticsByRegisterSchema')->willReturn([
			['count' => 0, 'avg_results' => 0, 'avg_response_time' => 0],
		]);

		$result = $this->service()->getRegisterSchemaStatistics();

		$this->assertSame(0, $result['statistics'][0]['percentage']);
		$this->assertSame('poor', $result['statistics'][0]['performance_rating']);
	}

	public function testUserAgentStatisticsParsingAndDistribution(): void {
		$chrome = 'Mozilla/5.0 AppleWebKit/537.36 Chrome/120.0.1 Safari/537.36';
		$this->trailMapper->expects($this->once())->method('getUserAgentStatistics')
			->willReturnCallback(function (...$args) use ($chrome) {
				$this->assertSame([3, null, null], $args);
				return [
					['user_agent' => $chrome, 'count' => 2],
					['user_agent' => 'Mozilla/5.0 Firefox/115.0', 'count' => 5],
					['user_agent' => 'Version/17.1 Safari/605.1.15', 'count' => 1],
					['user_agent' => 'Mozilla/5.0 Edge/18.19041', 'count' => 1],
					['user_agent' => 'Opera/9.80 (X11)', 'count' => 3],
					['user_agent' => 'curl/8.0', 'count' => 3],
					['user_agent' => 'Chrome/', 'count' => 1],
					['user_agent' => 'Chrome/9', 'count' => 0],
				];
			});

		$result = $this->service()->getUserAgentStatistics(new SearchPeriod(), 3);

		$this->assertSame(
			[
				['browser' => 'Chrome', 'version' => '120.0.1', 'full_string' => $chrome],
				['browser' => 'Firefox', 'version' => '115.0', 'full_string' => 'Mozilla/5.0 Firefox/115.0'],
				['browser' => 'Safari', 'version' => '605.1.15', 'full_string' => 'Version/17.1 Safari/605.1.15'],
				['browser' => 'Edge', 'version' => '18.19041', 'full_string' => 'Mozilla/5.0 Edge/18.19041'],
				['browser' => 'Opera', 'version' => '9.80', 'full_string' => 'Opera/9.80 (X11)'],
				['browser' => 'unknown', 'version' => 'unknown', 'full_string' => 'curl/8.0'],
				['browser' => 'unknown', 'version' => 'unknown', 'full_string' => 'Chrome/'],
				['browser' => 'Chrome', 'version' => '9', 'full_string' => 'Chrome/9'],
			],
			array_column($result['user_agents'], 'browser_info')
		);
		$this->assertSame(['user_agent', 'count', 'browser_info'], array_keys($result['user_agents'][0]));
		$this->assertSame(
			[
				['browser' => 'Firefox', 'count' => 5, 'percentage' => 31.25],
				['browser' => 'unknown', 'count' => 4, 'percentage' => 25.0],
				['browser' => 'Opera', 'count' => 3, 'percentage' => 18.75],
				['browser' => 'Chrome', 'count' => 2, 'percentage' => 12.5],
				['browser' => 'Safari', 'count' => 1, 'percentage' => 6.25],
				['browser' => 'Edge', 'count' => 1, 'percentage' => 6.25],
			],
			$result['browser_distribution']
		);
		$this->assertSame(8, $result['total_user_agents']);
		$this->assertSame(['user_agents', 'browser_distribution', 'total_user_agents', 'period'], array_keys($result));
		$this->assertSame(['from' => null, 'to' => null], $result['period']);
	}

	public function testUserAgentStatisticsWithZeroCounts(): void {
		$this->trailMapper->method('getUserAgentStatistics')->willReturn([['user_agent' => 'x', 'count' => 0]]);

		$this->assertSame(
			[['browser' => 'unknown', 'count' => 0, 'percentage' => 0]],
			$this->service()->getUserAgentStatistics()['browser_distribution']
		);
	}
}
