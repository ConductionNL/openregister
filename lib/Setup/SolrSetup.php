<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2024 OpenRegister
 *
 * @author OpenRegister Team
 *
 * @license AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\OpenRegister\Setup;

use Psr\Log\LoggerInterface;
use GuzzleHttp\Client as GuzzleClient;
use OCA\OpenRegister\Service\GuzzleSolrService;

/**
 * SOLR Setup and Configuration Manager
 *
 * Handles initial SOLR setup, configSet creation, and core management
 * for the multi-tenant OpenRegister architecture.
 * 
 * This class ensures that SOLR is properly configured with the necessary
 * configSets to support dynamic tenant core creation.
 *
 * @package OCA\OpenRegister\Setup
 * @category Setup
 * @author OpenRegister Team
 * @copyright 2024 OpenRegister
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 * @link https://github.com/OpenRegister/OpenRegister
 */
class SolrSetup
{

    /**
     * @var LoggerInterface PSR-3 compliant logger for operation tracking
     */
    private LoggerInterface $logger;

    /**
     * @var array{host: string, port: int, scheme: string, path: string, username?: string, password?: string} SOLR connection configuration
     */
    private array $solrConfig;

    /**
     * @var GuzzleClient HTTP client for SOLR requests (from GuzzleSolrService)
     */
    private GuzzleClient $httpClient;

    /**
     * @var GuzzleSolrService SOLR service for authenticated HTTP client and configuration
     */
    private GuzzleSolrService $solrService;

    /**
     * @var array|null Detailed error information from the last failed operation
     */
    private ?array $lastErrorDetails = null;

    /**
     * @var array Track infrastructure resources created/skipped during setup
     */
    private array $infrastructureCreated = [
        'configsets_created' => [],
        'configsets_skipped' => [],
        'collections_created' => [],
        'collections_skipped' => [],
        'schema_fields_configured' => false,
        'multi_tenant_ready' => false,
        'cloud_mode' => false
    ];

    /**
     * @var array Setup progress tracking with detailed step information
     */
    private array $setupProgress = [];

    /**
     * Initialize SOLR setup manager
     *
     * @param GuzzleSolrService $solrService SOLR service with authenticated HTTP client and configuration
     * @param LoggerInterface $logger PSR-3 compliant logger for operation tracking
     */
    public function __construct(GuzzleSolrService $solrService, LoggerInterface $logger)
    {
        $this->solrService = $solrService;
        $this->logger = $logger;
        
        // Get authenticated HTTP client and configuration from GuzzleSolrService
        $this->httpClient = $solrService->getHttpClient();
        $this->solrConfig = $solrService->getSolrConfig();
        
        $this->logger->info('SOLR Setup: Using authenticated HTTP client from GuzzleSolrService', [
            'has_credentials' => !empty($this->solrConfig['username']) && !empty($this->solrConfig['password']),
            'username' => $this->solrConfig['username'] ?? 'not_set',
            'password_set' => !empty($this->solrConfig['password']),
            'host' => $this->solrConfig['host'] ?? 'unknown',
            'port' => $this->solrConfig['port'] ?? 'not_set',
            'scheme' => $this->solrConfig['scheme'] ?? 'not_set',
            'path' => $this->solrConfig['path'] ?? 'not_set'
        ]);
    }

    /**
     * Get detailed error information from the last failed operation
     *
     * @return array|null Error details or null if no error occurred
     */
    public function getLastErrorDetails(): ?array
    {
        return $this->lastErrorDetails;
    }

    /**
     * Get infrastructure resources created during setup
     *
     * @return array Summary of created resources and configuration status
     */
    public function getInfrastructureCreated(): array
    {
        return $this->infrastructureCreated;
    }

    /**
     * Get detailed setup progress information
     *
     * @return array Setup progress with step details
     */
    public function getSetupProgress(): array
    {
        return $this->setupProgress;
    }

    /**
     * Track a setup step with detailed information
     *
     * @param int $stepNumber Step number (1-5)
     * @param string $stepName Human-readable step name
     * @param string $status Step status (started, completed, failed)
     * @param string $description Step description
     * @param array $details Additional step details
     */
    private function trackStep(int $stepNumber, string $stepName, string $status, string $description, array $details = []): void
    {
        $stepData = [
            'step_number' => $stepNumber,
            'step_name' => $stepName,
            'status' => $status,
            'description' => $description,
            'timestamp' => date('Y-m-d H:i:s'),
            'details' => $details
        ];

        // Update or add the step
        $found = false;
        foreach ($this->setupProgress['steps'] as &$step) {
            if ($step['step_number'] === $stepNumber) {
                $step = array_merge($step, $stepData);
                $found = true;
                break;
            }
        }

        if (!$found) {
            $this->setupProgress['steps'][] = $stepData;
        }

        $this->logger->info("Setup Step {$stepNumber}/{$stepName}: {$status} - {$description}", $details);
    }

    /**
     * Build SOLR URL using GuzzleSolrService base URL method for consistency
     *
     * @param string $path The SOLR API path (e.g., '/admin/info/system')
     * @return string Complete SOLR URL
     */
    private function buildSolrUrl(string $path): string
    {
        // Use GuzzleSolrService's buildSolrBaseUrl method for consistency
        // This ensures URL building logic is centralized and consistent
        $baseUrl = $this->solrService->buildSolrBaseUrl();
        return $baseUrl . $path;
    }

    /**
     * Initialize all setup steps as pending to show complete progress view
     * 
     * This ensures that users can see all steps in the setup modal,
     * including ones that haven't been reached yet due to earlier failures.
     */
    private function initializeAllSteps(): void
    {
        $allSteps = [
            1 => ['step_name' => 'SOLR Connectivity', 'description' => 'Verify SOLR server connectivity and authentication'],
            2 => ['step_name' => 'EnsureTenantConfigSet', 'description' => 'Create or verify tenant-specific configSet'],
            3 => ['step_name' => 'Collection Creation', 'description' => 'Create or verify tenant-specific collection'],
            4 => ['step_name' => 'Schema Configuration', 'description' => 'Configure schema fields for ObjectEntity metadata'],
            5 => ['step_name' => 'Setup Validation', 'description' => 'Validate complete SOLR setup and functionality']
        ];
        
        foreach ($allSteps as $stepNumber => $stepInfo) {
            $this->setupProgress['steps'][] = [
                'step_number' => $stepNumber,
                'step_name' => $stepInfo['step_name'],
                'status' => 'pending',
                'description' => $stepInfo['description'],
                'timestamp' => null,
                'details' => []
            ];
        }
    }

    /**
     * Get tenant-specific collection name using GuzzleSolrService
     *
     * @return string Tenant-specific collection name (e.g., "openregister_nc_f0e53393")
     */
    private function getTenantCollectionName(): string
    {
        $baseCollectionName = $this->solrConfig['core'] ?? 'openregister';
        return $this->solrService->getTenantSpecificCollectionName($baseCollectionName);
    }

    /**
     * Get tenant ID from GuzzleSolrService
     *
     * @return string Tenant identifier (e.g., "nc_f0e53393")
     */
    private function getTenantId(): string
    {
        return $this->solrService->getTenantId();
    }

    /**
     * Get tenant-specific configSet name
     *
     * @return string Tenant-specific configSet name (e.g., "openregister_nc_f0e53393")
     */
    private function getTenantConfigSetName(): string
    {
        $baseConfigSetName = $this->solrConfig['core'] ?? 'openregister';
        return $baseConfigSetName . '_' . $this->getTenantId();
    }

    /**
     * Run complete SOLR setup for OpenRegister multi-tenant architecture
     *
     * Performs all necessary setup operations for SolrCloud:
     * 1. Verifies SOLR connectivity
     * 2. Creates base configSet if missing
     * 3. Creates base collection for template
     * 4. Configures schema fields
     * 5. Validates setup completion
     *
     * Note: This works with SolrCloud mode with ZooKeeper coordination
     *
     * @return bool True if setup completed successfully, false otherwise
     * @throws \RuntimeException If critical setup operations fail
     */
    public function setupSolr(): bool
    {
        $this->logger->info('Starting SOLR setup for OpenRegister multi-tenant architecture (SolrCloud mode)');
        
        // Initialize setup progress tracking
        $this->setupProgress = [
            'started_at' => date('Y-m-d H:i:s'),
            'completed_at' => null,
            'total_steps' => 5,
            'completed_steps' => 0,
            'success' => false,
            'steps' => []
        ];
        
        // Initialize all steps as pending to show complete progress
        $this->initializeAllSteps();

        try {
            // Step 1: Verify SOLR connectivity
            $this->trackStep(1, 'SOLR Connectivity', 'started', 'Verifying SOLR server connectivity and authentication');
            
            try {
                if (!$this->verifySolrConnectivity()) {
                    $this->trackStep(1, 'SOLR Connectivity', 'failed', 'Cannot connect to SOLR server', [
                        'error' => 'SOLR connectivity test failed',
                        'host' => $this->solrConfig['host'] ?? 'unknown',
                        'port' => $this->solrConfig['port'] ?? 'unknown',
                        'url_tested' => $this->buildSolrUrl('/admin/info/system?wt=json')
                    ]);
                    
                    $this->lastErrorDetails = [
                        'operation' => 'verifySolrConnectivity',
                        'step' => 1,
                        'step_name' => 'SOLR Connectivity',
                        'error_type' => 'connectivity_failure',
                        'error_message' => 'Cannot connect to SOLR server',
                        'configuration' => $this->solrConfig,
                        'troubleshooting' => [
                            'Check if SOLR server is running',
                            'Verify host/port configuration',
                            'Check network connectivity',
                            'Verify authentication credentials if required'
                        ]
                    ];
                    return false;
                }
                
                $this->trackStep(1, 'SOLR Connectivity', 'completed', 'SOLR server connectivity verified');
                $this->setupProgress['completed_steps']++;
            } catch (\Exception $e) {
                $this->trackStep(1, 'SOLR Connectivity', 'failed', $e->getMessage(), [
                    'exception_type' => get_class($e),
                    'exception_message' => $e->getMessage()
                ]);
                
                $this->lastErrorDetails = [
                    'operation' => 'verifySolrConnectivity',
                    'step' => 1,
                    'step_name' => 'SOLR Connectivity',
                    'error_type' => 'connectivity_exception',
                    'error_message' => $e->getMessage(),
                    'exception_type' => get_class($e),
                    'configuration' => $this->solrConfig
                ];
                return false;
            }

            // Step 2: Ensure tenant configSet exists
            $tenantConfigSetName = $this->getTenantConfigSetName();
            $this->trackStep(2, 'EnsureTenantConfigSet', 'started', 'Checking and creating tenant configSet "' . $tenantConfigSetName . '"');
            
            try {
                if (!$this->ensureTenantConfigSet()) {
                    // Use detailed error information from createConfigSet if available
                    $errorDetails = $this->lastErrorDetails ?? [];
                    
                    $this->trackStep(2, 'EnsureTenantConfigSet', 'failed', 'Failed to create tenant configSet "' . $tenantConfigSetName . '"', [
                        'configSet' => $tenantConfigSetName,
                        'template' => '_default',
                        'error_type' => $errorDetails['error_type'] ?? 'configset_creation_failure',
                        'url_attempted' => $errorDetails['url_attempted'] ?? 'unknown',
                        'actual_error' => $errorDetails['error_message'] ?? 'Failed to create tenant configSet "' . $tenantConfigSetName . '"',
                        'guzzle_response_status' => $errorDetails['guzzle_response_status'] ?? null,
                        'guzzle_response_body' => $errorDetails['guzzle_response_body'] ?? null,
                        'solr_error_code' => $errorDetails['solr_error_code'] ?? null,
                        'solr_error_details' => $errorDetails['solr_error_details'] ?? null
                    ]);
                    
                    // Enhanced error details for configSet failure
                    if ($this->lastErrorDetails === null) {
                        $this->lastErrorDetails = [
                            'operation' => 'ensureTenantConfigSet',
                            'step' => 2,
                            'step_name' => 'ConfigSet Creation',
                            'error_type' => 'configset_creation_failure',
                            'error_message' => 'Failed to create tenant configSet "' . $tenantConfigSetName . '"',
                            'configSet' => $tenantConfigSetName,
                            'template' => '_default',
                            'troubleshooting' => [
                                'Check if SOLR server has write permissions for config directory',
                                'Verify template configSet "_default" exists in SOLR',
                                'Ensure SOLR is running in SolrCloud mode',
                                'Check ZooKeeper connectivity in SolrCloud setup',
                                'Check SOLR admin UI for existing configSets'
                            ]
                        ];
                    }
                    return false;
                }
                
                $this->trackStep(2, 'EnsureTenantConfigSet', 'completed', 'Tenant configSet "' . $tenantConfigSetName . '" is available');
                $this->setupProgress['completed_steps']++;
            } catch (\Exception $e) {
                $this->trackStep(2, 'EnsureTenantConfigSet', 'failed', $e->getMessage(), [
                    'exception_type' => get_class($e),
                    'configSet' => $tenantConfigSetName
                ]);
                
                $this->lastErrorDetails = [
                    'operation' => 'ensureTenantConfigSet',
                    'step' => 2,
                    'step_name' => 'ConfigSet Creation',
                    'error_type' => 'configset_exception',
                    'error_message' => $e->getMessage(),
                    'exception_type' => get_class($e),
                    'configSet' => $tenantConfigSetName
                ];
                return false;
            }

            // Step 3: Ensure tenant collection exists
            $tenantCollectionName = $this->getTenantCollectionName();
            $this->trackStep(3, 'Collection Creation', 'started', 'Checking and creating tenant collection "' . $tenantCollectionName . '"');
            
            try {
                // Ensure tenant collection exists (using tenant-specific configSet)
                if (!$this->ensureTenantCollectionExists()) {
                    $tenantConfigSetName = $this->getTenantConfigSetName();
                    $this->trackStep(3, 'Collection Creation', 'failed', 'Failed to create tenant collection', [
                        'collection' => $tenantCollectionName,
                        'configSet' => $tenantConfigSetName,
                        'error_details' => $this->lastErrorDetails
                    ]);
                    
                    // Enhanced error details for collection failure
                    if ($this->lastErrorDetails === null) {
                        $this->lastErrorDetails = [
                            'primary_error' => 'Failed to create tenant collection "' . $tenantCollectionName . '"',
                            'error_type' => 'collection_creation_failure',
                            'operation' => 'ensureTenantCollectionExists',
                            'step' => 3,
                            'step_name' => 'Collection Creation',
                            'url_attempted' => 'unknown',
                            'exception_type' => 'unknown',
                            'error_category' => 'unknown',
                            'solr_response' => null,
                            'guzzle_details' => [],
                            'configuration_used' => [
                                'host' => $this->solrConfig['host'] ?? 'unknown',
                                'port' => $this->solrConfig['port'] ?? 'default',
                                'scheme' => $this->solrConfig['scheme'] ?? 'http',
                                'path' => $this->solrConfig['path'] ?? '/solr'
                            ]
                        ];
                    }
                    return false;
                }
                
                $this->trackStep(3, 'Collection Creation', 'completed', 'Tenant collection "' . $tenantCollectionName . '" is available');
                $this->setupProgress['completed_steps']++;
            } catch (\Exception $e) {
                $this->trackStep(3, 'Collection Creation', 'failed', $e->getMessage(), [
                    'exception_type' => get_class($e),
                    'collection' => $tenantCollectionName
                ]);
                
                $this->lastErrorDetails = [
                    'operation' => 'ensureTenantCollectionExists',
                    'step' => 3,
                    'step_name' => 'Collection Creation',
                    'error_type' => 'collection_exception',
                    'error_message' => $e->getMessage(),
                    'exception_type' => get_class($e),
                    'collection' => $tenantCollectionName
                ];
                return false;
            }

            // Step 4: Configure schema fields
            $this->trackStep(4, 'Schema Configuration', 'started', 'Configuring schema fields for ObjectEntity metadata');
            
            try {
                if (!$this->configureSchemaFields()) {
                    $this->trackStep(4, 'Schema Configuration', 'failed', 'Failed to configure schema fields');
                    
                    $this->lastErrorDetails = [
                        'operation' => 'configureSchemaFields',
                        'step' => 4,
                        'step_name' => 'Schema Configuration',
                        'error_type' => 'schema_configuration_failure',
                        'error_message' => 'Failed to configure schema fields for ObjectEntity metadata',
                        'troubleshooting' => [
                            'Check SOLR collection is accessible',
                            'Verify schema API is enabled',
                            'Check field type definitions',
                            'Ensure proper field naming conventions'
                        ]
                    ];
                    return false;
                }
                
                $this->trackStep(4, 'Schema Configuration', 'completed', 'Schema fields configured successfully');
                $this->infrastructureCreated['schema_fields_configured'] = true;
                $this->setupProgress['completed_steps']++;
            } catch (\Exception $e) {
                $this->trackStep(4, 'Schema Configuration', 'failed', $e->getMessage(), [
                    'exception_type' => get_class($e)
                ]);
                
                $this->lastErrorDetails = [
                    'operation' => 'configureSchemaFields',
                    'step' => 4,
                    'step_name' => 'Schema Configuration',
                    'error_type' => 'schema_exception',
                    'error_message' => $e->getMessage(),
                    'exception_type' => get_class($e)
                ];
                return false;
            }

            // Step 5: Validate setup
            $this->trackStep(5, 'Setup Validation', 'started', 'Validating SOLR setup completion');
            
            try {
                if (!$this->validateSetup()) {
                    $this->trackStep(5, 'Setup Validation', 'failed', 'Setup validation failed');
                    
                    $this->lastErrorDetails = [
                        'operation' => 'validateSetup',
                        'step' => 5,
                        'step_name' => 'Setup Validation',
                        'error_type' => 'validation_failure',
                        'error_message' => 'Setup validation checks failed',
                        'troubleshooting' => [
                            'Check configSet exists and is accessible',
                            'Verify collection exists and is queryable',
                            'Test collection query functionality',
                            'Check SOLR admin UI for status'
                        ]
                    ];
                    return false;
                }
                
                $this->trackStep(5, 'Setup Validation', 'completed', 'Setup validation passed');
                $this->infrastructureCreated['multi_tenant_ready'] = true;
                $this->infrastructureCreated['cloud_mode'] = true;
                $this->setupProgress['completed_steps']++;
            } catch (\Exception $e) {
                $this->trackStep(5, 'Setup Validation', 'failed', $e->getMessage(), [
                    'exception_type' => get_class($e)
                ]);
                
                $this->lastErrorDetails = [
                    'operation' => 'validateSetup',
                    'step' => 5,
                    'step_name' => 'Setup Validation',
                    'error_type' => 'validation_exception',
                    'error_message' => $e->getMessage(),
                    'exception_type' => get_class($e)
                ];
                return false;
            }

            // Mark setup as completed successfully
            $this->setupProgress['completed_at'] = date('Y-m-d H:i:s');
            $this->setupProgress['success'] = true;

            $tenantCollectionName = $this->getTenantCollectionName();
            $tenantConfigSetName = $this->getTenantConfigSetName();
            $this->logger->info('✅ SOLR setup completed successfully (SolrCloud mode)', [
                'tenant_configSet_created' => $tenantConfigSetName,
                'tenant_collection_created' => $tenantCollectionName,
                'schema_fields_configured' => true,
                'setup_validated' => true,
                'completed_steps' => $this->setupProgress['completed_steps'],
                'total_steps' => $this->setupProgress['total_steps'],
                'solr_host' => $this->solrConfig['host'] ?? 'localhost',
                'solr_port' => $this->solrConfig['port'] ?? '8983',
                'admin_ui_url' => 'http://' . ($this->solrConfig['host'] ?? 'localhost') . ':' . ($this->solrConfig['port'] ?? '8983') . '/solr/'
            ]);
            
        return true;

        } catch (\Exception $e) {
            $this->setupProgress['completed_at'] = date('Y-m-d H:i:s');
            $this->setupProgress['success'] = false;
            
            $this->logger->error('SOLR setup failed', [
                'error' => $e->getMessage(),
                'completed_steps' => $this->setupProgress['completed_steps'],
                'total_steps' => $this->setupProgress['total_steps'],
                'trace' => $e->getTraceAsString()
            ]);
            
            // Store general failure details if no specific error was captured
            if ($this->lastErrorDetails === null) {
                $this->lastErrorDetails = [
                    'operation' => 'setupSolr',
                    'error_type' => 'general_setup_failure',
                    'error_message' => $e->getMessage(),
                    'exception_type' => get_class($e),
                    'completed_steps' => $this->setupProgress['completed_steps'],
                    'total_steps' => $this->setupProgress['total_steps']
                ];
            }
            
            return false;
        }
    }


    /**
     * Verify SOLR connectivity using GuzzleSolrService for consistency
     *
     * **CONSISTENCY FIX**: Uses the same comprehensive connectivity testing 
     * as all other parts of the system to ensure consistent behavior.
     *
     * @return bool True if SOLR is accessible and responding correctly
     */
    private function verifySolrConnectivity(): bool
    {
        try {
            // **SETUP-OPTIMIZED**: Use connectivity-only test for setup scenarios
            // Collections don't exist yet during setup, so we only test SOLR/Zookeeper connectivity
            $connectionTest = $this->solrService->testConnectivityOnly();
            $isConnected = $connectionTest['success'] ?? false;
            
            if ($isConnected) {
                $this->logger->info('SOLR connectivity verified successfully using GuzzleSolrService', [
                    'test_message' => $connectionTest['message'] ?? 'Connection test passed',
                    'components_tested' => array_keys($connectionTest['components'] ?? []),
                    'all_components_successful' => $this->allComponentsSuccessful($connectionTest['components'] ?? [])
                ]);
                return true;
            } else {
                $this->logger->error('SOLR connectivity verification failed using GuzzleSolrService', [
                    'test_message' => $connectionTest['message'] ?? 'Connection test failed',
                    'components' => $connectionTest['components'] ?? [],
                    'details' => $connectionTest['details'] ?? []
                ]);
                
                // Store detailed error information for better troubleshooting
                $this->lastErrorDetails = [
                    'operation' => 'verifySolrConnectivity',
                    'error_type' => 'connectivity_test_failure',
                    'error_message' => $connectionTest['message'] ?? 'Connection test failed',
                    'connection_test_result' => $connectionTest,
                    'troubleshooting' => [
                        'Check if SOLR server is running and accessible',
                        'Verify host/port configuration in settings',
                        'Check network connectivity between containers',
                        'Verify authentication credentials if required',
                        'Check SOLR admin UI manually: ' . $this->buildSolrUrl('/solr/')
                    ]
                ];
                
                return false;
            }
            
        } catch (\Exception $e) {
            $this->logger->error('SOLR connectivity verification failed - exception during GuzzleSolrService test', [
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            // Store detailed error information
            $this->lastErrorDetails = [
                'operation' => 'verifySolrConnectivity',
                'error_type' => 'connectivity_exception',
                'error_message' => $e->getMessage(),
                'exception_class' => get_class($e),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine()
            ];
            
            return false;
        }
    }
    
    /**
     * Check if all components in a connection test were successful
     *
     * @param array $components Components test results
     * @return bool True if all components passed
     */
    private function allComponentsSuccessful(array $components): bool
    {
        foreach ($components as $component => $result) {
            if (!($result['success'] ?? false)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Ensures the tenant-specific configSet exists in SOLR.
     * 
     * In SolrCloud mode, creating configSets from trusted configSets (like _default) 
     * requires authentication. Instead, we upload a ZIP file containing the configSet.
     *
     * @return bool True if configSet exists or was created successfully
     */
    private function ensureTenantConfigSet(): bool
    {
        $tenantConfigSetName = $this->getTenantConfigSetName();
        
        // Check if configSet already exists
        if ($this->configSetExists($tenantConfigSetName)) {
            $this->logger->info('Tenant configSet already exists (skipping creation)', [
                'configSet' => $tenantConfigSetName
            ]);
            // Track existing configSet as skipped (not newly created)
            if (!in_array($tenantConfigSetName, $this->infrastructureCreated['configsets_skipped'])) {
                $this->infrastructureCreated['configsets_skipped'][] = $tenantConfigSetName;
            }
            return true;
        }

        // Upload configSet from ZIP file (bypasses trusted configSet authentication)
        $this->logger->info('Uploading tenant configSet from ZIP file', [
            'configSet' => $tenantConfigSetName,
            'method' => 'ZIP upload (avoids SolrCloud authentication issues)'
        ]);
        return $this->uploadConfigSet($tenantConfigSetName);
    }

    /**
     * Check if a SOLR configSet exists
     *
     * @param string $configSetName Name of the configSet to check
     * @return bool True if configSet exists, false otherwise
     */
    private function configSetExists(string $configSetName): bool
    {
        $url = $this->buildSolrUrl('/admin/configs?action=LIST&wt=json');

        $this->logger->debug('Checking if configSet exists', [
            'configSet' => $configSetName,
            'url' => $url
        ]);

        try {
            $requestOptions = ['timeout' => 10];
            
            // Add authentication if configured
            if (!empty($this->solrConfig['username']) && !empty($this->solrConfig['password'])) {
                $requestOptions['auth'] = [$this->solrConfig['username'], $this->solrConfig['password']];
            }
            
            $response = $this->httpClient->get($url, $requestOptions);
            
            if ($response->getStatusCode() !== 200) {
                $this->logger->warning('Failed to check configSet existence - HTTP error', [
                    'configSet' => $configSetName,
                    'url' => $url,
                    'status_code' => $response->getStatusCode(),
                    'response_body' => (string)$response->getBody(),
                    'assumption' => 'Assuming configSet does not exist'
                ]);
            return false;
        }

            $data = json_decode((string)$response->getBody(), true);
            
        } catch (\Exception $e) {
            $this->logger->warning('Failed to check configSet existence - HTTP request failed', [
                'configSet' => $configSetName,
                'url' => $url,
                'error' => $e->getMessage(),
                'exception_type' => get_class($e),
                'assumption' => 'Assuming configSet does not exist'
            ]);
            return false;
        }
        
        if ($data === null) {
            $this->logger->warning('Failed to check configSet existence - Invalid JSON response', [
                'configSet' => $configSetName,
                'url' => $url,
                'json_error' => json_last_error_msg(),
                'assumption' => 'Assuming configSet does not exist'
            ]);
            return false;
        }

        $configSets = $data['configSets'] ?? [];
        $exists = in_array($configSetName, $configSets);
        
        $this->logger->debug('ConfigSet existence check completed', [
            'configSet' => $configSetName,
            'exists' => $exists,
            'available_configSets' => $configSets
        ]);
        
        return $exists;
    }

    /**
     * Create a new SOLR configSet based on an existing template
     *
     * @param string $newConfigSetName Name for the new configSet
     * @param string $templateConfigSetName Name of the template configSet to copy from
     * @return bool True if configSet was created successfully
     */
    private function createConfigSet(string $newConfigSetName, string $templateConfigSetName): bool
    {
        // First, test basic SOLR connectivity before attempting configSet creation
        $this->logger->info('Testing SOLR connectivity before configSet creation', [
            'configSet' => $newConfigSetName
        ]);
        
        // Use GuzzleSolrService's comprehensive connectivity test instead of simple ping
        try {
            $connectionTest = $this->solrService->testConnection();
            if ($connectionTest['success']) {
                $this->logger->info('SOLR connectivity test successful', [
                    'test_message' => $connectionTest['message'] ?? 'Connection verified',
                    'components_tested' => array_keys($connectionTest['components'] ?? [])
                ]);
            } else {
                $this->logger->error('SOLR connectivity test failed before configSet creation', [
                    'test_message' => $connectionTest['message'] ?? 'Connection failed',
                    'details' => $connectionTest['details'] ?? []
                ]);
                // Continue anyway - connectivity test might fail but configSet creation might still work
            }
        } catch (\Exception $e) {
            $this->logger->warning('SOLR connectivity test threw exception before configSet creation', [
                'error' => $e->getMessage(),
                'exception_type' => get_class($e)
            ]);
            // Continue anyway - connectivity test might not be available but admin endpoints might work
        }
        
        // Use SolrCloud ConfigSets API for configSet creation with authentication
        $url = $this->buildSolrUrl(sprintf('/admin/configs?action=CREATE&name=%s&baseConfigSet=%s&wt=json',
            urlencode($newConfigSetName),
            urlencode($templateConfigSetName)
        ));

        $this->logger->info('Attempting to create SOLR configSet', [
                'configSet' => $newConfigSetName,
            'template' => $templateConfigSetName,
            'url' => $url,
            'authentication_configured' => !empty($this->solrConfig['username']) && !empty($this->solrConfig['password'])
        ]);

        try {
            // Use Guzzle HTTP client with proper timeout, headers, and authentication for SolrCloud
            $requestOptions = [
                'timeout' => 30,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ]
            ];
            
            // Add authentication if configured
            if (!empty($this->solrConfig['username']) && !empty($this->solrConfig['password'])) {
                $requestOptions['auth'] = [$this->solrConfig['username'], $this->solrConfig['password']];
                $this->logger->info('Using HTTP Basic authentication for SOLR configSet creation', [
                    'username' => $this->solrConfig['username'],
                    'url' => $url
                ]);
            }
            
            $response = $this->httpClient->get($url, $requestOptions);

            if ($response->getStatusCode() !== 200) {
                $responseBody = (string)$response->getBody();
                $this->logger->error('Failed to create configSet - HTTP error', [
                    'configSet' => $newConfigSetName,
                    'template' => $templateConfigSetName,
                    'url' => $url,
                    'status_code' => $response->getStatusCode(),
                    'response_body' => $responseBody,
                    'possible_causes' => [
                        'SOLR server not reachable at configured URL',
                        'Network connectivity issues',
                        'SOLR server not responding',
                        'Invalid SOLR configuration (host/port/path)',
                        'SOLR server overloaded or timeout'
                    ]
                ]);
                
                // Store detailed error information for API response
                $this->lastErrorDetails = [
                    'operation' => 'createConfigSet',
                    'error_type' => 'http_error',
                    'error_message' => 'HTTP error ' . $response->getStatusCode(),
                    'url_attempted' => $url,
                    'guzzle_response_status' => $response->getStatusCode(),
                    'guzzle_response_body' => $responseBody,
                    'solr_error_code' => null,
                    'solr_error_details' => null,
                    'configuration_used' => $this->solrConfig
                ];
                
                return false;
            }

            $data = json_decode((string)$response->getBody(), true);
            
        } catch (\Exception $e) {
            // Enhanced exception logging for HTTP client issues
            $logData = [
                'configSet' => $newConfigSetName,
                'template' => $templateConfigSetName,
                'url' => $url,
                'error' => $e->getMessage(),
                'exception_type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ];
            
            // Extract additional details from Guzzle exceptions
            if ($e instanceof \GuzzleHttp\Exception\RequestException) {
                $logData['guzzle_request_exception'] = true;
                if ($e->hasResponse()) {
                    $response = $e->getResponse();
                    $logData['response_status'] = $response->getStatusCode();
                    $logData['response_body'] = (string)$response->getBody();
                    $logData['response_headers'] = $response->getHeaders();
                }
                if ($e->getRequest()) {
                    $request = $e->getRequest();
                    $logData['request_method'] = $request->getMethod();
                    $logData['request_uri'] = (string)$request->getUri();
                    $logData['request_headers'] = $request->getHeaders();
                }
            }
            
            // Check for authentication issues
            if (strpos($e->getMessage(), '401') !== false || strpos($e->getMessage(), 'Unauthorized') !== false) {
                $logData['authentication_issue'] = true;
                $logData['has_credentials'] = !empty($this->solrConfig['username']) && !empty($this->solrConfig['password']);
            }
            
            // Check for network connectivity issues
            if (strpos($e->getMessage(), 'Connection refused') !== false || 
                strpos($e->getMessage(), 'Could not resolve host') !== false ||
                strpos($e->getMessage(), 'timeout') !== false) {
                $logData['network_connectivity_issue'] = true;
            }
            
            $logData['possible_causes'] = [
                'SOLR server not reachable at configured URL',
                'Network connectivity issues',
                'SOLR server not responding',
                'Invalid SOLR configuration (host/port/path)',
                'SOLR server overloaded or timeout',
                'Authentication failure (check username/password)',
                'Kubernetes service name resolution failure'
            ];
            
            $this->logger->error('Failed to create configSet - HTTP request failed', $logData);
            
            // Store detailed error information for API response
            $this->lastErrorDetails = [
                'operation' => 'createConfigSet',
                'configSet' => $newConfigSetName,
                'template' => $templateConfigSetName,
                'url_attempted' => $url,
                'error_type' => 'http_request_failed',
                'error_message' => $e->getMessage(),
                'exception_type' => get_class($e),
                'guzzle_details' => []
            ];
            
            // Add Guzzle-specific details if available
            if ($e instanceof \GuzzleHttp\Exception\RequestException) {
                $this->lastErrorDetails['guzzle_details']['is_request_exception'] = true;
                
                if ($e->hasResponse()) {
                    $response = $e->getResponse();
                    $responseStatus = $response->getStatusCode();
                    $responseBody = (string)$response->getBody();
                    
                    // Store in guzzle_details for comprehensive logging
                    $this->lastErrorDetails['guzzle_details']['response_status'] = $responseStatus;
                    $this->lastErrorDetails['guzzle_details']['response_body'] = $responseBody;
                    $this->lastErrorDetails['guzzle_details']['response_headers'] = $response->getHeaders();
                    
                    // Also store at top level for step tracking consistency
                    $this->lastErrorDetails['guzzle_response_status'] = $responseStatus;
                    $this->lastErrorDetails['guzzle_response_body'] = $responseBody;
                }
                
                if ($e->getRequest()) {
                    $request = $e->getRequest();
                    $this->lastErrorDetails['guzzle_details']['request_method'] = $request->getMethod();
                    $this->lastErrorDetails['guzzle_details']['request_uri'] = (string)$request->getUri();
                    $this->lastErrorDetails['guzzle_details']['request_headers'] = $request->getHeaders();
                }
            }
            
            // Add specific error categorization
            if (strpos($e->getMessage(), '401') !== false || strpos($e->getMessage(), 'Unauthorized') !== false) {
                $this->lastErrorDetails['error_category'] = 'authentication_failure';
                $this->lastErrorDetails['has_credentials'] = !empty($this->solrConfig['username']) && !empty($this->solrConfig['password']);
            } elseif (strpos($e->getMessage(), 'Connection refused') !== false || 
                      strpos($e->getMessage(), 'Could not resolve host') !== false ||
                      strpos($e->getMessage(), 'timeout') !== false) {
                $this->lastErrorDetails['error_category'] = 'network_connectivity';
            } else {
                $this->lastErrorDetails['error_category'] = 'unknown_http_error';
            }
            
            return false;
        }
        if ($data === null) {
            $this->logger->error('Failed to create configSet - Invalid JSON response', [
                'configSet' => $newConfigSetName,
                'template' => $templateConfigSetName,
                'url' => $url,
                'raw_response' => (string)$response->getBody(),
                'json_error' => json_last_error_msg()
            ]);
            
            // Store detailed error information for API response
            $this->lastErrorDetails = [
                'operation' => 'createConfigSet',
                'configSet' => $newConfigSetName,
                'template' => $templateConfigSetName,
                'url_attempted' => $url,
                'error_type' => 'invalid_json_response',
                'error_message' => 'SOLR returned invalid JSON response',
                'json_error' => json_last_error_msg(),
                'raw_response' => (string)$response->getBody(),
                'response_status' => $response->getStatusCode(),
                'response_headers' => $response->getHeaders()
            ];
            
            return false;
        }

        $status = $data['responseHeader']['status'] ?? -1;
        if ($status === 0) {
            $this->logger->info('ConfigSet created successfully', [
                'configSet' => $newConfigSetName
            ]);
            return true;
        }

        // Extract detailed error information from SOLR response
        $errorMsg = $data['error']['msg'] ?? 'Unknown SOLR error';
        $errorCode = $data['error']['code'] ?? $status;
        $errorDetails = $data['error']['metadata'] ?? [];

        $this->logger->error('ConfigSet creation failed - SOLR returned error', [
            'configSet' => $newConfigSetName,
            'template' => $templateConfigSetName,
            'url' => $url,
            'solr_status' => $status,
            'solr_error_message' => $errorMsg,
            'solr_error_code' => $errorCode,
            'solr_error_details' => $errorDetails,
            'full_response' => $data,
            'troubleshooting_tips' => [
                'Verify template configSet exists: ' . $templateConfigSetName,
                'Check SOLR admin UI for existing configSets',
                'Ensure SOLR has write permissions for config directory',
                'Verify SOLR is running in SolrCloud mode if using collections',
                'Check SOLR logs for additional error details'
            ]
        ]);
        
        // Store detailed error information for API response
        $this->lastErrorDetails = [
            'operation' => 'createConfigSet',
            'configSet' => $newConfigSetName,
            'template' => $templateConfigSetName,
            'url_attempted' => $url,
            'error_type' => 'solr_api_error',
            'error_message' => $errorMsg,
            'solr_status' => $status,
            'solr_error_code' => $errorCode,
            'solr_error_details' => $errorDetails,
            'full_solr_response' => $data,
            'troubleshooting_tips' => [
                'Verify template configSet "' . $templateConfigSetName . '" exists in SOLR',
                'Check SOLR admin UI for existing configSets',
                'Ensure SOLR has write permissions for config directory',
                'Verify SOLR is running in SolrCloud mode if using collections',
                'Check SOLR logs for additional error details'
            ]
        ];
        
        return false;
    }


    /**
     * Ensure the tenant-specific collection exists for this instance
     *
     * Creates a tenant-specific collection (e.g., "openregister_nc_f0e53393")
     * using the tenant-specific configSet (e.g., "openregister_nc_f0e53393").
     *
     * @return bool True if tenant collection exists or was created successfully
     */
    private function ensureTenantCollectionExists(): bool
    {
        $tenantCollectionName = $this->getTenantCollectionName();
        
        // Check if tenant collection already exists
        if ($this->solrService->collectionExists($tenantCollectionName)) {
            $this->logger->info('Tenant collection already exists (skipping creation)', [
                'collection' => $tenantCollectionName
            ]);
            
            // Track existing collection as skipped (not newly created)
            if (!in_array($tenantCollectionName, $this->infrastructureCreated['collections_skipped'])) {
                $this->infrastructureCreated['collections_skipped'][] = $tenantCollectionName;
            }
            
            return true;
        }

        // Create tenant collection using the tenant-specific configSet
        $tenantConfigSetName = $this->getTenantConfigSetName();
        $this->logger->info('Creating tenant collection', [
            'collection' => $tenantCollectionName,
            'configSet' => $tenantConfigSetName
        ]);
        
        try {
            // Try to force configSet propagation before collection creation
            $this->forceConfigSetPropagation($tenantConfigSetName);
            
            // Attempt collection creation with retry logic for configSet propagation delays
            $success = $this->createCollectionWithRetry($tenantCollectionName, $tenantConfigSetName);
            
            // Track newly created collection
            if ($success && !in_array($tenantCollectionName, $this->infrastructureCreated['collections_created'])) {
                $this->infrastructureCreated['collections_created'][] = $tenantCollectionName;
            }
            
            return $success;
            
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            // Capture Guzzle HTTP errors (network, timeout, etc.)
            $this->lastErrorDetails = [
                'primary_error' => 'HTTP request to SOLR failed',
                'error_type' => 'guzzle_http_error',
                'operation' => 'ensureTenantCollectionExists',
                'step' => 3,
                'step_name' => 'Collection Creation',
                'collection' => $tenantCollectionName,
                'configSet' => $tenantConfigSetName,
                'url_attempted' => $e->getRequest() ? $e->getRequest()->getUri() : 'unknown',
                'exception_type' => get_class($e),
                'exception_message' => $e->getMessage(),
                'error_category' => 'network_connectivity',
                'guzzle_details' => [
                    'request_method' => $e->getRequest() ? $e->getRequest()->getMethod() : 'unknown',
                    'response_code' => $e->hasResponse() ? $e->getResponse()->getStatusCode() : null,
                    'response_body' => $e->hasResponse() ? (string)$e->getResponse()->getBody() : null
                ]
            ];
            
            $this->logger->error('Guzzle HTTP error during collection creation', $this->lastErrorDetails);
            return false;
            
        } catch (\Exception $e) {
            // Capture SOLR API errors (400 responses, validation errors, etc.)
            $solrResponse = null;
            $errorCategory = 'solr_api_error';
            
            // Try to extract SOLR response from nested exception
            if ($e->getPrevious() && $e->getPrevious()->getMessage()) {
                $possibleJson = $e->getPrevious()->getMessage();
                $decodedResponse = json_decode($possibleJson, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $solrResponse = $decodedResponse;
                    $errorCategory = 'solr_validation_error';
                }
            }
            
            // Log the collection creation failure with full details
            $this->logger->error('Collection creation failed', [
                'collection' => $tenantCollectionName,
                'configSet' => $tenantConfigSetName,
                'original_error' => $e->getMessage(),
                'error_type' => get_class($e)
            ]);
            
            $this->lastErrorDetails = [
                'primary_error' => 'Failed to create tenant collection "' . $tenantCollectionName . '"',
                'error_type' => 'collection_creation_failure',
                'operation' => 'ensureTenantCollectionExists',
                'step' => 3,
                'step_name' => 'Collection Creation',
                'collection' => $tenantCollectionName,
                'configSet' => $tenantConfigSetName,
                'url_attempted' => 'SOLR Collections API',
                'exception_type' => get_class($e),
                'exception_message' => $e->getMessage(),
                'error_category' => $errorCategory,
                'solr_response' => $solrResponse,
                'guzzle_details' => [],
                'configuration_used' => [
                    'host' => $this->solrConfig['host'] ?? 'unknown',
                    'port' => $this->solrConfig['port'] ?? 'default',
                    'scheme' => $this->solrConfig['scheme'] ?? 'http',
                    'path' => $this->solrConfig['path'] ?? '/solr'
                ]
            ];
            
            $this->logger->error('SOLR collection creation exception', $this->lastErrorDetails);
            return false;
        }
    }

    /**
     * Create collection with retry logic for configSet propagation delays
     * 
     * This addresses the ZooKeeper propagation delay issue by directly attempting
     * collection creation with exponential backoff retry logic instead of polling.
     * 
     * @param string $collectionName Collection name to create
     * @param string $configSetName ConfigSet name to use
     * @param int $maxAttempts Maximum number of retry attempts (default: 8 - up to ~60 seconds)
     * @return bool True if collection created successfully
     * @throws \Exception If all retry attempts fail
     */
    private function createCollectionWithRetry(string $collectionName, string $configSetName, int $maxAttempts = 8): bool
    {
        $attempt = 0;
        $baseDelaySeconds = 2; // Start with 2 second delay
        $startTime = time();
        $retryDetails = [
            'attempts' => 0,
            'total_delay_seconds' => 0,
            'attempt_timestamps' => []
        ];
        
        while ($attempt < $maxAttempts) {
            $attempt++;
            
            try {
                $retryDetails['attempts'] = $attempt;
                $retryDetails['attempt_timestamps'][] = date('Y-m-d H:i:s');
                
                $this->logger->info('Attempting collection creation', [
                    'collection' => $collectionName,
                    'configSet' => $configSetName,
                    'attempt' => $attempt,
                    'maxAttempts' => $maxAttempts,
                    'elapsed_seconds' => time() - $startTime
                ]);
                
                // Direct attempt to create collection
                $success = $this->solrService->createCollection($collectionName, $configSetName);
                
                if ($success) {
                    $totalElapsed = time() - $startTime;
                    $this->logger->info('Collection created successfully', [
                        'collection' => $collectionName,
                        'configSet' => $configSetName,
                        'attempt' => $attempt,
                        'total_elapsed_seconds' => $totalElapsed,
                        'retry_details' => $retryDetails
                    ]);
                    return true;
                }
                
            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
                $isConfigSetError = $this->isConfigSetPropagationError($errorMessage);
                
                $this->logger->warning('Collection creation attempt failed', [
                    'collection' => $collectionName,
                    'configSet' => $configSetName,
                    'attempt' => $attempt,
                    'maxAttempts' => $maxAttempts,
                    'error' => $errorMessage,
                    'isConfigSetPropagationError' => $isConfigSetError
                ]);
                
                // If this is the last attempt, provide user-friendly propagation error with retry details
                if ($attempt >= $maxAttempts && $isConfigSetError) {
                    $totalElapsed = time() - $startTime;
                    $retryDetails['total_elapsed_seconds'] = $totalElapsed;
                    
                    throw new \Exception(
                        "SOLR ConfigSet propagation timeout: The configSet was created successfully but is still propagating across the SOLR cluster. This is normal in distributed SOLR environments. Attempted {$attempt} times over {$totalElapsed} seconds. Please wait 2-5 minutes and try the setup again.",
                        500,
                        new \Exception(json_encode($retryDetails))
                    );
                }
                
                // If not a configSet propagation error, throw immediately  
                if (!$isConfigSetError) {
                    throw $e;
                }
                
                // Calculate exponential backoff delay: 2, 4, 8, 16 seconds
                $delaySeconds = $baseDelaySeconds * pow(2, $attempt - 1);
                $retryDetails['total_delay_seconds'] += $delaySeconds;
                
                $this->logger->info('Retrying collection creation after delay', [
                    'collection' => $collectionName,
                    'delaySeconds' => $delaySeconds,
                    'nextAttempt' => $attempt + 1,
                    'total_elapsed_seconds' => time() - $startTime,
                    'cumulative_delay_seconds' => $retryDetails['total_delay_seconds']
                ]);
                
                sleep($delaySeconds);
            }
        }
        
        // Should not reach here due to exception throwing above
        return false;
    }

    /**
     * Check if error message indicates configSet propagation delay
     * 
     * @param string $errorMessage Error message from SOLR
     * @return bool True if this appears to be a configSet propagation issue
     */
    private function isConfigSetPropagationError(string $errorMessage): bool
    {
        $configSetErrorPatterns = [
            'Underlying core creation failed while creating collection',
            'configset does not exist',
            'Config does not exist',
            'Could not find configSet',
            'configSet not found'
        ];
        
        foreach ($configSetErrorPatterns as $pattern) {
            if (stripos($errorMessage, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Try to force configSet propagation across SOLR cluster nodes
     * 
     * This attempts to trigger immediate configSet synchronization using
     * various SOLR admin API calls that can help speed up propagation.
     *
     * @param string $configSetName ConfigSet name to force propagation for
     * @return bool True if any propagation commands succeeded
     */
    private function forceConfigSetPropagation(string $configSetName): bool
    {
        $this->logger->info('Attempting to force configSet propagation', [
            'configSet' => $configSetName
        ]);
        
        $successCount = 0;
        
        try {
            // Method 1: List configSets to trigger cache refresh
            $url = $this->buildSolrUrl('/admin/configs?action=LIST&wt=json');
            $response = $this->httpClient->get($url, ['timeout' => 10]);
            
            if ($response->getStatusCode() === 200) {
                $successCount++;
                $this->logger->debug('ConfigSet list refresh successful', [
                    'configSet' => $configSetName,
                    'method' => 'LIST'
                ]);
            }
            
        } catch (\Exception $e) {
            $this->logger->debug('ConfigSet list refresh failed', [
                'configSet' => $configSetName,
                'method' => 'LIST',
                'error' => $e->getMessage()
            ]);
        }
        
        try {
            // Method 2: Check cluster status to trigger ZooKeeper sync
            $url = $this->buildSolrUrl('/admin/collections?action=CLUSTERSTATUS&wt=json');
            $response = $this->httpClient->get($url, ['timeout' => 10]);
            
            if ($response->getStatusCode() === 200) {
                $successCount++;
                $this->logger->debug('Cluster status refresh successful', [
                    'configSet' => $configSetName,
                    'method' => 'CLUSTERSTATUS'
                ]);
            }
            
        } catch (\Exception $e) {
            $this->logger->debug('Cluster status refresh failed', [
                'configSet' => $configSetName,
                'method' => 'CLUSTERSTATUS',
                'error' => $e->getMessage()
            ]);
        }
        
        $this->logger->info('ConfigSet propagation force completed', [
            'configSet' => $configSetName,
            'successful_methods' => $successCount,
            'total_methods' => 2
        ]);
        
        // Give a moment for any triggered propagation to begin
        if ($successCount > 0) {
            sleep(1);
        }
        
        return $successCount > 0;
    }


    /**
     * Check if a SOLR collection exists (SolrCloud)
     *
     * @param string $collectionName Name of the collection to check
     * @return bool True if collection exists, false otherwise
     */
    private function collectionExists(string $collectionName): bool
    {
        $url = $this->buildSolrUrl('/admin/collections?action=CLUSTERSTATUS&wt=json');

        try {
            $requestOptions = ['timeout' => 10];
            
            // Add authentication if configured
            if (!empty($this->solrConfig['username']) && !empty($this->solrConfig['password'])) {
                $requestOptions['auth'] = [$this->solrConfig['username'], $this->solrConfig['password']];
            }
            
            $response = $this->httpClient->get($url, $requestOptions);
            
            if ($response->getStatusCode() !== 200) {
                $this->logger->debug('Failed to check collection existence - HTTP error', [
                    'collection' => $collectionName,
                    'url' => $url,
                    'status_code' => $response->getStatusCode()
                ]);
                return false;
            }

            $data = json_decode((string)$response->getBody(), true);
            
            if ($data === null) {
                $this->logger->debug('Failed to check collection existence - Invalid JSON', [
                    'collection' => $collectionName,
                    'url' => $url,
                    'json_error' => json_last_error_msg()
                ]);
                return false;
            }
        
        // Collection exists if it's in the cluster status
            $exists = isset($data['cluster']['collections'][$collectionName]);
            
            $this->logger->debug('Collection existence check completed', [
                'collection' => $collectionName,
                'exists' => $exists,
                'available_collections' => array_keys($data['cluster']['collections'] ?? [])
            ]);
            
            return $exists;
        } catch (\Exception $e) {
            $this->logger->debug('Failed to check collection existence - Exception', [
                'collection' => $collectionName,
                'url' => $url,
                'error' => $e->getMessage(),
                'exception_type' => get_class($e)
            ]);
            return false;
        }
    }

    /**
     * Create a new SOLR collection using a configSet (SolrCloud)
     *
     * @param string $collectionName Name for the new collection
     * @param string $configSetName Name of the configSet to use
     * @return bool True if collection was created successfully
     */
    private function createCollection(string $collectionName, string $configSetName): bool
    {
        $url = $this->buildSolrUrl(sprintf('/admin/collections?action=CREATE&name=%s&collection.configName=%s&numShards=1&replicationFactor=1&wt=json',
            urlencode($collectionName),
            urlencode($configSetName)
        ));

        $this->logger->info('Attempting to create SOLR collection', [
                'collection' => $collectionName,
            'configSet' => $configSetName,
            'url' => $url
        ]);

        try {
            $response = $this->httpClient->get($url, ['timeout' => 30]);
            
            if ($response->getStatusCode() !== 200) {
                $this->logger->error('Failed to create collection - HTTP error', [
                    'collection' => $collectionName,
                    'configSet' => $configSetName,
                    'url' => $url,
                    'status_code' => $response->getStatusCode(),
                    'response_body' => (string)$response->getBody()
                ]);
                
                // Store detailed error information for API response
                $this->lastErrorDetails = [
                    'operation' => 'createCollection',
                    'collection' => $collectionName,
                    'configSet' => $configSetName,
                    'url_attempted' => $url,
                    'error_type' => 'http_error',
                    'error_message' => 'HTTP request failed with status ' . $response->getStatusCode(),
                    'response_status' => $response->getStatusCode(),
                    'response_body' => (string)$response->getBody()
                ];
                
            return false;
        }

            $data = json_decode((string)$response->getBody(), true);
            
            if ($data === null) {
                $this->logger->error('Failed to create collection - Invalid JSON response', [
                    'collection' => $collectionName,
                    'configSet' => $configSetName,
                    'url' => $url,
                    'raw_response' => (string)$response->getBody(),
                    'json_error' => json_last_error_msg()
                ]);
                
                $this->lastErrorDetails = [
                    'operation' => 'createCollection',
                    'collection' => $collectionName,
                    'configSet' => $configSetName,
                    'url_attempted' => $url,
                    'error_type' => 'invalid_json_response',
                    'error_message' => 'SOLR returned invalid JSON response',
                    'json_error' => json_last_error_msg(),
                    'raw_response' => (string)$response->getBody()
                ];
                
                return false;
            }
            
            $status = $data['responseHeader']['status'] ?? -1;
            if ($status === 0) {
            $this->logger->info('Collection created successfully', [
                'collection' => $collectionName,
                'configSet' => $configSetName
            ]);
            
            // Track newly created collection
            if (!in_array($collectionName, $this->infrastructureCreated['collections_created'])) {
                $this->infrastructureCreated['collections_created'][] = $collectionName;
            }
            
            return true;
        }

            // Extract detailed error information from SOLR response
            $errorMsg = $data['error']['msg'] ?? 'Unknown SOLR error';
            $errorCode = $data['error']['code'] ?? $status;
            $errorDetails = $data['error']['metadata'] ?? [];

            $this->logger->error('Collection creation failed - SOLR returned error', [
            'collection' => $collectionName,
                'configSet' => $configSetName,
                'url' => $url,
                'solr_status' => $status,
                'solr_error_message' => $errorMsg,
                'solr_error_code' => $errorCode,
                'solr_error_details' => $errorDetails,
                'full_response' => $data
            ]);
            
            // Store detailed error information for API response
            $this->lastErrorDetails = [
                'operation' => 'createCollection',
                'collection' => $collectionName,
                'configSet' => $configSetName,
                'url_attempted' => $url,
                'error_type' => 'solr_api_error',
                'error_message' => $errorMsg,
                'solr_status' => $status,
                'solr_error_code' => $errorCode,
                'solr_error_details' => $errorDetails,
                'full_solr_response' => $data,
                'troubleshooting_tips' => [
                    'Verify configSet "' . $configSetName . '" exists and is accessible',
                    'Check SOLR has permissions to create collections',
                    'Verify ZooKeeper coordination in SolrCloud',
                    'Check available disk space and memory on SOLR server',
                    'Check SOLR logs for additional error details'
                ]
            ];
            
            return false;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to create collection - HTTP request failed', [
                'collection' => $collectionName,
                'configSet' => $configSetName,
                'url' => $url,
                'error' => $e->getMessage(),
                'exception_type' => get_class($e)
            ]);
            
            // Store detailed error information for API response
            $this->lastErrorDetails = [
                'operation' => 'createCollection',
                'collection' => $collectionName,
                'configSet' => $configSetName,
                'url_attempted' => $url,
                'error_type' => 'http_request_failed',
                'error_message' => $e->getMessage(),
                'exception_type' => get_class($e)
            ];
            
        return false;
        }
    }

    /**
     * Upload a configSet from ZIP file to SOLR
     * 
     * This method uploads a pre-packaged configSet ZIP file to SOLR, which bypasses
     * the authentication requirements for creating configSets from trusted templates.
     *
     * @param string $configSetName Name for the new configSet
     * @return bool True if configSet was uploaded successfully
     */
    private function uploadConfigSet(string $configSetName): bool
    {
        // Path to our packaged configSet ZIP file
        $zipPath = __DIR__ . '/../../resources/solr/openregister-configset.zip';
        
        if (!file_exists($zipPath)) {
            $this->logger->error('ConfigSet ZIP file not found', [
                'configSet' => $configSetName,
                'zipPath' => $zipPath
            ]);
            
            $this->lastErrorDetails = [
                'operation' => 'uploadConfigSet',
                'configSet' => $configSetName,
                'error_type' => 'zip_file_not_found',
                'error_message' => 'ConfigSet ZIP file not found at: ' . $zipPath,
                'zip_path' => $zipPath,
                'troubleshooting_tips' => [
                    'Ensure the configSet ZIP file exists in resources/solr/',
                    'Check file permissions on the ZIP file',
                    'Verify the ZIP file contains valid configSet files'
                ]
            ];
            return false;
        }
        
        $url = $this->buildSolrUrl(sprintf('/admin/configs?action=UPLOAD&name=%s&wt=json',
            urlencode($configSetName)
        ));

        $this->logger->info('Uploading SOLR configSet from ZIP file', [
            'configSet' => $configSetName,
            'url' => $url,
            'zipPath' => $zipPath,
            'zipSize' => filesize($zipPath) . ' bytes'
        ]);

        try {
            // Read ZIP file contents
            $zipContents = file_get_contents($zipPath);
            if ($zipContents === false) {
                $this->logger->error('Failed to read configSet ZIP file', [
                    'configSet' => $configSetName,
                    'zipPath' => $zipPath
                ]);
                
                $this->lastErrorDetails = [
                    'operation' => 'uploadConfigSet',
                    'configSet' => $configSetName,
                    'error_type' => 'zip_read_failed',
                    'error_message' => 'Failed to read configSet ZIP file',
                    'zip_path' => $zipPath
                ];
                return false;
            }
            
            // Upload ZIP file via POST request
            $requestOptions = [
                'timeout' => 30,
                'headers' => [
                    'Content-Type' => 'application/octet-stream'
                ],
                'body' => $zipContents
            ];
            
            $response = $this->httpClient->post($url, $requestOptions);

            if ($response->getStatusCode() !== 200) {
                $responseBody = (string)$response->getBody();
                $this->logger->error('Failed to upload configSet - HTTP error', [
                    'configSet' => $configSetName,
                    'url' => $url,
                    'status_code' => $response->getStatusCode(),
                    'response_body' => $responseBody
                ]);
                
                $this->lastErrorDetails = [
                    'operation' => 'uploadConfigSet',
                    'configSet' => $configSetName,
                    'url_attempted' => $url,
                    'error_type' => 'http_error',
                    'error_message' => 'HTTP error ' . $response->getStatusCode(),
                    'response_status' => $response->getStatusCode(),
                    'response_body' => $responseBody
                ];
                return false;
            }

            $data = json_decode((string)$response->getBody(), true);
            
            if ($data === null) {
                $this->logger->error('Failed to upload configSet - Invalid JSON response', [
                    'configSet' => $configSetName,
                    'url' => $url,
                    'raw_response' => (string)$response->getBody(),
                    'json_error' => json_last_error_msg()
                ]);
                
                $this->lastErrorDetails = [
                    'operation' => 'uploadConfigSet',
                    'configSet' => $configSetName,
                    'url_attempted' => $url,
                    'error_type' => 'invalid_json_response',
                    'error_message' => 'SOLR returned invalid JSON response',
                    'json_error' => json_last_error_msg(),
                    'raw_response' => (string)$response->getBody()
                ];
                return false;
            }
            
            $status = $data['responseHeader']['status'] ?? -1;
            if ($status === 0) {
                $this->logger->info('ConfigSet uploaded successfully', [
                    'configSet' => $configSetName,
                    'method' => 'ZIP upload'
                ]);
                
                // Track newly created configSet
                if (!in_array($configSetName, $this->infrastructureCreated['configsets_created'])) {
                    $this->infrastructureCreated['configsets_created'][] = $configSetName;
                }
                
                return true;
            }
            
            // Handle SOLR API errors
            $errorCode = $data['error']['code'] ?? $status;
            $errorMsg = $data['error']['msg'] ?? 'Unknown SOLR error';
            $errorDetails = $data['error']['metadata'] ?? [];
            
            $this->logger->error('Failed to upload configSet - SOLR API error', [
                'configSet' => $configSetName,
                'url' => $url,
                'solr_status' => $status,
                'solr_error_code' => $errorCode,
                'solr_error_message' => $errorMsg,
                'solr_error_details' => $errorDetails,
                'full_response' => $data
            ]);
            
            $this->lastErrorDetails = [
                'operation' => 'uploadConfigSet',
                'configSet' => $configSetName,
                'url_attempted' => $url,
                'error_type' => 'solr_api_error',
                'error_message' => $errorMsg,
                'solr_status' => $status,
                'solr_error_code' => $errorCode,
                'solr_error_details' => $errorDetails,
                'full_solr_response' => $data
            ];
            return false;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to upload configSet - HTTP request failed', [
                'configSet' => $configSetName,
                'url' => $url,
                'error' => $e->getMessage(),
                'exception_type' => get_class($e)
            ]);
            
            $this->lastErrorDetails = [
                'operation' => 'uploadConfigSet',
                'configSet' => $configSetName,
                'url_attempted' => $url,
                'error_type' => 'http_request_failed',
                'error_message' => $e->getMessage(),
                'exception_type' => get_class($e)
            ];
            return false;
        }
    }

    /**
     * Check if a SOLR core exists
     *
     * @param string $coreName Name of the core to check
     * @return bool True if core exists, false otherwise
     */
    private function coreExists(string $coreName): bool
    {
        $url = $this->buildSolrUrl(sprintf('/admin/cores?action=STATUS&core=%s&wt=json',
            urlencode($coreName)
        ));

        try {
            $response = $this->httpClient->get($url, ['timeout' => 10]);
            $data = json_decode((string)$response->getBody(), true);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to check core existence', [
                'core' => $coreName,
                'error' => $e->getMessage(),
                'url' => $url
            ]);
            return false;
        }
        
        // Core exists if it's in the status response
        return isset($data['status'][$coreName]);
    }

    /**
     * Create a new SOLR core using a configSet
     *
     * @param string $coreName Name for the new core
     * @param string $configSetName Name of the configSet to use
     * @return bool True if core was created successfully
     */
    private function createCore(string $coreName, string $configSetName): bool
    {
        $url = $this->buildSolrUrl(sprintf('/admin/cores?action=CREATE&name=%s&configSet=%s&wt=json',
            urlencode($coreName),
            urlencode($configSetName)
        ));

        try {
            $response = $this->httpClient->get($url, ['timeout' => 30]);
            $data = json_decode((string)$response->getBody(), true);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create core', [
                'core' => $coreName,
                'configSet' => $configSetName,
                'error' => $e->getMessage(),
                'url' => $url
            ]);
            return false;
        }

        if (($data['responseHeader']['status'] ?? -1) === 0) {
            $this->logger->info('Core created successfully', [
                'core' => $coreName,
                'configSet' => $configSetName
            ]);
            return true;
        }

        $this->logger->error('Core creation failed', [
            'core' => $coreName,
            'response' => $data
        ]);
        return false;
    }

    /**
     * Configure SOLR schema fields for OpenRegister ObjectEntity metadata
     *
     * This method sets up all the necessary field types and fields based on the
     * ObjectEntity class metadata fields, ensuring proper data types and indexing.
     *
     * @return bool True if schema configuration was successful
     */
    private function configureSchemaFields(): bool
    {
        $this->logger->info('Configuring SOLR schema fields for ObjectEntity metadata');

        // Get field definitions but skip self_* fields since they're pre-configured in the base schema
        $allFieldDefinitions = self::getObjectEntityFieldDefinitions();
        $fieldDefinitions = array_filter($allFieldDefinitions, function($key) {
            return !str_starts_with($key, 'self_');
        }, ARRAY_FILTER_USE_KEY);
        
        $this->logger->info('Schema field configuration', [
            'total_defined_fields' => count($allFieldDefinitions),
            'self_fields_skipped' => count($allFieldDefinitions) - count($fieldDefinitions),
            'dynamic_fields_to_add' => count($fieldDefinitions),
            'note' => 'self_* fields are pre-configured in base schema'
        ]);
        
        $fieldResults = [
            'total_fields' => count($fieldDefinitions),
            'fields_added' => 0,
            'fields_updated' => 0,
            'fields_failed' => 0,
            'fields_skipped' => 0,
            'added_fields' => [],
            'updated_fields' => [],
            'failed_fields' => [],
            'skipped_fields' => []
        ];

        $success = true;
        foreach ($fieldDefinitions as $fieldName => $fieldConfig) {
            $result = $this->addOrUpdateSchemaFieldWithTracking($fieldName, $fieldConfig);
            
            if ($result['success']) {
                if ($result['action'] === 'added') {
                    $fieldResults['fields_added']++;
                    $fieldResults['added_fields'][] = $fieldName;
                } elseif ($result['action'] === 'updated') {
                    $fieldResults['fields_updated']++;
                    $fieldResults['updated_fields'][] = $fieldName;
                } elseif ($result['action'] === 'skipped') {
                    $fieldResults['fields_skipped']++;
                    $fieldResults['skipped_fields'][] = $fieldName;
                }
            } else {
                $fieldResults['fields_failed']++;
                $fieldResults['failed_fields'][] = $fieldName;
                $this->logger->error('Failed to configure field', ['field' => $fieldName, 'error' => $result['error'] ?? 'Unknown error']);
                $success = false;
            }
        }

        // Update the step tracking with detailed field information
        $this->trackStep(4, 'Schema Configuration', $success ? 'completed' : 'failed', 
            $success ? 'Schema fields configured successfully' : 'Schema field configuration failed',
            $fieldResults
        );

        if ($success) {
            $this->logger->info('Schema field configuration completed successfully', $fieldResults);
        }

        return $success;
    }

    /**
     * Add or update a schema field with detailed tracking
     *
     * @param string $fieldName Name of the field
     * @param array $fieldConfig Field configuration
     * @return array Result with success status, action taken, and error details
     */
    private function addOrUpdateSchemaFieldWithTracking(string $fieldName, array $fieldConfig): array
    {
        // First, try to add the field
        $addResult = $this->addSchemaFieldWithResult($fieldName, $fieldConfig);
        
        if ($addResult['success']) {
            return [
                'success' => true,
                'action' => 'added',
                'details' => $addResult
            ];
        }
        
        // If add failed because field exists, try to update/replace
        if (strpos($addResult['error'] ?? '', 'already exists') !== false || 
            strpos($addResult['error'] ?? '', 'Field') !== false) {
            
            $updateResult = $this->replaceSchemaFieldWithResult($fieldName, $fieldConfig);
            
            if ($updateResult['success']) {
                return [
                    'success' => true,
                    'action' => 'updated',
                    'details' => $updateResult
                ];
            } else {
                // Field exists but couldn't be updated - might be same config
                return [
                    'success' => true,
                    'action' => 'skipped',
                    'details' => ['reason' => 'Field exists with compatible configuration']
                ];
            }
        }
        
        // Both add and update failed
        return [
            'success' => false,
            'action' => 'failed',
            'error' => $addResult['error'] ?? 'Unknown error'
        ];
    }

    /**
     * Add a schema field and return detailed result
     *
     * @param string $fieldName Name of the field
     * @param array $fieldConfig Field configuration
     * @return array Result with success status and details
     */
    private function addSchemaFieldWithResult(string $fieldName, array $fieldConfig): array
    {
        $tenantCollectionName = $this->getTenantCollectionName();
        $url = $this->buildSolrUrl('/' . $tenantCollectionName . '/schema');

        $payload = [
            'add-field' => array_merge(['name' => $fieldName], $fieldConfig)
        ];

        try {
            $response = $this->httpClient->post($url, [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => $payload,
                'timeout' => 30
            ]);

            if ($response->getStatusCode() !== 200) {
                return [
                    'success' => false,
                    'error' => 'HTTP error: ' . $response->getStatusCode(),
                    'response_body' => (string)$response->getBody()
                ];
            }

            $data = json_decode((string)$response->getBody(), true);
            $success = ($data['responseHeader']['status'] ?? -1) === 0;

            if ($success) {
                return [
                    'success' => true,
                    'solr_response' => $data
                ];
            } else {
                return [
                    'success' => false,
                    'error' => $data['error']['msg'] ?? 'SOLR error',
                    'solr_response' => $data
                ];
            }
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'exception_type' => get_class($e)
            ];
        }
    }

    /**
     * Replace a schema field and return detailed result
     *
     * @param string $fieldName Name of the field
     * @param array $fieldConfig Field configuration
     * @return array Result with success status and details
     */
    private function replaceSchemaFieldWithResult(string $fieldName, array $fieldConfig): array
    {
        $tenantCollectionName = $this->getTenantCollectionName();
        $url = $this->buildSolrUrl('/' . $tenantCollectionName . '/schema');

        $payload = [
            'replace-field' => array_merge(['name' => $fieldName], $fieldConfig)
        ];

        try {
            $response = $this->httpClient->post($url, [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => $payload,
                'timeout' => 30
            ]);

            if ($response->getStatusCode() !== 200) {
                return [
                    'success' => false,
                    'error' => 'HTTP error: ' . $response->getStatusCode(),
                    'response_body' => (string)$response->getBody()
                ];
            }

            $data = json_decode((string)$response->getBody(), true);
            $success = ($data['responseHeader']['status'] ?? -1) === 0;

            if ($success) {
                return [
                    'success' => true,
                    'solr_response' => $data
                ];
            } else {
                return [
                    'success' => false,
                    'error' => $data['error']['msg'] ?? 'SOLR error',
                    'solr_response' => $data
                ];
            }
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'exception_type' => get_class($e)
            ];
        }
    }

    /**
     * Apply schema fields to a SOLR collection (shared method)
     *
     * This static method can be used by both setup and warmup processes to apply
     * the standard ObjectEntity schema fields to any SOLR collection.
     *
     * @param \GuzzleHttp\Client $httpClient HTTP client for SOLR requests
     * @param array $solrConfig SOLR configuration
     * @param string $collectionName Name of the collection to update
     * @param \Psr\Log\LoggerInterface $logger Logger for operation tracking
     * @return array Results with success status and field counts
     */
    public static function applySchemaFields(
        \GuzzleHttp\Client $httpClient,
        array $solrConfig,
        string $collectionName,
        \Psr\Log\LoggerInterface $logger
    ): array {
        $logger->info('Applying ObjectEntity schema fields to SOLR collection', [
            'collection' => $collectionName
        ]);

        $fieldDefinitions = self::getObjectEntityFieldDefinitions();
        $results = [
            'success' => true,
            'total_fields' => count($fieldDefinitions),
            'fields_added' => 0,
            'fields_updated' => 0,
            'fields_failed' => 0,
            'errors' => []
        ];

        // Build SOLR URL helper
        $buildUrl = function(string $path) use ($solrConfig): string {
            $host = $solrConfig['host'] ?? 'localhost';
            $port = $solrConfig['port'] ?? null;
            $scheme = $solrConfig['scheme'] ?? 'http';
            $basePath = $solrConfig['path'] ?? '/solr';
            
            // Handle Kubernetes service names and port logic
            if (strpos($host, '.svc.cluster.local') !== false) {
                return sprintf('%s://%s%s%s', $scheme, $host, $basePath, $path);
            } else {
                if ($port !== null && $port > 0) {
                    return sprintf('%s://%s:%d%s%s', $scheme, $host, $port, $basePath, $path);
                } else {
                    return sprintf('%s://%s%s%s', $scheme, $host, $basePath, $path);
                }
            }
        };

        $schemaUrl = $buildUrl('/' . $collectionName . '/schema');

        foreach ($fieldDefinitions as $fieldName => $fieldConfig) {
            try {
                // Try to add the field first
                $addPayload = [
                    'add-field' => array_merge(['name' => $fieldName], $fieldConfig)
                ];

                $response = $httpClient->post($schemaUrl, [
                    'headers' => ['Content-Type' => 'application/json'],
                    'json' => $addPayload,
                    'timeout' => 30
                ]);

                if ($response->getStatusCode() === 200) {
                    $data = json_decode((string)$response->getBody(), true);
                    if (($data['responseHeader']['status'] ?? -1) === 0) {
                        $results['fields_added']++;
                        $logger->debug('Added schema field', ['field' => $fieldName]);
                        continue;
                    }
                }

                // If add failed, try to replace
                $replacePayload = [
                    'replace-field' => array_merge(['name' => $fieldName], $fieldConfig)
                ];

                $response = $httpClient->post($schemaUrl, [
                    'headers' => ['Content-Type' => 'application/json'],
                    'json' => $replacePayload,
                    'timeout' => 30
                ]);

                if ($response->getStatusCode() === 200) {
                    $data = json_decode((string)$response->getBody(), true);
                    if (($data['responseHeader']['status'] ?? -1) === 0) {
                        $results['fields_updated']++;
                        $logger->debug('Updated schema field', ['field' => $fieldName]);
                        continue;
                    }
                }

                // Both add and replace failed
                $results['fields_failed']++;
                $results['errors'][] = "Failed to add/update field: {$fieldName}";
                $logger->warning('Failed to add/update schema field', [
                    'field' => $fieldName,
                    'last_response' => (string)$response->getBody()
                ]);

            } catch (\Exception $e) {
                $results['fields_failed']++;
                $results['errors'][] = "Exception for field {$fieldName}: " . $e->getMessage();
                $logger->error('Exception applying schema field', [
                    'field' => $fieldName,
                    'error' => $e->getMessage(),
                    'exception_type' => get_class($e)
                ]);
            }
        }

        $results['success'] = $results['fields_failed'] === 0;

        $logger->info('Schema field application completed', [
            'collection' => $collectionName,
            'total_fields' => $results['total_fields'],
            'fields_added' => $results['fields_added'],
            'fields_updated' => $results['fields_updated'],
            'fields_failed' => $results['fields_failed'],
            'success' => $results['success']
        ]);

        return $results;
    }

    /**
     * Get field definitions for ObjectEntity metadata fields (shared method)
     *
     * Based on ObjectEntity.php properties, this method returns the proper
     * SOLR field type configuration for each metadata field using self_ prefixes
     * and clean field names (no suffixes needed when explicitly defined).
     *
     * This method can be used by both setup and warmup processes to ensure
     * consistent schema field configuration across all SOLR operations.
     *
     * @return array Field definitions with SOLR type configuration
     */
    public static function getObjectEntityFieldDefinitions(): array
    {
        return [
            // **CRITICAL**: Core tenant field with self_ prefix (consistent naming)
            'self_tenant' => [
                'type' => 'string',
                'stored' => true,
                'indexed' => true,
                'multiValued' => false,
                'required' => true
            ],

            // Metadata fields with self_ prefix (consistent with legacy mapping)
            'self_object_id' => [
                'type' => 'pint',
                'stored' => true,
                'indexed' => true,
                'multiValued' => false
            ],
            'self_uuid' => [
                'type' => 'string',
                'stored' => true,
                'indexed' => true,
                'multiValued' => false
            ],

            // Context fields  
            'self_register' => [
                'type' => 'pint',
                'stored' => true,
                'indexed' => true,
                'multiValued' => false
            ],
            'self_schema' => [
                'type' => 'pint',
                'stored' => true,
                'indexed' => true,
                'multiValued' => false
            ],
            'self_schema_version' => [
                'type' => 'string',
                'stored' => true,
                'indexed' => true,
                'multiValued' => false
            ],

            // Ownership and metadata
            'self_owner' => [
                'type' => 'string',
                'stored' => true,
                'indexed' => true,
                'multiValued' => false
            ],
            'self_organisation' => [
                'type' => 'string',
                'stored' => true,
                'indexed' => true,
                'multiValued' => false
            ],
            'self_application' => [
                'type' => 'string',
                'stored' => true,
                'indexed' => true,
                'multiValued' => false
            ],

            // Core object fields (no suffixes needed when explicitly defined)
            'self_name' => [
                'type' => 'string',
                'stored' => true,
                'indexed' => true,
                'multiValued' => false
            ],
            'self_description' => [
                'type' => 'text_general',
                'stored' => true,
                'indexed' => true,
                'multiValued' => false
            ],
            'self_summary' => [
                'type' => 'text_general',
                'stored' => true,
                'indexed' => true,
                'multiValued' => false
            ],
            'self_image' => [
                'type' => 'string',
                'stored' => true,
                'indexed' => false,
                'multiValued' => false
            ],
            'self_slug' => [
                'type' => 'string',
                'stored' => true,
                'indexed' => true,
                'multiValued' => false
            ],
            'self_uri' => [
                'type' => 'string',
                'stored' => true,
                'indexed' => true,
                'multiValued' => false
            ],
            'self_version' => [
                'type' => 'string',
                'stored' => true,
                'indexed' => true,
                'multiValued' => false
            ],
            'self_size' => [
                'type' => 'string',
                'stored' => true,
                'indexed' => false,
                'multiValued' => false
            ],
            'self_folder' => [
                'type' => 'string',
                'stored' => true,
                'indexed' => true,
                'multiValued' => false
            ],

            // Timestamps (SOLR date format)
            'self_created' => [
                'type' => 'pdate',
                'stored' => true,
                'indexed' => true,
                'multiValued' => false
            ],
            'self_updated' => [
                'type' => 'pdate',
                'stored' => true,
                'indexed' => true,
                'multiValued' => false
            ],
            'self_published' => [
                'type' => 'pdate',
                'stored' => true,
                'indexed' => true,
                'multiValued' => false
            ],
            'self_depublished' => [
                'type' => 'pdate',
                'stored' => true,
                'indexed' => true,
                'multiValued' => false
            ],

            // **NEW**: UUID relation fields for clean object relationships
            'self_relations' => [
                'type' => 'string',
                'stored' => true,
                'indexed' => true,
                'multiValued' => true
            ],
            'self_files' => [
                'type' => 'string',
                'stored' => true,
                'indexed' => true,
                'multiValued' => true
            ],
            'self_parent_uuid' => [
                'type' => 'string',
                'stored' => true,
                'indexed' => true,
                'multiValued' => false
            ],
        ];
    }

    /**
     * Add or update a field in the SOLR schema
     *
     * @param string $fieldName Name of the field to add/update
     * @param array $fieldConfig Field configuration (type, stored, indexed, etc.)
     * @return bool True if field was added/updated successfully
     */
    private function addOrUpdateSchemaField(string $fieldName, array $fieldConfig): bool
    {
        // Try to add the field first (will fail if it already exists)
        if ($this->addSchemaField($fieldName, $fieldConfig)) {
            return true;
        }

        // If add failed, try to replace the existing field
        return $this->replaceSchemaField($fieldName, $fieldConfig);
    }

    /**
     * Add a new field to the SOLR schema
     *
     * @param string $fieldName Name of the field to add
     * @param array $fieldConfig Field configuration
     * @return bool True if field was added successfully
     */
    private function addSchemaField(string $fieldName, array $fieldConfig): bool
    {
        $tenantCollectionName = $this->getTenantCollectionName();
        $url = $this->buildSolrUrl('/' . $tenantCollectionName . '/schema');

        $payload = [
            'add-field' => array_merge(['name' => $fieldName], $fieldConfig)
        ];

        try {
            $response = $this->httpClient->post($url, [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => $payload,
                'timeout' => 30
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->debug('Failed to add schema field - HTTP error', [
                    'field' => $fieldName,
                    'status_code' => $response->getStatusCode(),
                    'response_body' => (string)$response->getBody()
                ]);
            return false;
        }

            $data = json_decode((string)$response->getBody(), true);
        $success = ($data['responseHeader']['status'] ?? -1) === 0;

        if ($success) {
            $this->logger->debug('Added schema field', ['field' => $fieldName]);
            } else {
                $this->logger->debug('Failed to add schema field - SOLR error', [
                    'field' => $fieldName,
                    'solr_response' => $data
                ]);
        }

        return $success;
            
        } catch (\Exception $e) {
            $this->logger->debug('Failed to add schema field - Exception', [
                'field' => $fieldName,
                'error' => $e->getMessage(),
                'exception_type' => get_class($e)
            ]);
            return false;
        }
    }

    /**
     * Replace an existing field in the SOLR schema
     *
     * @param string $fieldName Name of the field to replace
     * @param array $fieldConfig Field configuration
     * @return bool True if field was replaced successfully
     */
    private function replaceSchemaField(string $fieldName, array $fieldConfig): bool
    {
        $tenantCollectionName = $this->getTenantCollectionName();
        $url = $this->buildSolrUrl('/' . $tenantCollectionName . '/schema');

        $payload = [
            'replace-field' => array_merge(['name' => $fieldName], $fieldConfig)
        ];

        try {
            $response = $this->httpClient->post($url, [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => $payload,
                'timeout' => 30
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->debug('Failed to replace schema field - HTTP error', [
                    'field' => $fieldName,
                    'status_code' => $response->getStatusCode(),
                    'response_body' => (string)$response->getBody()
                ]);
            return false;
        }

            $data = json_decode((string)$response->getBody(), true);
        $success = ($data['responseHeader']['status'] ?? -1) === 0;

        if ($success) {
            $this->logger->debug('Replaced schema field', ['field' => $fieldName]);
            } else {
                $this->logger->debug('Failed to replace schema field - SOLR error', [
                    'field' => $fieldName,
                    'solr_response' => $data
                ]);
        }

        return $success;
            
        } catch (\Exception $e) {
            $this->logger->debug('Failed to replace schema field - Exception', [
                'field' => $fieldName,
                'error' => $e->getMessage(),
                'exception_type' => get_class($e)
            ]);
            return false;
        }
    }

    /**
     * Validate that SOLR setup is complete and functional (SolrCloud)
     *
     * Performs final validation checks:
     * 1. Base configSet exists
     * 2. Base collection exists
     * 3. Base collection is accessible via query
     *
     * @return bool True if all validation checks pass
     */
    private function validateSetup(): bool
    {
        $tenantCollectionName = $this->getTenantCollectionName();
        
        // Check tenant configSet exists
        $tenantConfigSetName = $this->getTenantConfigSetName();
        if (!$this->configSetExists($tenantConfigSetName)) {
            $this->logger->error('Validation failed: tenant configSet missing', [
                'configSet' => $tenantConfigSetName
            ]);
            return false;
        }

        // Check tenant collection exists
        if (!$this->solrService->collectionExists($tenantCollectionName)) {
            $this->logger->error('Validation failed: tenant collection missing', [
                'collection' => $tenantCollectionName
            ]);
            return false;
        }

        // Test tenant collection query functionality
        if (!$this->testCollectionQuery($tenantCollectionName)) {
            $this->logger->error('Validation failed: tenant collection query test failed', [
                'collection' => $tenantCollectionName
            ]);
            return false;
        }

        $this->logger->info('SOLR setup validation passed (SolrCloud mode)');
        return true;
    }

    /**
     * Test that a collection responds to queries correctly (SolrCloud)
     *
     * @param string $collectionName Name of the collection to test
     * @return bool True if collection responds to queries properly
     */
    private function testCollectionQuery(string $collectionName): bool
    {
        $url = $this->buildSolrUrl(sprintf('/%s/select?q=*:*&rows=0&wt=json',
            urlencode($collectionName)
        ));

        try {
            $response = $this->httpClient->get($url, ['timeout' => 10]);
            $data = json_decode((string)$response->getBody(), true);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to test collection query', [
                'collection' => $collectionName,
                'error' => $e->getMessage(),
                'url' => $url
            ]);
            return false;
        }
        
        // Valid response should have a response header with status 0
        return ($data['responseHeader']['status'] ?? -1) === 0;
    }

    /**
     * Test that a core responds to queries correctly
     *
     * @param string $coreName Name of the core to test
     * @return bool True if core responds to queries properly
     */
    private function testCoreQuery(string $coreName): bool
    {
        $url = $this->buildSolrUrl(sprintf('/%s/select?q=*:*&rows=0&wt=json',
            urlencode($coreName)
        ));

        try {
            $response = $this->httpClient->get($url, ['timeout' => 10]);
            $data = json_decode((string)$response->getBody(), true);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to test core query', [
                'core' => $coreName,
                'error' => $e->getMessage(),
                'url' => $url
            ]);
            return false;
        }
        
        // Valid response should have a response header with status 0
        return ($data['responseHeader']['status'] ?? -1) === 0;
    }

}
