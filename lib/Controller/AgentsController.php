<?php

/**
 * OpenRegister Agents Controller
 *
 * This file contains the controller for managing AI agents.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/chat-ai/spec.md
 * @spec openspec/specs/chat-ai/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Controller\Support\AgentsControllerServices;
use OCA\OpenRegister\Controller\Support\ChatFamilyControllerInterface;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Kernel\Contracts\AgentMapperInterface;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCA\OpenRegister\Mcp\Service\ToolRegistryInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * AgentsController handles REST API endpoints for AI agent management
 *
 * Provides REST API endpoints for managing AI agents including CRUD operations,
 * RBAC checks, tool management, and statistics. RBAC filtering is handled in
 * the mapper layer.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/openapi-generation/spec.md
 */
class AgentsController extends Controller implements ChatFamilyControllerInterface {

	/**
	 * Agent flags that default to true on create, keyed camelCase => snake_case
	 * (either spelling in the request counts as given).
	 *
	 * @var array<string, string>
	 */
	private const DEFAULT_TRUE_FLAGS = [
		'isPrivate' => 'is_private',
		'searchFiles' => 'search_files',
		'searchObjects' => 'search_objects',
	];

	/**
	 * Agent mapper for database operations
	 *
	 * Handles all database CRUD operations for agent entities with RBAC support.
	 *
	 * @var AgentMapperInterface Agent mapper instance
	 */
	private readonly AgentMapperInterface $agentMapper;

	/**
	 * Organisation service
	 *
	 * Used to get active organisation for multi-tenancy filtering.
	 *
	 * @var OrganisationServiceInterface Organisation service instance
	 */
	private readonly OrganisationServiceInterface $organisationService;

	/**
	 * Tool registry
	 *
	 * Provides access to all registered tools from all apps for agent configuration.
	 *
	 * @var ToolRegistryInterface Tool registry instance
	 */
	private readonly ToolRegistryInterface $toolRegistry;

	/**
	 * Logger for debugging and error tracking
	 *
	 * Used for logging errors, debug information, and operation tracking.
	 *
	 * @var LoggerInterface Logger instance
	 */
	private readonly LoggerInterface $logger;

	/**
	 * User ID
	 *
	 * Current user ID for RBAC checks and ownership validation.
	 *
	 * @var string|null User ID or null if not authenticated
	 */
	private readonly ?string $userId;

	/**
	 * Constructor
	 *
	 * Initializes controller with required dependencies for agent operations.
	 * Calls parent constructor to set up base controller functionality.
	 *
	 * @param IRequest $request HTTP request object
	 * @param AgentsControllerServices $services The agents controller services.
	 * @param string|null $userId Current user ID for RBAC checks
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		AgentsControllerServices $services,
		?string $userId,
	) {
		// Call parent constructor to initialize base controller.
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

		// Store dependencies for use in controller methods.
		$this->agentMapper = $services->agentMapper;
		$this->organisationService = $services->organisationService;
		$this->toolRegistry = $services->toolRegistry;
		$this->logger = $services->logger;
		$this->userId = $userId;
	}//end __construct()

	/**
	 * Get all agents accessible by current user
	 *
	 * RBAC filtering is handled in the mapper layer.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse List of agents
	 *
	 * @psalm-return JSONResponse<200|500, array{error?: 'Failed to retrieve agents',
	 *     results?: array<Agent>}, array<never, never>>
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function index(): JSONResponse {
		try {
			$params = $this->request->getParams();
			$limit  = (int)($params['_limit'] ?? 50);
			$offset = $this->pageOffset(params: $params, limit: $limit);
			$agents = $this->findAgents(limit: $limit, offset: $offset);

			return new JSONResponse(data: ['results' => $agents], statusCode: Http::STATUS_OK);
		} catch (Exception $e) {
			$context = ['line' => __LINE__, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
			$this->logFailure(message: 'Failed to get agents', context: $context);
			return $this->error(error: 'Failed to retrieve agents', statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end index()

	/**
	 * Resolve the list offset: `_page` (1-based) wins over `_offset`.
	 *
	 * @param array<string, mixed> $params The request parameters
	 * @param int                  $limit  The page size
	 *
	 * @return int The offset to read from
	 */
	private function pageOffset(array $params, int $limit): int {
		if (isset($params['_page']) === true) {
			return ((int)$params['_page'] - 1) * $limit;
		}

		return (int)($params['_offset'] ?? 0);
	}//end pageOffset()

	/**
	 * Read one page of agents.
	 *
	 * With an active organisation the mapper filters by organisation and user
	 * (multi-tenancy and RBAC); without one it lists global/legacy agents.
	 *
	 * @param int $limit  The page size
	 * @param int $offset The offset
	 *
	 * @return array<Agent> The agents
	 */
	private function findAgents(int $limit, int $offset): array {
		$organisationUuid = $this->organisationService->getActiveOrganisation()?->getUuid();
		if ($organisationUuid === null) {
			return $this->agentMapper->findAll(limit: $limit, offset: $offset);
		}

		return $this->agentMapper->findByOrganisation(
			organisationUuid: $organisationUuid,
			userId: $this->userId,
			limit: $limit,
			offset: $offset
		);
	}//end findAgents()

	/**
	 * Get a single agent
	 *
	 * Retrieves a specific agent by its database ID.
	 * Performs additional RBAC check using mapper method to verify user access.
	 *
	 * @param int $id Agent database ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response containing agent details
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200, Agent,
	 *     array<never, never>>|JSONResponse<403|404,
	 *     array{error: 'Access denied to this agent'|'Agent not found'},
	 *     array<never, never>>
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function show(int $id): JSONResponse {
		try {
			$agent = $this->agentMapper->find($id);
			if ($this->agentMapper->canUserAccessAgent(agent: $agent, userId: $this->userId ?? '') === false) {
				return $this->error(error: 'Access denied to this agent', statusCode: Http::STATUS_FORBIDDEN);
			}

			return new JSONResponse(data: $agent, statusCode: Http::STATUS_OK);
		} catch (Exception $e) {
			$context = ['line' => __LINE__, 'id' => $id, 'error' => $e->getMessage()];
			$this->logFailure(message: 'Failed to get agent', context: $context);
			return $this->error(error: 'Agent not found', statusCode: Http::STATUS_NOT_FOUND);
		}
	}//end show()

	/**
	 * Create a new agent
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with created agent
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function create(): JSONResponse {
		try {
			$agent = $this->agentMapper->createFromArray($this->newAgentData());
			$this->logger->info(
				message: '[AgentsController] Agent created successfully',
				context: ['file' => __FILE__, 'line' => __LINE__, 'id' => $agent->getId(),
					'organisation' => $agent->getOrganisation(), 'isPrivate' => $agent->getIsPrivate()]
			);

			return new JSONResponse(data: $agent, statusCode: Http::STATUS_CREATED);
		} catch (Exception $e) {
			$context = ['line' => __LINE__, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
			$this->logFailure(message: 'Failed to create agent', context: $context);
			return $this->error(error: 'Failed to create agent: '.$e->getMessage(), statusCode: Http::STATUS_BAD_REQUEST);
		}
	}//end create()

	/**
	 * The data for a new agent: the request parameters, with the active
	 * organisation and the current user forced (users cannot set them) and the
	 * boolean flags defaulted to true when given in neither spelling.
	 *
	 * @return array<string, mixed> The agent data
	 */
	private function newAgentData(): array {
		$data = $this->request->getParams();
		unset($data['_route']);
		$data['organisation'] = $this->organisationService->getActiveOrganisation()?->getUuid();
		$data['owner']        = $this->userId;
		foreach (self::DEFAULT_TRUE_FLAGS as $flag => $snakeCase) {
			if (isset($data[$flag]) === false && isset($data[$snakeCase]) === false) {
				$data[$flag] = true;
			}
		}

		return $data;
	}//end newAgentData()

	/**
	 * Update an existing agent
	 *
	 * @param int $id Agent ID
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with updated agent
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function update(int $id): JSONResponse {
		try {
			$agent = $this->agentMapper->find($id);
			if ($this->agentMapper->canUserModifyAgent(agent: $agent, userId: $this->userId ?? '') === false) {
				return $this->error(error: 'You do not have permission to modify this agent', statusCode: Http::STATUS_FORBIDDEN);
			}

			$updatedAgent = $this->agentMapper->update($this->applyChanges(agent: $agent, data: $this->request->getParams()));
			$this->logged(message: 'Agent updated successfully', id: $id);

			return new JSONResponse(data: $updatedAgent, statusCode: Http::STATUS_OK);
		} catch (Exception $e) {
			$context = ['line' => __LINE__, 'id' => $id, 'error' => $e->getMessage()];
			$this->logFailure(message: 'Failed to update agent', context: $context);
			return $this->error(error: 'Failed to update agent: '.$e->getMessage(), statusCode: Http::STATUS_BAD_REQUEST);
		}
	}//end update()

	/**
	 * Hydrate an agent from request data, never letting the data change its
	 * id, creation time, organisation or owner (no privilege escalation).
	 *
	 * @param Agent                $agent The agent to change
	 * @param array<string, mixed> $data  The request parameters
	 *
	 * @return Agent The changed agent
	 */
	private function applyChanges(Agent $agent, array $data): Agent {
		$currentOrganisation = $agent->getOrganisation();
		$currentOwner        = $agent->getOwner();
		unset($data['_route'], $data['id'], $data['created'], $data['organisation'], $data['owner']);

		$agent->hydrate($data);
		$agent->setOrganisation($currentOrganisation);
		$agent->setOwner($currentOwner);

		return $agent;
	}//end applyChanges()

	/**
	 * Patch (partially update) an agent
	 *
	 * Partially updates an agent entity (PATCH method).
	 * Delegates to update() method which handles partial updates.
	 *
	 * @param int $id The ID of the agent to patch
	 *
	 * @return JSONResponse JSON response with updated agent or error.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function patch(int $id): JSONResponse {
		// Delegate to update method (both handle partial updates).
		return $this->update(id: $id);
	}//end patch()

	/**
	 * Delete an agent
	 *
	 * RBAC check is handled in the mapper layer.
	 *
	 * @param int $id Agent ID
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Success message
	 *
	 * @psalm-return JSONResponse<200|400|403,
	 *     array{error?: 'Failed to delete agent'|
	 *     'You do not have permission to delete this agent'|
	 *     'User not authenticated', message?: 'Agent deleted successfully'},
	 *     array<never, never>>
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function destroy(int $id): JSONResponse {
		try {
			$agent = $this->agentMapper->find($id);
			$denied = $this->deleteDenied(agent: $agent);
			if ($denied !== null) {
				return $denied;
			}

			return $this->deleteAgent(agent: $agent, id: $id);
		} catch (Exception $e) {
			$context = ['line' => __LINE__, 'id' => $id, 'error' => $e->getMessage()];
			$this->logFailure(message: 'Failed to delete agent', context: $context);
			return $this->error(error: 'Failed to delete agent', statusCode: Http::STATUS_BAD_REQUEST);
		}
	}//end destroy()

	/**
	 * Refuse a delete by an anonymous caller or a user who may not modify the agent.
	 *
	 * @param Agent $agent The agent to delete
	 *
	 * @return JSONResponse|null The 403 response, or null when the delete is allowed
	 */
	private function deleteDenied(Agent $agent): ?JSONResponse {
		if ($this->userId === null) {
			return $this->error(error: 'User not authenticated', statusCode: Http::STATUS_FORBIDDEN);
		}

		if ($this->agentMapper->canUserModifyAgent(agent: $agent, userId: $this->userId) === false) {
			return $this->error(error: 'You do not have permission to delete this agent', statusCode: Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end deleteDenied()

	/**
	 * Delete an agent the caller may delete (the mapper fires the events).
	 *
	 * @param Agent $agent The agent
	 * @param int   $id    The agent id, for the log
	 *
	 * @return JSONResponse The success response
	 */
	private function deleteAgent(Agent $agent, int $id): JSONResponse {
		$this->agentMapper->delete($agent);
		$this->logged(message: 'Agent deleted successfully', id: $id);

		return new JSONResponse(data: ['message' => 'Agent deleted successfully'], statusCode: Http::STATUS_OK);
	}//end deleteAgent()

	/**
	 * Get agent statistics
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Agent statistics
	 *
	 * @psalm-return JSONResponse<200|500,
	 *     array{error?: 'Failed to retrieve statistics', total?: int,
	 *     active?: int, inactive?: int}, array<never, never>>
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function stats(): JSONResponse {
		try {
			$stats = [
				'total' => $this->agentMapper->count([]),
				'active' => $this->agentMapper->count(['active' => true]),
				'inactive' => $this->agentMapper->count(['active' => false]),
			];

			return new JSONResponse(data: $stats, statusCode: Http::STATUS_OK);
		} catch (Exception $e) {
			$context = ['line' => __LINE__, 'error' => $e->getMessage()];
			$this->logFailure(message: 'Failed to get agent statistics', context: $context);
			return $this->error(error: 'Failed to retrieve statistics', statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end stats()

	/**
	 * Get all available tools
	 *
	 * Returns metadata for all registered tools from all apps.
	 * This is used by the frontend agent editor to display available tools.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Capability discovery: returns the static tool-registry metadata (ToolRegistry::getAllTools);
	 *   no caller-supplied object id.
	 *
	 * @return JSONResponse List of available tools with metadata
	 *
	 * @psalm-return JSONResponse<200|500, array{error?: 'Failed to retrieve tools', results?: array}, array<never, never>>
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function tools(): JSONResponse {
		try {
			$tools = $this->toolRegistry->getAllTools();
			$this->logger->debug(
				message: '[AgentsController] Returning available tools',
				context: ['file' => __FILE__, 'line' => __LINE__, 'count' => count($tools)]
			);

			return new JSONResponse(data: ['results' => $tools], statusCode: Http::STATUS_OK);
		} catch (Exception $e) {
			$context = ['line' => __LINE__, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
			$this->logFailure(message: 'Failed to get available tools', context: $context);
			return $this->error(error: 'Failed to retrieve tools', statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end tools()

	/**
	 * Log a failed action as an error.
	 *
	 * @param string               $message The log message, without the controller prefix
	 * @param array<string, mixed> $context The log context, without the file
	 *
	 * @return void
	 */
	private function logFailure(string $message, array $context): void {
		$this->logger->error(message: '[AgentsController] '.$message, context: ['file' => __FILE__] + $context);
	}//end logFailure()

	/**
	 * Log a successful change to one agent.
	 *
	 * @param string $message The message, without the controller prefix
	 * @param int    $id      The agent id
	 *
	 * @return void
	 */
	private function logged(string $message, int $id): void {
		$this->logger->info(message: '[AgentsController] '.$message, context: ['file' => __FILE__, 'line' => __LINE__, 'id' => $id]);
	}//end logged()

	/**
	 * An error response.
	 *
	 * @param string $error      The error message
	 * @param int    $statusCode The HTTP status
	 *
	 * @return JSONResponse The response
	 */
	private function error(string $error, int $statusCode): JSONResponse {
		return new JSONResponse(data: ['error' => $error], statusCode: $statusCode);
	}//end error()
}//end class
