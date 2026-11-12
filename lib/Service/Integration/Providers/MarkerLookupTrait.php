<?php

/**
 * MarkerLookupTrait — shared LIKE-based marker lookup for leaf providers
 * that link via a text marker in some user-visible field (title, name,
 * description).
 *
 * The provider:
 *   1. Picks a marker convention — e.g. `[or:{objectUuid}]` in the
 *      title — and seeds upstream entities with that marker.
 *   2. Calls `$this->scanMarker($db, new MarkerScan('analytics_report',
 *      'name', $marker, ['id', 'description', 'type']))` to fetch matching rows.
 *   3. Returns the rows mapped into the registry leaf row contract.
 *
 * The trait owns the IDBConnection / IQueryBuilder boilerplate so each
 * provider's `list()` stays ~5 lines. Defensive: any DB error degrades
 * to an empty list (AD-23).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

use OCP\DB\IResult;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Every class using this trait must declare a `LoggerInterface $logger`
 * property (constructor-injected); the degraded path logs through it.
 */
trait MarkerLookupTrait {
	/**
	 * The rows of one marker scan.
	 *
	 * @param IDBConnection $db   NC DB connection.
	 * @param MarkerScan    $scan The table, marker column, marker and columns to return.
	 *
	 * @return array<int,array<string,mixed>> Matching rows.
	 *
	 * @spec exclude Shared defensive LIKE-scan helper for leaf list() impls — no standalone contract;
	 *              the behavioural contract is each leaf provider's list() (annotated to its integration-* change).
	 */
	protected function scanMarker(IDBConnection $db, MarkerScan $scan): array {
		try {
			$qb = $db->getQueryBuilder();
			$qb->select(...$scan->selectList())->from($scan->table);
			$qb->where($qb->expr()->iLike($scan->markerColumn, $qb->createNamedParameter('%' . $scan->marker . '%')));

			return $this->markerRows(result: $qb->executeQuery());
		} catch (Throwable $e) {
			// This catch exists to DEGRADE gracefully (AD-23); the logger is
			// constructor-injected by every using provider, so logging cannot
			// fail louder than the thing it reports.
			$this->logger->debug(
				'[MarkerLookupTrait] ' . $scan->table . '.' . $scan->markerColumn . ' query failed: ' . $e->getMessage(),
				['exception' => $e]
			);

			// Schema mismatch / app uninstalled / column missing — empty list (AD-23).
			return [];
		}//end try
	}//end scanMarker()

	/**
	 * Every row of an executed marker query.
	 *
	 * NC's IResult uses fetch() not fetchAllAssociative() in older
	 * versions — iterate manually.
	 *
	 * @param IResult $result The executed query.
	 *
	 * @return array<int,array<string,mixed>> The rows, in order.
	 */
	private function markerRows(IResult $result): array {
		$rows = [];
		$row = $result->fetch();
		while ($row !== false) {
			$rows[] = $row;
			$row = $result->fetch();
		}

		return $rows;
	}//end markerRows()
}//end trait
