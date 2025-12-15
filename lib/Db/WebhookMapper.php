<?php
/**
 * OpenRegister Webhook Mapper
 *
 * Mapper for Webhook entities to handle database operations.
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
 * @method Webhook[] findAll(int|null $limit=null, int|null $offset=null)
 * @method list<Webhook> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<Webhook>
 */
class WebhookMapper extends QBMapper
{
    use MultiTenancyTrait;

    /**
     * Organisation service for multi-tenancy
     *
     * Used to filter webhooks by organisation for multi-tenant support.
     *
     * @var OrganisationService Organisation service instance
     */
    private readonly OrganisationService $organisationService;

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
     * @param IDBConnection       $db                  Database connection
     * @param OrganisationService $organisationService Organisation service for multi-tenancy
     * @param IUserSession        $userSession         User session for RBAC
     * @param IGroupManager       $groupManager        Group manager for RBAC
     *
     * @return void
     */
    public function __construct(
        IDBConnection $db,
        // REMOVED: Services should not be in mappers
        //         OrganisationService $organisationService,
        IUserSession $userSession,
        IGroupManager $groupManager
    ) {
        // Call parent constructor to initialize base mapper with table name and entity class.
        parent::__construct($db, 'openregister_webhooks', Webhook::class);

        // Store dependencies for use in mapper methods.
        // REMOVED: Services should not be in mappers
        //         $this->organisationService = $organisationService;
        $this->userSession         = $userSession;
        $this->groupManager        = $groupManager;

    }//end __construct()


    /**
     * Find all webhooks
     *
     * Retrieves all webhooks with organisation filtering for multi-tenancy.
     * Returns only webhooks belonging to the current organisation.
     *
     * @return Webhook[] Array of webhook entities
     *
     * @psalm-return list<\OCA\OpenRegister\Db\Webhook>
     */
    public function findAll(): array
    {
        // Check if table exists before querying (migrations might not have run yet).
        if ($this->tableExists() === false) {
            return [];
        }

        // Step 1: Get query builder instance.
        $qb = $this->db->getQueryBuilder();

        // Step 2: Build SELECT query for all columns.
        $qb->select('*')
            ->from($this->getTableName());

        // Step 3: Apply organisation filter for multi-tenancy.
        // This ensures users only see webhooks from their organisation.
        $this->applyOrganisationFilter($qb);

        // Step 4: Execute query and return entities.
        return $this->findEntities($qb);

    }//end findAll()


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
    public function find(int $id): Webhook
    {
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
        $this->applyOrganisationFilter($qb);

        // Step 4: Execute query and return single entity.
        return $this->findEntity($qb);

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
    public function findEnabled(): array
    {
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
        $this->applyOrganisationFilter($qb);

        return $this->findEntities($qb);

    }//end findEnabled()


    /**
     * Find webhooks that match an event
     *
     * @param string $eventClass Event class name
     *
     * @return Webhook[]
     *
     * @psalm-return array<int<0, max>, Webhook>
     */
    public function findForEvent(string $eventClass): array
    {
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
    public function insert(Entity $entity): Entity
    {
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
        $this->setOrganisationOnCreate($entity);

        return parent::insert($entity);

    }//end insert()


    /**
     * Update an existing webhook
     *
     * @param Entity $entity Webhook entity to update
     *
     * @return Webhook The updated webhook
     * @throws \Exception
     */
    public function update(Entity $entity): Entity
    {
        // Verify RBAC permission to update.
        $this->verifyRbacPermission(action: 'update', entityType: 'webhook');

        // Verify user has access to this organisation.
        $this->verifyOrganisationAccess($entity);

        if ($entity instanceof Webhook) {
            $entity->setUpdated(new DateTime());
        }

        return parent::update($entity);

    }//end update()


    /**
     * Delete a webhook
     *
     * @param Entity $entity Webhook entity to delete
     *
     * @return Webhook The deleted webhook
     * @throws \Exception
     */
    public function delete(Entity $entity): Entity
    {
        // Verify RBAC permission to delete.
        $this->verifyRbacPermission(action: 'delete', entityType: 'webhook');

        // Verify user has access to this organisation.
        $this->verifyOrganisationAccess($entity);

        return parent::delete($entity);

    }//end delete()


    /**
     * Update webhook statistics
     *
     * @param Webhook $webhook       Webhook to update
     * @param bool    $success       Was delivery successful
     * @param bool    $incrementOnly Only increment counters, don't update timestamps
     *
     * @return Webhook
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function updateStatistics(Webhook $webhook, bool $success, bool $incrementOnly=false): Webhook
    {
        $webhook->setTotalDeliveries($webhook->getTotalDeliveries() + 1);

        if ($incrementOnly === false) {
            $webhook->setLastTriggeredAt(new DateTime());
        }

        if ($success === true) {
            $webhook->setSuccessfulDeliveries($webhook->getSuccessfulDeliveries() + 1);
            if ($incrementOnly === false) {
                $webhook->setLastSuccessAt(new DateTime());
            }
        } else {
            $webhook->setFailedDeliveries($webhook->getFailedDeliveries() + 1);
            if ($incrementOnly === false) {
                $webhook->setLastFailureAt(new DateTime());
            }
        }

        return $this->update($webhook);

    }//end updateStatistics()


    /**
     * Create webhook from array
     *
     * @param array $data Webhook data
     *
     * @return Webhook
     */
    public function createFromArray(array $data): Webhook
    {
        $webhook = new Webhook();
        $webhook->hydrate($data);

        return $this->insert($webhook);

    }//end createFromArray()


    /**
     * Update webhook from array
     *
     * @param int   $id   Webhook ID
     * @param array $data Webhook data
     *
     * @return Webhook
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function updateFromArray(int $id, array $data): Webhook
    {
        $webhook = $this->find($id);
        $webhook->hydrate($data);

        return $this->update($webhook);

    }//end updateFromArray()


    /**
     * Check if the webhooks table exists
     *
     * Used to gracefully handle cases where migrations haven't run yet.
     *
     * @return bool True if table exists, false otherwise
     */
    private function tableExists(): bool
    {
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
