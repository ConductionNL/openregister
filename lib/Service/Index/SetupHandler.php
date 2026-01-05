<?php

/**
 * SetupHandler
 *
 * Setup class for SOLR configuration in the OpenRegister application.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Index
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Index;

use Exception;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client as GuzzleClient;
use OCA\OpenRegister\Service\IndexService;

/**
 * SOLR Setup and Configuration Manager
 *
 * Handles initial SOLR setup, configSet creation, and core management
 * for the multi-tenant OpenRegister architecture.
 *
 * This class ensures that SOLR is properly configured with the necessary
 * configSets to support dynamic tenant core creation.
 *
 * @package   OCA\OpenRegister\Service\Index
 * @category  Service
 * @author    OpenRegister Team
 * @copyright 2024 OpenRegister
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version   GIT: <git_id>
 * @link      https://github.com/OpenRegister/OpenRegister
 */
class SetupHandler
{

    /**
     * PSR-3 compliant logger for operation tracking
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * SOLR connection configuration
     *
     * @var array<string, mixed>
     */
    private array $solrConfig;

    /**
     * HTTP client for SOLR requests (from IndexService)
     *
     * @var \OCP\Http\Client\IClient
     */
    private \OCP\Http\Client\IClient $httpClient;

    /**
     * SOLR service for authenticated HTTP client and configuration
     *
     * @var IndexService
     */
    private IndexService $solrService;

    /**
     * Detailed error information from the last failed operation
     *
     * @var array|null
     */
    private ?array $lastErrorDetails = null;

    /**
     * Track infrastructure resources created/skipped during setup
     *
     * @var array
     */
    private array $infrastructureCreated = [
        'configsets_created'       => [],
        'configsets_skipped'       => [],
        'collections_created'      => [],
        'collections_skipped'      => [],
        'schema_fields_configured' => false,
        'multi_tenant_ready'       => false,
        'cloud_mode'               => false,
    ];

    /**
     * Setup progress tracking with detailed step information
     *
     * @var array
     */
    private array $setupProgress = [];

    /**
     * Initialize SOLR setup manager
     *
     * @param IndexService    $solrService SOLR service with authenticated HTTP client and configuration
     * @param LoggerInterface $logger      PSR-3 compliant logger for operation tracking
     */
    public function __construct(IndexService $solrService, LoggerInterface $logger)
    {
        $this->solrService = $solrService;
        $this->logger      = $logger;

        // Get authenticated HTTP client and configuration from IndexService.
        $this->httpClient = $solrService->getHttpClient();
        /** @psalm-var array<string, mixed> */
        $this->solrConfig = $solrService->getSolrConfig();

        $this->logger->info(
            'SOLR Setup: Using authenticated HTTP client from IndexService',
            [
                'has_credentials' => empty($this->solrConfig['username']) === false
                    && empty($this->solrConfig['password']) === false,
                'username'        => $this->solrConfig['username'] ?? 'not_set',
                'password_set'    => empty($this->solrConfig['password']) === false,
                'host'            => $this->solrConfig['host'] ?? 'unknown',
                'port'            => $this->solrConfig['port'] ?? 'not_set',
                'scheme'          => $this->solrConfig['scheme'] ?? 'not_set',
                'path'            => $this->solrConfig['path'] ?? 'not_set',
            ]
        );
    }//end __construct()

    /**
     * Track a setup step with detailed information
     *
     * @param int    $stepNumber  Step number (1-5)
     * @param string $stepName    Human-readable step name
     * @param string $status      Step status (started, completed, failed)
     * @param string $description Step description
     * @param array  $details     Additional step details
     *
     * @return void
     */
    private function trackStep(
        int $stepNumber,
        string $stepName,
        string $status,
        string $description,
        array $details=[]
    ): void {
        $stepData = [
            'step_number' => $stepNumber,
            'step_name'   => $stepName,
            'status'      => $status,
            'description' => $description,
            'timestamp'   => date('Y-m-d H:i:s'),
            'details'     => $details,
        ];

        // Update or add the step.
        $found = false;
        foreach ($this->setupProgress['steps'] as &$step) {
            if ($step['step_number'] === $stepNumber) {
                $step  = array_merge($step, $stepData);
                $found = true;
                break;
            }
        }

        if ($found === false) {
            $this->setupProgress['steps'][] = $stepData;
        }

        $this->logger->info("Setup Step {$stepNumber}/{$stepName}: {$status} - {$description}", $details);
    }//end trackStep()

    /**
     * Build SOLR URL using IndexService base URL method for consistency
     *
     * @param string $path The SOLR API path (e.g., '/admin/info/system')
     *
     * @return string Complete SOLR URL
     */
    private function buildSolrUrl(string $path): string
    {
        // Use IndexService's buildSolrBaseUrl method for consistency.
        // This ensures URL building logic is centralized and consistent.
        $baseUrl = $this->solrService->buildSolrBaseUrl();
        return $baseUrl.$path;
    }//end buildSolrUrl()

    /**
     * Extract API calls information from a propagation result.
     *
     * @param array $propagationResult The propagation result array.
     *
     * @return array The API calls array with configset_list_refresh and cluster_status_sync.
     */
    private function getApiCallsFromResult(array $propagationResult): array
    {
        $summary = $propagationResult['summary'] ?? [];
        return [
            'configset_list_refresh' => $summary['configset_list_refresh'] ?? 'unknown',
            'cluster_status_sync'    => $summary['cluster_status_sync'] ?? 'unknown',
        ];
    }//end getApiCallsFromResult()

    /**
     * Initialize all setup steps as pending to show complete progress view
     *
     * This ensures that users can see all steps in the setup modal,
     * including ones that haven't been reached yet due to earlier failures.
     *
     * @return void
     */
    private function initializeAllSteps(): void
    {
        $allSteps = [
            1 => ['step_name' => 'SOLR Connectivity', 'description' => 'Verify SOLR server connectivity and authentication'],
            2 => ['step_name' => 'EnsureTenantConfigSet', 'description' => 'Create or verify tenant-specific configSet'],
            3 => ['step_name' => 'Collection Creation', 'description' => 'Create or verify tenant-specific collection'],
            4 => [
                'step_name'   => 'Schema Configuration',
                'description' => 'Configure schema fields for ObjectEntity metadata',
            ],
            5 => ['step_name' => 'Setup Validation', 'description' => 'Validate complete SOLR setup and functionality'],
        ];

        foreach ($allSteps as $stepNumber => $stepInfo) {
            $this->setupProgress['steps'][] = [
                'step_number' => $stepNumber,
                'step_name'   => $stepInfo['step_name'],
                'status'      => 'pending',
                'description' => $stepInfo['description'],
                'timestamp'   => null,
                'details'     => [],
            ];
        }
    }//end initializeAllSteps()

    /**
     * Get tenant-specific collection name using IndexService
     *
     * @return string Tenant-specific collection name (e.g., "openregister_nc_f0e53393")
     */
    private function getTenantCollectionName(): string
    {
        // SolrConfig may contain 'core' key even if not in type definition.
        $baseCollectionName = 'openregister';
        if (is_array($this->solrConfig) === true && array_key_exists('core', $this->solrConfig) === true) {
            $baseCollectionName = $this->solrConfig['core'];
        }

        return $this->solrService->getTenantSpecificCollectionName($baseCollectionName);
    }//end getTenantCollectionName()

    /**
     * Get tenant ID from IndexService
     *
     * @return string Tenant identifier (e.g., "nc_f0e53393")
     */
    private function getTenantId(): string
    {
        // GetTenantId doesn't exist, use getTenantSpecificCollectionName to derive tenant ID.
        // Extract tenant ID from collection name or use a default.
        $collectionName = $this->solrService->getTenantSpecificCollectionName('openregister');
        // Extract tenant ID from collection name pattern (e.g., "openregister_nc_xxx" -> "nc_xxx").
        if (preg_match('/_nc_([a-f0-9]+)$/', $collectionName, $matches) === 1) {
            return 'nc_'.$matches[1];
        }

        // Fallback: use a default tenant ID.
        return 'default';
    }//end getTenantId()

    /**
     * Get tenant-specific configSet name
     *
     * @return string ConfigSet name to use for tenant collections
     */
    private function getTenantConfigSetName(): string
    {
        // Use the configSet from configuration (defaults to '_default').
        // SolrConfig may contain 'configSet' key even if not in type definition.
        $configSetName = '_default';
        if (is_array($this->solrConfig) === true && array_key_exists('configSet', $this->solrConfig) === true) {
            $configSetName = $this->solrConfig['configSet'];
        }

        // If using _default, return it as-is (no tenant suffix needed).
        if ($configSetName === '_default') {
            $this->logger->info(
                'Using _default ConfigSet for maximum compatibility',
                [
                    'configSet' => $configSetName,
                    'tenant_id' => $this->getTenantId(),
                    'reason'    => 'Proven stable configuration with dynamic field support',
                ]
            );
            return '_default';
        }

        // For custom configSets, append tenant ID to make it tenant-specific.
        $tenantSpecificName = $configSetName.'_'.$this->getTenantId();
        $this->logger->info(
            'Using custom tenant-specific ConfigSet',
            [
                'base_configSet'   => $configSetName,
                'tenant_configSet' => $tenantSpecificName,
                'tenant_id'        => $this->getTenantId(),
            ]
        );

        return $tenantSpecificName;
    }//end getTenantConfigSetName()

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

        // Initialize setup progress tracking.
        $this->setupProgress = [
            'started_at'      => date('Y-m-d H:i:s'),
            'completed_at'    => null,
            'total_steps'     => 6,
            'completed_steps' => 0,
            'success'         => false,
            'steps'           => [],
        ];

        // Initialize all steps as pending to show complete progress.
        $this->initializeAllSteps();

        try {
            // Step 1: Verify SOLR connectivity.
            $this->trackStep(
                stepNumber: 1,
                stepName: 'SOLR Connectivity',
                status: 'started',
                description: 'Verifying SOLR server connectivity and authentication'
            );

            try {
                if ($this->verifySolrConnectivity() === false) {
                    $this->trackStep(
                        stepNumber: 1,
                        stepName: 'SOLR Connectivity',
                        status: 'failed',
                        description: 'Cannot connect to SOLR server',
                        details: [
                            'error'      => 'SOLR connectivity test failed',
                            'host'       => $this->solrConfig['host'] ?? 'unknown',
                            'port'       => $this->solrConfig['port'] ?? 'unknown',
                            'url_tested' => $this->buildSolrUrl('/admin/info/system?wt=json'),
                        ]
                    );

                    $this->lastErrorDetails = [
                        'operation'       => 'verifySolrConnectivity',
                        'step'            => 1,
                        'step_name'       => 'SOLR Connectivity',
                        'error_type'      => 'connectivity_failure',
                        'error_message'   => 'Cannot connect to SOLR server',
                        'configuration'   => $this->solrConfig,
                        'troubleshooting' => [
                            'Check if SOLR server is running',
                            'Verify host/port configuration',
                            'Check network connectivity',
                            'Verify authentication credentials if required',
                        ],
                    ];
                    return false;
                }//end if

                $this->trackStep(
                    stepNumber: 1,
                    stepName: 'SOLR Connectivity',
                    status: 'completed',
                    description: 'SOLR server connectivity verified'
                );
                $this->setupProgress['completed_steps']++;
            } catch (\Exception $e) {
                $this->trackStep(
                    stepNumber: 1,
                    stepName: 'SOLR Connectivity',
                    status: 'failed',
                    description: $e->getMessage(),
                    details: [
                        'exception_type'    => get_class($e),
                        'exception_message' => $e->getMessage(),
                    ]
                );

                $this->lastErrorDetails = [
                    'operation'      => 'verifySolrConnectivity',
                    'step'           => 1,
                    'step_name'      => 'SOLR Connectivity',
                    'error_type'     => 'connectivity_exception',
                    'error_message'  => $e->getMessage(),
                    'exception_type' => get_class($e),
                    'configuration'  => $this->solrConfig,
                ];
                return false;
            }//end try

            // Step 2: Ensure tenant configSet exists.
            $tenantConfigSetName = $this->getTenantConfigSetName();
            $this->trackStep(
                stepNumber: 2,
                stepName: 'EnsureTenantConfigSet',
                status: 'started',
                description: 'Checking and creating tenant configSet "'.$tenantConfigSetName.'"'
            );

            try {
                if ($this->ensureTenantConfigSet() === false) {
                    // Use detailed error information from createConfigSet if available.
                    $errorDetails = $this->lastErrorDetails ?? [];
                    $defaultErr   = 'Failed to create tenant configSet "'.$tenantConfigSetName.'"';
                    $actualError  = $errorDetails['error_message'] ?? $defaultErr;

                    $this->trackStep(
                        stepNumber: 2,
                        stepName: 'EnsureTenantConfigSet',
                        status: 'failed',
                        description: 'Failed to create tenant configSet "'.$tenantConfigSetName.'"',
                        details: [
                            'configSet'              => $tenantConfigSetName,
                            'template'               => '_default',
                            'error_type'             => $errorDetails['error_type'] ?? 'configset_creation_failure',
                            'url_attempted'          => $errorDetails['url_attempted'] ?? 'unknown',
                            'actual_error'           => $actualError,
                            'guzzle_response_status' => $errorDetails['guzzle_response_status'] ?? null,
                            'guzzle_response_body'   => $errorDetails['guzzle_response_body'] ?? null,
                            'solr_error_code'        => $errorDetails['solr_error_code'] ?? null,
                            'solr_error_details'     => $errorDetails['solr_error_details'] ?? null,
                        ]
                    );

                    // Enhanced error details for configSet failure.
                    if ($this->lastErrorDetails === null) {
                        $this->lastErrorDetails = [
                            'operation'       => 'ensureTenantConfigSet',
                            'step'            => 2,
                            'step_name'       => 'ConfigSet Creation',
                            'error_type'      => 'configset_creation_failure',
                            'error_message'   => 'Failed to create tenant configSet "'.$tenantConfigSetName.'"',
                            'configSet'       => $tenantConfigSetName,
                            'template'        => '_default',
                            'troubleshooting' => [
                                'Check if SOLR server has write permissions for config directory',
                                'Verify template configSet "_default" exists in SOLR',
                                'Ensure SOLR is running in SolrCloud mode',
                                'Check ZooKeeper connectivity in SolrCloud setup',
                                'Check SOLR admin UI for existing configSets',
                            ],
                        ];
                    }

                    return false;
                }//end if

                $this->trackStep(
                    stepNumber: 2,
                    stepName: 'EnsureTenantConfigSet',
                    status: 'completed',
                    description: 'Tenant configSet "'.$tenantConfigSetName.'" is available'
                );
                $this->setupProgress['completed_steps']++;
            } catch (\Exception $e) {
                $this->trackStep(
                    stepNumber: 2,
                    stepName: 'EnsureTenantConfigSet',
                    status: 'failed',
                    description: $e->getMessage(),
                    details: [
                        'exception_type' => get_class($e),
                        'configSet'      => $tenantConfigSetName,
                    ]
                );

                $this->lastErrorDetails = [
                    'operation'      => 'ensureTenantConfigSet',
                    'step'           => 2,
                    'step_name'      => 'ConfigSet Creation',
                    'error_type'     => 'configset_exception',
                    'error_message'  => $e->getMessage(),
                    'exception_type' => get_class($e),
                    'configSet'      => $tenantConfigSetName,
                ];
                return false;
            }//end try

            // Step 3: Force ConfigSet Propagation (always run for safety).
            $this->trackStep(
                stepNumber: 3,
                stepName: 'ConfigSet Propagation',
                status: 'started',
                description: 'Forcing configSet propagation across SOLR cluster nodes'
            );

            try {
                $propagationResult = $this->forceConfigSetPropagation($tenantConfigSetName);

                if ($propagationResult['success'] !== true) {
                    $this->trackStep(
                        stepNumber: 3,
                        stepName: 'ConfigSet Propagation',
                        status: 'failed',
                        description: 'ConfigSet propagation failed',
                        details: [
                            'configSet'           => $tenantConfigSetName,
                            'error'               => $propagationResult['error'] ?? 'Unknown error',
                            'propagation_details' => [
                                'successful_operations' => $propagationResult['successful_operations'] ?? 0,
                                'total_operations'      => $propagationResult['total_operations'] ?? 0,
                                'operations_attempted'  => $propagationResult['operations'] ?? [],
                                'api_calls'             => $this->getApiCallsFromResult($propagationResult),
                                'detailed_operations'   => $propagationResult['operations'] ?? [],
                            ],
                        ]
                    );

                        // Note: Propagation failure is not critical, so we continue but log the issue.
                    $this->logger->warning(
                        'ConfigSet propagation failed but continuing with setup',
                        [
                            'configSet' => $tenantConfigSetName,
                            'error'     => $propagationResult['error'] ?? 'Unknown error',
                        ]
                    );
                }//end if

                if ($propagationResult['success'] === true) {
                    $this->trackStep(
                        stepNumber: 3,
                        stepName: 'ConfigSet Propagation',
                        status: 'completed',
                        description: 'ConfigSet propagation completed successfully',
                        details: [
                            'configSet'           => $tenantConfigSetName,
                            'propagation_details' => [
                                'successful_operations' => $propagationResult['successful_operations'] ?? 0,
                                'total_operations'      => $propagationResult['total_operations'] ?? 0,
                                'operations_performed'  => $propagationResult['operations'] ?? [],
                                'cluster_sync_status'   => $propagationResult['cluster_sync'] ?? 'unknown',
                                'cache_refresh_status'  => $propagationResult['cache_refresh'] ?? 'unknown',
                                'api_calls'             => $this->getApiCallsFromResult($propagationResult),
                                'detailed_operations'   => $propagationResult['operations'] ?? [],
                            ],
                        ]
                    );
                }//end if

                $this->setupProgress['completed_steps']++;
            } catch (\Exception $e) {
                $this->trackStep(
                    stepNumber: 3,
                    stepName: 'ConfigSet Propagation',
                    status: 'failed',
                    description: 'Exception during configSet propagation: '.$e->getMessage(),
                    details: [
                        'exception_type' => get_class($e),
                        'configSet'      => $tenantConfigSetName,
                    ]
                );

                    // Note: Propagation exception is not critical, so we continue but log the issue.
                $this->logger->warning(
                    'Exception during configSet propagation but continuing with setup',
                    [
                        'configSet' => $tenantConfigSetName,
                        'error'     => $e->getMessage(),
                    ]
                );
                $this->setupProgress['completed_steps']++;
            }//end try

            // Step 4: Ensure tenant collection exists.
            $tenantCollectionName = $this->getTenantCollectionName();
            $this->trackStep(
                stepNumber: 4,
                stepName: 'Collection Creation',
                status: 'started',
                description: 'Checking and creating tenant collection "'.$tenantCollectionName.'"'
            );

            try {
                // Ensure tenant collection exists (using tenant-specific configSet).
                if ($this->ensureTenantCollectionExists() === false) {
                    $tenantConfigSetName = $this->getTenantConfigSetName();
                    $this->trackStep(
                        stepNumber: 4,
                        stepName: 'Collection Creation',
                        status: 'failed',
                        description: 'Failed to create tenant collection',
                        details: [
                            'collection'    => $tenantCollectionName,
                            'configSet'     => $tenantConfigSetName,
                            'error_details' => $this->lastErrorDetails,
                        ]
                    );

                    // Enhanced error details for collection failure.
                    if ($this->lastErrorDetails === null) {
                        $this->lastErrorDetails = [
                            'primary_error'      => 'Failed to create tenant collection "'.$tenantCollectionName.'"',
                            'error_type'         => 'collection_creation_failure',
                            'operation'          => 'ensureTenantCollectionExists',
                            'step'               => 4,
                            'step_name'          => 'Collection Creation',
                            'url_attempted'      => 'unknown',
                            'exception_type'     => 'unknown',
                            'error_category'     => 'unknown',
                            'solr_response'      => null,
                            'guzzle_details'     => [],
                            'configuration_used' => [
                                'host'   => $this->solrConfig['host'] ?? 'unknown',
                                'port'   => $this->solrConfig['port'] ?? 'default',
                                'scheme' => $this->solrConfig['scheme'] ?? 'http',
                                'path'   => $this->solrConfig['path'] ?? '/solr',
                            ],
                        ];
                    }

                    return false;
                }//end if

                $this->trackStep(
                    stepNumber: 4,
                    stepName: 'Collection Creation',
                    status: 'completed',
                    description: 'Tenant collection "'.$tenantCollectionName.'" is available'
                );
                $this->setupProgress['completed_steps']++;
            } catch (\Exception $e) {
                $this->trackStep(
                    stepNumber: 4,
                    stepName: 'Collection Creation',
                    status: 'failed',
                    description: $e->getMessage(),
                    details: [
                        'exception_type' => get_class($e),
                        'collection'     => $tenantCollectionName,
                    ]
                );

                $this->lastErrorDetails = [
                    'operation'      => 'ensureTenantCollectionExists',
                    'step'           => 4,
                    'step_name'      => 'Collection Creation',
                    'error_type'     => 'collection_exception',
                    'error_message'  => $e->getMessage(),
                    'exception_type' => get_class($e),
                    'collection'     => $tenantCollectionName,
                ];
                return false;
            }//end try

            // Step 5: Configure schema fields.
            $this->trackStep(
                stepNumber: 5,
                stepName: 'Schema Configuration',
                status: 'started',
                description: 'Configuring schema fields for ObjectEntity metadata'
            );

            try {
                if ($this->configureSchemaFields() === false) {
                    $this->trackStep(
                        stepNumber: 5,
                        stepName: 'Schema Configuration',
                        status: 'failed',
                        description: 'Failed to configure schema fields'
                    );

                    $this->lastErrorDetails = [
                        'operation'       => 'configureSchemaFields',
                        'step'            => 5,
                        'step_name'       => 'Schema Configuration',
                        'error_type'      => 'schema_configuration_failure',
                        'error_message'   => 'Failed to configure schema fields for ObjectEntity metadata',
                        'troubleshooting' => [
                            'Check SOLR collection is accessible',
                            'Verify schema API is enabled',
                            'Check field type definitions',
                            'Ensure proper field naming conventions',
                        ],
                    ];
                    return false;
                }//end if

                $this->trackStep(
                    stepNumber: 5,
                    stepName: 'Schema Configuration',
                    status: 'completed',
                    description: 'Schema fields configured successfully'
                );
                $this->infrastructureCreated['schema_fields_configured'] = true;
                $this->setupProgress['completed_steps']++;
            } catch (\Exception $e) {
                $this->trackStep(
                    stepNumber: 5,
                    stepName: 'Schema Configuration',
                    status: 'failed',
                    description: $e->getMessage(),
                    details: [
                        'exception_type' => get_class($e),
                    ]
                );

                $this->lastErrorDetails = [
                    'operation'      => 'configureSchemaFields',
                    'step'           => 5,
                    'step_name'      => 'Schema Configuration',
                    'error_type'     => 'schema_exception',
                    'error_message'  => $e->getMessage(),
                    'exception_type' => get_class($e),
                ];
                return false;
            }//end try

            // Step 6: Validate setup.
            $this->trackStep(
                stepNumber: 6,
                stepName: 'Setup Validation',
                status: 'started',
                description: 'Validating SOLR setup completion'
            );

            try {
                if ($this->validateSetup() === false) {
                    $this->trackStep(
                        stepNumber: 6,
                        stepName: 'Setup Validation',
                        status: 'failed',
                        description: 'Setup validation failed'
                    );

                    $this->lastErrorDetails = [
                        'operation'       => 'validateSetup',
                        'step'            => 6,
                        'step_name'       => 'Setup Validation',
                        'error_type'      => 'validation_failure',
                        'error_message'   => 'Setup validation checks failed',
                        'troubleshooting' => [
                            'Check configSet exists and is accessible',
                            'Verify collection exists and is queryable',
                            'Test collection query functionality',
                            'Check SOLR admin UI for status',
                        ],
                    ];
                    return false;
                }//end if

                $this->trackStep(
                    stepNumber: 6,
                    stepName: 'Setup Validation',
                    status: 'completed',
                    description: 'Setup validation passed'
                );
                $this->infrastructureCreated['multi_tenant_ready'] = true;
                $this->infrastructureCreated['cloud_mode']         = true;
                $this->setupProgress['completed_steps']++;
            } catch (\Exception $e) {
                $this->trackStep(
                    stepNumber: 6,
                    stepName: 'Setup Validation',
                    status: 'failed',
                    description: $e->getMessage(),
                    details: [
                        'exception_type' => get_class($e),
                    ]
                );

                $this->lastErrorDetails = [
                    'operation'      => 'validateSetup',
                    'step'           => 6,
                    'step_name'      => 'Setup Validation',
                    'error_type'     => 'validation_exception',
                    'error_message'  => $e->getMessage(),
                    'exception_type' => get_class($e),
                ];
                return false;
            }//end try

            // Mark setup as completed successfully.
            $this->setupProgress['completed_at'] = date('Y-m-d H:i:s');
            $this->setupProgress['success']      = true;

            $tenantCollectionName = $this->getTenantCollectionName();
            $tenantConfigSetName  = $this->getTenantConfigSetName();
            $solrHost   = $this->solrConfig['host'] ?? 'localhost';
            $solrPort   = $this->solrConfig['port'] ?? '8983';
            $adminUiUrl = 'http://'.$solrHost.':'.$solrPort.'/solr/';
            $this->logger->info(
                '✅ SOLR setup completed successfully (SolrCloud mode)',
                [
                    'tenant_configSet_created'  => $tenantConfigSetName,
                    'tenant_collection_created' => $tenantCollectionName,
                    'schema_fields_configured'  => true,
                    'setup_validated'           => true,
                    'completed_steps'           => $this->setupProgress['completed_steps'],
                    'total_steps'               => $this->setupProgress['total_steps'],
                    'solr_host'                 => $solrHost,
                    'solr_port'                 => $solrPort,
                    'admin_ui_url'              => $adminUiUrl,
                ]
            );

            return true;
        } catch (\Exception $e) {
            $this->setupProgress['completed_at'] = date('Y-m-d H:i:s');
            $this->setupProgress['success']      = false;

            $this->logger->error(
                'SOLR setup failed',
                [
                    'error'           => $e->getMessage(),
                    'completed_steps' => $this->setupProgress['completed_steps'],
                    'total_steps'     => $this->setupProgress['total_steps'],
                    'trace'           => $e->getTraceAsString(),
                ]
            );

            // Store general failure details if no specific error was captured.
            if ($this->lastErrorDetails === null) {
                $this->lastErrorDetails = [
                    'operation'       => 'setupSolr',
                    'error_type'      => 'general_setup_failure',
                    'error_message'   => $e->getMessage(),
                    'exception_type'  => get_class($e),
                    'completed_steps' => $this->setupProgress['completed_steps'],
                    'total_steps'     => $this->setupProgress['total_steps'],
                ];
            }

            return false;
        }//end try
    }//end setupSolr()

    /**
     * Verify SOLR connectivity using IndexService for consistency
     *
     * **CONSISTENCY FIX**: Uses the same comprehensive connectivity testing
     * as all other parts of the system to ensure consistent behavior.
     *
     * @return bool True if SOLR is accessible and responding correctly
     */
    private function verifySolrConnectivity(): bool
    {
        try {
            // **SETUP-OPTIMIZED**: Use connectivity-only test for setup scenarios.
            // Collections don't exist yet during setup, so we only test SOLR/Zookeeper connectivity.
            $connectionTest = $this->solrService->testConnectivityOnly();
            $isConnected    = $connectionTest['success'] ?? false;

            if ($isConnected !== true) {
                $this->logger->error(
                    'SOLR connectivity verification failed using IndexService',
                    [
                        'test_message' => $connectionTest['message'] ?? 'Connection test failed',
                        'components'   => $connectionTest['components'] ?? [],
                        'details'      => $connectionTest['details'] ?? [],
                    ]
                );

                // Store detailed error information for better troubleshooting.
                $this->lastErrorDetails = [
                    'operation'              => 'verifySolrConnectivity',
                    'error_type'             => 'connectivity_test_failure',
                    'error_message'          => $connectionTest['message'] ?? 'Connection test failed',
                    'connection_test_result' => $connectionTest,
                    'troubleshooting'        => [
                        'Check if SOLR server is running and accessible',
                        'Verify host/port configuration in settings',
                        'Check network connectivity between containers',
                        'Verify authentication credentials if required',
                        'Check SOLR admin UI manually: '.$this->buildSolrUrl('/solr/'),
                    ],
                ];

                return false;
            }//end if

            $this->logger->info(
                'SOLR connectivity verified successfully using IndexService',
                [
                    'test_message'              => $connectionTest['message'] ?? 'Connection test passed',
                    'components_tested'         => array_keys($connectionTest['components'] ?? []),
                    'all_components_successful' => $this->allComponentsSuccessful($connectionTest['components'] ?? []),
                ]
            );
            return true;
        } catch (\Exception $e) {
            $this->logger->error(
                'SOLR connectivity verification failed - exception during IndexService test',
                [
                    'error'           => $e->getMessage(),
                    'exception_class' => get_class($e),
                    'file'            => $e->getFile(),
                    'line'            => $e->getLine(),
                ]
            );

            // Store detailed error information.
            $this->lastErrorDetails = [
                'operation'       => 'verifySolrConnectivity',
                'error_type'      => 'connectivity_exception',
                'error_message'   => $e->getMessage(),
                'exception_class' => get_class($e),
                'exception_file'  => $e->getFile(),
                'exception_line'  => $e->getLine(),
            ];

            return false;
        }//end try
    }//end verifySolrConnectivity()

    /**
     * Check if all components in a connection test were successful
     *
     * @param array $components Components test results
     *
     * @return bool True if all components passed
     */
    private function allComponentsSuccessful(array $components): bool
    {
        foreach ($components as $componentName => $result) {
            // Suppress unused variable warning for $componentName - only checking results.
            unset($componentName);
            if (($result['success'] ?? false) === false) {
                return false;
            }
        }

        return true;
    }//end allComponentsSuccessful()

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

        // Check if configSet already exists.
        if ($this->configSetExists($tenantConfigSetName) === true) {
            $this->logger->info(
                'Tenant configSet already exists (skipping creation)',
                [
                    'configSet' => $tenantConfigSetName,
                ]
            );
            // Track existing configSet as skipped (not newly created).
            if (in_array($tenantConfigSetName, $this->infrastructureCreated['configsets_skipped']) === false) {
                $this->infrastructureCreated['configsets_skipped'][] = $tenantConfigSetName;
            }

            // Even for existing configSets, force propagation to ensure availability.
            // This handles cases where configSet exists but isn't fully propagated.
            $propagationResult = $this->forceConfigSetPropagation($tenantConfigSetName);
            $this->logger->info(
                'ConfigSet propagation attempted for existing configSet',
                [
                    'configSet' => $tenantConfigSetName,
                    'result'    => $propagationResult,
                ]
            );

            return true;
        }//end if

        // Upload configSet from ZIP file (bypasses trusted configSet authentication).
        $this->logger->info(
            'Uploading tenant configSet from ZIP file',
            [
                'configSet' => $tenantConfigSetName,
                'method'    => 'ZIP upload (avoids SolrCloud authentication issues)',
            ]
        );
        return $this->uploadConfigSet($tenantConfigSetName);
    }//end ensureTenantConfigSet()

    /**
     * Check if a SOLR configSet exists
     *
     * @param string $configSetName Name of the configSet to check
     *
     * @return bool True if configSet exists, false otherwise
     */
    private function configSetExists(string $configSetName): bool
    {
        $url = $this->buildSolrUrl('/admin/configs?action=LIST&wt=json');

        $this->logger->debug(
            'Checking if configSet exists',
            [
                'configSet' => $configSetName,
                'url'       => $url,
            ]
        );

        try {
            $requestOptions = ['timeout' => 10];

            // Add authentication if configured.
            if (empty($this->solrConfig['username']) === false && empty($this->solrConfig['password']) === false) {
                $requestOptions['auth'] = [$this->solrConfig['username'], $this->solrConfig['password']];
            }

            $response = $this->httpClient->get($url, $requestOptions);

            if ($response->getStatusCode() !== 200) {
                $this->logger->warning(
                    'Failed to check configSet existence - HTTP error',
                    [
                        'configSet'     => $configSetName,
                        'url'           => $url,
                        'status_code'   => $response->getStatusCode(),
                        'response_body' => (string) $response->getBody(),
                        'assumption'    => 'Assuming configSet does not exist',
                    ]
                );
                return false;
            }

            $data = json_decode((string) $response->getBody(), true);
        } catch (\Exception $e) {
            $this->logger->warning(
                'Failed to check configSet existence - HTTP request failed',
                [
                    'configSet'      => $configSetName,
                    'url'            => $url,
                    'error'          => $e->getMessage(),
                    'exception_type' => get_class($e),
                    'assumption'     => 'Assuming configSet does not exist',
                ]
            );
            return false;
        }//end try

        if ($data === null) {
            $this->logger->warning(
                'Failed to check configSet existence - Invalid JSON response',
                [
                    'configSet'  => $configSetName,
                    'url'        => $url,
                    'json_error' => json_last_error_msg(),
                    'assumption' => 'Assuming configSet does not exist',
                ]
            );
            return false;
        }

        $configSets = $data['configSets'] ?? [];
        $exists     = in_array($configSetName, $configSets);

        $this->logger->debug(
            'ConfigSet existence check completed',
            [
                'configSet'            => $configSetName,
                'exists'               => $exists,
                'available_configSets' => $configSets,
            ]
        );

        return $exists;
    }//end configSetExists()

    /**
     * Create a new SOLR configSet based on an existing template
     *
     * @param string $newConfigSetName      Name for the new configSet
     * @param string $templateConfigSetName Name of the template configSet to copy from
     *
     * @return bool True if configSet was created successfully
     */
    private function createConfigSet(string $newConfigSetName, string $templateConfigSetName): bool
    {
        // First, test basic SOLR connectivity before attempting configSet creation.
        $this->logger->info(
            'Testing SOLR connectivity before configSet creation',
            [
                'configSet' => $newConfigSetName,
            ]
        );

        // Use IndexService's comprehensive connectivity test instead of simple ping.
        try {
            $connectionTest = $this->solrService->testConnection();
            if ($connectionTest['success'] !== true) {
                $this->logger->error(
                    'SOLR connectivity test failed before configSet creation',
                    [
                        'test_message' => $connectionTest['message'] ?? 'Connection failed',
                        'details'      => $connectionTest['details'] ?? [],
                    ]
                );
                // Continue anyway - connectivity test might fail but configSet creation might still work.
            }

            if ($connectionTest['success'] === true) {
                $this->logger->info(
                    'SOLR connectivity test successful',
                    [
                        'test_message'      => $connectionTest['message'] ?? 'Connection verified',
                        'components_tested' => array_keys($connectionTest['components'] ?? []),
                    ]
                );
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                'SOLR connectivity test threw exception before configSet creation',
                [
                    'error'          => $e->getMessage(),
                    'exception_type' => get_class($e),
                ]
            );
            // Continue anyway - connectivity test might not be available but admin endpoints might work.
        }//end try

        // Use SolrCloud ConfigSets API for configSet creation with authentication.
        $url = $this->buildSolrUrl(
            sprintf(
                '/admin/configs?action=CREATE&name=%s&baseConfigSet=%s&wt=json',
                urlencode($newConfigSetName),
                urlencode($templateConfigSetName)
            )
        );

        $this->logger->info(
            'Attempting to create SOLR configSet',
            [
                'configSet'                 => $newConfigSetName,
                'template'                  => $templateConfigSetName,
                'url'                       => $url,
                'authentication_configured' => empty($this->solrConfig['username']) === false
                    && empty($this->solrConfig['password']) === false,
            ]
        );

        try {
            // Use Guzzle HTTP client with proper timeout, headers, and authentication for SolrCloud.
            $requestOptions = [
                'timeout' => 30,
                'headers' => [
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ];

            // Add authentication if configured.
            if (empty($this->solrConfig['username']) === false && empty($this->solrConfig['password']) === false) {
                $requestOptions['auth'] = [$this->solrConfig['username'], $this->solrConfig['password']];
                $this->logger->info(
                    'Using HTTP Basic authentication for SOLR configSet creation',
                    [
                        'username' => $this->solrConfig['username'],
                        'url'      => $url,
                    ]
                );
            }

            $response = $this->httpClient->get($url, $requestOptions);

            if ($response->getStatusCode() !== 200) {
                $responseBody = (string) $response->getBody();
                $this->logger->error(
                    'Failed to create configSet - HTTP error',
                    [
                        'configSet'       => $newConfigSetName,
                        'template'        => $templateConfigSetName,
                        'url'             => $url,
                        'status_code'     => $response->getStatusCode(),
                        'response_body'   => $responseBody,
                        'possible_causes' => [
                            'SOLR server not reachable at configured URL',
                            'Network connectivity issues',
                            'SOLR server not responding',
                            'Invalid SOLR configuration (host/port/path)',
                            'SOLR server overloaded or timeout',
                        ],
                    ]
                );

                // Store detailed error information for API response.
                $this->lastErrorDetails = [
                    'operation'              => 'createConfigSet',
                    'error_type'             => 'http_error',
                    'error_message'          => 'HTTP error '.$response->getStatusCode(),
                    'url_attempted'          => $url,
                    'guzzle_response_status' => $response->getStatusCode(),
                    'guzzle_response_body'   => $responseBody,
                    'solr_error_code'        => null,
                    'solr_error_details'     => null,
                    'configuration_used'     => $this->solrConfig,
                ];

                return false;
            }//end if

            $data = json_decode((string) $response->getBody(), true);
        } catch (\Exception $e) {
            // Enhanced exception logging for HTTP client issues.
            $logData = [
                'configSet'      => $newConfigSetName,
                'template'       => $templateConfigSetName,
                'url'            => $url,
                'error'          => $e->getMessage(),
                'exception_type' => get_class($e),
                'file'           => $e->getFile(),
                'line'           => $e->getLine(),
            ];

            // Extract additional details from Guzzle exceptions.
            if ($e instanceof \GuzzleHttp\Exception\RequestException) {
                $logData['guzzle_request_exception'] = true;
                if ($e->hasResponse() === true) {
                    $response = $e->getResponse();
                    if ($response !== null) {
                        $logData['response_status']  = $response->getStatusCode();
                        $logData['response_body']    = (string) $response->getBody();
                        $logData['response_headers'] = $response->getHeaders();
                    }
                }

                if ($e->getRequest() !== null) {
                    $request = $e->getRequest();
                    $logData['request_method']  = $request->getMethod();
                    $logData['request_uri']     = (string) $request->getUri();
                    $logData['request_headers'] = $request->getHeaders();
                }
            }

            // Check for authentication issues.
            if (strpos($e->getMessage(), '401') !== false || strpos($e->getMessage(), 'Unauthorized') !== false) {
                $logData['authentication_issue'] = true;
                $logData['has_credentials']      = empty($this->solrConfig['username']) === false
                    && empty($this->solrConfig['password']) === false;
            }

            // Check for network connectivity issues.
            if (strpos($e->getMessage(), 'Connection refused') !== false
                || strpos($e->getMessage(), 'Could not resolve host') !== false
                || strpos($e->getMessage(), 'timeout') !== false
            ) {
                $logData['network_connectivity_issue'] = true;
            }

            $logData['possible_causes'] = [
                'SOLR server not reachable at configured URL',
                'Network connectivity issues',
                'SOLR server not responding',
                'Invalid SOLR configuration (host/port/path)',
                'SOLR server overloaded or timeout',
                'Authentication failure (check username/password)',
                'Kubernetes service name resolution failure',
            ];

            $this->logger->error('Failed to create configSet - HTTP request failed', $logData);

            // Store detailed error information for API response.
            $this->lastErrorDetails = [
                'operation'      => 'createConfigSet',
                'configSet'      => $newConfigSetName,
                'template'       => $templateConfigSetName,
                'url_attempted'  => $url,
                'error_type'     => 'http_request_failed',
                'error_message'  => $e->getMessage(),
                'exception_type' => get_class($e),
                'guzzle_details' => [],
            ];

            // Add Guzzle-specific details if available.
            if ($e instanceof \GuzzleHttp\Exception\RequestException) {
                $this->lastErrorDetails['guzzle_details']['is_request_exception'] = true;

                if ($e->hasResponse() === true) {
                    $response = $e->getResponse();
                    if ($response !== null) {
                        $responseStatus = $response->getStatusCode();
                        $responseBody   = (string) $response->getBody();

                        // Store in guzzle_details for comprehensive logging.
                        $this->lastErrorDetails['guzzle_details']['response_status']  = $responseStatus;
                        $this->lastErrorDetails['guzzle_details']['response_body']    = $responseBody;
                        $this->lastErrorDetails['guzzle_details']['response_headers'] = $response->getHeaders();

                        // Also store at top level for step tracking consistency.
                        $this->lastErrorDetails['guzzle_response_status'] = $responseStatus;
                        $this->lastErrorDetails['guzzle_response_body']   = $responseBody;
                    }
                }

                if ($e->getRequest() !== null) {
                    $request = $e->getRequest();
                    $this->lastErrorDetails['guzzle_details']['request_method']  = $request->getMethod();
                    $this->lastErrorDetails['guzzle_details']['request_uri']     = (string) $request->getUri();
                    $this->lastErrorDetails['guzzle_details']['request_headers'] = $request->getHeaders();
                }
            }//end if

            // Add specific error categorization.
            $this->lastErrorDetails['error_category'] = 'unknown_http_error';
            if (strpos($e->getMessage(), '401') !== false || strpos($e->getMessage(), 'Unauthorized') !== false) {
                $this->lastErrorDetails['error_category']  = 'authentication_failure';
                $this->lastErrorDetails['has_credentials'] = empty($this->solrConfig['username']) === false
                    && empty($this->solrConfig['password']) === false;
            } else if (strpos($e->getMessage(), 'Connection refused') !== false
                || strpos($e->getMessage(), 'Could not resolve host') !== false
                || strpos($e->getMessage(), 'timeout') !== false
            ) {
                $this->lastErrorDetails['error_category'] = 'network_connectivity';
            }

            return false;
        }//end try

        if ($data === null) {
            $this->logger->error(
                'Failed to create configSet - Invalid JSON response',
                [
                    'configSet'    => $newConfigSetName,
                    'template'     => $templateConfigSetName,
                    'url'          => $url,
                    'raw_response' => (string) $response->getBody(),
                    'json_error'   => json_last_error_msg(),
                ]
            );

            // Store detailed error information for API response.
            $this->lastErrorDetails = [
                'operation'        => 'createConfigSet',
                'configSet'        => $newConfigSetName,
                'template'         => $templateConfigSetName,
                'url_attempted'    => $url,
                'error_type'       => 'invalid_json_response',
                'error_message'    => 'SOLR returned invalid JSON response',
                'json_error'       => json_last_error_msg(),
                'raw_response'     => (string) $response->getBody(),
                'response_status'  => $response->getStatusCode(),
                'response_headers' => $response->getHeaders(),
            ];

            return false;
        }//end if

        $status = $data['responseHeader']['status'] ?? -1;
        if ($status === 0) {
            $this->logger->info(
                'ConfigSet created successfully',
                [
                    'configSet' => $newConfigSetName,
                ]
            );
            return true;
        }

        // Extract detailed error information from SOLR response.
        $errorMsg     = $data['error']['msg'] ?? 'Unknown SOLR error';
        $errorCode    = $data['error']['code'] ?? $status;
        $errorDetails = $data['error']['metadata'] ?? [];

        $this->logger->error(
            'ConfigSet creation failed - SOLR returned error',
            [
                'configSet'            => $newConfigSetName,
                'template'             => $templateConfigSetName,
                'url'                  => $url,
                'solr_status'          => $status,
                'solr_error_message'   => $errorMsg,
                'solr_error_code'      => $errorCode,
                'solr_error_details'   => $errorDetails,
                'full_response'        => $data,
                'troubleshooting_tips' => [
                    'Verify template configSet exists: '.$templateConfigSetName,
                    'Check SOLR admin UI for existing configSets',
                    'Ensure SOLR has write permissions for config directory',
                    'Verify SOLR is running in SolrCloud mode if using collections',
                    'Check SOLR logs for additional error details',
                ],
            ]
        );

        // Store detailed error information for API response.
        $this->lastErrorDetails = [
            'operation'            => 'createConfigSet',
            'configSet'            => $newConfigSetName,
            'template'             => $templateConfigSetName,
            'url_attempted'        => $url,
            'error_type'           => 'solr_api_error',
            'error_message'        => $errorMsg,
            'solr_status'          => $status,
            'solr_error_code'      => $errorCode,
            'solr_error_details'   => $errorDetails,
            'full_solr_response'   => $data,
            'troubleshooting_tips' => [
                'Verify template configSet "'.$templateConfigSetName.'" exists in SOLR',
                'Check SOLR admin UI for existing configSets',
                'Ensure SOLR has write permissions for config directory',
                'Verify SOLR is running in SolrCloud mode if using collections',
                'Check SOLR logs for additional error details',
            ],
        ];

        return false;
    }//end createConfigSet()

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

        // Check if tenant collection already exists.
        if ($this->solrService->collectionExists($tenantCollectionName) === true) {
            $this->logger->info(
                'Tenant collection already exists (skipping creation)',
                [
                    'collection' => $tenantCollectionName,
                ]
            );

            // Track existing collection as skipped (not newly created).
            if (in_array($tenantCollectionName, $this->infrastructureCreated['collections_skipped']) === false) {
                $this->infrastructureCreated['collections_skipped'][] = $tenantCollectionName;
            }

            return true;
        }

        // Create tenant collection using the tenant-specific configSet.
        $tenantConfigSetName = $this->getTenantConfigSetName();
        $this->logger->info(
            'Creating tenant collection',
            [
                'collection' => $tenantCollectionName,
                'configSet'  => $tenantConfigSetName,
            ]
        );

        try {
            // Attempt collection creation with retry logic for configSet propagation delays.
            $success = $this->createCollectionWithRetry(
                collectionName: $tenantCollectionName,
                configSetName: $tenantConfigSetName
            );

            // Track newly created collection.
            $alreadyCreated = in_array($tenantCollectionName, $this->infrastructureCreated['collections_created']);
            if ($success === true && $alreadyCreated === false) {
                $this->infrastructureCreated['collections_created'][] = $tenantCollectionName;
            }

            return $success;
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            // Capture Guzzle HTTP errors (network, timeout, etc.).
            $requestMethod = 'unknown';
            $responseCode  = null;
            $responseBody  = null;
            $urlAttempted  = 'unknown';

            // @psalm-suppress UndefinedInterfaceMethod - Methods exist on specific exception types
            if (method_exists($e, 'getRequest') === true && $e->getRequest() !== null) {
                $requestMethod = $e->getRequest()->getMethod();
                $urlAttempted  = (string) $e->getRequest()->getUri();
            }

            // @psalm-suppress UndefinedInterfaceMethod - Methods exist on specific exception types
            $hasResponseMethod = method_exists($e, 'hasResponse') === true && $e->hasResponse() === true;
            if ($hasResponseMethod === true && method_exists($e, 'getResponse') === true) {
                $response = $e->getResponse();
                if ($response !== null) {
                    $responseCode = $response->getStatusCode();
                    $responseBody = (string) $response->getBody();
                }
            }

            $this->lastErrorDetails = [
                'primary_error'     => 'HTTP request to SOLR failed',
                'error_type'        => 'guzzle_http_error',
                'operation'         => 'ensureTenantCollectionExists',
                'step'              => 3,
                'step_name'         => 'Collection Creation',
                'collection'        => $tenantCollectionName,
                'configSet'         => $tenantConfigSetName,
                'url_attempted'     => $urlAttempted,
                'exception_type'    => get_class($e),
                'exception_message' => $e->getMessage(),
                'error_category'    => 'network_connectivity',
                'guzzle_details'    => [
                    'request_method' => $requestMethod,
                    'response_code'  => $responseCode,
                    'response_body'  => $responseBody,
                ],
            ];

            $this->logger->error('Guzzle HTTP error during collection creation', $this->lastErrorDetails);
            return false;
        } catch (\Exception $e) {
            // Capture SOLR API errors (400 responses, validation errors, etc.).
            $solrResponse  = null;
            $errorCategory = 'solr_api_error';
            $retryDetails  = null;

            // Try to extract retry details and SOLR response from nested exception.
            if (($e->getPrevious() !== null) === true && ($e->getPrevious()->getMessage() !== null) === true) {
                $possibleJson    = $e->getPrevious()->getMessage();
                $decodedResponse = json_decode($possibleJson, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    // Regular SOLR response (default).
                    $solrResponse  = $decodedResponse;
                    $errorCategory = 'solr_validation_error';

                    // Check if this is retry details from createCollectionWithRetry.
                    $hasAttempts   = ($decodedResponse['attempts'] ?? null) !== null;
                    $hasTimestamps = ($decodedResponse['attempt_timestamps'] ?? null) !== null;
                    if ($hasAttempts === true && $hasTimestamps === true) {
                        $retryDetails = $decodedResponse;
                        $solrResponse = $decodedResponse['last_solr_response'] ?? null;
                    }
                }
            }

            // Log the collection creation failure with full details.
            $this->logger->error(
                'Collection creation failed',
                [
                    'collection'     => $tenantCollectionName,
                    'configSet'      => $tenantConfigSetName,
                    'original_error' => $e->getMessage(),
                    'error_type'     => get_class($e),
                ]
            );

            // Determine SOLR response value for error details.
            // Note: $retryDetails is checked but $solrResponseValue calculation was incorrect.
            // Simplified to just use $solrResponse directly.
            // If ($retryDetails === true) {
            // $solrResponseValue = $retryDetails;
            // }.
            $this->lastErrorDetails = [
                'primary_error'      => 'Failed to create tenant collection "'.$tenantCollectionName.'"',
                'error_type'         => 'collection_creation_failure',
                'operation'          => 'ensureTenantCollectionExists',
                'step'               => 4,
                'step_name'          => 'Collection Creation',
                'collection'         => $tenantCollectionName,
                'configSet'          => $tenantConfigSetName,
                'url_attempted'      => 'SOLR Collections API',
                'exception_type'     => get_class($e),
                'exception_message'  => $e->getMessage(),
                'error_category'     => $errorCategory,
                'solr_response'      => null,
                'guzzle_details'     => [],
                'configuration_used' => [
                    'host'   => $this->solrConfig['host'] ?? 'unknown',
                    'port'   => $this->solrConfig['port'] ?? 'default',
                    'scheme' => $this->solrConfig['scheme'] ?? 'http',
                    'path'   => $this->solrConfig['path'] ?? '/solr',
                ],
            ];

            $this->logger->error('SOLR collection creation exception', $this->lastErrorDetails);
            return false;
        }//end try
    }//end ensureTenantCollectionExists()

    /**
     * Create collection with retry logic for configSet propagation delays
     *
     * This addresses the ZooKeeper propagation delay issue by directly attempting
     * collection creation with exponential backoff retry logic instead of polling.
     *
     * @param string $collectionName Collection name to create
     * @param string $configSetName  ConfigSet name to use
     * @param int    $maxAttempts    Maximum number of retry attempts (default: 6 - up to ~120 seconds)
     *
     * @return bool True if collection created successfully
     *
     * @throws \Exception If all retry attempts fail
     */
    private function createCollectionWithRetry(string $collectionName, string $configSetName, int $maxAttempts=6): bool
    {
        $attempt          = 0;
        $baseDelaySeconds = 2;
        // Start with 2 second delay.
        $startTime    = time();
        $retryDetails = [
            'attempts'            => 0,
            'total_delay_seconds' => 0,
            'attempt_timestamps'  => [],
            'last_error'          => null,
            'last_solr_response'  => null,
        ];

        while ($attempt < $maxAttempts) {
            $attempt++;

            try {
                $retryDetails['attempts'] = $attempt;
                $retryDetails['attempt_timestamps'][] = date('Y-m-d H:i:s');

                $this->logger->info(
                    'Attempting collection creation',
                    [
                        'collection'      => $collectionName,
                        'configSet'       => $configSetName,
                        'attempt'         => $attempt,
                        'maxAttempts'     => $maxAttempts,
                        'elapsed_seconds' => time() - $startTime,
                    ]
                );

                // Direct attempt to create collection.
                $result  = $this->solrService->createCollection(
                    name: $collectionName,
                    config: ['configSet' => $configSetName]
                );
                $success = isset($result['success']) && $result['success'] === true;

                if ($success === true) {
                    $totalElapsed = time() - $startTime;
                    $this->logger->info(
                        'Collection created successfully',
                        [
                            'collection'            => $collectionName,
                            'configSet'             => $configSetName,
                            'attempt'               => $attempt,
                            'total_elapsed_seconds' => $totalElapsed,
                            'retry_details'         => $retryDetails,
                        ]
                    );
                    return true;
                }
            } catch (\Exception $e) {
                $errorMessage     = $e->getMessage();
                $isConfigSetError = $this->isConfigSetPropagationError($errorMessage);

                // Capture the detailed error information.
                $retryDetails['last_error'] = $errorMessage;

                // Try to extract SOLR response from the exception.
                if (($e->getPrevious() !== null) === true && ($e->getPrevious()->getMessage() !== null) === true) {
                    try {
                        $solrResponse = json_decode($e->getPrevious()->getMessage(), true);
                        if (($solrResponse !== null) === true && json_last_error() === JSON_ERROR_NONE) {
                            $retryDetails['last_solr_response'] = $solrResponse;

                            // Log the actual SOLR error for debugging.
                            $this->logger->error(
                                'SOLR API returned error response',
                                [
                                    'collection'    => $collectionName,
                                    'configSet'     => $configSetName,
                                    'attempt'       => $attempt,
                                    'solr_status'   => $solrResponse['responseHeader']['status'] ?? 'unknown',
                                    'solr_error'    => $solrResponse['error'] ?? null,
                                    'solr_response' => $solrResponse,
                                ]
                            );
                        }
                    } catch (\Exception $jsonException) {
                        // If not JSON, store as string.
                        $retryDetails['last_solr_response'] = $e->getPrevious()->getMessage();
                    }//end try
                }//end if

                $this->logger->warning(
                    'Collection creation attempt failed',
                    [
                        'collection'                  => $collectionName,
                        'configSet'                   => $configSetName,
                        'attempt'                     => $attempt,
                        'maxAttempts'                 => $maxAttempts,
                        'error'                       => $errorMessage,
                        'isConfigSetPropagationError' => $isConfigSetError,
                        'solr_response'               => $retryDetails['last_solr_response'],
                    ]
                );

                // If this is the last attempt, provide user-friendly propagation error with retry details.
                if ($attempt >= $maxAttempts && ($isConfigSetError === true)) {
                    $totalElapsed = time() - $startTime;
                    $retryDetails['total_elapsed_seconds'] = $totalElapsed;

                    $msg1    = 'SOLR ConfigSet propagation timeout: ';
                    $msg2    = 'The configSet was created successfully but is still propagating. ';
                    $msg3    = "This is normal in distributed SOLR. Attempted {$attempt} times ";
                    $msg4    = "over {$totalElapsed} seconds. Please wait 2-5 minutes and retry.";
                    $message = $msg1.$msg2.$msg3.$msg4;
                    throw new Exception($message, 500, new Exception(json_encode($retryDetails)));
                }

                // If not a configSet propagation error, throw immediately.
                if ($isConfigSetError === false) {
                    throw $e;
                }

                // Calculate exponential backoff delay: 2, 4, 8, 16 seconds.
                $delaySeconds = $baseDelaySeconds * pow(2, $attempt - 1);
                $retryDetails['total_delay_seconds'] += $delaySeconds;

                $this->logger->info(
                    'Retrying collection creation after delay',
                    [
                        'collection'               => $collectionName,
                        'delaySeconds'             => $delaySeconds,
                        'nextAttempt'              => $attempt + 1,
                        'total_elapsed_seconds'    => time() - $startTime,
                        'cumulative_delay_seconds' => $retryDetails['total_delay_seconds'],
                    ]
                );

                sleep($delaySeconds);
            }//end try
        }//end while

        // Should not reach here due to exception throwing above.
        return false;
    }//end createCollectionWithRetry()

    /**
     * Check if error message indicates configSet propagation delay
     *
     * @param string $errorMessage Error message from SOLR
     *
     * @return bool True if this appears to be a configSet propagation issue
     */
    private function isConfigSetPropagationError(string $errorMessage): bool
    {
        // Only treat as propagation errors if they specifically mention propagation/availability issues.
        $propagationErrorPatterns = [
            'configset does not exist',
            'Config does not exist',
            'Could not find configSet',
            'configSet not found',
            'ConfigSet propagation timeout',
        // Our own timeout message.
        ];

        // "Underlying core creation failed" is NOT a propagation issue - it's a core creation failure.
        // This should fail immediately, not retry.
        foreach ($propagationErrorPatterns as $pattern) {
            if (stripos($errorMessage, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }//end isConfigSetPropagationError()

    /**
     * Try to force configSet propagation across SOLR cluster nodes
     *
     * This attempts to trigger immediate configSet synchronization using
     * various SOLR admin API calls that can help speed up propagation.
     *
     * @param string $configSetName ConfigSet name to force propagation for
     *
     * @return (((int|null|string)[]|string)[]|bool|int|null|string)[] Result array with success status,
     *         operations performed, and details
     *
     * @psalm-return array{
     *     success: bool,
     *     operations: array{
     *         configset_list_refresh: array{
     *             name: 'configset_list_refresh',
     *             description: 'List ConfigSets API call to trigger cache refresh',
     *             url: null|string,
     *             status: 'failed'|'success',
     *             http_status: int|null,
     *             response_size: int<0, max>,
     *             error: null|string
     *         },
     *         cluster_status_sync: array{
     *             name: 'cluster_status_sync',
     *             description: 'Cluster Status API call to trigger ZooKeeper sync',
     *             url: null|string,
     *             status: 'failed'|'success',
     *             http_status: int|null,
     *             response_size: int<0, max>,
     *             error: null|string
     *         }
     *     },
     *     successful_operations: 0|1|2,
     *     total_operations: 2,
     *     cluster_sync: 'failed'|'triggered',
     *     cache_refresh: 'failed'|'triggered',
     *     error: 'All propagation methods failed'|null,
     *     summary: array{
     *         configset_list_refresh: 'failed'|'success',
     *         cluster_status_sync: 'failed'|'success'
     *     }
     * }
     */
    private function forceConfigSetPropagation(string $configSetName): array
    {
        $this->logger->info(
            'Attempting to force configSet propagation',
            [
                'configSet' => $configSetName,
            ]
        );

        $successCount     = 0;
        $operationResults = [];

        // Method 1: List configSets to trigger cache refresh.
        $listOperation = [
            'name'          => 'configset_list_refresh',
            'description'   => 'List ConfigSets API call to trigger cache refresh',
            'url'           => null,
            'status'        => 'failed',
            'http_status'   => null,
            'response_size' => 0,
            'error'         => null,
        ];

        try {
            $url = $this->buildSolrUrl('/admin/configs?action=LIST&wt=json');
            $listOperation['url'] = $url;
            $response = $this->httpClient->get($url, ['timeout' => 10]);

            $listOperation['http_status']   = $response->getStatusCode();
            $listOperation['response_size'] = strlen((string) $response->getBody());

            if ($response->getStatusCode() === 200) {
                $successCount++;
                $listOperation['status'] = 'success';
                $this->logger->debug(
                    'ConfigSet list refresh successful',
                    [
                        'configSet'     => $configSetName,
                        'method'        => 'LIST',
                        'response_size' => $listOperation['response_size'],
                    ]
                );
            }
        } catch (\Exception $e) {
            $listOperation['error'] = $e->getMessage();
            $this->logger->debug(
                'ConfigSet list refresh failed',
                [
                    'configSet' => $configSetName,
                    'method'    => 'LIST',
                    'error'     => $e->getMessage(),
                ]
            );
        }//end try

        $operationResults['configset_list_refresh'] = $listOperation;

        // Method 2: Check cluster status to trigger ZooKeeper sync.
        $clusterOperation = [
            'name'          => 'cluster_status_sync',
            'description'   => 'Cluster Status API call to trigger ZooKeeper sync',
            'url'           => null,
            'status'        => 'failed',
            'http_status'   => null,
            'response_size' => 0,
            'error'         => null,
        ];

        try {
            $url = $this->buildSolrUrl('/admin/collections?action=CLUSTERSTATUS&wt=json');
            $clusterOperation['url'] = $url;
            $response = $this->httpClient->get($url, ['timeout' => 10]);

            $clusterOperation['http_status']   = $response->getStatusCode();
            $clusterOperation['response_size'] = strlen((string) $response->getBody());

            if ($response->getStatusCode() === 200) {
                $successCount++;
                $clusterOperation['status'] = 'success';
                $this->logger->debug(
                    'Cluster status refresh successful',
                    [
                        'configSet'     => $configSetName,
                        'method'        => 'CLUSTERSTATUS',
                        'response_size' => $clusterOperation['response_size'],
                    ]
                );
            }
        } catch (\Exception $e) {
            $clusterOperation['error'] = $e->getMessage();
            $this->logger->debug(
                'Cluster status refresh failed',
                [
                    'configSet' => $configSetName,
                    'method'    => 'CLUSTERSTATUS',
                    'error'     => $e->getMessage(),
                ]
            );
        }//end try

        $operationResults['cluster_status_sync'] = $clusterOperation;

        $this->logger->info(
            'ConfigSet propagation force completed',
            [
                'configSet'          => $configSetName,
                'successful_methods' => $successCount,
                'total_methods'      => 2,
            ]
        );

        // Give a moment for any triggered propagation to begin.
        if ($successCount > 0) {
            sleep(1);
        }

        // Determine cluster sync status.
        $clusterSync = 'failed';
        if ($successCount >= 2) {
            $clusterSync = 'triggered';
        }

        // Determine cache refresh status.
        $cacheRefresh = 'failed';
        if ($successCount >= 1) {
            $cacheRefresh = 'triggered';
        }

        // Determine error message.
        $error = null;
        if ($successCount === 0) {
            $error = 'All propagation methods failed';
        }

        return [
            'success'               => $successCount > 0,
            'operations'            => $operationResults,
            'successful_operations' => $successCount,
            'total_operations'      => 2,
            'cluster_sync'          => $clusterSync,
            'cache_refresh'         => $cacheRefresh,
            'error'                 => $error,
            'summary'               => [
                'configset_list_refresh' => $operationResults['configset_list_refresh']['status'],
                'cluster_status_sync'    => $operationResults['cluster_status_sync']['status'],
            ],
        ];
    }//end forceConfigSetPropagation()

    /**
     * Create a new SOLR collection using a configSet (SolrCloud)
     *
     * @param string $collectionName Name for the new collection
     * @param string $configSetName  Name of the configSet to use
     *
     * @return bool True if collection was created successfully
     */
    private function createCollection(string $collectionName, string $configSetName): bool
    {
        $url = $this->buildSolrUrl(
            sprintf(
                '/admin/collections?action=CREATE&name=%s&collection.configName=%s&numShards=1&replicationFactor=1&wt=json',
                urlencode($collectionName),
                urlencode($configSetName)
            )
        );

        $this->logger->info(
            'Attempting to create SOLR collection',
            [
                'collection' => $collectionName,
                'configSet'  => $configSetName,
                'url'        => $url,
            ]
        );

        try {
            $response = $this->httpClient->get($url, ['timeout' => 30]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->error(
                    'Failed to create collection - HTTP error',
                    [
                        'collection'    => $collectionName,
                        'configSet'     => $configSetName,
                        'url'           => $url,
                        'status_code'   => $response->getStatusCode(),
                        'response_body' => (string) $response->getBody(),
                    ]
                );

                // Store detailed error information for API response.
                $this->lastErrorDetails = [
                    'operation'       => 'createCollection',
                    'collection'      => $collectionName,
                    'configSet'       => $configSetName,
                    'url_attempted'   => $url,
                    'error_type'      => 'http_error',
                    'error_message'   => 'HTTP request failed with status '.$response->getStatusCode(),
                    'response_status' => $response->getStatusCode(),
                    'response_body'   => (string) $response->getBody(),
                ];

                return false;
            }//end if

            $data = json_decode((string) $response->getBody(), true);

            if ($data === null) {
                $this->logger->error(
                    'Failed to create collection - Invalid JSON response',
                    [
                        'collection'   => $collectionName,
                        'configSet'    => $configSetName,
                        'url'          => $url,
                        'raw_response' => (string) $response->getBody(),
                        'json_error'   => json_last_error_msg(),
                    ]
                );

                $this->lastErrorDetails = [
                    'operation'     => 'createCollection',
                    'collection'    => $collectionName,
                    'configSet'     => $configSetName,
                    'url_attempted' => $url,
                    'error_type'    => 'invalid_json_response',
                    'error_message' => 'SOLR returned invalid JSON response',
                    'json_error'    => json_last_error_msg(),
                    'raw_response'  => (string) $response->getBody(),
                ];

                return false;
            }//end if

            $status = $data['responseHeader']['status'] ?? -1;
            if ($status === 0) {
                $this->logger->info(
                    'Collection created successfully',
                    [
                        'collection' => $collectionName,
                        'configSet'  => $configSetName,
                    ]
                );

                // Track newly created collection.
                if (in_array($collectionName, $this->infrastructureCreated['collections_created'], true) === false) {
                    $this->infrastructureCreated['collections_created'][] = $collectionName;
                }

                return true;
            }

            // Extract detailed error information from SOLR response.
            $errorMsg     = $data['error']['msg'] ?? 'Unknown SOLR error';
            $errorCode    = $data['error']['code'] ?? $status;
            $errorDetails = $data['error']['metadata'] ?? [];

            $this->logger->error(
                'Collection creation failed - SOLR returned error',
                [
                    'collection'         => $collectionName,
                    'configSet'          => $configSetName,
                    'url'                => $url,
                    'solr_status'        => $status,
                    'solr_error_message' => $errorMsg,
                    'solr_error_code'    => $errorCode,
                    'solr_error_details' => $errorDetails,
                    'full_response'      => $data,
                ]
            );

            // Store detailed error information for API response.
            $this->lastErrorDetails = [
                'operation'            => 'createCollection',
                'collection'           => $collectionName,
                'configSet'            => $configSetName,
                'url_attempted'        => $url,
                'error_type'           => 'solr_api_error',
                'error_message'        => $errorMsg,
                'solr_status'          => $status,
                'solr_error_code'      => $errorCode,
                'solr_error_details'   => $errorDetails,
                'full_solr_response'   => $data,
                'troubleshooting_tips' => [
                    'Verify configSet "'.$configSetName.'" exists and is accessible',
                    'Check SOLR has permissions to create collections',
                    'Verify ZooKeeper coordination in SolrCloud',
                    'Check available disk space and memory on SOLR server',
                    'Check SOLR logs for additional error details',
                ],
            ];

            return false;
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to create collection - HTTP request failed',
                [
                    'collection'     => $collectionName,
                    'configSet'      => $configSetName,
                    'url'            => $url,
                    'error'          => $e->getMessage(),
                    'exception_type' => get_class($e),
                ]
            );

            // Store detailed error information for API response.
            $this->lastErrorDetails = [
                'operation'      => 'createCollection',
                'collection'     => $collectionName,
                'configSet'      => $configSetName,
                'url_attempted'  => $url,
                'error_type'     => 'http_request_failed',
                'error_message'  => $e->getMessage(),
                'exception_type' => get_class($e),
            ];

            return false;
        }//end try
    }//end createCollection()

    /**
     * Upload a configSet from ZIP file to SOLR
     *
     * This method uploads a pre-packaged configSet ZIP file to SOLR, which bypasses
     * the authentication requirements for creating configSets from trusted templates.
     *
     * @param string $configSetName Name for the new configSet
     *
     * @return bool True if configSet was uploaded successfully
     */
    private function uploadConfigSet(string $configSetName): bool
    {
        // Path to our packaged configSet ZIP file (fixed version with proper XML structure).
        $zipPath = __DIR__.'/../../resources/solr/openregister-configset-fixed.zip';

        if (file_exists($zipPath) === false) {
            $this->logger->error(
                'ConfigSet ZIP file not found',
                [
                    'configSet' => $configSetName,
                    'zipPath'   => $zipPath,
                ]
            );

            $this->lastErrorDetails = [
                'operation'            => 'uploadConfigSet',
                'configSet'            => $configSetName,
                'error_type'           => 'zip_file_not_found',
                'error_message'        => 'ConfigSet ZIP file not found at: '.$zipPath,
                'zip_path'             => $zipPath,
                'troubleshooting_tips' => [
                    'Ensure the configSet ZIP file exists in resources/solr/',
                    'Check file permissions on the ZIP file',
                    'Verify the ZIP file contains valid configSet files',
                ],
            ];
            return false;
        }//end if

        $url = $this->buildSolrUrl(
            sprintf(
                '/admin/configs?action=UPLOAD&name=%s&wt=json',
                urlencode($configSetName)
            )
        );

        $this->logger->info(
            'Uploading SOLR configSet from ZIP file',
            [
                'configSet' => $configSetName,
                'url'       => $url,
                'zipPath'   => $zipPath,
                'zipSize'   => filesize($zipPath).' bytes',
            ]
        );

        try {
            // Read ZIP file contents.
            $zipContents = file_get_contents($zipPath);
            if ($zipContents === false) {
                $this->logger->error(
                    'Failed to read configSet ZIP file',
                    [
                        'configSet' => $configSetName,
                        'zipPath'   => $zipPath,
                    ]
                );

                $this->lastErrorDetails = [
                    'operation'     => 'uploadConfigSet',
                    'configSet'     => $configSetName,
                    'error_type'    => 'zip_read_failed',
                    'error_message' => 'Failed to read configSet ZIP file',
                    'zip_path'      => $zipPath,
                ];
                return false;
            }

            // Upload ZIP file via POST request.
            $requestOptions = [
                'timeout' => 30,
                'headers' => [
                    'Content-Type' => 'application/octet-stream',
                ],
                'body'    => $zipContents,
            ];

            $response = $this->httpClient->post($url, $requestOptions);

            if ($response->getStatusCode() !== 200) {
                $responseBody = (string) $response->getBody();
                $this->logger->error(
                    'Failed to upload configSet - HTTP error',
                    [
                        'configSet'     => $configSetName,
                        'url'           => $url,
                        'status_code'   => $response->getStatusCode(),
                        'response_body' => $responseBody,
                    ]
                );

                $this->lastErrorDetails = [
                    'operation'       => 'uploadConfigSet',
                    'configSet'       => $configSetName,
                    'url_attempted'   => $url,
                    'error_type'      => 'http_error',
                    'error_message'   => 'HTTP error '.$response->getStatusCode(),
                    'response_status' => $response->getStatusCode(),
                    'response_body'   => $responseBody,
                ];
                return false;
            }//end if

            $data = json_decode((string) $response->getBody(), true);

            if ($data === null) {
                $this->logger->error(
                    'Failed to upload configSet - Invalid JSON response',
                    [
                        'configSet'    => $configSetName,
                        'url'          => $url,
                        'raw_response' => (string) $response->getBody(),
                        'json_error'   => json_last_error_msg(),
                    ]
                );

                $this->lastErrorDetails = [
                    'operation'     => 'uploadConfigSet',
                    'configSet'     => $configSetName,
                    'url_attempted' => $url,
                    'error_type'    => 'invalid_json_response',
                    'error_message' => 'SOLR returned invalid JSON response',
                    'json_error'    => json_last_error_msg(),
                    'raw_response'  => (string) $response->getBody(),
                ];
                return false;
            }//end if

            $status = $data['responseHeader']['status'] ?? -1;
            if ($status === 0) {
                $this->logger->info(
                    'ConfigSet uploaded successfully',
                    [
                        'configSet' => $configSetName,
                        'method'    => 'ZIP upload',
                    ]
                );

                // Track newly created configSet.
                if (in_array($configSetName, $this->infrastructureCreated['configsets_created'], true) === false) {
                    $this->infrastructureCreated['configsets_created'][] = $configSetName;
                }

                // Force configSet propagation immediately after successful upload.
                // This proactively triggers cache refresh and ZooKeeper sync to reduce.
                // The likelihood of propagation delays when creating collections.
                $propagationResult = $this->forceConfigSetPropagation($configSetName);
                $this->logger->info(
                    'ConfigSet propagation attempted after upload',
                    [
                        'configSet' => $configSetName,
                        'result'    => $propagationResult,
                    ]
                );

                return true;
            }//end if

            // Handle SOLR API errors.
            $errorCode    = $data['error']['code'] ?? $status;
            $errorMsg     = $data['error']['msg'] ?? 'Unknown SOLR error';
            $errorDetails = $data['error']['metadata'] ?? [];

            $this->logger->error(
                'Failed to upload configSet - SOLR API error',
                [
                    'configSet'          => $configSetName,
                    'url'                => $url,
                    'solr_status'        => $status,
                    'solr_error_code'    => $errorCode,
                    'solr_error_message' => $errorMsg,
                    'solr_error_details' => $errorDetails,
                    'full_response'      => $data,
                ]
            );

            $this->lastErrorDetails = [
                'operation'          => 'uploadConfigSet',
                'configSet'          => $configSetName,
                'url_attempted'      => $url,
                'error_type'         => 'solr_api_error',
                'error_message'      => $errorMsg,
                'solr_status'        => $status,
                'solr_error_code'    => $errorCode,
                'solr_error_details' => $errorDetails,
                'full_solr_response' => $data,
            ];
            return false;
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to upload configSet - HTTP request failed',
                [
                    'configSet'      => $configSetName,
                    'url'            => $url,
                    'error'          => $e->getMessage(),
                    'exception_type' => get_class($e),
                ]
            );

            $this->lastErrorDetails = [
                'operation'      => 'uploadConfigSet',
                'configSet'      => $configSetName,
                'url_attempted'  => $url,
                'error_type'     => 'http_request_failed',
                'error_message'  => $e->getMessage(),
                'exception_type' => get_class($e),
            ];
            return false;
        }//end try
    }//end uploadConfigSet()

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

        // Get all field definitions including self_* metadata fields.
        $fieldDefinitions = self::getObjectEntityFieldDefinitions();

        $this->logger->info(
            'Schema field configuration',
            [
                'total_fields_to_configure' => count($fieldDefinitions),
                'includes_metadata_fields'  => true,
                'note'                      => 'All ObjectEntity fields including self_* metadata fields will be configured',
            ]
        );

        $fieldResults = [
            'total_fields'   => count($fieldDefinitions),
            'fields_added'   => 0,
            'fields_updated' => 0,
            'fields_failed'  => 0,
            'fields_skipped' => 0,
            'added_fields'   => [],
            'updated_fields' => [],
            'failed_fields'  => [],
            'skipped_fields' => [],
        ];

        $success = true;
        foreach ($fieldDefinitions as $fieldName => $fieldConfig) {
            $result = $this->addOrUpdateSchemaFieldWithTracking(
                fieldName: $fieldName,
                fieldConfig: $fieldConfig
            );

            if ($result['success'] !== true) {
                $fieldResults['fields_failed']++;
                $fieldResults['failed_fields'][] = $fieldName;
                $errorMsg = $result['error'] ?? 'Unknown error';
                $this->logger->error('Failed to configure field', ['field' => $fieldName, 'error' => $errorMsg]);
                $success = false;
                continue;
            }

            if ($result['action'] === 'added') {
                $fieldResults['fields_added']++;
                $fieldResults['added_fields'][] = $fieldName;
            } else if ($result['action'] === 'updated') {
                $fieldResults['fields_updated']++;
                $fieldResults['updated_fields'][] = $fieldName;
            } else if ($result['action'] === 'skipped') {
                $fieldResults['fields_skipped']++;
                $fieldResults['skipped_fields'][] = $fieldName;
            }
        }//end foreach

        // Update the step tracking with detailed field information.
        $status = 'failed';
        if ($success === true) {
            $status = 'completed';
        }

        $message = 'Schema field configuration failed';
        if ($success === true) {
            $message = 'Schema fields configured successfully';
        }

        $this->trackStep(
            stepNumber: 4,
            stepName: 'Schema Configuration',
            status: $status,
            description: $message,
            details: $fieldResults
        );

        if ($success === true) {
            $this->logger->info('Schema field configuration completed successfully', $fieldResults);
        }

        return $success;
    }//end configureSchemaFields()

    /**
     * Add or update a schema field with detailed tracking
     *
     * @param string $fieldName   Name of the field
     * @param array  $fieldConfig Field configuration
     *
     * @return ((mixed|string|true)[]|bool|mixed|string)[]
     *
     * @psalm-return array{success: bool, action: string,
     *     error?: 'Unknown error'|mixed,
     *     details?: array{success?: mixed|true,
     *     reason?: 'Field exists with compatible configuration'|mixed,...}}
     */
    private function addOrUpdateSchemaFieldWithTracking(string $fieldName, array $fieldConfig): array
    {
        // First, try to add the field.
        $addResult = $this->addSchemaFieldWithResult(
            fieldName: $fieldName,
            fieldConfig: $fieldConfig
        );

        if ($addResult['success'] === true) {
            return [
                'success' => true,
                'action'  => 'added',
                'details' => $addResult,
            ];
        }

        // If add failed because field exists, try to update/replace.
        if (strpos($addResult['error'] ?? '', 'already exists') !== false
            || strpos($addResult['error'] ?? '', 'Field') !== false
        ) {
            $updateResult = $this->replaceSchemaFieldWithResult(
                fieldName: $fieldName,
                fieldConfig: $fieldConfig
            );

            if ($updateResult['success'] === true) {
                return [
                    'success' => true,
                    'action'  => 'updated',
                    'details' => $updateResult,
                ];
            }

            // Field exists but couldn't be updated - might be same config.
            return [
                'success' => true,
                'action'  => 'skipped',
                'details' => ['reason' => 'Field exists with compatible configuration'],
            ];
        }//end if

        // Both add and update failed.
        return [
            'success' => false,
            'action'  => 'failed',
            'error'   => $addResult['error'] ?? 'Unknown error',
        ];
    }//end addOrUpdateSchemaFieldWithTracking()

    /**
     * Add a schema field and return detailed result
     *
     * @param string $fieldName   Name of the field
     * @param array  $fieldConfig Field configuration
     *
     * @return (bool|mixed|string)[]
     *
     * @psalm-return array{success: bool, error?: mixed|string,
     *     exception_type?: get-class-of<$e, Exception>,
     *     response_body?: string, solr_response?: mixed}
     */
    private function addSchemaFieldWithResult(string $fieldName, array $fieldConfig): array
    {
        $tenantCollectionName = $this->getTenantCollectionName();
        $url = $this->buildSolrUrl('/'.$tenantCollectionName.'/schema');

        $payload = [
            'add-field' => array_merge(['name' => $fieldName], $fieldConfig),
        ];

        try {
            $response = $this->httpClient->post(
                $url,
                [
                    'headers' => ['Content-Type' => 'application/json'],
                    'json'    => $payload,
                    'timeout' => 30,
                ]
            );

            if ($response->getStatusCode() !== 200) {
                return [
                    'success'       => false,
                    'error'         => 'HTTP error: '.$response->getStatusCode(),
                    'response_body' => (string) $response->getBody(),
                ];
            }

            $data    = json_decode((string) $response->getBody(), true);
            $success = ($data['responseHeader']['status'] ?? -1) === 0;

            if ($success !== true) {
                return [
                    'success'       => false,
                    'error'         => $data['error']['msg'] ?? 'SOLR error',
                    'solr_response' => $data,
                ];
            }

            return [
                'success'       => true,
                'solr_response' => $data,
            ];
        } catch (\Exception $e) {
            return [
                'success'        => false,
                'error'          => $e->getMessage(),
                'exception_type' => get_class($e),
            ];
        }//end try
    }//end addSchemaFieldWithResult()

    /**
     * Replace a schema field and return detailed result
     *
     * @param string $fieldName   Name of the field
     * @param array  $fieldConfig Field configuration
     *
     * @return (bool|mixed|string)[]
     *
     * @psalm-return array{success: bool, error?: mixed|string,
     *     exception_type?: get-class-of<$e, Exception>,
     *     response_body?: string, solr_response?: mixed}
     */
    private function replaceSchemaFieldWithResult(string $fieldName, array $fieldConfig): array
    {
        $tenantCollectionName = $this->getTenantCollectionName();
        $url = $this->buildSolrUrl('/'.$tenantCollectionName.'/schema');

        $payload = [
            'replace-field' => array_merge(['name' => $fieldName], $fieldConfig),
        ];

        try {
            $response = $this->httpClient->post(
                $url,
                [
                    'headers' => ['Content-Type' => 'application/json'],
                    'json'    => $payload,
                    'timeout' => 30,
                ]
            );

            if ($response->getStatusCode() !== 200) {
                return [
                    'success'       => false,
                    'error'         => 'HTTP error: '.$response->getStatusCode(),
                    'response_body' => (string) $response->getBody(),
                ];
            }

            $data    = json_decode((string) $response->getBody(), true);
            $success = ($data['responseHeader']['status'] ?? -1) === 0;

            if ($success !== true) {
                return [
                    'success'       => false,
                    'error'         => $data['error']['msg'] ?? 'SOLR error',
                    'solr_response' => $data,
                ];
            }

            return [
                'success'       => true,
                'solr_response' => $data,
            ];
        } catch (\Exception $e) {
            return [
                'success'        => false,
                'error'          => $e->getMessage(),
                'exception_type' => get_class($e),
            ];
        }//end try
    }//end replaceSchemaFieldWithResult()

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
     * @return (bool|string)[][] Field definitions with SOLR type configuration
     *
     * @psalm-return array{
     *     self_tenant: array{
     *         type: 'string', stored: true, indexed: true, multiValued: false, required: true, docValues: true
     *     },
     *     self_object_id: array{type: 'pint', stored: true, indexed: true, multiValued: false, docValues: false},
     *     self_uuid: array{type: 'string', stored: true, indexed: true, multiValued: false, docValues: false},
     *     self_register: array{type: 'pint', stored: true, indexed: true, multiValued: false, docValues: true},
     *     self_schema: array{type: 'pint', stored: true, indexed: true, multiValued: false, docValues: true},
     *     self_schema_version: array{type: 'string', stored: true, indexed: true, multiValued: false, docValues: true},
     *     self_owner: array{type: 'string', stored: true, indexed: true, multiValued: false, docValues: false},
     *     self_organisation: array{type: 'string', stored: true, indexed: true, multiValued: false, docValues: true},
     *     self_application: array{type: 'string', stored: true, indexed: true, multiValued: false, docValues: true},
     *     self_name: array{type: 'string', stored: true, indexed: true, multiValued: false, docValues: false},
     *     self_description: array{type: 'text_general', stored: true, indexed: true, multiValued: false, docValues: false},
     *     self_summary: array{type: 'text_general', stored: true, indexed: true, multiValued: false},
     *     self_image: array{type: 'string', stored: true, indexed: false, multiValued: false},
     *     self_slug: array{type: 'string', stored: true, indexed: true, multiValued: false},
     *     self_uri: array{type: 'string', stored: true, indexed: true, multiValued: false},
     *     self_version: array{type: 'string', stored: true, indexed: true, multiValued: false},
     *     self_size: array{type: 'string', stored: true, indexed: false, multiValued: false},
     *     self_folder: array{type: 'string', stored: true, indexed: true, multiValued: false},
     *     self_created: array{type: 'pdate', stored: true, indexed: true, multiValued: false, docValues: true},
     *     self_updated: array{type: 'pdate', stored: true, indexed: true, multiValued: false, docValues: true},
     *     self_published: array{type: 'pdate', stored: true, indexed: true, multiValued: false, docValues: true},
     *     self_depublished: array{type: 'pdate', stored: true, indexed: true, multiValued: false, docValues: true},
     *     self_relations: array{type: 'string', stored: true, indexed: true, multiValued: true},
     *     self_files: array{type: 'string', stored: true, indexed: true, multiValued: true},
     *     self_parent_uuid: array{type: 'string', stored: true, indexed: true, multiValued: false}
     * }
     */
    public function getObjectEntityFieldDefinitions(): array
    {
        return [
            // **CRITICAL**: Core tenant field with self_ prefix (consistent naming).
            'self_tenant'         => [
                'type'        => 'string',
                'stored'      => true,
                'indexed'     => true,
                'multiValued' => false,
                'required'    => true,
                'docValues'   => true,
        // Enable faceting for tenant filtering.
            ],

            // Metadata fields with self_ prefix (consistent with legacy mapping).
            'self_object_id'      => [
                'type'        => 'pint',
                'stored'      => true,
                'indexed'     => true,
                'multiValued' => false,
                'docValues'   => false,
            // Not useful for faceting.
            ],
            'self_uuid'           => [
                'type'        => 'string',
                'stored'      => true,
                'indexed'     => true,
                'multiValued' => false,
                'docValues'   => false,
            // Not useful for faceting.
            ],

            // Context fields.
            'self_register'       => [
                'type'        => 'pint',
                'stored'      => true,
                'indexed'     => true,
                'multiValued' => false,
                'docValues'   => true,
            // Enable faceting.
            ],
            'self_schema'         => [
                'type'        => 'pint',
                'stored'      => true,
                'indexed'     => true,
                'multiValued' => false,
                'docValues'   => true,
            // Enable faceting.
            ],
            'self_schema_version' => [
                'type'        => 'string',
                'stored'      => true,
                'indexed'     => true,
                'multiValued' => false,
                'docValues'   => true,
            // Enable faceting.
            ],

            // Ownership and metadata.
            'self_owner'          => [
                'type'        => 'string',
                'stored'      => true,
                'indexed'     => true,
                'multiValued' => false,
                'docValues'   => false,
            // Not useful for faceting - used for ownership tracking.
            ],
            'self_organisation'   => [
                'type'        => 'string',
                'stored'      => true,
                'indexed'     => true,
                'multiValued' => false,
                'docValues'   => true,
            // Enable faceting.
            ],
            'self_application'    => [
                'type'        => 'string',
                'stored'      => true,
                'indexed'     => true,
                'multiValued' => false,
                'docValues'   => true,
            // Enable faceting.
            ],

            // Core object fields (no suffixes needed when explicitly defined).
            'self_name'           => [
                'type'        => 'string',
                'stored'      => true,
                'indexed'     => true,
                'multiValued' => false,
                'docValues'   => false,
            // Not useful for faceting - used for search.
            ],
            'self_description'    => [
                'type'        => 'text_general',
                'stored'      => true,
                'indexed'     => true,
                'multiValued' => false,
                'docValues'   => false,
            // Not useful for faceting - used for search.
            ],
            'self_summary'        => [
                'type'        => 'text_general',
                'stored'      => true,
                'indexed'     => true,
                'multiValued' => false,
            ],
            'self_image'          => [
                'type'        => 'string',
                'stored'      => true,
                'indexed'     => false,
                'multiValued' => false,
            ],
            'self_slug'           => [
                'type'        => 'string',
                'stored'      => true,
                'indexed'     => true,
                'multiValued' => false,
            ],
            'self_uri'            => [
                'type'        => 'string',
                'stored'      => true,
                'indexed'     => true,
                'multiValued' => false,
            ],
            'self_version'        => [
                'type'        => 'string',
                'stored'      => true,
                'indexed'     => true,
                'multiValued' => false,
            ],
            'self_size'           => [
                'type'        => 'string',
                'stored'      => true,
                'indexed'     => false,
                'multiValued' => false,
            ],
            'self_folder'         => [
                'type'        => 'string',
                'stored'      => true,
                'indexed'     => true,
                'multiValued' => false,
            ],

            // Timestamps (SOLR date format).
            'self_created'        => [
                'type'        => 'pdate',
                'stored'      => true,
                'indexed'     => true,
                'multiValued' => false,
                'docValues'   => true,
            // Enable faceting for date ranges.
            ],
            'self_updated'        => [
                'type'        => 'pdate',
                'stored'      => true,
                'indexed'     => true,
                'multiValued' => false,
                'docValues'   => true,
            // Enable faceting for date ranges.
            ],
            'self_published'      => [
                'type'        => 'pdate',
                'stored'      => true,
                'indexed'     => true,
                'multiValued' => false,
                'docValues'   => true,
            // Enable faceting for date ranges.
            ],
            'self_depublished'    => [
                'type'        => 'pdate',
                'stored'      => true,
                'indexed'     => true,
                'multiValued' => false,
                'docValues'   => true,
            // Enable faceting for date ranges.
            ],

            // **NEW**: UUID relation fields for clean object relationships.
            'self_relations'      => [
                'type'        => 'string',
                'stored'      => true,
                'indexed'     => true,
                'multiValued' => true,
            ],
            'self_files'          => [
                'type'        => 'string',
                'stored'      => true,
                'indexed'     => true,
                'multiValued' => true,
            ],
            'self_parent_uuid'    => [
                'type'        => 'string',
                'stored'      => true,
                'indexed'     => true,
                'multiValued' => false,
            ],
        ];
    }//end getObjectEntityFieldDefinitions()

    /**
     * Add a new field to the SOLR schema
     *
     * @param string $fieldName   Name of the field to add
     * @param array  $fieldConfig Field configuration
     *
     * @return bool True if field was added successfully
     */
    private function addSchemaField(string $fieldName, array $fieldConfig): bool
    {
        $tenantCollectionName = $this->getTenantCollectionName();
        $url = $this->buildSolrUrl('/'.$tenantCollectionName.'/schema');

        $payload = [
            'add-field' => array_merge(['name' => $fieldName], $fieldConfig),
        ];

        try {
            $response = $this->httpClient->post(
                $url,
                [
                    'headers' => ['Content-Type' => 'application/json'],
                    'json'    => $payload,
                    'timeout' => 30,
                ]
            );

            if ($response->getStatusCode() !== 200) {
                $this->logger->debug(
                    'Failed to add schema field - HTTP error',
                    [
                        'field'         => $fieldName,
                        'status_code'   => $response->getStatusCode(),
                        'response_body' => (string) $response->getBody(),
                    ]
                );
                return false;
            }

            $data    = json_decode((string) $response->getBody(), true);
            $success = ($data['responseHeader']['status'] ?? -1) === 0;

            if ($success !== true) {
                $this->logger->debug(
                    'Failed to add schema field - SOLR error',
                    [
                        'field'         => $fieldName,
                        'solr_response' => $data,
                    ]
                );
                return $success;
            }

            $this->logger->debug('Added schema field', ['field' => $fieldName]);
            return $success;
        } catch (\Exception $e) {
            $this->logger->debug(
                'Failed to add schema field - Exception',
                [
                    'field'          => $fieldName,
                    'error'          => $e->getMessage(),
                    'exception_type' => get_class($e),
                ]
            );
            return false;
        }//end try
    }//end addSchemaField()

    /**
     * Replace an existing field in the SOLR schema
     *
     * @param string $fieldName   Name of the field to replace
     * @param array  $fieldConfig Field configuration
     *
     * @return bool True if field was replaced successfully
     */
    private function replaceSchemaField(string $fieldName, array $fieldConfig): bool
    {
        $tenantCollectionName = $this->getTenantCollectionName();
        $url = $this->buildSolrUrl('/'.$tenantCollectionName.'/schema');

        $payload = [
            'replace-field' => array_merge(['name' => $fieldName], $fieldConfig),
        ];

        try {
            $response = $this->httpClient->post(
                $url,
                [
                    'headers' => ['Content-Type' => 'application/json'],
                    'json'    => $payload,
                    'timeout' => 30,
                ]
            );

            if ($response->getStatusCode() !== 200) {
                $this->logger->debug(
                    'Failed to replace schema field - HTTP error',
                    [
                        'field'         => $fieldName,
                        'status_code'   => $response->getStatusCode(),
                        'response_body' => (string) $response->getBody(),
                    ]
                );
                return false;
            }

            $data    = json_decode((string) $response->getBody(), true);
            $success = ($data['responseHeader']['status'] ?? -1) === 0;

            if ($success !== true) {
                $this->logger->debug(
                    'Failed to replace schema field - SOLR error',
                    [
                        'field'         => $fieldName,
                        'solr_response' => $data,
                    ]
                );
                return $success;
            }

            $this->logger->debug('Replaced schema field', ['field' => $fieldName]);
            return $success;
        } catch (\Exception $e) {
            $this->logger->debug(
                'Failed to replace schema field - Exception',
                [
                    'field'          => $fieldName,
                    'error'          => $e->getMessage(),
                    'exception_type' => get_class($e),
                ]
            );
            return false;
        }//end try
    }//end replaceSchemaField()

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

        // Check tenant configSet exists.
        $tenantConfigSetName = $this->getTenantConfigSetName();
        if ($this->configSetExists($tenantConfigSetName) === false) {
            $this->logger->error(
                'Validation failed: tenant configSet missing',
                [
                    'configSet' => $tenantConfigSetName,
                ]
            );
            return false;
        }

        // Check tenant collection exists.
        if ($this->solrService->collectionExists($tenantCollectionName) === false) {
            $this->logger->error(
                'Validation failed: tenant collection missing',
                [
                    'collection' => $tenantCollectionName,
                ]
            );
            return false;
        }

        // Test tenant collection query functionality.
        if ($this->testCollectionQuery($tenantCollectionName) === false) {
            $this->logger->error(
                'Validation failed: tenant collection query test failed',
                [
                    'collection' => $tenantCollectionName,
                ]
            );
            return false;
        }

        $this->logger->info('SOLR setup validation passed (SolrCloud mode)');
        return true;
    }//end validateSetup()

    /**
     * Test that a collection responds to queries correctly (SolrCloud)
     *
     * @param string $collectionName Name of the collection to test
     *
     * @return bool True if collection responds to queries properly
     */
    private function testCollectionQuery(string $collectionName): bool
    {
        $url = $this->buildSolrUrl(
            sprintf(
                '/%s/select?q=*:*&rows=0&wt=json',
                urlencode($collectionName)
            )
        );

        try {
            $response = $this->httpClient->get($url, ['timeout' => 10]);
            $data     = json_decode((string) $response->getBody(), true);
        } catch (\Exception $e) {
            $this->logger->warning(
                'Failed to test collection query',
                [
                    'collection' => $collectionName,
                    'error'      => $e->getMessage(),
                    'url'        => $url,
                ]
            );
            return false;
        }

        // Valid response should have a response header with status 0.
        return ($data['responseHeader']['status'] ?? -1) === 0;
    }//end testCollectionQuery()
}//end class
