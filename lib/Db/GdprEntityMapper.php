<?php

/**
 * Mapper for GDPR entities.
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

use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class GdprEntityMapper
 *
 * @method GdprEntity insert(Entity $entity)
 * @method GdprEntity update(Entity $entity)
 * @method GdprEntity insertOrUpdate(Entity $entity)
 * @method GdprEntity delete(Entity $entity)
 * @method GdprEntity find(int|string $id)
 * @method GdprEntity findEntity(IQueryBuilder $query)
 * @method GdprEntity[] findAll(int|null $limit = null, int|null $offset = null)
 * @method list<GdprEntity> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<GdprEntity>
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

    }//end __construct()



}//end class
