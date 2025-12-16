<?php
/**
 * OpenRegister View Mapper
 *
 * This file contains the class for handling view mapper related operations
 * in the OpenRegister application.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use DateTime;
use OCA\OpenRegister\Event\ViewCreatedEvent;
use OCA\OpenRegister\Event\ViewDeletedEvent;
use OCA\OpenRegister\Event\ViewUpdatedEvent;
use OCA\OpenRegister\Service\Configuration\CacheHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use Symfony\Component\Uid\Uuid;

/**
 * ViewMapper handles database operations for View entities
 *
 * Mapper for View entities with multi-tenancy and RBAC support.
 * Extends QBMapper to provide standard CRUD operations with access control.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @method View insert(Entity $entity)
 * @method View update(Entity $entity)
 * @method View insertOrUpdate(Entity $entity)
 * @method View delete(Entity $entity)
 * @method View find(int|string $id)
 * @method View findEntity(IQueryBuilder $query)
 * @method View[] findAll(int|null $limit=null, int|null $offset=null)
 * @method list<View> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<View>
 */
class ViewMapper extends QBMapper
{
    use MultiTenancyTrait;

    /**
     * Organisation service for multi-tenancy
     *
     * Used to filter views by organisation for multi-tenant support.
     *
     * @var OrganisationService Organisation service instance
     */
    private readonly OrganisationMapper $organisationMapper;

    /**
     * User session for current user
     *
     * Used to determine current user context for RBAC filtering.
     *
     * @var IUserSession User session instance
     */
    private readonly IUserSession $userSession;

    /**
     * Group manager for RBAC
     *
     * Used to check user group memberships for access control.
     *
     * @var IGroupManager Group manager instance
     */
    private readonly IGroupManager $groupManager;

    /**
     * Configuration cache service
     *
     * Used to invalidate configuration cache when views change.
     *
     * @var CacheHandler Configuration cache service instance
     */
    private readonly CacheHandler $configurationCacheService;

    /**
     * Event dispatcher for dispatching view events
     *
     * Used to dispatch ViewCreatedEvent, ViewUpdatedEvent, and ViewDeletedEvent.
     *
     * @var IEventDispatcher Event dispatcher instance
     */
    private readonly IEventDispatcher $eventDispatcher;


    /**
     * Constructor
     *
     * Initializes mapper with database connection and multi-tenancy/RBAC dependencies.
     * Calls parent constructor to set up base mapper functionality.
     *
     * @param IDBConnection      $db                        Database connection
     * @param OrganisationMapper $organisationMapper        Organisation service for multi-tenancy
     * @param IUserSession       $userSession               User session for RBAC
     * @param IGroupManager      $groupManager              Group manager for RBAC
     * @param CacheHandler       $configurationCacheService Configuration cache service for cache invalidation
     * @param IEventDispatcher   $eventDispatcher           Event dispatcher for view lifecycle events
     *
     * @return void
     */
    public function __construct(
        IDBConnection $db,
        // REMOVED: Services should not be in mappers
        // OrganisationMapper $organisationMapper,
        IUserSession $userSession,
        IGroupManager $groupManager,
        // REMOVED: Handlers should not be in mappers
        // CacheHandler $configurationCacheService,
        IEventDispatcher $eventDispatcher
    ) {
        // Call parent constructor to initialize base mapper with table name and entity class.
        parent::__construct($db, 'openregister_views', View::class);

        // Store dependencies for use in mapper methods.
        // REMOVED: Services should not be in mappers
        // $this->organisationMapper = $organisationService;
        $this->userSession  = $userSession;
        $this->groupManager = $groupManager;
        // $this->configurationCacheService = $configurationCacheService; // REMOVED
        $this->eventDispatcher = $eventDispatcher;

    }//end __construct()


    /**
     * Find a view by its ID
     *
     * Retrieves view by ID (supports both integer ID and UUID) with RBAC and
     * organisation filtering. Verifies user has read permission before querying.
     *
     * @param int|string $id The ID (integer) or UUID (string) of the view to find
     *
     * @return View The found view entity
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If view not found or not accessible
     * @throws \Exception If user doesn't have read permission for views
     */
    public function find($id): View
    {
        // Step 1: Verify RBAC permission to read views.
        // Throws exception if user doesn't have required permissions.
        $this->verifyRbacPermission(action: 'read', entityType: 'view');

        // Step 2: Get query builder instance.
        $qb = $this->db->getQueryBuilder();

        // Step 3: Build SELECT query with ID or UUID filter.
        // Supports both integer IDs and UUID strings for flexibility.
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->eq('uuid', $qb->createNamedParameter($id, IQueryBuilder::PARAM_STR))
                )
            );

        // Step 4: Apply organisation filter for multi-tenancy.
        // All users including admins must have active organisation.
        $this->applyOrganisationFilter($qb);

        $entity = $this->findEntity(query: $qb);

        // Enrich with configuration management info.
        $this->enrichWithConfigurationInfo($entity);

        return $entity;

    }//end find()


    /**
     * Find all views for a specific owner
     *
     * @param string $owner The owner user ID
     *
     * @return View[] Array of View entities
     *
     * @throws \Exception If user doesn't have read permission
     *
     * @psalm-return list<\OCA\OpenRegister\Db\View>
     */
    public function findAll(?string $owner=null): array
    {
        // Verify RBAC permission to read.
        $this->verifyRbacPermission(action: 'read', entityType: 'view');

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName());

        if ($owner !== null) {
            $qb->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('owner', $qb->createNamedParameter($owner, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->eq('is_public', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
                )
            );
        }

        $qb->orderBy('created', 'DESC');

        // Apply organisation filter (all users including admins must have active org).
        $this->applyOrganisationFilter($qb);

        $entities = $this->findEntities(query: $qb);

        // Enrich all entities with configuration management info.
        foreach ($entities as $entity) {
            $this->enrichWithConfigurationInfo($entity);
        }

        return $entities;

    }//end findAll()


    /**
     * Create a new view from an Entity
     *
     * @param Entity $entity The view entity to create
     *
     * @return View The created view
     * @throws \Exception If user doesn't have create permission
     */
    public function insert(Entity $entity): View
    {
        // Verify RBAC permission to create.
        $this->verifyRbacPermission(action: 'create', entityType: 'view');

        // Generate UUID if not present.
        if (empty($entity->getUuid()) === true) {
            $entity->setUuid((string) Uuid::v4());
        }

        // Set timestamps.
        $entity->setCreated(new DateTime());
        $entity->setUpdated(new DateTime());

        // Auto-set organisation from active session.
        $this->setOrganisationOnCreate($entity);

        $entity = parent::insert(entity: $entity);

        // Dispatch creation event.
        $this->eventDispatcher->dispatchTyped(new ViewCreatedEvent($entity));

        return $entity;

    }//end insert()


    /**
     * Update an existing view
     *
     * @param Entity $entity The view entity to update
     *
     * @return View The updated view
     * @throws \Exception If user doesn't have update permission or access to this organisation
     */
    public function update(Entity $entity): View
    {
        // Verify RBAC permission to update.
        $this->verifyRbacPermission(action: 'update', entityType: 'view');

        // Verify user has access to this organisation.
        $this->verifyOrganisationAccess($entity);

        // Get old state before update.
        $oldEntity = $this->find($entity->getId());

        // Update timestamp.
        $entity->setUpdated(new DateTime());

        $entity = parent::update(entity: $entity);

        // Dispatch update event.
        $this->eventDispatcher->dispatchTyped(new ViewUpdatedEvent($entity, $oldEntity));

        return $entity;

    }//end update()


    /**
     * Delete a view
     *
     * @param Entity $entity The view entity to delete
     *
     * @return View The deleted view
     * @throws \Exception If user doesn't have delete permission or access to this organisation
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function delete(Entity $entity): View
    {
        // Verify RBAC permission to delete.
        $this->verifyRbacPermission(action: 'delete', entityType: 'view');

        // Verify user has access to this organisation.
        $this->verifyOrganisationAccess($entity);

        $entity = parent::delete($entity);

        // Dispatch deletion event.
        $this->eventDispatcher->dispatchTyped(new ViewDeletedEvent($entity));

        return $entity;

    }//end delete()


    /**
     * Enrich a view entity with configuration management information
     *
     * This method fetches configurations for the active organisation and checks
     * if this view is managed by any configuration. If so, it sets the managedByConfiguration
     * property on the entity.
     *
     * @param View $view The view entity to enrich
     *
     * @return void
     */
    private function enrichWithConfigurationInfo(View $view): void
    {
        // Get configurations from cache for the active organisation.
        $configurations = $this->configurationCacheService->getConfigurationsForActiveOrganisation();

        // Check if this view is managed by any configuration.
        $managedBy = $view->getManagedByConfiguration($configurations);
        if ($managedBy !== null) {
            $view->setManagedByConfigurationEntity($managedBy);
        }

    }//end enrichWithConfigurationInfo()


}//end class
