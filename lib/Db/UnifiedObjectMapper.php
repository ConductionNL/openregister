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
use OCP\EventDispatcher\IEventDispatcher;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
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
     * @param IEventDispatcher   $eventDispatcher    Event dispatcher for lifecycle events.
     * @param MagicRbacHandler   $rbacHandler        RBAC handler for permission checks.
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly MagicMapper $magicMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly LoggerInterface $logger,
        private readonly IEventDispatcher $eventDispatcher,
        private readonly MagicRbacHandler $rbacHandler
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
     * 2. If both register and schema are provided → always use MagicMapper
     *
     * MagicMapper is always used when we have register+schema context because:
     * - It provides better query performance with proper SQL tables
     * - It enables UNION queries across multiple schemas
     * - It supports proper filtering and full-text search
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
                message: '[UnifiedObjectMapper] No register/schema context, using blob storage',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return false;
        }

        // Always use MagicMapper when we have register+schema context.
        $this->logger->debug(
            message: '[UnifiedObjectMapper] Using MagicMapper for register+schema combination',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'registerId' => $register->getId(),
                'schemaId'   => $schema->getId(),
                'schemaSlug' => $schema->getSlug(),
            ]
        );

        return true;
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
                    message: '[UnifiedObjectMapper] Failed to resolve register from entity',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'registerId' => $entity->getRegister(), 'error' => $e->getMessage()]
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
                    message: '[UnifiedObjectMapper] Failed to resolve schema from entity',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'schemaId' => $entity->getSchema(), 'error' => $e->getMessage()]
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
            $this->logger->debug(
                message: '[UnifiedObjectMapper] Routing find() to MagicMapper',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            $entity = $this->magicMapper->findInRegisterSchemaTable(
                identifier: $identifier,
                register: $register,
                schema: $schema,
                rbac: $rbac,
                multitenancy: $multitenancy
            );
            // Set source to indicate data came from magic tables (ORM).
            $entity->setSource('orm');
            return $entity;
        }

        $this->logger->debug(
            message: '[UnifiedObjectMapper] Routing find() to ObjectEntityMapper (blob storage direct)',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
        $entity = $this->objectEntityMapper->findDirectBlobStorage(
            identifier: $identifier,
            register: $register,
            schema: $schema,
            includeDeleted: $includeDeleted,
            _rbac: $rbac,
            _multitenancy: $multitenancy
        );
        // Set source to indicate data came from blob storage.
        $entity->setSource('blob');
        return $entity;
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
        $this->logger->debug(
            message: '[UnifiedObjectMapper] findAcrossAllSources called',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'identifier' => $identifier,
            ]
                );

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
            $this->logger->debug(
                message: '[UnifiedObjectMapper] Routing findAll() to MagicMapper',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            $entities = $this->magicMapper->findAllInRegisterSchemaTable(
                register: $register,
                schema: $schema,
                limit: $limit,
                offset: $offset,
                filters: $filters,
                sort: $sort,
                published: $published
            );
            // Set source to indicate data came from magic tables (ORM).
            foreach ($entities as $entity) {
                $entity->setSource('orm');
            }

            return $entities;
        }//end if

        $this->logger->debug(
            message: '[UnifiedObjectMapper] Routing findAll() to ObjectEntityMapper (blob storage direct)',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
        $entities = $this->objectEntityMapper->findAllDirectBlobStorage(
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
        // Set source to indicate data came from blob storage.
        foreach ($entities as $entity) {
            $entity->setSource('blob');
        }

        return $entities;
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
        $this->logger->debug(
            message: '[UnifiedObjectMapper] Routing findMultiple() to ObjectEntityMapper (cross-schema operation)',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
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
        $this->logger->debug(
            message: '[UnifiedObjectMapper] Routing findBySchema() to ObjectEntityMapper (cross-register)',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
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
            [$register, $schema] = $this->resolveRegisterAndSchema(entity: $entity);
        }

        if ($this->shouldUseMagicMapper(register: $register, schema: $schema) === true) {
            $this->logger->debug(
                message: '[UnifiedObjectMapper] Routing insert() to MagicMapper',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            $insertedEntity = $this->magicMapper->insertObjectEntity(entity: $entity, register: $register, schema: $schema);
        } else {
            $this->logger->debug(
                message: '[UnifiedObjectMapper] Using blob storage (via ObjectEntityMapper parent::insert)',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            // Call ObjectEntityMapper's blob storage insert directly by using its parent insert.
            // This avoids the circular loop where ObjectEntityMapper->insert() calls us back.
            // We replicate the blob storage logic here: parent::insert() + events.
            $insertedEntity = $this->objectEntityMapper->insertDirectBlobStorage($entity);
        }

        // Dispatch ObjectCreatedEvent after successful insert.
        $this->logger->debug(
            message: '[UnifiedObjectMapper] Dispatching ObjectCreatedEvent',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'entityUuid' => $insertedEntity->getUuid(),
            ]
        );
        $this->eventDispatcher->dispatchTyped(new ObjectCreatedEvent(object: $insertedEntity));

        return $insertedEntity;
    }//end insert()

    /**
     * Update an existing object entity with event dispatching.
     *
     * Routes based on the entity's register and schema fields.
     *
     * @param Entity            $entity    Entity to update.
     * @param Register|null     $register  Optional register for magic mapper routing.
     * @param Schema|null       $schema    Optional schema for magic mapper routing.
     * @param ObjectEntity|null $oldEntity Old entity for comparison.
     *
     * @return ObjectEntity Updated entity.
     *
     * @throws Exception If update fails.
     */
    public function update(Entity $entity, ?Register $register=null, ?Schema $schema=null, ?ObjectEntity $oldEntity=null): Entity
    {
        if ($entity instanceof ObjectEntity === false) {
            throw new Exception('Entity must be an instance of ObjectEntity');
        }

        // Use provided register/schema or resolve from entity.
        if ($register === null || $schema === null) {
            [$register, $schema] = $this->resolveRegisterAndSchema(entity: $entity);
        }

        // Use provided oldEntity (preferred) or fetch from DB as fallback.
        // The caller (SaveObject) should capture oldEntity BEFORE modifying the entity.
        if ($oldEntity === null) {
            // Fetch the old object state BEFORE any updates for event dispatching.
            // Use the UUID (not numeric ID) to ensure we get the correct object.
            try {
                $oldEntity = $this->find(
                    identifier: $entity->getUuid(),
                // Use UUID, not ID!
                    register: $register,
                    schema: $schema,
                    includeDeleted: false,
                    rbac: false,
                // Skip RBAC for internal fetch.
                    multitenancy: false
                // Skip multitenancy for internal fetch.
                );
            } catch (\Exception $e) {
                // If old object doesn't exist (shouldn't happen in update), use current entity.
                $this->logger->warning(
                    message: '[UnifiedObjectMapper] Could not fetch old entity for update event',
                    context: [
                        'file'       => __FILE__,
                        'line'       => __LINE__,
                        'entityId'   => $entity->getId(),
                        'entityUuid' => $entity->getUuid(),
                        'error'      => $e->getMessage(),
                    ]
                );
                $oldEntity = $entity;
            }//end try
        }//end if

        if ($this->shouldUseMagicMapper(register: $register, schema: $schema) === true) {
            $this->logger->debug(
                message: '[UnifiedObjectMapper] Routing update() to MagicMapper',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            $updatedEntity = $this->magicMapper->updateObjectEntity(entity: $entity, register: $register, schema: $schema, oldEntity: $oldEntity);
        } else {
            $this->logger->debug(
                message: '[UnifiedObjectMapper] Using blob storage (via ObjectEntityMapper parent::update)',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            $updatedEntity = $this->objectEntityMapper->updateDirectBlobStorage($entity, $oldEntity);
        }

        // Dispatch ObjectUpdatedEvent after successful update.
        $this->logger->debug(
            message: '[UnifiedObjectMapper] Dispatching ObjectUpdatedEvent',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'entityUuid' => $updatedEntity->getUuid(),
            ]
        );
        $this->eventDispatcher->dispatchTyped(new ObjectUpdatedEvent(newObject: $updatedEntity, oldObject: $oldEntity));

        return $updatedEntity;
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

        [$register, $schema] = $this->resolveRegisterAndSchema(entity: $entity);

        if ($this->shouldUseMagicMapper(register: $register, schema: $schema) === true) {
            $this->logger->debug(
                message: '[UnifiedObjectMapper] Routing delete() to MagicMapper',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            $deletedEntity = $this->magicMapper->deleteObjectEntity(
                entity: $entity,
                register: $register,
                schema: $schema,
                hardDelete: true
            );

            // Dispatch ObjectDeletedEvent after successful delete (MagicMapper doesn't dispatch events).
            $this->logger->debug(
                message: '[UnifiedObjectMapper] Dispatching ObjectDeletedEvent',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'entityUuid' => $deletedEntity->getUuid(),
                ]
            );
            $this->eventDispatcher->dispatchTyped(new ObjectDeletedEvent(object: $deletedEntity));
        } else {
            $this->logger->debug(
                message: '[UnifiedObjectMapper] Routing delete() to ObjectEntityMapper',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            // NOTE: ObjectEntityMapper.delete() handles its own event dispatching for blob storage.
            // Do NOT dispatch ObjectDeletedEvent here to avoid duplicates.
            $deletedEntity = $this->objectEntityMapper->delete(entity: $entity);
        }//end if

        return $deletedEntity;
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
            message: '[UnifiedObjectMapper] ultraFastBulkSave called',
            context: [
                'file'        => __FILE__,
                'line'        => __LINE__,
                'insertCount' => count($insertObjects),
                'updateCount' => count($updateObjects),
                'hasRegister' => $register !== null,
                'hasSchema'   => $schema !== null,
            ]
        );

        // MIXED SCHEMA SUPPORT: If schema is null and we have objects with different schemas,
        // group them by register+schema and process each group separately.
        if ($schema === null && count($insertObjects) > 0) {
            $this->logger->info(
                message: '[UnifiedObjectMapper] Schema is null, checking for mixed schemas',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            // Check if we have mixed schemas by examining all objects.
            $schemaGroups = [];
            foreach ($insertObjects as $obj) {
                $objSchemaId   = $obj['@self']['schema'] ?? null;
                $objRegisterId = $obj['@self']['register'] ?? ($register?->getId());
                if ($objSchemaId !== null) {
                    $groupKey = "{$objRegisterId}_{$objSchemaId}";
                    $schemaGroups[$groupKey][] = $obj;
                }
            }

            $this->logger->info(
                message: '[UnifiedObjectMapper] Schema grouping result',
                context: ['file' => __FILE__, 'line' => __LINE__, 'groupCount' => count($schemaGroups), 'groups' => array_keys($schemaGroups)]
            );

            // If we have multiple schema groups, process each separately.
            if (count($schemaGroups) > 1) {
                $this->logger->info(
                    message: '[UnifiedObjectMapper] Mixed schema batch detected, processing by schema groups',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'groupCount' => count($schemaGroups), 'groups' => array_keys($schemaGroups)]
                );

                $allResults = [];
                foreach ($schemaGroups as $groupKey => $groupObjects) {
                    [$groupRegisterId, $groupSchemaId] = explode('_', $groupKey);

                    // Resolve register and schema for this group.
                    $groupRegister = $register;
                    $groupSchema   = null;

                    if ($groupRegister === null && $groupRegisterId !== null) {
                        try {
                            $groupRegister = $this->registerMapper->find(id: (int) $groupRegisterId, multitenancy: false);
                        } catch (\Exception $e) {
                            $this->logger->warning(
                                message: '[UnifiedObjectMapper] Failed to resolve register for group',
                                context: ['file' => __FILE__, 'line' => __LINE__, 'id' => $groupRegisterId]
                            );
                        }
                    }

                    if ($groupSchemaId !== null) {
                        try {
                            $groupSchema = $this->schemaMapper->find(id: (int) $groupSchemaId, multitenancy: false);
                        } catch (\Exception $e) {
                            $this->logger->warning(
                                message: '[UnifiedObjectMapper] Failed to resolve schema for group',
                                context: ['file' => __FILE__, 'line' => __LINE__, 'id' => $groupSchemaId]
                            );
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
                }//end foreach

                return $allResults;
            }//end if
        }//end if

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
            $this->logger->debug(
                message: '[UnifiedObjectMapper] Resolving register/schema from object data',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            // Extract register and schema IDs from first object.
            $firstObject = $insertObjects[0] ?? [];
            $registerId  = $firstObject['@self']['register'] ?? null;
            $schemaId    = $firstObject['@self']['schema'] ?? null;

            if ($registerId !== null && $register === null) {
                try {
                    $register = $this->registerMapper->find(id: $registerId, multitenancy: false);
                } catch (\Exception $e) {
                    $this->logger->warning(
                        message: '[UnifiedObjectMapper] Failed to resolve register',
                        context: ['file' => __FILE__, 'line' => __LINE__, 'id' => $registerId]
                    );
                }
            }

            if ($schemaId !== null && $schema === null) {
                try {
                    $schema = $this->schemaMapper->find(id: $schemaId, multitenancy: false);
                } catch (\Exception $e) {
                    $this->logger->warning(
                        message: '[UnifiedObjectMapper] Failed to resolve schema',
                        context: ['file' => __FILE__, 'line' => __LINE__, 'id' => $schemaId]
                    );
                }
            }

            $this->logger->debug(
                message: '[UnifiedObjectMapper] Resolved',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'register' => $register?->getId(),
                    'schema'   => $schema?->getId(),
                ]
            );
        }//end if

        // Check if magic mapping should be used.
        if ($this->shouldUseMagicMapper(register: $register, schema: $schema) === true) {
            $this->logger->info(
                message: '[UnifiedObjectMapper] Routing bulk save to MagicMapper',
                context: [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'register'     => $register?->getId(),
                    'schema'       => $schema?->getId(),
                    'object_count' => count($insertObjects),
                ]
            );

            // Build table name (without prefix - MagicMapper adds it).
            $tableName = 'openregister_table_'.$register->getId().'_'.$schema->getId();

            // Ensure table exists (create if needed).
            $this->logger->debug(
                message: '[UnifiedObjectMapper] Ensuring table exists',
                context: ['file' => __FILE__, 'line' => __LINE__, 'table' => $tableName]
            );
            $this->magicMapper->ensureTableForRegisterSchema(register: $register, schema: $schema);
            $this->logger->debug(
                message: '[UnifiedObjectMapper] Table ready',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            // Route to MagicBulkHandler via MagicMapper.
            $result = $this->magicMapper->bulkUpsert(
                objects: $insertObjects,
                register: $register,
                schema: $schema,
                tableName: $tableName
            );
            $this->logger->debug(
                message: '[UnifiedObjectMapper] bulkUpsert returned',
                context: ['file' => __FILE__, 'line' => __LINE__, 'resultCount' => count($result)]
            );

            return $result;
        }//end if

        // Fallback to blob storage.
        $this->logger->debug(
            message: '[UnifiedObjectMapper] Routing bulk save to ObjectEntityMapper (blob storage)',
            context: [
                'file'         => __FILE__,
                'line'         => __LINE__,
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

        // Extract register IDs (plural) for multi-register faceting.
        $registerIds = $query['@self']['registers'] ?? $query['_registers'] ?? null;

        // If _schemas is provided (array of schema IDs), use multi-schema faceting.
        // Supports both single-register and multi-register.
        if ($schemaIds !== null && is_array($schemaIds) === true
            && ($registerId !== null || ($registerIds !== null && is_array($registerIds) === true && count($registerIds) > 0))
        ) {
            if ($registerIds !== null && is_array($registerIds) === true && count($registerIds) > 0) {
                $allRegisterIds = array_map('intval', $registerIds);
            } else {
                $allRegisterIds = [(int) $registerId];
            }

            return $this->getSimpleFacetsMultiSchema(
                query: $query,
                registerIds: $allRegisterIds,
                schemaIds: array_map('intval', $schemaIds)
            );
        }

        // Single schema faceting.
        if ($registerId !== null && $schemaId !== null) {
            try {
                // Disable multitenancy for register/schema resolution (they're system-level).
                $register = $this->registerMapper->find((int) $registerId, multitenancy: false, rbac: false);
                $schema   = $this->schemaMapper->find((int) $schemaId, multitenancy: false, rbac: false);

                if ($this->shouldUseMagicMapper(register: $register, schema: $schema) === true) {
                    return $this->magicMapper->getSimpleFacetsFromRegisterSchemaTable(
                        query: $query,
                        register: $register,
                        schema: $schema
                    );
                }
            } catch (\Exception $e) {
                $this->logger->warning(
                    message: '[UnifiedObjectMapper] Failed to resolve register/schema for magic mapper facets',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
                );
                // Fall through to blob storage.
            }
        }//end if

        return $this->objectEntityMapper->getSimpleFacets($query);
    }//end getSimpleFacets()

    /**
     * Get facets aggregated across multiple schemas and registers.
     *
     * @param array $query       The search query.
     * @param array $registerIds Array of register IDs to search.
     * @param array $schemaIds   Array of schema IDs to aggregate.
     *
     * @return array Merged facet results.
     */
    private function getSimpleFacetsMultiSchema(array $query, array $registerIds, array $schemaIds): array
    {
        // Load all registers.
        $registers = [];
        foreach ($registerIds as $registerId) {
            try {
                $register = $this->registerMapper->find($registerId, multitenancy: false, rbac: false);
                $registers[$register->getId()] = $register;
            } catch (\Exception $e) {
                $this->logger->warning(
                    message: '[UnifiedObjectMapper] Failed to find register for multi-schema facets',
                    context: [
                        'file'       => __FILE__,
                        'line'       => __LINE__,
                        'registerId' => $registerId,
                        'error'      => $e->getMessage(),
                    ]
                );
            }
        }

        if (empty($registers) === true) {
            return [];
        }

        // Collect register+schema pairs for UNION-based faceting.
        $registerSchemaPairs = [];
        foreach ($schemaIds as $schemaId) {
            try {
                $schema = $this->schemaMapper->find($schemaId, multitenancy: false, rbac: false);

                // Find the correct register for this schema.
                $matchedRegister = null;
                foreach ($registers as $register) {
                    $registerSchemas = $register->getSchemas();
                    if (is_string($registerSchemas) === true) {
                        $registerSchemas = json_decode($registerSchemas, true) ?? [];
                    }

                    // Handle both formats: sequential array [2, 3, 4] or keyed object {"2": {...}}.
                    if (is_array($registerSchemas) === true) {
                        $schemaIdStr = (string) $schemaId;
                        $schemaIdInt = (int) $schemaId;
                        $inValues    = in_array($schemaIdInt, $registerSchemas, false) || in_array($schemaIdStr, $registerSchemas, false);
                        $inKeys      = array_key_exists($schemaIdInt, $registerSchemas) || array_key_exists($schemaIdStr, $registerSchemas);
                        if ($inValues === true || $inKeys === true) {
                            $matchedRegister = $register;
                            break;
                        }
                    }
                }

                if ($matchedRegister === null) {
                    $matchedRegister = reset($registers);
                }

                if ($this->shouldUseMagicMapper(register: $matchedRegister, schema: $schema) === true) {
                    $registerSchemaPairs[] = ['register' => $matchedRegister, 'schema' => $schema];
                }
            } catch (\Exception $e) {
                $this->logger->warning(
                    message: '[UnifiedObjectMapper] Failed to find schema for multi-schema facets',
                    context: [
                        'file'     => __FILE__,
                        'line'     => __LINE__,
                        'schemaId' => $schemaId,
                        'error'    => $e->getMessage(),
                    ]
                );
            }//end try
        }//end foreach

        if (empty($registerSchemaPairs) === true) {
            return [];
        }

        // Use optimized UNION-based faceting for better performance.
        // This executes ONE query per facet field instead of separate queries per schema.
        return $this->magicMapper->getSimpleFacetsUnion(
            query: $query,
            registerSchemaPairs: $registerSchemaPairs
        );
    }//end getSimpleFacetsMultiSchema()

    /**
     * Search objects across multiple schemas using magic mapper tables.
     *
     * This method queries each schema's magic mapper table and combines the results
     * with proper pagination support. For efficient pagination across multiple tables,
     * we fetch more results than needed and then apply final pagination.
     *
     * @param array       $searchQuery   Search query parameters.
     * @param array       $countQuery    Count query parameters.
     * @param array       $registerIds   Register IDs to search.
     * @param array       $schemaIds     Array of schema IDs to search.
     * @param string|null $activeOrgUuid Organisation UUID.
     * @param bool        $rbac          Apply RBAC.
     * @param bool        $multitenancy  Apply multitenancy.
     * @param array|null  $ids           Specific IDs to filter.
     * @param string|null $uses          Uses filter.
     *
     * @return array{results: ObjectEntity[], total: int, registers: array, schemas: array}
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
     * @psalm-suppress                              UnusedParam Parameters reserved for future per-schema security filtering.
     */
    private function searchObjectsPaginatedMultiSchema(
        array $searchQuery,
        array $countQuery,
        array $registerIds,
        array $schemaIds,
        ?string $activeOrgUuid=null,
        bool $rbac=true,
        bool $multitenancy=true,
        ?array $ids=null,
        ?string $uses=null
    ): array {
        // Cache for loaded registers and schemas.
        $registersCache = [];
        $schemasCache   = [];

        // Load all registers.
        $registers = [];
        foreach ($registerIds as $registerId) {
            try {
                $register = $this->registerMapper->find($registerId, multitenancy: false, rbac: false);
                $registers[$register->getId()]      = $register;
                $registersCache[$register->getId()] = $register->jsonSerialize();
            } catch (\Exception $e) {
                $this->logger->warning(
                    message: '[UnifiedObjectMapper] Failed to find register for multi-schema search',
                    context: [
                        'file'       => __FILE__,
                        'line'       => __LINE__,
                        'registerId' => $registerId,
                        'error'      => $e->getMessage(),
                    ]
                );
            }
        }

        if (empty($registers) === true) {
            return [
                'results'   => [],
                'total'     => 0,
                'registers' => [],
                'schemas'   => [],
            ];
        }

        // Build register+schema pairs for UNION-based search.
        // Each schema belongs to a specific register; find the correct register for each schema.
        $registerSchemaPairs = [];
        $totalCount          = 0;
        $ignoredFilters      = [];

        foreach ($schemaIds as $schemaId) {
            try {
                $schema = $this->schemaMapper->find((int) $schemaId, multitenancy: false, rbac: false);
                $schemasCache[$schema->getId()] = $schema->jsonSerialize();

                // Find which register contains this schema by checking register's schema list.
                $matchedRegister = null;
                foreach ($registers as $register) {
                    $registerSchemas = $register->getSchemas();
                    if (is_string($registerSchemas) === true) {
                        $registerSchemas = json_decode($registerSchemas, true) ?? [];
                    }

                    // Handle both formats: sequential array [2, 3, 4] or keyed object {"2": {...}}.
                    if (is_array($registerSchemas) === true) {
                        // Check if schema ID is in the values (sequential) or keys (associative).
                        $schemaIdStr = (string) $schemaId;
                        $schemaIdInt = (int) $schemaId;
                        $inValues    = in_array($schemaIdInt, $registerSchemas, false) || in_array($schemaIdStr, $registerSchemas, false);
                        $inKeys      = array_key_exists($schemaIdInt, $registerSchemas) || array_key_exists($schemaIdStr, $registerSchemas);
                        if ($inValues === true || $inKeys === true) {
                            $matchedRegister = $register;
                            break;
                        }
                    }
                }

                // Fallback: use first register if no match found (for backward compatibility).
                if ($matchedRegister === null) {
                    $matchedRegister = reset($registers);
                }

                // Check if magic mapper should be used for this schema.
                if ($this->shouldUseMagicMapper(register: $matchedRegister, schema: $schema) === false) {
                    $this->logger->debug(
                        message: '[UnifiedObjectMapper] Skipping non-magic-mapper schema',
                        context: ['file' => __FILE__, 'line' => __LINE__, 'schemaId' => $schemaId]
                    );
                    continue;
                }

                // Add to pairs for UNION search.
                $registerSchemaPairs[] = ['register' => $matchedRegister, 'schema' => $schema];

                // Get count for this schema using MagicSearchHandler (applies all filters correctly).
                $schemaCountQuery          = $countQuery;
                $schemaCountQuery['_rbac'] = $rbac;
                $schemaCountQuery['_multitenancy'] = $multitenancy;
                $schemaCount = $this->magicMapper->countObjectsInRegisterSchemaTable(
                    query: $schemaCountQuery,
                    register: $matchedRegister,
                    schema: $schema
                );
                $totalCount += $schemaCount;
            } catch (\Exception $e) {
                $this->logger->warning(
                    message: '[UnifiedObjectMapper] Failed to load schema for multi-schema search',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'schemaId' => $schemaId, 'error' => $e->getMessage()]
                );
            }//end try
        }//end foreach

        // If no valid schema pairs, return empty.
        if (empty($registerSchemaPairs) === true) {
            return [
                'results'        => [],
                'total'          => 0,
                'registers'      => $registersCache,
                'schemas'        => $schemasCache,
                'ignoredFilters' => [],
                'source'         => 'magic_mapper',
            ];
        }

        // Use UNION-based search for proper SQL-level ordering across all tables.
        // Add RBAC and multitenancy flags.
        $unionQuery          = $searchQuery;
        $unionQuery['_rbac'] = $rbac;
        $unionQuery['_multitenancy'] = $multitenancy;

        $results = $this->magicMapper->searchAcrossMultipleTables(
            query: $unionQuery,
            registerSchemaPairs: $registerSchemaPairs
        );

        // For multi-schema UNION searches, we don't report ignoredFilters because:
        // - The UNION query correctly handles missing properties by adding WHERE 1=0
        // - Each schema is filtered independently - some may have the property, some may not
        // - Reporting a filter as "ignored" is misleading when it was applied to schemas that have it
        // Only single-schema searches should report ignoredFilters (when the filter has no effect).
        return [
            'results'        => $results,
            'total'          => $totalCount,
            'registers'      => $registersCache,
            'schemas'        => $schemasCache,
            'ignoredFilters' => [],
            'source'         => 'magic_mapper',
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
        $registerId = $query['@self']['register'] ?? $query['_register'] ?? $query['register'] ?? null;
        $schemaId   = $query['@self']['schema'] ?? $query['_schema'] ?? $query['schema'] ?? null;

        if ($registerId !== null && $schemaId !== null) {
            try {
                // Disable multitenancy for register/schema resolution (they're system-level).
                $register = $this->registerMapper->find((int) $registerId, multitenancy: false, rbac: false);
                $schema   = $this->schemaMapper->find((int) $schemaId, multitenancy: false, rbac: false);

                if ($this->shouldUseMagicMapper(register: $register, schema: $schema) === true) {
                    $this->logger->info(
                        message: '[UnifiedObjectMapper] Routing searchObjects() to MagicMapper',
                        context: ['file' => __FILE__, 'line' => __LINE__]
                    );
                    // Add RBAC and multitenancy flags to query for MagicSearchHandler.
                    $query['_rbac']         = $rbac;
                    $query['_multitenancy'] = $multitenancy;
                    return $this->magicMapper->searchObjectsInRegisterSchemaTable(
                        query: $query,
                        register: $register,
                        schema: $schema
                    );
                }
            } catch (\Exception $e) {
                $this->logger->warning(
                    message: '[UnifiedObjectMapper] Failed to resolve register/schema for magic mapper',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
                );
                // Fall through to blob storage.
            }//end try
        }//end if

        $this->logger->debug(
            message: '[UnifiedObjectMapper] Routing searchObjects() to blob storage (ObjectEntityMapper)',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
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
        $registerId = $query['@self']['register'] ?? $query['_register'] ?? $query['register'] ?? null;
        $schemaId   = $query['@self']['schema'] ?? $query['_schema'] ?? $query['schema'] ?? null;

        if ($registerId !== null && $schemaId !== null) {
            try {
                // Disable multitenancy for register/schema resolution (they're system-level).
                $register = $this->registerMapper->find((int) $registerId, multitenancy: false, rbac: false);
                $schema   = $this->schemaMapper->find((int) $schemaId, multitenancy: false, rbac: false);

                if ($this->shouldUseMagicMapper(register: $register, schema: $schema) === true) {
                    $this->logger->info(
                        message: '[UnifiedObjectMapper] Routing countSearchObjects() to MagicMapper',
                        context: ['file' => __FILE__, 'line' => __LINE__]
                    );
                    return $this->magicMapper->countObjectsInRegisterSchemaTable(
                        query: $query,
                        register: $register,
                        schema: $schema
                    );
                }
            } catch (\Exception $e) {
                $this->logger->warning(
                    message: '[UnifiedObjectMapper] Failed to resolve register/schema for magic mapper count',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
                );
                // Fall through to blob storage.
            }//end try
        }//end if

        $this->logger->debug(
            message: '[UnifiedObjectMapper] Routing countSearchObjects() to blob storage (ObjectEntityMapper)',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
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
        $registerId = $searchQuery['@self']['register'] ?? $searchQuery['_register'] ?? $searchQuery['register'] ?? null;
        $schemaId   = $searchQuery['@self']['schema'] ?? $searchQuery['_schema'] ?? $searchQuery['schema'] ?? null;
        $schemaIds  = $searchQuery['@self']['schemas'] ?? $searchQuery['_schemas'] ?? null;

        // Handle case where @self.schema is an array (multi-schema search via singular key).
        // This supports opencatalogi which uses @self.schema with array values.
        if (is_array($schemaId) === true && count($schemaId) > 0) {
            $schemaIds = $schemaId;
            $schemaId  = null;
        }

        $register       = null;
        $schema         = null;
        $useMagicMapper = false;

        // Cache for loaded registers and schemas (indexed by ID for frontend lookup).
        $registersCache = [];
        $schemasCache   = [];

        // Extract register IDs (plural) for multi-register search.
        $registerIds = $searchQuery['@self']['registers'] ?? $searchQuery['_registers'] ?? null;

        // Check for multi-schema search (when _schemas is provided but _schema is not).
        // Supports both single-register (_register + _schemas) and multi-register (_registers + _schemas).
        $isMultiSchemaSearch = $schemaId === null
            && $schemaIds !== null
            && is_array($schemaIds) === true
            && count($schemaIds) > 0
            && ($registerId !== null || ($registerIds !== null && is_array($registerIds) === true && count($registerIds) > 0));
        if ($isMultiSchemaSearch === true) {
            // Build array of register IDs: use _registers if available, otherwise wrap single _register.
            if ($registerIds !== null && is_array($registerIds) === true && count($registerIds) > 0) {
                $allRegisterIds = array_map('intval', $registerIds);
            } else {
                $allRegisterIds = [(int) $registerId];
            }

            return $this->searchObjectsPaginatedMultiSchema(
                searchQuery: $searchQuery,
                countQuery: $countQuery,
                registerIds: $allRegisterIds,
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
                $register       = $this->registerMapper->find((int) $registerId, multitenancy: false, rbac: false);
                $schema         = $this->schemaMapper->find((int) $schemaId, multitenancy: false, rbac: false);
                $useMagicMapper = $this->shouldUseMagicMapper(register: $register, schema: $schema);

                // Add to cache indexed by ID.
                $registersCache[$register->getId()] = $register->jsonSerialize();
                $schemasCache[$schema->getId()]     = $schema->jsonSerialize();
            } catch (\Exception $e) {
                $this->logger->warning(
                    message: '[UnifiedObjectMapper] Failed to resolve register/schema',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
                );
            }
        }

        // Perform search and count using the appropriate mapper.
        $canUseMagicMapper = $useMagicMapper === true && $register !== null && $schema !== null;
        if ($canUseMagicMapper === true) {
            // Add RBAC and multitenancy flags to query for MagicSearchHandler.
            $searchQuery['_rbac']         = $rbac;
            $searchQuery['_multitenancy'] = $multitenancy;

            $searchStart = microtime(true);
            $results     = $this->magicMapper->searchObjectsInRegisterSchemaTable(
                query: $searchQuery,
                register: $register,
                schema: $schema
            );
            $searchTime  = round((microtime(true) - $searchStart) * 1000, 2);

            // Add RBAC and multitenancy flags to count query for MagicSearchHandler.
            $countQuery['_rbac']         = $rbac;
            $countQuery['_multitenancy'] = $multitenancy;

            $countStart = microtime(true);
            $total      = $this->magicMapper->countObjectsInRegisterSchemaTable(
                query: $countQuery,
                register: $register,
                schema: $schema
            );
            $countTime  = round((microtime(true) - $countStart) * 1000, 2);

            // Get ignored filters from the search (properties that don't exist in schema).
            $ignoredFilters = $this->magicMapper->getIgnoredFilters();

            // Return results with registers/schemas indexed by ID for frontend lookup.
            return [
                'results'        => $results,
                'total'          => $total,
                'registers'      => $registersCache,
                'schemas'        => $schemasCache,
                'ignoredFilters' => $ignoredFilters,
                'metrics'        => [
                    'search_ms' => $searchTime,
                    'count_ms'  => $countTime,
                ],
            ];
        }//end if

        // Check if this is an ID search (_ids provided).
        // Always search magic tables when _ids is present, regardless of register/schema context.
        // This ensures RBAC is properly applied via filterBySchemaRbac and avoids the ORM
        // fallback which does not enforce RBAC.
        $queryIds   = $searchQuery['_ids'] ?? null;
        $isIdSearch = $queryIds !== null
            && is_array($queryIds) === true
            && count($queryIds) > 0;

        if ($isIdSearch === true) {
            return $this->searchObjectsGloballyByIds(
                ids: $queryIds,
                searchQuery: $searchQuery,
                activeOrgUuid: $activeOrgUuid,
                rbac: $rbac,
                multitenancy: $multitenancy
            );
        }

        // Check if this is a global relations search (no register/schema but _relations_contains provided).
        // In this case, search across ALL magic tables to find objects that reference the given UUID.
        $relationsContains       = $searchQuery['_relations_contains'] ?? null;
        $isGlobalRelationsSearch = $registerId === null
            && $schemaId === null
            && $relationsContains !== null
            && is_string($relationsContains) === true
            && empty($relationsContains) === false;

        if ($isGlobalRelationsSearch === true) {
            return $this->searchObjectsGloballyByRelations(
                uuid: $relationsContains,
                searchQuery: $searchQuery,
                activeOrgUuid: $activeOrgUuid,
                rbac: $rbac,
                multitenancy: $multitenancy
            );
        }

        // Check if this is a global text search (no register/schema but _search is provided).
        // In this case, search across ALL magic tables using UNION for multi-magic-table search.
        $searchTerm         = $searchQuery['_search'] ?? null;
        $isGlobalTextSearch = $registerId === null
            && $schemaId === null
            && $searchTerm !== null
            && is_string($searchTerm) === true
            && trim($searchTerm) !== '';

        if ($isGlobalTextSearch === true) {
            return $this->searchObjectsGloballyBySearch(
                searchQuery: $searchQuery,
                countQuery: $countQuery,
                activeOrgUuid: $activeOrgUuid,
                rbac: $rbac,
                multitenancy: $multitenancy
            );
        }

        // Fallback: Use objectEntityMapper for blob storage.
        // NOTE: The ORM blob storage does NOT enforce RBAC at the SQL level.
        // We apply RBAC post-filtering below via filterBySchemaRbac().
        $this->logger->warning(
            message: '[UnifiedObjectMapper] Using blob storage fallback - magic mapper unavailable',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'registerId' => $registerId,
                'schemaId'   => $schemaId,
            ]
        );

        $results = $this->objectEntityMapper->searchObjects(
            query: $searchQuery,
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
                $reg = $this->registerMapper->find((int) $regId, multitenancy: false, rbac: false);
                $registersCache[$reg->getId()] = $reg->jsonSerialize();
            } catch (\Exception $e) {
                // Skip if not found.
            }
        }

        // Load any missing schemas.
        foreach (array_keys($uniqueSchemaIds) as $schId) {
            try {
                $sch = $this->schemaMapper->find((int) $schId, multitenancy: false, rbac: false);
                $schemasCache[$sch->getId()] = $sch->jsonSerialize();
            } catch (\Exception $e) {
                // Skip if not found.
            }
        }

        // Apply RBAC post-filtering since the ORM blob storage does not enforce RBAC.
        // This ensures schema-level authorization (including conditional rules) is always applied.
        $results = $this->filterBySchemaRbac(objects: $results, schemasCache: $schemasCache, rbac: $rbac);
        $total   = count($results);

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

    /**
     * Filter objects by schema RBAC permissions.
     *
     * This method filters a list of objects based on schema-level RBAC rules:
     * - Admin users see everything
     * - Object owner has full access
     * - User with matching group in authorization has access
     * - Schema with 'public' in read authorization = all objects readable (no multitenancy)
     * - Schema with no authorization = normal RBAC (multitenancy + auth required)
     * - Published objects = override to make private objects public
     *
     * @param array $objects      Array of ObjectEntity objects to filter.
     * @param array $schemasCache Cache of schema data by ID.
     * @param bool  $rbac         Whether RBAC is enabled.
     *
     * @return array Filtered array of ObjectEntity objects.
     */
    private function filterBySchemaRbac(array $objects, array &$schemasCache, bool $rbac): array
    {
        // If RBAC is disabled, return all objects.
        if ($rbac === false) {
            return $objects;
        }

        // Admin users see everything.
        if ($this->rbacHandler->isAdmin() === true) {
            $this->logger->debug(
                message: '[UnifiedObjectMapper] filterBySchemaRbac: Admin user, returning all',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return $objects;
        }

        // Cache Schema entities for RBAC permission checks.
        $schemaEntityCache = [];
        $filtered          = [];

        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                $filtered[] = $object;
                continue;
            }

            $schemaId = $object->getSchema();

            // No schema = no RBAC restriction.
            if ($schemaId === null) {
                $filtered[] = $object;
                continue;
            }

            // Get Schema entity from cache or fetch it.
            if (isset($schemaEntityCache[$schemaId]) === false) {
                try {
                    $schema = $this->schemaMapper->find((int) $schemaId, multitenancy: false, rbac: false);
                    $schemaEntityCache[$schemaId] = $schema;

                    // Also update serialized schemasCache for response metadata.
                    if (isset($schemasCache[$schemaId]) === false) {
                        $schemasCache[$schemaId] = $schema->jsonSerialize();
                    }
                } catch (\Exception $e) {
                    // Schema not found - deny access.
                    $this->logger->debug(
                        message: '[UnifiedObjectMapper] filterBySchemaRbac: Schema not found, denying access',
                        context: ['file' => __FILE__, 'line' => __LINE__, 'schemaId' => $schemaId]
                    );
                    continue;
                }
            }

            $schema = $schemaEntityCache[$schemaId];

            // Build object data with metadata for conditional RBAC evaluation.
            // Conditional rules like {"group": "x", "match": {"_organisation": "$organisation"}}
            // need metadata fields (_organisation, _owner) in the object data for matching.
            $objectData = $object->getObject() ?? [];
            $objectData['_organisation'] = $object->getOrganisation();
            $objectData['_owner']        = $object->getOwner();

            // Use MagicRbacHandler::hasPermission() which properly handles both
            // simple string rules (e.g., "gebruik-beheerder") and conditional rules
            // with match conditions (e.g., {"group": "x", "match": {"_organisation": "$organisation"}}).
            if ($this->rbacHandler->hasPermission(
                schema: $schema,
                action: 'read',
                objectOwner: $object->getOwner(),
                objectData: $objectData
            ) === true
            ) {
                $filtered[] = $object;
            } else {
                $this->logger->debug(
                    message: '[UnifiedObjectMapper] filterBySchemaRbac: Filtered out object',
                    context: [
                        'file'     => __FILE__,
                        'line'     => __LINE__,
                        'uuid'     => $object->getUuid(),
                        'schemaId' => $schemaId,
                    ]
                );
            }
        }//end foreach

        $this->logger->debug(
            message: '[UnifiedObjectMapper] filterBySchemaRbac complete',
            context: [
                'file'        => __FILE__,
                'line'        => __LINE__,
                'inputCount'  => count($objects),
                'outputCount' => count($filtered),
            ]
        );

        return $filtered;
    }//end filterBySchemaRbac()

    /**
     * Search for objects globally by IDs across ALL magic tables.
     *
     * This method is used when searching for objects by UUID without knowing
     * which register/schema they belong to. It searches all magic tables efficiently.
     *
     * @param array       $ids           Array of UUIDs to search for.
     * @param array       $searchQuery   The original search query (for limit/offset).
     * @param string|null $activeOrgUuid Active organization UUID for multitenancy.
     * @param bool        $rbac          Whether to apply RBAC checks.
     * @param bool        $multitenancy  Whether to apply multitenancy filtering.
     *
     * @return array Search results with pagination info.
     *
     * @psalm-suppress UnusedParam $multitenancy reserved for future multitenancy filtering implementation
     */
    private function searchObjectsGloballyByIds(
        array $ids,
        array $searchQuery,
        ?string $activeOrgUuid=null,
        bool $rbac=true,
        bool $multitenancy=true
    ): array {
        $this->logger->debug(
                message: '[UnifiedObjectMapper] searchObjectsGloballyByIds starting',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'idsCount' => count($ids),
                ]
                );

        // Use MagicMapper's efficient batch search across all magic tables.
        $results = $this->magicMapper->findMultipleAcrossAllMagicTables(
            uuids: $ids,
            includeDeleted: false
        );

        // Also check blob storage for any objects not found in magic tables.
        $foundUuids   = array_map(fn($obj) => $obj->getUuid(), $results);
        $missingUuids = array_diff($ids, $foundUuids);

        if (empty($missingUuids) === false) {
            $blobResults = $this->objectEntityMapper->findMultiple(ids: $missingUuids);
            $results     = array_merge($results, $blobResults);
        }

        // Collect register/schema info for frontend (needed for RBAC filtering).
        $registersCache = [];
        $schemasCache   = [];

        foreach ($results as $result) {
            if ($result instanceof ObjectEntity) {
                $regId = $result->getRegister();
                $schId = $result->getSchema();

                if ($regId !== null && isset($registersCache[$regId]) === false) {
                    try {
                        $reg = $this->registerMapper->find(id: (int) $regId, multitenancy: false, rbac: false);
                        $registersCache[$reg->getId()] = $reg->jsonSerialize();
                    } catch (\Exception $e) {
                        // Skip if register not found.
                    }
                }

                if ($schId !== null && isset($schemasCache[$schId]) === false) {
                    try {
                        $sch = $this->schemaMapper->find((int) $schId, multitenancy: false, rbac: false);
                        $schemasCache[$sch->getId()] = $sch->jsonSerialize();
                    } catch (\Exception $e) {
                        // Skip if not found.
                    }
                }
            }//end if
        }//end foreach

        // Apply RBAC filtering based on schema authorization.
        $results = $this->filterBySchemaRbac(objects: $results, schemasCache: $schemasCache, rbac: $rbac);

        $total = count($results);

        // Apply limit/offset from query after RBAC filtering.
        $limit   = $searchQuery['_limit'] ?? 1000;
        $offset  = $searchQuery['_offset'] ?? 0;
        $results = array_slice($results, $offset, $limit);

        // Filter caches to only include schemas/registers actually in the filtered results.
        $finalSchemaIds   = [];
        $finalRegisterIds = [];
        foreach ($results as $object) {
            $schId = $object->getSchema();
            $regId = $object->getRegister();
            if ($schId !== null) {
                $finalSchemaIds[$schId] = true;
            }

            if ($regId !== null) {
                $finalRegisterIds[$regId] = true;
            }
        }

        $schemasCache   = array_intersect_key($schemasCache, $finalSchemaIds);
        $registersCache = array_intersect_key($registersCache, $finalRegisterIds);

        $this->logger->debug(
                message: '[UnifiedObjectMapper] searchObjectsGloballyByIds complete',
                context: [
                    'file'           => __FILE__,
                    'line'           => __LINE__,
                    'requestedCount' => count($ids),
                    'foundCount'     => $total,
                ]
                );

        return [
            'results'   => $results,
            'total'     => $total,
            'registers' => $registersCache,
            'schemas'   => $schemasCache,
        ];
    }//end searchObjectsGloballyByIds()

    /**
     * Search for objects across ALL magic tables that contain the given UUID in their relations.
     *
     * This method is used when no register/schema is specified but _relations_contains is provided.
     * It searches across all magic tables to find objects that reference the given UUID.
     *
     * @param string      $uuid          The UUID to search for in relations.
     * @param array       $searchQuery   The original search query parameters.
     * @param string|null $activeOrgUuid The active organisation UUID for multitenancy.
     * @param bool        $rbac          Whether to apply RBAC filtering.
     * @param bool        $multitenancy  Whether to apply multitenancy filtering.
     *
     * @return array Search results with pagination info.
     *
     * @psalm-suppress UnusedParam $multitenancy reserved for future multitenancy filtering implementation
     */
    private function searchObjectsGloballyByRelations(
        string $uuid,
        array $searchQuery,
        ?string $activeOrgUuid=null,
        bool $rbac=true,
        bool $multitenancy=true
    ): array {
        $this->logger->debug(
            message: '[UnifiedObjectMapper] searchObjectsGloballyByRelations starting',
            context: [
                'file' => __FILE__,
                'line' => __LINE__,
                'uuid' => $uuid,
                'rbac' => $rbac,
            ]
        );

        // Use MagicMapper to search across all magic tables for objects with this UUID in relations.
        $results = $this->magicMapper->findByRelationAcrossAllMagicTables(
            uuid: $uuid,
            includeDeleted: false
        );

        // Collect unique register/schema info for @self metadata (needed for RBAC filtering).
        $registersCache = [];
        $schemasCache   = [];

        foreach ($results as $object) {
            $regId = $object->getRegister();
            $schId = $object->getSchema();

            if ($regId !== null && isset($registersCache[$regId]) === false) {
                try {
                    $register = $this->registerMapper->find((int) $regId, multitenancy: false, rbac: false);
                    if ($register !== null) {
                        $registersCache[$regId] = $register->jsonSerialize();
                    }
                } catch (\Exception $e) {
                    // Skip if register not found.
                }
            }

            if ($schId !== null && isset($schemasCache[$schId]) === false) {
                try {
                    $schema = $this->schemaMapper->find((int) $schId, multitenancy: false, rbac: false);
                    if ($schema !== null) {
                        $schemasCache[$schId] = $schema->jsonSerialize();
                    }
                } catch (\Exception $e) {
                    // Skip if schema not found.
                }
            }
        }//end foreach

        // Apply RBAC filtering based on schema authorization.
        $results = $this->filterBySchemaRbac(objects: $results, schemasCache: $schemasCache, rbac: $rbac);

        $total = count($results);

        // Apply limit/offset from query after RBAC filtering.
        $limit   = $searchQuery['_limit'] ?? 1000;
        $offset  = $searchQuery['_offset'] ?? 0;
        $results = array_slice($results, $offset, $limit);

        // Filter caches to only include schemas/registers actually in the filtered results.
        $finalSchemaIds   = [];
        $finalRegisterIds = [];
        foreach ($results as $object) {
            $schId = $object->getSchema();
            $regId = $object->getRegister();
            if ($schId !== null) {
                $finalSchemaIds[$schId] = true;
            }

            if ($regId !== null) {
                $finalRegisterIds[$regId] = true;
            }
        }

        $schemasCache   = array_intersect_key($schemasCache, $finalSchemaIds);
        $registersCache = array_intersect_key($registersCache, $finalRegisterIds);

        $this->logger->debug(
            message: '[UnifiedObjectMapper] searchObjectsGloballyByRelations complete',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'uuid'       => $uuid,
                'foundCount' => $total,
            ]
        );

        return [
            'results'   => $results,
            'total'     => $total,
            'registers' => $registersCache,
            'schemas'   => $schemasCache,
        ];
    }//end searchObjectsGloballyByRelations()

    /**
     * Search for objects across ALL magic tables using a text search term.
     *
     * This method is used when no register/schema is specified but _search is provided.
     * It loads all registers and their schemas, builds register/schema pairs for all
     * magic-mapped tables, and performs a UNION search across all of them.
     *
     * @param array       $searchQuery   The search query parameters (must contain _search).
     * @param array       $countQuery    The count query parameters.
     * @param string|null $activeOrgUuid The active organisation UUID for multitenancy.
     * @param bool        $rbac          Whether to apply RBAC filtering.
     * @param bool        $multitenancy  Whether to apply multitenancy filtering.
     *
     * @return array Search results with pagination info.
     */
    private function searchObjectsGloballyBySearch(
        array $searchQuery,
        array $countQuery,
        ?string $activeOrgUuid=null,
        bool $rbac=true,
        bool $multitenancy=true
    ): array {
        $this->logger->debug(
            message: '[UnifiedObjectMapper] searchObjectsGloballyBySearch starting',
            context: [
                'file'   => __FILE__,
                'line'   => __LINE__,
                'search' => $searchQuery['_search'] ?? '',
                'rbac'   => $rbac,
            ]
        );

        // Discover ALL magic tables and build register/schema pairs.
        // Uses MagicMapper's existing table discovery instead of duplicating that logic.
        $registersCache      = [];
        $schemasCache        = [];
        $registerSchemaPairs = [];

        $idPairs = $this->magicMapper->getAllRegisterSchemaPairs();

        foreach ($idPairs as $idPair) {
            try {
                $registerId = $idPair['registerId'];
                $schemaId   = $idPair['schemaId'];

                if (isset($registersCache[$registerId]) === false) {
                    $register = $this->registerMapper->find($registerId, multitenancy: false, rbac: false);
                    $registersCache[$registerId] = $register->jsonSerialize();
                } else {
                    $register = $this->registerMapper->find($registerId, multitenancy: false, rbac: false);
                }

                if (isset($schemasCache[$schemaId]) === false) {
                    $schema = $this->schemaMapper->find($schemaId, multitenancy: false, rbac: false);
                    $schemasCache[$schemaId] = $schema->jsonSerialize();
                } else {
                    $schema = $this->schemaMapper->find($schemaId, multitenancy: false, rbac: false);
                }

                $registerSchemaPairs[] = ['register' => $register, 'schema' => $schema];
            } catch (\Exception $e) {
                // Skip if register or schema can't be loaded.
                continue;
            }//end try
        }//end foreach

        if (empty($registerSchemaPairs) === true) {
            $this->logger->debug(
                message: '[UnifiedObjectMapper] No magic tables found for global search',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return [
                'results'   => [],
                'total'     => 0,
                'registers' => $registersCache,
                'schemas'   => $schemasCache,
                '@self'     => ['source' => 'magic_mapper'],
            ];
        }

        // Build UNION query with RBAC and multitenancy flags.
        $unionQuery          = $searchQuery;
        $unionQuery['_rbac'] = $rbac;
        $unionQuery['_multitenancy'] = $multitenancy;

        $results = $this->magicMapper->searchAcrossMultipleTables(
            query: $unionQuery,
            registerSchemaPairs: $registerSchemaPairs
        );

        // Count total across all tables.
        $countQuery['_rbac']         = $rbac;
        $countQuery['_multitenancy'] = $multitenancy;
        $totalCount = 0;
        foreach ($registerSchemaPairs as $pair) {
            $totalCount += $this->magicMapper->countObjectsInRegisterSchemaTable(
                query: $countQuery,
                register: $pair['register'],
                schema: $pair['schema']
            );
        }

        $this->logger->debug(
            message: '[UnifiedObjectMapper] searchObjectsGloballyBySearch complete',
            context: [
                'file'        => __FILE__,
                'line'        => __LINE__,
                'pairsCount'  => count($registerSchemaPairs),
                'resultCount' => count($results),
                'totalCount'  => $totalCount,
            ]
        );

        return [
            'results'        => $results,
            'total'          => $totalCount,
            'registers'      => $registersCache,
            'schemas'        => $schemasCache,
            'ignoredFilters' => [],
            '@self'          => ['source' => 'magic_mapper'],
        ];
    }//end searchObjectsGloballyBySearch()

    /**
     * Get a field value from an ObjectEntity for sorting purposes.
     *
     * Handles both metadata fields (via getters) and object data properties.
     *
     * @param ObjectEntity $object    The object entity to get the value from.
     * @param string       $fieldName The field name (without _ prefix).
     *
     * @return mixed The field value, or null if not found.
     */
    private function getObjectFieldValue(ObjectEntity $object, string $fieldName): mixed
    {
        // Map common field names to getter methods.
        $getterMap = [
            'id'           => 'getId',
            'uuid'         => 'getUuid',
            'name'         => 'getName',
            'slug'         => 'getSlug',
            'uri'          => 'getUri',
            'version'      => 'getVersion',
            'register'     => 'getRegister',
            'schema'       => 'getSchema',
            'owner'        => 'getOwner',
            'organisation' => 'getOrganisation',
            'application'  => 'getApplication',
            'folder'       => 'getFolder',
            'created'      => 'getCreated',
            'updated'      => 'getUpdated',
            'published'    => 'getPublished',
            'description'  => 'getDescription',
            'summary'      => 'getSummary',
        ];

        // Try getter method first (ObjectEntity may use __call for dynamic getters).
        if (isset($getterMap[$fieldName]) === true) {
            $method = $getterMap[$fieldName];
            try {
                return $object->$method();
            } catch (\Exception $e) {
                // Method doesn't exist, continue to fallback.
            }
        }

        // Try dynamic getter (getFieldName).
        $camelCaseGetter = 'get'.ucfirst($fieldName);
        try {
            return $object->$camelCaseGetter();
        } catch (\Exception $e) {
            // Method doesn't exist, continue to fallback.
        }

        // Fall back to object data.
        $objectData = $object->getObject();
        if (is_array($objectData) === true && isset($objectData[$fieldName]) === true) {
            return $objectData[$fieldName];
        }

        return null;
    }//end getObjectFieldValue()

    /**
     * Compare two values for sorting purposes.
     *
     * Handles DateTime objects, numeric values, and strings.
     *
     * @param mixed $a First value.
     * @param mixed $b Second value.
     *
     * @return int Comparison result (-1, 0, or 1).
     */
    private function compareValues(mixed $a, mixed $b): int
    {
        // Handle null values.
        if ($a === null && $b === null) {
            return 0;
        }

        if ($a === null) {
            return -1;
        }

        if ($b === null) {
            return 1;
        }

        // Handle DateTime objects.
        if ($a instanceof DateTime && $b instanceof DateTime) {
            return $a->getTimestamp() <=> $b->getTimestamp();
        }

        // Handle numeric values.
        if (is_numeric($a) === true && is_numeric($b) === true) {
            return ((float) $a) <=> ((float) $b);
        }

        // Handle strings (case-insensitive).
        if (is_string($a) === true && is_string($b) === true) {
            return strcasecmp($a, $b);
        }

        // Handle arrays (compare by count).
        if (is_array($a) === true && is_array($b) === true) {
            return count($a) <=> count($b);
        }

        // Default string comparison.
        return strcmp((string) $a, (string) $b);
    }//end compareValues()
}//end class
