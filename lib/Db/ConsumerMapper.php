<?php

/**
 * Consumer Mapper for database operations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Maps Consumer entities to the database.
 *
 * @package OCA\OpenRegister\Db
 *
 * @template-extends                     QBMapper<Consumer>
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class ConsumerMapper extends QBMapper implements ConsumerMapperInterface {

	use ColumnFiltersTrait;

	/**
	 * ConsumerMapper constructor.
	 *
	 * @param IDBConnection $db The database connection
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_consumers');

	}//end __construct()

	/**
	 * Find a Consumer by its ID.
	 *
	 * @param int $id The ID of the Consumer
	 *
	 * @return Consumer The found Consumer entity
	 */
	public function find(int $id): Consumer {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_consumers')
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
			);

		return $this->findEntity(query: $qb);
	}//end find()

	/**
	 * Find all Consumers with optional filtering and pagination.
	 *
	 * @param int|null $limit Maximum number of results to return
	 * @param int|null $offset Number of results to skip
	 * @param array|null $filters Associative array of filters
	 * @param array|null $searchConditions Array of search conditions
	 * @param array|null $searchParams Array of search parameters
	 *
	 * @return array An array of Consumer entities
	 */
	public function findAll(
		int|null $limit = null,
		int|null $offset = null,
		array|null $filters = [],
		array|null $searchConditions = [],
		array|null $searchParams = [],
	): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_consumers')
			->setMaxResults($limit)
			->setFirstResult($offset);

		$this->applyColumnFilters(qb: $qb, filters: $filters);
		$this->applySearchConditions(qb: $qb, searchConditions: $searchConditions, searchParams: $searchParams);

		return $this->findEntities(query: $qb);
	}//end findAll()

	/**
	 * OR the raw search conditions into the query and bind their parameters.
	 *
	 * Nothing is added, and no parameter is bound, when there are no conditions.
	 *
	 * @param IQueryBuilder $qb               The query to add the conditions to.
	 * @param array|null    $searchConditions SQL fragments, joined with OR.
	 * @param array|null    $searchParams     Parameter name to value for those fragments.
	 *
	 * @return void
	 */
	private function applySearchConditions(IQueryBuilder $qb, array|null $searchConditions, array|null $searchParams): void {
		if (empty($searchConditions) === true) {
			return;
		}

		$qb->andWhere('(' . implode(separator: ' OR ', array: $searchConditions) . ')');
		foreach ($searchParams as $param => $value) {
			$qb->setParameter($param, $value);
		}
	}//end applySearchConditions()

	/**
	 * Create a new Consumer from an array of data.
	 *
	 * @param array $object An array of Consumer data
	 *
	 * @return Consumer The newly created Consumer entity
	 */
	public function createFromArray(array $object): Consumer {
		$obj = new Consumer();
		$obj->hydrate($object);

		if ($obj->getUuid() === null) {
			$obj->setUuid(Uuid::v4());
		}

		return $this->insert(entity: $obj);
	}//end createFromArray()

	/**
	 * Update an existing Consumer from an array of data.
	 *
	 * @param int $id The ID of the Consumer to update
	 * @param array $object An array of updated Consumer data
	 *
	 * @return Consumer The updated Consumer entity
	 */
	public function updateFromArray(int $id, array $object): Consumer {
		$obj = $this->find(id: $id);
		$obj->hydrate(object: $object);

		return $this->update(entity: $obj);
	}//end updateFromArray()

	/**
	 * Get the total count of all consumers.
	 *
	 * @return int The total number of consumers in the database.
	 */
	public function getTotalCallCount(): int {
		$qb = $this->db->getQueryBuilder();

		$qb->select($qb->createFunction('COUNT(*) as count'))
			->from('openregister_consumers');

		$result = $qb->executeQuery();
		$row = $result->fetch();

		return (int)$row['count'];
	}//end getTotalCallCount()
}//end class
