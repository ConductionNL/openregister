<?php

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Db\Schema;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

class SchemaMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'openregister_schemas');
	}

	public function find(int $id): Schema
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_schemas')
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
			);

		return $this->findEntity(query: $qb);
	}
	public function findMultiple(array $ids): array
	{
		$result = [];
		foreach($ids as $id) {
			$result[] = $this->find($id);
		}

		return $result;
	}

	public function findAll(?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $searchConditions = [], ?array $searchParams = []): array
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_schemas')
			->setMaxResults($limit)
			->setFirstResult($offset);

        foreach ($filters as $filter => $value) {
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

	public function createFromArray(array $object): Schema
	{
		$schema = new Schema();
		$schema->hydrate(object: $object);

		// Set uuid if not provided
		if ($schema->getUuid() === null) {
			$schema->setUuid(Uuid::v4());
		}
		
		return $this->insert(entity: $schema);
	}

	public function updateFromArray(int $id, array $object): Schema
	{
		$schema = $this->find($id);
		$schema->hydrate($object);

		return $this->update($schema);
	}
}
