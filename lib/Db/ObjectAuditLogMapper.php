<?php

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Db\ObjectAuditLog;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

class ObjectAuditLogMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'openregister_object_audit_logs');
	}

	public function find(int $id): ObjectAuditLog
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_object_audit_logs')
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
			);

		return $this->findEntity(query: $qb);
	}

	public function findAll(?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $searchConditions = [], ?array $searchParams = []): array
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_object_audit_logs')
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

	public function createFromArray(array $object): ObjectAuditLog
	{
		$obj = new ObjectAuditLog();
		$obj->hydrate($object);
		// Set uuid
		if ($obj->getUuid() === null) {
			$obj->setUuid(Uuid::v4());
		}

		return $this->insert(entity: $obj);
	}

	public function updateFromArray(int $id, array $object): ObjectAuditLog
	{
		$obj = $this->find($id);
		$obj->hydrate($object);

		// Set or update the version
		if (isset($object['version']) === false) {
			$version = explode('.', $obj->getVersion());
			$version[2] = (int)$version[2] + 1;
			$obj->setVersion(implode('.', $version));
		}


		return $this->update($obj);
	}
}
