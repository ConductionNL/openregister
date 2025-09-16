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
     * Build SOLR URL with proper Kubernetes service name support
     *
     * @param string $path The SOLR API path (e.g., '/admin/info/system')
     * @return string Complete SOLR URL
     */
    private function buildSolrUrl(string $path): string
    {
        $host = $this->solrConfig['host'] ?? 'localhost';
        $port = $this->solrConfig['port'] ?? null;
        $scheme = $this->solrConfig['scheme'] ?? 'http';
        $basePath = $this->solrConfig['path'] ?? '/solr';
        
        // Normalize port - convert string '0' to integer 0, handle empty strings
        if ($port === '0' || $port === '' || $port === null) {
            $port = null;
        } else {
            $port = (int)$port;
            if ($port === 0) {
                $port = null;
            }
        }
        
        // Check if it's a Kubernetes service name (contains .svc.cluster.local)
        if (strpos($host, '.svc.cluster.local') !== false) {
            // Kubernetes service - don't append port, it's handled by the service
            return sprintf('%s://%s%s%s',
                $scheme,
                $host,
                $basePath,
                $path
            );
        } else {
            // Regular hostname - only append port if explicitly provided and not 0/null
            if ($port !== null && $port > 0) {
                return sprintf('%s://%s:%d%s%s',
                    $scheme,
                    $host,
                    $port,
                    $basePath,
                    $path
                );
            } else {
                // No port provided or port is 0 - let the service handle it
                return sprintf('%s://%s%s%s',
                    $scheme,
                    $host,
                    $basePath,
                    $path
                );
            }
        }
    }

    /**
     * Run complete SOLR setup for OpenRegister multi-tenant architecture
     *
     * Performs all necessary setup operations for SolrCloud:
     * 1. Verifies SOLR connectivity
     * 2. Creates base configSet if missing
     * 3. Creates base collection for template
     * 4. Validates setup completion
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
            'steps' => []
        ];

        try {
            // Step 1: Verify SOLR connectivity
            $this->trackStep(1, 'SOLR Connectivity', 'started', 'Verifying SOLR server connectivity and authentication');
            
            try {
                if (!$this->verifySolrConnectivity()) {
                    $this->trackStep(1, 'SOLR Connectivity', 'failed', 'Cannot connect to SOLR server', [
                        'error' => 'SOLR connectivity test failed',
                        'host' => $this->solrConfig['host'] ?? 'unknown',
                        'port' => $this->solrConfig['port'] ?? 'unknown',
                        'url_tested' => $this->buildSolrUrl('/admin/ping?wt=json')
                    ]);
                    
                    $this->lastErrorDetails = [
                        'operation' => 'verifySolrConnectivity',
                        'step' => 1,
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
            } catch (\Exception $e) {
                $this->trackStep(1, 'SOLR Connectivity', 'failed', $e->getMessage(), [
                    'exception_type' => get_class($e),
                    'exception_message' => $e->getMessage()
                ]);
                
                $this->lastErrorDetails = [
                    'operation' => 'verifySolrConnectivity',
                    'step' => 1,
                    'error_type' => 'connectivity_exception',
                    'error_message' => $e->getMessage(),
                    'exception_type' => get_class($e),
                    'configuration' => $this->solrConfig
                ];
                return false;
            }

            // Step 2: Ensure base configSet exists
            $this->logger->info('Step 2/5: Ensuring base configSet exists');
            if (!$this->ensureBaseConfigSet()) {
                throw new \RuntimeException(
                    'Failed to create base configSet "openregister". This could be due to: ' .
                    '1) SOLR server lacks write permissions for config directory, ' .
                    '2) Template configSet "_default" does not exist, ' .
                    '3) SOLR is not running in SolrCloud mode, ' .
                    '4) ZooKeeper connectivity issues in SolrCloud setup. ' .
                    'Check SOLR admin UI at http://' . ($this->solrConfig['host'] ?? 'localhost') . ':' . ($this->solrConfig['port'] ?? '8983') . '/solr/#/~configs ' .
                    'and verify available configSets. Check application logs for detailed error messages.'
                );
            }

            // Step 3: Ensure base collection exists (used as template)
            $this->logger->info('Step 3/5: Ensuring base collection exists');
            if (!$this->ensureBaseCollectionExists()) {
                throw new \RuntimeException(
                    'Failed to create base collection "openregister". This could be due to: ' .
                    '1) ConfigSet "openregister" was not created in previous step, ' .
                    '2) SOLR lacks permissions to create collections, ' .
                    '3) ZooKeeper coordination issues in SolrCloud, ' .
                    '4) Insufficient disk space or memory on SOLR server. ' .
                    'Check SOLR admin UI at http://' . ($this->solrConfig['host'] ?? 'localhost') . ':' . ($this->solrConfig['port'] ?? '8983') . '/solr/#/~collections ' .
                    'and verify collection status. Check application logs for detailed error messages.'
                );
            }

            // Step 4: Configure schema fields for ObjectEntity metadata (placeholder)
            $this->logger->info('Step 4/5: Configuring schema fields for ObjectEntity metadata');
            // TODO: Implement schema field configuration
            $this->logger->info('Schema field configuration completed (placeholder - implement schema field setup)');

            // Step 5: Validate setup (placeholder)  
            $this->logger->info('Step 5/5: Validating SOLR setup');
            // TODO: Implement setup validation
            $this->logger->info('Setup validation completed (placeholder - implement validation checks)');

            $this->logger->info('✅ SOLR setup completed successfully (SolrCloud mode)', [
                'configSet_created' => 'openregister',
                'collection_created' => 'openregister',
                'solr_host' => $this->solrConfig['host'] ?? 'localhost',
                'solr_port' => $this->solrConfig['port'] ?? '8983',
                'admin_ui_url' => 'http://' . ($this->solrConfig['host'] ?? 'localhost') . ':' . ($this->solrConfig['port'] ?? '8983') . '/solr/'
            ]);
        return true;

        } catch (\Exception $e) {
            $this->logger->error('SOLR setup failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }


    /**
     * Verify that SOLR server is accessible and responding
     *
     * @return bool True if SOLR is accessible and responding properly
     */
    private function verifySolrConnectivity(): bool
    {
        $url = $this->buildSolrUrl('/admin/info/system?wt=json');

        try {
            $response = $this->httpClient->get($url, ['timeout' => 10]);
            
            if ($response->getStatusCode() !== 200) {
                $this->logger->error('SOLR connectivity verification failed - HTTP error', [
                    'url' => $url,
                    'status_code' => $response->getStatusCode(),
                    'response_body' => (string)$response->getBody()
                ]);
            return false;
        }

            $data = json_decode((string)$response->getBody(), true);
            if ($data === null || !isset($data['lucene'])) {
                $this->logger->error('SOLR connectivity verification failed - invalid response', [
                    'url' => $url,
                    'response' => (string)$response->getBody()
                ]);
            return false;
        }

            $this->logger->info('SOLR connectivity verified successfully', [
                'url' => $url,
                'lucene_version' => $data['lucene']['lucene-spec-version'] ?? 'unknown'
        ]);
        
        return true;
            
        } catch (\Exception $e) {
            $this->logger->error('SOLR connectivity verification failed - Exception', [
                'url' => $url,
                'error' => $e->getMessage(),
                'exception_type' => get_class($e)
            ]);
            return false;
        }
    }

    /**
     * Ensure the base configSet exists for creating tenant cores
     *
     * Creates the 'openregister' configSet if it doesn't exist, using
     * the default '_default' configSet as a template.
     *
     * @return bool True if configSet exists or was created successfully
     */
    private function ensureBaseConfigSet(): bool
    {
        // Check if configSet already exists
        if ($this->configSetExists('openregister')) {
            $this->logger->info('Base configSet already exists');
            return true;
        }

        // Create configSet using _default as template
        return $this->createConfigSet('openregister', '_default');
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
            $response = $this->httpClient->get($url, ['timeout' => 10]);
            
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
        
        $pingUrl = $this->buildSolrUrl('/admin/ping?wt=json');
        try {
            $pingResponse = $this->httpClient->get($pingUrl, ['timeout' => 10]);
            $this->logger->info('SOLR ping test successful', [
                'status_code' => $pingResponse->getStatusCode(),
                'ping_url' => $pingUrl
            ]);
        } catch (\Exception $e) {
            $this->logger->error('SOLR ping test failed - connectivity issue detected', [
                'ping_url' => $pingUrl,
                'error' => $e->getMessage(),
                'exception_type' => get_class($e)
            ]);
            // Continue anyway - ping might not be available but admin endpoints might work
        }
        
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
            // Use Guzzle HTTP client with proper timeout and headers
            $response = $this->httpClient->get($url, [
                'timeout' => 30,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ]
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->error('Failed to create configSet - HTTP error', [
                    'configSet' => $newConfigSetName,
                    'template' => $templateConfigSetName,
                    'url' => $url,
                    'status_code' => $response->getStatusCode(),
                    'response_body' => (string)$response->getBody(),
                    'possible_causes' => [
                        'SOLR server not reachable at configured URL',
                        'Network connectivity issues',
                        'SOLR server not responding',
                        'Invalid SOLR configuration (host/port/path)',
                        'SOLR server overloaded or timeout'
                    ]
            ]);
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
                    $this->lastErrorDetails['guzzle_details']['response_status'] = $response->getStatusCode();
                    $this->lastErrorDetails['guzzle_details']['response_body'] = (string)$response->getBody();
                    $this->lastErrorDetails['guzzle_details']['response_headers'] = $response->getHeaders();
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
     * Ensure the base collection exists as a template for tenant collections
     *
     * The base 'openregister' collection serves as both a working collection and
     * a template for creating tenant-specific collections in SolrCloud.
     *
     * @return bool True if base collection exists or was created successfully
     */
    private function ensureBaseCollectionExists(): bool
    {
        // Check if base collection already exists
        if ($this->collectionExists('openregister')) {
            $this->logger->info('Base collection already exists');
            return true;
        }

        // Create base collection using the openregister configSet
        return $this->createCollection('openregister', 'openregister');
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

        $response = @file_get_contents($url);
        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);
        
        // Collection exists if it's in the cluster status
        return isset($data['cluster']['collections'][$collectionName]);
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

        $response = @file_get_contents($url);
        if ($response === false) {
            $this->logger->error('Failed to create collection', [
                'collection' => $collectionName,
                'configSet' => $configSetName
            ]);
            return false;
        }

        $data = json_decode($response, true);
        if (($data['responseHeader']['status'] ?? -1) === 0) {
            $this->logger->info('Collection created successfully', [
                'collection' => $collectionName,
                'configSet' => $configSetName
            ]);
            return true;
        }

        $this->logger->error('Collection creation failed', [
            'collection' => $collectionName,
            'response' => $data
        ]);
        return false;
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

        $response = @file_get_contents($url);
        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);
        
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

        $response = @file_get_contents($url);
        if ($response === false) {
            $this->logger->error('Failed to create core', [
                'core' => $coreName,
                'configSet' => $configSetName
            ]);
            return false;
        }

        $data = json_decode($response, true);
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

        // Define field type mappings for ObjectEntity properties
        $fieldDefinitions = $this->getObjectEntityFieldDefinitions();

        $success = true;
        foreach ($fieldDefinitions as $fieldName => $fieldConfig) {
            if (!$this->addOrUpdateSchemaField($fieldName, $fieldConfig)) {
                $this->logger->error('Failed to configure field', ['field' => $fieldName]);
                $success = false;
            }
        }

        if ($success) {
            $this->logger->info('Schema field configuration completed successfully');
        }

        return $success;
    }

    /**
     * Get field definitions for ObjectEntity metadata fields
     *
     * Based on ObjectEntity.php properties, this method returns the proper
     * SOLR field type configuration for each metadata field using self_ prefixes
     * and clean field names (no suffixes needed when explicitly defined).
     *
     * @return array Field definitions with SOLR type configuration
     */
    private function getObjectEntityFieldDefinitions(): array
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
        $baseCollectionName = $this->solrConfig['core'] ?? 'openregister';
        $url = $this->buildSolrUrl('/' . $baseCollectionName . '/schema');

        $payload = [
            'add-field' => array_merge(['name' => $fieldName], $fieldConfig)
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => json_encode($payload),
                'timeout' => 30
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);
        $success = ($data['responseHeader']['status'] ?? -1) === 0;

        if ($success) {
            $this->logger->debug('Added schema field', ['field' => $fieldName]);
        }

        return $success;
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
        $baseCollectionName = $this->solrConfig['core'] ?? 'openregister';
        $url = $this->buildSolrUrl('/' . $baseCollectionName . '/schema');

        $payload = [
            'replace-field' => array_merge(['name' => $fieldName], $fieldConfig)
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => json_encode($payload),
                'timeout' => 30
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);
        $success = ($data['responseHeader']['status'] ?? -1) === 0;

        if ($success) {
            $this->logger->debug('Replaced schema field', ['field' => $fieldName]);
        }

        return $success;
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
        // Check configSet exists
        if (!$this->configSetExists('openregister')) {
            $this->logger->error('Validation failed: configSet missing');
            return false;
        }

        // Check base collection exists
        if (!$this->collectionExists('openregister')) {
            $this->logger->error('Validation failed: base collection missing');
            return false;
        }

        // Test collection query functionality
        if (!$this->testCollectionQuery('openregister')) {
            $this->logger->error('Validation failed: collection query test failed');
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

        $response = @file_get_contents($url);
        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);
        
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

        $response = @file_get_contents($url);
        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);
        
        // Valid response should have a response header with status 0
        return ($data['responseHeader']['status'] ?? -1) === 0;
    }

}
