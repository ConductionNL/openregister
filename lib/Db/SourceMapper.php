<?php

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Db\Source;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * The SourceMapper class
 *
 * @package OCA\OpenRegister\Db
 */
class SourceMapper extends QBMapper
{


    /**
     * Constructor for the SourceMapper
     *
     * @param IDBConnection $db The database connection
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openregister_sources');

    }//end __construct()


    /**
     * Finds a source by id
     *
     * @param  int $id The id of the source
     * @return Source The source
     */
    public function find(int $id): Source
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_sources')
            ->where(
                $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
            );

        return $this->findEntity(query: $qb);

    }//end find()


    /**
     * Finds all sources
     *
     * @param  int|null   $limit            The limit of the results
     * @param  int|null   $offset           The offset of the results
     * @param  array|null $filters          The filters to apply
     * @param  array|null $searchConditions The search conditions to apply
     * @param  array|null $searchParams     The search parameters to apply
     * @return array The sources
     */
    public function findAll(?int $limit=null, ?int $offset=null, ?array $filters=[], ?array $searchConditions=[], ?array $searchParams=[]): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_sources')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        foreach ($filters as $filter => $value) {
            if ($value === 'IS NOT NULL') {
                $qb->andWhere($qb->expr()->isNotNull($filter));
            } else if ($value === 'IS NULL') {
                $qb->andWhere($qb->expr()->isNull($filter));
            } else {
                $qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
            }
        }

        if (!empty($searchConditions)) {
            $qb->andWhere('('.implode(' OR ', $searchConditions).')');
            foreach ($searchParams as $param => $value) {
                $qb->setParameter($param, $value);
            }
        }

        return $this->findEntities(query: $qb);

    }//end findAll()


    /**
     * Creates a source from an array
     *
     * @param  array $object The object to create
     * @return Source The created source
     */
    public function createFromArray(array $object): Source
    {
        $source = new Source();
        $source->hydrate(object: $object);

        // Set uuid if not provided
        if ($source->getUuid() === null) {
            $source->setUuid(Uuid::v4());
        }

        return $this->insert(entity: $source);

    }//end createFromArray()


    /**
     * Updates a source from an array
     *
     * @param  int   $id     The id of the source to update
     * @param  array $object The object to update
     * @return Source The updated source
     */
    public function updateFromArray(int $id, array $object): Source
    {
        $obj = $this->find($id);
        $obj->hydrate($object);

        // Set or update the version
        if (isset($object['version']) === false) {
            $version    = explode('.', $obj->getVersion());
            $version[2] = ((int) $version[2] + 1);
            $obj->setVersion(implode('.', $version));
        }

        return $this->update($obj);

    }//end updateFromArray()


}//end class
