<?php

namespace OCA\OpenRegister\Db;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\IDatabaseJsonService;
use OCA\OpenRegister\Service\MySQLJsonService;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * The ObjectEntityMapper class
 *
 * @package OCA\OpenRegister\Db
 */
class ObjectEntityMapper extends QBMapper
{
	private IDatabaseJsonService $databaseJsonService;

	public const MAIN_FILTERS = ['register', 'schema', 'uuid', 'created', 'updated'];

	/**
	 * Constructor for the ObjectEntityMapper
	 *
	 * @param IDBConnection $db The database connection
	 * @param MySQLJsonService $mySQLJsonService The MySQL JSON service
	 */
	public function __construct(IDBConnection $db, MySQLJsonService $mySQLJsonService)
	{
		parent::__construct($db, 'openregister_objects');

		if ($db->getDatabasePlatform() instanceof MySQLPlatform === true) {
			$this->databaseJsonService = $mySQLJsonService;
		}
	}

	/**
	 * Find an object by ID or UUID
	 *
	 * @param int|string $idOrUuid The ID or UUID of the object to find
	 * @return ObjectEntity The ObjectEntity
	 */
	public function find($idOrUuid): ObjectEntity
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_objects')
			->where(
				$qb->expr()->orX(
					$qb->expr()->eq('id', $qb->createNamedParameter($idOrUuid, IQueryBuilder::PARAM_INT)),
					$qb->expr()->eq('uuid', $qb->createNamedParameter($idOrUuid, IQueryBuilder::PARAM_STR))
				)
			);

		return $this->findEntity($qb);
	}

	/**
	 * Find an object by UUID
	 *
	 * @param string $uuid The UUID of the object to find
	 * @return ObjectEntity The object
	 */
	public function findByUuid(Register $register, Schema $schema, string $uuid): ObjectEntity|null
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_objects')
			->where(
				$qb->expr()->eq('uuid', $qb->createNamedParameter($uuid))
			)
			->andWhere(
				$qb->expr()->eq('register', $qb->createNamedParameter($register->getId()))
			)
			->andWhere(
				$qb->expr()->eq('schema', $qb->createNamedParameter($schema->getId()))
			);

		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Find objects by register and schema
	 *
	 * @param string $register The register to find objects for
	 * @param string $schema The schema to find objects for
	 * @return array An array of ObjectEntitys
	 */
	public function findByRegisterAndSchema(string $register, string $schema): ObjectEntity
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_objects')
			->where(
				$qb->expr()->eq('register', $qb->createNamedParameter($register))
			)
			->andWhere(
				$qb->expr()->eq('schema', $qb->createNamedParameter($schema))
			);

		return $this->findEntities(query: $qb);
	}

	/**
	 * Counts all objects
	 *
	 * @param array|null $filters The filters to apply
	 * @param string|null $search The search string to apply
	 * @return int The number of objects
	 */
	public function countAll(?array $filters = [], ?string $search = null): int
	{
		$qb = $this->db->getQueryBuilder();

		$qb->selectAlias(select: $qb->createFunction(call: 'count(id)'), alias: 'count')
			->from(from: 'openregister_objects');
		foreach ($filters as $filter => $value) {
			if ($value === 'IS NOT NULL' && in_array(needle: $filter, haystack: self::MAIN_FILTERS) === true) {
				$qb->andWhere($qb->expr()->isNotNull($filter));
			} elseif ($value === 'IS NULL' && in_array(needle: $filter, haystack: self::MAIN_FILTERS) === true) {
				$qb->andWhere($qb->expr()->isNull($filter));
			} else if (in_array(needle: $filter, haystack: self::MAIN_FILTERS) === true) {
				$qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
			}
		}

		$qb = $this->databaseJsonService->filterJson($qb, $filters);
		$qb = $this->databaseJsonService->searchJson($qb, $search);

		$result = $qb->executeQuery();

		return $result->fetchAll()[0]['count'];
	}

	/**
	 * Find all ObjectEntitys
	 *
	 * @param int $limit The number of objects to return
	 * @param int $offset The offset of the objects to return
	 * @param array $filters The filters to apply to the objects
	 * @param array $searchConditions The search conditions to apply to the objects
	 * @param array $searchParams The search parameters to apply to the objects
	 * @return array An array of ObjectEntitys
	 */
	public function findAll(?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $searchConditions = [], ?array $searchParams = [], array $sort = [], ?string $search = null): array
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_objects')
			->setMaxResults($limit)
			->setFirstResult($offset);

        foreach ($filters as $filter => $value) {
			if ($value === 'IS NOT NULL' && in_array(needle: $filter, haystack: self::MAIN_FILTERS) === true) {
				$qb->andWhere($qb->expr()->isNotNull($filter));
			} elseif ($value === 'IS NULL' && in_array(needle: $filter, haystack: self::MAIN_FILTERS) === true) {
				$qb->andWhere($qb->expr()->isNull($filter));
			} else if (in_array(needle: $filter, haystack: self::MAIN_FILTERS) === true) {
				$qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
			}
        }

        if (!empty($searchConditions)) {
            $qb->andWhere('(' . implode(' OR ', $searchConditions) . ')');
            foreach ($searchParams as $param => $value) {
                $qb->setParameter($param, $value);
            }
        }

		$qb = $this->databaseJsonService->filterJson(builder: $qb, filters: $filters);
		$qb = $this->databaseJsonService->searchJson(builder: $qb, search: $search);
		$qb = $this->databaseJsonService->orderJson(builder: $qb, order: $sort);

//		var_dump($qb->getSQL());

		return $this->findEntities(query: $qb);
	}

	/**
	 * Creates an object from an array
	 *
	 * @param array $object The object to create
	 * @return ObjectEntity The created object
	 */
	public function createFromArray(array $object): ObjectEntity
	{
		$obj = new ObjectEntity();
		$obj->hydrate(object: $object);
		if ($obj->getUuid() === null) {
			$obj->setUuid(Uuid::v4());
		}
		return $this->insert($obj);
	}

	/**
	 * Updates an object from an array
	 *
	 * @param int $id The id of the object to update
	 * @param array $object The object to update
	 * @return ObjectEntity The updated object
	 */
	public function updateFromArray(int $id, array $object): ObjectEntity
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
	 * Gets the facets for the objects
	 *
	 * @param array $filters The filters to apply
	 * @param string|null $search The search string to apply
	 * @return array The facets
	 */
	public function getFacets(array $filters = [], ?string $search = null)
	{
		if (key_exists(key: 'register', array: $filters) === true) {
			$register = $filters['register'];
		}
		if (key_exists(key: 'schema', array: $filters) === true) {
			$schema = $filters['schema'];
		}

		$fields = [];
		if (isset($filters['_queries'])) {
			$fields = $filters['_queries'];
		}

		unset(
			$filters['_fields'],
			$filters['register'],
			$filters['schema'],
			$filters['created'],
			$filters['updated'],
			$filters['uuid']
		);

		return $this->databaseJsonService->getAggregations(
			builder: $this->db->getQueryBuilder(),
			fields: $fields,
			register: $register,
			schema: $schema,
			filters: $filters,
			search: $search
		);
	}
}
