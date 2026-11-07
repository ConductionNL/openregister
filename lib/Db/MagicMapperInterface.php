<?php

/**
 * MagicMapperInterface: the public surface of MagicMapper, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

use Exception;
use OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumnHealer;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\DB\QueryBuilder\IQueryBuilder;
use RuntimeException;

/**
 * Every public method of MagicMapper. Callers depend on this interface, not on the class.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The signatures are MagicMapper's, which carries the same flags
 */
interface MagicMapperInterface {

	/**
	 * Table name prefix for register+schema-specific tables.
	 *
	 * NOTE: Does NOT include 'oc_' prefix as Nextcloud's QueryBuilder adds that automatically.
	 *
	 * @internal Used by MagicTableHandler
	 */
	public const TABLE_PREFIX = 'openregister_table_';

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
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Force flag allows table recreation
	 */
	public function ensureTableForRegisterSchema(Register $register, Schema $schema, bool $force = false): bool;

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
	 * Save objects to register+schema-specific table
	 *
	 * @param array $objects Array of object data to save
	 * @param Register $register The register context for table selection
	 * @param Schema $schema The schema for table selection
	 *
	 * @throws Exception If save operation fails
	 *
	 * @return string[]
	 *
	 * @psalm-return list<non-empty-string>
	 */
	public function saveObjectsToRegisterSchemaTable(array $objects, Register $register, Schema $schema): array;

	/**
	 * Search objects in register+schema-specific table
	 *
	 * @param array $query Search query parameters
	 * @param Register $register The register context for table selection
	 * @param Schema $schema The schema for table selection
	 *
	 * @throws Exception If search operation fails
	 *
	 * @return ObjectEntity[]
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	public function searchObjectsInRegisterSchemaTable(array $query, Register $register, Schema $schema): array;

	/**
	 * Get the list of filter properties that were ignored during the last search.
	 *
	 * These are properties that were requested as filters but don't exist in the schema.
	 * Useful for providing feedback to API clients about invalid filter parameters.
	 *
	 * @return array<string> List of ignored filter property names
	 */
	public function getIgnoredFilters(): array;

	/**
	 * Count objects in a register+schema specific table.
	 *
	 * This method counts objects from a dedicated table based on register+schema combination.
	 * It supports basic filtering and returns only the count for better performance.
	 * A count that cannot be made answers 0 (logged); a caller that acts on the
	 * number uses countObjectsOrFail() instead.
	 *
	 * @param array    $query    Search parameters for filtering (excluding pagination).
	 * @param Register $register The register context for table selection.
	 * @param Schema   $schema   The schema for table selection.
	 *
	 * @return int Count of matching objects.
	 */
	public function countObjectsInRegisterSchemaTable(array $query, Register $register, Schema $schema): int;

	/**
	 * Count objects in a register+schema table, throwing when the count cannot be made.
	 *
	 * The same count as countObjectsInRegisterSchemaTable(), for a caller that
	 * acts on the number (a quota): a failed query or an answer that is not a
	 * number throws instead of reading as 0, so a broken count can never pass
	 * for an empty table (live pass O10).
	 *
	 * @param array    $query    Search parameters for filtering (excluding pagination).
	 * @param Register $register The register context for table selection.
	 * @param Schema   $schema   The schema for table selection.
	 *
	 * @return int Count of matching objects.
	 *
	 * @throws RuntimeException When the count query does not answer with a number.
	 * @throws Exception        When the count query fails.
	 *
	 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md
	 */
	public function countObjectsOrFail(array $query, Register $register, Schema $schema): int;

	/**
	 * Get simple facets for a register+schema specific table.
	 *
	 * This method retrieves facet data (aggregations) from a dedicated magic mapper table
	 * based on register+schema combination. It supports both metadata facets (@self fields)
	 * and schema property facets.
	 *
	 * @param array $query Search parameters including _facets configuration.
	 * @param Register $register The register context for table selection.
	 * @param Schema $schema The schema for table selection.
	 *
	 * @return array Facet results with buckets.
	 */
	public function getSimpleFacetsFromRegisterSchemaTable(array $query, Register $register, Schema $schema): array;

	/**
	 * Get facets using UNION ALL across multiple register+schema tables.
	 *
	 * This method is optimized for multi-schema faceting by executing ONE query
	 * per facet field using UNION ALL, instead of separate queries per table.
	 * Benchmarks show 2-2.5x speedup for large datasets.
	 *
	 * @param array $query Search parameters including _facets configuration.
	 * @param Register $register The register context.
	 * @param array $schemas Array of Schema objects to include.
	 * @param array $registerSchemaPairs Register/schema pairs.
	 *
	 * @return array Merged facet results.
	 */
	public function getSimpleFacetsUnion(array $query, ?Register $register = null, array $schemas = [], array $registerSchemaPairs = []): array;

	/**
	 * Search across multiple register+schema tables simultaneously.
	 *
	 * This method performs a cross-table search by:
	 * 1. Querying each register+schema table separately with fuzzy search.
	 * 2. Combining results using array merge (since we can't easily UNION with different schemas).
	 * 3. Sorting by relevance score globally.
	 *
	 * @param array $query Search parameters including _search term.
	 * @param array $registerSchemaPairs Array of ['register' => Register, 'schema' => Schema] pairs.
	 *
	 * @return array Array of ObjectEntity objects from all tables, sorted by relevance.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-cross-table-searches-honour-the-recent-lens-like-one-schema
	 */
	public function searchAcrossMultipleTables(array $query, array $registerSchemaPairs): array;

	/**
	 * Count every row a cross-table search matches, before paging.
	 *
	 * Follows the search's own path, so the total and the rows agree on
	 * every filter: the UNION path is counted with one `SUM` over `COUNT(*)`
	 * arms built from the same WHERE as its search arms (one statement per
	 * UNION_ARM_BATCH_SIZE tables), the sequential path by summing the
	 * single-table count it pages with.
	 *
	 * @param array $query               Search parameters (`_limit` and `_offset` are ignored).
	 * @param array $registerSchemaPairs Array of ['register' => Register, 'schema' => Schema] pairs.
	 *
	 * @return int The number of matching rows across all tables.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-limit-supports-an-explicit-unlimited-value
	 */
	public function countAcrossMultipleTables(array $query, array $registerSchemaPairs): int;

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
	 * What the last single-object write actually did.
	 *
	 * `create` or `update` — as decided by THIS layer's lookup, which is the
	 * one that ran last and therefore the one that matches the row. A caller
	 * that labelled the operation from its own earlier lookup should prefer
	 * this for anything it records, because between the two lookups a
	 * concurrent writer can turn a create into an update.
	 *
	 * Empty before any write in this request.
	 *
	 * @return string `create`, `update`, or ''.
	 */
	public function getLastWriteAction(): string;

	/**
	 * Convert database row to ObjectEntity.
	 *
	 * This method is public to allow bulk handlers to convert rows for event dispatching.
	 * Delegates to MagicStatisticsHandler.
	 *
	 * @param array $row Database row
	 * @param Register $_register Register context
	 * @param Schema $_schema Schema context
	 *
	 * @return ObjectEntity|null Converted entity or null on failure
	 */
	public function convertRowToObjectEntity(array $row, Register $_register, Schema $_schema): ?ObjectEntity;

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
	 * @param string $tableName The magic table.
	 * @param array $currentColumns Live columns, keyed by physical column name.
	 * @param array $requiredColumns Desired columns, keyed by property name.
	 *
	 * @return array<string> The column names actually retyped.
	 *
	 * @spec exclude magic-table DDL sync
	 */
	public function migrateRelationColumnsToVarchar(string $tableName, array $currentColumns, array $requiredColumns): array;

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
	 * @param string $tableName       The magic table (without prefix).
	 * @param array  $currentColumns  Live columns, keyed by physical column name.
	 * @param array  $requiredColumns Desired columns, keyed by property name.
	 *
	 * @return array<string> The column names widened.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-column-holds-every-value-its-property-accepts-req-mtc-001
	 */
	public function widenDecimalColumns(string $tableName, array $currentColumns, array $requiredColumns): array;

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

	/**
	 * Update table indexes
	 *
	 * @param string $tableName The table name
	 * @param Register $register The register context
	 * @param Schema $schema The schema for index analysis
	 *
	 * @return void
	 */
	public function updateTableIndexes(string $tableName, Register $register, Schema $schema): void;

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
	 * Get all existing register+schema tables
	 *
	 * This method scans the database for all tables matching our naming pattern
	 * and returns them as an array of register+schema combinations.
	 *
	 * @return (int|string)[][] Array of ['registerId' => int, 'schemaId' => int, 'tableName' => string].
	 */
	public function getExistingRegisterSchemaTables(): array;

	/**
	 * Check if MagicMapper is enabled for a register+schema combination
	 *
	 * @param Register $_register The register to check
	 * @param Schema $schema The schema to check
	 *
	 * @return bool True if MagicMapper should be used for this register+schema
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
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
	 * Find object in register+schema table by identifier (ID, UUID, slug, or URI).
	 *
	 * This method provides ObjectEntity compatibility for the public find() method.
	 *
	 * @param string|int $identifier Object identifier (ID, UUID, slug, or URI).
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param bool $_rbac Whether to apply RBAC.
	 * @param bool $_multitenancy Whether to apply multi-tenancy.
	 * @param bool $includeDeleted Whether to include soft-deleted objects.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found.
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple objects found.
	 *
	 * @return ObjectEntity The found object.
	 */
	public function findInRegisterSchemaTable(
		string|int $identifier, Register $register, Schema $schema, bool $_rbac = true, bool $_multitenancy = true,
		bool $includeDeleted = false
	): ObjectEntity;

	/**
	 * The live objects whose slug starts with a prefix, as slug => uuid pairs.
	 *
	 * For the seed import to find an object stored under a suffixed slug (`<slug>-<14 digits>`);
	 * the caller keeps the rows that really are a suffixed variant. No RBAC or multitenancy
	 * filter, as for every slug uniqueness query.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 * @param string   $prefix   The slug prefix, taken literally.
	 * @param int      $limit    Most rows to return.
	 *
	 * @return list<array{slug: string, uuid: string}> The matches.
	 */
	public function findSlugsByPrefix(Register $register, Schema $schema, string $prefix, int $limit=50): array;

	/**
	 * The slug an object keeps when a move or migration takes it to another register or schema (#4037).
	 *
	 * Resolved lazily, as on restore: the slug handler needs this mapper.
	 *
	 * @param ObjectEntity $entity   The object being moved (still carrying its uuid).
	 * @param Register     $register The target register.
	 * @param Schema       $schema   The target schema.
	 *
	 * @return string|null The slug to store at the target.
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function slugForMove(ObjectEntity $entity, Register $register, Schema $schema): ?string;

	/**
	 * Return the uuids of live objects that hold a slug in one register+schema table.
	 *
	 * No RBAC or multitenancy filter: uniqueness is a property of the data, so a
	 * caller who cannot see the holder must still not take its slug. Soft-deleted
	 * rows hold no slug, as on the read path.
	 *
	 * @param Register    $register    The register.
	 * @param Schema      $schema      The schema.
	 * @param string      $slug        The slug to look for.
	 * @param string|null $excludeUuid The object being saved, which never collides with itself.
	 *
	 * @return list<string> The holders' uuids (at most one: enough to know the slug is taken).
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function findSlugHolders(Register $register, Schema $schema, string $slug, ?string $excludeUuid=null): array;

	/**
	 * Load the slug state a bulk save or a backfill decides against, in two queries.
	 *
	 * `holders` maps each requested slug to the live uuids holding it; `stored`
	 * maps each requested uuid to the slug its live row holds now (null when the
	 * row has none, absent when the row does not exist yet). The IN lists are
	 * split into chunks of 1,000 values. No access filter, as findSlugHolders().
	 *
	 * @param Register      $register The register.
	 * @param Schema        $schema   The schema.
	 * @param list<string>  $slugs    Candidate slugs.
	 * @param list<string>  $uuids    Uuids of the rows being decided.
	 *
	 * @return array{holders: array<string, list<string>>, stored: array<string, string|null>}
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
	 */
	public function findSlugState(Register $register, Schema $schema, array $slugs, array $uuids): array;

	/**
	 * List every slug held by more than one live object in a register+schema table.
	 *
	 * Slugs are compared case-folded; the report is keyed by the lower-cased slug.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 *
	 * @return array<string, list<string>> Slug => holder uuids, oldest first.
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	public function findDuplicateSlugs(Register $register, Schema $schema): array;

	/**
	 * Every slug held by a live object in a register+schema table, with its holders.
	 *
	 * Only `_uuid` and `_slug` are read, so this stays cheap on large tables.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 *
	 * @return array<string, list<string>> Slug => holder uuids.
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	public function findAllSlugs(Register $register, Schema $schema): array;

	/**
	 * Page over the live rows of a register+schema table for the slug backfill.
	 *
	 * Keyset-paginated on `_id` (stable while the run writes). Returns the uuid,
	 * the stored slug, `_created` for the oldest-first ordering the caller applies,
	 * and the business data the slug is built from.
	 *
	 * A row that cannot be converted into an object is left out of `rows` and
	 * logged with its uuid, but still counts in `fetched` and moves `lastId`: the
	 * caller pages on what the database returned, so one unreadable row neither
	 * ends the scan early nor is read again.
	 *
	 * @param Register $register   The register.
	 * @param Schema   $schema     The schema.
	 * @param int      $afterId    Last `_id` of the previous page (0 for the first).
	 * @param int      $pageSize   Rows per page.
	 *
	 * @return array{
	 *     rows: list<array{id: int, uuid: string, slug: string|null, created: string|null, object: array}>,
	 *     fetched: int,
	 *     lastId: int
	 * } The convertible rows, how many rows the query returned, and the `_id` of the last one (`$afterId` when none).
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	public function findSlugBackfillPage(Register $register, Schema $schema, int $afterId, int $pageSize): array;

	/**
	 * Write one object's slug column directly, for the slug backfill.
	 *
	 * No version bump, audit-trail entry or object event: the slug is derived
	 * metadata, the same as openregister:backfill-system-owner (design D11).
	 *
	 * Only when the row still has no slug (null or empty), so a save that set
	 * the slug while the backfill was planning is not undone.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 * @param string   $uuid     The object.
	 * @param string   $slug     The new slug.
	 *
	 * @return int Rows updated: 0 when the row changed in the meantime.
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	public function updateSlugColumn(Register $register, Schema $schema, string $uuid, string $slug): int;

	/**
	 * Find objects by a list of uuids within ONE register+schema table.
	 *
	 * The batched counterpart of {@see findInRegisterSchemaTable()}: one indexed
	 * `IN` query answers "which of these uuids already exist here" for a whole
	 * batch, where the single-object method answers it one round trip at a time.
	 *
	 * Bulk save needs exactly this question — it has to classify every row of a
	 * batch as create or update before it writes any of them — and asking it per
	 * row made the create/update probe the dominant cost of a bulk write, and
	 * O(N) queries in the one code path whose entire purpose is to avoid them.
	 *
	 * Deliberately bounded to the given register+schema. The unbounded
	 * equivalent ({@see findMultipleAcrossAllMagicTables()}) unions every magic
	 * table on the instance, which answers a DIFFERENT question — "does this
	 * uuid exist anywhere" — and is the wrong one when the caller is deciding
	 * how to write into one specific table.
	 *
	 * No access-control filters are applied: this is an existence probe for
	 * callers that then run their own per-row RBAC check against the returned
	 * entity's owner, exactly as the per-row lookup it replaces did with
	 * `_rbac: false, _multitenancy: false`.
	 *
	 * @param Register $register The register whose table to search.
	 * @param Schema $schema The schema whose table to search.
	 * @param array<int, string> $uuids The uuids to look for.
	 * @param bool $includeDeleted Whether to include soft-deleted rows.
	 *
	 * @return array<string, ObjectEntity> Found entities keyed by uuid; missing uuids are simply absent.
	 */
	public function findObjectsByUuidsInRegisterSchema(Register $register, Schema $schema, array $uuids, bool $includeDeleted = false): array;

	/**
	 * Find an object across all magic tables without knowing register/schema upfront.
	 *
	 * This method searches all existing magic tables for an object by its identifier.
	 * It's useful for operations like lock/unlock where the caller doesn't know
	 * which storage backend contains the object.
	 *
	 * @param string|int $identifier Object identifier (ID, UUID, slug, or URI).
	 * @param bool $includeDeleted Whether to include deleted objects.
	 * @param bool $_rbac Whether to apply RBAC checks.
	 * @param bool $_multitenancy Whether to apply multitenancy filtering.
	 * @param int|null $registerIdScope Restrict the scan to one register's tables.
	 *                                  A caller that named a register has already
	 *                                  said where the object lives; widening the
	 *                                  search past it is never a correct answer,
	 *                                  and it is what makes this method cost
	 *                                  seconds. Null keeps the historic
	 *                                  everything-everywhere behaviour.
	 *
	 * @return array{object: ObjectEntity, register: Register|null, schema: Schema|null}
	 *                                                                                   The found object with its register and schema context.
	 *
	 * @throws DoesNotExistException If object not found in any magic table.
	 *
	 * @spec openspec/changes/object-write-sub-500ms/specs/object-write-performance/spec.md
	 */
	public function findAcrossAllMagicTables(
		string|int $identifier, bool $includeDeleted = false, bool $_rbac = true, bool $_multitenancy = true, ?int $registerIdScope = null
	): array;

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
	 * Find multiple objects by UUIDs across ALL magic tables.
	 *
	 * This method efficiently searches all magic tables for multiple UUIDs in batch.
	 * It's optimized for performance by searching all UUIDs in each table with a single query.
	 *
	 * @param array    $uuids           Array of UUIDs to search for.
	 * @param bool     $includeDeleted  Whether to include soft-deleted objects.
	 * @param int|null $registerIdScope Only look in this register's tables (null: every table).
	 *
	 * @return ObjectEntity[] Array of found objects (may be fewer than requested if some not found).
	 */
	public function findMultipleAcrossAllMagicTables(array $uuids, bool $includeDeleted = false, ?int $registerIdScope = null): array;

	/**
	 * Find objects by uuid across all magic tables, each with every stored column.
	 *
	 * @param array $uuids          The uuids to search for.
	 * @param bool  $includeDeleted Whether to include soft-deleted objects.
	 *
	 * @return ObjectEntity[] The found objects (may be fewer than requested).
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function findStoredAcrossAllMagicTables(array $uuids, bool $includeDeleted = false): array;

	/**
	 * Find all soft-deleted objects across ALL magic tables.
	 *
	 * Objects live in per-register/schema magic tables, so there is no single
	 * table to query for `/api/deleted`. This scans every magic table for rows
	 * whose `_deleted` marker is set and returns them newest-first. Replaces the
	 * broken register/schema-less `searchObjectsPaginated()` path which always
	 * fell through to an empty result.
	 *
	 * Each table is narrowed IN THE QUERY to the rows the caller may read, with
	 * the same organisation and RBAC filters the object list applies
	 * ({@see MagicSearchHandler::applyAccessControlToQuery()}), so a trashed
	 * object is never shown to someone who could not read it before it was
	 * deleted, and the total the count answers matches the pages this returns
	 * (openregister#4078). A table whose schema cannot be resolved is skipped:
	 * whether the caller may read it cannot be answered, and the answer is no.
	 *
	 * @param int|null $limit Maximum rows to return.
	 * @param int|null $offset Rows to skip (pagination).
	 * @param bool $_rbac Apply the schema's read rules for the caller (false only for an admin or system caller).
	 * @param bool $_multitenancy Apply the organisation boundary for the caller.
	 *
	 * @return ObjectEntity[] Soft-deleted objects across all magic tables.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function findDeletedAcrossAllMagicTables(?int $limit = null, ?int $offset = null, bool $_rbac = true, bool $_multitenancy = true): array;

	/**
	 * The file ids of the object folders that belong to one organisation.
	 *
	 * Scans every magic table for live rows whose `_organisation` is the given
	 * uuid and returns their distinct numeric `_folder` values. Not narrowed to
	 * a caller: it measures the organisation, and the caller decides who may
	 * see the measurement.
	 *
	 * @param string $organisationUuid The organisation uuid.
	 *
	 * @return list<int> The folder file ids.
	 *
	 * @throws RuntimeException When a table could not be read: a sum over the tables that could be read would
	 *                           be too low and look measured, so the whole measurement fails (and is logged).
	 *
	 * @spec openspec/specs/organisation-usage-measured/spec.md
	 */
	public function findFolderIdsByOrganisation(string $organisationUuid): array;

	/**
	 * Count an organisation's live objects across the magic tables, of the
	 * given schemas only when ids are given.
	 *
	 * A table that cannot be read fails the count after the scan, naming the
	 * tables, so a caller never shows a figure that is too low.
	 *
	 * @param string          $organisationUuid The organisation.
	 * @param array<int, int> $schemaIds        The schemas to count; empty counts every schema.
	 *
	 * @return int The number of live objects.
	 *
	 * @throws RuntimeException When a table could not be read.
	 *
	 * @spec openspec/specs/organisation-quota/spec.md#requirement-an-organisation-quota-limits-its-users-and-its-objects
	 */
	public function countObjectsByOrganisation(string $organisationUuid, array $schemaIds=[]): int;

	/**
	 * Count all soft-deleted objects across ALL magic tables.
	 *
	 * Narrowed per table exactly as {@see findDeletedAcrossAllMagicTables()}
	 * is, so the total never counts a row the listing would not return.
	 *
	 * @param bool $_rbac Apply the schema's read rules for the caller (false only for an admin or system caller).
	 * @param bool $_multitenancy Apply the organisation boundary for the caller.
	 *
	 * @return int Total soft-deleted object count.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function countDeletedAcrossAllMagicTables(bool $_rbac = true, bool $_multitenancy = true): int;

	/**
	 * Find all objects across ALL magic tables that have the given UUID in their relations.
	 *
	 * This method searches across all magic tables to find objects that reference the given UUID.
	 * Relations are stored as JSON objects like {"fieldName": "uuid", ...}.
	 *
	 * @param string $uuid The UUID to search for in relations.
	 * @param bool $includeDeleted Whether to include deleted objects.
	 *
	 * @return ObjectEntity[] Array of found ObjectEntity objects.
	 */
	public function findByRelationAcrossAllMagicTables(string $uuid, bool $includeDeleted = false): array;

	/**
	 * Find all objects in register+schema table with filtering and pagination.
	 *
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param int|null $limit Maximum number of results.
	 * @param int|null $offset Offset for pagination.
	 * @param array|null $filters Filters to apply.
	 * @param array $sort Sort order.
	 *
	 * @return ObjectEntity[]
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	public function findAllInRegisterSchemaTable(
		Register $register, Schema $schema, ?int $limit = null, ?int $offset = null, ?array $filters = null, array $sort = []
	): array;

	/**
	 * The row-level access predicate for a raw query over one magic table.
	 *
	 * For a caller that writes its own SQL against the table (the aggregation
	 * fast path) and must count the rows a list read would return, no more.
	 * The predicate is {@see MagicRbacHandler::buildRbacRowPredicateSql()}'s, so
	 * the list and the raw query cannot drift apart. Columns are unqualified:
	 * the query must read the magic table without an alias.
	 *
	 * @param Schema        $schema   The schema of the table being read.
	 * @param string        $action   The action the rows are filtered for (default: 'read').
	 * @param Register|null $register The register of the table, so a metadata column the table could not
	 *                                be given is left out of the predicate. Null keeps every predicate.
	 *
	 * @return string|null Null when no row restriction applies (an admin); otherwise
	 *                     a predicate safe to AND into a WHERE, `1 = 0` for deny-all.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function rbacRowPredicateSql(Schema $schema, string $action = 'read', ?Register $register = null): ?string;

	/**
	 * Whether the current caller qualifies for at least one rule of an action.
	 *
	 * @param Schema $schema The schema whose rules to consult.
	 * @param string $action The action (default: 'read').
	 *
	 * @return bool True when a rule for the action admits the caller.
	 *
	 * @see MagicRbacHandler::callerQualifiesForAction()
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function callerQualifiesForAction(Schema $schema, string $action = 'read'): bool;

	/**
	 * Whether a `public` read rule lifts the organisation filter for the current caller.
	 *
	 * @param Schema $schema The schema being read.
	 *
	 * @return bool True when the `public` rule may lift the organisation filter.
	 *
	 * @see MagicRbacHandler::publicReadLiftsOrganisationFilter()
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-when-inheritfrompublic-is-false-authenticated-users-must-not-qualify-for-public-rules
	 */
	public function publicReadLiftsOrganisationFilter(Schema $schema): bool;

	/**
	 * Whether a default read's organisation boundary carries a branch for the
	 * caller's conditional `crossOrganisation` rules.
	 *
	 * @param Schema $schema The schema being read.
	 *
	 * @return bool True when it does.
	 *
	 * @see MagicSearchHandler::organisationBoundaryHasCrossOrganisationBranch()
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-multi-tenancy-integration-with-scopes
	 */
	public function organisationBoundaryHasCrossOrganisationBranch(Schema $schema): bool;

	/**
	 * Whether a default list read waives the organisation boundary for this
	 * caller because RBAC governs the rows instead.
	 *
	 * @param Schema $schema The schema being read.
	 *
	 * @return bool True when the list would not apply the organisation filter.
	 *
	 * @see MagicSearchHandler::organisationBoundaryWaivedByRbac()
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function organisationBoundaryWaivedByRbac(Schema $schema): bool;

	/**
	 * Whether a default list read widens the organisation boundary by the
	 * rows with no organisation, because the caller's grant comes from the
	 * register cascade.
	 *
	 * @param Schema $schema The schema being read.
	 *
	 * @return bool True when the list's organisation filter also admits org-less rows.
	 *
	 * @see MagicSearchHandler::organisationlessRowsAdmittedByRbac()
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-register-level-authorization-cascade
	 */
	public function organisationlessRowsAdmittedByRbac(Schema $schema): bool;

	/**
	 * Whether a stored row lies inside the caller's organisations.
	 *
	 * Decided on the row's own organisation through the same scope the read
	 * filter renders, but without the read rules: a `public` read rule that
	 * lifts the organisation filter for reading does not widen this answer.
	 * The row's register and schema name the table, for shared master data.
	 *
	 * @param ObjectEntity $row The stored row.
	 *
	 * @return bool True when the caller's organisation scope holds the row.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function callerOrganisationsHold(ObjectEntity $row): bool;

	/**
	 * Insert ObjectEntity into register+schema table.
	 *
	 * @param ObjectEntity $entity The object entity to insert.
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param bool $dispatchEvents Whether to dispatch events.
	 * @param bool $failIfExists Insert-only: refuse instead of updating when the identifier is taken.
	 *
	 * @throws Exception If insertion fails.
	 *
	 * @return ObjectEntity The inserted object entity.
	 */
	public function insertObjectEntity(
		ObjectEntity $entity, Register $register, Schema $schema, bool $dispatchEvents = true, bool $failIfExists = false
	): ObjectEntity;

	/**
	 * Update ObjectEntity in register+schema table.
	 *
	 * @param ObjectEntity $entity The object entity to update.
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param ObjectEntity|null $oldEntity The old entity for comparison.
	 *
	 * @throws Exception If update fails.
	 *
	 * @return ObjectEntity The updated object entity.
	 */
	public function updateObjectEntity(ObjectEntity $entity, Register $register, Schema $schema, ?ObjectEntity $oldEntity = null): ObjectEntity;

	/**
	 * Ask the delete guards whether an object may be deleted.
	 *
	 * Dispatches the stoppable ObjectDeletingEvent, on which the frozen, the
	 * working-calendar and the concept guards listen. Both the permanent and
	 * the soft delete ask it before they write.
	 *
	 * @param ObjectEntity $entity The object about to be deleted.
	 *
	 * @return void
	 *
	 * @throws HookStoppedException When a listener stops the delete.
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-frozen-record-cannot-be-deleted
	 */
	public function assertDeletable(ObjectEntity $entity): void;

	/**
	 * Delete ObjectEntity from register+schema table.
	 *
	 * Supports both soft delete (sets _deleted field) and hard delete (removes from table).
	 *
	 * @param ObjectEntity $entity The object entity to delete.
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param bool $hardDelete Whether to perform hard delete (default: false for soft delete).
	 * @param bool $dispatchEvents Whether to dispatch events.
	 *
	 * @throws Exception If deletion fails.
	 *
	 * @return ObjectEntity The deleted object entity.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Hard delete toggle controls permanent vs soft delete
	 */
	public function deleteObjectEntity(
		ObjectEntity $entity, Register $register, Schema $schema, bool $hardDelete = false, bool $dispatchEvents = true
	): ObjectEntity;

	/**
	 * Soft-delete multiple pre-resolved objects with ONE UPDATE per magic table.
	 *
	 * PERF: the per-object soft-delete path re-serialises and rewrites the whole
	 * row (updateObjectEntity → saveObjectToRegisterSchemaTable) and re-fetches
	 * it afterwards — for a cascade of N children that is ~5 queries per child.
	 * This method batches children of the same (register, schema) pair into a
	 * single `UPDATE ... SET _deleted = CASE _uuid ... END WHERE _uuid IN (...)`
	 * statement while preserving the per-object event contract:
	 *
	 * - `ObjectUpdatingEvent` is dispatched per entity BEFORE the write; a hook
	 *   that stops propagation skips that entity (parity with the per-object
	 *   path, where HookStoppedException aborted the child's delete).
	 * - A hook that modifies the payload routes that entity through the
	 *   full-row save so the modification persists (the batched UPDATE only
	 *   writes the deleted marker).
	 * - `ObjectUpdatedEvent` is dispatched per entity AFTER the write.
	 *
	 * The caller must set the `deleted` metadata on every entity beforehand
	 * (its JSON is what the batched UPDATE writes) — deletion attribution is
	 * the caller's domain, persistence is this mapper's.
	 *
	 * @param array $entities ObjectEntity list to soft-delete
	 *                        (validated at runtime); uuid, numeric
	 *                        register/schema ids and `deleted`
	 *                        metadata must be set on every entity.
	 * @param array<string, ObjectEntity> $oldEntities Optional pre-delete snapshots keyed
	 *                                                 by uuid, used as event old-objects.
	 *                                                 Missing snapshots are derived by
	 *                                                 cloning the (mutated) entity.
	 *
	 * @return ObjectEntity[] The entities that were actually soft-deleted.
	 *
	 * @throws Exception If a batched UPDATE fails for every table.
	 *
	 * @psalm-return list<ObjectEntity>
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Hook handling requires per-entity branching
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Hook handling requires per-entity branching
	 */
	public function softDeleteMultipleObjectEntities(array $entities, array $oldEntities = []): array;

	/**
	 * Delete all objects belonging to a specific schema from the magic table.
	 *
	 * This method performs an optimized bulk deletion from the magic table
	 * for all objects belonging to a given register/schema combination.
	 *
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param bool $hardDelete Whether to perform a hard delete (default: false for soft delete).
	 *
	 * @throws Exception If deletion fails.
	 *
	 * @return int The number of objects deleted.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Hard delete toggle controls permanent vs soft delete
	 */
	public function deleteObjectsBySchema(Register $register, Schema $schema, bool $hardDelete = false): int;

	/**
	 * Hard-delete the rows of a register+schema table whose `_expires` has passed.
	 *
	 * The sweep for rows written through bulkUpsert() with an expiry, i.e. raw
	 * appends. There is no soft-delete and no audit entry: those rows never had
	 * either, and keeping expired telemetry as tombstones would defeat the
	 * retention the expiry expresses. Rows with a NULL `_expires` are never
	 * touched. A table that does not exist yet has nothing to purge and answers 0.
	 *
	 * @param Register $register The register context.
	 * @param Schema   $schema   The schema context.
	 *
	 * @return int The number of rows removed.
	 *
	 * @throws Exception If the delete fails.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-raw-append-for-high-volume-writers
	 */
	public function purgeExpired(Register $register, Schema $schema): int;

	/**
	 * Batch delete objects by UUID list from a register+schema magic table.
	 *
	 * Performs a single SQL statement for all UUIDs instead of one-by-one.
	 * Supports both soft delete (sets _deleted metadata) and hard delete.
	 *
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param array $uuids Array of object UUIDs to delete.
	 * @param bool $hardDelete Whether to hard delete (default: soft delete).
	 *
	 * @return int Number of objects deleted.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 */
	public function deleteObjectsByUuids(Register $register, Schema $schema, array $uuids, bool $hardDelete = false): int;

	/**
	 * Lock object in register+schema table.
	 *
	 * @param ObjectEntity $entity The object entity to lock.
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param int|null $lockDuration Lock duration in seconds (null for default).
	 * @param string|null $process What the lock was taken for.
	 * @param string|null $runUuid Flow run taking the lock, for a run-scoped lock.
	 *
	 * @throws Exception If locking fails.
	 *
	 * @return ObjectEntity The locked object entity.
	 */
	public function lockObjectEntity(
		ObjectEntity $entity, Register $register, Schema $schema, ?int $lockDuration = null, ?string $process = null,
		?string $runUuid = null
	): ObjectEntity;

	/**
	 * Unlock object in register+schema table.
	 *
	 * @param ObjectEntity $entity The object entity to unlock.
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param string|null $runUuid Flow run releasing the lock, for a run-scoped lock.
	 * @param bool $break Release regardless of holder (administrator break-lock).
	 *
	 * @throws Exception If unlocking fails.
	 *
	 * @return ObjectEntity The unlocked object entity.
	 */
	public function unlockObjectEntity(
		ObjectEntity $entity, Register $register, Schema $schema, ?string $runUuid = null, bool $break = false
	): ObjectEntity;

	/**
	 * Perform bulk upsert operation on register+schema table
	 *
	 * This method provides high-performance bulk insert/update operations for dynamic tables.
	 * It delegates to MagicBulkHandler for the actual database operations.
	 *
	 * @param array $objects Array of object data in standard format
	 * @param Register $register Register context
	 * @param Schema $schema Schema context
	 * @param string $tableName Target table name
	 * @param bool $needsPreUpdateState Whether anything downstream will read the
	 *                                  pre-update rows (audit changeset or update
	 *                                  event). False narrows the fetch to the uuid.
	 *
	 * @return array[] Array of complete objects with object_status field
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 *
	 * @psalm-return list<array<string, mixed>>
	 */
	public function bulkUpsert(array $objects, Register $register, Schema $schema, string $tableName, bool $needsPreUpdateState = true): array;

	/**
	 * Find objects that reference a specific UUID in any of their columns.
	 *
	 * This method searches across ALL magic mapper tables for objects that
	 * contain the specified UUID in any column. This is used for inverse
	 * relationship resolution.
	 *
	 * For PostgreSQL, it uses casting to text and LIKE for JSON columns.
	 * For other databases, it uses LIKE on all columns.
	 *
	 * @param string $uuid The UUID to search for
	 * @param string|null $_search Optional search filter
	 * @param bool $_partialMatch Whether to use partial matching
	 *
	 * @return ObjectEntity[] Array of objects that contain the UUID
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	public function findByRelation(string $uuid, ?string $_search = null, bool $_partialMatch = false): array;

	/**
	 * Find objects that reference a UUID using the _relations column.
	 *
	 * This is a PERFORMANT alternative to findByRelation() that uses
	 * PostgreSQL's JSONB @> operator on the indexed _relations column
	 * instead of slow full-text LIKE searches.
	 *
	 * The _relations column stores an array of UUIDs that the object references,
	 * making containment queries very efficient (O(log n) with GIN index).
	 *
	 * @param string $uuid The UUID to search for in _relations
	 *
	 * @return ObjectEntity[] Array of objects that have this UUID in their _relations
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	public function findByRelationUsingRelationsColumn(string $uuid): array;

	/**
	 * Find objects in a specific schema's magic table that have a given entity ID in a linked type column.
	 *
	 * Used for reverse lookups: "find all objects linked to mail 1/6".
	 * The linked type columns (_mail, _contacts, etc.) store JSON arrays of string IDs.
	 *
	 * @param Schema $schema The schema whose magic table to search
	 * @param string $columnName The column name (e.g., '_mail', '_contacts')
	 * @param string $entityId The entity ID to search for
	 *
	 * @return array Array of ObjectEntity objects
	 */
	public function findByLinkedEntity(Schema $schema, string $columnName, string $entityId): array;

	/**
	 * Batch find objects in a specific schema that reference ANY of the given UUIDs.
	 *
	 * This is a CRITICAL performance optimization for inverse relationship preloading.
	 * Instead of N queries (one per entity), we do ONE query per target schema to find
	 * ALL objects that reference ANY of our entities.
	 *
	 * Uses the _relations GIN index for efficient containment queries.
	 *
	 * @param array $uuids Array of UUIDs to search for in _relations.
	 * @param int $schemaId The target schema ID to search in.
	 * @param int $registerId The register ID for the magic table.
	 * @param string $fieldName The field name to check (for logging/debugging).
	 * @param array $additionalFieldNames Additional field names to check.
	 *
	 * @return ObjectEntity[] Array of objects that have ANY of the UUIDs in _relations
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	public function findByRelationBatchInSchema(
		array $uuids, int $schemaId, int $registerId, string $fieldName, array $additionalFieldNames = []
	): array;

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
	 * Find an object entity by identifier (ID, UUID, slug, or URI).
	 *
	 * When register+schema context is available, searches the specific magic table.
	 * Otherwise searches across all magic tables.
	 *
	 * @param string|int $identifier Object identifier (ID, UUID, slug, or URI).
	 * @param Register|null $register Optional register to filter by.
	 * @param Schema|null $schema Optional schema to filter by.
	 * @param bool $includeDeleted Whether to include deleted objects.
	 * @param bool $_rbac Whether to apply RBAC checks (default: true).
	 * @param bool $_multitenancy Whether to apply multitenancy filtering (default: true).
	 *
	 * @return ObjectEntity The found object.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found.
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple objects found.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
	 */
	public function find(
		string|int $identifier, ?Register $register = null, ?Schema $schema = null, bool $includeDeleted = false, bool $_rbac = true,
		bool $_multitenancy = true
	): ObjectEntity;

	/**
	 * Find an object across all storage sources (all magic tables).
	 *
	 * Searches all magic tables to find an object by its identifier without
	 * requiring register/schema context.
	 *
	 * @param string|int $identifier Object identifier (ID, UUID, slug, or URI).
	 * @param bool $includeDeleted Whether to include deleted objects.
	 * @param bool $_rbac Whether to apply RBAC checks.
	 * @param bool $_multitenancy Whether to apply multitenancy filtering.
	 * @param int|null $registerIdScope Restrict the scan to one register's tables.
	 *
	 * @return array{object: ObjectEntity, register: Register|null, schema: Schema|null}
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found in any source.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
	 */
	public function findAcrossAllSources(
		string|int $identifier, bool $includeDeleted = false,
		// phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- named arg convention.
		bool $_rbac = true,
		// phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- named arg convention.
		bool $_multitenancy = true,
		?int $registerIdScope = null
	): array;

	/**
	 * Find all ObjectEntities with filtering, pagination, and search.
	 *
	 * @param int|null $limit The number of objects to return.
	 * @param int|null $offset The offset of the objects to return.
	 * @param array|null $filters The filters to apply to the objects.
	 * @param array|null $searchConditions The search conditions to apply to the objects.
	 * @param array|null $searchParams The search parameters to apply to the objects.
	 * @param array $sort The sort order to apply.
	 * @param string|null $search The search string to apply.
	 * @param array|null $ids Array of IDs or UUIDs to filter by.
	 * @param string|null $uses Value that must be present in relations.
	 * @param bool $includeDeleted Whether to include deleted objects.
	 * @param Register|null $register Optional register to filter objects.
	 * @param Schema|null $schema Optional schema to filter objects.
	 *
	 * @return ObjectEntity[]
	 *
	 * @throws \OCP\DB\Exception If a database error occurs.
	 *
	 * @psalm-return list<ObjectEntity>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    Include deleted toggle is intentional
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Required for flexible query interface
	 */
	public function findAll(
		?int $limit = null, ?int $offset = null, ?array $filters = null, ?array $searchConditions = null, ?array $searchParams = null,
		array $sort = [], ?string $search = null, ?array $ids = null, ?string $uses = null, bool $includeDeleted = false,
		?Register $register = null, ?Schema $schema = null
	): array;

	/**
	 * Find multiple objects by their IDs or UUIDs.
	 *
	 * @param array $ids Array of IDs or UUIDs.
	 *
	 * @return ObjectEntity[]
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	public function findMultiple(array $ids): array;

	/**
	 * Which of these UUIDs, all living in one register+schema table, may the caller read?
	 *
	 * The answer comes from the same access-control filter the single-object read
	 * applies (applyAccessControlToQuery: RBAC plus multitenancy, with every rule
	 * that widens or narrows them), so a name lookup can never disagree with
	 * `GET /api/objects/{register}/{schema}/{id}`. Soft-deleted rows are never
	 * readable here. Any failure answers "none": a lookup that cannot establish
	 * read access must not disclose.
	 *
	 * @param int           $registerId The register the table belongs to.
	 * @param int           $schemaId   The schema the table belongs to.
	 * @param array<string> $uuids      UUIDs to test, all expected in that table.
	 *
	 * @return array<int, string> The subset of $uuids the caller may read.
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	public function filterReadableUuids(int $registerId, int $schemaId, array $uuids): array;

	/**
	 * The same filter as filterReadableUuids(), but a failure is told apart from "none".
	 *
	 * A caller that reports completeness (a tree that says which bound stopped
	 * it) must not read "read access could not be established" as "nothing is
	 * readable here": the first is an incomplete answer, the second is a
	 * complete one. Null means the check itself failed (and was logged);
	 * an empty array means it ran and the caller may read none of the uuids.
	 *
	 * @param int           $registerId The register the table belongs to.
	 * @param int           $schemaId   The schema the table belongs to.
	 * @param array<string> $uuids      UUIDs to test, all expected in that table.
	 *
	 * @return array<int, string>|null The readable subset, or null when read access could not be established.
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	public function readableUuidsOrNull(int $registerId, int $schemaId, array $uuids): ?array;

	/**
	 * Find all objects for a given schema.
	 *
	 * Searches across all magic tables that belong to the given schema.
	 *
	 * PERF-13: the optional $limit/$offset/$filter arguments are pushed down
	 * into SQL (via findAllInRegisterSchemaTable) so callers that only need a
	 * bounded/filtered slice no longer pull every row of the schema into PHP.
	 * They are optional and default to the previous unbounded full-table read,
	 * so existing callers are unaffected.
	 *
	 * @param int $schemaId Schema ID.
	 * @param int|null $limit Optional maximum number of rows (pushed to SQL).
	 * @param int|null $offset Optional rows to skip for pagination (pushed to SQL).
	 * @param array|null $filter Optional filter criteria (pushed to SQL).
	 *
	 * @return ObjectEntity[]
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	public function findBySchema(int $schemaId, ?int $limit = null, ?int $offset = null, ?array $filter = null): array;

	/**
	 * Insert a new object entity with event dispatching.
	 *
	 * @param Entity $entity Entity to insert.
	 * @param Register|null $register Optional register for routing.
	 * @param Schema|null $schema Optional schema for routing.
	 * @param bool $failIfExists Insert-only: refuse instead of updating when the identifier is taken.
	 *
	 * @return ObjectEntity Inserted entity.
	 *
	 * @throws Exception If insertion fails.
	 */
	public function insert(Entity $entity, ?Register $register = null, ?Schema $schema = null, bool $failIfExists = false): Entity;

	/**
	 * Update an existing object entity with event dispatching.
	 *
	 * @param Entity $entity Entity to update.
	 * @param Register|null $register Optional register for routing.
	 * @param Schema|null $schema Optional schema for routing.
	 * @param ObjectEntity|null $oldEntity Old entity for comparison.
	 *
	 * @return ObjectEntity Updated entity.
	 *
	 * @throws Exception If update fails.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	public function update(Entity $entity, ?Register $register = null, ?Schema $schema = null, ?ObjectEntity $oldEntity = null): Entity;

	/**
	 * Delete an object entity with event dispatching.
	 *
	 * @param Entity $entity Entity to delete.
	 *
	 * @return ObjectEntity Deleted entity.
	 *
	 * @throws Exception If deletion fails.
	 */
	public function delete(Entity $entity): Entity;

	/**
	 * Lock an object.
	 *
	 * @param string $uuid The object UUID
	 * @param int|null $lockDuration Lock duration in seconds
	 *
	 * @return array Lock result.
	 *
	 * @psalm-return array{locked: mixed, uuid: string}
	 */
	public function lockObject(string $uuid, ?int $lockDuration = null): array;

	/**
	 * Unlock an object.
	 *
	 * @param string $uuid The object UUID
	 *
	 * @return bool True on success
	 */
	public function unlockObject(string $uuid): bool;

	/**
	 * Restore a soft-deleted object by clearing its `_deleted` marker.
	 *
	 * Objects live in per-register/schema magic tables (oc_openregister_table_*),
	 * NOT in the legacy generic `openregister_objects` table. A direct
	 * `UPDATE openregister_objects` therefore matches zero rows — which is why
	 * the previous restore reported success but never un-deleted anything. This
	 * resolves the object's owning magic table (including deleted rows) and
	 * sets `_deleted = NULL` on the correct table.
	 *
	 * @param string $uuid The UUID (or id/slug) of the deleted object.
	 *
	 * @return ObjectEntity The restored object.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If the object is not found.
	 * @throws Exception If register/schema context cannot be resolved.
	 */
	public function restoreObject(string $uuid): ObjectEntity;

	/**
	 * Record the node id of an object's files folder as bookkeeping, not as an edit of the object.
	 *
	 * The files folder is made on demand, often by a request that only lists
	 * the object's files (a portal showing a case's documents). Storing its id
	 * through update() ran the whole save path for that read: the updating and
	 * updated events, so every save-time listener (calculations, quality
	 * scoring, retention, other apps' handlers) ran as whoever was reading,
	 * with no audit row and no version bump to show for it. This write touches
	 * the `_folder` column of the one row and nothing else, dispatches no
	 * lifecycle event, and only lands while the stored value is still empty or
	 * what the caller read, so it never repoints a folder another request
	 * recorded first. It mirrors RegisterFolderRecorder for registers.
	 *
	 * @param ObjectEntity $entity The object whose folder was made.
	 * @param string|null $expected The folder value read before the folder was made: null or '' for none,
	 *                              or the legacy path that no longer resolves.
	 * @param string $folderId The node id of the folder the file service made or found.
	 *
	 * @return bool True when this call recorded the id; false when another request recorded one first.
	 *
	 * @throws Exception If the object has no uuid or its register and schema cannot be resolved.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-recording-an-objects-folder-id-is-bookkeeping-req-ofib-001
	 */
	public function recordFolder(ObjectEntity $entity, ?string $expected, string $folderId): bool;

	/**
	 * Ultra-fast bulk save operation with automatic routing.
	 *
	 * Returns complete objects with database-computed classification (created/updated/unchanged).
	 *
	 * @param array $insertObjects Objects to insert/upsert
	 * @param array $updateObjects Objects to update (legacy parameter)
	 * @param Register|null $register Optional register context
	 * @param Schema|null $schema Optional schema context
	 * @param bool $needsPreUpdateState Whether anything downstream will read the
	 *                                  pre-update rows (audit changeset or update
	 *                                  event). False narrows the fetch to the uuid.
	 *
	 * @return array Array of complete objects with object_status field
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	public function ultraFastBulkSave(
		array $insertObjects = [], array $updateObjects = [], ?Register $register = null, ?Schema $schema = null,
		bool $needsPreUpdateState = true
	): array;

	/**
	 * Delete multiple objects.
	 *
	 * @param array $uuids Object UUIDs to delete
	 * @param bool $hardDelete Whether to hard delete
	 *
	 * @return array Delete results
	 *
	 * @psalm-return list<mixed>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Hard delete toggle controls permanent vs soft delete
	 */
	public function deleteObjects(array $uuids = [], bool $hardDelete = false): array;

	/**
	 * Get statistics for objects across all magic tables.
	 *
	 * @param int|array|null $registerId Register ID filter
	 * @param int|array|null $schemaId Schema ID filter
	 * @param array $exclude Exclusions
	 *
	 * @return int[] Statistics data
	 *
	 * @psalm-return array{total: int, size: int, invalid: int, deleted: int, locked: int}
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	public function getStatistics(int|array|null $registerId = null, int|array|null $schemaId = null, array $exclude = []): array;

	/**
	 * Get object statistics grouped by schema for multiple schemas.
	 *
	 * Returns per-schema statistics using one count query per register-schema pair,
	 * grouped by schema ID. Replaces N individual getStatistics() calls.
	 *
	 * @param int[] $schemaIds Array of schema IDs to get statistics for.
	 *
	 * @return array<int, array{total: int, size: int}> Map of schemaId => statistics array.
	 */
	public function getStatisticsGroupedBySchema(array $schemaIds): array;

	/**
	 * Get register chart data.
	 *
	 * @param int|null $registerId Register ID filter
	 * @param int|null $schemaId Schema ID filter
	 *
	 * @return (int|mixed|string)[][] Chart data
	 *
	 * @psalm-return array{labels: array<'Unknown'|mixed>, series: array<int>}
	 */
	public function getRegisterChartData(?int $registerId = null, ?int $schemaId = null): array;

	/**
	 * Get schema chart data.
	 *
	 * @param int|null $registerId Register ID filter
	 * @param int|null $schemaId Schema ID filter
	 *
	 * @return (int|mixed|string)[][] Chart data
	 *
	 * @psalm-return array{labels: array<'Unknown'|mixed>, series: array<int>}
	 */
	public function getSchemaChartData(?int $registerId = null, ?int $schemaId = null): array;

	/**
	 * Get simple facets.
	 *
	 * Routes to the correct faceting method based on query parameters.
	 *
	 * @param array $query Search query containing register, schema, and _facets configuration.
	 *
	 * @return array Facets data.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	public function getSimpleFacets(array $query = []): array;

	/**
	 * Get facetable fields from schemas.
	 *
	 * Collects facetable fields from all schemas referenced in the query.
	 *
	 * @param array $baseQuery Base query
	 *
	 * @return array[] Facetable fields
	 *
	 * @psalm-return array<string, array>
	 */
	public function getFacetableFieldsFromSchemas(array $baseQuery = []): array;

	/**
	 * Search objects.
	 *
	 * @param array $query Search query
	 * @param string|null $_activeOrgUuid Organisation UUID
	 * @param bool $_rbac Apply RBAC
	 * @param bool $_multitenancy Apply multitenancy
	 * @param array|null $ids Specific IDs
	 * @param string|null $uses Uses filter
	 *
	 * @return list<ObjectEntity>|int
	 *
	 * @psalm-suppress                              LessSpecificImplementedReturnType
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
	 */
	public function searchObjects(
		array $query = [], ?string $_activeOrgUuid = null, bool $_rbac = true, bool $_multitenancy = true, ?array $ids = null,
		?string $uses = null
	): array|int;

	/**
	 * Count search objects.
	 *
	 * @param array $query Search query
	 * @param string|null $_activeOrgUuid Organisation UUID
	 * @param bool $_rbac Apply RBAC
	 * @param bool $_multitenancy Apply multitenancy
	 * @param array|null $ids Specific IDs
	 * @param string|null $uses Uses filter
	 *
	 * @return int Object count
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
	 */
	public function countSearchObjects(
		array $query = [], ?string $_activeOrgUuid = null, bool $_rbac = true, bool $_multitenancy = true, ?array $ids = null,
		?string $uses = null
	): int;

	/**
	 * Count all objects with optional filtering.
	 *
	 * @param array|null $_filters Filters
	 * @param Schema|null $schema Schema filter
	 * @param Register|null $register Register filter
	 *
	 * @return int Object count
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	public function countAll(?array $_filters = null, ?Schema $schema = null, ?Register $register = null): int;

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

	/**
	 * Optimized paginated search that loads register/schema once and performs both search and count.
	 *
	 * @param array $searchQuery Query for search (with _limit, _offset).
	 * @param array $countQuery Query for count (without pagination).
	 * @param string|null $_activeOrgUuid Active organization UUID.
	 * @param bool $_rbac Whether to apply RBAC.
	 * @param bool $_multitenancy Whether to apply multitenancy.
	 * @param array|null $ids Optional ID filter.
	 * @param string|null $uses Optional uses filter.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Flags control security filtering behavior
	 *
	 * @spec openspec/changes/unified-search-index/specs/unified-search-provider/spec.md
	 */
	public function searchObjectsPaginated(
		array $searchQuery = [], array $countQuery = [], ?string $_activeOrgUuid = null, bool $_rbac = true, bool $_multitenancy = true,
		?array $ids = null, ?string $uses = null
	): array;

	/**
	 * Get size distribution chart data for objects.
	 *
	 * @param int|null $registerId Optional register ID filter.
	 * @param int|null $schemaId Optional schema ID filter.
	 *
	 * @return array{labels: list<string>, series: list<int>} Chart data.
	 */
	public function getSizeDistributionChartData(?int $registerId = null, ?int $schemaId = null): array;
}//end interface
