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
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * Reason: Core mapper handles comprehensive query operations across both storage modes
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
        bool $_rbac=true,
        bool $_multitenancy=true
    ): ObjectEntity {
        if ($this->shouldUseMagicMapper(register: $register, schema: $schema) === true) {
            $this->logger->debug('[UnifiedObjectMapper] Routing find() to MagicMapper');
            return $this->magicMapper->findInRegisterSchemaTable(
                identifier: $identifier,
                register: $register,
                schema: $schema,
                rbac: $_rbac,
                multitenancy: $_multitenancy
            );
        }

        $this->logger->debug('[UnifiedObjectMapper] Routing find() to ObjectEntityMapper (blob storage direct)');
        return $this->objectEntityMapper->findDirectBlobStorage(
            identifier: $identifier,
            register: $register,
            schema: $schema,
            includeDeleted: $includeDeleted,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy
        );
    }//end find()

    /**
     * Find an object across all storage sources (blob storage and magic tables).
     *
     * This method searches both blob storage and all magic tables to find an object
     * by its identifier (UUID, slug, or URI) without requiring register/schema context.
     * This is useful for operations like audit trails, files, lock/unlock where the
     * caller may not know which storage backend contains the object.
     *
     * @param string|int $identifier     Object identifier (ID, UUID, slug, or URI).
     * @param bool       $includeDeleted Whether to include deleted objects.
     * @param bool       $_rbac          Whether to apply RBAC checks.
     * @param bool       $_multitenancy  Whether to apply multitenancy filtering.
     *
     * @return array{object: ObjectEntity, register: Register|null, schema: Schema|null}
     *               The found object with its register and schema context.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found in any source.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
     */
    public function findAcrossAllSources(
        string|int $identifier,
        bool $includeDeleted=false,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): array {
        $this->logger->debug('[UnifiedObjectMapper] findAcrossAllSources called', [
            'identifier' => $identifier,
        ]);

        return $this->objectEntityMapper->findAcrossAllSources(
            identifier: $identifier,
            includeDeleted: $includeDeleted,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy
        );
    }//end findAcrossAllSources()

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

        // Fetch the old object state BEFORE any updates for event dispatching.
        // Use the UUID (not numeric ID) to ensure we get the correct object.
        try {
            $oldEntity = $this->find(
                identifier: $entity->getUuid(),  // Use UUID, not ID!
                register: $register,
                schema: $schema,
                includeDeleted: false,
                _rbac: false,  // Skip RBAC for internal fetch
                _multitenancy: false  // Skip multitenancy for internal fetch
            );
        } catch (\Exception $e) {
            // If old object doesn't exist (shouldn't happen in update), use current entity.
            $this->logger->warning('[UnifiedObjectMapper] Could not fetch old entity for update event', [
                'entityId' => $entity->getId(),
                'entityUuid' => $entity->getUuid(),
                'error' => $e->getMessage()
            ]);
            $oldEntity = $entity;
        }

        if ($this->shouldUseMagicMapper(register: $register, schema: $schema) === true) {
            $this->logger->debug('[UnifiedObjectMapper] Routing update() to MagicMapper');
            return $this->magicMapper->updateObjectEntity(entity: $entity, register: $register, schema: $schema, oldEntity: $oldEntity);
        }

        $this->logger->debug('[UnifiedObjectMapper] Using blob storage (via ObjectEntityMapper parent::update)');
        return $this->objectEntityMapper->updateDirectBlobStorage($entity, $oldEntity);
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

        // MIXED SCHEMA SUPPORT: If schema is null and we have objects with different schemas,
        // group them by register+schema and process each group separately.
        if ($schema === null && count($insertObjects) > 0) {
            $this->logger->info('[UnifiedObjectMapper] Schema is null, checking for mixed schemas');

            // Check if we have mixed schemas by examining all objects.
            $schemaGroups = [];
            foreach ($insertObjects as $obj) {
                $objSchemaId = $obj['@self']['schema'] ?? null;
                $objRegisterId = $obj['@self']['register'] ?? ($register?->getId());
                if ($objSchemaId !== null) {
                    $groupKey = "{$objRegisterId}_{$objSchemaId}";
                    $schemaGroups[$groupKey][] = $obj;
                }
            }

            $this->logger->info(
                '[UnifiedObjectMapper] Schema grouping result',
                ['groupCount' => count($schemaGroups), 'groups' => array_keys($schemaGroups)]
            );

            // If we have multiple schema groups, process each separately.
            if (count($schemaGroups) > 1) {
                $this->logger->info(
                    '[UnifiedObjectMapper] Mixed schema batch detected, processing by schema groups',
                    ['groupCount' => count($schemaGroups), 'groups' => array_keys($schemaGroups)]
                );

                $allResults = [];
                foreach ($schemaGroups as $groupKey => $groupObjects) {
                    [$groupRegisterId, $groupSchemaId] = explode('_', $groupKey);

                    // Resolve register and schema for this group.
                    $groupRegister = $register;
                    $groupSchema = null;

                    if ($groupRegister === null && $groupRegisterId !== null) {
                        try {
                            $groupRegister = $this->registerMapper->find(id: (int) $groupRegisterId, _multitenancy: false);
                        } catch (\Exception $e) {
                            $this->logger->warning('[UnifiedObjectMapper] Failed to resolve register for group', ['id' => $groupRegisterId]);
                        }
                    }

                    if ($groupSchemaId !== null) {
                        try {
                            $groupSchema = $this->schemaMapper->find(id: (int) $groupSchemaId, _multitenancy: false);
                        } catch (\Exception $e) {
                            $this->logger->warning('[UnifiedObjectMapper] Failed to resolve schema for group', ['id' => $groupSchemaId]);
                        }
                    }

                    // Process this group with its specific register+schema.
                    $groupResults = $this->ultraFastBulkSaveSingleSchema(
                        insertObjects: $groupObjects,
                        updateObjects: [],
                        register: $groupRegister,
                        schema: $groupSchema
                    );

                    $allResults = array_merge($allResults, $groupResults);
                }

                return $allResults;
            }
        }

        // Single schema processing (or schema was explicitly provided).
        return $this->ultraFastBulkSaveSingleSchema(
            insertObjects: $insertObjects,
            updateObjects: $updateObjects,
            register: $register,
            schema: $schema
        );
    }//end ultraFastBulkSave()

    /**
     * Ultra-fast bulk save for a single schema (internal method).
     *
     * @param array         $insertObjects Objects to insert/upsert
     * @param array         $updateObjects Objects to update
     * @param Register|null $register      Register context
     * @param Schema|null   $schema        Schema context
     *
     * @return array Array of complete objects with object_status field
     */
    private function ultraFastBulkSaveSingleSchema(
        array $insertObjects,
        array $updateObjects,
        ?Register $register,
        ?Schema $schema
    ): array {
        // Try to resolve register and schema from object data if not provided.
        if ($register === null || $schema === null) {
            $this->logger->debug('[UnifiedObjectMapper] Resolving register/schema from object data');

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

            $this->logger->debug(
                '[UnifiedObjectMapper] Resolved',
                [
                    'register' => $register?->getId(),
                    'schema'   => $schema?->getId(),
                ]
            );
        }//end if

        // Check if magic mapping should be used.
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
            $this->logger->debug('[UnifiedObjectMapper] Ensuring table exists', ['table' => $tableName]);
            $this->magicMapper->ensureTableForRegisterSchema(register: $register, schema: $schema);
            $this->logger->debug('[UnifiedObjectMapper] Table ready');

            // Route to MagicBulkHandler via MagicMapper.
            $result = $this->magicMapper->bulkUpsert(
                objects: $insertObjects,
                register: $register,
                schema: $schema,
                tableName: $tableName
            );
            $this->logger->debug('[UnifiedObjectMapper] bulkUpsert returned', ['resultCount' => count($result)]);

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
    }//end ultraFastBulkSaveSingleSchema()

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
     * Routes to MagicMapper if magic mapping is enabled for the register+schema combination,
     * otherwise uses ObjectEntityMapper blob storage for faceting.
     *
     * @param array $query Search query containing register, schema, and _facets configuration.
     *
     * @return ((((int|mixed|string)[]|int|mixed|string)[]|mixed|string)[]|mixed|string)[][] Facets data.
     */
    public function getSimpleFacets(array $query=[]): array
    {
        // Check if register and schema(s) are specified in query for magic mapper routing.
        $registerId = $query['@self']['register'] ?? $query['_register'] ?? $query['register'] ?? null;
        $schemaIds  = $query['@self']['schemas'] ?? $query['_schemas'] ?? null;
        $schemaId   = $query['@self']['schema'] ?? $query['_schema'] ?? $query['schema'] ?? null;

        // If _schemas is provided (array of schema IDs), use multi-schema faceting.
        if ($registerId !== null && $schemaIds !== null && is_array($schemaIds) === true) {
            return $this->getSimpleFacetsMultiSchema(
                query: $query,
                registerId: (int) $registerId,
                schemaIds: array_map('intval', $schemaIds)
            );
        }

        // Single schema faceting.
        if ($registerId !== null && $schemaId !== null) {
            try {
                // Disable multitenancy for register/schema resolution (they're system-level).
                $register = $this->registerMapper->find((int) $registerId, _multitenancy: false, _rbac: false);
                $schema   = $this->schemaMapper->find((int) $schemaId, _multitenancy: false, _rbac: false);

                if ($this->shouldUseMagicMapper(register: $register, schema: $schema) === true) {
                    return $this->magicMapper->getSimpleFacetsFromRegisterSchemaTable(
                        query: $query,
                        register: $register,
                        schema: $schema
                    );
                }
            } catch (\Exception $e) {
                $this->logger->warning(
                    '[UnifiedObjectMapper] Failed to resolve register/schema for magic mapper facets',
                    ['error' => $e->getMessage()]
                );
                // Fall through to blob storage.
            }
        }//end if

        return $this->objectEntityMapper->getSimpleFacets($query);
    }//end getSimpleFacets()

    /**
     * Get facets aggregated across multiple schemas.
     *
     * @param array $query      The search query.
     * @param int   $registerId The register ID.
     * @param array $schemaIds  Array of schema IDs to aggregate.
     *
     * @return array Merged facet results.
     */
    private function getSimpleFacetsMultiSchema(array $query, int $registerId, array $schemaIds): array
    {
        $mergedFacets = [];

        try {
            $register = $this->registerMapper->find($registerId, _multitenancy: false, _rbac: false);
        } catch (\Exception $e) {
            $this->logger->warning(
                '[UnifiedObjectMapper] Failed to find register for multi-schema facets',
                [
                    'registerId' => $registerId,
                    'error'      => $e->getMessage(),
                ]
            );
            return [];
        }

        foreach ($schemaIds as $schemaId) {
            try {
                $schema = $this->schemaMapper->find($schemaId, _multitenancy: false, _rbac: false);

                if ($this->shouldUseMagicMapper(register: $register, schema: $schema) === false) {
                    continue;
                }

                // Get facets for this schema.
                $schemaFacets = $this->magicMapper->getSimpleFacetsFromRegisterSchemaTable(
                    query: $query,
                    register: $register,
                    schema: $schema
                );

                // Merge into combined results.
                $mergedFacets = $this->mergeFacetResults(existing: $mergedFacets, new: $schemaFacets);
            } catch (\Exception $e) {
                $this->logger->warning(
                    '[UnifiedObjectMapper] Failed to get facets for schema',
                    [
                        'schemaId' => $schemaId,
                        'error'    => $e->getMessage(),
                    ]
                );
                // Continue with other schemas.
            }//end try
        }//end foreach

        return $mergedFacets;
    }//end getSimpleFacetsMultiSchema()

    /**
     * Merge facet results from multiple schemas.
     *
     * @param array $existing Existing facet results.
     * @param array $new      New facet results to merge.
     *
     * @return array Merged facet results.
     */
    private function mergeFacetResults(array $existing, array $new): array
    {
        foreach ($new as $facetKey => $facetData) {
            if (isset($existing[$facetKey]) === false) {
                $existing[$facetKey] = $facetData;
                continue;
            }

            // Handle @self metadata facets.
            if ($facetKey === '@self' && is_array($facetData) === true) {
                foreach ($facetData as $metaKey => $metaFacet) {
                    if (isset($existing['@self'][$metaKey]) === false) {
                        $existing['@self'][$metaKey] = $metaFacet;
                        continue;
                    }

                    // Merge buckets.
                    $existing['@self'][$metaKey] = $this->mergeFacetBuckets(
                        existing: $existing['@self'][$metaKey],
                        new: $metaFacet
                    );
                }

                continue;
            }

            // Merge object field facets.
            if (is_array($facetData) === true && isset($facetData['buckets']) === true) {
                $existing[$facetKey] = $this->mergeFacetBuckets(existing: $existing[$facetKey], new: $facetData);
            }
        }//end foreach

        return $existing;
    }//end mergeFacetResults()

    /**
     * Merge facet buckets, combining counts for same keys.
     *
     * @param array $existing Existing facet with buckets.
     * @param array $new      New facet with buckets to merge.
     *
     * @return array Merged facet.
     */
    private function mergeFacetBuckets(array $existing, array $new): array
    {
        $existingBuckets = $existing['buckets'] ?? [];
        $newBuckets      = $new['buckets'] ?? [];

        // Index existing buckets by key for fast lookup.
        $bucketIndex = [];
        foreach ($existingBuckets as $idx => $bucket) {
            $key = $bucket['key'] ?? '';
            $bucketIndex[$key] = $idx;
        }

        // Merge new buckets.
        foreach ($newBuckets as $newBucket) {
            $key = $newBucket['key'] ?? '';
            if (isset($bucketIndex[$key]) === true) {
                // Add counts.
                $idx = $bucketIndex[$key];
                $existingBuckets[$idx]['results'] += $newBucket['results'] ?? 0;
                continue;
            }

            // Add new bucket.
            $existingBuckets[] = $newBucket;
            $bucketIndex[$key] = count($existingBuckets) - 1;
        }

        // Re-sort by results descending.
        usort(
            $existingBuckets,
            function (array $a, array $b): int {
                return ($b['results'] ?? 0) - ($a['results'] ?? 0);
            }
        );

        $existing['buckets'] = $existingBuckets;
        return $existing;
    }//end mergeFacetBuckets()

    /**
     * Search objects across multiple schemas using magic mapper tables.
     *
     * This method queries each schema's magic mapper table and combines the results
     * with proper pagination support. For efficient pagination across multiple tables,
     * we fetch more results than needed and then apply final pagination.
     *
     * @param array       $searchQuery   Search query parameters
     * @param array       $countQuery    Count query parameters
     * @param int         $registerId    Register ID
     * @param array       $schemaIds     Array of schema IDs to search
     * @param string|null $activeOrgUuid Organisation UUID
     * @param bool        $rbac          Apply RBAC
     * @param bool        $multitenancy  Apply multitenancy
     * @param array|null  $ids           Specific IDs to filter
     * @param string|null $uses          Uses filter
     *
     * @return array{results: ObjectEntity[], total: int, registers: array, schemas: array}
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
     * @psalm-suppress                              UnusedParam Parameters reserved for future per-schema security filtering.
     */
    private function searchObjectsPaginatedMultiSchema(
        array $searchQuery,
        array $countQuery,
        int $registerId,
        array $schemaIds,
        ?string $activeOrgUuid=null,
        bool $rbac=true,
        bool $multitenancy=true,
        ?array $ids=null,
        ?string $uses=null
    ): array {
        // Extract pagination parameters.
        $limit  = (int) ($searchQuery['_limit'] ?? 20);
        $offset = (int) ($searchQuery['_offset'] ?? 0);

        // Cache for loaded registers and schemas.
        $registersCache = [];
        $schemasCache   = [];

        // Load register once.
        try {
            $register = $this->registerMapper->find($registerId, _multitenancy: false, _rbac: false);
            $registersCache[$register->getId()] = $register->jsonSerialize();
        } catch (\Exception $e) {
            $this->logger->warning(
                '[UnifiedObjectMapper] Failed to find register for multi-schema search',
                [
                    'registerId' => $registerId,
                    'error'      => $e->getMessage(),
                ]
            );
            return [
                'results'   => [],
                'total'     => 0,
                'registers' => [],
                'schemas'   => [],
            ];
        }

        $allResults = [];
        $totalCount = 0;

        // Query each schema.
        foreach ($schemaIds as $schemaId) {
            try {
                $schema = $this->schemaMapper->find((int) $schemaId, _multitenancy: false, _rbac: false);
                $schemasCache[$schema->getId()] = $schema->jsonSerialize();

                // Check if magic mapper should be used for this schema.
                if ($this->shouldUseMagicMapper(register: $register, schema: $schema) === false) {
                    $this->logger->debug(
                        '[UnifiedObjectMapper] Skipping non-magic-mapper schema',
                        [
                            'schemaId' => $schemaId,
                        ]
                    );
                    continue;
                }

                // Get count for this schema.
                $schemaCount = $this->magicMapper->countObjectsInRegisterSchemaTable(
                    query: $countQuery,
                    register: $register,
                    schema: $schema
                );
                $totalCount += $schemaCount;

                // For results, we need to fetch enough to cover pagination.
                // Fetch up to (offset + limit) from each table to ensure we have enough results.
                $schemaSearchQuery            = $searchQuery;
                $schemaSearchQuery['_limit']  = $offset + $limit;
                $schemaSearchQuery['_offset'] = 0;

                $schemaResults = $this->magicMapper->searchObjectsInRegisterSchemaTable(
                    query: $schemaSearchQuery,
                    register: $register,
                    schema: $schema
                );

                // Add schema ID to each result for sorting reference.
                foreach ($schemaResults as $result) {
                    $allResults[] = $result;
                }
            } catch (\Exception $e) {
                $this->logger->warning(
                    '[UnifiedObjectMapper] Failed to search schema in multi-schema search',
                    [
                        'schemaId' => $schemaId,
                        'error'    => $e->getMessage(),
                    ]
                );
                // Continue with other schemas.
            }//end try
        }//end foreach

        // Sort combined results by updated/created date descending (most recent first).
        usort(
            $allResults,
            function ($a, $b) {
                $aDate = $a->getUpdated() ?? $a->getCreated() ?? new DateTime('1970-01-01');
                $bDate = $b->getUpdated() ?? $b->getCreated() ?? new DateTime('1970-01-01');

                if ($aDate instanceof DateTime && $bDate instanceof DateTime) {
                    return $bDate->getTimestamp() - $aDate->getTimestamp();
                }

                return 0;
            }
        );

        // Apply final pagination.
        $paginatedResults = array_slice($allResults, $offset);
        if ($limit > 0) {
            $paginatedResults = array_slice($allResults, $offset, $limit);
        }

        return [
            'results'   => $paginatedResults,
            'total'     => $totalCount,
            'registers' => $registersCache,
            'schemas'   => $schemasCache,
        ];
    }//end searchObjectsPaginatedMultiSchema()

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
        // Support both top-level keys (_register, register) and @self nested keys.
        $registerId = $query['@self']['register']
            ?? $query['_register']
            ?? $query['register']
            ?? null;
        $schemaId   = $query['@self']['schema']
            ?? $query['_schema']
            ?? $query['schema']
            ?? null;

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
        // Support both top-level keys (_register, register) and @self nested keys.
        $registerId = $query['@self']['register']
            ?? $query['_register']
            ?? $query['register']
            ?? null;
        $schemaId   = $query['@self']['schema']
            ?? $query['_schema']
            ?? $query['schema']
            ?? null;

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
        // Support both top-level keys (_register, register) and @self nested keys.
        $registerId = $searchQuery['@self']['register']
            ?? $searchQuery['_register']
            ?? $searchQuery['register']
            ?? null;
        $schemaId   = $searchQuery['@self']['schema']
            ?? $searchQuery['_schema']
            ?? $searchQuery['schema']
            ?? null;
        $schemaIds  = $searchQuery['@self']['schemas']
            ?? $searchQuery['_schemas']
            ?? null;

        $register       = null;
        $schema         = null;
        $useMagicMapper = false;

        // Cache for loaded registers and schemas (indexed by ID for frontend lookup).
        $registersCache = [];
        $schemasCache   = [];

        // Check for multi-schema search (when _schemas is provided but _schema is not).
        $isMultiSchemaSearch = $registerId !== null
            && $schemaId === null
            && $schemaIds !== null
            && is_array($schemaIds) === true
            && count($schemaIds) > 0;
        if ($isMultiSchemaSearch === true) {
            return $this->searchObjectsPaginatedMultiSchema(
                searchQuery: $searchQuery,
                countQuery: $countQuery,
                registerId: (int) $registerId,
                schemaIds: $schemaIds,
                activeOrgUuid: $activeOrgUuid,
                rbac: $rbac,
                multitenancy: $multitenancy,
                ids: $ids,
                uses: $uses
            );
        }

        // Load register and schema ONCE if both are specified.
        if ($registerId !== null && $schemaId !== null) {
            try {
                $register       = $this->registerMapper->find((int) $registerId, _multitenancy: false, _rbac: false);
                $schema         = $this->schemaMapper->find((int) $schemaId, _multitenancy: false, _rbac: false);
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
        $canUseMagicMapper = $useMagicMapper === true && $register !== null && $schema !== null;
        if ($canUseMagicMapper === true) {
            $results = $this->magicMapper->searchObjectsInRegisterSchemaTable(
                query: $searchQuery,
                register: $register,
                schema: $schema
            );
            $total   = $this->magicMapper->countObjectsInRegisterSchemaTable(
                query: $countQuery,
                register: $register,
                schema: $schema
            );

            // Return results with registers/schemas indexed by ID for frontend lookup.
            return [
                'results'   => $results,
                'total'     => $total,
                'registers' => $registersCache,
                'schemas'   => $schemasCache,
            ];
        }

        // Use objectEntityMapper for blob storage.
        $results = $this->objectEntityMapper->searchObjects(
            query: $searchQuery,
            _activeOrgUuid: $activeOrgUuid,
            _rbac: $rbac,
            _multitenancy: $multitenancy,
            ids: $ids,
            uses: $uses
        );
        $total   = $this->objectEntityMapper->countSearchObjects(
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

                if ($regId !== null && isset($registersCache[$regId]) === false) {
                    $uniqueRegisterIds[$regId] = true;
                }

                if ($schId !== null && isset($schemasCache[$schId]) === false) {
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
