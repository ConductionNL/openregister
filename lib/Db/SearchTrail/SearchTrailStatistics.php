<?php

/**
 * SearchTrailStatistics: the aggregate reads over the search trail table.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SearchTrail
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SearchTrail;

use DateTime;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The statistics SearchTrailMapper serves: totals, popular terms, activity
 * over time, per register and schema, per user agent, distinct counts and
 * per-session averages. Every read is narrowed to an optional created range.
 *
 * SearchTrailMapper keeps its public methods and delegates to this class.
 */
final class SearchTrailStatistics {

	/**
	 * The date_period format per interval, MySQL DATE_FORMAT.
	 *
	 * @var array<string, string>
	 */
	private const MYSQL_PERIODS = ['hour' => '%Y-%m-%d %H:00:00', 'day' => '%Y-%m-%d', 'week' => '%Y-%u', 'month' => '%Y-%m'];

	/**
	 * The date_period format per interval, PostgreSQL TO_CHAR (week is the ISO week).
	 *
	 * @var array<string, string>
	 */
	private const POSTGRES_PERIODS = ['hour' => 'YYYY-MM-DD HH24:00:00', 'day' => 'YYYY-MM-DD', 'week' => 'IYYY-IW', 'month' => 'YYYY-MM'];

	/**
	 * The date_period format per interval, SQLite strftime.
	 *
	 * @var array<string, string>
	 */
	private const SQLITE_PERIODS = ['hour' => '%Y-%m-%d %H:00:00', 'day' => '%Y-%m-%d', 'week' => '%Y-%W', 'month' => '%Y-%m'];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db    The connection.
	 * @param string        $table The search trail table.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly string $table,
	) {
	}//end __construct()

	/**
	 * Totals over the range: searches, results, averages, non-empty searches.
	 *
	 * @param CreatedRange $range The created window.
	 *
	 * @return array<string, int|float>
	 */
	public function summary(CreatedRange $range): array {
		$qb = $this->db->getQueryBuilder();
		$totalResultsSql = 'COALESCE(SUM(CASE WHEN total_results IS NOT NULL THEN total_results ELSE 0 END), 0) AS total_results';
		$avgResultsSql = 'AVG(CASE WHEN total_results IS NOT NULL THEN total_results END) AS avg_results_per_search';
		$qb->select([$qb->func()->count('*', 'total_searches'), $qb->createFunction($totalResultsSql)])
			->addSelect($qb->createFunction($avgResultsSql))
			->addSelect($qb->createFunction('AVG(response_time) AS avg_response_time'))
			->addSelect($qb->createFunction('COUNT(CASE WHEN total_results > 0 THEN 1 END) AS non_empty_searches'))
			->from($this->table);

		return $this->summaryOf(stats: $this->fetchRow(qb: $qb, range: [$range->from, $range->to]));
	}//end summary()

	/**
	 * The summary of one fetched row.
	 *
	 * @param mixed $stats The row, or false.
	 *
	 * @return array<string, int|float>
	 */
	private function summaryOf(mixed $stats): array {
		return [
			'total_searches' => (int)($stats['total_searches'] ?? 0),
			'total_results' => (int)($stats['total_results'] ?? 0),
			'avg_results_per_search' => round((float)($stats['avg_results_per_search'] ?? 0), 2),
			'avg_response_time' => round((float)($stats['avg_response_time'] ?? 0), 2),
			'non_empty_searches' => (int)($stats['non_empty_searches'] ?? 0),
		];
	}//end summaryOf()

	/**
	 * The most searched terms.
	 *
	 * @param int           $limit How many.
	 * @param CreatedRange $range The created window.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function popularTerms(int $limit, CreatedRange $range): array {
		$qb = $this->db->getQueryBuilder();
		$this->selectAverages(qb: $qb, columns: ['search_term']);
		$this->whereNotEmpty(qb: $qb, column: 'search_term')
			->groupBy('search_term')
			->orderBy('search_count', 'DESC')
			->setMaxResults($limit);

		return $this->withAverages(rows: $this->fetchRows(qb: $qb, range: [$range->from, $range->to]), labels: ['term' => 'search_term']);
	}//end popularTerms()

	/**
	 * Search activity per period of the interval.
	 *
	 * @param string        $interval hour, day, week or month (anything else is day).
	 * @param CreatedRange $range The created window.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function activityByTime(string $interval, CreatedRange $range): array {
		$qb = $this->db->getQueryBuilder();
		$this->selectAverages(qb: $qb, columns: [])
			->groupBy('date_period')
			->orderBy('date_period', 'ASC');

		$period = $this->datePeriodSelect(interval: $interval);
		if ($period !== null) {
			$qb->addSelect($qb->createFunction($period));
		}

		return $this->withAverages(rows: $this->fetchRows(qb: $qb, range: [$range->from, $range->to]), labels: ['period' => 'date_period']);
	}//end activityByTime()

	/**
	 * The date_period select for this connection's platform, or null for another platform.
	 *
	 * @param string $interval hour, day, week or month (anything else is day).
	 *
	 * @return string|null
	 */
	private function datePeriodSelect(string $interval): ?string {
		$platform = $this->db->getDatabasePlatform();
		if ($platform instanceof AbstractMySQLPlatform) {
			return "DATE_FORMAT(created, '" . (self::MYSQL_PERIODS[$interval] ?? '%Y-%m-%d') . "') AS date_period";
		}

		if ($platform instanceof PostgreSQLPlatform) {
			return "TO_CHAR(created, '" . (self::POSTGRES_PERIODS[$interval] ?? 'YYYY-MM-DD') . "') AS date_period";
		}

		if ($platform instanceof SqlitePlatform) {
			return "strftime('" . (self::SQLITE_PERIODS[$interval] ?? '%Y-%m-%d') . "', created) AS date_period";
		}

		return null;
	}//end datePeriodSelect()

	/**
	 * Searches per register and schema.
	 *
	 * @param CreatedRange $range The created window.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function byRegisterSchema(CreatedRange $range): array {
		$columns = ['register', 'schema', 'register_uuid', 'schema_uuid'];
		$qb = $this->db->getQueryBuilder();
		$this->selectAverages(qb: $qb, columns: $columns)
			->groupBy(...$columns)
			->orderBy('search_count', 'DESC');

		return $this->withAverages(rows: $this->fetchRows(qb: $qb, range: [$range->from, $range->to]), labels: array_combine($columns, $columns));
	}//end byRegisterSchema()

	/**
	 * The user agents that search most.
	 *
	 * @param int           $limit How many.
	 * @param CreatedRange $range The created window.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function userAgents(int $limit, CreatedRange $range): array {
		$qb = $this->db->getQueryBuilder();
		$this->selectAverages(qb: $qb, columns: ['user_agent'])
			->where($qb->expr()->isNotNull('user_agent'))
			->groupBy('user_agent')
			->orderBy('search_count', 'DESC')
			->setMaxResults($limit);

		return $this->withAverages(rows: $this->fetchRows(qb: $qb, range: [$range->from, $range->to]), labels: ['user_agent' => 'user_agent']);
	}//end userAgents()

	/**
	 * How many distinct non-empty values a column holds over the range.
	 *
	 * @param string        $column The column.
	 * @param CreatedRange $range The created window.
	 *
	 * @return int
	 */
	public function distinctCount(string $column, CreatedRange $range): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct($column)
			->from($this->table);
		$this->whereNotEmpty(qb: $qb, column: $column);

		return count($this->fetchRows(qb: $qb, range: [$range->from, $range->to]));
	}//end distinctCount()

	/**
	 * The average number of searches per session.
	 *
	 * @param CreatedRange $range The created window.
	 *
	 * @return float
	 */
	public function searchesPerSession(CreatedRange $range): float {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total_searches'), $qb->createFunction('COUNT(DISTINCT session) as unique_sessions'))
			->from($this->table);
		$this->whereNotEmpty(qb: $qb, column: 'session');

		return $this->perSession(data: $this->fetchRow(qb: $qb, range: [$range->from, $range->to]), countKey: 'total_searches');
	}//end searchesPerSession()

	/**
	 * The average number of object reads per session, from the audit trail.
	 *
	 * @param CreatedRange $range The created window.
	 *
	 * @return float
	 */
	public function objectViewsPerSession(CreatedRange $range): float {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total_views'), $qb->createFunction('COUNT(DISTINCT session) as unique_sessions'))
			->from('openregister_audit_trails')
			->where($qb->expr()->eq('action', $qb->createNamedParameter('read')))
			->andWhere($qb->expr()->isNotNull('session'))
			->andWhere($qb->expr()->neq('session', $qb->createNamedParameter('')));

		return $this->perSession(data: $this->fetchRow(qb: $qb, range: [$range->from, $range->to]), countKey: 'total_views');
	}//end objectViewsPerSession()

	/**
	 * A count divided by its unique sessions, rounded to two decimals; 0.0 without sessions.
	 *
	 * @param mixed  $data     The fetched row, or false.
	 * @param string $countKey The column holding the count.
	 *
	 * @return float
	 */
	private function perSession(mixed $data, string $countKey): float {
		$total = (int)($data[$countKey] ?? 0);
		$uniqueSessions = (int)($data['unique_sessions'] ?? 0);
		if ($uniqueSessions <= 0) {
			return 0.0;
		}

		return round($total / $uniqueSessions, 2);
	}//end perSession()

	/**
	 * Narrow a query to rows created within a range; a null bound is open.
	 *
	 * @param IQueryBuilder                     $qb    The query to narrow.
	 * @param array{0: ?DateTime, 1: ?DateTime} $range The from and to moments.
	 *
	 * @return void
	 */
	public function filterByCreated(IQueryBuilder $qb, array $range): void {
		[$from, $to] = $range;
		if ($from !== null) {
			$qb->andWhere($qb->expr()->gte('created', $qb->createNamedParameter($from->format('Y-m-d H:i:s'))));
		}

		if ($to !== null) {
			$qb->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($to->format('Y-m-d H:i:s'))));
		}
	}//end filterByCreated()

	/**
	 * Select the given columns with the search count and the result and response time averages.
	 *
	 * @param IQueryBuilder $qb      The query.
	 * @param array         $columns The grouping columns to select first.
	 *
	 * @return IQueryBuilder
	 */
	private function selectAverages(IQueryBuilder $qb, array $columns): IQueryBuilder {
		return $qb->select([...$columns, $qb->func()->count('*', 'search_count')])
			->addSelect($qb->createFunction('AVG(total_results) AS avg_results'))
			->addSelect($qb->createFunction('AVG(response_time) AS avg_response_time'))
			->from($this->table);
	}//end selectAverages()

	/**
	 * Keep rows whose column is neither NULL nor empty.
	 *
	 * @param IQueryBuilder $qb     The query.
	 * @param string        $column The column.
	 *
	 * @return IQueryBuilder
	 */
	private function whereNotEmpty(IQueryBuilder $qb, string $column): IQueryBuilder {
		return $qb->where($qb->expr()->isNotNull($column))
			->andWhere($qb->expr()->neq($column, $qb->createNamedParameter('')));
	}//end whereNotEmpty()

	/**
	 * Run a query narrowed to the range and return every row.
	 *
	 * @param IQueryBuilder                     $qb    The query.
	 * @param array{0: ?DateTime, 1: ?DateTime} $range The from and to moments.
	 *
	 * @return array
	 */
	private function fetchRows(IQueryBuilder $qb, array $range): array {
		$this->filterByCreated(qb: $qb, range: $range);
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}//end fetchRows()

	/**
	 * Run a query narrowed to the range and return its first row.
	 *
	 * @param IQueryBuilder                     $qb    The query.
	 * @param array{0: ?DateTime, 1: ?DateTime} $range The from and to moments.
	 *
	 * @return mixed The row, or false.
	 */
	private function fetchRow(IQueryBuilder $qb, array $range): mixed {
		$this->filterByCreated(qb: $qb, range: $range);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row;
	}//end fetchRow()

	/**
	 * Map grouped rows to the labelled columns plus count and rounded averages.
	 *
	 * @param array                 $rows   The fetched rows.
	 * @param array<string, string> $labels Output key => column, in output order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function withAverages(array $rows, array $labels): array {
		return array_map(fn ($row): array => $this->rowWithAverages(row: $row, labels: $labels), $rows);
	}//end withAverages()

	/**
	 * One grouped row: the labelled columns, then count, avg_results and avg_response_time.
	 *
	 * @param mixed                 $row    The fetched row.
	 * @param array<string, string> $labels Output key => column, in output order.
	 *
	 * @return array<string, mixed>
	 */
	private function rowWithAverages(mixed $row, array $labels): array {
		$out = [];
		foreach ($labels as $label => $column) {
			$out[$label] = $row[$column];
		}

		$out['count'] = (int)$row['search_count'];
		$out['avg_results'] = round((float)$row['avg_results'], 2);
		$out['avg_response_time'] = round((float)$row['avg_response_time'], 2);
		return $out;
	}//end rowWithAverages()
}//end class
