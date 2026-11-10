<?php

/**
 * OpenRegister Endpoint Log Mapper
 *
 * Mapper for EndpointLog entities to handle database operations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * EndpointLogMapper handles database operations for EndpointLog entities
 *
 * Mapper for EndpointLog entities to handle database operations for endpoint
 * execution logs. Provides methods for querying logs by endpoint, retrieving
 * statistics, and managing log entries.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @method EndpointLog insert(Entity $entity)
 * @method EndpointLog update(Entity $entity)
 * @method EndpointLog insertOrUpdate(Entity $entity)
 * @method EndpointLog delete(Entity $entity)
 * @method EndpointLog find(int $id)
 * @method EndpointLog findEntity(IQueryBuilder $query)
 * @method EndpointLog[] findAll(int|null $limit=null, int|null $offset=null)
 * @method list<EndpointLog> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<EndpointLog>
 */
class EndpointLogMapper extends QBMapper implements EndpointLogMapperInterface {
	use FindAllNewestFirstTrait;

	/**
	 * Constructor
	 *
	 * Initializes mapper with database connection.
	 * Calls parent constructor to set up base mapper functionality.
	 *
	 * @param IDBConnection $db Database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		// Call parent constructor to initialize base mapper with table name and entity class.
		parent::__construct(db: $db, tableName: 'openregister_endpoint_logs', entityClass: EndpointLog::class);
	}//end __construct()

	/**
	 * Find logs by endpoint ID
	 *
	 * Retrieves all execution logs for a specific endpoint with optional pagination.
	 * Results are ordered by creation date descending (newest first).
	 *
	 * @param int $endpointId Endpoint ID to filter logs by
	 * @param int|null $limit Maximum number of results to return (null = no limit)
	 * @param int|null $offset Starting offset for pagination (null = no offset)
	 *
	 * @return EndpointLog[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\EndpointLog>
	 *
	 * @spec openspec/specs/production-observability/spec.md#requirement-per-entity-statistics-and-endpoint-delivery-log-api
	 */
	public function findByEndpoint(int $endpointId, ?int $limit = null, ?int $offset = null): array {
		return $this->findByEndpoints(endpointIds: [$endpointId], limit: $limit, offset: $offset);
	}//end findByEndpoint()

	/**
	 * Find logs of any of the given endpoints, newest first
	 *
	 * @param int[]    $endpointIds Endpoint IDs to filter logs by; an empty list matches nothing
	 * @param int|null $limit       Maximum number of results to return (null = no limit)
	 * @param int|null $offset      Starting offset for pagination (null = no offset)
	 *
	 * @return EndpointLog[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\EndpointLog>
	 *
	 * @spec openspec/specs/production-observability/spec.md#requirement-per-entity-statistics-and-endpoint-delivery-log-api
	 */
	public function findByEndpoints(array $endpointIds, int|null $limit = null, int|null $offset = null): array {
		if ($endpointIds === []) {
			return [];
		}

		// Step 1: Get query builder instance.
		$qb = $this->db->getQueryBuilder();

		// Step 2: Build SELECT query with endpoint ID filter.
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->in(
					'endpoint_id',
					$qb->createNamedParameter(array_values($endpointIds), IQueryBuilder::PARAM_INT_ARRAY)
				)
			)
			->orderBy('created', 'DESC');

		// Step 3: Apply pagination: a limit and an offset, each only when given.
		$this->applyPaging(qb: $qb, limit: $limit, offset: $offset);

		// Step 4: Execute query and return entities.
		return $this->findEntities(query: $qb);
	}//end findByEndpoints()

	/**
	 * Apply a limit and an offset, each only when given.
	 *
	 * @param IQueryBuilder $qb     The query.
	 * @param int|null      $limit  Maximum rows, or null for no limit.
	 * @param int|null      $offset Rows to skip, or null for none.
	 *
	 * @return void
	 */
	private function applyPaging(IQueryBuilder $qb, int|null $limit, int|null $offset): void {
		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}

		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}
	}//end applyPaging()

	/**
	 * One page of logs, and their total, of the given visible endpoints or of one of them
	 *
	 * @param int[]    $visibleIds The endpoints the caller can see
	 * @param int|null $endpointId One endpoint to narrow to, or null for all visible ones
	 * @param int|null $limit      Maximum number of results to return (null = no limit)
	 * @param int|null $offset     Starting offset for pagination (null = no offset)
	 *
	 * @return array{0: list<EndpointLog>, 1: int<0, max>} The page and the total; empty when the endpoint is not visible
	 *
	 * @spec openspec/specs/production-observability/spec.md#requirement-per-entity-statistics-and-endpoint-delivery-log-api
	 */
	public function findPageWithin(array $visibleIds, ?int $endpointId, ?int $limit = null, ?int $offset = null): array {
		$endpointIds = $visibleIds;
		if ($endpointId !== null) {
			$endpointIds = array_values(array_intersect([$endpointId], $visibleIds));
		}

		return [
			$this->findByEndpoints(endpointIds: $endpointIds, limit: $limit, offset: $offset),
			count($this->findByEndpoints(endpointIds: $endpointIds)),
		];
	}//end findPageWithin()

	/**
	 * Find a single log by ID
	 *
	 * Retrieves endpoint log entry by ID. Throws exception if log not found.
	 *
	 * @param int $id Log ID to find
	 *
	 * @return EndpointLog The found endpoint log entity
	 *
	 * @throws DoesNotExistException If log entry not found
	 * @throws MultipleObjectsReturnedException If multiple log entries found (should not happen)
	 */
	public function find($id): EndpointLog {
		// Step 1: Get query builder instance.
		$qb = $this->db->getQueryBuilder();

		// Step 2: Build SELECT query with ID filter.
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		// Step 3: Execute query and return single entity.
		return $this->findEntity(query: $qb);
	}//end find()

	/**
	 * Get statistics for endpoint logs
	 *
	 * @param int|null $endpointId Optional endpoint ID to filter statistics
	 *
	 * @return int[]
	 *
	 * @phpstan-return array<string, int>
	 *
	 * @psalm-return array{total: int, success: int, failed: int}
	 */
	public function getStatistics(?int $endpointId = null): array {
		$qb = $this->db->getQueryBuilder();

		// Total logs.
		$qb->select($qb->func()->count('*', 'total'))
			->from($this->getTableName());

		if ($endpointId !== null) {
			$qb->where($qb->expr()->eq('endpoint_id', $qb->createNamedParameter($endpointId, IQueryBuilder::PARAM_INT)));
		}

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$total = (int)($row['total'] ?? 0);
		$result->closeCursor();

		// Success logs.
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'success'))
			->from($this->getTableName())
			->where($qb->expr()->gte('status_code', $qb->createNamedParameter(200, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('status_code', $qb->createNamedParameter(300, IQueryBuilder::PARAM_INT)));

		if ($endpointId !== null) {
			$qb->andWhere($qb->expr()->eq('endpoint_id', $qb->createNamedParameter($endpointId, IQueryBuilder::PARAM_INT)));
		}

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$success = (int)($row['success'] ?? 0);
		$result->closeCursor();

		// Failed logs.
		$failed = $total - $success;

		return [
			'total' => $total,
			'success' => $success,
			'failed' => $failed,
		];
	}//end getStatistics()
}//end class
