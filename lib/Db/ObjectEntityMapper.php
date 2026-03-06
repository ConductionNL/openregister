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

use DateInterval;
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
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
// Use OCA\OpenRegister\Service\MySQLJsonService; // REMOVED: Dead code (never used).
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Db\Entity;
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
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessivePublicCount)
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
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
    private BulkOperationsHandler $bulkOpsHandler;

    /**
     * Query optimization handler - needs QueryBuilderHandler
     *
     * @var QueryOptimizationHandler
     */
    private QueryOptimizationHandler $queryOptHandler;

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
        parent::__construct(db: $db, tableName: 'openregister_objects');

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
        $this->queryBuilderHandler = new QueryBuilderHandler(db: $db, logger: $logger);
        $this->statisticsHandler   = new StatisticsHandler(db: $db, logger: $logger, tableName: 'openregister_objects');
        $this->facetsHandler       = new FacetsHandler(logger: $logger, schemaMapper: $schemaMapper);
        $this->queryOptHandler     = new QueryOptimizationHandler(
            db: $db,
            logger: $logger,
            tableName: 'openregister_objects'
        );
        $this->bulkOpsHandler      = new BulkOperationsHandler(
            db: $db,
            logger: $logger,
            queryBuilderHandler: $this->queryBuilderHandler,
            tableName: 'openregister_objects',
            eventDispatcher: $this->eventDispatcher
        );
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
     * @return ((int|null|string)[]|string)[] Lock result.
     *
     * @throws Exception If locking fails.
     */
    public function lockObject(string $uuid, ?int $lockDuration=null): array
    {
        try {
            // Get current user from session.
            $user = $this->userSession->getUser();

            $userId = 'system';
            if ($user !== null) {
                $userId = $user->getUID();
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
            // Default 1 hour if no duration specified.
            $expiration->add(new DateInterval('PT3600S'));
            if ($lockDuration !== null && $lockDuration > 0) {
                $expiration = clone $now;
                $expiration->add(new DateInterval('PT'.$lockDuration.'S'));
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
            $entity = $this->find(identifier: $uuid);
            $entity->setLocked($lockData);
            $this->update(entity: $entity);

            // Return lock information.
            return [
                'locked' => $lockData,
                'uuid'   => $uuid,
            ];
        } catch (Exception $e) {
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
        } catch (Exception $e) {
            return false;
        }
    }//end unlockObject()

    // ==================================================================================
    // CRUD OPERATIONS (Orchestrated with Events)
    // ==================================================================================

    /**
     * Insert a new object entity with event dispatching and optional magic mapper routing.
     *
     * This method checks if magic mapping is enabled for the register+schema combination.
     * If enabled and auto-create is configured, it delegates to MagicMapper for storage
     * in a dedicated table. Otherwise, it uses standard blob storage.
     *
     * @param ObjectEntity $entity   Entity to insert.
     * @param ?Register    $register Optional register for magic mapper routing.
     * @param ?Schema      $schema   Optional schema for magic mapper routing.
     *
     * @return ObjectEntity Inserted entity.
     *
     * @throws Exception If insertion fails.
     */
    public function insert(Entity $entity, ?Register $register=null, ?Schema $schema=null): Entity
    {
        // Dispatch creating event.
        $creatingEvent = new ObjectCreatingEvent($entity);
        $this->eventDispatcher->dispatchTyped($creatingEvent);

        // Check if a hook stopped propagation (reject mode).
        if ($creatingEvent->isPropagationStopped() === true) {
            throw new HookStoppedException(
                $creatingEvent->getErrors()['message'] ?? 'Object creation rejected by hook'
            );
        }

        // Merge modified data from hooks if any.
        $modifiedData = $creatingEvent->getModifiedData();
        if (empty($modifiedData) === false && $entity instanceof ObjectEntity === true) {
            $objectData = $entity->getObject();
            $entity->setObject(array_merge($objectData, $modifiedData));
        }

        // Check if this entity should use magic mapping.
        // IMPORTANT: Use provided register/schema if available, otherwise extract from entity.
        // This ensures cascade-created objects use the correct routing.
        $useMagicMapper = false;
        if ($entity instanceof ObjectEntity === true) {
            if ($register !== null && $schema !== null) {
                // Use provided register/schema directly (more reliable).
                $useMagicMapper = $this->shouldUseMagicMapperForRegisterSchema(
                    register: $register,
                    schema: $schema
                );
                $this->logger->debug(
                    message: '[ObjectEntityMapper::insert] Using provided register/schema for magic check',
                    context: [
                        'file'       => __FILE__,
                        'line'       => __LINE__,
                        'registerId' => $register->getId(),
                        'schemaId'   => $schema->getId(),
                        'schemaSlug' => $schema->getSlug(),
                        'result'     => $useMagicMapper,
                    ]
                        );
            } else {
                // Fall back to extracting from entity.
                $useMagicMapper = $this->shouldUseMagicMapper(entity: $entity);
            }//end if
        }//end if

        if ($useMagicMapper === true) {
            // No blob fallback — objects live in per-schema tables, not in the
            // empty openregister_objects blob table. Let errors propagate.
            $unifiedMapper = \OC::$server->get(UnifiedObjectMapper::class);
            return $unifiedMapper->insert(entity: $entity, register: $register, schema: $schema);
        }//end if

        // Call parent QBMapper insert directly (CrudHandler has circular dependency).
        $result = parent::insert(entity: $entity);

        // Dispatch created event.
        $this->eventDispatcher->dispatchTyped(new ObjectCreatedEvent(object: $result));

        return $result;
    }//end insert()

    /**
     * Insert entity directly to blob storage (skip magic mapper check).
     *
     * This method is used by UnifiedObjectMapper to avoid circular calls.
     * It performs the same blob storage insert as insert() but skips the magic mapper routing.
     *
     * @param \OCP\AppFramework\Db\Entity $entity Entity to insert.
     *
     * @return ObjectEntity Inserted entity.
     */
    public function insertDirectBlobStorage(\OCP\AppFramework\Db\Entity $entity): ObjectEntity
    {
        // Dispatch creating event (pre-save hook).
        $creatingEvent = new ObjectCreatingEvent($entity);
        $this->eventDispatcher->dispatchTyped($creatingEvent);

        // Check if a hook stopped propagation (reject mode).
        if ($creatingEvent->isPropagationStopped() === true) {
            throw new HookStoppedException(
                $creatingEvent->getErrors()['message'] ?? 'Object creation rejected by hook'
            );
        }

        // Merge modified data from hooks if any.
        $modifiedData = $creatingEvent->getModifiedData();
        if (empty($modifiedData) === false && $entity instanceof ObjectEntity === true) {
            $objectData = $entity->getObject();
            $entity->setObject(array_merge($objectData, $modifiedData));
        }

        // Call parent QBMapper insert directly (blob storage).
        $result = parent::insert(entity: $entity);

        // NOTE: ObjectCreatedEvent is dispatched by UnifiedObjectMapper (the facade) to avoid duplicate events.
        // Do NOT dispatch ObjectCreatedEvent here.
        return $result;
    }//end insertDirectBlobStorage()

    /**
     * Check if magic mapping should be used for this entity.
     *
     * Determines whether an ObjectEntity should be stored in a magic mapper table
     * based on the register configuration.
     *
     * @param ObjectEntity $entity The entity to check.
     *
     * @return bool True if magic mapping should be used, false otherwise.
     */

    /**
     * Check if magic mapper should be used for an entity.
     *
     * This method fetches the register and schema from the entity and delegates
     * to shouldUseMagicMapperForRegisterSchema() which supports both configuration formats.
     *
     * @param ObjectEntity $entity The entity to check
     *
     * @return bool True if magic mapper should be used
     */
    private function shouldUseMagicMapper(ObjectEntity $entity): bool
    {
        try {
            // Entity must have register and schema set.
            $registerId = $entity->getRegister();
            $schemaId   = $entity->getSchema();

            if (empty($registerId) === true || empty($schemaId) === true) {
                return false;
            }

            // Get RegisterMapper and SchemaMapper and fetch the objects.
            $registerMapper = \OC::$server->get(RegisterMapper::class);
            $schemaMapper   = \OC::$server->get(SchemaMapper::class);
            // Pass $_multitenancy=false to bypass multitenancy filter for internal lookup.
            $register = $registerMapper->find(id: $registerId, _multitenancy: false);
            $schema   = $schemaMapper->find(id: $schemaId, _multitenancy: false);

            // Delegate to the shared method that supports both configuration formats.
            return $this->shouldUseMagicMapperForRegisterSchema(register: $register, schema: $schema);
        } catch (Exception $e) {
            // Let errors propagate — defaulting to blob is wrong since blob table is empty.
            $this->logger->error(
                message: '[ObjectEntityMapper] Failed to determine magic mapping status',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
            throw $e;
        }//end try
    }//end shouldUseMagicMapper()

    /**
     * Check if magic mapper should be used for given register and schema.
     *
     * Delegates to Register::isMagicMappingEnabledForSchema() which is the
     * SINGLE SOURCE OF TRUTH for magic mapping checks.
     *
     * @param Register $register The register
     * @param Schema   $schema   The schema
     *
     * @return bool True if magic mapper should be used
     */
    private function shouldUseMagicMapperForRegisterSchema(Register $register, Schema $schema): bool
    {
        try {
            $result = $register->isMagicMappingEnabledForSchema(
                schemaId: $schema->getId(),
                schemaSlug: $schema->getSlug()
            );

            if ($result === true) {
                $this->logger->debug(
                    message: '[ObjectEntityMapper] Magic mapping enabled for schema',
                    context: [
                        'file'       => __FILE__,
                        'line'       => __LINE__,
                        'registerId' => $register->getId(),
                        'schemaId'   => $schema->getId(),
                        'schemaSlug' => $schema->getSlug(),
                    ]
                );
            }

            return $result;
        } catch (Exception $e) {
            // Let errors propagate — defaulting to blob is wrong since blob table is empty.
            $this->logger->error(
                message: '[ObjectEntityMapper] Failed to determine magic mapping status',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
            throw $e;
        }//end try
    }//end shouldUseMagicMapperForRegisterSchema()

    /**
     * Update an existing object entity with event dispatching and optional magic mapper routing.
     *
     * This method checks if magic mapping is enabled for the register+schema combination.
     * If enabled, it delegates to MagicMapper. Otherwise, it uses standard blob storage.
     *
     * @param ObjectEntity $entity   Entity to update.
     * @param ?Register    $register Optional register for magic mapper routing.
     * @param ?Schema      $schema   Optional schema for magic mapper routing.
     *
     * @return ObjectEntity Updated entity.
     *
     * @throws Exception If update fails.
     */
    public function update(Entity $entity, ?Register $register=null, ?Schema $schema=null): Entity
    {
        // Dispatch updating event.
        // Pass includeDeleted=true to allow fetching the old state even if the object is being restored from deleted.
        // CRITICAL: Pass register/schema for magic mapper routing.
        // CRITICAL: Use UUID (not numeric ID) to ensure we get the correct object.
        $oldObject = null;
        try {
            $oldObject = $this->find(
                identifier: $entity->getUuid(),
            // Use UUID instead of ID!
                register: $register,
                schema: $schema,
                includeDeleted: true
            );
        } catch (Exception $e) {
            // Ignore errors when fetching old object - it's just for event/audit trail.
            $this->logger->debug(
                message: '[ObjectEntityMapper] Could not fetch old object for event',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
        }

        $updatingEvent = new ObjectUpdatingEvent($entity, $oldObject);
        $this->eventDispatcher->dispatchTyped($updatingEvent);

        // Check if a hook stopped propagation (reject mode).
        if ($updatingEvent->isPropagationStopped() === true) {
            throw new HookStoppedException(
                $updatingEvent->getErrors()['message'] ?? 'Object update rejected by hook'
            );
        }

        // Merge modified data from hooks if any.
        $modifiedData = $updatingEvent->getModifiedData();
        if (empty($modifiedData) === false && $entity instanceof ObjectEntity === true) {
            $objectData = $entity->getObject();
            $entity->setObject(array_merge($objectData, $modifiedData));
        }

        // Check if this entity should use magic mapping.
        // Use register+schema parameters if provided, otherwise try to resolve from entity.
        $useMagic = false;
        if ($register !== null && $schema !== null) {
            $this->logger->debug(
                message: '[ObjectEntityMapper::update] Has register+schema params - checking magic mapper',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            $useMagic = $this->shouldUseMagicMapperForRegisterSchema(register: $register, schema: $schema);
            $this->logger->debug(
                message: '[ObjectEntityMapper::update] shouldUseMagicMapper result: FALSE',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            if ($useMagic === true) {
                $this->logger->debug(
                    message: '[ObjectEntityMapper::update] shouldUseMagicMapper result: TRUE',
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
            }
        } else if ($entity instanceof ObjectEntity) {
            $this->logger->debug(
                message: '[ObjectEntityMapper::update] No register/schema params - checking entity',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            $useMagic = $this->shouldUseMagicMapper(entity: $entity);
        }//end if

        if ($useMagic === true) {
            // No blob fallback — objects live in per-schema tables, not in the
            // empty openregister_objects blob table. Let errors propagate.
            $unifiedMapper = \OC::$server->get(UnifiedObjectMapper::class);
            return $unifiedMapper->update(entity: $entity, register: $register, schema: $schema);
        }//end if

        // Call parent QBMapper update directly (CrudHandler has circular dependency).
        $this->logger->error(
            message: '[ObjectEntityMapper] DEBUG: About to call parent::update with entity object',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'app'        => 'openregister',
                'uuid'       => $entity->getUuid(),
                'objectData' => json_encode($entity->getObject()),
            ]
        );
        $result = parent::update(entity: $entity);

        // Dispatch updated event with correct oldObject.
        $this->eventDispatcher->dispatchTyped(new ObjectUpdatedEvent(newObject: $result, oldObject: $oldObject));

        return $result;
    }//end update()

    /**
     * Update entity directly to blob storage (skip magic mapper check).
     *
     * This method is used by UnifiedObjectMapper to avoid circular calls.
     *
     * @param \OCP\AppFramework\Db\Entity $entity    Entity to update.
     * @param \OCP\AppFramework\Db\Entity $oldEntity The entity state before update (for events).
     *
     * @return ObjectEntity Updated entity.
     */
    public function updateDirectBlobStorage(\OCP\AppFramework\Db\Entity $entity, \OCP\AppFramework\Db\Entity $oldEntity=null): ObjectEntity
    {
        // Use provided oldEntity or fallback to current entity.
        if ($oldEntity === null) {
            $oldEntity = $entity;
        }

        // Dispatch updating event (pre-save hook).
        $updatingEvent = new ObjectUpdatingEvent($entity, $oldEntity);
        $this->eventDispatcher->dispatchTyped($updatingEvent);

        // Check if a hook stopped propagation (reject mode).
        if ($updatingEvent->isPropagationStopped() === true) {
            throw new HookStoppedException(
                $updatingEvent->getErrors()['message'] ?? 'Object update rejected by hook'
            );
        }

        // Merge modified data from hooks if any.
        $modifiedData = $updatingEvent->getModifiedData();
        if (empty($modifiedData) === false && $entity instanceof ObjectEntity === true) {
            $objectData = $entity->getObject();
            $entity->setObject(array_merge($objectData, $modifiedData));
        }

        // Call parent QBMapper update directly (blob storage).
        $this->logger->error(
            message: '[ObjectEntityMapper] updateDirectBlobStorage calling parent::update',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'app'        => 'openregister',
                'id'         => $entity->getId(),
                'uuid'       => $entity->getUuid(),
                'objectData' => json_encode($entity->getObject()),
            ]
                );
        $result = parent::update(entity: $entity);
        $this->logger->error(
            message: '[ObjectEntityMapper] updateDirectBlobStorage after parent::update',
            context: [
                'file'         => __FILE__,
                'line'         => __LINE__,
                'app'          => 'openregister',
                'resultObject' => json_encode($result->getObject()),
            ]
                );

        // NOTE: ObjectUpdatedEvent is dispatched by UnifiedObjectMapper (the facade) to avoid duplicate events.
        // Do NOT dispatch ObjectUpdatedEvent here.
        return $result;
    }//end updateDirectBlobStorage()

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
        $deletingEvent = new ObjectDeletingEvent($entity);
        $this->eventDispatcher->dispatchTyped($deletingEvent);

        // Check if a hook stopped propagation (reject mode).
        if ($deletingEvent->isPropagationStopped() === true) {
            throw new HookStoppedException(
                $deletingEvent->getErrors()['message'] ?? 'Object deletion rejected by hook'
            );
        }

        // Call parent QBMapper delete directly (CrudHandler has circular dependency).
        $result = parent::delete(entity: $entity);

        // Dispatch deleted event.
        $this->eventDispatcher->dispatchTyped(new ObjectDeletedEvent(object: $result));

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
        return parent::insert(entity: $entity);
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
        return parent::update(entity: $entity);
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
        return parent::delete(entity: $entity);
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
     * @return int[] Statistics including total, size, invalid, deleted, locked, published counts.
     *
     * @psalm-return array{total: int, size: int, invalid: int, deleted: int, locked: int, published: int}
     */
    public function getStatistics(int|array|null $registerId=null, int|array|null $schemaId=null, array $exclude=[]): array
    {
        return $this->statisticsHandler->getStatistics(registerId: $registerId, schemaId: $schemaId, exclude: $exclude);
    }//end getStatistics()

    /**
     * Get statistics grouped by schema for multiple schemas in a single query
     *
     * @param int[] $schemaIds Array of schema IDs to get statistics for
     *
     * @return array<int, array{total: int, size: int, invalid: int, deleted: int, locked: int, published: int}>
     */
    public function getStatisticsGroupedBySchema(array $schemaIds): array
    {
        return $this->statisticsHandler->getStatisticsGroupedBySchema(schemaIds: $schemaIds);
    }//end getStatisticsGroupedBySchema()

    /**
     * Get chart data for objects grouped by register.
     *
     * @param int|null $registerId Filter by register ID.
     * @param int|null $schemaId   Filter by schema ID.
     *
     * @return (int|mixed|string)[][] Chart data with 'labels' and 'series' keys.
     *
     * @psalm-return array{labels: array<'Unknown'|mixed>, series: array<int>}
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
     * @return (int|mixed|string)[][] Chart data with 'labels' and 'series' keys.
     *
     * @psalm-return array{labels: array<'Unknown'|mixed>, series: array<int>}
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
     * @return (int|string)[][] Chart data with 'labels' and 'series' keys.
     *
     * @psalm-return array{labels: list<'0-1 KB'|'1-10 KB'|'10-100 KB'|'100 KB-1 MB'|'> 1 MB'>, series: list<int>}
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
     * @return ((((int|mixed|string)[]|int|mixed|string)[]|mixed|string)[]|string)[][] Facet results.
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
     * @return array[] Facetable fields with their configuration.
     *
     * @throws \OCP\DB\Exception If a database error occurs.
     *
     * @psalm-return array<string, array>
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
        return $this->bulkOpsHandler->ultraFastBulkSave(insertObjects: $insertObjects, updateObjects: $updateObjects);
    }//end ultraFastBulkSave()

    /**
     * Perform bulk delete operations on objects by UUID.
     *
     * @param array $uuids      Array of object UUIDs to delete.
     * @param bool  $hardDelete Whether to force hard delete.
     *
     * @return array Array of UUIDs of deleted objects.
     */

    /**
     * Perform bulk delete operations on objects by UUID.
     *
     * @param array         $uuids      Array of object UUIDs to delete.
     * @param bool          $hardDelete Whether to perform hard delete.
     * @param Register|null $register   Optional register context for magic mapper routing.
     * @param Schema|null   $schema     Optional schema context for magic mapper routing.
     *
     * @return array Array of UUIDs of deleted objects.
     *
     * @psalm-return list<mixed>
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Hard delete toggle controls permanent vs soft delete
     */
    public function deleteObjects(
        array $uuids=[],
        bool $hardDelete=false,
        ?Register $register=null,
        ?Schema $schema=null
    ): array {
        // Check if magic mapping should be used.
        $useMagic = $register !== null && $schema !== null
            && $this->shouldUseMagicMapperForRegisterSchema(register: $register, schema: $schema) === true;
        if ($useMagic === true) {
            try {
                $this->logger->debug(
                    message: '[ObjectEntityMapper] Routing deleteObjects() to MagicMapper',
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                $deletedUuids = [];
                foreach ($uuids as $uuid) {
                    try {
                        $unifiedObjectMapper = \OC::$server->get(UnifiedObjectMapper::class);
                        $object = $unifiedObjectMapper->find(
                            identifier: $uuid,
                            register: $register,
                            schema: $schema
                        );

                        if ($hardDelete === true) {
                            // Hard delete: remove from database.
                            $unifiedObjectMapper->delete($object);
                        }

                        if ($hardDelete === false) {
                            // Soft delete: set deleted timestamp.
                            $object->setDeleted(new DateTime());
                            $unifiedObjectMapper->update($object);
                        }

                        $deletedUuids[] = $uuid;
                    } catch (Exception $e) {
                        $this->logger->warning(
                            message: '[ObjectEntityMapper] Failed to delete object via magic mapper',
                            context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $uuid, 'error' => $e->getMessage()]
                        );
                    }//end try
                }//end foreach

                return $deletedUuids;
            } catch (Exception $e) {
                // No blob fallback — let errors propagate.
                throw $e;
            }//end try
        }//end if

        return $this->bulkOpsHandler->deleteObjects(uuids: $uuids, hardDelete: $hardDelete);
    }//end deleteObjects()

    /**
     * Perform bulk publish operations on objects by UUID.
     *
     * @param array         $uuids    Array of object UUIDs to publish.
     * @param DateTime|bool $datetime Optional datetime for publishing.
     * @param Register|null $register Optional register context for magic mapper routing.
     * @param Schema|null   $schema   Optional schema context for magic mapper routing.
     *
     * @return array Array of UUIDs of published objects.
     *
     * @psalm-return list<mixed>
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  DateTime or bool controls publish timing
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function publishObjects(
        array $uuids=[],
        DateTime|bool $datetime=true,
        ?Register $register=null,
        ?Schema $schema=null
    ): array {
        // Check if magic mapping should be used.
        $useMagic = $register !== null && $schema !== null
            && $this->shouldUseMagicMapperForRegisterSchema(register: $register, schema: $schema) === true;
        if ($useMagic === true) {
            try {
                $this->logger->debug(
                    message: '[ObjectEntityMapper] Routing publishObjects() to MagicMapper',
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                // For each UUID, update the published timestamp in the magic mapper table.
                $publishedUuids = [];
                foreach ($uuids as $uuid) {
                    try {
                        // Find the object via magic mapper.
                        $unifiedObjectMapper = \OC::$server->get(UnifiedObjectMapper::class);
                        $object = $unifiedObjectMapper->find(
                            identifier: $uuid,
                            register: $register,
                            schema: $schema
                        );

                        // Update published timestamp.
                        if ($datetime === true) {
                            $object->setPublished(new DateTime());
                        } else if ($datetime instanceof DateTime) {
                            $object->setPublished($datetime);
                        } else if ($datetime === false) {
                            $object->setPublished(null);
                        }

                        // Save the updated object.
                        $unifiedObjectMapper->update($object);
                        $publishedUuids[] = $uuid;
                    } catch (Exception $e) {
                        $this->logger->warning(
                            message: '[ObjectEntityMapper] Failed to publish object via magic mapper',
                            context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $uuid, 'error' => $e->getMessage()]
                        );
                    }//end try
                }//end foreach

                return $publishedUuids;
            } catch (Exception $e) {
                // No blob fallback — let errors propagate.
                throw $e;
            }//end try
        }//end if

        // Original blob storage publish logic.
        return $this->bulkOpsHandler->publishObjects(uuids: $uuids, datetime: $datetime);
    }//end publishObjects()

    /**
     * Perform bulk depublish operations on objects by UUID.
     *
     * @param array         $uuids    Array of object UUIDs to depublish.
     * @param DateTime|bool $datetime Optional datetime for depublishing.
     * @param Register|null $register Optional register context for magic mapper routing.
     * @param Schema|null   $schema   Optional schema context for magic mapper routing.
     *
     * @return array Array of UUIDs of depublished objects.
     *
     * @psalm-return list<mixed>
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  DateTime or bool controls depublish timing
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function depublishObjects(
        array $uuids=[],
        DateTime|bool $datetime=true,
        ?Register $register=null,
        ?Schema $schema=null
    ): array {
        // Check if magic mapping should be used.
        $useMagic = $register !== null && $schema !== null
            && $this->shouldUseMagicMapperForRegisterSchema(register: $register, schema: $schema) === true;
        if ($useMagic === true) {
            try {
                $this->logger->debug(
                    message: '[ObjectEntityMapper] Routing depublishObjects() to MagicMapper',
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                $depublishedUuids = [];
                foreach ($uuids as $uuid) {
                    try {
                        $unifiedObjectMapper = \OC::$server->get(UnifiedObjectMapper::class);
                        $object = $unifiedObjectMapper->find(
                            identifier: $uuid,
                            register: $register,
                            schema: $schema
                        );

                        if ($datetime === true) {
                            $object->setDepublished(new DateTime());
                        } else if ($datetime instanceof DateTime) {
                            $object->setDepublished($datetime);
                        } else if ($datetime === false) {
                            $object->setDepublished(null);
                        }

                        $unifiedObjectMapper->update($object);
                        $depublishedUuids[] = $uuid;
                    } catch (Exception $e) {
                        $this->logger->warning(
                            message: '[ObjectEntityMapper] Failed to depublish object via magic mapper',
                            context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $uuid, 'error' => $e->getMessage()]
                        );
                    }//end try
                }//end foreach

                return $depublishedUuids;
            } catch (Exception $e) {
                // No blob fallback — let errors propagate.
                throw $e;
            }//end try
        }//end if

        return $this->bulkOpsHandler->depublishObjects(uuids: $uuids, datetime: $datetime);
    }//end depublishObjects()

    /**
     * Publish all objects belonging to a specific schema.
     *
     * @param int  $schemaId   Schema ID.
     * @param bool $publishAll Whether to publish all objects.
     *
     * @return (array|int)[] Statistics about the publishing operation.
     *
     * @throws \Exception If the publishing operation fails.
     *
     * @psalm-return array{published_count: int<0, max>, published_uuids: list<mixed>, schema_id: int}
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Publish all toggle controls scope of operation
     */
    public function publishObjectsBySchema(int $schemaId, bool $publishAll=false): array
    {
        return $this->bulkOpsHandler->publishObjectsBySchema(schemaId: $schemaId, publishAll: $publishAll);
    }//end publishObjectsBySchema()

    /**
     * Delete all objects belonging to a specific schema.
     *
     * @param int  $schemaId   Schema ID.
     * @param bool $hardDelete Whether to force hard delete.
     *
     * @return (array|int)[]
     *
     * @throws \Exception If the deletion operation fails.
     *
     * @psalm-return array{deleted_count: int<0, max>, deleted_uuids: list<mixed>, schema_id: int}
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Hard delete toggle controls permanent vs soft delete
     */
    public function deleteObjectsBySchema(int $schemaId, bool $hardDelete=false): array
    {
        return $this->bulkOpsHandler->deleteObjectsBySchema(schemaId: $schemaId, hardDelete: $hardDelete);
    }//end deleteObjectsBySchema()

    /**
     * Delete all objects belonging to a specific register.
     *
     * @param int $registerId Register ID.
     *
     * @return (array|int)[]
     *
     * @throws \Exception If the deletion operation fails.
     *
     * @psalm-return array{deleted_count: int<0, max>, deleted_uuids: list<mixed>, register_id: int}
     */
    public function deleteObjectsByRegister(int $registerId): array
    {
        return $this->bulkOpsHandler->deleteObjectsByRegister($registerId);
    }//end deleteObjectsByRegister()

    /**
     * Process a single chunk of insert objects within a transaction.
     *
     * @param array $insertChunk Array of objects to insert.
     *
     * @return array Array of inserted object UUIDs.
     *
     * @throws \OCP\DB\Exception If a database error occurs.
     *
     * @psalm-return list<mixed>
     */
    public function processInsertChunk(array $insertChunk): array
    {
        return $this->bulkOpsHandler->processInsertChunk($insertChunk);
    }//end processInsertChunk()

    /**
     * Process a single chunk of update objects within a transaction.
     *
     * @param array $updateChunk Array of ObjectEntity instances to update.
     *
     * @return string[] Array of updated object UUIDs.
     *
     * @throws \OCP\DB\Exception If a database error occurs.
     *
     * @psalm-return list<string>
     */
    public function processUpdateChunk(array $updateChunk): array
    {
        return $this->bulkOpsHandler->processUpdateChunk($updateChunk);
    }//end processUpdateChunk()

    /**
     * Calculate optimal chunk size based on actual data size.
     *
     * @param array $insertObjects Array of objects to insert.
     * @param array $updateObjects Array of objects to update.
     *
     * @return int Optimal chunk size in number of objects.
     *
     * @psalm-return int<5, 100>
     */
    public function calculateOptimalChunkSize(array $insertObjects, array $updateObjects): int
    {
        return $this->bulkOpsHandler->calculateOptimalChunkSize(
            insertObjects: $insertObjects,
            updateObjects: $updateObjects
        );
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
     * @return array[] Array with 'large' and 'normal' keys.
     *
     * @psalm-return array{large: list<mixed>, normal: list<mixed>}
     */
    public function separateLargeObjects(array $objects, int $maxSafeSize=1000000): array
    {
        return $this->queryOptHandler->separateLargeObjects(objects: $objects, maxSafeSize: $maxSafeSize);
    }//end separateLargeObjects()

    /**
     * Process large objects individually to prevent packet size errors.
     *
     * @param array $largeObjects Array of large objects to process.
     *
     * @return array Array of processed object UUIDs.
     *
     * @psalm-return list<mixed>
     */
    public function processLargeObjectsIndividually(array $largeObjects): array
    {
        return $this->queryOptHandler->processLargeObjectsIndividually($largeObjects);
    }//end processLargeObjectsIndividually()

    /**
     * Bulk assign default owner and organization to objects.
     *
     * @param string|null $defaultOwner        Default owner to assign.
     * @param string|null $defaultOrganisation Default organization UUID.
     * @param int         $batchSize           Number of objects per batch.
     *
     * @return (DateTime|mixed|string)[] Statistics about the bulk operation.
     *
     * @throws \Exception If the bulk operation fails.
     *
     * @psalm-return array{endTime: DateTime, duration: string,...}
     */
    public function bulkOwnerDeclaration(
        ?string $defaultOwner=null,
        ?string $defaultOrganisation=null,
        int $batchSize=1000
    ): array {
        return $this->queryOptHandler->bulkOwnerDeclaration(
            defaultOwner: $defaultOwner,
            defaultOrganisation: $defaultOrganisation,
            batchSize: $batchSize
        );
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
        return $this->queryOptHandler->setExpiryDate($retentionMs);
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
        $this->queryOptHandler->applyCompositeIndexOptimizations(_qb: $_qb, filters: $filters);
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
        $this->queryOptHandler->optimizeOrderBy($qb);
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
        $this->queryOptHandler->addQueryHints(qb: $qb, filters: $filters, skipRbac: $skipRbac);
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
        return $this->queryOptHandler->hasJsonFilters($filters);
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
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Flags control security filtering behavior
     * @SuppressWarnings(PHPMD.NPathComplexity)       Find operation requires multiple lookup strategies
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function find(
        string|int $identifier,
        ?Register $register=null,
        ?Schema $schema=null,
        bool $includeDeleted=false,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): ObjectEntity {
        // Route through UnifiedObjectMapper when register/schema context is available.
        // This matches how collection endpoints work: always try magic tables first
        // when we have register+schema context, regardless of configuration flags.
        // Previously this only routed to UnifiedObjectMapper when config explicitly
        // enabled magicMapping, causing 404s for objects stored in magic tables
        // without explicit register configuration.
        $hasContext = $register !== null && $schema !== null;

        if ($hasContext === true) {
            $hasContextStr = 'true';
        } else {
            $hasContextStr = 'false';
        }

        if ($register !== null) {
            $registerStr = 'true';
        } else {
            $registerStr = 'false';
        }

        if ($schema !== null) {
            $schemaStr = 'true';
        } else {
            $schemaStr = 'false';
        }

        $this->logger->debug(
            message: '[ObjectEntityMapper::find] Routing check',
            context: [
                'file'            => __FILE__,
                'line'            => __LINE__,
                'hasContext'      => $hasContextStr,
                'registerNotNull' => $registerStr,
                'schemaNotNull'   => $schemaStr,
            ]
        );

        if ($hasContext === true) {
            $this->logger->debug(
                message: '[ObjectEntityMapper] Routing find() to UnifiedObjectMapper',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            // Use the UnifiedObjectMapper to handle the find, which will route
            // to MagicMapper or blob storage based on table existence.
            // No blob fallback — objects live in per-schema tables, not in the
            // empty openregister_objects blob table.
            $unifiedObjectMapper = \OC::$server->get(UnifiedObjectMapper::class);
            return $unifiedObjectMapper->find(
                identifier: $identifier,
                register: $register,
                schema: $schema,
                includeDeleted: $includeDeleted,
                rbac: $_rbac,
                multitenancy: $_multitenancy
            );
        }//end if

        $qb = $this->db->getQueryBuilder();

        // Build the base query.
        $qb->select('*')
            ->from('openregister_objects');

        // Build OR conditions for matching against id, uuid, slug, or uri.
        // Note: Only include id comparison if identifier is actually numeric (PostgreSQL strict typing).
        // Build OR conditions for matching against id, uuid, slug, or uri.
        // Note: Only include id comparison if $identifier is actually numeric (PostgreSQL strict typing).
        if (is_numeric($identifier) === true) {
            $qb->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('id', $qb->createNamedParameter((int) $identifier, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->eq('uuid', $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->eq('slug', $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->eq('uri', $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR))
                )
            );
        } else {
            $qb->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('uuid', $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->eq('slug', $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->eq('uri', $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR))
                )
            );
        }

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

        $entity = $this->findEntity(query: $qb);
        // Set source to indicate data came from blob storage.
        $entity->setSource('blob');
        return $entity;
    }//end find()

    /**
     * Find entity directly from blob storage (skip magic mapper check).
     *
     * This method is used by UnifiedObjectMapper to avoid circular calls.
     *
     * @param string|int    $identifier     Object identifier (ID, UUID, slug, or URI).
     * @param Register|null $register       Optional register to filter by.
     * @param Schema|null   $schema         Optional schema to filter by.
     * @param bool          $includeDeleted Whether to include deleted objects.
     * @param bool          $_rbac          Whether to apply RBAC checks.
     * @param bool          $_multitenancy  Whether to apply multitenancy filtering.
     *
     * @return ObjectEntity The found object.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found.
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple objects found.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $_rbac reserved for interface compatibility.
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Flags control security filtering behavior
     */
    public function findDirectBlobStorage(
        string|int $identifier,
        ?Register $register=null,
        ?Schema $schema=null,
        bool $includeDeleted=false,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): ObjectEntity {
        $qb = $this->db->getQueryBuilder();

        // Build the base query (same logic as find() but without magic mapper routing).
        $qb->select('*')
            ->from('openregister_objects');

        // Build OR conditions for matching against id, uuid, slug, or uri.
        // Build OR conditions for matching against id, uuid, slug, or uri.
        // Note: Only include id comparison if $identifier is actually numeric (PostgreSQL strict typing).
        if (is_numeric($identifier) === true) {
            $qb->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('id', $qb->createNamedParameter((int) $identifier, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->eq('uuid', $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->eq('slug', $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->eq('uri', $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR))
                )
            );
        } else {
            $qb->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('uuid', $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->eq('slug', $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->eq('uri', $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR))
                )
            );
        }

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

        // Apply multitenancy filter if enabled.
        $this->logger->debug(
            message: '[ObjectEntityMapper::findDirectBlobStorage] Multitenancy check',
            context: [
                'file'            => __FILE__,
                'line'            => __LINE__,
                'identifier'      => $identifier,
                '_multitenancy'   => $_multitenancy,
                '_rbac'           => $_rbac,
                'willApplyFilter' => $_multitenancy === true,
                'backtrace'       => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3),
            ]
                );

        if ($_multitenancy === true) {
            $this->logger->info(
                message: '[ObjectEntityMapper::findDirectBlobStorage] APPLYING organisation filter',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            $this->applyOrganisationFilter(qb: $qb, allowNullOrg: true, multiTenancyEnabled: true);
        } else {
            $this->logger->info(
                message: '[ObjectEntityMapper::findDirectBlobStorage] SKIPPING organisation filter',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
        }

        return $this->findEntity(query: $qb);
    }//end findDirectBlobStorage()

    /**
     * Find an object across all storage sources (blob storage and magic tables).
     *
     * This method searches both blob storage and all magic tables to find an object
     * by its identifier (UUID, slug, or URI) without requiring register/schema context.
     * This is useful for operations like lock/unlock where the caller may not know
     * which storage backend contains the object.
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
            message: '[ObjectEntityMapper::findAcrossAllSources] Starting search',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'identifier' => $identifier,
            ]
                );

        // First, try to find in blob storage (fast path for non-magic objects).
        try {
            $object = $this->findDirectBlobStorage(
                identifier: $identifier,
                register: null,
                schema: null,
                includeDeleted: $includeDeleted,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy
            );

            $this->logger->debug(
                message: '[ObjectEntityMapper::findAcrossAllSources] Found in blob storage',
                context: [
                    'file' => __FILE__,
                    'line' => __LINE__,
                    'uuid' => $object->getUuid(),
                ]
                    );

            // Set source to indicate data came from blob storage.
            $object->setSource('blob');

            // Get register and schema entities if available.
            $register = null;
            $schema   = null;
            try {
                $registerMapper = \OC::$server->get(RegisterMapper::class);
                $schemaMapper   = \OC::$server->get(SchemaMapper::class);
                if ($object->getRegister() !== null) {
                    $register = $registerMapper->find(id: $object->getRegister(), _multitenancy: false);
                }

                if ($object->getSchema() !== null) {
                    $schema = $schemaMapper->find(id: $object->getSchema(), _multitenancy: false);
                }
            } catch (\Exception $e) {
                // Ignore - register/schema lookup is optional.
            }

            return [
                'object'   => $object,
                'register' => $register,
                'schema'   => $schema,
            ];
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // Not found in blob storage, continue to search magic tables.
            $this->logger->debug(
                message: '[ObjectEntityMapper::findAcrossAllSources] Not in blob storage, searching magic tables',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
        }//end try

        // Search magic tables via MagicMapper.
        try {
            $magicMapper = \OC::$server->get(MagicMapper::class);
            $result      = $magicMapper->findAcrossAllMagicTables(
                identifier: $identifier,
                includeDeleted: $includeDeleted,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy
            );

            $this->logger->debug(
                message: '[ObjectEntityMapper::findAcrossAllSources] Found in magic table',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'uuid'       => $result['object']->getUuid(),
                    'registerId' => $result['register']?->getId(),
                    'schemaId'   => $result['schema']?->getId(),
                ]
                    );

            // Set source to indicate data came from magic tables (ORM).
            $result['object']->setSource('orm');

            return $result;
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // Not found in any magic table either.
            $this->logger->debug(
                message: '[ObjectEntityMapper::findAcrossAllSources] Not found in any source',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            throw $e;
        }//end try
    }//end findAcrossAllSources()

    /**
     * Find multiple objects by their IDs or UUIDs.
     *
     * @param array $ids Array of IDs or UUIDs.
     *
     * @return ObjectEntity[]
     *
     * @psalm-return list<ObjectEntity>
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
                continue;
            }

            $uuids[] = $id;
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

        // First, search blob storage.
        $blobResults = $this->findEntities(query: $qb);

        // Set source to indicate data came from blob storage.
        foreach ($blobResults as $entity) {
            $entity->setSource('blob');
        }

        // Track which UUIDs were found in blob storage.
        $foundUuids = array_map(
            fn($obj) => $obj->getUuid(),
            $blobResults
        );

        // Find UUIDs that weren't in blob storage - they might be in magic tables.
        $missingUuids = array_filter(
            $uuids,
            fn($uuid) => in_array($uuid, $foundUuids, true) === false
        );

        // If we have missing UUIDs, search magic tables.
        if (empty($missingUuids) === false) {
            try {
                $magicMapper  = \OC::$server->get(MagicMapper::class);
                $magicResults = $magicMapper->findMultipleAcrossAllMagicTables(
                    uuids: array_values($missingUuids),
                    includeDeleted: false
                );

                // Set source to indicate data came from magic tables (ORM).
                foreach ($magicResults as $entity) {
                    $entity->setSource('orm');
                }

                // Merge results from both sources.
                $blobResults = array_merge($blobResults, $magicResults);
            } catch (\Exception $e) {
                // Log error but continue with blob results only.
                $this->logger->warning(
                    message: '[ObjectEntityMapper] Failed to search magic tables in findMultiple',
                    context: [
                        'file'         => __FILE__,
                        'line'         => __LINE__,
                        'error'        => $e->getMessage(),
                        'missingUuids' => count($missingUuids),
                    ]
                        );
            }//end try
        }//end if

        return $blobResults;
    }//end findMultiple()

    /**
     * Find all objects for a given schema.
     *
     * @param int $schemaId Schema ID.
     *
     * @return ObjectEntity[]
     *
     * @psalm-return list<ObjectEntity>
     */
    public function findBySchema(int $schemaId): array
    {
        $qb = $this->db->getQueryBuilder();

        // Get database platform to determine casting method.
        $platform = $qb->getConnection()->getDatabasePlatform();

        $qb->select('o.*')
            ->from('openregister_objects', 'o');

        // PostgreSQL requires explicit casting for VARCHAR to BIGINT comparison.
        // MySQL/MariaDB does implicit type conversion.
        if ($platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
            $qb->leftJoin('o', 'openregister_schemas', 's', 'CAST(o.schema AS BIGINT) = s.id');
        } else {
            $qb->leftJoin('o', 'openregister_schemas', 's', 'o.schema = s.id');
        }

        $qb->where($qb->expr()->eq('o.schema', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNull('o.deleted'));

        return $this->findEntities(query: $qb);
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
        if ($this->shouldRoutToMagicMapper(register: $register, schema: $schema) === true) {
            $result = $this->tryMagicMapperFindAll(
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
            if ($result !== null) {
                return $result;
            }
        }

        $qb = $this->buildFindAllQuery(
            filters: $filters,
            includeDeleted: $includeDeleted,
            register: $register,
            schema: $schema,
            ids: $ids,
            published: $published,
            sort: $sort,
            limit: $limit,
            offset: $offset,
            uses: $uses
        );
        return $this->findEntities(query: $qb);
    }//end findAll()

    /**
     * Check if query should be routed to magic mapper
     *
     * @param Register|null $register Register to check
     * @param Schema|null   $schema   Schema to check
     *
     * @return bool True if should use magic mapper
     */
    private function shouldRoutToMagicMapper(?Register $register, ?Schema $schema): bool
    {
        return $register !== null && $schema !== null
            && $this->shouldUseMagicMapperForRegisterSchema(register: $register, schema: $schema) === true;
    }//end shouldRoutToMagicMapper()

    /**
     * Try to execute findAll via magic mapper
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
     * @return array|null Result array or null if failed
     *
     * @psalm-suppress UnusedParam Parameters are passed to UnifiedObjectMapper::findAll()
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    Include deleted toggle is intentional
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Required for flexible query interface
     */
    private function tryMagicMapperFindAll(
        ?int $limit,
        ?int $offset,
        ?array $filters,
        ?array $searchConditions,
        ?array $searchParams,
        array $sort,
        ?string $search,
        ?array $ids,
        ?string $uses,
        bool $includeDeleted,
        ?Register $register,
        ?Schema $schema,
        ?bool $published
    ): array|null {
        // No blob fallback — objects live in per-schema tables, not in the
        // empty openregister_objects blob table. Let errors propagate.
        $this->logger->debug(
            message: '[ObjectEntityMapper] Routing findAll() to UnifiedObjectMapper (MagicMapper)',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
        $unifiedObjectMapper = \OC::$server->get(UnifiedObjectMapper::class);
        return $unifiedObjectMapper->findAll(
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
    }//end tryMagicMapperFindAll()

    /**
     * Build the findAll query for blob storage
     *
     * @param array|null    $filters        The filters to apply
     * @param bool          $includeDeleted Whether to include deleted
     * @param Register|null $register       Register filter
     * @param Schema|null   $schema         Schema filter
     * @param array|null    $ids            IDs to filter
     * @param bool|null     $published      Published filter
     * @param array         $sort           Sort order
     * @param int|null      $limit          Result limit
     * @param int|null      $offset         Result offset
     * @param string|null   $uses           Filter by objects this object uses
     *
     * @return IQueryBuilder Query builder
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Include deleted toggle is intentional
     */
    private function buildFindAllQuery(
        ?array $filters,
        bool $includeDeleted,
        ?Register $register,
        ?Schema $schema,
        ?array $ids,
        ?bool $published,
        array $sort,
        ?int $limit,
        ?int $offset,
        ?string $uses=null
    ): IQueryBuilder {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('openregister_objects');

        $this->applyDeletedFilter(qb: $qb, filters: $filters, includeDeleted: $includeDeleted);
        $this->applyRegisterSchemaFilters(qb: $qb, register: $register, schema: $schema);
        $this->applySchemasFilter(qb: $qb, filters: $filters, schema: $schema);
        $this->applyIdFilters(qb: $qb, ids: $ids);
        $this->applyUsesFilter(qb: $qb, uses: $uses);
        $this->applyPublishedFilter(qb: $qb, published: $published);
        $this->applySorting(qb: $qb, sort: $sort);
        $this->applyPagination(qb: $qb, limit: $limit, offset: $offset);

        return $qb;
    }//end buildFindAllQuery()

    /**
     * Apply deleted filter to query
     *
     * @param IQueryBuilder $qb             Query builder
     * @param array|null    $filters        Filters array
     * @param bool          $includeDeleted Include deleted flag
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Include deleted toggle is intentional
     */
    private function applyDeletedFilter(IQueryBuilder $qb, ?array $filters, bool $includeDeleted): void
    {
        $hasDeletedFilter = false;
        if ($filters !== null && isset($filters['@self.deleted']) === true) {
            $deletedFilter = $filters['@self.deleted'];
            if ($deletedFilter === 'IS NOT NULL') {
                $qb->andWhere($qb->expr()->isNotNull('deleted'));
            } else if ($deletedFilter === 'IS NULL') {
                $qb->andWhere($qb->expr()->isNull('deleted'));
            }

            $hasDeletedFilter = true;
        }

        if ($hasDeletedFilter === false && $includeDeleted === false) {
            $qb->andWhere($qb->expr()->isNull('deleted'));
        }
    }//end applyDeletedFilter()

    /**
     * Apply register and schema filters to query
     *
     * @param IQueryBuilder $qb       Query builder
     * @param Register|null $register Register filter
     * @param Schema|null   $schema   Schema filter
     *
     * @return void
     */
    private function applyRegisterSchemaFilters(IQueryBuilder $qb, ?Register $register, ?Schema $schema): void
    {
        if ($register !== null) {
            $registerId = (string) $register->getId();
            $qb->andWhere($qb->expr()->eq('register', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_STR)));
        }

        if ($schema !== null) {
            $schemaId = (string) $schema->getId();
            $qb->andWhere($qb->expr()->eq('schema', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_STR)));
        }
    }//end applyRegisterSchemaFilters()

    /**
     * Apply multi-schema filter from _schemas parameter
     *
     * When _schemas array is provided and no single schema is set,
     * filter results to only include objects from the specified schemas.
     *
     * @param IQueryBuilder $qb      Query builder
     * @param array|null    $filters Filters array containing _schemas
     * @param Schema|null   $schema  Single schema filter (if set, _schemas is ignored)
     *
     * @return void
     */
    private function applySchemasFilter(IQueryBuilder $qb, ?array $filters, ?Schema $schema): void
    {
        // Only apply _schemas filter if no single schema is set.
        if ($schema !== null) {
            return;
        }

        // Check for _schemas in filters.
        $schemaIds = $filters['_schemas'] ?? null;
        if ($schemaIds === null || is_array($schemaIds) === false || empty($schemaIds) === true) {
            return;
        }

        // Convert to strings for VARCHAR column comparison.
        $schemaIdsStr = array_map('strval', $schemaIds);
        $qb->andWhere($qb->expr()->in('schema', $qb->createNamedParameter($schemaIdsStr, IQueryBuilder::PARAM_STR_ARRAY)));
    }//end applySchemasFilter()

    /**
     * Apply ID filters to query
     *
     * @param IQueryBuilder $qb  Query builder
     * @param array|null    $ids IDs to filter
     *
     * @return void
     */
    private function applyIdFilters(IQueryBuilder $qb, ?array $ids): void
    {
        if ($ids === null || empty($ids) === true) {
            return;
        }

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
    }//end applyIdFilters()

    /**
     * Apply uses filter to query (find objects that have a specific UUID in their relations)
     *
     * Searches the JSON relations column for a specific UUID.
     * Uses LIKE pattern matching for database-agnostic compatibility.
     *
     * @param IQueryBuilder $qb   Query builder
     * @param string|null   $uses UUID that must be present in relations
     *
     * @return void
     */
    private function applyUsesFilter(IQueryBuilder $qb, ?string $uses): void
    {
        if ($uses === null || empty($uses) === true) {
            return;
        }

        // Use LIKE pattern matching for database-agnostic compatibility.
        // The UUID will be quoted in the JSON, so search for "uuid" pattern.
        $pattern = '%"'.$uses.'"%';
        $qb->andWhere($qb->expr()->like('relations', $qb->createNamedParameter($pattern)));
    }//end applyUsesFilter()

    /**
     * Apply published filter to query
     *
     * @param IQueryBuilder $qb        Query builder
     * @param bool|null     $published Published filter
     *
     * @return void
     */
    private function applyPublishedFilter(IQueryBuilder $qb, ?bool $published): void
    {
        if ($published === null) {
            return;
        }

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
            return;
        }

        $qb->andWhere($qb->expr()->isNull('published'));
    }//end applyPublishedFilter()

    /**
     * Apply sorting to query
     *
     * @param IQueryBuilder $qb   Query builder
     * @param array         $sort Sort order
     *
     * @return void
     */
    private function applySorting(IQueryBuilder $qb, array $sort): void
    {
        if (empty($sort) === true) {
            $qb->addOrderBy('id', 'ASC');
            return;
        }

        foreach ($sort as $field => $direction) {
            if ($direction === 'desc') {
                $qb->addOrderBy($field, 'DESC');
            } else {
                $qb->addOrderBy($field, 'ASC');
            }
        }
    }//end applySorting()

    /**
     * Apply pagination to query
     *
     * @param IQueryBuilder $qb     Query builder
     * @param int|null      $limit  Result limit
     * @param int|null      $offset Result offset
     *
     * @return void
     */
    private function applyPagination(IQueryBuilder $qb, ?int $limit, ?int $offset): void
    {
        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }
    }//end applyPagination()

    /**
     * Find all entities directly from blob storage (skip magic mapper check).
     *
     * This method is used by UnifiedObjectMapper to avoid circular calls.
     * It contains the same blob storage logic as findAll() but without magic mapper routing.
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
     * @psalm-return list<ObjectEntity>
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)  Parameters reserved for interface compatibility.
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    Include deleted toggle is intentional
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Required for flexible query interface
     */
    public function findAllDirectBlobStorage(
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
        $qb = $this->buildFindAllQuery(
            filters: $filters,
            includeDeleted: $includeDeleted,
            register: $register,
            schema: $schema,
            ids: $ids,
            published: $published,
            sort: $sort,
            limit: $limit,
            offset: $offset
        );
        return $this->findEntities(query: $qb);
    }//end findAllDirectBlobStorage()

    /**
     * Search for objects with complex filtering.
     *
     * This method is restored from pre-refactor version for compatibility.
     * Note: This is a simplified version. For full functionality, use QueryHandler.
     *
     * @param array       $query          Query parameters.
     * @param string|null $_activeOrgUuid Active organisation UUID.
     * @param bool        $_rbac          Whether to apply RBAC checks.
     * @param bool        $_multitenancy  Whether to apply multitenancy filtering.
     * @param array|null  $ids            Array of IDs or UUIDs to filter by.
     * @param string|null $uses           Value that must be present in relations.
     *
     * @return ObjectEntity[]
     *
     * @psalm-return list<ObjectEntity>
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) Parameters reserved for interface compatibility.
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Flags control security filtering behavior
     */
    public function searchObjects(
        array $query=[],
        ?string $_activeOrgUuid=null,
        bool $_rbac=true,
        bool $_multitenancy=true,
        ?array $ids=null,
        ?string $uses=null
    ): array|int {
        // Extract common query parameters.
        $limit  = $query['_limit'] ?? 30;
        $offset = $query['_offset'] ?? 0;
        $sort   = $query['_order'] ?? [];

        // **DELETED FILTER HANDLING**: Check if @self.deleted filter is in query.
        // If yes, include deleted objects and apply the filter. If no, exclude deleted objects.
        $hasDeletedFilter = isset($query['@self.deleted']);
        $includeDeleted   = $hasDeletedFilter;

        // Build the query using the existing method.
        $qb = $this->buildFindAllQuery(
            filters: $query,
            includeDeleted: $includeDeleted,
            register: null,
            schema: null,
            ids: $ids,
            published: null,
            sort: $sort,
            limit: $limit,
            offset: $offset,
            uses: $uses
        );

        // Apply organisation filter when multitenancy is enabled.
        // This ensures users only see objects belonging to their organisation.
        if ($_multitenancy === true) {
            $this->applyOrganisationFilter(
                qb: $qb,
                columnName: 'organisation',
                allowNullOrg: false,
                tableAlias: '',
                enablePublished: true,
                multiTenancyEnabled: true
            );
        }

        return $this->findEntities(query: $qb);
    }//end searchObjects()

    /**
     * Count search results.
     *
     * This method is restored from pre-refactor version for compatibility.
     *
     * @param array       $query          Query parameters.
     * @param string|null $_activeOrgUuid Active organisation UUID.
     * @param bool        $_rbac          Whether to apply RBAC checks.
     * @param bool        $_multitenancy  Whether to apply multitenancy filtering.
     * @param array|null  $ids            Array of IDs or UUIDs to filter by.
     * @param string|null $uses           Value that must be present in relations.
     *
     * @return int Count of objects.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) Parameters reserved for interface compatibility.
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Flags control security filtering behavior
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function countSearchObjects(
        array $query=[],
        ?string $_activeOrgUuid=null,
        bool $_rbac=true,
        bool $_multitenancy=true,
        ?array $ids=null,
        ?string $uses=null
    ): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id'))
            ->from('openregister_objects');

        // **@SELF FILTER PROCESSING**: Handle @self.deleted filter from query.
        // Check if @self.deleted filter is present in query.
        if (isset($query['@self.deleted']) === true) {
            $deletedFilter = $query['@self.deleted'];
            if ($deletedFilter === 'IS NOT NULL') {
                $qb->andWhere($qb->expr()->isNotNull('deleted'));
            } else if ($deletedFilter === 'IS NULL') {
                $qb->andWhere($qb->expr()->isNull('deleted'));
            }
        }

        if (isset($query['@self.deleted']) === false) {
            // Default behavior: exclude deleted objects unless explicitly filtered.
            $qb->andWhere($qb->expr()->isNull('deleted'));
        }

        // Apply ID filters.
        if ($ids !== null && empty($ids) === false) {
            $numericIds = array_filter($ids, 'is_numeric');
            $stringIds  = array_filter($ids, fn ($id) => is_string($id) === true);

            $idConditions = [];
            if (empty($numericIds) === false) {
                $numericParam   = $qb->createNamedParameter($numericIds, IQueryBuilder::PARAM_INT_ARRAY);
                $idConditions[] = $qb->expr()->in('id', $numericParam);
            }

            if (empty($stringIds) === false) {
                $stringParam    = $qb->createNamedParameter($stringIds, IQueryBuilder::PARAM_STR_ARRAY);
                $idConditions[] = $qb->expr()->in('uuid', $stringParam);
            }

            if (empty($idConditions) === false) {
                $qb->andWhere($qb->expr()->orX(...$idConditions));
            }
        }

        // Apply uses filter (find objects that have a specific UUID in their relations).
        if ($uses !== null && empty($uses) === false) {
            $pattern = '%"'.$uses.'"%';
            $qb->andWhere($qb->expr()->like('relations', $qb->createNamedParameter($pattern)));
        }

        // Apply _schemas filter for multi-schema search.
        $schemaIds = $query['_schemas'] ?? null;
        if ($schemaIds !== null && is_array($schemaIds) === true && empty($schemaIds) === false) {
            $schemaIdsStr = array_map('strval', $schemaIds);
            $qb->andWhere(
                $qb->expr()->in('schema', $qb->createNamedParameter($schemaIdsStr, IQueryBuilder::PARAM_STR_ARRAY))
            );
        }

        // Apply organisation filter when multitenancy is enabled.
        // This ensures users only see objects belonging to their organisation.
        if ($_multitenancy === true) {
            $this->logger->debug(
                message: '[ObjectEntityMapper::countSearchObjects] Applying organisation filter for multitenancy',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            $this->applyOrganisationFilter(
                qb: $qb,
                columnName: 'organisation',
                allowNullOrg: false,
                tableAlias: '',
                enablePublished: true,
                multiTenancyEnabled: true
            );
        }

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        return (int) ($row['COUNT(id)'] ?? 0);
    }//end countSearchObjects()

    /**
     * Count all objects with optional filtering.
     *
     * @param array|null    $_filters Filter parameters.
     * @param Schema|null   $schema   Optional schema to filter by.
     * @param Register|null $register Optional register to filter by.
     *
     * @return int Count of objects.
     */
    public function countAll(?array $_filters=null, ?Schema $schema=null, ?Register $register=null): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id'))
            ->from('openregister_objects')
            ->where($qb->expr()->isNull('deleted'));

        // Note: register and schema columns are VARCHAR(255), not BIGINT - they store ID values as strings.
        if ($schema !== null) {
            $schemaId = (string) $schema->getId();
            $qb->andWhere(
                $qb->expr()->eq('schema', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_STR))
            );
        }

        if ($register !== null) {
            $registerId = (string) $register->getId();
            $qb->andWhere(
                $qb->expr()->eq('register', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_STR))
            );
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
     * @psalm-return list<ObjectEntity>
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

        return $this->findEntities(query: $qb);
    }//end findBySchemas()

    /**
     * Find objects by relation search.
     *
     * Searches for objects that contain a specific value in their relationships.
     * This is a simplified version that searches in the JSON object field.
     *
     * @param string $search             Search term to find in relationships.
     * @param bool   $partialMatch       Whether to allow partial matches (default: true).
     * @param bool   $includeMagicTables Whether to include magic tables in search.
     *
     * @return ObjectEntity[]
     *
     * @psalm-return list<ObjectEntity>
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Partial match toggle controls search behavior
     */
    public function findByRelation(string $search, bool $partialMatch=true, bool $includeMagicTables=true): array
    {
        if (empty($search) === true) {
            return [];
        }

        // Search in blob storage (openregister_objects table).
        $blobResults = $this->findByRelationInBlobStorage(search: $search, partialMatch: $partialMatch);

        // Optionally search in magic tables using the efficient _relations column.
        if ($includeMagicTables === true) {
            try {
                $magicMapper  = \OC::$server->get(MagicMapper::class);
                $magicResults = $magicMapper->findByRelationUsingRelationsColumn($search);

                // Merge results, deduplicating by UUID.
                $seenUuids = [];
                foreach ($blobResults as $entity) {
                    $seenUuids[$entity->getUuid()] = true;
                }

                foreach ($magicResults as $entity) {
                    if (isset($seenUuids[$entity->getUuid()]) === false) {
                        $blobResults[] = $entity;
                        $seenUuids[$entity->getUuid()] = true;
                    }
                }
            } catch (Exception $e) {
                $this->logger->debug(
                    message: '[ObjectEntityMapper] findByRelation failed to search magic tables',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
                );
            }//end try
        }//end if

        return $blobResults;
    }//end findByRelation()

    /**
     * Search for related objects in blob storage (openregister_objects table).
     *
     * @param string $search       Search term to find in relationships
     * @param bool   $partialMatch Whether to allow partial matches
     *
     * @return ObjectEntity[] Array of matching objects
     */
    private function findByRelationInBlobStorage(string $search, bool $partialMatch): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_objects')
            ->where($qb->expr()->isNull('deleted'));

        // Search in the object JSON field for the search term.
        // For PostgreSQL, we need to cast JSON to text for LIKE operations.
        $dbPlatform = $this->db->getDatabasePlatform();
        $isPostgres = str_contains(strtolower(get_class($dbPlatform)), 'postgresql');

        if ($isPostgres === true) {
            // PostgreSQL: cast JSON to text.
            $objectColumn = $qb->createFunction('object::text');
        } else {
            // MySQL/MariaDB: object column works directly.
            $objectColumn = 'object';
        }

        if ($partialMatch === true) {
            $qb->andWhere(
                $qb->expr()->like($objectColumn, $qb->createNamedParameter('%'.$this->db->escapeLikeParameter($search).'%'))
            );
        }

        if ($partialMatch === false) {
            $qb->andWhere(
                $qb->expr()->like($objectColumn, $qb->createNamedParameter('%"'.$this->db->escapeLikeParameter($search).'"%'))
            );
        }

        $qb->setMaxResults(100);
        // Limit to prevent performance issues.
        return $this->findEntities(query: $qb);
    }//end findByRelationInBlobStorage()

    /**
     * Clear all blob storage objects
     *
     * Deletes all objects from the openregister_objects table (blob storage).
     * This does NOT affect magic mapper tables.
     *
     * @return array{deleted: int} The number of deleted objects
     */
    public function clearBlobObjects(): array
    {
        $qb = $this->db->getQueryBuilder();

        // Count objects before deletion.
        $countQb = $this->db->getQueryBuilder();
        $countQb->select($countQb->func()->count('*', 'total'))
            ->from('openregister_objects')
            ->where($countQb->expr()->isNull('deleted'));

        $count = (int) $countQb->execute()->fetch()['total'];

        // Delete all non-deleted blob objects.
        $qb->delete('openregister_objects')
            ->where($qb->expr()->isNull('deleted'));

        $qb->execute();

        return ['deleted' => $count];
    }//end clearBlobObjects()
}//end class
