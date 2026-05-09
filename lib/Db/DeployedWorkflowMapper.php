<?php

/**
 * OpenRegister DeployedWorkflowMapper
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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Mapper for DeployedWorkflow entities.
 *
 * @extends QBMapper<DeployedWorkflow>
 */
class DeployedWorkflowMapper extends QBMapper
{
    /**
     * Constructor for DeployedWorkflowMapper.
     *
     * @param IDBConnection $db Database connection
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'openregister_deployed_workflows', entityClass: DeployedWorkflow::class);
    }//end __construct()

    /**
     * Find a deployed workflow by ID.
     *
     * @param int $id Deployed workflow ID
     *
     * @return DeployedWorkflow
     */
    public function find(int $id): DeployedWorkflow
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
     * Find all deployed workflows.
     *
     * @param int|null $limit  Maximum results
     * @param int|null $offset Offset for pagination
     *
     * @return array<int, DeployedWorkflow>
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
     * Find a deployed workflow by name and engine type.
     *
     * @param string $name   Workflow name
     * @param string $engine Engine type identifier
     *
     * @return DeployedWorkflow|null The matching entity or null if not found
     */
    public function findByNameAndEngine(string $name, string $engine): ?DeployedWorkflow
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('name', $qb->createNamedParameter(value: $name))
            )
            ->andWhere(
                $qb->expr()->eq('engine', $qb->createNamedParameter(value: $engine))
            );

        try {
            return $this->findEntity(query: $qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }//end findByNameAndEngine()

    /**
     * Find all deployed workflows attached to a schema.
     *
     * @param string $schemaSlug Schema slug
     *
     * @return array<int, DeployedWorkflow>
     */
    public function findBySchema(string $schemaSlug): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('attached_schema', $qb->createNamedParameter(value: $schemaSlug))
            );

        return $this->findEntities(query: $qb);
    }//end findBySchema()

    /**
     * Find all deployed workflows from a specific import source.
     *
     * @param string $source Import source identifier
     *
     * @return array<int, DeployedWorkflow>
     */
    public function findByImportSource(string $source): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('import_source', $qb->createNamedParameter(value: $source))
            );

        return $this->findEntities(query: $qb);
    }//end findByImportSource()

    /**
     * Create a deployed workflow from an array.
     *
     * @param array<string, mixed> $data Workflow data
     *
     * @return DeployedWorkflow
     */
    public function createFromArray(array $data): DeployedWorkflow
    {
        $workflow = new DeployedWorkflow();
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
     * Update a deployed workflow from an array.
     *
     * @param int                  $id   Workflow ID
     * @param array<string, mixed> $data Updated data
     *
     * @return DeployedWorkflow
     */
    public function updateFromArray(int $id, array $data): DeployedWorkflow
    {
        $workflow = $this->find(id: $id);
        $workflow->hydrate($data);
        $workflow->setUpdated(new DateTime());

        return $this->update(entity: $workflow);
    }//end updateFromArray()
}//end class
