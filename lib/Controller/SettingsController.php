<?php
/**
 *  OpenRegister Settings Controller
 *
 * This file contains the controller class for handling settings in the OpenRegister application.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Controller;

use OCP\IAppConfig;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use OCP\App\IAppManager;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\GuzzleSolrService;

/**
 * Controller for handling settings-related operations in the OpenRegister.
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
     * @param string             $appName         The name of the app
     * @param IRequest           $request         The request object
     * @param IAppConfig         $config          The app configuration
     * @param ContainerInterface $container       The container
     * @param IAppManager        $appManager      The app manager
     * @param SettingsService    $settingsService The settings service
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly SettingsService $settingsService,
    ) {
        parent::__construct($appName, $request);

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
            return new JSONResponse($data);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
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
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
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
            $result = $this->settingsService->loadSettings();
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
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
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
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
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
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
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 422);
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
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
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
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
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
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 422);
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
            $objectService = $this->getObjectService();
            $validateHandler = $this->container->get('OCA\OpenRegister\Service\ObjectHandlers\ValidateObject');
            $schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
            
            // Get all objects from the system
            $allObjects = $objectService->findAll();
            
            $validationResults = [
                'total_objects' => count($allObjects),
                'valid_objects' => 0,
                'invalid_objects' => 0,
                'validation_errors' => [],
                'summary' => []
            ];
            
            foreach ($allObjects as $object) {
                try {
                    // Get the schema for this object
                    $schema = $schemaMapper->find($object->getSchema());
                    
                    // Validate the object against its schema using the ValidateObject handler
                    $validationResult = $validateHandler->validateObject($object->getObject(), $schema);
                    
                    if ($validationResult->isValid() === true) {
                        $validationResults['valid_objects']++;
                    } else {
                        $validationResults['invalid_objects']++;
                        $validationResults['validation_errors'][] = [
                            'object_id' => $object->getUuid(),
                            'object_name' => $object->getName() ?? $object->getUuid(),
                            'register' => $object->getRegister(),
                            'schema' => $object->getSchema(),
                            'errors' => $validationResult->error()
                        ];
                    }
                } catch (\Exception $e) {
                    $validationResults['invalid_objects']++;
                    $validationResults['validation_errors'][] = [
                        'object_id' => $object->getUuid(),
                        'object_name' => $object->getName() ?? $object->getUuid(),
                        'register' => $object->getRegister(),
                        'schema' => $object->getSchema(),
                        'errors' => ['Validation failed: ' . $e->getMessage()]
                    ];
                }
            }
            
            // Create summary
            $validationResults['summary'] = [
                'validation_success_rate' => $validationResults['total_objects'] > 0 
                    ? round(($validationResults['valid_objects'] / $validationResults['total_objects']) * 100, 2) 
                    : 100,
                'has_errors' => $validationResults['invalid_objects'] > 0,
                'error_count' => count($validationResults['validation_errors'])
            ];
            
            return new JSONResponse($validationResults);
            
        } catch (\Exception $e) {
            return new JSONResponse([
                'error' => 'Failed to validate objects: ' . $e->getMessage(),
                'total_objects' => 0,
                'valid_objects' => 0,
                'invalid_objects' => 0,
                'validation_errors' => [],
                'summary' => ['has_errors' => true, 'error_count' => 1]
            ], 500);
        }

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
            $startTime = microtime(true);
            $startMemory = memory_get_usage(true);
            $peakMemory = memory_get_peak_usage(true);
            
            // Get request parameters from JSON body or query parameters
            $maxObjects = $this->request->getParam('maxObjects', 0);
            $batchSize = $this->request->getParam('batchSize', 1000);
            $mode = $this->request->getParam('mode', 'serial'); // New mode parameter
            $collectErrors = $this->request->getParam('collectErrors', false); // New error collection parameter
            
            // Try to get from JSON body if not in query params
            if ($maxObjects === 0 && $batchSize === 1000) {
                $input = file_get_contents('php://input');
                if ($input) {
                    $data = json_decode($input, true);
                    if ($data) {
                        $maxObjects = $data['maxObjects'] ?? 0;
                        $batchSize = $data['batchSize'] ?? 1000;
                        $mode = $data['mode'] ?? 'serial';
                        $collectErrors = $data['collectErrors'] ?? false;
                    }
                }
            }
            
            // Convert string boolean to actual boolean
            if (is_string($collectErrors)) {
                $collectErrors = filter_var($collectErrors, FILTER_VALIDATE_BOOLEAN);
            }
            
            // Validate parameters
            if (!in_array($mode, ['serial', 'parallel'])) {
                return new JSONResponse([
                    'error' => 'Invalid mode parameter. Must be "serial" or "parallel"'
                ], 400);
            }
            
            if ($batchSize < 1 || $batchSize > 5000) {
                return new JSONResponse([
                    'error' => 'Invalid batch size. Must be between 1 and 5000'
                ], 400);
            }
            
            $objectService = $this->getObjectService();
            $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
            
            // Use optimized approach like SOLR warmup - get count first, then process in chunks
            $objectMapper = \OC::$server->get(\OCA\OpenRegister\Db\ObjectEntityMapper::class);
            $totalObjects = $objectMapper->countSearchObjects([], null, false, false);
            
            // Apply maxObjects limit if specified
            if ($maxObjects > 0 && $maxObjects < $totalObjects) {
                $totalObjects = $maxObjects;
            }
            
            $logger->info('🚀 STARTING MASS VALIDATION', [
                'totalObjects' => $totalObjects,
                'batchSize' => $batchSize,
                'mode' => $mode,
                'collectErrors' => $collectErrors
            ]);
            
            $results = [
                'success' => true,
                'message' => 'Mass validation completed successfully',
                'stats' => [
                    'total_objects' => $totalObjects,
                    'processed_objects' => 0,
                    'successful_saves' => 0,
                    'failed_saves' => 0,
                    'duration_seconds' => 0,
                    'batches_processed' => 0,
                    'objects_per_second' => 0
                ],
                'errors' => [],
                'batches_processed' => 0,
                'timestamp' => date('c'),
                'config_used' => [
                    'mode' => $mode,
                    'max_objects' => $maxObjects,
                    'batch_size' => $batchSize,
                    'collect_errors' => $collectErrors
                ]
            ];
            
            // Create batch jobs like SOLR warmup
            $batchJobs = [];
            $offset = 0;
            $batchNumber = 0;
            
            while ($offset < $totalObjects) {
                $currentBatchSize = min($batchSize, $totalObjects - $offset);
                $batchJobs[] = [
                    'batchNumber' => ++$batchNumber,
                    'offset' => $offset,
                    'limit' => $currentBatchSize
                ];
                $offset += $currentBatchSize;
            }
            
            $results['stats']['batches_processed'] = count($batchJobs);
            
            $logger->info('📋 BATCH JOBS CREATED', [
                'totalBatches' => count($batchJobs),
                'estimatedDuration' => round((count($batchJobs) * 2)) . 's'
            ]);
            
            // Process batches based on mode
            if ($mode === 'parallel') {
                $this->processJobsParallel($batchJobs, $objectMapper, $objectService, $results, $collectErrors, 4, $logger);
            } else {
                $this->processJobsSerial($batchJobs, $objectMapper, $objectService, $results, $collectErrors, $logger);
            }
            
            // Calculate final metrics
            $endTime = microtime(true);
            $endMemory = memory_get_usage(true);
            $finalPeakMemory = memory_get_peak_usage(true);
            
            $results['stats']['duration_seconds'] = round($endTime - $startTime, 2);
            $results['stats']['objects_per_second'] = $results['stats']['duration_seconds'] > 0 
                ? round($results['stats']['processed_objects'] / $results['stats']['duration_seconds'], 2)
                : 0;
            
            // Add memory usage information
            $results['memory_usage'] = [
                'start_memory' => $startMemory,
                'end_memory' => $endMemory,
                'peak_memory' => max($peakMemory, $finalPeakMemory),
                'memory_used' => $endMemory - $startMemory,
                'peak_percentage' => round((max($peakMemory, $finalPeakMemory) / (1024 * 1024 * 1024)) * 100, 1), // Assume 1GB available
                'formatted' => [
                    'actual_used' => $this->formatBytes($endMemory - $startMemory),
                    'peak_usage' => $this->formatBytes(max($peakMemory, $finalPeakMemory)),
                    'peak_percentage' => round((max($peakMemory, $finalPeakMemory) / (1024 * 1024 * 1024)) * 100, 1) . '%'
                ]
            ];
            
            // Determine overall success
            if ($results['stats']['failed_saves'] > 0) {
                if ($collectErrors) {
                    $results['success'] = $results['stats']['successful_saves'] > 0; // Partial success if some objects were saved
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
            
            $logger->info('✅ MASS VALIDATION COMPLETED', [
                'successful' => $results['stats']['successful_saves'],
                'failed' => $results['stats']['failed_saves'],
                'total' => $results['stats']['processed_objects'],
                'duration' => $results['stats']['duration_seconds'] . 's',
                'objectsPerSecond' => $results['stats']['objects_per_second'],
                'mode' => $mode
            ]);
            
            return new JSONResponse($results);
            
        } catch (\Exception $e) {
            $logger = $logger ?? \OC::$server->get(\Psr\Log\LoggerInterface::class);
            $logger->error('❌ MASS VALIDATION FAILED', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return new JSONResponse([
                'success' => false,
                'error' => 'Mass validation failed: ' . $e->getMessage(),
                'stats' => [
                    'total_objects' => 0,
                    'processed_objects' => 0,
                    'successful_saves' => 0,
                    'failed_saves' => 0,
                    'duration_seconds' => 0
                ],
                'errors' => [
                    [
                        'error' => $e->getMessage()
                    ]
                ],
                'timestamp' => date('c')
            ], 500);
        }

    }//end massValidateObjects()


    /**
     * Process a batch of objects in serial mode
     *
     * @param array $batch Array of objects to process
     * @param mixed $objectService The object service instance
     * @param array &$results Results array to update
     * @param bool $collectErrors Whether to collect all errors or stop on first
     * @return void
     */
    private function processBatchSerial(array $batch, $objectService, array &$results, bool $collectErrors): void
    {
        foreach ($batch as $object) {
                try {
                    $results['stats']['processed_objects']++;
                    
                    // Re-save the object to trigger all business logic
                    // This will run validation, transformations, and other handlers
                    $savedObject = $objectService->saveObject(
                        $object->getObject(),
                    [], // extend parameter
                        $object->getRegister(),
                        $object->getSchema(),
                        $object->getUuid()
                    );
                    
                    if ($savedObject) {
                        $results['stats']['successful_saves']++;
                    } else {
                        $results['stats']['failed_saves']++;
                        $results['errors'][] = [
                            'object_id' => $object->getUuid(),
                            'object_name' => $object->getName() ?? $object->getUuid(),
                            'register' => $object->getRegister(),
                            'schema' => $object->getSchema(),
                        'error' => 'Save operation returned null',
                        'batch_mode' => 'serial'
                        ];
                    }
                    
                } catch (\Exception $e) {
                    $results['stats']['failed_saves']++;
                    $results['errors'][] = [
                        'object_id' => $object->getUuid(),
                        'object_name' => $object->getName() ?? $object->getUuid(),
                        'register' => $object->getRegister(),
                        'schema' => $object->getSchema(),
                    'error' => $e->getMessage(),
                    'batch_mode' => 'serial'
                ];
                
                // Log the error for debugging
                $this->logger->error('Mass validation failed for object ' . $object->getUuid() . ': ' . $e->getMessage());
                
                // If not collecting errors, stop processing this batch
                if (!$collectErrors) {
                    break;
                }
            }
        }
    }//end processBatchSerial()


    /**
     * Process a batch of objects in parallel mode (simulated)
     *
     * @param array $batch Array of objects to process
     * @param mixed $objectService The object service instance
     * @param array &$results Results array to update
     * @param bool $collectErrors Whether to collect all errors or stop on first
     * @return void
     */
    private function processBatchParallel(array $batch, $objectService, array &$results, bool $collectErrors): void
    {
        // Note: True parallel processing would require process forking or threading
        // For now, we simulate parallel processing with optimized serial processing
        // In a real implementation, you might use ReactPHP, Swoole, or similar
        
        $batchErrors = [];
        $batchSuccesses = 0;
        
        foreach ($batch as $object) {
            try {
                $results['stats']['processed_objects']++;
                
                // Re-save the object to trigger all business logic
                // This will run validation, transformations, and other handlers
                $savedObject = $objectService->saveObject(
                    $object->getObject(),
                    [], // extend parameter
                    $object->getRegister(),
                    $object->getSchema(),
                    $object->getUuid()
                );
                
                if ($savedObject) {
                    $batchSuccesses++;
                } else {
                    $batchErrors[] = [
                        'object_id' => $object->getUuid(),
                        'object_name' => $object->getName() ?? $object->getUuid(),
                        'register' => $object->getRegister(),
                        'schema' => $object->getSchema(),
                        'error' => 'Save operation returned null',
                        'batch_mode' => 'parallel'
                    ];
                }
                
            } catch (\Exception $e) {
                $batchErrors[] = [
                    'object_id' => $object->getUuid(),
                    'object_name' => $object->getName() ?? $object->getUuid(),
                    'register' => $object->getRegister(),
                    'schema' => $object->getSchema(),
                    'error' => $e->getMessage(),
                    'batch_mode' => 'parallel'
                ];
                
                // Log the error for debugging
                $this->logger->error('Mass validation failed for object ' . $object->getUuid() . ': ' . $e->getMessage());
                
                // If not collecting errors, stop processing this batch
                if (!$collectErrors) {
                    break;
                }
            }
        }
        
        // Update results with batch totals
        $results['stats']['successful_saves'] += $batchSuccesses;
        $results['stats']['failed_saves'] += count($batchErrors);
        $results['errors'] = array_merge($results['errors'], $batchErrors);
    }//end processBatchParallel()


    /**
     * Process batch jobs in serial mode (optimized like SOLR warmup)
     *
     * @param array $batchJobs Array of batch job definitions
     * @param mixed $objectMapper The object entity mapper
     * @param mixed $objectService The object service instance
     * @param array &$results Results array to update
     * @param bool $collectErrors Whether to collect all errors or stop on first
     * @return void
     */
    private function processJobsSerial(array $batchJobs, $objectMapper, $objectService, array &$results, bool $collectErrors, $logger): void
    {
        foreach ($batchJobs as $batchIndex => $job) {
            $batchStartTime = microtime(true);
            
            // Get objects for this batch using offset/limit like SOLR warmup
            $objects = $objectMapper->findAll(
                limit: $job['limit'],
                offset: $job['offset']
            );
            
            $batchProcessed = 0;
            $batchSuccesses = 0;
            $batchErrors = [];
            
            foreach ($objects as $object) {
                try {
                    $batchProcessed++;
                    $results['stats']['processed_objects']++;
                    
                    // Re-save the object to trigger all business logic
                    $savedObject = $objectService->saveObject(
                        $object->getObject(),
                        [], // extend parameter
                        $object->getRegister(),
                        $object->getSchema(),
                        $object->getUuid()
                    );
                    
                    if ($savedObject) {
                        $batchSuccesses++;
                        $results['stats']['successful_saves']++;
                    } else {
                        $results['stats']['failed_saves']++;
                        $batchErrors[] = [
                            'object_id' => $object->getUuid(),
                            'object_name' => $object->getName() ?? $object->getUuid(),
                            'register' => $object->getRegister(),
                            'schema' => $object->getSchema(),
                            'error' => 'Save operation returned null',
                            'batch_mode' => 'serial_optimized'
                        ];
                    }
                    
                } catch (\Exception $e) {
                    $results['stats']['failed_saves']++;
                    $batchErrors[] = [
                        'object_id' => $object->getUuid(),
                        'object_name' => $object->getName() ?? $object->getUuid(),
                        'register' => $object->getRegister(),
                        'schema' => $object->getSchema(),
                        'error' => $e->getMessage(),
                        'batch_mode' => 'serial_optimized'
                    ];
                    
                    $logger->error('Mass validation failed for object ' . $object->getUuid() . ': ' . $e->getMessage());
                    
                    if (!$collectErrors) {
                        break;
                    }
                }
            }
            
            $batchDuration = microtime(true) - $batchStartTime;
            $objectsPerSecond = $batchDuration > 0 ? round($batchProcessed / $batchDuration, 2) : 0;
            
            // Log progress every batch like SOLR warmup
            $logger->info('📈 MASS VALIDATION PROGRESS', [
                'batchNumber' => $job['batchNumber'],
                'totalBatches' => count($batchJobs),
                'processed' => $batchProcessed,
                'successful' => $batchSuccesses,
                'failed' => count($batchErrors),
                'batchDuration' => round($batchDuration * 1000) . 'ms',
                'objectsPerSecond' => $objectsPerSecond,
                'totalProcessed' => $results['stats']['processed_objects']
            ]);
            
            // Add batch errors to results
            $results['errors'] = array_merge($results['errors'], $batchErrors);
            
            // Memory management every 10 batches
            if ($job['batchNumber'] % 10 === 0) {
                $logger->debug('🧹 MEMORY CLEANUP', [
                    'memoryUsage' => round(memory_get_usage() / 1024 / 1024, 2) . 'MB',
                    'peakMemory' => round(memory_get_peak_usage() / 1024 / 1024, 2) . 'MB'
                ]);
                gc_collect_cycles();
            }
            
            // Clear objects from memory
            unset($objects);
        }
    }//end processJobsSerial()


    /**
     * Process batch jobs in parallel mode (optimized like SOLR warmup)
     *
     * @param array $batchJobs Array of batch job definitions
     * @param mixed $objectMapper The object entity mapper
     * @param mixed $objectService The object service instance
     * @param array &$results Results array to update
     * @param bool $collectErrors Whether to collect all errors or stop on first
     * @param int $parallelBatches Number of parallel batches to process
     * @return void
     */
    private function processJobsParallel(array $batchJobs, $objectMapper, $objectService, array &$results, bool $collectErrors, int $parallelBatches, $logger): void
    {
        // Process batches in parallel chunks like SOLR warmup
        $batchChunks = array_chunk($batchJobs, $parallelBatches);
        
        foreach ($batchChunks as $chunkIndex => $chunk) {
            $logger->info('🔄 PROCESSING PARALLEL CHUNK', [
                'chunkIndex' => $chunkIndex + 1,
                'totalChunks' => count($batchChunks),
                'batchesInChunk' => count($chunk)
            ]);
            
            $chunkStartTime = microtime(true);
            
            // Process batches in this chunk (simulated parallel processing)
            // In a real implementation, this would use actual parallel processing
            $chunkResults = [];
            foreach ($chunk as $job) {
                $result = $this->processBatchDirectly($objectMapper, $objectService, $job, $collectErrors);
                $chunkResults[] = $result;
            }
            
            // Aggregate results from this chunk
            foreach ($chunkResults as $result) {
                $results['stats']['processed_objects'] += $result['processed'];
                $results['stats']['successful_saves'] += $result['successful'];
                $results['stats']['failed_saves'] += $result['failed'];
                $results['errors'] = array_merge($results['errors'], $result['errors']);
            }
            
            $chunkTime = round((microtime(true) - $chunkStartTime) * 1000, 2);
            $chunkProcessed = array_sum(array_column($chunkResults, 'processed'));
            
            $logger->info('✅ COMPLETED PARALLEL CHUNK', [
                'chunkIndex' => $chunkIndex + 1,
                'chunkTime' => $chunkTime . 'ms',
                'objectsProcessed' => $chunkProcessed,
                'totalProcessed' => $results['stats']['processed_objects']
            ]);
            
            // Memory cleanup after each chunk
            gc_collect_cycles();
        }
    }//end processJobsParallel()


    /**
     * Process a single batch directly (helper for parallel processing)
     *
     * @param mixed $objectMapper The object entity mapper
     * @param mixed $objectService The object service instance
     * @param array $job Batch job definition
     * @param bool $collectErrors Whether to collect all errors
     * @return array Batch processing results
     */
    private function processBatchDirectly($objectMapper, $objectService, array $job, bool $collectErrors): array
    {
        $batchStartTime = microtime(true);
        
        // Get objects for this batch
        $objects = $objectMapper->findAll(
            limit: $job['limit'],
            offset: $job['offset']
        );
        
        $batchProcessed = 0;
        $batchSuccesses = 0;
        $batchErrors = [];
        
        foreach ($objects as $object) {
            try {
                $batchProcessed++;
                
                // Re-save the object to trigger all business logic
                $savedObject = $objectService->saveObject(
                    $object->getObject(),
                    [], // extend parameter
                    $object->getRegister(),
                    $object->getSchema(),
                    $object->getUuid()
                );
                
                if ($savedObject) {
                    $batchSuccesses++;
                } else {
                    $batchErrors[] = [
                        'object_id' => $object->getUuid(),
                        'object_name' => $object->getName() ?? $object->getUuid(),
                        'register' => $object->getRegister(),
                        'schema' => $object->getSchema(),
                        'error' => 'Save operation returned null',
                        'batch_mode' => 'parallel_optimized'
                    ];
                }
                
            } catch (\Exception $e) {
                $batchErrors[] = [
                    'object_id' => $object->getUuid(),
                    'object_name' => $object->getName() ?? $object->getUuid(),
                    'register' => $object->getRegister(),
                    'schema' => $object->getSchema(),
                    'error' => $e->getMessage(),
                    'batch_mode' => 'parallel_optimized'
                ];
                
                if (!$collectErrors) {
                    break;
                }
            }
        }
        
        $batchDuration = microtime(true) - $batchStartTime;
        
        // Clear objects from memory
        unset($objects);
        
        return [
            'processed' => $batchProcessed,
            'successful' => $batchSuccesses,
            'failed' => count($batchErrors),
            'errors' => $batchErrors,
            'duration' => $batchDuration
        ];
    }//end processBatchDirectly()


    /**
     * Format bytes into human readable format
     *
     * @param int $bytes Number of bytes
     * @param int $precision Decimal precision
     * @return string Formatted string
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
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
            // Get request parameters
            $maxObjects = $this->request->getParam('maxObjects', 0);
            
            // Try to get from JSON body if not in query params
            if ($maxObjects === 0) {
                $input = file_get_contents('php://input');
                if ($input) {
                    $data = json_decode($input, true);
                    if ($data) {
                        $maxObjects = $data['maxObjects'] ?? 0;
                    }
                }
            }
            
            // Get current memory usage without loading all objects (much faster)
            $currentMemory = memory_get_usage(true);
            $memoryLimit = ini_get('memory_limit');
            
            // Convert memory limit to bytes
            $memoryLimitBytes = $this->convertToBytes($memoryLimit);
            $availableMemory = $memoryLimitBytes - $currentMemory;
            
            // Use a lightweight approach - estimate based on typical object size
            // We'll use the maxObjects parameter or provide a reasonable default estimate
            $estimatedObjectCount = $maxObjects > 0 ? $maxObjects : 10000; // Default estimate
            
            // Estimate memory usage (rough calculation)
            // Assume each object uses approximately 50KB in memory during processing
            $estimatedMemoryPerObject = 50 * 1024; // 50KB
            $totalEstimatedMemory = $estimatedObjectCount * $estimatedMemoryPerObject;
            
            // Determine if prediction is safe
            $predictionSafe = $totalEstimatedMemory < ($availableMemory * 0.8); // Use 80% as safety margin
            
            $prediction = [
                'success' => true,
                'prediction_safe' => $predictionSafe,
                'objects_to_process' => $estimatedObjectCount,
                'total_objects_available' => 'Unknown (fast mode)', // Don't count all objects for speed
                'memory_per_object_bytes' => $estimatedMemoryPerObject,
                'total_predicted_bytes' => $totalEstimatedMemory,
                'current_memory_bytes' => $currentMemory,
                'memory_limit_bytes' => $memoryLimitBytes,
                'available_memory_bytes' => $availableMemory,
                'safety_margin_percentage' => 80,
                'formatted' => [
                    'total_predicted' => $this->formatBytes($totalEstimatedMemory),
                    'available' => $this->formatBytes($availableMemory),
                    'current_usage' => $this->formatBytes($currentMemory),
                    'memory_limit' => $this->formatBytes($memoryLimitBytes),
                    'memory_per_object' => $this->formatBytes($estimatedMemoryPerObject)
                ],
                'recommendation' => $predictionSafe 
                    ? 'Memory usage looks safe for this operation'
                    : 'Consider reducing batch size or max objects to avoid memory issues',
                'note' => 'Fast prediction mode - actual object count will be determined during processing'
            ];
            
            return new JSONResponse($prediction);
            
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => 'Failed to predict memory usage: ' . $e->getMessage(),
                'prediction_safe' => true, // Default to safe if we can't predict
                'formatted' => [
                    'total_predicted' => 'Unknown',
                    'available' => 'Unknown'
                ]
            ], 500);
        }
    }//end predictMassValidationMemory()


    /**
     * Convert memory limit string to bytes
     *
     * @param string $memoryLimit Memory limit string (e.g., '128M', '1G')
     * @return int Memory limit in bytes
     */
    private function convertToBytes(string $memoryLimit): int
    {
        $memoryLimit = trim($memoryLimit);
        $last = strtolower($memoryLimit[strlen($memoryLimit) - 1]);
        $value = (int) $memoryLimit;
        
        switch ($last) {
            case 'g':
                $value *= 1024;
                // no break
            case 'm':
                $value *= 1024;
                // no break
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
            // Get logger for improved logging
            $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
            
            // **IMPROVED LOGGING**: Log setup attempt with detailed context
            $logger->info('🔧 SOLR setup endpoint called', [
                'timestamp' => date('c'),
                'user_id' => $this->userId ?? 'unknown',
                'request_id' => $this->request->getId() ?? 'unknown'
            ]);
            
            // Get SOLR settings
            $solrSettings = $this->settingsService->getSolrSettings();
            
            // **IMPROVED LOGGING**: Log SOLR configuration (without sensitive data)
            $logger->info('📋 SOLR configuration loaded for setup', [
                'enabled' => $solrSettings['enabled'] ?? false,
                'host' => $solrSettings['host'] ?? 'not_set',
                'port' => $solrSettings['port'] ?? 'not_set',
                'has_credentials' => !empty($solrSettings['username']) && !empty($solrSettings['password'])
            ]);
            
            // Create SolrSetup using GuzzleSolrService for authenticated HTTP client
            $guzzleSolrService = $this->container->get(\OCA\OpenRegister\Service\GuzzleSolrService::class);
            $setup = new \OCA\OpenRegister\Setup\SolrSetup($guzzleSolrService, $logger);
            
            // **IMPROVED LOGGING**: Log setup initialization
            $logger->info('🏗️ SolrSetup instance created, starting setup process');
            
            // Run setup
            $setupResult = $setup->setupSolr();
            
            if ($setupResult) {
                // Get detailed setup progress and infrastructure info from SolrSetup
                $setupProgress = $setup->getSetupProgress();
                $infrastructureCreated = $setup->getInfrastructureCreated();
                
                // **IMPROVED LOGGING**: Log successful setup
                $logger->info('✅ SOLR setup completed successfully', [
                    'completed_steps' => $setupProgress['completed_steps'] ?? 0,
                    'total_steps' => $setupProgress['total_steps'] ?? 0,
                    'duration' => $setupProgress['completed_at'] ?? 'unknown',
                    'infrastructure' => $infrastructureCreated
                ]);
                
                return new JSONResponse([
                    'success' => true,
                    'message' => 'SOLR setup completed successfully',
                    'timestamp' => date('Y-m-d H:i:s'),
                    'mode' => 'SolrCloud',
                    'progress' => [
                        'started_at' => $setupProgress['started_at'] ?? null,
                        'completed_at' => $setupProgress['completed_at'] ?? null,
                        'total_steps' => $setupProgress['total_steps'] ?? 5,
                        'completed_steps' => $setupProgress['completed_steps'] ?? 5,
                        'success' => $setupProgress['success'] ?? true
                    ],
                    'steps' => $setupProgress['steps'] ?? [],
                    'infrastructure' => $infrastructureCreated,
                    'next_steps' => [
                        'Tenant-specific resources are ready for use',
                        'Objects can now be indexed to SOLR',
                        'Search functionality is ready for use'
                    ]
                ]);
            } else {
                // Get detailed error information and setup progress from SolrSetup
                $errorDetails = $setup->getLastErrorDetails();
                $setupProgress = $setup->getSetupProgress();
                
                if ($errorDetails) {
                    // Get infrastructure info even on failure to show partial progress
                    $infrastructureCreated = $setup->getInfrastructureCreated();
                    
                    // Use the detailed error information from SolrSetup
                    return new JSONResponse([
                        'success' => false,
                        'message' => 'SOLR setup failed',
                        'timestamp' => date('Y-m-d H:i:s'),
                        'mode' => 'SolrCloud',
                        'progress' => [
                            'started_at' => $setupProgress['started_at'] ?? null,
                            'completed_at' => $setupProgress['completed_at'] ?? null,
                            'total_steps' => $setupProgress['total_steps'] ?? 5,
                            'completed_steps' => $setupProgress['completed_steps'] ?? 0,
                            'success' => false,
                            'failed_at_step' => $errorDetails['step'] ?? 'unknown',
                            'failed_step_name' => $errorDetails['step_name'] ?? 'unknown'
                        ],
                        'steps' => $setupProgress['steps'] ?? [],
                        'infrastructure' => $infrastructureCreated,
                        'error_details' => [
                            'primary_error' => $errorDetails['error_message'] ?? 'SOLR setup operation failed',
                            'error_type' => $errorDetails['error_type'] ?? 'unknown_error',
                            'operation' => $errorDetails['operation'] ?? 'unknown_operation',
                            'step' => $errorDetails['step'] ?? 'unknown',
                            'step_name' => $errorDetails['step_name'] ?? 'unknown',
                            'url_attempted' => $errorDetails['url_attempted'] ?? 'unknown',
                            'exception_type' => $errorDetails['exception_type'] ?? 'unknown',
                            'error_category' => $errorDetails['error_category'] ?? 'unknown',
                            'solr_response' => $errorDetails['full_solr_response'] ?? null,
                            'guzzle_details' => $errorDetails['guzzle_details'] ?? [],
                            'configuration_used' => [
                                'host' => $solrSettings['host'],
                                'port' => $solrSettings['port'] ?: 'default',
                                'scheme' => $solrSettings['scheme'],
                                'path' => $solrSettings['path']
                            ]
                        ],
                        'troubleshooting_steps' => $errorDetails['troubleshooting'] ?? $errorDetails['troubleshooting_tips'] ?? [
                            'Check SOLR server connectivity',
                            'Verify SOLR configuration',
                            'Check SOLR server logs'
                        ],
                        'steps' => $setupProgress['steps'] ?? []
                    ], 422);
                } else {
                    // Fallback to generic error if no detailed error information is available
                    $lastError = error_get_last();
                    
                    return new JSONResponse([
                        'success' => false,
                        'message' => 'SOLR setup failed',
                        'timestamp' => date('Y-m-d H:i:s'),
                        'error_details' => [
                            'primary_error' => 'Setup failed but no detailed error information was captured',
                            'last_system_error' => $lastError ? $lastError['message'] : 'No system error captured',
                            'configuration_used' => [
                                'host' => $solrSettings['host'],
                                'port' => $solrSettings['port'] ?: 'default',
                                'scheme' => $solrSettings['scheme'],
                                'path' => $solrSettings['path']
                            ]
                        ],
                        'troubleshooting_steps' => [
                            'Check SOLR server logs for detailed error messages',
                            'Verify SOLR server connectivity',
                            'Check SOLR configuration'
                        ]
                    ], 422);
                }
            }
            
        } catch (\Exception $e) {
            // Get logger for error logging if not already available
            if (!isset($logger)) {
                $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
            }
            
            // **IMPROVED ERROR LOGGING**: Log detailed setup failure information
            $logger->error('❌ SOLR setup failed with exception', [
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Try to get detailed error information from SolrSetup if available
            $detailedError = null;
            if (isset($setup)) {
                try {
                    $setupProgress = $setup->getSetupProgress();
                    $lastErrorDetails = $setup->getLastErrorDetails();
                    
                    $detailedError = [
                        'setup_progress' => $setupProgress,
                        'last_error_details' => $lastErrorDetails,
                        'failed_at_step' => $setupProgress['completed_steps'] ?? 0,
                        'total_steps' => $setupProgress['total_steps'] ?? 5
                    ];
                    
                    // **IMPROVED LOGGING**: Log setup progress and error details
                    $logger->error('📋 SOLR setup failure details', $detailedError);
                    
                } catch (\Exception $progressException) {
                    $logger->warning('Failed to get setup progress details', [
                        'error' => $progressException->getMessage()
                    ]);
                }
            }
            
            return new JSONResponse([
                'success' => false,
                'message' => 'SOLR setup failed: ' . $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s'),
                'error' => [
                    'type' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'detailed_error' => $detailedError
                ]
            ], 422);
        }
    }

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
            // Get SOLR settings directly
            $solrSettings = $this->settingsService->getSolrSettings();
            
            if (!$solrSettings['enabled']) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'SOLR is disabled'
                ], 400);
            }
            
            // Create SolrSetup using GuzzleSolrService for authenticated HTTP client
            $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
            $guzzleSolrService = $this->container->get(\OCA\OpenRegister\Service\GuzzleSolrService::class);
            $setup = new \OCA\OpenRegister\Setup\SolrSetup($guzzleSolrService, $logger);
            
            // Run setup
            $result = $setup->setupSolr();
            
            if ($result) {
                return new JSONResponse([
                    'success' => true,
                    'message' => 'SOLR setup completed successfully',
                    'config' => [
                        'host' => $solrSettings['host'],
                        'port' => $solrSettings['port'],
                        'scheme' => $solrSettings['scheme']
                    ]
                ]);
            } else {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'SOLR setup failed - check logs'
                ], 422);
            }
            
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'message' => 'SOLR setup error: ' . $e->getMessage()
            ], 422);
        }
    }

    /**
     * Delete SOLR collection (DANGER: This will permanently delete all data in the collection)
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse The deletion results
     */
    public function deleteSolrCollection(): JSONResponse
    {
        try {
            $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
            
            $logger->warning('🚨 SOLR collection deletion requested', [
                'timestamp' => date('c'),
                'user_id' => $this->userId ?? 'unknown',
                'request_id' => $this->request->getId() ?? 'unknown'
            ]);
            
            // Get GuzzleSolrService
            $guzzleSolrService = $this->container->get(\OCA\OpenRegister\Service\GuzzleSolrService::class);
            
            // Get current collection name for logging
            $currentCollection = $guzzleSolrService->getActiveCollectionName();
            
            $logger->warning('🗑️ Deleting SOLR collection', [
                'collection' => $currentCollection,
                'user_id' => $this->userId ?? 'unknown'
            ]);
            
            // Delete the collection
            $result = $guzzleSolrService->deleteCollection();
            
            if ($result['success']) {
                $logger->info('✅ SOLR collection deleted successfully', [
                    'collection' => $result['collection'] ?? 'unknown',
                    'user_id' => $this->userId ?? 'unknown'
                ]);
                
                return new JSONResponse([
                    'success' => true,
                    'message' => $result['message'],
                    'collection' => $result['collection'] ?? null,
                    'tenant_id' => $result['tenant_id'] ?? null,
                    'response_time_ms' => $result['response_time_ms'] ?? null,
                    'next_steps' => [
                        'Run SOLR Setup to create a new collection',
                        'Run Warmup Index to rebuild the search index',
                        'Verify search functionality is working'
                    ]
                ]);
            } else {
                $logger->error('❌ SOLR collection deletion failed', [
                    'error' => $result['message'],
                    'error_code' => $result['error_code'] ?? 'unknown',
                    'collection' => $result['collection'] ?? 'unknown'
                ]);
                
                return new JSONResponse([
                    'success' => false,
                    'message' => $result['message'],
                    'error_code' => $result['error_code'] ?? 'unknown',
                    'collection' => $result['collection'] ?? null,
                    'solr_error' => $result['solr_error'] ?? null
                ], 422);
            }
            
        } catch (\Exception $e) {
            $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
            $logger->error('Exception during SOLR collection deletion', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return new JSONResponse([
                'success' => false,
                'message' => 'Collection deletion failed: ' . $e->getMessage(),
                'error_code' => 'EXCEPTION'
            ], 422);
        }
    }

    /**
     * Test SOLR connection with provided settings
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse The test results
     */
    public function testSolrConnection(): JSONResponse
    {
        try {
            // Phase 1: Use GuzzleSolrService directly for SOLR operations
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            $result = $guzzleSolrService->testConnection();
            
            return new JSONResponse($result);
            
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage(),
                'details' => ['exception' => $e->getMessage()]
            ], 422);
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
            // Use GuzzleSolrService to get field information
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            
            // Check if SOLR is available first
            if (!$guzzleSolrService->isAvailable()) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'SOLR is not available or not configured',
                    'details' => ['error' => 'SOLR service is not enabled or connection failed']
                ], 422);
            }

            // Get field configuration from SOLR
            $fieldsData = $guzzleSolrService->getFieldsConfiguration();
            
            // Get expected fields based on OpenRegister schemas
            $expectedFields = $this->getExpectedSchemaFields();
            
            // Compare actual vs expected fields
            $comparison = $this->compareFields(
                actualFields: $fieldsData['fields'] ?? [], 
                expectedFields: $expectedFields
            );
            
            // Add expected fields and comparison to response
            $fieldsData['expected_fields'] = $expectedFields;
            $fieldsData['comparison'] = $comparison;
            
            return new JSONResponse($fieldsData);
            
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to retrieve SOLR field configuration: ' . $e->getMessage(),
                'details' => ['error' => $e->getMessage()]
            ], 422);
        }
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
            // Get GuzzleSolrService for field creation
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            
            // Check if SOLR is available first
            if (!$guzzleSolrService->isAvailable()) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'SOLR is not available or not configured',
                    'details' => ['error' => 'SOLR service is not enabled or connection failed']
                ], 422);
            }

            // Get dry run parameter
            $dryRun = $this->request->getParam('dry_run', false);
            $dryRun = filter_var($dryRun, FILTER_VALIDATE_BOOLEAN);

            // Get expected fields using the same method as the comparison
            $expectedFields = $this->getExpectedSchemaFields();
            
            // Create missing fields
            $result = $guzzleSolrService->createMissingFields($expectedFields, $dryRun);
            
            return new JSONResponse($result);
            
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to create missing SOLR fields: ' . $e->getMessage(),
                'details' => ['error' => $e->getMessage()]
            ], 422);
        }
    }//end createMissingSolrFields()

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
            // Get GuzzleSolrService for field operations
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            
            // Check if SOLR is available first
            if (!$guzzleSolrService->isAvailable()) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'SOLR is not available or not configured',
                    'details' => ['error' => 'SOLR service is not enabled or connection failed']
                ], 422);
            }

            // Get dry run parameter
            $dryRun = $this->request->getParam('dry_run', false);
            $dryRun = filter_var($dryRun, FILTER_VALIDATE_BOOLEAN);

            // Get expected fields and current SOLR fields for comparison
            $expectedFields = $this->getExpectedSchemaFields();
            $fieldsInfo = $guzzleSolrService->getFieldsConfiguration();
            
            if (!$fieldsInfo['success']) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'Failed to get SOLR field configuration',
                    'details' => ['error' => $fieldsInfo['message'] ?? 'Unknown error']
                ], 422);
            }
            
            // Compare fields to find mismatched ones
            $comparison = $this->compareFields(
                actualFields: $fieldsInfo['fields'] ?? [], 
                expectedFields: $expectedFields
            );
            
            if (empty($comparison['mismatched'])) {
                return new JSONResponse([
                    'success' => true,
                    'message' => 'No mismatched fields found - SOLR schema is properly configured',
                    'fixed' => [],
                    'errors' => []
                ]);
            }
            
            // Prepare fields to fix from mismatched fields
            $fieldsToFix = [];
            foreach ($comparison['mismatched'] as $mismatch) {
                $fieldsToFix[$mismatch['field']] = $mismatch['expected_config'];
                
            }
            
            // Debug: Log field count for troubleshooting
            
            // Fix the mismatched fields using the dedicated method
            $result = $guzzleSolrService->fixMismatchedFields($fieldsToFix, $dryRun);
            
            // The fixMismatchedFields method already returns the correct format
            return new JSONResponse($result);
            
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to fix mismatched SOLR fields: ' . $e->getMessage(),
                'details' => ['error' => $e->getMessage()]
            ], 422);
        }
    }//end fixMismatchedSolrFields()

    /**
     * Get expected schema fields based on OpenRegister schemas
     *
     * @return array Expected field configuration
     */
    private function getExpectedSchemaFields(): array
    {
        try {
            // Start with the core ObjectEntity metadata fields from SolrSetup
            $expectedFields = \OCA\OpenRegister\Setup\SolrSetup::getObjectEntityFieldDefinitions();
            
            // Get SolrSchemaService to analyze user-defined schemas
            $solrSchemaService = $this->container->get(\OCA\OpenRegister\Service\SolrSchemaService::class);
            $schemaMapper = $this->container->get(\OCA\OpenRegister\Db\SchemaMapper::class);
            
            // Get all schemas
            $schemas = $schemaMapper->findAll();
            
            // Use the existing analyzeAndResolveFieldConflicts method via reflection
            $reflection = new \ReflectionClass($solrSchemaService);
            $method = $reflection->getMethod('analyzeAndResolveFieldConflicts');
            $method->setAccessible(true);
            
            $result = $method->invoke($solrSchemaService, $schemas);
            
            // Merge user-defined schema fields with core metadata fields
            $userSchemaFields = $result['fields'] ?? [];
            $expectedFields = array_merge($expectedFields, $userSchemaFields);
            
            return $expectedFields;
            
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get expected schema fields', [
                'error' => $e->getMessage()
            ]);
            // Return at least the core metadata fields even if schema analysis fails
            return \OCA\OpenRegister\Setup\SolrSetup::getObjectEntityFieldDefinitions();
        }
    }

    /**
     * Compare actual SOLR fields with expected schema fields
     *
     * @param array $actualFields   Current SOLR fields
     * @param array $expectedFields Expected fields from schemas
     * @return array Comparison results
     */
    private function compareFields(array $actualFields, array $expectedFields): array
    {
        $missing = [];
        $extra = [];
        $mismatched = [];
        
        // Find missing fields (expected but not in SOLR)
        foreach ($expectedFields as $fieldName => $expectedConfig) {
            if (!isset($actualFields[$fieldName])) {
                $missing[] = [
                    'field' => $fieldName,
                    'expected_type' => $expectedConfig['type'] ?? 'unknown',
                    'expected_config' => $expectedConfig
                ];
            }
        }
        
        // Find extra fields (in SOLR but not expected) and mismatched configurations
        foreach ($actualFields as $fieldName => $actualField) {
            // Skip only system fields (but allow self_* metadata fields to be checked)
            if (str_starts_with($fieldName, '_')) {
                continue;
            }
            
            if (!isset($expectedFields[$fieldName])) {
                $extra[] = [
                    'field' => $fieldName,
                    'actual_type' => $actualField['type'] ?? 'unknown',
                    'actual_config' => $actualField
                ];
            } else {
                // Check for configuration mismatches (type, multiValued, docValues)
                $expectedConfig = $expectedFields[$fieldName];
                $expectedType = $expectedConfig['type'] ?? '';
                $actualType = $actualField['type'] ?? '';
                $expectedMultiValued = $expectedConfig['multiValued'] ?? false;
                $actualMultiValued = $actualField['multiValued'] ?? false;
                $expectedDocValues = $expectedConfig['docValues'] ?? false;
                $actualDocValues = $actualField['docValues'] ?? false;
                
                // Check if any configuration differs
                if ($expectedType !== $actualType || 
                    $expectedMultiValued !== $actualMultiValued || 
                    $expectedDocValues !== $actualDocValues) {
                    
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
                        'field' => $fieldName,
                        'expected_type' => $expectedType,
                        'actual_type' => $actualType,
                        'expected_multiValued' => $expectedMultiValued,
                        'actual_multiValued' => $actualMultiValued,
                        'expected_docValues' => $expectedDocValues,
                        'actual_docValues' => $actualDocValues,
                        'differences' => $differences,
                        'expected_config' => $expectedConfig,
                        'actual_config' => $actualField
                    ];
                }
            }
        }
        
        return [
            'missing' => $missing,
            'extra' => $extra,
            'mismatched' => $mismatched,
            'summary' => [
                'missing_count' => count($missing),
                'extra_count' => count($extra),
                'mismatched_count' => count($mismatched),
                'total_differences' => count($missing) + count($extra) + count($mismatched)
            ]
        ];
    }

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
            return new JSONResponse($data);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

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
            $data = $this->request->getParams();
            $result = $this->settingsService->updateSolrSettingsOnly($data);
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

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
            return new JSONResponse($data);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

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
            $data = $this->request->getParams();
            $result = $this->settingsService->updateSolrFacetConfiguration($data);
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

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
            // Get GuzzleSolrService from container
            $guzzleSolrService = $this->container->get(\OCA\OpenRegister\Service\GuzzleSolrService::class);
            
            // Check if SOLR is available
            if (!$guzzleSolrService->isAvailable()) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'SOLR is not available or not configured',
                    'facets' => []
                ], 422);
            }

            // Get raw SOLR field information for facet configuration
            $facetableFields = $guzzleSolrService->getRawSolrFieldsForFacetConfiguration();

            return new JSONResponse([
                'success' => true,
                'message' => 'Facets discovered successfully',
                'facets' => $facetableFields
            ]);
            
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to discover facets: ' . $e->getMessage(),
                'facets' => []
            ], 422);
        }
    }

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
            // Get GuzzleSolrService from container
            $guzzleSolrService = $this->container->get(\OCA\OpenRegister\Service\GuzzleSolrService::class);
            
            // Check if SOLR is available
            if (!$guzzleSolrService->isAvailable()) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'SOLR is not available or not configured',
                    'facets' => []
                ], 422);
            }

            // Get discovered facets
            $discoveredFacets = $guzzleSolrService->getRawSolrFieldsForFacetConfiguration();
            
            // Get existing configuration
            $existingConfig = $this->settingsService->getSolrFacetConfiguration();
            $existingFacets = $existingConfig['facets'] ?? [];
            
            // Merge discovered facets with existing configuration
            $mergedFacets = [
                '@self' => [],
                'object_fields' => []
            ];
            
            // Process metadata facets
            if (isset($discoveredFacets['@self'])) {
                $index = 0;
                foreach ($discoveredFacets['@self'] as $key => $facetInfo) {
                    $fieldName = "self_{$key}";
                    $existingFacetConfig = $existingFacets[$fieldName] ?? [];
                    
                    $mergedFacets['@self'][$key] = array_merge($facetInfo, [
                        'config' => [
                            'enabled' => $existingFacetConfig['enabled'] ?? true,
                            'title' => $existingFacetConfig['title'] ?? $facetInfo['displayName'] ?? $key,
                            'description' => $existingFacetConfig['description'] ?? ($facetInfo['category'] ?? 'metadata') . " field: " . ($facetInfo['displayName'] ?? $key),
                            'order' => $existingFacetConfig['order'] ?? $index,
                            'maxItems' => $existingFacetConfig['max_items'] ?? $existingFacetConfig['maxItems'] ?? 10,
                            'facetType' => $existingFacetConfig['facet_type'] ?? $existingFacetConfig['facetType'] ?? $facetInfo['suggestedFacetType'] ?? 'terms',
                            'displayType' => $existingFacetConfig['display_type'] ?? $existingFacetConfig['displayType'] ?? ($facetInfo['suggestedDisplayTypes'][0] ?? 'select'),
                            'showCount' => $existingFacetConfig['show_count'] ?? $existingFacetConfig['showCount'] ?? true,
                        ]
                    ]);
                    $index++;
                }
            }
            
            // Process object field facets
            if (isset($discoveredFacets['object_fields'])) {
                $index = 0;
                foreach ($discoveredFacets['object_fields'] as $key => $facetInfo) {
                    $fieldName = $key;
                    $existingFacetConfig = $existingFacets[$fieldName] ?? [];
                    
                    $mergedFacets['object_fields'][$key] = array_merge($facetInfo, [
                        'config' => [
                            'enabled' => $existingFacetConfig['enabled'] ?? false,
                            'title' => $existingFacetConfig['title'] ?? $facetInfo['displayName'] ?? $key,
                            'description' => $existingFacetConfig['description'] ?? ($facetInfo['category'] ?? 'object') . " field: " . ($facetInfo['displayName'] ?? $key),
                            'order' => $existingFacetConfig['order'] ?? (100 + $index),
                            'maxItems' => $existingFacetConfig['max_items'] ?? $existingFacetConfig['maxItems'] ?? 10,
                            'facetType' => $existingFacetConfig['facet_type'] ?? $existingFacetConfig['facetType'] ?? $facetInfo['suggestedFacetType'] ?? 'terms',
                            'displayType' => $existingFacetConfig['display_type'] ?? $existingFacetConfig['displayType'] ?? ($facetInfo['suggestedDisplayTypes'][0] ?? 'select'),
                            'showCount' => $existingFacetConfig['show_count'] ?? $existingFacetConfig['showCount'] ?? true,
                        ]
                    ]);
                    $index++;
                }
            }
            
            return new JSONResponse([
                'success' => true,
                'message' => 'Facets discovered and configured successfully',
                'facets' => $mergedFacets,
                'global_settings' => $existingConfig['default_settings'] ?? [
                    'show_count' => true,
                    'show_empty' => false,
                    'max_items' => 10
                ]
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to get facet configuration: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }

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
            $data = $this->request->getParams();
            $result = $this->settingsService->updateSolrFacetConfiguration($data);
            
            return new JSONResponse([
                'success' => true,
                'message' => 'Facet configuration updated successfully',
                'config' => $result
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to update facet configuration: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }

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
            // Get request parameters from JSON body or query parameters
            $maxObjects = $this->request->getParam('maxObjects', 0);
            $batchSize = $this->request->getParam('batchSize', 1000);
            $mode = $this->request->getParam('mode', 'serial'); // New mode parameter
            $collectErrors = $this->request->getParam('collectErrors', false); // New error collection parameter
            
            // Try to get from JSON body if not in query params
            if ($maxObjects === 0) {
                $input = file_get_contents('php://input');
                if ($input) {
                    $data = json_decode($input, true);
                    if ($data) {
                        $maxObjects = $data['maxObjects'] ?? 0;
                        $batchSize = $data['batchSize'] ?? 1000;
                        $mode = $data['mode'] ?? 'serial';
                        $collectErrors = $data['collectErrors'] ?? false;
                    }
                }
            }
            
            // Convert string boolean to actual boolean
            if (is_string($collectErrors)) {
                $collectErrors = filter_var($collectErrors, FILTER_VALIDATE_BOOLEAN);
            }
            
            // Validate mode parameter
            if (!in_array($mode, ['serial', 'parallel', 'hyper'])) {
                return new JSONResponse([
                    'error' => 'Invalid mode parameter. Must be "serial", "parallel", or "hyper"'
                ], 400);
            }
            
            // Phase 1: Use GuzzleSolrService directly for SOLR operations
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            $result = $guzzleSolrService->warmupIndex([], $maxObjects, $mode, $collectErrors, $batchSize);
            return new JSONResponse($result);
        } catch (\Exception $e) {
            // **ERROR VISIBILITY**: Let exceptions bubble up with full details
            return new JSONResponse([
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

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
            // Phase 1: Use GuzzleSolrService directly for SOLR operations
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            $stats = $guzzleSolrService->getDashboardStats();
            return new JSONResponse($stats);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

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
            // Phase 1: Use GuzzleSolrService directly for SOLR operations
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            
            switch ($operation) {
                case 'commit':
                    $success = $guzzleSolrService->commit();
                    return new JSONResponse([
                        'success' => $success,
                        'operation' => 'commit',
                        'message' => $success ? 'Index committed successfully' : 'Commit failed',
                        'timestamp' => date('c')
                    ]);
                    
                case 'optimize':
                    $success = $guzzleSolrService->optimize();
                    return new JSONResponse([
                        'success' => $success,
                        'operation' => 'optimize',
                        'message' => $success ? 'Index optimized successfully' : 'Optimization failed',
                        'timestamp' => date('c')
                    ]);
                    
                case 'clear':
                    $result = $guzzleSolrService->clearIndex();
                    return new JSONResponse([
                        'success' => $result['success'],
                        'operation' => 'clear',
                        'error' => $result['error'] ?? null,
                        'error_details' => $result['error_details'] ?? null,
                        'message' => $result['success'] ? 'Index cleared successfully' : 'Clear operation failed',
                        'timestamp' => date('c')
                    ]);
                    
                default:
                    return new JSONResponse([
                        'success' => false,
                        'message' => 'Unknown operation: ' . $operation
                    ], 400);
            }
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

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
            return new JSONResponse($data);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

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
            $data = $this->request->getParams();
            $result = $this->settingsService->updateRbacSettingsOnly($data);
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

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
            return new JSONResponse($data);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

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
            $data = $this->request->getParams();
            $result = $this->settingsService->updateMultitenancySettingsOnly($data);
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

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
            return new JSONResponse($data);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

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
            $data = $this->request->getParams();
            $result = $this->settingsService->updateRetentionSettingsOnly($data);
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

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
            return new JSONResponse($data);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

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
            $solrService = $this->solrServiceFactory->createService();
            
            // Get required dependencies from container
            $objectMapper = $this->container->get(\OCA\OpenRegister\Db\ObjectEntityMapper::class);
            $schemaMapper = $this->container->get(\OCA\OpenRegister\Db\SchemaMapper::class);
            
            // Run the test
            $results = $solrService->testSchemaAwareMapping($objectMapper, $schemaMapper);
            
            return new JSONResponse($results);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Clear SOLR index
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * 
     * @return JSONResponse
     */
    public function clearSolrIndex(): JSONResponse
    {
        try {
            // Get logger and GuzzleSolrService from container
            $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            
            $logger->info('Starting SOLR index clear operation');
            
            // Use the GuzzleSolrService to clear the index - now returns detailed result array
            $result = $guzzleSolrService->clearIndex();
            
            if ($result['success']) {
                $logger->info('SOLR index cleared successfully', [
                    'deleted_docs' => $result['deleted_docs'] ?? 'unknown'
                ]);
                return new JSONResponse([
                    'success' => true,
                    'message' => 'SOLR index cleared successfully',
                    'deleted_docs' => $result['deleted_docs'] ?? null
                ]);
            } else {
                // Log detailed error information for debugging
                $logger->error('Failed to clear SOLR index', [
                    'error' => $result['error'],
                    'error_details' => $result['error_details'] ?? null
                ]);
                
                return new JSONResponse([
                    'success' => false,
                    'error' => $result['error'],
                    'error_details' => $result['error_details'] ?? null
                ], 422);
            }
            
        } catch (\Exception $e) {
            // Get logger for error logging
            $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
            $logger->error('Exception in clearSolrIndex controller', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return new JSONResponse([
                'success' => false,
                'error' => 'Controller exception: ' . $e->getMessage(),
                'error_details' => [
                    'exception_type' => get_class($e),
                    'trace' => $e->getTraceAsString()
                ]
            ], 500);
        }
    }

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
            $query = $this->request->getParam('query', '*:*');
            $start = (int)$this->request->getParam('start', 0);
            $rows = (int)$this->request->getParam('rows', 20);
            $fields = $this->request->getParam('fields', '');
            
            // Validate parameters
            $rows = min(max($rows, 1), 100); // Limit between 1 and 100
            $start = max($start, 0);
            
            // Get GuzzleSolrService from container
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            
            // Search documents in SOLR
            $result = $guzzleSolrService->inspectIndex($query, $start, $rows, $fields);
            
            if ($result['success']) {
                return new JSONResponse([
                    'success' => true,
                    'documents' => $result['documents'],
                    'total' => $result['total'],
                    'start' => $start,
                    'rows' => $rows,
                    'query' => $query
                ]);
            } else {
                return new JSONResponse([
                    'success' => false,
                    'error' => $result['error'],
                    'error_details' => $result['error_details'] ?? null
                ], 422);
            }
            
        } catch (\Exception $e) {
            $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
            $logger->error('Exception in inspectSolrIndex controller', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return new JSONResponse([
                'success' => false,
                'error' => 'Controller exception: ' . $e->getMessage(),
                'error_details' => [
                    'exception_type' => get_class($e),
                    'trace' => $e->getTraceAsString()
                ]
            ], 500);
        }
    }


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
            // Get request parameters
            $maxObjects = (int) $this->request->getParam('maxObjects', 0);
            
            // Get GuzzleSolrService for prediction
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            
            if (!$guzzleSolrService->isAvailable()) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'SOLR is not available or not configured',
                    'prediction' => [
                        'error' => 'SOLR service unavailable',
                    'prediction_safe' => false
                ]
            ], 422);
            }

            // Use reflection to call the private method (for API access)
            $reflection = new \ReflectionClass($guzzleSolrService);
            $method = $reflection->getMethod('predictWarmupMemoryUsage');
            $method->setAccessible(true);
            $prediction = $method->invoke($guzzleSolrService, $maxObjects);

            return new JSONResponse([
                'success' => true,
                'message' => 'Memory prediction calculated successfully',
                'prediction' => $prediction
            ]);

        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to calculate memory prediction: ' . $e->getMessage(),
                'prediction' => [
                    'error' => $e->getMessage(),
                    'prediction_safe' => false
                ]
            ], 422);
        }
    }

    /**
     * Delete a SOLR field
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $fieldName Name of the field to delete
     * @return JSONResponse
     */
    public function deleteSolrField(string $fieldName): JSONResponse
    {
        try {
            $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
            $logger->info('🗑️ Deleting SOLR field via API', [
                'field_name' => $fieldName,
                'user' => $this->userId
            ]);

            // Validate field name
            if (empty($fieldName) || !is_string($fieldName)) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'Invalid field name provided'
                ], 400);
            }

            // Prevent deletion of critical system fields
            $protectedFields = ['id', '_version_', '_root_', '_text_'];
            if (in_array($fieldName, $protectedFields)) {
                return new JSONResponse([
                    'success' => false,
                    'message' => "Cannot delete protected system field: {$fieldName}"
                ], 403);
            }

            // Get GuzzleSolrService from container
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            $result = $guzzleSolrService->deleteField($fieldName);

            if ($result['success']) {
                $logger->info('✅ SOLR field deleted successfully via API', [
                    'field_name' => $fieldName,
                    'user' => $this->userId
                ]);

                return new JSONResponse([
                    'success' => true,
                    'message' => $result['message'],
                    'field_name' => $fieldName
                ]);
            } else {
                $logger->warning('❌ Failed to delete SOLR field via API', [
                    'field_name' => $fieldName,
                    'error' => $result['message'],
                    'user' => $this->userId
                ]);

                return new JSONResponse([
                    'success' => false,
                    'message' => $result['message'],
                    'error' => $result['error'] ?? null
                ], 422);
            }

        } catch (\Exception $e) {
            $logger = $logger ?? \OC::$server->get(\Psr\Log\LoggerInterface::class);
            $logger->error('Exception deleting SOLR field via API', [
                'field_name' => $fieldName,
                'error' => $e->getMessage(),
                'user' => $this->userId,
                'trace' => $e->getTraceAsString()
            ]);

            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to delete SOLR field: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reindex all objects in SOLR
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse
     */
    public function reindexSolr(): JSONResponse
    {
        try {
            $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
            
            // Get parameters from request
            $maxObjects = (int) ($this->request->getParam('maxObjects', 0));
            $batchSize = (int) ($this->request->getParam('batchSize', 1000));
            
            // Validate parameters
            if ($batchSize < 1 || $batchSize > 5000) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'Invalid batch size. Must be between 1 and 5000'
                ], 400);
            }
            
            if ($maxObjects < 0) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'Invalid maxObjects. Must be 0 (all) or positive number'
                ], 400);
            }

            $logger->info('🔄 Starting SOLR reindex via API', [
                'max_objects' => $maxObjects,
                'batch_size' => $batchSize,
                'user' => $this->userId
            ]);

            // Get GuzzleSolrService from container
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            
            // Check if SOLR is available
            if (!$guzzleSolrService->isAvailable()) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'SOLR is not available or not configured'
                ], 422);
            }

            // Start reindex operation
            $result = $guzzleSolrService->reindexAll($maxObjects, $batchSize);

            if ($result['success']) {
                $logger->info('✅ SOLR reindex completed successfully via API', [
                    'processed_objects' => $result['stats']['processed_objects'] ?? 0,
                    'duration' => $result['stats']['duration_seconds'] ?? 0,
                    'user' => $this->userId
                ]);

                return new JSONResponse([
                    'success' => true,
                    'message' => $result['message'],
                    'stats' => $result['stats'] ?? []
                ]);
            } else {
                $logger->warning('❌ SOLR reindex failed via API', [
                    'error' => $result['message'],
                    'user' => $this->userId
                ]);

                return new JSONResponse([
                    'success' => false,
                    'message' => $result['message'],
                    'error' => $result['error'] ?? null
                ], 422);
            }

        } catch (\Exception $e) {
            $logger = $logger ?? \OC::$server->get(\Psr\Log\LoggerInterface::class);
            $logger->error('Exception during SOLR reindex via API', [
                'error' => $e->getMessage(),
                'user' => $this->userId,
                'trace' => $e->getTraceAsString()
            ]);

            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to reindex SOLR: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }

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
            // Get services
            $objectService = $this->container->get(\OCA\OpenRegister\Service\ObjectService::class);
            $connection = $this->container->get(\OCP\IDBConnection::class);

            // Set register and schema context
            $objectService->setRegister('voorzieningen');
            $objectService->setSchema('organisatie');

            $results = [];

            // Test 1: Get all organizations
            $query1 = [
                '_limit' => 10,
                '_page' => 1,
                '_source' => 'database'
            ];
            $result1 = $objectService->searchObjectsPaginated($query1);
            $results['all_organizations'] = [
                'count' => count($result1['results']),
                'organizations' => array_map(function($org) {
                    $objectData = $org->getObject();
                    return [
                        'id' => $org->getId(),
                        'name' => $org->getName(),
                        'type' => $objectData['type'] ?? 'NO TYPE',
                        'object_data' => $objectData
                    ];
                }, $result1['results'])
            ];

            // Test 2: Try type filtering with samenwerking
            $query2 = [
                '_limit' => 10,
                '_page' => 1,
                '_source' => 'database',
                'type' => ['samenwerking']
            ];
            $result2 = $objectService->searchObjectsPaginated($query2);
            $results['type_samenwerking'] = [
                'count' => count($result2['results']),
                'organizations' => array_map(function($org) {
                    $objectData = $org->getObject();
                    return [
                        'id' => $org->getId(),
                        'name' => $org->getName(),
                        'type' => $objectData['type'] ?? 'NO TYPE'
                    ];
                }, $result2['results'])
            ];

            // Test 3: Try type filtering with community
            $query3 = [
                '_limit' => 10,
                '_page' => 1,
                '_source' => 'database',
                'type' => ['community']
            ];
            $result3 = $objectService->searchObjectsPaginated($query3);
            $results['type_community'] = [
                'count' => count($result3['results']),
                'organizations' => array_map(function($org) {
                    $objectData = $org->getObject();
                    return [
                        'id' => $org->getId(),
                        'name' => $org->getName(),
                        'type' => $objectData['type'] ?? 'NO TYPE'
                    ];
                }, $result3['results'])
            ];

            // Test 4: Try type filtering with both types
            $query4 = [
                '_limit' => 10,
                '_page' => 1,
                '_source' => 'database',
                'type' => ['samenwerking', 'community']
            ];
            $result4 = $objectService->searchObjectsPaginated($query4);
            $results['type_both'] = [
                'count' => count($result4['results']),
                'organizations' => array_map(function($org) {
                    $objectData = $org->getObject();
                    return [
                        'id' => $org->getId(),
                        'name' => $org->getName(),
                        'type' => $objectData['type'] ?? 'NO TYPE'
                    ];
                }, $result4['results'])
            ];

            // Test 5: Direct database query to check type field
            $qb = $connection->getQueryBuilder();
            $qb->select('o.id', 'o.name', 'o.object')
               ->from('openregister_objects', 'o')
               ->where($qb->expr()->like('o.name', $qb->createNamedParameter('%Samenwerking%')))
               ->orWhere($qb->expr()->like('o.name', $qb->createNamedParameter('%Community%')));

            $stmt = $qb->executeQuery();
            $rows = $stmt->fetchAllAssociative();

            $results['direct_database_query'] = [
                'count' => count($rows),
                'organizations' => array_map(function($row) {
                    $objectData = json_decode($row['object'], true);
                    return [
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'type' => $objectData['type'] ?? 'NO TYPE',
                        'object_json' => $row['object']
                    ];
                }, $rows)
            ];

            return new JSONResponse($results);

        } catch (\Exception $e) {
            return new JSONResponse([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

}//end class
