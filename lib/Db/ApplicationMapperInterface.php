<?php

/**
 * ApplicationMapperInterface: what callers outside the Db component use of ApplicationMapper.
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

use OCA\OpenRegister\Kernel\Contracts\ApplicationInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

/**
 * The methods of ApplicationMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to ApplicationMapper.
 *
 * The native return types name the Kernel application contract, so a caller
 * below Configuration reads an application without naming the Configuration
 * entity. The template carries the entity class for callers that need it:
 * ApplicationMapper implements `ApplicationMapperInterface<Application>`, and a
 * Configuration caller types its property the same way.
 *
 * @template-covariant TApplication of ApplicationInterface
 */
interface ApplicationMapperInterface {

	/**
	 * Count total applications
	 *
	 * Returns the total number of applications accessible to the current user.
	 * Automatically applies organisation filtering for multi-tenancy.
	 * Useful for pagination calculations and statistics.
	 *
	 * @return int Total number of applications
	 *
	 * @throws \Exception If user doesn't have read permission
	 *
	 * @psalm-return int
	 */
	public function countAll(): int;

	/**
	 * Create an application from an array
	 *
	 * Creates a new application entity from a data array.
	 * Hydrates the entity with provided data and inserts it into the database.
	 *
	 * @param array<string, mixed> $data The application data as key-value pairs
	 *
	 * @return TApplication The created application entity with assigned ID
	 *
	 * @psalm-return TApplication
	 */
	public function createFromArray(array $data): ApplicationInterface;

	/**
	 * Delete an application
	 *
	 * Deletes an application entity from the database.
	 * Verifies user has access to the application's organisation.
	 * Dispatches ApplicationDeletedEvent after successful deletion.
	 *
	 * @param Entity $entity Application entity to delete
	 *
	 * @return TApplication&Entity The deleted application entity
	 *
	 * @throws \Exception If user doesn't have delete permission or access to this organisation
	 *
	 * @psalm-return   TApplication&Entity
	 */
	public function delete(Entity $entity): Entity;

	/**
	 * Find an application by its ID
	 *
	 * Retrieves a single application entity by database ID.
	 * Applies RBAC permission checks and organisation filtering.
	 *
	 * @param int $id Application database ID
	 *
	 * @return TApplication The application entity
	 *
	 * @throws DoesNotExistException If application not found with the given ID
	 * @throws MultipleObjectsReturnedException If multiple applications found (should not happen)
	 * @throws \Exception If user doesn't have read permission
	 *
	 * @psalm-return TApplication
	 */
	public function find(int $id): ApplicationInterface;

	/**
	 * Find all applications
	 *
	 * Retrieves all applications with optional pagination and filtering.
	 * Results are ordered by creation date (newest first).
	 * Automatically applies organisation filtering for multi-tenancy.
	 *
	 * @param int|null $limit Maximum number of results to return (null for all)
	 * @param int|null $offset Number of results to skip for pagination (null for no offset)
	 * @param array<string, mixed> $filters Filter conditions as key-value pairs (default: empty array)
	 *
	 * @return TApplication[]
	 *
	 * @throws \Exception If user doesn't have read permission
	 *
	 * @psalm-return list<TApplication>
	 */
	public function findAll(
		?int $limit = null,
		?int $offset = null,
		array $filters = [],
	): array;

	/**
	 * Find the applications of a listing
	 *
	 * Results are ordered by creation date (newest first). The listing's
	 * limit (unbounded for all rows), offset and equality filters apply; its
	 * sort and search do not. Automatically applies organisation filtering
	 * for multi-tenancy.
	 *
	 * @param ListQuery $query The listing.
	 *
	 * @return TApplication[]
	 *
	 * @throws \Exception If user doesn't have read permission
	 *
	 * @psalm-return list<TApplication>
	 */
	public function findAllBy(ListQuery $query): array;

	/**
	 * Find an application by its UUID
	 *
	 * Retrieves a single application entity by UUID.
	 * Applies RBAC permission checks and organisation filtering.
	 *
	 * @param string $uuid Application UUID (RFC 4122 format)
	 *
	 * @return TApplication The application entity
	 *
	 * @throws DoesNotExistException If application not found with the given UUID
	 * @throws MultipleObjectsReturnedException If multiple applications found (should not happen)
	 * @throws \Exception If user doesn't have read permission
	 *
	 * @psalm-return TApplication
	 */
	public function findByUuid(string $uuid): ApplicationInterface;

	/**
	 * Insert a new application
	 *
	 * Creates a new application entity in the database.
	 * Automatically generates UUID if not set, sets timestamps, and applies organisation.
	 * Dispatches ApplicationCreatedEvent after successful insertion.
	 *
	 * @param Entity $entity Application entity to insert
	 *
	 * @return TApplication&Entity The inserted application with updated ID and timestamps
	 *
	 * @throws \Exception If user doesn't have create permission
	 *
	 * @psalm-return TApplication&Entity
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Update an existing application
	 *
	 * Updates an existing application entity in the database.
	 * Verifies user has access to the application's organisation.
	 * Updates timestamp and dispatches ApplicationUpdatedEvent with old and new state.
	 *
	 * @param Entity $entity Application entity to update
	 *
	 * @return TApplication&Entity The updated application entity
	 *
	 * @throws \Exception If user doesn't have update permission or access to this organisation
	 *
	 * @psalm-return TApplication&Entity
	 */
	public function update(Entity $entity): Entity;

	/**
	 * Update an application from an array
	 *
	 * Updates an existing application entity from a data array.
	 * First retrieves the application by ID, then hydrates it with new data and updates.
	 *
	 * @param int $id The application database ID
	 * @param array<string, mixed> $data The application data as key-value pairs to update
	 *
	 * @return TApplication The updated application entity
	 *
	 * @throws DoesNotExistException If the application is not found with the given ID
	 *
	 * @psalm-return TApplication
	 */
	public function updateFromArray(int $id, array $data): ApplicationInterface;
}//end interface
