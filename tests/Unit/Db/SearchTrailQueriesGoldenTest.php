<?php

/**
 * Golden test for the list, count and statistics queries of SearchTrailMapper.
 *
 * Records the query and the result of findAll(), count() and the nine
 * statistics methods with and without a date range and a search term.
 * Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\SearchTrail;
use OCA\OpenRegister\Db\SearchTrailCriteria;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Db\SearchTrailMapper
 * @uses   \OCA\OpenRegister\Db\SearchTrail
 */
class SearchTrailQueriesGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/search-trail-queries-golden.json';

	private const ROWS = [
		[
			'id' => 1, 'search_term' => 'tax', 'search_count' => '4', 'count' => '4', 'avg_results' => '2.5',
			'avg_response_time' => '12.345', 'total_searches' => '6', 'total_results' => '15',
			'avg_results_per_search' => '2.5', 'non_empty_searches' => '5', 'period' => '2026-01-02', 'date_period' => '2026-01-02 10:00:00',
			'register' => '3', 'schema' => '7', 'register_uuid' => 'r-3', 'schema_uuid' => 's-7',
			'user_agent' => 'agent-a', 'unique_terms' => '3', 'unique_users' => '2', 'avg_searches' => '1.5',
			'avg_views' => '2.25', 'sessions' => '2', 'searches' => '6', 'views' => '9',
		],
		[
			'id' => 2, 'search_term' => 'rent', 'search_count' => '1', 'count' => '1', 'avg_results' => null,
			'avg_response_time' => null, 'total_searches' => '1', 'total_results' => null,
			'avg_results_per_search' => null, 'non_empty_searches' => '0', 'period' => '2026-01-03', 'date_period' => '2026-01-03 11:00:00',
			'register' => null, 'schema' => null, 'register_uuid' => null, 'schema_uuid' => null,
			'user_agent' => 'agent-b', 'unique_terms' => '1', 'unique_users' => '1', 'avg_searches' => '1',
			'avg_views' => '0', 'sessions' => '1', 'searches' => '1', 'views' => '0',
		],
	];

	private static function calls(): array {
		$calls = [];
		$ranges = [
			'open'  => [null, null],
			'from'  => [new DateTime('2026-01-01 08:00:00'), null],
			'to'    => [null, new DateTime('2026-01-31 18:00:00')],
			'both'  => [new DateTime('2026-01-01 08:00:00'), new DateTime('2026-01-31 18:00:00')],
		];
		foreach ($ranges as $tag => [$from, $to]) {
			foreach ([null, 'tax'] as $search) {
				$s = var_export($search, true);
				$calls["findAll $tag search $s"] = fn (SearchTrailMapper $m) => array_map(
					fn (SearchTrail $t) => $t->getId(),
					$m->findAll(new SearchTrailCriteria(['user' => 'alice'], $search, $from, $to), 5, 10, [])
				);
				$calls["count $tag search $s"] = fn (SearchTrailMapper $m) => $m->count(new SearchTrailCriteria(['user' => 'alice'], $search, $from, $to));
			}

			$calls["getSearchStatistics $tag"] = fn (SearchTrailMapper $m) => $m->getSearchStatistics($from, $to);
			$calls["getPopularSearchTerms $tag"] = fn (SearchTrailMapper $m) => $m->getPopularSearchTerms(3, $from, $to);
			$calls["getSearchActivityByTime $tag"] = fn (SearchTrailMapper $m) => $m->getSearchActivityByTime('hour', $from, $to);
			$calls["getSearchStatisticsByRegisterSchema $tag"] = fn (SearchTrailMapper $m) => $m->getSearchStatisticsByRegisterSchema($from, $to);
			$calls["getUserAgentStatistics $tag"] = fn (SearchTrailMapper $m) => $m->getUserAgentStatistics(2, $from, $to);
			$calls["getUniqueSearchTermsCount $tag"] = fn (SearchTrailMapper $m) => $m->getUniqueSearchTermsCount($from, $to);
			$calls["getUniqueUsersCount $tag"] = fn (SearchTrailMapper $m) => $m->getUniqueUsersCount($from, $to);
			$calls["getAverageSearchesPerSession $tag"] = fn (SearchTrailMapper $m) => $m->getAverageSearchesPerSession($from, $to);
			$calls["getAverageObjectViewsPerSession $tag"] = fn (SearchTrailMapper $m) => $m->getAverageObjectViewsPerSession($from, $to);
		}

		return $calls;
	}//end calls()

	public function testQueriesMatchGolden(): void {
		$actual = [];
		foreach (self::calls() as $name => $call) {
			$rowSets = ['rows' => self::ROWS, 'empty' => []];
			if (str_starts_with($name, 'findAll ') === true) {
				$rowSets['rows'] = [['id' => 1, 'search_term' => 'tax'], ['id' => 2, 'search_term' => 'rent']];
			}

			foreach ($rowSets as $rowName => $rows) {
				$this->queryLog = [];
				$this->builderCount = 0;
				$this->parameterCount = 0;
				$this->queuedRows = [$rows, $rows, $rows];
				try {
					$outcome = ['result' => $call($this->mapper())];
				} catch (\Throwable $e) {
					$outcome = ['thrown' => get_class($e)];
				}

				$outcome['queries'] = $this->queryLog;
				$actual["$name $rowName"] = $outcome;
			}
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testQueriesMatchGolden()

	private function mapper(): SearchTrailMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());

		return new SearchTrailMapper(
			$db,
			$this->createMock(IRequest::class),
			$this->createMock(IUserSession::class),
			new NullLogger()
		);
	}//end mapper()
}//end class
