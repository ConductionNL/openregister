<?php

/**
 * OpenRegister Unified Object Mapper
 *
 * Facade that routes object operations to either ObjectEntityMapper (blob storage)
 * or MagicMapper (column-mapped storage) based on register+schema configuration.
 *
 * This class provides a transparent switching layer that allows registers to configure
 * their storage strategy per schema without affecting application code.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use Exception;
use OCP\AppFramework\Db\Entity;
use OCP\DB\QueryBuilder\IQueryBuilder;
use Psr\Log\LoggerInterface;

/**
 * Unified Object Mapper - Storage Strategy Facade
 *
 * Routes object operations to the appropriate storage backend based on
 * register+schema configuration. Supports:
 *
 * - Blob Storage (ObjectEntityMapper): Default, flexible, schema-agnostic
 * - Column-Mapped Storage (MagicMapper): Optimized for indexing and search
 *
 * ROUTING LOGIC:
 * 1. Check if register and schema are provided
 * 2. Check register configuration for schema-specific magic mapping setting
 * 3. Verify magic table exists if magic mapping is enabled
 * 4. Route to MagicMapper if all conditions met, otherwise ObjectEntityMapper
 *
 * FALLBACK STRATEGY:
 * - No register/schema context → ObjectEntityMapper
 * - Magic mapping disabled → ObjectEntityMapper
 * - Table doesn't exist and autoCreate disabled → ObjectEntityMapper
 * - Table doesn't exist and autoCreate enabled → Create table, use MagicMapper
 *
 * @package OCA\OpenRegister\Db
 */
class UnifiedObjectMapper extends AbstractObjectMapper
{
    /**
     * Constructor for UnifiedObjectMapper.
     *
     * @param ObjectEntityMapper $objectEntityMapper Blob storage mapper.
     * @param MagicMapper        $magicMapper        Column-mapped storage mapper.
     * @param RegisterMapper     $registerMapper     Register mapper for configuration.
     * @param SchemaMapper       $schemaMapper       Schema mapper for metadata.
     * @param LoggerInterface    $logger             Logger for debugging.
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly MagicMapper $magicMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    // ==================================================================================
    // ROUTING LOGIC
    // ==================================================================================

    /**
     * Determine whether to use MagicMapper for a given register+schema combination.
     *
     * Decision flow:
     * 1. If register or schema is null → use blob storage (no context)
     * 2. Check register configuration for this schema's magic mapping setting
     * 3. If enabled, verify table exists (or auto-create if configured)
     * 4. Return true if MagicMapper should be used, false for blob storage
     *
     * @param Register|null $register The register context.
     * @param Schema|null   $schema   The schema context.
     *
     * @return bool True if MagicMapper should be used, false for ObjectEntityMapper.
     */
    private function shouldUseMagicMapper(?Register $register, ?Schema $schema): bool
    {
        // No context → use blob storage.
        if ($register === null || $schema === null) {
            $this->logger->debug(
                '[UnifiedObjectMapper] No register/schema context, using blob storage'
            );
            return false;
        }

        // Check register configuration for this schema.
        $registerConfig = $register->getConfiguration() ?? [];
        $schemaConfigs  = $registerConfig['schemas'] ?? [];
        $schemaId       = $schema->getId();
        $schemaConfig   = $schemaConfigs[$schemaId] ?? [];

        $this->logger->debug(
            '[UnifiedObjectMapper] Checking magic mapping configuration',
            [
                'registerId'   => $register->getId(),
                'schemaId'     => $schemaId,
                'schemaConfig' => $schemaConfig,
            ]
        );

        // Check if magic mapping is enabled for this register+schema combo.
        $magicMappingEnabled = ($schemaConfig['magicMapping'] ?? false) === true;

        if ($magicMappingEnabled === false) {
            $this->logger->debug(
                '[UnifiedObjectMapper] Magic mapping disabled for this register+schema, using blob storage'
            );
            return false;
        }

        // Magic mapping is enabled - check if table exists.
        $tableExists = $this->magicMapper->existsTableForRegisterSchema(register: $register, schema: $schema);

        if ($tableExists === true) {
            $this->logger->debug(
                '[UnifiedObjectMapper] Magic table exists, using MagicMapper',
                ['tableName' => $this->magicMapper->getTableNameForRegisterSchema(register: $register, schema: $schema)]
            );
            return true;
        }

        // Table doesn't exist - check if auto-create is enabled.
        $autoCreateTable = ($schemaConfig['autoCreateTable'] ?? false) === true;

        if ($autoCreateTable === true) {
            $this->logger->info(
                '[UnifiedObjectMapper] Magic table does not exist, auto-creating',
                [
                    'registerId' => $register->getId(),
                    'schemaId'   => $schemaId,
                ]
            );

            try {
                // Create the table.
                $this->magicMapper->ensureTableForRegisterSchema(register: $register, schema: $schema);
                $this->logger->info(
                    '[UnifiedObjectMapper] Magic table created successfully, using MagicMapper'
                );
                return true;
            } catch (Exception $e) {
                $this->logger->error(
                    '[UnifiedObjectMapper] Failed to auto-create magic table, falling back to blob storage',
                    [
                        'error'      => $e->getMessage(),
                        'registerId' => $register->getId(),
                        'schemaId'   => $schemaId,
                    ]
                );
                return false;
            }//end try
        }//end if

        // Magic mapping enabled but table doesn't exist and auto-create is disabled.
        $this->logger->warning(
            '[UnifiedObjectMapper] Magic mapping enabled but table does not exist and autoCreate is disabled, using blob storage'
        );

        return false;
    }//end shouldUseMagicMapper()

    /**
     * Extract register and schema from ObjectEntity if not explicitly provided.
     *
     * This helper allows operations to work with just an ObjectEntity by extracting
     * the register and schema IDs from the entity and fetching the full objects.
     *
     * @param ObjectEntity  $entity   The object entity.
     * @param Register|null $register Optional register (will be fetched if null).
     * @param Schema|null   $schema   Optional schema (will be fetched if null).
     *
     * @return array{Register|null, Schema|null} Array with [register, schema].
     */
    private function resolveRegisterAndSchema(
        ObjectEntity $entity,
        ?Register $register = null,
        ?Schema $schema = null
    ): array {
        // If register not provided, try to get it from entity.
        if ($register === null && $entity->getRegister() !== null) {
            try {
                $register = $this->registerMapper->find((int) $entity->getRegister());
            } catch (Exception $e) {
                $this->logger->warning(
                    '[UnifiedObjectMapper] Failed to resolve register from entity',
                    ['registerId' => $entity->getRegister(), 'error' => $e->getMessage()]
                );
            }
        }

        // If schema not provided, try to get it from entity.
        if ($schema === null && $entity->getSchema() !== null) {
            try {
                $schema = $this->schemaMapper->find((int) $entity->getSchema());
            } catch (Exception $e) {
                $this->logger->warning(
                    '[UnifiedObjectMapper] Failed to resolve schema from entity',
                    ['schemaId' => $entity->getSchema(), 'error' => $e->getMessage()]
                );
            }
        }

        return [$register, $schema];
    }//end resolveRegisterAndSchema()

    // ==================================================================================
    // CORE CRUD OPERATIONS
    // ==================================================================================

    /**
     * Find an object entity by identifier (ID, UUID, slug, or URI).
     *
     * Routes to MagicMapper if magic mapping is enabled and table exists,
     * otherwise uses ObjectEntityMapper blob storage.
     *
     * @param string|int    $identifier     Object identifier (ID, UUID, slug, or URI).
     * @param Register|null $register       Optional register to filter by.
     * @param Schema|null   $schema         Optional schema to filter by.
     * @param bool          $includeDeleted Whether to include deleted objects.
     * @param bool          $rbac           Whether to apply RBAC checks (default: true).
     * @param bool          $multitenancy   Whether to apply multitenancy filtering (default: true).
     *
     * @return ObjectEntity The found object.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found.
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple objects found.
     */
    public function find(
        string|int $identifier,
        ?Register $register = null,
        ?Schema $schema = null,
        bool $includeDeleted = false,
        bool $rbac = true,
        bool $multitenancy = true
    ): ObjectEntity {
        if ($this->shouldUseMagicMapper(register: $register, schema: $schema) === true) {
            $this->logger->debug('[UnifiedObjectMapper] Routing find() to MagicMapper');
            return $this->magicMapper->findInRegisterSchemaTable(identifier: $identifier, register: $register, schema: $schema);
        }

        $this->logger->debug('[UnifiedObjectMapper] Routing find() to ObjectEntityMapper');
        return $this->objectEntityMapper->find($identifier, $register, $schema, $includeDeleted, $rbac, $multitenancy);
    }//end find()

    /**
     * Find all ObjectEntities with filtering, pagination, and search.
     *
     * @param int|null      $limit            The number of objects to return.
     * @param int|null      $offset           The offset of the objects to return.
     * @param array|null    $filters          The filters to apply to the objects.
     * @param array|null    $searchConditions The search conditions to apply to the objects.
     * @param array|null    $searchParams     The search parameters to apply to the objects.
     * @param array         $sort             The sort order to apply.
     * @param string|null   $search           The search string to apply.
     * @param array|null    $ids              Array of IDs or UUIDs to filter by.
     * @param string|null   $uses             Value that must be present in relations.
     * @param bool          $includeDeleted   Whether to include deleted objects.
     * @param Register|null $register         Optional register to filter objects.
     * @param Schema|null   $schema           Optional schema to filter objects.
     * @param bool|null     $published        If true, only return currently published objects.
     *
     * @return ObjectEntity[]
     *
     * @throws \OCP\DB\Exception If a database error occurs.
     *
     * @psalm-return list<ObjectEntity>
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
        ?Register $register = null,
        ?Schema $schema = null,
        ?bool $published = null
    ): array {
        if ($this->shouldUseMagicMapper(register: $register, schema: $schema) === true) {
            $this->logger->debug('[UnifiedObjectMapper] Routing findAll() to MagicMapper');
            return $this->magicMapper->findAllInRegisterSchemaTable(
                register: $register,
                schema: $schema,
                limit: $limit,
                offset: $offset,
                filters: $filters,
                sort: $sort,
                published: $published
            );
        }

        $this->logger->debug('[UnifiedObjectMapper] Routing findAll() to ObjectEntityMapper');
        return $this->objectEntityMapper->findAll(
            limit: $limit,
            offset: $offset,
            filters: $filters,
            searchConditions: $searchConditions,
            searchParams: $searchParams,
            sort: $sort,
            search: $search,
            ids: $ids,
            uses: $uses,
            includeDeleted: $includeDeleted,
            register: $register,
            schema: $schema,
            published: $published
        );
    }//end findAll()

    /**
     * Find multiple objects by their IDs or UUIDs.
     *
     * Note: Since multiple objects may span different register+schema combinations,
     * this always uses ObjectEntityMapper blob storage for simplicity.
     *
     * @param array $ids Array of IDs or UUIDs.
     *
     * @return ObjectEntity[]
     *
     * @psalm-return list<ObjectEntity>
     */
    public function findMultiple(array $ids): array
    {
        $this->logger->debug('[UnifiedObjectMapper] Routing findMultiple() to ObjectEntityMapper (cross-schema operation)');
        return $this->objectEntityMapper->findMultiple($ids);
    }//end findMultiple()

    /**
     * Find all objects for a given schema.
     *
     * Note: This operates across all registers for a schema, so uses blob storage.
     *
     * @param int $schemaId Schema ID.
     *
     * @return ObjectEntity[]
     *
     * @psalm-return list<ObjectEntity>
     */
    public function findBySchema(int $schemaId): array
    {
        $this->logger->debug('[UnifiedObjectMapper] Routing findBySchema() to ObjectEntityMapper (cross-register operation)');
        return $this->objectEntityMapper->findBySchema($schemaId);
    }//end findBySchema()

    /**
     * Insert a new object entity with event dispatching.
     *
     * Routes based on the entity's register and schema fields.
     *
     * @param Entity $entity Entity to insert.
     *
     * @return Entity Inserted entity.
     *
     * @throws Exception If insertion fails.
     */
    public function insert(Entity $entity): Entity
    {
        if ($entity instanceof ObjectEntity === false) {
            throw new Exception('Entity must be an instance of ObjectEntity');
        }

        [$register, $schema] = $this->resolveRegisterAndSchema($entity);

        if ($this->shouldUseMagicMapper(register: $register, schema: $schema) === true) {
            $this->logger->debug('[UnifiedObjectMapper] Routing insert() to MagicMapper');
            return $this->magicMapper->insertObjectEntity(entity: $entity, register: $register, schema: $schema);
        }

        $this->logger->debug('[UnifiedObjectMapper] Routing insert() to ObjectEntityMapper');
        return $this->objectEntityMapper->insert($entity);
    }//end insert()

    /**
     * Update an existing object entity with event dispatching.
     *
     * Routes based on the entity's register and schema fields.
     *
     * @param Entity $entity Entity to update.
     *
     * @return Entity Updated entity.
     *
     * @throws Exception If update fails.
     */
    public function update(Entity $entity): Entity
    {
        if ($entity instanceof ObjectEntity === false) {
            throw new Exception('Entity must be an instance of ObjectEntity');
        }

        [$register, $schema] = $this->resolveRegisterAndSchema($entity);

        if ($this->shouldUseMagicMapper(register: $register, schema: $schema) === true) {
            $this->logger->debug('[UnifiedObjectMapper] Routing update() to MagicMapper');
            return $this->magicMapper->updateObjectEntity(entity: $entity, register: $register, schema: $schema);
        }

        $this->logger->debug('[UnifiedObjectMapper] Routing update() to ObjectEntityMapper');
        return $this->objectEntityMapper->update(entity: $entity);
    }//end update()

    /**
     * Delete an object entity with event dispatching.
     *
     * Routes based on the entity's register and schema fields.
     *
     * @param Entity $entity Entity to delete.
     *
     * @return Entity Deleted entity.
     *
     * @throws Exception If deletion fails.
     */
    public function delete(Entity $entity): Entity
    {
        if ($entity instanceof ObjectEntity === false) {
            throw new Exception('Entity must be an instance of ObjectEntity');
        }

        [$register, $schema] = $this->resolveRegisterAndSchema($entity);

        if ($this->shouldUseMagicMapper(register: $register, schema: $schema) === true) {
            $this->logger->debug('[UnifiedObjectMapper] Routing delete() to MagicMapper');
            return $this->magicMapper->deleteObjectEntity(entity: $entity, register: $register, schema: $schema, soft: false);
        }

        $this->logger->debug('[UnifiedObjectMapper] Routing delete() to ObjectEntityMapper');
        return $this->objectEntityMapper->delete(entity: $entity);
    }//end delete()

    // ==================================================================================
    // LOCKING OPERATIONS
    // ==================================================================================

    /**
     * Lock an object to prevent concurrent modifications.
     *
     * Note: Locking operations are currently always routed to ObjectEntityMapper
     * as they operate on UUID which may span storage strategies.
     *
     * @param string   $uuid         Object UUID to lock.
     * @param int|null $lockDuration Lock duration in seconds (null for default).
     *
     * @return array Lock information including expiry time.
     *
     * @throws Exception If locking fails.
     *
     * @psalm-return array{locked: mixed, uuid: string}
     */
    public function lockObject(string $uuid, ?int $lockDuration = null): array
    {
        $this->logger->debug('[UnifiedObjectMapper] Routing lockObject() to ObjectEntityMapper');
        return $this->objectEntityMapper->lockObject(uuid: $uuid, duration: $lockDuration);
    }//end lockObject()

    /**
     * Unlock an object to allow modifications.
     *
     * @param string $uuid Object UUID to unlock.
     *
     * @return bool True if unlocked successfully.
     *
     * @throws Exception If unlocking fails.
     */
    public function unlockObject(string $uuid): bool
    {
        $this->logger->debug('[UnifiedObjectMapper] Routing unlockObject() to ObjectEntityMapper');
        return $this->objectEntityMapper->unlockObject($uuid);
    }//end unlockObject()

    // ==================================================================================
    // BULK OPERATIONS
    // ==================================================================================

    /**
     * ULTRA PERFORMANCE: Memory-intensive unified bulk save operation.
     *
     * Note: Bulk operations are complex across storage strategies,
     * currently routed to ObjectEntityMapper for consistency.
     *
     * @param array $insertObjects Array of arrays (insert data).
     * @param array $updateObjects Array of ObjectEntity instances (update data).
     *
     * @return array Array of processed UUIDs.
     */
    public function ultraFastBulkSave(array $insertObjects = [], array $updateObjects = []): array
    {
        $this->logger->debug('[UnifiedObjectMapper] Routing ultraFastBulkSave() to ObjectEntityMapper');
        return $this->objectEntityMapper->ultraFastBulkSave(insertObjects: $insertObjects, updateObjects: $updateObjects);
    }//end ultraFastBulkSave()

    /**
     * Perform bulk delete operations on objects by UUID.
     *
     * @param array $uuids      Array of object UUIDs to delete.
     * @param bool  $hardDelete Whether to force hard delete.
     *
     * @return array Array of UUIDs of deleted objects.
     */
    public function deleteObjects(array $uuids = [], bool $hardDelete = false): array
    {
        $this->logger->debug('[UnifiedObjectMapper] Routing deleteObjects() to ObjectEntityMapper');
        return $this->objectEntityMapper->deleteObjects(uuids: $uuids, hardDelete: $hardDelete);
    }//end deleteObjects()

    /**
     * Perform bulk publish operations on objects by UUID.
     *
     * @param array         $uuids    Array of object UUIDs to publish.
     * @param DateTime|bool $datetime Optional datetime for publishing.
     *
     * @return array Array of UUIDs of published objects.
     */
    public function publishObjects(array $uuids = [], DateTime|bool $datetime = true): array
    {
        $this->logger->debug('[UnifiedObjectMapper] Routing publishObjects() to ObjectEntityMapper');
        return $this->objectEntityMapper->publishObjects(uuids: $uuids, datetime: $datetime);
    }//end publishObjects()

    /**
     * Perform bulk depublish operations on objects by UUID.
     *
     * @param array         $uuids    Array of object UUIDs to depublish.
     * @param DateTime|bool $datetime Optional datetime for depublishing.
     *
     * @return array Array of UUIDs of depublished objects.
     */
    public function depublishObjects(array $uuids = [], DateTime|bool $datetime = true): array
    {
        $this->logger->debug('[UnifiedObjectMapper] Routing depublishObjects() to ObjectEntityMapper');
        return $this->objectEntityMapper->depublishObjects(uuids: $uuids, datetime: $datetime);
    }//end depublishObjects()

    // ==================================================================================
    // STATISTICS OPERATIONS
    // ==================================================================================

    /**
     * Get statistics for objects.
     *
     * Note: Statistics aggregate across all storage strategies,
     * currently routed to ObjectEntityMapper.
     *
     * @param int|array|null $registerId Filter by register ID(s).
     * @param int|array|null $schemaId   Filter by schema ID(s).
     * @param array          $exclude    Combinations to exclude.
     *
     * @return array Statistics including total, size, invalid, deleted, locked, published counts.
     */
    public function getStatistics(
        int|array|null $registerId = null,
        int|array|null $schemaId = null,
        array $exclude = []
    ): array {
        $this->logger->debug('[UnifiedObjectMapper] Routing getStatistics() to ObjectEntityMapper');
        return $this->objectEntityMapper->getStatistics(registerId: $registerId, schemaId: $schemaId, exclude: $exclude);
    }//end getStatistics()

    /**
     * Get chart data for objects grouped by register.
     *
     * @param int|null $registerId Filter by register ID.
     * @param int|null $schemaId   Filter by schema ID.
     *
     * @return array Chart data with 'labels' and 'series' keys.
     */
    public function getRegisterChartData(?int $registerId = null, ?int $schemaId = null): array
    {
        $this->logger->debug('[UnifiedObjectMapper] Routing getRegisterChartData() to ObjectEntityMapper');
        return $this->objectEntityMapper->getRegisterChartData(registerId: $registerId, schemaId: $schemaId);
    }//end getRegisterChartData()

    /**
     * Get chart data for objects grouped by schema.
     *
     * @param int|null $registerId Filter by register ID.
     * @param int|null $schemaId   Filter by schema ID.
     *
     * @return array Chart data with 'labels' and 'series' keys.
     */
    public function getSchemaChartData(?int $registerId = null, ?int $schemaId = null): array
    {
        $this->logger->debug('[UnifiedObjectMapper] Routing getSchemaChartData() to ObjectEntityMapper');
        return $this->objectEntityMapper->getSchemaChartData(registerId: $registerId, schemaId: $schemaId);
    }//end getSchemaChartData()

    // ==================================================================================
    // FACETING OPERATIONS
    // ==================================================================================

    /**
     * Get simple facets using the facet handlers.
     *
     * @param array $query Search query array containing filters and facet configuration.
     *
     * @return array Simple facet data.
     *
     * @throws \OCP\DB\Exception If a database error occurs.
     */
    public function getSimpleFacets(array $query = []): array
    {
        $this->logger->debug('[UnifiedObjectMapper] Routing getSimpleFacets() to ObjectEntityMapper');
        return $this->objectEntityMapper->getSimpleFacets($query);
    }//end getSimpleFacets()

    /**
     * Get facetable fields from schemas.
     *
     * @param array $baseQuery Base query filters for context.
     *
     * @return array Facetable fields with their configuration.
     *
     * @throws \OCP\DB\Exception If a database error occurs.
     */
    public function getFacetableFieldsFromSchemas(array $baseQuery = []): array
    {
        $this->logger->debug('[UnifiedObjectMapper] Routing getFacetableFieldsFromSchemas() to ObjectEntityMapper');
        return $this->objectEntityMapper->getFacetableFieldsFromSchemas($baseQuery);
    }//end getFacetableFieldsFromSchemas()

    // ==================================================================================
    // SEARCH OPERATIONS
    // ==================================================================================

    /**
     * Search for objects with complex filtering.
     *
     * @param array       $query                  Query parameters.
     * @param string|null $activeOrganisationUuid Active organisation UUID.
     * @param bool        $rbac                   Whether to apply RBAC checks.
     * @param bool        $multitenancy           Whether to apply multitenancy filtering.
     * @param array|null  $ids                    Array of IDs or UUIDs to filter by.
     * @param string|null $uses                   Value that must be present in relations.
     *
     * @return ObjectEntity[]|int
     *
     * @psalm-return list<ObjectEntity>|int
     */
    public function searchObjects(
        array $query = [],
        ?string $activeOrganisationUuid = null,
        bool $rbac = true,
        bool $multitenancy = true,
        ?array $ids = null,
        ?string $uses = null
    ): array|int {
        $this->logger->debug('[UnifiedObjectMapper] searchObjects() - implementing cross-table search');

        // PHASE 2: Cross-table search implementation.
        // Strategy:
        // 1. Determine which schemas use magic mapping.
        // 2. Search blob storage for non-magic schemas.
        // 3. Search individual magic tables for magic-enabled schemas.
        // 4. Merge and deduplicate results.
        // 5. Apply consistent sorting and pagination.
        // Extract schema filter from query to determine which tables to search.
        $schemaFilter = null;
        if (isset($query['_schema']) === true) {
            $schemaFilter = $query['_schema'];
        }

        // Extract register filter if present.
        $registerFilter = null;
        if (isset($query['_register']) === true) {
            $registerFilter = $query['_register'];
        }

        // Determine which schemas and registers to search.
        $magicSchemas = $this->identifyMagicMappedSchemas(registerFilter: $registerFilter, schemaFilter: $schemaFilter);

        if (empty($magicSchemas) === true) {
            // No magic mapping enabled - use blob storage only.
            $this->logger->debug('[UnifiedObjectMapper] No magic mapping found, using blob storage only');
            return $this->objectEntityMapper->searchObjects(query: $query, _activeOrganisationUuid: $activeOrganisationUuid, _rbac: $rbac, _multitenancy: $multitenancy, ids: $ids, uses: $uses);
        }

        // Collect results from multiple sources.
        $allResults = [];

        // Search blob storage for non-magic schemas (if applicable).
        $blobResults = $this->searchBlobStorage(query: $query, magicSchemas: $magicSchemas, activeOrganisationUuid: $activeOrganisationUuid, rbac: $rbac, multitenancy: $multitenancy, ids: $ids, uses: $uses);
        if (empty($blobResults) === false) {
            $allResults = array_merge($allResults, $blobResults);
        }

        // Search magic tables for magic-enabled schemas.
        $magicResults = $this->searchMagicTables(query: $query, magicSchemas: $magicSchemas, activeOrganisationUuid: $activeOrganisationUuid, rbac: $rbac, multitenancy: $multitenancy, ids: $ids, uses: $uses);
        if (empty($magicResults) === false) {
            $allResults = array_merge($allResults, $magicResults);
        }

        // Deduplicate by UUID.
        $allResults = $this->deduplicateResults($allResults);

        // Apply sorting if specified in query.
        $allResults = $this->applySorting(results: $allResults, query: $query);

        // Apply pagination if limit is specified.
        $allResults = $this->applyPagination(results: $allResults, query: $query);

        $this->logger->debug(
            '[UnifiedObjectMapper] Cross-table search complete',
            [
                'totalResults' => count($allResults),
                'magicSchemas' => count($magicSchemas),
                'blobResults'  => count($blobResults),
                'magicResults' => count($magicResults),
            ]
        );

        return $allResults;
    }//end searchObjects()

    /**
     * Count search results.
     *
     * @param array       $query                  Query parameters.
     * @param string|null $activeOrganisationUuid Active organisation UUID.
     * @param bool        $rbac                   Whether to apply RBAC checks.
     * @param bool        $multitenancy           Whether to apply multitenancy filtering.
     * @param array|null  $ids                    Array of IDs or UUIDs to filter by.
     * @param string|null $uses                   Value that must be present in relations.
     *
     * @return int Count of objects.
     */
    public function countSearchObjects(
        array $query = [],
        ?string $activeOrganisationUuid = null,
        bool $rbac = true,
        bool $multitenancy = true,
        ?array $ids = null,
        ?string $uses = null
    ): int {
        $this->logger->debug('[UnifiedObjectMapper] Routing countSearchObjects() to ObjectEntityMapper');
        return $this->objectEntityMapper->countSearchObjects(query: $query, _activeOrganisationUuid: $activeOrganisationUuid, _rbac: $rbac, _multitenancy: $multitenancy, ids: $ids, uses: $uses);
    }//end countSearchObjects()

    /**
     * Count all objects with optional filtering.
     *
     * @param array|null    $filters  Filter parameters.
     * @param Schema|null   $schema   Optional schema to filter by.
     * @param Register|null $register Optional register to filter by.
     *
     * @return int Count of objects.
     */
    public function countAll(
        ?array $filters = null,
        ?Schema $schema = null,
        ?Register $register = null
    ): int {
        $this->logger->debug('[UnifiedObjectMapper] Routing countAll() to ObjectEntityMapper');
        return $this->objectEntityMapper->countAll(filters: $filters, schema: $schema, register: $register);
    }//end countAll()

    // ==================================================================================
    // QUERY BUILDER OPERATIONS
    // ==================================================================================

    /**
     * Get query builder instance.
     *
     * @return IQueryBuilder Query builder instance.
     */
    public function getQueryBuilder(): IQueryBuilder
    {
        return $this->objectEntityMapper->getQueryBuilder();
    }//end getQueryBuilder()

    /**
     * Get the actual max_allowed_packet value from the database.
     *
     * @return int The max_allowed_packet value in bytes.
     */
    public function getMaxAllowedPacketSize(): int
    {
        return $this->objectEntityMapper->getMaxAllowedPacketSize();
    }//end getMaxAllowedPacketSize()

    // ==================================================================================
    // CROSS-TABLE SEARCH HELPER METHODS
    // ==================================================================================

    /**
     * Identify schemas that use magic mapping.
     *
     * @param int|string|null $registerFilter Optional register filter from query.
     * @param int|string|null $schemaFilter   Optional schema filter from query.
     *
     * @return array Array of [registerId => [schemaId1, schemaId2, ...]].
     *
     * @psalm-return array<int, list<int>>
     */
    private function identifyMagicMappedSchemas(int|string|null $registerFilter, int|string|null $schemaFilter): array
    {
        $magicSchemas = [];

        // Get all registers or specific register.
        if ($registerFilter !== null) {
            $registers = [$this->registerMapper->find((int) $registerFilter)];
        } else {
            $registers = $this->registerMapper->findAll();
        }

        foreach ($registers as $register) {
            $schemasWithMagic = $register->getSchemasWithMagicMapping();

            // Filter by schema if specified.
            if ($schemaFilter !== null) {
                $schemaIdFilter = (int) $schemaFilter;
                if (in_array($schemaIdFilter, $schemasWithMagic, true) === true) {
                    $magicSchemas[$register->getId()] = [$schemaIdFilter];
                }
            } else {
                if (empty($schemasWithMagic) === false) {
                    $magicSchemas[$register->getId()] = $schemasWithMagic;
                }
            }
        }

        return $magicSchemas;
    }//end identifyMagicMappedSchemas()

    /**
     * Search blob storage excluding magic-mapped schemas.
     *
     * @param array       $query                  Original query.
     * @param array       $magicSchemas           Magic-mapped schema IDs by register.
     * @param string|null $activeOrganisationUuid Active organisation UUID.
     * @param bool        $rbac                   RBAC flag.
     * @param bool        $multitenancy           Multitenancy flag.
     * @param array|null  $ids                    ID filter.
     * @param string|null $uses                   Uses filter.
     *
     * @return ObjectEntity[]
     *
     * @psalm-return list<ObjectEntity>
     */
    private function searchBlobStorage(
        array $query,
        array $magicSchemas,
        ?string $activeOrganisationUuid,
        bool $rbac,
        bool $multitenancy,
        ?array $ids,
        ?string $uses
    ): array {
        // If all schemas use magic mapping, skip blob storage.
        if ($this->allSchemasUseMagicMapping(query: $query, magicSchemas: $magicSchemas) === true) {
            $this->logger->debug('[UnifiedObjectMapper] All schemas use magic mapping, skipping blob storage');
            return [];
        }

        // Add exclusion filter for magic-mapped schemas.
        $modifiedQuery = $query;
        if (empty($magicSchemas) === false) {
            // Exclude objects in magic-mapped schemas from blob search.
            // This prevents duplicate results.
            $excludedCombinations = [];
            foreach ($magicSchemas as $registerId => $schemaIds) {
                foreach ($schemaIds as $schemaId) {
                    $excludedCombinations[] = ['register' => $registerId, 'schema' => $schemaId];
                }
            }

            $modifiedQuery['__excludeRegisterSchemas'] = $excludedCombinations;
        }

        $this->logger->debug('[UnifiedObjectMapper] Searching blob storage with exclusions');
        return $this->objectEntityMapper->searchObjects(query: $modifiedQuery, _activeOrganisationUuid: $activeOrganisationUuid, _rbac: $rbac, _multitenancy: $multitenancy, ids: $ids, uses: $uses);
    }//end searchBlobStorage()

    /**
     * Search magic tables for magic-mapped schemas.
     *
     * @param array       $query                  Original query.
     * @param array       $magicSchemas           Magic-mapped schema IDs by register.
     * @param string|null $activeOrganisationUuid Active organisation UUID.
     * @param bool        $rbac                   RBAC flag.
     * @param bool        $multitenancy           Multitenancy flag.
     * @param array|null  $ids                    ID filter.
     * @param string|null $uses                   Uses filter.
     *
     * @return ObjectEntity[]
     *
     * @psalm-return list<ObjectEntity>
     */
    private function searchMagicTables(
        array $query,
        array $magicSchemas,
        ?string $activeOrganisationUuid,
        bool $rbac,
        bool $multitenancy,
        ?array $ids,
        ?string $uses
    ): array {
        $results = [];

        foreach ($magicSchemas as $registerId => $schemaIds) {
            $register = $this->registerMapper->find($registerId);

            foreach ($schemaIds as $schemaId) {
                try {
                    $schema = $this->schemaMapper->find($schemaId);

                    // Check if table exists for this register+schema combination.
                    if ($this->magicMapper->existsTableForRegisterSchema(register: $register, schema: $schema) === false) {
                        $this->logger->debug(
                            '[UnifiedObjectMapper] Magic table does not exist yet',
                            ['register' => $registerId, 'schema' => $schemaId]
                        );
                        continue;
                    }

                    // Search this specific magic table.
                    $this->logger->debug(
                        '[UnifiedObjectMapper] Searching magic table',
                        ['register' => $registerId, 'schema' => $schemaId]
                    );

                    $tableResults = $this->magicMapper->searchObjectsInRegisterSchemaTable(query: $query, register: $register, schema: $schema);
                    if (empty($tableResults) === false) {
                        $results = array_merge($results, $tableResults);
                    }
                } catch (\Exception $e) {
                    $this->logger->error(
                        '[UnifiedObjectMapper] Failed to search magic table',
                        [
                            'register' => $registerId,
                            'schema'   => $schemaId,
                            'error'    => $e->getMessage(),
                        ]
                    );
                    // Continue with other tables.
                }//end try
            }//end foreach
        }//end foreach

        return $results;
    }//end searchMagicTables()

    /**
     * Check if all schemas in query use magic mapping.
     *
     * @param array $query        The search query.
     * @param array $magicSchemas Magic-mapped schema IDs by register.
     *
     * @return bool True if all schemas use magic mapping.
     */
    private function allSchemasUseMagicMapping(array $query, array $magicSchemas): bool
    {
        // If no schema filter, we need to search both.
        if (isset($query['_schema']) === false) {
            return false;
        }

        // If schema filter exists, check if it's magic-mapped.
        $schemaFilter   = (int) $query['_schema'];
        $registerFilter = isset($query['_register']) === true ? (int) $query['_register'] : null;

        foreach ($magicSchemas as $registerId => $schemaIds) {
            if ($registerFilter !== null && $registerFilter !== $registerId) {
                continue;
            }

            if (in_array($schemaFilter, $schemaIds, true) === true) {
                return true;
            }
        }

        return false;
    }//end allSchemasUseMagicMapping()

    /**
     * Deduplicate results by UUID.
     *
     * @param ObjectEntity[] $results Array of objects.
     *
     * @return ObjectEntity[] Deduplicated array.
     *
     * @psalm-return list<ObjectEntity>
     */
    private function deduplicateResults(array $results): array
    {
        $seen   = [];
        $unique = [];

        foreach ($results as $object) {
            $uuid = $object->getUuid();
            if (isset($seen[$uuid]) === false) {
                $seen[$uuid] = true;
                $unique[]    = $object;
            }
        }

        return $unique;
    }//end deduplicateResults()

    /**
     * Apply sorting to results.
     *
     * @param ObjectEntity[] $results Array of objects.
     * @param array          $query   Query parameters.
     *
     * @return ObjectEntity[] Sorted array.
     *
     * @psalm-return list<ObjectEntity>
     */
    private function applySorting(array $results, array $query): array
    {
        // If no sort specified, return as-is.
        if (isset($query['_order']) === false) {
            return $results;
        }

        $orderBy   = $query['_order'];
        $direction = isset($query['_orderDirection']) === true && strtoupper($query['_orderDirection']) === 'DESC' ? 'DESC' : 'ASC';

        usort(
            $results,
            function ($a, $b) use ($orderBy, $direction) {
                $aValue = $this->getObjectField(object: $a, fieldName: $orderBy);
                $bValue = $this->getObjectField(object: $b, fieldName: $orderBy);

                if ($aValue === $bValue) {
                    return 0;
                }

                $comparison = $aValue <=> $bValue;
                return $direction === 'DESC' ? -$comparison : $comparison;
            }
        );

        return $results;
    }//end applySorting()

    /**
     * Apply pagination to results.
     *
     * @param ObjectEntity[] $results Array of objects.
     * @param array          $query   Query parameters.
     *
     * @return ObjectEntity[] Paginated array.
     *
     * @psalm-return list<ObjectEntity>
     */
    private function applyPagination(array $results, array $query): array
    {
        $offset = isset($query['_offset']) === true ? (int) $query['_offset'] : 0;
        $limit  = isset($query['_limit']) === true ? (int) $query['_limit'] : null;

        if ($offset > 0 || $limit !== null) {
            $length = $limit !== null ? $limit : null;
            return array_slice($results, $offset, $length);
        }

        return $results;
    }//end applyPagination()

    /**
     * Get field value from ObjectEntity.
     *
     * @param ObjectEntity $object    The object entity.
     * @param string       $fieldName The field name.
     *
     * @return mixed The field value.
     */
    private function getObjectField(ObjectEntity $object, string $fieldName): mixed
    {
        // Try getter method first (e.g., getCreated, getUpdated).
        $getter = 'get' . ucfirst($fieldName);
        if (method_exists($object, $getter) === true) {
            return $object->$getter();
        }

        // Fall back to object data.
        $data = $object->getObject();
        return $data[$fieldName] ?? null;
    }//end getObjectField()
}//end class
