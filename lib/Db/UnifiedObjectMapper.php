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
            return $this->magicMapper->findInRegisterSchemaTable(identifier: $identifier, register: $register, schema: $schema);
        }

        $this->logger->debug('[UnifiedObjectMapper] Routing find() to ObjectEntityMapper (blob storage direct)');
        return $this->objectEntityMapper->findDirectBlobStorage($identifier, $register, $schema, $includeDeleted, $rbac, $multitenancy);
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
    // LOCKING, BULK OPERATIONS, STATISTICS, FACETING, SEARCH
    // All route to ObjectEntityMapper for now - magic mapper support to be added
    // ==================================================================================

    public function lockObject(string $uuid, ?int $lockDuration=null): array
    {
        return $this->objectEntityMapper->lockObject(uuid: $uuid, duration: $lockDuration);
    }

    public function unlockObject(string $uuid): bool
    {
        return $this->objectEntityMapper->unlockObject($uuid);
    }

    public function ultraFastBulkSave(array $insertObjects=[], array $updateObjects=[]): array
    {
        return $this->objectEntityMapper->ultraFastBulkSave(insertObjects: $insertObjects, updateObjects: $updateObjects);
    }

    public function deleteObjects(array $uuids=[], bool $hardDelete=false): array
    {
        return $this->objectEntityMapper->deleteObjects(uuids: $uuids, hardDelete: $hardDelete);
    }

    public function publishObjects(array $uuids=[], DateTime|bool $datetime=true): array
    {
        return $this->objectEntityMapper->publishObjects(uuids: $uuids, datetime: $datetime);
    }

    public function depublishObjects(array $uuids=[], DateTime|bool $datetime=true): array
    {
        return $this->objectEntityMapper->depublishObjects(uuids: $uuids, datetime: $datetime);
    }

    public function getStatistics(int|array|null $registerId=null, int|array|null $schemaId=null, array $exclude=[]): array
    {
        return $this->objectEntityMapper->getStatistics(registerId: $registerId, schemaId: $schemaId, exclude: $exclude);
    }

    public function getRegisterChartData(?int $registerId=null, ?int $schemaId=null): array
    {
        return $this->objectEntityMapper->getRegisterChartData(registerId: $registerId, schemaId: $schemaId);
    }

    public function getSchemaChartData(?int $registerId=null, ?int $schemaId=null): array
    {
        return $this->objectEntityMapper->getSchemaChartData(registerId: $registerId, schemaId: $schemaId);
    }

    public function getSimpleFacets(array $query=[]): array
    {
        return $this->objectEntityMapper->getSimpleFacets($query);
    }

    public function getFacetableFieldsFromSchemas(array $baseQuery=[]): array
    {
        return $this->objectEntityMapper->getFacetableFieldsFromSchemas($baseQuery);
    }

    public function searchObjects(array $query=[], ?string $activeOrganisationUuid=null, bool $rbac=true, bool $multitenancy=true, ?array $ids=null, ?string $uses=null): array|int
    {
        return $this->objectEntityMapper->searchObjects(query: $query, _activeOrganisationUuid: $activeOrganisationUuid, _rbac: $rbac, _multitenancy: $multitenancy, ids: $ids, uses: $uses);
    }

    public function countSearchObjects(array $query=[], ?string $activeOrganisationUuid=null, bool $rbac=true, bool $multitenancy=true, ?array $ids=null, ?string $uses=null): int
    {
        return $this->objectEntityMapper->countSearchObjects(query: $query, _activeOrganisationUuid: $activeOrganisationUuid, _rbac: $rbac, _multitenancy: $multitenancy, ids: $ids, uses: $uses);
    }

    public function countAll(?array $filters=null, ?Schema $schema=null, ?Register $register=null): int
    {
        return $this->objectEntityMapper->countAll(filters: $filters, schema: $schema, register: $register);
    }

    public function getQueryBuilder(): IQueryBuilder
    {
        return $this->objectEntityMapper->getQueryBuilder();
    }

    public function getMaxAllowedPacketSize(): int
    {
        return $this->objectEntityMapper->getMaxAllowedPacketSize();
    }
}//end class
