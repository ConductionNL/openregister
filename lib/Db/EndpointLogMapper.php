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
 * EndpointLogMapper handles database operations for EndpointLog entities
 *
 * Mapper for EndpointLog entities to handle database operations for endpoint
 * execution logs. Provides methods for querying logs by endpoint, retrieving
 * statistics, and managing log entries.
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
     * Initializes mapper with database connection.
     * Calls parent constructor to set up base mapper functionality.
     *
     * @param IDBConnection $db Database connection
     *
     * @return void
     */
    public function __construct(IDBConnection $db)
    {
        // Call parent constructor to initialize base mapper with table name and entity class.
        parent::__construct($db, 'openregister_endpoint_logs', EndpointLog::class);

    }//end __construct()


    /**
     * Find all endpoint logs
     *
     * Retrieves all endpoint execution logs with optional pagination.
     * Results are ordered by creation date descending (newest first).
     *
     * @param int|null $limit  Maximum number of results to return (null = no limit)
     * @param int|null $offset Starting offset for pagination (null = no offset)
     *
     * @return EndpointLog[] Array of endpoint log entities
     */
    public function findAll(?int $limit=null, ?int $offset=null): array
    {
        // Step 1: Get query builder instance.
        $qb = $this->db->getQueryBuilder();

        // Step 2: Build SELECT query for all columns.
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('created', 'DESC');

        // Step 3: Apply pagination if limit specified.
        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        // Step 4: Apply offset if specified.
        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        // Step 5: Execute query and return entities.
        return $this->findEntities($qb);

    }//end findAll()


    /**
     * Find logs by endpoint ID
     *
     * Retrieves all execution logs for a specific endpoint with optional pagination.
     * Results are ordered by creation date descending (newest first).
     *
     * @param int      $endpointId Endpoint ID to filter logs by
     * @param int|null $limit      Maximum number of results to return (null = no limit)
     * @param int|null $offset     Starting offset for pagination (null = no offset)
     *
     * @return EndpointLog[] Array of endpoint log entities for the specified endpoint
     */
    public function findByEndpoint(int $endpointId, ?int $limit=null, ?int $offset=null): array
    {
        // Step 1: Get query builder instance.
        $qb = $this->db->getQueryBuilder();

        // Step 2: Build SELECT query with endpoint ID filter.
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('endpoint_id', $qb->createNamedParameter($endpointId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created', 'DESC');

        // Step 3: Apply pagination if limit specified.
        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        // Step 4: Apply offset if specified.
        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        // Step 5: Execute query and return entities.
        return $this->findEntities($qb);

    }//end findByEndpoint()


    /**
     * Find a single log by ID
     *
     * Retrieves endpoint log entry by ID. Throws exception if log not found.
     *
     * @param int $id Log ID to find
     *
     * @return EndpointLog The found endpoint log entity
     *
     * @throws DoesNotExistException If log entry not found
     * @throws MultipleObjectsReturnedException If multiple log entries found (should not happen)
     */
    public function find($id): EndpointLog
    {
        // Step 1: Get query builder instance.
        $qb = $this->db->getQueryBuilder();

        // Step 2: Build SELECT query with ID filter.
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        // Step 3: Execute query and return single entity.
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
