<?php

/**
 * MagicMapperInterface: reads and writes object rows in the per-schema tables.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use Exception;
use OCP\AppFramework\Db\Entity;
use RuntimeException;

/**
 * Finds, counts, searches, writes, locks and deletes object rows, and manages
 * the per register and schema tables that hold them. Implemented by Db\MagicMapper.
 *
 * @template TObject of ObjectRowInterface
 * @template TRegister of RegisterInterface
 * @template TSchema of SchemaInterface
 *
 * @SuppressWarnings(PHPMD.ExcessivePublicCount)  It mirrors the mapper's public surface that callers use.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)  It mirrors the mapper's public surface that callers use.
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   The mapper's flags are part of its signatures.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) findAll() mirrors the mapper signature.
 */
interface MagicMapperInterface extends ObjectTableLocatorInterface {

	/**
	 * Table name prefix for register+schema-specific tables.
	 *
	 * Does not include the 'oc_' prefix, which Nextcloud's QueryBuilder adds.
	 */
	public const TABLE_PREFIX = 'openregister_table_';

	/**
	 * Find an object entity by identifier (ID, UUID, slug, or URI).
	 *
	 * When register+schema context is available, searches the specific magic table.
	 * Otherwise searches across all magic tables.
	 *
	 * @param string|int $identifier Object identifier (ID, UUID, slug, or URI).
	 * @param RegisterInterface|null $register Optional register to filter by.
	 * @param SchemaInterface|null $schema Optional schema to filter by.
	 * @param bool $includeDeleted Whether to include deleted objects.
	 * @param bool $_rbac Whether to apply RBAC checks (default: true).
	 * @param bool $_multitenancy Whether to apply multitenancy filtering (default: true).
	 *
	 * @return TObject The found object.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found.
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple objects found.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function find(
		string|int $identifier,
		?RegisterInterface $register = null,
		?SchemaInterface $schema = null,
		bool $includeDeleted = false,
		bool $_rbac = true,
		bool $_multitenancy = true,
	);

	/**
	 * Update an existing object entity with event dispatching.
	 *
	 * @param Entity $entity Entity to update.
	 * @param RegisterInterface|null $register Optional register for routing.
	 * @param SchemaInterface|null $schema Optional schema for routing.
	 * @param ObjectRowInterface|null $oldEntity Old entity for comparison.
	 *
	 * @return Entity&TObject Updated entity.
	 *
	 * @throws Exception If update fails.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function update(
		Entity $entity,
		?RegisterInterface $register = null,
		?SchemaInterface $schema = null,
		?ObjectRowInterface $oldEntity = null,
	): Entity;

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
	 * @return array{object: TObject, register: TRegister|null, schema: TSchema|null}
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found in any source.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function findAcrossAllSources(
		string|int $identifier,
		bool $includeDeleted = false,
		// phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- named arg convention.
		bool $_rbac = true,
		// phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- named arg convention.
		bool $_multitenancy = true,
		?int $registerIdScope = null,
	): array;

	/**
	 * Find all objects in register+schema table with filtering and pagination.
	 *
	 * @param RegisterInterface $register The register context.
	 * @param SchemaInterface $schema The schema context.
	 * @param int|null $limit Maximum number of results.
	 * @param int|null $offset Offset for pagination.
	 * @param array|null $filters Filters to apply.
	 * @param array $sort Sort order.
	 *
	 * @return TObject[]
	 *
	 * @psalm-return list<TObject>
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function findAllInRegisterSchemaTable(
		RegisterInterface $register,
		SchemaInterface $schema,
		?int $limit = null,
		?int $offset = null,
		?array $filters = null,
		array $sort = [],
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
	 * @param RegisterInterface|null $register Optional register to filter objects.
	 * @param SchemaInterface|null $schema Optional schema to filter objects.
	 *
	 * @return TObject[]
	 *
	 * @throws \OCP\DB\Exception If a database error occurs.
	 *
	 * @psalm-return list<TObject>
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function findAll(
		?int $limit = null,
		?int $offset = null,
		?array $filters = null,
		?array $searchConditions = null,
		?array $searchParams = null,
		array $sort = [],
		?string $search = null,
		?array $ids = null,
		?string $uses = null,
		bool $includeDeleted = false,
		?RegisterInterface $register = null,
		?SchemaInterface $schema = null,
	): array;

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
	 * @return TObject[] Array of found objects (may be fewer than requested if some not found).
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function findMultipleAcrossAllMagicTables(
		array $uuids,
		bool $includeDeleted = false,
		?int $registerIdScope = null,
	): array;

	/**
	 * Check if register+schema table exists (with caching)
	 *
	 * @param RegisterInterface $register The register context
	 * @param SchemaInterface $schema The schema context
	 *
	 * @return bool True if table exists
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function tableExistsForRegisterSchema(RegisterInterface $register, SchemaInterface $schema): bool;

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
	 * @return list<TObject>|int
	 *
	 * @psalm-suppress                              LessSpecificImplementedReturnType
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function searchObjects(
		array $query = [],
		?string $_activeOrgUuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		?array $ids = null,
		?string $uses = null,
	): array|int;

	/**
	 * Delete an object entity with event dispatching.
	 *
	 * @param Entity $entity Entity to delete.
	 *
	 * @return Entity&TObject Deleted entity.
	 *
	 * @throws Exception If deletion fails.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function delete(Entity $entity): Entity;

	/**
	 * Count objects in a register+schema specific table.
	 *
	 * This method counts objects from a dedicated table based on register+schema combination.
	 * It supports basic filtering and returns only the count for better performance.
	 * A count that cannot be made answers 0 (logged); a caller that acts on the
	 * number uses countObjectsOrFail() instead.
	 *
	 * @param array    $query    Search parameters for filtering (excluding pagination).
	 * @param RegisterInterface $register The register context for table selection.
	 * @param SchemaInterface   $schema   The schema for table selection.
	 *
	 * @return int Count of matching objects.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function countObjectsInRegisterSchemaTable(array $query, RegisterInterface $register, SchemaInterface $schema): int;

	/**
	 * Count all objects with optional filtering.
	 *
	 * @param array|null $_filters Filters
	 * @param SchemaInterface|null $schema Schema filter
	 * @param RegisterInterface|null $register Register filter
	 *
	 * @return int Object count
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function countAll(?array $_filters = null, ?SchemaInterface $schema = null, ?RegisterInterface $register = null): int;

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
	 * @return TObject[] Array of objects that contain the UUID
	 *
	 * @psalm-return list<TObject>
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function findByRelation(string $uuid, ?string $_search = null, bool $_partialMatch = false): array;

	/**
	 * Create or update table for a specific register+schema combination
	 *
	 * This method analyzes the JSON schema and creates/updates the corresponding
	 * database table with appropriate columns, indexes, and constraints.
	 *
	 * @param RegisterInterface $register The register context for the table
	 * @param SchemaInterface $schema The schema to create/update table for
	 * @param bool $force Whether to force recreation even if table exists
	 *
	 * @throws Exception If table creation/update fails
	 *
	 * @return bool True if table was created/updated successfully
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function ensureTableForRegisterSchema(RegisterInterface $register, SchemaInterface $schema, bool $force = false): bool;

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
	 * @return TObject[]
	 *
	 * @psalm-return list<TObject>
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function findBySchema(int $schemaId, ?int $limit = null, ?int $offset = null, ?array $filter = null): array;

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
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function getStatistics(
		int|array|null $registerId = null,
		int|array|null $schemaId = null,
		array $exclude = [],
	): array;

	/**
	 * Every physical magic table, as bare names, whether or not a register still lists its schema.
	 *
	 * The reconcile command and the repair step walk this rather than the
	 * register to schema links, because a table whose schema a register no
	 * longer lists can still be queried and still lack a column.
	 *
	 * @return list<string> Bare table names (`openregister_table_<register>_<schema>`).
	 *
	 * @spec openspec/changes/magic-table-metadata-self-healing/specs/object-lifecycle/spec.md#requirement-reconcile-reports-and-repairs-missing-metadata-columns-req-mth-003
	 */
	public function listMagicTableNames(): array;

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
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function countSearchObjects(
		array $query = [],
		?string $_activeOrgUuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		?array $ids = null,
		?string $uses = null,
	): int;

	/**
	 * Build table columns from JSON schema properties
	 *
	 * This method analyzes the JSON schema and creates appropriate SQL column
	 * definitions for each property, plus all metadata columns from ObjectEntity.
	 *
	 * @param SchemaInterface $schema The schema to analyze
	 *
	 * @return (bool|int|mixed|null|string)[][] Column definitions.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function buildTableColumnsFromSchema(SchemaInterface $schema): array;

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
	 * @spec openspec/changes/unified-search-index/specs/unified-search-provider/spec.md
	 */
	public function searchObjectsPaginated(
		array $searchQuery = [],
		array $countQuery = [],
		?string $_activeOrgUuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		?array $ids = null,
		?string $uses = null,
	): array;

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
	 * @param RegisterInterface $register The register whose table to search.
	 * @param SchemaInterface $schema The schema whose table to search.
	 * @param array<int, string> $uuids The uuids to look for.
	 * @param bool $includeDeleted Whether to include soft-deleted rows.
	 *
	 * @return array<string, TObject> Found entities keyed by uuid; missing uuids are simply absent.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function findObjectsByUuidsInRegisterSchema(
		RegisterInterface $register,
		SchemaInterface $schema,
		array $uuids,
		bool $includeDeleted = false,
	): array;

	/**
	 * Find object in register+schema table by identifier (ID, UUID, slug, or URI).
	 *
	 * This method provides ObjectEntity compatibility for the public find() method.
	 *
	 * @param string|int $identifier Object identifier (ID, UUID, slug, or URI).
	 * @param RegisterInterface $register The register context.
	 * @param SchemaInterface $schema The schema context.
	 * @param bool $_rbac Whether to apply RBAC.
	 * @param bool $_multitenancy Whether to apply multi-tenancy.
	 * @param bool $includeDeleted Whether to include soft-deleted objects.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found.
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple objects found.
	 *
	 * @return TObject The found object.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function findInRegisterSchemaTable(
		string|int $identifier,
		RegisterInterface $register,
		SchemaInterface $schema,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $includeDeleted = false,
	);

	/**
	 * Add the metadata columns a magic table lacks.
	 *
	 * @param string $tableName Bare magic table name.
	 *
	 * @return array{added: list<string>, failed: array<string, string>} What was added, and what failed with why.
	 *
	 * @spec openspec/changes/magic-table-metadata-self-healing/specs/object-lifecycle/spec.md#requirement-reconcile-reports-and-repairs-missing-metadata-columns-req-mth-003
	 */
	public function healMetadataColumns(string $tableName): array;

	/**
	 * Find objects in a specific schema's magic table that have a given entity ID in a linked type column.
	 *
	 * Used for reverse lookups: "find all objects linked to mail 1/6".
	 * The linked type columns (_mail, _contacts, etc.) store JSON arrays of string IDs.
	 *
	 * @param SchemaInterface $schema The schema whose magic table to search
	 * @param string $columnName The column name (e.g., '_mail', '_contacts')
	 * @param string $entityId The entity ID to search for
	 *
	 * @return array Array of ObjectEntity objects
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function findByLinkedEntity(SchemaInterface $schema, string $columnName, string $entityId): array;

	/**
	 * Get all register/schema pairs that have magic tables in the database.
	 *
	 * Discovers magic tables from information_schema and extracts register/schema IDs
	 * from table names (oc_openregister_table_{registerId}_{schemaId}).
	 *
	 * @return array<array{registerId: int, schemaId: int}> Array of register/schema ID pairs
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function getAllRegisterSchemaPairs(): array;

	/**
	 * Get existing table columns
	 *
	 * @param string $tableName The table name
	 *
	 * @throws Exception If unable to get table columns
	 *
	 * @return (bool|mixed)[][] Array of existing column definitions
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function getExistingTableColumns(string $tableName): array;

	/**
	 * Insert a new object entity with event dispatching.
	 *
	 * @param Entity $entity Entity to insert.
	 * @param RegisterInterface|null $register Optional register for routing.
	 * @param SchemaInterface|null $schema Optional schema for routing.
	 * @param bool $failIfExists Insert-only: refuse instead of updating when the identifier is taken.
	 *
	 * @return Entity&TObject Inserted entity.
	 *
	 * @throws Exception If insertion fails.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function insert(Entity $entity, ?RegisterInterface $register = null, ?SchemaInterface $schema = null, bool $failIfExists = false): Entity;

	/**
	 * Update ObjectEntity in register+schema table.
	 *
	 * @param ObjectRowInterface $entity The object entity to update.
	 * @param RegisterInterface $register The register context.
	 * @param SchemaInterface $schema The schema context.
	 * @param ObjectRowInterface|null $oldEntity The old entity for comparison.
	 *
	 * @throws Exception If update fails.
	 *
	 * @return TObject The updated object entity.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function updateObjectEntity(
		ObjectRowInterface $entity,
		RegisterInterface $register,
		SchemaInterface $schema,
		?ObjectRowInterface $oldEntity = null,
	);

	/**
	 * Delete ObjectEntity from register+schema table.
	 *
	 * Supports both soft delete (sets _deleted field) and hard delete (removes from table).
	 *
	 * @param ObjectRowInterface $entity The object entity to delete.
	 * @param RegisterInterface $register The register context.
	 * @param SchemaInterface $schema The schema context.
	 * @param bool $hardDelete Whether to perform hard delete (default: false for soft delete).
	 * @param bool $dispatchEvents Whether to dispatch events.
	 *
	 * @throws Exception If deletion fails.
	 *
	 * @return TObject The deleted object entity.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function deleteObjectEntity(
		ObjectRowInterface $entity,
		RegisterInterface $register,
		SchemaInterface $schema,
		bool $hardDelete = false,
		bool $dispatchEvents = true,
	);

	/**
	 * Unlock object in register+schema table.
	 *
	 * @param ObjectRowInterface $entity The object entity to unlock.
	 * @param RegisterInterface $register The register context.
	 * @param SchemaInterface $schema The schema context.
	 * @param string|null $runUuid Flow run releasing the lock, for a run-scoped lock.
	 * @param bool $break Release regardless of holder (administrator break-lock).
	 *
	 * @throws Exception If unlocking fails.
	 *
	 * @return TObject The unlocked object entity.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function unlockObjectEntity(
		ObjectRowInterface $entity,
		RegisterInterface $register,
		SchemaInterface $schema,
		?string $runUuid = null,
		bool $break = false,
	);

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
	 * @param array<string, TObject> $oldEntities Optional pre-delete snapshots keyed
	 *                                            by uuid, used as event old-objects.
	 *                                            Missing snapshots are derived by
	 *                                            cloning the (mutated) entity.
	 *
	 * @return TObject[] The entities that were actually soft-deleted.
	 *
	 * @throws Exception If a batched UPDATE fails for every table.
	 *
	 * @psalm-return list<TObject>
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function softDeleteMultipleObjectEntities(array $entities, array $oldEntities = []): array;

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
	 * @param RegisterInterface $register The register context
	 * @param SchemaInterface $schema The schema definition
	 *
	 * @return array Statistics about what was changed
	 *
	 * @throws Exception If table sync fails
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function syncTableForRegisterSchema(RegisterInterface $register, SchemaInterface $schema): array;

	/**
	 * Get object statistics grouped by schema for multiple schemas.
	 *
	 * Returns per-schema statistics using one count query per register-schema pair,
	 * grouped by schema ID. Replaces N individual getStatistics() calls.
	 *
	 * @param int[] $schemaIds Array of schema IDs to get statistics for.
	 *
	 * @return array<int, array{total: int, size: int}> Map of schemaId => statistics array.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function getStatisticsGroupedBySchema(array $schemaIds): array;

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
	 * (MagicSearchHandler::applyAccessControlToQuery()), so a trashed
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
	 * @return TObject[] Soft-deleted objects across all magic tables.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function findDeletedAcrossAllMagicTables(
		?int $limit = null,
		?int $offset = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array;

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
	 * @return TObject The restored object.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If the object is not found.
	 * @throws Exception If register/schema context cannot be resolved.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function restoreObject(string $uuid);

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
	 * @spec openspec/changes/magic-number-columns-keep-their-decimals/specs/magic-table-columns/spec.md#requirement-a-number-column-holds-every-value-its-property-accepts-req-mtc-001
	 */
	public function widenNumberColumnsToDefault(string $tableName): array;

	/**
	 * Get register chart data.
	 *
	 * @param int|null $registerId Register ID filter
	 * @param int|null $schemaId Schema ID filter
	 *
	 * @return (int|mixed|string)[][] Chart data
	 *
	 * @psalm-return array{labels: array<'Unknown'|mixed>, series: array<int>}
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function getRegisterChartData(?int $registerId = null, ?int $schemaId = null): array;

	/**
	 * Get size distribution chart data for objects.
	 *
	 * @param int|null $registerId Optional register ID filter.
	 * @param int|null $schemaId Optional schema ID filter.
	 *
	 * @return array{labels: list<string>, series: list<int>} Chart data.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function getSizeDistributionChartData(?int $registerId = null, ?int $schemaId = null): array;

	/**
	 * Get schema chart data.
	 *
	 * @param int|null $registerId Register ID filter
	 * @param int|null $schemaId Schema ID filter
	 *
	 * @return (int|mixed|string)[][] Chart data
	 *
	 * @psalm-return array{labels: array<'Unknown'|mixed>, series: array<int>}
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function getSchemaChartData(?int $registerId = null, ?int $schemaId = null): array;

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
	 * @spec openspec/changes/organisation-user-and-object-quota/specs/organisation-quota/spec.md#requirement-an-organisation-quota-limits-its-users-and-its-objects
	 */
	public function countObjectsByOrganisation(string $organisationUuid, array $schemaIds=[]): int;

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
	 * @spec openspec/changes/organisation-usage-measured/specs/organisation-usage-measured/spec.md
	 */
	public function findFolderIdsByOrganisation(string $organisationUuid): array;

	/**
	 * Hard-delete the rows of a register+schema table whose `_expires` has passed.
	 *
	 * The sweep for rows written through bulkUpsert() with an expiry, i.e. raw
	 * appends. There is no soft-delete and no audit entry: those rows never had
	 * either, and keeping expired telemetry as tombstones would defeat the
	 * retention the expiry expresses. Rows with a NULL `_expires` are never
	 * touched. A table that does not exist yet has nothing to purge and answers 0.
	 *
	 * @param RegisterInterface $register The register context.
	 * @param SchemaInterface   $schema   The schema context.
	 *
	 * @return int The number of rows removed.
	 *
	 * @throws Exception If the delete fails.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-raw-append-for-high-volume-writers
	 */
	public function purgeExpired(RegisterInterface $register, SchemaInterface $schema): int;

	/**
	 * Perform bulk upsert operation on register+schema table
	 *
	 * This method provides high-performance bulk insert/update operations for dynamic tables.
	 * It delegates to MagicBulkHandler for the actual database operations.
	 *
	 * @param array $objects Array of object data in standard format
	 * @param RegisterInterface $register Register context
	 * @param SchemaInterface $schema Schema context
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
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function bulkUpsert(
		array $objects,
		RegisterInterface $register,
		SchemaInterface $schema,
		string $tableName,
		bool $needsPreUpdateState = true,
	): array;

	/**
	 * Delete all objects belonging to a specific schema from the magic table.
	 *
	 * This method performs an optimized bulk deletion from the magic table
	 * for all objects belonging to a given register/schema combination.
	 *
	 * @param RegisterInterface $register The register context.
	 * @param SchemaInterface $schema The schema context.
	 * @param bool $hardDelete Whether to perform a hard delete (default: false for soft delete).
	 *
	 * @throws Exception If deletion fails.
	 *
	 * @return int The number of objects deleted.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function deleteObjectsBySchema(
		RegisterInterface $register,
		SchemaInterface $schema,
		bool $hardDelete = false,
	): int;

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
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function dropTable(string $tableName): void;

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
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function getLastWriteAction(): string;

	/**
	 * Find multiple objects by their IDs or UUIDs.
	 *
	 * @param array $ids Array of IDs or UUIDs.
	 *
	 * @return TObject[]
	 *
	 * @psalm-return list<TObject>
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function findMultiple(array $ids): array;

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
	 * @return TObject[] Array of objects that have ANY of the UUIDs in _relations
	 *
	 * @psalm-return list<TObject>
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function findByRelationBatchInSchema(
		array $uuids,
		int $schemaId,
		int $registerId,
		string $fieldName,
		array $additionalFieldNames = [],
	): array;

	/**
	 * Get simple facets.
	 *
	 * Routes to the correct faceting method based on query parameters.
	 *
	 * @param array $query Search query containing register, schema, and _facets configuration.
	 *
	 * @return array Facets data.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function getSimpleFacets(array $query = []): array;

	/**
	 * Convert database row to ObjectEntity.
	 *
	 * This method is public to allow bulk handlers to convert rows for event dispatching.
	 * Delegates to MagicStatisticsHandler.
	 *
	 * @param array $row Database row
	 * @param RegisterInterface $_register Register context
	 * @param SchemaInterface $_schema Schema context
	 *
	 * @return TObject|null Converted entity or null on failure
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function convertRowToObjectEntity(array $row, RegisterInterface $_register, SchemaInterface $_schema);

	/**
	 * Ultra-fast bulk save operation with automatic routing.
	 *
	 * Returns complete objects with database-computed classification (created/updated/unchanged).
	 *
	 * @param array $insertObjects Objects to insert/upsert
	 * @param array $updateObjects Objects to update (legacy parameter)
	 * @param RegisterInterface|null $register Optional register context
	 * @param SchemaInterface|null $schema Optional schema context
	 * @param bool $needsPreUpdateState Whether anything downstream will read the
	 *                                  pre-update rows (audit changeset or update
	 *                                  event). False narrows the fetch to the uuid.
	 *
	 * @return array Array of complete objects with object_status field
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function ultraFastBulkSave(
		array $insertObjects = [],
		array $updateObjects = [],
		?RegisterInterface $register = null,
		?SchemaInterface $schema = null,
		bool $needsPreUpdateState = true,
	): array;

	/**
	 * Lock object in register+schema table.
	 *
	 * @param ObjectRowInterface $entity The object entity to lock.
	 * @param RegisterInterface $register The register context.
	 * @param SchemaInterface $schema The schema context.
	 * @param int|null $lockDuration Lock duration in seconds (null for default).
	 * @param string|null $process What the lock was taken for.
	 * @param string|null $runUuid Flow run taking the lock, for a run-scoped lock.
	 *
	 * @throws Exception If locking fails.
	 *
	 * @return TObject The locked object entity.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function lockObjectEntity(
		ObjectRowInterface $entity,
		RegisterInterface $register,
		SchemaInterface $schema,
		?int $lockDuration = null,
		?string $process = null,
		?string $runUuid = null,
	);

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
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function deleteObjects(array $uuids = [], bool $hardDelete = false): array;

	/**
	 * Whether a default list read widens the organisation boundary by the
	 * rows with no organisation, because the caller's grant comes from the
	 * register cascade.
	 *
	 * @param SchemaInterface $schema The schema being read.
	 *
	 * @return bool True when the list's organisation filter also admits org-less rows.
  *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-register-level-authorization-cascade
	 */
	public function organisationlessRowsAdmittedByRbac(SchemaInterface $schema): bool;

	/**
	 * The row-level access predicate for a raw query over one magic table.
	 *
	 * For a caller that writes its own SQL against the table (the aggregation
	 * fast path) and must count the rows a list read would return, no more.
	 * The predicate is MagicRbacHandler::buildRbacRowPredicateSql()'s, so
	 * the list and the raw query cannot drift apart. Columns are unqualified:
	 * the query must read the magic table without an alias.
	 *
	 * @param SchemaInterface        $schema   The schema of the table being read.
	 * @param string        $action   The action the rows are filtered for (default: 'read').
	 * @param RegisterInterface|null $register The register of the table, so a metadata column the table could not
	 *                                be given is left out of the predicate. Null keeps every predicate.
	 *
	 * @return string|null Null when no row restriction applies (an admin); otherwise
	 *                     a predicate safe to AND into a WHERE, `1 = 0` for deny-all.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function rbacRowPredicateSql(SchemaInterface $schema, string $action = 'read', ?RegisterInterface $register = null): ?string;

	/**
	 * Whether a default list read waives the organisation boundary for this
	 * caller because RBAC governs the rows instead.
	 *
	 * @param SchemaInterface $schema The schema being read.
	 *
	 * @return bool True when the list would not apply the organisation filter.
  *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function organisationBoundaryWaivedByRbac(SchemaInterface $schema): bool;

	/**
	 * Whether the current caller qualifies for at least one rule of an action.
	 *
	 * @param SchemaInterface $schema The schema whose rules to consult.
	 * @param string $action The action (default: 'read').
	 *
	 * @return bool True when a rule for the action admits the caller.
  *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function callerQualifiesForAction(SchemaInterface $schema, string $action = 'read'): bool;

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
	 * @return TObject[] Array of objects that have this UUID in their _relations
	 *
	 * @psalm-return list<TObject>
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function findByRelationUsingRelationsColumn(string $uuid): array;

	/**
	 * Get all existing register+schema tables
	 *
	 * This method scans the database for all tables matching our naming pattern
	 * and returns them as an array of register+schema combinations.
	 *
	 * @return (int|string)[][] Array of ['registerId' => int, 'schemaId' => int, 'tableName' => string].
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function getExistingRegisterSchemaTables(): array;

	/**
	 * Search objects in register+schema-specific table
	 *
	 * @param array $query Search query parameters
	 * @param RegisterInterface $register The register context for table selection
	 * @param SchemaInterface $schema The schema for table selection
	 *
	 * @throws Exception If search operation fails
	 *
	 * @return TObject[]
	 *
	 * @psalm-return list<TObject>
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function searchObjectsInRegisterSchemaTable(array $query, RegisterInterface $register, SchemaInterface $schema): array;

	/**
	 * Count objects in a register+schema table, throwing when the count cannot be made.
	 *
	 * The same count as countObjectsInRegisterSchemaTable(), for a caller that
	 * acts on the number (a quota): a failed query or an answer that is not a
	 * number throws instead of reading as 0, so a broken count can never pass
	 * for an empty table (live pass O10).
	 *
	 * @param array    $query    Search parameters for filtering (excluding pagination).
	 * @param RegisterInterface $register The register context for table selection.
	 * @param SchemaInterface   $schema   The schema for table selection.
	 *
	 * @return int Count of matching objects.
	 *
	 * @throws RuntimeException When the count query does not answer with a number.
	 * @throws Exception        When the count query fails.
	 *
	 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md
	 */
	public function countObjectsOrFail(array $query, RegisterInterface $register, SchemaInterface $schema): int;

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
	 * @param ObjectRowInterface $entity The object whose folder was made.
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
	public function recordFolder(ObjectRowInterface $entity, ?string $expected, string $folderId): bool;

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
	 * The metadata columns a magic table lacks, read fresh.
	 *
	 * @param string $tableName Bare magic table name.
	 *
	 * @return array<string, array> Missing column definitions keyed by column name.
	 *
	 * @spec openspec/changes/magic-table-metadata-self-healing/specs/object-lifecycle/spec.md#requirement-reconcile-reports-and-repairs-missing-metadata-columns-req-mth-003
	 */
	public function inspectMetadataColumns(string $tableName): array;

	/**
	 * The metadata column definitions every magic table carries.
	 *
	 * @return array<string, array> Column definitions keyed by column name.
	 *
	 * @spec openspec/changes/magic-table-metadata-self-healing/specs/object-lifecycle/spec.md#requirement-a-magic-table-missing-metadata-columns-heals-itself-on-first-use-req-mth-001
	 */
	public function getMetadataColumnDefinitions(): array;

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
	 * @spec openspec/changes/magic-number-columns-keep-their-decimals/specs/magic-table-columns/spec.md#requirement-a-number-column-holds-every-value-its-property-accepts-req-mtc-001
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
	 * @spec openspec/changes/magic-number-columns-keep-their-decimals/specs/magic-table-columns/spec.md#requirement-a-number-column-holds-every-value-its-property-accepts-req-mtc-001
	 */
	public function widenDecimalColumns(string $tableName, array $currentColumns, array $requiredColumns): array;

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
	 * Find all objects across ALL magic tables that have the given UUID in their relations.
	 *
	 * This method searches across all magic tables to find objects that reference the given UUID.
	 * Relations are stored as JSON objects like {"fieldName": "uuid", ...}.
	 *
	 * @param string $uuid The UUID to search for in relations.
	 * @param bool $includeDeleted Whether to include deleted objects.
	 *
	 * @return TObject[] Array of found ObjectEntity objects.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function findByRelationAcrossAllMagicTables(
		string $uuid,
		bool $includeDeleted = false,
	): array;

	/**
	 * The metadata columns this process knows a magic table lacks, without any I/O.
	 *
	 * @param string $tableName Bare magic table name.
	 *
	 * @return list<string> Missing metadata column names, empty when complete or unknown.
	 *
	 * @spec openspec/changes/magic-table-metadata-self-healing/specs/object-lifecycle/spec.md#requirement-a-table-that-cannot-be-healed-degrades-quietly-req-mth-002
	 */
	public function missingMetadataColumns(string $tableName): array;
}//end interface
