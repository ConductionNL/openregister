<?php

/**
 * OpenRegister Endpoints Controller
 *
 * Controller for handling endpoint management operations.
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
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\EndpointLogMapper;
use OCA\OpenRegister\Db\EndpointMapper;
use OCA\OpenRegister\Service\EndpointService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * EndpointsController handles endpoint management operations
 *
 * Provides REST API endpoints for managing external API endpoints configuration.
 * Supports CRUD operations, endpoint testing, and log management.
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
 * @link https://www.OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 */
class EndpointsController extends Controller
{

    /**
     * Endpoint mapper for database operations
     *
     * Handles CRUD operations for endpoint entities in the database.
     *
     * @var EndpointMapper Endpoint mapper instance
     */
    private readonly EndpointMapper $endpointMapper;

    /**
     * Endpoint service for business logic
     *
     * Handles endpoint testing and execution logic.
     *
     * @var EndpointService Endpoint service instance
     */
    private readonly EndpointService $endpointService;

    /**
     * Endpoint log mapper for log operations
     *
     * Handles database operations for endpoint execution logs.
     *
     * @var EndpointLogMapper Endpoint log mapper instance
     */
    private readonly EndpointLogMapper $endpointLogMapper;

    /**
     * Logger for error tracking and debugging
     *
     * Used to log errors, warnings, and informational messages.
     *
     * @var LoggerInterface Logger instance
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor
     *
     * Initializes controller with required dependencies for endpoint management.
     * Calls parent constructor to set up base controller functionality.
     *
     * @param string            $appName           Application name
     * @param IRequest          $request           HTTP request object
     * @param EndpointMapper    $endpointMapper    Endpoint mapper for database operations
     * @param EndpointLogMapper $endpointLogMapper Endpoint log mapper for log operations
     * @param EndpointService   $endpointService   Endpoint service for business logic
     * @param LoggerInterface   $logger            Logger for error tracking
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        EndpointMapper $endpointMapper,
        EndpointLogMapper $endpointLogMapper,
        EndpointService $endpointService,
        LoggerInterface $logger
    ) {
        // Call parent constructor to initialize base controller.
        parent::__construct(appName: $appName, request: $request);

        // Store dependencies for use in controller methods.
        $this->endpointMapper    = $endpointMapper;
        $this->endpointLogMapper = $endpointLogMapper;
        $this->endpointService   = $endpointService;
        $this->logger            = $logger;
    }//end __construct()

    /**
     * List all endpoints
     *
     * Retrieves all configured endpoints from the database and returns them
     * as a JSON response with total count. Used for endpoint management UI.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse JSON response containing list of endpoints
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|500,
     *     array{error?: 'Failed to list endpoints',
     *     results?: array<\OCA\OpenRegister\Db\Endpoint>, total?: int<0, max>},
     *     array<never, never>>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        try {
            // Retrieve all endpoints from database.
            $endpoints = $this->endpointMapper->findAll();

            // Return successful response with endpoints and total count.
            return new JSONResponse(
                data: [
                    'results' => $endpoints,
                    'total'   => count($endpoints),
                ],
                statusCode: 200
            );
        } catch (\Exception $e) {
            // Log error for debugging and monitoring.
            $this->logger->error(
                message: 'Error listing endpoints: '.$e->getMessage(),
                context: [
                    'trace' => $e->getTraceAsString(),
                ]
            );

            // Return error response to client.
            return new JSONResponse(
                data: [
                    'error' => 'Failed to list endpoints',
                ],
                statusCode: 500
            );
        }//end try
    }//end index()

    /**
     * Get a single endpoint
     *
     * Retrieves endpoint details by ID from the database.
     * Returns 404 if endpoint doesn't exist, 500 on database errors.
     *
     * @param int $id Endpoint ID to retrieve
     *
     * @NoAdminRequired
     *
     * @return JSONResponse JSON response containing endpoint details
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200, \OCA\OpenRegister\Db\Endpoint,
     *     array<never, never>>|JSONResponse<404|500,
     *     array{error: 'Endpoint not found'|'Failed to retrieve endpoint'},
     *     array<never, never>>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(int $id): JSONResponse
    {
        try {
            // Find endpoint by ID in database.
            $endpoint = $this->endpointMapper->find($id);

            // Return successful response with endpoint data.
            return new JSONResponse(data: $endpoint);
        } catch (DoesNotExistException $e) {
            // Endpoint not found - return 404 error.
            return new JSONResponse(
                data: [
                    'error' => 'Endpoint not found',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            // Log error for debugging and monitoring.
            $this->logger->error(
                message: 'Error retrieving endpoint: '.$e->getMessage(),
                context: [
                    'id'    => $id,
                    'trace' => $e->getTraceAsString(),
                ]
            );

            // Return error response to client.
            return new JSONResponse(
                data: [
                    'error' => 'Failed to retrieve endpoint',
                ],
                statusCode: 500
            );
        }//end try
    }//end show()

    /**
     * Create a new endpoint
     *
     * Creates a new endpoint configuration from request data.
     * Validates required fields (name and endpoint path) before creation.
     * Returns 201 Created on success, 400 Bad Request on validation failure.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with created endpoint or error
     *
     * @psalm-return JSONResponse<201, \OCA\OpenRegister\Db\Endpoint,
     *     array<never, never>>|JSONResponse<400|500, array{error: string},
     *     array<never, never>>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function create(): JSONResponse
    {
        try {
            // Get endpoint data from request parameters.
            $data = $this->request->getParams();

            // Validate required fields: name and endpoint path must be provided.
            if (empty($data['name']) === true || empty($data['endpoint']) === true) {
                return new JSONResponse(
                    data: [
                        'error' => 'Name and endpoint path are required',
                    ],
                    statusCode: 400
                );
            }

            // Create endpoint entity from array data.
            $endpoint = $this->endpointMapper->createFromArray($data);

            // Log successful endpoint creation for audit trail.
            $this->logger->info(
                message: 'Endpoint created',
                context: [
                    'id'   => $endpoint->getId(),
                    'name' => $endpoint->getName(),
                    'path' => $endpoint->getEndpoint(),
                ]
            );

            // Return successful response with created endpoint (HTTP 201 Created).
            return new JSONResponse(data: $endpoint, statusCode: 201);
        } catch (\Exception $e) {
            // Log error for debugging and monitoring.
            $this->logger->error(
                'Error creating endpoint: '.$e->getMessage(),
                [
                    'data'  => $this->request->getParams(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            // Return error response to client.
            return new JSONResponse(
                data: [
                    'error' => 'Failed to create endpoint: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end create()

    /**
     * Update an existing endpoint
     *
     * Updates endpoint configuration with data from request.
     * Removes ID from update data to prevent ID modification.
     * Returns 404 if endpoint doesn't exist, 500 on database errors.
     *
     * @param int $id Endpoint ID to update
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with updated endpoint or error
     *
     * @psalm-return JSONResponse<200, \OCA\OpenRegister\Db\Endpoint,
     *     array<never, never>>|JSONResponse<404|500, array{error: string},
     *     array<never, never>>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function update(int $id): JSONResponse
    {
        try {
            // Get update data from request parameters.
            $data = $this->request->getParams();

            // Remove ID from data if present to prevent ID modification.
            // ID is determined by route parameter, not request body.
            unset($data['id']);

            // Update endpoint in database with new data.
            $endpoint = $this->endpointMapper->updateFromArray(id: $id, data: $data);

            // Log successful endpoint update for audit trail.
            $this->logger->info(
                message: 'Endpoint updated',
                context: [
                    'id'   => $endpoint->getId(),
                    'name' => $endpoint->getName(),
                ]
            );

            // Return successful response with updated endpoint.
            return new JSONResponse(data: $endpoint);
        } catch (DoesNotExistException $e) {
            // Endpoint not found - return 404 error.
            return new JSONResponse(
                data: [
                    'error' => 'Endpoint not found',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            // Log error for debugging and monitoring.
            $this->logger->error(
                'Error updating endpoint: '.$e->getMessage(),
                [
                    'id'    => $id,
                    'data'  => $this->request->getParams(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            // Return error response to client.
            return new JSONResponse(
                data: [
                    'error' => 'Failed to update endpoint: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end update()

    /**
     * Delete an endpoint
     *
     * Deletes endpoint configuration from database by ID.
     * Returns 204 No Content on success, 404 if endpoint doesn't exist.
     *
     * @param int $id Endpoint ID to delete
     *
     * @NoAdminRequired
     *
     * @return JSONResponse JSON response containing deletion result
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<204, null,
     *     array<never, never>>|JSONResponse<404|500,
     *     array{error: 'Endpoint not found'|'Failed to delete endpoint'},
     *     array<never, never>>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function destroy(int $id): JSONResponse
    {
        try {
            // Find endpoint by ID to ensure it exists before deletion.
            $endpoint = $this->endpointMapper->find($id);

            // Delete endpoint from database.
            $this->endpointMapper->delete($endpoint);

            // Log successful endpoint deletion for audit trail.
            $this->logger->info(
                message: 'Endpoint deleted',
                context: [
                    'id'   => $endpoint->getId(),
                    'name' => $endpoint->getName(),
                ]
            );

            // Return successful response with no content (HTTP 204 No Content).
            return new JSONResponse(data: null, statusCode: 204);
        } catch (DoesNotExistException $e) {
            // Endpoint not found - return 404 error.
            return new JSONResponse(
                data: [
                    'error' => 'Endpoint not found',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            // Log error for debugging and monitoring.
            $this->logger->error(
                'Error deleting endpoint: '.$e->getMessage(),
                [
                    'id'    => $id,
                    'trace' => $e->getTraceAsString(),
                ]
            );

            // Return error response to client.
            return new JSONResponse(
                data: [
                    'error' => 'Failed to delete endpoint',
                ],
                statusCode: 500
            );
        }//end try
    }//end destroy()

    /**
     * Test an endpoint by executing it with test data
     *
     * Executes endpoint with optional test data to verify endpoint configuration.
     * Returns execution result including status code and response data.
     * Used for endpoint validation and debugging.
     *
     * @param int $id Endpoint ID to test
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-suppress InvalidReturnType
     * @psalm-suppress InvalidReturnStatement
     *
     * @return JSONResponse JSON response with test result or error
     *
     * @psalm-return JSONResponse<int,
     *     array{error?: string, success?: bool, message?: string,
     *     statusCode?: int, response?: mixed},
     *     array<never, never>>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function test(int $id): JSONResponse
    {
        try {
            // Find endpoint by ID to ensure it exists.
            $endpoint = $this->endpointMapper->find($id);

            // Get test data from request parameters (optional).
            // Test data is used to simulate endpoint execution with sample payload.
            $testData = $this->request->getParams()['data'] ?? [];

            $result = $this->endpointService->testEndpoint(endpoint: $endpoint, testData: $testData);

            // Return success response if test executed successfully.
            if ($result['success'] === true) {
                return new JSONResponse(
                    data: [
                        'success'    => true,
                        'message'    => 'Test endpoint executed successfully',
                        'statusCode' => $result['statusCode'],
                        'response'   => $result['response'],
                    ]
                );
            } else {
                // Return failure response with error details.
                return new JSONResponse(
                    data: [
                        'success'    => false,
                        'message'    => $result['error'] ?? 'Test endpoint execution failed',
                        'statusCode' => $result['statusCode'],
                    ],
                    statusCode: $result['statusCode']
                );
            }
        } catch (DoesNotExistException $e) {
            // Endpoint not found - return 404 error.
            return new JSONResponse(
                data: [
                    'error' => 'Endpoint not found',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            // Log error for debugging and monitoring.
            $this->logger->error(
                'Error testing endpoint: '.$e->getMessage(),
                [
                    'id'    => $id,
                    'trace' => $e->getTraceAsString(),
                ]
            );

            // Return error response to client.
            return new JSONResponse(
                data: [
                    'error' => 'Failed to test endpoint: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end test()

    /**
     * Get logs for a specific endpoint
     *
     * Retrieves execution logs for a specific endpoint with pagination support.
     * Validates endpoint exists before retrieving logs.
     * Returns paginated log entries with total count.
     *
     * @param int $id Endpoint ID to retrieve logs for
     *
     * @NoAdminRequired
     *
     * @return JSONResponse JSON response containing endpoint logs
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|404|500,
     *     array{error?: 'Endpoint not found'|'Failed to retrieve endpoint logs',
     *     results?: list<\OCA\OpenRegister\Db\EndpointLog>, total?: int<0, max>},
     *     array<never, never>>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function logs(int $id): JSONResponse
    {
        try {
            // Validate endpoint exists by attempting to find it.
            // Throws DoesNotExistException if endpoint not found.
            $this->endpointMapper->find($id);

            // Get pagination parameters from request (with defaults).
            $limit  = (int) ($this->request->getParam('limit') ?? 50);
            $offset = (int) ($this->request->getParam('offset') ?? 0);

            $logs = $this->endpointLogMapper->findByEndpoint(endpointId: $id, limit: $limit, offset: $offset);

            // Return successful response with logs and total count.
            return new JSONResponse(
                data: [
                    'results' => $logs,
                    'total'   => count($logs),
                ],
                statusCode: 200
            );
        } catch (DoesNotExistException $e) {
            // Endpoint not found - return 404 error.
            return new JSONResponse(
                data: [
                    'error' => 'Endpoint not found',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            // Log error for debugging and monitoring.
            $this->logger->error(
                message: 'Error retrieving endpoint logs: '.$e->getMessage(),
                context: [
                    'id'    => $id,
                    'trace' => $e->getTraceAsString(),
                ]
            );

            // Return error response to client.
            return new JSONResponse(
                data: [
                    'error' => 'Failed to retrieve endpoint logs',
                ],
                statusCode: 500
            );
        }//end try
    }//end logs()

    /**
     * Get statistics for a specific endpoint
     *
     * Retrieves aggregated statistics for endpoint execution logs.
     * Includes metrics like total requests, success rate, average response time, etc.
     * Validates endpoint exists before calculating statistics.
     *
     * @param int $id Endpoint ID to retrieve statistics for
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with endpoint log statistics
     *
     * @psalm-return JSONResponse<200|404|500,
     *     array{error?: 'Endpoint not found'|
     *     'Failed to retrieve endpoint log statistics', total?: int,
     *     success?: int, failed?: int}, array<never, never>>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function logStats(int $id): JSONResponse
    {
        try {
            // Validate endpoint exists by attempting to find it.
            // Throws DoesNotExistException if endpoint not found.
            $this->endpointMapper->find($id);

            // Calculate statistics from endpoint logs.
            // Statistics include counts, success rates, response times, etc.
            $stats = $this->endpointLogMapper->getStatistics($id);

            // Return successful response with statistics data.
            return new JSONResponse(data: $stats);
        } catch (DoesNotExistException $e) {
            // Endpoint not found - return 404 error.
            return new JSONResponse(
                data: [
                    'error' => 'Endpoint not found',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            // Log error for debugging and monitoring.
            $this->logger->error(
                message: 'Error retrieving endpoint log statistics: '.$e->getMessage(),
                context: [
                    'id'    => $id,
                    'trace' => $e->getTraceAsString(),
                ]
            );

            // Return error response to client.
            return new JSONResponse(
                data: [
                    'error' => 'Failed to retrieve endpoint log statistics',
                ],
                statusCode: 500
            );
        }//end try
    }//end logStats()

    /**
     * Get all endpoint logs with optional filtering
     *
     * Retrieves endpoint execution logs with optional filtering by endpoint ID.
     * Supports pagination via limit and offset parameters.
     * Returns logs for specific endpoint if endpoint_id provided, otherwise all logs.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with logs or error
     *
     * @psalm-return JSONResponse<200|500,
     *     array{error?: string, results?: array<\OCA\OpenRegister\Db\EndpointLog>,
     *     total?: int<0, max>},
     *     array<never, never>>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function allLogs(): JSONResponse
    {
        try {
            // Get optional endpoint ID filter from request parameters.
            $endpointId = $this->request->getParam('endpoint_id');

            // Get pagination parameters from request (with defaults).
            $limit  = (int) ($this->request->getParam('limit') ?? 50);
            $offset = (int) ($this->request->getParam('offset') ?? 0);

            // If endpoint_id is provided and valid, filter logs by endpoint.
            if ($endpointId !== null && $endpointId !== '' && $endpointId !== '0') {
                // Convert endpoint ID to integer for database query.
                $endpointIdInt = (int) $endpointId;
                $logs          = $this->endpointLogMapper->findByEndpoint(
                    endpointId: $endpointIdInt,
                    limit: $limit,
                    offset: $offset
                );
                // Get total count for this endpoint.
                $allLogsForEndpoint = $this->endpointLogMapper->findByEndpoint(
                    endpointId: $endpointIdInt,
                    limit: null,
                    offset: null
                );
                $total = count($allLogsForEndpoint);
            } else {
                // No endpoint filter - get all logs from all endpoints.
                $logs = $this->endpointLogMapper->findAll(limit: $limit, offset: $offset);

                // Get total count for all logs (without pagination).
                $allLogs = $this->endpointLogMapper->findAll(limit: null, offset: null);
                $total   = count($allLogs);
            }//end if

            // Return successful response with logs and total count.
            return new JSONResponse(
                data: [
                    'results' => $logs,
                    'total'   => $total,
                ],
                statusCode: 200
            );
        } catch (\Exception $e) {
            // Log error for debugging and monitoring.
            $this->logger->error(
                message: 'Error retrieving endpoint logs: '.$e->getMessage(),
                context: [
                    'trace' => $e->getTraceAsString(),
                ]
            );

            // Return error response to client.
            return new JSONResponse(
                data: [
                    'error' => 'Failed to retrieve endpoint logs: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end allLogs()
}//end class
