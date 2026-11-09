<?php

/**
 * ConsumerMapperInterface: what callers outside the Db component use of ConsumerMapper.
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

use OCP\AppFramework\Db\Entity;

/**
 * The methods of ConsumerMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to ConsumerMapper. Guarded by C04InterfaceBindingsTest.
 */
interface ConsumerMapperInterface {

	/**
	 * Create a new Consumer from an array of data.
	 *
	 * @param array $object An array of Consumer data
	 *
	 * @return Consumer The newly created Consumer entity
	 */
	public function createFromArray(array $object): Consumer;

	/**
	 * Find a Consumer by its ID.
	 *
	 * @param int $id The ID of the Consumer
	 *
	 * @return Consumer The found Consumer entity
	 */
	public function find(int $id): Consumer;

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
		?int $limit = null,
		?int $offset = null,
		?array $filters = [],
		?array $searchConditions = [],
		?array $searchParams = [],
	): array;

	/**
	 * Get the total count of all consumers.
	 *
	 * @return int The total number of consumers in the database.
	 */
	public function getTotalCallCount(): int;

	/**
	 * Update an existing Consumer from an array of data.
	 *
	 * @param int $id The ID of the Consumer to update
	 * @param array $object An array of updated Consumer data
	 *
	 * @return Consumer The updated Consumer entity
	 */
	public function updateFromArray(int $id, array $object): Consumer;

	/**
	 * Delete one row.
	 *
	 * @param Entity $entity The row to delete.
	 *
	 * @return Entity The deleted row.
	 */
	public function delete(Entity $entity): Entity;
}//end interface
