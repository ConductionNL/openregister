<?php

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Db\Schema;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * The SchemaMapper class
 *
 * @package OCA\OpenRegister\Db
 */
class SchemaMapper extends QBMapper
{
	/**
	 * Constructor for the SchemaMapper
	 *
	 * @param IDBConnection $db The database connection
	 */
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'openregister_schemas');
	}

	/**
	 * Finds a schema by id
	 *
	 * @param int $id The id of the schema
	 * @return Schema The schema
	 */
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

	/**
	 * Finds multiple schemas by id
	 *
	 * @param array $ids The ids of the schemas
	 * @return array The schemas
	 */
	public function findMultiple(array $ids): array
	{
		$result = [];
		foreach ($ids as $id) {
			$result[] = $this->find($id);
		}

		return $result;
	}


	/**
	 * Finds all schemas
	 *
	 * @param int|null $limit The limit of the results
	 * @param int|null $offset The offset of the results
	 * @param array|null $filters The filters to apply
	 * @param array|null $searchConditions The search conditions to apply
	 * @param array|null $searchParams The search parameters to apply
	 * @return array The schemas
	 */
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

	/**
	 * Creates a schema from an array
	 *
	 * @param array $object The object to create
	 * @return Schema The created schema
	 */
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

	/**
	 * Updates a schema from an array
	 *
	 * @param int $id The id of the schema to update
	 * @param array $object The object to update
	 * @return Schema The updated schema
	 */
	public function updateFromArray(int $id, array $object): Schema
	{
		$obj = $this->find($id);
		$obj->hydrate($object);

		// Set or update the version
		if (isset($object['version']) === false) {
			$version = explode('.', $obj->getVersion());
			$version[2] = (int) $version[2] + 1;
			$obj->setVersion(implode('.', $version));
		}

		return $this->update($obj);
	}
}
