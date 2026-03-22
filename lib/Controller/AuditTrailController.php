<?php

/**
 * Class AuditTrailController
 *
 * Controller for managing audit trail operations in the OpenRegister app.
 * Provides functionality to retrieve audit trails related to objects within registers and schemas.
 * Includes hash chain verification, verwerkingsregister, and immutability enforcement.
 *
 * @category Controller
 * @package  OCA\OpenRegister\AppInfo
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\AuditHashService;
use OCA\OpenRegister\Service\LogService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Class AuditTrailController
 *
 * Handles all audit trail related operations.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)   Controller covers audit trail, verification, verwerkingsregister
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Necessary service dependencies
 */
class AuditTrailController extends Controller
{
    /**
     * Constructor for AuditTrailController
     *
     * @param string           $appName          The name of the app
     * @param IRequest         $request          The request object
     * @param LogService       $logService       The log service
     * @param AuditTrailMapper $auditTrailMapper The audit trail mapper
     * @param AuditHashService $auditHashService The audit hash chain service
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly LogService $logService,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly AuditHashService $auditHashService
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Extract pagination, filter, and search parameters from request
     *
     * @return array The extracted request parameters
     *
     * @SuppressWarnings(PHPMD.NPathComplexity)      Request parameter extraction requires many conditional checks
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function extractRequestParameters(): array
    {
        // Get request parameters for filtering and pagination.
        $params = $this->request->getParams();

        // Extract pagination parameters.
        $limit = 20;
        if (($params['limit'] ?? null) !== null) {
            $limit = (int) $params['limit'];
        } else if (($params['_limit'] ?? null) !== null) {
            $limit = (int) $params['_limit'];
        }

        $offset = null;
        if (($params['offset'] ?? null) !== null) {
            $offset = (int) $params['offset'];
        } else if (($params['_offset'] ?? null) !== null) {
            $offset = (int) $params['_offset'];
        }

        $page = null;
        if (($params['page'] ?? null) !== null) {
            $page = (int) $params['page'];
        } else if (($params['_page'] ?? null) !== null) {
            $page = (int) $params['_page'];
        }

        // If we have a page but no offset, calculate the offset.
        if ($page !== null && $offset === null) {
            $offset = ($page - 1) * $limit;
        }

        // Extract search parameter.
        $search = $params['search'] ?? $params['_search'] ?? null;

        // Extract sort parameters.
        $sort = [];
        if (($params['sort'] ?? null) !== null || (($params['_sort'] ?? null) !== null) === true) {
            $sortField        = $params['sort'] ?? $params['_sort'] ?? 'created';
            $sortOrder        = $params['order'] ?? $params['_order'] ?? 'DESC';
            $sort[$sortField] = $sortOrder;
        }

        if (empty($sort) === true) {
            $sort['created'] = 'DESC';
        }

        // Filter out special parameters and system fields.
        $filters = array_filter(
            $params,
            function ($key) {
                return !in_array(
                    $key,
                    [
                        'limit',
                        '_limit',
                        'offset',
                        '_offset',
                        'page',
                        '_page',
                        'search',
                        '_search',
                        'sort',
                        '_sort',
                        'order',
                        '_order',
                        '_route',
                        'id',
                        'register',
                        'schema',
                        'format',
                        'from',
                        'to',
                        'identifier',
                    ]
                );
            },
            ARRAY_FILTER_USE_KEY
        );

        return [
            'limit'   => $limit,
            'offset'  => $offset,
            'page'    => $page,
            'filters' => $filters,
            'sort'    => $sort,
            'search'  => $search,
        ];
    }//end extractRequestParameters()

    /**
     * Get all audit trail logs
     *
     * @NoAdminRequired
     *
     * @return JSONResponse JSON response containing list of audit trails
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200,
     *     array{results: array<\OCA\OpenRegister\Db\AuditTrail>,
     *     total: int<0, max>, page: int|null, pages: float, limit: int,
     *     offset: int|null}, array<never, never>>
     */
    public function index(): JSONResponse
    {
        // Extract common parameters.
        $params = $this->extractRequestParameters();

        // Get logs from service.
        $logs = $this->logService->getAllLogs($params);

        // Get total count for pagination.
        $total = $this->logService->countAllLogs($params['filters']);

        // Return paginated results.
        return new JSONResponse(
            data: [
                'results' => $logs,
                'total'   => $total,
                'page'    => $params['page'],
                'pages'   => ceil($total / $params['limit']),
                'limit'   => $params['limit'],
                'offset'  => $params['offset'],
            ]
        );
    }//end index()

    /**
     * Get a specific audit trail log by ID
     *
     * @param int $id The audit trail ID
     *
     * @return JSONResponse A JSON response containing the log
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<
     *     200,
     *     array<array-key, mixed>,
     *     array<never, never>
     * >|JSONResponse<
     *     404,
     *     array{error: 'Audit trail not found'},
     *     array<never, never>
     * >
     */
    public function show(int $id): JSONResponse
    {
        try {
            $log = $this->logService->getLog($id);
            return new JSONResponse(data: $log);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Audit trail not found'], statusCode: 404);
        }
    }//end show()

    /**
     * Reject audit trail modification (immutability enforcement).
     *
     * @param int $id The audit trail ID
     *
     * @return JSONResponse HTTP 405 Method Not Allowed
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function update(int $id): JSONResponse
    {
        return new JSONResponse(
            data: ['error' => 'Audit trail entries cannot be modified'],
            statusCode: Http::STATUS_METHOD_NOT_ALLOWED
        );
    }//end update()

    /**
     * Get logs for an object
     *
     * @param string $register The register identifier
     * @param string $schema   The schema identifier
     * @param string $id       The object ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse JSON response containing audit trails for specific object
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|404,
     *     array{error?: string,
     *     results?: array<\OCA\OpenRegister\Db\AuditTrail>,
     *     total?: int<0, max>, page?: int|null, pages?: float, limit?: int,
     *     offset?: int|null}, array<never, never>>
     */
    public function objects(string $register, string $schema, string $id): JSONResponse
    {
        // Extract common parameters.
        $params = $this->extractRequestParameters();

        try {
            // Get logs from service.
            $logs = $this->logService->getLogs(
                register: $register,
                schema: $schema,
                id: $id,
                config: $params
            );

            // Get total count for pagination.
            $total = $this->logService->count(register: $register, schema: $schema, id: $id);

            // Return paginated results.
            return new JSONResponse(
                data: [
                    'results' => $logs,
                    'total'   => $total,
                    'page'    => $params['page'],
                    'pages'   => ceil($total / $params['limit']),
                    'limit'   => $params['limit'],
                    'offset'  => $params['offset'],
                ]
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
        }//end try
    }//end objects()

    /**
     * Export audit trail logs in specified format
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with export data or error
     */
    public function export(): JSONResponse
    {
        // Extract request parameters.
        $params = $this->extractRequestParameters();

        // Get export specific parameters.
        $format          = $this->request->getParam('format', 'csv');
        $includeChanges  = $this->request->getParam('includeChanges', true);
        $includeMetadata = $this->request->getParam('includeMetadata', false);

        try {
            // Build export configuration.
            $exportConfig = [
                'filters'         => $params['filters'],
                'search'          => $params['search'],
                'includeChanges'  => filter_var($includeChanges, FILTER_VALIDATE_BOOLEAN),
                'includeMetadata' => filter_var($includeMetadata, FILTER_VALIDATE_BOOLEAN),
            ];

            // Export logs using service.
            $exportResult = $this->logService->exportLogs(format: $format, config: $exportConfig);

            // Return export data.
            $content     = $exportResult['content'];
            $contentSize = 0;
            if (is_string($content) === true) {
                $contentSize = strlen($content);
            }

            return new JSONResponse(
                data: [
                    'success' => true,
                    'data'    => [
                        'content'     => $content,
                        'filename'    => $exportResult['filename'],
                        'contentType' => $exportResult['contentType'],
                        'size'        => $contentSize,
                    ],
                ]
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Invalid export format: '.$e->getMessage(),
                ],
                statusCode: 400
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Export failed: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end export()

    /**
     * Reject audit trail deletion (immutability enforcement).
     *
     * @param int $id The audit trail ID
     *
     * @return JSONResponse HTTP 405 Method Not Allowed
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function destroy(int $id): JSONResponse
    {
        return new JSONResponse(
            data: ['error' => 'Audit trail entries cannot be deleted'],
            statusCode: Http::STATUS_METHOD_NOT_ALLOWED
        );
    }//end destroy()

    /**
     * Delete multiple audit trail logs based on filters or specific IDs
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with deletion results or error
     */
    public function destroyMultiple(): JSONResponse
    {
        // Extract request parameters.
        $params = $this->extractRequestParameters();

        // Get specific parameters for mass deletion.
        $ids = $this->request->getParam('ids', null);

        try {
            // Build deletion configuration.
            $deleteConfig = [
                'filters' => $params['filters'],
                'search'  => $params['search'],
            ];

            // Add specific IDs if provided.
            if ($ids !== null) {
                // Handle both comma-separated string and array.
                if (is_string($ids) === true) {
                    $deleteConfig['ids'] = array_map('intval', explode(',', $ids));
                } else if (is_array($ids) === true) {
                    $deleteConfig['ids'] = array_map('intval', $ids);
                }
            }

            // Delete logs using service.
            $result = $this->logService->deleteLogs($deleteConfig);

            return new JSONResponse(
                data: [
                    'success' => true,
                    'results' => $result,
                    'message' => sprintf(
                        'Deleted %d audit trails successfully. %d failed.',
                        $result['deleted'],
                        $result['failed']
                    ),
                ]
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Mass deletion failed: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end destroyMultiple()

    /**
     * Clear all audit trail logs
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response confirming clear or error
     */
    public function clearAll(): JSONResponse
    {
        try {
            // Use the clearAllLogs method from the mapper.
            $result = $this->auditTrailMapper->clearAllLogs();

            if ($result === true) {
                return new JSONResponse(
                    data: [
                        'success' => true,
                        'message' => 'All audit trails cleared successfully',
                        'deleted' => 'All expired audit trails have been deleted',
                    ],
                    statusCode: 200
                );
            }

            return new JSONResponse(
                data: [
                    'success' => true,
                    'message' => 'No expired audit trails found to clear',
                    'deleted' => 0,
                ],
                statusCode: 200
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                data: [
                    'success' => false,
                    'error'   => 'Failed to clear audit trails: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end clearAll()

    /**
     * Verify the integrity of the audit trail hash chain.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Verification result with valid/invalid status
     */
    public function verify(): JSONResponse
    {
        $from = $this->request->getParam('from');
        $to   = $this->request->getParam('to');

        $fromInt = ($from !== null) ? (int) $from : null;
        $toInt   = ($to !== null) ? (int) $to : null;

        try {
            $result = $this->auditHashService->verifyChain($fromInt, $toInt);
            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(
                data: ['error' => 'Verification failed: '.$e->getMessage()],
                statusCode: 500
            );
        }
    }//end verify()

    /**
     * Get verwerkingsregister (processing register) overview.
     *
     * Returns distinct processing activities from the audit trail with counts
     * and date ranges, for GDPR Art 30 compliance.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse List of processing activities
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function verwerkingsregister(): JSONResponse
    {
        $organisationId = $this->request->getParam('organisationId');

        try {
            $results = $this->auditTrailMapper->getProcessingActivities($organisationId);
            return new JSONResponse(data: $results);
        } catch (\Exception $e) {
            return new JSONResponse(
                data: ['error' => 'Failed to retrieve verwerkingsregister: '.$e->getMessage()],
                statusCode: 500
            );
        }
    }//end verwerkingsregister()

    /**
     * Handle a data subject access request (inzageverzoek).
     *
     * Searches audit trail entries by identifier in the changed JSON field,
     * grouped by schema.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Matching audit trail entries grouped by schema
     */
    public function inzageverzoek(): JSONResponse
    {
        $identifier = $this->request->getParam('identifier');

        if ($identifier === null || $identifier === '') {
            return new JSONResponse(
                data: ['error' => 'identifier parameter is required'],
                statusCode: 400
            );
        }

        try {
            $results = $this->auditTrailMapper->findByIdentifier($identifier);
            return new JSONResponse(data: $results);
        } catch (\Exception $e) {
            return new JSONResponse(
                data: ['error' => 'Inzageverzoek failed: '.$e->getMessage()],
                statusCode: 500
            );
        }
    }//end inzageverzoek()
}//end class
