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

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
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
	 *
	 * @return array An array of Consumer entities
	 *
	 * @spec exclude Persistence primitive: builds the ListQuery and delegates to findPage().
	 */
	public function findAll(?int $limit = null, ?int $offset = null, ?array $filters = []): array {
		return $this->findPage(query: new ListQuery(limit: ($limit ?? ListQuery::UNBOUNDED), offset: $offset, filters: ($filters ?? [])));
	}//end findAll()

	/**
	 * One page of Consumers, narrowed by column filters.
	 *
	 * @param ListQuery $query The page (unbounded for every row) and the column filters.
	 *
	 * @return Consumer[] The matching Consumer entities.
	 *
	 * @spec exclude Persistence primitive: a paged column-filter read with no behaviour of its own.
	 */
	public function findPage(ListQuery $query): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_consumers')
			->setMaxResults($query->limitOrNull())
			->setFirstResult($query->offset);

		$this->applyColumnFilters(qb: $qb, filters: $query->filters);

		return $this->findEntities(query: $qb);
	}//end findPage()

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
