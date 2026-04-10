<?php

/**
 * OpenRegister Action Mapper
 *
 * Mapper for Action entities to handle database operations.
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
use OCP\IAppConfig;
use Symfony\Component\Uid\Uuid;

/**
 * ActionMapper handles database operations for Action entities
 *
 * @method Action insert(Entity $entity)
 * @method Action update(Entity $entity)
 * @method Action insertOrUpdate(Entity $entity)
 * @method Action delete(Entity $entity)
 * @method Action find(int $id)
 * @method Action findEntity(IQueryBuilder $query)
 * @method Action[] findAll(int|null $limit=null, int|null $offset=null, array|null $filters=[])
 * @method list<Action> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<Action>
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ActionMapper extends QBMapper
{
    use MultiTenancyTrait;

    /**
     * Organisation mapper for multi-tenancy
     *
     * @var OrganisationMapper Organisation mapper instance
     */
    protected OrganisationMapper $organisationMapper;

    /**
     * App configuration for multitenancy settings
     *
     * @var IAppConfig App configuration instance
     */
    protected IAppConfig $appConfig;

    /**
     * User session for current user
     *
     * @var IUserSession User session instance
     */
    private readonly IUserSession $userSession;

    /**
     * Group manager for RBAC
     *
     * @var IGroupManager Group manager instance
     */
    private readonly IGroupManager $groupManager;

    /**
     * Constructor
     *
     * @param IDBConnection      $db                 Database connection
     * @param OrganisationMapper $organisationMapper Organisation mapper
     * @param IUserSession       $userSession        User session
     * @param IGroupManager      $groupManager       Group manager
     * @param IAppConfig         $appConfig          App configuration
     *
     * @return void
     */
    public function __construct(
        IDBConnection $db,
        OrganisationMapper $organisationMapper,
        IUserSession $userSession,
        IGroupManager $groupManager,
        IAppConfig $appConfig
    ) {
        parent::__construct(db: $db, tableName: 'openregister_actions', entityClass: Action::class);

        $this->organisationMapper = $organisationMapper;
        $this->userSession        = $userSession;
        $this->groupManager       = $groupManager;
        $this->appConfig          = $appConfig;
    }//end __construct()

    /**
     * Find all actions
     *
     * @param int|null $limit   Maximum number of results
     * @param int|null $offset  Number of results to skip
     * @param array    $filters Optional filters
     *
     * @return Action[]
     *
     * @psalm-return list<Action>
     */
    public function findAll(?int $limit=null, ?int $offset=null, ?array $filters=[]): array
    {
        if ($this->tableExists() === false) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->isNull('deleted'));

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        foreach ($filters ?? [] as $filter => $value) {
            if ($value === 'IS NOT NULL') {
                $qb->andWhere($qb->expr()->isNotNull($filter));
                continue;
            }

            if ($value === 'IS NULL') {
                $qb->andWhere($qb->expr()->isNull($filter));
                continue;
            }

            $qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
        }

        $this->applyOrganisationFilter(qb: $qb);

        return $this->findEntities(query: $qb);
    }//end findAll()

    /**
     * Find a single action by ID
     *
     * @param int $id Action ID
     *
     * @return Action
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function find(int $id): Action
    {
        if ($this->tableExists() === false) {
            throw new DoesNotExistException('Actions table does not exist. Please run migrations.');
        }

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $this->applyOrganisationFilter(qb: $qb);

        return $this->findEntity(query: $qb);
    }//end find()

    /**
     * Find action by UUID
     *
     * @param string $uuid Action UUID
     *
     * @return Action
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findByUuid(string $uuid): Action
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

        $this->applyOrganisationFilter(qb: $qb);

        return $this->findEntity(query: $qb);
    }//end findByUuid()

    /**
     * Find action by slug
     *
     * @param string $slug Action slug
     *
     * @return Action
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findBySlug(string $slug): Action
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('slug', $qb->createNamedParameter($slug)));

        $this->applyOrganisationFilter(qb: $qb);

        return $this->findEntity(query: $qb);
    }//end findBySlug()

    /**
     * Find actions by event type
     *
     * @param string $eventType Event type to match
     *
     * @return Action[]
     *
     * @psalm-return list<Action>
     */
    public function findByEventType(string $eventType): array
    {
        // Since event_type can be JSON array or single string, we need to fetch enabled actions
        // and filter in PHP to support fnmatch patterns.
        $actions = $this->findAll(filters: ['status' => 'active', 'enabled' => true]);

        return array_values(
            array_filter(
                $actions,
                function (Action $action) use ($eventType) {
                    return $action->matchesEvent($eventType);
                }
            )
        );
    }//end findByEventType()

    /**
     * Find matching actions for a given event, schema, and register
     *
     * Queries for all enabled, active, non-deleted actions, then filters by
     * event type (exact + fnmatch wildcard), schema binding, and register binding.
     *
     * @param string      $eventType    Event type class name
     * @param string|null $schemaUuid   Schema UUID to filter by
     * @param string|null $registerUuid Register UUID to filter by
     *
     * @return Action[] Sorted by execution_order ASC
     *
     * @psalm-return list<Action>
     */
    public function findMatchingActions(string $eventType, ?string $schemaUuid=null, ?string $registerUuid=null): array
    {
        if ($this->tableExists() === false) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')))
            ->andWhere($qb->expr()->isNull('deleted'))
            ->orderBy('execution_order', 'ASC');

        $actions = $this->findEntities(query: $qb);

        // Filter by event type, schema, and register in PHP (supports fnmatch patterns).
        return array_values(
            array_filter(
                $actions,
                function (Action $action) use ($eventType, $schemaUuid, $registerUuid) {
                    return $action->matchesEvent($eventType)
                        && $action->matchesSchema($schemaUuid)
                        && $action->matchesRegister($registerUuid);
                }
            )
        );
    }//end findMatchingActions()

    /**
     * Insert a new action
     *
     * @param Entity $entity Action entity to insert
     *
     * @return Action
     *
     * @throws \Exception
     */
    public function insert(Entity $entity): Entity
    {
        $this->verifyRbacPermission(action: 'create', entityType: 'action');

        if ($entity instanceof Action) {
            if (empty($entity->getUuid()) === true) {
                $entity->setUuid(Uuid::v4()->toRfc4122());
            }

            $entity->setCreated(new DateTime());
            $entity->setUpdated(new DateTime());
        }

        $this->setOrganisationOnCreate(entity: $entity);

        return parent::insert(entity: $entity);
    }//end insert()

    /**
     * Update an existing action
     *
     * @param Entity $entity Action entity to update
     *
     * @return Action
     *
     * @throws \Exception
     */
    public function update(Entity $entity): Entity
    {
        $this->verifyRbacPermission(action: 'update', entityType: 'action');
        $this->verifyOrganisationAccess(entity: $entity);

        if ($entity instanceof Action) {
            $entity->setUpdated(new DateTime());
        }

        return parent::update(entity: $entity);
    }//end update()

    /**
     * Delete an action
     *
     * @param Entity $entity Action entity to delete
     *
     * @return Action
     *
     * @throws \Exception
     */
    public function delete(Entity $entity): Entity
    {
        $this->verifyRbacPermission(action: 'delete', entityType: 'action');
        $this->verifyOrganisationAccess(entity: $entity);

        return parent::delete(entity: $entity);
    }//end delete()

    /**
     * Create action from array
     *
     * @param array $data Action data
     *
     * @return Action
     */
    public function createFromArray(array $data): Action
    {
        $action = new Action();
        $action->hydrate($data);

        return $this->insert(entity: $action);
    }//end createFromArray()

    /**
     * Update action from array
     *
     * @param int   $id   Action ID
     * @param array $data Action data
     *
     * @return Action
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function updateFromArray(int $id, array $data): Action
    {
        $action = $this->find(id: $id);
        $action->hydrate($data);

        return $this->update(entity: $action);
    }//end updateFromArray()

    /**
     * Check if the actions table exists
     *
     * @return bool
     */
    private function tableExists(): bool
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*)'))
                ->from($this->getTableName())
                ->setMaxResults(1);
            $qb->executeQuery();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }//end tableExists()
}//end class
