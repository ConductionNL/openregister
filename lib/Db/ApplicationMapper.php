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
use OCA\OpenRegister\Service\OrganisationService;
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
 * Class ApplicationMapper
 *
 * Mapper for Application entities with multi-tenancy and RBAC support.
 *
 * @package OCA\OpenRegister\Db
 *
 * @method Application insert(Entity $entity)
 * @method Application update(Entity $entity)
 * @method Application insertOrUpdate(Entity $entity)
 * @method Application delete(Entity $entity)
 * @method Application find(int|string $id)
 * @method Application findEntity(IQueryBuilder $query)
 * @method Application[] findAll(int|null $limit = null, int|null $offset = null)
 * @method list<Application> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<Application>
 */
class ApplicationMapper extends QBMapper
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
     * Event dispatcher for dispatching application events
     *
     * @var IEventDispatcher
     */
    private IEventDispatcher $eventDispatcher;


    /**
     * ApplicationMapper constructor.
     *
     * @param IDBConnection       $db                  Database connection instance
     * @param OrganisationService $organisationService Organisation service for multi-tenancy
     * @param IUserSession        $userSession         User session
     * @param IGroupManager       $groupManager        Group manager for RBAC
     * @param IEventDispatcher    $eventDispatcher     Event dispatcher
     */
    public function __construct(
        IDBConnection $db,
        OrganisationService $organisationService,
        IUserSession $userSession,
        IGroupManager $groupManager,
        IEventDispatcher $eventDispatcher
    ) {
        parent::__construct($db, 'openregister_applications', Application::class);
        $this->organisationService = $organisationService;
        $this->userSession         = $userSession;
        $this->groupManager        = $groupManager;
        $this->eventDispatcher     = $eventDispatcher;

    }//end __construct()


    /**
     * Find an application by its ID
     *
     * @param int $id Application ID
     *
     * @return Application The application entity
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     * @throws \Exception If user doesn't have read permission
     */
    public function find(int $id): Application
    {
        // Verify RBAC permission to read.
        $this->verifyRbacPermission('read', 'application');

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        // Apply organisation filter (all users including admins must have active org).
        $this->applyOrganisationFilter($qb);

        return $this->findEntity($qb);

    }//end find()


    /**
     * Find an application by its UUID
     *
     * @param string $uuid Application UUID
     *
     * @return Application The application entity
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     * @throws \Exception If user doesn't have read permission
     */
    public function findByUuid(string $uuid): Application
    {
        // Verify RBAC permission to read.
        $this->verifyRbacPermission('read', 'application');

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));

        // Apply organisation filter.
        $this->applyOrganisationFilter($qb);

        return $this->findEntity($qb);

    }//end findByUuid()


    /**
     * Find applications by organisation
     *
     * @param string $organisationUuid Organisation UUID
     * @param int    $limit            Maximum number of results
     * @param int    $offset           Offset for pagination
     *
     * @return Application[] Array of application entities
     * @throws \Exception If user doesn't have read permission
     */
    public function findByOrganisation(string $organisationUuid, int $limit=50, int $offset=0): array
    {
        // Verify RBAC permission to read.
        $this->verifyRbacPermission('read', 'application');

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('organisation', $qb->createNamedParameter($organisationUuid, IQueryBuilder::PARAM_STR)))
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('created', 'DESC');

        return $this->findEntities($qb);

    }//end findByOrganisation()


    /**
     * Find all applications
     *
     * @param int|null $limit            Maximum number of results
     * @param int|null $offset           Offset for pagination
     * @param array    $filters          Filter conditions
     * @param array    $searchConditions Search conditions for WHERE clause
     * @param array    $searchParams     Parameters for search conditions
     *
     * @return Application[] Array of application entities
     * @throws \Exception If user doesn't have read permission
     */
    public function findAll(?int $limit=null, ?int $offset=null, array $filters=[], array $searchConditions=[], array $searchParams=[]): array
    {
        // Verify RBAC permission to read.
        $this->verifyRbacPermission('read', 'application');

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('created', 'DESC');

        // Apply filters.
        foreach ($filters as $key => $value) {
            $qb->andWhere($qb->expr()->eq($key, $qb->createNamedParameter($value)));
        }

        // Apply search conditions.
        if (empty($searchConditions) === false) {
            $qb->andWhere($qb->expr()->orX(...$searchConditions));
            foreach ($searchParams as $key => $value) {
                $qb->setParameter($key, $value);
            }
        }

        // Apply organisation filter (all users including admins must have active org).
        $this->applyOrganisationFilter($qb);

        return $this->findEntities($qb);

    }//end findAll()


    /**
     * Insert a new application
     *
     * @param Application $entity Application entity to insert
     *
     * @return Application The inserted application with updated ID
     * @throws \Exception If user doesn't have create permission
     *
     */
    public function insert(Entity $entity): Entity
    {
        // Verify RBAC permission to create.
        $this->verifyRbacPermission('create', 'application');

        if ($entity instanceof Application) {
            // Generate UUID if not set.
            if (empty($entity->getUuid()) === true) {
                $entity->setUuid(\Symfony\Component\Uid\Uuid::v4()->toRfc4122());
            }

            $entity->setCreated(new DateTime());
            $entity->setUpdated(new DateTime());
        }

        // Auto-set organisation from active session.
        $this->setOrganisationOnCreate($entity);

        $entity = parent::insert($entity);

        // Dispatch creation event.
        $this->eventDispatcher->dispatchTyped(new ApplicationCreatedEvent($entity));

        return $entity;

    }//end insert()


    /**
     * Update an existing application
     *
     * @param Entity $entity Application entity to update
     *
     * @return Application The updated application
     * @throws \Exception If user doesn't have update permission or access to this organisation
     *
     */
    public function update(Entity $entity): Entity
    {
        // Verify RBAC permission to update.
        $this->verifyRbacPermission('update', 'application');

        // Verify user has access to this organisation.
        $this->verifyOrganisationAccess($entity);

        // Get old state before update.
        $oldEntity = $this->find(id: $entity->getId());

        if ($entity instanceof Application) {
            $entity->setUpdated(new DateTime());
        }

        $entity = parent::update($entity);

        // Dispatch update event.
        $this->eventDispatcher->dispatchTyped(new ApplicationUpdatedEvent($entity, $oldEntity));

        return $entity;

    }//end update()


    /**
     * Delete an application
     *
     * @param Entity $entity Application entity to delete
     *
     * @return Application The deleted application
     * @throws \Exception If user doesn't have delete permission or access to this organisation
     *
     */
    public function delete(Entity $entity): Entity
    {
        // Verify RBAC permission to delete.
        $this->verifyRbacPermission('delete', 'application');

        // Verify user has access to this organisation.
        $this->verifyOrganisationAccess($entity);

        $entity = parent::delete($entity);

        // Dispatch deletion event.
        $this->eventDispatcher->dispatchTyped(new ApplicationDeletedEvent($entity));

        return $entity;

    }//end delete()


    /**
     * Create an application from an array
     *
     * @param array $data The application data
     *
     * @return Application The created application
     */
    public function createFromArray(array $data): Application
    {
        $application = new Application();
        $application->hydrate($data);

        return $this->insert($application);

    }//end createFromArray()


    /**
     * Update an application from an array
     *
     * @param int   $id   The application ID
     * @param array $data The application data
     *
     * @throws DoesNotExistException If the application is not found
     * @return Application The updated application
     */
    public function updateFromArray(int $id, array $data): Application
    {
        $application = $this->find($id);
        $application->hydrate($data);

        return $this->update($application);

    }//end updateFromArray()


    /**
     * Count applications by organisation
     *
     * @param string $organisationUuid Organisation UUID
     *
     * @return int Number of applications
     * @throws \Exception If user doesn't have read permission
     */
    public function countByOrganisation(string $organisationUuid): int
    {
        // Verify RBAC permission to read.
        $this->verifyRbacPermission('read', 'application');

        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->tableName)
            ->where($qb->expr()->eq('organisation', $qb->createNamedParameter($organisationUuid, IQueryBuilder::PARAM_STR)));

        $result = $qb->executeQuery();
        $count  = $result->fetchOne();
        $result->closeCursor();

        return (int) $count;

    }//end countByOrganisation()


    /**
     * Count total applications
     *
     * @return int Total number of applications
     * @throws \Exception If user doesn't have read permission
     */
    public function countAll(): int
    {
        // Verify RBAC permission to read.
        $this->verifyRbacPermission('read', 'application');

        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->tableName);

        // Apply organisation filter (all users including admins must have active org).
        $this->applyOrganisationFilter($qb);

        $result = $qb->executeQuery();
        $count  = $result->fetchOne();
        $result->closeCursor();

        return (int) $count;

    }//end countAll()


}//end class
