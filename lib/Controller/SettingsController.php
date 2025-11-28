<?php

declare(strict_types=1);

/*
 * OpenRegister Settings Controller
 *
 * This file contains the controller class for handling settings in the OpenRegister application.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Controller;

use OCP\IAppConfig;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IDBConnection;
use Psr\Container\ContainerInterface;
use OCP\App\IAppManager;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\GuzzleSolrService;
use OCA\OpenRegister\Service\SolrSchemaService;
use OCA\OpenRegister\Service\VectorEmbeddingService;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Psr\Log\LoggerInterface;

/**
 * Controller for handling settings-related operations in the OpenRegister.
 *
 * This controller serves as a THIN LAYER that validates HTTP requests and delegates
 * to the appropriate service for business logic execution. It does NOT contain
 * business logic itself.
 *
 * RESPONSIBILITIES:
 * - Validate HTTP request parameters
 * - Delegate settings CRUD operations to SettingsService
 * - Delegate LLM testing to VectorEmbeddingService and ChatService
 * - Delegate SOLR testing to GuzzleSolrService
 * - Return appropriate JSONResponse with correct HTTP status codes
 * - Handle HTTP-level concerns (authentication, CSRF, etc.)
 *
 * ARCHITECTURE PATTERN:
 * - Thin controller: minimal logic, delegates to services
 * - Services handle business logic and return structured arrays
 * - Controller converts service responses to JSONResponse
 * - Service errors are caught and converted to appropriate HTTP responses
 *
 * ENDPOINTS ORGANIZED BY CATEGORY:
 *
 * GENERAL SETTINGS:
 * - GET  /api/settings              - Get all settings
 * - POST /api/settings              - Update all settings
 * - GET  /api/settings/stats        - Get statistics
 * - POST /api/settings/rebase       - Rebase objects and logs
 *
 * RBAC SETTINGS:
 * - GET  /api/settings/rbac         - Get RBAC settings
 * - PUT  /api/settings/rbac         - Update RBAC settings
 * - PATCH /api/settings/rbac        - Patch RBAC settings
 *
 * MULTITENANCY SETTINGS:
 * - GET  /api/settings/multitenancy - Get multitenancy settings
 * - PUT  /api/settings/multitenancy - Update multitenancy settings
 * - PATCH /api/settings/multitenancy - Patch multitenancy settings
 *
 * RETENTION SETTINGS:
 * - GET  /api/settings/retention    - Get retention settings
 * - PUT  /api/settings/retention    - Update retention settings
 * - PATCH /api/settings/retention   - Patch retention settings
 *
 * SOLR SETTINGS:
 * - GET  /api/settings/solr         - Get SOLR settings
 * - PUT  /api/settings/solr         - Update SOLR settings
 * - PATCH /api/settings/solr        - Patch SOLR settings
 * - POST /api/settings/solr/test    - Test SOLR connection (delegates to GuzzleSolrService)
 * - POST /api/settings/solr/warmup  - Warmup SOLR index
 *
 * LLM SETTINGS:
 * - GET  /api/settings/llm          - Get LLM settings
 * - PUT  /api/settings/llm          - Update LLM settings
 * - PATCH /api/settings/llm         - Patch LLM settings
 * - POST /api/vectors/test-embedding - Test embedding generation (delegates to VectorEmbeddingService)
 * - POST /api/llm/test-chat         - Test chat functionality (delegates to ChatService)
 *
 * FILE SETTINGS:
 * - GET  /api/settings/files        - Get file settings
 * - PUT  /api/settings/files        - Update file settings
 * - PATCH /api/settings/files       - Patch file settings
 *
 * OBJECT SETTINGS:
 * - GET  /api/settings/objects      - Get object settings
 * - PUT  /api/settings/objects      - Update object settings
 * - PATCH /api/settings/objects     - Patch object settings
 *
 * CACHE MANAGEMENT:
 * - GET  /api/settings/cache/stats  - Get cache statistics
 * - POST /api/settings/cache/clear  - Clear cache
 * - POST /api/settings/cache/warmup - Warmup cache
 *
 * DELEGATION PATTERN:
 * - Settings storage/retrieval → SettingsService
 * - LLM embedding testing → VectorEmbeddingService
 * - LLM chat testing → ChatService
 * - SOLR testing → GuzzleSolrService
 * - Cache operations → Cache services
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 */
class SettingsController extends Controller
{

    /**
     * The OpenRegister object service.
     *
     * @var \OCA\OpenRegister\Service\ObjectService|null The OpenRegister object service.
     */
    private $objectService;


    /**
     * SettingsController constructor.
     *
     * @param string                 $appName                The name of the app
     * @param IRequest               $request                The request object
     * @param IAppConfig             $config                 The app configuration
     * @param IDBConnection          $db                     The database connection
     * @param ContainerInterface     $container              The container
     * @param IAppManager            $appManager             The app manager
     * @param SettingsService        $settingsService        The settings service
     * @param VectorEmbeddingService $vectorEmbeddingService The vector embedding service
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly IDBConnection $db,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly SettingsService $settingsService,
        private readonly VectorEmbeddingService $vectorEmbeddingService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()


    /**
     * Attempts to retrieve the OpenRegister service from the container.
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister service if available, null otherwise.
     * @throws \RuntimeException If the service is not available.
     */
    public function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            $this->objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            return $this->objectService;
        }

        throw new \RuntimeException('OpenRegister service is not available.');

    }//end getObjectService()


    /**
     * Attempts to retrieve the Configuration service from the container.
     *
     * @return \OCA\OpenRegister\Service\ConfigurationService|null The Configuration service if available, null otherwise.
     * @throws \RuntimeException If the service is not available.
     */
    public function getConfigurationService(): ?\OCA\OpenRegister\Service\ConfigurationService
    {
        // Check if the 'openregister' app is installed.
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            // Retrieve the ConfigurationService from the container.
            $configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
            return $configurationService;
        }

        // Throw an exception if the service is not available.
        throw new \RuntimeException('Configuration service is not available.');

    }//end getConfigurationService()


    /**
     * Retrieve the current settings.
     *
     * @return JSONResponse JSON response containing the current settings.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): JSONResponse
    {
        try {
            $data = $this->settingsService->getSettings();
            return new JSONResponse(data: $data);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end index()


    /**
     * Handle the PUT request to update settings.
     *
     * @return JSONResponse JSON response containing the updated settings.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function update(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $result = $this->settingsService->updateSettings($data);
            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end update()


    /**
     * Load the settings from the publication_register.json file.
     *
     * @return JSONResponse JSON response containing the settings.
     *
     * @NoCSRFRequired
     */
    public function load(): JSONResponse
    {
        try {
            $result = $this->settingsService->getSettings();
            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end load()


    /**
     * Update the publishing options.
     *
     * @return JSONResponse JSON response containing the updated publishing options.
     *
     * @NoCSRFRequired
     */
    public function updatePublishingOptions(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $result = $this->settingsService->updatePublishingOptions($data);
            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end updatePublishingOptions()


    /**
     * Rebase all objects and logs with current retention settings.
     *
     * This method recalculates deletion times for all objects and logs based on current retention settings.
     * It also assigns default owners and organizations to objects that don't have them assigned.
     *
     * @return JSONResponse JSON response containing the rebase operation result.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function rebase(): JSONResponse
    {
        try {
            $result = $this->settingsService->rebase();
            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end rebase()


    /**
     * Get statistics for the settings dashboard.
     *
     * This method provides warning counts for objects and logs that need attention,
     * as well as total counts for all objects, audit trails, and search trails.
     *
     * @return JSONResponse JSON response containing statistics data.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function stats(): JSONResponse
    {
        try {
            $result = $this->settingsService->getStats();
            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 422);
        }

    }//end stats()


    /**
     * Get statistics for the settings dashboard (alias for stats method).
     *
     * This method provides warning counts for objects and logs that need attention,
     * as well as total counts for all objects, audit trails, and search trails.
     *
     * @return JSONResponse JSON response containing statistics data.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getStatistics(): JSONResponse
    {
        return $this->stats();

    }//end getStatistics()


    /**
     * Get comprehensive cache statistics and performance metrics.
     *
     * This method provides detailed insights into cache usage, performance, memory consumption,
     * hit/miss rates, and object name cache statistics for admin monitoring.
     *
     * @return JSONResponse JSON response containing cache statistics data.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getCacheStats(): JSONResponse
    {
        try {
            $result = $this->settingsService->getCacheStats();
            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end getCacheStats()


    /**
     * Clear cache with granular control.
     *
     * This method supports clearing different types of caches: 'all', 'object', 'schema', 'facet', 'distributed', 'names'.
     * It accepts a JSON body with 'type' parameter to specify which cache to clear.
     *
     * @return JSONResponse JSON response containing cache clearing results.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function clearCache(): JSONResponse
    {
        try {
            $data = $this->request->getParams();
            $type = $data['type'] ?? 'all';

            $result = $this->settingsService->clearCache($type);
            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end clearCache()


    /**
     * Warmup object names cache manually.
     *
     * This method triggers manual cache warmup for object names to improve performance
     * after system maintenance or during off-peak hours.
     *
     * @return JSONResponse JSON response containing warmup operation results.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function warmupNamesCache(): JSONResponse
    {
        try {
            $result = $this->settingsService->warmupNamesCache();
            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 422);
        }

    }//end warmupNamesCache()


    /**
     * Validate all objects in the system
     *
     * This method validates all objects against their schemas and returns
     * a summary of validation results including any errors found.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Validation results summary
     */
    public function validateAllObjects(): JSONResponse
    {
        try {
            $objectService   = $this->getObjectService();
            $validateHandler = $this->container->get('OCA\OpenRegister\Service\ObjectHandlers\ValidateObject');
            $schemaMapper    = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');

            // Get all objects from the system.
            $allObjects = $objectService->findAll(config: []);

            $validationResults = [
                'total_objects'     => count($allObjects),
                'valid_objects'     => 0,
                'invalid_objects'   => 0,
                'validation_errors' => [],
                'summary'           => [],
            ];

            foreach ($allObjects as $object) {
                try {
                    // Get the schema for this object.
                    $schema = $schemaMapper->find(id: $object->getSchema());

                    // Validate the object against its schema using the ValidateObject handler.
                    $validationResult = $validateHandler->validateObject($object->getObject(), register: $schema);

                    if ($validationResult->isValid() === true) {
                        $validationResults['valid_objects']++;
                    } else {
                        $validationResults['invalid_objects']++;
                        $validationResults['validation_errors'][] = [
                            'object_id'   => $object->getUuid(),
                            'object_name' => $object->getName() ?? $object->getUuid(),
                            'register'    => $object->getRegister(),
                            'schema'      => $object->getSchema(),
                            'errors'      => $validationResult->error(),
                        ];
                    }
                } catch (\Exception $e) {
                    $validationResults['invalid_objects']++;
                    $validationResults['validation_errors'][] = [
                        'object_id'   => $object->getUuid(),
                        'object_name' => $object->getName() ?? $object->getUuid(),
                        'register'    => $object->getRegister(),
                        'schema'      => $object->getSchema(),
                        'errors'      => ['Validation failed: '.$e->getMessage()],
                    ];
                }//end try
            }//end foreach

            // Create summary.
            // Calculate validation success rate.
            $validationSuccessRate = 100;
            if ($validationResults['total_objects'] > 0) {
                $validationSuccessRate = round(($validationResults['valid_objects'] / $validationResults['total_objects']) * 100, 2);
            }

            $validationResults['summary'] = [
                'validation_success_rate' => $validationSuccessRate,
                'has_errors'              => $validationResults['invalid_objects'] > 0,
                'error_count'             => count($validationResults['validation_errors']),
            ];

            return new JSONResponse(data: $validationResults);
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'error'             => 'Failed to validate objects: '.$e->getMessage(),
                        'total_objects'     => 0,
                        'valid_objects'     => 0,
                        'invalid_objects'   => 0,
                        'validation_errors' => [],
                        'summary'           => ['has_errors' => true, 'error_count' => 1],
                    ],
                    statusCode: 500
                    );
        }//end try

    }//end validateAllObjects()


    /**
     * Mass validate all objects by re-saving them to trigger business logic
     *
     * This method re-saves all objects in the system to ensure all business logic
     * is triggered and objects are properly processed according to current rules.
     * Unlike validateAllObjects, this actually saves each object.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Mass validation results summary
     */
    public function massValidateObjects(): JSONResponse
    {
        try {
            $startTime   = microtime(true);
            $startMemory = memory_get_usage(true);
            $peakMemory  = memory_get_peak_usage(true);

            // Get request parameters from JSON body or query parameters.
            $maxObjects = $this->request->getParam('maxObjects', 0);
            $batchSize  = $this->request->getParam('batchSize', 1000);
            $mode       = $this->request->getParam('mode', 'serial');
            // New mode parameter.
            $collectErrors = $this->request->getParam('collectErrors', false);
            // New error collection parameter.
            // Try to get from JSON body if not in query params.
            if ($maxObjects === 0 && $batchSize === 1000) {
                $input = file_get_contents('php://input');
                if ($input !== false && $input !== '') {
                    $data = json_decode($input, true);
                    if ($data !== null && $data !== false) {
                        $maxObjects    = $data['maxObjects'] ?? 0;
                        $batchSize     = $data['batchSize'] ?? 1000;
                        $mode          = $data['mode'] ?? 'serial';
                        $collectErrors = $data['collectErrors'] ?? false;
                    }
                }
            }

            // Convert string boolean to actual boolean.
            if (is_string($collectErrors) === true) {
                $collectErrors = filter_var($collectErrors, FILTER_VALIDATE_BOOLEAN);
            }

            // Validate parameters.
            if (!in_array($mode, ['serial', 'parallel'])) {
                return new JSONResponse(
                    data: [
                        'error' => 'Invalid mode parameter. Must be "serial" or "parallel"',
                    ],
                    statusCode: 400
                );
            }

            if ($batchSize < 1 || $batchSize > 5000) {
                return new JSONResponse(
                    data: [
                        'error' => 'Invalid batch size. Must be between 1 and 5000',
                    ],
                    statusCode: 400
                );
            }

            $objectService = $this->getObjectService();
            $logger        = \OC::$server->get(\Psr\Log\LoggerInterface::class);

            // Use optimized approach like SOLR warmup - get count first, then process in chunks.
            $objectMapper = \OC::$server->get(\OCA\OpenRegister\Db\ObjectEntityMapper::class);
            $totalObjects = $objectMapper->countSearchObjects(query: [], activeOrganisationUuid: null, rbac: false, multi: false);

            // Apply maxObjects limit if specified.
            if ($maxObjects > 0 && $maxObjects < $totalObjects) {
                $totalObjects = $maxObjects;
            }

            $logger->info(
                    message: '🚀 STARTING MASS VALIDATION',
                    context: [
                        'totalObjects'  => $totalObjects,
                        'batchSize'     => $batchSize,
                        'mode'          => $mode,
                        'collectErrors' => $collectErrors,
                    ]
                    );

            $results = [
                'success'           => true,
                'message'           => 'Mass validation completed successfully',
                'stats'             => [
                    'total_objects'      => $totalObjects,
                    'processed_objects'  => 0,
                    'successful_saves'   => 0,
                    'failed_saves'       => 0,
                    'duration_seconds'   => 0,
                    'batches_processed'  => 0,
                    'objects_per_second' => 0,
                ],
                'errors'            => [],
                'batches_processed' => 0,
                'timestamp'         => date('c'),
                'config_used'       => [
                    'mode'           => $mode,
                    'max_objects'    => $maxObjects,
                    'batch_size'     => $batchSize,
                    'collect_errors' => $collectErrors,
                ],
            ];

            // Create batch jobs like SOLR warmup.
            $batchJobs   = [];
            $offset      = 0;
            $batchNumber = 0;

            while ($offset < $totalObjects) {
                $currentBatchSize = min($batchSize, $totalObjects - $offset);
                $batchJobs[]      = [
                    'batchNumber' => ++$batchNumber,
                    'offset'      => $offset,
                    'limit'       => $currentBatchSize,
                ];
                $offset          += $currentBatchSize;
            }

            $results['stats']['batches_processed'] = count($batchJobs);

            $logger->info(
                    message: '📋 BATCH JOBS CREATED',
                    context: [
                        'totalBatches'      => count($batchJobs),
                        'estimatedDuration' => round((count($batchJobs) * 2)).'s',
                    ]
                    );

            // Process batches based on mode.
            if ($mode === 'parallel') {
                $this->processJobsParallel(batchJobs: $batchJobs, objectMapper: $objectMapper, objectService: $objectService, results: $results, collectErrors: $collectErrors, parallelBatches: 4, logger: $logger);
            } else {
                $this->processJobsSerial($batchJobs, $objectMapper, $objectService, $results, $collectErrors, $logger);
            }

            // Calculate final metrics.
            $endTime         = microtime(true);
            $endMemory       = memory_get_usage(true);
            $finalPeakMemory = memory_get_peak_usage(true);

            $results['stats']['duration_seconds'] = round($endTime - $startTime, 2);
            // Calculate objects per second.
            $objectsPerSecond = 0;
            if ($results['stats']['duration_seconds'] > 0) {
                $objectsPerSecond = round($results['stats']['processed_objects'] / $results['stats']['duration_seconds'], 2);
            }

            $results['stats']['objects_per_second'] = $objectsPerSecond;

            // Add memory usage information.
            $results['memory_usage'] = [
                'start_memory'    => $startMemory,
                'end_memory'      => $endMemory,
                'peak_memory'     => max($peakMemory, $finalPeakMemory),
                'memory_used'     => $endMemory - $startMemory,
                'peak_percentage' => round((max($peakMemory, $finalPeakMemory) / (1024 * 1024 * 1024)) * 100, 1),
            // Assume 1GB available.
                'formatted'       => [
                    'actual_used'     => $this->formatBytes($endMemory - $startMemory),
                    'peak_usage'      => $this->formatBytes(max($peakMemory, $finalPeakMemory)),
                    'peak_percentage' => round((max($peakMemory, $finalPeakMemory) / (1024 * 1024 * 1024)) * 100, 1).'%',
                ],
            ];

            // Determine overall success.
            if ($results['stats']['failed_saves'] > 0) {
                if ($collectErrors === true) {
                    $results['success'] = $results['stats']['successful_saves'] > 0;
                    // Partial success if some objects were saved.
                    $results['message'] = sprintf(
                        'Mass validation completed with %d errors out of %d objects (%d successful)',
                            $results['stats']['failed_saves'],
                        $results['stats']['total_objects'],
                        $results['stats']['successful_saves']
                            );
                } else {
                    $results['success'] = false;
                    $results['message'] = sprintf(
                        'Mass validation stopped after %d errors (processed %d out of %d objects)',
                            $results['stats']['failed_saves'],
                        $results['stats']['processed_objects'],
                        $results['stats']['total_objects']
                            );
                }
            }

            $logger->info(
                    '✅ MASS VALIDATION COMPLETED',
                    [
                        'successful'       => $results['stats']['successful_saves'],
                        'failed'           => $results['stats']['failed_saves'],
                        'total'            => $results['stats']['processed_objects'],
                        'duration'         => $results['stats']['duration_seconds'].'s',
                        'objectsPerSecond' => $results['stats']['objects_per_second'],
                        'mode'             => $mode,
                    ]
                    );

            return new JSONResponse(data: $results);
        } catch (\Exception $e) {
            $logger = $logger ?? \OC::$server->get(\Psr\Log\LoggerInterface::class);
            $logger->error(
                    message: '❌ MASS VALIDATION FAILED',
                    context: [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success'   => false,
                        'error'     => 'Mass validation failed: '.$e->getMessage(),
                        'stats'     => [
                            'total_objects'     => 0,
                            'processed_objects' => 0,
                            'successful_saves'  => 0,
                            'failed_saves'      => 0,
                            'duration_seconds'  => 0,
                        ],
                        'errors'    => [
                            [
                                'error' => $e->getMessage(),
                            ],
                        ],
                        'timestamp' => date('c'),
                    ],
                    statusCode: 500
                    );
        }//end try

    }//end massValidateObjects()


    /**
     * Process a batch of objects in serial mode
     *
     * @param  array $batch         Array of objects to process
     * @param  mixed $objectService The object service instance
     * @param  array &$results      Results array to update
     * @param  bool  $collectErrors Whether to collect all errors or stop on first
     * @return void
     */
    private function processBatchSerial(array $batch, $objectService, array &$results, bool $collectErrors, $logger=null): void
    {
        foreach ($batch as $object) {
            try {
                $results['stats']['processed_objects']++;

                // Re-save the object to trigger all business logic.
                // This will run validation, transformations, and other handlers.
                $savedObject = $objectService->saveObject(
                        register: $object->getObject(),
                        schema: [],
                        data:
                // extend parameter.
                    $object->getRegister(),
                        uuid: $object->getSchema(),
                        folderId: $object->getUuid()
                );

                if ($savedObject !== null) {
                    $results['stats']['successful_saves']++;
                } else {
                    $results['stats']['failed_saves']++;
                    $results['errors'][] = [
                        'object_id'   => $object->getUuid(),
                        'object_name' => $object->getName() ?? $object->getUuid(),
                        'register'    => $object->getRegister(),
                        'schema'      => $object->getSchema(),
                        'error'       => 'Save operation returned null',
                        'batch_mode'  => 'serial',
                    ];
                }
            } catch (\Exception $e) {
                $results['stats']['failed_saves']++;
                $results['errors'][] = [
                    'object_id'   => $object->getUuid(),
                    'object_name' => $object->getName() ?? $object->getUuid(),
                    'register'    => $object->getRegister(),
                    'schema'      => $object->getSchema(),
                    'error'       => $e->getMessage(),
                    'batch_mode'  => 'serial',
                ];

                // Log the error for debugging (logger is passed as parameter).
                $logger->error('Mass validation failed for object '.$object->getUuid().': '.$e->getMessage());

                // If not collecting errors, stop processing this batch.
                if ($collectErrors === false) {
                    break;
                }
            }//end try
        }//end foreach

    }//end processBatchSerial()


    /**
     * Process a batch of objects in parallel mode (simulated)
     *
     * @param  array $batch           Array of objects to process
     * @param  mixed $objectService   The object service instance
     * @param  array &$results        Results array to update
     * @param  bool  $collectErrors   Whether to collect all errors or stop on first
     * @param  int   $parallelBatches Number of parallel batches (unused in current implementation)
     * @param  mixed $logger          Optional logger instance
     * @return void
     */
    private function processBatchParallel(array $batch, $objectService, array &$results, bool $collectErrors, int $parallelBatches=1, $logger=null): void
    {
            // Note: True parallel processing would require process forking or threading.
        // For now, we simulate parallel processing with optimized serial processing.
        // In a real implementation, you might use ReactPHP, Swoole, or similar.
        $batchErrors    = [];
        $batchSuccesses = 0;

        foreach ($batch as $object) {
            try {
                $results['stats']['processed_objects']++;

                // Re-save the object to trigger all business logic.
                // This will run validation, transformations, and other handlers.
                $savedObject = $objectService->saveObject(
                        register: $object->getObject(),
                        schema: [],
                        data:
                // extend parameter.
                    $object->getRegister(),
                        uuid: $object->getSchema(),
                        folderId: $object->getUuid()
                );

                if ($savedObject !== null) {
                    $batchSuccesses++;
                } else {
                    $batchErrors[] = [
                        'object_id'   => $object->getUuid(),
                        'object_name' => $object->getName() ?? $object->getUuid(),
                        'register'    => $object->getRegister(),
                        'schema'      => $object->getSchema(),
                        'error'       => 'Save operation returned null',
                        'batch_mode'  => 'parallel',
                    ];
                }
            } catch (\Exception $e) {
                $batchErrors[] = [
                    'object_id'   => $object->getUuid(),
                    'object_name' => $object->getName() ?? $object->getUuid(),
                    'register'    => $object->getRegister(),
                    'schema'      => $object->getSchema(),
                    'error'       => $e->getMessage(),
                    'batch_mode'  => 'parallel',
                ];

                // Log the error for debugging (logger is passed as parameter).
                $logger->error('Mass validation failed for object '.$object->getUuid().': '.$e->getMessage());

                // If not collecting errors, stop processing this batch.
                if ($collectErrors === false) {
                    break;
                }
            }//end try
        }//end foreach

        // Update results with batch totals.
        $results['stats']['successful_saves'] += $batchSuccesses;
        $results['stats']['failed_saves']     += count($batchErrors);
        $results['errors'] = array_merge($results['errors'], $batchErrors);

    }//end processBatchParallel()


    /**
     * Process batch jobs in serial mode (optimized like SOLR warmup)
     *
     * @param  array $batchJobs     Array of batch job definitions
     * @param  mixed $objectMapper  The object entity mapper
     * @param  mixed $objectService The object service instance
     * @param  array &$results      Results array to update
     * @param  bool  $collectErrors Whether to collect all errors or stop on first
     * @return void
     */
    private function processJobsSerial(array $batchJobs, $objectMapper, $objectService, array &$results, bool $collectErrors, $logger): void
    {
        foreach ($batchJobs as $job) {
            $batchStartTime = microtime(true);

            // Get objects for this batch using offset/limit like SOLR warmup.
            $objects = $objectMapper->findAll(
                limit: $job['limit'],
                offset: $job['offset']
            );

            $batchProcessed = 0;
            $batchSuccesses = 0;
            $batchErrors    = [];

            foreach ($objects as $object) {
                try {
                    $batchProcessed++;
                    $results['stats']['processed_objects']++;

                    // Re-save the object to trigger all business logic.
                    $savedObject = $objectService->saveObject(
                            register: $object->getObject(),
                            schema: [],
                            data:
                    // extend parameter.
                        $object->getRegister(),
                            uuid: $object->getSchema(),
                            folderId: $object->getUuid()
                    );

                    if ($savedObject !== null) {
                        $batchSuccesses++;
                        $results['stats']['successful_saves']++;
                    } else {
                        $results['stats']['failed_saves']++;
                        $batchErrors[] = [
                            'object_id'   => $object->getUuid(),
                            'object_name' => $object->getName() ?? $object->getUuid(),
                            'register'    => $object->getRegister(),
                            'schema'      => $object->getSchema(),
                            'error'       => 'Save operation returned null',
                            'batch_mode'  => 'serial_optimized',
                        ];
                    }
                } catch (\Exception $e) {
                    $results['stats']['failed_saves']++;
                    $batchErrors[] = [
                        'object_id'   => $object->getUuid(),
                        'object_name' => $object->getName() ?? $object->getUuid(),
                        'register'    => $object->getRegister(),
                        'schema'      => $object->getSchema(),
                        'error'       => $e->getMessage(),
                        'batch_mode'  => 'serial_optimized',
                    ];

                    $logger->error('Mass validation failed for object '.$object->getUuid().': '.$e->getMessage());

                    if ($collectErrors === false) {
                        break;
                    }
                }//end try
            }//end foreach

            $batchDuration = microtime(true) - $batchStartTime;
            // Calculate objects per second.
            $objectsPerSecond = 0;
            if ($batchDuration > 0) {
                $objectsPerSecond = round($batchProcessed / $batchDuration, 2);
            }

            // Log progress every batch like SOLR warmup.
            $logger->info(
                    '📈 MASS VALIDATION PROGRESS',
                    [
                        'batchNumber'      => $job['batchNumber'],
                        'totalBatches'     => count($batchJobs),
                        'processed'        => $batchProcessed,
                        'successful'       => $batchSuccesses,
                        'failed'           => count($batchErrors),
                        'batchDuration'    => round($batchDuration * 1000).'ms',
                        'objectsPerSecond' => $objectsPerSecond,
                        'totalProcessed'   => $results['stats']['processed_objects'],
                    ]
                    );

            // Add batch errors to results.
            $results['errors'] = array_merge($results['errors'], $batchErrors);

            // Memory management every 10 batches.
            if ($job['batchNumber'] % 10 === 0) {
                $logger->debug(
                        message: '🧹 MEMORY CLEANUP',
                        context: [
                            'memoryUsage' => round(memory_get_usage() / 1024 / 1024, 2).'MB',
                            'peakMemory'  => round(memory_get_peak_usage() / 1024 / 1024, 2).'MB',
                        ]
                        );
                gc_collect_cycles();
            }

            // Clear objects from memory.
            unset($objects);
        }//end foreach

    }//end processJobsSerial()


    /**
     * Process batch jobs in parallel mode (optimized like SOLR warmup)
     *
     * @param  array $batchJobs       Array of batch job definitions
     * @param  mixed $objectMapper    The object entity mapper
     * @param  mixed $objectService   The object service instance
     * @param  array &$results        Results array to update
     * @param  bool  $collectErrors   Whether to collect all errors or stop on first
     * @param  int   $parallelBatches Number of parallel batches to process
     * @return void
     */
    private function processJobsParallel(array $batchJobs, $objectMapper, $objectService, array &$results, bool $collectErrors, int $parallelBatches, $logger): void
    {
        // Process batches in parallel chunks like SOLR warmup.
        $batchChunks = array_chunk($batchJobs, $parallelBatches);

        foreach ($batchChunks as $chunkIndex => $chunk) {
            $logger->info(
                    message: '🔄 PROCESSING PARALLEL CHUNK',
                    context: [
                        'chunkIndex'     => $chunkIndex + 1,
                        'totalChunks'    => count($batchChunks),
                        'batchesInChunk' => count($chunk),
                    ]
                    );

            $chunkStartTime = microtime(true);

            // Process batches in this chunk (simulated parallel processing).
            // In a real implementation, this would use actual parallel processing.
            $chunkResults = [];
            foreach ($chunk as $job) {
                $result         = $this->processBatchDirectly(objectMapper: $objectMapper, objectService: $objectService, job: $job, collectErrors: $collectErrors);
                $chunkResults[] = $result;
            }

            // Aggregate results from this chunk.
            foreach ($chunkResults as $result) {
                $results['stats']['processed_objects'] += $result['processed'];
                $results['stats']['successful_saves']  += $result['successful'];
                $results['stats']['failed_saves']      += $result['failed'];
                $results['errors'] = array_merge($results['errors'], $result['errors']);
            }

            $chunkTime      = round((microtime(true) - $chunkStartTime) * 1000, 2);
            $chunkProcessed = array_sum(array_column($chunkResults, 'processed'));

            $logger->info(
                    '✅ COMPLETED PARALLEL CHUNK',
                    [
                        'chunkIndex'       => $chunkIndex + 1,
                        'chunkTime'        => $chunkTime.'ms',
                        'objectsProcessed' => $chunkProcessed,
                        'totalProcessed'   => $results['stats']['processed_objects'],
                    ]
                    );

            // Memory cleanup after each chunk.
            gc_collect_cycles();
        }//end foreach

    }//end processJobsParallel()


    /**
     * Process a single batch directly (helper for parallel processing)
     *
     * @param  mixed $objectMapper  The object entity mapper
     * @param  mixed $objectService The object service instance
     * @param  array $job           Batch job definition
     * @param  bool  $collectErrors Whether to collect all errors
     * @return array Batch processing results
     */
    private function processBatchDirectly($objectMapper, $objectService, array $job, bool $collectErrors): array
    {
        $batchStartTime = microtime(true);

        // Get objects for this batch.
        $objects = $objectMapper->findAll(
            limit: $job['limit'],
            offset: $job['offset']
        );

        $batchProcessed = 0;
        $batchSuccesses = 0;
        $batchErrors    = [];

        foreach ($objects as $object) {
            try {
                $batchProcessed++;

                // Re-save the object to trigger all business logic.
                $savedObject = $objectService->saveObject(
                        register: $object->getObject(),
                        schema: [],
                        data:
                // extend parameter.
                    $object->getRegister(),
                        uuid: $object->getSchema(),
                        folderId: $object->getUuid()
                );

                if ($savedObject !== null) {
                    $batchSuccesses++;
                } else {
                    $batchErrors[] = [
                        'object_id'   => $object->getUuid(),
                        'object_name' => $object->getName() ?? $object->getUuid(),
                        'register'    => $object->getRegister(),
                        'schema'      => $object->getSchema(),
                        'error'       => 'Save operation returned null',
                        'batch_mode'  => 'parallel_optimized',
                    ];
                }
            } catch (\Exception $e) {
                $batchErrors[] = [
                    'object_id'   => $object->getUuid(),
                    'object_name' => $object->getName() ?? $object->getUuid(),
                    'register'    => $object->getRegister(),
                    'schema'      => $object->getSchema(),
                    'error'       => $e->getMessage(),
                    'batch_mode'  => 'parallel_optimized',
                ];

                if ($collectErrors === false) {
                    break;
                }
            }//end try
        }//end foreach

        $batchDuration = microtime(true) - $batchStartTime;

        // Clear objects from memory.
        unset($objects);

        return [
            'processed'  => $batchProcessed,
            'successful' => $batchSuccesses,
            'failed'     => count($batchErrors),
            'errors'     => $batchErrors,
            'duration'   => $batchDuration,
        ];

    }//end processBatchDirectly()


    /**
     * Format bytes into human readable format
     *
     * @param  int $bytes     Number of bytes
     * @param  int $precision Decimal precision
     * @return string Formatted string
     */
    private function formatBytes(int $bytes, int $precision=2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        // Ensure $i is within bounds of $units array.
        $unitIndex = min($i, count($units) - 1);
        return round($bytes, $precision).' '.$units[$unitIndex];

    }//end formatBytes()


    /**
     * Predict memory usage for mass validation operation
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Memory prediction results
     */
    public function predictMassValidationMemory(): JSONResponse
    {
        try {
            // Get request parameters.
            $maxObjects = $this->request->getParam('maxObjects', 0);

            // Try to get from JSON body if not in query params.
            if ($maxObjects === 0) {
                $input = file_get_contents('php://input');
                if ($input !== false && $input !== '') {
                    $data = json_decode($input, true);
                    if ($data !== null && $data !== false) {
                        $maxObjects = $data['maxObjects'] ?? 0;
                    }
                }
            }

            // Get current memory usage without loading all objects (much faster).
            $currentMemory = memory_get_usage(true);
            $memoryLimit   = ini_get('memory_limit');

            // Convert memory limit to bytes.
            $memoryLimitBytes = $this->convertToBytes($memoryLimit);
            $availableMemory  = $memoryLimitBytes - $currentMemory;

            // Use a lightweight approach - estimate based on typical object size.
            // We'll use the maxObjects parameter or provide a reasonable default estimate.
            $estimatedObjectCount = 10000;
            // Default estimate.
            if ($maxObjects > 0) {
                $estimatedObjectCount = $maxObjects;
            }

            // Estimate memory usage (rough calculation).
            // Assume each object uses approximately 50KB in memory during processing.
            $estimatedMemoryPerObject = 50 * 1024;
            // 50KB.
            $totalEstimatedMemory = $estimatedObjectCount * $estimatedMemoryPerObject;

            // Determine if prediction is safe.
            $predictionSafe = $totalEstimatedMemory < ($availableMemory * 0.8);
            // Use 80% as safety margin.
            $prediction = [
                'success'                  => true,
                'prediction_safe'          => $predictionSafe,
                'objects_to_process'       => $estimatedObjectCount,
                'total_objects_available'  => 'Unknown (fast mode)',
            // Don't count all objects for speed.
                'memory_per_object_bytes'  => $estimatedMemoryPerObject,
                'total_predicted_bytes'    => $totalEstimatedMemory,
                'current_memory_bytes'     => $currentMemory,
                'memory_limit_bytes'       => $memoryLimitBytes,
                'available_memory_bytes'   => $availableMemory,
                'safety_margin_percentage' => 80,
                'formatted'                => [
                    'total_predicted'   => $this->formatBytes($totalEstimatedMemory),
                    'available'         => $this->formatBytes($availableMemory),
                    'current_usage'     => $this->formatBytes($currentMemory),
                    'memory_limit'      => $this->formatBytes($memoryLimitBytes),
                    'memory_per_object' => $this->formatBytes($estimatedMemoryPerObject),
                ],
                // Get recommendation message based on prediction safety.
                'recommendation'           => $predictionSafe ? 'Safe to process' : 'Warning: Memory usage may exceed available memory',
                'note'                     => 'Fast prediction mode - actual object count will be determined during processing',
            ];

            return new JSONResponse(data: $prediction);
        } catch (\Exception $e) {
            return new JSONResponse(
                data: [
                    'success'         => false,
                    'error'           => 'Failed to predict memory usage: '.$e->getMessage(),
                    'prediction_safe' => true,
                    // Default to safe if we can't predict.
                    'formatted'       => [
                        'total_predicted' => 'Unknown',
                        'available'       => 'Unknown',
                    ],
                ],
                statusCode: 500
            );
        }//end try

    }//end predictMassValidationMemory()


    /**
     * Convert memory limit string to bytes
     *
     * @param  string $memoryLimit Memory limit string (e.g., '128M', '1G')
     * @return int Memory limit in bytes
     */
    private function convertToBytes(string $memoryLimit): int
    {
        $memoryLimit = trim($memoryLimit);
        $last        = strtolower($memoryLimit[strlen($memoryLimit) - 1]);
        $value       = (int) $memoryLimit;

        switch ($last) {
            case 'g':
                $value *= 1024;
                // no break.
            case 'm':
                $value *= 1024;
                // no break.
            case 'k':
                $value *= 1024;
        }

        return $value;

    }//end convertToBytes()


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

            // **IMPROVED LOGGING**: Log SOLR configuration (without sensitive data).
            $logger->info(
                    '📋 SOLR configuration loaded for setup',
                    [
                        'enabled'         => $solrSettings['enabled'] ?? false,
                        'host'            => $solrSettings['host'] ?? 'not_set',
                        'port'            => $solrSettings['port'] ?? 'not_set',
                        'has_credentials' => !empty($solrSettings['username']) === true && !empty($solrSettings['password']),
                    ]
                    );

            // Create SolrSetup using GuzzleSolrService for authenticated HTTP client.
            $guzzleSolrService = $this->container->get(\OCA\OpenRegister\Service\GuzzleSolrService::class);
            $setup = new \OCA\OpenRegister\Setup\SolrSetup(solrService: $guzzleSolrService, logger: $logger);

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
            } else {
                // Get detailed error information and setup progress from SolrSetup.
                $errorDetails  = $setup->getLastErrorDetails();
                $setupProgress = $setup->getSetupProgress();

                if ($errorDetails !== null && $errorDetails !== '') {
                    // Get infrastructure info even on failure to show partial progress.
                    $infrastructureCreated = $setup->getInfrastructureCreated();

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
                                        'port'   => (($solrSettings['port'] !== null) === true && ($solrSettings['port'] !== '') === true) ? $solrSettings['port'] : 'default',
                                        'scheme' => $solrSettings['scheme'],
                                        'path'   => $solrSettings['path'],
                                    ],
                                ],
                                'troubleshooting_steps' => $errorDetails['troubleshooting'] ?? $errorDetails['troubleshooting_tips'] ?? [
                                    'Check SOLR server connectivity',
                                    'Verify SOLR configuration',
                                    'Check SOLR server logs',
                                ],
                                'steps'                 => $setupProgress['steps'] ?? [],
                            ],
                            statusCode: 422
                            );
                } else {
                    // Fallback to generic error if no detailed error information is available.
                    $lastError = error_get_last();

                    // Get last system error message.
                    $lastSystemError = 'No system error captured';
                    if ($lastError !== null && (($lastError['message'] ?? null) !== null)) {
                        $lastSystemError = $lastError['message'];
                    }

                    // Get port value or default.
                    $portValue = 'default';
                    if ($solrSettings['port'] !== null && $solrSettings['port'] !== '') {
                        $portValue = $solrSettings['port'];
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
                                    'port'   => $portValue,
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
                }//end if
            }//end if
        } catch (\Exception $e) {
            // Get logger for error logging if not already available.
            if (!isset($logger)) {
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
                } catch (\Exception $progressException) {
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
     * Test SOLR setup directly (bypassing SolrService)
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse The SOLR setup test results
     */
    public function testSolrSetup(): JSONResponse
    {
        try {
            // Get SOLR settings directly.
            $solrSettings = $this->settingsService->getSolrSettings();

            if (!$solrSettings['enabled']) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'SOLR is disabled',
                        ],
                        statusCode: 400
                        );
            }

            // Create SolrSetup using GuzzleSolrService for authenticated HTTP client.
            $logger            = \OC::$server->get(\Psr\Log\LoggerInterface::class);
            $guzzleSolrService = $this->container->get(\OCA\OpenRegister\Service\GuzzleSolrService::class);
            $setup = new \OCA\OpenRegister\Setup\SolrSetup(solrService: $guzzleSolrService, logger: $logger);

            // Run setup.
            $result = $setup->setupSolr();

            if ($result === true) {
                return new JSONResponse(
                        data: [
                            'success' => true,
                            'message' => 'SOLR setup completed successfully',
                            'config'  => [
                                'host'   => $solrSettings['host'],
                                'port'   => $solrSettings['port'],
                                'scheme' => $solrSettings['scheme'],
                            ],
                        ]
                        );
            } else {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'SOLR setup failed - check logs',
                        ],
                        statusCode: 422
                        );
            }//end if
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'SOLR setup error: '.$e->getMessage(),
                    ],
                    statusCode: 422
                    );
        }//end try

    }//end testSolrSetup()


    /**
     * Delete a specific SOLR collection by name
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $name The name of the collection to delete
     *
     * @return JSONResponse The deletion result
     */
    public function deleteSpecificSolrCollection(string $name): JSONResponse
    {
        try {
            $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);

            $logger->warning(
                    message: '🚨 SOLR collection deletion requested',
                    context: [
                        'timestamp'  => date('c'),
                        'user_id'    => $this->userId ?? 'unknown',
                        'collection' => $name,
                        'request_id' => $this->request->getId() ?? 'unknown',
                    ]
                    );

            // Get GuzzleSolrService.
            $guzzleSolrService = $this->container->get(\OCA\OpenRegister\Service\GuzzleSolrService::class);

            // Delete the specific collection.
            $result = $guzzleSolrService->deleteCollection($name);

            if ($result['success'] === true) {
                $logger->info(
                        message: '✅ SOLR collection deleted successfully',
                        context: [
                            'collection' => $name,
                            'user_id'    => $this->userId ?? 'unknown',
                        ]
                        );

                return new JSONResponse(
                        data: [
                            'success'    => true,
                            'message'    => 'Collection deleted successfully',
                            'collection' => $name,
                        ],
                        statusCode: 200
                        );
            } else {
                $logger->error(
                        '❌ SOLR collection deletion failed',
                        [
                            'error'      => $result['message'],
                            'error_code' => $result['error_code'] ?? 'unknown',
                            'collection' => $name,
                        ]
                        );

                return new JSONResponse(
                        data: [
                            'success'    => false,
                            'message'    => $result['message'],
                            'error_code' => $result['error_code'] ?? 'unknown',
                            'collection' => $name,
                            'solr_error' => $result['solr_error'] ?? null,
                        ],
                        statusCode: 422
                        );
            }//end if
        } catch (\Exception $e) {
            $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
            $logger->error(
                    message: 'Exception during SOLR collection deletion',
                    context: [
                        'error'      => $e->getMessage(),
                        'collection' => $name,
                        'trace'      => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success'    => false,
                        'message'    => 'Collection deletion failed: '.$e->getMessage(),
                        'error_code' => 'EXCEPTION',
                        'collection' => $name,
                    ],
                    statusCode: 422
                    );
        }//end try

    }//end deleteSpecificSolrCollection()


    /**
     * Clear a specific SOLR collection by name
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $name The name of the collection to clear
     *
     * @return JSONResponse The clear result
     */
    public function clearSpecificCollection(string $name): JSONResponse
    {
        try {
            $guzzleSolrService = $this->container->get(\OCA\OpenRegister\Service\GuzzleSolrService::class);

            // Clear the specific collection.
            $result = $guzzleSolrService->clearIndex($name);

            if ($result['success'] === true) {
                return new JSONResponse(
                        data: [
                            'success'    => true,
                            'message'    => 'Collection cleared successfully',
                            'collection' => $name,
                        ],
                        statusCode: 200
                        );
            } else {
                return new JSONResponse(
                        data: [
                            'success'    => false,
                            'message'    => $result['message'] ?? 'Failed to clear collection',
                            'collection' => $name,
                        ],
                        statusCode: 422
                        );
            }
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success'    => false,
                        'message'    => 'Collection clear failed: '.$e->getMessage(),
                        'collection' => $name,
                    ],
                    statusCode: 422
                    );
        }//end try

    }//end clearSpecificCollection()


    /**
     * Reindex a specific SOLR collection by name
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $name The name of the collection to reindex
     *
     * @return JSONResponse The reindex result
     */
    public function reindexSpecificCollection(string $name): JSONResponse
    {
        try {
            $guzzleSolrService = $this->container->get(\OCA\OpenRegister\Service\GuzzleSolrService::class);

            // Get optional parameters from request body.
            $maxObjects = (int) ($this->request->getParam('maxObjects', 0));
            $batchSize  = (int) ($this->request->getParam('batchSize', 1000));

            // Validate parameters.
            if ($batchSize < 1 || $batchSize > 5000) {
                return new JSONResponse(
                        data: [
                            'success'    => false,
                            'message'    => 'Invalid batch size. Must be between 1 and 5000',
                            'collection' => $name,
                        ],
                        statusCode: 400
                        );
            }

            if ($maxObjects < 0) {
                return new JSONResponse(
                        data: [
                            'success'    => false,
                            'message'    => 'Invalid maxObjects. Must be 0 (all) or positive number',
                            'collection' => $name,
                        ],
                        statusCode: 400
                        );
            }

            // Reindex the specified collection.
            $result = $guzzleSolrService->reindexAll(maxObjects: $maxObjects, batchSize: $batchSize, collectionName: $name);

            if ($result['success'] === true) {
                return new JSONResponse(
                        data: [
                            'success'    => true,
                            'message'    => 'Reindex completed successfully',
                            'stats'      => $result['stats'] ?? [],
                            'collection' => $name,
                        ],
                        statusCode: 200
                        );
            } else {
                return new JSONResponse(
                        data: [
                            'success'    => false,
                            'message'    => $result['message'] ?? 'Failed to reindex collection',
                            'collection' => $name,
                        ],
                        statusCode: 422
                        );
            }
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success'    => false,
                        'message'    => 'Reindex failed: '.$e->getMessage(),
                        'collection' => $name,
                    ],
                    statusCode: 422
                );
        }//end try

    }//end reindexSpecificCollection()


    /**
     * Test SOLR connection with provided settings (basic connectivity and authentication only)
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse The test results
     */
    public function testSolrConnection(): JSONResponse
    {
        try {
            // Test only basic SOLR connectivity and authentication.
            // Does NOT test collections, queries, or Zookeeper.
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            $result            = $guzzleSolrService->testConnectivityOnly();

            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
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
     * Get SOLR field configuration and schema information
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse SOLR field configuration data
     */
    public function getSolrFields(): JSONResponse
    {
        try {
            // Use SolrSchemaService to get field status for both collections.
            $solrSchemaService = $this->container->get(\OCA\OpenRegister\Service\SolrSchemaService::class);
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);

            // Check if SOLR is available first.
            if (!$guzzleSolrService->isAvailable()) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'SOLR is not available or not configured',
                            'details' => ['error' => 'SOLR service is not enabled or connection failed'],
                        ],
                        statusCode: 422
                        );
            }

            // Get field status for both collections.
            $objectFieldStatus = $solrSchemaService->getObjectCollectionFieldStatus();
            $fileFieldStatus   = $solrSchemaService->getFileCollectionFieldStatus();

            // Combine missing fields from both collections with collection identifier.
            $missingFields = [];

            foreach ($objectFieldStatus['missing'] as $fieldName => $fieldInfo) {
                $missingFields[] = [
                    'name'            => $fieldName,
                    'type'            => $fieldInfo['type'],
                    'config'          => $fieldInfo,
                    'collection'      => 'objects',
                    'collectionLabel' => 'Object Collection',
                ];
            }

            foreach ($fileFieldStatus['missing'] as $fieldName => $fieldInfo) {
                $missingFields[] = [
                    'name'            => $fieldName,
                    'type'            => $fieldInfo['type'],
                    'config'          => $fieldInfo,
                    'collection'      => 'files',
                    'collectionLabel' => 'File Collection',
                ];
            }

            // Combine extra fields from both collections.
            $extraFields = [];

            foreach ($objectFieldStatus['extra'] as $fieldName) {
                $extraFields[] = [
                    'name'            => $fieldName,
                    'collection'      => 'objects',
                    'collectionLabel' => 'Object Collection',
                ];
            }

            foreach ($fileFieldStatus['extra'] as $fieldName) {
                $extraFields[] = [
                    'name'            => $fieldName,
                    'collection'      => 'files',
                    'collectionLabel' => 'File Collection',
                ];
            }

            // Build comparison result.
            $comparison = [
                'total_differences' => count($missingFields) + count($extraFields),
                'missing_count'     => count($missingFields),
                'extra_count'       => count($extraFields),
                'missing'           => $missingFields,
                'extra'             => $extraFields,
                'object_collection' => [
                    'missing' => count($objectFieldStatus['missing']),
                    'extra'   => count($objectFieldStatus['extra']),
                ],
                'file_collection'   => [
                    'missing' => count($fileFieldStatus['missing']),
                    'extra'   => count($fileFieldStatus['extra']),
                ],
            ];

            return new JSONResponse(
                    data: [
                        'success'                  => true,
                        'comparison'               => $comparison,
                        'object_collection_status' => $objectFieldStatus,
                        'file_collection_status'   => $fileFieldStatus,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Failed to retrieve SOLR field configuration: '.$e->getMessage(),
                        'details' => ['error' => $e->getMessage()],
                    ],
                    statusCode: 422
                );
        }//end try

    }//end getSolrFields()


    /**
     * Create missing SOLR fields based on schema analysis
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse The field creation results
     */
    public function createMissingSolrFields(): JSONResponse
    {
        try {
            // Get services.
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            $solrSchemaService = $this->container->get(SolrSchemaService::class);

            // Check if SOLR is available first.
            if (!$guzzleSolrService->isAvailable()) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'SOLR is not available or not configured',
                            'details' => ['error' => 'SOLR service is not enabled or connection failed'],
                        ],
                        statusCode: 422
                        );
            }

            // Get dry run parameter.
            $dryRun = $this->request->getParam('dry_run', false);
            $dryRun = filter_var($dryRun, FILTER_VALIDATE_BOOLEAN);

            $startTime    = microtime(true);
            $totalCreated = 0;
            $totalErrors  = 0;
            $results      = [
                'objects' => null,
                'files'   => null,
            ];

            // Create missing fields for OBJECT collection.
            try {
                $objectStatus = $solrSchemaService->getObjectCollectionFieldStatus();
                if (!empty($objectStatus['missing'])) {
                    $objectResult       = $solrSchemaService->createMissingFields(
                        collectionType: 'objects',
                        missingFields: $objectStatus['missing'],
                        dryRun: $dryRun
                    );
                    $results['objects'] = $objectResult;
                    if (($objectResult['created_count'] ?? null) !== null) {
                        $totalCreated += $objectResult['created_count'];
                    }

                    if (($objectResult['error_count'] ?? null) !== null) {
                        $totalErrors += $objectResult['error_count'];
                    }
                }
            } catch (\Exception $e) {
                $results['objects'] = [
                    'success' => false,
                    'message' => 'Failed to create object fields: '.$e->getMessage(),
                ];
                $totalErrors++;
            }//end try

            // Create missing fields for FILE collection.
            try {
                $fileStatus = $solrSchemaService->getFileCollectionFieldStatus();
                if (!empty($fileStatus['missing'])) {
                    $fileResult       = $solrSchemaService->createMissingFields(
                        collectionType: 'files',
                        missingFields: $fileStatus['missing'],
                        dryRun: $dryRun
                    );
                    $results['files'] = $fileResult;
                    if (($fileResult['created_count'] ?? null) !== null) {
                        $totalCreated += $fileResult['created_count'];
                    }

                    if (($fileResult['error_count'] ?? null) !== null) {
                        $totalErrors += $fileResult['error_count'];
                    }
                }
            } catch (\Exception $e) {
                $results['files'] = [
                    'success' => false,
                    'message' => 'Failed to create file fields: '.$e->getMessage(),
                ];
                $totalErrors++;
            }//end try

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            return new JSONResponse(
                    data: [
                        'success'           => $totalErrors === 0,
                        'message'           => sprintf(
                    'Field creation completed: %d total fields created across both collections',
                                    $totalCreated
                                    ),
                        'total_created'     => $totalCreated,
                        'total_errors'      => $totalErrors,
                        'results'           => $results,
                        'execution_time_ms' => $executionTime,
                        'dry_run'           => $dryRun,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Failed to create missing SOLR fields: '.$e->getMessage(),
                        'details' => ['error' => $e->getMessage()],
                    ],
                    statusCode: 422
                );
        }//end try

    }//end createMissingSolrFields()


    /**
     * Get object collection field status
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Field status for object collection
     */
    public function getObjectCollectionFields(): JSONResponse
    {
        try {
            $solrSchemaService = $this->container->get(SolrSchemaService::class);
            $status            = $solrSchemaService->getObjectCollectionFieldStatus();

            return new JSONResponse(
                    data: [
                        'success'    => true,
                        'collection' => 'objects',
                        'status'     => $status,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Failed to get object collection field status: '.$e->getMessage(),
                    ],
                    statusCode: 500
                );
        }

    }//end getObjectCollectionFields()


    /**
     * Get file collection field status
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Field status for file collection
     */
    public function getFileCollectionFields(): JSONResponse
    {
        try {
            $solrSchemaService = $this->container->get(SolrSchemaService::class);
            $status            = $solrSchemaService->getFileCollectionFieldStatus();

            return new JSONResponse(
                    data: [
                        'success'    => true,
                        'collection' => 'files',
                        'status'     => $status,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Failed to get file collection field status: '.$e->getMessage(),
                    ],
                    statusCode: 500
                );
        }

    }//end getFileCollectionFields()


    /**
     * Create missing fields in object collection
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Creation results
     */
    public function createMissingObjectFields(): JSONResponse
    {
        try {
            $solrSchemaService = $this->container->get(SolrSchemaService::class);

            // Switch to object collection.
            $objectCollection = $this->settingsService->getSolrSettingsOnly()['objectCollection'] ?? null;
            if ($objectCollection === null || $objectCollection === '') {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'Object collection not configured',
                        ],
                        statusCode: 400
                    );
            }

            // Create missing fields.
            $result = $solrSchemaService->mirrorSchemas(force: true);

            return new JSONResponse(
                    data: [
                        'success'    => true,
                        'collection' => 'objects',
                        'message'    => 'Missing object fields created successfully',
                        'result'     => $result,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Failed to create missing object fields: '.$e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end createMissingObjectFields()


    /**
     * Create missing fields in file collection
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Creation results
     */
    public function createMissingFileFields(): JSONResponse
    {
        try {
            $solrSchemaService = $this->container->get(SolrSchemaService::class);
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);

            // Switch to file collection.
            $fileCollection = $this->settingsService->getSolrSettingsOnly()['fileCollection'] ?? null;
            if ($fileCollection === null || $fileCollection === '') {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'File collection not configured',
                        ],
                        statusCode: 400
                    );
            }

            // Set active collection to file collection temporarily.
            $originalCollection = $guzzleSolrService->getActiveCollectionName();
            $guzzleSolrService->setActiveCollection($fileCollection);

            // Create missing file metadata fields using reflection to call private method.
            $reflection = new \ReflectionClass($solrSchemaService);
            $method     = $reflection->getMethod('ensureFileMetadataFields');
            $result     = $method->invoke($solrSchemaService, true);

            // Restore original collection.
            $guzzleSolrService->setActiveCollection($originalCollection);

            return new JSONResponse(
                    data: [
                        'success'    => $result,
                        'collection' => 'files',
                        'message'    => $result === true ? 'File metadata fields ensured successfully' : 'Failed to ensure file metadata fields',
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Failed to create missing file fields: '.$e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end createMissingFileFields()


    /**
     * Fix mismatched SOLR field configurations
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse The field fix results
     */
    public function fixMismatchedSolrFields(): JSONResponse
    {
        try {
            // Get GuzzleSolrService for field operations.
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);

            // Check if SOLR is available first.
            if (!$guzzleSolrService->isAvailable()) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'SOLR is not available or not configured',
                            'details' => ['error' => 'SOLR service is not enabled or connection failed'],
                        ],
                        statusCode: 422
                        );
            }

            // Get dry run parameter.
            $dryRun = $this->request->getParam('dry_run', false);
            $dryRun = filter_var($dryRun, FILTER_VALIDATE_BOOLEAN);

            // Get expected fields and current SOLR fields for comparison.
            $expectedFields = $this->getExpectedSchemaFields();
            $fieldsInfo     = $guzzleSolrService->getFieldsConfiguration();

            if (!$fieldsInfo['success']) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'Failed to get SOLR field configuration',
                            'details' => ['error' => $fieldsInfo['message'] ?? 'Unknown error'],
                        ],
                        statusCode: 422
                        );
            }

            // Compare fields to find mismatched ones.
            $comparison = $this->compareFields(
                actualFields: $fieldsInfo['fields'] ?? [],
                expectedFields: $expectedFields
            );

            if (empty($comparison['mismatched']) === true) {
                return new JSONResponse(
                        data: [
                            'success' => true,
                            'message' => 'No mismatched fields found - SOLR schema is properly configured',
                            'fixed'   => [],
                            'errors'  => [],
                        ]
                        );
            }

            // Prepare fields to fix from mismatched fields.
            $fieldsToFix = [];
            foreach ($comparison['mismatched'] as $mismatch) {
                $fieldsToFix[$mismatch['field']] = $mismatch['expected_config'];
            }

            // Debug: Log field count for troubleshooting.
            // Fix the mismatched fields using the dedicated method.
            $result = $guzzleSolrService->fixMismatchedFields($fieldsToFix, $dryRun);

            // The fixMismatchedFields method already returns the correct format.
            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Failed to fix mismatched SOLR fields: '.$e->getMessage(),
                        'details' => ['error' => $e->getMessage()],
                    ],
                    statusCode: 422
                );
        }//end try

    }//end fixMismatchedSolrFields()


    /**
     * Get expected schema fields based on OpenRegister schemas
     *
     * @return array Expected field configuration
     */
    private function getExpectedSchemaFields(): array
    {
        try {
            // Start with the core ObjectEntity metadata fields from SolrSetup.
            $expectedFields = \OCA\OpenRegister\Setup\SolrSetup::getObjectEntityFieldDefinitions();

            // Get SolrSchemaService to analyze user-defined schemas.
            $solrSchemaService = $this->container->get(\OCA\OpenRegister\Service\SolrSchemaService::class);
            $schemaMapper      = $this->container->get(\OCA\OpenRegister\Db\SchemaMapper::class);

            // Get all schemas.
            $schemas = $schemaMapper->findAll(config: []);

            // Use the existing analyzeAndResolveFieldConflicts method via reflection.
            $reflection = new \ReflectionClass($solrSchemaService);
            $method     = $reflection->getMethod('analyzeAndResolveFieldConflicts');

            $result = $method->invoke($solrSchemaService, $schemas);

            // Merge user-defined schema fields with core metadata fields.
            $userSchemaFields = $result['fields'] ?? [];
            $expectedFields   = array_merge($expectedFields, $userSchemaFields);

            return $expectedFields;
        } catch (\Exception $e) {
            $this->logger->warning(
                    'Failed to get expected schema fields',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );
            // Return at least the core metadata fields even if schema analysis fails.
            return \OCA\OpenRegister\Setup\SolrSetup::getObjectEntityFieldDefinitions();
        }//end try

    }//end getExpectedSchemaFields()


    /**
     * Compare actual SOLR fields with expected schema fields
     *
     * @param  array $actualFields   Current SOLR fields
     * @param  array $expectedFields Expected fields from schemas
     * @return array Comparison results
     */
    private function compareFields(array $actualFields, array $expectedFields): array
    {
        $missing    = [];
        $extra      = [];
        $mismatched = [];

        // Find missing fields (expected but not in SOLR).
        foreach ($expectedFields as $fieldName => $expectedConfig) {
            if (!isset($actualFields[$fieldName])) {
                $missing[] = [
                    'field'           => $fieldName,
                    'expected_type'   => $expectedConfig['type'] ?? 'unknown',
                    'expected_config' => $expectedConfig,
                ];
            }
        }

        // Find extra fields (in SOLR but not expected) and mismatched configurations.
        foreach ($actualFields as $fieldName => $actualField) {
            // Skip only system fields (but allow self_* metadata fields to be checked).
            if (str_starts_with($fieldName, '_') === true) {
                continue;
            }

            if (!isset($expectedFields[$fieldName])) {
                $extra[] = [
                    'field'         => $fieldName,
                    'actual_type'   => $actualField['type'] ?? 'unknown',
                    'actual_config' => $actualField,
                ];
            } else {
                // Check for configuration mismatches (type, multiValued, docValues).
                $expectedConfig      = $expectedFields[$fieldName];
                $expectedType        = $expectedConfig['type'] ?? '';
                $actualType          = $actualField['type'] ?? '';
                $expectedMultiValued = $expectedConfig['multiValued'] ?? false;
                $actualMultiValued   = $actualField['multiValued'] ?? false;
                $expectedDocValues   = $expectedConfig['docValues'] ?? false;
                $actualDocValues     = $actualField['docValues'] ?? false;

                // Check if any configuration differs.
                if ($expectedType !== $actualType
                    || $expectedMultiValued !== $actualMultiValued
                    || $expectedDocValues !== $actualDocValues
                ) {
                    $differences = [];
                    if ($expectedType !== $actualType) {
                        $differences[] = 'type';
                    }

                    if ($expectedMultiValued !== $actualMultiValued) {
                        $differences[] = 'multiValued';
                    }

                    if ($expectedDocValues !== $actualDocValues) {
                        $differences[] = 'docValues';
                    }

                    $mismatched[] = [
                        'field'                => $fieldName,
                        'expected_type'        => $expectedType,
                        'actual_type'          => $actualType,
                        'expected_multiValued' => $expectedMultiValued,
                        'actual_multiValued'   => $actualMultiValued,
                        'expected_docValues'   => $expectedDocValues,
                        'actual_docValues'     => $actualDocValues,
                        'differences'          => $differences,
                        'expected_config'      => $expectedConfig,
                        'actual_config'        => $actualField,
                    ];
                }//end if
            }//end if
        }//end foreach

        return [
            'missing'    => $missing,
            'extra'      => $extra,
            'mismatched' => $mismatched,
            'summary'    => [
                'missing_count'     => count($missing),
                'extra_count'       => count($extra),
                'mismatched_count'  => count($mismatched),
                'total_differences' => count($missing) + count($extra) + count($mismatched),
            ],
        ];

    }//end compareFields()


    /**
     * Get SOLR settings only
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse SOLR configuration
     */
    public function getSolrSettings(): JSONResponse
    {
        try {
            $data = $this->settingsService->getSolrSettingsOnly();
            return new JSONResponse(data: $data);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end getSolrSettings()


    /**
     * Update SOLR settings only
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Updated SOLR configuration
     */
    public function updateSolrSettings(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $result = $this->settingsService->updateSolrSettingsOnly($data);
            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end updateSolrSettings()


    /**
     * Get SOLR facet configuration
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse SOLR facet configuration
     */
    public function getSolrFacetConfiguration(): JSONResponse
    {
        try {
            $data = $this->settingsService->getSolrFacetConfiguration();
            return new JSONResponse(data: $data);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end getSolrFacetConfiguration()


    /**
     * Update SOLR facet configuration
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Updated SOLR facet configuration
     */
    public function updateSolrFacetConfiguration(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $result = $this->settingsService->updateSolrFacetConfiguration($data);
            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end updateSolrFacetConfiguration()


    /**
     * Discover available SOLR facets
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Available SOLR facets
     */
    public function discoverSolrFacets(): JSONResponse
    {
        try {
            // Get GuzzleSolrService from container.
            $guzzleSolrService = $this->container->get(\OCA\OpenRegister\Service\GuzzleSolrService::class);

            // Check if SOLR is available.
            if (!$guzzleSolrService->isAvailable()) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'SOLR is not available or not configured',
                            'facets'  => [],
                        ],
                        statusCode: 422
                        );
            }

            // Get raw SOLR field information for facet configuration.
            $facetableFields = $guzzleSolrService->getRawSolrFieldsForFacetConfiguration();

            return new JSONResponse(
                    data: [
                        'success' => true,
                        'message' => 'Facets discovered successfully',
                        'facets'  => $facetableFields,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Failed to discover facets: '.$e->getMessage(),
                        'facets'  => [],
                    ],
                    statusCode: 422
                );
        }//end try

    }//end discoverSolrFacets()


    /**
     * Get SOLR facet configuration with discovery
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Discovered facets merged with current configuration
     */
    public function getSolrFacetConfigWithDiscovery(): JSONResponse
    {
        try {
            // Get GuzzleSolrService from container.
            $guzzleSolrService = $this->container->get(\OCA\OpenRegister\Service\GuzzleSolrService::class);

            // Check if SOLR is available.
            if (!$guzzleSolrService->isAvailable()) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'SOLR is not available or not configured',
                            'facets'  => [],
                        ],
                        statusCode: 422
                        );
            }

            // Get discovered facets.
            $discoveredFacets = $guzzleSolrService->getRawSolrFieldsForFacetConfiguration();

            // Get existing configuration.
            $existingConfig = $this->settingsService->getSolrFacetConfiguration();
            $existingFacets = $existingConfig['facets'] ?? [];

            // Merge discovered facets with existing configuration.
            $mergedFacets = [
                '@self'         => [],
                'object_fields' => [],
            ];

            // Process metadata facets.
            if (($discoveredFacets['@self'] ?? null) !== null) {
                $index = 0;
                foreach ($discoveredFacets['@self'] as $key => $facetInfo) {
                    $fieldName           = "self_{$key}";
                    $existingFacetConfig = $existingFacets[$fieldName] ?? [];

                    $mergedFacets['@self'][$key] = array_merge(
                            $facetInfo,
                            [
                                'config' => [
                                    'enabled'     => $existingFacetConfig['enabled'] ?? true,
                                    'title'       => $existingFacetConfig['title'] ?? $facetInfo['displayName'] ?? $key,
                                    'description' => $existingFacetConfig['description'] ?? ($facetInfo['category'] ?? 'metadata')." field: ".($facetInfo['displayName'] ?? $key),
                                    'order'       => $existingFacetConfig['order'] ?? $index,
                                    'maxItems'    => $existingFacetConfig['max_items'] ?? $existingFacetConfig['maxItems'] ?? 10,
                                    'facetType'   => $existingFacetConfig['facet_type'] ?? $existingFacetConfig['facetType'] ?? $facetInfo['suggestedFacetType'] ?? 'terms',
                                    'displayType' => $existingFacetConfig['display_type'] ?? $existingFacetConfig['displayType'] ?? ($facetInfo['suggestedDisplayTypes'][0] ?? 'select'),
                                    'showCount'   => $existingFacetConfig['show_count'] ?? $existingFacetConfig['showCount'] ?? true,
                                ],
                            ]
                            );
                    $index++;
                }//end foreach
            }//end if

            // Process object field facets.
            if (($discoveredFacets['object_fields'] ?? null) !== null) {
                $index = 0;
                foreach ($discoveredFacets['object_fields'] as $key => $facetInfo) {
                    $fieldName           = $key;
                    $existingFacetConfig = $existingFacets[$fieldName] ?? [];

                    $mergedFacets['object_fields'][$key] = array_merge(
                            $facetInfo,
                            [
                                'config' => [
                                    'enabled'     => $existingFacetConfig['enabled'] ?? false,
                                    'title'       => $existingFacetConfig['title'] ?? $facetInfo['displayName'] ?? $key,
                                    'description' => $existingFacetConfig['description'] ?? ($facetInfo['category'] ?? 'object')." field: ".($facetInfo['displayName'] ?? $key),
                                    'order'       => $existingFacetConfig['order'] ?? (100 + $index),
                                    'maxItems'    => $existingFacetConfig['max_items'] ?? $existingFacetConfig['maxItems'] ?? 10,
                                    'facetType'   => $existingFacetConfig['facet_type'] ?? $existingFacetConfig['facetType'] ?? $facetInfo['suggestedFacetType'] ?? 'terms',
                                    'displayType' => $existingFacetConfig['display_type'] ?? $existingFacetConfig['displayType'] ?? ($facetInfo['suggestedDisplayTypes'][0] ?? 'select'),
                                    'showCount'   => $existingFacetConfig['show_count'] ?? $existingFacetConfig['showCount'] ?? true,
                                ],
                            ]
                            );
                    $index++;
                }//end foreach
            }//end if

            return new JSONResponse(
                    data: [
                        'success'         => true,
                        'message'         => 'Facets discovered and configured successfully',
                        'facets'          => $mergedFacets,
                        'global_settings' => $existingConfig['default_settings'] ?? [
                            'show_count' => true,
                            'show_empty' => false,
                            'max_items'  => 10,
                        ],
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Failed to get facet configuration: '.$e->getMessage(),
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end getSolrFacetConfigWithDiscovery()


    /**
     * Update SOLR facet configuration with discovery
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Updated facet configuration
     */
    public function updateSolrFacetConfigWithDiscovery(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $result = $this->settingsService->updateSolrFacetConfiguration($data);

            return new JSONResponse(
                    data: [
                        'success' => true,
                        'message' => 'Facet configuration updated successfully',
                        'config'  => $result,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Failed to update facet configuration: '.$e->getMessage(),
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end updateSolrFacetConfigWithDiscovery()


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
            if (!in_array($mode, ['serial', 'parallel', 'hyper'])) {
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

            // Phase 1: Use GuzzleSolrService directly for SOLR operations.
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            $result            = $guzzleSolrService->warmupIndex([], $maxObjects, $mode, $collectErrors, $batchSize, $schemaIds);
            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
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
     * Get comprehensive SOLR dashboard statistics
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse SOLR dashboard metrics and statistics
     */
    public function getSolrDashboardStats(): JSONResponse
    {
        try {
            // Phase 1: Use GuzzleSolrService directly for SOLR operations.
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            $stats = $guzzleSolrService->getDashboardStats();
            return new JSONResponse(data: $stats);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end getSolrDashboardStats()


    /**
     * Perform SOLR management operations
     *
     * @param string $operation Operation to perform (commit, optimize, clear)
     *
     * @return JSONResponse Operation results
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function manageSolr(string $operation): JSONResponse
    {
        try {
            // Phase 1: Use GuzzleSolrService directly for SOLR operations.
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);

            switch ($operation) {
                case 'commit':
                    $success = $guzzleSolrService->commit();
                    return new JSONResponse(
                            data: [
                                'success'   => $success,
                                'operation' => 'commit',
                                // Get commit message based on success.
                                'message'   => $success === true ? 'Index committed successfully' : 'Failed to commit index',
                                'timestamp' => date('c'),
                            ]
                            );

                case 'optimize':
                    $success = $guzzleSolrService->optimize();
                    return new JSONResponse(
                            data: [
                                'success'   => $success,
                                'operation' => 'optimize',
                                'message'   => $success === true ? 'Index optimized successfully' : 'Failed to optimize index',
                                'timestamp' => date('c'),
                            ]
                            );

                case 'clear':
                    $result = $guzzleSolrService->clearIndex();
                    return new JSONResponse(
                            data: [
                                'success'       => $result['success'],
                                'operation'     => 'clear',
                                'error'         => $result['error'] ?? null,
                                'error_details' => $result['error_details'] ?? null,
                                'message'       => $result['success'] === true ? 'Index cleared successfully' : 'Failed to clear index: '.($result['error'] ?? 'Unknown error'),
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
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }//end try

    }//end manageSolr()


    /**
     * Get RBAC settings only
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse RBAC configuration
     */
    public function getRbacSettings(): JSONResponse
    {
        try {
            $data = $this->settingsService->getRbacSettingsOnly();
            return new JSONResponse(data: $data);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end getRbacSettings()


    /**
     * Update RBAC settings only
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Updated RBAC configuration
     */
    public function updateRbacSettings(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $result = $this->settingsService->updateRbacSettingsOnly($data);
            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end updateRbacSettings()


    /**
     * Get Organisation settings only
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Organisation configuration
     */
    public function getOrganisationSettings(): JSONResponse
    {
        try {
            $data = $this->settingsService->getOrganisationSettingsOnly();
            return new JSONResponse(data: $data);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end getOrganisationSettings()


    /**
     * Update Organisation settings only
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Updated Organisation configuration
     */
    public function updateOrganisationSettings(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $result = $this->settingsService->updateOrganisationSettingsOnly($data);
            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end updateOrganisationSettings()


    /**
     * Get Multitenancy settings only
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Multitenancy configuration
     */
    public function getMultitenancySettings(): JSONResponse
    {
        try {
            $data = $this->settingsService->getMultitenancySettingsOnly();
            return new JSONResponse(data: $data);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end getMultitenancySettings()


    /**
     * Update Multitenancy settings only
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Updated Multitenancy configuration
     */
    public function updateMultitenancySettings(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $result = $this->settingsService->updateMultitenancySettingsOnly($data);
            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end updateMultitenancySettings()


    /**
     * Get LLM (Large Language Model) settings
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse LLM settings
     */
    public function getLLMSettings(): JSONResponse
    {
        try {
            $data = $this->settingsService->getLLMSettingsOnly();
            return new JSONResponse(data: $data);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end getLLMSettings()


    /**
     * Get Solr information and vector search capabilities
     *
     * Returns information about Solr availability, version, and vector search support.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Solr information
     */
    public function getSolrInfo(): JSONResponse
    {
        try {
            $solrAvailable = false;
            $solrVersion   = 'Unknown';
            $vectorSupport = false;
            $collections   = [];
            $errorMessage  = null;

            // Check if Solr service is available.
            try {
                // Get GuzzleSolrService from container.
                $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
                $solrAvailable     = $guzzleSolrService->isAvailable();

                if ($solrAvailable === true) {
                    // Try to detect version from Solr admin API.
                    // Note: Dashboard stats not currently used but available via $guzzleSolrService->getDashboardStats()
                    // For now, assume if it's available, it could support vectors.
                    // TODO: Add actual version detection from Solr admin API.
                    $solrVersion   = '9.x (detection pending)';
                    $vectorSupport = false;
                    // Set to false until we implement it.
                    // Get list of collections from Solr.
                    try {
                        $collectionsList = $guzzleSolrService->listCollections();
                        // Transform to format expected by frontend (array of objects with 'name' and 'id').
                        $collections = array_map(
                                function ($collection) {
                                    return [
                                        'id'            => $collection['name'],
                                        'name'          => $collection['name'],
                                        'documentCount' => $collection['documentCount'] ?? 0,
                                        'shards'        => $collection['shards'] ?? 0,
                                        'health'        => $collection['health'] ?? 'unknown',
                                    ];
                                },
                                $collectionsList
                                );
                    } catch (\Exception $e) {
                        $this->logger->warning(
                                '[SettingsController] Failed to list Solr collections',
                                [
                                    'error' => $e->getMessage(),
                                ]
                                );
                        $collections = [];
                    }//end try
                }//end if
            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
            }//end try

            return new JSONResponse(
                    data: [
                        'success' => true,
                        'solr'    => [
                            'available'     => $solrAvailable,
                            'version'       => $solrVersion,
                            'vectorSupport' => $vectorSupport,
                            'collections'   => $collections,
                            'error'         => $errorMessage,
                        ],
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    '[SettingsController] Failed to get Solr info',
                    [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => 'Failed to get Solr information: '.$e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end getSolrInfo()


    /**
     * Get database information and vector search capabilities
     *
     * Returns information about the current database system and whether it
     * supports native vector operations for optimal semantic search performance.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Database information
     */
    public function getDatabaseInfo(): JSONResponse
    {
        try {
            // Get database platform information.
            /*
             * @var AbstractPlatform $platform
             */
            $platform = $this->db->getDatabasePlatform();
            /*
             * @var string $platformName
             */
            $platformName = $platform->getName();

            // Determine database type and version.
            $dbType            = 'Unknown';
            $dbVersion         = 'Unknown';
            $vectorSupport     = false;
            $recommendedPlugin = null;
            $performanceNote   = null;

            if (strpos($platformName, 'mysql') !== false || strpos($platformName, 'mariadb') !== false) {
                // Check if it's MariaDB or MySQL.
                try {
                    $stmt    = $this->db->prepare('SELECT VERSION()');
                    $result  = $stmt->execute();
                    $version = $result->fetchOne();

                    if (stripos($version, 'MariaDB') !== false) {
                        $dbType = 'MariaDB';
                        preg_match('/\d+\.\d+\.\d+/', $version, $matches);
                        $dbVersion = $matches[0] ?? $version;
                    } else {
                        $dbType = 'MySQL';
                        preg_match('/\d+\.\d+\.\d+/', $version, $matches);
                        $dbVersion = $matches[0] ?? $version;
                    }
                } catch (\Exception $e) {
                    $dbType    = 'MySQL/MariaDB';
                    $dbVersion = 'Unknown';
                }

                // MariaDB/MySQL do not support native vector operations.
                $vectorSupport     = false;
                $recommendedPlugin = 'pgvector for PostgreSQL';
                $performanceNote   = 'Current: Similarity calculated in PHP (slow). Recommended: Migrate to PostgreSQL + pgvector for 10-100x speedup.';
            } else if (strpos($platformName, 'postgres') !== false) {
                $dbType = 'PostgreSQL';

                try {
                    $stmt    = $this->db->prepare('SELECT VERSION()');
                    $result  = $stmt->execute();
                    $version = $result->fetchOne();
                    preg_match('/PostgreSQL (\d+\.\d+)/', $version, $matches);
                    $dbVersion = $matches[1] ?? 'Unknown';
                } catch (\Exception $e) {
                    $dbVersion = 'Unknown';
                }

                // Check if pgvector extension is installed.
                try {
                    $stmt      = $this->db->prepare("SELECT COUNT(*) FROM pg_extension WHERE extname = 'vector'");
                    $result    = $stmt->execute();
                    $hasVector = $result->fetchOne() > 0;

                    if ($hasVector === true) {
                        $vectorSupport     = true;
                        $recommendedPlugin = 'pgvector (installed ✓)';
                        $performanceNote   = 'Optimal: Using database-level vector operations for fast semantic search.';
                    } else {
                        $vectorSupport     = false;
                        $recommendedPlugin = 'pgvector (not installed)';
                        $performanceNote   = 'Install pgvector extension: CREATE EXTENSION vector;';
                    }
                } catch (\Exception $e) {
                    $vectorSupport     = false;
                    $recommendedPlugin = 'pgvector (not found)';
                    $performanceNote   = 'Unable to detect pgvector. Install with: CREATE EXTENSION vector;';
                }
            } else if (strpos($platformName, 'sqlite') !== false) {
                $dbType            = 'SQLite';
                $vectorSupport     = false;
                $recommendedPlugin = 'sqlite-vss or migrate to PostgreSQL';
                $performanceNote   = 'SQLite not recommended for production vector search.';
            }//end if

            return new JSONResponse(
                    data: [
                        'success'  => true,
                        'database' => [
                            'type'              => $dbType,
                            'version'           => $dbVersion,
                            'platform'          => $platformName,
                            'vectorSupport'     => $vectorSupport,
                            'recommendedPlugin' => $recommendedPlugin,
                            'performanceNote'   => $performanceNote,
                        ],
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    '[SettingsController] Failed to get database info',
                    [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => 'Failed to get database information: '.$e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end getDatabaseInfo()


    /**
     * Update LLM (Large Language Model) settings
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Updated LLM settings
     */
    public function updateLLMSettings(): JSONResponse
    {
        try {
            $data = $this->request->getParams();

            // Extract the model IDs from the objects sent by frontend.
            if (($data['fireworksConfig']['embeddingModel'] ?? null) !== null && is_array($data['fireworksConfig']['embeddingModel']) === true) {
                $data['fireworksConfig']['embeddingModel'] = $data['fireworksConfig']['embeddingModel']['id'] ?? null;
            }

            if (($data['fireworksConfig']['chatModel'] ?? null) !== null && is_array($data['fireworksConfig']['chatModel']) === true) {
                $data['fireworksConfig']['chatModel'] = $data['fireworksConfig']['chatModel']['id'] ?? null;
            }

            if (($data['openaiConfig']['model'] ?? null) !== null && is_array($data['openaiConfig']['model']) === true) {
                $data['openaiConfig']['model'] = $data['openaiConfig']['model']['id'] ?? null;
            }

            if (($data['openaiConfig']['chatModel'] ?? null) !== null && is_array($data['openaiConfig']['chatModel']) === true) {
                $data['openaiConfig']['chatModel'] = $data['openaiConfig']['chatModel']['id'] ?? null;
            }

            if (($data['ollamaConfig']['model'] ?? null) !== null && is_array($data['ollamaConfig']['model']) === true) {
                $data['ollamaConfig']['model'] = $data['ollamaConfig']['model']['id'] ?? null;
            }

            if (($data['ollamaConfig']['chatModel'] ?? null) !== null && is_array($data['ollamaConfig']['chatModel']) === true) {
                $data['ollamaConfig']['chatModel'] = $data['ollamaConfig']['chatModel']['id'] ?? null;
            }

            $result = $this->settingsService->updateLLMSettingsOnly($data);
            return new JSONResponse(
                    data: [
                        'success' => true,
                        'message' => 'LLM settings updated successfully',
                        'data'    => $result,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end updateLLMSettings()


    /**
     * Patch LLM settings (partial update)
     *
     * This is an alias for updateLLMSettings but specifically for PATCH requests.
     * It provides the same functionality but is registered under a different route name
     * to ensure PATCH verb is properly registered in Nextcloud routing.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Updated LLM settings
     */
    public function patchLLMSettings(): JSONResponse
    {
        return $this->updateLLMSettings();

    }//end patchLLMSettings()


    /**
     * Test LLM embedding functionality
     *
     * Tests if the configured embedding provider works correctly
     * by generating a test embedding vector.
     * Accepts provider and config from the request to allow testing
     * before saving the configuration.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Test result with embedding info
     */
    public function testEmbedding(): JSONResponse
    {
        try {
            // Get parameters from request.
            $provider = (string) $this->request->getParam('provider');
            $config   = $this->request->getParam('config', []);
            $testText = (string) $this->request->getParam('testText', 'This is a test embedding to verify the LLM configuration.');

            // Validate input.
            if (empty($provider) === true) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Missing provider',
                            'message' => 'Provider is required for testing',
                        ],
                        statusCode: 400
                    );
            }

            if (empty($config) === true || is_array($config) === false) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Invalid config',
                            'message' => 'Config must be provided as an object',
                        ],
                        statusCode: 400
                    );
            }

            // Delegate to VectorEmbeddingService for testing.
            $vectorService = $this->container->get('OCA\OpenRegister\Service\VectorEmbeddingService');
            $result        = $vectorService->testEmbedding($provider, $config, $testText);

            // Return appropriate status code.
            $statusCode = 400;
            if ($result['success'] === true) {
                $statusCode = 200;
            }

            return new JSONResponse(data: $result, statusCode: $statusCode);
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                        'message' => 'Failed to generate embedding: '.$e->getMessage(),
                    ],
                    statusCode: 400
                );
        }//end try

    }//end testEmbedding()


    /**
     * Test LLM chat functionality
     *
     * Tests if the configured chat provider works correctly
     * by sending a simple test message and receiving a response.
     * Accepts provider and config from the request to allow testing
     * before saving the configuration.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Test result with chat response
     */
    public function testChat(): JSONResponse
    {
        try {
            // Get parameters from request.
            $provider    = (string) $this->request->getParam('provider');
            $config      = $this->request->getParam('config', []);
            $testMessage = (string) $this->request->getParam('testMessage', 'Hello! Please respond with a brief greeting.');

            // Validate input.
            if (empty($provider) === true) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Missing provider',
                            'message' => 'Provider is required for testing',
                        ],
                        statusCode: 400
                    );
            }

            if (empty($config) === true || is_array($config) === false) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Invalid config',
                            'message' => 'Config must be provided as an object',
                        ],
                        statusCode: 400
                    );
            }

            // Delegate to ChatService for testing.
            $chatService = $this->container->get('OCA\OpenRegister\Service\ChatService');
            $result      = $chatService->testChat($provider, $config, $testMessage);

            // Return appropriate status code.
            $statusCode = 400;
            if ($result['success'] === true) {
                $statusCode = 200;
            }

            return new JSONResponse(data: $result, statusCode: $statusCode);
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                        'message' => 'Failed to test chat: '.$e->getMessage(),
                    ],
                    statusCode: 400
                );
        }//end try

    }//end testChat()


    /**
     * Get File Management settings
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse File settings
     */
    public function getFileSettings(): JSONResponse
    {
        try {
            $data = $this->settingsService->getFileSettingsOnly();
            return new JSONResponse(data: $data);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end getFileSettings()


    /**
     * Test Dolphin API connection
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param  string $apiEndpoint Dolphin API endpoint URL
     * @param  string $apiKey      Dolphin API key
     * @return JSONResponse
     */
    public function testDolphinConnection(string $apiEndpoint, string $apiKey): JSONResponse
    {
        try {
            // Validate inputs.
            if (empty($apiEndpoint) === true || empty($apiKey) === true) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'API endpoint and API key are required',
                        ],
                        statusCode: 400
                    );
            }

            // Test the connection by making a simple request.
            $ch = curl_init($apiEndpoint.'/health');
            curl_setopt_array(
                    $ch,
                    [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTPHEADER     => [
                            'Authorization: Bearer '.$apiKey,
                            'Content-Type: application/json',
                        ],
                        CURLOPT_TIMEOUT        => 10,
                        CURLOPT_SSL_VERIFYPEER => true,
                    ]
                    );

            curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError !== '') {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Connection failed: '.$curlError,
                        ]
                        );
            }

            if ($httpCode === 200 || $httpCode === 201) {
                return new JSONResponse(
                        data: [
                            'success' => true,
                            'message' => 'Dolphin connection successful',
                        ]
                        );
            } else {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Dolphin API returned HTTP '.$httpCode,
                        ]
                        );
            }
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end testDolphinConnection()


    /**
     * Get available Ollama models from the configured Ollama instance
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse List of available models
     */
    public function getOllamaModels(): JSONResponse
    {
        try {
            // Get Ollama URL from settings.
            $settings  = $this->settingsService->getLLMSettingsOnly();
            $ollamaUrl = $settings['ollamaConfig']['url'] ?? 'http://localhost:11434';

            // Call Ollama API to get available models.
            $apiUrl = rtrim($ollamaUrl, '/').'/api/tags';

            $ch = curl_init($apiUrl);
            curl_setopt_array(
                    $ch,
                    [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 5,
                        CURLOPT_FOLLOWLOCATION => true,
                    ]
                    );

            $response = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError !== '') {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Failed to connect to Ollama: '.$curlError,
                            'models'  => [],
                        ]
                        );
            }

            if ($httpCode !== 200) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => "Ollama API returned HTTP {$httpCode}",
                            'models'  => [],
                        ]
                        );
            }

            $data = json_decode($response, true);
            if (!isset($data['models']) || !is_array($data['models'])) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Unexpected response from Ollama API',
                            'models'  => [],
                        ]
                        );
            }

            // Format models for frontend dropdown.
            $models = array_map(
                    function ($model) {
                        $name = $model['name'] ?? 'unknown';
                        // Format size if available.
                        $size = '';
                        if (($model['size'] ?? null) !== null && is_numeric($model['size']) === true) {
                            $size = $this->formatBytes((int) $model['size']);
                        }

                        $family = $model['details']['family'] ?? '';

                        // Build description.
                        $description = $family;
                        if ($size !== '') {
                            // Add size separator if description exists.
                            if ($description !== null && $description !== '') {
                                $description .= ' • ';
                            }

                            $description .= $size;
                        }

                        return [
                            'id'          => $name,
                            'name'        => $name,
                            'description' => $description,
                            'size'        => $model['size'] ?? 0,
                            'modified'    => $model['modified_at'] ?? null,
                        ];
                    },
                    $data['models']
                    );

            // Sort by name.
            usort(
                    $models,
                    function ($a, $b) {
                        return strcmp($a['name'], $b['name']);
                    }
                    );

            return new JSONResponse(
                    data: [
                        'success' => true,
                        'models'  => $models,
                        'count'   => count($models),
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                        'models'  => [],
                    ],
                    statusCode: 500
                );
        }//end try

    }//end getOllamaModels()


    /**
     * Check if embedding model has changed and vectors need regeneration
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Mismatch status
     */
    public function checkEmbeddingModelMismatch(): JSONResponse
    {
        try {
            $result = $this->vectorEmbeddingService->checkEmbeddingModelMismatch();

            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'has_vectors' => false,
                        'mismatch'    => false,
                        'error'       => $e->getMessage(),
                    ],
                    statusCode: 500
                    );
        }

    }//end checkEmbeddingModelMismatch()


    /**
     * Clear all embeddings from the database
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Result with deleted count
     */
    public function clearAllEmbeddings(): JSONResponse
    {
        try {
            $result = $this->vectorEmbeddingService->clearAllEmbeddings();

            if ($result['success'] === true) {
                return new JSONResponse(data: $result);
            } else {
                return new JSONResponse(data: $result, statusCode: 500);
            }
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                );
        }

    }//end clearAllEmbeddings()


    /**
     * Update File Management settings
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Updated file settings
     */
    public function updateFileSettings(): JSONResponse
    {
        try {
            $data = $this->request->getParams();

            // Extract IDs from objects sent by frontend.
            if (($data['provider'] ?? null) !== null && is_array($data['provider']) === true) {
                $data['provider'] = $data['provider']['id'] ?? null;
            }

            if (($data['chunkingStrategy'] ?? null) !== null && is_array($data['chunkingStrategy']) === true) {
                $data['chunkingStrategy'] = $data['chunkingStrategy']['id'] ?? null;
            }

            $result = $this->settingsService->updateFileSettingsOnly($data);
            return new JSONResponse(
                    data: [
                        'success' => true,
                        'message' => 'File settings updated successfully',
                        'data'    => $result,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end updateFileSettings()


    /**
     * Get Object settings only
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Object configuration
     */
    public function getObjectSettings(): JSONResponse
    {
        try {
            $settings = $this->settingsService->getObjectSettingsOnly();
            return new JSONResponse(
                    data: [
                        'success' => true,
                        'data'    => $settings,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                );
        }

    }//end getObjectSettings()


    /**
     * Update Object Management settings
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Updated object settings
     */
    public function updateObjectSettings(): JSONResponse
    {
        try {
            $data = $this->request->getParams();

            // Extract IDs from objects sent by frontend.
            if (($data['provider'] ?? null) !== null && is_array($data['provider']) === true) {
                $data['provider'] = $data['provider']['id'] ?? null;
            }

            $result = $this->settingsService->updateObjectSettingsOnly($data);
            return new JSONResponse(
                    data: [
                        'success' => true,
                        'message' => 'Object settings updated successfully',
                        'data'    => $result,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end updateObjectSettings()


    /**
     * PATCH Object settings (delegates to updateObjectSettings)
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Updated settings
     */
    public function patchObjectSettings(): JSONResponse
    {
        return $this->updateObjectSettings();

    }//end patchObjectSettings()


    /**
     * Get Retention settings only
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Retention configuration
     */
    public function getRetentionSettings(): JSONResponse
    {
        try {
            $data = $this->settingsService->getRetentionSettingsOnly();
            return new JSONResponse(data: $data);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end getRetentionSettings()


    /**
     * Update Retention settings only
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Updated Retention configuration
     */
    public function updateRetentionSettings(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $result = $this->settingsService->updateRetentionSettingsOnly($data);
            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end updateRetentionSettings()


    /**
     * Get version information only
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Version information
     */
    public function getVersionInfo(): JSONResponse
    {
        try {
            $data = $this->settingsService->getVersionInfoOnly();
            return new JSONResponse(data: $data);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end getVersionInfo()


    /**
     * Test schema-aware SOLR mapping by indexing sample objects
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Test results
     */
    public function testSchemaMapping(): JSONResponse
    {
        try {
            // Get GuzzleSolrService from container.
            $solrService = $this->container->get(\OCA\OpenRegister\Service\GuzzleSolrService::class);

            // Get required dependencies from container.
            $objectMapper = $this->container->get(\OCA\OpenRegister\Db\ObjectEntityMapper::class);
            $schemaMapper = $this->container->get(\OCA\OpenRegister\Db\SchemaMapper::class);

            // Run the test.
            $results = $solrService->testSchemaAwareMapping($objectMapper, $schemaMapper);

            return new JSONResponse(data: $results);
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 422
                );
        }//end try

    }//end testSchemaMapping()


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

            // Get GuzzleSolrService from container.
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);

            // Search documents in SOLR.
            $result = $guzzleSolrService->inspectIndex($query, $start, $rows, $fields);

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
            } else {
                return new JSONResponse(
                        data: [
                            'success'       => false,
                            'error'         => $result['error'],
                            'error_details' => $result['error_details'] ?? null,
                        ],
                        statusCode: 422
                        );
            }//end if
        } catch (\Exception $e) {
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
     * @NoCSRFRequired
     *
     * @return JSONResponse Memory usage prediction
     */
    public function getSolrMemoryPrediction(): JSONResponse
    {
        try {
            // Get request parameters.
            $maxObjects = (int) $this->request->getParam('maxObjects', 0);

            // Get GuzzleSolrService for prediction.
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);

            if (!$guzzleSolrService->isAvailable()) {
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
            $reflection = new \ReflectionClass($guzzleSolrService);
            $method     = $reflection->getMethod('predictWarmupMemoryUsage');
            $prediction = $method->invoke($guzzleSolrService, $maxObjects);

            return new JSONResponse(
                    data: [
                        'success'    => true,
                        'message'    => 'Memory prediction calculated successfully',
                        'prediction' => $prediction,
                    ]
                    );
        } catch (\Exception $e) {
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
     * Delete a SOLR field
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param  string $fieldName Name of the field to delete
     * @return JSONResponse
     */
    public function deleteSolrField(string $fieldName): JSONResponse
    {
        try {
            $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
            $logger->info(
                    message: '🗑️ Deleting SOLR field via API',
                    context: [
                        'field_name' => $fieldName,
                        'user'       => $this->userId,
                    ]
                    );

            // Validate field name.
            if (empty($fieldName) === true || !is_string($fieldName)) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'Invalid field name provided',
                        ],
                        statusCode: 400
                    );
            }

            // Prevent deletion of critical system fields.
            $protectedFields = ['id', '_version_', '_root_', '_text_'];
            if (in_array($fieldName, $protectedFields) === true) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => "Cannot delete protected system field: {$fieldName}",
                        ],
                        statusCode: 403
                        );
            }

            // Get GuzzleSolrService from container.
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            $result            = $guzzleSolrService->deleteField($fieldName);

            if ($result['success'] === true) {
                $logger->info(
                        message: '✅ SOLR field deleted successfully via API',
                        context: [
                            'field_name' => $fieldName,
                            'user'       => $this->userId,
                        ]
                        );

                return new JSONResponse(
                        data: [
                            'success'    => true,
                            'message'    => $result['message'],
                            'field_name' => $fieldName,
                        ]
                        );
            } else {
                $logger->warning(
                        '❌ Failed to delete SOLR field via API',
                        [
                            'field_name' => $fieldName,
                            'error'      => $result['message'],
                            'user'       => $this->userId,
                        ]
                        );

                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => $result['message'],
                            'error'   => $result['error'] ?? null,
                        ],
                        statusCode: 422
                        );
            }//end if
        } catch (\Exception $e) {
            $logger = $logger ?? \OC::$server->get(\Psr\Log\LoggerInterface::class);
            $logger->error(
                    message: 'Exception deleting SOLR field via API',
                    context: [
                        'field_name' => $fieldName,
                        'error'      => $e->getMessage(),
                        'user'       => $this->userId,
                        'trace'      => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Failed to delete SOLR field: '.$e->getMessage(),
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end deleteSolrField()


    /**
     * Debug endpoint for type filtering issue
     *
     * @return JSONResponse Debug information about type filtering
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function debugTypeFiltering(): JSONResponse
    {
        try {
            // Get services.
            $objectService = $this->container->get(\OCA\OpenRegister\Service\ObjectService::class);
            $connection    = $this->container->get(\OCP\IDBConnection::class);

            // Set register and schema context.
            $objectService->setRegister('voorzieningen');
            $objectService->setSchema('organisatie');

            $results = [];

            // Test 1: Get all organizations.
            $query1  = [
                '_limit'  => 10,
                '_page'   => 1,
                '_source' => 'database',
            ];
            $result1 = $objectService->searchObjectsPaginated($query1);
            $results['all_organizations'] = [
                'count'         => count($result1['results']),
                'organizations' => array_map(
                        function ($org) {
                            $objectData = $org->getObject();
                            return [
                                'id'          => $org->getId(),
                                'name'        => $org->getName(),
                                'type'        => $objectData['type'] ?? 'NO TYPE',
                                'object_data' => $objectData,
                            ];
                        },
                        $result1['results']
                        ),
            ];

            // Test 2: Try type filtering with samenwerking.
            $query2  = [
                '_limit'  => 10,
                '_page'   => 1,
                '_source' => 'database',
                'type'    => ['samenwerking'],
            ];
            $result2 = $objectService->searchObjectsPaginated($query2);
            $results['type_samenwerking'] = [
                'count'         => count($result2['results']),
                'organizations' => array_map(
                        function ($org) {
                            $objectData = $org->getObject();
                            return [
                                'id'   => $org->getId(),
                                'name' => $org->getName(),
                                'type' => $objectData['type'] ?? 'NO TYPE',
                            ];
                        },
                        $result2['results']
                        ),
            ];

            // Test 3: Try type filtering with community.
            $query3  = [
                '_limit'  => 10,
                '_page'   => 1,
                '_source' => 'database',
                'type'    => ['community'],
            ];
            $result3 = $objectService->searchObjectsPaginated($query3);
            $results['type_community'] = [
                'count'         => count($result3['results']),
                'organizations' => array_map(
                        function ($org) {
                            $objectData = $org->getObject();
                            return [
                                'id'   => $org->getId(),
                                'name' => $org->getName(),
                                'type' => $objectData['type'] ?? 'NO TYPE',
                            ];
                        },
                        $result3['results']
                        ),
            ];

            // Test 4: Try type filtering with both types.
            $query4  = [
                '_limit'  => 10,
                '_page'   => 1,
                '_source' => 'database',
                'type'    => ['samenwerking', 'community'],
            ];
            $result4 = $objectService->searchObjectsPaginated($query4);
            $results['type_both'] = [
                'count'         => count($result4['results']),
                'organizations' => array_map(
                        function ($org) {
                            $objectData = $org->getObject();
                            return [
                                'id'   => $org->getId(),
                                'name' => $org->getName(),
                                'type' => $objectData['type'] ?? 'NO TYPE',
                            ];
                        },
                        $result4['results']
                        ),
            ];

            // Test 5: Direct database query to check type field.
            $qb = $connection->getQueryBuilder();
            $qb->select('o.id', 'o.name', 'o.object')
                ->from('openregister_objects', 'o')
                ->where($qb->expr()->like('o.name', $qb->createNamedParameter('%Samenwerking%')))
                ->orWhere($qb->expr()->like('o.name', $qb->createNamedParameter('%Community%')));

            $stmt = $qb->executeQuery();
            $rows = $stmt->fetchAllAssociative();

            $results['direct_database_query'] = [
                'count'         => count($rows),
                'organizations' => array_map(
                        function ($row) {
                            $objectData = json_decode($row['object'], true);
                            return [
                                'id'          => $row['id'],
                                'name'        => $row['name'],
                                'type'        => $objectData['type'] ?? 'NO TYPE',
                                'object_json' => $row['object'],
                            ];
                        },
                        $rows
                        ),
            ];

            return new JSONResponse(data: $results);
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ],
                    statusCode: 500
                    );
        }//end try

    }//end debugTypeFiltering()


    /**
     * List all SOLR collections with statistics
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse List of collections with metadata
     */
    public function listSolrCollections(): JSONResponse
    {
        try {
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            $collections       = $guzzleSolrService->listCollections();

            return new JSONResponse(
                    data: [
                        'success'     => true,
                        'collections' => $collections,
                        'count'       => count($collections),
                        'timestamp'   => date('c'),
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                        'trace'   => $e->getTraceAsString(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end listSolrCollections()


    /**
     * List all SOLR ConfigSets
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse List of ConfigSets with metadata
     */
    public function listSolrConfigSets(): JSONResponse
    {
        try {
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            $configSets        = $guzzleSolrService->listConfigSets();

            return new JSONResponse(
                    data: [
                        'success'    => true,
                        'configSets' => $configSets,
                        'count'      => count($configSets),
                        'timestamp'  => date('c'),
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                        'trace'   => $e->getTraceAsString(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end listSolrConfigSets()


    /**
     * Create a new SOLR ConfigSet by copying an existing one
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $name          Name for the new ConfigSet
     * @param string $baseConfigSet Base ConfigSet to copy from (default: _default)
     *
     * @return JSONResponse Creation result
     */
    public function createSolrConfigSet(string $name, string $baseConfigSet='_default'): JSONResponse
    {
        try {
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            $result            = $guzzleSolrService->createConfigSet($name, $baseConfigSet);

            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 400
                );
        }

    }//end createSolrConfigSet()


    /**
     * Delete a SOLR ConfigSet
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $name Name of the ConfigSet to delete
     *
     * @return JSONResponse Deletion result
     */
    public function deleteSolrConfigSet(string $name): JSONResponse
    {
        try {
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            $result            = $guzzleSolrService->deleteConfigSet($name);

            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 400
                );
        }

    }//end deleteSolrConfigSet()


    /**
     * Create a new SOLR collection from a ConfigSet
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $collectionName    Name for the new collection
     * @param string $configName        ConfigSet to use
     * @param int    $numShards         Number of shards (default: 1)
     * @param int    $replicationFactor Number of replicas (default: 1)
     * @param int    $maxShardsPerNode  Maximum shards per node (default: 1)
     *
     * @return JSONResponse Creation result
     */
    public function createSolrCollection(
        string $collectionName,
        string $configName,
        int $numShards=1,
        int $replicationFactor=1,
        int $maxShardsPerNode=1
    ): JSONResponse {
        try {
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            $result            = $guzzleSolrService->createCollection(
                $collectionName,
                $configName,
                $numShards,
                $replicationFactor,
                $maxShardsPerNode
            );

            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                        'trace'   => $e->getTraceAsString(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end createSolrCollection()


    /**
     * Copy a SOLR collection
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $sourceCollection Source collection name
     * @param string $targetCollection Target collection name
     * @param bool   $copyData         Whether to copy data (default: false)
     *
     * @return JSONResponse Copy operation result
     */
    public function copySolrCollection(string $sourceCollection, string $targetCollection, bool $copyData=false): JSONResponse
    {
        try {
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            $result            = $guzzleSolrService->copyCollection($sourceCollection, $targetCollection, $copyData);

            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                        'trace'   => $e->getTraceAsString(),
                    ],
                    statusCode: 500
                );
        }

    }//end copySolrCollection()


    /**
     * Update SOLR collection assignments (Object Collection and File Collection)
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string|null $objectCollection Collection name for objects
     * @param string|null $fileCollection   Collection name for files
     *
     * @return JSONResponse Update result
     */
    public function updateSolrCollectionAssignments(?string $objectCollection=null, ?string $fileCollection=null): JSONResponse
    {
        try {
            // Get current SOLR settings.
            $solrSettings = $this->settingsService->getSolrSettingsOnly();

            // Update collection assignments.
            if ($objectCollection !== null) {
                $solrSettings['objectCollection'] = $objectCollection;
            }

            if ($fileCollection !== null) {
                $solrSettings['fileCollection'] = $fileCollection;
            }

            // Save updated settings.
            $this->settingsService->updateSolrSettingsOnly($solrSettings);

            return new JSONResponse(
                    data: [
                        'success'          => true,
                        'message'          => 'Collection assignments updated successfully',
                        'objectCollection' => $solrSettings['objectCollection'] ?? null,
                        'fileCollection'   => $solrSettings['fileCollection'] ?? null,
                        'timestamp'        => date('c'),
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                        'trace'   => $e->getTraceAsString(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end updateSolrCollectionAssignments()


    /**
     * Perform semantic search using vector embeddings
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string      $query    Search query text
     * @param int         $limit    Maximum number of results (default: 10)
     * @param array       $filters  Optional filters (entity_type, entity_id, etc.)
     * @param string|null $provider Embedding provider override
     *
     * @return JSONResponse Search results
     */
    public function semanticSearch(string $query, int $limit=10, array $filters=[], ?string $provider=null): JSONResponse
    {
        try {
            if (empty(trim($query)) === true) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Query parameter is required',
                        ],
                        statusCode: 400
                    );
            }

            // Get VectorEmbeddingService from container.
            $vectorService = $this->container->get(VectorEmbeddingService::class);

            // Perform semantic search.
            $results = $vectorService->semanticSearch($query, $limit, $filters, $provider);

            return new JSONResponse(
                    data: [
                        'success'   => true,
                        'query'     => $query,
                        'results'   => $results,
                        'total'     => count($results),
                        'limit'     => $limit,
                        'filters'   => $filters,
                        'timestamp' => date('c'),
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                        'trace'   => $e->getTraceAsString(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end semanticSearch()


    /**
     * Perform hybrid search combining SOLR keyword and vector semantic search
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string      $query       Search query text
     * @param int         $limit       Maximum number of results (default: 20)
     * @param array       $solrFilters SOLR-specific filters
     * @param array       $weights     Search type weights ['solr' => 0.5, 'vector' => 0.5]
     * @param string|null $provider    Embedding provider override
     *
     * @return JSONResponse Combined search results
     */
    public function hybridSearch(
        string $query,
        int $limit=20,
        array $solrFilters=[],
        array $weights=['solr' => 0.5, 'vector' => 0.5],
        ?string $provider=null
    ): JSONResponse {
        try {
            if (empty(trim($query)) === true) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Query parameter is required',
                        ],
                        statusCode: 400
                    );
            }

            // Get VectorEmbeddingService from container.
            $vectorService = $this->container->get(VectorEmbeddingService::class);

            // Perform hybrid search.
            $result = $vectorService->hybridSearch($query, $solrFilters, $limit, $weights, $provider);

            // Ensure result is an array for spread operator.
            $resultArray = is_array($result) === true ? $result : [];

            return new JSONResponse(
                    data: [
                        'success'   => true,
                        'query'     => $query,
                        ...$resultArray,
                        'timestamp' => date('c'),
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                        'trace'   => $e->getTraceAsString(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end hybridSearch()


    /**
     * Get vector embedding statistics
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Vector statistics
     */
    public function getVectorStats(): JSONResponse
    {
        try {
            // Get VectorEmbeddingService from container.
            $vectorService = $this->container->get(VectorEmbeddingService::class);

            // Get statistics.
            $stats = $vectorService->getVectorStats();

            return new JSONResponse(
                    data: [
                        'success'   => true,
                        'stats'     => $stats,
                        'timestamp' => date('c'),
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                        'trace'   => $e->getTraceAsString(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end getVectorStats()


    /**
     * Warmup files - Extract text and index in SOLR file collection
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Warmup results
     */
    public function warmupFiles(): JSONResponse
    {
        try {
            // Get request parameters.
            $maxFiles  = (int) $this->request->getParam('max_files', 100);
            $batchSize = (int) $this->request->getParam('batch_size', 50);
            // Note: file_types parameter not currently used.
            $skipIndexed = $this->request->getParam('skip_indexed', true);
            $mode        = $this->request->getParam('mode', 'parallel');

            // Validate parameters.
            $maxFiles = min($maxFiles, 5000);
            // Max 5000 files.
            $batchSize = min($batchSize, 500);
            // Max 500 per batch.
            $this->logger->info(
                    '[SettingsController] Starting file warmup',
                    [
                        'max_files'    => $maxFiles,
                        'batch_size'   => $batchSize,
                        'skip_indexed' => $skipIndexed,
                    ]
                    );

            // Get GuzzleSolrService and TextExtractionService.
            $guzzleSolrService     = $this->container->get(\OCA\OpenRegister\Service\GuzzleSolrService::class);
            $textExtractionService = $this->container->get(\OCA\OpenRegister\Service\TextExtractionService::class);

            // Get files that need processing.
            $filesToProcess = [];
            if ($skipIndexed === true) {
                $notIndexed = $textExtractionService->findNotIndexedInSolr('file', $maxFiles);
                foreach ($notIndexed as $fileId) {
                    $filesToProcess[] = $fileId;
                }
            } else {
                $completed = $textExtractionService->findByStatus('file', 'completed', $maxFiles, 0);
                foreach ($completed as $fileId) {
                    $filesToProcess[] = $fileId;
                }
            }

            // If no files to process, return early.
            if (empty($filesToProcess) === true) {
                return new JSONResponse(
                        data: [
                            'success'         => true,
                            'message'         => 'No files to process',
                            'files_processed' => 0,
                            'indexed'         => 0,
                            'failed'          => 0,
                        ]
                        );
            }

            // Process files in batches.
            $totalIndexed = 0;
            $totalFailed  = 0;
            $allErrors    = [];

            $batches = array_chunk($filesToProcess, $batchSize);
            foreach ($batches as $batch) {
                $result        = $guzzleSolrService->indexFiles($batch);
                $totalIndexed += $result['indexed'];
                $totalFailed  += $result['failed'];
                $allErrors     = array_merge($allErrors, $result['errors']);
            }

            return new JSONResponse(
                    data: [
                        'success'         => true,
                        'message'         => 'File warmup completed',
                        'files_processed' => count($filesToProcess),
                        'indexed'         => $totalIndexed,
                        'failed'          => $totalFailed,
                        'errors'          => array_slice($allErrors, 0, 20),
            // First 20 errors.
                        'mode'            => $mode,
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    '[SettingsController] File warmup failed',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'File warmup failed: '.$e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end warmupFiles()


    /**
     * Index a specific file in SOLR
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $fileId File ID to index
     *
     * @return JSONResponse Indexing result
     */
    public function indexFile(int $fileId): JSONResponse
    {
        try {
            $guzzleSolrService = $this->container->get(\OCA\OpenRegister\Service\GuzzleSolrService::class);

            $result = $guzzleSolrService->indexFiles([$fileId]);

            if ($result['indexed'] > 0) {
                return new JSONResponse(
                        data: [
                            'success' => true,
                            'message' => 'File indexed successfully',
                            'file_id' => $fileId,
                        ]
                        );
            } else {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => $result['errors'][0] ?? 'Failed to index file',
                            'file_id' => $fileId,
                        ],
                        statusCode: 422
                        );
            }
        } catch (\Exception $e) {
            $this->logger->error(
                    '[SettingsController] Failed to index file',
                    [
                        'file_id' => $fileId,
                        'error'   => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Failed to index file: '.$e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end indexFile()


    /**
     * Reindex all files
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Reindex results
     */
    public function reindexFiles(): JSONResponse
    {
        try {
            // Get all completed file texts.
            $textExtractionService = $this->container->get(\OCA\OpenRegister\Service\TextExtractionService::class);
            $guzzleSolrService     = $this->container->get(\OCA\OpenRegister\Service\GuzzleSolrService::class);

            $maxFiles  = (int) $this->request->getParam('max_files', 1000);
            $batchSize = (int) $this->request->getParam('batch_size', 100);

            // Get all completed extractions.
            $fileIds = $textExtractionService->findByStatus('file', 'completed', $maxFiles, 0);

            if (empty($fileIds) === true) {
                return new JSONResponse(
                        data: [
                            'success' => true,
                            'message' => 'No files to reindex',
                            'indexed' => 0,
                        ]
                        );
            }

            // Process in batches.
            $totalIndexed = 0;
            $totalFailed  = 0;
            $allErrors    = [];

            $batches = array_chunk($fileIds, $batchSize);
            foreach ($batches as $batch) {
                $result        = $guzzleSolrService->indexFiles($batch);
                $totalIndexed += $result['indexed'];
                $totalFailed  += $result['failed'];
                $allErrors     = array_merge($allErrors, $result['errors']);
            }

            return new JSONResponse(
                    data: [
                        'success'         => true,
                        'message'         => 'Reindex completed',
                        'files_processed' => count($fileIds),
                        'indexed'         => $totalIndexed,
                        'failed'          => $totalFailed,
                        'errors'          => array_slice($allErrors, 0, 20),
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    '[SettingsController] Reindex files failed',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Reindex failed: '.$e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end reindexFiles()


    /**
     * Get file index statistics
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse File index statistics
     */
    public function getFileIndexStats(): JSONResponse
    {
        try {
            $guzzleSolrService = $this->container->get(\OCA\OpenRegister\Service\GuzzleSolrService::class);
            $stats = $guzzleSolrService->getFileIndexStats();

            return new JSONResponse(data: $stats);
        } catch (\Exception $e) {
            $this->logger->error(
                    '[SettingsController] Failed to get file index stats',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Failed to get statistics: '.$e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end getFileIndexStats()


    /**
     * Get file extraction statistics
     *
     * Combines multiple data sources for comprehensive file statistics:
     * - FileMapper: Total files in Nextcloud (from oc_filecache, bypasses rights logic)
     * - FileTextMapper: Extraction status (from oc_openregister_file_texts)
     * - GuzzleSolrService: Chunk statistics (from SOLR index)
     *
     * This provides accurate statistics without dealing with Nextcloud's extensive rights logic.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse File extraction statistics including:
     *                      - totalFiles: All files in Nextcloud (from oc_filecache)
     *                      - processedFiles: Files tracked in extraction system (from oc_openregister_file_texts)
     *                      - pendingFiles: Files discovered and waiting for extraction (status='pending')
     *                      - untrackedFiles: Files in Nextcloud not yet discovered
     *                      - totalChunks: Number of text chunks in SOLR (one file = multiple chunks)
     *                      - completed, failed, indexed, processing, vectorized: Detailed processing status counts
     */
    public function getFileExtractionStats(): JSONResponse
    {
        try {
            // Get total files from Nextcloud filecache (bypasses rights logic).
            $fileMapper            = $this->container->get(\OCA\OpenRegister\Db\FileMapper::class);
            $totalFilesInNextcloud = $fileMapper->countAllFiles();
            $totalFilesSize        = $fileMapper->getTotalFilesSize();

            // Get extraction statistics from our file_texts table.
            $textExtractionService = $this->container->get(\OCA\OpenRegister\Service\TextExtractionService::class);
            $dbStats = $textExtractionService->getExtractionStats('file');

            // Get SOLR statistics.
            $guzzleSolrService = $this->container->get(\OCA\OpenRegister\Service\GuzzleSolrService::class);
            $solrStats         = $guzzleSolrService->getFileIndexStats();

            // Calculate storage in MB.
            $extractedTextStorageMB = round($dbStats['total_text_size'] / 1024 / 1024, 2);
            $totalFilesStorageMB    = round($totalFilesSize / 1024 / 1024, 2);

            // Calculate untracked files (files in Nextcloud not yet discovered).
            $untrackedFiles = $totalFilesInNextcloud - $dbStats['total'];

            return new JSONResponse(
                    data: [
                        'success'                => true,
                        'totalFiles'             => $totalFilesInNextcloud,
                        'processedFiles'         => $dbStats['completed'],
            // Files successfully extracted (status='completed').
                        'pendingFiles'           => $dbStats['pending'],
            // Files discovered and waiting for extraction.
                        'untrackedFiles'         => max(0, $untrackedFiles),
            // Files not yet discovered.
                        'totalChunks'            => $solrStats['total_chunks'] ?? 0,
                        'extractedTextStorageMB' => number_format($extractedTextStorageMB, 2),
                        'totalFilesStorageMB'    => number_format($totalFilesStorageMB, 2),
                        'completed'              => $dbStats['completed'],
                        'failed'                 => $dbStats['failed'],
                        'indexed'                => $dbStats['indexed'],
                        'processing'             => $dbStats['processing'],
                        'vectorized'             => $dbStats['vectorized'],
                    ]
                    );
        } catch (\Exception $e) {
            // Return zeros instead of error to avoid breaking UI.
            return new JSONResponse(
                    data: [
                        'success'                => true,
                        'totalFiles'             => 0,
                        'processedFiles'         => 0,
                        'pendingFiles'           => 0,
                        'untrackedFiles'         => 0,
                        'totalChunks'            => 0,
                        'extractedTextStorageMB' => '0.00',
                        'totalFilesStorageMB'    => '0.00',
                        'completed'              => 0,
                        'failed'                 => 0,
                        'indexed'                => 0,
                        'processing'             => 0,
                        'vectorized'             => 0,
                        'error'                  => $e->getMessage(),
                    ]
                    );
        }//end try

    }//end getFileExtractionStats()


    /**
     * Get API tokens for GitHub and GitLab
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse The API tokens
     */
    public function getApiTokens(): JSONResponse
    {
        try {
            $githubToken = $this->config->getValueString('openregister', 'github_api_token', '');
            $gitlabToken = $this->config->getValueString('openregister', 'gitlab_api_token', '');
            $gitlabUrl   = $this->config->getValueString('openregister', 'gitlab_api_url', '');

            // Mask tokens for security (only show first/last few characters).
            $maskedGithubToken = '';
            if ($githubToken !== '') {
                $maskedGithubToken = $this->maskToken($githubToken);
            }

            $maskedGitlabToken = '';
            if ($gitlabToken !== '') {
                $maskedGitlabToken = $this->maskToken($gitlabToken);
            }

            return new JSONResponse(
                    data: [
                        'github_token' => $maskedGithubToken,
                        'gitlab_token' => $maskedGitlabToken,
                        'gitlab_url'   => $gitlabUrl,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Failed to retrieve API tokens: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try

    }//end getApiTokens()


    /**
     * Save API tokens for GitHub and GitLab
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Success or error message
     */
    public function saveApiTokens(): JSONResponse
    {
        try {
            $data = $this->request->getParams();

            if (($data['github_token'] ?? null) !== null) {
                // Only save if not masked.
                if (!str_contains($data['github_token'], '***')) {
                    $this->config->setValueString('openregister', 'github_api_token', $data['github_token']);
                }
            }

            if (($data['gitlab_token'] ?? null) !== null) {
                // Only save if not masked.
                if (!str_contains($data['gitlab_token'], '***')) {
                    $this->config->setValueString('openregister', 'gitlab_api_token', $data['gitlab_token']);
                }
            }

            if (($data['gitlab_url'] ?? null) !== null) {
                $this->config->setValueString('openregister', 'gitlab_api_url', $data['gitlab_url']);
            }

            return new JSONResponse(
                    data: [
                        'success' => true,
                        'message' => 'API tokens saved successfully',
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Failed to save API tokens: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try

    }//end saveApiTokens()


    /**
     * Test GitHub API token
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Test result
     */
    public function testGitHubToken(): JSONResponse
    {
        try {
            $data  = $this->request->getParams();
            $token = $data['token'] ?? $this->config->getValueString('openregister', 'github_api_token', '');

            if (empty($token) === true) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'No GitHub token provided',
                        ],
                        statusCode: 400
                    );
            }

            // Test the token by making a simple API call.
            $client   = \OC::$server->get(\OCP\Http\Client\IClientService::class)->newClient();
            $response = $client->get(
                    'https://api.github.com/user',
                    [
                        'headers' => [
                            'Accept'               => 'application/vnd.github+json',
                            'Authorization'        => 'Bearer '.$token,
                            'X-GitHub-Api-Version' => '2022-11-28',
                        ],
                    ]
                    );

            $data = json_decode($response->getBody(), true);

            return new JSONResponse(
                    data: [
                        'success'  => true,
                        'message'  => 'GitHub token is valid',
                        'username' => $data['login'] ?? 'Unknown',
                        'scopes'   => $response->getHeader('X-OAuth-Scopes') ?? [],
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'GitHub token test failed: '.$e->getMessage(),
                    ],
                    statusCode: 400
                );
        }//end try

    }//end testGitHubToken()


    /**
     * Test GitLab API token
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Test result
     */
    public function testGitLabToken(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $token  = $data['token'] ?? $this->config->getValueString('openregister', 'gitlab_api_token', '');
            $apiUrl = $data['url'] ?? $this->config->getValueString('openregister', 'gitlab_api_url', 'https://gitlab.com/api/v4');

            if (empty($token) === true) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'No GitLab token provided',
                        ],
                        statusCode: 400
                    );
            }

            // Ensure API URL doesn't end with slash.
            $apiUrl = rtrim($apiUrl, '/');

            // Default to gitlab.com if no URL provided.
            if (empty($apiUrl) === true) {
                $apiUrl = 'https://gitlab.com/api/v4';
            }

            // Test the token by making a simple API call.
            $client   = \OC::$server->get(\OCP\Http\Client\IClientService::class)->newClient();
            $response = $client->get(
                    $apiUrl.'/user',
                    [
                        'headers' => [
                            'PRIVATE-TOKEN' => $token,
                        ],
                    ]
                    );

            $data = json_decode($response->getBody(), true);

            return new JSONResponse(
                    data: [
                        'success'  => true,
                        'message'  => 'GitLab token is valid',
                        'username' => $data['username'] ?? 'Unknown',
                        'instance' => $apiUrl,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'GitLab token test failed: '.$e->getMessage(),
                    ],
                    statusCode: 400
                );
        }//end try

    }//end testGitLabToken()


    /**
     * Mask sensitive token for display
     *
     * @param string $token The token to mask
     *
     * @return string The masked token
     */
    private function maskToken(string $token): string
    {
        if (strlen($token) <= 8) {
            return str_repeat('*', strlen($token));
        }

        $start  = substr($token, 0, 4);
        $end    = substr($token, -4);
        $middle = str_repeat('*', min(20, strlen($token) - 8));

        return $start.$middle.$end;

    }//end maskToken()


}//end class
