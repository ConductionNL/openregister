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
 * EndpointMapper handles database operations for Endpoint entities
 *
 * Mapper for Endpoint entities to handle database operations with multi-tenancy
 * and RBAC support. Extends QBMapper to provide standard CRUD operations.
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
 *
 * @method Endpoint insert(Entity $entity)
 * @method Endpoint update(Entity $entity)
 * @method Endpoint insertOrUpdate(Entity $entity)
 * @method Endpoint delete(Entity $entity)
 * @method Endpoint find(int $id)
 * @method Endpoint findEntity(IQueryBuilder $query)
 * @method Endpoint[] findAll(int|null $limit=null, int|null $offset=null)
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
     * Used to filter endpoints by organisation for multi-tenant support.
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
     * EndpointMapper constructor
     *
     * Initializes mapper with database connection and multi-tenancy/RBAC dependencies.
     * Calls parent constructor to set up base mapper functionality.
     *
     * @param IDBConnection      $db                 Database connection
     * @param OrganisationMapper $organisationMapper Organisation service for multi-tenancy
     * @param IUserSession       $userSession        User session for RBAC
     * @param IGroupManager      $groupManager       Group manager for RBAC
     *
     * @return void
     */
    public function __construct(
        IDBConnection $db,
        // REMOVED: Services should not be in mappers.
        // OrganisationMapper $organisationMapper.
        IUserSession $userSession,
        IGroupManager $groupManager
    ) {
        // Call parent constructor to initialize base mapper with table name and entity class.
        parent::__construct($db, 'openregister_endpoints', Endpoint::class);

        // Store dependencies for use in mapper methods.
        // REMOVED: Services should not be in mappers.
        // $this->organisationMapper = $organisationService.
        $this->userSession  = $userSession;
        $this->groupManager = $groupManager;

    }//end __construct()

    /**
     * Find all endpoints
     *
     * Retrieves all endpoints with optional pagination and organisation filtering.
     * Applies multi-tenancy filter to return only endpoints for current organisation.
     *
     * @param int|null $limit  Maximum number of results to return (null = no limit)
     * @param int|null $offset Starting offset for pagination (null = no offset)
     *
     * @return Endpoint[]
     *
     * @psalm-return list<OCA\OpenRegister\Db\Endpoint>
     */
    public function findAll(?int $limit=null, ?int $offset=null): array
    {
        // Step 1: Get query builder instance.
        $qb = $this->db->getQueryBuilder();

        // Step 2: Build SELECT query for all columns.
        $qb->select('*')
            ->from($this->getTableName());

        // Step 3: Apply organisation filter for multi-tenancy.
        // This ensures users only see endpoints from their organisation.
        $this->applyOrganisationFilter($qb);

        // Step 4: Apply pagination if limit specified.
        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        // Step 5: Apply offset if specified.
        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        // Step 6: Execute query and return entities.
        return $this->findEntities($qb);

    }//end findAll()

    /**
     * Find a single endpoint by ID
     *
     * Retrieves endpoint by ID with organisation filtering for multi-tenancy.
     * Throws exception if endpoint not found or doesn't belong to current organisation.
     *
     * @param int $id Endpoint ID to find
     *
     * @return Endpoint The found endpoint entity
     *
     * @throws DoesNotExistException If endpoint not found or not accessible
     * @throws MultipleObjectsReturnedException If multiple endpoints found (should not happen)
     */
    public function find($id): Endpoint
    {
        // Step 1: Get query builder instance.
        $qb = $this->db->getQueryBuilder();

        // Step 2: Build SELECT query with ID filter.
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        // Step 3: Apply organisation filter for multi-tenancy.
        // This ensures users can only access endpoints from their organisation.
        $this->applyOrganisationFilter($qb);

        // Step 4: Execute query and return single entity.
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
        $this->verifyRbacPermission(action: 'create', entityType: 'endpoint');

        $endpoint = new Endpoint();

        // Generate UUID if not provided.
        if (isset($data['uuid']) === false || empty($data['uuid']) === true) {
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
        $this->verifyRbacPermission(action: 'update', entityType: 'endpoint');

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
     * @return Endpoint
     * @throws \Exception
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function delete(Entity $entity): Endpoint
    {
        // Check RBAC permissions.
        $this->verifyRbacPermission(action: 'delete', entityType: 'endpoint');

        // Verify organisation access.
        $this->verifyOrganisationAccess($entity);

        return parent::delete($entity);

    }//end delete()
}//end class
