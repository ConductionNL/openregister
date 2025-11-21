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
 * WebhookMapper
 *
 * @method Webhook insert(Entity $entity)
 * @method Webhook update(Entity $entity)
 * @method Webhook insertOrUpdate(Entity $entity)
 * @method Webhook delete(Entity $entity)
 * @method Webhook find(int $id)
 * @method Webhook findEntity(IQueryBuilder $query)
 * @method Webhook[] findAll(int|null $limit = null, int|null $offset = null)
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
     * WebhookMapper constructor
     *
     * @param IDBConnection       $db                  Database connection
     * @param OrganisationService $organisationService Organisation service
     * @param IUserSession        $userSession         User session
     * @param IGroupManager       $groupManager        Group manager
     */
    public function __construct(
        IDBConnection $db,
        OrganisationService $organisationService,
        IUserSession $userSession,
        IGroupManager $groupManager
    ) {
        parent::__construct($db, 'openregister_webhooks', Webhook::class);
        $this->organisationService = $organisationService;
        $this->userSession         = $userSession;
        $this->groupManager        = $groupManager;

    }//end __construct()


    /**
     * Find webhook by UUID
     *
     * @param string $uuid Webhook UUID
     *
     * @return Webhook
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findByUuid(string $uuid): Webhook
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

        // Apply organisation filter.
        $this->applyOrganisationFilter($qb);

        return $this->findEntity($qb);

    }//end findByUuid()


    /**
     * Find all webhooks
     *
     * @return Webhook[]
     */
    public function findAll(): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName());

        // Apply organisation filter.
        $this->applyOrganisationFilter($qb);

        return $this->findEntities($qb);

    }//end findAll()


    /**
     * Find a single webhook by ID
     *
     * @param int $id Webhook ID
     *
     * @return Webhook
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function find(int $id): Webhook
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        // Apply organisation filter.
        $this->applyOrganisationFilter($qb);

        return $this->findEntity($qb);

    }//end find()


    /**
     * Find all enabled webhooks
     *
     * @return Webhook[]
     */
    public function findEnabled(): array
    {
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
        $this->verifyRbacPermission('create', 'webhook');

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
        $this->verifyRbacPermission('update', 'webhook');

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
        $this->verifyRbacPermission('delete', 'webhook');

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


}//end class
