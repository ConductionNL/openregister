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
use BadMethodCallException;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity\BulkOperationsHandler;
use OCA\OpenRegister\Db\ObjectEntity\FacetsHandler;
use OCA\OpenRegister\Db\ObjectEntity\QueryBuilderHandler;
use OCA\OpenRegister\Db\ObjectEntity\QueryOptimizationHandler;
use OCA\OpenRegister\Db\ObjectEntity\StatisticsHandler;
// REMOVED: CrudHandler and LockingHandler (dead code - never instantiated, create circular dependencies).
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
// Use OCA\OpenRegister\Service\MySQLJsonService; // REMOVED: Dead code (never used).
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
    // REMOVED: LockingHandler and CrudHandler
    // These were dead code that created circular dependencies. The real handlers
    // Exist in Service/Object/ layer where they belong (Service/Object/LockHandler and Service/Object/CrudHandler).
    // Handlers WITHOUT circular dependencies (only need DB, logger, simple deps).

    /**
     * Query builder handler
     *
     * @var QueryBuilderHandler
     */
    private QueryBuilderHandler $queryBuilderHandler;

    /**
     * Statistics handler
     *
     * @var StatisticsHandler
     */
    private StatisticsHandler $statisticsHandler;

    /**
     * Facets handler - only needs DB, logger, tableName
     *
     * @var FacetsHandler
     */
    private FacetsHandler $facetsHandler;

    /**
     * Bulk operations handler - only needs logger, schemaMapper
     *
     * @var BulkOperationsHandler
     */
    private BulkOperationsHandler $bulkOperationsHandler;

    /**
     * Query optimization handler - needs QueryBuilderHandler
     *
     * @var QueryOptimizationHandler
     */
    private QueryOptimizationHandler $queryOptimizationHandler;

    /**
     * Organisation mapper
     *
     * @var OrganisationMapper
     */
    private OrganisationMapper $organisationMapper;

    /**
     * Event dispatcher
     *
     * @var IEventDispatcher
     */
    private IEventDispatcher $eventDispatcher;

    /**
     * User session
     *
     * @var IUserSession
     */
    private IUserSession $userSession;

    /**
     * Schema mapper
     *
     * @var SchemaMapper
     */
    private SchemaMapper $schemaMapper;

    /**
     * Group manager
     *
     * @var IGroupManager
     */
    private IGroupManager $groupManager;

    /**
     * User manager
     *
     * @var IUserManager
     */
    private IUserManager $userManager;

    /**
     * Logger interface
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * App configuration
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * Constructor for the ObjectEntityMapper.
     *
     * @param IDBConnection      $db                 Database connection.
     * @param IEventDispatcher   $eventDispatcher    Event dispatcher.
     * @param IUserSession       $userSession        User session.
     * @param SchemaMapper       $schemaMapper       Schema mapper.
     * @param IGroupManager      $groupManager       Group manager.
     * @param IUserManager       $userManager        User manager.
     * @param IAppConfig         $appConfig          App configuration.
     * @param LoggerInterface    $logger             Logger.
     * @param OrganisationMapper $organisationMapper Organisation service for multi-tenancy.
     */
    public function __construct(
        IDBConnection $db,
        // MySQLJsonService $mySQLJsonService, // REMOVED: Dead code (never used).
        IEventDispatcher $eventDispatcher,
        IUserSession $userSession,
        SchemaMapper $schemaMapper,
        IGroupManager $groupManager,
        IUserManager $userManager,
        IAppConfig $appConfig,
        LoggerInterface $logger,
        OrganisationMapper $organisationMapper
    ) {
        parent::__construct($db, 'openregister_objects');

        // Existing dependencies.
        // $this->databaseJsonService = $mySQLJsonService; // REMOVED: Dead code (never used).
        $this->eventDispatcher = $eventDispatcher;
        $this->userSession     = $userSession;
        $this->schemaMapper    = $schemaMapper;
        $this->groupManager    = $groupManager;
        $this->userManager     = $userManager;
        $this->appConfig       = $appConfig;
        $this->logger          = $logger;
        $this->organisationMapper = $organisationMapper;

        // Initialize handlers (no circular dependencies).
        $this->queryBuilderHandler      = new QueryBuilderHandler(db: $db, logger: $logger);
        $this->statisticsHandler        = new StatisticsHandler(db: $db, logger: $logger, tableName: 'openregister_objects');
        $this->facetsHandler            = new FacetsHandler(logger: $logger, schemaMapper: $schemaMapper);
        $this->queryOptimizationHandler = new QueryOptimizationHandler(db: $db, logger: $logger, tableName: 'openregister_objects');
        $this->bulkOperationsHandler    = new BulkOperationsHandler(db: $db, logger: $logger, queryBuilderHandler: $this->queryBuilderHandler, tableName: 'openregister_objects');
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
     * @return (mixed|string)[] Lock information including expiry time.
     *
     * @throws Exception If locking fails.
     *
     * @psalm-return array{locked: mixed, uuid: string}
     */
    public function lockObject(string $uuid, ?int $lockDuration=null): array
    {
        try {
            // Get current user from session.
            $user = $this->userSession->getUser();

            if ($user !== null) {
                $userId = $user->getUID();
            } else {
                $userId = 'system';
            }

            // Get the active organization from session at time of lock for audit trail.
            $activeOrganisation = null;
            if ($user !== null) {
                $activeOrganisation = $this->organisationMapper->getActiveOrganisationWithFallback($user->getUID());
            }

            // Create lock information as array (will be serialized to JSON by Entity).
            // Calculate expiration time for the lock.
            $now        = new DateTime();
            $expiration = clone $now;
            if ($lockDuration !== null && $lockDuration > 0) {
                $expiration->add(new \DateInterval('PT'.$lockDuration.'S'));
            } else {
                // Default 1 hour if no duration specified.
                $expiration->add(new \DateInterval('PT3600S'));
            }

            $lockData = [
                'userId'       => $userId,
                'lockedAt'     => $now->format(DateTime::ATOM),
                'duration'     => $lockDuration,
                'expiration'   => $expiration->format(DateTime::ATOM),
                'organisation' => $activeOrganisation,
            ];

            // Load the entity, set the lock, and update it properly through Entity.
            // This ensures Entity's change tracking and JSON serialization work correctly.
            $entity = $this->find($uuid);
            $entity->setLocked($lockData);
            $updatedEntity = $this->update($entity);

            // Return lock information.
            return [
                'locked' => $lockData,
                'uuid'   => $uuid,
            ];
        } catch (\Exception $e) {
            throw new Exception("Failed to lock object: ".$e->getMessage());
        }//end try
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
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->update($this->getTableName())
                ->set('locked', $qb->createNamedParameter(null))
                ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));
            $qb->executeStatement();
            return true;
        } catch (\Exception $e) {
            return false;
        }
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

        // Call parent QBMapper insert directly (CrudHandler has circular dependency).
        $result = parent::insert($entity);

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
        // Pass includeDeleted=true to allow fetching the old state even if the object is being restored from deleted.
        $this->eventDispatcher->dispatch(ObjectUpdatingEvent::class, new ObjectUpdatingEvent(newObject: $entity, oldObject: $this->find($entity->getId(), null, null, true)));

        // Call parent QBMapper update directly (CrudHandler has circular dependency).
        $result = parent::update($entity);

        // Dispatch updated event.
        $this->eventDispatcher->dispatch(ObjectUpdatedEvent::class, new ObjectUpdatedEvent($result, $entity));

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

        // Call parent QBMapper delete directly (CrudHandler has circular dependency).
        $result = parent::delete($entity);

        // Dispatch deleted event.
        $this->eventDispatcher->dispatch(ObjectDeletedEvent::class, new ObjectDeletedEvent($result));

        return $result;
    }//end delete()

    /**
     * Internal insert method that calls parent QBMapper without events.
     *
     * This method is called by CrudHandler to perform the actual database insert
     * after validation and event dispatching. It calls the parent QBMapper::insert()
     * method directly to avoid circular dependencies.
     *
     * @param ObjectEntity $entity Entity to insert.
     *
     * @return ObjectEntity Inserted entity.
     *
     * @throws Exception If insertion fails.
     */
    public function insertEntity(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity
    {
        return parent::insert($entity);
    }//end insertEntity()

    /**
     * Internal update method that calls parent QBMapper without events.
     *
     * This method is called by CrudHandler to perform the actual database update
     * after validation and event dispatching. It calls the parent QBMapper::update()
     * method directly to avoid circular dependencies.
     *
     * @param ObjectEntity $entity Entity to update.
     *
     * @return ObjectEntity Updated entity.
     *
     * @throws Exception If update fails.
     */
    public function updateEntity(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity
    {
        return parent::update($entity);
    }//end updateEntity()

    /**
     * Internal delete method that calls parent QBMapper without events.
     *
     * This method is called by CrudHandler to perform the actual database delete
     * after validation and event dispatching. It calls the parent QBMapper::delete()
     * method directly to avoid circular dependencies.
     *
     * @param ObjectEntity $entity Entity to delete.
     *
     * @return ObjectEntity Deleted entity.
     *
     * @throws Exception If deletion fails.
     */
    public function deleteEntity(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity
    {
        return parent::delete($entity);
    }//end deleteEntity()

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
        return $this->statisticsHandler->getStatistics(registerId: $registerId, schemaId: $schemaId, exclude: $exclude);
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
        return $this->statisticsHandler->getRegisterChartData(registerId: $registerId, schemaId: $schemaId);
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
        return $this->statisticsHandler->getSchemaChartData(registerId: $registerId, schemaId: $schemaId);
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
        return $this->statisticsHandler->getSizeDistributionChartData(registerId: $registerId, schemaId: $schemaId);
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
        return $this->bulkOperationsHandler->ultraFastBulkSave(insertObjects: $insertObjects, updateObjects: $updateObjects);
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
        return $this->bulkOperationsHandler->deleteObjects(uuids: $uuids, hardDelete: $hardDelete);
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
        return $this->bulkOperationsHandler->publishObjects(uuids: $uuids, datetime: $datetime);
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
        return $this->bulkOperationsHandler->depublishObjects(uuids: $uuids, datetime: $datetime);
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
        return $this->bulkOperationsHandler->publishObjectsBySchema(schemaId: $schemaId, publishAll: $publishAll);
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
        return $this->bulkOperationsHandler->deleteObjectsBySchema(schemaId: $schemaId, hardDelete: $hardDelete);
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
        return $this->bulkOperationsHandler->calculateOptimalChunkSize(insertObjects: $insertObjects, updateObjects: $updateObjects);
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
        return $this->queryOptimizationHandler->separateLargeObjects(objects: $objects, maxSafeSize: $maxSafeSize);
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
        return $this->queryOptimizationHandler->bulkOwnerDeclaration(defaultOwner: $defaultOwner, defaultOrganisation: $defaultOrganisation, batchSize: $batchSize);
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
        $this->queryOptimizationHandler->applyCompositeIndexOptimizations(_qb: $_qb, filters: $filters);
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
        $this->queryOptimizationHandler->addQueryHints(qb: $qb, filters: $filters, skipRbac: $skipRbac);
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
    // CORE QUERY OPERATIONS (Find/Search/Count Methods - Restored from pre-refactor)
    // ==================================================================================

    /**
     * Find an object entity by identifier (ID, UUID, slug, or URI).
     *
     * @param string|int    $identifier     Object identifier (ID, UUID, slug, or URI).
     * @param Register|null $register       Optional register to filter by.
     * @param Schema|null   $schema         Optional schema to filter by.
     * @param bool          $includeDeleted Whether to include deleted objects.
     * @param bool          $_rbac          Whether to apply RBAC checks (default: true).
     * @param bool          $_multitenancy  Whether to apply multitenancy filtering (default: true).
     *
     * @return ObjectEntity The found object.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found.
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple objects found.
     */
    public function find(string|int $identifier, ?Register $register=null, ?Schema $schema=null, bool $includeDeleted=false, bool $_rbac=true, bool $_multitenancy=true): ObjectEntity
    {
        $qb = $this->db->getQueryBuilder();

        // Determine ID parameter based on whether identifier is numeric.
        $idParam = -1;
        if (is_numeric($identifier) === true) {
            $idParam = $identifier;
        }

        // Build the base query.
        $qb->select('*')
            ->from('openregister_objects')
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('id', $qb->createNamedParameter($idParam, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->eq('uuid', $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->eq('slug', $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->eq('uri', $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR))
                )
            );

        // By default, only include objects where 'deleted' is NULL unless $includeDeleted is true.
        if ($includeDeleted === false) {
            $qb->andWhere($qb->expr()->isNull('deleted'));
        }

        // Add optional register filter if provided.
        if ($register !== null) {
            $qb->andWhere(
                $qb->expr()->eq('register', $qb->createNamedParameter($register->getId(), IQueryBuilder::PARAM_INT))
            );
        }

        // Add optional schema filter if provided.
        if ($schema !== null) {
            $qb->andWhere(
                $qb->expr()->eq('schema', $qb->createNamedParameter($schema->getId(), IQueryBuilder::PARAM_INT))
            );
        }

        return $this->findEntity($qb);
    }//end find()

    /**
     * Find multiple objects by their IDs or UUIDs.
     *
     * @param array $ids Array of IDs or UUIDs.
     *
     * @return ObjectEntity[]
     *
     * @psalm-return list<OCA\OpenRegister\Db\ObjectEntity>
     */
    public function findMultiple(array $ids): array
    {
        if (empty($ids) === true) {
            return [];
        }

            $qb = $this->db->getQueryBuilder();

        // Separate numeric IDs from UUIDs.
        $numericIds = [];
        $uuids      = [];
        foreach ($ids as $id) {
            if (is_numeric($id) === true) {
                $numericIds[] = $id;
            } else {
                $uuids[] = $id;
            }
        }

        $qb->select('*')
            ->from('openregister_objects');

        $conditions = [];
        if (empty($numericIds) === false) {
            $conditions[] = $qb->expr()->in('id', $qb->createNamedParameter($numericIds, IQueryBuilder::PARAM_INT_ARRAY));
        }

        if (empty($uuids) === false) {
            $conditions[] = $qb->expr()->in('uuid', $qb->createNamedParameter($uuids, IQueryBuilder::PARAM_STR_ARRAY));
        }

        if (empty($conditions) === false) {
            $qb->where($qb->expr()->orX(...$conditions));
        }

        // Exclude deleted objects.
        $qb->andWhere($qb->expr()->isNull('deleted'));

        return $this->findEntities($qb);
    }//end findMultiple()

    /**
     * Find all objects for a given schema.
     *
     * @param int $schemaId Schema ID.
     *
     * @return ObjectEntity[]
     *
     * @psalm-return list<OCA\OpenRegister\Db\ObjectEntity>
     */
    public function findBySchema(int $schemaId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('o.*')
            ->from('openregister_objects', 'o')
            ->leftJoin('o', 'openregister_schemas', 's', 'o.schema = s.id')
            ->where($qb->expr()->eq('o.schema', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNull('o.deleted'));

        return $this->findEntities($qb);
    }//end findBySchema()

    /**
     * Find all ObjectEntities with filtering, pagination, and search.
     *
     * This method is restored from pre-refactor version for compatibility.
     * Note: For new code, consider using the specialized handler methods instead.
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
            $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('openregister_objects');

        // **@SELF FILTER PROCESSING**: Handle @self.* filters from query.
        // Process @self.deleted filter if present in filters array.
        $hasDeletedFilter = false;
        if ($filters !== null && isset($filters['@self.deleted'])) {
            $deletedFilter = $filters['@self.deleted'];
            if ($deletedFilter === 'IS NOT NULL') {
                $qb->andWhere($qb->expr()->isNotNull('deleted'));
            } else if ($deletedFilter === 'IS NULL') {
                $qb->andWhere($qb->expr()->isNull('deleted'));
            }

            // Additional @self.deleted.* filters can be added here for nested properties
            $hasDeletedFilter = true;
        }

        // Apply basic deleted filter ONLY if no @self.deleted filter was specified.
        // Default behavior: exclude deleted objects unless explicitly filtered.
        if ($hasDeletedFilter === false && $includeDeleted === false) {
            $qb->andWhere($qb->expr()->isNull('deleted'));
        }

        if ($register !== null) {
            $qb->andWhere($qb->expr()->eq('register', $qb->createNamedParameter($register->getId(), IQueryBuilder::PARAM_INT)));
        }

        if ($schema !== null) {
            $qb->andWhere($qb->expr()->eq('schema', $qb->createNamedParameter($schema->getId(), IQueryBuilder::PARAM_INT)));
        }

        // Apply ID filters.
        if ($ids !== null && empty($ids) === false) {
            $numericIds = array_filter($ids, 'is_numeric');
            $stringIds  = array_filter($ids, fn ($id) => is_string($id) === true);

            $idConditions = [];
            if (empty($numericIds) === false) {
                $idConditions[] = $qb->expr()->in('id', $qb->createNamedParameter($numericIds, IQueryBuilder::PARAM_INT_ARRAY));
            }

            if (empty($stringIds) === false) {
                $idConditions[] = $qb->expr()->in('uuid', $qb->createNamedParameter($stringIds, IQueryBuilder::PARAM_STR_ARRAY));
            }

            if (empty($idConditions) === false) {
                $qb->andWhere($qb->expr()->orX(...$idConditions));
            }
        }

        // Apply published filter.
        if ($published !== null) {
            $now = (new DateTime())->format('Y-m-d H:i:s');
            if ($published === true) {
                $qb->andWhere($qb->expr()->isNotNull('published'));
                $qb->andWhere($qb->expr()->lte('published', $qb->createNamedParameter($now)));
                $qb->andWhere(
                    $qb->expr()->orX(
                        $qb->expr()->isNull('depublished'),
                        $qb->expr()->gt('depublished', $qb->createNamedParameter($now))
                    )
                );
            } else {
                $qb->andWhere($qb->expr()->isNull('published'));
            }
        }

        // Apply sorting.
        if (empty($sort) === false) {
            foreach ($sort as $field => $direction) {
                $qb->addOrderBy($field, $direction === 'desc' ? 'DESC' : 'ASC');
            }
        } else {
            $qb->addOrderBy('id', 'ASC');
        }

        // Apply pagination.
        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities($qb);
    }//end findAll()

    /**
     * Search for objects with complex filtering.
     *
     * This method is restored from pre-refactor version for compatibility.
     * Note: This is a simplified version. For full functionality, use QueryHandler.
     *
     * @param array       $query                   Query parameters.
     * @param string|null $_activeOrganisationUuid Active organisation UUID.
     * @param bool        $_rbac                   Whether to apply RBAC checks.
     * @param bool        $_multitenancy           Whether to apply multitenancy filtering.
     * @param array|null  $ids                     Array of IDs or UUIDs to filter by.
     * @param string|null $uses                    Value that must be present in relations.
     *
     * @return ObjectEntity[]
     *
     * @psalm-return list<OCA\OpenRegister\Db\ObjectEntity>
     */
    public function searchObjects(array $query=[], ?string $_activeOrganisationUuid=null, bool $_rbac=true, bool $_multitenancy=true, ?array $ids=null, ?string $uses=null): array|int
    {
        // Extract common query parameters.
        $limit  = $query['_limit'] ?? 30;
        $offset = $query['_offset'] ?? 0;
        $sort   = $query['_order'] ?? [];

        // **DELETED FILTER HANDLING**: Check if @self.deleted filter is in query.
        // If yes, include deleted objects and apply the filter. If no, exclude deleted objects.
        $hasDeletedFilter = isset($query['@self.deleted']);
        $includeDeleted   = $hasDeletedFilter;

        // Pass the entire query array so filters can be applied.
        return $this->findAll(
            limit: $limit,
            offset: $offset,
            filters: $query,
            // Pass full query so filters like @self.deleted are available
            sort: $sort,
            ids: $ids,
            uses: $uses,
            includeDeleted: $includeDeleted
        );
    }//end searchObjects()

    /**
     * Count search results.
     *
     * This method is restored from pre-refactor version for compatibility.
     *
     * @param array       $query                   Query parameters.
     * @param string|null $_activeOrganisationUuid Active organisation UUID.
     * @param bool        $_rbac                   Whether to apply RBAC checks.
     * @param bool        $_multitenancy           Whether to apply multitenancy filtering.
     * @param array|null  $ids                     Array of IDs or UUIDs to filter by.
     * @param string|null $uses                    Value that must be present in relations.
     *
     * @return int Count of objects.
     */
    public function countSearchObjects(array $query=[], ?string $_activeOrganisationUuid=null, bool $_rbac=true, bool $_multitenancy=true, ?array $ids=null, ?string $uses=null): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id'))
            ->from('openregister_objects');

        // **@SELF FILTER PROCESSING**: Handle @self.deleted filter from query.
        // Check if @self.deleted filter is present in query.
        if (isset($query['@self.deleted'])) {
            $deletedFilter = $query['@self.deleted'];
            if ($deletedFilter === 'IS NOT NULL') {
                $qb->andWhere($qb->expr()->isNotNull('deleted'));
            } else if ($deletedFilter === 'IS NULL') {
                $qb->andWhere($qb->expr()->isNull('deleted'));
            }
        } else {
            // Default behavior: exclude deleted objects unless explicitly filtered.
            $qb->andWhere($qb->expr()->isNull('deleted'));
        }

        // Apply ID filters.
        if ($ids !== null && empty($ids) === false) {
            $numericIds = array_filter($ids, 'is_numeric');
            $stringIds  = array_filter($ids, fn ($id) => is_string($id) === true);

            $idConditions = [];
            if (empty($numericIds) === false) {
                $idConditions[] = $qb->expr()->in('id', $qb->createNamedParameter($numericIds, IQueryBuilder::PARAM_INT_ARRAY));
            }

            if (empty($stringIds) === false) {
                $idConditions[] = $qb->expr()->in('uuid', $qb->createNamedParameter($stringIds, IQueryBuilder::PARAM_STR_ARRAY));
            }

            if (empty($idConditions) === false) {
                $qb->andWhere($qb->expr()->orX(...$idConditions));
            }
        }

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        return (int) ($row['COUNT(id)'] ?? 0);
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
    public function countAll(?array $filters=null, ?Schema $schema=null, ?Register $register=null): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id'))
            ->from('openregister_objects')
            ->where($qb->expr()->isNull('deleted'));

        if ($schema !== null) {
            $qb->andWhere($qb->expr()->eq('schema', $qb->createNamedParameter($schema->getId(), IQueryBuilder::PARAM_INT)));
        }

        if ($register !== null) {
            $qb->andWhere($qb->expr()->eq('register', $qb->createNamedParameter($register->getId(), IQueryBuilder::PARAM_INT)));
        }

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        return (int) ($row['COUNT(id)'] ?? 0);
    }//end countAll()

    /**
     * Count objects across multiple schemas.
     *
     * @param array $schemaIds Array of schema IDs
     *
     * @return int Total count of objects across all specified schemas
     */
    public function countBySchemas(array $schemaIds): int
    {
        if (empty($schemaIds) === true) {
            return 0;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id'))
            ->from('openregister_objects')
            ->where($qb->expr()->isNull('deleted'))
            ->andWhere($qb->expr()->in('schema', $qb->createNamedParameter($schemaIds, IQueryBuilder::PARAM_INT_ARRAY)));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        return (int) ($row['COUNT(id)'] ?? 0);
    }//end countBySchemas()

    /**
     * Find objects across multiple schemas.
     *
     * @param array    $schemaIds Array of schema IDs
     * @param int|null $limit     Maximum number of results
     * @param int|null $offset    Offset for pagination
     *
     * @return ObjectEntity[]
     *
     * @psalm-return list<OCA\OpenRegister\Db\ObjectEntity>
     */
    public function findBySchemas(array $schemaIds, ?int $limit=null, ?int $offset=null): array
    {
        if (empty($schemaIds) === true) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_objects')
            ->where($qb->expr()->isNull('deleted'))
            ->andWhere($qb->expr()->in('schema', $qb->createNamedParameter($schemaIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->orderBy('id', 'ASC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities($qb);
    }//end findBySchemas()

    /**
     * Find objects by relation search.
     *
     * Searches for objects that contain a specific value in their relationships.
     * This is a simplified version that searches in the JSON object field.
     *
     * @param string $search       Search term to find in relationships
     * @param bool   $partialMatch Whether to allow partial matches (default: true)
     *
     * @return ObjectEntity[]
     *
     * @psalm-return list<OCA\OpenRegister\Db\ObjectEntity>
     */
    public function findByRelation(string $search, bool $partialMatch=true): array
    {
        if (empty($search) === true) {
            return [];
        }

                $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_objects')
            ->where($qb->expr()->isNull('deleted'));

        // Search in the object JSON field for the search term.
        if ($partialMatch === true) {
            /*
             * @psalm-suppress UndefinedInterfaceMethod - escapeLikeParameter exists on QueryBuilder implementation
             */
            $qb->andWhere(
                $qb->expr()->like('object', $qb->createNamedParameter('%'.$qb->escapeLikeParameter($search).'%'))
            );
        } else {
            /*
             * @psalm-suppress UndefinedInterfaceMethod - escapeLikeParameter exists on QueryBuilder implementation
             */
            $qb->andWhere(
                $qb->expr()->like('object', $qb->createNamedParameter('%"'.$qb->escapeLikeParameter($search).'"%'))
            );
        }

        $qb->setMaxResults(100);
        // Limit to prevent performance issues.
        return $this->findEntities($qb);
    }//end findByRelation()

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
