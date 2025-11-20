<?php

declare(strict_types=1);

/*
 * Mapper for entity relations.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 */

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class EntityRelationMapper
 *
 * @method EntityRelation insert(Entity $entity)
 * @method EntityRelation update(Entity $entity)
 * @method EntityRelation insertOrUpdate(Entity $entity)
 * @method EntityRelation delete(Entity $entity)
 * @method EntityRelation find(int|string $id)
 * @method EntityRelation findEntity(IQueryBuilder $query)
 * @method EntityRelation[] findAll(int|null $limit = null, int|null $offset = null)
 * @method EntityRelation[] findEntities(IQueryBuilder $query)
 */
class EntityRelationMapper extends QBMapper
{


    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openregister_entity_relations', EntityRelation::class);

    }//end __construct()


    /**
     * Find relations by entity ID.
     *
     * @param int $entityId Entity identifier.
     *
     * @return EntityRelation[]
     */
    public function findByEntity(int $entityId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('entity_id', $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_INT))
            )
            ->orderBy('created_at', 'DESC');

        return $this->findEntities($qb);

    }//end findByEntity()


}//end class
