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
use OCA\OpenRegister\Event\ConfigurationCreatedEvent;
use OCA\OpenRegister\Event\ConfigurationDeletedEvent;
use OCA\OpenRegister\Event\ConfigurationUpdatedEvent;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\ISession;
use OCP\IUserSession;

/**
 * Class ConfigurationMapper
 *
 * Mapper for Configuration entities with multi-tenancy and RBAC support.
 *
 * @package OCA\OpenRegister\Db
 *
 * @template-extends QBMapper<Configuration>
 * @method           Configuration insert(Entity $entity)
 * @method           Configuration update(Entity $entity)
 * @method           Configuration insertOrUpdate(Entity $entity)
 * @method           Configuration delete(Entity $entity)
 * @method           Configuration find(int|string $id)
 * @method           Configuration findEntity(IQueryBuilder $query)
 * @method           Configuration[] findAll(int|null $limit = null, int|null $offset = null)
 * @method           list<Configuration> findEntities(IQueryBuilder $query)
 *
 * @extends QBMapper<Configuration>
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
     * Session for caching configurations
     *
     * @var ISession
     */
    private ISession $session;

    /**
     * Event dispatcher for dispatching configuration events
     *
     * @var IEventDispatcher
     */
    private IEventDispatcher $eventDispatcher;

    /**
     * Session key prefix for storing configurations
     *
     * @var string
     */
    private const SESSION_KEY_PREFIX = 'openregister_configurations_';



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
        // Verify RBAC permission to read.
        $this->verifyRbacPermission('read', 'configuration');

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        // Apply organisation filter (all users including admins must have active org).
        $this->applyOrganisationFilter($qb);

        return $this->findEntity($qb);

    }//end find()



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
        // Verify RBAC permission to read.
        $this->verifyRbacPermission('read', 'configuration');

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('app', $qb->createNamedParameter($app, IQueryBuilder::PARAM_STR)))
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('created', 'DESC');

        // Apply organisation filter.
        $this->applyOrganisationFilter($qb);

        return $this->findEntities($qb);

    }//end findByApp()


    /**
     * Find configuration by source URL
     *
     * This method finds a configuration by its source URL, which serves as a unique
     * identifier for configurations loaded from files or remote sources.
     *
     * @param string $sourceUrl Source URL to search for
     *
     * @return Configuration|null The configuration entity or null if not found
     * @throws \Exception If user doesn't have read permission
     *
     * @since 0.2.10
     */
    public function findBySourceUrl(string $sourceUrl): ?Configuration
    {
        // Verify RBAC permission to read.
        $this->verifyRbacPermission('read', 'configuration');

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('source_url', $qb->createNamedParameter($sourceUrl, IQueryBuilder::PARAM_STR)))
            ->orderBy('created', 'DESC')
            ->setMaxResults(1);

        // Apply organisation filter.
        $this->applyOrganisationFilter($qb);

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            // No configuration found with this source URL.
            return null;
        }

    }//end findBySourceUrl()


    /**
     * Find configurations that have sync enabled
     *
     * This method finds all configurations that should be synchronized automatically
     *
     * @param int $limit  Maximum number of results
     * @param int $offset Offset for pagination
     *
     * @return Configuration[] Array of configuration entities
     * @throws \Exception If user doesn't have read permission
     *
     * @since 0.2.10
     */
    public function findBySyncEnabled(int $limit=50, int $offset=0): array
    {
        // Verify RBAC permission to read.
        $this->verifyRbacPermission('read', 'configuration');

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('sync_enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->orderBy('last_sync_date', 'ASC')
        // Oldest first for priority sync.
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        // Apply organisation filter.
        $this->applyOrganisationFilter($qb);

        return $this->findEntities($qb);

    }//end findBySyncEnabled()



    /**
     * Update synchronization status for a configuration
     *
     * @param int      $id       Configuration ID
     * @param string   $status   Sync status: 'success', 'failed', 'pending'
     * @param DateTime $syncDate Synchronization timestamp
     * @param string   $message  Optional message about the sync result
     *
     * @return Configuration The updated configuration
     * @throws \Exception If configuration not found or user doesn't have permission
     *
     * @since 0.2.10
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function updateSyncStatus(int $id, string $status, \DateTime $syncDate, string $message=''): Configuration
    {
        // Verify RBAC permission to update.
        $this->verifyRbacPermission('update', 'configuration');

        $configuration = $this->find($id);
        $configuration->setSyncStatus($status);
        $configuration->setLastSyncDate($syncDate);
        $configuration->setUpdated(new \DateTime());

        return $this->update($configuration);

    }//end updateSyncStatus()


    /**
     * Insert a new configuration
     *
     * @param Configuration $entity Configuration entity to insert
     *
     * @return Configuration The inserted configuration with updated ID
     * @throws \Exception If user doesn't have create permission
     */
    public function insert(Entity $entity): Configuration
    {
        // Verify RBAC permission to create.
        $this->verifyRbacPermission('create', 'configuration');

        /*
         * @var Configuration $entity
         */

        if ($entity instanceof Configuration) {
            // Generate UUID if not set.
            if (empty($entity->getUuid()) === true) {
                $entity->setUuid(\Symfony\Component\Uid\Uuid::v4()->toRfc4122());
            }

            // Set default type if not provided (required by database).
            if (empty($entity->getType()) === true) {
                $entity->setType('default');
            }

            // Auto-set owner to current user if not already set.
            if (empty($entity->getOwner()) === true) {
                $currentUserId = $this->getCurrentUserId();
                if ($currentUserId !== null) {
                    $entity->setOwner($currentUserId);
                }
            }

            $entity->setCreated(new DateTime());
            $entity->setUpdated(new DateTime());
        }//end if

        // Auto-set organisation from active session.
        $this->setOrganisationOnCreate($entity);

        $result = parent::insert($entity);

        // Invalidate configuration cache.
        $this->invalidateConfigurationCache();

        // Dispatch creation event.
        $this->eventDispatcher->dispatchTyped(new ConfigurationCreatedEvent($result));

        return $result;

    }//end insert()


    /**
     * Update an existing configuration
     *
     * @param Configuration $entity Configuration entity to update
     *
     * @return Configuration The updated configuration
     * @throws \Exception If user doesn't have update permission or access to this organisation
     */
    public function update(Entity $entity): Configuration
    {
        // Verify RBAC permission to update.
        $this->verifyRbacPermission('update', 'configuration');

        // Verify user has access to this organisation.
        $this->verifyOrganisationAccess($entity);

        // Get old state before update.
        $oldEntity = $this->find($entity->getId());

        $entity->setUpdated(new DateTime());

        $result = parent::update($entity);

        // Invalidate configuration cache.
        $this->invalidateConfigurationCache();

        // Dispatch update event.
        $this->eventDispatcher->dispatchTyped(new ConfigurationUpdatedEvent($result, $oldEntity));

        return $result;

    }//end update()


    /**
     * Delete a configuration
     *
     * @param Configuration $entity Configuration entity to delete
     *
     * @return Configuration The deleted configuration
     * @throws \Exception If user doesn't have delete permission or access to this organisation
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function delete(Entity $entity): Entity
    {
        // Verify RBAC permission to delete.
        $this->verifyRbacPermission('delete', 'configuration');

        // Verify user has access to this organisation.
        $this->verifyOrganisationAccess($entity);

        $result = parent::delete($entity);

        // Invalidate configuration cache.
        $this->invalidateConfigurationCache();

        // Dispatch deletion event.
        $this->eventDispatcher->dispatchTyped(new ConfigurationDeletedEvent($result));

        return $result;

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
        $object = $this->find(id: $id);

        // Set or update the version.
        if (!isset($data['version'])) {
            $version    = explode('.', $object->getVersion());
            $version[2] = ((int) $version[2] + 1);
            $object->setVersion(implode('.', $version));
        }

        $object->hydrate(object: $data);

        return $this->update($object);

    }//end updateFromArray()




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
        // Verify RBAC permission to read.
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

        // Apply organisation filter (all users including admins must have active org).
        $this->applyOrganisationFilter($qb);

        // Execute the query and return the results.
        return $this->findEntities($qb);

    }//end findAll()


    /**
     * Invalidate the configuration cache for the active organisation
     *
     * This method removes cached configurations from the session
     * to ensure fresh data is loaded on the next request.
     *
     * @return void
     */
    private function invalidateConfigurationCache(): void
    {
        $activeOrg = $this->organisationService->getActiveOrganisation();
        if ($activeOrg === null) {
            return;
        }

        $orgUuid    = $activeOrg->getUuid();
        $sessionKey = self::SESSION_KEY_PREFIX.$orgUuid;

        // Remove from session.
        $this->session->remove($sessionKey);

    }//end invalidateConfigurationCache()


}//end class
