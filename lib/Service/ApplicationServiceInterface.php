<?php

/**
 * ApplicationServiceInterface: what callers outside the Service component use of ApplicationService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Application;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The application CRUD the applications endpoint calls on ApplicationService.
 */
interface ApplicationServiceInterface {

	/**
	 * Create a new application
	 *
	 * Creates a new application entity from the provided data array.
	 * Logs the creation process for audit and debugging purposes.
	 *
	 * @param array<string, mixed> $data Application data as key-value pairs
	 *
	 * @return Application The created application entity with assigned ID
	 *
	 * @psalm-return Application
	 *
	 * @spec exclude Facade plumbing: log + delegate to ApplicationMapper::createFromArray, no standalone behavioral contract.
	 */
	public function create(array $data): Application;

	/**
	 * Delete an application
	 *
	 * Deletes an application entity by ID.
	 * First retrieves the entity to ensure it exists, then deletes it.
	 * Throws exception if application does not exist.
	 * Logs the deletion process for audit purposes.
	 *
	 * @param int $id Application database ID
	 *
	 * @return void
	 *
	 * @throws DoesNotExistException If application not found with the given ID
	 *
	 * @spec exclude Facade plumbing: find-then-delete delegation to ApplicationMapper, no standalone behavioral contract.
	 */
	public function delete(int $id): void;

	/**
	 * Get a single application by ID
	 *
	 * Retrieves a specific application entity by its database ID.
	 * Throws exception if application does not exist.
	 *
	 * @param int $id Application database ID
	 *
	 * @return Application The application entity
	 *
	 * @throws DoesNotExistException If application not found with the given ID
	 *
	 * @psalm-return Application
	 *
	 * @spec exclude Facade plumbing: thin delegation to ApplicationMapper::find, no standalone behavioral contract.
	 */
	public function find(int $id): Application;

	/**
	 * Update an existing application
	 *
	 * Updates an existing application entity with new data.
	 * Throws exception if application does not exist.
	 * Logs the update process for audit and debugging purposes.
	 *
	 * @param int $id Application database ID
	 * @param array<string, mixed> $data Application data as key-value pairs to update
	 *
	 * @return Application The updated application entity
	 *
	 * @throws DoesNotExistException If application not found with the given ID
	 *
	 * @psalm-return Application
	 *
	 * @spec exclude Facade plumbing: log + delegate to ApplicationMapper::updateFromArray, no standalone behavioral contract.
	 */
	public function update(int $id, array $data): Application;
}//end interface
