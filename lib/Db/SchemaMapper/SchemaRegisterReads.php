<?php

/**
 * SchemaRegisterReads: which registers hold a schema, and how many objects it has.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCP\IDBConnection;

/**
 * Reads the schema lists of the registers and the magic tables behind them.
 *
 * Direct queries, decoded in PHP: the `schemas` column is JSON on Postgres and
 * text elsewhere, so there is no portable SQL predicate for "array contains N".
 * Registers are O(10s) per install, so the cost is trivial. RegisterMapper and
 * MagicStatisticsHandler cannot be injected here: both inject SchemaMapper.
 *
 * @spec exclude schema read plumbing moved out of SchemaMapper, not a capability of its own
 */
final class SchemaRegisterReads {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database.
	 */
	public function __construct(private readonly IDBConnection $db) {
	}//end __construct()

	/**
	 * The number of live objects attached to a schema, over every register holding it.
	 *
	 * Objects live in the per-register/schema magic tables
	 * (`openregister_table_{registerId}_{schemaId}`). Soft-deleted rows are excluded,
	 * matching the object count the controller's guard reports to the caller.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return int The number of live objects.
	 *
	 * @spec openspec/changes/schema-delete-cascade/specs/runtime-schema-api/spec.md
	 */
	public function attachedObjects(int $schemaId): int {
		$total = 0;
		foreach ($this->registerIdsHolding(schemaId: $schemaId) as $registerId) {
			$tableName = MagicMapperInterface::TABLE_PREFIX . $registerId . '_' . $schemaId;
			if ($this->db->tableExists($tableName) === true) {
				$total += $this->liveRows(tableName: $tableName);
			}
		}

		return $total;
	}//end attachedObjects()

	/**
	 * How many registers reference each schema.
	 *
	 * @return array<int, int> Schema id => register count.
	 */
	public function registerCountPerSchema(): array {
		$counts = [];
		foreach ($this->schemaLists() as $schemas) {
			foreach ($schemas as $schemaId) {
				$counts[(int) $schemaId] = (($counts[(int) $schemaId] ?? 0) + 1);
			}
		}

		return $counts;
	}//end registerCountPerSchema()

	/**
	 * The ids of every register that references a schema.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return array<int, int> The register ids.
	 */
	private function registerIdsHolding(int $schemaId): array {
		$needle = (string) $schemaId;
		$registerIds = [];
		foreach ($this->schemaLists() as $registerId => $schemas) {
			if (in_array($needle, array_map('strval', $schemas), true) === true) {
				$registerIds[] = $registerId;
			}
		}

		return $registerIds;
	}//end registerIdsHolding()

	/**
	 * The decoded schema list of every register; an undecodable one is empty.
	 *
	 * @return array<int, array<mixed>> Register id => schema ids.
	 */
	private function schemaLists(): array {
		$lists = [];
		foreach ($this->registerRows() as $row) {
			$schemas = json_decode(($row['schemas'] ?? '[]'), true);
			$lists[(int) $row['id']] = [];
			if (is_array($schemas) === true) {
				$lists[(int) $row['id']] = $schemas;
			}
		}

		return $lists;
	}//end schemaLists()

	/**
	 * The id and schema list column of every register.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function registerRows(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'schemas')
			->from('openregister_registers');

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}//end registerRows()

	/**
	 * Count the rows of a magic table that are not soft-deleted.
	 *
	 * @param string $tableName The table.
	 *
	 * @return int The count.
	 */
	private function liveRows(string $tableName): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($tableName)
			->where($qb->expr()->isNull('_deleted'));

		$result = $qb->executeQuery();
		$count = (int) $result->fetchOne();
		$result->closeCursor();

		return $count;
	}//end liveRows()
}//end class
