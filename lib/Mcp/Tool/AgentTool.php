<?php

/**
 * AgentTool
 *
 * LLphant function tool for AI agents to manage other agents.
 * Provides CRUD operations for agents with RBAC enforcement.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tool
 * @package  OCA\OpenRegister\Tool
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Tool;

use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * AgentTool
 *
 * Provides function calling capabilities for AI agents to perform CRUD operations on other agents.
 * All operations respect the agent's configured views, RBAC permissions, and organisation boundaries.
 * Note: An agent can manage other agents but should be mindful of access control and privacy settings.
 *
 * @package OCA\OpenRegister\Tool
 */
class AgentTool extends AbstractTool implements ToolInterface {

	/**
	 * Constructor
	 *
	 * @param ToolCallTrail $trail The session that names the caller and the log of each call.
	 * @param AgentMapper $agentMapper Agent mapper instance
	 */
	public function __construct(
		ToolCallTrail $trail,
		private readonly AgentMapper $agentMapper,
	) {
		parent::__construct(trail: $trail);
	}//end __construct()

	/**
	 * Get the tool name
	 *
	 * @return string Tool name
	 *
	 * @psalm-return 'Agent Management'
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getName(): string {
		return 'Agent Management';
	}//end getName()

	/**
	 * Get the tool description
	 *
	 * @return string The tool description
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getDescription(): string {
		$desc = 'Manage AI agents: list, view, create, update, or delete agents ';
		return $desc . 'with RBAC permissions and organisation boundaries.';
	}//end getDescription()

	/**
	 * The function definitions, one row each: [name, subject, action,
	 * description, [parameter => [type, description]], required], expanded
	 * by {@see ToolFunctionTable::expand()}.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	protected const FUNCTIONS = [
		['list_agents', 'agent', 'list', 'List all agents accessible to current user. Returns name, type, status with privacy settings respected.', [
			'limit' => ['integer', 'Maximum number of results to return (default: 50)'],
			'offset' => ['integer', 'Number of results to skip for pagination (default: 0)'],
		], []],
		['get_agent', 'agent', 'get', 'Get detailed agent information by UUID. Returns configuration, system prompt, model settings, and tools.', [
			'uuid' => ['string', 'UUID of the agent to retrieve'],
		], ['uuid']],
		['create_agent', 'agent', 'create', 'Create a new AI agent. Requires name and system prompt. Configure model, temperature, tools, and privacy.', [
			'name' => ['string', 'Name of the agent (required)'],
			'description' => ['string', 'Description of what the agent does'],
			'type' => ['string', 'Type of agent (e.g., "assistant", "support", "analyzer")'],
			'systemPrompt' => ['string', 'System prompt that defines the agent\'s behavior and personality'],
		], ['name']],
		['update_agent', 'agent', 'update', 'Update an existing agent. Only the owner can modify agents. Provide the UUID and fields to update.', [
			'uuid' => ['string', 'UUID of the agent to update'],
			'name' => ['string', 'New name for the agent'],
			'description' => ['string', 'New description'],
			'systemPrompt' => ['string', 'New system prompt'],
		], ['uuid']],
		['delete_agent', 'agent', 'delete', 'Permanently delete agent (owner only). Deletes all associated conversations. Cannot be undone.', [
			'uuid' => ['string', 'UUID of the agent to delete'],
		], ['uuid']],
	];

	/**
	 * List agents
	 *
	 * @param int $limit Maximum number of results (default: 50)
	 * @param int $offset Offset for pagination (default: 0)
	 *
	 * @return (bool|mixed|string)[] Response with agents list
	 *
	 * @psalm-return array{success: bool, error?: string, details?: mixed, message?: string, data?: mixed}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function listAgents(int $limit = 50, int $offset = 0): array {
		try {
			$this->logger->info(
				message: '[AgentTool] Listing agents',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'limit' => $limit,
					'offset' => $offset,
				]
			);

			// Get agents via mapper (RBAC is enforced in mapper).
			$agents = $this->agentMapper->findAll(limit: $limit, offset: $offset);
			$total = $this->agentMapper->count();

			// Convert to array.
			$results = array_map(fn ($agent) => $agent->jsonSerialize(), $agents);

			return $this->formatSuccess(
				data: [
					'agents' => $results,
					'total' => $total,
					'limit' => $limit,
					'offset' => $offset,
				],
				message: "Found {$total} agents."
			);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[AgentTool] Failed to list agents',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
			return $this->formatError(message: 'Failed to list agents: ' . $e->getMessage());
		}//end try
	}//end listAgents()

	/**
	 * Get agent details
	 *
	 * @param string $uuid Agent UUID
	 *
	 * @return (bool|mixed|string)[] Response with agent details
	 *
	 * @psalm-return array{success: bool, error?: string, details?: mixed, message?: string, data?: mixed}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getAgent(string $uuid): array {
		try {
			$this->logger->info(
				message: '[AgentTool] Getting agent',
				context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $uuid]
			);

			// Find agent (RBAC enforced in mapper).
			$agent = $this->agentMapper->findByUuid(uuid: $uuid);

			return $this->formatSuccess(
				data: $agent->jsonSerialize(),
				message: "Agent '{$agent->getName()}' retrieved successfully."
			);
		} catch (DoesNotExistException $e) {
			return $this->formatError(message: "Agent with UUID '{$uuid}' not found.");
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[AgentTool] Failed to get agent',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $uuid,
					'error' => $e->getMessage(),
				]
			);
			return $this->formatError(message: 'Failed to get agent: ' . $e->getMessage());
		}//end try
	}//end getAgent()

	/**
	 * Create agent
	 *
	 * @param CreateAgentCommand $command The agent's name and optional description, type and system prompt
	 *
	 * @return (bool|mixed|string)[] Response with created agent
	 *
	 * @psalm-return array{success: bool, error?: string, details?: mixed, message?: string, data?: mixed}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function createAgent(CreateAgentCommand $command): array {
		return $this->insertAgent(
			name: $command->name,
			fields: ['setDescription' => $command->description, 'setType' => $command->type, 'setPrompt' => $command->systemPrompt]
		);
	}//end createAgent()

	/**
	 * Insert a new agent and report the outcome.
	 *
	 * @param string                     $name   Agent name
	 * @param array<string, string|null> $fields The optional fields, keyed by setter; null or empty is skipped.
	 *
	 * @return (bool|mixed|string)[] Response with created agent
	 *
	 * @psalm-return array{success: bool, error?: string, details?: mixed, message?: string, data?: mixed}
	 */
	private function insertAgent(string $name, array $fields): array {
		try {
			$this->logger->info(
				message: '[AgentTool] Creating agent',
				context: ['file' => __FILE__, 'line' => __LINE__, 'name' => $name]
			);

			// Save via mapper (RBAC and organisation are enforced in mapper).
			$agent = $this->agentMapper->insert($this->newAgent(name: $name, fields: $fields));

			return $this->formatSuccess(
				data: $agent->jsonSerialize(),
				message: "Agent '{$name}' created successfully with UUID {$agent->getUuid()}."
			);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[AgentTool] Failed to create agent',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'name' => $name,
					'error' => $e->getMessage(),
				]
			);
			return $this->formatError(message: 'Failed to create agent: ' . $e->getMessage());
		}//end try
	}//end insertAgent()

	/**
	 * A new agent entity with its name, the non-empty optional fields and the context's owner.
	 *
	 * @param string                     $name   Agent name
	 * @param array<string, string|null> $fields The optional fields, keyed by setter; null or empty is skipped.
	 *
	 * @return Agent The unsaved agent.
	 */
	private function newAgent(string $name, array $fields): Agent {
		$agent = new Agent();
		$agent->setName($name);

		foreach ($fields as $setter => $value) {
			if ($value !== null && $value !== '') {
				$agent->$setter($value);
			}
		}

		// Set current user as owner if we have agent context.
		if ($this->agent !== null) {
			$agent->setOwner($this->agent->getOwner());
		}

		return $agent;
	}//end newAgent()

	/**
	 * Update agent
	 *
	 * @param UpdateAgentCommand $command The agent's UUID and the fields to change; null keeps one
	 *
	 * @return (bool|mixed|string)[] Response with updated agent
	 *
	 * @psalm-return array{success: bool, error?: string, details?: mixed, message?: string, data?: mixed}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function updateAgent(UpdateAgentCommand $command): array {
		return $this->patchAgent(
			uuid: $command->uuid,
			fields: ['setName' => $command->name, 'setDescription' => $command->description, 'setPrompt' => $command->systemPrompt]
		);
	}//end updateAgent()

	/**
	 * Change the non-null fields of an agent and report the outcome.
	 *
	 * @param string                     $uuid   Agent UUID
	 * @param array<string, string|null> $fields The fields, keyed by setter; null keeps one.
	 *
	 * @return (bool|mixed|string)[] Response with updated agent
	 *
	 * @psalm-return array{success: bool, error?: string, details?: mixed, message?: string, data?: mixed}
	 */
	private function patchAgent(string $uuid, array $fields): array {
		try {
			$this->logger->info(
				message: '[AgentTool] Updating agent',
				context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $uuid]
			);

			// Find agent (RBAC enforced in mapper).
			$agent = $this->agentMapper->findByUuid(uuid: $uuid);

			// Update fields.
			foreach ($fields as $setter => $value) {
				if ($value !== null) {
					$agent->$setter($value);
				}
			}

			// Save changes (RBAC enforced in mapper).
			$agent = $this->agentMapper->update($agent);

			return $this->formatSuccess(
				data: $agent->jsonSerialize(),
				message: 'Agent updated successfully.'
			);
		} catch (DoesNotExistException $e) {
			return $this->formatError(message: "Agent with UUID '{$uuid}' not found.");
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[AgentTool] Failed to update agent',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $uuid,
					'error' => $e->getMessage(),
				]
			);
			return $this->formatError(message: 'Failed to update agent: ' . $e->getMessage());
		}//end try
	}//end patchAgent()

	/**
	 * Delete agent
	 *
	 * @param string $uuid Agent UUID
	 *
	 * @return (bool|mixed|string)[] Response confirming deletion
	 *
	 * @psalm-return array{success: bool, error?: string, details?: mixed, message?: string, data?: mixed}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function deleteAgent(string $uuid): array {
		try {
			$this->logger->info(
				message: '[AgentTool] Deleting agent',
				context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $uuid]
			);

			// Find agent (RBAC enforced in mapper).
			$agent = $this->agentMapper->findByUuid(uuid: $uuid);
			$name = $agent->getName();

			// Delete (RBAC enforced in mapper).
			$this->agentMapper->delete($agent);

			return $this->formatSuccess(
				data: ['uuid' => $uuid],
				message: "Agent '{$name}' deleted successfully."
			);
		} catch (DoesNotExistException $e) {
			return $this->formatError(message: "Agent with UUID '{$uuid}' not found.");
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[AgentTool] Failed to delete agent',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $uuid,
					'error' => $e->getMessage(),
				]
			);
			return $this->formatError(message: 'Failed to delete agent: ' . $e->getMessage());
		}//end try
	}//end deleteAgent()

	/**
	 * Execute a function by name
	 *
	 * @param string $functionName Name of the function to execute
	 * @param array $parameters Function parameters
	 * @param string|null $userId User ID for session context (optional)
	 *
	 * @return array Response
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function executeFunction(string $functionName, array $parameters, ?string $userId = null): array {
		return $this->invokeFunction(functionName: $functionName, parameters: $parameters);
	}//end executeFunction()
}//end class
