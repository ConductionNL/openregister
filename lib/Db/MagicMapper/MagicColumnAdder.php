<?php

/**
 * MagicColumnAdder: add the columns a schema requires to an existing magic table.
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

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Add required columns to an existing table, which may already hold rows.
 *
 * `ADD COLUMN ... NOT NULL` without a DEFAULT is rejected on a populated table,
 * so a NOT NULL is only written when a DEFAULT backfills the rows; otherwise the
 * column is added nullable and schema validation enforces it at write time
 * (see #2082/#2075).
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */
final class MagicColumnAdder {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db     The connection.
	 * @param LoggerInterface $logger Receives the outcome.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Add every required column the table lacks; a failure stops the sync.
	 *
	 * @param TableStructureSync $sync The table sync.
	 *
	 * @return array<string> The column names added.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function addMissing(TableStructureSync $sync): array {
		$added = [];
		foreach ($sync->columns->missing() as $propertyName => $columnDef) {
			$columnName = $sync->columns->columnName(propertyName: $propertyName, columnDef: $columnDef);
			$this->logger->debug(
				message: '[MagicMapper] Adding new column to schema table',
				context: ['tableName' => $sync->tableName, 'propertyName' => $propertyName, 'columnName' => $columnName, 'columnType' => $columnDef['type']]
			);
			$this->db->executeStatement($this->statement(sync: $sync, column: ['name' => $columnName] + $columnDef));
			$added[] = $columnName;
		}

		return $added;
	}//end addMissing()

	/**
	 * The SQL literal of a column default.
	 *
	 * @param mixed $default The default value, not null.
	 *
	 * @return string The literal.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function defaultLiteral(mixed $default): string {
		if (is_bool($default) === true) {
			return ['FALSE', 'TRUE'][(int)$default];
		}

		if (is_string($default) === true) {
			// Escaped by the platform, so a value such as O'Brien cannot break out of the DDL.
			return $this->db->getDatabasePlatform()->quoteStringLiteral($default);
		}

		return (string)$default;
	}//end defaultLiteral()

	/**
	 * The statement that adds one column.
	 *
	 * @param TableStructureSync $sync   The table sync.
	 * @param array              $column The required definition, with its physical name.
	 *
	 * @return string The ALTER TABLE statement.
	 */
	private function statement(TableStructureSync $sync, array $column): string {
		$sql = new MagicColumnSql(isPostgres: $sync->isPostgres);
		$add = 'ALTER TABLE ' . $sync->tableNameQuoted . ' ADD COLUMN ' . $sql->quote(name: $column['name']);

		return $add . ' ' . $sql->type(type: $column['type'], column: $column)
			. $this->notNullClause(sync: $sync, column: $column)
			. $this->defaultClause(column: $column);
	}//end statement()

	/**
	 * The NOT NULL clause, when a default backfills the rows.
	 *
	 * @param TableStructureSync $sync   The table sync.
	 * @param array              $column The required definition, with its physical name.
	 *
	 * @return string The clause, or nothing.
	 */
	private function notNullClause(TableStructureSync $sync, array $column): string {
		if (($column['nullable'] ?? true) !== false) {
			return '';
		}

		if (isset($column['default']) === true) {
			return ' NOT NULL';
		}

		$this->logger->debug(
			message: '[MagicMapper] Adding required column as NULLABLE on existing table (no default to backfill rows)',
			context: ['tableName' => $sync->tableName, 'columnName' => $column['name']]
		);

		return '';
	}//end notNullClause()

	/**
	 * The DEFAULT clause of a column.
	 *
	 * @param array $column The required definition.
	 *
	 * @return string The clause, or nothing.
	 */
	private function defaultClause(array $column): string {
		if (isset($column['default']) === false) {
			return '';
		}

		return ' DEFAULT ' . $this->defaultLiteral(default: $column['default']);
	}//end defaultClause()
}//end class
