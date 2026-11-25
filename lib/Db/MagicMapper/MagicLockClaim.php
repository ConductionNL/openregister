<?php

/**
 * MagicLockClaim
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Db
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The atomic step of taking a lock: one conditional UPDATE of an object's `_locked` column.
 *
 * Deciding whether a lock may be taken happens in PHP on the lock as it was
 * read (ObjectEntity::acquireLockAs(): unlocked, expired, or held by the same
 * owner). This writes the new lock only while the row still holds exactly the
 * lock that decision was made on, and reports whether exactly one row changed.
 * Two workers that both read an unlocked row therefore cannot both win: the
 * first UPDATE changes the row, the second matches nothing and loses.
 *
 * The column holds JSON, so "still the same lock" is compared as JSON on each
 * platform rather than as text: MySQL and PostgreSQL may store it normalised.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class MagicLockClaim {

	/**
	 * The lock column.
	 *
	 * @var string
	 */
	public const COLUMN = '_locked';

	/**
	 * The identity column.
	 *
	 * @var string
	 */
	public const UUID_COLUMN = '_uuid';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The connection.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * Write the new lock only while the row still holds the previous one.
	 *
	 * @param string     $tableName The magic table, without prefix.
	 * @param string     $uuid      The object.
	 * @param array|null $previous  The lock the decision was made on; null or empty for unlocked.
	 * @param array      $next      The lock to store.
	 *
	 * @return bool True when exactly one row changed, so the caller holds the lock.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function swap(string $tableName, string $uuid, ?array $previous, array $next): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($tableName)
			->set(self::COLUMN, $qb->createNamedParameter(json_encode($next), IQueryBuilder::PARAM_STR))
			->where($qb->expr()->eq(self::UUID_COLUMN, $qb->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)))
			->andWhere($this->unchanged(qb: $qb, previous: $previous));

		return $qb->executeStatement() === 1;
	}//end swap()

	/**
	 * The lock the row holds now, read fresh; null when it holds none or the row is gone.
	 *
	 * @param string $tableName The magic table, without prefix.
	 * @param string $uuid      The object.
	 *
	 * @return array|null The stored lock.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function current(string $tableName, string $uuid): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(self::COLUMN)
			->from($tableName)
			->where($qb->expr()->eq(self::UUID_COLUMN, $qb->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));
		$result = $qb->executeQuery();
		$raw    = $result->fetchOne();
		$result->closeCursor();

		if (is_string($raw) === false || $raw === '') {
			return null;
		}

		$lock = json_decode($raw, true);
		if (is_array($lock) === false || $lock === []) {
			return null;
		}

		return $lock;
	}//end current()

	/**
	 * The condition "the row still holds this lock", per platform.
	 *
	 * @param IQueryBuilder $qb       The update being built.
	 * @param array|null    $previous The lock read; null or empty for unlocked.
	 *
	 * @return string The SQL condition.
	 */
	private function unchanged(IQueryBuilder $qb, ?array $previous): string {
		$column   = self::COLUMN;
		// Strict, or Nextcloud reports MariaDB as MySQL and the MySQL-only
		// CAST(... AS JSON) branch runs there: a syntax error, so no lock is ever claimed.
		$platform = $this->db->getDatabaseProvider(true);
		if ($previous === null || $previous === []) {
			// Unlocked: SQL NULL, or a JSON null or empty value.
			return match ($platform) {
				IDBConnection::PLATFORM_POSTGRES => "({$column} IS NULL OR {$column}::jsonb IN ('null'::jsonb, '[]'::jsonb, '{}'::jsonb))",
				IDBConnection::PLATFORM_MYSQL => "({$column} IS NULL OR CAST({$column} AS JSON) IN "
					."(CAST('null' AS JSON), CAST('[]' AS JSON), CAST('{}' AS JSON)))",
				IDBConnection::PLATFORM_MARIADB => "({$column} IS NULL OR JSON_COMPACT({$column}) IN ('null', '[]', '{}'))",
				IDBConnection::PLATFORM_SQLITE => "({$column} IS NULL OR json({$column}) IN ('null', '[]', '{}'))",
				default => "({$column} IS NULL OR {$column} IN ('null', '[]', '{}'))",
			};
		}

		$held = $qb->createNamedParameter(json_encode($previous), IQueryBuilder::PARAM_STR);

		return match ($platform) {
			IDBConnection::PLATFORM_POSTGRES => "{$column}::jsonb = CAST({$held} AS jsonb)",
			IDBConnection::PLATFORM_MYSQL => "CAST({$column} AS JSON) = CAST({$held} AS JSON)",
			IDBConnection::PLATFORM_MARIADB => "JSON_COMPACT({$column}) = JSON_COMPACT({$held})",
			IDBConnection::PLATFORM_SQLITE => "json({$column}) = json({$held})",
			default => "{$column} = {$held}",
		};
	}//end unchanged()
}//end class
