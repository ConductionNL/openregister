<?php

/**
 * MagicTableLocatorInterface: Finds, names and ensures the magic table of a register and schema.
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
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * Finds, names and ensures the magic table of a register and schema.
 *
 * One role of MagicMapperInterface: a caller that needs only this role types against it.
 */
interface MagicTableLocatorInterface {
	/**
	 * Get table name for a specific register+schema combination
	 *
	 * @param Register $register The register context
	 * @param Schema $schema The schema context
	 *
	 * @return string The table name for the register+schema combination
	 */
	public function getTableNameForRegisterSchema(Register $register, Schema $schema): string;

	/**
	 * Check if register+schema table exists (with caching)
	 *
	 * @param Register $register The register context
	 * @param Schema $schema The schema context
	 *
	 * @return bool True if table exists
	 */
	public function tableExistsForRegisterSchema(Register $register, Schema $schema): bool;

	/**
	 * Check if a specialized table exists for register+schema combination
	 *
	 * This method provides fast existence checking with intelligent caching
	 * to avoid repeated database calls. Cache is automatically invalidated
	 * when tables are created, updated, or dropped.
	 *
	 * @param Register $register The register context
	 * @param Schema $schema The schema context
	 *
	 * @return bool True if specialized table exists, false if should use generic storage
	 */
	public function existsTableForRegisterSchema(Register $register, Schema $schema): bool;

	/**
	 * Create or update table for a specific register+schema combination
	 *
	 * This method analyzes the JSON schema and creates/updates the corresponding
	 * database table with appropriate columns, indexes, and constraints.
	 *
	 * @param Register $register The register context for the table
	 * @param Schema $schema The schema to create/update table for
	 * @param bool $force Whether to force recreation even if table exists
	 *
	 * @throws Exception If table creation/update fails
	 *
	 * @return bool True if table was created/updated successfully
	 */
	public function ensureTableForRegisterSchema(Register $register, Schema $schema, bool $force = false): bool;

	/**
	 * Get all existing register+schema tables
	 *
	 * This method scans the database for all tables matching our naming pattern
	 * and returns them as an array of register+schema combinations.
	 *
	 * @return (int|string)[][] Array of ['registerId' => int, 'schemaId' => int, 'tableName' => string].
	 */
	public function getExistingRegisterSchemaTables(): array;

	/**
	 * Get all register/schema pairs that have magic tables in the database.
	 *
	 * Discovers magic tables from information_schema and extracts register/schema IDs
	 * from table names (oc_openregister_table_{registerId}_{schemaId}).
	 *
	 * @return array<array{registerId: int, schemaId: int}> Array of register/schema ID pairs
	 */
	public function getAllRegisterSchemaPairs(): array;

	/**
	 * Every physical magic table, as bare names, whether or not a register still lists its schema.
	 *
	 * The reconcile command and the repair step walk this rather than the
	 * register to schema links, because a table whose schema a register no
	 * longer lists can still be queried and still lack a column.
	 *
	 * @return list<string> Bare table names (`openregister_table_<register>_<schema>`).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-reconcile-reports-and-repairs-missing-metadata-columns-req-mth-003
	 */
	public function listMagicTableNames(): array;

	/**
	 * Check if MagicMapper is enabled for a register+schema combination
	 *
	 * @param Register $_register The register to check
	 * @param Schema $schema The schema to check
	 *
	 * @return bool True if MagicMapper should be used for this register+schema
	 */
	public function isMagicMappingEnabled(Register $_register, Schema $schema): bool;

	/**
	 * BACKWARD COMPATIBILITY: Check if MagicMapper is enabled for a schema only
	 *
	 * @param Schema $schema The schema to check
	 *
	 * @deprecated Use isMagicMappingEnabled(Register, Schema) instead
	 * @return bool True if MagicMapper should be used for this schema
	 */
	public function isMagicMappingEnabledForSchema(Schema $schema): bool;

	/**
	 * Get cache key for register+schema combination
	 *
	 * @param int $registerId The register ID
	 * @param int $schemaId The schema ID
	 *
	 * @return string Cache key for the combination
	 */
	public function getCacheKey(int $registerId, int $schemaId): string;

	/**
	 * Check if table exists in database (bypassing cache)
	 *
	 * Uses Nextcloud 32+ compatible API for checking table existence.
	 *
	 * @param string $tableName The table name to check
	 *
	 * @return bool True if table exists in database
	 */
	public function checkTableExistsInDatabase(string $tableName): bool;
}//end interface
