<?php
/**
 * OpenRegister DataAccessProfile Mapper
 *
 * This file contains the class for the DataAccessProfile mapper.
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
use OCP\IDBConnection;

/**
 * Class DataAccessProfileMapper
 *
 * Mapper for DataAccessProfile entities.
 *
 * @package OCA\OpenRegister\Db
 *
 * @method DataAccessProfile insert(Entity $entity)
 * @method DataAccessProfile update(Entity $entity)
 * @method DataAccessProfile insertOrUpdate(Entity $entity)
 * @method DataAccessProfile delete(Entity $entity)
 * @method DataAccessProfile find(int|string $id)
 * @method DataAccessProfile findEntity(\OCP\DB\QueryBuilder\IQueryBuilder $query)
 * @method DataAccessProfile[] findAll(int|null $limit = null, int|null $offset = null)
 * @method list<DataAccessProfile> findEntities(\OCP\DB\QueryBuilder\IQueryBuilder $query)
 *
 * @template-extends QBMapper<DataAccessProfile>
 */
class DataAccessProfileMapper extends QBMapper
{


    /**
     * Constructor
     *
     * @param IDBConnection $db Database connection
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openregister_data_access_profiles', DataAccessProfile::class);

    }//end __construct()


}//end class
