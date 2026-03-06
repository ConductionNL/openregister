<?php

/**
 * OpenRegister WorkflowEngineMapper
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

use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for WorkflowEngine entities.
 *
 * @extends QBMapper<WorkflowEngine>
 */
class WorkflowEngineMapper extends QBMapper
{
    /**
     * Constructor for WorkflowEngineMapper.
     *
     * @param IDBConnection $db Database connection
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'openregister_workflow_engines', entityClass: WorkflowEngine::class);
    }//end __construct()

    /**
     * Find a workflow engine by ID.
     *
     * @param int $id Engine ID
     *
     * @return WorkflowEngine
     */
    public function find(int $id): WorkflowEngine
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
     * Find all workflow engines.
     *
     * @param int|null $limit  Maximum results
     * @param int|null $offset Offset for pagination
     *
     * @return array<int, WorkflowEngine>
     */
    public function findAll(?int $limit=null, ?int $offset=null): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('name', 'ASC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities(query: $qb);
    }//end findAll()

    /**
     * Find engines by type.
     *
     * @param string $engineType Engine type (e.g., 'n8n', 'windmill')
     *
     * @return array<int, WorkflowEngine>
     */
    public function findByType(string $engineType): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('engine_type', $qb->createNamedParameter(value: $engineType))
            );

        return $this->findEntities(query: $qb);
    }//end findByType()

    /**
     * Create a workflow engine from an array.
     *
     * @param array<string, mixed> $data Engine data
     *
     * @return WorkflowEngine
     */
    public function createFromArray(array $data): WorkflowEngine
    {
        $engine = new WorkflowEngine();
        $engine->hydrate($data);

        if ($engine->getUuid() === null) {
            $engine->setUuid(\Ramsey\Uuid\Uuid::uuid4()->toString());
        }

        $now = new \DateTime();
        $engine->setCreated($now);
        $engine->setUpdated($now);

        return $this->insert(entity: $engine);
    }//end createFromArray()

    /**
     * Update a workflow engine from an array.
     *
     * @param int                  $id   Engine ID
     * @param array<string, mixed> $data Updated data
     *
     * @return WorkflowEngine
     */
    public function updateFromArray(int $id, array $data): WorkflowEngine
    {
        $engine = $this->find(id: $id);
        $engine->hydrate($data);
        $engine->setUpdated(new \DateTime());

        return $this->update(entity: $engine);
    }//end updateFromArray()
}//end class
