<?php

/**
 * OpenRegister Webhook Log Mapper
 *
 * Mapper for WebhookLog entities to handle database operations.
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
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * WebhookLogMapper
 *
 * @method WebhookLog insert(Entity $entity)
 * @method WebhookLog update(Entity $entity)
 * @method WebhookLog insertOrUpdate(Entity $entity)
 * @method WebhookLog delete(Entity $entity)
 * @method WebhookLog find(int $id)
 * @method WebhookLog findEntity(IQueryBuilder $query)
 * @method WebhookLog[] findAll(int|null $limit=null, int|null $offset=null)
 * @method list<WebhookLog> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<WebhookLog>
 */
class WebhookLogMapper extends QBMapper
{
    /**
     * Constructor for WebhookLogMapper
     *
     * @param IDBConnection $db Database connection
     *
     * @return void
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openregister_webhook_logs', WebhookLog::class);
    }//end __construct()

    /**
     * Find a webhook log by ID
     *
     * @param int $id Log entry ID
     *
     * @return WebhookLog
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    public function find(int $id): WebhookLog
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }//end find()

    /**
     * Find logs for a specific webhook
     *
     * @param int      $webhookId Webhook ID
     * @param int|null $limit     Limit results
     * @param int|null $offset    Offset results
     *
     * @return WebhookLog[]
     *
     * @psalm-return list<\OCA\OpenRegister\Db\WebhookLog>
     */
    public function findByWebhook(int $webhookId, ?int $limit=null, ?int $offset=null): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('webhook', $qb->createNamedParameter($webhookId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities($qb);
    }//end findByWebhook()

    /**
     * Find all webhook logs
     *
     * @param int|null $limit  Limit results
     * @param int|null $offset Offset results
     *
     * @return WebhookLog[]
     *
     * @psalm-return list<OCA\OpenRegister\Db\WebhookLog>
     */
    public function findAll(?int $limit=null, ?int $offset=null): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('created', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities($qb);
    }//end findAll()

    /**
     * Find failed logs that need retry
     *
     * @param DateTime $before Before timestamp
     *
     * @return WebhookLog[]
     *
     * @psalm-return list<\OCA\OpenRegister\Db\WebhookLog>
     */
    public function findFailedForRetry(DateTime $before): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('success', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->isNotNull('next_retry_at'))
            ->andWhere($qb->expr()->lte('next_retry_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_DATE)))
            ->orderBy('next_retry_at', 'ASC');

        return $this->findEntities($qb);
    }//end findFailedForRetry()

    /**
     * Insert a new webhook log
     *
     * @param Entity $entity WebhookLog entity to insert
     *
     * @return WebhookLog The inserted log
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function insert(Entity $entity): Entity
    {
        if ($entity instanceof WebhookLog) {
            // Always set created timestamp to ensure it's properly marked for insertion.
            $entity->setCreated(new DateTime());
        }

        return parent::insert($entity);
    }//end insert()

    /**
     * Get statistics for a webhook
     *
     * @param int $webhookId Webhook ID (0 for all webhooks)
     *
     * @return int[] Statistics
     *
     * @psalm-return array{total: int, successful: int, failed: int}
     */
    public function getStatistics(int $webhookId): array
    {
        $qb = $this->db->getQueryBuilder();

        // Get database platform to determine boolean handling.
        $platform = $qb->getConnection()->getDatabasePlatform()->getName();

        // Build conditional expressions for success/failure counts.
        // PostgreSQL uses TRUE/FALSE for booleans, MySQL/MariaDB use 1/0.
        if ($platform === 'postgresql') {
            $successCase = 'SUM(CASE WHEN success = TRUE THEN 1 ELSE 0 END) as successful';
            $failedCase  = 'SUM(CASE WHEN success = FALSE THEN 1 ELSE 0 END) as failed';
        } else {
            $successCase = 'SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful';
            $failedCase  = 'SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed';
        }

        $qb->select($qb->createFunction('COUNT(*) as total'))
            ->addSelect($qb->createFunction($successCase))
            ->addSelect($qb->createFunction($failedCase))
            ->from($this->getTableName());

        // Only filter by webhook if a specific webhook is requested.
        if ($webhookId > 0) {
            $qb->where($qb->expr()->eq('webhook', $qb->createNamedParameter($webhookId, IQueryBuilder::PARAM_INT)));
        }

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return [
            'total'      => (int) ($row['total'] ?? 0),
            'successful' => (int) ($row['successful'] ?? 0),
            'failed'     => (int) ($row['failed'] ?? 0),
        ];
    }//end getStatistics()
}//end class
