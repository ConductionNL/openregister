<?php

/**
 * OpenRegister Agent Mapper
 *
 * This file contains the AgentMapper class for database operations on agents.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

namespace OCA\OpenRegister\Mcp\Db;

use OCA\OpenRegister\Db\ListQuery;
use DateTime;
use OCA\OpenRegister\Kernel\Contracts\AgentMapperInterface;
use OCA\OpenRegister\Event\AgentCreatedEvent;
use OCA\OpenRegister\Event\AgentDeletedEvent;
use OCA\OpenRegister\Event\AgentUpdatedEvent;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IUserSession;
use Symfony\Component\Uid\Uuid;
use OCA\OpenRegister\Db\MultiTenancyTrait;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Db\QueryPage;

/**
 * Class AgentMapper
 *
 * Mapper for Agent entities with multi-tenancy and RBAC support.
 *
 * @package OCA\OpenRegister\Db
 *
 * @template-extends QBMapper<Agent>
 * @method Agent insert(Entity $entity)
 * @method Agent update(Entity $entity)
 * @method Agent insertOrUpdate(Entity $entity)
 * @method Agent delete(Entity $entity)
 * @method Agent find(int|string $id)
 * @method Agent findEntity(IQueryBuilder $query)
 * @method list<Agent> findEntities(IQueryBuilder $query)
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AgentMapper extends QBMapper implements AgentMapperInterface {
	use MultiTenancyTrait;

	/**
	 * Organisation service for multi-tenancy
	 *
	 * @var OrganisationServiceInterface
	 */

	/**
	 * Event dispatcher for dispatching agent events
	 *
	 * @var IEventDispatcher
	 */
	private IEventDispatcher $eventDispatcher;

	/**
	 * Constructor
	 *
	 * The organisation mapper, user session and group manager are the
	 * multi-tenancy and RBAC inputs MultiTenancyTrait reads; they stay direct
	 * parameters (access guards are never bundled) and are promoted.
	 *
	 * @param AgentMapperPlatform         $platform           Database connection and event dispatcher
	 * @param OrganisationMapperInterface $organisationMapper Organisation mapper, for multi-tenancy
	 * @param IUserSession                $userSession        User session, for the current user
	 * @param IGroupManager               $groupManager       Group manager, for RBAC
	 */
	public function __construct(
		AgentMapperPlatform $platform,
		protected OrganisationMapperInterface $organisationMapper,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
	) {
		parent::__construct(db: $platform->db, tableName: 'openregister_agents', entityClass: Agent::class);
		$this->eventDispatcher = $platform->eventDispatcher;
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
	public function find(int $id): Agent {
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

		return $this->findEntity(query: $qb);
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
	public function findByUuid(string $uuid): Agent {
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

		return $this->findEntity(query: $qb);
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
	 * @param AgentAudience $audience The organisation and, for access filtering, the user (null = no filtering)
	 * @param ListQuery     $page     The limit and offset.
	 *
	 * @return (Agent|OCA\OpenRegister\Mcp\Db\Agent)[]
	 *
	 * @throws \Exception If user doesn't have read permission
	 *
	 * @psalm-return list<\OCA\OpenRegister\Mcp\Db\Agent>
	 */
	public function findByOrganisation(AgentAudience $audience, ListQuery $page): array {
		$this->verifyRbacPermission(action: 'read', entityType: 'agent');
		$query = $this->organisationPage(
			organisationUuid: $audience->organisationUuid,
			page: new QueryPage(limit: $page->limitOrNull(), offset: $page->offset)
		);
		// Without a user every agent in the organisation is returned; with one, only those it may access.
		return $this->filterByUserAccess(agents: $this->findEntities(query: $query), userId: $audience->userId);
	}//end findByOrganisation()

	/**
	 * The newest-first page of one organisation's agents.
	 *
	 * @param string    $organisationUuid Organisation UUID.
	 * @param QueryPage $page             The limit and offset.
	 *
	 * @return IQueryBuilder The query.
	 */
	private function organisationPage(string $organisationUuid, QueryPage $page): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('organisation', $qb->createNamedParameter($organisationUuid, IQueryBuilder::PARAM_STR)));
		$page->applyTo(qb: $qb);
		$qb->orderBy('created', 'DESC');

		return $qb;
	}//end organisationPage()

	/**
	 * Filter agents by user access rights
	 *
	 * @param Agent[]     $agents Array of agents to filter
	 * @param string|null $userId User ID, or null to keep every agent
	 *
	 * @return Agent[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Mcp\Db\Agent>
	 */
	private function filterByUserAccess(array $agents, ?string $userId): array {
		if ($userId === null) {
			return $agents;
		}

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
	 * @param Agent $agent Agent entity
	 * @param string $userId User ID
	 *
	 * @return bool True if user can access
	 */
	public function canUserAccessAgent(Entity $agent, string $userId): bool {
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
	 * @param Agent $agent Agent entity
	 * @param string $userId User ID
	 *
	 * @return bool True if user can modify
	 */
	public function canUserModifyAgent(Entity $agent, string $userId): bool {
		return $agent->getOwner() === $userId;
	}//end canUserModifyAgent()

	/**
	 * Find all agents with optional filters
	 *
	 * @param ListQuery $query The limit (ListQuery::unbounded() for none), offset, filter criteria and order.
	 *
	 * @return Agent[]
	 *
	 * @throws \Exception If user doesn't have read permission
	 *
	 * @psalm-return list<\OCA\OpenRegister\Mcp\Db\Agent>
	 */
	public function findAll(ListQuery $query): array {
		$this->verifyRbacPermission(action: 'read', entityType: 'agent');
		$qb = $this->filteredQuery(filters: $query->filters, order: $query->sort);
		(new QueryPage(limit: $query->limitOrNull(), offset: $query->offset))->applyTo(qb: $qb);
		return $this->findInOrganisation(qb: $qb);
	}//end findAll()

	/**
	 * An agent query with the filters and order applied.
	 *
	 * @param array|null $filters Filter criteria.
	 * @param array|null $order   Field to direction.
	 *
	 * @return IQueryBuilder The query.
	 */
	private function filteredQuery(?array $filters, ?array $order): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->tableName);

		$this->applyAgentFilters(qb: $qb, filters: $filters);
		$this->applyAgentOrder(qb: $qb, order: $order);

		return $qb;
	}//end filteredQuery()

	/**
	 * Run an agent query inside the active organisation.
	 *
	 * NULL organisation stays allowed for legacy and global agents.
	 *
	 * @param IQueryBuilder $qb The query.
	 *
	 * @return Agent[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Mcp\Db\Agent>
	 */
	private function findInOrganisation(IQueryBuilder $qb): array {
		$this->applyOrganisationFilter(
			qb: $qb,
			columnName: 'organisation',
			allowNullOrg: true
		);

		return $this->findEntities(query: $qb);
	}//end findInOrganisation()

	/**
	 * Order an agent read by the given fields, or newest first when none are given.
	 *
	 * NC 35 types addOrderBy() as (string|..., string|SortDirection|null): an
	 * int key (a list-shaped sort) or a non-string direction is a TypeError
	 * there, so both are normalised here.
	 *
	 * @param IQueryBuilder $qb    The builder to order.
	 * @param array|null    $order Field to direction.
	 *
	 * @return void
	 */
	private function applyAgentOrder(IQueryBuilder $qb, ?array $order): void {
		if (empty($order) === true) {
			$qb->orderBy('created', 'DESC');
			return;
		}

		foreach ($order as $field => $direction) {
			$qb->addOrderBy((string)$field, $this->normaliseSortDirection(direction: $direction));
		}
	}//end applyAgentOrder()

	/**
	 * Insert a new agent
	 *
	 * @param Agent $entity Agent entity to insert
	 *
	 * @return Agent The inserted agent with updated ID
	 * @throws \Exception If user doesn't have create permission
	 */
	public function insert(Entity $entity): Agent {
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
		$this->setOrganisationOnCreate(entity: $entity);

		$entity = parent::insert(entity: $entity);

		// Dispatch creation event.
		$this->eventDispatcher->dispatchTyped(new AgentCreatedEvent(agent: $entity));

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
	public function update(Entity $entity): Agent {
		// Verify RBAC permission to update.
		$this->verifyRbacPermission(
			action: 'update',
			entityType: 'agent'
		);

		// Verify user has access to this organisation.
		$this->verifyOrganisationAccess(entity: $entity);

		// Get old state before update.
		$oldEntity = $this->find(id: $entity->getId());

		$entity->setUpdated(new DateTime());

		$entity = parent::update(entity: $entity);

		// Dispatch update event.
		$this->eventDispatcher->dispatchTyped(new AgentUpdatedEvent(newAgent: $entity, oldAgent: $oldEntity));

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
	public function delete(Entity $entity): Entity {
		// Verify RBAC permission to delete.
		$this->verifyRbacPermission(
			action: 'delete',
			entityType: 'agent'
		);

		// Verify user has access to this organisation.
		$this->verifyOrganisationAccess(entity: $entity);

		$entity = parent::delete(entity: $entity);

		// Dispatch deletion event.
		$this->eventDispatcher->dispatchTyped(new AgentDeletedEvent(agent: $entity));

		return $entity;
	}//end delete()

	/**
	 * Create an agent from an array
	 *
	 * @param array $data The agent data
	 *
	 * @return Agent The created agent
	 */
	public function createFromArray(array $data): Agent {
		$agent = new Agent();
		$agent->hydrate($data);

		return $this->insert(entity: $agent);
	}//end createFromArray()

	/**
	 * Count agents with optional filters
	 *
	 * @param array|null $filters Filter criteria
	 *
	 * @return int Total count of agents
	 * @throws \Exception If user doesn't have read permission
	 */
	public function count(?array $filters = []): int {
		// Verify RBAC permission to read.
		$this->verifyRbacPermission(
			action: 'read',
			entityType: 'agent'
		);

		$qb = $this->db->getQueryBuilder();

		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->tableName);

		$this->applyAgentFilters(qb: $qb, filters: $filters);

		// Apply organisation filter (all users including admins must have active org).
		$this->applyOrganisationFilter(qb: $qb);

		return (int)$qb->executeQuery()->fetchOne();
	}//end count()

	/**
	 * Add a condition per agent filter, skipping null values and the `_route` key.
	 *
	 * `active` compares as a boolean, a list value as IN, anything else as a string.
	 *
	 * @param IQueryBuilder $qb      The builder to add the conditions to.
	 * @param array|null    $filters Column name to value.
	 *
	 * @return void
	 */
	private function applyAgentFilters(IQueryBuilder $qb, ?array $filters): void {
		foreach ($filters ?? [] as $field => $value) {
			if ($value === null || $field === '_route') {
				continue;
			}

			if ($field === 'active') {
				$qb->andWhere($qb->expr()->eq($field, $qb->createNamedParameter((bool)$value, IQueryBuilder::PARAM_BOOL)));
				continue;
			}

			if (is_array($value) === true) {
				$qb->andWhere($qb->expr()->in($field, $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR_ARRAY)));
				continue;
			}

			$qb->andWhere($qb->expr()->eq($field, $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR)));
		}
	}//end applyAgentFilters()

	/**
	 * Normalise a caller-supplied sort direction to 'ASC' or 'DESC'.
	 *
	 * NC 35 types IQueryBuilder::addOrderBy()'s direction as
	 * string|SortDirection|null, so a non-string value is a TypeError there.
	 * Anything that is not a case-insensitive 'DESC' sorts ascending.
	 *
	 * @param mixed $direction The requested direction
	 *
	 * @return string 'ASC' or 'DESC'
	 */
	private function normaliseSortDirection(mixed $direction): string {
		if (is_string($direction) === true && strtoupper($direction) === 'DESC') {
			return 'DESC';
		}

		return 'ASC';
	}//end normaliseSortDirection()
}//end class
