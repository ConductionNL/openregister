<?php

/**
 * Mapper for object text entities.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class ObjectTextMapper
 *
 * @method ObjectText insert(Entity $entity)
 * @method ObjectText update(Entity $entity)
 * @method ObjectText insertOrUpdate(Entity $entity)
 * @method ObjectText delete(Entity $entity)
 * @method ObjectText find(int|string $id)
 * @method ObjectText findEntity(IQueryBuilder $query)
 * @method ObjectText[] findAll(int|null $limit = null, int|null $offset = null)
 * @method ObjectText[] findEntities(IQueryBuilder $query)
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

    }//end __construct()


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

    }//end findByObjectId()


}//end class
