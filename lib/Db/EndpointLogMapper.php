<?php
/**
 * OpenRegister Endpoint Log Mapper
 *
 * Mapper for EndpointLog entities to handle database operations.
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

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * EndpointLogMapper
 *
 * @method EndpointLog insert(Entity $entity)
 * @method EndpointLog update(Entity $entity)
 * @method EndpointLog insertOrUpdate(Entity $entity)
 * @method EndpointLog delete(Entity $entity)
 * @method EndpointLog find(int $id)
 * @method EndpointLog findEntity(IQueryBuilder $query)
 * @method EndpointLog[] findAll(int|null $limit = null, int|null $offset = null)
 * @method list<EndpointLog> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<EndpointLog>
 */
class EndpointLogMapper extends QBMapper
{


    /**
     * Constructor
     *
     * @param IDBConnection $db Database connection
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openregister_endpoint_logs', EndpointLog::class);

    }//end __construct()


    /**
     * Find all endpoint logs
     *
     * @param int|null $limit  Maximum number of results
     * @param int|null $offset Starting offset
     *
     * @return EndpointLog[]
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
     * Find logs by endpoint ID
     *
     * @param int      $endpointId Endpoint ID
     * @param int|null $limit      Maximum number of results
     * @param int|null $offset     Starting offset
     *
     * @return EndpointLog[]
     */
    public function findByEndpoint(int $endpointId, ?int $limit=null, ?int $offset=null): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('endpoint_id', $qb->createNamedParameter($endpointId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities($qb);

    }//end findByEndpoint()


    /**
     * Find a single log by ID
     *
     * @param int $id Log ID
     *
     * @return EndpointLog
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function find($id): EndpointLog
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);

    }//end find()


    /**
     * Get statistics for endpoint logs
     *
     * @param int|null $endpointId Optional endpoint ID to filter statistics
     *
     * @return         array Statistics array with counts
     * @phpstan-return array<string, int>
     * @psalm-return   array<string, int>
     */
    public function getStatistics(?int $endpointId=null): array
    {
        $qb = $this->db->getQueryBuilder();

        // Total logs.
        $qb->select($qb->func()->count('*', 'total'))
            ->from($this->getTableName());

        if ($endpointId !== null) {
            $qb->where($qb->expr()->eq('endpoint_id', $qb->createNamedParameter($endpointId, IQueryBuilder::PARAM_INT)));
        }

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $total  = (int) ($row['total'] ?? 0);
        $result->closeCursor();

        // Success logs.
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'success'))
            ->from($this->getTableName())
            ->where($qb->expr()->gte('status_code', $qb->createNamedParameter(200, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lt('status_code', $qb->createNamedParameter(300, IQueryBuilder::PARAM_INT)));

        if ($endpointId !== null) {
            $qb->andWhere($qb->expr()->eq('endpoint_id', $qb->createNamedParameter($endpointId, IQueryBuilder::PARAM_INT)));
        }

        $result  = $qb->executeQuery();
        $row     = $result->fetch();
        $success = (int) ($row['success'] ?? 0);
        $result->closeCursor();

        // Failed logs.
        $failed = $total - $success;

        return [
            'total'   => $total,
            'success' => $success,
            'failed'  => $failed,
        ];

    }//end getStatistics()


}//end class
