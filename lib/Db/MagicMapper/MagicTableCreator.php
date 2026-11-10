<?php

/**
 * MagicTableCreator: the CREATE TABLE statement of a magic table.
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

use Exception;

/**
 * Create a magic table from its column definitions.
 *
 * The SQL is built by hand for Nextcloud 32 compatibility.
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */
final class MagicTableCreator {
	/**
	 * Build the creator.
	 *
	 * @param MagicTableSql $sql The connection, the table prefix and the logger.
	 */
	public function __construct(private readonly MagicTableSql $sql) {
	}//end __construct()

	/**
	 * Create a table with the given columns.
	 *
	 * @param string $tableName The table name, without prefix.
	 * @param array  $columns   The column definitions.
	 *
	 * @throws Exception If table creation fails.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function create(string $tableName, array $columns): void {
		try {
			$this->sql->db->executeStatement($this->statement(tableName: $tableName, columns: $columns));
			$this->sql->logger->debug(
				message: '[MagicMapper] Created table with columns',
				context: ['tableName' => $tableName, 'fullTableName' => $this->prefixed(tableName: $tableName), 'columns' => array_column($columns, 'name')]
			);
		} catch (Exception $e) {
			$this->sql->logger->error(
				message: '[MagicMapper] Failed to create table',
				context: ['tableName' => $tableName, 'error' => $e->getMessage()]
			);

			throw new Exception('Failed to create table ' . $tableName . ': ' . $e->getMessage(), 0, $e);
		}
	}//end create()

	/**
	 * The CREATE TABLE statement.
	 *
	 * @param string $tableName The table name, without prefix.
	 * @param array  $columns   The column definitions.
	 *
	 * @return string The SQL.
	 */
	private function statement(string $tableName, array $columns): string {
		$ddl = new MagicTableDdl(platform: $this->sql->db->getDatabasePlatform(), tableName: $tableName);
		foreach ($columns as $column) {
			$this->addColumn(ddl: $ddl, column: $column);
		}

		return 'CREATE TABLE IF NOT EXISTS ' . $ddl->quoted(name: $this->prefixed(tableName: $tableName))
			. ' (' . implode(', ', $ddl->columnDefs) . $this->constraints(ddl: $ddl) . ')';
	}//end statement()

	/**
	 * The table name with the configured prefix.
	 *
	 * @param string $tableName The table name, without prefix.
	 *
	 * @return string The prefixed name.
	 */
	private function prefixed(string $tableName): string {
		return $this->sql->config->getSystemValue('dbtableprefix', 'oc_') . $tableName;
	}//end prefixed()

	/**
	 * Add one column to the statement and track its primary key and unique constraint.
	 *
	 * @param MagicTableDdl $ddl    The statement being built.
	 * @param array         $column The column definition.
	 *
	 * @return void
	 */
	private function addColumn(MagicTableDdl $ddl, array $column): void {
		$colName = (new MagicColumnSql(isPostgres: $ddl->isPostgres))->quote(name: $column['name']);
		$ddl->columnDefs[] = $this->definition(ddl: $ddl, column: $column);

		if (($column['primary'] ?? false) === true) {
			$ddl->primaryKey = $colName;
		}

		if (($column['unique'] ?? false) === true) {
			$ddl->uniqueConstraints[] = $colName;
		}
	}//end addColumn()

	/**
	 * The definition of one column.
	 *
	 * @param MagicTableDdl $ddl    The statement being built.
	 * @param array         $column The column definition.
	 *
	 * @return string The definition.
	 */
	private function definition(MagicTableDdl $ddl, array $column): string {
		$sql = new MagicColumnSql(isPostgres: $ddl->isPostgres);
		$colName = $sql->quote(name: $column['name']);
		$def = $colName . ' ' . $sql->type(type: $column['type'], column: $column);

		if (($column['nullable'] ?? true) === false) {
			$def .= ' NOT NULL';
		}

		if (isset($column['default']) === true) {
			$def .= ' DEFAULT ' . $this->defaultLiteral(ddl: $ddl, value: $column['default']);
		}

		if (($column['autoincrement'] ?? false) === true) {
			return $this->autoincrementColumn(ddl: $ddl, colName: $colName) ?? $def . ' AUTO_INCREMENT';
		}

		return $def;
	}//end definition()

	/**
	 * The SQL literal of a column default.
	 *
	 * @param MagicTableDdl $ddl   The statement being built.
	 * @param mixed         $value The default value, never null (a null default is not written).
	 *
	 * @return mixed The literal: TRUE/FALSE, a quoted string, or the value itself.
	 */
	private function defaultLiteral(MagicTableDdl $ddl, mixed $value): mixed {
		if ($value === true) {
			return 'TRUE';
		}

		if ($value === false) {
			return 'FALSE';
		}

		if (is_string($value) === true) {
			// Escaped by the platform, so a value like O'Brien cannot break the DDL or inject into it.
			return $ddl->platform->quoteStringLiteral($value);
		}

		return $value;
	}//end defaultLiteral()

	/**
	 * The definition of an autoincrement column on PostgreSQL or SQLite.
	 *
	 * PostgreSQL uses BIGSERIAL. SQLite auto-increments an INTEGER PRIMARY KEY
	 * column declared inline; AUTO_INCREMENT is a syntax error there and a
	 * separate PRIMARY KEY(...) constraint would stop the column from aliasing
	 * rowid, so the inline declaration is recorded on the statement.
	 *
	 * @param MagicTableDdl $ddl     The statement being built.
	 * @param string        $colName The quoted column name.
	 *
	 * @return string|null The definition, or null where MySQL's AUTO_INCREMENT applies.
	 */
	private function autoincrementColumn(MagicTableDdl $ddl, string $colName): ?string {
		if ($ddl->isPostgres === true) {
			return $colName . ' BIGSERIAL';
		}

		if ($ddl->isSqlite === true) {
			$ddl->inlinePrimaryKey = true;
			return $colName . ' INTEGER PRIMARY KEY AUTOINCREMENT';
		}

		return null;
	}//end autoincrementColumn()

	/**
	 * The PRIMARY KEY and UNIQUE table constraints of the statement.
	 *
	 * The primary key is skipped on SQLite when the autoincrement column already
	 * declared it inline. Unique constraints (required for PostgreSQL ON
	 * CONFLICT) are named explicitly so MySQL/MariaDB doesn't fall back to using
	 * the column name as the constraint/index name, which collides across tables
	 * (e.g. every dynamic table getting a constraint literally called `_uuid`)
	 * and breaks Nextcloud's ensureUniqueNamesConstraints check on the next app
	 * install.
	 *
	 * @param MagicTableDdl $ddl The statement being built.
	 *
	 * @return string The constraints, each with a leading comma.
	 */
	private function constraints(MagicTableDdl $ddl): string {
		$sql = '';
		if ($ddl->primaryKey !== null && $ddl->inlinePrimaryKey === false) {
			$sql .= ', PRIMARY KEY (' . $ddl->primaryKey . ')';
		}

		foreach ($ddl->uniqueConstraints as $uniqueCol) {
			$constraintName = $ddl->tableName . '_' . ltrim(trim($uniqueCol, '`"'), '_') . '_uq';
			$sql .= ', CONSTRAINT ' . $ddl->quoted(name: $constraintName) . ' UNIQUE (' . $uniqueCol . ')';
		}

		return $sql;
	}//end constraints()
}//end class
