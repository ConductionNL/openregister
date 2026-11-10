<?php

/**
 * AgentMapperInterface: the public surface of AgentMapper, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\Db
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

namespace OCA\OpenRegister\Kernel\Contracts;

use OCA\OpenRegister\Mcp\Db\AgentAudience;
use OCA\OpenRegister\Db\ListQuery;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

/**
 * Every public method of AgentMapper. Callers depend on this interface, not on the class.
 */
interface AgentMapperInterface {

	/**
	 * Find an agent by its ID
	 *
	 * @param int $id \OCA\OpenRegister\Mcp\Db\Agent ID
	 *
	 * @return \OCA\OpenRegister\Mcp\Db\Agent The agent entity
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws \Exception If user doesn't have read permission
	 */
	public function find(int $id): Entity;

	/**
	 * Find an agent by its UUID
	 *
	 * @param string $uuid \OCA\OpenRegister\Mcp\Db\Agent UUID
	 *
	 * @return \OCA\OpenRegister\Mcp\Db\Agent The agent entity
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws \Exception If user doesn't have read permission
	 */
	public function findByUuid(string $uuid): Entity;

	/**
	 * Find agents accessible by a user in an organisation
	 *
	 * Filters agents based on:
	 * - Agents in the organisation
	 * - Non-private agents (is_private = false)
	 * - Private agents owned by the user
	 * - Private agents where user is invited
	 *
	 * @param AgentAudience $audience The organisation and, for access filtering, the user (null = no filtering)
	 * @param ListQuery     $page     The limit and offset.
	 *
	 * @return (\OCA\OpenRegister\Mcp\Db\Agent|OCA\OpenRegister\Mcp\Db\Agent)[]
	 *
	 * @throws \Exception If user doesn't have read permission
	 *
	 * @psalm-return list<\OCA\OpenRegister\Mcp\Db\Agent>
	 */
	public function findByOrganisation(AgentAudience $audience, ListQuery $page): array;

	/**
	 * Check if user can access an agent
	 *
	 * Access rules:
	 * - Non-private agents: anyone in the organisation can access
	 * - Private agents: only owner or invited users can access
	 *
	 * @param \OCA\OpenRegister\Mcp\Db\Agent $agent Agent entity
	 * @param string $userId User ID
	 *
	 * @return bool True if user can access
	 */
	public function canUserAccessAgent(Entity $agent, string $userId): bool;

	/**
	 * Check if user can modify an agent
	 *
	 * Modification rules:
	 * - Only the owner can modify the agent
	 *
	 * @param \OCA\OpenRegister\Mcp\Db\Agent $agent Agent entity
	 * @param string $userId User ID
	 *
	 * @return bool True if user can modify
	 */
	public function canUserModifyAgent(Entity $agent, string $userId): bool;

	/**
	 * Find all agents with optional filters
	 *
	 * @param ListQuery $query The limit (ListQuery::unbounded() for none), offset, filter criteria and order.
	 *
	 * @return \OCA\OpenRegister\Mcp\Db\Agent[]
	 *
	 * @throws \Exception If user doesn't have read permission
	 *
	 * @psalm-return list<\OCA\OpenRegister\Mcp\Db\Agent>
	 */
	public function findAll(ListQuery $query): array;

	/**
	 * Insert a new agent
	 *
	 * @param \OCA\OpenRegister\Mcp\Db\Agent $entity Agent entity to insert
	 *
	 * @return \OCA\OpenRegister\Mcp\Db\Agent The inserted agent with updated ID
	 * @throws \Exception If user doesn't have create permission
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Update an existing agent
	 *
	 * @param \OCA\OpenRegister\Mcp\Db\Agent $entity Agent entity to update
	 *
	 * @return \OCA\OpenRegister\Mcp\Db\Agent The updated agent
	 * @throws \Exception If user doesn't have update permission or access to this organisation
	 */
	public function update(Entity $entity): Entity;

	/**
	 * Delete an agent
	 *
	 * @param \OCA\OpenRegister\Mcp\Db\Agent $entity Agent entity to delete
	 *
	 * @return \OCA\OpenRegister\Mcp\Db\Agent The deleted agent
	 * @throws \Exception If user doesn't have delete permission or access to this organisation
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 */
	public function delete(Entity $entity): Entity;

	/**
	 * Create an agent from an array
	 *
	 * @param array $data The agent data
	 *
	 * @return \OCA\OpenRegister\Mcp\Db\Agent The created agent
	 */
	public function createFromArray(array $data): Entity;

	/**
	 * Count agents with optional filters
	 *
	 * @param array|null $filters Filter criteria
	 *
	 * @return int Total count of agents
	 * @throws \Exception If user doesn't have read permission
	 */
	public function count(?array $filters = []): int;
}//end interface
