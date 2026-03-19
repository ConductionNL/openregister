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
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
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
 * Else clauses improve readability in find and update methods
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
     * Distributed cache for mapping entity lookups
     *
     * @var ICache|null
     */
    private ?ICache $mappingCache = null;

    /**
     * Cache key prefix matching MappingService
     *
     * @var string
     */
    private const CACHE_PREFIX = 'openregister_mapping_';

    /**
     * MappingMapper constructor
     *
     * Initializes mapper with database connection and multi-tenancy/RBAC dependencies.
     * Calls parent constructor to set up base mapper functionality.
     *
     * @param IDBConnection   $db           Database connection
     * @param IUserSession    $userSession  User session
     * @param IGroupManager   $groupManager Group manager
     * @param ICacheFactory   $cacheFactory Cache factory for distributed caching
     * @param LoggerInterface $logger       Logger for cache diagnostics
     *
     * @return void
     */
    public function __construct(
        IDBConnection $db,
        IUserSession $userSession,
        IGroupManager $groupManager,
        ICacheFactory $cacheFactory,
        private readonly LoggerInterface $logger
    ) {
        // Call parent constructor to initialize base mapper with table name and entity class.
        parent::__construct(db: $db, tableName: 'openregister_mappings', entityClass: Mapping::class);

        // Store dependencies for use in mapper methods.
        $this->userSession  = $userSession;
        $this->groupManager = $groupManager;

        // Initialize distributed cache for invalidation on write operations.
        try {
            $this->mappingCache = $cacheFactory->createDistributed(self::CACHE_PREFIX);
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[MappingMapper] Failed to initialize distributed cache',
                context: ['error' => $e->getMessage()]
            );
        }
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
        $this->applyOrganisationFilter(qb: $qb);

        // Step 4: Apply pagination if limit specified.
        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        // Step 5: Apply offset if specified.
        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        // Step 6: Execute query and return entities.
        return $this->findEntities(query: $qb);
    }//end findAll()

    /**
     * Find a single mapping by ID, UUID, or slug
     *
     * Retrieves mapping by ID with organisation filtering for multi-tenancy.
     * Throws exception if mapping not found or doesn't belong to current organisation.
     *
     * @param int|string $id             Mapping ID, UUID, or slug to find
     * @param bool       $includeNullOrg Include mappings with no organisation set
     *
     * @return Mapping The found mapping entity
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flag controls org filter inclusion
     *
     * @throws DoesNotExistException If mapping not found or not accessible
     * @throws MultipleObjectsReturnedException If multiple mappings found (should not happen)
     */
    public function find(int|string $id, bool $includeNullOrg=false): Mapping
    {
        // Step 1: Get query builder instance.
        $qb = $this->db->getQueryBuilder();

        // Step 2: Build SELECT query.
        $qb->select('*')
            ->from($this->getTableName());

        // Step 3: If it's a string but can be converted to a numeric value, check if it's actually numeric.
        // Default: for numeric values, search in id column.
        $qb->where(
            $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
        );
        if (is_string($id) === true && ctype_digit($id) === false) {
            // For non-numeric strings, search in uuid and slug columns.
            $qb->resetQueryPart('where');
            $qb->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('uuid', $qb->createNamedParameter($id)),
                    $qb->expr()->eq('slug', $qb->createNamedParameter($id)),
                    $qb->expr()->eq('id', $qb->createNamedParameter($id))
                )
            );
        }

        // Step 4: Apply organisation filter for multi-tenancy.
        // This ensures users can only access mappings from their organisation.
        // When includeNullOrg is true, also matches mappings with no organisation set.
        $this->applyOrganisationFilter(qb: $qb, allowNullOrg: $includeNullOrg);

        // Step 5: Execute query and return single entity.
        return $this->findEntity(query: $qb);
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

        $this->applyOrganisationFilter(qb: $qb);

        return $this->findEntities(query: $qb);
    }//end findByRef()

    /**
     * Invalidates distributed cache entries for a mapping entity.
     *
     * Removes cache entries keyed by ID, UUID, and slug so that subsequent
     * reads via MappingService::getMapping() fetch fresh data from the database.
     *
     * @param Entity $mapping The mapping entity whose cache entries should be invalidated
     *
     * @return void
     */
    private function invalidateCache(Entity $mapping): void
    {
        if ($this->mappingCache === null) {
            return;
        }

        // Invalidate all possible lookup keys: numeric ID, UUID, and slug.
        $keys = [(string) $mapping->getId()];

        if ($mapping instanceof Mapping) {
            $keys[] = $mapping->getUuid();
            $keys[] = $mapping->getSlug();
        }

        foreach (array_filter($keys) as $key) {
            $this->mappingCache->remove($key);
        }
    }//end invalidateCache()

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
        $this->setOrganisationOnCreate(entity: $mapping);

        // Persist to database.
        $mapping = $this->insert(entity: $mapping);

        // Invalidate cache so subsequent lookups get fresh data.
        $this->invalidateCache(mapping: $mapping);

        return $mapping;
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
        $mapping = $this->find(id: $id);

        // Verify organisation access.
        $this->verifyOrganisationAccess(entity: $mapping);

        // Set version if not provided (auto-increment patch version).
        if (isset($data['version']) === false || empty($data['version']) === true) {
            $currentVersion = $mapping->getVersion();
            $data['version'] = '0.0.1';
            if (empty($currentVersion) === false) {
                $version = explode('.', $currentVersion);
                if (isset($version[2]) === true) {
                    $version[2]      = (int) $version[2] + 1;
                    $data['version'] = implode('.', $version);
                }
            }
        }

        // Update timestamp.
        $data['updated'] = new DateTime();

        // Don't allow changing UUID or organisation.
        unset($data['uuid'], $data['organisation'], $data['created']);

        // Hydrate the entity with updated data.
        $mapping->hydrate($data);

        // Persist to database.
        $mapping = $this->update(entity: $mapping);

        // Invalidate cache so subsequent lookups get fresh data.
        $this->invalidateCache(mapping: $mapping);

        return $mapping;
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
        $this->verifyOrganisationAccess(entity: $entity);

        // Invalidate cache before deletion.
        $this->invalidateCache(mapping: $entity);

        return parent::delete(entity: $entity);
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

        $this->applyOrganisationFilter(qb: $qb);

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

        $this->applyOrganisationFilter(qb: $qb);

        return $this->findEntities(query: $qb);
    }//end findByConfiguration()

    /**
     * Get all mapping ID to slug mappings
     *
     * @param bool $includeNullOrg Include mappings with no organisation set
     *
     * @return array<string,string> Array mapping mapping IDs to their slugs
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flag controls org filter inclusion
     */
    public function getIdToSlugMap(bool $includeNullOrg=false): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'slug')
            ->from($this->getTableName());

        $this->applyOrganisationFilter(qb: $qb, allowNullOrg: $includeNullOrg);

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
     * @param bool $includeNullOrg Include mappings with no organisation set
     *
     * @return array<string,string> Array mapping mapping slugs to their IDs
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flag controls org filter inclusion
     */
    public function getSlugToIdMap(bool $includeNullOrg=false): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'slug')
            ->from($this->getTableName());

        $this->applyOrganisationFilter(qb: $qb, allowNullOrg: $includeNullOrg);

        $result   = $qb->executeQuery();
        $mappings = [];
        while (($row = $result->fetch()) !== false) {
            $mappings[$row['slug']] = $row['id'];
        }

        return $mappings;
    }//end getSlugToIdMap()
}//end class
