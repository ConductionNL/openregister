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
		$version = explode('.', $obj->getVersion());
		$version[2] = (int)$version[2] + 1;
		$obj->setVersion(implode('.', $version));

		return $this->update($obj);
	}

	/**
	 * Count total number of audit logs
	 * @return int Total number of audit logs
	 */
	public function count(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*) as count'))
		   ->from('openregister_object_audit_logs');

		$result = $qb->executeQuery();
		$count = $result->fetch();
		$result->closeCursor();

		return (int)$count['count'];
	}

	/**
	 * Get daily statistics for audit logs between two dates
	 * Temporarily treats all operations as updates until operation column is added
	 * 
	 * @param \DateTime $from Start date
	 * @param \DateTime $to End date
	 * @return array Daily statistics grouped by operation type
	 */
	public function getDailyStats(\DateTime $from, \DateTime $to): array
	{
		$qb = $this->db->getQueryBuilder();
		
		$qb->select(
				$qb->createFunction('DATE(created) as date'),
				$qb->createFunction('COUNT(*) as total')
			)
			->from('openregister_object_audit_logs')
			->where($qb->expr()->gte('created', $qb->createNamedParameter($from->format('Y-m-d H:i:s'))))
			->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($to->format('Y-m-d H:i:s'))))
			->groupBy('date')
			->orderBy('date', 'ASC');

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		// Initialize the return array with all dates and operations
		$stats = [
			'daily' => [
				'created' => [],
				'updated' => [],
				'deleted' => [],
			],
			'totals' => [
				'created' => 0,
				'updated' => 0,
				'deleted' => 0,
			],
		];

		// Fill in the actual counts - temporarily treating all as updates
		foreach ($rows as $row) {
			$date = $row['date'];
			$count = (int)$row['total'];

			// Set all counts as updates for now
			$stats['daily']['updated'][$date] = $count;
			$stats['totals']['updated'] += $count;
			
			// Set other operations to 0
			$stats['daily']['created'][$date] = 0;
			$stats['daily']['deleted'][$date] = 0;
		}

		// Ensure all dates have values (fill gaps with 0)
		$period = new \DatePeriod(
			$from,
			new \DateInterval('P1D'),
			$to->modify('+1 day')
		);

		foreach ($period as $date) {
			$dateStr = $date->format('Y-m-d');
			foreach (['created', 'updated', 'deleted'] as $operation) {
				if (!isset($stats['daily'][$operation][$dateStr])) {
					$stats['daily'][$operation][$dateStr] = 0;
				}
			}
		}

		return $stats;
	}
}
