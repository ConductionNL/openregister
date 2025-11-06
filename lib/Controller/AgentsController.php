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
     * @param LoggerInterface     $logger              Logger service
     * @param string|null         $userId              User ID
     */
    public function __construct(
        string $appName,
        IRequest $request,
        AgentMapper $agentMapper,
        OrganisationService $organisationService,
        LoggerInterface $logger,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->agentMapper = $agentMapper;
        $this->organisationService = $organisationService;
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
            'openregister',
            'index',
            []
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
            // Get active organisation
            $organisation = $this->organisationService->getActiveOrganisation();
            $organisationUuid = $organisation?->getUuid();

            $params = $this->request->getParams();
            
            // Extract pagination parameters
            $limit  = isset($params['_limit']) ? (int) $params['_limit'] : 50;
            $offset = isset($params['_offset']) ? (int) $params['_offset'] : 0;
            $page   = isset($params['_page']) ? (int) $params['_page'] : null;
            
            // Convert page to offset if provided
            if ($page !== null) {
                $offset = ($page - 1) * $limit;
            }

            // Get agents with RBAC filtering (handled in mapper)
            $agents = $organisationUuid !== null
                ? $this->agentMapper->findByOrganisation($organisationUuid, $this->userId, $limit, $offset)
                : $this->agentMapper->findAll($limit, $offset);

            return new JSONResponse(['results' => $agents], Http::STATUS_OK);
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to get agents',
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                ['error' => 'Failed to retrieve agents'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

    }//end index()


    /**
     * Get a single agent
     *
     * RBAC check is handled in the mapper layer.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id Agent ID
     *
     * @return JSONResponse Agent details
     */
    public function show(int $id): JSONResponse
    {
        try {
            $agent = $this->agentMapper->find($id);

            // Check access rights using mapper method
            if (!$this->agentMapper->canUserAccessAgent($agent, $this->userId)) {
                return new JSONResponse(
                    ['error' => 'Access denied to this agent'],
                    Http::STATUS_FORBIDDEN
                );
            }

            return new JSONResponse($agent, Http::STATUS_OK);
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to get agent',
                [
                    'id' => $id,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                ['error' => 'Agent not found'],
                Http::STATUS_NOT_FOUND
            );
        }

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

            // Set active organisation UUID (users cannot manually set organization)
            $organisation = $this->organisationService->getActiveOrganisation();
            $data['organisation'] = $organisation?->getUuid();

            // Set owner
            $data['owner'] = $this->userId;

            // Set default values for new properties if not provided
            if (!isset($data['isPrivate']) && !isset($data['is_private'])) {
                $data['isPrivate'] = true; // Private by default
            }

            if (!isset($data['searchFiles']) && !isset($data['search_files'])) {
                $data['searchFiles'] = true; // Search files by default
            }

            if (!isset($data['searchObjects']) && !isset($data['search_objects'])) {
                $data['searchObjects'] = true; // Search objects by default
            }

            $agent = $this->agentMapper->createFromArray($data);

            $this->logger->info('Agent created successfully', [
                'id' => $agent->getId(),
                'organisation' => $agent->getOrganisation(),
                'isPrivate' => $agent->getIsPrivate(),
            ]);

            return new JSONResponse($agent, Http::STATUS_CREATED);
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to create agent',
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                ['error' => 'Failed to create agent: ' . $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        }

    }//end create()


    /**
     * Update an existing agent
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id Agent ID
     *
     * @return JSONResponse Updated agent
     */
    public function update(int $id): JSONResponse
    {
        try {
            $agent = $this->agentMapper->find($id);

            // Check if user can modify this agent using mapper method
            if (!$this->agentMapper->canUserModifyAgent($agent, $this->userId)) {
                return new JSONResponse(
                    ['error' => 'You do not have permission to modify this agent'],
                    Http::STATUS_FORBIDDEN
                );
            }
            
            $data = $this->request->getParams();
            
            // Remove internal parameters and immutable fields to prevent tampering
            unset($data['_route']);
            unset($data['id']);
            unset($data['created']);

            // Preserve current organisation and owner (security: prevent privilege escalation)
            $currentOrganisation = $agent->getOrganisation();
            $currentOwner = $agent->getOwner();
            
            unset($data['organisation']);
            unset($data['owner']);

            // Update agent properties via hydration
            $agent->hydrate($data);
            
            // Restore preserved immutable values
            $agent->setOrganisation($currentOrganisation);
            $agent->setOwner($currentOwner);
            
            $updatedAgent = $this->agentMapper->update($agent);

            $this->logger->info('Agent updated successfully', ['id' => $id]);

            return new JSONResponse($updatedAgent, Http::STATUS_OK);
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to update agent',
                [
                    'id' => $id,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                ['error' => 'Failed to update agent: ' . $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        }

    }//end update()


    /**
     * Patch (partially update) an agent
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The ID of the agent to patch
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
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id Agent ID
     *
     * @return JSONResponse Success message
     */
    public function destroy(int $id): JSONResponse
    {
        try {
            $agent = $this->agentMapper->find($id);

            // Check if user can modify (delete) this agent using mapper method
            if (!$this->agentMapper->canUserModifyAgent($agent, $this->userId)) {
                return new JSONResponse(
                    ['error' => 'You do not have permission to delete this agent'],
                    Http::STATUS_FORBIDDEN
                );
            }

            $this->agentMapper->delete($agent);

            $this->logger->info('Agent deleted successfully', ['id' => $id]);

            return new JSONResponse(['message' => 'Agent deleted successfully'], Http::STATUS_OK);
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to delete agent',
                [
                    'id' => $id,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                ['error' => 'Failed to delete agent'],
                Http::STATUS_BAD_REQUEST
            );
        }

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
            $total = $this->agentMapper->count([]);
            $active = $this->agentMapper->count(['active' => true]);
            $inactive = $this->agentMapper->count(['active' => false]);

            $stats = [
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive,
            ];

            return new JSONResponse($stats, Http::STATUS_OK);
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to get agent statistics',
                [
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                ['error' => 'Failed to retrieve statistics'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

    }//end stats()


}//end class


