<?php
/**
 * OpenRegister Agent Mapper
 *
 * This file contains the AgentMapper class for database operations on agents.
 *
 * @category Mapper
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class AgentMapper
 *
 * @package OCA\OpenRegister\Db
 *
 * @template-extends QBMapper<Agent>
 *
 * @psalm-suppress MissingTemplateParam
 */
class AgentMapper extends QBMapper
{

    /**
     * AgentMapper constructor.
     *
     * @param IDBConnection $db Database connection instance
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openregister_agents', Agent::class);

    }//end __construct()


    /**
     * Find an agent by its ID
     *
     * @param int $id Agent ID
     *
     * @return Agent The agent entity
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function find(int $id): Agent
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);

    }//end find()


    /**
     * Find an agent by its UUID
     *
     * @param string $uuid Agent UUID
     *
     * @return Agent The agent entity
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findByUuid(string $uuid): Agent
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));

        return $this->findEntity($qb);

    }//end findByUuid()


    /**
     * Find agents by organisation
     *
     * @param int $organisationId Organisation ID
     * @param int $limit          Maximum number of results
     * @param int $offset         Offset for pagination
     *
     * @return Agent[] Array of agent entities
     */
    public function findByOrganisation(int $organisationId, int $limit=50, int $offset=0): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('organisation', $qb->createNamedParameter($organisationId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('created', 'DESC');

        return $this->findEntities($qb);

    }//end findByOrganisation()


    /**
     * Find agents by owner
     *
     * @param string $owner  Owner user ID
     * @param int    $limit  Maximum number of results
     * @param int    $offset Offset for pagination
     *
     * @return Agent[] Array of agent entities
     */
    public function findByOwner(string $owner, int $limit=50, int $offset=0): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('owner', $qb->createNamedParameter($owner, IQueryBuilder::PARAM_STR)))
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('created', 'DESC');

        return $this->findEntities($qb);

    }//end findByOwner()


    /**
     * Find agents by type
     *
     * @param string $type   Agent type
     * @param int    $limit  Maximum number of results
     * @param int    $offset Offset for pagination
     *
     * @return Agent[] Array of agent entities
     */
    public function findByType(string $type, int $limit=50, int $offset=0): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_STR)))
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('created', 'DESC');

        return $this->findEntities($qb);

    }//end findByType()


    /**
     * Find all agents with optional filters
     *
     * @param int|null    $limit   Maximum number of results
     * @param int|null    $offset  Offset for pagination
     * @param array|null  $filters Filter criteria
     * @param array|null  $order   Order by criteria
     *
     * @return Agent[] Array of agent entities
     */
    public function findAll(?int $limit=null, ?int $offset=null, ?array $filters=[], ?array $order=[]): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName);

        // Apply filters
        if (!empty($filters)) {
            foreach ($filters as $field => $value) {
                if ($value !== null && $field !== '_route') {
                    if ($field === 'active') {
                        $qb->andWhere($qb->expr()->eq($field, $qb->createNamedParameter((bool) $value, IQueryBuilder::PARAM_BOOL)));
                    } elseif (is_array($value)) {
                        $qb->andWhere($qb->expr()->in($field, $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR_ARRAY)));
                    } else {
                        $qb->andWhere($qb->expr()->eq($field, $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR)));
                    }
                }
            }
        }

        // Apply ordering
        if (!empty($order)) {
            foreach ($order as $field => $direction) {
                $qb->addOrderBy($field, $direction);
            }
        } else {
            $qb->orderBy('created', 'DESC');
        }

        // Apply pagination
        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }
        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities($qb);

    }//end findAll()


    /**
     * Insert a new agent
     *
     * @param Agent $entity Agent entity to insert
     *
     * @return Agent The inserted agent with updated ID
     */
    public function insert(Entity $entity): Entity
    {
        if ($entity instanceof Agent) {
            // Generate UUID if not set
            if (empty($entity->getUuid())) {
                $entity->setUuid(\OC::$server->get(\OCP\Security\ISecureRandom::class)->generate(
                    36,
                    \OCP\Security\ISecureRandom::CHAR_ALPHANUMERIC
                ));
            }
            
            $entity->setCreated(new DateTime());
            $entity->setUpdated(new DateTime());
        }

        return parent::insert($entity);

    }//end insert()


    /**
     * Update an existing agent
     *
     * @param Agent $entity Agent entity to update
     *
     * @return Agent The updated agent
     */
    public function update(Entity $entity): Entity
    {
        if ($entity instanceof Agent) {
            $entity->setUpdated(new DateTime());
        }

        return parent::update($entity);

    }//end update()


    /**
     * Delete an agent
     *
     * @param Agent $entity Agent entity to delete
     *
     * @return Agent The deleted agent
     */
    public function delete(Entity $entity): Entity
    {
        return parent::delete($entity);

    }//end delete()


    /**
     * Create an agent from an array
     *
     * @param array $data The agent data
     *
     * @return Agent The created agent
     */
    public function createFromArray(array $data): Agent
    {
        $agent = new Agent();
        $agent->hydrate($data);

        return $this->insert($agent);

    }//end createFromArray()


    /**
     * Count agents with optional filters
     *
     * @param array|null $filters Filter criteria
     *
     * @return int Total count of agents
     */
    public function count(?array $filters=[]): int
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->tableName);

        // Apply filters
        if (!empty($filters)) {
            foreach ($filters as $field => $value) {
                if ($value !== null && $field !== '_route') {
                    if ($field === 'active') {
                        $qb->andWhere($qb->expr()->eq($field, $qb->createNamedParameter((bool) $value, IQueryBuilder::PARAM_BOOL)));
                    } elseif (is_array($value)) {
                        $qb->andWhere($qb->expr()->in($field, $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR_ARRAY)));
                    } else {
                        $qb->andWhere($qb->expr()->eq($field, $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR)));
                    }
                }
            }
        }

        return (int) $qb->executeQuery()->fetchOne();

    }//end count()


}//end class

