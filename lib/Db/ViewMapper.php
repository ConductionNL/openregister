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

use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
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
     * Constructor for ViewMapper
     *
     * @param IDBConnection       $db                  The database connection
     * @param OrganisationService $organisationService Organisation service for multi-tenancy
     * @param IUserSession        $userSession         User session
     * @param IGroupManager       $groupManager        Group manager for RBAC
     *
     * @return void
     */
    public function __construct(
        IDBConnection $db,
        OrganisationService $organisationService,
        IUserSession $userSession,
        IGroupManager $groupManager
    ) {
        parent::__construct($db, 'openregister_view');
        $this->organisationService = $organisationService;
        $this->userSession         = $userSession;
        $this->groupManager        = $groupManager;
    }//end __construct()


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
        // Verify RBAC permission to read
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

        // Apply organisation filter (admins see all, others see only their org)
        $this->applyOrganisationFilter($qb);

        return $this->findEntity(query: $qb);
    }//end find()


    /**
     * Find all views for a specific owner
     *
     * @param string $owner The owner user ID
     *
     * @return array Array of View entities
     * @throws \Exception If user doesn't have read permission
     */
    public function findAll(?string $owner = null): array
    {
        // Verify RBAC permission to read
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

        // Apply organisation filter (admins see all, others see only their org)
        $this->applyOrganisationFilter($qb);

        return $this->findEntities(query: $qb);
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
        // Verify RBAC permission to create
        $this->verifyRbacPermission('create', 'view');

        // Generate UUID if not present
        if (empty($entity->getUuid()) === true) {
            $entity->setUuid(Uuid::v4());
        }

        // Set timestamps
        $entity->setCreated(new \DateTime());
        $entity->setUpdated(new \DateTime());

        // Auto-set organisation from active session
        $this->setOrganisationOnCreate($entity);

        return parent::insert(entity: $entity);
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
        // Verify RBAC permission to update
        $this->verifyRbacPermission('update', 'view');

        // Verify user has access to this organisation
        $this->verifyOrganisationAccess($entity);

        // Update timestamp
        $entity->setUpdated(new \DateTime());

        return parent::update(entity: $entity);
    }//end update()


    /**
     * Delete a view
     *
     * @param Entity $entity The view entity to delete
     *
     * @return Entity The deleted view
     * @throws \Exception If user doesn't have delete permission or access to this organisation
     */
    public function delete(Entity $entity): Entity
    {
        // Verify RBAC permission to delete
        $this->verifyRbacPermission('delete', 'view');

        // Verify user has access to this organisation
        $this->verifyOrganisationAccess($entity);

        return parent::delete($entity);
    }//end delete()


    /**
     * Delete a view by ID
     *
     * @param int|string $id The ID of the view to delete
     *
     * @return void
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If view not found
     * @throws \Exception If user doesn't have delete permission
     */
    public function deleteById($id): void
    {
        $entity = $this->find($id);
        $this->delete($entity);
    }//end deleteById()


}//end class


