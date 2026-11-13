<?php

/**
 * The search trail statistics count the searches that found something.
 *
 * The summary query's counting expressions are run for real, against an
 * in-memory SQLite table holding the recorded searches: only the query
 * builder that carries them is a stand-in, so the test reads what the SQL
 * itself counts rather than a row the test made up.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/capability-rows-verified-a/specs/search-trail-recording/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\SearchTrailMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Db\SearchTrailMapper
 * @uses   \OCA\OpenRegister\Db\SearchTrail\CreatedRange
 * @uses   \OCA\OpenRegister\Db\SearchTrail\SearchTrailStatistics
 */
class SearchTrailEmptySearchStatisticsTest extends TestCase {

	/**
	 * The searches the store holds.
	 *
	 * @var PDO
	 */
	private PDO $store;

	/**
	 * Hold the two recorded searches of the scenario: one that found nothing
	 * and one that found twelve results.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new PDO('sqlite::memory:');
		$this->store->exec(
			'CREATE TABLE openregister_search_trails '
			. '(search_term TEXT, total_results INTEGER, response_time REAL, created TEXT)'
		);
		$this->record(term: 'afvalkalender', results: 0);
		$this->record(term: 'paspoort', results: 12);
	}//end setUp()

	/**
	 * Record one search in the store.
	 *
	 * @param string   $term    The search term.
	 * @param int|null $results How many results it found (null when unknown).
	 *
	 * @return void
	 */
	private function record(string $term, ?int $results): void {
		$insert = $this->store->prepare('INSERT INTO openregister_search_trails VALUES (?, ?, 10.0, ?)');
		$insert->execute([$term, $results, '2026-11-12 10:00:00']);
	}//end record()

	/**
	 * A query builder that collects the selected expressions and runs them,
	 * as plain SQL, against the store.
	 *
	 * @return IQueryBuilder
	 */
	private function builder(): IQueryBuilder {
		$selects = [];
		$table = '';
		$store = $this->store;

		$qb = $this->createMock(IQueryBuilder::class);
		$functions = $this->createMock(IFunctionBuilder::class);
		$functions->method('count')->willReturnCallback(
			fn (string $input = '', string $alias = ''): IQueryFunction => $this->sql('COUNT(' . $input . ') AS ' . $alias)
		);
		$qb->method('func')->willReturn($functions);
		$qb->method('createFunction')->willReturnCallback(fn (string $sql): IQueryFunction => $this->sql($sql));
		$qb->method('select')->willReturnCallback(
			static function (mixed ...$columns) use (&$selects, $qb): IQueryBuilder {
				foreach ($columns as $column) {
					$parts = [$column];
					if (is_array($column) === true) {
						$parts = $column;
					}

					foreach ($parts as $part) {
						$selects[] = (string) $part;
					}
				}

				return $qb;
			}
		);
		$qb->method('addSelect')->willReturnCallback(
			static function (mixed ...$columns) use (&$selects, $qb): IQueryBuilder {
				foreach ($columns as $column) {
					$selects[] = (string) $column;
				}

				return $qb;
			}
		);
		$qb->method('from')->willReturnCallback(
			static function (string $from) use (&$table, $qb): IQueryBuilder {
				$table = $from;
				return $qb;
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			function () use (&$selects, &$table, $store): IResult {
				$row = $store->query('SELECT ' . implode(', ', $selects) . ' FROM ' . $table)->fetch(PDO::FETCH_ASSOC);
				$result = $this->createMock(IResult::class);
				$result->method('fetch')->willReturn($row);
				return $result;
			}
		);

		return $qb;
	}//end builder()

	/**
	 * A query function standing for a piece of SQL.
	 *
	 * @param string $sql The SQL.
	 *
	 * @return IQueryFunction
	 */
	private function sql(string $sql): IQueryFunction {
		return new class ($sql) implements IQueryFunction {
			/**
			 * @param string $sql The SQL.
			 */
			public function __construct(private readonly string $sql) {
			}

			/**
			 * @return string
			 */
			public function __toString(): string {
				return $this->sql;
			}
		};
	}//end sql()

	/**
	 * The mapper over the store.
	 *
	 * @return SearchTrailMapper
	 */
	private function mapper(): SearchTrailMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->builder());

		return new SearchTrailMapper(
			$db,
			$this->createMock(IRequest::class),
			$this->createMock(IUserSession::class),
			new NullLogger()
		);
	}//end mapper()

	/**
	 * Of an empty and a found search, the total is two and one found something.
	 *
	 * @return void
	 */
	public function testTheStatisticsCountTheSearchesWithResults(): void {
		$stats = $this->mapper()->getSearchStatistics();

		$this->assertSame(2, $stats['total_searches']);
		$this->assertSame(1, $stats['non_empty_searches']);
		$this->assertSame(12, $stats['total_results']);
	}//end testTheStatisticsCountTheSearchesWithResults()

	/**
	 * A search whose result count was never recorded is not counted as one that
	 * found something, and is left out of the average.
	 *
	 * @return void
	 */
	public function testASearchWithAnUnknownResultCountIsNotCountedAsFindingSomething(): void {
		$this->record(term: 'onbekend', results: null);

		$stats = $this->mapper()->getSearchStatistics();

		$this->assertSame(3, $stats['total_searches']);
		$this->assertSame(1, $stats['non_empty_searches']);
		$this->assertSame(6.0, $stats['avg_results_per_search']);
	}//end testASearchWithAnUnknownResultCountIsNotCountedAsFindingSomething()
}//end class
