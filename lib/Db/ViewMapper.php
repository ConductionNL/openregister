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

use OCA\OpenRegister\Event\ViewCreatedEvent;
use OCA\OpenRegister\Event\ViewDeletedEvent;
use OCA\OpenRegister\Event\ViewUpdatedEvent;
use OCA\OpenRegister\Service\ConfigurationCacheService;
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
 * The ViewMapper class
 *
 * Mapper for View entities with multi-tenancy and RBAC support.
 *
 * @package OCA\OpenRegister\Db
 *
 * @method View insert(Entity $entity)
 * @method View update(Entity $entity)
 * @method View insertOrUpdate(Entity $entity)
 * @method View delete(Entity $entity)
 * @method View find(int|string $id)
 * @method View findEntity(IQueryBuilder $query)
 * @method View[] findAll(int|null $limit = null, int|null $offset = null)
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
     * @var OrganisationService
     */
    private OrganisationService $organisationService;

    /**
     * User session for current user
     *
     * @var IUserSession
     */
    private IUserSession $userSession;

    /**
     * Group manager for RBAC
     *
     * @var IGroupManager
     */
    private IGroupManager $groupManager;

    /**
     * Configuration cache service
     *
     * @var ConfigurationCacheService
     */
    private ConfigurationCacheService $configurationCacheService;

    /**
     * Event dispatcher for dispatching view events
     *
     * @var IEventDispatcher
     */
    private IEventDispatcher $eventDispatcher;



    /**
     * Find a view by its ID
     *
     * @param int|string $id The ID of the view to find
     *
     * @return View The found view
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If view not found
     * @throws \Exception If user doesn't have read permission
     */
    public function find($id): View
    {
        // Verify RBAC permission to read.
        $this->verifyRbacPermission('read', 'view');

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->eq('uuid', $qb->createNamedParameter($id, IQueryBuilder::PARAM_STR))
                )
            );

        // Apply organisation filter (all users including admins must have active org).
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
     * @return array Array of View entities
     * @throws \Exception If user doesn't have read permission
     */
    public function findAll(?string $owner=null): array
    {
        // Verify RBAC permission to read.
        $this->verifyRbacPermission('read', 'view');

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
        $this->verifyRbacPermission('create', 'view');

        // Generate UUID if not present.
        if (empty($entity->getUuid()) === true) {
            $entity->setUuid((string) Uuid::v4());
        }

        // Set timestamps.
        $entity->setCreated(new \DateTime());
        $entity->setUpdated(new \DateTime());

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
        $this->verifyRbacPermission('update', 'view');

        // Verify user has access to this organisation.
        $this->verifyOrganisationAccess($entity);

        // Get old state before update.
        $oldEntity = $this->find($entity->getId());

        // Update timestamp.
        $entity->setUpdated(new \DateTime());

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
     */
    public function delete(Entity $entity): View
    {
        // Verify RBAC permission to delete.
        $this->verifyRbacPermission('delete', 'view');

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
