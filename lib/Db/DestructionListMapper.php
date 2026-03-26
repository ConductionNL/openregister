<?php

/**
 * OpenRegister Destruction List Mapper
 *
 * Handles database operations for destruction list entities.
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

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Mapper class for DestructionList entities.
 *
 * @method DestructionList insert(Entity $entity)
 * @method DestructionList update(Entity $entity)
 * @method DestructionList delete(Entity $entity)
 *
 * @template-extends QBMapper<DestructionList>
 *
 * @psalm-suppress PossiblyUnusedMethod
 */
class DestructionListMapper extends QBMapper
{

    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openregister_destruction_lists');
    }//end __construct()

    /**
     * Find a destruction list by its database ID.
     *
     * @param int $id The database ID
     *
     * @return DestructionList
     *
     * @throws DoesNotExistException If no entry found
     */
    public function find(int $id): DestructionList
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }//end find()

    /**
     * Find a destruction list by its UUID.
     *
     * @param string $uuid The UUID
     *
     * @return DestructionList
     *
     * @throws DoesNotExistException If no entry found
     */
    public function findByUuid(string $uuid): DestructionList
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

        return $this->findEntity($qb);
    }//end findByUuid()

    /**
     * Find destruction lists by status.
     *
     * @param string $status The status to filter by
     *
     * @return DestructionList[]
     */
    public function findByStatus(string $status): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter($status)))
            ->orderBy('created', 'DESC');

        return $this->findEntities($qb);
    }//end findByStatus()

    /**
     * Find all destruction lists.
     *
     * @param int|null $limit  Maximum number of entries to return
     * @param int|null $offset Offset for pagination
     *
     * @return DestructionList[]
     */
    public function findAll(?int $limit = null, ?int $offset = null): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('created', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }
        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities($qb);
    }//end findAll()

    /**
     * Create a new destruction list with auto-generated UUID.
     *
     * @param DestructionList $entity The entity to create
     *
     * @return DestructionList The created entity
     */
    public function createEntry(DestructionList $entity): DestructionList
    {
        if ($entity->getUuid() === null) {
            $entity->setUuid(Uuid::v4()->toRfc4122());
        }
        if ($entity->getStatus() === null) {
            $entity->setStatus(DestructionList::STATUS_PENDING_REVIEW);
        }
        $entity->setCreated(new \DateTime());
        $entity->setUpdated(new \DateTime());

        return $this->insert($entity);
    }//end createEntry()

    /**
     * Update an existing destruction list.
     *
     * @param DestructionList $entity The entity to update
     *
     * @return DestructionList The updated entity
     */
    public function updateEntry(DestructionList $entity): DestructionList
    {
        $entity->setUpdated(new \DateTime());

        return $this->update($entity);
    }//end updateEntry()
}//end class
