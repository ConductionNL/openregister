<?php

/**
 * OpenRegister ActionLog Mapper
 *
 * Mapper for ActionLog entities to handle database operations.
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
 * ActionLogMapper handles database operations for ActionLog entities
 *
 * @method ActionLog insert(Entity $entity)
 * @method ActionLog update(Entity $entity)
 * @method ActionLog delete(Entity $entity)
 * @method ActionLog find(int $id)
 * @method ActionLog findEntity(IQueryBuilder $query)
 * @method list<ActionLog> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<ActionLog>
 */
class ActionLogMapper extends QBMapper
{
    /**
     * Constructor
     *
     * @param IDBConnection $db Database connection
     *
     * @return void
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'openregister_action_logs', entityClass: ActionLog::class);
    }//end __construct()

    /**
     * Find a log by ID
     *
     * @param int $id Log entry ID
     *
     * @return ActionLog
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    public function find(int $id): ActionLog
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity(query: $qb);
    }//end find()

    /**
     * Find logs for a specific action by action ID
     *
     * @param int      $actionId Action ID
     * @param int|null $limit    Limit results
     * @param int|null $offset   Offset results
     *
     * @return ActionLog[]
     *
     * @psalm-return list<ActionLog>
     */
    public function findByActionId(int $actionId, ?int $limit=null, ?int $offset=null): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('action_id', $qb->createNamedParameter($actionId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities(query: $qb);
    }//end findByActionId()

    /**
     * Find logs for a specific action by action UUID
     *
     * @param string   $actionUuid Action UUID
     * @param int|null $limit      Limit results
     * @param int|null $offset     Offset results
     *
     * @return ActionLog[]
     *
     * @psalm-return list<ActionLog>
     */
    public function findByActionUuid(string $actionUuid, ?int $limit=null, ?int $offset=null): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('action_uuid', $qb->createNamedParameter($actionUuid)))
            ->orderBy('created', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities(query: $qb);
    }//end findByActionUuid()

    /**
     * Get aggregate statistics for a specific action
     *
     * @param int $actionId Action ID
     *
     * @return array Statistics array with total, successful, failed counts
     *
     * @psalm-return array{total: int, successful: int, failed: int}
     */
    public function getStatsByActionId(int $actionId): array
    {
        $qb = $this->db->getQueryBuilder();

        $successCase = "SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful";
        $failedCase  = "SUM(CASE WHEN status IN ('failure', 'abandoned') THEN 1 ELSE 0 END) as failed";

        $qb->select($qb->createFunction('COUNT(*) as total'))
            ->addSelect($qb->createFunction($successCase))
            ->addSelect($qb->createFunction($failedCase))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('action_id', $qb->createNamedParameter($actionId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return [
            'total'      => (int) ($row['total'] ?? 0),
            'successful' => (int) ($row['successful'] ?? 0),
            'failed'     => (int) ($row['failed'] ?? 0),
        ];
    }//end getStatsByActionId()

    /**
     * Insert a new action log
     *
     * @param Entity $entity ActionLog entity to insert
     *
     * @return ActionLog
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function insert(Entity $entity): Entity
    {
        if ($entity instanceof ActionLog) {
            $entity->setCreated(new DateTime());
        }

        return parent::insert(entity: $entity);
    }//end insert()
}//end class
