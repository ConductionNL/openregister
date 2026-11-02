<?php

/**
 * MagicNumberColumnSizes: the actual precision and scale of a magic table's
 * number columns, read once per table per process.
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
 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-the-column-cannot-hold-is-refused-not-rounded-req-mtc-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCP\IDBConnection;

/**
 * Read a table's DECIMAL column sizes, so a save checks a value against the
 * column that will hold it rather than the size the schema asks for.
 *
 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-the-column-cannot-hold-is-refused-not-rounded-req-mtc-002
 */
final class MagicNumberColumnSizes {

	/**
	 * Sizes per lower-cased full table name, then per lower-cased column name.
	 *
	 * @var array<string, array<string, array{precision: int, scale: int}>>
	 */
	private static array $cache = [];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db          The database.
	 * @param string        $tablePrefix The Nextcloud table prefix.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly string $tablePrefix,
	) {
	}//end __construct()

	/**
	 * The number columns of a table with their precision and scale.
	 *
	 * @param string $tableName The magic table (without prefix).
	 *
	 * @return array<string, array{precision: int, scale: int}> Sizes keyed by lower-cased column name.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-the-column-cannot-hold-is-refused-not-rounded-req-mtc-002
	 */
	public function sizes(string $tableName): array {
		$key = strtolower($this->tablePrefix.$tableName);
		if (isset(self::$cache[$key]) === true) {
			return self::$cache[$key];
		}

		$sql = 'SELECT LOWER(column_name) AS col, numeric_precision, numeric_scale FROM information_schema.columns';
		$sql .= " WHERE LOWER(table_name) = ? AND LOWER(data_type) IN ('decimal', 'numeric')";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([$key]);

		$sizes = [];
		while (($row = $stmt->fetch()) !== false) {
			$sizes[(string)$row['col']] = [
				'precision' => (int)$row['numeric_precision'],
				'scale' => (int)$row['numeric_scale'],
			];
		}

		self::$cache[$key] = $sizes;
		return $sizes;
	}//end sizes()

	/**
	 * Forget a table's sizes after its columns changed.
	 *
	 * @param string $fullTableName The table name with its prefix.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-the-column-cannot-hold-is-refused-not-rounded-req-mtc-002
	 */
	public static function forget(string $fullTableName): void {
		unset(self::$cache[strtolower($fullTableName)]);
	}//end forget()
}//end class
