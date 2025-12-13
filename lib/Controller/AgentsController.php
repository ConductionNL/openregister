<?php
/**
 * OpenRegister Agents Controller
 *
 * This file contains the controller for managing AI agents.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\ToolRegistry;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Exception;

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
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version  GIT: <git-id>
 *
 * @link     https://OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 */
class AgentsController extends Controller
{

    /**
     * Agent mapper for database operations
     *
     * Handles all database CRUD operations for agent entities with RBAC support.
     *
     * @var AgentMapper Agent mapper instance
     */
    private readonly AgentMapper $agentMapper;

    /**
     * Organisation service
     *
     * Used to get active organisation for multi-tenancy filtering.
     *
     * @var OrganisationService Organisation service instance
     */
    private readonly OrganisationService $organisationService;

    /**
     * Tool registry
     *
     * Provides access to all registered tools from all apps for agent configuration.
     *
     * @var ToolRegistry Tool registry instance
     */
    private readonly ToolRegistry $toolRegistry;

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
     * @param string              $appName             Application name
     * @param IRequest            $request             HTTP request object
     * @param AgentMapper         $agentMapper         Agent mapper for database operations
     * @param OrganisationService $organisationService Organisation service for multi-tenancy
     * @param ToolRegistry        $toolRegistry        Tool registry for available tools
     * @param LoggerInterface     $logger              Logger for error tracking
     * @param string|null         $userId              Current user ID for RBAC checks
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        AgentMapper $agentMapper,
        OrganisationService $organisationService,
        ToolRegistry $toolRegistry,
        LoggerInterface $logger,
        ?string $userId
    ) {
        // Call parent constructor to initialize base controller.
        parent::__construct(appName: $appName, request: $request);

        // Store dependencies for use in controller methods.
        $this->agentMapper         = $agentMapper;
        $this->organisationService = $organisationService;
        $this->toolRegistry        = $toolRegistry;
        $this->logger             = $logger;
        $this->userId             = $userId;
    }//end __construct()


    /**
     * Render the Agents page
     *
     * Returns the template for the main agents page.
     * All routing is handled client-side by the SPA.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return TemplateResponse Template response for agents SPA
     *
     * @psalm-return TemplateResponse<200, array<never, never>>
     */
    public function page(): TemplateResponse
    {
        // Return SPA template response (routing handled client-side).
        return new TemplateResponse(
            appName: 'openregister',
            templateName: 'index',
            params: []
        );
    }//end page()


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
     * @psalm-return JSONResponse<200|500, array{error?: 'Failed to retrieve agents', results?: array<Agent>}, array<never, never>>
     */
    public function index(): JSONResponse
    {
        try {
            // Get active organisation.
            $organisation     = $this->organisationService->getActiveOrganisation();
            $organisationUuid = $organisation?->getUuid();

            $params = $this->request->getParams();

            // Extract pagination parameters.
            if (($params['_limit'] ?? null) !== null) {
                $limit = (int) $params['_limit'];
            } else {
                $limit = 50;
            }

            if (($params['_offset'] ?? null) !== null) {
                $offset = (int) $params['_offset'];
            } else {
                $offset = 0;
            }

            if (($params['_page'] ?? null) !== null) {
                $page = (int) $params['_page'];
            } else {
                $page = null;
            }

            // Convert page to offset if provided (page-based pagination).
            if ($page !== null) {
                $offset = ($page - 1) * $limit;
            }

            // Get agents with RBAC filtering (handled in mapper layer).
            if ($organisationUuid !== null) {
                // Filter by organisation for multi-tenancy.
                $agents = $this->agentMapper->findByOrganisation(
                    organisationUuid: $organisationUuid,
                    userId: $this->userId,
                    limit: $limit,
                    offset: $offset
                );
            } else {
                // Get all agents (for global/legacy agents without organisation).
                $agents = $this->agentMapper->findAll(limit: $limit, offset: $offset);
            }

            // Return successful response with agents list.
            return new JSONResponse(
                data: ['results' => $agents],
                statusCode: Http::STATUS_OK
            );
        } catch (Exception $e) {
            // Log error with full context.
            $this->logger->error(
                'Failed to get agents',
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            // Return error response.
            return new JSONResponse(
                data: ['error' => 'Failed to retrieve agents'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end index()


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
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200, Agent, array<never, never>>|JSONResponse<403|404, array{error: 'Access denied to this agent'|'Agent not found'}, array<never, never>>
     */
    public function show(int $id): JSONResponse
    {
        try {
            // Retrieve agent using mapper (includes basic RBAC check).
            $agent = $this->agentMapper->find($id);

            // Perform additional access check using mapper method.
            if ($this->agentMapper->canUserAccessAgent(agent: $agent, userId: $this->userId ?? '') === false) {
                return new JSONResponse(
                    data: ['error' => 'Access denied to this agent'],
                    statusCode: Http::STATUS_FORBIDDEN
                );
            }

            // Return successful response with agent data.
            return new JSONResponse(
                data: $agent,
                statusCode: Http::STATUS_OK
            );
        } catch (Exception $e) {
            // Log error with agent ID.
            $this->logger->error(
                'Failed to get agent',
                [
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]
            );

            // Return not found error response.
            return new JSONResponse(
                data: ['error' => 'Agent not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }//end try
    }//end show()


    /**
     * Create a new agent
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Created agent
     *
     * @psalm-return JSONResponse<201, Agent, array<never, never>>|JSONResponse<400, array{error: string}, array<never, never>>
     */
    public function create(): JSONResponse
    {
        try {
            $data = $this->request->getParams();
            unset($data['_route']);

            // Set active organisation UUID (users cannot manually set organization).
            $organisation         = $this->organisationService->getActiveOrganisation();
            $data['organisation'] = $organisation?->getUuid();

            // Set owner.
            $data['owner'] = $this->userId;

            // Set default values for new properties if not provided.
            $isPrivateSet = isset($data['isPrivate']) === true || isset($data['is_private']) === true;

            if ($isPrivateSet === false) {
                $data['isPrivate'] = true;
                // Private by default.
            }

            $searchFilesSet = isset($data['searchFiles']) === true || isset($data['search_files']) === true;

            if ($searchFilesSet === false) {
                $data['searchFiles'] = true;
                // Search files by default.
            }

            $searchObjectsSet = isset($data['searchObjects']) === true || isset($data['search_objects']) === true;

            if ($searchObjectsSet === false) {
                $data['searchObjects'] = true;
                // Search objects by default.
            }

            // Create agent using mapper (handles UUID, timestamps, RBAC).
            $agent = $this->agentMapper->createFromArray($data);

            // Log successful creation.
            $this->logger->info(
                'Agent created successfully',
                [
                    'id'           => $agent->getId(),
                    'organisation' => $agent->getOrganisation(),
                    'isPrivate'    => $agent->getIsPrivate(),
                ]
            );

            // Return successful response with created agent.
            return new JSONResponse(
                data: $agent,
                statusCode: Http::STATUS_CREATED
            );
        } catch (Exception $e) {
            // Log error with full context.
            $this->logger->error(
                'Failed to create agent',
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            // Return error response with message.
            return new JSONResponse(
                data: ['error' => 'Failed to create agent: '.$e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end create()


    /**
     * Update an existing agent
     *
     * @param int $id Agent ID
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Updated agent
     *
     * @psalm-return JSONResponse<200, Agent, array<never, never>>|JSONResponse<400|403, array{error: string}, array<never, never>>
     */
    public function update(int $id): JSONResponse
    {
        try {
            $agent = $this->agentMapper->find($id);

            // Check if user can modify this agent using mapper method.
            if ($this->agentMapper->canUserModifyAgent(agent: $agent, userId: $this->userId ?? '') === false) {
                return new JSONResponse(data: ['error' => 'You do not have permission to modify this agent'], statusCode: Http::STATUS_FORBIDDEN);
            }

            $data = $this->request->getParams();

            // Remove internal parameters and immutable fields to prevent tampering.
            unset($data['_route']);
            unset($data['id']);
            unset($data['created']);

            // Preserve current organisation and owner (security: prevent privilege escalation).
            $currentOrganisation = $agent->getOrganisation();
            $currentOwner        = $agent->getOwner();

            unset($data['organisation']);
            unset($data['owner']);

            // Update agent properties via hydration.
            $agent->hydrate($data);

            // Restore preserved immutable values.
            $agent->setOrganisation($currentOrganisation);
            $agent->setOwner($currentOwner);

            // Update agent using mapper (handles timestamp, RBAC, events).
            $updatedAgent = $this->agentMapper->update($agent);

            // Log successful update.
            $this->logger->info(
                message: 'Agent updated successfully',
                context: ['id' => $id]
            );

            // Return successful response with updated agent.
            return new JSONResponse(
                data: $updatedAgent,
                statusCode: Http::STATUS_OK
            );
        } catch (Exception $e) {
            // Log error with agent ID.
            $this->logger->error(
                'Failed to update agent',
                [
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]
            );

            // Return error response with message.
            return new JSONResponse(
                data: ['error' => 'Failed to update agent: '.$e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end update()


    /**
     * Patch (partially update) an agent
     *
     * Partially updates an agent entity (PATCH method).
     * Delegates to update() method which handles partial updates.
     *
     * @param int $id The ID of the agent to patch
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200, Agent, array<never, never>>|JSONResponse<400|403, array{error: string}, array<never, never>>
     */
    public function patch(int $id): JSONResponse
    {
        // Delegate to update method (both handle partial updates).
        return $this->update($id);
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
     * @psalm-return JSONResponse<200|400|403, array{error?: 'Failed to delete agent'|'You do not have permission to delete this agent', message?: 'Agent deleted successfully'}, array<never, never>>
     */
    public function destroy(int $id): JSONResponse
    {
        try {
            $agent = $this->agentMapper->find($id);

            // Check if user can modify (delete) this agent using mapper method.
            if ($this->agentMapper->canUserModifyAgent(agent: $agent, userId: $this->userId) === false) {
                return new JSONResponse(data: ['error' => 'You do not have permission to delete this agent'], statusCode: Http::STATUS_FORBIDDEN);
            }

            // Delete agent using mapper (handles RBAC, events).
            $this->agentMapper->delete($agent);

            // Log successful deletion.
            $this->logger->info(
                message: 'Agent deleted successfully',
                context: ['id' => $id]
            );

            // Return successful response.
            return new JSONResponse(
                data: ['message' => 'Agent deleted successfully'],
                statusCode: Http::STATUS_OK
            );
        } catch (Exception $e) {
            // Log error with agent ID.
            $this->logger->error(
                'Failed to delete agent',
                [
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]
            );

            // Return error response.
            return new JSONResponse(
                data: ['error' => 'Failed to delete agent'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end destroy()


    /**
     * Get agent statistics
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Agent statistics
     *
     * @psalm-return JSONResponse<200|500, array{error?: 'Failed to retrieve statistics', total?: int, active?: int, inactive?: int}, array<never, never>>
     */
    public function stats(): JSONResponse
    {
        try {
            $total    = $this->agentMapper->count([]);
            $active   = $this->agentMapper->count(['active' => true]);
            $inactive = $this->agentMapper->count(['active' => false]);

            $stats = [
                'total'    => $total,
                'active'   => $active,
                'inactive' => $inactive,
            ];

            return new JSONResponse(data: $stats, statusCode: Http::STATUS_OK);
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to get agent statistics',
                [
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(data: ['error' => 'Failed to retrieve statistics'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

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
     *
     * @return JSONResponse List of available tools with metadata
     *
     * @psalm-return JSONResponse<200|500, array{error?: 'Failed to retrieve tools', results?: array}, array<never, never>>
     */
    public function tools(): JSONResponse
    {
        try {
            $tools = $this->toolRegistry->getAllTools();

            // Log debug information about tools returned.
            $this->logger->debug(
                '[AgentsController] Returning available tools',
                [
                    'count' => count($tools),
                ]
            );

            // Return successful response with tools list.
            return new JSONResponse(
                data: ['results' => $tools],
                statusCode: Http::STATUS_OK
            );
        } catch (Exception $e) {
            // Log error with full context.
            $this->logger->error(
                'Failed to get available tools',
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            // Return error response.
            return new JSONResponse(
                data: ['error' => 'Failed to retrieve tools'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end tools()


}//end class
