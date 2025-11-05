<?php
/**
 * OpenRegister Configuration Mapper
 *
 * This file contains the ConfigurationMapper class for database operations on configurations.
 *
 * @category Mapper
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
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Class ConfigurationMapper
 *
 * Mapper for Configuration entities with multi-tenancy and RBAC support.
 *
 * @package OCA\OpenRegister\Db
 *
 * @template-extends QBMapper<Configuration>
 *
 * @psalm-suppress MissingTemplateParam
 */
class ConfigurationMapper extends QBMapper
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
     * ConfigurationMapper constructor.
     *
     * @param IDBConnection       $db                  Database connection instance
     * @param OrganisationService $organisationService Organisation service for multi-tenancy
     * @param IUserSession        $userSession         User session
     * @param IGroupManager       $groupManager        Group manager for RBAC
     */
    public function __construct(
        IDBConnection $db,
        OrganisationService $organisationService,
        IUserSession $userSession,
        IGroupManager $groupManager
    ) {
        parent::__construct($db, 'openregister_configurations', Configuration::class);
        $this->organisationService = $organisationService;
        $this->userSession         = $userSession;
        $this->groupManager        = $groupManager;

    }//end __construct()


    /**
     * Find a configuration by its ID
     *
     * @param int $id Configuration ID
     *
     * @return Configuration The configuration entity
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     * @throws \Exception If user doesn't have read permission
     */
    public function find(int $id): Configuration
    {
        // Verify RBAC permission to read
        $this->verifyRbacPermission('read', 'configuration');

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        // Apply organisation filter (all users including admins must have active org)
        $this->applyOrganisationFilter($qb);

        return $this->findEntity($qb);

    }//end find()


    /**
     * Find configurations by type
     *
     * @param string $type   Configuration type
     * @param int    $limit  Maximum number of results
     * @param int    $offset Offset for pagination
     *
     * @return Configuration[] Array of configuration entities
     * @throws \Exception If user doesn't have read permission
     */
    public function findByType(string $type, int $limit=50, int $offset=0): array
    {
        // Verify RBAC permission to read
        $this->verifyRbacPermission('read', 'configuration');

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_STR)))
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('created', 'DESC');

        // Apply organisation filter
        $this->applyOrganisationFilter($qb);

        return $this->findEntities($qb);

    }//end findByType()


    /**
     * Find configurations by app
     *
     * @param string $app    App identifier
     * @param int    $limit  Maximum number of results
     * @param int    $offset Offset for pagination
     *
     * @return Configuration[] Array of configuration entities
     * @throws \Exception If user doesn't have read permission
     */
    public function findByApp(string $app, int $limit=50, int $offset=0): array
    {
        // Verify RBAC permission to read
        $this->verifyRbacPermission('read', 'configuration');

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('app', $qb->createNamedParameter($app, IQueryBuilder::PARAM_STR)))
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('created', 'DESC');

        // Apply organisation filter
        $this->applyOrganisationFilter($qb);

        return $this->findEntities($qb);

    }//end findByApp()


    /**
     * Insert a new configuration
     *
     * @param Configuration $entity Configuration entity to insert
     *
     * @return Configuration The inserted configuration with updated ID
     * @throws \Exception If user doesn't have create permission
     */
    public function insert(Entity $entity): Entity
    {
        // Verify RBAC permission to create
        $this->verifyRbacPermission('create', 'configuration');

        if ($entity instanceof Configuration) {
            // Generate UUID if not set
            if (empty($entity->getUuid())) {
                $entity->setUuid(\Symfony\Component\Uid\Uuid::v4()->toRfc4122());
            }
            
            // Set default type if not provided (required by database)
            if (empty($entity->getType())) {
                $entity->setType('default');
            }
            
            $entity->setCreated(new DateTime());
            $entity->setUpdated(new DateTime());
        }

        // Auto-set organisation from active session
        $this->setOrganisationOnCreate($entity);

        return parent::insert($entity);

    }//end insert()


    /**
     * Update an existing configuration
     *
     * @param Configuration $entity Configuration entity to update
     *
     * @return Configuration The updated configuration
     * @throws \Exception If user doesn't have update permission or access to this organisation
     */
    public function update(Entity $entity): Entity
    {
        // Verify RBAC permission to update
        $this->verifyRbacPermission('update', 'configuration');

        // Verify user has access to this organisation
        $this->verifyOrganisationAccess($entity);

        if ($entity instanceof Configuration) {
            $entity->setUpdated(new DateTime());
        }

        return parent::update($entity);

    }//end update()


    /**
     * Delete a configuration
     *
     * @param Configuration $entity Configuration entity to delete
     *
     * @return Configuration The deleted configuration
     * @throws \Exception If user doesn't have delete permission or access to this organisation
     */
    public function delete(Entity $entity): Entity
    {
        // Verify RBAC permission to delete
        $this->verifyRbacPermission('delete', 'configuration');

        // Verify user has access to this organisation
        $this->verifyOrganisationAccess($entity);

        return parent::delete($entity);

    }//end delete()


    /**
     * Create a configuration from an array
     *
     * @param array $data The configuration data
     *
     * @return Configuration The created configuration
     */
    public function createFromArray(array $data): Configuration
    {
        $config = new Configuration();
        $config->setVersion('0.0.1');
        $config->hydrate(object: $data);

        // Prepare the object before insertion.
        return $this->insert($config);

    }//end createFromArray()


    /**
     * Update a configuration from an array
     *
     * @param int   $id   The configuration ID
     * @param array $data The configuration data
     *
     * @throws DoesNotExistException If the configuration is not found
     * @return Configuration The updated configuration
     */
    public function updateFromArray(int $id, array $data): Configuration
    {
        $object = $this->find($id);

        // Set or update the version.
        if (isset($data['version']) === false) {
            $version    = explode('.', $object->getVersion());
            $version[2] = ((int) $version[2] + 1);
            $object->setVersion(implode('.', $version));
        }

        $object->hydrate(object: $data);

        return $this->update($object);

    }//end updateFromArray()


    /**
     * Count configurations by type
     *
     * @param string $type Configuration type
     *
     * @return int Number of configurations
     */
    public function countByType(string $type): int
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->tableName)
            ->where($qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_STR)));

        $result = $qb->executeQuery();
        $count  = $result->fetchOne();
        $result->closeCursor();

        return (int) $count;

    }//end countByType()


    /**
     * Count configurations by app
     *
     * @param string $app App ID
     *
     * @return int Number of configurations
     */
    public function countByApp(string $app): int
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->tableName)
            ->where($qb->expr()->eq('app', $qb->createNamedParameter($app, IQueryBuilder::PARAM_STR)));

        $result = $qb->executeQuery();
        $count  = $result->fetchOne();
        $result->closeCursor();

        return (int) $count;

    }//end countByApp()


    /**
     * Find all configurations
     *
     * @param int|null   $limit            The limit of the results
     * @param int|null   $offset           The offset of the results
     * @param array|null $filters          The filters to apply
     * @param array|null $searchConditions Array of search conditions
     * @param array|null $searchParams     Array of search parameters
     *
     * @return Configuration[] Array of found configurations
     * @throws \Exception If user doesn't have read permission
     */
    public function findAll(
        ?int $limit=null,
        ?int $offset=null,
        ?array $filters=[],
        ?array $searchConditions=[],
        ?array $searchParams=[]
    ): array {
        // Verify RBAC permission to read
        $this->verifyRbacPermission('read', 'configuration');

        $qb = $this->db->getQueryBuilder();

        // Build the base query.
        $qb->select('*')
            ->from($this->tableName)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('created', 'DESC');

        // Apply filters.
        foreach ($filters as $filter => $value) {
            if ($value === 'IS NOT NULL') {
                $qb->andWhere($qb->expr()->isNotNull($filter));
            } else if ($value === 'IS NULL') {
                $qb->andWhere($qb->expr()->isNull($filter));
            } else {
                $qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
            }
        }

        // Apply search conditions.
        if (empty($searchConditions) === false) {
            $qb->andWhere('('.implode(' OR ', $searchConditions).')');
            foreach ($searchParams as $param => $value) {
                $qb->setParameter($param, $value);
            }
        }

        // Apply organisation filter (all users including admins must have active org)
        $this->applyOrganisationFilter($qb);

        // Execute the query and return the results.
        return $this->findEntities($qb);

    }//end findAll()


}//end class
