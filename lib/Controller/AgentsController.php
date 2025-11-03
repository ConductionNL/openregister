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

use OCA\OpenRegister\Db\AgentMapper;
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
     * Logger for debugging and error tracking
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;


    /**
     * AgentsController constructor
     *
     * @param string          $appName     Application name
     * @param IRequest        $request     HTTP request
     * @param AgentMapper     $agentMapper Agent mapper
     * @param LoggerInterface $logger      Logger service
     */
    public function __construct(
        string $appName,
        IRequest $request,
        AgentMapper $agentMapper,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->agentMapper = $agentMapper;
        $this->logger = $logger;

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
     * Get all agents
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse List of agents
     */
    public function index(): JSONResponse
    {
        try {
            $params = $this->request->getParams();
            
            // Extract pagination and search parameters
            $limit  = isset($params['_limit']) ? (int) $params['_limit'] : null;
            $offset = isset($params['_offset']) ? (int) $params['_offset'] : null;
            $page   = isset($params['_page']) ? (int) $params['_page'] : null;
            $search = $params['_search'] ?? '';
            
            // Convert page to offset if provided
            if ($page !== null && $limit !== null) {
                $offset = ($page - 1) * $limit;
            }
            
            // Remove special query params from filters
            $filters = $params;
            unset($filters['_limit'], $filters['_offset'], $filters['_page'], $filters['_search'], $filters['_route']);

            $agents = $this->agentMapper->findAll(
                $limit,
                $offset,
                $filters
            );

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

            $agent = $this->agentMapper->createFromArray($data);

            $this->logger->info('Agent created successfully', ['id' => $agent->getId()]);

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
            
            $data = $this->request->getParams();
            unset($data['_route']);

            // Update agent properties
            $agent->hydrate($data);
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


