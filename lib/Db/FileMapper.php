<?php

namespace OCA\OpenRegister\Db;

use DateTime;
use OCA\OpenRegister\Db\File;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

class FileMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'openregister_files');
	}

	public function find(int $id): File
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_files')
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
			);

		return $this->findEntity(query: $qb);
	}

	public function findAll(?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $searchConditions = [], ?array $searchParams = []): array
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_files')
			->setMaxResults($limit)
			->setFirstResult($offset);

        foreach ($filters as $filter => $value) {
			$filter = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $filter));
			if ($value === 'IS NOT NULL') {
				$qb->andWhere($qb->expr()->isNotNull($filter));
			} elseif ($value === 'IS NULL') {
				$qb->andWhere($qb->expr()->isNull($filter));
			} else {
				$qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
			}
        }

		if (empty($searchConditions) === false) {
            $qb->andWhere('(' . implode(' OR ', $searchConditions) . ')');
            foreach ($searchParams as $param => $value) {
                $qb->setParameter($param, $value);
            }
        }

		return $this->findEntities(query: $qb);
	}

	/**
	 * @inheritDoc
	 *
	 * @param \OCA\OpenRegister\Db\File|Entity $entity
	 * @return \OCA\OpenRegister\Db\File
	 * @throws \OCP\DB\Exception
	 */
	public function insert(File|Entity $entity): File
	{
		// Set created and updated fields
		$entity->setCreated(new DateTime());
		$entity->setUpdated(new DateTime());

		if($entity->getUuid() === null) {
			$entity->setUuid(Uuid::v4());
		}

		return parent::insert($entity);
	}

	/**
	 * @inheritDoc
	 *
	 * @param \OCA\OpenRegister\Db\File|Entity $entity
	 * @return \OCA\OpenRegister\Db\File
	 * @throws \OCP\DB\Exception
	 */
	public function update(File|Entity $entity): File
	{
		// Set updated field
		$entity->setUpdated(new DateTime());

		return parent::update($entity);
	}

	public function createFromArray(array $object): File
	{
		$obj = new File();
		$obj->hydrate($object);
		// Set uuid
		if ($obj->getUuid() === null){
			$obj->setUuid(Uuid::v4());
		}
		return $this->insert(entity: $obj);
	}

	public function updateFromArray(int $id, array $object): File
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
     * Get the total count of all call logs.
     *
     * @return int The total number of call logs in the database.
     */
    public function getTotalCallCount(): int
    {
        $qb = $this->db->getQueryBuilder();

        // Select count of all logs
        $qb->select($qb->createFunction('COUNT(*) as count'))
           ->from('openregister_files');

        $result = $qb->execute();
        $row = $result->fetch();

        // Return the total count
        return (int)$row['count'];
    }
}
