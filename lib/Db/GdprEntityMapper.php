<?php

declare(strict_types=1);

/**
 * Mapper for GDPR entities.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 */

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class GdprEntityMapper
 */
class GdprEntityMapper extends QBMapper
{
    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openregister_entities', GdprEntity::class);
    }

    /**
     * Find entities by type and value prefix.
     *
     * @param string      $type   Entity type.
     * @param string|null $search Optional search string.
     *
     * @return GdprEntity[]
     */
    public function findByType(string $type, ?string $search = null): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_STR))
            )
            ->orderBy('detected_at', 'DESC');

        if ($search !== null && $search !== '') {
            $qb->andWhere(
                $qb->expr()->like('value', $qb->createNamedParameter('%' . $qb->escapeLikeParameter($search) . '%'))
            );
        }

        return $this->findEntities($qb);
    }
}




