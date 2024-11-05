<?php

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Db\Register;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * The RegisterMapper class
 * 
 * @package OCA\OpenRegister\Db
 */
class RegisterMapper extends QBMapper
{
	/**
	 * Constructor for the RegisterMapper
	 *
	 * @param IDBConnection $db The database connection
	 */
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'openregister_registers');
	}

	/**
	 * Finds a register by id
	 *
	 * @param int $id The id of the register
	 * @return Register The register
	 */
	public function find(int $id): Register
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_registers')
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
			);

		return $this->findEntity(query: $qb);
	}

	/**
	 * Finds all registers
	 *
	 * @param int|null $limit The limit of the results
	 * @param int|null $offset The offset of the results
	 * @param array|null $filters The filters to apply
	 * @param array|null $searchConditions The search conditions to apply
	 * @param array|null $searchParams The search parameters to apply
	 * @return array The registers
	 */
	public function findAll(?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $searchConditions = [], ?array $searchParams = []): array
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_registers')
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
	 * Creates a register from an array
	 *
	 * @param array $object The object to create
	 * @return Register The created register
	 */
	public function createFromArray(array $object): Register
	{
		$register = new Register();
		$register->hydrate(object: $object);

		// Set uuid if not provided
		if ($register->getUuid() === null) {
			$register->setUuid(Uuid::v4());
		}

		return $this->insert(entity: $register);
	}

	/**
	 * Updates a register from an array
	 *
	 * @param int $id The id of the register to update
	 * @param array $object The object to update
	 * @return Register The updated register
	 */
	public function updateFromArray(int $id, array $object): Register
	{
		$register = $this->find($id);
		$register->hydrate($object);

		return $this->update(entity: $register);
	}
}
