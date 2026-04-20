<?php

/**
 * OpenRegister Selection List Mapper
 *
 * Handles database operations for selection list entries.
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

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Mapper class for SelectionList entities.
 *
 * @method SelectionList insert(Entity $entity)
 * @method SelectionList update(Entity $entity)
 * @method SelectionList delete(Entity $entity)
 *
 * @template-extends QBMapper<SelectionList>
 *
 * @psalm-suppress PossiblyUnusedMethod
 */
class SelectionListMapper extends QBMapper
{
    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'openregister_selection_lists');
    }//end __construct()

    /**
     * Find a selection list entry by its database ID.
     *
     * @param int $id The database ID
     *
     * @return SelectionList
     *
     * @throws DoesNotExistException If no entry found
     */
    public function find(int $id): SelectionList
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity(query: $qb);
    }//end find()

    /**
     * Find a selection list entry by its UUID.
     *
     * @param string $uuid The UUID
     *
     * @return SelectionList
     *
     * @throws DoesNotExistException If no entry found
     */
    public function findByUuid(string $uuid): SelectionList
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

        return $this->findEntity(query: $qb);
    }//end findByUuid()

    /**
     * Find selection list entries by category.
     *
     * @param string $category The category code
     *
     * @return SelectionList[]
     */
    public function findByCategory(string $category): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('category', $qb->createNamedParameter($category)));

        return $this->findEntities(query: $qb);
    }//end findByCategory()

    /**
     * Find all selection list entries.
     *
     * @param int|null $limit  Maximum number of entries to return
     * @param int|null $offset Offset for pagination
     *
     * @return SelectionList[]
     */
    public function findAll(?int $limit=null, ?int $offset=null): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('category', 'ASC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities(query: $qb);
    }//end findAll()

    /**
     * Create a new selection list entry with auto-generated UUID.
     *
     * @param SelectionList $entity The entity to create
     *
     * @return SelectionList The created entity
     */
    public function createEntry(SelectionList $entity): SelectionList
    {
        if ($entity->getUuid() === null) {
            $entity->setUuid(Uuid::v4()->toRfc4122());
        }

        $entity->setCreated(new \DateTime());
        $entity->setUpdated(new \DateTime());

        return $this->insert(entity: $entity);
    }//end createEntry()

    /**
     * Update an existing selection list entry.
     *
     * @param SelectionList $entity The entity to update
     *
     * @return SelectionList The updated entity
     */
    public function updateEntry(SelectionList $entity): SelectionList
    {
        $entity->setUpdated(new \DateTime());

        return $this->update(objectId: $entity);
    }//end updateEntry()
}//end class
