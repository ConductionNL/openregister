<?php

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class ObjectEntityMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'openregister_objects');
	}

	public function find(int $id): Object
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_objects')
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
			);

		return $this->findEntity(query: $qb);
	}

	public function findAll(?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $searchConditions = [], ?array $searchParams = []): array
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_objects')
			->setMaxResults($limit)
			->setFirstResult($offset);

        foreach($filters as $filter => $value) {
			if ($value === 'IS NOT NULL') {
				$qb->andWhere($qb->expr()->isNotNull($filter));
			} elseif ($value === 'IS NULL') {
				$qb->andWhere($qb->expr()->isNull($filter));
			} else {
				$qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
			}
        }

        if (!empty($searchConditions)) {
            $qb->andWhere('(' . implode(' OR ', $searchConditions) . ')');
            foreach ($searchParams as $param => $value) {
                $qb->setParameter($param, $value);
            }
        }

		return $this->findEntities(query: $qb);
	}

	public function createFromArray(array $object): Object
	{
		$obj = new Object();
		$obj->hydrate(object: $object);
		return $this->insert(entity: $obj);
	}

	public function updateFromArray(int $id, array $object): Object
	{
		$obj = $this->find($id);
		$obj->hydrate($object);

		return $this->update($obj);
	}
}
