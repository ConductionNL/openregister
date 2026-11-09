<?php

/**
 * Audit-trail counts per processing activity, for the accountability report.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Aggregates `openregister_audit_trails` rows by `processing_activity_id` + `action`.
 *
 * A failing query yields no counts rather than an error: the report then
 * shows zero events for every activity.
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */
final class ProcessingActivityAuditCounts {
	/**
	 * Wrap the connection.
	 *
	 * @param IDBConnection $db DB for the aggregation.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * The counts per activity uuid, scoped to the given uuids.
	 *
	 * @param array<int, string> $uuids Activity uuids to aggregate against.
	 *
	 * @return array<string, array{totalEvents: int, byAction: array<string, int>}>
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function byActivity(array $uuids): array {
		$uuids = array_values(array_filter($uuids, static fn ($v) => is_string($v) === true && $v !== ''));
		if ($uuids === []) {
			return [];
		}

		try {
			$rows = $this->query(uuids: $uuids);
		} catch (Exception $e) {
			return [];
		}

		return $this->fold(rows: $rows);
	}//end byActivity()

	/**
	 * Run the grouped count.
	 *
	 * @param array<int, string> $uuids Non-empty activity uuids.
	 *
	 * @return array<int, array<string, mixed>> One row per activity and action.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private function query(array $uuids): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('processing_activity_id', 'action')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from('openregister_audit_trails')
			->where(
				$qb->expr()->in(
					'processing_activity_id',
					$qb->createNamedParameter($uuids, IQueryBuilder::PARAM_STR_ARRAY)
				)
			)
			->groupBy('processing_activity_id', 'action');

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}//end query()

	/**
	 * Fold the grouped rows into per-activity totals.
	 *
	 * @param array<int, array<string, mixed>> $rows The grouped rows.
	 *
	 * @return array<string, array{totalEvents: int, byAction: array<string, int>}>
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private function fold(array $rows): array {
		$aggregate = [];
		foreach ($rows as $row) {
			$uuid = (string)($row['processing_activity_id'] ?? '');
			$action = (string)($row['action'] ?? '');
			$count = (int)($row['cnt'] ?? 0);
			$aggregate[$uuid] ??= ['totalEvents' => 0, 'byAction' => []];
			$aggregate[$uuid]['byAction'][$action] = $count;
			$aggregate[$uuid]['totalEvents'] += $count;
		}

		return $aggregate;
	}//end fold()
}//end class
