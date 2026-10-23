<?php

/**
 * OpenRegister Audit Trail Statistics
 *
 * Grouped aggregates over the audit trail for the built-in dashboards.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\IDBConnection;

/**
 * Audit trail count and size per register-schema combination, read in one grouped query.
 */
class AuditTrailStatistics {
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
	 * Audit trail count and size per register-schema combination, in one grouped query.
	 *
	 * The register and schema columns are strings and may be null; each row keeps them as stored.
	 *
	 * @return array<int, array{register: string|null, schema: string|null, total: int, size: int}> One row per combination.
	 *
	 * @spec openspec/changes/dashboard-stats-one-pass/specs/built-in-dashboards/spec.md#requirement-the-registers-with-schemas-rollup-must-read-each-table-and-the-audit-trail-at-most-once
	 */
	public function groupedByRegisterSchema(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('register', 'schema')
			->selectAlias($qb->createFunction('COUNT(id)'), 'total')
			->selectAlias($qb->createFunction('COALESCE(SUM(size), 0)'), 'size')
			->from('openregister_audit_trails')
			->groupBy('register', 'schema');

		$rows = [];
		$result = $qb->executeQuery();
		while (($row = $result->fetch()) !== false) {
			$rows[] = [
				'register' => $this->idOrNull(value: $row['register']),
				'schema' => $this->idOrNull(value: $row['schema']),
				'total' => (int)$row['total'],
				'size' => (int)$row['size'],
			];
		}

		$result->closeCursor();

		return $rows;
	}//end groupedByRegisterSchema()

	/**
	 * A stored register or schema id as a string, or null when none is stored.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null The id as stored.
	 */
	private function idOrNull(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		return (string)$value;
	}//end idOrNull()
}//end class
