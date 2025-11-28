<?php
/**
 * OpenRegister Endpoint Mapper
 *
 * Mapper for Endpoint entities to handle database operations.
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
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use Symfony\Component\Uid\Uuid;

/**
 * EndpointMapper
 *
 * @method Endpoint insert(Entity $entity)
 * @method Endpoint update(Entity $entity)
 * @method Endpoint insertOrUpdate(Entity $entity)
 * @method Endpoint delete(Entity $entity)
 * @method Endpoint find(int $id)
 * @method Endpoint findEntity(IQueryBuilder $query)
 * @method Endpoint[] findAll(int|null $limit = null, int|null $offset = null)
 * @method list<Endpoint> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<Endpoint>
 */
class EndpointMapper extends QBMapper
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
     * EndpointMapper constructor
     *
     * @param IDBConnection       $db                  Database connection
     * @param OrganisationService $organisationService Organisation service
     * @param IUserSession        $userSession         User session
     * @param IGroupManager       $groupManager        Group manager
     */
    public function __construct(
        IDBConnection $db,
        OrganisationService $organisationService,
        IUserSession $userSession,
        IGroupManager $groupManager
    ) {
        parent::__construct($db, 'openregister_endpoints', Endpoint::class);
        $this->organisationService = $organisationService;
        $this->userSession         = $userSession;
        $this->groupManager        = $groupManager;

    }//end __construct()


    /**
     * Find endpoint by UUID
     *
     * @param string $uuid Endpoint UUID
     *
     * @return Endpoint
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findByUuid(string $uuid): Endpoint
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

        // Apply organisation filter.
        $this->applyOrganisationFilter($qb);

        return $this->findEntity($qb);

    }//end findByUuid()


    /**
     * Find all endpoints
     *
     * @param int|null $limit  Maximum number of results
     * @param int|null $offset Starting offset
     *
     * @return Endpoint[]
     */
    public function findAll(?int $limit=null, ?int $offset=null): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName());

        // Apply organisation filter.
        $this->applyOrganisationFilter($qb);

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities($qb);

    }//end findAll()


    /**
     * Find a single endpoint by ID
     *
     * @param int $id Endpoint ID
     *
     * @return Endpoint
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function find($id): Endpoint
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        // Apply organisation filter.
        $this->applyOrganisationFilter($qb);

        return $this->findEntity($qb);

    }//end find()


    /**
     * Create a new endpoint from array data
     *
     * @param array $data Endpoint data
     *
     * @return Endpoint
     * @throws \Exception
     */
    public function createFromArray(array $data): Endpoint
    {
        // Check RBAC permissions.
        $this->verifyRbacPermission('create', 'endpoint');

        $endpoint = new Endpoint();

        // Generate UUID if not provided.
        if (!isset($data['uuid']) === false || empty($data['uuid']) === true) {
            $data['uuid'] = Uuid::v4()->toRfc4122();
        }

        // Set timestamps.
        $now = new DateTime();
        $data['created'] = $now;
        $data['updated'] = $now;

        // Hydrate the entity with data.
        $endpoint->hydrate($data);

        // Set organisation from session.
        $this->setOrganisationOnCreate($endpoint);

        // Persist to database.
        return $this->insert($endpoint);

    }//end createFromArray()


    /**
     * Update an endpoint from array data
     *
     * @param int   $id   Endpoint ID
     * @param array $data Updated endpoint data
     *
     * @return Endpoint
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     * @throws \Exception
     */
    public function updateFromArray(int $id, array $data): Endpoint
    {
        // Check RBAC permissions.
        $this->verifyRbacPermission('update', 'endpoint');

        // Find the existing endpoint.
        $endpoint = $this->find($id);

        // Verify organisation access.
        $this->verifyOrganisationAccess($endpoint);

        // Update timestamp.
        $data['updated'] = new DateTime();

        // Don't allow changing UUID or organisation.
        unset($data['uuid'], $data['organisation'], $data['created']);

        // Hydrate the entity with updated data.
        $endpoint->hydrate($data);

        // Persist to database.
        return $this->update($endpoint);

    }//end updateFromArray()


    /**
     * Delete an endpoint
     *
     * @param Entity $entity Endpoint entity to delete
     *
     * @return Entity
     * @throws \Exception
     */
    public function delete(Entity $entity): Entity
    {
        // Check RBAC permissions.
        $this->verifyRbacPermission('delete', 'endpoint');

        // Verify organisation access.
        $this->verifyOrganisationAccess($entity);

        return parent::delete($entity);

    }//end delete()


}//end class
