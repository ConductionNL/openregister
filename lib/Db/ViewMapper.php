<?php

/**
 * OpenRegister View Mapper
 *
 * This file contains the class for handling view mapper related operations
 * in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use DateTime;
use OCA\OpenRegister\Event\ViewCreatedEvent;
use OCA\OpenRegister\Event\ViewDeletedEvent;
use OCA\OpenRegister\Event\ViewUpdatedEvent;
use OCA\OpenRegister\Service\Rbac\ViewShareResolver;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use Symfony\Component\Uid\Uuid;

/**
 * ViewMapper handles database operations for View entities
 *
 * Mapper for View entities with multi-tenancy and RBAC support.
 * Extends QBMapper to provide standard CRUD operations with access control.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @method View insert(Entity $entity)
 * @method View update(Entity $entity)
 * @method View insertOrUpdate(Entity $entity)
 * @method View delete(Entity $entity)
 * @method View findEntity(IQueryBuilder $query)
 * @method list<View> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<View>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class ViewMapper extends QBMapper {
	use MultiTenancyTrait;

	/**
	 * Organisation mapper for multi-tenancy (from trait)
	 *
	 * Used to get active organisation UUID and apply organisation filters.
	 *
	 * @var OrganisationMapper Organisation mapper instance
	 */
	protected OrganisationMapper $organisationMapper;

	/**
	 * App configuration for multitenancy settings (from trait)
	 *
	 * Used by MultiTenancyTrait to check multitenancy configuration and
	 * resolve the default organisation UUID when no session org is set.
	 *
	 * @var IAppConfig App configuration instance
	 */
	protected IAppConfig $appConfig;

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
	 * Event dispatcher for dispatching view events
	 *
	 * Used to dispatch ViewCreatedEvent, ViewUpdatedEvent, and ViewDeletedEvent.
	 *
	 * @var IEventDispatcher Event dispatcher instance
	 */
	private readonly IEventDispatcher $eventDispatcher;

	/**
	 * Constructor
	 *
	 * Initializes mapper with database connection and multi-tenancy/RBAC dependencies.
	 * Calls parent constructor to set up base mapper functionality.
	 *
	 * @param IDBConnection $db Database connection
	 * @param OrganisationMapper $organisationMapper Organisation mapper for multi-tenancy
	 * @param IAppConfig $appConfig App configuration for multitenancy settings
	 * @param IUserSession $userSession User session for RBAC
	 * @param IGroupManager $groupManager Group manager for RBAC
	 * @param IEventDispatcher $eventDispatcher Event dispatcher for view lifecycle events
	 *
	 * @return void
	 */
	public function __construct(
		IDBConnection $db,
		OrganisationMapper $organisationMapper,
		IAppConfig $appConfig,
		IUserSession $userSession,
		IGroupManager $groupManager,
		IEventDispatcher $eventDispatcher,
	) {
		// Call parent constructor to initialize base mapper with table name and entity class.
		parent::__construct(db: $db, tableName: 'openregister_views', entityClass: View::class);

		// Store dependencies for use in mapper methods.
		$this->organisationMapper = $organisationMapper;
		$this->appConfig = $appConfig;
		$this->userSession = $userSession;
		$this->groupManager = $groupManager;
		$this->eventDispatcher = $eventDispatcher;
	}//end __construct()

	/**
	 * Views carrying an alert, oldest evaluation first.
	 *
	 * 🔑 THE ORDER IS THE WATERMARK. Taking the least recently evaluated views
	 * means a bounded pass walks the whole set over several ticks instead of
	 * re-counting the same busy ones, so no view starves behind a neighbour.
	 * A view never evaluated sorts first, which is what makes an alert somebody
	 * set a minute ago run on the next pass.
	 *
	 * @param int $limit Most views to return.
	 *
	 * @return View[] The views.
	 *
	 * @psalm-return array<int, View>
	 *
	 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-the-alert-sweep-is-bounded
	 */
	public function findWithAlerts(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('alert'))
			->orderBy('alert_evaluated_at', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findWithAlerts()


	/**
	 * Find a view by its ID
	 *
	 * Retrieves view by ID (supports both integer ID and UUID) with RBAC and
	 * organisation filtering. Verifies user has read permission before querying.
	 *
	 * @param int|string $id The ID (integer) or UUID (string) of the view to find
	 *
	 * @return View The found view entity
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If view not found or not accessible
	 * @throws \Exception If user doesn't have read permission for views
	 */
	public function find($id): View {
		// Step 1: Verify RBAC permission to read views.
		// Throws exception if user doesn't have required permissions.
		$this->verifyRbacPermission(action: 'read', entityType: 'view');

		// Step 2: Get query builder instance.
		$qb = $this->db->getQueryBuilder();

		// Step 3: Build SELECT query with ID or UUID filter.
		// Supports both integer IDs and UUID strings for flexibility.
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->orX(
					$qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)),
					$qb->expr()->eq('uuid', $qb->createNamedParameter($id, IQueryBuilder::PARAM_STR))
				)
			);

		// Step 4: Apply organisation filter for multi-tenancy.
		// All users including admins must have active organisation.
		$this->applyOrganisationFilter(qb: $qb);

		$entity = $this->findEntity(query: $qb);

		// Enrich with configuration management info.
		$this->enrichWithConfigurationInfo(view: $entity);

		return $entity;
	}//end find()

	/**
	 * Find all views for a specific owner
	 *
	 * @param string $owner The owner user ID
	 *
	 * @return View[]
	 *
	 * @throws \Exception If user doesn't have read permission
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\View>
	 */
	public function findAll(?string $owner = null): array {
		// Verify RBAC permission to read.
		$this->verifyRbacPermission(action: 'read', entityType: 'view');

		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName());

		if ($owner !== null) {
			$qb->where(
				$qb->expr()->orX(
					$qb->expr()->eq('owner', $qb->createNamedParameter($owner, IQueryBuilder::PARAM_STR)),
					$qb->expr()->eq('is_public', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
				)
			);
		}

		$qb->orderBy('created', 'DESC');

		// Apply organisation filter (all users including admins must have active org).
		$this->applyOrganisationFilter(qb: $qb);

		$entities = $this->findEntities(query: $qb);

		// Enrich all entities with configuration management info.
		foreach ($entities as $entity) {
			$this->enrichWithConfigurationInfo(view: $entity);
		}

		return $entities;
	}//end findAll()

	/**
	 * Every view this caller may see, each carrying the access they hold.
	 *
	 * The union `view-group-share` asks for: the caller's own views, the views
	 * shared with a group they are in, and the public ones.
	 *
	 * 🔑 THE GROUP MATCH IS DONE IN PHP AND THAT IS DELIBERATE. `shared_with`
	 * is JSON in a TEXT column, and the portable ways to match inside it are a
	 * `LIKE '%"group":"x"%'` — which matches a group whose name is a substring
	 * of another, and breaks the day the encoder emits a space after the colon
	 * — or a backend-specific JSON operator, which is four spellings that have
	 * to agree forever on a question about authorization. The row count makes
	 * the choice free: a view list is tens of rows per organisation, not
	 * millions, so the SQL narrows to "mine, public, or shared with anybody"
	 * and this walks what comes back.
	 *
	 * A view the caller may not see is DROPPED rather than returned without an
	 * access: a row with a null access reaching a client is a row somebody
	 * renders.
	 *
	 * @param string $userId The caller.
	 * @param string[] $userGroups The caller's group ids.
	 * @param bool $isAdmin Whether the caller administers the instance.
	 *
	 * @return View[] The views, each with its `access` set.
	 *
	 * @spec openspec/changes/view-group-share/specs/saved-search-views/spec.md
	 */
	public function findAllFor(string $userId, array $userGroups, bool $isAdmin = false): array {
		$this->verifyRbacPermission(action: 'read', entityType: 'view');

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->orX(
					$qb->expr()->eq('owner', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)),
					$qb->expr()->eq('is_public', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)),
					$qb->expr()->isNotNull('shared_with')
				)
			)
			->orderBy('created', 'DESC');

		$this->applyOrganisationFilter(qb: $qb);

		$resolver = new ViewShareResolver();
		$visible = [];
		foreach ($this->findEntities(query: $qb) as $entity) {
			$view = $entity->jsonSerialize();

			$access = $resolver->accessFor(
				view: $view,
				userId: $userId,
				userGroups: $userGroups
			);

			// An administrator reaches every view, and reaches it AS an
			// administrator rather than as its owner: `accessFor()` answers what
			// the view grants, and calling that `owner` would put a level on a
			// row they cannot hand back.
			if ($access === null) {
				if ($isAdmin === false) {
					continue;
				}

				$access = ViewShareResolver::ACCESS_WRITE;
			}

			$entity->setAccess($access);
			$this->enrichWithConfigurationInfo(view: $entity);
			$visible[] = $entity;
		}

		return $visible;
	}//end findAllFor()

	/**
	 * Create a new view from an Entity
	 *
	 * @param Entity $entity The view entity to create
	 *
	 * @return View The created view
	 * @throws \Exception If user doesn't have create permission
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is standard Symfony UID pattern
	 */
	public function insert(Entity $entity): View {
		// Verify RBAC permission to create.
		$this->verifyRbacPermission(action: 'create', entityType: 'view');

		// Generate UUID if not present.
		if (empty($entity->getUuid()) === true) {
			$entity->setUuid((string)Uuid::v4());
		}

		// Set timestamps.
		$entity->setCreated(new DateTime());
		$entity->setUpdated(new DateTime());

		// Auto-set organisation from active session.
		$this->setOrganisationOnCreate(entity: $entity);

		$entity = parent::insert(entity: $entity);

		// Dispatch creation event.
		$this->eventDispatcher->dispatchTyped(new ViewCreatedEvent(view: $entity));

		return $entity;
	}//end insert()

	/**
	 * Update an existing view
	 *
	 * @param Entity $entity The view entity to update
	 *
	 * @return View The updated view
	 * @throws \Exception If user doesn't have update permission or access to this organisation
	 */
	public function update(Entity $entity): View {
		// Verify RBAC permission to update.
		$this->verifyRbacPermission(action: 'update', entityType: 'view');

		// Verify user has access to this organisation.
		$this->verifyOrganisationAccess(entity: $entity);

		// Get old state before update.
		$oldEntity = $this->find(id: $entity->getId());

		// Update timestamp.
		$entity->setUpdated(new DateTime());

		$entity = parent::update(entity: $entity);

		// Dispatch update event.
		$this->eventDispatcher->dispatchTyped(new ViewUpdatedEvent(newView: $entity, oldView: $oldEntity));

		return $entity;
	}//end update()

	/**
	 * Delete a view
	 *
	 * @param Entity $entity The view entity to delete
	 *
	 * @return View The deleted view
	 * @throws \Exception If user doesn't have delete permission or access to this organisation
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 */
	public function delete(Entity $entity): View {
		// Verify RBAC permission to delete.
		$this->verifyRbacPermission(action: 'delete', entityType: 'view');

		// Verify user has access to this organisation.
		$this->verifyOrganisationAccess(entity: $entity);

		$entity = parent::delete(entity: $entity);

		// Dispatch deletion event.
		$this->eventDispatcher->dispatchTyped(new ViewDeletedEvent(view: $entity));

		return $entity;
	}//end delete()

	/**
	 * Enrich a view entity with configuration management information
	 *
	 * This method fetches configurations for the active organisation and checks
	 * if this view is managed by any configuration. If so, it sets the managedByConfiguration
	 * property on the entity.
	 *
	 * @param View $view The view entity to enrich
	 *
	 * @psalm-suppress UnusedParam Method is kept as no-op for API compatibility
	 *
	 * @return void
	 */
	private function enrichWithConfigurationInfo(View $view): void {
		// NOTE: Configuration enrichment disabled - configurationCacheService was removed from mapper.
		// Services should not be in mappers. Configuration enrichment should be done at the service layer.
		// This method is kept as a no-op to avoid breaking existing code that calls it.
	}//end enrichWithConfigurationInfo()
}//end class
