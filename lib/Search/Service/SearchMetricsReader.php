<?php

/**
 * Reads the search and embedding aggregates the metrics endpoint publishes.
 *
 * MetricsService records one `openregister_metrics` row per search (keyword,
 * semantic, hybrid) and per embedding generation. This reader aggregates
 * those rows in the database, so the scrape never loads individual rows:
 * one grouped query for the search counts and duration buckets, one for the
 * embedding outcomes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Aggregates recorded search and embedding metrics.
 *
 * @spec openspec/changes/production-observability
 */
class SearchMetricsReader {

	/**
	 * The metrics table.
	 */
	private const TABLE = 'openregister_metrics';

	/**
	 * The metric types MetricsService records; equal to its METRIC_* constants (pinned by a test).
	 *
	 * Restated so the metrics reader (Integrations) does not name the Search service.
	 */
	public const METRIC_SEARCH_KEYWORD = 'search_keyword';

	/**
	 * A semantic search.
	 */
	public const METRIC_SEARCH_SEMANTIC = 'search_semantic';

	/**
	 * A hybrid search.
	 */
	public const METRIC_SEARCH_HYBRID = 'search_hybrid';

	/**
	 * One generated embedding.
	 */
	public const METRIC_EMBEDDING_GENERATED = 'embedding_generated';

	/**
	 * The search type label of each recorded search metric type.
	 */
	public const SEARCH_TYPES = [
		self::METRIC_SEARCH_KEYWORD => 'keyword',
		self::METRIC_SEARCH_SEMANTIC => 'semantic',
		self::METRIC_SEARCH_HYBRID => 'hybrid',
	];

	/**
	 * The upper bounds of the search duration buckets, in milliseconds.
	 */
	public const DURATION_BUCKETS_MS = [50, 100, 250, 500, 1000, 2500, 5000];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * Per search type: the request count, the timed count, the summed duration and the cumulative buckets.
	 *
	 * Every type is present; a type without rows reads as zeros. `buckets`
	 * maps each bound of DURATION_BUCKETS_MS to the number of timed searches
	 * that took at most that long. A row without a duration counts as a
	 * request but not as a timed search.
	 *
	 * @return array<string, array{total: int, timed: int, sumMs: float, buckets: array<int, int>}>
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function searchStats(): array {
		$stats = [];
		foreach (self::SEARCH_TYPES as $label) {
			$stats[$label] = [
				'total' => 0,
				'timed' => 0,
				'sumMs' => 0.0,
				'buckets' => array_fill_keys(self::DURATION_BUCKETS_MS, 0),
			];
		}

		foreach ($this->searchRows() as $row) {
			$label = self::SEARCH_TYPES[(string) ($row['metric_type'] ?? '')] ?? null;
			if ($label === null) {
				continue;
			}

			$buckets = [];
			foreach (self::DURATION_BUCKETS_MS as $bound) {
				$buckets[$bound] = (int) ($row['le_'.$bound] ?? 0);
			}

			$stats[$label] = [
				'total' => (int) ($row['total'] ?? 0),
				'timed' => (int) ($row['timed'] ?? 0),
				'sumMs' => (float) ($row['sum_ms'] ?? 0),
				'buckets' => $buckets,
			];
		}

		return $stats;
	}//end searchStats()

	/**
	 * The embedding generations by outcome: `success` rows, and every other status as `failure`.
	 *
	 * @return array{success: int, failure: int}
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function embeddingCounts(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('status')
			->selectAlias($qb->func()->count('*'), 'total')
			->from(self::TABLE)
			->where($qb->expr()->eq('metric_type', $qb->createNamedParameter(self::METRIC_EMBEDDING_GENERATED)))
			->groupBy('status');

		$result = $qb->executeQuery();
		$rows   = $result->fetchAll();
		$result->closeCursor();

		$counts = ['success' => 0, 'failure' => 0];
		foreach ($rows as $row) {
			$outcome = 'failure';
			if (($row['status'] ?? null) === 'success') {
				$outcome = 'success';
			}

			$counts[$outcome] += (int) ($row['total'] ?? 0);
		}

		return $counts;
	}//end embeddingCounts()

	/**
	 * The grouped search rows: one per recorded search metric type.
	 *
	 * The bucket expressions are built from the integer constants above,
	 * never from input.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function searchRows(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('metric_type')
			->selectAlias($qb->func()->count('*'), 'total')
			->selectAlias($qb->createFunction('COUNT(duration_ms)'), 'timed')
			->selectAlias($qb->createFunction('SUM(duration_ms)'), 'sum_ms');

		foreach (self::DURATION_BUCKETS_MS as $bound) {
			$qb->selectAlias(
				$qb->createFunction('SUM(CASE WHEN duration_ms <= '.(int) $bound.' THEN 1 ELSE 0 END)'),
				'le_'.$bound
			);
		}

		$qb->from(self::TABLE)
			->where(
				$qb->expr()->in(
					'metric_type',
					$qb->createNamedParameter(array_keys(self::SEARCH_TYPES), IQueryBuilder::PARAM_STR_ARRAY)
				)
			)
			->groupBy('metric_type');

		$result = $qb->executeQuery();
		$rows   = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}//end searchRows()
}//end class
