<?php

namespace OCA\OpenRegister\Db;

use DateTime;
use OCA\OpenRegister\Db\File;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

class FileMapper extends QBMapper
{
	/**
	 * Constructor for FileMapper.
	 *
	 * @param IDBConnection $db Database connection instance.
	 */
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'openregister_files');
	}

	/**
	 * Finds a File entity by its ID.
	 *
	 * @param int $id The ID of the file to find.
	 *
	 * @return \OCA\OpenRegister\Db\File The found file entity.
	 * @throws Exception If a database error occurs.
	 * @throws DoesNotExistException If no file is found with the given ID.
	 * @throws MultipleObjectsReturnedException If multiple files are found with the given ID.
	 */
	public function find(int $identifier): File
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_files')
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_INT))
			);

		return $this->findEntity(query: $qb);
	}

	/**
	 * Retrieves all File entities with optional filtering, search, and pagination.
	 *
	 * @param int|null $limit Maximum number of results to return.
	 * @param int|null $offset Number of results to skip.
	 * @param array|null $filters Key-value pairs to filter results.
	 * @param array|null $searchConditions Search conditions for query.
	 * @param array|null $searchParams Parameters for search conditions.
	 *
	 * @return array List of File entities.
	 * @throws Exception If a database error occurs.
	 */
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

		if ($entity->getUuid() === null) {
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

	/**
	 * Creates a File entity from an array of data.
	 *
	 * @param array $object The data to create the entity from.
	 *
	 * @return \OCA\OpenRegister\Db\File The created File entity.
	 * @throws Exception If a database error occurs.
	 */
	public function createFromArray(array $object): File
	{
		$obj = new File();
		$obj->hydrate($object);
		// Set UUID
		if ($obj->getUuid() === null) {
			$obj->setUuid(Uuid::v4());
		}
		return $this->insert(entity: $obj);
	}

	/**
	 * Updates a File entity by its ID using an array of data.
	 *
	 * @param int $id The ID of the file to update.
	 * @param array $object The data to update the entity with.
	 *
	 * @return \OCA\OpenRegister\Db\File The updated File entity.
	 * @throws DoesNotExistException If no file is found with the given ID.
	 * @throws Exception If a database error occurs.
	 * @throws MultipleObjectsReturnedException If multiple files are found with the given ID.
	 */
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
	 * Gets the total count of files.
	 *
	 * @return int The total number of files in the database.
	 * @throws Exception If a database error occurs.
	 */
	public function countAll(): int
	{
		$qb = $this->db->getQueryBuilder();

		// Select count of all files
		$qb->select($qb->createFunction('COUNT(*) as count'))
			->from('openregister_files');

		$result = $qb->execute();
		$row = $result->fetch();

		// Return the total count
		return (int)$row['count'];
	}
}
