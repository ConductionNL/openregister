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

use OCA\OpenRegister\Db\Support\SecretAtRest;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCP\AppFramework\Db\Entity;
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
	 * @param IDBConnection     $db      The database connection
	 * @param SecretAtRest|null $secrets Encrypts the secret configuration values at rest; null stores them as given
	 */
	public function __construct(
		IDBConnection $db,
		private readonly ?SecretAtRest $secrets = null,
	) {
		parent::__construct(db: $db, tableName: 'openregister_consumers');
	}//end __construct()

	/**
	 * Insert a consumer with the secret values of its authorization configuration sealed.
	 *
	 * @param Consumer $entity The consumer.
	 *
	 * @return Consumer The inserted consumer, with its configuration open.
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function insert(Entity $entity): Entity {
		$this->sealConfiguration(entity: $entity);
		try {
			return parent::insert(entity: $entity);
		} finally {
			$this->openConfiguration(entity: $entity);
		}
	}//end insert()

	/**
	 * Update a consumer with the secret values of its authorization configuration sealed.
	 *
	 * @param Consumer $entity The consumer.
	 *
	 * @return Consumer The updated consumer, with its configuration open.
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function update(Entity $entity): Entity {
		$this->sealConfiguration(entity: $entity);
		try {
			return parent::update(entity: $entity);
		} finally {
			$this->openConfiguration(entity: $entity);
		}
	}//end update()

	/**
	 * Seal the secret values of a consumer's configuration before it is written.
	 *
	 * @param Entity $entity The consumer.
	 *
	 * @return void
	 */
	private function sealConfiguration(Entity $entity): void {
		if ($this->secrets !== null && $entity instanceof Consumer) {
			$entity->setAuthorizationConfiguration($this->secrets->sealConfiguration(configuration: $entity->getAuthorizationConfiguration()));
		}
	}//end sealConfiguration()

	/**
	 * Open the secret values of a consumer's configuration again after it was written.
	 *
	 * @param Entity $entity The consumer.
	 *
	 * @return void
	 */
	private function openConfiguration(Entity $entity): void {
		if ($this->secrets !== null && $entity instanceof Consumer) {
			$entity->setAuthorizationConfiguration($this->secrets->openConfiguration(configuration: $entity->getAuthorizationConfiguration()));
		}
	}//end openConfiguration()

	/**
	 * A stored row as a consumer, with the secret values of its configuration opened.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return Entity The consumer.
	 */
	protected function mapRowToEntity(array $row): Entity {
		$stored = ($row['authorization_configuration'] ?? null);
		if ($this->secrets !== null && is_string($stored) === true && $stored !== '') {
			$configuration = json_decode($stored, true);
			if (is_array($configuration) === true) {
				$row['authorization_configuration'] = json_encode($this->secrets->openConfiguration(configuration: $configuration));
			}
		}

		return parent::mapRowToEntity(row: $row);
	}//end mapRowToEntity()

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
