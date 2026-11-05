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
	 * Snake_case column names the schema requires, keyed by name.
	 *
	 * @var array<string, bool>
	 */
	public array $snakeCaseColumns = [];

	/**
	 * Constructor.
	 *
	 * @param string $tableName       The logical table name.
	 * @param string $tableNameQuoted The quoted full table name for SQL.
	 * @param array  $currentColumns  Current column definitions from the database.
	 * @param array  $requiredColumns Required column definitions from the schema.
	 * @param bool   $isPostgres      Whether the platform is PostgreSQL.
	 */
	public function __construct(
		public readonly string $tableName,
		public readonly string $tableNameQuoted,
		public readonly array $currentColumns,
		public readonly array $requiredColumns,
		public readonly bool $isPostgres,
	) {
	}//end __construct()
}//end class
