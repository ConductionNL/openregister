<?php
/**
 * OpenRegister File Mapper
 *
 * This file contains the class for handling file related operations
 * in the OpenRegister application.
 *
 * @category  Database
 * @package   OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version   GIT: <git-id>
 *
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * File Mapper class for database operations
 *
 * Handles all database operations related to File entities
 *
 * @package OCA\OpenRegister\Db
 */
class FileMapper extends QBMapper
{
    /**
     * Constructor for FileMapper.
     *
     * @param IDBConnection $db Database connection instance.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openregister_files');

    }//end __construct()

    /**
     * Finds a File entity by its ID.
     *
     * @param int $id The ID of the file to find.
     *
     * @throws Exception If a database error occurs.
     * @throws DoesNotExistException If no file is found with the given ID.
     * @throws MultipleObjectsReturnedException If multiple files are found with the given ID.
     *
     * @return \OCA\OpenRegister\Db\File The found file entity.
     */
    public function find(int $id): File
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_files')
            ->where(
                $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
            );

        return $this->findEntity(query: $qb);

    }//end find()

    /**
     * Retrieves all File entities with optional filtering, search, and pagination.
     *
     * @param int|null   $limit            Maximum number of results to return.
     * @param int|null   $offset           Number of results to skip.
     * @param array|null $filters          Key-value pairs to filter results.
     * @param array|null $searchConditions Search conditions for query.
     * @param array|null $searchParams     Parameters for search conditions.
     *
     * @throws Exception If a database error occurs.
     *
     * @return array List of File entities.
     */
    public function findAll(
        ?int $limit = null,
        ?int $offset = null,
        ?array $filters = [],
        ?array $searchConditions = [],
        ?array $searchParams = []
    ): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_files')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        foreach ($filters as $filter => $value) {
            $filter = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $filter));
            if ($value === 'IS NOT NULL') {
                $qb->andWhere($qb->expr()->isNotNull($filter));
            } elseif ($value === 'IS NULL') {
                $qb->andWhere($qb->expr()->isNull($filter));
            } else {
                $qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
            }
        }

        if (empty($searchConditions) === false) {
            $qb->andWhere('('.implode(' OR ', $searchConditions).')');
            foreach ($searchParams as $param => $value) {
                $qb->setParameter($param, $value);
            }
        }

        return $this->findEntities(query: $qb);

    }//end findAll()

    /**
     * Inserts a File entity into the database
     *
     * Overrides the parent method to set additional fields
     *
     * @param \OCA\OpenRegister\Db\File|Entity $entity The entity to insert
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return \OCA\OpenRegister\Db\File The inserted entity with updated ID
     */
    public function insert(File | Entity $entity): File
    {
        // Set created and updated fields.
        $entity->setCreated(new DateTime());
        $entity->setUpdated(new DateTime());

        if ($entity->getUuid() === null) {
            $entity->setUuid(Uuid::v4());
        }

        return parent::insert($entity);

    }//end insert()

    /**
     * Updates a File entity in the database
     *
     * Overrides the parent method to update timestamp
     *
     * @param \OCA\OpenRegister\Db\File|Entity $entity The entity to update
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return \OCA\OpenRegister\Db\File The updated entity
     */
    public function update(File | Entity $entity): File
    {
        // Set updated field.
        $entity->setUpdated(new DateTime());

        return parent::update($entity);

    }//end update()

    /**
     * Creates a File entity from an array of data.
     *
     * @param array $object The data to create the entity from.
     *
     * @throws Exception If a database error occurs.
     *
     * @return \OCA\OpenRegister\Db\File The created File entity.
     */
    public function createFromArray(array $object): File
    {
        $obj = new File();
        $obj->hydrate($object);
        // Set UUID.
        if ($obj->getUuid() === null) {
            $obj->setUuid(Uuid::v4());
        }

        return $this->insert(entity: $obj);

    }//end createFromArray()

    /**
     * Updates a File entity by its ID using an array of data.
     *
     * @param int   $id     The ID of the file to update.
     * @param array $object The data to update the entity with.
     *
     * @throws DoesNotExistException If no file is found with the given ID.
     * @throws Exception If a database error occurs.
     * @throws MultipleObjectsReturnedException If multiple files are found with the given ID.
     *
     * @return \OCA\OpenRegister\Db\File The updated File entity.
     */
    public function updateFromArray(int $id, array $object): File
    {
        $obj = $this->find($id);
        $obj->hydrate($object);

        // Set or update the version.
        $version = explode('.', $obj->getVersion());
        $version[2] = ((int) $version[2] + 1);
        $obj->setVersion(implode('.', $version));

        return $this->update($obj);

    }//end updateFromArray()

    /**
     * Gets the total count of files.
     *
     * @throws Exception If a database error occurs.
     *
     * @return int The total number of files in the database.
     */
    public function countAll(): int
    {
        $qb = $this->db->getQueryBuilder();

        // Select count of all files.
        $qb->select($qb->createFunction('COUNT(*) as count'))
            ->from('openregister_files');

        $result = $qb->execute();
        $row = $result->fetch();

        // Return the total count.
        return (int) $row['count'];

    }//end countAll()

}//end class
