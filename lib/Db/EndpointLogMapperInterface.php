<?php

/**
 * EndpointLogMapperInterface: what callers outside the Db component use of EndpointLogMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCP\AppFramework\Db\Entity;

/**
 * The methods of EndpointLogMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to EndpointLogMapper.
 */
interface EndpointLogMapperInterface {

	/**
	 * Find all rows, newest first.
	 *
	 * @param int|null $limit  Maximum number of rows, or null for all.
	 * @param int|null $offset Rows to skip, or null for none.
	 *
	 * @return \OCP\AppFramework\Db\Entity[] The entities of this mapper.
	 *
	 * @spec exclude shared mapper plumbing, not a capability of its own
	 */
	public function findAll(?int $limit = null, ?int $offset = null): array;

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
	public function findByEndpoint(int $endpointId, ?int $limit = null, ?int $offset = null): array;

	/**
	 * One page of logs, and their total, of the given visible endpoints or of one of them
	 *
	 * @param EndpointLogScope $scope The endpoints the caller can see, optionally narrowed to one
	 * @param ListQuery        $query The page; unbounded for no limit, a null offset for none
	 *
	 * @return array{0: list<EndpointLog>, 1: int<0, max>} The page and the total; empty when the endpoint is not visible
	 *
	 * @spec openspec/specs/production-observability/spec.md#requirement-per-entity-statistics-and-endpoint-delivery-log-api
	 */
	public function findPageWithin(EndpointLogScope $scope, ListQuery $query): array;

	/**
	 * One page of logs, and their total, of the given endpoints
	 *
	 * @param int[]     $endpointIds The endpoints; an empty list matches nothing
	 * @param ListQuery $query       The page; unbounded for no limit, a null offset for none
	 *
	 * @return array{0: list<EndpointLog>, 1: int<0, max>} The page and the total
	 *
	 * @spec openspec/specs/production-observability/spec.md#requirement-per-entity-statistics-and-endpoint-delivery-log-api
	 */
	public function findPage(array $endpointIds, ListQuery $query): array;

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
	public function getStatistics(?int $endpointId = null): array;

	/**
	 * Inherited from QBMapper::insert().
	 *
	 * @param EndpointLog $entity The entity.
	 *
	 * @return EndpointLog
	 *
	 * @spec exclude Inherited persistence primitive; no behaviour of its own.
	 *
	 * @spec exclude Inherited persistence primitive; no behaviour of its own.
	 */
	public function insert(Entity $entity): Entity;
}//end interface
