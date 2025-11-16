<?php

declare(strict_types=1);

/**
 * Mapper for object text entities.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 */

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class ObjectTextMapper
 */
class ObjectTextMapper extends QBMapper
{
    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openregister_object_texts', ObjectText::class);
    }

    /**
     * Find text by object ID.
     *
     * @param int $objectId Object identifier.
     *
     * @return ObjectText[]
     */
    public function findByObjectId(int $objectId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('object_id', $qb->createNamedParameter($objectId, IQueryBuilder::PARAM_INT))
            );

        return $this->findEntities($qb);
    }
}




