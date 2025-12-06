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
 * @category Controller
 * @package  OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 */
/**
 * @psalm-suppress UnusedClass
 */

class EndpointsController extends Controller
{

    /**
     * Endpoint mapper
     *
     * @var EndpointMapper
     */
    private EndpointMapper $endpointMapper;

    /**
     * Endpoint service
     *
     * @var EndpointService
     */
    private EndpointService $endpointService;

    /**
     * Endpoint log mapper
     *
     * @var EndpointLogMapper
     */
    private EndpointLogMapper $endpointLogMapper;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;


    /**
     * Constructor
     *
     * @param string            $appName           Application name
     * @param IRequest          $request           HTTP request
     * @param EndpointMapper    $endpointMapper    Endpoint mapper
     * @param EndpointLogMapper $endpointLogMapper Endpoint log mapper
     * @param EndpointService   $endpointService   Endpoint service
     * @param LoggerInterface   $logger            Logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        EndpointMapper $endpointMapper,
        EndpointLogMapper $endpointLogMapper,
        EndpointService $endpointService,
        LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
        $this->endpointMapper    = $endpointMapper;
        $this->endpointLogMapper = $endpointLogMapper;
        $this->endpointService   = $endpointService;
        $this->logger            = $logger;

    }//end __construct()


    /**
     * List all endpoints
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        try {
            $endpoints = $this->endpointMapper->findAll();

            return new JSONResponse(
                data: [
                    'results' => $endpoints,
                    'total'   => count($endpoints),
                ],
                statusCode: 200
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Error listing endpoints: '.$e->getMessage(),
                context: [
                    'trace' => $e->getTraceAsString(),
                ]
            );

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
     * @param int $id Endpoint ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(int $id): JSONResponse
    {
        try {
            $endpoint = $this->endpointMapper->find($id);

            return new JSONResponse(data: $endpoint);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Endpoint not found',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Error retrieving endpoint: '.$e->getMessage(),
                context: [
                    'id'    => $id,
                    'trace' => $e->getTraceAsString(),
                ]
            );

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
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function create(): JSONResponse
    {
        try {
            $data = $this->request->getParams();

            // Validate required fields.
            if (empty($data['name']) === true || empty($data['endpoint']) === true) {
                return new JSONResponse(
                    data: [
                        'error' => 'Name and endpoint path are required',
                    ],
                    statusCode: 400
                );
            }

            $endpoint = $this->endpointMapper->createFromArray($data);

            $this->logger->info(
                message: 'Endpoint created',
                context: [
                    'id'   => $endpoint->getId(),
                    'name' => $endpoint->getName(),
                    'path' => $endpoint->getEndpoint(),
                ]
            );

            return new JSONResponse(data: $endpoint, statusCode: 201);
        } catch (\Exception $e) {
            $this->logger->error(
                'Error creating endpoint: '.$e->getMessage(),
                [
                    'data'  => $this->request->getParams(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

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
     * @param int $id Endpoint ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function update(int $id): JSONResponse
    {
        try {
            $data = $this->request->getParams();

            // Remove ID from data if present.
            unset($data['id']);

            $endpoint = $this->endpointMapper->updateFromArray(id: $id, data: $data);

            $this->logger->info(
                message: 'Endpoint updated',
                context: [
                    'id'   => $endpoint->getId(),
                    'name' => $endpoint->getName(),
                ]
            );

            return new JSONResponse(data: $endpoint);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Endpoint not found',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'Error updating endpoint: '.$e->getMessage(),
                [
                    'id'    => $id,
                    'data'  => $this->request->getParams(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

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
     * @param int $id Endpoint ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function destroy(int $id): JSONResponse
    {
        try {
            $endpoint = $this->endpointMapper->find($id);
            $this->endpointMapper->delete($endpoint);

            $this->logger->info(
                message: 'Endpoint deleted',
                context: [
                    'id'   => $endpoint->getId(),
                    'name' => $endpoint->getName(),
                ]
            );

            return new JSONResponse(data: null, statusCode: 204);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Endpoint not found',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'Error deleting endpoint: '.$e->getMessage(),
                [
                    'id'    => $id,
                    'trace' => $e->getTraceAsString(),
                ]
            );

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
     * @param int $id Endpoint ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function test(int $id): JSONResponse
    {
        try {
            $endpoint = $this->endpointMapper->find($id);

            $testData = $this->request->getParams()['data'] ?? [];

            $result = $this->endpointService->testEndpoint(endpoint: $endpoint, testData: $testData);

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
            return new JSONResponse(
                data: [
                    'error' => 'Endpoint not found',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'Error testing endpoint: '.$e->getMessage(),
                [
                    'id'    => $id,
                    'trace' => $e->getTraceAsString(),
                ]
            );

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
     * @param int $id Endpoint ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function logs(int $id): JSONResponse
    {
        try {
            // Validate endpoint exists by attempting to find it.
            $this->endpointMapper->find($id);

            $limit  = (int) ($this->request->getParam('limit') ?? 50);
            $offset = (int) ($this->request->getParam('offset') ?? 0);

            $logs = $this->endpointLogMapper->findByEndpoint(endpointId: $id, limit: $limit, offset: $offset);

            return new JSONResponse(
                data: [
                    'results' => $logs,
                    'total'   => count($logs),
                ],
                statusCode: 200
            );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Endpoint not found',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Error retrieving endpoint logs: '.$e->getMessage(),
                context: [
                    'id'    => $id,
                    'trace' => $e->getTraceAsString(),
                ]
            );

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
     * @param int $id Endpoint ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function logStats(int $id): JSONResponse
    {
        try {
            // Validate endpoint exists by attempting to find it.
            $this->endpointMapper->find($id);

            $stats = $this->endpointLogMapper->getStatistics($id);

            return new JSONResponse(data: $stats);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Endpoint not found',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Error retrieving endpoint log statistics: '.$e->getMessage(),
                context: [
                    'id'    => $id,
                    'trace' => $e->getTraceAsString(),
                ]
            );

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
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function allLogs(): JSONResponse
    {
        try {
            $endpointId = $this->request->getParam('endpoint_id');
            $limit      = (int) ($this->request->getParam('limit') ?? 50);
            $offset     = (int) ($this->request->getParam('offset') ?? 0);

            // If endpoint_id is provided and valid, use findByEndpoint method.
            if ($endpointId !== null && $endpointId !== '' && $endpointId !== '0') {
                $endpointIdInt = (int) $endpointId;
                $logs          = $this->endpointLogMapper->findByEndpoint(endpointId: $endpointIdInt, limit: $limit, offset: $offset);
                // Get total count for this endpoint.
                $allLogsForEndpoint = $this->endpointLogMapper->findByEndpoint($endpointIdInt, null, null);
                $total = count($allLogsForEndpoint);
            } else {
                // Get all logs.
                $logs = $this->endpointLogMapper->findAll($limit, $offset);
                // Get total count for all logs.
                $allLogs = $this->endpointLogMapper->findAll(null, null);
                $total   = count($allLogs);
            }

            return new JSONResponse(
                data: [
                    'results' => $logs,
                    'total'   => $total,
                ],
                statusCode: 200
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Error retrieving endpoint logs: '.$e->getMessage(),
                context: [
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error' => 'Failed to retrieve endpoint logs: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try

    }//end allLogs()


}//end class
