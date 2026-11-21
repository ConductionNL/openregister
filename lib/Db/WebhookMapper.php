<?php

/**
 * OpenRegister Webhook Mapper
 *
 * Mapper for Webhook entities to handle database operations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use DateTime;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCA\OpenRegister\Db\Support\SecretAtRest;
use OCA\OpenRegister\Notifications\Service\Webhook\WebhookInterceptionCacheInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use Symfony\Component\Uid\Uuid;

/**
 * WebhookMapper handles database operations for Webhook entities
 *
 * Mapper for Webhook entities to handle database operations with multi-tenancy
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
 * @method Webhook insert(Entity $entity)
 * @method Webhook update(Entity $entity)
 * @method Webhook insertOrUpdate(Entity $entity)
 * @method Webhook delete(Entity $entity)
 * @method Webhook find(int $id)
 * @method Webhook findEntity(IQueryBuilder $query)
 * @method Webhook[] findAll(int|null $limit=null, int|null $offset=null, array|null $filters=[])
 * @method list<Webhook> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<Webhook>
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Webhook dispatching requires event and HTTP dependencies
 */
class WebhookMapper extends QBMapper implements WebhookMapperInterface {

	use ColumnFiltersTrait;
	use MultiTenancyTrait;

	/**
	 * Organisation mapper for multi-tenancy
	 *
	 * Used to get active organisation and apply organisation filters.
	 *
	 * @var OrganisationMapperInterface Organisation mapper instance
	 */
	protected OrganisationMapperInterface $organisationMapper;

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
	 * Constructor
	 *
	 * Initializes mapper with database connection and multi-tenancy/RBAC dependencies.
	 * Calls parent constructor to set up base mapper functionality.
	 *
	 * @param IDBConnection                          $db                Database connection
	 * @param TenancyServices                        $tenancy           Organisation mapper, user session and groups for multi-tenancy and RBAC
	 * @param IAppConfig                             $appConfig         App configuration for multitenancy settings, read by MultiTenancyTrait
	 * @param WebhookInterceptionCacheInterface|null $interceptionCache Interception-flag cache invalidated on webhook CRUD;
	 *                                                                  null skips invalidation (no cache backend)
	 * @param SecretAtRest|null                      $secrets           Encrypts the signing secret at rest; null stores it as given
	 *
	 * @return void
	 */
	public function __construct(
		IDBConnection $db,
		TenancyServices $tenancy,
		protected IAppConfig $appConfig,
		private readonly ?WebhookInterceptionCacheInterface $interceptionCache = null,
		private readonly ?SecretAtRest $secrets = null,
	) {
		parent::__construct(db: $db, tableName: 'openregister_webhooks', entityClass: Webhook::class);
		$this->organisationMapper = $tenancy->organisationMapper;
		$this->userSession = $tenancy->userSession;
		$this->groupManager = $tenancy->groupManager;
	}//end __construct()

	/**
	 * Find all webhooks
	 *
	 * Retrieves all webhooks with organisation filtering for multi-tenancy.
	 * Returns only webhooks belonging to the current organisation.
	 * Supports pagination and column filtering; the listing's sort and search are not applied.
	 *
	 * @param ListQuery $query The page (limitOrNull() and offset) and the column filters.
	 *
	 * @return Webhook[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Webhook>
	 */
	public function findAll(ListQuery $query): array {
		// Check if table exists before querying (migrations might not have run yet).
		if ($this->tableExists() === false) {
			return [];
		}

		$page = new QueryPage(limit: $query->limitOrNull(), offset: $query->offset);
		return $this->findEntities(query: $this->listQuery(page: $page, filters: $query->filters));
	}//end findAll()

	/**
	 * The listing query: one page, the column filters, and the organisation filter for multi-tenancy.
	 *
	 * @param QueryPage $page    The page to take.
	 * @param array     $filters Column filters to apply.
	 *
	 * @return IQueryBuilder The query, ready to run.
	 */
	private function listQuery(QueryPage $page, array $filters): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName());
		$page->applyTo(qb: $qb);
		$this->applyColumnFilters(qb: $qb, filters: $filters);

		// Users only see webhooks from their organisation.
		$this->applyOrganisationFilter(qb: $qb);

		return $qb;
	}//end listQuery()

	/**
	 * Find a single webhook by ID
	 *
	 * Retrieves webhook by ID with organisation filtering for multi-tenancy.
	 * Throws exception if webhook not found or doesn't belong to current organisation.
	 *
	 * @param int $id Webhook ID to find
	 *
	 * @return Webhook The found webhook entity
	 *
	 * @throws DoesNotExistException If webhook not found or not accessible
	 * @throws MultipleObjectsReturnedException If multiple webhooks found (should not happen)
	 */
	public function find(int $id): Webhook {
		// Check if table exists before querying (migrations might not have run yet).
		if ($this->tableExists() === false) {
			throw new DoesNotExistException('Webhook table does not exist. Please run migrations.');
		}

		// Step 1: Get query builder instance.
		$qb = $this->db->getQueryBuilder();

		// Step 2: Build SELECT query with ID filter.
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		// Step 3: Apply organisation filter for multi-tenancy.
		// This ensures users can only access webhooks from their organisation.
		$this->applyOrganisationFilter(qb: $qb);

		// Step 4: Execute query and return single entity.
		return $this->findEntity(query: $qb);
	}//end find()

	/**
	 * Find all enabled webhooks
	 *
	 * Retrieves all enabled webhooks with organisation filtering for multi-tenancy.
	 * Only returns webhooks that are currently enabled and belong to current organisation.
	 *
	 * @return Webhook[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Webhook>
	 */
	public function findEnabled(): array {
		// Check if table exists before querying (migrations might not have run yet).
		if ($this->tableExists() === false) {
			return [];
		}

		// Step 1: Get query builder instance.
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

		// Apply organisation filter.
		$this->applyOrganisationFilter(qb: $qb);

		return $this->findEntities(query: $qb);
	}//end findEnabled()

	/**
	 * The webhooks one user owns, in the active organisation.
	 *
	 * An administrator's webhook has no owner and is never among them. An
	 * empty user id owns nothing.
	 *
	 * @param string $uid The owner's user id.
	 *
	 * @return Webhook[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Webhook>
	 *
	 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-a-person-with-the-right-may-own-webhook-subscriptions
	 */
	public function findOwnedBy(string $uid): array {
		if (trim($uid) === '' || $this->tableExists() === false) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner', $qb->createNamedParameter($uid)))
			->orderBy('id', 'ASC');

		$this->applyOrganisationFilter(qb: $qb);

		return $this->findEntities(query: $qb);
	}//end findOwnedBy()

	/**
	 * Find all enabled webhooks WITHOUT organisation filtering
	 *
	 * Tenant-agnostic variant of findEnabled() used exclusively to compute
	 * the global "has interception webhooks for event X" cache flag. The
	 * flag must consider ALL organisations: a per-tenant "no webhooks"
	 * answer cached globally would silently disable another tenant's
	 * interception hooks. Callers that deliver webhooks must still use the
	 * organisation-filtered findEnabled()/findForEvent() paths.
	 *
	 * @return Webhook[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Webhook>
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md#request-interception-must-support-pre-event-webhooks
	 */
	public function findEnabledForInterceptionScan(): array {
		// Check if table exists before querying (migrations might not have run yet).
		if ($this->tableExists() === false) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

		// Deliberately NO organisation filter — see method docblock.
		return $this->findEntities(query: $qb);
	}//end findEnabledForInterceptionScan()

	/**
	 * Find webhooks that match an event
	 *
	 * @param string $eventClass Event class name
	 *
	 * @return Webhook[]
	 *
	 * @psalm-return array<int<0, max>, Webhook>
	 */
	public function findForEvent(string $eventClass): array {
		// Get all enabled webhooks.
		$webhooks = $this->findEnabled();

		// Filter webhooks that match the event.
		return array_filter(
			$webhooks,
			function ($webhook) use ($eventClass) {
				return $webhook->matchesEvent($eventClass);
			}
		);
	}//end findForEvent()

	/**
	 * Insert a new webhook
	 *
	 * @param Entity $entity Webhook entity to insert
	 *
	 * @return Webhook The inserted webhook
	 * @throws \Exception
	 */
	public function insert(Entity $entity): Entity {
		// Verify RBAC permission to create.
		$this->verifyRbacPermission(action: 'create', entityType: 'webhook');

		if ($entity instanceof Webhook) {
			// Generate UUID if not set.
			if (empty($entity->getUuid()) === true) {
				$entity->setUuid(Uuid::v4()->toRfc4122());
			}

			$entity->setCreated(new DateTime());
			$entity->setUpdated(new DateTime());
		}

		// Auto-set organisation from active session.
		$this->setOrganisationOnCreate(entity: $entity);

		$this->sealSecret(entity: $entity);
		try {
			$inserted = parent::insert(entity: $entity);
		} finally {
			$this->openSecret(entity: $entity);
		}

		// A new webhook can introduce interception for any event type.
		$this->interceptionCache?->invalidate();

		return $inserted;
	}//end insert()

	/**
	 * Update an existing webhook
	 *
	 * @param Entity $entity Webhook entity to update
	 *
	 * @return Webhook The updated webhook
	 * @throws \Exception
	 */
	public function update(Entity $entity): Entity {
		// Verify RBAC permission to update.
		$this->verifyRbacPermission(action: 'update', entityType: 'webhook');

		// Verify user has access to this organisation.
		$this->verifyOrganisationAccess(entity: $entity);

		if ($entity instanceof Webhook) {
			$entity->setUpdated(new DateTime());
		}

		$this->sealSecret(entity: $entity);
		try {
			$updated = parent::update(entity: $entity);
		} finally {
			$this->openSecret(entity: $entity);
		}

		// Enabled/events/configuration may have changed interception applicability.
		$this->interceptionCache?->invalidate();

		return $updated;
	}//end update()

	/**
	 * Seal a webhook's secret before it is written.
	 *
	 * @param Entity $entity The webhook.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function sealSecret(Entity $entity): void {
		if ($this->secrets !== null && $entity instanceof Webhook) {
			$entity->setSecret($this->secrets->seal(value: $entity->getSecret()));
		}
	}//end sealSecret()

	/**
	 * Open a webhook's secret again after it was written, so the caller keeps using it.
	 *
	 * @param Entity $entity The webhook.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function openSecret(Entity $entity): void {
		if ($this->secrets !== null && $entity instanceof Webhook && $entity->getSecret() !== null) {
			$entity->setSecret($this->secrets->open(value: $entity->getSecret()) ?? SecretAtRest::UNREADABLE);
		}
	}//end openSecret()

	/**
	 * A stored row as a webhook, with its secret opened.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return Entity The webhook.
	 */
	protected function mapRowToEntity(array $row): Entity {
		if ($this->secrets !== null && isset($row['secret']) === true) {
			$row['secret'] = ($this->secrets->open(value: (string)$row['secret']) ?? SecretAtRest::UNREADABLE);
		}

		return parent::mapRowToEntity(row: $row);
	}//end mapRowToEntity()

	/**
	 * Delete a webhook
	 *
	 * @param Entity $entity Webhook entity to delete
	 *
	 * @return Webhook The deleted webhook
	 * @throws \Exception
	 */
	public function delete(Entity $entity): Entity {
		// Verify RBAC permission to delete.
		$this->verifyRbacPermission(action: 'delete', entityType: 'webhook');

		// Verify user has access to this organisation.
		$this->verifyOrganisationAccess(entity: $entity);

		$deleted = parent::delete(entity: $entity);

		// Removing a webhook can drop the last interception hook for an event type.
		$this->interceptionCache?->invalidate();

		return $deleted;
	}//end delete()

	/**
	 * Update webhook statistics
	 *
	 * @param Webhook $webhook Webhook to update
	 * @param bool $success Was delivery successful
	 *
	 * @return Webhook
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Boolean flags control update behavior
	 */
	public function updateStatistics(Webhook $webhook, bool $success): Webhook {
		$webhook->setTotalDeliveries($webhook->getTotalDeliveries() + 1);
		$webhook->setLastTriggeredAt(new DateTime());

		if ($success === true) {
			$webhook->setSuccessfulDeliveries($webhook->getSuccessfulDeliveries() + 1);
			$webhook->setLastSuccessAt(new DateTime());

			return $this->update(entity: $webhook);
		}

		$webhook->setFailedDeliveries($webhook->getFailedDeliveries() + 1);
		$webhook->setLastFailureAt(new DateTime());

		return $this->update(entity: $webhook);
	}//end updateStatistics()

	/**
	 * Create webhook from array
	 *
	 * @param array $data Webhook data
	 *
	 * @return Webhook
	 */
	public function createFromArray(array $data): Webhook {
		$webhook = new Webhook();
		$webhook->hydrate($data);

		return $this->insert(entity: $webhook);
	}//end createFromArray()

	/**
	 * Update webhook from array
	 *
	 * @param int $id Webhook ID
	 * @param array $data Webhook data
	 *
	 * @return Webhook
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function updateFromArray(int $id, array $data): Webhook {
		$webhook = $this->find(id: $id);
		$webhook->hydrate($data);

		return $this->update(entity: $webhook);
	}//end updateFromArray()

	/**
	 * Check if the webhooks table exists
	 *
	 * Used to gracefully handle cases where migrations haven't run yet.
	 *
	 * @return bool True if table exists, false otherwise
	 */
	private function tableExists(): bool {
		try {
			// Try to execute a simple query to check if table exists.
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->createFunction('COUNT(*)'))
				->from($this->getTableName())
				->setMaxResults(1);
			$qb->executeQuery();
			return true;
		} catch (\Exception $e) {
			// If query fails, table likely doesn't exist.
			return false;
		}
	}//end tableExists()
}//end class
