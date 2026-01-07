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
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
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
     * 2. Delegate to Register::isMagicMappingEnabledForSchema() which is the
     *    SINGLE SOURCE OF TRUTH for magic mapping checks.
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

        // Delegate to Register::isMagicMappingEnabledForSchema() - the single source of truth.
        $result = $register->isMagicMappingEnabledForSchema(
            schemaId: $schema->getId(),
            schemaSlug: $schema->getSlug()
        );

        $this->logger->debug(
            '[UnifiedObjectMapper] Magic mapping check',
            [
                'registerId' => $register->getId(),
                'schemaId'   => $schema->getId(),
                'schemaSlug' => $schema->getSlug(),
                'enabled'    => $result,
            ]
        );

        return $result;
    }//end shouldUseMagicMapper()

    /**
     * Extract register and schema from ObjectEntity if not explicitly provided.
     *
     * This helper allows operations to work with just an ObjectEntity by extracting
     * the register and schema IDs from the entity and fetching the full objects.
     *
     * IMPORTANT: Uses $_multitenancy=false to avoid multitenancy filtering issues.
     * The entity's register and schema IDs have already been validated by the controller
     * with proper multitenancy context, so we can safely skip multitenancy checks here.
     *
     * @param ObjectEntity  $entity   The object entity.
     * @param Register|null $register Optional register (will be fetched if null).
     * @param Schema|null   $schema   Optional schema (will be fetched if null).
     *
     * @return array{Register|null, Schema|null} Array with [register, schema].
     */
    private function resolveRegisterAndSchema(
        ObjectEntity $entity,
        ?Register $register=null,
        ?Schema $schema=null
    ): array {
        // If register not provided, try to get it from entity.
        if ($register === null && $entity->getRegister() !== null) {
            try {
                // Skip multitenancy check - the register ID on the entity is already validated.
                $register = $this->registerMapper->find((int) $entity->getRegister(), [], null, true, false);
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
                // Skip multitenancy check - the schema ID on the entity is already validated.
                $schema = $this->schemaMapper->find((int) $entity->getSchema(), [], null, true, false);
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
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
     */
    public function find(
        string|int $identifier,
        ?Register $register=null,
        ?Schema $schema=null,
        bool $includeDeleted=false,
        bool $rbac=true,
        bool $multitenancy=true
    ): ObjectEntity {
        if ($this->shouldUseMagicMapper(register: $register, schema: $schema) === true) {
            $this->logger->debug('[UnifiedObjectMapper] Routing find() to MagicMapper');
            return $this->magicMapper->findInRegisterSchemaTable(
                identifier: $identifier,
                register: $register,
                schema: $schema
            );
        }

        $this->logger->debug('[UnifiedObjectMapper] Routing find() to ObjectEntityMapper (blob storage direct)');
        return $this->objectEntityMapper->findDirectBlobStorage(
            identifier: $identifier,
            register: $register,
            schema: $schema,
            includeDeleted: $includeDeleted,
            _rbac: $rbac,
            _multitenancy: $multitenancy
        );
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
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    Include deleted toggle is intentional
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Required for flexible query interface
     */
    public function findAll(
        ?int $limit=null,
        ?int $offset=null,
        ?array $filters=null,
        ?array $searchConditions=null,
        ?array $searchParams=null,
        array $sort=[],
        ?string $search=null,
        ?array $ids=null,
        ?string $uses=null,
        bool $includeDeleted=false,
        ?Register $register=null,
        ?Schema $schema=null,
        ?bool $published=null
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

        $this->logger->debug('[UnifiedObjectMapper] Routing findAll() to ObjectEntityMapper (blob storage direct)');
        return $this->objectEntityMapper->findAllDirectBlobStorage(
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
        $msg = '[UnifiedObjectMapper] Routing findBySchema() to ObjectEntityMapper (cross-register)';
        $this->logger->debug($msg);
        return $this->objectEntityMapper->findBySchema($schemaId);
    }//end findBySchema()

    /**
     * Insert a new object entity with event dispatching.
     *
     * Routes based on the entity's register and schema fields.
     *
     * @param Entity    $entity   Entity to insert.
     * @param ?Register $register Optional register for magic mapper routing.
     * @param ?Schema   $schema   Optional schema for magic mapper routing.
     *
     * @return ObjectEntity Inserted entity.
     *
     * @throws Exception If insertion fails.
     */
    public function insert(Entity $entity, ?Register $register=null, ?Schema $schema=null): Entity
    {
        if ($entity instanceof ObjectEntity === false) {
            throw new Exception('Entity must be an instance of ObjectEntity');
        }

        // Use provided register/schema or resolve from entity.
        if ($register === null || $schema === null) {
            [$register, $schema] = $this->resolveRegisterAndSchema($entity);
        }

        if ($this->shouldUseMagicMapper(register: $register, schema: $schema) === true) {
            $this->logger->debug('[UnifiedObjectMapper] Routing insert() to MagicMapper');
            return $this->magicMapper->insertObjectEntity(entity: $entity, register: $register, schema: $schema);
        }

        $this->logger->debug('[UnifiedObjectMapper] Using blob storage (via ObjectEntityMapper parent::insert)');
        // Call ObjectEntityMapper's blob storage insert directly by using its parent insert.
        // This avoids the circular loop where ObjectEntityMapper->insert() calls us back.
        // We replicate the blob storage logic here: parent::insert() + events.
        return $this->objectEntityMapper->insertDirectBlobStorage($entity);
    }//end insert()

    /**
     * Update an existing object entity with event dispatching.
     *
     * Routes based on the entity's register and schema fields.
     *
     * @param Entity    $entity   Entity to update.
     * @param ?Register $register Optional register for magic mapper routing.
     * @param ?Schema   $schema   Optional schema for magic mapper routing.
     *
     * @return ObjectEntity Updated entity.
     *
     * @throws Exception If update fails.
     */
    public function update(Entity $entity, ?Register $register=null, ?Schema $schema=null): Entity
    {
        if ($entity instanceof ObjectEntity === false) {
            throw new Exception('Entity must be an instance of ObjectEntity');
        }

        // Use provided register/schema or resolve from entity.
        if ($register === null || $schema === null) {
            [$register, $schema] = $this->resolveRegisterAndSchema($entity);
        }

        if ($this->shouldUseMagicMapper(register: $register, schema: $schema) === true) {
            $this->logger->debug('[UnifiedObjectMapper] Routing update() to MagicMapper');
            return $this->magicMapper->updateObjectEntity(entity: $entity, register: $register, schema: $schema);
        }

        $this->logger->debug('[UnifiedObjectMapper] Using blob storage (via ObjectEntityMapper parent::update)');
        return $this->objectEntityMapper->updateDirectBlobStorage($entity);
    }//end update()

    /**
     * Delete an object entity with event dispatching.
     *
     * Routes based on the entity's register and schema fields.
     *
     * @param Entity $entity Entity to delete.
     *
     * @return ObjectEntity Deleted entity.
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
            return $this->magicMapper->deleteObjectEntity(
                entity: $entity,
                register: $register,
                schema: $schema,
                hardDelete: true
            );
        }

        $this->logger->debug('[UnifiedObjectMapper] Routing delete() to ObjectEntityMapper');
        return $this->objectEntityMapper->delete(entity: $entity);
    }//end delete()

    /**
     * Lock an object.
     *
     * @param string   $uuid         The object UUID
     * @param int|null $lockDuration Lock duration in seconds
     *
     * @return array Lock result.
     *
     * @psalm-return array{locked: mixed, uuid: string}
     */
    public function lockObject(string $uuid, ?int $lockDuration=null): array
    {
        return $this->objectEntityMapper->lockObject(uuid: $uuid, lockDuration: $lockDuration);
    }//end lockObject()

    /**
     * Unlock an object.
     *
     * @param string $uuid The object UUID
     *
     * @return bool True on success
     */
    public function unlockObject(string $uuid): bool
    {
        return $this->objectEntityMapper->unlockObject($uuid);
    }//end unlockObject()

    /**
     * Ultra-fast bulk save operation with automatic routing
     *
     * Routes to MagicMapper for magic-mapped schemas or ObjectEntityMapper for blob storage.
     * Returns complete objects with database-computed classification (created/updated/unchanged).
     *
     * @param array         $insertObjects Objects to insert/upsert
     * @param array         $updateObjects Objects to update (legacy parameter, not used with magic mapper)
     * @param Register|null $register      Optional register context for routing decision
     * @param Schema|null   $schema        Optional schema context for routing decision
     *
     * @return array Array of complete objects with object_status field
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function ultraFastBulkSave(
        array $insertObjects=[],
        array $updateObjects=[],
        ?Register $register=null,
        ?Schema $schema=null
    ): array {
        $this->logger->info(
                '[UnifiedObjectMapper] ultraFastBulkSave called',
                [
                    'insertCount' => count($insertObjects),
                    'updateCount' => count($updateObjects),
                    'hasRegister' => $register !== null,
                    'hasSchema'   => $schema !== null,
                ]
                );

        // Try to resolve register and schema from object data if not provided.
        if ($register === null || $schema === null) {
            $this->logger->info('[UnifiedObjectMapper] Resolving register/schema from object data');

            // Extract register and schema IDs from first object.
            $firstObject = $insertObjects[0] ?? [];
            $registerId  = $firstObject['@self']['register'] ?? null;
            $schemaId    = $firstObject['@self']['schema'] ?? null;

            if ($registerId !== null && $register === null) {
                try {
                    $register = $this->registerMapper->find(id: $registerId, _multitenancy: false);
                } catch (\Exception $e) {
                    $this->logger->warning('[UnifiedObjectMapper] Failed to resolve register', ['id' => $registerId]);
                }
            }

            if ($schemaId !== null && $schema === null) {
                try {
                    $schema = $this->schemaMapper->find(id: $schemaId, _multitenancy: false);
                } catch (\Exception $e) {
                    $this->logger->warning('[UnifiedObjectMapper] Failed to resolve schema', ['id' => $schemaId]);
                }
            }

            $this->logger->info(
                    '[UnifiedObjectMapper] Resolved',
                    [
                        'register' => $register?->getId(),
                        'schema'   => $schema?->getId(),
                    ]
                    );
        }//end if

        // Check if magic mapping should be used.
        $this->logger->info('[UnifiedObjectMapper] Checking if magic mapping should be used');

        if ($this->shouldUseMagicMapper(register: $register, schema: $schema) === true) {
            $this->logger->info(
                '[UnifiedObjectMapper] Routing bulk save to MagicMapper',
                [
                    'register'     => $register?->getId(),
                    'schema'       => $schema?->getId(),
                    'object_count' => count($insertObjects),
                ]
            );

            // Build table name (without prefix - MagicMapper adds it).
            $tableName = 'openregister_table_'.$register->getId().'_'.$schema->getId();

            // Ensure table exists (create if needed).
            $this->logger->info('[UnifiedObjectMapper] Ensuring table exists', ['table' => $tableName]);
            $this->magicMapper->ensureTableForRegisterSchema(register: $register, schema: $schema);
            $this->logger->info('[UnifiedObjectMapper] Table ready');

            // Route to MagicBulkHandler via MagicMapper.
            $this->logger->info('[UnifiedObjectMapper] Calling magicMapper->bulkUpsert');

            $result = $this->magicMapper->bulkUpsert(
                objects: $insertObjects,
                register: $register,
                schema: $schema,
                tableName: $tableName
            );
            $this->logger->info('[UnifiedObjectMapper] bulkUpsert returned', ['resultCount' => count($result)]);

            return $result;
        }//end if

        // Fallback to blob storage.
        $this->logger->debug(
            '[UnifiedObjectMapper] Routing bulk save to ObjectEntityMapper (blob storage)',
            [
                'register'     => $register?->getId(),
                'schema'       => $schema?->getId(),
                'object_count' => count($insertObjects),
            ]
        );

        return $this->objectEntityMapper->ultraFastBulkSave(
            insertObjects: $insertObjects,
            updateObjects: $updateObjects
        );
    }//end ultraFastBulkSave()

    /**
     * Delete multiple objects.
     *
     * @param array $uuids      Object UUIDs to delete
     * @param bool  $hardDelete Whether to hard delete
     *
     * @return array Delete results
     *
     * @psalm-return list<mixed>
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Hard delete toggle controls permanent vs soft delete
     */
    public function deleteObjects(array $uuids=[], bool $hardDelete=false): array
    {
        return $this->objectEntityMapper->deleteObjects(uuids: $uuids, hardDelete: $hardDelete);
    }//end deleteObjects()

    /**
     * Publish multiple objects.
     *
     * @param array         $uuids    Object UUIDs to publish
     * @param DateTime|bool $datetime Publish datetime or true for now
     *
     * @return array Publish results
     *
     * @psalm-return list<mixed>
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) DateTime or bool controls publish timing
     */
    public function publishObjects(array $uuids=[], DateTime|bool $datetime=true): array
    {
        return $this->objectEntityMapper->publishObjects(uuids: $uuids, datetime: $datetime);
    }//end publishObjects()

    /**
     * Depublish multiple objects.
     *
     * @param array         $uuids    Object UUIDs to depublish
     * @param DateTime|bool $datetime Depublish datetime or true for now
     *
     * @return array Depublish results
     *
     * @psalm-return list<mixed>
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) DateTime or bool controls depublish timing
     */
    public function depublishObjects(array $uuids=[], DateTime|bool $datetime=true): array
    {
        return $this->objectEntityMapper->depublishObjects(uuids: $uuids, datetime: $datetime);
    }//end depublishObjects()

    /**
     * Get statistics.
     *
     * @param int|array|null $registerId Register ID filter
     * @param int|array|null $schemaId   Schema ID filter
     * @param array          $exclude    Exclusions
     *
     * @return int[] Statistics data
     *
     * @psalm-return array{total: int, size: int, invalid: int, deleted: int, locked: int, published: int}
     */
    public function getStatistics(
        int|array|null $registerId=null,
        int|array|null $schemaId=null,
        array $exclude=[]
    ): array {
        return $this->objectEntityMapper->getStatistics(
            registerId: $registerId,
            schemaId: $schemaId,
            exclude: $exclude
        );
    }//end getStatistics()

    /**
     * Get register chart data.
     *
     * @param int|null $registerId Register ID filter
     * @param int|null $schemaId   Schema ID filter
     *
     * @return (int|mixed|string)[][] Chart data
     *
     * @psalm-return array{labels: array<'Unknown'|mixed>, series: array<int>}
     */
    public function getRegisterChartData(?int $registerId=null, ?int $schemaId=null): array
    {
        return $this->objectEntityMapper->getRegisterChartData(registerId: $registerId, schemaId: $schemaId);
    }//end getRegisterChartData()

    /**
     * Get schema chart data.
     *
     * @param int|null $registerId Register ID filter
     * @param int|null $schemaId   Schema ID filter
     *
     * @return (int|mixed|string)[][] Chart data
     *
     * @psalm-return array{labels: array<'Unknown'|mixed>, series: array<int>}
     */
    public function getSchemaChartData(?int $registerId=null, ?int $schemaId=null): array
    {
        return $this->objectEntityMapper->getSchemaChartData(registerId: $registerId, schemaId: $schemaId);
    }//end getSchemaChartData()

    /**
     * Get simple facets.
     *
     * @param array $query Search query
     *
     * @return ((((int|mixed|string)[]|int|mixed|string)[]|mixed|string)[]|mixed|string)[][] Facets data.
     */
    public function getSimpleFacets(array $query=[]): array
    {
        return $this->objectEntityMapper->getSimpleFacets($query);
    }//end getSimpleFacets()

    /**
     * Get facetable fields from schemas.
     *
     * @param array $baseQuery Base query
     *
     * @return array[] Facetable fields
     *
     * @psalm-return array<string, array>
     */
    public function getFacetableFieldsFromSchemas(array $baseQuery=[]): array
    {
        return $this->objectEntityMapper->getFacetableFieldsFromSchemas($baseQuery);
    }//end getFacetableFieldsFromSchemas()

    /**
     * Search objects.
     *
     * @param array       $query         Search query
     * @param string|null $activeOrgUuid Organisation UUID
     * @param bool        $rbac          Apply RBAC
     * @param bool        $multitenancy  Apply multitenancy
     * @param array|null  $ids           Specific IDs
     * @param string|null $uses          Uses filter
     *
     * @return ObjectEntity[]
     *
     * @psalm-return list<OCA\OpenRegister\Db\ObjectEntity>
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
     */
    public function searchObjects(
        array $query=[],
        ?string $activeOrgUuid=null,
        bool $rbac=true,
        bool $multitenancy=true,
        ?array $ids=null,
        ?string $uses=null
    ): array|int {
        // Check if register and schema are specified in query for magic mapper routing.
        $registerId = $query['_register'] ?? $query['register'] ?? null;
        $schemaId   = $query['_schema'] ?? $query['schema'] ?? null;

        if ($registerId !== null && $schemaId !== null) {
            try {
                // Disable multitenancy for register/schema resolution (they're system-level).
                $register = $this->registerMapper->find((int) $registerId, _multitenancy: false, _rbac: false);
                $schema   = $this->schemaMapper->find((int) $schemaId, _multitenancy: false, _rbac: false);

                if ($this->shouldUseMagicMapper(register: $register, schema: $schema) === true) {
                    $this->logger->info('[UnifiedObjectMapper] Routing searchObjects() to MagicMapper');
                    return $this->magicMapper->searchObjectsInRegisterSchemaTable(
                        query: $query,
                        register: $register,
                        schema: $schema
                    );
                }
            } catch (\Exception $e) {
                $this->logger->warning(
                    '[UnifiedObjectMapper] Failed to resolve register/schema for magic mapper',
                    ['error' => $e->getMessage()]
                );
                // Fall through to blob storage.
            }
        }//end if

        $this->logger->debug('[UnifiedObjectMapper] Routing searchObjects() to blob storage (ObjectEntityMapper)');
        return $this->objectEntityMapper->searchObjects(
            query: $query,
            _activeOrgUuid: $activeOrgUuid,
            _rbac: $rbac,
            _multitenancy: $multitenancy,
            ids: $ids,
            uses: $uses
        );
    }//end searchObjects()

    /**
     * Count search objects.
     *
     * @param array       $query         Search query
     * @param string|null $activeOrgUuid Organisation UUID
     * @param bool        $rbac          Apply RBAC
     * @param bool        $multitenancy  Apply multitenancy
     * @param array|null  $ids           Specific IDs
     * @param string|null $uses          Uses filter
     *
     * @return int Object count
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
     */
    public function countSearchObjects(
        array $query=[],
        ?string $activeOrgUuid=null,
        bool $rbac=true,
        bool $multitenancy=true,
        ?array $ids=null,
        ?string $uses=null
    ): int {
        // Check if register and schema are specified in query for magic mapper routing.
        $registerId = $query['_register'] ?? $query['register'] ?? null;
        $schemaId   = $query['_schema'] ?? $query['schema'] ?? null;

        if ($registerId !== null && $schemaId !== null) {
            try {
                // Disable multitenancy for register/schema resolution (they're system-level).
                $register = $this->registerMapper->find((int) $registerId, _multitenancy: false, _rbac: false);
                $schema   = $this->schemaMapper->find((int) $schemaId, _multitenancy: false, _rbac: false);

                if ($this->shouldUseMagicMapper(register: $register, schema: $schema) === true) {
                    $this->logger->info('[UnifiedObjectMapper] Routing countSearchObjects() to MagicMapper');
                    return $this->magicMapper->countObjectsInRegisterSchemaTable(
                        query: $query,
                        register: $register,
                        schema: $schema
                    );
                }
            } catch (\Exception $e) {
                $this->logger->warning(
                    '[UnifiedObjectMapper] Failed to resolve register/schema for magic mapper count',
                    ['error' => $e->getMessage()]
                );
                // Fall through to blob storage.
            }
        }//end if

        $this->logger->debug('[UnifiedObjectMapper] Routing countSearchObjects() to blob storage (ObjectEntityMapper)');
        return $this->objectEntityMapper->countSearchObjects(
            query: $query,
            _activeOrgUuid: $activeOrgUuid,
            _rbac: $rbac,
            _multitenancy: $multitenancy,
            ids: $ids,
            uses: $uses
        );
    }//end countSearchObjects()

    /**
     * Optimized paginated search that loads register/schema once and performs both search and count.
     *
     * This method eliminates duplicate register/schema lookups by:
     * 1. Loading register and schema once at the start
     * 2. Performing both search and count with the cached objects
     * 3. Returning the register/schema for inclusion in response metadata
     *
     * @param array       $searchQuery   Query for search (with _limit, _offset).
     * @param array       $countQuery    Query for count (without pagination).
     * @param string|null $activeOrgUuid Active organization UUID.
     * @param bool        $rbac          Whether to apply RBAC.
     * @param bool        $multitenancy  Whether to apply multitenancy.
     * @param array|null  $ids           Optional ID filter.
     * @param string|null $uses          Optional uses filter.
     *
     * @return array{results: ObjectEntity[], total: int, register: ?array, schema: ?array}
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
     */
    public function searchObjectsPaginated(
        array $searchQuery=[],
        array $countQuery=[],
        ?string $activeOrgUuid=null,
        bool $rbac=true,
        bool $multitenancy=true,
        ?array $ids=null,
        ?string $uses=null
    ): array {
        // Extract register and schema IDs from query.
        $registerId = $searchQuery['_register'] ?? $searchQuery['register'] ?? null;
        $schemaId   = $searchQuery['_schema'] ?? $searchQuery['schema'] ?? null;

        $register = null;
        $schema   = null;
        $useMagicMapper = false;

        // Cache for loaded registers and schemas (indexed by ID for frontend lookup).
        $registersCache = [];
        $schemasCache   = [];

        // Load register and schema ONCE if both are specified.
        if ($registerId !== null && $schemaId !== null) {
            try {
                $register = $this->registerMapper->find((int) $registerId, _multitenancy: false, _rbac: false);
                $schema   = $this->schemaMapper->find((int) $schemaId, _multitenancy: false, _rbac: false);
                $useMagicMapper = $this->shouldUseMagicMapper(register: $register, schema: $schema);

                // Add to cache indexed by ID.
                $registersCache[$register->getId()] = $register->jsonSerialize();
                $schemasCache[$schema->getId()]     = $schema->jsonSerialize();
            } catch (\Exception $e) {
                $this->logger->warning(
                    '[UnifiedObjectMapper] Failed to resolve register/schema',
                    ['error' => $e->getMessage()]
                );
            }
        }

        // Perform search and count using the appropriate mapper.
        if ($useMagicMapper === true && $register !== null && $schema !== null) {
            $results = $this->magicMapper->searchObjectsInRegisterSchemaTable(
                query: $searchQuery,
                register: $register,
                schema: $schema
            );
            $total = $this->magicMapper->countObjectsInRegisterSchemaTable(
                query: $countQuery,
                register: $register,
                schema: $schema
            );
        } else {
            $results = $this->objectEntityMapper->searchObjects(
                query: $searchQuery,
                _activeOrgUuid: $activeOrgUuid,
                _rbac: $rbac,
                _multitenancy: $multitenancy,
                ids: $ids,
                uses: $uses
            );
            $total = $this->objectEntityMapper->countSearchObjects(
                query: $countQuery,
                _activeOrgUuid: $activeOrgUuid,
                _rbac: $rbac,
                _multitenancy: $multitenancy,
                ids: $ids,
                uses: $uses
            );

            // For blob storage results, collect unique register/schema IDs from results.
            // This handles queries that span multiple schemas.
            $uniqueRegisterIds = [];
            $uniqueSchemaIds   = [];

            foreach ($results as $result) {
                if ($result instanceof ObjectEntity) {
                    $regId = $result->getRegister();
                    $schId = $result->getSchema();

                    if ($regId !== null && !isset($registersCache[$regId])) {
                        $uniqueRegisterIds[$regId] = true;
                    }

                    if ($schId !== null && !isset($schemasCache[$schId])) {
                        $uniqueSchemaIds[$schId] = true;
                    }
                }
            }

            // Load any missing registers.
            foreach (array_keys($uniqueRegisterIds) as $regId) {
                try {
                    $reg = $this->registerMapper->find((int) $regId, _multitenancy: false, _rbac: false);
                    $registersCache[$reg->getId()] = $reg->jsonSerialize();
                } catch (\Exception $e) {
                    // Skip if not found.
                }
            }

            // Load any missing schemas.
            foreach (array_keys($uniqueSchemaIds) as $schId) {
                try {
                    $sch = $this->schemaMapper->find((int) $schId, _multitenancy: false, _rbac: false);
                    $schemasCache[$sch->getId()] = $sch->jsonSerialize();
                } catch (\Exception $e) {
                    // Skip if not found.
                }
            }
        }

        // Return results with registers/schemas indexed by ID for frontend lookup.
        return [
            'results'   => $results,
            'total'     => $total,
            'registers' => $registersCache,
            'schemas'   => $schemasCache,
        ];
    }//end searchObjectsPaginated()

    /**
     * Count all objects.
     *
     * @param array|null    $filters  Filters
     * @param Schema|null   $schema   Schema filter
     * @param Register|null $register Register filter
     *
     * @return int Object count
     */
    public function countAll(?array $filters=null, ?Schema $schema=null, ?Register $register=null): int
    {
        return $this->objectEntityMapper->countAll(_filters: $filters, schema: $schema, register: $register);
    }//end countAll()

    /**
     * Get query builder.
     *
     * @return IQueryBuilder Query builder instance
     */
    public function getQueryBuilder(): IQueryBuilder
    {
        return $this->objectEntityMapper->getQueryBuilder();
    }//end getQueryBuilder()

    /**
     * Get max allowed packet size.
     *
     * @return int Max packet size
     */
    public function getMaxAllowedPacketSize(): int
    {
        return $this->objectEntityMapper->getMaxAllowedPacketSize();
    }//end getMaxAllowedPacketSize()
}//end class
