<?php

/**
 * OpenRegister ScheduledWorkflowMapper
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * Mapper for ScheduledWorkflow entities.
 *
 * @extends QBMapper<ScheduledWorkflow>
 */
class ScheduledWorkflowMapper extends QBMapper
{
    /**
     * Constructor for ScheduledWorkflowMapper.
     *
     * @param IDBConnection $db Database connection
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(
            db: $db,
            tableName: 'openregister_scheduled_workflows',
            entityClass: ScheduledWorkflow::class
        );
    }//end __construct()

    /**
     * Find a scheduled workflow by ID.
     *
     * @param int $id Scheduled workflow ID
     *
     * @return ScheduledWorkflow
     */
    public function find(int $id): ScheduledWorkflow
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
     * Find all scheduled workflows.
     *
     * @param int|null $limit  Maximum results
     * @param int|null $offset Offset for pagination
     *
     * @return array<int, ScheduledWorkflow>
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
     * Find all enabled scheduled workflows.
     *
     * @return array<int, ScheduledWorkflow>
     */
    public function findAllEnabled(): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'enabled',
                    $qb->createNamedParameter(value: true, type: IQueryBuilder::PARAM_BOOL)
                )
            )
            ->orderBy('name', 'ASC');

        return $this->findEntities(query: $qb);
    }//end findAllEnabled()

    /**
     * Create a scheduled workflow from an array.
     *
     * @param array<string, mixed> $data Workflow data
     *
     * @return ScheduledWorkflow
     */
    public function createFromArray(array $data): ScheduledWorkflow
    {
        $workflow = new ScheduledWorkflow();
        $workflow->hydrate($data);

        if ($workflow->getUuid() === null) {
            $workflow->setUuid(Uuid::v4()->toRfc4122());
        }

        $now = new DateTime();
        $workflow->setCreated($now);
        $workflow->setUpdated($now);

        return $this->insert(entity: $workflow);
    }//end createFromArray()

    /**
     * Update a scheduled workflow from an array.
     *
     * @param int                  $id   Workflow ID
     * @param array<string, mixed> $data Updated data
     *
     * @return ScheduledWorkflow
     */
    public function updateFromArray(int $id, array $data): ScheduledWorkflow
    {
        $workflow = $this->find(id: $id);
        $workflow->hydrate($data);
        $workflow->setUpdated(new DateTime());

        return $this->update(entity: $workflow);
    }//end updateFromArray()
}//end class
