<?php

/**
 * MagicColumnDriftInterface: Reads the columns a magic table has and repairs the drift from the columns its schema needs.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use Exception;

/**
 * Reads the columns a magic table has and repairs the drift from the columns its schema needs.
 *
 * One role of MagicMapperInterface: a caller that needs only this role types against it.
 */
interface MagicColumnDriftInterface {
	/**
	 * Get existing table columns
	 *
	 * @param string $tableName The table name
	 *
	 * @throws Exception If unable to get table columns
	 *
	 * @return (bool|mixed)[][] Array of existing column definitions
	 */
	public function getExistingTableColumns(string $tableName): array;

	/**
	 * Update table structure with new columns
	 *
	 * @param string $tableName The table name
	 * @param array $currentColumns Current column definitions
	 * @param array $requiredColumns Required column definitions
	 *
	 * @throws Exception If table update fails
	 *
	 * @return array Column operation statistics with keys columnsAdded, columnsDeRequired, columnsReRequired, columnsDropped
	 */
	public function updateTableStructure(string $tableName, array $currentColumns, array $requiredColumns): array;

	/**
	 * Identify required columns whose physical column is absent from the live table.
	 *
	 * Required columns are keyed by schema PROPERTY name (camelCase) while
	 * getExistingTableColumns() keys by PHYSICAL column name (snake_case). A raw
	 * array_diff_key() between the two therefore reports every multi-word
	 * camelCase property (e.g. `allowedApps` → `allowed_apps`) as missing on
	 * every request, forcing a table sync that can never converge. Resolve the
	 * physical name first — the same resolution addMissingColumns() applies.
	 *
	 * @param array $currentColumns Live column definitions keyed by physical name (see
	 *                              getExistingTableColumns()).
	 * @param array $requiredColumns Required column definitions keyed by property name (see
	 *                               buildTableColumnsFromSchema()).
	 *
	 * @return array Missing column definitions keyed by property name.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function findMissingColumns(array $currentColumns, array $requiredColumns): array;

	/**
	 * Identify object-typed columns still backed by PostgreSQL JSONB that need retyping
	 * to JSON for key-order preservation (issue #1720).
	 *
	 * Returns the list of column names where:
	 *   - the schema column definition is type `json_ordered` (object-typed schema property), and
	 *   - the live database column is `jsonb` (the legacy storage type).
	 *
	 * MySQL/MariaDB JSON already preserves document order; this helper short-circuits to
	 * an empty list on any non-PostgreSQL platform, so the fast-path check stays cheap.
	 *
	 * @param array $currentColumns Live column definitions from information_schema (see
	 *                              getExistingTableColumns()).
	 * @param array $requiredColumns Required column definitions from the schema (see
	 *                               buildTableColumnsFromSchema()).
	 *
	 * @return string[] Column names whose storage type needs to change from JSONB to JSON.
	 */
	public function findJsonbColumnsNeedingRetype(array $currentColumns, array $requiredColumns): array;

	/**
	 * Find columns that must become a UUID reference but are still stored as JSON.
	 *
	 * When a property is turned INTO a relation (its handling becomes related-schema /
	 * related-object), it stops holding a document and starts holding a bare UUID
	 * string. A pre-existing `json` / `jsonb` column then rejects every write:
	 *
	 *     SQLSTATE[22P02]: invalid input syntax for type json
	 *     DETAIL: Token "b25f5f9c" is invalid.
	 *
	 * Without this the table is broken FOREVER after such an edit: the sync only ever
	 * added missing columns and migrated jsonb→json, so it never noticed that an
	 * existing column's type no longer matches what the property now stores.
	 *
	 * @param array $currentColumns Live columns, keyed by physical column name.
	 * @param array $requiredColumns Desired columns, keyed by property name.
	 *
	 * @return array<string> Physical column names needing a JSON → VARCHAR retype.
	 *
	 * @spec exclude schema-drift detection for the magic-table sync
	 */
	public function findRelationColumnsNeedingRetype(array $currentColumns, array $requiredColumns): array;

	/**
	 * Convert JSON columns that now hold a UUID reference to VARCHAR.
	 *
	 * Deliberately NON-destructive. The ALTER is attempted inside a try/catch, and a
	 * failure is logged rather than swallowed or forced: a column that still holds real
	 * documents (because the property used to be `nested-*`) cannot become a 255-char
	 * UUID without losing data, and quietly truncating a user's objects to make a schema
	 * edit "work" would be far worse than the edit failing loudly.
	 *
	 * `#>> '{}'` extracts a JSON scalar as text, so a column that already holds only
	 * UUID strings (or nulls) converts cleanly.
	 *
	 * @param string           $tableName The magic table.
	 * @param MagicColumnDrift $columns   The live columns (keyed by physical column name) and the desired ones (keyed by property name).
	 *
	 * @return array<string> The column names actually retyped.
	 *
	 * @spec exclude magic-table DDL sync
	 */
	public function migrateRelationColumnsToVarchar(string $tableName, MagicColumnDrift $columns): array;

	/**
	 * Find number columns narrower than their property now maps to.
	 *
	 * A table made before a number column took its scale from the schema has
	 * DECIMAL(10,2) columns, which round every value with more decimals.
	 *
	 * @param array $currentColumns  Live columns, keyed by physical column name.
	 * @param array $requiredColumns Desired columns, keyed by property name.
	 *
	 * @return array<string> Physical column names to widen.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-column-holds-every-value-its-property-accepts-req-mtc-001
	 */
	public function findDecimalColumnsNeedingWiden(array $currentColumns, array $requiredColumns): array;

	/**
	 * Widen number columns in place; never narrows, so no stored value changes.
	 *
	 * A failure is logged and the column left as it is: the write path refuses
	 * a value the property's column size cannot hold, so nothing is rounded.
	 *
	 * @param string           $tableName The magic table (without prefix).
	 * @param MagicColumnDrift $columns   The live columns (keyed by physical column name) and the desired ones (keyed by property name).
	 *
	 * @return array<string> The column names widened.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-column-holds-every-value-its-property-accepts-req-mtc-001
	 */
	public function widenDecimalColumns(string $tableName, MagicColumnDrift $columns): array;

	/**
	 * Widen every number column of a table to at least the default DECIMAL(20,6).
	 *
	 * The upgrade step runs this over the physical tables, so a table whose
	 * schema no register lists any more is widened too. A property whose
	 * multipleOf needs a larger scale gets it from the reconcile command or its
	 * next schema change.
	 *
	 * @param string $tableName The magic table (without prefix).
	 *
	 * @return array<string> The column names widened.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-column-holds-every-value-its-property-accepts-req-mtc-001
	 */
	public function widenNumberColumnsToDefault(string $tableName): array;
}//end interface
