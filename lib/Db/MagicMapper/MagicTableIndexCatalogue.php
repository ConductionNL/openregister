<?php

/**
 * MagicTableIndexCatalogue: the indexes that exist on a magic table.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-property-index-switch/tasks.md#1-backend
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCP\IDBConnection;

/**
 * Read the index names of one table from the database catalogue.
 *
 * @spec openspec/changes/modelling-property-index-switch/tasks.md#1-backend
 */
final class MagicTableIndexCatalogue {

	/**
	 * Build the catalogue reader.
	 *
	 * @param IDBConnection $db The connection.
	 */
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * The names of the indexes on a table, as the database knows them.
	 *
	 * @param string $fullTableName The table name with its prefix.
	 * @param bool   $isPostgres    Whether the platform is PostgreSQL.
	 *
	 * @return array<int, string> The index names, each once.
	 *
	 * @spec openspec/changes/modelling-property-index-switch/tasks.md#1-backend
	 */
	public function names(string $fullTableName, bool $isPostgres): array {
		$sql = 'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tbl';
		if ($isPostgres === true) {
			$sql = 'SELECT indexname FROM pg_indexes WHERE tablename = :tbl';
		}

		$result = $this->db->executeQuery($sql, ['tbl' => $fullTableName]);
		$names  = [];
		foreach ($result->fetchAll() as $row) {
			$name = $row;
			if (is_array($row) === true) {
				$name = reset($row);
			}

			if (is_string($name) === true && $name !== '') {
				$names[] = $name;
			}
		}

		$result->closeCursor();

		return array_values(array_unique($names));
	}//end names()
}//end class
