<?php
/**
 * OpenRegister Application Mapper
 *
 * This file contains the ApplicationMapper class for database operations on applications.
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
 * Class ApplicationMapper
 *
 * @package OCA\OpenRegister\Db
 *
 * @template-extends QBMapper<Application>
 *
 * @psalm-suppress MissingTemplateParam
 */
class ApplicationMapper extends QBMapper
{

    /**
     * ApplicationMapper constructor.
     *
     * @param IDBConnection $db Database connection instance
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openregister_applications', Application::class);

    }//end __construct()


    /**
     * Find an application by its ID
     *
     * @param int $id Application ID
     *
     * @return Application The application entity
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function find(int $id): Application
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);

    }//end find()


    /**
     * Find an application by its UUID
     *
     * @param string $uuid Application UUID
     *
     * @return Application The application entity
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findByUuid(string $uuid): Application
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));

        return $this->findEntity($qb);

    }//end findByUuid()


    /**
     * Find applications by organisation
     *
     * @param int $organisationId Organisation ID
     * @param int $limit          Maximum number of results
     * @param int $offset         Offset for pagination
     *
     * @return Application[] Array of application entities
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
     * Find all applications
     *
     * @param int|null    $limit             Maximum number of results
     * @param int|null    $offset            Offset for pagination
     * @param array       $filters           Filter conditions
     * @param array       $searchConditions  Search conditions for WHERE clause
     * @param array       $searchParams      Parameters for search conditions
     *
     * @return Application[] Array of application entities
     */
    public function findAll(?int $limit=null, ?int $offset=null, array $filters=[], array $searchConditions=[], array $searchParams=[]): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('created', 'DESC');

        // Apply filters
        foreach ($filters as $key => $value) {
            $qb->andWhere($qb->expr()->eq($key, $qb->createNamedParameter($value)));
        }

        // Apply search conditions
        if (!empty($searchConditions)) {
            $qb->andWhere($qb->expr()->orX(...$searchConditions));
            foreach ($searchParams as $key => $value) {
                $qb->setParameter($key, $value);
            }
        }

        return $this->findEntities($qb);

    }//end findAll()


    /**
     * Insert a new application
     *
     * @param Application $entity Application entity to insert
     *
     * @return Application The inserted application with updated ID
     */
    public function insert(Entity $entity): Entity
    {
        if ($entity instanceof Application) {
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
     * Update an existing application
     *
     * @param Application $entity Application entity to update
     *
     * @return Application The updated application
     */
    public function update(Entity $entity): Entity
    {
        if ($entity instanceof Application) {
            $entity->setUpdated(new DateTime());
        }

        return parent::update($entity);

    }//end update()


    /**
     * Delete an application
     *
     * @param Application $entity Application entity to delete
     *
     * @return Application The deleted application
     */
    public function delete(Entity $entity): Entity
    {
        return parent::delete($entity);

    }//end delete()


    /**
     * Create an application from an array
     *
     * @param array $data The application data
     *
     * @return Application The created application
     */
    public function createFromArray(array $data): Application
    {
        $application = new Application();
        $application->hydrate($data);

        return $this->insert($application);

    }//end createFromArray()


    /**
     * Update an application from an array
     *
     * @param int   $id   The application ID
     * @param array $data The application data
     *
     * @throws DoesNotExistException If the application is not found
     * @return Application The updated application
     */
    public function updateFromArray(int $id, array $data): Application
    {
        $application = $this->find($id);
        $application->hydrate($data);

        return $this->update($application);

    }//end updateFromArray()


    /**
     * Count applications by organisation
     *
     * @param int $organisationId Organisation ID
     *
     * @return int Number of applications
     */
    public function countByOrganisation(int $organisationId): int
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->tableName)
            ->where($qb->expr()->eq('organisation', $qb->createNamedParameter($organisationId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $count  = $result->fetchOne();
        $result->closeCursor();

        return (int) $count;

    }//end countByOrganisation()


    /**
     * Count total applications
     *
     * @return int Total number of applications
     */
    public function countAll(): int
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->tableName);

        $result = $qb->executeQuery();
        $count  = $result->fetchOne();
        $result->closeCursor();

        return (int) $count;

    }//end countAll()


}//end class

