<?php

/**
 * UniqueUuidIndex: reports duplicate uuids in a magic table and adds the unique uuid index once there are none.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use Doctrine\DBAL\Schema\Schema;
use OCP\IConfig;
use OCP\IDBConnection;
use RuntimeException;

/**
 * The unique `_uuid` index of a magic table.
 *
 * Tables created before the index was part of every table may lack it, and
 * may hold two rows with the same uuid. This never deletes or changes a row:
 * it reports the duplicates so an administrator can resolve them, and adds the
 * index only to a table that holds none.
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */
class UniqueUuidIndex {

	/**
	 * Duplicate uuids listed per table in a report.
	 */
	public const SAMPLE = 20;

	/**
	 * The database schema, read once per run.
	 *
	 * @var Schema|null
	 */
	private ?Schema $schema = null;

	/**
	 * Build the index check.
	 *
	 * @param IDBConnection $db     The connection.
	 * @param IConfig       $config Holds the table prefix.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IConfig $config,
	) {
	}//end __construct()

	/**
	 * Whether the table already has a unique index or constraint on `_uuid` alone.
	 *
	 * @param string $tableName The magic table, without the instance prefix.
	 *
	 * @return bool True when uuids are already unique by constraint.
	 *
	 * @throws RuntimeException When the table's indexes cannot be read.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function isUnique(string $tableName): bool {
		$this->schema ??= $this->db->createSchema();
		$fullName = $this->fullName(tableName: $tableName);
		if ($this->schema->hasTable($fullName) === false) {
			throw new RuntimeException('Table ' . $fullName . ' is not in the database schema');
		}

		foreach ($this->schema->getTable($fullName)->getIndexes() as $index) {
			$columns = array_map('strtolower', $index->getColumns());
			if ($columns === ['_uuid'] && ($index->isUnique() === true || $index->isPrimary() === true)) {
				return true;
			}
		}

		return false;
	}//end isUnique()

	/**
	 * The uuids more than one row of the table carries, with their row count.
	 *
	 * @param string $tableName The magic table, without the instance prefix.
	 * @param int    $limit     How many to list.
	 *
	 * @return array<string, int> Row count per duplicated uuid.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function duplicates(string $tableName, int $limit = self::SAMPLE): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('_uuid')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($tableName)
			->where($qb->expr()->isNotNull('_uuid'))
			->groupBy('_uuid')
			->having($qb->expr()->gt($qb->func()->count('*'), $qb->createNamedParameter(1)))
			->orderBy('_uuid')
			->setMaxResults($limit);

		$result = $qb->executeQuery();
		$duplicates = [];
		while (($row = $result->fetch()) !== false) {
			$duplicates[(string)$row['_uuid']] = (int)$row['cnt'];
		}

		$result->closeCursor();

		return $duplicates;
	}//end duplicates()

	/**
	 * Add the unique uuid index.
	 *
	 * @param string $tableName The magic table, without the instance prefix.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function add(string $tableName): void {
		$this->db->executeStatement('CREATE UNIQUE INDEX ' . $tableName . '_uuid_uidx ON ' . $this->fullName(tableName: $tableName) . ' (_uuid)');
	}//end add()

	/**
	 * A magic table's name with the instance's table prefix.
	 *
	 * @param string $tableName The magic table, without the prefix.
	 *
	 * @return string The prefixed name.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	private function fullName(string $tableName): string {
		return $this->config->getSystemValueString('dbtableprefix', 'oc_') . $tableName;
	}//end fullName()
}//end class
