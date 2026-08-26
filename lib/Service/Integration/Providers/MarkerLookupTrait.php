<?php

/**
 * MarkerLookupTrait — shared LIKE-based marker lookup for leaf providers
 * that link via a text marker in some user-visible field (title, name,
 * description).
 *
 * The provider:
 *   1. Picks a marker convention — e.g. `[or:{objectUuid}]` in the
 *      title — and seeds upstream entities with that marker.
 *   2. Calls `$this->findByMarker($db, 'analytics_report', 'name',
 *      $marker, ['description', 'type'])` to fetch matching rows.
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

use OCP\IDBConnection;
use Throwable;

trait MarkerLookupTrait {
	/**
	 * Find rows in an upstream NC app's table whose marker column
	 * contains the given marker substring.
	 *
	 * @param IDBConnection $db NC DB connection.
	 * @param string $table Table name without the `oc_` prefix.
	 * @param string $markerColumn Column to search the marker in.
	 * @param string $marker Marker substring (e.g. `[or:UUID]`).
	 * @param array<int,string> $extraColumns Other columns to return alongside the row.
	 * @param string $idColumn Primary-key column (default `id`).
	 *
	 * @return array<int,array<string,mixed>> Matching rows.
	 *
	 * @spec exclude Shared defensive LIKE-scan helper for leaf list() impls — no standalone contract;
	 *              the behavioural contract is each leaf provider's list() (annotated to its integration-* change).
	 */
	protected function findByMarker(
		IDBConnection $db,
		string $table,
		string $markerColumn,
		string $marker,
		array $extraColumns = [],
		string $idColumn = 'id',
	): array {
		try {
			$qb = $db->getQueryBuilder();
			$select = array_unique(array_merge([$idColumn, $markerColumn], $extraColumns));
			$qb->select(...$select)
				->from($table)
				->where(
					$qb->expr()->iLike(
						$markerColumn,
						$qb->createNamedParameter('%' . $marker . '%')
					)
				);
			// NC's IResult uses fetch() not fetchAllAssociative() in
			// older versions — iterate manually.
			$result = $qb->executeQuery();
			$rows = [];
			$row = $result->fetch();
			while ($row !== false) {
				$rows[] = $row;
				$row = $result->fetch();
			}

			return $rows;
		} catch (Throwable $e) {
			// Log defensively. This catch exists to DEGRADE gracefully (AD-23),
			// so the logging must not be able to fail louder than the thing it
			// reports. `\OCP\Server::get()` yields null when no server container
			// is up — under unit tests, for instance — and calling ->debug() on
			// that turned every handled query failure into a fatal
			// "Call to a member function debug() on null". Seven provider tests
			// died in the error handler rather than in the code under test.
			try {
				$logger = \OCP\Server::get(\Psr\Log\LoggerInterface::class);
				if ($logger !== null) {
					$logger->debug(
						'[MarkerLookupTrait] ' . $table . '.' . $markerColumn . ' query failed: ' . $e->getMessage(),
						['exception' => $e]
					);
				}
			} catch (Throwable $loggingFailure) {
				// No logger reachable at all — the degraded return below is
				// still the correct outcome, so swallow and carry on.
			}

			// Schema mismatch / app uninstalled / column missing — empty list (AD-23).
			return [];
		}//end try
	}//end findByMarker()
}//end trait
