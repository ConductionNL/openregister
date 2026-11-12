<?php

/**
 * EndpointMapperInterface: what callers outside the Db component use of EndpointMapper.
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

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

/**
 * The methods of EndpointMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to EndpointMapper.
 */
interface EndpointMapperInterface {

	/**
	 * Create a new endpoint from array data
	 *
	 * @param array $data Endpoint data
	 *
	 * @return Endpoint
	 * @throws \Exception
	 */
	public function createFromArray(array $data): Endpoint;

	/**
	 * Delete an endpoint
	 *
	 * @param Entity $entity Endpoint entity to delete
	 *
	 * @return Endpoint
	 * @throws \Exception
	 *
	 */
	public function delete(Entity $entity): Endpoint;

	/**
	 * Find a single endpoint by ID
	 *
	 * Retrieves endpoint by ID with organisation filtering for multi-tenancy.
	 * Throws exception if endpoint not found or doesn't belong to current organisation.
	 *
	 * @param int $id Endpoint ID to find
	 *
	 * @return Endpoint The found endpoint entity
	 *
	 * @throws DoesNotExistException If endpoint not found or not accessible
	 * @throws MultipleObjectsReturnedException If multiple endpoints found (should not happen)
	 */
	public function find($id): Endpoint;

	/**
	 * Find all endpoints
	 *
	 * Retrieves all endpoints with optional pagination and organisation filtering.
	 * Applies multi-tenancy filter to return only endpoints for current organisation.
	 *
	 * @param int|null $limit Maximum number of results to return (null = no limit)
	 * @param int|null $offset Starting offset for pagination (null = no offset)
	 *
	 * @return Endpoint[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Endpoint>
	 */
	public function findAll(?int $limit = null, ?int $offset = null): array;

	/**
	 * Update an endpoint from array data
	 *
	 * @param int $id Endpoint ID
	 * @param array $data Updated endpoint data
	 *
	 * @return Endpoint
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws \Exception
	 */
	public function updateFromArray(int $id, array $data): Endpoint;
}//end interface
