<?php

/**
 * OpenRegister Agent Mapper
 *
 * This file contains the AgentMapper class for database operations on agents.
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
use OCA\OpenRegister\Event\AgentCreatedEvent;
use OCA\OpenRegister\Event\AgentDeletedEvent;
use OCA\OpenRegister\Event\AgentUpdatedEvent;
use OCA\OpenRegister\Service\Configuration\CacheHandler;
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
use Symfony\Component\Uid\Uuid;

/**
 * Class AgentMapper
 *
 * Mapper for Agent entities with multi-tenancy and RBAC support.
 *
 * @package OCA\OpenRegister\Db
 *
 * @template-extends QBMapper<Agent>
 * @method           Agent insert(Entity $entity)
 * @method           Agent update(Entity $entity)
 * @method           Agent insertOrUpdate(Entity $entity)
 * @method           Agent delete(Entity $entity)
 * @method           Agent find(int|string $id)
 * @method           Agent findEntity(IQueryBuilder $query)
 * @method           Agent[] findAll(int|null $limit=null, int|null $offset=null)
 * @method           list<Agent> findEntities(IQueryBuilder $query)
 */
class AgentMapper extends QBMapper
{
    use MultiTenancyTrait;

    /**
     * Organisation service for multi-tenancy
     *
     * @var OrganisationService
     */

    /**
     * Organisation mapper for organisation-related operations.
     *
     * @var OrganisationMapper
     */
    protected OrganisationMapper $organisationMapper;

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
     * Event dispatcher for dispatching agent events
     *
     * @var IEventDispatcher
     */
    private IEventDispatcher $eventDispatcher;

    /**
     * Constructor
     *
     * @param IDBConnection      $db                 Database connection
     * @param OrganisationMapper $organisationMapper Organisation mapper
     * @param IUserSession       $userSession        User session
     * @param IGroupManager      $groupManager       Group manager
     * @param IEventDispatcher   $eventDispatcher    Event dispatcher
     */
    public function __construct(
        IDBConnection $db,
        // REMOVED: Services should not be in mappers.
        OrganisationMapper $organisationMapper,
        IUserSession $userSession,
        IGroupManager $groupManager,
        IEventDispatcher $eventDispatcher
    ) {
        parent::__construct($db, 'openregister_agents', Agent::class);
        // REMOVED: Services should not be in mappers.
        $this->organisationMapper = $organisationMapper;
        $this->userSession        = $userSession;
        $this->groupManager       = $groupManager;
        $this->eventDispatcher    = $eventDispatcher;
    }//end __construct()

    /**
     * Find an agent by its ID
     *
     * @param int $id Agent ID
     *
     * @return Agent The agent entity
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     * @throws \Exception If user doesn't have read permission
     */
    public function find(int $id): Agent
    {
        // Verify RBAC permission to read.
        $this->verifyRbacPermission(
            action: 'read',
            entityType: 'agent'
        );

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        // Apply organisation filter, allowing NULL organisation for legacy/global agents.
        $this->applyOrganisationFilter(
            qb: $qb,
            columnName: 'organisation',
            allowNullOrg: true
        );

        return $this->findEntity($qb);
    }//end find()

    /**
     * Find an agent by its UUID
     *
     * @param string $uuid Agent UUID
     *
     * @return Agent The agent entity
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     * @throws \Exception If user doesn't have read permission
     */
    public function findByUuid(string $uuid): Agent
    {
        // Verify RBAC permission to read.
        $this->verifyRbacPermission(
            action: 'read',
            entityType: 'agent'
        );

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));

        // Apply organisation filter, allowing NULL organisation for legacy/global agents.
        $this->applyOrganisationFilter(
            qb: $qb,
            columnName: 'organisation',
            allowNullOrg: true
        );

        return $this->findEntity($qb);
    }//end findByUuid()

    /**
     * Find agents accessible by a user in an organisation
     *
     * Filters agents based on:
     * - Agents in the organisation
     * - Non-private agents (is_private = false)
     * - Private agents owned by the user
     * - Private agents where user is invited
     *
     * @param string      $organisationUuid Organisation UUID
     * @param string|null $userId           User ID for access filtering (null = no filtering)
     * @param int         $limit            Maximum number of results
     * @param int         $offset           Offset for pagination
     *
     * @return (Agent|OCA\OpenRegister\Db\Agent)[]
     *
     * @throws \Exception If user doesn't have read permission
     *
     * @psalm-return list<\OCA\OpenRegister\Db\Agent>
     */
    public function findByOrganisation(string $organisationUuid, ?string $userId=null, int $limit=50, int $offset=0): array
    {
        // Verify RBAC permission to read.
        $this->verifyRbacPermission(
            action: 'read',
            entityType: 'agent'
        );

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('organisation', $qb->createNamedParameter($organisationUuid, IQueryBuilder::PARAM_STR)))
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('created', 'DESC');

        // If no user provided, return all agents in the organisation.
        if ($userId === null) {
            return $this->findEntities($qb);
        }

        // Filter results by user access rights.
        $allAgents = $this->findEntities($qb);
        return $this->filterByUserAccess(
            agents: $allAgents,
            userId: $userId
        );
    }//end findByOrganisation()

    /**
     * Filter agents by user access rights
     *
     * @param Agent[] $agents Array of agents to filter
     * @param string  $userId User ID
     *
     * @return Agent[]
     *
     * @psalm-return list<\OCA\OpenRegister\Db\Agent>
     */
    private function filterByUserAccess(array $agents, string $userId): array
    {
        $accessible = [];

        foreach ($agents as $agent) {
            if ($this->canUserAccessAgent(agent: $agent, userId: $userId) === true) {
                $accessible[] = $agent;
            }
        }

        return $accessible;
    }//end filterByUserAccess()

    /**
     * Check if user can access an agent
     *
     * Access rules:
     * - Non-private agents: anyone in the organisation can access
     * - Private agents: only owner or invited users can access
     *
     * @param Agent  $agent  Agent entity
     * @param string $userId User ID
     *
     * @return bool True if user can access
     */
    public function canUserAccessAgent(Agent $agent, string $userId): bool
    {
        // Non-private agents are accessible to all users in the organisation.
        if ($agent->getIsPrivate() === false || $agent->getIsPrivate() === null) {
            return true;
        }

        // Owner always has access.
        if ($agent->getOwner() === $userId) {
            return true;
        }

        // Check if user is invited.
        if ($agent->hasInvitedUser($userId) === true) {
            return true;
        }

        return false;
    }//end canUserAccessAgent()

    /**
     * Check if user can modify an agent
     *
     * Modification rules:
     * - Only the owner can modify the agent
     *
     * @param Agent  $agent  Agent entity
     * @param string $userId User ID
     *
     * @return bool True if user can modify
     */
    public function canUserModifyAgent(Agent $agent, string $userId): bool
    {
        return $agent->getOwner() === $userId;
    }//end canUserModifyAgent()

    /**
     * Find all agents with optional filters
     *
     * @param int|null   $limit   Maximum number of results
     * @param int|null   $offset  Offset for pagination
     * @param array|null $filters Filter criteria
     * @param array|null $order   Order by criteria
     *
     * @return Agent[]
     *
     * @throws \Exception If user doesn't have read permission
     *
     * @psalm-return list<OCA\OpenRegister\Db\Agent>
     */
    public function findAll(?int $limit=null, ?int $offset=null, ?array $filters=[], ?array $order=[]): array
    {
        // Verify RBAC permission to read.
        $this->verifyRbacPermission(
            action: 'read',
            entityType: 'agent'
        );

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName);

        // Apply filters.
        if (empty($filters) === false) {
            foreach ($filters as $field => $value) {
                if ($value !== null && $field !== '_route') {
                    if ($field === 'active') {
                        $qb->andWhere(
                            $qb->expr()->eq($field, $qb->createNamedParameter((bool) $value, IQueryBuilder::PARAM_BOOL))
                        );
                        continue;
                    }
                    if (is_array($value) === true) {
                        $qb->andWhere(
                            $qb->expr()->in($field, $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR_ARRAY))
                        );
                        continue;
                    }
                    $qb->andWhere($qb->expr()->eq($field, $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR)));
                }
            }
        }

        // Apply ordering.
        if (empty($order) === false) {
            foreach ($order as $field => $direction) {
                $qb->addOrderBy($field, $direction);
            }
        }
        if (empty($order) === true) {
            $qb->orderBy('created', 'DESC');
        }

        // Apply pagination.
        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        // Apply organisation filter, allowing NULL organisation for legacy/global agents.
        $this->applyOrganisationFilter(
            qb: $qb,
            columnName: 'organisation',
            allowNullOrg: true
        );

        return $this->findEntities($qb);
    }//end findAll()

    /**
     * Insert a new agent
     *
     * @param Agent $entity Agent entity to insert
     *
     * @return Agent The inserted agent with updated ID
     * @throws \Exception If user doesn't have create permission
     */
    public function insert(Entity $entity): Agent
    {
        // Verify RBAC permission to create.
        $this->verifyRbacPermission(
            action: 'create',
            entityType: 'agent'
        );

        /*
         * @var Agent $entity
         */

        if ($entity instanceof Agent) {
            // Ensure UUID is set.
            $uuid = $entity->getUuid();
            if ($uuid === null || $uuid === '' || trim($uuid) === '') {
                $newUuid = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
                $entity->setUuid($newUuid);
            }

            // Set timestamps if not already set.
            if ($entity->getCreated() === null) {
                $entity->setCreated(new DateTime());
            }

            if ($entity->getUpdated() === null) {
                $entity->setUpdated(new DateTime());
            }
        }

        // Auto-set organisation from active session.
        $this->setOrganisationOnCreate($entity);

        $entity = parent::insert($entity);

        // Dispatch creation event.
        $this->eventDispatcher->dispatchTyped(new AgentCreatedEvent($entity));

        return $entity;
    }//end insert()

    /**
     * Update an existing agent
     *
     * @param Agent $entity Agent entity to update
     *
     * @return Agent The updated agent
     * @throws \Exception If user doesn't have update permission or access to this organisation
     */
    public function update(Entity $entity): Agent
    {
        // Verify RBAC permission to update.
        $this->verifyRbacPermission(
            action: 'update',
            entityType: 'agent'
        );

        // Verify user has access to this organisation.
        $this->verifyOrganisationAccess($entity);

        // Get old state before update.
        $oldEntity = $this->find(id: $entity->getId());

        $entity->setUpdated(new DateTime());

        $entity = parent::update($entity);

        // Dispatch update event.
        $this->eventDispatcher->dispatchTyped(new AgentUpdatedEvent($entity, $oldEntity));

        return $entity;
    }//end update()

    /**
     * Delete an agent
     *
     * @param Agent $entity Agent entity to delete
     *
     * @return Agent The deleted agent
     * @throws \Exception If user doesn't have delete permission or access to this organisation
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function delete(Entity $entity): Entity
    {
        // Verify RBAC permission to delete.
        $this->verifyRbacPermission(
            action: 'delete',
            entityType: 'agent'
        );

        // Verify user has access to this organisation.
        $this->verifyOrganisationAccess($entity);

        $entity = parent::delete($entity);

        // Dispatch deletion event.
        $this->eventDispatcher->dispatchTyped(new AgentDeletedEvent($entity));

        return $entity;
    }//end delete()

    /**
     * Create an agent from an array
     *
     * @param array $data The agent data
     *
     * @return Agent The created agent
     */
    public function createFromArray(array $data): Agent
    {
        $agent = new Agent();
        $agent->hydrate($data);

        return $this->insert($agent);
    }//end createFromArray()

    /**
     * Count agents with optional filters
     *
     * @param array|null $filters Filter criteria
     *
     * @return int Total count of agents
     * @throws \Exception If user doesn't have read permission
     */
    public function count(?array $filters=[]): int
    {
        // Verify RBAC permission to read.
        $this->verifyRbacPermission(
            action: 'read',
            entityType: 'agent'
        );

        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->tableName);

        // Apply filters.
        if (empty($filters) === false) {
            foreach ($filters as $field => $value) {
                if ($value !== null && $field !== '_route') {
                    if ($field === 'active') {
                        $qb->andWhere(
                            $qb->expr()->eq($field, $qb->createNamedParameter((bool) $value, IQueryBuilder::PARAM_BOOL))
                        );
                        continue;
                    }
                    if (is_array($value) === true) {
                        $qb->andWhere(
                            $qb->expr()->in($field, $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR_ARRAY))
                        );
                        continue;
                    }
                    $qb->andWhere($qb->expr()->eq($field, $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR)));
                }
            }
        }

        // Apply organisation filter (all users including admins must have active org).
        $this->applyOrganisationFilter($qb);

        return (int) $qb->executeQuery()->fetchOne();
    }//end count()
}//end class
