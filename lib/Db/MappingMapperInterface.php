<?php

/**
 * MappingMapperInterface: what other components ask of MappingMapper.
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
 * The mapping queries and writes that callers outside lib/Db use.
 *
 * Bound to MappingMapper in DbRegistrar.
 */
interface MappingMapperInterface {

	/**
	 * Create a new mapping from array data
	 *
	 * @param array $data Mapping data
	 *
	 * @return Mapping
	 * @throws \Exception
	 */
	public function createFromArray(array $data): Mapping;

	/**
	 * Delete a mapping
	 *
	 * @param Entity $entity Mapping entity to delete
	 *
	 * @return Mapping
	 * @throws \Exception
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 */
	public function delete(Entity $entity): Mapping;

	/**
	 * Find a single mapping by ID, UUID, or slug
	 *
	 * Retrieves mapping by ID with organisation filtering for multi-tenancy.
	 * Throws exception if mapping not found or doesn't belong to current organisation.
	 *
	 * @param int|string $id Mapping ID, UUID, or slug to find
	 * @param bool $includeNullOrg Include mappings with no organisation set
	 *
	 * @return Mapping The found mapping entity
	 *
	 * @throws DoesNotExistException If mapping not found or not accessible
	 * @throws MultipleObjectsReturnedException If multiple mappings found (should not happen)
	 */
	public function find(int|string $id, bool $includeNullOrg = true): Mapping;

	/**
	 * Find all mappings
	 *
	 * Retrieves all mappings with optional pagination and organisation filtering.
	 * Applies multi-tenancy filter to return only mappings for current organisation.
	 *
	 * @param int|null $limit Maximum number of results to return (null = no limit)
	 * @param int|null $offset Starting offset for pagination (null = no offset)
	 *
	 * @return Mapping[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Mapping>
	 */
	public function findAll(?int $limit = null, ?int $offset = null): array;

	/**
	 * Find mappings by reference
	 *
	 * @param string $reference The reference value to search for
	 *
	 * @return Mapping[] Array of mapping entities
	 */
	public function findByRef(string $reference): array;

	/**
	 * Update a mapping from array data
	 *
	 * @param int $id Mapping ID
	 * @param array $data Updated mapping data
	 *
	 * @return Mapping
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws \Exception
	 */
	public function updateFromArray(int $id, array $data): Mapping;
}//end interface
