<?php

/**
 * ConfigurationMapperInterface: the surface of ConfigurationMapper that callers type against.
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

use DateTime;
use OCP\AppFramework\Db\Entity;

/**
 * The methods of ConfigurationMapper that its callers use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to ConfigurationMapper. See ConfigurationMapper for what
 * each read scopes to the active organisation.
 */
interface ConfigurationMapperInterface {

	/**
	 * Find a configuration by its ID.
	 *
	 * @param integer $id            Configuration ID.
	 * @param boolean $_multitenancy Whether to apply multi-tenancy rules.
	 *
	 * @return Configuration The configuration entity.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int $id, bool $_multitenancy = true): Configuration;

	/**
	 * Find configurations by app.
	 *
	 * @param string  $app          App identifier.
	 * @param integer $limit        Maximum number of results.
	 * @param integer $offset       Offset for pagination.
	 * @param boolean $systemLookup When true, bypass the organisation filter.
	 *
	 * @return Configuration[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Configuration>
	 */
	public function findByApp(string $app, int $limit = 50, int $offset = 0, bool $systemLookup = false): array;

	/**
	 * Find a configuration by source URL.
	 *
	 * @param string  $sourceUrl    Source URL to search for.
	 * @param boolean $systemLookup When true, bypass the organisation filter.
	 *
	 * @return Configuration|null The configuration, or null if not found.
	 */
	public function findBySourceUrl(string $sourceUrl, bool $systemLookup = false): ?Configuration;

	/**
	 * Find configurations that have sync enabled.
	 *
	 * @param integer $limit  Maximum number of results.
	 * @param integer $offset Offset for pagination.
	 *
	 * @return Configuration[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Configuration>
	 */
	public function findBySyncEnabled(int $limit = 50, int $offset = 0): array;

	/**
	 * Update synchronization status for a configuration.
	 *
	 * @param integer  $id       Configuration ID.
	 * @param string   $status   Sync status: 'success', 'failed', 'pending'.
	 * @param DateTime $syncDate Synchronization timestamp.
	 * @param string   $_message Optional message about the sync result.
	 *
	 * @return Configuration The updated configuration.
	 */
	public function updateSyncStatus(int $id, string $status, DateTime $syncDate, string $_message = ''): Configuration;

	/**
	 * Insert a new configuration.
	 *
	 * @param Entity $entity Configuration entity to insert.
	 *
	 * @return Configuration The inserted configuration.
	 */
	public function insert(Entity $entity): Configuration;

	/**
	 * Update a configuration.
	 *
	 * @param Entity $entity Configuration entity to update.
	 *
	 * @return Configuration The updated configuration.
	 */
	public function update(Entity $entity): Configuration;

	/**
	 * Delete a configuration.
	 *
	 * @param Entity $entity Configuration entity to delete.
	 *
	 * @return Configuration The deleted configuration.
	 */
	public function delete(Entity $entity): Entity;

	/**
	 * Create a configuration from an array.
	 *
	 * @param array $data The configuration data.
	 *
	 * @return Configuration The created configuration.
	 */
	public function createFromArray(array $data): Configuration;

	/**
	 * Update a configuration from an array.
	 *
	 * @param integer $id   The configuration ID.
	 * @param array   $data The configuration data.
	 *
	 * @return Configuration The updated configuration.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If the configuration is not found.
	 */
	public function updateFromArray(int $id, array $data): Configuration;

	/**
	 * Find all configurations.
	 *
	 * @param integer|null $limit            The limit of the results.
	 * @param integer|null $offset           The offset of the results.
	 * @param array|null   $filters          The filters to apply.
	 * @param array|null   $searchConditions Array of search conditions.
	 * @param array|null   $searchParams     Array of search parameters.
	 * @param boolean      $_multitenancy    Whether to apply multitenancy filtering.
	 *
	 * @return Configuration[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Configuration>
	 */
	public function findAll(
		?int $limit = null,
		?int $offset = null,
		?array $filters = [],
		?array $searchConditions = [],
		?array $searchParams = [],
		bool $_multitenancy = true,
	): array;
}//end interface
