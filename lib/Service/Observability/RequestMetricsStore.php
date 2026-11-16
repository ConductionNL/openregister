<?php

/**
 * Durable aggregate rows of the request metrics in openregister_metrics.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Observability
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

namespace OCA\OpenRegister\Service\Observability;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Keeps one cumulative row per label set and reads them back grouped.
 *
 * A row holds a running total in `sample_count` (and `sample_sum_ms` for the
 * request rows): a flush adds to the existing row of its label set, or
 * inserts one. `created_at` is set to the time of the last flush, so the
 * retention cleanup only removes a label set that saw no traffic for the
 * whole retention period, and the table grows with the number of label sets,
 * not with the number of requests.
 *
 * Columns: `entity_type` the HTTP method or the error type, `entity_id` the
 * route, `status` the HTTP status (request rows) or the bucket bound in
 * milliseconds, `inf` for the overflow bucket (bucket rows).
 *
 * @spec openspec/changes/production-observability
 */
class RequestMetricsStore {

	/**
	 * The metrics table.
	 */
	private const TABLE = 'openregister_metrics';

	/**
	 * The request counter rows: count and summed duration per method, route and status.
	 */
	public const METRIC_REQUEST = 'http_request';

	/**
	 * The duration bucket rows: non-cumulative count per method, route and bucket.
	 */
	public const METRIC_BUCKET = 'http_request_bucket';

	/**
	 * The error rows: count per error type.
	 */
	public const METRIC_ERROR = 'http_error';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db   The database connection.
	 * @param ITimeFactory  $time The clock.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Add observations to the row of one label set, inserting the row when it does not exist.
	 *
	 * @param string $metricType One of the METRIC_ constants.
	 * @param string $entityType The method or error type (at most 32 characters are kept).
	 * @param string $entityId   The route, empty for errors (at most 64 characters are kept).
	 * @param string $status     The status or bucket, empty for errors (at most 20 characters are kept).
	 * @param int    $count      The number of observations.
	 * @param int    $sumMs      Their summed duration in milliseconds.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function add(string $metricType, string $entityType, string $entityId, string $status, int $count, int $sumMs): void {
		$entityType = substr($entityType, 0, 32);
		$entityId   = substr($entityId, 0, 64);
		$status     = substr($status, 0, 20);
		$now        = $this->time->getTime();

		$update = $this->db->getQueryBuilder();
		$update->update(self::TABLE)
			->set('sample_count', $update->createFunction('COALESCE(sample_count, 0) + '.$update->createNamedParameter($count, IQueryBuilder::PARAM_INT)))
			->set('sample_sum_ms', $update->createFunction('COALESCE(sample_sum_ms, 0) + '.$update->createNamedParameter($sumMs, IQueryBuilder::PARAM_INT)))
			->set('created_at', $update->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($update->expr()->eq('metric_type', $update->createNamedParameter($metricType)))
			->andWhere($update->expr()->eq('entity_type', $update->createNamedParameter($entityType)))
			->andWhere($update->expr()->eq('entity_id', $update->createNamedParameter($entityId)))
			->andWhere($update->expr()->eq('status', $update->createNamedParameter($status)));

		if ((int) $update->executeStatement() > 0) {
			return;
		}

		$insert = $this->db->getQueryBuilder();
		$insert->insert(self::TABLE)
			->values(
				[
					'metric_type' => $insert->createNamedParameter($metricType),
					'entity_type' => $insert->createNamedParameter($entityType),
					'entity_id' => $insert->createNamedParameter($entityId),
					'status' => $insert->createNamedParameter($status),
					'sample_count' => $insert->createNamedParameter($count, IQueryBuilder::PARAM_INT),
					'sample_sum_ms' => $insert->createNamedParameter($sumMs, IQueryBuilder::PARAM_INT),
					'created_at' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				]
			);
		$insert->executeStatement();
	}//end add()

	/**
	 * The request totals: method, route, status, count and summed milliseconds.
	 *
	 * @return array<int, array{method: string, route: string, status: string, count: int, sumMs: int}>
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function requestTotals(): array {
		$totals = [];
		foreach ($this->grouped(metricType: self::METRIC_REQUEST) as $row) {
			$totals[] = [
				'method' => (string) ($row['entity_type'] ?? ''),
				'route' => (string) ($row['entity_id'] ?? ''),
				'status' => (string) ($row['status'] ?? ''),
				'count' => (int) ($row['total'] ?? 0),
				'sumMs' => (int) ($row['sum_ms'] ?? 0),
			];
		}

		return $totals;
	}//end requestTotals()

	/**
	 * The duration bucket totals: method, route, bucket (bound in ms or `inf`) and non-cumulative count.
	 *
	 * @return array<int, array{method: string, route: string, bucket: string, count: int}>
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function bucketTotals(): array {
		$totals = [];
		foreach ($this->grouped(metricType: self::METRIC_BUCKET) as $row) {
			$totals[] = [
				'method' => (string) ($row['entity_type'] ?? ''),
				'route' => (string) ($row['entity_id'] ?? ''),
				'bucket' => (string) ($row['status'] ?? ''),
				'count' => (int) ($row['total'] ?? 0),
			];
		}

		return $totals;
	}//end bucketTotals()

	/**
	 * The error totals by type.
	 *
	 * @return array<string, int>
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function errorTotals(): array {
		$totals = [];
		foreach ($this->grouped(metricType: self::METRIC_ERROR) as $row) {
			$type          = (string) ($row['entity_type'] ?? '');
			$totals[$type] = ($totals[$type] ?? 0) + (int) ($row['total'] ?? 0);
		}

		return $totals;
	}//end errorTotals()

	/**
	 * The rows of one metric type summed per label set (normally one row each).
	 *
	 * @param string $metricType The metric type.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function grouped(string $metricType): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('entity_type', 'entity_id', 'status')
			->selectAlias($qb->createFunction('SUM(sample_count)'), 'total')
			->selectAlias($qb->createFunction('SUM(sample_sum_ms)'), 'sum_ms')
			->from(self::TABLE)
			->where($qb->expr()->eq('metric_type', $qb->createNamedParameter($metricType)))
			->groupBy('entity_type', 'entity_id', 'status');

		$result = $qb->executeQuery();
		$rows   = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}//end grouped()
}//end class
