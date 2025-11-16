<?php

declare(strict_types=1);

/**
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
    }

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
    }
}




