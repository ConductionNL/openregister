<?php

/**
 * OpenRegister Application Mapper
 *
 * This file contains the ApplicationMapper class for database operations on applications.
 *
 * @category Mapper
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Db;

use DateTime;
use OCA\OpenRegister\Event\ApplicationCreatedEvent;
use OCA\OpenRegister\Event\ApplicationDeletedEvent;
use OCA\OpenRegister\Event\ApplicationUpdatedEvent;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * ApplicationMapper handles database operations for Application entities
 *
 * Mapper for Application entities with multi-tenancy and RBAC support.
 * RBAC support. Provides CRUD operations with automatic organisation
 * filtering and permission checks.
 *
 * @category Mapper
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @method Application insert(Entity $entity)
 * @method Application update(Entity $entity)
 * @method Application insertOrUpdate(Entity $entity)
 * @method Application delete(Entity $entity)
 * @method Application find(int|string $id)
 * @method Application findEntity(IQueryBuilder $query)
 * @method Application[] findAll(int|null $limit=null, int|null $offset=null)
 * @method list<Application> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<Application>
 *
 * @psalm-suppress PossiblyUnusedMethod
 */
class ApplicationMapper extends QBMapper
{
    use MultiTenancyTrait;

    /**
     * Organisation mapper for multi-tenancy
     *
     * Used to get active organisation and apply organisation filters.
     *
     * @var OrganisationMapper Organisation mapper instance
     */
    protected OrganisationMapper $organisationMapper;

    /**
     * User session for current user
     *
     * Used to get current user context for RBAC and multi-tenancy.
     *
     * @var IUserSession User session instance
     */
    private readonly IUserSession $userSession;

    /**
     * Group manager for RBAC
     *
     * Used to check user group memberships for permission verification.
     *
     * @var IGroupManager Group manager instance
     */
    private readonly IGroupManager $groupManager;

    /**
     * Event dispatcher for dispatching application events
     *
     * Dispatches events when applications are created, updated, or deleted.
     *
     * @var IEventDispatcher Event dispatcher instance
     */
    private readonly IEventDispatcher $eventDispatcher;

    /**
     * Constructor
     *
     * Initializes mapper with database connection and required dependencies
     * for multi-tenancy, RBAC, and event dispatching.
     *
     * @param IDBConnection      $db                 Database connection for queries
     * @param OrganisationMapper $organisationMapper Organisation mapper for multi-tenancy
     * @param IUserSession       $userSession        User session for current user context
     * @param IGroupManager      $groupManager       Group manager for RBAC checks
     * @param IEventDispatcher   $eventDispatcher    Event dispatcher for application events
     *
     * @return void
     */
    public function __construct(
        IDBConnection $db,
        // REMOVED: Services should not be in mappers.
        OrganisationMapper $organisationMapper,
        IUserSession $userSession,
        IGroupManager $groupManager,
        IEventDispatcher $eventDispatcher
    ) {
        // Initialize parent mapper with table name and entity class.
        parent::__construct($db, 'openregister_applications', Application::class);

        // Store dependencies for use in mapper methods.
        // REMOVED: Services should not be in mappers.
        $this->organisationMapper = $organisationMapper;
        $this->userSession        = $userSession;
        $this->groupManager       = $groupManager;
        $this->eventDispatcher    = $eventDispatcher;
    }//end __construct()

    /**
     * Find an application by its ID
     *
     * Retrieves a single application entity by database ID.
     * Applies RBAC permission checks and organisation filtering.
     *
     * @param int $id Application database ID
     *
     * @return Application The application entity
     *
     * @throws DoesNotExistException If application not found with the given ID
     * @throws MultipleObjectsReturnedException If multiple applications found (should not happen)
     * @throws \Exception If user doesn't have read permission
     *
     * @psalm-return Application
     */
    public function find(int $id): Application
    {
        // Verify RBAC permission to read applications.
        $this->verifyRbacPermission(action: 'read', entityType: 'application');

        // Build query to find application by ID.
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where(
                $qb->expr()->eq(
                    'id',
                    $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)
                )
            );

        // Apply organisation filter (all users including admins must have active org).
        $this->applyOrganisationFilter($qb);

        // Execute query and return entity.
        return $this->findEntity($qb);
    }//end find()

    /**
     * Find an application by its UUID
     *
     * Retrieves a single application entity by UUID.
     * Applies RBAC permission checks and organisation filtering.
     *
     * @param string $uuid Application UUID (RFC 4122 format)
     *
     * @return Application The application entity
     *
     * @throws DoesNotExistException If application not found with the given UUID
     * @throws MultipleObjectsReturnedException If multiple applications found (should not happen)
     * @throws \Exception If user doesn't have read permission
     *
     * @psalm-return Application
     */
    public function findByUuid(string $uuid): Application
    {
        // Verify RBAC permission to read applications.
        $this->verifyRbacPermission(action: 'read', entityType: 'application');

        // Build query to find application by UUID.
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where(
                $qb->expr()->eq(
                    'uuid',
                    $qb->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)
                )
            );

        // Apply organisation filter to ensure user can only access their organisation's applications.
        $this->applyOrganisationFilter($qb);

        // Execute query and return entity.
        return $this->findEntity($qb);
    }//end findByUuid()

    /**
     * Find applications by organisation
     *
     * Retrieves all applications belonging to a specific organisation.
     * Results are ordered by creation date (newest first) with pagination support.
     *
     * @param string $organisationUuid Organisation UUID to filter by
     * @param int    $limit            Maximum number of results to return (default: 50)
     * @param int    $offset           Number of results to skip for pagination (default: 0)
     *
     * @return Application[]
     *
     * @throws \Exception If user doesn't have read permission
     *
     * @psalm-return list<\OCA\OpenRegister\Db\Application>
     */
    public function findByOrganisation(string $organisationUuid, int $limit=50, int $offset=0): array
    {
        // Verify RBAC permission to read applications.
        $this->verifyRbacPermission(action: 'read', entityType: 'application');

        // Build query to find applications by organisation.
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where(
                $qb->expr()->eq(
                    'organisation',
                    $qb->createNamedParameter($organisationUuid, IQueryBuilder::PARAM_STR)
                )
            )
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('created', 'DESC');

        // Execute query and return entities.
        return $this->findEntities($qb);
    }//end findByOrganisation()

    /**
     * Find all applications
     *
     * Retrieves all applications with optional pagination, filtering, and search.
     * Results are ordered by creation date (newest first).
     * Automatically applies organisation filtering for multi-tenancy.
     *
     * @param int|null             $limit            Maximum number of results to return (null for all)
     * @param int|null             $offset           Number of results to skip for pagination (null for no offset)
     * @param array<string, mixed> $filters          Filter conditions as key-value pairs (default: empty array)
     * @param array                $searchConditions Search conditions for WHERE clause (default: empty array)
     * @param array<string, mixed> $searchParams     Parameters for search conditions (default: empty array)
     *
     * @return Application[]
     *
     * @throws \Exception If user doesn't have read permission
     *
     * @psalm-return list<Application>
     */
    public function findAll(
        ?int $limit=null,
        ?int $offset=null,
        array $filters=[],
        array $searchConditions=[],
        array $searchParams=[]
    ): array {
        // Verify RBAC permission to read applications.
        $this->verifyRbacPermission(action: 'read', entityType: 'application');

        // Build base query.
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->setMaxResults($limit)
            ->setFirstResult($offset ?? 0)
            ->orderBy('created', 'DESC');

        // Apply simple equality filters.
        foreach ($filters as $key => $value) {
            $qb->andWhere(
                $qb->expr()->eq(
                    $key,
                    $qb->createNamedParameter($value)
                )
            );
        }

        // Apply complex search conditions (OR logic).
        if (empty($searchConditions) === false) {
            $qb->andWhere($qb->expr()->orX(...$searchConditions));

            // Set parameters for search conditions.
            foreach ($searchParams as $key => $value) {
                $qb->setParameter($key, $value);
            }
        }

        // Apply organisation filter (all users including admins must have active org).
        $this->applyOrganisationFilter($qb);

        // Execute query and return entities.
        return $this->findEntities($qb);
    }//end findAll()

    /**
     * Insert a new application
     *
     * Creates a new application entity in the database.
     * Automatically generates UUID if not set, sets timestamps, and applies organisation.
     * Dispatches ApplicationCreatedEvent after successful insertion.
     *
     * @param Entity $entity Application entity to insert
     *
     * @return Application The inserted application with updated ID and timestamps
     *
     * @throws \Exception If user doesn't have create permission
     *
     * @psalm-return Application
     */
    public function insert(Entity $entity): Entity
    {
        // Verify RBAC permission to create applications.
        $this->verifyRbacPermission(action: 'create', entityType: 'application');

        // Set up application-specific fields if entity is Application instance.
        if ($entity instanceof Application) {
            // Generate UUID if not already set.
            if (empty($entity->getUuid()) === true) {
                $entity->setUuid(\Symfony\Component\Uid\Uuid::v4()->toRfc4122());
            }

            // Set creation and update timestamps.
            $entity->setCreated(new DateTime());
            $entity->setUpdated(new DateTime());
        }

        // Auto-set organisation from active session (multi-tenancy).
        $this->setOrganisationOnCreate($entity);

        // Insert entity into database using parent method.
        $entity = parent::insert($entity);

        // Dispatch creation event for other components to react to.
        $this->eventDispatcher->dispatchTyped(new ApplicationCreatedEvent($entity));

        return $entity;
    }//end insert()

    /**
     * Update an existing application
     *
     * Updates an existing application entity in the database.
     * Verifies user has access to the application's organisation.
     * Updates timestamp and dispatches ApplicationUpdatedEvent with old and new state.
     *
     * @param Entity $entity Application entity to update
     *
     * @return Application The updated application entity
     *
     * @throws \Exception If user doesn't have update permission or access to this organisation
     *
     * @psalm-return Application
     */
    public function update(Entity $entity): Entity
    {
        // Verify RBAC permission to update applications.
        $this->verifyRbacPermission(action: 'update', entityType: 'application');

        // Verify user has access to this application's organisation.
        $this->verifyOrganisationAccess($entity);

        // Get old state before update for event payload.
        $oldEntity = $this->find(id: $entity->getId());

        // Update timestamp if entity is Application instance.
        if ($entity instanceof Application) {
            $entity->setUpdated(new DateTime());
        }

        // Update entity in database using parent method.
        $entity = parent::update($entity);

        // Dispatch update event with old and new state for other components.
        $this->eventDispatcher->dispatchTyped(
            new ApplicationUpdatedEvent($entity, $oldEntity)
        );

        return $entity;
    }//end update()

    /**
     * Delete an application
     *
     * Deletes an application entity from the database.
     * Verifies user has access to the application's organisation.
     * Dispatches ApplicationDeletedEvent after successful deletion.
     *
     * @param Entity $entity Application entity to delete
     *
     * @return Application The deleted application entity
     *
     * @throws \Exception If user doesn't have delete permission or access to this organisation
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     * @psalm-return   Application
     */
    public function delete(Entity $entity): Entity
    {
        // Verify RBAC permission to delete applications.
        $this->verifyRbacPermission(action: 'delete', entityType: 'application');

        // Verify user has access to this application's organisation.
        $this->verifyOrganisationAccess($entity);

        // Delete entity from database using parent method.
        $entity = parent::delete($entity);

        // Dispatch deletion event for other components to react to.
        $this->eventDispatcher->dispatchTyped(new ApplicationDeletedEvent($entity));

        return $entity;
    }//end delete()

    /**
     * Create an application from an array
     *
     * Creates a new application entity from a data array.
     * Hydrates the entity with provided data and inserts it into the database.
     *
     * @param array<string, mixed> $data The application data as key-value pairs
     *
     * @return Application The created application entity with assigned ID
     *
     * @psalm-return Application
     */
    public function createFromArray(array $data): Application
    {
        // Create new application entity.
        $application = new Application();

        // Hydrate entity with provided data.
        $application->hydrate($data);

        // Insert entity into database (handles UUID, timestamps, organisation).
        return $this->insert($application);
    }//end createFromArray()

    /**
     * Update an application from an array
     *
     * Updates an existing application entity from a data array.
     * First retrieves the application by ID, then hydrates it with new data and updates.
     *
     * @param int                  $id   The application database ID
     * @param array<string, mixed> $data The application data as key-value pairs to update
     *
     * @return Application The updated application entity
     *
     * @throws DoesNotExistException If the application is not found with the given ID
     *
     * @psalm-return Application
     */
    public function updateFromArray(int $id, array $data): Application
    {
        // Find existing application (throws exception if not found).
        $application = $this->find($id);

        // Hydrate entity with new data.
        $application->hydrate($data);

        // Update entity in database (handles timestamp, organisation verification).
        return $this->update($application);
    }//end updateFromArray()

    /**
     * Count applications by organisation
     *
     * Returns the total number of applications belonging to a specific organisation.
     * Useful for statistics and pagination calculations.
     *
     * @param string $organisationUuid Organisation UUID to count applications for
     *
     * @return int Number of applications in the organisation
     *
     * @throws \Exception If user doesn't have read permission
     *
     * @psalm-return int
     */
    public function countByOrganisation(string $organisationUuid): int
    {
        // Verify RBAC permission to read applications.
        $this->verifyRbacPermission(action: 'read', entityType: 'application');

        // Build query to count applications by organisation.
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->tableName)
            ->where(
                $qb->expr()->eq(
                    'organisation',
                    $qb->createNamedParameter($organisationUuid, IQueryBuilder::PARAM_STR)
                )
            );

        // Execute query and get count.
        $result = $qb->executeQuery();
        $count  = $result->fetchOne();
        $result->closeCursor();

        // Return count as integer.
        return (int) $count;
    }//end countByOrganisation()

    /**
     * Count total applications
     *
     * Returns the total number of applications accessible to the current user.
     * Automatically applies organisation filtering for multi-tenancy.
     * Useful for pagination calculations and statistics.
     *
     * @return int Total number of applications
     *
     * @throws \Exception If user doesn't have read permission
     *
     * @psalm-return int
     */
    public function countAll(): int
    {
        // Verify RBAC permission to read applications.
        $this->verifyRbacPermission(action: 'read', entityType: 'application');

        // Build query to count all applications.
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->tableName);

        // Apply organisation filter (all users including admins must have active org).
        $this->applyOrganisationFilter($qb);

        // Execute query and get count.
        $result = $qb->executeQuery();
        $count  = $result->fetchOne();
        $result->closeCursor();

        // Return count as integer.
        return (int) $count;
    }//end countAll()
}//end class
