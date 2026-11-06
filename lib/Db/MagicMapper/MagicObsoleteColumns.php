<?php

/**
 * MagicObsoleteColumns: tidy the columns of a magic table its schema no longer has.
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
 * @spec openspec/specs/magic-table-columns/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use Psr\Log\LoggerInterface;

/**
 * Tidy columns a schema no longer has: drop camelCase duplicates of a
 * snake_case column, and make the rest nullable so their data is kept.
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */
final class MagicObsoleteColumns {
	use MagicColumnNameTrait;

	/**
	 * Constructor.
	 *
	 * @param MagicDdlRunner  $ddl    Runs the statements.
	 * @param LoggerInterface $logger Receives the outcome.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function __construct(
		private readonly MagicDdlRunner $ddl,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Drop camelCase columns whose snake_case version the schema requires.
	 *
	 * @param TableStructureSync $sync The table sync.
	 *
	 * @return array<string> The column names dropped.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function dropDuplicates(TableStructureSync $sync): array {
		$dropped = [];
		foreach (array_keys($sync->columns->current) as $colName) {
			if ($this->isDuplicate(sync: $sync, colName: $colName) === true
				&& $this->drop(sync: $sync, colName: $colName) === true
			) {
				$dropped[] = $colName;
			}
		}

		return $dropped;
	}//end dropDuplicates()

	/**
	 * Make columns the schema no longer has nullable.
	 *
	 * @param TableStructureSync $sync The table sync.
	 *
	 * @return array<string> The column names, suffixed with " (obsolete)".
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function release(TableStructureSync $sync): array {
		$released = [];
		foreach ($sync->columns->current as $colName => $colDef) {
			if ($this->isObsolete(sync: $sync, colName: $colName) === true
				&& $this->releaseColumn(sync: $sync, column: [$colName, $this->liveType(column: $colDef)]) === true
			) {
				$released[] = $colName . ' (obsolete)';
			}
		}

		return $released;
	}//end release()

	/**
	 * Whether a live column is a camelCase copy of a required snake_case column.
	 *
	 * @param TableStructureSync $sync    The table sync.
	 * @param string             $colName The live column.
	 *
	 * @return bool
	 */
	private function isDuplicate(TableStructureSync $sync, string $colName): bool {
		$snakeVersion = $this->sanitizeColumnName(name: $colName);

		return str_starts_with($colName, '_') === false
			&& $snakeVersion !== $colName
			&& isset($sync->requiredNames[$snakeVersion]) === true;
	}//end isDuplicate()

	/**
	 * Drop one duplicate column; it was read from the table, so no `IF EXISTS`, which MySQL does not have.
	 *
	 * @param TableStructureSync $sync    The table sync.
	 * @param string             $colName The live column.
	 *
	 * @return bool Whether the statement succeeded.
	 */
	private function drop(TableStructureSync $sync, string $colName): bool {
		$this->logger->debug(
			message: '[MagicMapper] Dropping duplicate camelCase column (snake_case version exists)',
			context: ['tableName' => $sync->tableName, 'camelCaseCol' => $colName, 'snakeCaseCol' => $this->sanitizeColumnName(name: $colName)]
		);
		$quoted = (new MagicColumnSql(isPostgres: $sync->isPostgres))->quote(name: $colName);

		return $this->ddl->attempt(
			sql: 'ALTER TABLE ' . $sync->tableNameQuoted . ' DROP COLUMN ' . $quoted,
			failure: new MagicDdlFailure(message: '[MagicMapper] Failed to drop duplicate column', context: ['columnName' => $colName])
		);
	}//end drop()

	/**
	 * Whether a live NOT NULL column is one the schema does not have.
	 *
	 * @param TableStructureSync $sync    The table sync.
	 * @param string             $colName The live column.
	 *
	 * @return bool
	 */
	private function isObsolete(TableStructureSync $sync, string $colName): bool {
		return str_starts_with($colName, '_') === false
			&& isset($sync->requiredNames[$colName]) === false
			&& ($sync->columns->current[$colName]['nullable'] ?? true) !== true;
	}//end isObsolete()

	/**
	 * The live SQL type of a column; `data_type` alone has no length, and MySQL refuses a bare `varchar`.
	 *
	 * @param array $column The live column.
	 *
	 * @return string The SQL type.
	 */
	private function liveType(array $column): string {
		return $column['sqlType'] ?? $column['type'] ?? 'text';
	}//end liveType()

	/**
	 * Make one obsolete column nullable.
	 *
	 * @param TableStructureSync $sync   The table sync.
	 * @param array              $column Pair of column name and live SQL type.
	 *
	 * @return bool Whether the statement succeeded.
	 */
	private function releaseColumn(TableStructureSync $sync, array $column): bool {
		[$colName, $sqlType] = $column;
		$this->logger->debug(
			message: '[MagicMapper] Making obsolete column nullable',
			context: ['tableName' => $sync->tableName, 'columnName' => $colName]
		);
		$change = new MagicNullabilityChange(sync: $sync, nullable: true);

		return $this->ddl->attempt(
			sql: $change->statement(columnName: $colName, sqlType: $sqlType),
			failure: new MagicDdlFailure(message: '[MagicMapper] Failed to make obsolete column nullable', context: ['columnName' => $colName])
		);
	}//end releaseColumn()
}//end class
