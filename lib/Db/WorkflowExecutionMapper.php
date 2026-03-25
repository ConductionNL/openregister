<?php

/**
 * OpenRegister WorkflowExecutionMapper
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Mapper for WorkflowExecution entities.
 *
 * @extends QBMapper<WorkflowExecution>
 */
class WorkflowExecutionMapper extends QBMapper
{
    /**
     * Constructor for WorkflowExecutionMapper.
     *
     * @param IDBConnection $db Database connection
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(
            db: $db,
            tableName: 'openregister_workflow_executions',
            entityClass: WorkflowExecution::class
        );
    }//end __construct()

    /**
     * Find a workflow execution by ID.
     *
     * @param int $id Execution ID
     *
     * @return WorkflowExecution
     */
    public function find(int $id): WorkflowExecution
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('id', $qb->createNamedParameter(value: $id, type: IQueryBuilder::PARAM_INT))
            );

        return $this->findEntity(query: $qb);
    }//end find()

    /**
     * Find all workflow executions with optional filters and pagination.
     *
     * @param array<string, mixed> $filters Filter parameters
     * @param int|null             $limit   Maximum results (default 50)
     * @param int|null             $offset  Pagination offset
     *
     * @return array<int, WorkflowExecution>
     */
    public function findAll(array $filters = [], ?int $limit = 50, ?int $offset = 0): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('executed_at', 'DESC');

        $this->applyFilters($qb, $filters);

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities(query: $qb);
    }//end findAll()

    /**
     * Count all workflow executions matching the given filters.
     *
     * @param array<string, mixed> $filters Filter parameters
     *
     * @return int Total count
     */
    public function countAll(array $filters = []): int
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName());

        $this->applyFilters($qb, $filters);

        $result = $qb->executeQuery();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;
    }//end countAll()

    /**
     * Delete all records older than the given cutoff date.
     *
     * @param DateTime $cutoff Records older than this are deleted
     *
     * @return int Number of deleted rows
     */
    public function deleteOlderThan(DateTime $cutoff): int
    {
        $qb = $this->db->getQueryBuilder();

        $qb->delete($this->getTableName())
            ->where(
                $qb->expr()->lt(
                    'executed_at',
                    $qb->createNamedParameter(
                        value: $cutoff->format('Y-m-d H:i:s'),
                        type: IQueryBuilder::PARAM_STR
                    )
                )
            );

        return $qb->executeStatement();
    }//end deleteOlderThan()

    /**
     * Create a workflow execution from an array.
     *
     * @param array<string, mixed> $data Execution data
     *
     * @return WorkflowExecution
     */
    public function createFromArray(array $data): WorkflowExecution
    {
        $execution = new WorkflowExecution();
        $execution->hydrate($data);

        if ($execution->getUuid() === null) {
            $execution->setUuid(Uuid::v4()->toRfc4122());
        }

        if ($execution->getExecutedAt() === null) {
            $execution->setExecutedAt(new DateTime());
        }

        return $this->insert(entity: $execution);
    }//end createFromArray()

    /**
     * Apply filter parameters to a query builder.
     *
     * @param IQueryBuilder        $qb      Query builder
     * @param array<string, mixed> $filters Filter parameters
     *
     * @return void
     */
    private function applyFilters(IQueryBuilder $qb, array $filters): void
    {
        if (isset($filters['objectUuid']) === true) {
            $qb->andWhere(
                $qb->expr()->eq('object_uuid', $qb->createNamedParameter($filters['objectUuid']))
            );
        }

        if (isset($filters['schemaId']) === true) {
            $qb->andWhere(
                $qb->expr()->eq(
                    'schema_id',
                    $qb->createNamedParameter(value: (int) $filters['schemaId'], type: IQueryBuilder::PARAM_INT)
                )
            );
        }

        if (isset($filters['hookId']) === true) {
            $qb->andWhere(
                $qb->expr()->eq('hook_id', $qb->createNamedParameter($filters['hookId']))
            );
        }

        if (isset($filters['status']) === true) {
            $qb->andWhere(
                $qb->expr()->eq('status', $qb->createNamedParameter($filters['status']))
            );
        }

        if (isset($filters['engine']) === true) {
            $qb->andWhere(
                $qb->expr()->eq('engine', $qb->createNamedParameter($filters['engine']))
            );
        }

        if (isset($filters['since']) === true) {
            $qb->andWhere(
                $qb->expr()->gte('executed_at', $qb->createNamedParameter($filters['since']))
            );
        }
    }//end applyFilters()
}//end class
