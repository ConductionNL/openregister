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
            return new JSONResponse(['error' => $e->getMessage()], 500);
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
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }

    }//end warmupNamesCache()


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
            // Get SOLR settings
            $solrSettings = $this->settingsService->getSolrSettings();
            
            // Create SolrSetup using GuzzleSolrService for authenticated HTTP client
            $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
            $guzzleSolrService = $this->container->get(\OCA\OpenRegister\Service\GuzzleSolrService::class);
            $setup = new \OCA\OpenRegister\Setup\SolrSetup($guzzleSolrService, $logger);
            
            // Run setup
            $setupResult = $setup->setupSolr();
            
            if ($setupResult) {
                // Return detailed setup results
                return new JSONResponse([
                    'success' => true,
                    'message' => 'SOLR setup completed successfully',
                    'timestamp' => date('Y-m-d H:i:s'),
                    'mode' => 'SolrCloud',
                    'steps' => [
                        [
                            'step' => 1,
                            'name' => 'SOLR Connectivity',
                            'description' => 'Verified SOLR server connectivity and version',
                            'status' => 'completed',
                            'details' => [
                                'host' => $solrSettings['host'],
                                'port' => $solrSettings['port'],
                                'scheme' => $solrSettings['scheme'],
                                'path' => $solrSettings['path']
                            ]
                        ],
                        [
                            'step' => 2,
                            'name' => 'ConfigSet Creation',
                            'description' => 'Created OpenRegister configSet from default template',
                            'status' => 'completed',
                            'details' => [
                                'configset_name' => 'openregister',
                                'template' => '_default',
                                'purpose' => 'Template for tenant collections'
                            ]
                        ],
                        [
                            'step' => 3,
                            'name' => 'Base Collection',
                            'description' => 'Created base collection for OpenRegister',
                            'status' => 'completed',
                            'details' => [
                                'collection_name' => 'openregister',
                                'configset' => 'openregister',
                                'shards' => 1,
                                'replicas' => 1
                            ]
                        ],
                        [
                            'step' => 4,
                            'name' => 'Schema Configuration',
                            'description' => 'Configured 22 ObjectEntity metadata fields',
                            'status' => 'completed',
                            'details' => [
                                'fields_configured' => 22,
                                'field_types' => ['text', 'string', 'boolean', 'date', 'int'],
                                'purpose' => 'OpenRegister object metadata indexing'
                            ]
                        ],
                        [
                            'step' => 5,
                            'name' => 'Setup Validation',
                            'description' => 'Validated complete SOLR setup configuration',
                            'status' => 'completed',
                            'details' => [
                                'configset_verified' => true,
                                'collection_verified' => true,
                                'schema_verified' => true
                            ]
                        ]
                    ],
                    'infrastructure' => [
                        'configsets_created' => ['openregister'],
                        'collections_created' => ['openregister'],
                        'schema_fields' => 22,
                        'multi_tenant_ready' => true,
                        'cloud_mode' => true
                    ],
                    'next_steps' => [
                        'Tenant collections will be created automatically',
                        'Objects can now be indexed to SOLR',
                        'Search functionality is ready for use'
                    ]
                ]);
            } else {
                // Get detailed error information from SolrSetup
                $errorDetails = $setup->getLastErrorDetails();
                
                if ($errorDetails) {
                    // Use the detailed error information from SolrSetup
                    return new JSONResponse([
                        'success' => false,
                        'message' => 'SOLR setup failed',
                        'timestamp' => date('Y-m-d H:i:s'),
                        'error_details' => [
                            'primary_error' => $errorDetails['error_message'] ?? 'SOLR setup operation failed',
                            'error_type' => $errorDetails['error_type'] ?? 'unknown_error',
                            'operation' => $errorDetails['operation'] ?? 'unknown_operation',
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
                        'troubleshooting_steps' => $errorDetails['troubleshooting_tips'] ?? [
                            'Check SOLR server connectivity',
                            'Verify SOLR configuration',
                            'Check SOLR server logs'
                        ],
                        'steps' => [
                            [
                                'step' => 1,
                                'name' => ucfirst($errorDetails['operation'] ?? 'Setup Operation'),
                                'description' => $errorDetails['error_message'] ?? 'Setup operation failed',
                                'status' => 'failed',
                                'details' => [
                                    'error_type' => $errorDetails['error_type'] ?? 'unknown',
                                    'url_attempted' => $errorDetails['url_attempted'] ?? 'unknown',
                                    'actual_error' => $errorDetails['error_message'] ?? 'No error message available',
                                    'guzzle_response_status' => $errorDetails['guzzle_details']['response_status'] ?? null,
                                    'guzzle_response_body' => $errorDetails['guzzle_details']['response_body'] ?? null,
                                    'solr_error_code' => $errorDetails['solr_error_code'] ?? null,
                                    'solr_error_details' => $errorDetails['solr_error_details'] ?? null
                                ]
                            ]
                        ]
                    ], 500);
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
                    ], 500);
                }
            }
            
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'message' => 'SOLR setup failed: ' . $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s'),
                'error' => [
                    'type' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ], 500);
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
                ], 500);
            }
            
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'message' => 'SOLR setup error: ' . $e->getMessage()
            ], 500);
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
            ], 500);
        }

    }//end testSolrConnection()


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
            if (!in_array($mode, ['serial', 'parallel'])) {
                return new JSONResponse([
                    'error' => 'Invalid mode parameter. Must be "serial" or "parallel"'
                ], 400);
            }
            
            // Phase 1: Use GuzzleSolrService directly for SOLR operations
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            $result = $guzzleSolrService->warmupIndex([], $maxObjects, $mode, $collectErrors);
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
                    $success = $guzzleSolrService->clearIndex();
                    return new JSONResponse([
                        'success' => $success,
                        'operation' => 'clear',
                        'message' => $success ? 'Index cleared successfully' : 'Clear operation failed',
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
            ], 500);
        }
    }


}//end class
