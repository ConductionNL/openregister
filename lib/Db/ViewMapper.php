<?php
/**
 * OpenRegister View Mapper
 *
 * This file contains the class for handling view mapper related operations
 * in the OpenRegister application.
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
use Symfony\Component\Uid\Uuid;

/**
 * The ViewMapper class
 *
 * @package OCA\OpenRegister\Db
 */
class ViewMapper extends QBMapper
{

    /**
     * Constructor for ViewMapper
     *
     * @param IDBConnection $db The database connection
     *
     * @return void
     */
    public function __construct(
        IDBConnection $db
    ) {
        parent::__construct($db, 'openregister_view');
    }//end __construct()


    /**
     * Find a view by its ID
     *
     * @param int|string $id The ID of the view to find
     *
     * @return View The found view
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If view not found
     */
    public function find($id): View
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->eq('uuid', $qb->createNamedParameter($id, IQueryBuilder::PARAM_STR))
                )
            );

        return $this->findEntity(query: $qb);
    }//end find()


    /**
     * Find all views for a specific owner
     *
     * @param string $owner The owner user ID
     *
     * @return array Array of View entities
     */
    public function findAll(?string $owner = null): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName());

        if ($owner !== null) {
            $qb->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('owner', $qb->createNamedParameter($owner, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->eq('is_public', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
                )
            );
        }

        $qb->orderBy('created', 'DESC');

        return $this->findEntities(query: $qb);
    }//end findAll()


    /**
     * Create a new view from an Entity
     *
     * @param Entity $entity The view entity to create
     *
     * @return View The created view
     */
    public function insert(Entity $entity): View
    {
        // Generate UUID if not present
        if (empty($entity->getUuid()) === true) {
            $entity->setUuid(Uuid::v4());
        }

        // Set timestamps
        $entity->setCreated(new \DateTime());
        $entity->setUpdated(new \DateTime());

        return parent::insert(entity: $entity);
    }//end insert()


    /**
     * Update an existing view
     *
     * @param Entity $entity The view entity to update
     *
     * @return View The updated view
     */
    public function update(Entity $entity): View
    {
        // Update timestamp
        $entity->setUpdated(new \DateTime());

        return parent::update(entity: $entity);
    }//end update()


    /**
     * Delete a view by ID
     *
     * @param int|string $id The ID of the view to delete
     *
     * @return void
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If view not found
     */
    public function deleteById($id): void
    {
        $entity = $this->find($id);
        $this->delete($entity);
    }//end deleteById()


}//end class


