<?php
/**
 * OpenRegister Configuration Mapper
 *
 * This file contains the ConfigurationMapper class for database operations on configurations.
 *
 * @category Mapper
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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class ConfigurationMapper
 *
 * @package OCA\OpenRegister\Db
 *
 * @template-extends QBMapper<Configuration>
 *
 * @psalm-suppress MissingTemplateParam
 */
class ConfigurationMapper extends QBMapper
{


    /**
     * ConfigurationMapper constructor.
     *
     * @param IDBConnection $db Database connection instance
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openregister_configurations', Configuration::class);

    }//end __construct()


    /**
     * Find a configuration by its ID
     *
     * @param int $id Configuration ID
     *
     * @return Configuration The configuration entity
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function find(int $id): Configuration
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);

    }//end find()


    /**
     * Find configurations by type
     *
     * @param string $type   Configuration type
     * @param int    $limit  Maximum number of results
     * @param int    $offset Offset for pagination
     *
     * @return Configuration[] Array of configuration entities
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
     * Find configurations by owner
     *
     * @param string $owner  Owner identifier
     * @param int    $limit  Maximum number of results
     * @param int    $offset Offset for pagination
     *
     * @return Configuration[] Array of configuration entities
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
     * Insert a new configuration
     *
     * @param Configuration $entity Configuration entity to insert
     *
     * @return Configuration The inserted configuration with updated ID
     */
    public function insert(Entity $entity): Entity
    {
        if ($entity instanceof Configuration) {
            $entity->setCreated(new DateTime());
            $entity->setUpdated(new DateTime());
        }

        return parent::insert($entity);

    }//end insert()


    /**
     * Update an existing configuration
     *
     * @param Configuration $entity Configuration entity to update
     *
     * @return Configuration The updated configuration
     */
    public function update(Entity $entity): Entity
    {
        if ($entity instanceof Configuration) {
            $entity->setUpdated(new DateTime());
        }

        return parent::update($entity);

    }//end update()


    /**
     * Delete a configuration
     *
     * @param Configuration $entity Configuration entity to delete
     *
     * @return Configuration The deleted configuration
     */
    public function delete(Entity $entity): Entity
    {
        return parent::delete($entity);

    }//end delete()


    /**
     * Create a configuration from an array
     *
     * @param array $data The configuration data
     *
     * @return Configuration The created configuration
     */
    public function createFromArray(array $data): Configuration
    {
        $config = new Configuration();
        $config->hydrate(object: $data);

        // Prepare the object before insertion.
        return $this->insert($config);

    }//end createFromArray()


    /**
     * Update a configuration from an array
     *
     * @param int   $id   The configuration ID
     * @param array $data The configuration data
     *
     * @throws DoesNotExistException If the configuration is not found
     * @return Configuration The updated configuration
     */
    public function updateFromArray(int $id, array $data): Configuration
    {
        $config = $this->find($id);
        $config->hydrate(object: $data);

        return $this->update($config);

    }//end updateFromArray()


    /**
     * Count configurations by type
     *
     * @param string $type Configuration type
     *
     * @return int Number of configurations
     */
    public function countByType(string $type): int
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->tableName)
            ->where($qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_STR)));

        $result = $qb->executeQuery();
        $count  = $result->fetchOne();
        $result->closeCursor();

        return (int) $count;

    }//end countByType()


    /**
     * Count configurations by owner
     *
     * @param string $owner Owner ID
     *
     * @return int Number of configurations
     */
    public function countByOwner(string $owner): int
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->tableName)
            ->where($qb->expr()->eq('owner', $qb->createNamedParameter($owner, IQueryBuilder::PARAM_STR)));

        $result = $qb->executeQuery();
        $count  = $result->fetchOne();
        $result->closeCursor();

        return (int) $count;

    }//end countByOwner()


    /**
     * Find all configurations
     *
     * @param int|null   $limit            The limit of the results
     * @param int|null   $offset           The offset of the results
     * @param array|null $filters          The filters to apply
     * @param array|null $searchConditions Array of search conditions
     * @param array|null $searchParams     Array of search parameters
     *
     * @return Configuration[] Array of found configurations
     */
    public function findAll(
        ?int $limit=null,
        ?int $offset=null,
        ?array $filters=[],
        ?array $searchConditions=[],
        ?array $searchParams=[]
    ): array {
        $qb = $this->db->getQueryBuilder();

        // Build the base query.
        $qb->select('*')
            ->from($this->tableName)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('created', 'DESC');

        // Apply filters.
        foreach ($filters as $filter => $value) {
            if ($value === 'IS NOT NULL') {
                $qb->andWhere($qb->expr()->isNotNull($filter));
            } else if ($value === 'IS NULL') {
                $qb->andWhere($qb->expr()->isNull($filter));
            } else {
                $qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
            }
        }

        // Apply search conditions.
        if (empty($searchConditions) === false) {
            $qb->andWhere('('.implode(' OR ', $searchConditions).')');
            foreach ($searchParams as $param => $value) {
                $qb->setParameter($param, $value);
            }
        }

        // Execute the query and return the results.
        return $this->findEntities($qb);

    }//end findAll()


}//end class
