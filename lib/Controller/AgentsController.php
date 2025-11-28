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
 * AgentsController
 *
 * REST API controller for managing AI agents.
 *
 * @package OCA\OpenRegister\Controller
 *
 */
class AgentsController extends Controller
{

    /**
     * Agent mapper for database operations
     *
     * @var AgentMapper
     */
    private AgentMapper $agentMapper;

    /**
     * Organisation service
     *
     * @var OrganisationService
     */
    private OrganisationService $organisationService;

    /**
     * Tool registry
     *
     * @var ToolRegistry
     */
    private ToolRegistry $toolRegistry;

    /**
     * Logger for debugging and error tracking
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * User ID
     *
     * @var string|null
     */
    private ?string $userId;


    /**
     * AgentsController constructor
     *
     * @param string              $appName             Application name
     * @param IRequest            $request             HTTP request
     * @param AgentMapper         $agentMapper         Agent mapper
     * @param OrganisationService $organisationService Organisation service
     * @param ToolRegistry        $toolRegistry        Tool registry
     * @param LoggerInterface     $logger              Logger service
     * @param string|null         $userId              User ID
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
        parent::__construct(appName: $appName, request: $request);
        $this->agentMapper         = $agentMapper;
        $this->organisationService = $organisationService;
        $this->toolRegistry        = $toolRegistry;
        $this->logger = $logger;
        $this->userId = $userId;

    }//end __construct()


    /**
     * This returns the template of the main app's page
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse
     */
    public function page(): TemplateResponse
    {
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
     * @NoCSRFRequired
     *
     * @return JSONResponse List of agents
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

            // Convert page to offset if provided.
            if ($page !== null) {
                $offset = ($page - 1) * $limit;
            }

            // Get agents with RBAC filtering (handled in mapper).
            if ($organisationUuid !== null) {
                $agents = $this->agentMapper->findByOrganisation(
                    organisationUuid: $organisationUuid,
                    userId: $this->userId,
                    limit: $limit,
                    offset: $offset
                );
            } else {
                $agents = $this->agentMapper->findAll(limit: $limit, offset: $offset);
            }

            return new JSONResponse(data: ['results' => $agents], statusCode: Http::STATUS_OK);
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to get agents',
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(data: ['error' => 'Failed to retrieve agents'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end index()


    /**
     * Get a single agent
     *
     * RBAC check is handled in the mapper layer.
     *
     * @param int $id Agent ID
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Agent details
     */
    public function show(int $id): JSONResponse
    {
        try {
            $agent = $this->agentMapper->find($id);

            // Check access rights using mapper method.
            if ($this->agentMapper->canUserAccessAgent(agent: $agent, userId: $this->userId) === false) {
                return new JSONResponse(data: ['error' => 'Access denied to this agent'], statusCode: Http::STATUS_FORBIDDEN);
            }

            return new JSONResponse(data: $agent, statusCode: Http::STATUS_OK);
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to get agent',
                [
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(data: ['error' => 'Agent not found'], statusCode: Http::STATUS_NOT_FOUND);
        }//end try

    }//end show()


    /**
     * Create a new agent
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Created agent
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
            if (!isset($data['isPrivate']) === false && !isset($data['is_private'])) {
                $data['isPrivate'] = true;
                // Private by default.
            }

            if (!isset($data['searchFiles']) === false && !isset($data['search_files'])) {
                $data['searchFiles'] = true;
                // Search files by default.
            }

            if (!isset($data['searchObjects']) === false && !isset($data['search_objects'])) {
                $data['searchObjects'] = true;
                // Search objects by default.
            }

            $agent = $this->agentMapper->createFromArray($data);

            $this->logger->info(
                    'Agent created successfully',
                    [
                        'id'           => $agent->getId(),
                        'organisation' => $agent->getOrganisation(),
                        'isPrivate'    => $agent->getIsPrivate(),
                    ]
                    );

            return new JSONResponse(data: $agent, statusCode: Http::STATUS_CREATED);
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to create agent',
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(data: ['error' => 'Failed to create agent: '.$e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
        }//end try

    }//end create()


    /**
     * Update an existing agent
     *
     * @param int $id Agent ID
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Updated agent
     */
    public function update(int $id): JSONResponse
    {
        try {
            $agent = $this->agentMapper->find($id);

            // Check if user can modify this agent using mapper method.
            if ($this->agentMapper->canUserModifyAgent(agent: $agent, userId: $this->userId) === false) {
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

            $updatedAgent = $this->agentMapper->update($agent);

            $this->logger->info(message: 'Agent updated successfully', context: ['id' => $id]);

            return new JSONResponse(data: $updatedAgent, statusCode: Http::STATUS_OK);
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to update agent',
                [
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(data: ['error' => 'Failed to update agent: '.$e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
        }//end try

    }//end update()


    /**
     * Patch (partially update) an agent
     *
     * @param int $id The ID of the agent to patch
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse The updated agent data
     */
    public function patch(int $id): JSONResponse
    {
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
     * @NoCSRFRequired
     *
     * @return JSONResponse Success message
     */
    public function destroy(int $id): JSONResponse
    {
        try {
            $agent = $this->agentMapper->find($id);

            // Check if user can modify (delete) this agent using mapper method.
            if ($this->agentMapper->canUserModifyAgent(agent: $agent, userId: $this->userId) === false) {
                return new JSONResponse(data: ['error' => 'You do not have permission to delete this agent'], statusCode: Http::STATUS_FORBIDDEN);
            }

            $this->agentMapper->delete($agent);

            $this->logger->info(message: 'Agent deleted successfully', context: ['id' => $id]);

            return new JSONResponse(data: ['message' => 'Agent deleted successfully'], statusCode: Http::STATUS_OK);
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to delete agent',
                [
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(data: ['error' => 'Failed to delete agent'], statusCode: Http::STATUS_BAD_REQUEST);
        }//end try

    }//end destroy()


    /**
     * Get agent statistics
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Agent statistics
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
     * @NoCSRFRequired
     *
     * @return JSONResponse List of available tools with metadata
     */
    public function tools(): JSONResponse
    {
        try {
            $tools = $this->toolRegistry->getAllTools();

            $this->logger->debug(
                    '[AgentsController] Returning available tools',
                    [
                        'count' => count($tools),
                    ]
                    );

            return new JSONResponse(data: ['results' => $tools], statusCode: Http::STATUS_OK);
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to get available tools',
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(data: ['error' => 'Failed to retrieve tools'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end tools()


}//end class
