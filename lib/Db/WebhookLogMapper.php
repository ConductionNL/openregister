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
 * @method WebhookLog[] findAll(int|null $limit = null, int|null $offset = null)
 * @method list<WebhookLog> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<WebhookLog>
 *
 * @psalm-suppress ImplementedParamTypeMismatch - Entity type is used consistently across all mappers
 * @psalm-suppress PossiblyUnusedMethod - Constructor is called by Nextcloud's DI container
 */
class WebhookLogMapper extends QBMapper
{

    /**
     * WebhookLogMapper constructor
     *
     * @param IDBConnection $db Database connection
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openregister_webhook_logs', WebhookLog::class);

    }//end __construct()


    /**
     * Find logs for a specific webhook
     *
     * @param int      $webhookId Webhook ID
     * @param int|null $limit     Limit results
     * @param int|null $offset    Offset results
     *
     * @return WebhookLog[]
     */
    public function findByWebhook(int $webhookId, ?int $limit = null, ?int $offset = null): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('webhook_id', $qb->createNamedParameter($webhookId, IQueryBuilder::PARAM_INT)))
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
     * Find failed logs that need retry
     *
     * @param DateTime $before Before timestamp
     *
     * @return WebhookLog[]
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
     * @psalm-suppress PossiblyUnusedReturnValue - Return value may be used by callers
     */
    public function insert(Entity $entity): Entity
    {
        if ($entity instanceof WebhookLog) {
            if ($entity->getCreated() === null) {
                $entity->setCreated(new DateTime());
            }
        }

        return parent::insert($entity);

    }//end insert()


    /**
     * Delete old logs
     *
     * @param DateTime $before Delete logs created before this date
     *
     * @return int Number of deleted logs
     *
     * @psalm-suppress PossiblyUnusedMethod - May be used by cleanup cron jobs or admin operations
     */
    public function deleteOldLogs(DateTime $before): int
    {
        $qb = $this->db->getQueryBuilder();

        $qb->delete($this->getTableName())
            ->where($qb->expr()->lt('created', $qb->createNamedParameter($before, IQueryBuilder::PARAM_DATE)));

        return $qb->executeStatement();

    }//end deleteOldLogs()


    /**
     * Get statistics for a webhook
     *
     * @param int $webhookId Webhook ID
     *
     * @return array Statistics
     */
    public function getStatistics(int $webhookId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->selectAlias($qb->createFunction('COUNT(*)'), 'total')
            ->addSelectAlias(
                expr: $qb->createFunction('SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END)'),
                alias: 'successful'
            )
            ->addSelectAlias(
                expr: $qb->createFunction('SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END)'),
                alias: 'failed'
            )
            ->from($this->getTableName())
            ->where($qb->expr()->eq('webhook_id', $qb->createNamedParameter($webhookId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return [
            'total'     => (int) ($row['total'] ?? 0),
            'successful' => (int) ($row['successful'] ?? 0),
            'failed'    => (int) ($row['failed'] ?? 0),
        ];

    }//end getStatistics()


}//end class

