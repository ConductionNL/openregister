<?php

/**
 * OpenRegister Mapping Mapper
 *
 * Mapper for Mapping entities to handle database operations.
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
 * MappingMapper handles database operations for Mapping entities
 *
 * Mapper for Mapping entities to handle database operations with multi-tenancy
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
 * @method Mapping insert(Entity $entity)
 * @method Mapping update(Entity $entity)
 * @method Mapping insertOrUpdate(Entity $entity)
 * @method Mapping delete(Entity $entity)
 * @method Mapping findEntity(IQueryBuilder $query)
 * @method list<Mapping> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<Mapping>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ElseExpression)         Else clauses improve readability in find and update methods
 */
class MappingMapper extends QBMapper
{
    use MultiTenancyTrait;

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
     * MappingMapper constructor
     *
     * Initializes mapper with database connection and multi-tenancy/RBAC dependencies.
     * Calls parent constructor to set up base mapper functionality.
     *
     * @param IDBConnection $db           Database connection
     * @param IUserSession  $userSession  User session
     * @param IGroupManager $groupManager Group manager
     *
     * @return void
     */
    public function __construct(
        IDBConnection $db,
        IUserSession $userSession,
        IGroupManager $groupManager
    ) {
        // Call parent constructor to initialize base mapper with table name and entity class.
        parent::__construct($db, 'openregister_mappings', Mapping::class);

        // Store dependencies for use in mapper methods.
        $this->userSession  = $userSession;
        $this->groupManager = $groupManager;
    }//end __construct()

    /**
     * Find all mappings
     *
     * Retrieves all mappings with optional pagination and organisation filtering.
     * Applies multi-tenancy filter to return only mappings for current organisation.
     *
     * @param int|null $limit  Maximum number of results to return (null = no limit)
     * @param int|null $offset Starting offset for pagination (null = no offset)
     *
     * @return Mapping[]
     *
     * @psalm-return list<OCA\OpenRegister\Db\Mapping>
     */
    public function findAll(?int $limit=null, ?int $offset=null): array
    {
        // Step 1: Get query builder instance.
        $qb = $this->db->getQueryBuilder();

        // Step 2: Build SELECT query for all columns.
        $qb->select('*')
            ->from($this->getTableName());

        // Step 3: Apply organisation filter for multi-tenancy.
        // This ensures users only see mappings from their organisation.
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
     * Find a single mapping by ID, UUID, or slug
     *
     * Retrieves mapping by ID with organisation filtering for multi-tenancy.
     * Throws exception if mapping not found or doesn't belong to current organisation.
     *
     * @param int|string $id Mapping ID, UUID, or slug to find
     *
     * @return Mapping The found mapping entity
     *
     * @throws DoesNotExistException If mapping not found or not accessible
     * @throws MultipleObjectsReturnedException If multiple mappings found (should not happen)
     */
    public function find(int|string $id): Mapping
    {
        // Step 1: Get query builder instance.
        $qb = $this->db->getQueryBuilder();

        // Step 2: Build SELECT query.
        $qb->select('*')
            ->from($this->getTableName());

        // Step 3: If it's a string but can be converted to a numeric value, check if it's actually numeric.
        if (is_string($id) === true && ctype_digit($id) === false) {
            // For non-numeric strings, search in uuid and slug columns.
            $qb->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('uuid', $qb->createNamedParameter($id)),
                    $qb->expr()->eq('slug', $qb->createNamedParameter($id)),
                    $qb->expr()->eq('id', $qb->createNamedParameter($id))
                )
            );
        } else {
            // For numeric values, search in id column.
            $qb->where(
                $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
            );
        }

        // Step 4: Apply organisation filter for multi-tenancy.
        // This ensures users can only access mappings from their organisation.
        $this->applyOrganisationFilter($qb);

        // Step 5: Execute query and return single entity.
        return $this->findEntity($qb);
    }//end find()

    /**
     * Find mappings by reference
     *
     * @param string $reference The reference value to search for
     *
     * @return Mapping[] Array of mapping entities
     */
    public function findByRef(string $reference): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('reference', $qb->createNamedParameter($reference))
            );

        $this->applyOrganisationFilter($qb);

        return $this->findEntities($qb);
    }//end findByRef()

    /**
     * Create a new mapping from array data
     *
     * @param array $data Mapping data
     *
     * @return Mapping
     * @throws \Exception
     */
    public function createFromArray(array $data): Mapping
    {
        // Check RBAC permissions.
        $this->verifyRbacPermission(action: 'create', entityType: 'mapping');

        $mapping = new Mapping();

        // Generate UUID if not provided.
        if (isset($data['uuid']) === false || empty($data['uuid']) === true) {
            $data['uuid'] = Uuid::v4()->toRfc4122();
        }

        // Set version if not provided.
        if (isset($data['version']) === false || empty($data['version']) === true) {
            $data['version'] = '0.0.1';
        }

        // Set timestamps.
        $now = new DateTime();
        $data['created'] = $now;
        $data['updated'] = $now;

        // Hydrate the entity with data.
        $mapping->hydrate($data);

        // Set organisation from session.
        $this->setOrganisationOnCreate($mapping);

        // Persist to database.
        return $this->insert($mapping);
    }//end createFromArray()

    /**
     * Update a mapping from array data
     *
     * @param int   $id   Mapping ID
     * @param array $data Updated mapping data
     *
     * @return Mapping
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     * @throws \Exception
     */
    public function updateFromArray(int $id, array $data): Mapping
    {
        // Check RBAC permissions.
        $this->verifyRbacPermission(action: 'update', entityType: 'mapping');

        // Find the existing mapping.
        $mapping = $this->find($id);

        // Verify organisation access.
        $this->verifyOrganisationAccess($mapping);

        // Set version if not provided (auto-increment patch version).
        if (isset($data['version']) === false || empty($data['version']) === true) {
            $currentVersion = $mapping->getVersion();
            if (empty($currentVersion) === false) {
                $version = explode('.', $currentVersion);
                if (isset($version[2]) === true) {
                    $version[2]      = (int) $version[2] + 1;
                    $data['version'] = implode('.', $version);
                }
            } else {
                $data['version'] = '0.0.1';
            }
        }

        // Update timestamp.
        $data['updated'] = new DateTime();

        // Don't allow changing UUID or organisation.
        unset($data['uuid'], $data['organisation'], $data['created']);

        // Hydrate the entity with updated data.
        $mapping->hydrate($data);

        // Persist to database.
        return $this->update($mapping);
    }//end updateFromArray()

    /**
     * Delete a mapping
     *
     * @param Entity $entity Mapping entity to delete
     *
     * @return Mapping
     * @throws \Exception
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function delete(Entity $entity): Mapping
    {
        // Check RBAC permissions.
        $this->verifyRbacPermission(action: 'delete', entityType: 'mapping');

        // Verify organisation access.
        $this->verifyOrganisationAccess($entity);

        return parent::delete($entity);
    }//end delete()

    /**
     * Get the total count of all mappings.
     *
     * @return int The total number of mappings in the database.
     */
    public function getTotalCount(): int
    {
        $qb = $this->db->getQueryBuilder();

        // Select count of all mappings.
        $qb->select($qb->createFunction('COUNT(*) as count'))
            ->from($this->getTableName());

        $this->applyOrganisationFilter($qb);

        $result = $qb->executeQuery();
        $row    = $result->fetch();

        // Return the total count.
        return (int) $row['count'];
    }//end getTotalCount()

    /**
     * Find all mappings that belong to a specific configuration.
     *
     * @param string $configurationId The ID of the configuration to find mappings for
     *
     * @return Mapping[] Array of Mapping entities
     */
    public function findByConfiguration(string $configurationId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where('JSON_CONTAINS(configurations, :configId)');

        $qb->setParameter('configId', '"'.$configurationId.'"');

        $this->applyOrganisationFilter($qb);

        return $this->findEntities($qb);
    }//end findByConfiguration()

    /**
     * Get all mapping ID to slug mappings
     *
     * @return array<string,string> Array mapping mapping IDs to their slugs
     */
    public function getIdToSlugMap(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'slug')
            ->from($this->getTableName());

        $this->applyOrganisationFilter($qb);

        $result   = $qb->executeQuery();
        $mappings = [];
        while (($row = $result->fetch()) !== false) {
            $mappings[$row['id']] = $row['slug'];
        }

        return $mappings;
    }//end getIdToSlugMap()

    /**
     * Get all mapping slug to ID mappings
     *
     * @return array<string,string> Array mapping mapping slugs to their IDs
     */
    public function getSlugToIdMap(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'slug')
            ->from($this->getTableName());

        $this->applyOrganisationFilter($qb);

        $result   = $qb->executeQuery();
        $mappings = [];
        while (($row = $result->fetch()) !== false) {
            $mappings[$row['slug']] = $row['id'];
        }

        return $mappings;
    }//end getSlugToIdMap()
}//end class
