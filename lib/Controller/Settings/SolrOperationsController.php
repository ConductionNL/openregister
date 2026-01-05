<?php

/**
 * OpenRegister SOLR Operations Controller
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Settings;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IDBConnection;
use Psr\Container\ContainerInterface;
use Exception;
use ReflectionClass;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\Index\SetupHandler;
use Psr\Log\LoggerInterface;

/**
 * Controller for SOLR operations (setup, testing, indexing).
 *
 * Handles:
 * - SOLR setup and initialization
 * - Connection testing and diagnostics
 * - Index warmup operations
 * - Index inspection and statistics
 * - Memory predictions
 * - SOLR management operations
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Settings
 */
class SolrOperationsController extends Controller
{
    /**
     * Constructor.
     *
     * @param string             $appName         The app name.
     * @param IRequest           $request         The request.
     * @param IDBConnection      $db              Database connection.
     * @param ContainerInterface $container       DI container.
     * @param SettingsService    $settingsService Settings service.
     * @param IndexService       $indexService    Index service.
     * @param LoggerInterface    $logger          Logger.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IDBConnection $db,
        private readonly ContainerInterface $container,
        private readonly SettingsService $settingsService,
        private readonly IndexService $indexService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Run SOLR setup to prepare for multi-tenant architecture
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse The SOLR setup results
     */
    public function setupSolr(): JSONResponse
    {
        try {
            // Get logger for improved logging.
            $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);

            // **IMPROVED LOGGING**: Log setup attempt with detailed context.
            $logger->info(
                message: '🔧 SOLR setup endpoint called',
                context: [
                    'timestamp'  => date('c'),
                    'user_id'    => $this->userId ?? 'unknown',
                    'request_id' => $this->request->getId() ?? 'unknown',
                ]
            );

            // Get SOLR settings.
            $solrSettings = $this->settingsService->getSolrSettings();

            // Determine port value for configuration display.
            $portValue = 'default';
            if (($solrSettings['port'] !== null) === true && ($solrSettings['port'] !== '') === true) {
                $portValue = $solrSettings['port'];
            }

            // **IMPROVED LOGGING**: Log SOLR configuration (without sensitive data).
            $logger->info(
                '📋 SOLR configuration loaded for setup',
                [
                    'enabled'         => $solrSettings['enabled'] ?? false,
                    'host'            => $solrSettings['host'] ?? 'not_set',
                    'port'            => $solrSettings['port'] ?? 'not_set',
                    'has_credentials' => empty($solrSettings['username']) === false
                        && empty($solrSettings['password']) === false,
                ]
            );

            // Create SolrSetup using IndexService for authenticated HTTP client.
            $guzzleSolrService = $this->container->get(IndexService::class);
            $setup = new SetupHandler(solrService: $guzzleSolrService, logger: $logger);

            // **IMPROVED LOGGING**: Log setup initialization.
            $logger->info(message: '🏗️ SolrSetup instance created, starting setup process');

            // Run setup.
            $setupResult = $setup->setupSolr();

            if ($setupResult === true) {
                // Get detailed setup progress and infrastructure info from SolrSetup.
                $setupProgress         = $setup->getSetupProgress();
                $infrastructureCreated = $setup->getInfrastructureCreated();

                // **IMPROVED LOGGING**: Log successful setup.
                $logger->info(
                    '✅ SOLR setup completed successfully',
                    [
                        'completed_steps' => $setupProgress['completed_steps'] ?? 0,
                        'total_steps'     => $setupProgress['total_steps'] ?? 0,
                        'duration'        => $setupProgress['completed_at'] ?? 'unknown',
                        'infrastructure'  => $infrastructureCreated,
                    ]
                );

                return new JSONResponse(
                    data: [
                        'success'        => true,
                        'message'        => 'SOLR setup completed successfully',
                        'timestamp'      => date('Y-m-d H:i:s'),
                        'mode'           => 'SolrCloud',
                        'progress'       => [
                            'started_at'      => $setupProgress['started_at'] ?? null,
                            'completed_at'    => $setupProgress['completed_at'] ?? null,
                            'total_steps'     => $setupProgress['total_steps'] ?? 5,
                            'completed_steps' => $setupProgress['completed_steps'] ?? 5,
                            'success'         => $setupProgress['success'] ?? true,
                        ],
                        'steps'          => $setupProgress['steps'] ?? [],
                        'infrastructure' => $infrastructureCreated,
                        'next_steps'     => [
                            'Tenant-specific resources are ready for use',
                            'Objects can now be indexed to SOLR',
                            'Search functionality is ready for use',
                        ],
                    ]
                );
            }

            // Get detailed error information and setup progress from SolrSetup.
            $errorDetails  = $setup->getLastErrorDetails();
            $setupProgress = $setup->getSetupProgress();

            if ($errorDetails !== null && $errorDetails !== '') {
                    // Get infrastructure info even on failure to show partial progress.
                    $infrastructureCreated = $setup->getInfrastructureCreated();

                    // Build troubleshooting steps from error details.
                    $troubleshooting      = $errorDetails['troubleshooting'] ?? $errorDetails['troubleshooting_tips'];
                    $defaultSteps         = [
                        'Check SOLR server connectivity',
                        'Verify SOLR configuration',
                        'Check SOLR server logs',
                    ];
                    $troubleshootingSteps = $troubleshooting ?? $defaultSteps;

                    // Use the detailed error information from SolrSetup.
                    return new JSONResponse(
                        data: [
                            'success'               => false,
                            'message'               => 'SOLR setup failed',
                            'timestamp'             => date('Y-m-d H:i:s'),
                            'mode'                  => 'SolrCloud',
                            'progress'              => [
                                'started_at'       => $setupProgress['started_at'] ?? null,
                                'completed_at'     => $setupProgress['completed_at'] ?? null,
                                'total_steps'      => $setupProgress['total_steps'] ?? 5,
                                'completed_steps'  => $setupProgress['completed_steps'] ?? 0,
                                'success'          => false,
                                'failed_at_step'   => $errorDetails['step'] ?? 'unknown',
                                'failed_step_name' => $errorDetails['step_name'] ?? 'unknown',
                            ],
                            'steps'                 => $setupProgress['steps'] ?? [],
                            'infrastructure'        => $infrastructureCreated,
                            'error_details'         => [
                                'primary_error'      => $errorDetails['error_message'] ?? 'SOLR setup operation failed',
                                'error_type'         => $errorDetails['error_type'] ?? 'unknown_error',
                                'operation'          => $errorDetails['operation'] ?? 'unknown_operation',
                                'step'               => $errorDetails['step'] ?? 'unknown',
                                'step_name'          => $errorDetails['step_name'] ?? 'unknown',
                                'url_attempted'      => $errorDetails['url_attempted'] ?? 'unknown',
                                'exception_type'     => $errorDetails['exception_type'] ?? 'unknown',
                                'error_category'     => $errorDetails['error_category'] ?? 'unknown',
                                'solr_response'      => $errorDetails['full_solr_response'] ?? null,
                                'guzzle_details'     => $errorDetails['guzzle_details'] ?? [],
                                'configuration_used' => [
                                    'host'   => $solrSettings['host'],
                                    'port'   => $portValue,
                                    'scheme' => $solrSettings['scheme'],
                                    'path'   => $solrSettings['path'],
                                ],
                            ],
                            'troubleshooting_steps' => $troubleshootingSteps,
                        ],
                        statusCode: 422
                    );
            }

            // Fallback to generic error if no detailed error information is available.
            $lastError = error_get_last();

            // Get last system error message.
            $lastSystemError = 'No system error captured';
            if ($lastError !== null && (($lastError['message'] ?? null) !== null)) {
                $lastSystemError = $lastError['message'];
            }

            // Get port value or default for fallback error response.
            $portValueFallback = 'default';
            if ($solrSettings['port'] !== null && $solrSettings['port'] !== '') {
                $portValueFallback = $solrSettings['port'];
            }

            return new JSONResponse(
                data: [
                    'success'               => false,
                    'message'               => 'SOLR setup failed',
                    'timestamp'             => date('Y-m-d H:i:s'),
                    'error_details'         => [
                        'primary_error'      => 'Setup failed but no detailed error information was captured',
                        'last_system_error'  => $lastSystemError,
                        'configuration_used' => [
                            'host'   => $solrSettings['host'],
                            'port'   => $portValueFallback,
                            'scheme' => $solrSettings['scheme'],
                            'path'   => $solrSettings['path'],
                        ],
                    ],
                    'troubleshooting_steps' => [
                        'Check SOLR server logs for detailed error messages',
                        'Verify SOLR server connectivity',
                        'Check SOLR configuration',
                    ],
                ],
                statusCode: 422
            );
        } catch (Exception $e) {
            // Get logger for error logging if not already available.
            if (isset($logger) === false) {
                $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
            }

            // **IMPROVED ERROR LOGGING**: Log detailed setup failure information.
            $logger->error(
                message: '❌ SOLR setup failed with exception',
                context: [
                    'exception_class'   => get_class($e),
                    'exception_message' => $e->getMessage(),
                    'exception_file'    => $e->getFile(),
                    'exception_line'    => $e->getLine(),
                    'trace'             => $e->getTraceAsString(),
                ]
            );

            // Try to get detailed error information from SolrSetup if available.
            $detailedError = null;
            if (($setup ?? null) !== null) {
                try {
                    $setupProgress    = $setup->getSetupProgress();
                    $lastErrorDetails = $setup->getLastErrorDetails();

                    $detailedError = [
                        'setup_progress'     => $setupProgress,
                        'last_error_details' => $lastErrorDetails,
                        'failed_at_step'     => $setupProgress['completed_steps'] ?? 0,
                        'total_steps'        => $setupProgress['total_steps'] ?? 5,
                    ];

                    // **IMPROVED LOGGING**: Log setup progress and error details.
                    $logger->error('📋 SOLR setup failure details', $detailedError);
                } catch (Exception $progressException) {
                    $logger->warning(
                        message: 'Failed to get setup progress details',
                        context: [
                            'error' => $progressException->getMessage(),
                        ]
                    );
                }//end try
            }//end if

            return new JSONResponse(
                data: [
                    'success'   => false,
                    'message'   => 'SOLR setup failed: '.$e->getMessage(),
                    'timestamp' => date('Y-m-d H:i:s'),
                    'error'     => [
                        'type'           => get_class($e),
                        'message'        => $e->getMessage(),
                        'file'           => $e->getFile(),
                        'line'           => $e->getLine(),
                        'detailed_error' => $detailedError,
                    ],
                ],
                statusCode: 422
            );
        }//end try
    }//end setupSolr()

    /**
     * Test SOLR connection with provided settings (basic connectivity and authentication only)
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse The test results
     *
     * @psalm-return JSONResponse<200, array<array-key, mixed>,
     *     array<never, never>>|JSONResponse<422,
     *     array{success: false, message: string,
     *     details: array{exception: string}}, array<never, never>>
     */
    public function testSolrConnection(): JSONResponse
    {
        try {
            // Test only basic SOLR connectivity and authentication.
            // Does NOT test collections, queries, or Zookeeper.
            $guzzleSolrService = $this->container->get(IndexService::class);
            $result            = $guzzleSolrService->testConnectivityOnly();

            return new JSONResponse(data: $result);
        } catch (Exception $e) {
            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Connection test failed: '.$e->getMessage(),
                    'details' => ['exception' => $e->getMessage()],
                ],
                statusCode: 422
            );
        }
    }//end testSolrConnection()

    /**
     * Warmup SOLR index
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Warmup operation results
     */
    public function warmupSolrIndex(): JSONResponse
    {
        try {
            // Get request parameters from JSON body or query parameters.
            $maxObjects = $this->request->getParam('maxObjects', 0);
            $batchSize  = $this->request->getParam('batchSize', 1000);
            $mode       = $this->request->getParam('mode', 'serial');
            // New mode parameter.
            $collectErrors = $this->request->getParam('collectErrors', false);
            // New error collection parameter.
            $schemaIds = $this->request->getParam('selectedSchemas', []);
            // New schema selection parameter.
            // Try to get from JSON body if not in query params.
            if ($maxObjects === 0) {
                $input = file_get_contents('php://input');
                if ($input !== false && $input !== '') {
                    $data = json_decode($input, true);
                    if ($data !== null && $data !== false) {
                        $maxObjects    = $data['maxObjects'] ?? 0;
                        $batchSize     = $data['batchSize'] ?? 1000;
                        $mode          = $data['mode'] ?? 'serial';
                        $collectErrors = $data['collectErrors'] ?? false;
                        $schemaIds     = $data['selectedSchemas'] ?? [];
                    }
                }
            }

            // Convert string boolean to actual boolean.
            if (is_string($collectErrors) === true) {
                $collectErrors = filter_var($collectErrors, FILTER_VALIDATE_BOOLEAN);
            }

            // Validate mode parameter.
            if (in_array($mode, ['serial', 'parallel', 'hyper'], true) === false) {
                return new JSONResponse(
                    data: [
                        'error' => 'Invalid mode parameter. Must be "serial", "parallel", or "hyper"',
                    ],
                    statusCode: 400
                );
            }

            // Debug logging for schema IDs.
            $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
            $logger->info(
                message: '🔥 WARMUP: Received warmup request',
                context: [
                    'maxObjects'      => $maxObjects,
                    'mode'            => $mode,
                    'batchSize'       => $batchSize,
                    'schemaIds'       => $schemaIds,
                    'schemaIds_type'  => gettype($schemaIds),
                    'schemaIds_count' => $this->getSchemaIdsCount($schemaIds),
                ]
            );

            // Phase 1: Use IndexService directly for SOLR operations.
            $guzzleSolrService = $this->container->get(IndexService::class);
            $result            = $guzzleSolrService->warmupIndex(
                schemas: [],
                maxObjects: $maxObjects,
                mode: $mode,
                collectErrors: $collectErrors,
                batchSize: $batchSize,
                schemaIds: $schemaIds
            );
            return new JSONResponse(data: $result);
        } catch (Exception $e) {
            // **ERROR VISIBILITY**: Let exceptions bubble up with full details.
            return new JSONResponse(
                data: [
                    'error'           => $e->getMessage(),
                    'exception_class' => get_class($e),
                    'file'            => $e->getFile(),
                    'line'            => $e->getLine(),
                    'trace'           => $e->getTraceAsString(),
                ],
                statusCode: 500
            );
        }//end try
    }//end warmupSolrIndex()

    /**
     * Inspect SOLR index documents
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse
     */
    public function inspectSolrIndex(): JSONResponse
    {
        try {
            $query  = $this->request->getParam('query', '*:*');
            $start  = (int) $this->request->getParam('start', 0);
            $rows   = (int) $this->request->getParam('rows', 20);
            $fields = $this->request->getParam('fields', '');

            // Validate parameters.
            $rows = min(max($rows, 1), 100);
            // Limit between 1 and 100.
            $start = max($start, 0);

            // Get IndexService from container.
            $guzzleSolrService = $this->container->get(IndexService::class);

            // Search documents in SOLR.
            $result = $guzzleSolrService->inspectIndex(query: $query, start: $start, rows: $rows, fields: $fields);

            if ($result['success'] === true) {
                return new JSONResponse(
                    data: [
                        'success'   => true,
                        'documents' => $result['documents'],
                        'total'     => $result['total'],
                        'start'     => $start,
                        'rows'      => $rows,
                        'query'     => $query,
                    ]
                );
            }

            return new JSONResponse(
                data: [
                    'success'       => false,
                    'error'         => $result['error'],
                    'error_details' => $result['error_details'] ?? null,
                ],
                statusCode: 422
            );
        } catch (Exception $e) {
            $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
            $logger->error(
                message: 'Exception in inspectSolrIndex controller',
                context: [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'success'       => false,
                    'error'         => 'Controller exception: '.$e->getMessage(),
                    'error_details' => [
                        'exception_type' => get_class($e),
                        'trace'          => $e->getTraceAsString(),
                    ],
                ],
                statusCode: 500
            );
        }//end try
    }//end inspectSolrIndex()

    /**
     * Get memory usage prediction for SOLR warmup
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with memory prediction
     */
    public function getSolrMemoryPrediction(): JSONResponse
    {
        try {
            // Get request parameters.
            $maxObjects = (int) $this->request->getParam('maxObjects', 0);

            // Get IndexService for prediction.
            $guzzleSolrService = $this->container->get(IndexService::class);

            if ($guzzleSolrService->isAvailable() === false) {
                return new JSONResponse(
                    data: [
                        'success'    => false,
                        'message'    => 'SOLR is not available or not configured',
                        'prediction' => [
                            'error'           => 'SOLR service unavailable',
                            'prediction_safe' => false,
                        ],
                    ],
                    statusCode: 422
                );
            }

            // Use reflection to call the private method (for API access).
            $reflection = new ReflectionClass($guzzleSolrService);
            $method     = $reflection->getMethod('predictWarmupMemoryUsage');
            $prediction = $method->invoke($guzzleSolrService, $maxObjects);

            return new JSONResponse(
                data: [
                    'success'    => true,
                    'message'    => 'Memory prediction calculated successfully',
                    'prediction' => $prediction,
                ]
            );
        } catch (Exception $e) {
            return new JSONResponse(
                data: [
                    'success'    => false,
                    'message'    => 'Failed to calculate memory prediction: '.$e->getMessage(),
                    'prediction' => [
                        'error'           => $e->getMessage(),
                        'prediction_safe' => false,
                    ],
                ],
                statusCode: 422
            );
        }//end try
    }//end getSolrMemoryPrediction()

    /**
     * Perform SOLR management operations
     *
     * @param string $operation Operation to perform (commit, optimize, clear)
     *
     * @return JSONResponse Operation results
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|500,
     *     array{error?: mixed|null|string, success?: false|mixed,
     *     operation?: 'clear'|'commit'|'optimize', message?: string,
     *     timestamp?: string, error_details?: mixed|null},
     *     array<never, never>>
     */
    public function manageSolr(string $operation): JSONResponse
    {
        try {
            // Phase 1: Use IndexService directly for SOLR operations.
            $guzzleSolrService = $this->container->get(IndexService::class);

            switch ($operation) {
                case 'commit':
                    $success = $guzzleSolrService->commit();

                    // Get commit message based on success.
                    $message = 'Failed to commit index';
                    if ($success === true) {
                        $message = 'Index committed successfully';
                    }

                    return new JSONResponse(
                        data: [
                            'success'   => $success,
                            'operation' => 'commit',
                            'message'   => $message,
                            'timestamp' => date('c'),
                        ]
                    );

                case 'optimize':
                    $success = $guzzleSolrService->optimize();

                    // Get optimize message based on success.
                    $message = 'Failed to optimize index';
                    if ($success === true) {
                        $message = 'Index optimized successfully';
                    }

                    return new JSONResponse(
                        data: [
                            'success'   => $success,
                            'operation' => 'optimize',
                            'message'   => $message,
                            'timestamp' => date('c'),
                        ]
                    );

                case 'clear':
                    $result = $guzzleSolrService->clearIndex();

                    // Get clear message based on success.
                    $message = 'Failed to clear index: '.($result['error'] ?? 'Unknown error');
                    if ($result['success'] === true) {
                        $message = 'Index cleared successfully';
                    }

                    return new JSONResponse(
                        data: [
                            'success'       => $result['success'],
                            'operation'     => 'clear',
                            'error'         => $result['error'] ?? null,
                            'error_details' => $result['error_details'] ?? null,
                            'message'       => $message,
                            'timestamp'     => date('c'),
                        ]
                    );

                default:
                    return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'Unknown operation: '.$operation,
                        ],
                        statusCode: 400
                    );
            }//end switch
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }//end try
    }//end manageSolr()
}//end class
