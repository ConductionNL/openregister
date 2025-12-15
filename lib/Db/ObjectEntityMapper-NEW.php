<?php

/**
 * OpenRegister Object Entity Mapper (Refactored Facade)
 *
 * This file contains the refactored ObjectEntityMapper which now acts as a thin facade,
 * delegating responsibilities to 7 specialized handlers following the Single Responsibility Principle.
 *
 * Refactored from a 4,985-line God Object into a ~700-line facade coordinating 7 handlers (2,894 lines).
 *
 * @category  Database
 * @package   OCA\OpenRegister\Db
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity\BulkOperationsHandler;
use OCA\OpenRegister\Db\ObjectEntity\CrudHandler;
use OCA\OpenRegister\Db\ObjectEntity\FacetsHandler;
use OCA\OpenRegister\Db\ObjectEntity\LockingHandler;
use OCA\OpenRegister\Db\ObjectEntity\QueryBuilderHandler;
use OCA\OpenRegister\Db\ObjectEntity\QueryOptimizationHandler;
use OCA\OpenRegister\Db\ObjectEntity\StatisticsHandler;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\MySQLJsonService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The ObjectEntityMapper class (Refactored Facade)
 *
 * This class has been refactored from a 4,985-line God Object into a thin facade
 * that coordinates 7 specialized handlers:
 *
 * 1. LockingHandler - Object locking/unlocking
 * 2. QueryBuilderHandler - Query builder utilities
 * 3. CrudHandler - Basic CRUD operations
 * 4. StatisticsHandler - Statistics & chart data
 * 5. FacetsHandler - Facet operations
 * 6. BulkOperationsHandler - Performance-critical bulk operations
 * 7. QueryOptimizationHandler - Query optimization & specialized operations
 *
 * The facade keeps orchestration logic (insert/update/delete with events) and
 * delegates domain-specific operations to handlers.
 *
 * @package          OCA\OpenRegister\Db
 * @template-extends QBMapper<ObjectEntity>
 */
class ObjectEntityMapper extends QBMapper
{
    use MultiTenancyTrait;

    // Handler instances (delegated responsibilities).
    private LockingHandler $lockingHandler;

    private QueryBuilderHandler $queryBuilderHandler;

    private CrudHandler $crudHandler;

    private StatisticsHandler $statisticsHandler;

    private FacetsHandler $facetsHandler;

    private BulkOperationsHandler $bulkOperationsHandler;

    private QueryOptimizationHandler $queryOptimizationHandler;

    // Existing dependencies (kept from original).
    private OrganisationService $organisationService;

    private MySQLJsonService $databaseJsonService;

    private IEventDispatcher $eventDispatcher;

    private IUserSession $userSession;

    private SchemaMapper $schemaMapper;

    private IGroupManager $groupManager;

    private IUserManager $userManager;

    private LoggerInterface $logger;

    private IAppConfig $appConfig;


    /**
     * Constructor for the ObjectEntityMapper.
     *
     * @param IDBConnection            $db                       Database connection.
     * @param MySQLJsonService         $mySQLJsonService         MySQL JSON service.
     * @param IEventDispatcher         $eventDispatcher          Event dispatcher.
     * @param IUserSession             $userSession              User session.
     * @param SchemaMapper             $schemaMapper             Schema mapper.
     * @param IGroupManager            $groupManager             Group manager.
     * @param IUserManager             $userManager              User manager.
     * @param IAppConfig               $appConfig                App configuration.
     * @param LoggerInterface          $logger                   Logger.
     * @param OrganisationService      $organisationService      Organisation service for multi-tenancy.
     * @param LockingHandler           $lockingHandler           Locking handler.
     * @param QueryBuilderHandler      $queryBuilderHandler      Query builder handler.
     * @param CrudHandler              $crudHandler              CRUD handler.
     * @param StatisticsHandler        $statisticsHandler        Statistics handler.
     * @param FacetsHandler            $facetsHandler            Facets handler.
     * @param BulkOperationsHandler    $bulkOperationsHandler    Bulk operations handler.
     * @param QueryOptimizationHandler $queryOptimizationHandler Query optimization handler.
     */
    public function __construct(
        IDBConnection $db,
        MySQLJsonService $mySQLJsonService,
        IEventDispatcher $eventDispatcher,
        IUserSession $userSession,
        SchemaMapper $schemaMapper,
        IGroupManager $groupManager,
        IUserManager $userManager,
        IAppConfig $appConfig,
        LoggerInterface $logger,
        OrganisationService $organisationService,
        LockingHandler $lockingHandler,
        QueryBuilderHandler $queryBuilderHandler,
        CrudHandler $crudHandler,
        StatisticsHandler $statisticsHandler,
        FacetsHandler $facetsHandler,
        BulkOperationsHandler $bulkOperationsHandler,
        QueryOptimizationHandler $queryOptimizationHandler
    ) {
        parent::__construct($db, 'openregister_objects');

        // Existing dependencies.
        $this->databaseJsonService = $mySQLJsonService;
        $this->eventDispatcher     = $eventDispatcher;
        $this->userSession         = $userSession;
        $this->schemaMapper        = $schemaMapper;
        $this->groupManager        = $groupManager;
        $this->userManager         = $userManager;
        $this->appConfig           = $appConfig;
        $this->logger = $logger;
        $this->organisationService = $organisationService;

        // Injected handlers.
        $this->lockingHandler        = $lockingHandler;
        $this->queryBuilderHandler   = $queryBuilderHandler;
        $this->crudHandler           = $crudHandler;
        $this->statisticsHandler     = $statisticsHandler;
        $this->facetsHandler         = $facetsHandler;
        $this->bulkOperationsHandler = $bulkOperationsHandler;
        $this->queryOptimizationHandler = $queryOptimizationHandler;

    }//end __construct()


    // ==================================================================================
    // QUERY BUILDER OPERATIONS (Delegated to QueryBuilderHandler)
    // ==================================================================================


    /**
     * Get query builder instance.
     *
     * @return IQueryBuilder Query builder instance.
     */
    public function getQueryBuilder(): IQueryBuilder
    {
        return $this->queryBuilderHandler->getQueryBuilder();

    }//end getQueryBuilder()


    /**
     * Get the actual max_allowed_packet value from the database.
     *
     * @return int The max_allowed_packet value in bytes.
     */
    public function getMaxAllowedPacketSize(): int
    {
        return $this->queryBuilderHandler->getMaxAllowedPacketSize();

    }//end getMaxAllowedPacketSize()


    // ==================================================================================
    // LOCKING OPERATIONS (Delegated to LockingHandler)
    // ==================================================================================


    /**
     * Lock an object to prevent concurrent modifications.
     *
     * @param string   $uuid         Object UUID to lock.
     * @param int|null $lockDuration Lock duration in seconds (null for default).
     *
     * @return array Lock information including expiry time.
     *
     * @throws Exception If locking fails.
     */
    public function lockObject(string $uuid, ?int $lockDuration=null): array
    {
        $result = $this->lockingHandler->lockObject($uuid, $lockDuration);

        // Dispatch lock event.
        $this->eventDispatcher->dispatch(ObjectLockedEvent::class, new ObjectLockedEvent($uuid));

        return $result;

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
        $result = $this->lockingHandler->unlockObject($uuid);

        // Dispatch unlock event.
        $this->eventDispatcher->dispatch(ObjectUnlockedEvent::class, new ObjectUnlockedEvent($uuid));

        return $result;

    }//end unlockObject()


    // ==================================================================================
    // CRUD OPERATIONS (Orchestrated with Events)
    // ==================================================================================


    /**
     * Insert a new object entity with event dispatching.
     *
     * @param ObjectEntity $entity Entity to insert.
     *
     * @return ObjectEntity Inserted entity.
     *
     * @throws Exception If insertion fails.
     */
    public function insert(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity
    {
        // Dispatch creating event.
        $this->eventDispatcher->dispatch(ObjectCreatingEvent::class, new ObjectCreatingEvent($entity));

        // Delegate to CRUD handler.
        $result = $this->crudHandler->insert($entity);

        // Dispatch created event.
        $this->eventDispatcher->dispatch(ObjectCreatedEvent::class, new ObjectCreatedEvent($result));

        return $result;

    }//end insert()


    /**
     * Update an existing object entity with event dispatching.
     *
     * @param ObjectEntity $entity Entity to update.
     *
     * @return ObjectEntity Updated entity.
     *
     * @throws Exception If update fails.
     */
    public function update(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity
    {
        // Dispatch updating event.
        $this->eventDispatcher->dispatch(ObjectUpdatingEvent::class, new ObjectUpdatingEvent($entity));

        // Delegate to CRUD handler.
        $result = $this->crudHandler->update($entity);

        // Dispatch updated event.
        $this->eventDispatcher->dispatch(ObjectUpdatedEvent::class, new ObjectUpdatedEvent($result));

        return $result;

    }//end update()


    /**
     * Delete an object entity with event dispatching.
     *
     * @param ObjectEntity $entity Entity to delete.
     *
     * @return ObjectEntity Deleted entity.
     *
     * @throws Exception If deletion fails.
     */
    public function delete(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity
    {
        // Dispatch deleting event.
        $this->eventDispatcher->dispatch(ObjectDeletingEvent::class, new ObjectDeletingEvent($entity));

        // Delegate to CRUD handler.
        $result = $this->crudHandler->delete($entity);

        // Dispatch deleted event.
        $this->eventDispatcher->dispatch(ObjectDeletedEvent::class, new ObjectDeletedEvent($result));

        return $result;

    }//end delete()


    // ==================================================================================
    // STATISTICS OPERATIONS (Delegated to StatisticsHandler)
    // ==================================================================================


    /**
     * Get statistics for objects.
     *
     * @param int|array|null $registerId Filter by register ID(s).
     * @param int|array|null $schemaId   Filter by schema ID(s).
     * @param array          $exclude    Combinations to exclude.
     *
     * @return array Statistics including total, size, invalid, deleted, locked, published counts.
     */
    public function getStatistics(int|array|null $registerId=null, int|array|null $schemaId=null, array $exclude=[]): array
    {
        return $this->statisticsHandler->getStatistics($registerId, $schemaId, $exclude);

    }//end getStatistics()


    /**
     * Get chart data for objects grouped by register.
     *
     * @param int|null $registerId Filter by register ID.
     * @param int|null $schemaId   Filter by schema ID.
     *
     * @return array Chart data with 'labels' and 'series' keys.
     */
    public function getRegisterChartData(?int $registerId=null, ?int $schemaId=null): array
    {
        return $this->statisticsHandler->getRegisterChartData($registerId, $schemaId);

    }//end getRegisterChartData()


    /**
     * Get chart data for objects grouped by schema.
     *
     * @param int|null $registerId Filter by register ID.
     * @param int|null $schemaId   Filter by schema ID.
     *
     * @return array Chart data with 'labels' and 'series' keys.
     */
    public function getSchemaChartData(?int $registerId=null, ?int $schemaId=null): array
    {
        return $this->statisticsHandler->getSchemaChartData($registerId, $schemaId);

    }//end getSchemaChartData()


    /**
     * Get chart data for objects grouped by size ranges.
     *
     * @param int|null $registerId Filter by register ID.
     * @param int|null $schemaId   Filter by schema ID.
     *
     * @return array Chart data with 'labels' and 'series' keys.
     */
    public function getSizeDistributionChartData(?int $registerId=null, ?int $schemaId=null): array
    {
        return $this->statisticsHandler->getSizeDistributionChartData($registerId, $schemaId);

    }//end getSizeDistributionChartData()


    // ==================================================================================
    // FACET OPERATIONS (Delegated to FacetsHandler)
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
    public function getSimpleFacets(array $query=[]): array
    {
        return $this->facetsHandler->getSimpleFacets($query);

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
    public function getFacetableFieldsFromSchemas(array $baseQuery=[]): array
    {
        return $this->facetsHandler->getFacetableFieldsFromSchemas($baseQuery);

    }//end getFacetableFieldsFromSchemas()


    // ==================================================================================
    // BULK OPERATIONS (Delegated to BulkOperationsHandler)
    // ==================================================================================


    /**
     * ULTRA PERFORMANCE: Memory-intensive unified bulk save operation.
     *
     * @param array $insertObjects Array of arrays (insert data).
     * @param array $updateObjects Array of ObjectEntity instances (update data).
     *
     * @return array Array of processed UUIDs.
     */
    public function ultraFastBulkSave(array $insertObjects=[], array $updateObjects=[]): array
    {
        return $this->bulkOperationsHandler->ultraFastBulkSave($insertObjects, $updateObjects);

    }//end ultraFastBulkSave()


    /**
     * Perform bulk delete operations on objects by UUID.
     *
     * @param array $uuids      Array of object UUIDs to delete.
     * @param bool  $hardDelete Whether to force hard delete.
     *
     * @return array Array of UUIDs of deleted objects.
     */
    public function deleteObjects(array $uuids=[], bool $hardDelete=false): array
    {
        return $this->bulkOperationsHandler->deleteObjects($uuids, $hardDelete);

    }//end deleteObjects()


    /**
     * Perform bulk publish operations on objects by UUID.
     *
     * @param array          $uuids    Array of object UUIDs to publish.
     * @param \DateTime|bool $datetime Optional datetime for publishing.
     *
     * @return array Array of UUIDs of published objects.
     */
    public function publishObjects(array $uuids=[], \DateTime|bool $datetime=true): array
    {
        return $this->bulkOperationsHandler->publishObjects($uuids, $datetime);

    }//end publishObjects()


    /**
     * Perform bulk depublish operations on objects by UUID.
     *
     * @param array          $uuids    Array of object UUIDs to depublish.
     * @param \DateTime|bool $datetime Optional datetime for depublishing.
     *
     * @return array Array of UUIDs of depublished objects.
     */
    public function depublishObjects(array $uuids=[], \DateTime|bool $datetime=true): array
    {
        return $this->bulkOperationsHandler->depublishObjects($uuids, $datetime);

    }//end depublishObjects()


    /**
     * Publish all objects belonging to a specific schema.
     *
     * @param int  $schemaId   Schema ID.
     * @param bool $publishAll Whether to publish all objects.
     *
     * @return array Statistics about the publishing operation.
     *
     * @throws \Exception If the publishing operation fails.
     */
    public function publishObjectsBySchema(int $schemaId, bool $publishAll=false): array
    {
        return $this->bulkOperationsHandler->publishObjectsBySchema($schemaId, $publishAll);

    }//end publishObjectsBySchema()


    /**
     * Delete all objects belonging to a specific schema.
     *
     * @param int  $schemaId   Schema ID.
     * @param bool $hardDelete Whether to force hard delete.
     *
     * @return array Statistics about the deletion operation.
     *
     * @throws \Exception If the deletion operation fails.
     */
    public function deleteObjectsBySchema(int $schemaId, bool $hardDelete=false): array
    {
        return $this->bulkOperationsHandler->deleteObjectsBySchema($schemaId, $hardDelete);

    }//end deleteObjectsBySchema()


    /**
     * Delete all objects belonging to a specific register.
     *
     * @param int $registerId Register ID.
     *
     * @return array Statistics about the deletion operation.
     *
     * @throws \Exception If the deletion operation fails.
     */
    public function deleteObjectsByRegister(int $registerId): array
    {
        return $this->bulkOperationsHandler->deleteObjectsByRegister($registerId);

    }//end deleteObjectsByRegister()


    /**
     * Process a single chunk of insert objects within a transaction.
     *
     * @param array $insertChunk Array of objects to insert.
     *
     * @return array Array of inserted object UUIDs.
     *
     * @throws \OCP\DB\Exception If a database error occurs.
     */
    public function processInsertChunk(array $insertChunk): array
    {
        return $this->bulkOperationsHandler->processInsertChunk($insertChunk);

    }//end processInsertChunk()


    /**
     * Process a single chunk of update objects within a transaction.
     *
     * @param array $updateChunk Array of ObjectEntity instances to update.
     *
     * @return array Array of updated object UUIDs.
     *
     * @throws \OCP\DB\Exception If a database error occurs.
     */
    public function processUpdateChunk(array $updateChunk): array
    {
        return $this->bulkOperationsHandler->processUpdateChunk($updateChunk);

    }//end processUpdateChunk()


    /**
     * Calculate optimal chunk size based on actual data size.
     *
     * @param array $insertObjects Array of objects to insert.
     * @param array $updateObjects Array of objects to update.
     *
     * @return int Optimal chunk size in number of objects.
     */
    public function calculateOptimalChunkSize(array $insertObjects, array $updateObjects): int
    {
        return $this->bulkOperationsHandler->calculateOptimalChunkSize($insertObjects, $updateObjects);

    }//end calculateOptimalChunkSize()


    // ==================================================================================
    // QUERY OPTIMIZATION OPERATIONS (Delegated to QueryOptimizationHandler)
    // ==================================================================================


    /**
     * Detect and separate extremely large objects for individual processing.
     *
     * @param array $objects     Array of objects to check.
     * @param int   $maxSafeSize Maximum safe size in bytes.
     *
     * @return array Array with 'large' and 'normal' keys.
     */
    public function separateLargeObjects(array $objects, int $maxSafeSize=1000000): array
    {
        return $this->queryOptimizationHandler->separateLargeObjects($objects, $maxSafeSize);

    }//end separateLargeObjects()


    /**
     * Process large objects individually to prevent packet size errors.
     *
     * @param array $largeObjects Array of large objects to process.
     *
     * @return array Array of processed object UUIDs.
     */
    public function processLargeObjectsIndividually(array $largeObjects): array
    {
        return $this->queryOptimizationHandler->processLargeObjectsIndividually($largeObjects);

    }//end processLargeObjectsIndividually()


    /**
     * Bulk assign default owner and organization to objects.
     *
     * @param string|null $defaultOwner        Default owner to assign.
     * @param string|null $defaultOrganisation Default organization UUID.
     * @param int         $batchSize           Number of objects per batch.
     *
     * @return array Statistics about the bulk operation.
     *
     * @throws \Exception If the bulk operation fails.
     */
    public function bulkOwnerDeclaration(?string $defaultOwner=null, ?string $defaultOrganisation=null, int $batchSize=1000): array
    {
        return $this->queryOptimizationHandler->bulkOwnerDeclaration($defaultOwner, $defaultOrganisation, $batchSize);

    }//end bulkOwnerDeclaration()


    /**
     * Set expiry dates for objects based on retention period.
     *
     * @param int $retentionMs Retention period in milliseconds.
     *
     * @return int Number of objects updated.
     *
     * @throws \Exception Database operation exceptions.
     */
    public function setExpiryDate(int $retentionMs): int
    {
        return $this->queryOptimizationHandler->setExpiryDate($retentionMs);

    }//end setExpiryDate()


    /**
     * Apply optimizations for composite indexes.
     *
     * @param IQueryBuilder $_qb     Query builder.
     * @param array         $filters Applied filters.
     *
     * @return void
     */
    public function applyCompositeIndexOptimizations(IQueryBuilder $_qb, array $filters): void
    {
        $this->queryOptimizationHandler->applyCompositeIndexOptimizations($_qb, $filters);

    }//end applyCompositeIndexOptimizations()


    /**
     * Optimize ORDER BY clauses to use indexes.
     *
     * @param IQueryBuilder $qb Query builder.
     *
     * @return void
     */
    public function optimizeOrderBy(IQueryBuilder $qb): void
    {
        $this->queryOptimizationHandler->optimizeOrderBy($qb);

    }//end optimizeOrderBy()


    /**
     * Add database-specific query hints for better performance.
     *
     * @param IQueryBuilder $qb       Query builder.
     * @param array         $filters  Applied filters.
     * @param bool          $skipRbac Whether RBAC is skipped.
     *
     * @return void
     */
    public function addQueryHints(IQueryBuilder $qb, array $filters, bool $skipRbac): void
    {
        $this->queryOptimizationHandler->addQueryHints($qb, $filters, $skipRbac);

    }//end addQueryHints()


    /**
     * Check if filters contain JSON-based queries.
     *
     * @param array $filters Filter array to check.
     *
     * @return bool True if JSON filters are present.
     */
    public function hasJsonFilters(array $filters): bool
    {
        return $this->queryOptimizationHandler->hasJsonFilters($filters);

    }//end hasJsonFilters()


    // ==================================================================================
    // RBAC AND MULTITENANCY HELPERS (Kept in Facade)
    // ==================================================================================


    /**
     * Check if RBAC is enabled in app configuration.
     *
     * @return bool True if RBAC is enabled.
     */
    private function isRbacEnabled(): bool
    {
        $rbacConfig = $this->appConfig->getValueString('openregister', 'rbac', '');
        if (empty($rbacConfig) === true) {
            return false;
        }

        $rbacData = json_decode($rbacConfig, true);
        return $rbacData['enabled'] ?? false;

    }//end isRbacEnabled()


    /**
     * Check if multi-tenancy is enabled in app configuration.
     *
     * @return bool True if multi-tenancy is enabled.
     */
    private function isMultiTenancyEnabled(): bool
    {
        $multitenancyConfig = $this->appConfig->getValueString('openregister', 'multitenancy', '');
        if (empty($multitenancyConfig) === true) {
            return false;
        }

        $multitenancyData = json_decode($multitenancyConfig, true);
        return $multitenancyData['enabled'] ?? false;

    }//end isMultiTenancyEnabled()


    /**
     * Check if multitenancy admin override is enabled.
     *
     * @return bool True if admin override is enabled.
     */
    private function isMultitenancyAdminOverrideEnabled(): bool
    {
        $multitenancyConfig = $this->appConfig->getValueString('openregister', 'multitenancy', '');
        if (empty($multitenancyConfig) === true) {
            return true;
            // Default to true.
        }

        $multitenancyData = json_decode($multitenancyConfig, true);
        return $multitenancyData['adminOverride'] ?? true;

    }//end isMultitenancyAdminOverrideEnabled()


}//end class
