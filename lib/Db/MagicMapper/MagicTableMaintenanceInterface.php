<?php

/**
 * MagicTableMaintenanceInterface: Creates, syncs, heals, indexes and drops magic tables, and clears their caches.
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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Creates, syncs, heals, indexes and drops magic tables, and clears their caches.
 *
 * One role of MagicMapperInterface: a caller that needs only this role types against it.
 */
interface MagicTableMaintenanceInterface {
	/**
	 * The metadata column definitions every magic table carries.
	 *
	 * @return array<string, array> Column definitions keyed by column name.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-magic-table-missing-metadata-columns-heals-itself-on-first-use-req-mth-001
	 */
	public function getMetadataColumnDefinitions(): array;

	/**
	 * Render the statement that adds one column to an existing magic table.
	 *
	 * The column is added nullable unless it has a default, because the table
	 * may already hold rows (see addMissingColumns()). On PostgreSQL it carries
	 * IF NOT EXISTS, so two requests adding the same column both succeed.
	 *
	 * @param string $tableName  Bare magic table name.
	 * @param array  $definition Column definition as getMetadataColumns() declares it.
	 *
	 * @return string The ALTER TABLE statement.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-magic-table-missing-metadata-columns-heals-itself-on-first-use-req-mth-001
	 */
	public function buildAddColumnSql(string $tableName, array $definition): string;

	/**
	 * The metadata columns a magic table lacks, read fresh.
	 *
	 * @param string $tableName Bare magic table name.
	 *
	 * @return array<string, array> Missing column definitions keyed by column name.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-reconcile-reports-and-repairs-missing-metadata-columns-req-mth-003
	 */
	public function inspectMetadataColumns(string $tableName): array;

	/**
	 * Add the metadata columns a magic table lacks.
	 *
	 * @param string $tableName Bare magic table name.
	 *
	 * @return array{added: list<string>, failed: array<string, string>} What was added, and what failed with why.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-reconcile-reports-and-repairs-missing-metadata-columns-req-mth-003
	 */
	public function healMetadataColumns(string $tableName): array;

	/**
	 * The shared metadata-column healer.
	 *
	 * @return MagicMetadataColumnHealer The healer.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-magic-table-missing-metadata-columns-heals-itself-on-first-use-req-mth-001
	 */
	public function getMetadataColumnHealer(): MagicMetadataColumnHealer;

	/**
	 * Invalidate table cache for specific register+schema
	 *
	 * @param string $cacheKey The cache key to invalidate
	 *
	 * @return void
	 */
	public function invalidateTableCache(string $cacheKey): void;

	/**
	 * Create table for specific register+schema combination
	 *
	 * @param Register $register The register context
	 * @param Schema $schema The schema to create table for
	 *
	 * @throws Exception If table creation fails
	 *
	 * @return true True if table created successfully
	 */
	public function createTableForRegisterSchema(Register $register, Schema $schema): bool;

	/**
	 * Synchronize table structure with schema definition.
	 *
	 * This is a public method that can be called to update an existing magic table
	 * to match the current schema definition. It will:
	 * - Add missing columns
	 * - De-require columns that are no longer required in schema
	 * - Drop duplicate camelCase columns when snake_case exists
	 * - Make obsolete columns nullable
	 * - Update indexes for relations and facetable fields
	 *
	 * @param Register $register The register context
	 * @param Schema $schema The schema definition
	 *
	 * @return array Statistics about what was changed
	 *
	 * @throws Exception If table sync fails
	 */
	public function syncTableForRegisterSchema(Register $register, Schema $schema): array;

	/**
	 * Build table columns from JSON schema properties
	 *
	 * This method analyzes the JSON schema and creates appropriate SQL column
	 * definitions for each property, plus all metadata columns from ObjectEntity.
	 *
	 * @param Schema $schema The schema to analyze
	 *
	 * @return (bool|int|mixed|null|string)[][] Column definitions.
	 */
	public function buildTableColumnsFromSchema(Schema $schema): array;

	/**
	 * Convert snake_case column name back to camelCase property name.
	 *
	 * Used when reading data from magic mapper tables to restore the original
	 * property names that OpenRegister expects.
	 *
	 * Examples:
	 * - in_stock -> inStock
	 * - first_name -> firstName
	 * - is_active -> isActive
	 *
	 * /**
	 * Check if register+schema combination has changed since last table update
	 *
	 * @param Register $register The register to check
	 * @param Schema $schema The schema to check
	 *
	 * @return bool True if register+schema has changed
	 */
	public function hasRegisterSchemaChanged(Register $register, Schema $schema): bool;

	/**
	 * Store register+schema version for change detection
	 *
	 * @param Register $register The register context
	 * @param Schema $schema The schema to store version for
	 *
	 * @return void
	 */
	public function storeRegisterSchemaVersion(Register $register, Schema $schema): void;

	/**
	 * Update table indexes
	 *
	 * @param string $tableName The table name
	 * @param Schema $schema The schema for index analysis
	 *
	 * @return void
	 */
	public function updateTableIndexes(string $tableName, Schema $schema): void;

	/**
	 * Drop table
	 *
	 * @param string $tableName The table name to drop
	 *
	 * @throws Exception If table drop fails
	 *
	 * @return void
	 *
	 * @psalm-suppress UndefinedInterfaceMethod quoteIdentifier exists via DBAL Connection
	 */
	public function dropTable(string $tableName): void;

	/**
	 * Clear all caches for MagicMapper.
	 *
	 * @param int|null $registerId Optional register ID to clear cache for specific register
	 * @param int|null $schemaId Optional schema ID to clear cache for specific schema
	 *
	 * @return void
	 */
	public function clearCache(?int $registerId = null, ?int $schemaId = null): void;

	/**
	 * Drop the request-scoped table memos.
	 *
	 * Called whenever a magic table is created, so a write that follows a table
	 * creation in the same request sees the new table rather than a cached
	 * "does not exist" / stale enumeration. A background job calls it before it
	 * looks objects up: a long-running `background-job:worker` outlives the
	 * request the memos were made for, and would not see tables created since.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
	 */
	public function invalidateTableMemos(): void;

	/**
	 * Get query builder instance.
	 *
	 * @return IQueryBuilder Query builder instance
	 */
	public function getQueryBuilder(): IQueryBuilder;

	/**
	 * Get max allowed packet size.
	 *
	 * @return int Max packet size
	 */
	public function getMaxAllowedPacketSize(): int;
}//end interface
