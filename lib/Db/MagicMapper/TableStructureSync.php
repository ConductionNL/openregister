<?php

/**
 * TableStructureSync
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

/**
 * One update of a magic table's structure: the table, the columns it has,
 * the columns its schema requires, and the platform the DDL is written for.
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */
final class TableStructureSync {

	/**
	 * The quoted full table name for SQL.
	 *
	 * @var string
	 */
	public readonly string $tableNameQuoted;

	/**
	 * The physical names of the columns the schema requires, as keys.
	 *
	 * @var array<string, bool>
	 */
	public readonly array $requiredNames;

	/**
	 * Constructor.
	 *
	 * @param string           $tableName     The logical table name.
	 * @param string           $fullTableName The table name with the configured prefix.
	 * @param MagicColumnDrift $columns       The live and required columns.
	 * @param bool             $isPostgres    Whether the platform is PostgreSQL.
	 */
	public function __construct(
		public readonly string $tableName,
		public readonly string $fullTableName,
		public readonly MagicColumnDrift $columns,
		public readonly bool $isPostgres,
	) {
		$this->tableNameQuoted = (new MagicColumnSql(isPostgres: $isPostgres))->quote(name: $fullTableName);
		$this->requiredNames = $columns->requiredNames();
	}//end __construct()
}//end class
