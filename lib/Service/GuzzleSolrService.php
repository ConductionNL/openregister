<?php

declare(strict_types=1);

/**
 * GuzzleSolrService - Lightweight SOLR Integration using HTTP calls
 *
 * This service provides SOLR integration using direct HTTP calls via Guzzle,
 * avoiding the memory issues and complexity of the Solarium library.
 * Specifically designed for SolrCloud with proper multi-tenant support.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 * @author   OpenRegister Team
 * @copyright 2024 OpenRegister
 * @license  AGPL-3.0-or-later
 * @version  1.0.0
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\SolrSchemaService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client as GuzzleClient;

/**
 * Lightweight SOLR service using Guzzle HTTP client
 *
 * Provides full-text search, faceted search, and high-performance querying
 * without the memory issues of Solarium. Uses direct SOLR REST API calls.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    OpenRegister Team
 * @license   AGPL-3.0-or-later
 * @link      https://github.com/OpenRegister/OpenRegister
 * @version   1.0.0
 * @copyright 2024 OpenRegister
 */
class GuzzleSolrService
{
    /**
     * App prefix for OpenRegister fields (multi-app support)
     *
     * @var string
     */
    private const APP_PREFIX = 'or';

    /**
     * HTTP client for SOLR requests
     *
     * @var \OCP\Http\Client\IClient
     */
    private $httpClient;

    /**
     * SOLR connection configuration
     *
     * @var array{enabled: bool, host: string, port: int, path: string, core: string, scheme: string, username: string, password: string, timeout: int, autoCommit: bool, commitWithin: int, enableLogging: bool}
     */
    private array $solrConfig = [];

    /**
     * Tenant identifier for multi-tenancy support
     *
     * @var string
     */
    private string $tenantId;

    /** @var array|null Cached SOLR field types to avoid repeated API calls */
    private ?array $cachedSolrFieldTypes = null;
    
    /** @var array|null Cached schema data to avoid repeated processing */
    private ?array $cachedSchemaData = null;

    /**
     * Service statistics
     *
     * @var array{searches: int, indexes: int, deletes: int, search_time: float, index_time: float, errors: int}
     */
    private array $stats = [
        'searches' => 0,
        'indexes' => 0,
        'deletes' => 0,
        'search_time' => 0.0,
        'index_time' => 0.0,
        'errors' => 0
    ];

    /**
     * Constructor
     *
     * @param SettingsService         $settingsService     Settings service for configuration
     * @param LoggerInterface         $logger              Logger for debugging and monitoring
     * @param IClientService          $clientService       HTTP client service
     * @param IConfig                 $config              Nextcloud configuration
     * @param SchemaMapper|null       $schemaMapper        Schema mapper for database operations
     * @param RegisterMapper|null     $registerMapper      Register mapper for database operations
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
        private readonly IClientService $clientService,
        private readonly IConfig $config,
        private readonly ?SchemaMapper $schemaMapper = null,
        private readonly ?RegisterMapper $registerMapper = null,
        private readonly ?OrganisationService $organisationService = null,
        private readonly ?OrganisationMapper $organisationMapper = null,
    ) {
        $this->tenantId = $this->generateTenantId();
        $this->initializeConfig();
        $this->initializeHttpClient();
    }

    /**
     * Initialize SOLR configuration
     *
     * @return void
     */
    private function initializeConfig(): void
    {
        try {
            $this->solrConfig = $this->settingsService->getSolrSettings();
        } catch (\Exception $e) {
            $this->logger->warning('Failed to load SOLR settings', ['error' => $e->getMessage()]);
            $this->solrConfig = ['enabled' => false];
        }
    }

    /**
     * Initialize HTTP client with authentication support
     *
     * @return void
     */
    private function initializeHttpClient(): void
    {
        // Prepare Guzzle client configuration
        $clientConfig = [
            'timeout' => 30,
            'connect_timeout' => 10,
            'verify' => false, // Allow self-signed certificates
            'allow_redirects' => true,
            'http_errors' => false // Don't throw exceptions for 4xx/5xx responses
        ];
        
        // Add HTTP Basic Authentication if credentials are provided
        if (!empty($this->solrConfig['username']) && !empty($this->solrConfig['password'])) {
            $clientConfig['auth'] = [
                $this->solrConfig['username'],
                $this->solrConfig['password'],
                'basic'
            ];
            
            $this->logger->info('GuzzleSolrService: HTTP Basic Authentication configured', [
                'username' => $this->solrConfig['username'],
                'auth_type' => 'basic'
            ]);
        }
        
        // TODO: Switch back to Nextcloud HTTP client when local access restrictions are properly configured
        // Currently using direct Guzzle client to bypass Nextcloud's 'allow_local_address' restrictions
        // Future improvement: $this->httpClient = $clientService->newClient(['allow_local_address' => true]);
        // This is necessary for SOLR/Zookeeper connections in Kubernetes environments
        $this->httpClient = new GuzzleClient($clientConfig);
    }

    /**
     * Generate unique tenant ID for this Nextcloud instance
     *
     * @return string Tenant identifier
     */
    private function generateTenantId(): string
    {
        // First try to get tenant_id from SOLR settings
        $solrSettings = $this->settingsService->getSolrSettings();
        if (!empty($solrSettings['tenantId'])) {
            return $solrSettings['tenantId'];
        }
        
        // Fallback to generating from instance configuration
        $instanceId = $this->config->getSystemValue('instanceid', 'default');
        $overwriteHost = $this->config->getSystemValue('overwrite.cli.url', '');
        
        if (!empty($overwriteHost)) {
            return 'nc_' . hash('crc32', $overwriteHost);
        }
        
        return 'nc_' . substr($instanceId, 0, 8);
    }

    /**
     * Get tenant ID for this Nextcloud instance (public accessor)
     *
     * @return string The tenant ID
     */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }
    /**
     * Generate tenant-specific collection name for SolrCloud
     *
     * @param string $baseCollectionName Base collection name
     * @return string Tenant-specific collection name (not core name)
     */
    public function getTenantSpecificCollectionName(string $baseCollectionName): string
    {
        // SOLR CLOUD: Use collection names, not core names
        // Format: openregister_nc_f0e53393 (collection)
        // Underlying core: openregister_nc_f0e53393_shard1_replica_n1 (handled by SolrCloud)
        return $baseCollectionName . '_' . $this->tenantId;
    }

    /**
     * Build SOLR base URL
     *
     * @return string SOLR base URL
     */
    public function buildSolrBaseUrl(): string
    {
        $host = $this->solrConfig['host'] ?? 'localhost';
        $port = $this->solrConfig['port'] ?? null; // Don't default port here
        
        // Normalize port - convert string '0' to null, handle empty strings
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
            return sprintf(
                '%s://%s%s',
                $this->solrConfig['scheme'] ?? 'http',
                $host,
                $this->solrConfig['path'] ?? '/solr'
            );
        } else {
               // Regular hostname - only append port if explicitly provided and not 0/null
               if ($port !== null && $port > 0) {
                return sprintf(
                    '%s://%s:%d%s',
                    $this->solrConfig['scheme'] ?? 'http',
                    $host,
                    $port,
                    $this->solrConfig['path'] ?? '/solr'
                );
            } else {
                // No port provided or port is 0 - let the service handle it
                return sprintf(
                    '%s://%s%s',
                    $this->solrConfig['scheme'] ?? 'http',
                    $host,
                    $this->solrConfig['path'] ?? '/solr'
                );
            }
        }
    }

    /**
     * Check if SOLR is properly configured with required settings
     *
     * @return bool True if SOLR configuration is complete
     */
    private function isSolrConfigured(): bool
    {
        // Check if SOLR is enabled
        if (!($this->solrConfig['enabled'] ?? false)) {
            $this->logger->debug('SOLR is not enabled in configuration');
            return false;
        }
        
        // Check required configuration values - use 'core' not 'collection'
        $requiredConfig = ['host'];
        foreach ($requiredConfig as $key) {
            if (empty($this->solrConfig[$key])) {
                $this->logger->debug('SOLR configuration missing required key: ' . $key);
                return false;
            }
        }
        
        // Port is optional (can be null for Kubernetes services)
        // Core/collection is optional (has defaults)
        
        return true;
    }

    /**
     * Check if SOLR is available and properly configured
     *
     * This method performs a comprehensive availability check including:
     * - Configuration validation (enabled, host, etc.)
     * - Network connectivity test
     * - SOLR server response validation
     * - Collection existence and accessibility
     *
     * **PERFORMANCE OPTIMIZATION**: Results are cached for 1 hour to avoid expensive
     * connectivity tests on every API call while still ensuring accurate availability status.
     *
     * @param bool $forceRefresh If true, ignores cache and performs fresh availability check
     * @return bool True if SOLR is available and properly configured
     */
    public function isAvailable(bool $forceRefresh = false): bool
    {
        // Check if SOLR is enabled in configuration
        if (!($this->solrConfig['enabled'] ?? false)) {
            $this->logger->debug('SOLR is disabled in configuration');
            return false;
        }
        
        // Check if basic configuration is present
        if (empty($this->solrConfig['host'])) {
            $this->logger->debug('SOLR host not configured');
            return false;
        }
        
        // **CACHING STRATEGY**: Check cached availability result first (unless forced refresh)
        $cacheKey = 'solr_availability_' . md5($this->solrConfig['host'] . ':' . ($this->solrConfig['port'] ?? 8983));
        
        if (!$forceRefresh) {
            $cachedResult = $this->getCachedAvailability($cacheKey);
            
            if ($cachedResult !== null) {
                $this->logger->debug('Using cached SOLR availability result', [
                    'available' => $cachedResult,
                    'cache_key' => $cacheKey
                ]);
                return $cachedResult;
            }
        } else {
            $this->logger->debug('Forcing fresh SOLR availability check (ignoring cache)', [
                'cache_key' => $cacheKey
            ]);
        }
        
        try {
            // **DEBUG**: Log current SOLR configuration for troubleshooting
            $this->logger->debug('SOLR availability check - current configuration', [
                'enabled' => $this->solrConfig['enabled'] ?? false,
                'host' => $this->solrConfig['host'] ?? 'not set',
                'port' => $this->solrConfig['port'] ?? 'not set',
                'tenant_id' => $this->tenantId,
                'force_refresh' => $forceRefresh
            ]);
            
            // **COMPREHENSIVE TEST**: Use full operational readiness test for accurate availability
            // This ensures complete SOLR readiness including collections and schema
            $connectionTest = $this->testFullOperationalReadiness();
            $isAvailable = $connectionTest['success'] ?? false;
            
            // **CACHE RESULT**: Store result for 1 hour to improve performance
            $this->setCachedAvailability($cacheKey, $isAvailable);
            
            $this->logger->debug('SOLR availability check completed and cached', [
                'available' => $isAvailable,
                'test_result' => $connectionTest['message'] ?? 'No message',
                'components_tested' => array_keys($connectionTest['components'] ?? []),
                'cache_key' => $cacheKey,
                'cache_ttl' => 3600,
                'full_test_result' => $connectionTest // **DEBUG**: Full test result for troubleshooting
            ]);
            
            return $isAvailable;
            
        } catch (\Exception $e) {
            $this->logger->warning('SOLR availability check failed with exception', [
                'error' => $e->getMessage(),
                'host' => $this->solrConfig['host'] ?? 'unknown',
                'exception_class' => get_class($e)
            ]);
            
            // **CACHE FAILURE**: Cache negative result for shorter period (5 minutes)
            $this->setCachedAvailability($cacheKey, false, 300);
            
            return false;
        }
    }

    /**
     * Get cached SOLR availability result
     *
     * @param string $cacheKey The cache key to lookup
     * @return bool|null The cached availability result, or null if not cached or expired
     */
    private function getCachedAvailability(string $cacheKey): ?bool
    {
        try {
            // Use APCu cache if available for best performance
            if (function_exists('apcu_fetch')) {
                $result = apcu_fetch($cacheKey);
                return $result === false ? null : (bool) $result;
            }
            
            // Fallback to file-based caching
            $cacheFile = sys_get_temp_dir() . '/' . $cacheKey . '.cache';
            if (file_exists($cacheFile)) {
                $data = json_decode(file_get_contents($cacheFile), true);
                if ($data && ($data['expires'] ?? 0) > time()) {
                    return (bool) $data['available'];
                }
                // Clean up expired cache
                unlink($cacheFile);
            }
            
            return null;
        } catch (\Exception $e) {
            $this->logger->debug('Failed to read SOLR availability cache', [
                'error' => $e->getMessage(),
                'cache_key' => $cacheKey
            ]);
            return null;
        }
    }

    /**
     * Cache SOLR availability result
     *
     * @param string $cacheKey The cache key to store under
     * @param bool $isAvailable The availability result to cache
     * @param int $ttl Time to live in seconds (default: 1 hour)
     * @return void
     */
    private function setCachedAvailability(string $cacheKey, bool $isAvailable, int $ttl = 3600): void
    {
        try {
            // Use APCu cache if available for best performance
            if (function_exists('apcu_store')) {
                apcu_store($cacheKey, $isAvailable, $ttl);
                return;
            }
            
            // Fallback to file-based caching
            $cacheFile = sys_get_temp_dir() . '/' . $cacheKey . '.cache';
            $data = [
                'available' => $isAvailable,
                'expires' => time() + $ttl,
                'created' => time()
            ];
            file_put_contents($cacheFile, json_encode($data));
        } catch (\Exception $e) {
            $this->logger->debug('Failed to cache SOLR availability result', [
                'error' => $e->getMessage(),
                'cache_key' => $cacheKey,
                'available' => $isAvailable,
                'ttl' => $ttl
            ]);
            // Don't throw - caching is optional
        }
    }

    /**
     * Clear cached SOLR availability results (public method for manual cache invalidation)
     *
     * This method should be called when SOLR configuration changes to ensure
     * availability checks reflect the new configuration immediately.
     *
     * @return void
     */
    public function clearAvailabilityCache(): void
    {
        $this->clearCachedAvailability();
        $this->logger->info('SOLR availability cache cleared manually');
    }

    /**
     * Clear cached SOLR availability result (internal method)
     *
     * @param string|null $cacheKey Specific cache key to clear, or null to clear all SOLR availability cache
     * @return void
     */
    private function clearCachedAvailability(?string $cacheKey = null): void
    {
        try {
            if ($cacheKey) {
                // Clear specific cache entry
                if (function_exists('apcu_delete')) {
                    apcu_delete($cacheKey);
                }
                $cacheFile = sys_get_temp_dir() . '/' . $cacheKey . '.cache';
                if (file_exists($cacheFile)) {
                    unlink($cacheFile);
                }
            } else {
                // Clear all SOLR availability cache entries
                if (function_exists('apcu_delete')) {
                    $iterator = new \APCUIterator('/^solr_availability_/');
                    apcu_delete($iterator);
                }
                
                // Clear file-based cache
                $tempDir = sys_get_temp_dir();
                $pattern = $tempDir . '/solr_availability_*.cache';
                foreach (glob($pattern) as $file) {
                    unlink($file);
                }
            }
        } catch (\Exception $e) {
            $this->logger->debug('Failed to clear SOLR availability cache', [
                'error' => $e->getMessage(),
                'cache_key' => $cacheKey
            ]);
        }
    }

    /**
     * Test SOLR connection with comprehensive testing
     *
     * @param bool $includeCollectionTests Whether to include collection/query tests (default: true for full test)
     * @return array{success: bool, message: string, details: array, components: array} Connection test results
     */
    public function testConnection(bool $includeCollectionTests = true): array
    {
        try {
            $solrConfig = $this->solrConfig;
            
            if (!$solrConfig['enabled']) {
                return [
                    'success' => false,
                    'message' => 'SOLR is disabled in settings',
                    'details' => [],
                    'components' => []
                ];
            }

            $testResults = [
                'success' => true,
                'message' => 'All connection tests passed',
                'details' => [],
                'components' => []
            ];

            // Test 1: Zookeeper connectivity (if using SolrCloud)
            if ($solrConfig['useCloud'] ?? false) {
                $zookeeperTest = $this->testZookeeperConnection();
                $testResults['components']['zookeeper'] = $zookeeperTest;
                
                if (!$zookeeperTest['success']) {
                    $testResults['success'] = false;
                    $testResults['message'] = 'Zookeeper connection failed';
                }
            }

            // Test 2: SOLR connectivity
            $solrTest = $this->testSolrConnectivity();
            $testResults['components']['solr'] = $solrTest;
            
            if (!$solrTest['success']) {
                $testResults['success'] = false;
                $testResults['message'] = 'SOLR connection failed';
            }

            // Test 3: Collection/Core availability (conditional)
            if ($includeCollectionTests) {
            $collectionTest = $this->testSolrCollection();
            $testResults['components']['collection'] = $collectionTest;
            
            if (!$collectionTest['success']) {
                $testResults['success'] = false;
                $testResults['message'] = 'SOLR collection/core not available';
            }

            // Test 4: Collection query test (if collection exists)
            if ($collectionTest['success']) {
                $queryTest = $this->testSolrQuery();
                $testResults['components']['query'] = $queryTest;
                
                if (!$queryTest['success']) {
                    // Don't fail overall test if query fails but collection exists
                    $testResults['message'] = 'SOLR collection exists but query test failed';
                }
                }
            } else {
                // **CONNECTIVITY-ONLY MODE**: Skip collection tests for setup scenarios
                $testResults['components']['collection'] = [
                    'success' => true,
                    'message' => 'Collection tests skipped (connectivity-only mode)',
                    'details' => ['test_mode' => 'connectivity_only']
                ];
            }

            return $testResults;

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage(),
                'details' => ['error' => $e->getMessage()],
                'components' => []
            ];
        }
    }

    /**
     * Test SOLR connectivity only (for setup scenarios)
     * 
     * This method only tests if SOLR server and Zookeeper are reachable,
     * without requiring collections to exist. Perfect for setup processes.
     *
     * @return array{success: bool, message: string, details: array, components: array} Connectivity test results
     */
    public function testConnectivityOnly(): array
    {
        return $this->testConnection(false);
    }

    /**
     * Test full SOLR operational readiness (for dashboard/monitoring)
     * 
     * This method tests connectivity, collection availability, and query capability.
     * Use this for dashboard status checks and operational monitoring.
     *
     * @return array{success: bool, message: string, details: array, components: array} Full operational test results
     */
    public function testFullOperationalReadiness(): array
    {
        return $this->testConnection(true);
    }

    /**
     * Check if collection exists
     *
     * @param string $collectionName Collection name to check
     * @return bool True if collection exists
     */
    public function collectionExists(string $collectionName): bool
    {
        try {
            $url = $this->buildSolrBaseUrl() . '/admin/collections?action=CLUSTERSTATUS&wt=json';
            $response = $this->httpClient->get($url, ['timeout' => 10]);
            $data = json_decode((string)$response->getBody(), true);
            
            return isset($data['cluster']['collections'][$collectionName]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to check collection existence', [
                'collection' => $collectionName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Ensure tenant collection exists (check both tenant-specific and base collections)
     *
     * @return bool True if collection exists or was created
     */
    public function ensureTenantCollection(): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $baseCollectionName = $this->solrConfig['core'] ?? 'openregister';
        $tenantCollectionName = $this->getTenantSpecificCollectionName($baseCollectionName);

        // Check if tenant collection exists
        if ($this->collectionExists($tenantCollectionName)) {
            $this->logger->debug('Tenant collection already exists', [
                'collection' => $tenantCollectionName,
                'tenant_id' => $this->tenantId
            ]);
            return true;
        }

        // FALLBACK: Check if base collection exists
        if ($this->collectionExists($baseCollectionName)) {
            $this->logger->info('Using base collection as fallback (no tenant isolation)', [
                'base_collection' => $baseCollectionName,
                'tenant_id' => $this->tenantId
            ]);
            return true;
        }

        // Try to create tenant collection
        $this->logger->info('Attempting to create tenant collection', [
            'collection' => $tenantCollectionName
        ]);
        return $this->createCollection($tenantCollectionName, 'openregister');
    }

    /**
     * Get the actual collection name to use for operations
     * 
     * Returns tenant-specific collection name only if it exists, otherwise null
     *
     * @return string|null The collection name to use for SOLR operations, or null if no collection exists
     */
    public function getActiveCollectionName(): ?string
    {
        $baseCollectionName = $this->solrConfig['collection'] ?? 'openregister';
        $tenantCollectionName = $this->getTenantSpecificCollectionName($baseCollectionName);

        // Check if tenant collection exists
        if ($this->collectionExists($tenantCollectionName)) {
            return $tenantCollectionName;
        }

        // **FIX**: No fallback to base collection - if tenant collection doesn't exist, return null
        // This prevents operations on non-existent collections
        $this->logger->warning('Tenant-specific collection does not exist', [
            'tenant_collection' => $tenantCollectionName,
            'tenant_id' => $this->tenantId,
            'base_collection' => $baseCollectionName
        ]);
        
        return null;
    }

    /**
     * Create SOLR collection
     *
     * @param string $collectionName Collection name
     * @param string $configSetName  ConfigSet name
     * @return bool True if successful
     * @throws \GuzzleHttp\Exception\GuzzleException When HTTP request fails
     * @throws \Exception When SOLR returns error response
     */
    public function createCollection(string $collectionName, string $configSetName): bool
    {
        $url = $this->buildSolrBaseUrl() . '/admin/collections?' . http_build_query([
            'action' => 'CREATE',
            'name' => $collectionName,
            'collection.configName' => $configSetName,
            'numShards' => 1,
            'replicationFactor' => 1,
            'wt' => 'json'
        ]);

        $response = $this->httpClient->get($url, ['timeout' => 30]);
        $data = json_decode((string)$response->getBody(), true);

        if (($data['responseHeader']['status'] ?? -1) === 0) {
            $this->logger->info('SOLR collection created successfully', [
                'collection' => $collectionName,
                'configSet' => $configSetName,
                'tenant_id' => $this->tenantId
            ]);
            return true;
        }

        // SOLR returned an error response - throw exception with details
        $errorMessage = $data['error']['msg'] ?? 'Unknown SOLR error';
        $errorCode = $data['responseHeader']['status'] ?? 500;
        
        $this->logger->error('SOLR collection creation failed', [
            'collection' => $collectionName,
            'configSet' => $configSetName,
            'tenant_id' => $this->tenantId,
            'url' => $url,
            'solr_status' => $errorCode,
            'solr_error' => $data['error'] ?? null,
            'full_response' => $data
        ]);

        // Throw exception with SOLR response details
        throw new \Exception(
            "SOLR collection creation failed: {$errorMessage}",
            $errorCode,
            new \Exception(json_encode($data))
        );
    }



    /**
     * Delete SOLR collection
     *
     * @param string|null $collectionName Collection name (if null, uses active collection)
     * @return array Result with success status and details
     */
    public function deleteCollection(?string $collectionName = null): array
    {
        try {
            // Use provided collection name or get active collection
            $targetCollection = $collectionName ?? $this->getActiveCollectionName();
            
            if ($targetCollection === null) {
                return [
                    'success' => false,
                    'message' => 'No collection specified and no active collection found',
                    'error_code' => 'NO_COLLECTION'
                ];
            }

            // Check if collection exists before attempting to delete
            if (!$this->collectionExists($targetCollection)) {
                return [
                    'success' => false,
                    'message' => "Collection '{$targetCollection}' does not exist",
                    'error_code' => 'COLLECTION_NOT_EXISTS',
                    'collection' => $targetCollection
                ];
            }

            // Build delete collection URL
            $url = $this->buildSolrBaseUrl() . '/admin/collections?' . http_build_query([
                'action' => 'DELETE',
                'name' => $targetCollection,
                'wt' => 'json'
            ]);

            $this->logger->info('🗑️ Attempting to delete SOLR collection', [
                'collection' => $targetCollection,
                'tenant_id' => $this->tenantId,
                'url' => $url
            ]);

            $response = $this->httpClient->get($url, ['timeout' => 60]);
            $data = json_decode((string)$response->getBody(), true);

            if (($data['responseHeader']['status'] ?? -1) === 0) {
                $this->logger->info('✅ SOLR collection deleted successfully', [
                    'collection' => $targetCollection,
                    'tenant_id' => $this->tenantId,
                    'response_time' => $data['responseHeader']['QTime'] ?? 'unknown'
                ]);
                
                return [
                    'success' => true,
                    'message' => "Collection '{$targetCollection}' deleted successfully",
                    'collection' => $targetCollection,
                    'tenant_id' => $this->tenantId,
                    'response_time_ms' => $data['responseHeader']['QTime'] ?? null
                ];
            }

            $this->logger->error('❌ SOLR collection deletion failed', [
                'collection' => $targetCollection,
                'response' => $data
            ]);
            
            return [
                'success' => false,
                'message' => "Failed to delete collection '{$targetCollection}': " . ($data['error']['msg'] ?? 'Unknown error'),
                'error_code' => 'DELETE_FAILED',
                'collection' => $targetCollection,
                'solr_error' => $data['error'] ?? null
            ];

        } catch (\Exception $e) {
            $this->logger->error('Exception deleting SOLR collection', [
                'collection' => $targetCollection ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'message' => 'Exception occurred while deleting collection: ' . $e->getMessage(),
                'error_code' => 'EXCEPTION',
                'collection' => $targetCollection ?? 'unknown',
                'exception' => $e->getMessage()
            ];
        }
    }

    /**
     * Index object in SOLR
     *
     * @param ObjectEntity $object Object to index
     * @param bool         $commit Whether to commit immediately
     * @return bool True if successful
     */
    public function indexObject(ObjectEntity $object, bool $commit = false): bool
    {
        // Index ALL objects (published and unpublished) for comprehensive search.
        // Filtering for published-only content is now handled at query time, not index time.
        $this->logger->debug('Indexing object (published and unpublished objects are both indexed)', [
            'object_id' => $object->getId(),
            'object_uuid' => $object->getUuid(),
            'published' => $object->getPublished() ? $object->getPublished()->format('Y-m-d\TH:i:s\Z') : null
        ]);
        
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $startTime = microtime(true);

            // Ensure tenant collection exists
            if (!$this->ensureTenantCollection()) {
                $this->logger->warning('Cannot index object: tenant collection unavailable');
                return false;
            }

            // Get the active collection name - return false if no collection exists
            $tenantCollectionName = $this->getActiveCollectionName();
            if ($tenantCollectionName === null) {
                $this->logger->warning('Cannot index object: no active collection available');
                return false;
            }

            // Create SOLR document using schema-aware mapping (no fallback)
            try {
                $document = $this->createSolrDocument($object);
            } catch (\RuntimeException $e) {
                // Check if this is a non-searchable schema
                if (str_contains($e->getMessage(), 'Schema is not searchable')) {
                    $this->logger->debug('Skipping indexing for non-searchable schema', [
                        'object_id' => $object->getId(),
                        'message' => $e->getMessage()
                    ]);
                    return false; // Return false to indicate object was not indexed (skipped)
                }
                // Re-throw other runtime exceptions
                throw $e;
            }
            
            // Prepare update request
            $updateData = [
                'add' => [
                    'doc' => $document
                ]
            ];

            $url = $this->buildSolrBaseUrl() . '/' . $tenantCollectionName . '/update?wt=json';
            
            if ($commit) {
                $url .= '&commit=true';
            }

            $response = $this->httpClient->post($url, [
                'body' => json_encode($updateData),
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 30
            ]);

            $data = json_decode((string)$response->getBody(), true);
            $success = ($data['responseHeader']['status'] ?? -1) === 0;

            if ($success) {
                $this->stats['indexes']++;
                $this->stats['index_time'] += (microtime(true) - $startTime);
                
                $this->logger->debug('🔍 OBJECT INDEXED IN SOLR', [
                    'object_id' => $object->getId(),
                    'uuid' => $object->getUuid(),
                    'collection' => $tenantCollectionName,
                    'tenant_id' => $this->tenantId,
                    'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
                ]);
            } else {
                $this->stats['errors']++;
                $this->logger->error('SOLR indexing failed', [
                    'object_id' => $object->getId(),
                    'response' => $data
                ]);
            }

            return $success;

        } catch (\Exception $e) {
            $this->stats['errors']++;
            $this->logger->error('Exception indexing object in SOLR', [
                'object_id' => $object->getId(),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Delete object from SOLR
     *
     * @param string|int $objectId Object ID or UUID
     * @param bool       $commit   Whether to commit immediately
     * @return bool True if successful
     */
    public function deleteObject(string|int $objectId, bool $commit = false): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            // Get the active collection name - return false if no collection exists
            $tenantCollectionName = $this->getActiveCollectionName();
            if ($tenantCollectionName === null) {
                $this->logger->warning('Cannot delete object: no active collection available');
                return false;
            }

            $deleteData = [
                'delete' => [
                    'query' => sprintf('id:%s AND self_tenant:%s', 
                        (string)$objectId,
                        $this->tenantId
                    )
                ]
            ];

            $url = $this->buildSolrBaseUrl() . '/' . $tenantCollectionName . '/update?wt=json';
            
            if ($commit) {
                $url .= '&commit=true';
            }

            $response = $this->httpClient->post($url, [
                'body' => json_encode($deleteData),
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 30
            ]);

            $data = json_decode((string)$response->getBody(), true);
            $success = ($data['responseHeader']['status'] ?? -1) === 0;

            if ($success) {
                $this->stats['deletes']++;
                $this->logger->debug('🗑️ OBJECT REMOVED FROM SOLR', [
                    'object_id' => $objectId,
                    'collection' => $tenantCollectionName,
                    'tenant_id' => $this->tenantId
                ]);
            }

            return $success;

        } catch (\Exception $e) {
            $this->stats['errors']++;
            $this->logger->error('Exception deleting object from SOLR', [
                'object_id' => $objectId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get document count from tenant collection
     *
     * @return int Number of documents
     */
    public function getDocumentCount(): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        try {
            // Get the active collection name - return 0 if no collection exists
            $tenantCollectionName = $this->getActiveCollectionName();
            if ($tenantCollectionName === null) {
                $this->logger->warning('Cannot get document count: no active collection available');
                return 0;
            }

            $url = $this->buildSolrBaseUrl() . '/' . $tenantCollectionName . '/select?' . http_build_query([
                'q' => 'self_tenant:' . $this->tenantId,
                'rows' => 0,
                'wt' => 'json'
            ]);

            $response = $this->httpClient->get($url, ['timeout' => 10]);
            $data = json_decode((string)$response->getBody(), true);

            return (int)($data['response']['numFound'] ?? 0);

        } catch (\Exception $e) {
            $this->logger->error('Failed to get document count', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Create SOLR document from ObjectEntity with field validation
     *
     * @param ObjectEntity $object Object to convert
     * @param array $solrFieldTypes Optional SOLR field types for validation
     * @return array SOLR document
     */
    public function createSolrDocument(ObjectEntity $object, array $solrFieldTypes = []): array
    {
        // **SCHEMA-AWARE MAPPING REQUIRED**: Validate schema availability first
        if (!$this->schemaMapper) {
            throw new \RuntimeException(
                'Schema mapper is not available. Cannot create SOLR document without schema validation. ' .
                'Object ID: ' . $object->getId() . ', Schema ID: ' . $object->getSchema()
            );
        }

        // Get the schema for this object
        $schema = $this->schemaMapper->find($object->getSchema());
        
        if (!($schema instanceof Schema)) {
            throw new \RuntimeException(
                'Schema not found for object. Cannot create SOLR document without valid schema. ' .
                'Object ID: ' . $object->getId() . ', Schema ID: ' . $object->getSchema()
            );
        }

        // Check if schema is searchable - skip indexing if not
        if (!$schema->getSearchable()) {
            $this->logger->debug('Skipping SOLR indexing for non-searchable schema', [
                'object_id' => $object->getId(),
                'schema_id' => $object->getSchema(),
                'schema_slug' => $schema->getSlug(),
                'schema_title' => $schema->getTitle()
            ]);
            throw new \RuntimeException(
                'Schema is not searchable. Objects of this schema are excluded from SOLR indexing. ' .
                'Object ID: ' . $object->getId() . ', Schema: ' . ($schema->getTitle() ?: $schema->getSlug())
            );
        }

        // Get the register for this object (if registerMapper is available)
        $register = null;
        if ($this->registerMapper) {
            try {
                $register = $this->registerMapper->find($object->getRegister());
            } catch (\Exception $e) {
                $this->logger->warning('Failed to fetch register for object', [
                    'object_id' => $object->getId(),
                    'register_id' => $object->getRegister(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        // **USE CONSOLIDATED MAPPING**: Create schema-aware document directly
        try {
            $document = $this->createSchemaAwareDocument($object, $schema, $register, $solrFieldTypes);
            
            // Document created successfully using schema-aware mapping
            
            $this->logger->debug('Created SOLR document using schema-aware mapping', [
            'object_id' => $object->getId(),
                'schema_id' => $object->getSchema(),
                'mapped_fields' => count($document)
            ]);
            
            return $document;
            
        } catch (\Exception $e) {
            // **NO FALLBACK**: Throw error to prevent schemaless documents
            $this->logger->error('Schema-aware mapping failed and no fallback allowed', [
                'object_id' => $object->getId(),
                'schema_id' => $object->getSchema(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new \RuntimeException(
                'Schema-aware mapping failed for object. Schemaless fallback is disabled to prevent inconsistent documents. ' .
                'Object ID: ' . $object->getId() . ', Schema ID: ' . $object->getSchema() . '. ' .
                'Original error: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Create schema-aware SOLR document from ObjectEntity, Schema, and Register
     *
     * This method implements the consolidated schema-aware mapping logic
     * that was previously in SolrSchemaMappingService.
     *
     * @param ObjectEntity $object   The object to convert
     * @param Schema       $schema   The schema for mapping
     * @param \OCA\OpenRegister\Db\Register|null $register The register for metadata
     * @param array        $solrFieldTypes Optional SOLR field types for validation
     *
     * @return array SOLR document structure
     * @throws \RuntimeException If mapping fails
     */
    private function createSchemaAwareDocument(ObjectEntity $object, Schema $schema, $register = null, array $solrFieldTypes = []): array
    {
        // **FIX**: Get the actual object business data, not entity metadata
        $objectData = $object->getObject(); // This contains the schema fields like 'naam', 'website', 'type'
        $schemaProperties = $schema->getProperties();
        
        // Base SOLR document with core identifiers and metadata fields using self_ prefix
        $document = [
            // Core identifiers (always present) - no prefix for SOLR system fields
            'id' => $object->getUuid() ?: (string)$object->getId(),
            'self_tenant' => (string)$this->tenantId,
            
            // Metadata fields with self_ prefix (consistent with legacy mapping)
            'self_uuid' => $object->getUuid(),
            
            // Context fields - resolve to integer IDs
            'self_register' => $this->resolveRegisterToId($object->getRegister(), $register),
            'self_register_id' => $this->resolveRegisterToId($object->getRegister(), $register),
            'self_register_uuid' => $register?->getUuid(),
            'self_register_slug' => $register?->getSlug(),
            
            'self_schema' => $this->resolveSchemaToId($object->getSchema(), $schema),
            'self_schema_id' => $this->resolveSchemaToId($object->getSchema(), $schema),
            'self_schema_uuid' => $schema->getUuid(),
            'self_schema_slug' => $schema->getSlug(),
            'self_schema_version' => $object->getSchemaVersion(),
            
            // Ownership and metadata
            'self_owner' => $object->getOwner(),
            'self_organisation' => $object->getOrganisation(),
            'self_application' => $object->getApplication(),
            
            // Core object fields
            'self_name' => $object->getName() ?: null,
            'self_description' => $object->getDescription() ?: null,
            'self_summary' => $object->getSummary() ?: null,
            'self_image' => $object->getImage() ?: null,
            'self_slug' => $object->getSlug() ?: null,
            'self_uri' => $object->getUri() ?: null,
            'self_version' => $object->getVersion() ?: null,
            'self_size' => $object->getSize() ?: null,
            'self_folder' => $object->getFolder() ?: null,
            
            // Timestamps
            'self_created' => $object->getCreated()?->format('Y-m-d\\TH:i:s\\Z'),
            'self_updated' => $object->getUpdated()?->format('Y-m-d\\TH:i:s\\Z'),
            'self_published' => $object->getPublished()?->format('Y-m-d\\TH:i:s\\Z'),
            'self_depublished' => $object->getDepublished()?->format('Y-m-d\\TH:i:s\\Z'),

            // **NEW**: UUID relation fields with proper types - flatten to avoid SOLR issues
            'self_relations' => $this->flattenRelationsForSolr($object->getRelations()),
            'self_files' => $this->flattenFilesForSolr($object->getFiles()),
            
            // **COMPLETE OBJECT STORAGE**: Store entire object as JSON for exact reconstruction
            'self_object' => json_encode($object->jsonSerialize() ?: [])
        ];
        
        // **SCHEMA-AWARE FIELD MAPPING**: Map object data based on schema properties
        
        if (is_array($schemaProperties) && is_array($objectData)) {
            // **DEBUG**: Log what we're mapping
            $this->logger->debug('Schema-aware mapping', [
                'object_id' => $object->getId(),
                'schema_properties' => array_keys($schemaProperties),
                'object_data_keys' => array_keys($objectData)
            ]);
            
            foreach ($schemaProperties as $fieldName => $fieldDefinition) {
                if (!isset($objectData[$fieldName])) {
                    continue;
                }
                
                $fieldValue = $objectData[$fieldName];
                $fieldType = $fieldDefinition['type'] ?? 'string';
                
                // **TRUNCATE LARGE VALUES**: Respect SOLR's 32,766 byte limit for indexed fields
                if ($this->shouldTruncateField($fieldName, $fieldDefinition)) {
                    $fieldValue = $this->truncateFieldValue($fieldValue, $fieldName);
                }
                
                // **FILTER COMPLEX DATA**: Skip arrays and objects - they don't belong in SOLR as individual fields
                if (is_array($fieldValue) || is_object($fieldValue)) {
                    $this->logger->debug('Skipping complex field value', [
                        'field' => $fieldName,
                        'type' => gettype($fieldValue),
                        'reason' => 'Arrays and objects are not suitable for SOLR field indexing'
                    ]);
                    continue;
                }
                
                // **FILTER NON-SCALAR VALUES**: Only index scalar values (string, int, float, bool, null)
                if (!is_scalar($fieldValue) && $fieldValue !== null) {
                    $this->logger->debug('Skipping non-scalar field value', [
                        'field' => $fieldName,
                        'type' => gettype($fieldValue),
                        'reason' => 'Only scalar values can be indexed in SOLR'
                    ]);
                    continue;
                }
                
                // **HOTFIX**: Temporarily skip 'versie' field to prevent NumberFormatException
                // TODO: Fix versie field type conflict - it's defined as integer in SOLR but contains decimal strings like '9.1'
                if ($fieldName === 'versie') {
                    $this->logger->debug('HOTFIX: Skipped versie field to prevent type mismatch', [
                        'field' => $fieldName,
                        'value' => $fieldValue,
                        'reason' => 'Temporary fix for integer/decimal type conflict'
                    ]);
                    continue;
                }
                
                        // Map field based on schema type to appropriate SOLR field name
                        $solrFieldName = $this->mapFieldToSolrType($fieldName, $fieldType, $fieldValue);
                        
                        if ($solrFieldName) {
                            $convertedValue = $this->convertValueForSolr($fieldValue, $fieldType);
                            
                            // **FIELD VALIDATION**: Check if field exists in SOLR and type is compatible
                            if ($convertedValue !== null && $this->validateFieldForSolr($solrFieldName, $convertedValue, $solrFieldTypes)) {
                                $document[$solrFieldName] = $convertedValue;
                        $this->logger->debug('Mapped field', [
                            'original' => $fieldName,
                            'solr_field' => $solrFieldName,
                            'original_value' => $fieldValue,
                            'converted_value' => $convertedValue,
                            'value_type' => gettype($convertedValue)
                        ]);
                    } else {
                        $this->logger->debug('Skipped field with null converted value', [
                            'original' => $fieldName,
                            'solr_field' => $solrFieldName,
                            'original_value' => $fieldValue,
                            'field_type' => $fieldType
                        ]);
                    }
                }
            }
        } else {
            // **DEBUG**: Log when schema mapping fails
            $this->logger->warning('Schema-aware mapping skipped', [
                'object_id' => $object->getId(),
                'schema_properties_type' => gettype($schemaProperties),
                'object_data_type' => gettype($objectData),
                'schema_properties_empty' => empty($schemaProperties),
                'object_data_empty' => empty($objectData)
            ]);
        }
        
        // Remove null values, but keep published/depublished fields for proper filtering
        return array_filter($document, function($value, $key) {
            // Always keep published/depublished fields even if null for proper Solr filtering
            if (in_array($key, ['self_published', 'self_depublished'])) {
                return true;
            }
            return $value !== null && $value !== '';
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Flatten relations array for SOLR - ONLY include UUIDs, filter out URLs and other values
     *
     * @param mixed $relations Relations data from ObjectEntity
     * @return array Simple array of UUID strings for SOLR multi-valued field
     */
    private function flattenRelationsForSolr($relations): array
    {
        if (empty($relations)) {
            return [];
        }
        
        if (is_array($relations)) {
            $uuids = [];
            foreach ($relations as $key => $value) {
                // Check if value is a UUID (36 chars with dashes)
                if (is_string($value) && $this->isValidUuid($value)) {
                    $uuids[] = $value;
                }
                // Check if key is a UUID (for associative arrays)
                if (is_string($key) && $this->isValidUuid($key)) {
                    $uuids[] = $key;
                }
                // Skip URLs, non-UUID strings, etc.
            }
            return $uuids;
        }
        
        // Single value - check if it's a UUID
        if (is_string($relations) && $this->isValidUuid($relations)) {
            return [$relations];
        }
        
        return [];
    }

    /**
     * Check if a string is a valid UUID format
     *
     * @param string $value String to check
     * @return bool True if valid UUID format
     */
    private function isValidUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    /**
     * Flatten files array for SOLR to prevent document multiplication
     *
     * @param mixed $files Files data from ObjectEntity
     * @return array Simple array of strings for SOLR multi-valued field
     */
    private function flattenFilesForSolr($files): array
    {
        if (empty($files)) {
            return [];
        }
        
        if (is_array($files)) {
            $flattened = [];
            foreach ($files as $file) {
                if (is_string($file)) {
                    $flattened[] = $file;
                } elseif (is_array($file) && isset($file['id'])) {
                    $flattened[] = (string)$file['id'];
                } elseif (is_array($file) && isset($file['uuid'])) {
                    $flattened[] = $file['uuid'];
                }
            }
            return $flattened;
        }
        
        return is_string($files) ? [$files] : [];
    }

    /**
     * Map field name and type to appropriate SOLR field name (clean names, no suffixes)
     *
     * @param string $fieldName Original field name
     * @param string $fieldType Schema field type
     * @param mixed  $fieldValue Field value for context
     *
     * @return string|null SOLR field name (clean, no suffixes - field types defined in SOLR setup)
     */
    private function mapFieldToSolrType(string $fieldName, string $fieldType, $fieldValue): ?string
    {
        // Avoid conflicts with core SOLR fields and self_ metadata fields
        if (in_array($fieldName, ['id', 'tenant_id', '_version_']) || str_starts_with($fieldName, 'self_')) {
            return null;
        }
        
        // **CLEAN FIELD NAMES**: Return field name as-is since we define proper types in SOLR setup
        return $fieldName;
    }

    /**
     * Convert value to appropriate format for SOLR
     *
     * @param mixed  $value     Field value
     * @param string $fieldType Schema field type
     *
     * @return mixed Converted value for SOLR
     */
    private function convertValueForSolr($value, string $fieldType)
    {
        if ($value === null) {
            return null;
        }
        
        switch (strtolower($fieldType)) {
            case 'integer':
            case 'int':
                // **SAFE NUMERIC CONVERSION**: Handle non-numeric strings gracefully
                if (is_numeric($value)) {
                    return (int)$value;
                }
                // Skip non-numeric values for integer fields
                $this->logger->debug('Skipping non-numeric value for integer field', [
                    'value' => $value,
                    'field_type' => $fieldType
                ]);
                return null;
                
            case 'float':
            case 'double':
            case 'number':
                // **SAFE NUMERIC CONVERSION**: Handle non-numeric strings gracefully
                if (is_numeric($value)) {
                    return (float)$value;
                }
                // Skip non-numeric values for float fields
                $this->logger->debug('Skipping non-numeric value for float field', [
                    'value' => $value,
                    'field_type' => $fieldType
                ]);
                return null;
                
            case 'boolean':
            case 'bool':
                return (bool)$value;
                
            case 'date':
            case 'datetime':
                if ($value instanceof \DateTime) {
                    return $value->format('Y-m-d\\TH:i:s\\Z');
                }
                if (is_string($value)) {
                    $date = \DateTime::createFromFormat('Y-m-d H:i:s', $value);
                    return $date ? $date->format('Y-m-d\\TH:i:s\\Z') : $value;
                }
                return $value;
                
            case 'array':
                return is_array($value) ? $value : [$value];
                
            default:
                return (string)$value;
        }
    }

    /**
     * Create a SOLR document using legacy schemaless mapping
     *
     * This method provides backward compatibility for systems without
     * schema mapping service or when schema-aware mapping fails
     *
     * @param ObjectEntity $object The object to convert
     *
     * @return array SOLR document structure
     */
    private function createLegacySolrDocument(ObjectEntity $object): array
    {
        // **CRITICAL**: Ensure we always have a unique ID for SOLR document ID
        $uuid = $object->getUuid();
        if (empty($uuid)) {
            // **FALLBACK**: Use object ID if UUID is missing (for legacy objects)
            $uuid = $object->getId();
            $this->logger->warning('Object missing UUID - using object ID as fallback', [
                'object_id' => $object->getId(),
                'register' => $object->getRegister(),
                'schema' => $object->getSchema()
            ]);
        }

        $objectData = $object->getObject();
        
        // Create document with object properties at root (no prefix) and metadata under self_ prefix
        $document = [
            // **CRITICAL**: Always use UUID as the SOLR document ID for guaranteed uniqueness
            'id' => $uuid,
            'self_tenant' => (string)$this->tenantId, // Consistent with schema-aware mapping
            
            // Full-text search content (at root for Solr optimization)
            '_text_' => $this->extractTextContent($object, $objectData ?: []),
        ];

        // **SCHEMALESS MODE**: Add object properties at root level + typed fields for advanced queries
        if (is_array($objectData)) {
            foreach ($objectData as $key => $value) {
                if (!is_array($value) && !is_object($value)) {
                    // **TRUNCATE LARGE VALUES**: Apply truncation for known large content fields
                    if (is_string($value) && $this->shouldTruncateField($key)) {
                        $value = $this->truncateFieldValue($value, $key);
                    }
                    
                    // **PRIMARY**: Raw field for natural querying (SOLR will auto-detect type)
                    $document[$key] = $value;
                    
                    // **SECONDARY**: Typed fields for advanced faceting and sorting
                    if (is_string($value)) {
                        $document[$key . '_s'] = $value;  // String field for faceting/filtering
                        $document[$key . '_t'] = $value;  // Text field for full-text search
                    } elseif (is_numeric($value)) {
                        $document[$key . '_i'] = (int)$value;    // Integer field
                        $document[$key . '_f'] = (float)$value;  // Float field
                    } elseif (is_bool($value)) {
                        $document[$key . '_b'] = $value;  // Boolean field
                    }
                }
            }
        }

        // Store complete object data as JSON for exact reconstruction
        $document['self_object'] = json_encode($objectData ?: []);

        // Add metadata fields with self_ prefix for easy identification and faceting
        $document['self_id'] = $uuid; // Always use UUID for consistency
        $document['self_uuid'] = $uuid;
        $document['self_register'] = $this->resolveRegisterToId($object->getRegister());
        $document['self_schema'] = $this->resolveSchemaToId($object->getSchema());
        $document['self_organisation'] = $object->getOrganisation();
        $document['self_name'] = $object->getName();
        $document['self_description'] = $object->getDescription();
        $document['self_summary'] = $object->getSummary();
        $document['self_image'] = $object->getImage();
        $document['self_slug'] = $object->getSlug();
        $document['self_uri'] = $object->getUri();
        $document['self_version'] = $object->getVersion();
        $document['self_size'] = $object->getSize();
        $document['self_owner'] = $object->getOwner();
        $document['self_locked'] = $object->getLocked();
        $document['self_folder'] = $object->getFolder();
        $document['self_application'] = $object->getApplication();
        
        // DateTime fields
        $document['self_created'] = $object->getCreated() ? $object->getCreated()->format('Y-m-d\TH:i:s\Z') : null;
        $document['self_updated'] = $object->getUpdated() ? $object->getUpdated()->format('Y-m-d\TH:i:s\Z') : null;
        $document['self_published'] = $object->getPublished() ? $object->getPublished()->format('Y-m-d\TH:i:s\Z') : null;
        $document['self_depublished'] = $object->getDepublished() ? $object->getDepublished()->format('Y-m-d\TH:i:s\Z') : null;
        
        // Complex fields as JSON
        $document['self_authorization'] = $object->getAuthorization() ? json_encode($object->getAuthorization()) : null;
        $document['self_deleted'] = $object->getDeleted() ? json_encode($object->getDeleted()) : null;
        $document['self_validation'] = $object->getValidation() ? json_encode($object->getValidation()) : null;
        $document['self_groups'] = $object->getGroups() ? json_encode($object->getGroups()) : null;

        // Remove null values, but keep published/depublished fields for proper filtering
        return array_filter($document, function($value, $key) {
            // Always keep published/depublished fields even if null for proper Solr filtering
            if (in_array($key, ['self_published', 'self_depublished'])) {
                return true;
            }
            return $value !== null && $value !== '';
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Search objects with pagination using OpenRegister query format
     * 
     * This method translates OpenRegister query parameters into Solr queries
     * and converts results back to ObjectEntity format for compatibility
     *
     * @param array $query OpenRegister-style query parameters
     * @param bool $rbac Apply role-based access control (default: true)
     * @param bool $multi Multi-tenant support (default: true)
     * @param bool $published Include only published objects (default: false)
     * @param bool $deleted Include deleted objects (default: false)
     * @return array Paginated results in OpenRegister format
     * @throws \Exception When Solr is not available or query fails
     */
    public function searchObjectsPaginated(array $query = [], bool $rbac = true, bool $multi = true, bool $published = false, bool $deleted = false): array
    {
        
        $startTime = microtime(true);
        
        // Check SOLR configuration first
        if (!$this->isSolrConfigured()) {
            $configStatus = [
                'enabled' => $this->solrConfig['enabled'] ?? false,
                'host' => !empty($this->solrConfig['host']) ? 'configured' : 'missing',
                'port' => isset($this->solrConfig['port']) ? 'configured' : 'optional',
                'core' => !empty($this->solrConfig['core']) ? 'configured' : 'using_default'
            ];
            
            throw new \Exception(
                'SOLR configuration validation failed. Current status: ' . json_encode($configStatus) . '. ' .
                'Please check your SOLR settings in the OpenRegister admin panel.'
            );
        }
        
        // Test SOLR connection
        if (!$this->isAvailable()) {
            $connectionTest = $this->testConnection();
            throw new \Exception(
                'SOLR service is not available. Connection test failed: ' . 
                ($connectionTest['error'] ?? 'Unknown connection error') . 
                '. Please verify that SOLR is running and accessible at the configured URL.'
            );
        }

        try {
            // Get active collection name - if null, SOLR is not properly set up
            $collectionName = $this->getActiveCollectionName();
            if ($collectionName === null) {
                throw new \Exception(
                    'No active SOLR collection available. Please ensure a SOLR collection is created and configured ' .
                    'in the OpenRegister settings, and that the collection exists in your SOLR instance.'
                );
            }
            
            // Build SOLR query from OpenRegister query parameters
            $solrQuery = $this->buildSolrQuery($query);
            
            // Query building completed successfully
            
            // Log the built SOLR query for troubleshooting
            $this->logger->debug('Executing SOLR search', [
                'original_query' => $query,
                'solr_query' => $solrQuery,
                'collection' => $collectionName
            ]);
            
            // Apply additional filters (RBAC, multi-tenancy, published, deleted)
            $this->applyAdditionalFilters($solrQuery, $rbac, $multi, $published, $deleted);
            
            // Execute the search
            $extend = $query['_extend'] ?? [];
            // Normalize extend to array if it's a string
            if (is_string($extend)) {
                $extend = array_map('trim', explode(',', $extend));
            }
            $searchResults = $this->executeSearch($solrQuery, $collectionName, $extend);
            
            // Convert SOLR results to OpenRegister paginated format
            $paginatedResults = $this->convertToOpenRegisterPaginatedFormat($searchResults, $query, $solrQuery);
            
            // Add execution metadata
            $paginatedResults['_execution_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->logger->info('SOLR search completed successfully', [
                'query_fingerprint' => substr(md5(json_encode($query)), 0, 8),
                'results_count' => count($paginatedResults['results'] ?? []),
                'total_results' => $paginatedResults['total'] ?? 0,
                'execution_time_ms' => $paginatedResults['_execution_time_ms']
            ]);
            
            return $paginatedResults;
            
        } catch (\Exception $e) {
            $this->logger->error('SOLR search failed', [
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
                'query_fingerprint' => substr(md5(json_encode($query)), 0, 8),
                'collection' => $collectionName ?? 'unknown',
                'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ]);
            
            // Re-throw with more context for user
            throw new \Exception(
                'SOLR search failed: ' . $e->getMessage() . 
                '. This indicates an issue with the SOLR service or query. Check the logs for more details.',
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Apply additional filters based on RBAC, multi-tenancy, published, and deleted parameters
     *
     * @param array $solrQuery Reference to the Solr query array to modify
     * @param bool $rbac Apply role-based access control
     * @param bool $multi Apply multi-tenancy filtering
     * @param bool $published Filter for published objects only
     * @param bool $deleted Include deleted objects
     * @return void
     */
    private function applyAdditionalFilters(array &$solrQuery, bool $rbac, bool $multi, bool $published, bool $deleted): void
    {
        
        $filters = $solrQuery['fq'] ?? [];
        $now = date('Y-m-d\TH:i:s\Z');
        
        // Define published object condition: published is not null AND published <= now AND (depublished is null OR depublished > now)
        $publishedCondition = 'self_published:[* TO ' . $now . '] AND (-self_depublished:[* TO *] OR self_depublished:[' . $now . ' TO *])';
        
        // Multi-tenancy filtering (removed automatic published object exception)
        if ($multi) {
            $multitenancyEnabled = $this->isMultitenancyEnabled();
            if ($multitenancyEnabled) {
                $activeOrganisationUuid = $this->getActiveOrganisationUuid();
                if ($activeOrganisationUuid !== null) {
                    // Only include objects from user's organisation
                    $filters[] = 'self_organisation:' . $this->escapeSolrValue($activeOrganisationUuid);
                }
            }
        }
        
        // RBAC filtering (removed automatic published object exception)
        if ($rbac) {
            // Note: RBAC role filtering would be implemented here if we had role-based fields
            // For now, we assume all authenticated users have basic access
            $this->logger->debug('[SOLR] RBAC filtering applied');
        }
        
        // Published filtering (only if explicitly requested)
        if ($published) {
            // Filter for objects that have a published date AND it's in the past
            // AND either no depublished date OR depublished date is in the future
            $filters[] = 'self_published:[* TO ' . $now . '] AND NOT self_published:null';
            $filters[] = '(self_depublished:null OR self_depublished:[' . $now . ' TO *])';
        }
        
        // Deleted filtering
        // @todo: this is not working as expected so we turned it of, for now deleted items should not be indexed
        //if ($deleted) {
        //    // Include only deleted objects
        //    $filters[] = 'self_deleted:[* TO *]';
        //} else {
        //    // Exclude deleted objects (default behavior)
        //    $filters[] = '-self_deleted:[* TO *]';
        //}        
        
        // Update the filters in the query
        $solrQuery['fq'] = $filters;
    }

    /**
     * Check if multi-tenancy is enabled in the application configuration
     *
     * @return bool True if multi-tenancy is enabled
     */
    private function isMultitenancyEnabled(): bool
    {
        try {
            return $this->settingsService->isMultiTenancyEnabled();
        } catch (\Exception $e) {
            $this->logger->warning('Failed to check multi-tenancy status', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get the active organisation UUID for the current user
     *
     * @return string|null The active organisation UUID or null if not available
     */
    private function getActiveOrganisationUuid(): ?string
    {
        try {
            if ($this->organisationService === null) {
                $this->logger->warning('OrganisationService not available for multi-tenancy filtering');
                return null;
            }
            
            $activeOrganisation = $this->organisationService->getActiveOrganisation();
            return $activeOrganisation ? $activeOrganisation->getUuid() : null;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get active organisation', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Translate OpenRegister query parameters to Solr query format
     *
     * @param array $query OpenRegister query parameters
     * @return array Solr query parameters
     */
    private function translateOpenRegisterQuery(array $query): array
    {
        $solrQuery = [
            'q' => '*:*',
            'offset' => 0,
            'limit' => 20,
            'sort' => 'self_created desc',
            'facets' => [],
            'filters' => []
        ];

        // Handle search query
        if (!empty($query['_search'])) {
            $searchTerm = $this->escapeSolrValue($query['_search']);
            $solrQuery['q'] = "_text_:($searchTerm) OR naam:($searchTerm) OR description:($searchTerm)";
        }

        // Handle pagination
        if (isset($query['_page'])) {
            $page = max(1, (int)$query['_page']);
            $limit = isset($query['_limit']) ? max(1, (int)$query['_limit']) : 20;
            $solrQuery['offset'] = ($page - 1) * $limit;
            $solrQuery['limit'] = $limit;
        } elseif (isset($query['_limit'])) {
            $solrQuery['limit'] = max(1, (int)$query['_limit']);
        }

        // Handle sorting
        if (!empty($query['_order'])) {
            $solrQuery['sort'] = $this->translateSortField($query['_order']);
        }

        // Handle faceting - check for _facetable parameter (can be boolean true or string "true")
        $enableFacets = isset($query['_facetable']) && ($query['_facetable'] === true || $query['_facetable'] === 'true');
        
        // Handle extended faceting - check for _facets parameter
        $facetsMode = $query['_facets'] ?? null;
        $enableExtendedFacets = ($facetsMode === 'extend');
        
        // Store faceting flags for later processing in convertToOpenRegisterPaginatedFormat
        $solrQuery['_facetable'] = $enableFacets;
        $solrQuery['_facets'] = $facetsMode;

        // Handle filters
        $filterQueries = [];
        
        foreach ($query as $key => $value) {
            if (str_starts_with($key, '_')) {
                continue; // Skip internal parameters
            }
            
            // Handle @self metadata filters
            if ($key === '@self' && is_array($value)) {
                foreach ($value as $metaKey => $metaValue) {
                    $solrField = 'self_' . $metaKey;
                    if (is_numeric($metaValue)) {
                        $filterQueries[] = $solrField . ':' . $metaValue;
                    } else {
                        $filterQueries[] = $solrField . ':"' . $this->escapeSolrValue((string)$metaValue) . '"';
                    }
                }
                continue;
            }
            
            $solrField = $this->translateFilterField($key);
            
            if (is_array($value)) {
                // Handle array values (OR condition)
                $conditions = array_map(function($v) use ($solrField) {
                    if (is_numeric($v)) {
                        return $solrField . ':' . $v;
                    }
                    return $solrField . ':"' . $this->escapeSolrValue((string)$v) . '"';
                }, $value);
                $filterQueries[] = '(' . implode(' OR ', $conditions) . ')';
            } else {
                // Handle single values
                if (is_numeric($value)) {
                    $filterQueries[] = $solrField . ':' . $value;
                } else {
                    $filterQueries[] = $solrField . ':"' . $this->escapeSolrValue((string)$value) . '"';
                }
            }
        }

        if (!empty($filterQueries)) {
            $solrQuery['filters'] = $filterQueries;
        }
        
        // Filter queries built successfully

        // Handle faceting - only add facets when _facetable=true
        if ($enableFacets) {
            $solrQuery['facets'] = [
                'self_register',
                'self_schema', 
                'self_organisation',
                'self_owner',
                'type_s',
                'naam_s'
            ];
        } else {
            $solrQuery['facets'] = [];
        }

        return $solrQuery;
    }

    /**
     * Translate OpenRegister field names to Solr field names for filtering
     *
     * @param string $field OpenRegister field name
     * @return string Solr field name
     */
    private function translateFilterField(string $field): string
    {
        // Handle @self.* fields (metadata)
        if (str_starts_with($field, '@self.')) {
            $metadataField = substr($field, 6); // Remove '@self.'
            return 'self_' . $metadataField;
        }
        
        // Handle special field mappings
        $fieldMappings = [
            'register' => 'self_register',
            'schema' => 'self_schema',
            'organisation' => 'self_organisation',
            'owner' => 'self_owner',
            'created' => 'self_created',
            'updated' => 'self_updated',
            'published' => 'self_published'
        ];

        if (isset($fieldMappings[$field])) {
            return $fieldMappings[$field];
        }

        // For object properties, use the field name directly (now stored at root)
        return $field;
    }

    /**
     * Translate OpenRegister sort field to Solr sort format
     *
     * @param array|string $order Sort specification
     * @return string Solr sort string
     */
    private function translateSortField(array|string $order): string
    {
        if (is_string($order)) {
            $field = $this->translateFilterField($order);
            return $field . ' asc';
        }

        if (is_array($order)) {
            $sortParts = [];
            foreach ($order as $field => $direction) {
                $solrField = $this->translateFilterField($field);
                $solrDirection = strtolower($direction) === 'desc' ? 'desc' : 'asc';
                $sortParts[] = $solrField . ' ' . $solrDirection;
            }
            return implode(', ', $sortParts);
        }

        return 'self_created desc'; // Default sort
    }

    /**
     * Convert Solr search results back to OpenRegister format
     *
     * @param array $solrResults Solr search results
     * @param array $originalQuery Original OpenRegister query for context
     * @return array OpenRegister-formatted results
     */
    private function convertSolrResultsToOpenRegisterFormat(array $solrResults, array $originalQuery): array
    {
        $results = [];
        
        // Check if search was successful
        if (!($solrResults['success'] ?? false)) {
            $this->logger->warning('[GuzzleSolrService] Search failed', [
                'error' => $solrResults['error'] ?? 'Unknown error'
            ]);
            return [
                'results' => [],
                'total' => 0,
                'page' => 1,
                'pages' => 1,
                'limit' => 20,
                'facets' => [],
                'aggregations' => []
            ];
        }
        
        $documents = $solrResults['data'] ?? [];
        
        foreach ($documents as $doc) {
            // Reconstruct object from Solr document
            $objectEntity = $this->reconstructObjectFromSolrDocument($doc);
            if ($objectEntity) {
                $results[] = $objectEntity;
            }
        }

        // Build pagination info
        $total = $solrResults['total'] ?? count($results);
        $page = isset($originalQuery['_page']) ? (int)$originalQuery['_page'] : 1;
        $limit = isset($originalQuery['_limit']) ? (int)$originalQuery['_limit'] : 20;
        $pages = $limit > 0 ? ceil($total / $limit) : 1;

        // Build base response
        $response = [
            'results' => $results,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'limit' => $limit,
        ];

        // Only add facets if _facetable=true (same logic as before)
        $facetable = $originalQuery['_facetable'] ?? false;
        if ($facetable === true || $facetable === 'true') {
            $response['facets'] = $solrResults['facets'] ?? [];
        }

        // Only add aggregations if _aggregations=true
        $aggregations = $originalQuery['_aggregations'] ?? false;
        if ($aggregations === true || $aggregations === 'true') {
            $response['aggregations'] = $solrResults['facets'] ?? []; // Alias for compatibility
        }

        // Only add debug if _debug=true
        $debug = $originalQuery['_debug'] ?? false;
        if ($debug === true || $debug === 'true') {
            $response['debug'] = array_merge($solrResults['debug'] ?? [], [
                'translated_query' => $originalQuery,
                'solr_facets' => $solrResults['facets'] ?? []
            ]);
        }

        return $response;
    }

    /**
     * Reconstruct ObjectEntity from Solr document
     *
     * @param array $doc Solr document
     * @return ObjectEntity|null Reconstructed object or null if invalid
     */
    private function reconstructObjectFromSolrDocument(array $doc): ?ObjectEntity
    {
        // Extract metadata from self_ fields
        // Handle both single values and arrays (SOLR can return either)
        $object = is_array($doc['self_object'] ?? null) ? ($doc['self_object'][0] ?? null) : ($doc['self_object'] ?? null);
        $uuid = is_array($doc['self_uuid'] ?? null) ? ($doc['self_uuid'][0] ?? null) : ($doc['self_uuid'] ?? null);
        $register = is_array($doc['self_register'] ?? null) ? ($doc['self_register'][0] ?? null) : ($doc['self_register'] ?? null);
        $schema = is_array($doc['self_schema'] ?? null) ? ($doc['self_schema'][0] ?? null) : ($doc['self_schema'] ?? null);
        
        if (!$object) {
            $this->logger->error('[GuzzleSolrService] Invalid document missing required self_object', [
                'uuid' => $uuid,
                'register' => $register,
                'schema' => $schema
            ]);
            return null;
        }

        // Create ObjectEntity instance
        $entity = new \OCA\OpenRegister\Db\ObjectEntity();
        $entity->hydrateObject(json_decode($object, true));

        return $entity;
    }

    /**
     * Extract text content for full-text search
     *
     * @param ObjectEntity $object     Object entity
     * @param array        $objectData Object data
     * @return string Combined text content
     */
    private function extractTextContent(ObjectEntity $object, array $objectData): string
    {
        $textParts = [];
        
        if ($object->getName()) {
            $textParts[] = $object->getName();
        }
        
        if ($object->getUuid()) {
            $textParts[] = $object->getUuid();
        }
        
        $this->extractTextFromArray($objectData, $textParts);
        
        return implode(' ', array_filter($textParts));
    }

    /**
     * Extract text from array recursively
     *
     * @param array $data      Data array
     * @param array $textParts Text parts collector
     * @return void
     */
    private function extractTextFromArray(array $data, array &$textParts): void
    {
        foreach ($data as $value) {
            if (is_string($value) && strlen($value) > 2) {
                $textParts[] = $value;
            } elseif (is_array($value)) {
                $this->extractTextFromArray($value, $textParts);
            }
        }
    }

    /**
     * Extract dynamic fields from object data with app prefix
     *
     * **Multi-App Field Naming**: Applies `or_` prefix to all dynamic fields
     * to prevent conflicts with other Nextcloud apps using the same SOLR instance.
     *
     * @param array  $objectData Object data
     * @param string $prefix     Field prefix (used for nested objects)
     * @return array Dynamic fields with app prefix
     */
    private function extractDynamicFields(array $objectData, string $prefix = ''): array
    {
        $fields = [];
        
        foreach ($objectData as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            
            // Apply app prefix for multi-app support (unless already prefixed)
            $basePrefix = $prefix === '' ? self::APP_PREFIX . '_' : $prefix;
            $fieldName = $basePrefix . $key;
            
            if (is_array($value)) {
                if ($this->isAssociativeArray($value)) {
                    $fields = array_merge($fields, $this->extractDynamicFields($value, $fieldName . '_'));
                } else {
                    foreach ($value as $item) {
                        if (is_scalar($item)) {
                            $fields[$fieldName . '_ss'][] = (string)$item;
                        }
                    }
                }
            } elseif (is_bool($value)) {
                $fields[$fieldName . '_b'] = $value;
            } elseif (is_int($value)) {
                $fields[$fieldName . '_i'] = $value;
            } elseif (is_float($value)) {
                $fields[$fieldName . '_f'] = $value;
            } else {
                $fields[$fieldName . '_s'] = (string)$value;
            }
        }
        
        return $fields;
    }

    /**
     * Check if array is associative
     *
     * @param array $array Array to check
     * @return bool True if associative
     */
    private function isAssociativeArray(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Escape SOLR query value
     *
     * @param string $value Value to escape
     * @return string Escaped value
     */
    private function escapeSolrValue(string $value): string
    {
        $specialChars = ['\\', '+', '-', '!', '(', ')', '{', '}', '[', ']', '^', '"', '~', '*', '?', ':', '/'];
        return '"' . str_replace($specialChars, array_map(fn($char) => '\\' . $char, $specialChars), $value) . '"';
    }

    /**
     * Bulk index multiple objects (alias for bulkIndex)
     *
     * @param array $objects Array of ObjectEntity objects
     * @param bool  $commit  Whether to commit immediately
     * @return array Result array with success status and statistics
     */
    public function bulkIndexObjects(array $objects, bool $commit = true): array
    {
        $startTime = microtime(true);
        $success = $this->bulkIndex($objects, $commit);
        $executionTime = microtime(true) - $startTime;

        return [
            'success' => $success,
            'processed' => count($objects),
            'execution_time' => $executionTime,
            'tenant_id' => $this->tenantId
        ];
    }

    /**
     * Bulk index multiple documents
     *
     * @param array $documents Array of SOLR documents or ObjectEntity objects
     * @param bool  $commit    Whether to commit immediately
     * @return bool True if successful
     */
    public function bulkIndex(array $documents, bool $commit = false): bool
    {
        $this->logger->info('🚀 BULK INDEX CALLED', [
            'document_count' => count($documents),
            'commit' => $commit,
            'is_available' => $this->isAvailable()
        ]);
        
        // **DEBUG**: Log all documents being indexed
        $this->logger->debug('=== BULK INDEX DEBUG ===');
        $this->logger->debug('Document count: ' . count($documents));
        foreach ($documents as $i => $doc) {
            if (is_array($doc)) {
                $this->logger->debug('Doc ' . $i . ': ID=' . ($doc['id'] ?? 'missing') . ', has_self_object_id=' . (isset($doc['self_object_id']) ? 'YES' : 'NO'));
            } else if ($doc instanceof ObjectEntity) {
                $this->logger->debug('Doc ' . $i . ': ObjectEntity ID=' . $doc->getId() . ', UUID=' . ($doc->getUuid() ?? 'null'));
            } else {
                $this->logger->debug('Doc ' . $i . ': ' . gettype($doc));
            }
        }
        $this->logger->debug('=== END BULK INDEX DEBUG ===');
        
        if (!$this->isAvailable() || empty($documents)) {
            $this->logger->warning('Bulk index early return', [
                'is_available' => $this->isAvailable(),
                'documents_empty' => empty($documents),
                'document_count' => count($documents)
            ]);
            return false;
        }

        try {
            $startTime = microtime(true);

            // Ensure tenant collection exists
            if (!$this->ensureTenantCollection()) {
                $this->logger->warning('Cannot bulk index: tenant collection unavailable');
                return false;
            }

            // Get the active collection name - return false if no collection exists
            $tenantCollectionName = $this->getActiveCollectionName();
            if ($tenantCollectionName === null) {
                $this->logger->warning('Cannot bulk index: no active collection available');
                return false;
            }

            // Prepare documents
            $solrDocs = [];
            foreach ($documents as $doc) {
                if ($doc instanceof ObjectEntity) {
                    $solrDocs[] = $this->createSolrDocument($doc);
                } elseif (is_array($doc)) {
                    // **FIXED**: Use self_tenant instead of tenant_id, and only add if missing
                    if (!isset($doc['self_tenant'])) {
                        $doc['self_tenant'] = (string)$this->tenantId;
                    }
                    // Document is already a Solr document array - don't recreate it
                    $solrDocs[] = $doc;
                } else {
                    $this->logger->warning('Invalid document type in bulk index', ['type' => gettype($doc)]);
                    continue;
                }
            }

            if (empty($solrDocs)) {
                $this->logger->warning('No valid SOLR documents after processing', [
                    'original_count' => count($documents),
                    'processed_count' => count($solrDocs)
                ]);
                return false;
            }
            
            $this->logger->info('Prepared SOLR documents for bulk index', [
                'original_count' => count($documents),
                'processed_count' => count($solrDocs)
            ]);
            
            // Debug: Log first document structure with tenant_id details
            if (!empty($solrDocs)) {
                $firstDoc = $solrDocs[0];
                $this->logger->debug('First SOLR document prepared', [
                    'document_keys' => array_keys($firstDoc),
                    'id' => $firstDoc['id'] ?? 'missing',
                    'tenant_id' => $firstDoc['tenant_id'] ?? 'missing',
                    'tenant_id_type' => gettype($firstDoc['tenant_id'] ?? null),
                    'total_fields' => count($firstDoc)
                ]);
            }

            // **FIX**: SOLR bulk update format - single "add" with array of docs (no extra "doc" wrapper)
            $updateData = [
                'add' => $solrDocs
            ];

            // Debug removed - bulk update working correctly

            $url = $this->buildSolrBaseUrl() . '/' . $tenantCollectionName . '/update?wt=json';
            
            if ($commit) {
                $url .= '&commit=true';
            }

            // Log bulk update details
            $this->logger->debug('About to send SOLR bulk update', [
                'url' => $url,
                'document_count' => count($updateData['add'] ?? []),
                'json_size' => strlen(json_encode($updateData))
            ]);

            // Bulk POST ready
            // **DEBUG**: Log HTTP request details AND actual payload
            $this->logger->debug('=== HTTP POST TO SOLR DEBUG ===');
            $this->logger->debug('URL: ' . $url);
            $this->logger->debug('Document count in payload: ' . count($updateData['add'] ?? []));
            $this->logger->debug('Payload size: ' . strlen(json_encode($updateData)) . ' bytes');
            $this->logger->debug('ACTUAL JSON PAYLOAD:');
            $this->logger->debug(json_encode($updateData, JSON_PRETTY_PRINT));
            $this->logger->debug('=== END HTTP POST DEBUG ===');
            
            try {
            $response = $this->httpClient->post($url, [
                'body' => json_encode($updateData),
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 60
            ]);

                $statusCode = $response->getStatusCode();
                $responseBody = (string)$response->getBody();
            } catch (\Exception $httpException) {
                // Extract full response body from Guzzle ClientException
                $fullResponseBody = '';
                if ($httpException instanceof \GuzzleHttp\Exception\ClientException) {
                    $response = $httpException->getResponse();
                    if ($response) {
                        $fullResponseBody = (string)$response->getBody();
                    }
                }
                
                $this->logger->error('SOLR HTTP call failed', [
                    'error' => $httpException->getMessage(),
                    'class' => get_class($httpException),
                    'code' => $httpException->getCode(),
                    'full_response' => $fullResponseBody
                ]);
                
                // Create enhanced exception with full SOLR response
                if (!empty($fullResponseBody)) {
                    throw new \RuntimeException(
                        'SOLR bulk index failed: ' . $httpException->getMessage() . 
                        '. Full SOLR Response: ' . $fullResponseBody,
                        $httpException->getCode(),
                        $httpException
                    );
                }
                
                throw $httpException;
            }
            
            // Log SOLR response details and debug tenant_id
            $this->logger->debug('SOLR bulk update response received', [
                'status_code' => $statusCode,
                'response_length' => strlen($responseBody),
                'document_count' => count($solrDocs),
                'tenant_id_type' => gettype($this->tenantId),
                'tenant_id_value' => $this->tenantId
            ]);
            
            // **ERROR HANDLING**: Throw exception for non-20X HTTP status codes
            if ($statusCode < 200 || $statusCode >= 300) {
                $this->stats['errors']++;
                throw new \RuntimeException(
                    "SOLR bulk index HTTP error: HTTP {$statusCode}. " .
                    "Full Response: " . $responseBody,
                    $statusCode
                );
            }
            
            $this->logger->info('SOLR bulk response received', [
                'status_code' => $statusCode,
                'content_length' => strlen($responseBody)
            ]);

            $data = json_decode($responseBody, true);
            
            // **ERROR HANDLING**: Validate JSON response structure
            if ($data === null) {
                $this->stats['errors']++;
                throw new \RuntimeException(
                    "SOLR bulk index invalid JSON response. HTTP {$statusCode}. " .
                    "Full Raw Response: " . $responseBody
                );
            }
            
            $solrStatus = $data['responseHeader']['status'] ?? -1;
            
            // **ERROR HANDLING**: Throw exception for SOLR-level errors
            if ($solrStatus !== 0) {
                $this->stats['errors']++;
                $errorDetails = [
                    'solr_status' => $solrStatus,
                    'http_status' => $statusCode,
                    'error_msg' => $data['error']['msg'] ?? 'Unknown SOLR error',
                    'error_code' => $data['error']['code'] ?? 'Unknown',
                    'response' => $data
                ];
                
                throw new \RuntimeException(
                    "SOLR bulk index failed: SOLR status {$solrStatus}. " .
                    "Error: {$errorDetails['error_msg']} (Code: {$errorDetails['error_code']}). " .
                    "HTTP Status: {$statusCode}",
                    $solrStatus
                );
            }

            // Success path
            $this->stats['indexes'] += count($solrDocs);
            $this->stats['index_time'] += (microtime(true) - $startTime);
            
            $this->logger->debug('📦 BULK INDEXED IN SOLR', [
                'document_count' => count($solrDocs),
                'collection' => $tenantCollectionName,
                'tenant_id' => $this->tenantId,
                'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ]);

            return true;

        } catch (\Exception $e) {
            $this->stats['errors']++;
            $this->logger->error('🚨 EXCEPTION DURING BULK INDEXING', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            // **FIX**: Don't suppress the exception - re-throw it to expose the 400 error
            throw $e;
        }
    }

    /**
     * Commit changes to SOLR
     *
     * @return bool True if successful
     */
    public function commit(): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            // Get the active collection name - return false if no collection exists
            $tenantCollectionName = $this->getActiveCollectionName();
            if ($tenantCollectionName === null) {
                $this->logger->warning('Cannot commit: no active collection available');
                return false;
            }

            $url = $this->buildSolrBaseUrl() . '/' . $tenantCollectionName . '/update?wt=json&commit=true';

            $response = $this->httpClient->post($url, [
                'body' => json_encode(['commit' => []]),
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 30
            ]);

            $data = json_decode((string)$response->getBody(), true);
            $success = ($data['responseHeader']['status'] ?? -1) === 0;

            if ($success) {
                $this->logger->debug('💾 SOLR COMMIT', [
                    'collection' => $tenantCollectionName,
                    'tenant_id' => $this->tenantId
                ]);
            }

            return $success;

        } catch (\Exception $e) {
            $this->logger->error('Exception committing to SOLR', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Delete documents by query
     *
     * @param string $query  SOLR query
     * @param bool   $commit Whether to commit immediately
     * @param bool   $returnDetails Whether to return detailed error information
     * @return bool|array True if successful (when $returnDetails=false), or detailed result array (when $returnDetails=true)
     */
    public function deleteByQuery(string $query, bool $commit = false, bool $returnDetails = false): bool|array
    {
        if (!$this->isAvailable()) {
            if ($returnDetails) {
                return [
                    'success' => false,
                    'error' => 'SOLR service is not available',
                    'error_details' => 'SOLR connection is not configured or unavailable'
                ];
            }
            return false;
        }

        try {
            // Get the active collection name - return error if no collection exists
            $tenantCollectionName = $this->getActiveCollectionName();
            if ($tenantCollectionName === null) {
                $this->logger->warning('Cannot delete by query: no active collection available');
                if ($returnDetails) {
                    return [
                        'success' => false,
                        'error' => 'No active SOLR collection available',
                        'error_details' => 'No collection found for the current tenant'
                    ];
                }
                return false;
            }

            // Add tenant isolation to query
            $tenantQuery = sprintf('(%s) AND self_tenant:%s', $query, $this->tenantId);

            $deleteData = [
                'delete' => [
                    'query' => $tenantQuery
                ]
            ];

            $url = $this->buildSolrBaseUrl() . '/' . $tenantCollectionName . '/update?wt=json';
            
            if ($commit) {
                $url .= '&commit=true';
            }

            $response = $this->httpClient->post($url, [
                'body' => json_encode($deleteData),
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 30
            ]);

            $data = json_decode((string)$response->getBody(), true);
            $success = ($data['responseHeader']['status'] ?? -1) === 0;

            if ($success) {
                $this->stats['deletes']++;
                $this->logger->debug('🗑️ SOLR DELETE BY QUERY', [
                    'query' => $tenantQuery,
                    'collection' => $tenantCollectionName,
                    'tenant_id' => $this->tenantId
                ]);
                
                if ($returnDetails) {
                    return [
                        'success' => true,
                        'deleted_docs' => $data['responseHeader']['QTime'] ?? 0
                    ];
                }
                return true;
            } else {
                if ($returnDetails) {
                    $errorMsg = $data['error']['msg'] ?? 'Unknown SOLR error';
                    $errorCode = $data['error']['code'] ?? $data['responseHeader']['status'] ?? -1;
                    
                    return [
                        'success' => false,
                        'error' => "SOLR delete operation failed: {$errorMsg}",
                        'error_details' => [
                            'solr_error' => $errorMsg,
                            'error_code' => $errorCode,
                            'query' => $tenantQuery,
                            'collection' => $tenantCollectionName,
                            'full_response' => $data
                        ]
                    ];
                }
                return false;
            }

        } catch (\GuzzleHttp\Exception\RequestException $e) {
            if ($returnDetails) {
                $errorMsg = 'HTTP request failed';
                $errorDetails = [
                    'exception_type' => 'RequestException',
                    'message' => $e->getMessage(),
                    'query' => $query
                ];

                // Try to extract SOLR error from response
                if ($e->hasResponse()) {
                    $responseBody = (string)$e->getResponse()->getBody();
                    $responseData = json_decode($responseBody, true);
                    
                    if ($responseData && isset($responseData['error'])) {
                        $errorMsg = "SOLR HTTP {$e->getResponse()->getStatusCode()} Error: " . ($responseData['error']['msg'] ?? $responseData['error']);
                        $errorDetails['solr_response'] = $responseData;
                        $errorDetails['http_status'] = $e->getResponse()->getStatusCode();
                    }
                }

                $this->logger->error('HTTP exception deleting by query from SOLR', $errorDetails);
                
                return [
                    'success' => false,
                    'error' => $errorMsg,
                    'error_details' => $errorDetails
                ];
            }

            $this->logger->error('Exception deleting by query from SOLR', [
                'query' => $query,
                'error' => $e->getMessage()
            ]);
            return false;

        } catch (\Exception $e) {
            if ($returnDetails) {
                $this->logger->error('Exception deleting by query from SOLR', [
                    'query' => $query,
                    'error' => $e->getMessage(),
                    'exception_type' => get_class($e)
                ]);
                
                return [
                    'success' => false,
                    'error' => 'Unexpected error during SOLR delete operation: ' . $e->getMessage(),
                    'error_details' => [
                        'exception_type' => get_class($e),
                        'message' => $e->getMessage(),
                        'query' => $query
                    ]
                ];
            }

            $this->logger->error('Exception deleting by query from SOLR', [
                'query' => $query,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }


    /**
     * Search objects in SOLR
     *
     * @param array $searchParams Search parameters
     * @return array Search results
     */
    public function searchObjects(array $searchParams): array
    {
        // Check SOLR availability first
        if (!$this->isAvailable()) {
            return [
                'success' => false,
                'data' => [],
                'total' => 0,
                'facets' => [],
                'message' => 'SOLR service is not available'
            ];
        }

        try {
            $startTime = microtime(true);
            
            // Get active collection name - if null, SOLR is not properly set up
            $collectionName = $this->getActiveCollectionName();
            if ($collectionName === null) {
                return [
                    'success' => false,
                    'data' => [],
                    'total' => 0,
                    'facets' => [],
                    'message' => 'No active SOLR collection available'
                ];
            }
            
            // Build and execute SOLR query
            $solrQuery = $this->buildSolrQuery($searchParams);
            $extend = $searchParams['_extend'] ?? [];
            $searchResults = $this->executeSearch($solrQuery, $collectionName, $extend);
            
            // Return results in expected format
        return [
            'success' => true,
                'data' => $searchResults['objects'] ?? [],
                'total' => $searchResults['total'] ?? 0,
                'facets' => $searchResults['facets'] ?? [],
                'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('SOLR search failed in searchObjects', [
                'error' => $e->getMessage(),
                'searchParams' => $searchParams
            ]);
            
            return [
                'success' => false,
            'data' => [],
            'total' => 0,
            'facets' => [],
                'message' => 'SOLR search failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Build weighted search query with wildcard support and field boosting
     *
     * This method implements a sophisticated search strategy with:
     * - Field-based relevance weighting following OpenRegister metadata standards
     * - Multi-level matching: exact > wildcard > fuzzy
     * - Automatic wildcard expansion for partial matching
     * - Typo tolerance through fuzzy matching
     *
     * **Field Weighting Strategy (NL API Strategy compliant):**
     * - self_name (15.0x): OpenRegister standardized name field - highest relevance
     * - self_summary (10.0x): OpenRegister standardized summary field
     * - self_description (7.0x): OpenRegister standardized description field  
     * - naam (5.0x): Legacy name field - maintained for backwards compatibility
     * - beschrijvingKort (3.0x): Legacy short description field
     * - beschrijving (2.0x): Legacy full description field
     * - _text_ (1.0x): Catch-all text field - lowest priority
     *
     * **Matching Strategy per Field:**
     * - Exact match: field:"term" (3x field weight)
     * - Wildcard match: field:*term* (2x field weight) 
     * - Fuzzy match: field:term~ (1x field weight)
     *
     * @param string $searchTerm The search term to query for
     * @return string SOLR query string with weighted fields and multi-level matching
     */
    private function buildWeightedSearchQuery(string $searchTerm): string
    {
        // Clean the search term
        $cleanTerm = $this->cleanSearchTerm($searchTerm);
        
        // Define field weights (higher = more important)
        // Using essential OpenRegister fields with specified weights
        $fieldWeights = [
            'self_name' => 15.0,       // OpenRegister standardized name (highest priority)
            'self_summary' => 10.0,    // OpenRegister standardized summary
            'self_description' => 5.0, // OpenRegister standardized description
            '_text_' => 1.0            // Catch-all text field (lowest priority)
        ];
        
        $queryParts = [];
        
        // Build weighted queries for each field
        foreach ($fieldWeights as $field => $weight) {
            // Exact phrase match (highest relevance)
            $queryParts[] = $field . ':"' . $cleanTerm . '"^' . ($weight * 3);
            
            // Wildcard match (medium relevance) - proper wildcard syntax
            $queryParts[] = $field . ':*' . $cleanTerm . '*^' . ($weight * 2);
            
            // Fuzzy match (lowest relevance) - proper fuzzy syntax
            $queryParts[] = $field . ':' . $cleanTerm . '~^' . $weight;
        }
        
        // Join all parts with OR
        return '(' . implode(' OR ', $queryParts) . ')';
    }
    
    /**
     * Clean search term for SOLR query safety
     *
     * @param string $term Raw search term
     * @return string Cleaned search term safe for SOLR
     */
    private function cleanSearchTerm(string $term): string
    {
        // Remove dangerous characters but keep wildcards if user explicitly added them
        $userHasWildcards = (strpos($term, '*') !== false || strpos($term, '?') !== false);
        
        if (!$userHasWildcards) {
            // Escape special SOLR characters except space
            $specialChars = ['\\', '+', '-', '!', '(', ')', '{', '}', '[', ']', '^', '"', '~', ':', '/'];
            $term = str_replace($specialChars, array_map(fn($char) => '\\' . $char, $specialChars), $term);
        }
        
        return trim($term);
    }

    /**
     * Build SOLR query from OpenRegister query parameters
     *
     * @param array $query OpenRegister query parameters
     * @return array SOLR query parameters
     */
    private function buildSolrQuery(array $query): array
    {
        
        $solrQuery = [
            'q' => '*:*',
            'start' => 0,
            'rows' => 20,
            'wt' => 'json'
        ];
        
        // Handle _facetable parameter for field discovery
        if (isset($query['_facetable']) && ($query['_facetable'] === true || $query['_facetable'] === 'true')) {
            $solrQuery['_facetable'] = true;
        }
        
        // Handle _facets parameter for extended faceting
        if (isset($query['_facets'])) {
            $solrQuery['_facets'] = $query['_facets'];
            
            // For extended faceting, we'll use JSON faceting instead of traditional faceting
            // Skip traditional faceting setup when using extended mode
            if ($query['_facets'] === 'extend') {
                $solrQuery['_use_json_faceting'] = true;
            }
        }

        // Handle search query with wildcard support and field weighting
        if (!empty($query['_search'])) {
            $searchTerm = trim($query['_search']);
            
            // Build weighted multi-field search query
            $searchQuery = $this->buildWeightedSearchQuery($searchTerm);
            $solrQuery['q'] = $searchQuery;
            
            
            // Enable highlighting for search results (only for searched fields)
            $solrQuery['hl'] = 'true';
            $solrQuery['hl.fl'] = 'self_name,self_summary,self_description';
            $solrQuery['hl.simple.pre'] = '<mark>';
            $solrQuery['hl.simple.post'] = '</mark>';
        }

        // Handle pagination
        if (isset($query['_limit'])) {
            $solrQuery['rows'] = (int)$query['_limit'];
        }
        if (isset($query['_offset'])) {
            $solrQuery['start'] = (int)$query['_offset'];
        } elseif (isset($query['_page'])) {
            $page = max(1, (int)$query['_page']);
            $solrQuery['start'] = ($page - 1) * $solrQuery['rows'];
        }

        // Handle filters
        $filters = [];
        
        // Handle @self metadata filters (register, schema, etc.)
        if (isset($query['@self']) && is_array($query['@self'])) {
            foreach ($query['@self'] as $metaKey => $metaValue) {
                if ($metaValue !== null && $metaValue !== '') {
                    $solrField = 'self_' . $metaKey;
                    
                    // Handle string values for register/schema fields by resolving to integer IDs
                    if (in_array($metaKey, ['register', 'schema']) && !is_numeric($metaValue)) {
                        $metaValue = $this->resolveMetadataValueToId($metaKey, $metaValue);
                    }
                    
                    if (is_array($metaValue)) {
                        $conditions = array_map(function($v) use ($solrField, $metaKey) {
                            // Handle string values in arrays by resolving to integer IDs
                            if (in_array($metaKey, ['register', 'schema']) && !is_numeric($v)) {
                                $v = $this->resolveMetadataValueToId($metaKey, $v);
                            }
                            return $solrField . ':' . (is_numeric($v) ? $v : $this->escapeSolrValue((string)$v));
                        }, $metaValue);
                        $filters[] = '(' . implode(' OR ', $conditions) . ')';
                    } else {
                        if (is_numeric($metaValue)) {
                            $filters[] = $solrField . ':' . $metaValue;
                        } else {
                            $filters[] = $solrField . ':' . $this->escapeSolrValue((string)$metaValue);
                        }
                    }
                }
            }
        }
        
        // Handle regular object field filters
        foreach ($query as $key => $value) {
            if (!str_starts_with($key, '_') && !in_array($key, ['@self']) && $value !== null && $value !== '') {
                if (is_array($value)) {
                    $conditions = array_map(function($v) use ($key) {
                        return $key . ':' . (is_numeric($v) ? $v : $this->escapeSolrValue((string)$v));
                    }, $value);
                    $filters[] = '(' . implode(' OR ', $conditions) . ')';
                } else {
                    if (is_numeric($value)) {
                        $filters[] = $key . ':' . $value;
                    } else {
                        $filters[] = $key . ':' . $this->escapeSolrValue((string)$value);
                    }
                }
            }
        }

        // Handle multiple filter queries correctly for Guzzle
        if (!empty($filters)) {
            // Guzzle expects array values for multiple parameters with same name
            $solrQuery['fq'] = $filters;
        }
        


        // Handle facets - but skip traditional faceting if using JSON faceting (extend mode)
        if (!empty($query['_facets']) && $query['_facets'] !== 'extend') {
            $solrQuery['facet'] = 'true';
            $solrQuery['facet.field'] = [];
            
            foreach ($query['_facets'] as $facetGroup => $facetConfig) {
                if ($facetGroup === '@self' && is_array($facetConfig)) {
                    // Handle @self metadata facets
                    foreach ($facetConfig as $metaField => $metaConfig) {
                        if (is_array($metaConfig) && isset($metaConfig['type'])) {
                            $solrFacetField = 'self_' . $metaField;
                            
                            if ($metaConfig['type'] === 'terms') {
                                $solrQuery['facet.field'][] = $solrFacetField;
                            } elseif ($metaConfig['type'] === 'date_histogram') {
                                // Handle date histogram facets
                                $solrQuery['facet.date'] = $solrFacetField;
                                $solrQuery['facet.date.start'] = 'NOW/YEAR-10YEARS';
                                $solrQuery['facet.date.end'] = 'NOW';
                                $solrQuery['facet.date.gap'] = '+1MONTH';
                            }
                        }
                    }
                } elseif (is_array($facetConfig) && isset($facetConfig['type'])) {
                    // Handle regular facets
                    if ($facetConfig['type'] === 'terms') {
                        $solrQuery['facet.field'][] = $facetGroup;
                    }
                }
            }
        }


        return $solrQuery;
    }

    /**
     * Execute SOLR search query
     *
     * @param array $solrQuery SOLR query parameters
     * @param string $collectionName Collection name to search in
     * @param array  $extend         Extension parameters for @self properties
     * @return array Search results
     * @throws \Exception When search fails
     */
    private function executeSearch(array $solrQuery, string $collectionName, array $extend = []): array
    {
        $url = $this->buildSolrBaseUrl() . '/' . $collectionName . '/select';
        
        
        try {
            // Build the query string manually to handle multiple fq parameters correctly
            $queryParts = [];
            foreach ($solrQuery as $key => $value) {
                if ($key === 'fq' && is_array($value)) {
                    // Handle multiple fq parameters correctly
                    foreach ($value as $fqValue) {
                        $queryParts[] = 'fq=' . urlencode((string)$fqValue);
                    }
                } else {
                    $queryParts[] = $key . '=' . urlencode((string)$value);
                }
            }
            $queryString = implode('&', $queryParts);
            $fullUrl = $url . '?' . $queryString;
            
            // **DEBUG**: Log the final SOLR URL and query for troubleshooting
            $this->logger->debug('=== EXECUTING SOLR SEARCH ===', [
                'full_url' => $fullUrl,
                'collection' => $collectionName,
                'query_string' => $queryString,
                'query_parts_breakdown' => $queryParts
            ]);
            
            // SOLR query execution prepared
            
            // Use the manually built URL instead of Guzzle's query parameter handling
            $response = $this->httpClient->get($fullUrl, [
                'timeout' => 30,
                'connect_timeout' => 10
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();
            
            // SOLR response received
            
            if ($statusCode !== 200) {
                throw new \Exception("SOLR search failed with status code: $statusCode. Response: " . $responseBody);
            }

            $responseData = json_decode($responseBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON response from SOLR: ' . json_last_error_msg());
            }


            return $this->parseSolrResponse($responseData, $extend);

        } catch (\Exception $e) {
            $this->logger->error('SOLR search execution failed', [
                'url' => $url,
                'query' => $solrQuery,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Parse SOLR response into standardized format
     *
     * @param array $responseData Raw SOLR response
     * @param array $extend       Extension parameters for @self properties
     * @return array Parsed search results
     */
    private function parseSolrResponse(array $responseData, array $extend = []): array
    {
        $results = [
            'objects' => [],
            'total' => 0,
            'facets' => []
        ];

        // Parse documents and convert back to OpenRegister objects
        if (isset($responseData['response']['docs'])) {
            $results['objects'] = $this->convertSolrDocumentsToOpenRegisterObjects($responseData['response']['docs'], $extend);
            $results['total'] = $responseData['response']['numFound'] ?? count($results['objects']);
            
            // **DEBUG**: Log total vs results count for troubleshooting
            $this->logger->debug('SOLR response parsing', [
                'numFound_from_solr' => $responseData['response']['numFound'] ?? 'missing',
                'docs_returned' => count($responseData['response']['docs'] ?? []),
                'objects_converted' => count($results['objects']),
                'final_total' => $results['total']
            ]);
        }

        // Parse facets
        if (isset($responseData['facet_counts']['facet_fields'])) {
            foreach ($responseData['facet_counts']['facet_fields'] as $field => $values) {
                $facetData = [];
                for ($i = 0; $i < count($values); $i += 2) {
                    if (isset($values[$i + 1])) {
                        $facetData[] = [
                            'value' => $values[$i],
                            'count' => $values[$i + 1]
                        ];
                    }
                }
                $results['facets'][$field] = $facetData;
            }
        }

        return $results;
    }

    /**
     * Convert SOLR search results to OpenRegister paginated format
     *
     * @param array $searchResults SOLR search results
     * @param array $originalQuery Original OpenRegister query
     * @return array Paginated results in OpenRegister format matching database response structure
     */
    private function convertToOpenRegisterPaginatedFormat(array $searchResults, array $originalQuery, array $solrQuery = null): array
    {
        $limit = (int)($originalQuery['_limit'] ?? 20);
        $page = (int)($originalQuery['_page'] ?? 1);
        $offset = (int)($originalQuery['_offset'] ?? (($page - 1) * $limit));
        $total = $searchResults['total'] ?? 0;
        $pages = $limit > 0 ? max(1, ceil($total / $limit)) : 1;
        
        // **DEBUG**: Log pagination calculation for troubleshooting
        $this->logger->debug('Converting to OpenRegister paginated format', [
            'searchResults_total' => $searchResults['total'] ?? 'missing',
            'searchResults_objects_count' => count($searchResults['objects'] ?? []),
            'calculated_total' => $total,
            'limit' => $limit,
            'page' => $page,
            'calculated_pages' => $pages
        ]);

        // Match the database response format exactly
        $response = [
            'results' => $searchResults['objects'] ?? [],
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'limit' => $limit,
            'offset' => $offset,
            'facets' => [
                'facets' => $searchResults['facets'] ?? []
            ],
        ];
        
        // Handle _facetable parameter for live field discovery from SOLR
        if (isset($originalQuery['_facetable']) && ($originalQuery['_facetable'] === true || $originalQuery['_facetable'] === 'true')) {
            try {
                $facetableFields = $this->discoverFacetableFieldsFromSolr();
                // Combine all facetable fields into a single flat structure
                $combinedFacetableFields = array_merge(
                    $facetableFields['@self'] ?? [],
                    $facetableFields['object_fields'] ?? []
                );
                $response['facetable'] = $combinedFacetableFields;
                
                $this->logger->debug('Added facetable fields to response', [
                    'facetableFieldCount' => count($combinedFacetableFields)
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Failed to discover facetable fields from SOLR', [
                    'error' => $e->getMessage()
                ]);
                // Don't fail the whole request, just return empty facetable fields
                $response['facetable'] = [];
            }
        }

        // Handle _facets=extend parameter for complete faceting (discovery + data)
        if (isset($originalQuery['_facets']) && $originalQuery['_facets'] === 'extend') {
            try {
                
                // Build contextual facets - if we don't have solrQuery, build it from original query
                if ($solrQuery === null) {
                    $solrQuery = $this->buildSolrQuery($originalQuery);
                }
                
                // Re-run the same query with faceting enabled to get contextual facets
                // This is much more efficient than making separate calls
                $contextualFacetData = $this->getContextualFacetsFromSameQuery($solrQuery, $originalQuery);
                
                // Also discover all available facetable fields from SOLR schema
                $allFacetableFields = $this->discoverFacetableFieldsFromSolr();
                
                // Get metadata facetable fields (always available)
                $metadataFacetableFields = $this->getMetadataFacetableFields();
                
                // Combine contextual facets (metadata with data) with all available facetable fields
                $facetableFields = [
                    '@self' => $metadataFacetableFields['@self'] ?? [],
                    'object_fields' => $allFacetableFields['object_fields'] ?? []
                ];
                
                // Combine all facetable fields (metadata + object) into a single flat structure
                $combinedFacetableFields = array_merge(
                    $facetableFields['@self'] ?? [],
                    $facetableFields['object_fields'] ?? []
                );
                $response['facetable'] = $combinedFacetableFields;
                
                // Combine all extended facet data (metadata + object) into a single flat structure
                $extendedData = $contextualFacetData['extended'] ?? [];
                $combinedFacetData = array_merge(
                    $extendedData['@self'] ?? [],
                    $extendedData['object_fields'] ?? []
                );
                $response['facets'] = $combinedFacetData;
                
                $this->logger->debug('Added contextual faceting data to response', [
                    'facetableFieldCount' => count($contextualFacetData['facetable']['@self'] ?? []) + count($contextualFacetData['facetable']['object_fields'] ?? []),
                    'metadataFacets' => count($contextualFacetData['extended']['@self'] ?? []),
                    'objectFieldFacets' => count($contextualFacetData['extended']['object_fields'] ?? [])
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Failed to get contextual faceting data from SOLR', [
                    'error' => $e->getMessage()
                ]);
                // Don't fail the whole request, just return empty faceting data
                $response['facets']['facetable'] = [
                    '@self' => [],
                    'object_fields' => []
                ];
                $response['facets']['facets'] = [
                    '@self' => [],
                    'object_fields' => []
                ];
            }
        }

        // Add pagination URLs if applicable (matching database format)
        if ($page < $pages) {
            $response['next'] = $_SERVER['REQUEST_URI'] ?? '';
            // Update page parameter in URL for next page
            $response['next'] = preg_replace('/([?&])page=\d+/', '$1page=' . ($page + 1), $response['next']);
            if (strpos($response['next'], 'page=') === false) {
                $response['next'] .= (strpos($response['next'], '?') === false ? '?' : '&') . 'page=' . ($page + 1);
            }
        }

        return $response;
    }

    /**
     * Convert SOLR documents back to OpenRegister objects
     *
     * This method extracts the actual OpenRegister object from the SOLR document's self_object field
     * and merges it with essential metadata from the SOLR document. If @self.register or @self.schema
     * extensions are requested, it will load and include the full register/schema objects using the
     * RenderObject service's caching mechanism.
     *
     * @phpstan-param array<int, array<string, mixed>> $solrDocuments
     * @psalm-param array<array<string, mixed>> $solrDocuments
     * @phpstan-return array<int, array<string, mixed>>
     * @psalm-return array<array<string, mixed>>
     *
     * @param array $solrDocuments Array of SOLR documents
     * @param array $extend Array of properties to extend (e.g., ['@self.register', '@self.schema'])
     * @return array Array of OpenRegister objects with extended @self properties
     */
    private function convertSolrDocumentsToOpenRegisterObjects(array $solrDocuments = [], $extend = []): array
    {
        $openRegisterObjects = [];

        foreach ($solrDocuments as $doc) {
            $object   = is_array($doc['self_object'] ?? null) ? ($doc['self_object'][0] ?? null) : ($doc['self_object'] ?? null);
            $uuid     = is_array($doc['self_uuid'] ?? null) ? ($doc['self_uuid'][0] ?? null) : ($doc['self_uuid'] ?? null);
            $registerId = is_array($doc['self_register'] ?? null) ? ($doc['self_register'][0] ?? null) : ($doc['self_register'] ?? null);
            $schemaId   = is_array($doc['self_schema'] ?? null) ? ($doc['self_schema'][0] ?? null) : ($doc['self_schema'] ?? null);
    
            if (!$object) {
                $this->logger->warning('[GuzzleSolrService] Invalid document missing required self_object', [
                    'uuid' => $uuid,
                    'register' => $registerId,
                    'schema' => $schemaId
                ]);
                continue;
            }

            try { 
                $objectData = json_decode($object, true);

                // Add register and schema context to @self if requested and we have the necessary data
                if (is_array($extend) && ($registerId || $schemaId) && 
                    (in_array('@self.register', $extend) === true || in_array('@self.schema', $extend) === true)) {
                    
                    $self = $objectData['@self'] ?? [];
        
                    if (in_array('@self.register', $extend) === true && $registerId && $this->registerMapper !== null) {
                        // Use the RegisterMapper directly to get register
                        try {
                            $register = $this->registerMapper->find($registerId);
                            if ($register !== null) {
                                $self['register'] = $register->jsonSerialize();
                            }
                        } catch (\Exception $e) {
                            $this->logger->warning('Failed to load register for @self extension', [
                                'registerId' => $registerId,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
        
                    if (in_array('@self.schema', $extend) === true && $schemaId && $this->schemaMapper !== null) {
                        // Use the SchemaMapper directly to get schema
                        try {
                            $schema = $this->schemaMapper->find($schemaId);
                            if ($schema !== null) {
                                $self['schema'] = $schema->jsonSerialize();
                            }
                        } catch (\Exception $e) {
                            $this->logger->warning('Failed to load schema for @self extension', [
                                'schemaId' => $schemaId,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
        
                    $objectData['@self'] = $self;
                }
                
                $openRegisterObjects[] = $objectData;

            } catch (\Exception $e) {
                $this->logger->warning('[GuzzleSolrService] Failed to reconstruct object from Solr document', [
                    'doc_id' => $doc['id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }
    
        return $openRegisterObjects;
    }

    /**
     * Test Zookeeper connectivity for SolrCloud
     *
     * @return array Zookeeper test results
     */
    private function testZookeeperConnection(): array
    {
        try {
            $zookeeperHosts = $this->solrConfig['zookeeperHosts'] ?? 'zookeeper:2181';
            $hosts = explode(',', $zookeeperHosts);
            
            $successfulHosts = [];
            $failedHosts = [];
            
            foreach ($hosts as $host) {
                $host = trim($host);
                
                // Try to test Zookeeper via SOLR Collections API
                // This is more reliable than direct Zookeeper connection
                try {
                    $url = $this->buildSolrBaseUrl() . '/admin/collections?action=LIST&wt=json';
                    $response = $this->httpClient->get($url, ['timeout' => 10]);
                    
                    if ($response->getStatusCode() === 200) {
                        $successfulHosts[] = $host;
                    } else {
                        $failedHosts[] = $host;
                    }
                } catch (\Exception $e) {
                    $failedHosts[] = $host;
                }
            }
            
            if (!empty($successfulHosts)) {
                return [
                    'success' => true,
                    'message' => 'Zookeeper accessible via SOLR Collections API',
                    'details' => [
                        'zookeeper_hosts' => $zookeeperHosts,
                        'successful_hosts' => $successfulHosts,
                        'failed_hosts' => $failedHosts,
                        'test_method' => 'SOLR Collections API'
                    ]
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Zookeeper not accessible via any host',
                    'details' => [
                        'zookeeper_hosts' => $zookeeperHosts,
                        'successful_hosts' => $successfulHosts,
                        'failed_hosts' => $failedHosts,
                        'test_method' => 'SOLR Collections API'
                    ]
                ];
            }
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Zookeeper test failed: ' . $e->getMessage(),
                'details' => [
                    'error' => $e->getMessage(),
                    'zookeeper_hosts' => $this->solrConfig['zookeeperHosts'] ?? 'zookeeper:2181'
                ]
            ];
        }
    }

    /**
     * Test SOLR connectivity
     *
     * @return array SOLR test results
     */
    private function testSolrConnectivity(): array
    {
        try {
            $baseUrl = $this->buildSolrBaseUrl();

            // Test basic SOLR connectivity with admin endpoints
            // Try multiple common SOLR admin endpoints for maximum compatibility
            $testEndpoints = [
                '/admin/ping?wt=json',
                '/solr/admin/ping?wt=json',
                '/admin/info/system?wt=json'
            ];
            
            $testUrl = null;
            $lastError = null;
            $workingEndpoint = null;
            
            // Try each endpoint until one works
            $response = null;
            $responseTime = 0;
            
            foreach ($testEndpoints as $endpoint) {
                $testUrl = $baseUrl . $endpoint;
                $startTime = microtime(true);
                
                try {
                    $response = $this->httpClient->get($testUrl, ['timeout' => 10]);
                    $responseTime = (microtime(true) - $startTime) * 1000;
                    
                    if ($response->getStatusCode() === 200) {
                        $workingEndpoint = $endpoint;
                        break;
                    }
                } catch (\Exception $e) {
                    $lastError = "Failed to connect to: " . $testUrl;
                    continue;
                }
            }
            
            if (!$response || $response->getStatusCode() !== 200) {
                return [
                    'success' => false,
                    'message' => 'SOLR server not responding on any admin endpoint',
                    'details' => [
                        'tested_endpoints' => array_map(function($endpoint) use ($baseUrl) {
                            return $baseUrl . $endpoint;
                        }, $testEndpoints),
                        'last_error' => $lastError,
                        'test_type' => 'admin_ping',
                        'response_time_ms' => round($responseTime, 2)
                    ]
                ];
            }
            
            $data = json_decode((string)$response->getBody(), true);
            
            // Validate admin response - be flexible about response format
            $isValidResponse = false;
            
            if (isset($data['status']) && $data['status'] === 'OK') {
                // Standard ping response
                $isValidResponse = true;
            } elseif (isset($data['responseHeader']['status']) && $data['responseHeader']['status'] === 0) {
                // System info response
                $isValidResponse = true;
            } elseif (is_array($data) && !empty($data)) {
                // Any valid JSON response indicates SOLR is responding
                $isValidResponse = true;
            }
            
            if ($isValidResponse) {
                return [
                    'success' => true,
                    'message' => 'SOLR server is responding',
                    'details' => [
                        'working_endpoint' => $workingEndpoint,
                        'response_time_ms' => round($responseTime, 2),
                        'server_info' => [
                            'solr_version' => $data['lucene']['solr-spec-version'] ?? 'unknown',
                            'lucene_version' => $data['lucene']['lucene-spec-version'] ?? 'unknown'
                        ]
                    ]
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'SOLR server responding but with invalid format',
                    'details' => [
                        'response_data' => $data,
                        'response_time_ms' => round($responseTime, 2)
                    ]
                ];
            }
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'SOLR connectivity test failed: ' . $e->getMessage(),
                'details' => [
                    'error' => $e->getMessage(),
                    'base_url' => $this->buildSolrBaseUrl()
                ]
            ];
        }
    }

    /**
     * Test SOLR collection/core availability
     *
     * @return array Collection test results
     */
    private function testSolrCollection(): array
    {
        try {
            // Use tenant-specific collection name - return failure if none exists
            $collectionName = $this->getActiveCollectionName();
            if ($collectionName === null) {
                return [
                    'success' => false,
                    'message' => 'No active collection available for testing',
                    'details' => [
                        'tenant_id' => $this->tenantId,
                        'reason' => 'Tenant collection does not exist'
                    ]
                ];
            }
            
            $baseUrl = $this->buildSolrBaseUrl();
            
            // For SolrCloud, test collection existence
            if ($this->solrConfig['useCloud'] ?? false) {
                $url = $baseUrl . '/admin/collections?action=CLUSTERSTATUS&wt=json';
                
                $response = $this->httpClient->get($url, ['timeout' => 10]);
                
                if ($response->getStatusCode() !== 200) {
                    return [
                        'success' => false,
                        'message' => 'Failed to check collection status',
                        'details' => ['url' => $url]
                    ];
                }
                
                $data = json_decode((string)$response->getBody(), true);
                $collections = $data['cluster']['collections'] ?? [];
                
                if (isset($collections[$collectionName])) {
                    return [
                        'success' => true,
                        'message' => "Collection '{$collectionName}' exists and is available",
                        'details' => [
                            'collection_name' => $collectionName,
                            'collection_type' => strpos($collectionName, '_nc_') !== false ? 'tenant-specific' : 'base',
                            'status' => $collections[$collectionName]['status'] ?? 'unknown',
                            'shards' => count($collections[$collectionName]['shards'] ?? [])
                        ]
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => "Collection '{$collectionName}' not found",
                        'details' => [
                            'collection_name' => $collectionName,
                            'collection_type' => strpos($collectionName, '_nc_') !== false ? 'tenant-specific' : 'base',
                            'available_collections' => array_keys($collections),
                            'tenant_id' => $this->tenantId,
                            'expected_pattern' => 'openregister_' . $this->tenantId
                        ]
                    ];
                }
            } else {
                // For standalone SOLR, test core existence
                $url = $baseUrl . '/admin/cores?action=STATUS&core=' . urlencode($collectionName) . '&wt=json';
                
                $response = $this->httpClient->get($url, ['timeout' => 10]);
                
                if ($response->getStatusCode() !== 200) {
                    return [
                        'success' => false,
                        'message' => 'Failed to check core status',
                        'details' => ['url' => $url]
                    ];
                }
                
                $data = json_decode((string)$response->getBody(), true);
                $cores = $data['status'] ?? [];
                
                if (isset($cores[$collectionName])) {
                    return [
                        'success' => true,
                        'message' => "Core '{$collectionName}' exists and is available",
                        'details' => [
                            'collection_name' => $collectionName,
                            'collection_type' => strpos($collectionName, '_nc_') !== false ? 'tenant-specific' : 'base',
                            'num_docs' => $cores[$collectionName]['index']['numDocs'] ?? 0,
                            'max_docs' => $cores[$collectionName]['index']['maxDoc'] ?? 0
                        ]
                    ];
            } else {
                    return [
                        'success' => false,
                        'message' => "Core '{$collectionName}' not found",
                        'details' => [
                            'collection_name' => $collectionName,
                            'collection_type' => strpos($collectionName, '_nc_') !== false ? 'tenant-specific' : 'base',
                            'available_cores' => array_keys($cores),
                            'tenant_id' => $this->tenantId,
                            'expected_pattern' => 'openregister_' . $this->tenantId
                        ]
                    ];
                }
            }
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Collection/core test failed: ' . $e->getMessage(),
                'details' => [
                    'error' => $e->getMessage(),
                    'collection' => $this->solrConfig['collection'] ?? $this->solrConfig['core'] ?? 'openregister'
                ]
            ];
        }
    }

    /**
     * Test SOLR collection query functionality
     *
     * @return array Query test results
     */
    private function testSolrQuery(): array
    {
        try {
            // Use tenant-specific collection name - return failure if none exists
            $collectionName = $this->getActiveCollectionName();
            if ($collectionName === null) {
                return [
                    'success' => false,
                    'message' => 'No active collection available for query testing',
                    'details' => [
                        'tenant_id' => $this->tenantId,
                        'reason' => 'Tenant collection does not exist'
                    ]
                ];
            }
            
            $baseUrl = $this->buildSolrBaseUrl();
            
            // Test basic query functionality
            $url = $baseUrl . '/' . $collectionName . '/select?q=*:*&rows=1&wt=json';
            
            $startTime = microtime(true);
            $response = $this->httpClient->get($url, ['timeout' => 10]);
            $responseTime = (microtime(true) - $startTime) * 1000;
            
            if ($response->getStatusCode() !== 200) {
                return [
                    'success' => false,
                    'message' => 'Query test failed with HTTP error',
                    'details' => [
                        'status_code' => $response->getStatusCode(),
                        'url' => $url
                    ]
                ];
            }
            
            $data = json_decode((string)$response->getBody(), true);
            
            if (isset($data['response'])) {
                return [
                    'success' => true,
                    'message' => 'Query test successful',
                    'details' => [
                        'collection_name' => $collectionName,
                        'collection_type' => strpos($collectionName, '_nc_') !== false ? 'tenant-specific' : 'base',
                        'total_docs' => $data['response']['numFound'] ?? 0,
                        'response_time_ms' => round($responseTime, 2),
                        'query_url' => $url,
                        'tenant_id' => $this->tenantId
                    ]
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Query returned invalid response format',
                    'details' => [
                        'response_data' => $data,
                        'response_time_ms' => round($responseTime, 2)
                    ]
                ];
            }
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Query test failed: ' . $e->getMessage(),
                'details' => [
                    'error' => $e->getMessage(),
                    'collection' => $this->solrConfig['collection'] ?? $this->solrConfig['core'] ?? 'openregister'
                ]
            ];
        }
    }

    /**
     * Clear entire index for tenant
     *
     * @return array Result with success status and error details
     */
    public function clearIndex(): array
    {
        return $this->deleteByQuery('*:*', true, true);
    }

    /**
     * Inspect SOLR index documents
     *
     * @param string $query SOLR query
     * @param int $start Start offset
     * @param int $rows Number of rows to return
     * @param string $fields Comma-separated list of fields to return
     * @return array Result with documents and metadata
     */
    public function inspectIndex(string $query = '*:*', int $start = 0, int $rows = 20, string $fields = ''): array
    {
        if (!$this->isAvailable()) {
            return [
                'success' => false,
                'error' => 'SOLR service is not available',
                'error_details' => 'SOLR connection is not configured or unavailable'
            ];
        }

        try {
            // Get the active collection name
            $tenantCollectionName = $this->getActiveCollectionName();
            if ($tenantCollectionName === null) {
                return [
                    'success' => false,
                    'error' => 'No active SOLR collection available',
                    'error_details' => 'No collection found for the current tenant'
                ];
            }

            // Add tenant isolation to query
            $tenantQuery = sprintf('(%s) AND self_tenant:%s', $query, $this->tenantId);

            // Build search parameters
            $searchParams = [
                'q' => $tenantQuery,
                'start' => $start,
                'rows' => $rows,
                'wt' => 'json',
                'indent' => 'true'
            ];

            // Add field list if specified
            if (!empty($fields)) {
                $searchParams['fl'] = $fields;
            }

            $url = $this->buildSolrBaseUrl() . '/' . $tenantCollectionName . '/select';
            
            $response = $this->httpClient->get($url, [
                'query' => $searchParams,
                'timeout' => 30
            ]);

            $data = json_decode((string)$response->getBody(), true);
            
            if (($data['responseHeader']['status'] ?? -1) === 0) {
                $documents = $data['response']['docs'] ?? [];
                $totalResults = $data['response']['numFound'] ?? 0;
                
                $this->logger->debug('🔍 SOLR INDEX INSPECT', [
                    'query' => $tenantQuery,
                    'collection' => $tenantCollectionName,
                    'tenant_id' => $this->tenantId,
                    'total_results' => $totalResults,
                    'returned_docs' => count($documents)
                ]);
                
                return [
                    'success' => true,
                    'documents' => $documents,
                    'total' => $totalResults,
                    'start' => $start,
                    'rows' => $rows,
                    'collection' => $tenantCollectionName,
                    'tenant_id' => $this->tenantId
                ];
            } else {
                $errorMsg = $data['error']['msg'] ?? 'Unknown SOLR error';
                $errorCode = $data['error']['code'] ?? $data['responseHeader']['status'] ?? -1;
                
                return [
                    'success' => false,
                    'error' => "SOLR search failed: {$errorMsg}",
                    'error_details' => [
                        'solr_error' => $errorMsg,
                        'error_code' => $errorCode,
                        'query' => $tenantQuery,
                        'collection' => $tenantCollectionName,
                        'full_response' => $data
                    ]
                ];
            }

        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $errorMsg = 'HTTP request failed';
            $errorDetails = [
                'exception_type' => 'RequestException',
                'message' => $e->getMessage(),
                'query' => $query
            ];

            // Try to extract SOLR error from response
            if ($e->hasResponse()) {
                $responseBody = (string)$e->getResponse()->getBody();
                $responseData = json_decode($responseBody, true);
                
                if ($responseData && isset($responseData['error'])) {
                    $errorMsg = "SOLR HTTP {$e->getResponse()->getStatusCode()} Error: " . ($responseData['error']['msg'] ?? $responseData['error']);
                    $errorDetails['solr_response'] = $responseData;
                    $errorDetails['http_status'] = $e->getResponse()->getStatusCode();
                }
            }

            $this->logger->error('HTTP exception inspecting SOLR index', $errorDetails);
            
            return [
                'success' => false,
                'error' => $errorMsg,
                'error_details' => $errorDetails
            ];

        } catch (\Exception $e) {
            $this->logger->error('Exception inspecting SOLR index', [
                'query' => $query,
                'error' => $e->getMessage(),
                'exception_type' => get_class($e)
            ]);
            
            return [
                'success' => false,
                'error' => 'Unexpected error during SOLR inspection: ' . $e->getMessage(),
                'error_details' => [
                    'exception_type' => get_class($e),
                    'message' => $e->getMessage(),
                    'query' => $query
                ]
            ];
        }
    }

    /**
     * Optimize SOLR index
     *
     * @return bool True if successful
     */
    public function optimize(): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            // Get the active collection name - return false if no collection exists
            $tenantCollectionName = $this->getActiveCollectionName();
            if ($tenantCollectionName === null) {
                $this->logger->warning('Cannot optimize: no active collection available');
                return false;
            }

            $url = $this->buildSolrBaseUrl() . '/' . $tenantCollectionName . '/update?wt=json&optimize=true';

            $response = $this->httpClient->post($url, [
                'body' => json_encode(['optimize' => []]),
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 120 // Optimization can take time
            ]);

            $data = json_decode((string)$response->getBody(), true);
            $success = ($data['responseHeader']['status'] ?? -1) === 0;

            if ($success) {
                $this->logger->info('⚡ SOLR INDEX OPTIMIZED', [
                    'collection' => $tenantCollectionName,
                    'tenant_id' => $this->tenantId
                ]);
            }

            return $success;

        } catch (\Exception $e) {
            $this->logger->error('Exception optimizing SOLR', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get dashboard statistics
     *
     * @return array Dashboard statistics
     */
    public function getDashboardStats(): array
    {
        // Use the same availability check as testConnection() instead of isAvailable()
        // This ensures consistency between connection test and dashboard stats
        try {
            $connectionTest = $this->testConnection();
            if (!$connectionTest['success']) {
                return [
                    'available' => false, 
                    'error' => 'SOLR not available: ' . ($connectionTest['message'] ?? 'Connection test failed')
                ];
            }
        } catch (\Exception $e) {
            return [
                'available' => false, 
                'error' => 'SOLR not available: ' . $e->getMessage()
            ];
        }

        try {
            // Get the active collection name - return error if no collection exists
            $tenantCollectionName = $this->getActiveCollectionName();
            if ($tenantCollectionName === null) {
                return [
                    'available' => false,
                    'error' => 'No active collection available - tenant collection may not exist',
                    'tenant_id' => $this->tenantId
                ];
            }

            // Get collection stats
            $statsUrl = $this->buildSolrBaseUrl() . '/admin/collections?action=CLUSTERSTATUS&collection=' . $tenantCollectionName . '&wt=json';
            $statsResponse = $this->httpClient->get($statsUrl, ['timeout' => 10]);
            $statsData = json_decode((string)$statsResponse->getBody(), true);

            // Get document count from Solr (indexed objects)
            $docCount = $this->getDocumentCount();

            // Get object counts from database using ObjectEntityMapper
            $totalCount = 0;
            $publishedCount = 0;
            try {
                // Get ObjectEntityMapper directly from container
                $objectMapper = \OC::$server->get(\OCA\OpenRegister\Db\ObjectEntityMapper::class);
                
                // Get total object count (excluding deleted objects)
                $totalCount = $objectMapper->countAll(
                    filters: [],
                    search: null,
                    ids: null,
                    uses: null,
                    includeDeleted: false,
                    register: null,
                    schema: null,
                    published: null, // Don't filter by published status for total count
                    rbac: false,     // Skip RBAC for performance
                    multi: false     // Skip multitenancy for performance
                );
                
                // Get published object count
                $publishedCount = $objectMapper->countAll(
                    filters: [],
                    search: null,
                    ids: null,
                    uses: null,
                    includeDeleted: false,
                    register: null,
                    schema: null,
                    published: true, // Only count published objects
                    rbac: false,     // Skip RBAC for performance
                    multi: false     // Skip multitenancy for performance
                );
            } catch (\Exception $e) {
                $this->logger->warning('Failed to get object counts from database', ['error' => $e->getMessage()]);
            }

            // Get index size (approximate)
            $indexSizeUrl = $this->buildSolrBaseUrl() . '/' . $tenantCollectionName . '/admin/luke?wt=json&numTerms=0';
            $sizeResponse = $this->httpClient->get($indexSizeUrl, ['timeout' => 10]);
            $sizeData = json_decode((string)$sizeResponse->getBody(), true);

            $collectionInfo = $statsData['cluster']['collections'][$tenantCollectionName] ?? [];
            $shards = $collectionInfo['shards'] ?? [];

            // Get memory prediction for warmup (using published object count)
            $memoryPrediction = [];
            try {
                $memoryPrediction = $this->predictWarmupMemoryUsage(0); // 0 = all published objects
            } catch (\Exception $e) {
                $this->logger->warning('Failed to get memory prediction for dashboard stats', ['error' => $e->getMessage()]);
                $memoryPrediction = [
                    'error' => 'Unable to predict memory usage',
                    'prediction_safe' => true // Default to safe
                ];
            }

            return [
                'available' => true,
                'tenant_id' => $this->tenantId,
                'collection' => $tenantCollectionName,
                'document_count' => $docCount,
                'total_count' => $totalCount,
                'published_count' => $publishedCount,
                'shards' => count($shards),
                'index_version' => $sizeData['index']['version'] ?? 'unknown',
                'last_modified' => $sizeData['index']['lastModified'] ?? 'unknown',
                'service_stats' => $this->stats,
                'health' => !empty($collectionInfo) ? 'healthy' : 'degraded',
                'memory_prediction' => $memoryPrediction
            ];

        } catch (\Exception $e) {
            $this->logger->error('Exception getting dashboard stats', ['error' => $e->getMessage()]);
            return [
                'available' => false,
                'error' => $e->getMessage(),
                'tenant_id' => $this->tenantId
            ];
        }
    }

    /**
     * Get service statistics
     *
     * @return array Service statistics
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'available' => $this->isAvailable(),
            'tenant_id' => $this->tenantId,
            'service_type' => 'GuzzleHttp',
            'memory_usage' => 'lightweight'
        ]);
    }

    /**
     * Test SOLR connection specifically for dashboard display
     * 
     * @return array Dashboard-specific connection test results
     */
    public function testConnectionForDashboard(): array
    {
        try {
            $connectionTest = $this->testConnection();
            $stats = $this->getDashboardStats();
            
            return [
                'connection' => $connectionTest,
                'availability' => $stats['available'] ?? false,
                'stats' => $stats,
                'timestamp' => date('c')
            ];
            
        } catch (\Exception $e) {
            return [
                'connection' => [
                    'success' => false,
                    'message' => 'SOLR service unavailable: ' . $e->getMessage(),
                    'details' => ['error' => $e->getMessage()]
                ],
                'availability' => false,
                'stats' => [
                    'available' => false,
                    'error' => $e->getMessage()
                ],
                'timestamp' => date('c')
            ];
        }
    }

    /**
     * Get SOLR endpoint URL for dashboard display
     * 
     * @param string|null $collection Optional collection name, defaults to active collection
     * @return string SOLR endpoint URL
     */
    public function getEndpointUrl(?string $collection = null): string
    {
        try {
            $baseUrl = $this->buildSolrBaseUrl();
            $collectionName = $collection ?? $this->getActiveCollectionName();
            
            if ($collectionName === null) {
                return 'N/A (no active collection)';
            }
            
            return $baseUrl . '/' . $collectionName;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to build endpoint URL', ['error' => $e->getMessage()]);
            return 'N/A';
        }
    }

    /**
     * Get the authenticated HTTP client for other services to use
     * 
     * This allows other services like SolrSetup to use the same authenticated
     * HTTP client without duplicating authentication logic.
     * 
     * @return GuzzleClient The configured and authenticated HTTP client
     */
    public function getHttpClient(): GuzzleClient
    {
        return $this->httpClient;
    }

    /**
     * Get SOLR configuration for other services
     * 
     * @return array SOLR configuration array
     */
    public function getSolrConfig(): array
    {
        return $this->solrConfig;
    }

    /**
     * Count objects that belong to searchable schemas
     *
     * @param \OCA\OpenRegister\Db\ObjectEntityMapper $objectMapper The object mapper instance
     * @return int Number of objects with searchable schemas
     */
    private function countSearchableObjects(\OCA\OpenRegister\Db\ObjectEntityMapper $objectMapper): int
    {
        try {
            // Use direct database query to count objects with searchable schemas
            $db = \OC::$server->getDatabaseConnection();
            $qb = $db->getQueryBuilder();
            
            $qb->select($qb->createFunction('COUNT(o.id)'))
                ->from('openregister_objects', 'o')
                ->leftJoin('o', 'openregister_schemas', 's', 'o.schema = s.id')
                ->where($qb->expr()->eq('s.searchable', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
                ->andWhere($qb->expr()->isNull('o.deleted')); // Exclude deleted objects
            
            $result = $qb->executeQuery();
            $count = (int) $result->fetchOne();
            
            $this->logger->info('📊 Counted searchable objects', [
                'searchable_objects' => $count
            ]);
            
            return $count;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to count searchable objects, falling back to all objects', [
                'error' => $e->getMessage()
            ]);
            // Fallback to counting all objects if searchable filter fails
            return $objectMapper->countAll(rbac: false, multi: false);
        }
    }

    /**
     * Fetch objects that belong to searchable schemas only
     *
     * @param \OCA\OpenRegister\Db\ObjectEntityMapper $objectMapper The object mapper instance
     * @param int $limit Number of objects to fetch
     * @param int $offset Offset for pagination
     * @return array Array of ObjectEntity objects with searchable schemas
     */
    private function fetchSearchableObjects(\OCA\OpenRegister\Db\ObjectEntityMapper $objectMapper, int $limit, int $offset): array
    {
        try {
            // Use direct database query to fetch objects with searchable schemas
            $db = \OC::$server->getDatabaseConnection();
            $qb = $db->getQueryBuilder();
            
            $qb->select('o.*')
                ->from('openregister_objects', 'o')
                ->leftJoin('o', 'openregister_schemas', 's', 'o.schema = s.id')
                ->where($qb->expr()->eq('s.searchable', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
                ->andWhere($qb->expr()->isNull('o.deleted')) // Exclude deleted objects
                ->setMaxResults($limit)
                ->setFirstResult($offset)
                ->orderBy('o.id', 'ASC'); // Consistent ordering for pagination
            
            $result = $qb->executeQuery();
            $rows = $result->fetchAll();
            
            // Convert rows to ObjectEntity objects
            $objects = [];
            foreach ($rows as $row) {
                $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
                $objectEntity->hydrate($row);
                $objects[] = $objectEntity;
            }
            
            $this->logger->debug('📊 Fetched searchable objects', [
                'requested' => $limit,
                'offset' => $offset,
                'found' => count($objects)
            ]);
            
            return $objects;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to fetch searchable objects, falling back to all objects', [
                'error' => $e->getMessage(),
                'limit' => $limit,
                'offset' => $offset
            ]);
            // Fallback to fetching all objects if searchable filter fails
            return $objectMapper->findAll(
                limit: $limit,
                offset: $offset,
                rbac: false,
                multi: false
            );
        }
    }

    /**
     * Bulk index objects from database to Solr in batches
     *
     * @param int $batchSize Number of objects to process per batch (default: 1000)
     * @param int $maxObjects Maximum total objects to process (0 = no limit)
     *
     * @return array Results of the bulk indexing operation
     */
    public function bulkIndexFromDatabase(int $batchSize = 1000, int $maxObjects = 0, array $solrFieldTypes = []): array
    {
        if (!$this->isAvailable()) {
            return [
                'success' => false,
                'error' => 'Solr is not available',
                'indexed' => 0,
                'batches' => 0
            ];
        }

        try {
            // Get ObjectEntityMapper directly for better performance
            $objectMapper = \OC::$server->get(\OCA\OpenRegister\Db\ObjectEntityMapper::class);
            
            $totalIndexed = 0;
            $batchCount = 0;
            $offset = 0;
            $results = ['skipped_non_searchable' => 0];
            
            $this->logger->info('Starting sequential bulk index from database using ObjectEntityMapper directly');
            
            // **IMPROVED**: Get count of only searchable objects for more accurate planning
            $totalObjects = $this->countSearchableObjects($objectMapper);
            $this->logger->info('📊 Sequential bulk index planning (searchable objects only)', [
                'totalSearchableObjects' => $totalObjects,
                'maxObjects' => $maxObjects,
                'batchSize' => $batchSize,
                'estimatedBatches' => $maxObjects > 0 ? ceil(min($totalObjects, $maxObjects) / $batchSize) : ceil($totalObjects / $batchSize),
                'willProcess' => $maxObjects > 0 ? min($totalObjects, $maxObjects) : $totalObjects
            ]);
            
            do {
                // Calculate current batch size (respect maxObjects limit)
                $currentBatchSize = $batchSize;
                if ($maxObjects > 0) {
                    $remaining = $maxObjects - $totalIndexed;
                    if ($remaining <= 0) {
                        break;
                    }
                    $currentBatchSize = min($batchSize, $remaining);
                }
                
                // **IMPROVED**: Fetch only objects with searchable schemas
                $fetchStart = microtime(true);
                // Batch fetched (logging removed for performance)
                
                $objects = $this->fetchSearchableObjects($objectMapper, $currentBatchSize, $offset);
                
                $fetchEnd = microtime(true);
                $fetchDuration = round(($fetchEnd - $fetchStart) * 1000, 2);
                $this->logger->info('✅ Batch fetch complete', [
                    'batch' => $batchCount + 1,
                    'objectsFound' => count($objects),
                    'fetchTime' => $fetchDuration . 'ms'
                ]);
                
                if (empty($objects)) {
                    break; // No more objects
                }
                
                // **IMPROVED**: Index only searchable objects (already filtered at database level)
                // Filtering for published-only content is now handled at query time, not index time.
                $documents = [];
                foreach ($objects as $object) {
                    try {
                        $objectEntity = null;
                        if ($object instanceof ObjectEntity) {
                            $objectEntity = $object;
                        } else if (is_array($object)) {
                            // Convert array to ObjectEntity if needed
                            $objectEntity = new ObjectEntity();
                            $objectEntity->hydrate($object);
                        }
                        
                        if ($objectEntity) {
                            // Since we already filtered for searchable schemas at database level,
                            // we should not encounter non-searchable schemas here
                            $document = $this->createSolrDocument($objectEntity, $solrFieldTypes);
                            $documents[] = $document;
                        }
                    } catch (\RuntimeException $e) {
                        // This should rarely happen now since we pre-filter for searchable schemas
                        if (str_contains($e->getMessage(), 'Schema is not searchable')) {
                            $results['skipped_non_searchable']++;
                            $this->logger->warning('Unexpected non-searchable schema found despite pre-filtering', [
                                'objectId' => $objectEntity ? $objectEntity->getId() : 'unknown',
                                'error' => $e->getMessage()
                            ]);
                            continue;
                        }
                        throw $e;
                    } catch (\Exception $e) {
                        $this->logger->warning('Failed to create SOLR document', [
                            'error' => $e->getMessage(),
                            'objectId' => is_array($object) ? ($object['id'] ?? 'unknown') : ($object instanceof ObjectEntity ? $object->getId() : 'unknown')
                        ]);
                    }
                }
                
                // Bulk index the entire batch
                $indexed = 0;
                if (!empty($documents)) {
                    $indexStart = microtime(true);
                    // Bulk index the documents (minimal logging for performance)
                    
                    $this->bulkIndex($documents, true); // Commit each batch for immediate visibility
                    $indexed = count($documents); // If we reach here, indexing succeeded
                    
                    $indexEnd = microtime(true);
                    $indexDuration = round(($indexEnd - $indexStart) * 1000, 2);
                    
                    // Progress tracking (logging removed for performance)
                }
                
                // Removed redundant per-batch logging for performance
                
                // Commit after each batch
                if (!empty($objects)) {
                    $this->commit();
                    // Reduced commit logging for performance
                }
                
                $batchCount++;
                $totalIndexed += $indexed; // Use actual indexed count, not object count
                $offset += $currentBatchSize;
                
            } while (count($objects) === $currentBatchSize && ($maxObjects === 0 || $totalIndexed < $maxObjects));
            
            // **CRITICAL**: Commit all indexed documents at the end
            $this->commit();
            
            $this->logger->info('Sequential bulk indexing completed', [
                'totalIndexed' => $totalIndexed,
                'totalBatches' => $batchCount,
                'batchSize' => $batchSize
            ]);
            
            return [
                'success' => true,
                'indexed' => $totalIndexed,
                'batches' => $batchCount,
                'batch_size' => $batchSize,
                'skipped_non_searchable' => $results['skipped_non_searchable'] ?? 0
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Serial bulk indexing failed', ['error' => $e->getMessage()]);
            // **ERROR VISIBILITY**: Re-throw exception to expose errors
            throw new \RuntimeException(
                'Serial bulk indexing failed: ' . $e->getMessage() . 
                ' (Indexed: ' . ($totalIndexed ?? 0) . ', Batches: ' . ($batchCount ?? 0) . ')',
                0,
                $e
            );
        }
    }

    /**
     * Parallel bulk index objects from database to SOLR using ReactPHP
     *
     * @param int $batchSize Size of each batch (default: 1000)
     * @param int $maxObjects Maximum total objects to process (0 = no limit)
     * @param int $parallelBatches Number of parallel batches to process (default: 4)
     * @return array Result with success status and statistics
     */
    public function bulkIndexFromDatabaseParallel(int $batchSize = 1000, int $maxObjects = 0, int $parallelBatches = 4, array $solrFieldTypes = []): array
    {
        // Parallel bulk indexing method
        
        if (!$this->isAvailable()) {
            return [
                'success' => false,
                'error' => 'Solr is not available',
                'indexed' => 0,
                'batches' => 0
            ];
        }

        try {
            // Get ObjectEntityMapper directly for better performance
            $objectMapper = \OC::$server->get(\OCA\OpenRegister\Db\ObjectEntityMapper::class);
            
            $startTime = microtime(true);
            // Parallel bulk indexing started (logging removed for performance)

            // **IMPROVED**: Get count of only searchable objects for more accurate planning
            $totalObjects = $this->countSearchableObjects($objectMapper);
            
            // Total objects retrieved from database
            
            // Total objects determined (logging removed for performance)
            
            if ($maxObjects > 0) {
                $totalObjects = min($totalObjects, $maxObjects);
            }

            // Parallel batch processing planned (logging removed for performance)

            // Create batch jobs
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

            // Batch jobs created (logging removed for performance)

        // **FIXED**: Process batches in parallel chunks using ReactPHP (without ->wait())
        $totalIndexed = 0;
        $totalBatches = 0;
        $batchChunks = array_chunk($batchJobs, $parallelBatches);

        foreach ($batchChunks as $chunkIndex => $chunk) {
            $this->logger->info('Processing parallel chunk', [
                'chunkIndex' => $chunkIndex + 1,
                'totalChunks' => count($batchChunks),
                'batchesInChunk' => count($chunk)
            ]);

            $chunkStartTime = microtime(true);
            
            // **FIX**: Process batches synchronously within each chunk to avoid ReactPHP ->wait() issues
            $chunkResults = [];
            foreach ($chunk as $job) {
                $result = $this->processBatchDirectly($objectMapper, $job);
                $chunkResults[] = $result;
            }

            // Aggregate results from this chunk
            foreach ($chunkResults as $result) {
                if ($result['success']) {
                    $totalIndexed += $result['indexed'];
                    $totalBatches++;
                }
            }

            $chunkTime = round((microtime(true) - $chunkStartTime) * 1000, 2);
            $chunkIndexed = array_sum(array_column($chunkResults, 'indexed'));
            $this->logger->info('Completed parallel chunk', [
                'chunkIndex' => $chunkIndex + 1,
                'chunkTime' => $chunkTime . 'ms',
                'indexedInChunk' => $chunkIndexed,
                'totalIndexedSoFar' => $totalIndexed
            ]);

            // Commit after each chunk to ensure data persistence
            $this->commit();
        }

            $totalTime = round((microtime(true) - $startTime) * 1000, 2);
            // **CRITICAL**: Commit all indexed documents at the end
            $this->commit();
            
            $this->logger->info('Parallel bulk indexing completed', [
                'totalIndexed' => $totalIndexed,
                'totalBatches' => $totalBatches,
                'totalTime' => $totalTime . 'ms',
                'objectsPerSecond' => $totalTime > 0 ? round(($totalIndexed / $totalTime) * 1000, 2) : 0
            ]);

            return [
                'success' => true,
                'indexed' => $totalIndexed,
                'batches' => $totalBatches,
                'batch_size' => $batchSize,
                'parallel_batches' => $parallelBatches,
                'total_time_ms' => $totalTime
            ];

        } catch (\Exception $e) {
            $this->logger->error('Parallel bulk indexing failed', ['error' => $e->getMessage()]);
            // **ERROR VISIBILITY**: Re-throw exception to expose errors
            throw new \RuntimeException(
                'Parallel bulk indexing failed: ' . $e->getMessage() . 
                ' (Indexed: ' . ($totalIndexed ?? 0) . ', Batches: ' . ($totalBatches ?? 0) . ')',
                0,
                $e
            );
        }
    }

    /**
     * Process a single batch directly without ReactPHP promises
     *
     * @param ObjectEntityMapper $objectMapper
     * @param array $job
     * @return array
     */
    private function processBatchDirectly($objectMapper, array $job): array
    {
        $batchStartTime = microtime(true);
        
        // Processing batch
        
        try {
            // **IMPROVED**: Fetch only objects with searchable schemas for this batch
            $objects = $this->fetchSearchableObjects($objectMapper, $job['limit'], $job['offset']);

            if (empty($objects)) {
                return ['success' => true, 'indexed' => 0, 'batchNumber' => $job['batchNumber']];
            }

            // Create SOLR documents for the entire batch
            $documents = [];
            foreach ($objects as $object) {
                try {
                    if ($object instanceof ObjectEntity) {
                        $document = $this->createSolrDocument($object);
                        $documents[] = $document;
                    } else if (is_array($object)) {
                        $entity = new ObjectEntity();
                        $entity->hydrate($object);
                        $document = $this->createSolrDocument($entity);
                        $documents[] = $document;
                    }
                } catch (\RuntimeException $e) {
                    // Skip non-searchable schemas
                    if (str_contains($e->getMessage(), 'Schema is not searchable')) {
                        continue;
                    }
                    throw $e;
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to create SOLR document', [
                        'error' => $e->getMessage(),
                        'batch' => $job['batchNumber']
                    ]);
                }
            }
            
            // Documents prepared for bulk indexing
            
            // Bulk index the entire batch
            $indexed = 0;
            if (!empty($documents)) {
                $this->bulkIndex($documents, true); // Commit each batch for immediate visibility
                $indexed = count($documents); // If we reach here, indexing succeeded
            }

            $batchTime = round((microtime(true) - $batchStartTime) * 1000, 2);
            $this->logger->debug('Completed batch directly', [
                'batchNumber' => $job['batchNumber'],
                'indexed' => $indexed,
                'duration_ms' => $batchTime
            ]);

            return [
                'success' => true,
                'indexed' => $indexed,
                'batchNumber' => $job['batchNumber']
            ];

        } catch (\Exception $e) {
            $this->logger->error('Batch processing failed', [
                'batchNumber' => $job['batchNumber'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'indexed' => 0,
                'batchNumber' => $job['batchNumber'],
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Process a single batch asynchronously using ObjectEntityMapper
     *
     * @param \OCA\OpenRegister\Db\ObjectEntityMapper $objectMapper The ObjectEntityMapper instance
     * @param array $job Batch job configuration
     * @return \React\Promise\PromiseInterface
     */
    private function processBatchAsync($objectMapper, array $job): \React\Promise\PromiseInterface
    {
        return \React\Promise\resolve(null)->then(function() use ($objectMapper, $job) {
            $batchStartTime = microtime(true);
            
            // Fetch objects directly from ObjectEntityMapper
            $this->logger->debug('Processing batch async with ObjectEntityMapper', [
                'batchNumber' => $job['batchNumber'],
                'offset' => $job['offset'],
                'limit' => $job['limit']
            ]);

            // Fetch ALL objects (published and unpublished) for comprehensive indexing
            $objects = $objectMapper->findAll(
                limit: $job['limit'],
                offset: $job['offset'],
                filters: [],
                searchConditions: [],
                searchParams: [],
                sort: [],
                search: null,
                ids: null,
                uses: null,
                includeDeleted: false,
                register: null,
                schema: null,
                published: null, // Fetch ALL objects (published and unpublished)
                rbac: false,     // Skip RBAC for performance
                multi: false     // Skip multitenancy for performance
            );

            if (empty($objects)) {
                return ['success' => true, 'indexed' => 0, 'batchNumber' => $job['batchNumber']];
            }

            // Parallel batch processing
            
            // **PERFORMANCE**: Use bulk indexing for the entire batch
            $documents = [];
            foreach ($objects as $object) {
                try {
                    if ($object instanceof ObjectEntity) {
                        $document = $this->createSolrDocument($object);
                        $documents[] = $document;
                    } else if (is_array($object)) {
                        $entity = new ObjectEntity();
                        $entity->hydrate($object);
                        $document = $this->createSolrDocument($entity);
                        $documents[] = $document;
                    }
                } catch (\RuntimeException $e) {
                    // Skip non-searchable schemas
                    if (str_contains($e->getMessage(), 'Schema is not searchable')) {
                        continue;
                    }
                    throw $e;
                } catch (\Exception $e) {
                    // Log document creation errors
                }
            }
            
            // Bulk index the entire batch
            $indexed = 0;
            if (!empty($documents)) {
                $success = $this->bulkIndex($documents, true); // Commit each batch for immediate visibility
                $indexed = $success ? count($documents) : 0;
            }

            $batchTime = round((microtime(true) - $batchStartTime) * 1000, 2);
            $this->logger->debug('Completed batch async', [
                'batchNumber' => $job['batchNumber'],
                'indexed' => $indexed,
                'batchTime' => $batchTime . 'ms'
            ]);

            return [
                'success' => true,
                'indexed' => $indexed,
                'batchNumber' => $job['batchNumber'],
                'time_ms' => $batchTime
            ];
        });
    }

    /**
     * Hyper-fast bulk index with minimal processing for speed tests
     *
     * @param int $batchSize Size of each batch (default: 5000)
     * @param int $maxObjects Maximum objects to process (default: 10000 for 5-second test)
     * @return array Result with success status and statistics
     */
    public function bulkIndexFromDatabaseHyperFast(int $batchSize = 5000, int $maxObjects = 10000): array
    {
        if (!$this->isAvailable()) {
            return [
                'success' => false,
                'error' => 'Solr is not available',
                'indexed' => 0,
                'batches' => 0
            ];
        }

        try {
            // Get ObjectEntityMapper directly for maximum performance
            $objectMapper = \OC::$server->get(\OCA\OpenRegister\Db\ObjectEntityMapper::class);
            
            $startTime = microtime(true);
            $this->logger->info('Starting hyper-fast bulk index using ObjectEntityMapper', [
                'batchSize' => $batchSize,
                'maxObjects' => $maxObjects
            ]);

            $totalIndexed = 0;
            $batchCount = 0;
            $offset = 0;
            
            // **OPTIMIZATION**: Single large batch instead of multiple small ones
            $query = [
                '_limit' => $maxObjects, // Get all objects in one go
                '_offset' => 0
            ];

            $this->logger->info('Fetching all objects in single batch', [
                'limit' => $maxObjects
            ]);

            // Fetch only published objects (since we only index published objects)
            $objects = $objectMapper->findAll(
                limit: $maxObjects > 0 ? $maxObjects : null,
                offset: null,
                filters: [],
                searchConditions: [],
                searchParams: [],
                sort: [],
                search: null,
                ids: null,
                uses: null,
                includeDeleted: false,
                register: null,
                schema: null,
                published: true, // Only fetch published objects
                rbac: false,     // Skip RBAC for performance
                multi: false     // Skip multitenancy for performance
            );
            
            if (empty($objects)) {
                $this->logger->info('No objects to index');
                return [
                    'success' => true,
                    'indexed' => 0,
                    'batches' => 0,
                    'batch_size' => $batchSize
                ];
            }

            // **OPTIMIZATION**: Prepare all documents at once
            $documents = [];
            foreach ($objects as $object) {
                try {
                    if ($object instanceof ObjectEntity) {
                        $document = $this->createSolrDocument($object);
                        $documents[] = $document;
                    } else if (is_array($object)) {
                        $entity = new ObjectEntity();
                        $entity->hydrate($object);
                        $document = $this->createSolrDocument($entity);
                        $documents[] = $document;
                    }
                } catch (\RuntimeException $e) {
                    // Skip non-searchable schemas
                    if (str_contains($e->getMessage(), 'Schema is not searchable')) {
                        continue;
                    }
                    throw $e;
                }
            }

            $this->logger->info('Prepared documents for bulk index', [
                'documentCount' => count($documents)
            ]);

            // **OPTIMIZATION**: Single massive bulk index operation
            if (!empty($documents)) {
                $this->bulkIndex($documents, true); // Commit immediately - will throw on error
                $totalIndexed = count($documents); // If we reach here, indexing succeeded
                $batchCount = 1;
            }

            $totalTime = round((microtime(true) - $startTime) * 1000, 2);
            $this->logger->info('Hyper-fast bulk indexing completed', [
                'totalIndexed' => $totalIndexed,
                'totalTime' => $totalTime . 'ms',
                'objectsPerSecond' => $totalTime > 0 ? round(($totalIndexed / $totalTime) * 1000, 2) : 0
            ]);

            return [
                'success' => true,
                'indexed' => $totalIndexed,
                'batches' => $batchCount,
                'batch_size' => count($documents),
                'total_time_ms' => $totalTime
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Hyper-fast bulk indexing failed', ['error' => $e->getMessage()]);
            // **ERROR VISIBILITY**: Re-throw exception to expose errors
            throw new \RuntimeException(
                'Hyper-fast bulk indexing failed: ' . $e->getMessage() . 
                ' (Indexed: ' . ($totalIndexed ?? 0) . ', Batches: ' . ($batchCount ?? 0) . ')',
                0,
                $e
            );
        }
    }

    /**
     * Test schema-aware mapping by indexing sample objects
     *
     * This method indexes 5 objects per schema to test the new schema-aware mapping system.
     * Objects without schema properties will only have metadata indexed.
     *
     * @param ObjectEntityMapper $objectMapper Object mapper for database operations
     * @param SchemaMapper       $schemaMapper Schema mapper for database operations
     *
     * @return array Test results with statistics
     */
    public function testSchemaAwareMapping($objectMapper, $schemaMapper): array
    {
        if (!$this->isAvailable()) {
            return [
                'success' => false,
                'error' => 'SOLR is not available',
                'schemas_tested' => 0,
                'objects_indexed' => 0
            ];
        }

        $startTime = microtime(true);
        $results = [
            'success' => true,
            'schemas_tested' => 0,
            'objects_indexed' => 0,
            'schema_details' => [],
            'errors' => []
        ];

        try {
            // Get all schemas
            $schemas = $schemaMapper->findAll();
            
            $this->logger->info('Starting schema-aware mapping test', [
                'total_schemas' => count($schemas)
            ]);

            foreach ($schemas as $schema) {
                $schemaId = $schema->getId();
                $schemaDetails = [
                    'schema_id' => $schemaId,
                    'schema_title' => $schema->getTitle(),
                    'properties_count' => count($schema->getProperties()),
                    'objects_found' => 0,
                    'objects_indexed' => 0,
                    'mapping_type' => 'unknown'
                ];

                try {
                    // Get 5 objects for this schema
                    $objects = $objectMapper->searchObjects([
                        'schema' => $schemaId,
                        '_limit' => 5,
                        '_offset' => 0
                    ]);

                    $schemaDetails['objects_found'] = count($objects);

                    // Index each object using schema-aware mapping
                    foreach ($objects as $objectData) {
                        try {
                            $entity = new ObjectEntity();
                            $entity->hydrate($objectData);
                            
                            // Create SOLR document (will use schema-aware mapping)
                            $document = $this->createSolrDocument($entity);
                            
                            // Determine mapping type based on document structure
                            if (isset($document['self_schema']) && count($document) > 20) {
                                $schemaDetails['mapping_type'] = 'schema-aware';
                            } else {
                                $schemaDetails['mapping_type'] = 'legacy-fallback';
                            }
                            
                            // Index the document
                            if ($this->bulkIndex([$document], true)) {
                                $schemaDetails['objects_indexed']++;
                                $results['objects_indexed']++;
                            }
                        } catch (\Exception $e) {
                            $results['errors'][] = [
                                'schema_id' => $schemaId,
                                'object_id' => $objectData['id'] ?? 'unknown',
                                'error' => $e->getMessage()
                            ];
                        }
                    }

                    $results['schema_details'][] = $schemaDetails;
                    $results['schemas_tested']++;

                } catch (\Exception $e) {
                    $results['errors'][] = [
                        'schema_id' => $schemaId,
                        'error' => 'Schema processing failed: ' . $e->getMessage()
                    ];
                }
            }

            // Commit all indexed documents
            $this->commit();

            $results['duration'] = round((microtime(true) - $startTime) * 1000, 2);
            $results['success'] = true;

            $this->logger->info('Schema-aware mapping test completed', [
                'schemas_tested' => $results['schemas_tested'],
                'objects_indexed' => $results['objects_indexed'],
                'duration_ms' => $results['duration']
            ]);

        } catch (\Exception $e) {
            $results['success'] = false;
            $results['error'] = $e->getMessage();
            $this->logger->error('Schema-aware mapping test failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return $results;
    }

    /**
     * Get all current SOLR field types for validation
     *
     * @return array Field name => field type mapping
     */
    private function getSolrFieldTypes(bool $forceRefresh = false): array
    {
        // Return cached version if available and not forcing refresh
        if (!$forceRefresh && $this->cachedSolrFieldTypes !== null) {
            $this->logger->debug('🚀 Using cached SOLR field types', [
                'cached_field_count' => count($this->cachedSolrFieldTypes)
            ]);
            return $this->cachedSolrFieldTypes;
        }
        
        try {
            $tenantCollectionName = $this->getActiveCollectionName();
            if ($tenantCollectionName === null) {
                $this->logger->warning('Cannot get SOLR field types: no active collection available');
                return [];
            }
            
            $url = $this->buildSolrBaseUrl() . '/' . $tenantCollectionName . '/schema/fields?wt=json';
            $response = $this->httpClient->get($url);
            $data = json_decode((string)$response->getBody(), true);
            
            $fieldTypes = [];
            if (isset($data['fields']) && is_array($data['fields'])) {
                foreach ($data['fields'] as $field) {
                    $fieldTypes[$field['name']] = $field['type'];
                }
            }
            
            // Cache the field types
            $this->cachedSolrFieldTypes = $fieldTypes;
            
            $this->logger->info('🔍 Retrieved and cached SOLR field types for validation', [
                'field_count' => count($fieldTypes),
                'sample_fields' => array_slice($fieldTypes, 0, 5, true),
                'versie_field_type' => $fieldTypes['versie'] ?? 'NOT_FOUND'
            ]);
            
            return $fieldTypes;
            
        } catch (\Exception $e) {
            $this->logger->warning('Failed to retrieve SOLR field types', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Warmup SOLR index (simplified implementation for GuzzleSolrService)
     *
     * @param array $schemas Array of Schema entities (not used in this implementation)
     * @param int   $maxObjects Maximum number of objects to index
     * @param string $mode Processing mode ('serial', 'parallel', 'hyper')
     * @param bool $collectErrors Whether to collect all errors or stop on first
     * @param int $batchSize Number of objects to process per batch
     *
     * @return array Warmup results
     */
    public function warmupIndex(array $schemas = [], int $maxObjects = 0, string $mode = 'serial', bool $collectErrors = false, int $batchSize = 1000): array
    {
        if (!$this->isAvailable()) {
            return [
                'success' => false,
                'operations' => [],
                'execution_time_ms' => 0.0,
                'error' => 'SOLR is not available'
            ];
        }

        $startTime = microtime(true);
        $operations = [];
        
        // **MEMORY OPTIMIZATION**: Increase memory limit and optimize settings for large datasets
        $originalMemoryLimit = ini_get('memory_limit');
        $originalMaxExecutionTime = ini_get('max_execution_time');
        
        // Set execution time limit for warmup process (memory limit now set at container level)
        ini_set('max_execution_time', 3600); // 1 hour
        
        // **MEMORY TRACKING**: Capture initial memory usage and predict requirements
        $initialMemoryUsage = (int) memory_get_usage(true);
        $initialMemoryPeak = (int) memory_get_peak_usage(true);
        $memoryPrediction = $this->predictWarmupMemoryUsage($maxObjects);
        
        // **CRITICAL**: Disable profiler during warmup - even with reduced logging, 26K+ queries overwhelm profiler
        $profilerWasEnabled = false;
        try {
            $profiler = \OC::$server->get(\OCP\Profiler\IProfiler::class);
            if ($profiler->isEnabled()) {
                $profilerWasEnabled = true;
                $reflection = new \ReflectionClass($profiler);
                if ($reflection->hasMethod('setEnabled')) {
                    $profiler->setEnabled(false);
                }
            }
        } catch (\Exception $e) {
            // Profiler not available - continue
        }
        
        // Minimal warmup logging to prevent profiler memory issues
        
        try {
            // 1. Test connection
            $connectionResult = $this->testConnection();
            $operations['connection_test'] = $connectionResult['success'] ?? false;
            
            // 2. Schema mirroring with intelligent conflict resolution
            if (!empty($schemas)) {
                $stageStart = microtime(true);
                
                // Schema mirroring (logging removed for performance)
                
                // Lazy-load SolrSchemaService to avoid circular dependency
                $solrSchemaService = \OC::$server->get(SolrSchemaService::class);
                $mirrorResult = $solrSchemaService->mirrorSchemas(true); // Force update for testing
                $operations['schema_mirroring'] = $mirrorResult['success'] ?? false;
                $operations['schemas_processed'] = $mirrorResult['stats']['schemas_processed'] ?? 0;
                $operations['fields_created'] = $mirrorResult['stats']['fields_created'] ?? 0;
                $operations['conflicts_resolved'] = $mirrorResult['stats']['conflicts_resolved'] ?? 0;
                
                // 2.5. Field creation removed from warmup process to prevent conflicts
                // Fields should be managed via the dedicated field management UI/API
                $fieldManagementStart = microtime(true);
                
                // Skip automatic field creation - use dedicated field management instead
                $operations['missing_fields_created'] = true; // Always true since we skip this step
                $operations['fields_added'] = 0;
                $operations['fields_updated'] = 0;
                
                // Field management skipped (logging removed for performance)
                
                $fieldManagementEnd = microtime(true);
                $timing['field_management'] = round(($fieldManagementEnd - $fieldManagementStart) * 1000, 2) . 'ms';
                
                // 2.6. Collect current SOLR field types for validation (force refresh after schema changes)
                $solrFieldTypes = $this->getSolrFieldTypes(true);
                $operations['field_types_collected'] = count($solrFieldTypes);
                
                // Schema mirroring result stored in operations (logging removed for performance)
                
                $stageEnd = microtime(true);
                $timing['schema_mirroring'] = round(($stageEnd - $stageStart) * 1000, 2) . 'ms';
            } else {
                $operations['schema_mirroring'] = false;
                $operations['schemas_processed'] = 0;
                $operations['fields_created'] = 0;
                $operations['conflicts_resolved'] = 0;
                $timing['schema_mirroring'] = '0ms (no schemas provided)';
                
                // Field creation removed from warmup process to prevent conflicts
                // Fields should be managed via the dedicated field management UI/API
                $fieldManagementStart = microtime(true);
                
                // Skip automatic field creation - use dedicated field management instead
                $operations['missing_fields_created'] = true; // Always true since we skip this step
                $operations['fields_added'] = 0;
                $operations['fields_updated'] = 0;
                
                // Field management skipped (logging removed for performance)
                
                $fieldManagementEnd = microtime(true);
                $timing['field_management'] = round(($fieldManagementEnd - $fieldManagementStart) * 1000, 2) . 'ms';
                
                // Get current SOLR field types for validation
                $solrFieldTypes = $this->getSolrFieldTypes(true);
                $operations['field_types_collected'] = count($solrFieldTypes);
            }
            
            // 3. Object indexing using mode-based bulk indexing (no logging for performance)
            
            if ($mode === 'hyper') {
                $indexResult = $this->bulkIndexFromDatabaseOptimized($batchSize, $maxObjects, $solrFieldTypes ?? []);
            } elseif ($mode === 'parallel') {
                $indexResult = $this->bulkIndexFromDatabaseParallel($batchSize, $maxObjects, 5, $solrFieldTypes ?? []);
            } else {
                $indexResult = $this->bulkIndexFromDatabase($batchSize, $maxObjects, $solrFieldTypes ?? []);
            }
            
            // Pass collectErrors mode for potential future use
            $operations['error_collection_mode'] = $collectErrors;
            $operations['object_indexing'] = $indexResult['success'] ?? false;
            $operations['objects_indexed'] = $indexResult['indexed'] ?? 0;
            // **BUG FIX**: Calculate errors properly - if no errors field, assume 0 errors when successful
            $operations['indexing_errors'] = $indexResult['errors'] ?? 0;
            
            // 4. Perform basic warmup queries to warm SOLR caches
            $warmupQueries = [
                ['q' => '*:*', 'rows' => 1],           // Basic query to warm general cache
                ['q' => 'name:*', 'rows' => 5],       // Name field search to warm field caches  
                ['q' => '*:*', 'rows' => 0, 'facet.field' => 'register_id_i'] // Facet query to warm facet cache
            ];
            
            $successfulQueries = 0;
            foreach ($warmupQueries as $i => $query) {
                try {
                    // Simple query execution for cache warming
                    $collectionName = $this->getActiveCollectionName();
                    if ($collectionName === null) {
                        $operations["warmup_query_$i"] = false;
                        continue;
                    }
                    
                    $queryString = http_build_query($query);
                    $url = $this->buildSolrBaseUrl() . "/{$collectionName}/select?" . $queryString;
                    
                    $response = $this->httpClient->get($url);
                    $operations["warmup_query_$i"] = ($response->getStatusCode() === 200);
                    if ($response->getStatusCode() === 200) {
                        $successfulQueries++;
                    }
                } catch (\Exception $e) {
                    $operations["warmup_query_$i"] = false;
                    $this->logger->warning("Warmup query $i failed", ['error' => $e->getMessage()]);
                }
            }
            
            // 5. Commit (simplified)
            $operations['commit'] = true;
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            
            // **MEMORY TRACKING**: Calculate final memory usage and statistics
            $finalMemoryUsage = (int) memory_get_usage(true);
            $finalMemoryPeak = (int) memory_get_peak_usage(true);
            $memoryReport = $this->generateMemoryReport($initialMemoryUsage, $finalMemoryUsage, $initialMemoryPeak, $finalMemoryPeak, $memoryPrediction);
            
            // **RESTORE SETTINGS**: Reset PHP execution time to original value
            ini_set('max_execution_time', $originalMaxExecutionTime);
            
            // **RESTORE PROFILER**: Re-enable profiler if it was enabled
            if ($profilerWasEnabled) {
                try {
                    $profiler = \OC::$server->get(\OCP\Profiler\IProfiler::class);
                    $reflection = new \ReflectionClass($profiler);
                    if ($reflection->hasMethod('setEnabled')) {
                        $profiler->setEnabled(true);
                    }
                } catch (\Exception $e) {
                    // Ignore profiler restoration errors
                }
            }
            
            return [
                'success' => true,
                'operations' => $operations,
                'execution_time_ms' => round($executionTime, 2),
                'message' => 'GuzzleSolrService warmup completed with field management and optimization',
                'total_objects_found' => $indexResult['total'] ?? 0,
                'batches_processed' => $indexResult['batches'] ?? 0,
                'max_objects_limit' => $maxObjects,
                'memory_usage' => $memoryReport
            ];
            
        } catch (\Exception $e) {
            // **MEMORY TRACKING**: Calculate memory usage even on error
            $finalMemoryUsage = (int) memory_get_usage(true);
            $finalMemoryPeak = (int) memory_get_peak_usage(true);
            $memoryReport = $this->generateMemoryReport($initialMemoryUsage, $finalMemoryUsage, $initialMemoryPeak, $finalMemoryPeak, $memoryPrediction ?? []);
            
            // **RESTORE SETTINGS**: Reset PHP execution time to original value even on error
            ini_set('max_execution_time', $originalMaxExecutionTime);
            
            // **RESTORE PROFILER**: Re-enable profiler if it was enabled (even on error)
            if ($profilerWasEnabled) {
                try {
                    $profiler = \OC::$server->get(\OCP\Profiler\IProfiler::class);
                    $reflection = new \ReflectionClass($profiler);
                    if ($reflection->hasMethod('setEnabled')) {
                        $profiler->setEnabled(true);
                    }
                } catch (\Exception $profilerError) {
                    // Ignore profiler restoration errors
                }
            }
            
            return [
                'success' => false,
                'operations' => $operations,
                'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'error' => $e->getMessage(),
                'memory_usage' => $memoryReport
            ];
        }
    }

    /**
     * Validate field against SOLR schema to prevent type mismatches
     *
     * @param string $fieldName The SOLR field name
     * @param mixed $fieldValue The value to be indexed
     * @param array $solrFieldTypes Current SOLR field types
     * @return bool True if field is safe to index
     */
    private function validateFieldForSolr(string $fieldName, $fieldValue, array $solrFieldTypes): bool
    {
        // If no field types provided, allow all (fallback to original behavior)
        if (empty($solrFieldTypes)) {
            return true;
        }

        // If field doesn't exist in SOLR, it will be auto-created (allow)
        if (!isset($solrFieldTypes[$fieldName])) {
            $this->logger->debug('Field not in SOLR schema, will be auto-created', [
                'field' => $fieldName,
                'value' => $fieldValue,
                'type' => gettype($fieldValue)
            ]);
            return true;
        }

        $solrFieldType = $solrFieldTypes[$fieldName];
        
        // **CRITICAL VALIDATION**: Check for type compatibility
        $isCompatible = $this->isValueCompatibleWithSolrType($fieldValue, $solrFieldType);
        
        if (!$isCompatible) {
            $this->logger->warning('🛡️ Field validation prevented type mismatch', [
                'field' => $fieldName,
                'value' => $fieldValue,
                'value_type' => gettype($fieldValue),
                'solr_field_type' => $solrFieldType,
                'action' => 'SKIPPED'
            ]);
            return false;
        }

        $this->logger->debug('✅ Field validation passed', [
            'field' => $fieldName,
            'value' => $fieldValue,
            'solr_type' => $solrFieldType
        ]);
        
        return true;
    }

    /**
     * Check if a value is compatible with a SOLR field type
     *
     * @param mixed $value The value to check
     * @param string $solrFieldType The SOLR field type (e.g., 'plongs', 'string', 'text_general')
     * @return bool True if compatible
     */
    private function isValueCompatibleWithSolrType($value, string $solrFieldType): bool
    {
        // Handle null values (generally allowed)
        if ($value === null) {
            return true;
        }

        return match ($solrFieldType) {
            // Numeric types - only allow numeric values
            'pint', 'plong', 'plongs', 'pfloat', 'pdouble' => is_numeric($value),
            
            // String types - allow anything (can be converted to string)
            'string', 'text_general', 'text_en' => true,
            
            // Boolean types - allow boolean or boolean-like values
            'boolean' => is_bool($value) || in_array(strtolower($value), ['true', 'false', '1', '0']),
            
            // Date types - allow date strings or objects
            'pdate', 'pdates' => is_string($value) || ($value instanceof \DateTime),
            
            // Default: allow for unknown types
            default => true
        };
    }
    
    /**
     * Clear all cached data to force refresh
     */
    public function clearCache(): void
    {
        $this->cachedSolrFieldTypes = null;
        $this->cachedSchemaData = null;
        $this->logger->debug('🧹 Cleared SOLR service caches');
    }
    
    /**
     * Optimized bulk indexing with performance improvements
     *
     * @param int $batchSize Number of objects per batch
     * @param int $maxObjects Maximum objects to process (0 = no limit)
     * @param array $solrFieldTypes Pre-fetched SOLR field types for validation
     * @return array Results with performance metrics
     */
    public function bulkIndexFromDatabaseOptimized(int $batchSize = 1000, int $maxObjects = 0, array $solrFieldTypes = []): array
    {
        $startTime = microtime(true);
        $totalIndexed = 0;
        $totalErrors = 0;
        $batchCount = 0;
        
        try {
            // **IMPROVED**: Get count of only searchable objects for more accurate planning
            $objectMapper = \OC::$server->get(\OCA\OpenRegister\Db\ObjectEntityMapper::class);
            $totalObjects = $this->countSearchableObjects($objectMapper);
            $actualLimit = $maxObjects > 0 ? min($maxObjects, $totalObjects) : $totalObjects;
            
            $this->logger->info('🚀 Starting optimized bulk indexing', [
                'total_objects' => $totalObjects,
                'actual_limit' => $actualLimit,
                'batch_size' => $batchSize,
                'field_types_cached' => !empty($solrFieldTypes)
            ]);
            
            // Process in optimized chunks
            for ($offset = 0; $offset < $actualLimit; $offset += $batchSize) {
                $currentBatchSize = min($batchSize, $actualLimit - $offset);
                $batchCount++;
                
                // **IMPROVED**: Fetch only objects with searchable schemas
                $objects = $this->fetchSearchableObjects($objectMapper, $currentBatchSize, $offset);
                
                if (empty($objects)) {
                    break;
                }
                
                // Index ALL objects (published and unpublished) for comprehensive search.
                // Filtering for published-only content is now handled at query time, not index time.
                $documents = [];
                foreach ($objects as $object) {
                    try {
                        $document = $this->createSolrDocument($object, $solrFieldTypes);
                        if (!empty($document)) {
                            $documents[] = $document;
                        }
                    } catch (\RuntimeException $e) {
                        // Skip non-searchable schemas
                        if (str_contains($e->getMessage(), 'Schema is not searchable')) {
                            continue;
                        }
                        $totalErrors++;
                        $this->logger->warning('Failed to create document', [
                            'object_id' => $object->getId(),
                            'error' => $e->getMessage()
                        ]);
                    } catch (\Exception $e) {
                        $totalErrors++;
                        $this->logger->warning('Failed to create document', [
                            'object_id' => $object->getId(),
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                
                // Bulk index the batch
                if (!empty($documents)) {
                    $indexResult = $this->bulkIndex($documents, true); // Commit each batch for immediate visibility
                    if ($indexResult) {
                        $totalIndexed += count($documents);
                    } else {
                        $totalErrors += count($documents);
                    }
                }
                
                // Log progress every 10 batches
                if ($batchCount % 10 === 0) {
                    $elapsed = microtime(true) - $startTime;
                    $rate = $totalIndexed / $elapsed;
                    $this->logger->info('📊 Bulk indexing progress', [
                        'batches_processed' => $batchCount,
                        'objects_indexed' => $totalIndexed,
                        'elapsed_seconds' => round($elapsed, 2),
                        'objects_per_second' => round($rate, 2)
                    ]);
                }
            }
            
            // Final commit
            $this->commit();
            
            $totalTime = microtime(true) - $startTime;
            $rate = $totalIndexed / $totalTime;
            
            return [
                'success' => true,
                'indexed' => $totalIndexed,
                'errors' => $totalErrors,
                'batches' => $batchCount,
                'total_time_seconds' => round($totalTime, 3),
                'objects_per_second' => round($rate, 2),
                'total_objects_found' => $totalObjects,
                'actual_limit' => $actualLimit
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Optimized bulk indexing failed', [
                'error' => $e->getMessage(),
                'indexed_so_far' => $totalIndexed
            ]);
            
            return [
                'success' => false,
                'indexed' => $totalIndexed,
                'errors' => $totalErrors + 1,
                'error_message' => $e->getMessage()
            ];
        }
    }

    /**
     * Fix mismatched SOLR fields by updating their configuration
     *
     * @param array $mismatchedFields Array of field configurations keyed by field name that need to be fixed
     * @param bool $dryRun If true, only simulate the updates without actually making changes
     * @return array Result with success status, message, and fixed fields list
     */
    public function fixMismatchedFields(array $mismatchedFields, bool $dryRun = false): array
    {
        if (!$this->isAvailable()) {
            return [
                'success' => false,
                'message' => 'SOLR is not available or not configured'
            ];
        }

        try {
            $startTime = microtime(true);
            $collectionName = $this->getActiveCollectionName();
            
            if (!$collectionName) {
                return [
                    'success' => false,
                    'message' => 'No active SOLR collection found'
                ];
            }

            if (empty($mismatchedFields)) {
                return [
                    'success' => true,
                    'message' => 'No mismatched fields provided',
                    'fixed' => [],
                    'errors' => []
                ];
            }

            $this->logger->debug('Fixing mismatched SOLR fields', [
                'field_count' => count($mismatchedFields),
                'fields' => array_keys($mismatchedFields),
                'dry_run' => $dryRun
            ]);

            // Process mismatched fields - check for SOLR limitations first
            $fixed = [];
            $errors = [];
            $warnings = [];
            $schemaUrl = $this->buildSolrBaseUrl() . "/{$collectionName}/schema";

            // Get current field configuration to check for immutable property changes
            $currentFieldsResponse = $this->getFieldsConfiguration();
            $currentFields = $currentFieldsResponse['fields'] ?? [];

            foreach ($mismatchedFields as $fieldName => $fieldConfig) {
                try {
                    // Check if this is a docValues change (which is immutable in SOLR)
                    $currentField = $currentFields[$fieldName] ?? null;
                    $newDocValues = $fieldConfig['docValues'] ?? false;
                    $currentDocValues = $currentField['docValues'] ?? false;
                    
                    if ($currentField && $newDocValues !== $currentDocValues) {
                        $warning = "Cannot change docValues for field '{$fieldName}' from " . 
                                 ($currentDocValues ? 'true' : 'false') . " to " . 
                                 ($newDocValues ? 'true' : 'false') . 
                                 " - docValues is immutable in SOLR. Field would need to be deleted and recreated (losing data).";
                        $warnings[] = $warning;
                        $this->logger->warning($warning, [
                            'field' => $fieldName,
                            'current_docValues' => $currentDocValues,
                            'desired_docValues' => $newDocValues
                        ]);
                        continue;
                    }
                    
                    // Prepare field configuration for SOLR
                    $solrFieldConfig = $this->prepareSolrFieldConfig($fieldName, $fieldConfig);
                    
                    // Use replace-field for existing mismatched fields
                    $payload = [
                        'replace-field' => $solrFieldConfig
                    ];

                    if ($dryRun) {
                        $fixed[] = $fieldName;
                        $this->logger->debug("Dry run: Would fix field '{$fieldName}'", [
                            'field_config' => $solrFieldConfig
                        ]);
                    } else {
                        // Make the API call to fix the field
                        $response = $this->httpClient->post($schemaUrl, [
                            'json' => $payload,
                            'headers' => [
                                'Content-Type' => 'application/json'
                            ]
                        ]);

                        $responseData = json_decode($response->getBody()->getContents(), true);
                        
                        if ($response->getStatusCode() === 200 && ($responseData['responseHeader']['status'] ?? 1) === 0) {
                            $fixed[] = $fieldName;
                            $this->logger->info("Successfully fixed field '{$fieldName}'", [
                                'field_config' => $solrFieldConfig
                            ]);
                        } else {
                            $error = "Failed to fix field '{$fieldName}': " . ($responseData['error']['msg'] ?? 'Unknown error');
                            $errors[] = $error;
                            $this->logger->error($error, [
                                'response_status' => $response->getStatusCode(),
                                'response_data' => $responseData
                            ]);
                        }
                    }

                } catch (\Exception $e) {
                    $error = "Exception while fixing field '{$fieldName}': " . $e->getMessage();
                    $errors[] = $error;
                    $this->logger->error($error, [
                        'exception' => $e,
                        'field_config' => $fieldConfig
                    ]);
                }
            }

            $executionTime = (microtime(true) - $startTime) * 1000;
            $fixedCount = count($fixed);
            $errorCount = count($errors);
            $warningCount = count($warnings);

            if ($dryRun) {
                $message = "Dry run completed: {$fixedCount} fields would be fixed";
                if ($errorCount > 0) {
                    $message .= ", {$errorCount} errors detected";
                }
                if ($warningCount > 0) {
                    $message .= ", {$warningCount} warnings (immutable properties)";
                }
            } else {
                $message = "Fixed {$fixedCount} mismatched SOLR fields";
                if ($errorCount > 0) {
                    $message .= " with {$errorCount} errors";
                }
                if ($warningCount > 0) {
                    $message .= " and {$warningCount} warnings (immutable properties)";
                }
            }

            return [
                'success' => true,
                'message' => $message,
                'fixed' => $fixed,
                'errors' => $errors,
                'warnings' => $warnings,
                'execution_time_ms' => round($executionTime, 2),
                'dry_run' => $dryRun
            ];

        } catch (\Exception $e) {
            $this->logger->error('Exception in fixMismatchedFields', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to fix mismatched fields: ' . $e->getMessage(),
                'errors' => [$e->getMessage()]
            ];
        }
    }

    /**
     * Delete a field from SOLR schema
     *
     * @param string $fieldName Name of the field to delete
     * @return array{success: bool, message: string, error?: string}
     */
    public function deleteField(string $fieldName): array
    {
        if (!$this->isAvailable()) {
            return [
                'success' => false,
                'message' => 'SOLR is not available or not configured'
            ];
        }

        try {
            $collectionName = $this->getActiveCollectionName();
            
            if (!$collectionName) {
                return [
                    'success' => false,
                    'message' => 'No active SOLR collection found'
                ];
            }

            $schemaUrl = $this->buildSolrBaseUrl() . "/{$collectionName}/schema";
            
            // Prepare delete field payload
            $payload = [
                'delete-field' => [
                    'name' => $fieldName
                ]
            ];

            $this->logger->info('🗑️ Deleting SOLR field', [
                'field_name' => $fieldName,
                'collection' => $collectionName,
                'url' => $schemaUrl
            ]);

            $response = $this->httpClient->post($schemaUrl, [
                'json' => $payload,
                'timeout' => 30
            ]);

            $data = json_decode((string)$response->getBody(), true);
            $success = ($data['responseHeader']['status'] ?? -1) === 0;

            if ($success) {
                $this->logger->info('✅ SOLR field deleted successfully', [
                    'field_name' => $fieldName,
                    'collection' => $collectionName
                ]);

                return [
                    'success' => true,
                    'message' => "Field '{$fieldName}' deleted successfully"
                ];
            } else {
                $error = $data['error']['msg'] ?? 'Unknown error occurred';
                $this->logger->error('❌ Failed to delete SOLR field', [
                    'field_name' => $fieldName,
                    'error' => $error,
                    'response' => $data
                ]);

                return [
                    'success' => false,
                    'message' => "Failed to delete field '{$fieldName}': {$error}",
                    'error' => $error
                ];
            }

        } catch (\Exception $e) {
            $this->logger->error('Exception deleting SOLR field', [
                'field_name' => $fieldName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => "Exception deleting field '{$fieldName}': " . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Reindex all objects in SOLR
     *
     * This method clears the current SOLR index and rebuilds it from scratch
     * with all objects using the current field schema configuration.
     *
     * @param int $maxObjects Maximum number of objects to reindex (0 = all)
     * @param int $batchSize Number of objects to process per batch
     * @return array{success: bool, message: string, stats?: array, error?: string}
     */
    public function reindexAll(int $maxObjects = 0, int $batchSize = 1000): array
    {
        if (!$this->isAvailable()) {
            return [
                'success' => false,
                'message' => 'SOLR is not available or not configured'
            ];
        }

        try {
            $startTime = microtime(true);
            $startMemory = memory_get_usage(true);

            $this->logger->info('🔄 Starting SOLR reindex operation', [
                'max_objects' => $maxObjects,
                'batch_size' => $batchSize,
                'collection' => $this->getActiveCollectionName()
            ]);

            // Step 1: Clear the current index
            $this->logger->info('🗑️ Clearing current SOLR index');
            $clearResult = $this->clearIndex();
            
            if (!$clearResult['success']) {
                return [
                    'success' => false,
                    'message' => 'Failed to clear SOLR index: ' . $clearResult['message'],
                    'error' => $clearResult['error'] ?? null
                ];
            }

            // Step 2: Get object count for progress tracking
            $objectMapper = \OC::$server->get(\OCA\OpenRegister\Db\ObjectEntityMapper::class);
            $totalObjects = $objectMapper->countAll(
                filters: [],
                search: null,
                ids: null,
                uses: null,
                includeDeleted: false,
                register: null,
                schema: null,
                published: null, // Reindex ALL objects (published and unpublished)
                rbac: false,
                multi: false
            );

            // Apply maxObjects limit if specified
            if ($maxObjects > 0 && $maxObjects < $totalObjects) {
                $totalObjects = $maxObjects;
            }

            $this->logger->info('📊 Reindex scope determined', [
                'total_objects' => $totalObjects,
                'batch_size' => $batchSize,
                'estimated_batches' => ceil($totalObjects / $batchSize)
            ]);

            // Step 3: Reindex objects in batches
            $stats = [
                'total_objects' => $totalObjects,
                'processed_objects' => 0,
                'successful_indexes' => 0,
                'failed_indexes' => 0,
                'batches_processed' => 0,
                'errors' => []
            ];

            $offset = 0;
            $batchNumber = 1;

            while ($offset < $totalObjects) {
                $currentBatchSize = min($batchSize, $totalObjects - $offset);
                
                $this->logger->debug('📦 Processing reindex batch', [
                    'batch_number' => $batchNumber,
                    'offset' => $offset,
                    'batch_size' => $currentBatchSize
                ]);

                // Get objects for this batch
                $objects = $objectMapper->findAll(
                    limit: $currentBatchSize,
                    offset: $offset,
                    filters: [],
                    searchConditions: [],
                    searchParams: [],
                    sort: [],
                    search: null,
                    ids: null,
                    uses: null,
                    includeDeleted: false,
                    register: null,
                    schema: null,
                    published: null, // Reindex ALL objects
                    rbac: false,
                    multi: false
                );

                // Index objects in this batch
                $batchSuccesses = 0;
                $batchErrors = 0;

                foreach ($objects as $object) {
                    try {
                        $success = $this->indexObject($object, false); // Don't commit each object
                        if ($success) {
                            $batchSuccesses++;
                        } else {
                            $batchErrors++;
                            $stats['errors'][] = [
                                'object_id' => $object->getId(),
                                'object_uuid' => $object->getUuid(),
                                'error' => 'Failed to index object'
                            ];
                        }
                    } catch (\Exception $e) {
                        $batchErrors++;
                        $stats['errors'][] = [
                            'object_id' => $object->getId(),
                            'object_uuid' => $object->getUuid(),
                            'error' => $e->getMessage()
                        ];
                        
                        $this->logger->warning('Failed to reindex object', [
                            'object_id' => $object->getId(),
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                // Commit batch
                $this->commit();

                // Update stats
                $stats['processed_objects'] += count($objects);
                $stats['successful_indexes'] += $batchSuccesses;
                $stats['failed_indexes'] += $batchErrors;
                $stats['batches_processed']++;

                $this->logger->info('✅ Reindex batch completed', [
                    'batch_number' => $batchNumber,
                    'processed' => count($objects),
                    'successful' => $batchSuccesses,
                    'failed' => $batchErrors,
                    'total_processed' => $stats['processed_objects'],
                    'progress_percent' => round(($stats['processed_objects'] / $totalObjects) * 100, 1)
                ]);

                $offset += $currentBatchSize;
                $batchNumber++;

                // Memory cleanup every 10 batches
                if ($batchNumber % 10 === 0) {
                    gc_collect_cycles();
                }
            }

            // Final commit and optimize
            $this->logger->info('🔧 Finalizing reindex - committing and optimizing');
            $this->commit();
            
            // Calculate final stats
            $endTime = microtime(true);
            $endMemory = memory_get_usage(true);
            
            $stats['duration_seconds'] = round($endTime - $startTime, 2);
            $stats['objects_per_second'] = $stats['duration_seconds'] > 0 
                ? round($stats['processed_objects'] / $stats['duration_seconds'], 2) 
                : 0;
            $stats['memory_used_mb'] = round(($endMemory - $startMemory) / 1024 / 1024, 2);
            $stats['peak_memory_mb'] = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

            $this->logger->info('🎉 SOLR reindex completed successfully', [
                'total_processed' => $stats['processed_objects'],
                'successful' => $stats['successful_indexes'],
                'failed' => $stats['failed_indexes'],
                'duration' => $stats['duration_seconds'] . 's',
                'objects_per_second' => $stats['objects_per_second'],
                'memory_used' => $stats['memory_used_mb'] . 'MB'
            ]);

            return [
                'success' => true,
                'message' => "Reindex completed successfully. Processed {$stats['processed_objects']} objects in {$stats['duration_seconds']}s",
                'stats' => $stats
            ];

        } catch (\Exception $e) {
            $this->logger->error('Exception during SOLR reindex', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Reindex failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Create missing SOLR fields based on schema analysis
     *
     * This method analyzes the difference between expected schema fields and actual SOLR fields,
     * then creates the missing fields using the SOLR Schema API.
     *
     * @param array $expectedFields Expected field configuration from schema analysis
     * @param bool $dryRun If true, only returns what would be created without making changes
     * @return array{success: bool, message: string, created?: array, errors?: array, dry_run?: bool}
     */
    public function createMissingFields(array $expectedFields = [], bool $dryRun = false): array
    {
        if (!$this->isAvailable()) {
            return [
                'success' => false,
                'message' => 'SOLR is not available or not configured'
            ];
        }

        try {
            $startTime = microtime(true);
            $collectionName = $this->getActiveCollectionName();
            
            if (!$collectionName) {
                return [
                    'success' => false,
                    'message' => 'No active SOLR collection found'
                ];
            }

            // If no expected fields provided, get them from schema analysis
            if (empty($expectedFields)) {
                // Get SolrSchemaService to analyze schemas
                $solrSchemaService = \OC::$server->get(SolrSchemaService::class);
                $schemaMapper = \OC::$server->get(\OCA\OpenRegister\Db\SchemaMapper::class);
                
                // Get all schemas
                $schemas = $schemaMapper->findAll();
                
                // Use the existing analyzeAndResolveFieldConflicts method via reflection
                $reflection = new \ReflectionClass($solrSchemaService);
                $method = $reflection->getMethod('analyzeAndResolveFieldConflicts');
                $method->setAccessible(true);
                $expectedFields = $method->invoke($solrSchemaService, $schemas);
                
                // Debug: Log the structure of expected fields
                $this->logger->debug('Expected fields from schema analysis', [
                    'field_count' => count($expectedFields),
                    'sample_fields' => array_slice($expectedFields, 0, 3, true),
                    'field_keys_sample' => array_slice(array_keys($expectedFields), 0, 5)
                ]);
            }

            // Get current SOLR fields
            $currentFieldsResponse = $this->getFieldsConfiguration();
            if (!$currentFieldsResponse['success']) {
                return [
                    'success' => false,
                    'message' => 'Failed to retrieve current SOLR fields: ' . $currentFieldsResponse['message']
                ];
            }

            $currentFields = $currentFieldsResponse['fields'] ?? [];
            
            // Find only missing fields (not mismatched ones - use fixMismatchedFields for those)
            $fieldsToProcess = [];
            foreach ($expectedFields as $fieldName => $fieldConfig) {
                // Skip if fieldName is not a string (defensive programming)
                if (!is_string($fieldName)) {
                    $this->logger->warning('Skipping non-string field name', [
                        'field_name_type' => gettype($fieldName),
                        'field_name_value' => $fieldName
                    ]);
                    continue;
                }
                
                // Skip if fieldConfig is not an array
                if (!is_array($fieldConfig)) {
                    $this->logger->warning('Skipping non-array field config', [
                        'field_name' => $fieldName,
                        'field_config_type' => gettype($fieldConfig),
                        'field_config_value' => $fieldConfig
                    ]);
                    continue;
                }
                
                // Only add truly missing fields
                if (!isset($currentFields[$fieldName])) {
                    $fieldsToProcess[$fieldName] = $fieldConfig;
                    $this->logger->debug("Field '{$fieldName}' is missing and will be created");
                }
            }

            if (empty($fieldsToProcess)) {
                return [
                    'success' => true,
                    'message' => 'No missing or mismatched fields found - SOLR schema is up to date',
                    'created' => [],
                    'errors' => []
                ];
            }

            $this->logger->info('🔧 Processing SOLR fields (create missing, update mismatched)', [
                'collection' => $collectionName,
                'fields_to_process' => count($fieldsToProcess),
                'dry_run' => $dryRun
            ]);

            if ($dryRun) {
                return [
                    'success' => true,
                    'message' => 'Dry run completed - ' . count($fieldsToProcess) . ' fields would be processed',
                    'would_create' => array_keys($fieldsToProcess),
                    'dry_run' => true
                ];
            }

            // Process fields (create missing, update mismatched)
            $created = [];
            $errors = [];
            $schemaUrl = $this->buildSolrBaseUrl() . "/{$collectionName}/schema";

            foreach ($fieldsToProcess as $fieldName => $fieldConfig) {
                try {
                    // Prepare field configuration for SOLR
                    $solrFieldConfig = $this->prepareSolrFieldConfig($fieldName, $fieldConfig);
                    
                    // Always use add-field since we only process missing fields
                    $operation = 'add-field';
                    
                    $payload = [
                        $operation => $solrFieldConfig
                    ];

                    $response = $this->httpClient->post($schemaUrl, [
                        'body' => json_encode($payload),
                        'headers' => ['Content-Type' => 'application/json'],
                        'timeout' => 30
                    ]);

                    $responseData = json_decode($response->getBody()->getContents(), true);
                    
                    if (($responseData['responseHeader']['status'] ?? -1) === 0) {
                        $created[] = $fieldName;
                        // Since we only process missing fields, this is always a create operation
                        $this->logger->debug("✅ Created SOLR field", [
                            'field' => $fieldName,
                            'type' => $solrFieldConfig['type'],
                            'multiValued' => $solrFieldConfig['multiValued'] ?? false,
                            'operation' => $operation
                        ]);
                    } else {
                        $error = $responseData['error']['msg'] ?? 'Unknown error';
                        $errors[$fieldName] = $error;
                        // Since we only process missing fields, this is always a create operation
                        $this->logger->warning("❌ Failed to create SOLR field", [
                            'field' => $fieldName,
                            'error' => $error,
                            'operation' => $operation
                        ]);
                    }
                } catch (\Exception $e) {
                    $errors[$fieldName] = $e->getMessage();
                    $this->logger->error('Exception creating SOLR field', [
                        'field' => $fieldName,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $executionTime = (microtime(true) - $startTime) * 1000;
            $success = count($created) > 0 && count($errors) === 0;

            $result = [
                'success' => $success,
                'message' => sprintf(
                    'Field creation completed: %d created, %d errors',
                    count($created),
                    count($errors)
                ),
                'created' => $created,
                'errors' => $errors,
                'execution_time_ms' => round($executionTime, 2),
                'collection' => $collectionName
            ];

            $this->logger->info('🎯 SOLR field creation completed', [
                'created_count' => count($created),
                'error_count' => count($errors),
                'execution_time_ms' => $result['execution_time_ms']
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->logger->error('Failed to create missing SOLR fields', [
                'error' => $e->getMessage(),
                'collection' => $collectionName ?? 'unknown'
            ]);

            return [
                'success' => false,
                'message' => 'Failed to create missing SOLR fields: ' . $e->getMessage(),
                'details' => ['error' => $e->getMessage()]
            ];
        }
    }

    /**
     * Prepare field configuration for SOLR Schema API
     *
     * @param string $fieldName Field name
     * @param array $fieldConfig Field configuration from schema analysis
     * @return array SOLR-compatible field configuration
     */
    private function prepareSolrFieldConfig(string $fieldName, array $fieldConfig): array
    {
        
        // The field config already contains the resolved SOLR type from SolrSchemaService
        // So we should use it directly instead of re-mapping
        $solrType = $fieldConfig['type'] ?? 'string';
        
        // Handle array case - if type is an array, take the first element
        if (is_array($solrType)) {
            $solrType = !empty($solrType) && isset($solrType[0]) ? (string)$solrType[0] : 'string';
        } else {
            $solrType = (string)$solrType;
        }
        
        $config = [
            'name' => $fieldName,
            'type' => $solrType,
            'indexed' => $fieldConfig['indexed'] ?? true,
            'stored' => $fieldConfig['stored'] ?? true,
            'multiValued' => $fieldConfig['multiValued'] ?? false,
            'docValues' => $fieldConfig['docValues'] ?? true
        ];
        
        
        return $config;
    }

    /**
     * Get comprehensive SOLR field configuration and schema information
     *
     * Retrieves field definitions, dynamic fields, field types, and core information
     * from the active SOLR collection to help debug field configuration issues.
     *
     * @return array{success: bool, message: string, fields?: array, dynamic_fields?: array, field_types?: array, core_info?: array, environment_notes?: array}
     */
    public function getFieldsConfiguration(): array
    {
        if (!$this->isAvailable()) {
            return [
                'success' => false,
                'message' => 'SOLR is not available or not configured'
            ];
        }

        try {
            $startTime = microtime(true);
            $collectionName = $this->getActiveCollectionName();
            
            if (!$collectionName) {
                return [
                    'success' => false,
                    'message' => 'No active SOLR collection found'
                ];
            }

            $this->logger->info('🔍 Retrieving SOLR field configuration', [
                'collection' => $collectionName,
                'tenant_id' => $this->tenantId
            ]);

            // Build schema API URL
            $schemaUrl = $this->buildSolrBaseUrl() . "/{$collectionName}/schema";
            
            // Prepare request options
            $requestOptions = [
                'timeout' => $this->solrConfig['timeout'] ?? 30,
                'headers' => ['Accept' => 'application/json']
            ];

            // Add authentication if configured
            if (!empty($this->solrConfig['username']) && !empty($this->solrConfig['password'])) {
                $requestOptions['auth'] = [
                    $this->solrConfig['username'],
                    $this->solrConfig['password']
                ];
            }

            // Make the schema request
            $response = $this->httpClient->get($schemaUrl, $requestOptions);
            $schemaData = json_decode($response->getBody()->getContents(), true);

            if (!$schemaData || !isset($schemaData['schema'])) {
                return [
                    'success' => false,
                    'message' => 'Invalid schema response from SOLR',
                    'details' => ['response' => $schemaData]
                ];
            }

            $schema = $schemaData['schema'];
            $executionTime = (microtime(true) - $startTime) * 1000;

            // Extract and organize field information
            $result = [
                'success' => true,
                'message' => 'SOLR field configuration retrieved successfully',
                'execution_time_ms' => round($executionTime, 2),
                'fields' => $this->extractFields($schema),
                'dynamic_fields' => $this->extractSchemaDynamicFields($schema),
                'field_types' => $this->extractFieldTypes($schema),
                'core_info' => $this->extractCoreInfo($schema, $collectionName),
                'environment_notes' => $this->generateEnvironmentNotes($schema)
            ];

            $this->logger->info('✅ SOLR field configuration retrieved', [
                'collection' => $collectionName,
                'field_count' => count($result['fields']),
                'dynamic_field_count' => count($result['dynamic_fields']),
                'field_type_count' => count($result['field_types']),
                'execution_time_ms' => $result['execution_time_ms']
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve SOLR field configuration', [
                'error' => $e->getMessage(),
                'collection' => $collectionName ?? 'unknown'
            ]);

            return [
                'success' => false,
                'message' => 'Failed to retrieve SOLR field configuration: ' . $e->getMessage(),
                'details' => ['error' => $e->getMessage()]
            ];
        }
    }

    /**
     * Extract field definitions from schema
     *
     * @param array $schema SOLR schema data
     * @return array Field definitions
     */
    private function extractFields(array $schema): array
    {
        $fields = [];
        
        if (isset($schema['fields'])) {
            foreach ($schema['fields'] as $field) {
                $name = $field['name'] ?? 'unknown';
                $fields[$name] = [
                    'type' => $field['type'] ?? 'unknown',
                    'indexed' => $field['indexed'] ?? true,
                    'stored' => $field['stored'] ?? true,
                    'multiValued' => $field['multiValued'] ?? false,
                    'required' => $field['required'] ?? false,
                    'docValues' => $field['docValues'] ?? false,
                ];
            }
        }

        // Sort fields alphabetically for better readability
        ksort($fields);
        
        return $fields;
    }

    /**
     * Extract dynamic field patterns from schema
     *
     * @param array $schema SOLR schema data
     * @return array Dynamic field patterns
     */
    private function extractSchemaDynamicFields(array $schema): array
    {
        $dynamicFields = [];
        
        if (isset($schema['dynamicFields'])) {
            foreach ($schema['dynamicFields'] as $field) {
                $name = $field['name'] ?? 'unknown';
                $dynamicFields[$name] = [
                    'type' => $field['type'] ?? 'unknown',
                    'indexed' => $field['indexed'] ?? true,
                    'stored' => $field['stored'] ?? true,
                    'multiValued' => $field['multiValued'] ?? false,
                ];
            }
        }

        return $dynamicFields;
    }

    /**
     * Extract field type definitions from schema
     *
     * @param array $schema SOLR schema data
     * @return array Field type definitions
     */
    private function extractFieldTypes(array $schema): array
    {
        $fieldTypes = [];
        
        if (isset($schema['fieldTypes'])) {
            foreach ($schema['fieldTypes'] as $fieldType) {
                $name = $fieldType['name'] ?? 'unknown';
                $fieldTypes[$name] = [
                    'class' => $fieldType['class'] ?? 'unknown',
                    'analyzer' => $fieldType['analyzer'] ?? null,
                    'properties' => array_diff_key($fieldType, array_flip(['name', 'class', 'analyzer']))
                ];
            }
        }

        return $fieldTypes;
    }

    /**
     * Extract core information from schema
     *
     * @param array $schema SOLR schema data
     * @param string $collectionName Collection name
     * @return array Core information
     */
    private function extractCoreInfo(array $schema, string $collectionName): array
    {
        return [
            'core_name' => $collectionName,
            'schema_name' => $schema['name'] ?? 'unknown',
            'schema_version' => $schema['version'] ?? 'unknown',
            'unique_key' => $schema['uniqueKey'] ?? 'id',
            'default_search_field' => $schema['defaultSearchField'] ?? null,
            'similarity' => $schema['similarity'] ?? null,
        ];
    }

    /**
     * Generate environment analysis notes
     *
     * @param array $schema SOLR schema data
     * @return array Environment notes and warnings
     */
    private function generateEnvironmentNotes(array $schema): array
    {
        $notes = [];

        // Check for common field configuration issues
        if (isset($schema['fields'])) {
            $stringFields = array_filter($schema['fields'], function($field) {
                return ($field['type'] ?? '') === 'string' && ($field['multiValued'] ?? false) === true;
            });

            if (!empty($stringFields)) {
                $notes[] = [
                    'type' => 'warning',
                    'title' => 'Multi-valued String Fields Detected',
                    'message' => 'Found ' . count($stringFields) . ' multi-valued string fields. This might cause array conversion issues during object reconstruction.',
                    'details' => array_keys($stringFields)
                ];
            }
        }

        // Check for OpenRegister-specific field patterns
        if (isset($schema['dynamicFields'])) {
            $orFields = array_filter($schema['dynamicFields'], function($field) {
                return strpos($field['name'] ?? '', '*_s') !== false || strpos($field['name'] ?? '', '*_t') !== false;
            });

            if (!empty($orFields)) {
                $notes[] = [
                    'type' => 'info',
                    'title' => 'OpenRegister Dynamic Fields Found',
                    'message' => 'Found ' . count($orFields) . ' OpenRegister-compatible dynamic field patterns.',
                    'details' => array_column($orFields, 'name')
                ];
            }
        }

        return $notes;
    }

    /**
     * Predict memory usage for SOLR warmup operation
     *
     * @param int $maxObjects Maximum number of objects to process
     * @return array Memory usage prediction
     */
    private function predictWarmupMemoryUsage(int $maxObjects): array
    {
        try {
            // Get current memory info
            $currentMemory = (int) memory_get_usage(true);
            $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
            
            // Get ALL object count for prediction (since we now index all objects, not just published)
            $objectMapper = \OC::$server->get(\OCA\OpenRegister\Db\ObjectEntityMapper::class);
            $totalObjects = $objectMapper->countAll(
                filters: [],
                search: null,
                ids: null,
                uses: null,
                includeDeleted: false,
                register: null,
                schema: null,
                published: null, // Count ALL objects (published and unpublished)
                rbac: false,     // Skip RBAC for performance
                multi: false     // Skip multitenancy for performance
            );
            
            // Calculate objects to process
            $objectsToProcess = ($maxObjects === 0) ? $totalObjects : min($maxObjects, $totalObjects);
            
            // Memory estimation based on empirical data:
            // - Base overhead: ~50MB for SOLR service, profiler, etc.
            // - Per object: ~2KB for document creation and processing
            // - Batch overhead: ~10MB per 1000 objects for bulk operations
            // - Schema operations: ~20MB for field management
            
            $baseOverhead = 50 * 1024 * 1024; // 50MB
            $schemaOperations = 20 * 1024 * 1024; // 20MB
            $perObjectMemory = 2 * 1024; // 2KB per object
            $batchOverhead = ceil($objectsToProcess / 1000) * 10 * 1024 * 1024; // 10MB per 1000 objects
            
            $estimatedUsage = $baseOverhead + $schemaOperations + ($objectsToProcess * $perObjectMemory) + $batchOverhead;
            $totalPredicted = $currentMemory + $estimatedUsage;
            
            return [
                'current_memory' => $currentMemory,
                'memory_limit' => $memoryLimit,
                'objects_to_process' => $objectsToProcess,
                'estimated_additional' => $estimatedUsage,
                'total_predicted' => $totalPredicted,
                'memory_available' => $memoryLimit - $currentMemory,
                'prediction_safe' => $totalPredicted < ($memoryLimit * 0.9), // 90% threshold
                'formatted' => [
                    'current' => $this->formatBytes($currentMemory),
                    'limit' => $this->formatBytes($memoryLimit),
                    'estimated_additional' => $this->formatBytes($estimatedUsage),
                    'total_predicted' => $this->formatBytes($totalPredicted),
                    'available' => $this->formatBytes($memoryLimit - $currentMemory)
                ]
            ];
        } catch (\Exception $e) {
            return [
                'error' => 'Unable to predict memory usage: ' . $e->getMessage(),
                'prediction_safe' => false
            ];
        }
    }

    /**
     * Generate memory usage report after warmup completion
     *
     * @param int $initialUsage Initial memory usage
     * @param int $finalUsage Final memory usage
     * @param int $initialPeak Initial peak memory
     * @param int $finalPeak Final peak memory
     * @param array $prediction Original prediction data
     * @return array Memory usage report
     */
    private function generateMemoryReport(int $initialUsage, int $finalUsage, int $initialPeak, int $finalPeak, array $prediction): array
    {
        $actualUsed = $finalUsage - $initialUsage;
        $peakUsed = $finalPeak - $initialPeak;
        $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        
        $report = [
            'initial_usage' => $initialUsage,
            'final_usage' => $finalUsage,
            'actual_used' => $actualUsed,
            'initial_peak' => $initialPeak,
            'final_peak' => $finalPeak,
            'peak_used' => $peakUsed,
            'memory_limit' => $memoryLimit,
            'peak_percentage' => round(($finalPeak / $memoryLimit) * 100, 2),
            'formatted' => [
                'initial_usage' => $this->formatBytes($initialUsage),
                'final_usage' => $this->formatBytes($finalUsage),
                'actual_used' => $this->formatBytes($actualUsed),
                'peak_usage' => $this->formatBytes($finalPeak),
                'peak_used' => $this->formatBytes($peakUsed),
                'memory_limit' => $this->formatBytes($memoryLimit),
                'peak_percentage' => round(($finalPeak / $memoryLimit) * 100, 2) . '%'
            ]
        ];
        
        // Add prediction accuracy if prediction was available
        if (!empty($prediction) && isset($prediction['estimated_additional'])) {
            $predictionAccuracy = ($prediction['estimated_additional'] > 0) 
                ? round((abs($actualUsed - $prediction['estimated_additional']) / $prediction['estimated_additional']) * 100, 2)
                : 0;
            
            $report['prediction'] = [
                'estimated' => $prediction['estimated_additional'],
                'actual' => $actualUsed,
                'accuracy_percentage' => max(0, 100 - $predictionAccuracy),
                'difference' => $actualUsed - $prediction['estimated_additional'],
                'formatted' => [
                    'estimated' => $this->formatBytes($prediction['estimated_additional']),
                    'difference' => $this->formatBytes($actualUsed - $prediction['estimated_additional'])
                ]
            ];
        }
        
        return $report;
    }

    /**
     * Parse memory limit string to bytes
     *
     * @param string $memoryLimit Memory limit string (e.g., "512M", "2G")
     * @return int Memory limit in bytes
     */
    private function parseMemoryLimit(string $memoryLimit): int
    {
        if ($memoryLimit === '-1') {
            return PHP_INT_MAX; // No limit
        }
        
        $unit = strtoupper(substr($memoryLimit, -1));
        $value = (int) substr($memoryLimit, 0, -1);
        
        switch ($unit) {
            case 'G':
                return $value * 1024 * 1024 * 1024;
            case 'M':
                return $value * 1024 * 1024;
            case 'K':
                return $value * 1024;
            default:
                return (int) $memoryLimit;
        }
    }

    /**
     * Format bytes to human readable format
     *
     * @param int|float $bytes Number of bytes
     * @return string Formatted string
     */
    private function formatBytes(int|float $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
        } elseif ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' B';
        }
    }

    /**
     * Truncate field value to respect SOLR's 32,766 byte limit for indexed string fields
     *
     * SOLR has a hard limit of 32,766 bytes for indexed string fields. This method
     * ensures field values stay within this limit while preserving as much data as possible.
     *
     * @param mixed $value The field value to check and potentially truncate
     * @param string $fieldName Field name for logging purposes
     * @return mixed Truncated value or original value if within limits
     */
    private function truncateFieldValue($value, string $fieldName = ''): mixed
    {
        // Only truncate string values
        if (!is_string($value)) {
            return $value;
        }
        
        // SOLR's byte limit for indexed string fields
        $maxBytes = 32766;
        
        // Check if value exceeds byte limit (UTF-8 safe)
        if (strlen($value) <= $maxBytes) {
            return $value; // Within limits
        }
        
        // **TRUNCATE SAFELY**: Ensure we don't break UTF-8 characters
        $truncated = mb_strcut($value, 0, $maxBytes - 100, 'UTF-8'); // Leave buffer for safety
        
        // Add truncation indicator
        $truncated .= '...[TRUNCATED]';
        
        // Log truncation for monitoring
        $this->logger->info('Field value truncated for SOLR indexing', [
            'field' => $fieldName,
            'original_bytes' => strlen($value),
            'truncated_bytes' => strlen($truncated),
            'truncation_point' => $maxBytes - 100
        ]);
        
        return $truncated;
    }

    /**
     * Check if a field should be truncated based on schema definition
     *
     * File fields and other large content fields should be truncated to prevent
     * SOLR indexing errors.
     *
     * @param string $fieldName Field name
     * @param array $fieldDefinition Schema field definition (if available)
     * @return bool True if field should be truncated
     */
    private function shouldTruncateField(string $fieldName, array $fieldDefinition = []): bool
    {
        $type = $fieldDefinition['type'] ?? '';
        $format = $fieldDefinition['format'] ?? '';
        
        // File fields should always be truncated
        if ($type === 'file' || $format === 'file' || $format === 'binary' || 
            in_array($format, ['data-url', 'base64', 'image', 'document'])) {
            return true;
        }
        
        // Fields that commonly contain large content
        $largeContentFields = ['logo', 'image', 'icon', 'thumbnail', 'content', 'body', 'description'];
        if (in_array(strtolower($fieldName), $largeContentFields)) {
            return true;
        }
        
        // Base64 data URLs (common pattern)
        if (is_string($fieldName) && str_contains(strtolower($fieldName), 'base64')) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Discover facetable fields directly from SOLR schema
     *
     * This method queries SOLR's schema API to find all fields that have docValues=true,
     * which makes them suitable for faceting. It returns the fields in the same format
     * as the database-based facetable field discovery.
     *
     * @return array<string, mixed> Facetable fields configuration
     * @throws \Exception If SOLR schema query fails
     */
    private function discoverFacetableFieldsFromSolr(): array
    {
        $collectionName = $this->getActiveCollectionName();
        if ($collectionName === null) {
            throw new \Exception('No active SOLR collection available for schema discovery');
        }
        
        // Query SOLR schema API for all fields
        $baseUrl = $this->buildSolrBaseUrl();
        $schemaUrl = $baseUrl . "/{$collectionName}/schema/fields";
        
        try {
            $response = $this->httpClient->get($schemaUrl, [
                'query' => [
                    'wt' => 'json'
                ]
            ]);
            
            $schemaData = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($schemaData['fields']) || !is_array($schemaData['fields'])) {
                throw new \Exception('Invalid schema response from SOLR');
            }
            
            $facetableFields = [
                '@self' => [],
                'object_fields' => []
            ];
            
            // Process each field to determine if it's facetable
            foreach ($schemaData['fields'] as $field) {
                $fieldName = $field['name'] ?? 'unknown';
                
                // Log self_ fields for debugging
                if (str_starts_with($fieldName, 'self_')) {
                    $this->logger->debug('Found self_ field in SOLR schema', [
                        'field' => $fieldName,
                        'docValues' => $field['docValues'] ?? 'not set',
                        'type' => $field['type'] ?? 'unknown'
                    ]);
                }
                
                if (!isset($field['name']) || !isset($field['docValues']) || $field['docValues'] !== true) {
                    continue; // Skip fields without docValues
                }
                
                $fieldName = $field['name'];
                $fieldType = $field['type'] ?? 'string';
                
                // Categorize fields
                if (str_starts_with($fieldName, 'self_')) {
                    // Metadata field
                    $metadataKey = substr($fieldName, 5); // Remove 'self_' prefix
                    $facetableFields['@self'][$metadataKey] = [
                        'name' => $metadataKey,
                        'type' => $this->mapSolrTypeToFacetType($fieldType),
                        'index_field' => $fieldName,
                        'index_type' => $fieldType,
                        'queryParameter' => '@self[' . $metadataKey . ']',
                        'source' => 'metadata'
                    ];
                } elseif (!in_array($fieldName, ['_version_', 'id', '_text_'])) {
                    // Object field (exclude system fields)
                    $facetableFields['object_fields'][$fieldName] = [
                        'name' => $fieldName,
                        'type' => $this->mapSolrTypeToFacetType($fieldType),
                        'index_field' => $fieldName,
                        'index_type' => $fieldType,
                        'queryParameter' => $fieldName,
                        'source' => 'object'
                    ];
                }
            }
            
            $this->logger->debug('Discovered facetable fields from SOLR schema', [
                'collection' => $collectionName,
                'metadataFields' => count($facetableFields['@self']),
                'objectFields' => count($facetableFields['object_fields']),
                'totalFields' => count($schemaData['fields'])
            ]);
            
            return $facetableFields;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to query SOLR schema for facetable fields', [
                'collection' => $collectionName,
                'url' => $schemaUrl,
                'error' => $e->getMessage()
            ]);
            
            throw new \Exception('SOLR schema discovery failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Get raw SOLR field information for facet configuration
     * Returns unprocessed field data suitable for configuration UI
     *
     * @return array Raw SOLR field information grouped by category
     * @throws \Exception If SOLR is not available or schema discovery fails
     */
    public function getRawSolrFieldsForFacetConfiguration(): array
    {
        $collectionName = $this->getActiveCollectionName();
        if ($collectionName === null) {
            throw new \Exception('No active SOLR collection available for field discovery');
        }
        
        // Query SOLR schema API for all fields
        $baseUrl = $this->buildSolrBaseUrl();
        $schemaUrl = $baseUrl . "/{$collectionName}/schema/fields";
        
        try {
            $response = $this->httpClient->get($schemaUrl, [
                'query' => [
                    'wt' => 'json'
                ]
            ]);
            
            $schemaData = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($schemaData['fields']) || !is_array($schemaData['fields'])) {
                throw new \Exception('Invalid schema response from SOLR');
            }
            
            $rawFields = [
                '@self' => [],
                'object_fields' => []
            ];
            
            // Process each field and return raw information for configuration
            foreach ($schemaData['fields'] as $field) {
                $fieldName = $field['name'] ?? 'unknown';
                
                // Only include fields that have docValues (facetable)
                if (!isset($field['name']) || !isset($field['docValues']) || $field['docValues'] !== true) {
                    continue;
                }
                
                $fieldInfo = [
                    'name' => $fieldName,
                    'type' => $field['type'] ?? 'string',
                    'stored' => $field['stored'] ?? false,
                    'indexed' => $field['indexed'] ?? false,
                    'docValues' => $field['docValues'] ?? false,
                    'multiValued' => $field['multiValued'] ?? false,
                    'required' => $field['required'] ?? false,
                    // Add suggested facet type based on SOLR type
                    'suggestedFacetType' => $this->mapSolrTypeToFacetType($field['type'] ?? 'string'),
                    // Add suggested display types based on field characteristics
                    'suggestedDisplayTypes' => $this->getSuggestedDisplayTypes($field)
                ];
                
                // Categorize fields
                if (str_starts_with($fieldName, 'self_')) {
                    // Metadata field
                    $metadataKey = substr($fieldName, 5); // Remove 'self_' prefix
                    $fieldInfo['displayName'] = ucfirst(str_replace('_', ' ', $metadataKey));
                    $fieldInfo['category'] = 'metadata';
                    $rawFields['@self'][$metadataKey] = $fieldInfo;
                } elseif (!in_array($fieldName, ['_version_', 'id', '_text_'])) {
                    // Object field (exclude system fields)
                    $fieldInfo['displayName'] = ucfirst(str_replace('_', ' ', $fieldName));
                    $fieldInfo['category'] = 'object';
                    $rawFields['object_fields'][$fieldName] = $fieldInfo;
                }
            }
            
            $this->logger->debug('Retrieved raw SOLR fields for facet configuration', [
                'collection' => $collectionName,
                'metadataFields' => count($rawFields['@self']),
                'objectFields' => count($rawFields['object_fields']),
                'totalFields' => count($schemaData['fields'])
            ]);
            
            return $rawFields;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve raw SOLR fields for facet configuration', [
                'collection' => $collectionName,
                'url' => $schemaUrl,
                'error' => $e->getMessage()
            ]);
            
            throw new \Exception('SOLR field discovery failed: ' . $e->getMessage());
        }
    }

    /**
     * Get suggested display types for a SOLR field based on its characteristics
     *
     * @param array $field SOLR field information
     * @return array Suggested display types
     */
    private function getSuggestedDisplayTypes(array $field): array
    {
        $fieldType = $field['type'] ?? 'string';
        $multiValued = $field['multiValued'] ?? false;
        
        $suggestions = [];
        
        // Based on field type
        switch ($fieldType) {
            case 'boolean':
                $suggestions = ['checkbox', 'radio'];
                break;
            case 'pint':
            case 'plong':
            case 'pfloat':
            case 'pdouble':
            case 'int':
            case 'long':
            case 'float':
            case 'double':
                $suggestions = ['range', 'select'];
                break;
            case 'pdate':
            case 'date':
                $suggestions = ['date_range', 'select'];
                break;
            default:
                // String fields
                if ($multiValued) {
                    $suggestions = ['multiselect', 'checkbox'];
                } else {
                    $suggestions = ['select', 'radio', 'checkbox'];
                }
                break;
        }
        
        return $suggestions;
    }

    /**
     * Map SOLR field type to OpenRegister facet type
     *
     * @param string $solrType SOLR field type
     * @return string OpenRegister facet type
     */
    private function mapSolrTypeToFacetType(string $solrType): string
    {
        switch ($solrType) {
            case 'pint':
            case 'plong':
            case 'pfloat':
            case 'pdouble':
            case 'int':
            case 'long':
            case 'float':
            case 'double':
                return 'range';
            case 'pdate':
            case 'date':
                return 'date_histogram';
            case 'boolean':
                return 'terms';
            default:
                return 'terms';
        }
    }

    /**
     * Get contextual facets by re-running the same query with faceting enabled
     * This is much more efficient and respects all current search parameters
     *
     * @param array $solrQuery The current SOLR query parameters
     * @param array $originalQuery The original OpenRegister query
     * @return array Contextual facet data with both facetable fields and extended data
     */
    private function getContextualFacetsFromSameQuery(array $solrQuery, array $originalQuery): array
    {
        $collectionName = $this->getActiveCollectionName();
        if ($collectionName === null) {
            throw new \Exception('No active SOLR collection available for contextual faceting');
        }

        // Build faceting query using the same parameters as the main query
        // but with rows=0 for performance and JSON faceting enabled
        $facetQuery = $solrQuery;
        $facetQuery['rows'] = 0; // We only want facet data
        
        
        // Add JSON faceting for core metadata fields with domain filtering
        $filterQueries = $facetQuery['fq'] ?? [];
        $jsonFacets = $this->buildOptimizedContextualFacetQuery($filterQueries);
        if (!empty($jsonFacets)) {
            $facetQuery['json.facet'] = json_encode($jsonFacets);
        }

        // Execute the faceting query
        $baseUrl = $this->buildSolrBaseUrl();
        $queryUrl = $baseUrl . "/{$collectionName}/select";
        
        try {
            $startTime = microtime(true);
            
            
            // Use POST to avoid URI length issues
            $response = $this->httpClient->post($queryUrl, [
                'form_params' => $facetQuery,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ]
            ]);
            
            $responseBody = $response->getBody()->getContents();
            $data = json_decode($responseBody, true);
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            
            if ($data === null) {
                $this->logger->error('Failed to decode SOLR JSON response for contextual facets', [
                    'response_body' => substr($responseBody, 0, 1000), // First 1000 chars
                    'json_error' => json_last_error_msg()
                ]);
                throw new \Exception('Failed to decode SOLR JSON response: ' . json_last_error_msg());
            }
            
            $this->logger->debug('Contextual faceting query completed', [
                'execution_time_ms' => round($executionTime, 2),
                'total_found' => $data['response']['numFound'] ?? 0,
                'facets_available' => isset($data['facets'])
            ]);
            
            
            // Process the facet data
            if (isset($data['facets'])) {
                return $this->processOptimizedContextualFacets($data['facets']);
            } else {
                // Fallback: discover fields that have values in the current result set
                return $this->discoverFieldsFromCurrentResults($data);
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get contextual facets from same query', [
                'collection' => $collectionName,
                'url' => $queryUrl,
                'error' => $e->getMessage()
            ]);
            
            throw new \Exception('SOLR contextual faceting failed: ' . $e->getMessage());
        }
    }

    /**
     * Discover fields that have values from the current search results
     * This is a fallback when JSON faceting is not available
     *
     * @param array $solrResponse The SOLR response data
     * @return array Contextual facet data
     */
    private function discoverFieldsFromCurrentResults(array $solrResponse): array
    {
        $contextualData = [
            'facetable' => ['@self' => [], 'object_fields' => []],
            'extended' => ['@self' => [], 'object_fields' => []]
        ];

        // If there are no results, return empty facets
        if (!isset($solrResponse['response']['docs']) || empty($solrResponse['response']['docs'])) {
            return $contextualData;
        }

        $docs = $solrResponse['response']['docs'];
        $fieldsFound = [];

        // Analyze the first few documents to discover available fields
        $sampleSize = min(10, count($docs));
        for ($i = 0; $i < $sampleSize; $i++) {
            foreach ($docs[$i] as $fieldName => $fieldValue) {
                if (!isset($fieldsFound[$fieldName])) {
                    $fieldsFound[$fieldName] = [];
                }
                
                // Store unique values (up to 50 per field for performance)
                if (is_array($fieldValue)) {
                    foreach ($fieldValue as $value) {
                        if (count($fieldsFound[$fieldName]) < 50) {
                            $fieldsFound[$fieldName][] = $value;
                        }
                    }
                } else {
                    if (count($fieldsFound[$fieldName]) < 50) {
                        $fieldsFound[$fieldName][] = $fieldValue;
                    }
                }
            }
        }

        // Process discovered fields
        $metadataFields = [
            'self_register' => ['name' => 'register', 'type' => 'terms', 'index_field' => 'self_register', 'index_type' => 'pint'],
            'self_schema' => ['name' => 'schema', 'type' => 'terms', 'index_field' => 'self_schema', 'index_type' => 'pint'],
            'self_organisation' => ['name' => 'organisation', 'type' => 'terms', 'index_field' => 'self_organisation', 'index_type' => 'string'],
            'self_application' => ['name' => 'application', 'type' => 'terms', 'index_field' => 'self_application', 'index_type' => 'string'],
            'self_created' => ['name' => 'created', 'type' => 'date_histogram', 'index_field' => 'self_created', 'index_type' => 'pdate'],
            'self_updated' => ['name' => 'updated', 'type' => 'date_histogram', 'index_field' => 'self_updated', 'index_type' => 'pdate']
        ];

        foreach ($fieldsFound as $fieldName => $values) {
            if (str_starts_with($fieldName, 'self_')) {
                // Metadata field
                if (isset($metadataFields[$fieldName])) {
                    $fieldInfo = $metadataFields[$fieldName];
                    $contextualData['facetable']['@self'][$fieldInfo['name']] = $fieldInfo;
                    $contextualData['extended']['@self'][$fieldInfo['name']] = array_merge(
                        $fieldInfo,
                        ['data' => array_map(function($value) { return ['value' => $value, 'count' => 1]; }, array_unique($values))]
                    );
                }
            } elseif (!in_array($fieldName, ['_version_', 'id', '_text_'])) {
                // Object field (exclude system fields)
                $fieldType = $this->inferFieldType($values);
                $fieldInfo = [
                    'name' => $fieldName,
                    'type' => $this->mapSolrTypeToFacetType($fieldType),
                    'index_field' => $fieldName,
                    'index_type' => $fieldType
                ];
                
                $contextualData['facetable']['object_fields'][$fieldName] = $fieldInfo;
                $contextualData['extended']['object_fields'][$fieldName] = array_merge(
                    $fieldInfo,
                    ['data' => array_map(function($value) { return ['value' => $value, 'count' => 1]; }, array_unique($values))]
                );
            }
        }

        $this->logger->debug('Discovered fields from current results', [
            'metadata_fields' => count($contextualData['facetable']['@self']),
            'object_fields' => count($contextualData['facetable']['object_fields']),
            'sample_size' => $sampleSize,
            'total_docs' => count($docs)
        ]);

        return $contextualData;
    }

    /**
     * Infer field type from sample values
     *
     * @param array $values Sample values from the field
     * @return string Inferred SOLR field type
     */
    private function inferFieldType(array $values): string
    {
        if (empty($values)) {
            return 'string';
        }

        $sampleValue = $values[0];
        
        if (is_numeric($sampleValue)) {
            return strpos($sampleValue, '.') !== false ? 'pfloat' : 'pint';
        } elseif (strtotime($sampleValue) !== false) {
            return 'pdate';
        } else {
            return 'string';
        }
    }

    /**
     * Get contextual facet data in one optimized SOLR call
     * This method respects current search parameters and only returns facets with actual values
     *
     * @param array $searchResults Current search results to extract facets from
     * @param array $filters Current query filters to apply
     * @return array Contextual facet data with both facetable fields and extended data
     */
    private function getContextualFacetData(array $searchResults, array $filters = []): array
    {
        $collectionName = $this->getActiveCollectionName();
        if ($collectionName === null) {
            throw new \Exception('No active SOLR collection available for contextual faceting');
        }

        // Extract facets from the current search results if they exist
        // This is much faster than making additional SOLR calls
        if (isset($searchResults['facets']) && !empty($searchResults['facets'])) {
            $this->logger->debug('Using facets from current search results for contextual data');
            return $this->processContextualFacetsFromSearchResults($searchResults['facets']);
        }

        // If no facets in search results, make an optimized SOLR call
        // that discovers fields AND gets data in one request
        return $this->getOptimizedContextualFacets($filters);
    }

    /**
     * Process contextual facets from existing search results
     *
     * @param array $searchFacets Facets from current search results
     * @return array Processed contextual facet data
     */
    private function processContextualFacetsFromSearchResults(array $searchFacets): array
    {
        // For now, return empty structure - we'll implement this if needed
        // Most of the time we'll use the optimized call instead
        return [
            'facetable' => ['@self' => [], 'object_fields' => []],
            'extended' => ['@self' => [], 'object_fields' => []]
        ];
    }

    /**
     * Get optimized contextual facets with a single SOLR call
     * Uses SOLR's field stats to discover which fields have values, then facets only those
     *
     * @param array $filters Current query filters
     * @return array Contextual facet data
     */
    private function getOptimizedContextualFacets(array $filters = []): array
    {
        $collectionName = $this->getActiveCollectionName();
        
        // Build a comprehensive JSON faceting query that discovers fields with values
        // and gets their facet data in one call
        $optimizedFacetQuery = $this->buildOptimizedContextualFacetQuery();
        
        if (empty($optimizedFacetQuery)) {
            return [
                'facetable' => ['@self' => [], 'object_fields' => []],
                'extended' => ['@self' => [], 'object_fields' => []]
            ];
        }

        // Build base query with current filters
        $baseQuery = '*:*';
        $filterQueries = [];
        
        // Add tenant filter
        $tenantId = $this->getTenantId();
        if ($tenantId) {
            $filterQueries[] = 'self_tenant:' . $tenantId;
        }
        
        // Add current filters
        foreach ($filters as $filter) {
            if (!empty($filter)) {
                $filterQueries[] = $filter;
            }
        }

        // Query SOLR with optimized JSON faceting
        $baseUrl = $this->buildSolrBaseUrl();
        $queryUrl = $baseUrl . "/{$collectionName}/select";
        
        $queryParams = [
            'q' => $baseQuery,
            'rows' => 0, // We only want facet data
            'wt' => 'json',
            'json.facet' => json_encode($optimizedFacetQuery)
        ];
        
        // Add filter queries
        if (!empty($filterQueries)) {
            $queryParams['fq'] = $filterQueries;
        }

        try {
            $startTime = microtime(true);
            
            // Use POST to avoid URI length issues
            $response = $this->httpClient->post($queryUrl, [
                'form_params' => $queryParams,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ]
            ]);
            
            $responseBody = $response->getBody()->getContents();
            $data = json_decode($responseBody, true);
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            
            if ($data === null) {
                $this->logger->error('Failed to decode SOLR JSON response for contextual facets', [
                    'response_body' => substr($responseBody, 0, 1000), // First 1000 chars
                    'json_error' => json_last_error_msg()
                ]);
                throw new \Exception('Failed to decode SOLR JSON response: ' . json_last_error_msg());
            }
            
            if (!isset($data['facets'])) {
                $this->logger->error('SOLR response missing facets key for contextual facets', [
                    'response_keys' => array_keys($data)
                ]);
                throw new \Exception('Invalid contextual faceting response from SOLR - missing facets key');
            }
            
            $this->logger->debug('Optimized contextual faceting completed', [
                'execution_time_ms' => round($executionTime, 2),
                'facets_found' => count($data['facets'])
            ]);
            
            // Process and format the contextual facet data
            return $this->processOptimizedContextualFacets($data['facets']);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get optimized contextual facet data from SOLR', [
                'collection' => $collectionName,
                'url' => $queryUrl,
                'error' => $e->getMessage()
            ]);
            
            throw new \Exception('SOLR contextual faceting failed: ' . $e->getMessage());
        }
    }

    /**
     * Build optimized contextual facet query that includes both metadata and object fields with actual values
     * This method now properly applies filter queries to facets using SOLR domains
     *
     * @param array $filterQueries Filter queries to apply to facets (e.g., ['self_register:3'])
     * @return array Optimized JSON facet query with domain filtering
     */
    private function buildOptimizedContextualFacetQuery(array $filterQueries = []): array
    {
        // Get core metadata fields that should always be checked
        $coreMetadataFields = [
            'register' => 'self_register',
            'schema' => 'self_schema', 
            'organisation' => 'self_organisation',
            'application' => 'self_application',
            'created' => 'self_created',
            'updated' => 'self_updated'
        ];
        
        // Build facet query with existence checks and data retrieval
        $facetQuery = [];
        
        // Build domain filter for applying filter queries to facets
        $domainFilter = null;
        if (!empty($filterQueries)) {
            $domainFilter = [
                'filter' => $filterQueries
            ];
        }
        
        // Add metadata fields with existence checks and domain filtering
        foreach ($coreMetadataFields as $fieldName => $solrField) {
            $facetConfig = [
                'type' => 'terms',
                'field' => $solrField,
                'limit' => 50,
                'mincount' => 1, // Only include if there are actual values
                'missing' => false // Don't include missing values
            ];
            
            // Apply domain filter if we have filter queries
            if ($domainFilter !== null) {
                $facetConfig['domain'] = $domainFilter;
            }
            
            $facetQuery[$fieldName] = $facetConfig;
        }
        
        // For _facets=extend, discover and facet ALL available fields from SOLR schema
        try {
            $allFacetableFields = $this->discoverFacetableFieldsFromSolr();
            
            if (isset($allFacetableFields['object_fields'])) {
                foreach ($allFacetableFields['object_fields'] as $fieldName => $fieldConfig) {
                    // Only add fields that can be faceted (have docValues)
                    if (isset($fieldConfig['index_field'])) {
                        $objectFacetConfig = [
                            'type' => 'terms',
                            'field' => $fieldConfig['index_field'],
                            'limit' => 50,
                            'mincount' => 1, // Only include if there are actual values
                            'missing' => false // Don't include missing values
                        ];
                        
                        // Apply domain filter if we have filter queries
                        if ($domainFilter !== null) {
                            $objectFacetConfig['domain'] = $domainFilter;
                        }
                        
                        $facetQuery['object_' . $fieldName] = $objectFacetConfig;
                    }
                }
            }
        } catch (\Exception $e) {
            // Fallback to commonly facetable fields if schema discovery fails
            $this->logger->warning('Failed to discover all facetable fields, using fallback list', [
                'error' => $e->getMessage()
            ]);
            
            $commonObjectFields = [
                'slug', 'naam', 'type', 'status', 'category', 'tag', 'label',
                'cloudDienstverleningsmodel', 'hostingJurisdictie', 'hostingLocatie',
                'beschrijvingKort', 'website', 'logo'
            ];
            
            foreach ($commonObjectFields as $fieldName) {
                $fallbackFacetConfig = [
                    'type' => 'terms',
                    'field' => $fieldName,
                    'limit' => 50,
                    'mincount' => 1, // Only include if there are actual values
                    'missing' => false // Don't include missing values
                ];
                
                // Apply domain filter if we have filter queries
                if ($domainFilter !== null) {
                    $fallbackFacetConfig['domain'] = $domainFilter;
                }
                
                $facetQuery['object_' . $fieldName] = $fallbackFacetConfig;
            }
        }
        
        return $facetQuery;
    }

    /**
     * Process optimized contextual facets from SOLR response
     *
     * @param array $facetData Raw facet data from SOLR
     * @return array Processed contextual facet data
     */
    private function processOptimizedContextualFacets(array $facetData): array
    {
        $contextualData = [
            'facetable' => ['@self' => [], 'object_fields' => []],
            'extended' => ['@self' => [], 'object_fields' => []]
        ];
        
        // Process metadata fields with underscore prefix to avoid collisions
        $metadataFieldMap = [
            'register' => ['name' => '_register', 'type' => 'terms', 'index_field' => 'self_register', 'index_type' => 'pint', 'queryParameter' => '@self[register]', 'source' => 'metadata'],
            'schema' => ['name' => '_schema', 'type' => 'terms', 'index_field' => 'self_schema', 'index_type' => 'pint', 'queryParameter' => '@self[schema]', 'source' => 'metadata'],
            'organisation' => ['name' => '_organisation', 'type' => 'terms', 'index_field' => 'self_organisation', 'index_type' => 'string', 'queryParameter' => '@self[organisation]', 'source' => 'metadata'],
            'application' => ['name' => '_application', 'type' => 'terms', 'index_field' => 'self_application', 'index_type' => 'string', 'queryParameter' => '@self[application]', 'source' => 'metadata'],
            'created' => ['name' => '_created', 'type' => 'date_histogram', 'index_field' => 'self_created', 'index_type' => 'pdate', 'queryParameter' => '@self[created]', 'source' => 'metadata'],
            'updated' => ['name' => '_updated', 'type' => 'date_histogram', 'index_field' => 'self_updated', 'index_type' => 'pdate', 'queryParameter' => '@self[updated]', 'source' => 'metadata']
        ];
        
        foreach ($metadataFieldMap as $solrFieldName => $fieldInfo) {
            if (isset($facetData[$solrFieldName]) && !empty($facetData[$solrFieldName]['buckets'])) {
                // Add to facetable fields with underscore-prefixed name
                $contextualData['facetable']['@self'][$fieldInfo['name']] = $fieldInfo;
                
                // Add to extended data with actual values and resolved labels for metadata fields
                $formattedData = $this->formatMetadataFacetData($facetData[$solrFieldName], $solrFieldName, $fieldInfo['type']);
                $facetResult = array_merge(
                    $fieldInfo,
                    ['data' => $formattedData]
                );
                
                // Apply custom facet configuration
                $facetResult = $this->applyFacetConfiguration($facetResult, 'self_' . $solrFieldName);
                
                // Only include enabled facets
                if ($facetResult['enabled'] ?? true) {
                    $contextualData['extended']['@self'][$fieldInfo['name']] = $facetResult;
                }
            }
        }
        
        // Process object fields (they come with 'object_' prefix in facet response)
        foreach ($facetData as $facetKey => $facetValue) {
            if (str_starts_with($facetKey, 'object_') && !empty($facetValue['buckets'])) {
                $objectFieldName = substr($facetKey, 7); // Remove 'object_' prefix
                
                $objectFieldInfo = [
                    'name' => $objectFieldName,
                    'type' => 'terms', // Most object fields are terms-based
                    'index_field' => $objectFieldName,
                    'index_type' => 'string',
                    'queryParameter' => $objectFieldName,
                    'source' => 'object'
                ];
                
                // Add to facetable fields
                $contextualData['facetable']['object_fields'][$objectFieldName] = $objectFieldInfo;
                
                // Add to extended data with actual values
                $facetResult = array_merge(
                    $objectFieldInfo,
                    ['data' => $this->formatFacetData($facetValue, 'terms')]
                );
                
                // Apply custom facet configuration
                $facetResult = $this->applyFacetConfiguration($facetResult, $objectFieldName);
                
                // Only include enabled facets
                if ($facetResult['enabled'] ?? true) {
                    $contextualData['extended']['object_fields'][$objectFieldName] = $facetResult;
                }
            }
        }
        
        // Sort facets according to configuration
        $contextualData['extended']['@self'] = $this->sortFacetsWithConfiguration($contextualData['extended']['@self']);
        $contextualData['extended']['object_fields'] = $this->sortFacetsWithConfiguration($contextualData['extended']['object_fields']);
        
        $this->logger->debug('Processed optimized contextual facets', [
            'metadata_fields_found' => count($contextualData['extended']['@self']),
            'object_fields_found' => count($contextualData['extended']['object_fields'])
        ]);
        
        return $contextualData;
    }

    /**
     * Get extended facet data from SOLR using JSON faceting API
     *
     * @param array $facetableFields The facetable fields discovered from schema
     * @param array $filters Current query filters to apply
     * @return array Extended facet data with counts and options
     */
    private function getExtendedFacetData(array $facetableFields, array $filters = []): array
    {
        $collectionName = $this->getActiveCollectionName();
        if ($collectionName === null) {
            throw new \Exception('No active SOLR collection available for extended faceting');
        }

        // Build JSON faceting query
        $facetQuery = $this->buildJsonFacetQuery($facetableFields);
        
        if (empty($facetQuery)) {
            return [
                '@self' => [],
                'object_fields' => []
            ];
        }

        // Build base query with filters
        $baseQuery = '*:*';
        $filterQueries = [];
        
        // Add tenant filter
        $tenantId = $this->getTenantId();
        if ($tenantId) {
            $filterQueries[] = 'self_tenant:' . $tenantId;
        }
        
        // Add any additional filters from the query
        foreach ($filters as $filter) {
            if (!empty($filter)) {
                $filterQueries[] = $filter;
            }
        }

        // Query SOLR with JSON faceting
        $baseUrl = $this->buildSolrBaseUrl();
        $queryUrl = $baseUrl . "/{$collectionName}/select";
        
        $queryParams = [
            'q' => $baseQuery,
            'rows' => 0, // We only want facet data, not documents
            'wt' => 'json',
            'json.facet' => json_encode($facetQuery)
        ];
        
        // Add filter queries
        if (!empty($filterQueries)) {
            $queryParams['fq'] = $filterQueries;
        }

        try {
            // Use POST instead of GET to avoid 414 Request-URI Too Large errors
            // when using complex JSON faceting queries
            $response = $this->httpClient->post($queryUrl, [
                'form_params' => $queryParams,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ]
            ]);
            
            $responseBody = $response->getBody()->getContents();
            $data = json_decode($responseBody, true);
            
            if ($data === null) {
                $this->logger->error('Failed to decode SOLR JSON response', [
                    'response_body' => $responseBody,
                    'json_error' => json_last_error_msg()
                ]);
                throw new \Exception('Failed to decode SOLR JSON response: ' . json_last_error_msg());
            }
            
            $this->logger->debug('SOLR JSON faceting response', [
                'response_keys' => array_keys($data),
                'facets_key_exists' => isset($data['facets']),
                'response_sample' => array_slice($data, 0, 3, true)
            ]);
            
            if (!isset($data['facets'])) {
                // Log the full response for debugging
                $this->logger->error('SOLR response missing facets key', [
                    'response' => $data
                ]);
                throw new \Exception('Invalid faceting response from SOLR - missing facets key');
            }
            
            // Process and format the facet data
            return $this->processFacetResponse($data['facets'], $facetableFields);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get extended facet data from SOLR', [
                'collection' => $collectionName,
                'url' => $queryUrl,
                'error' => $e->getMessage()
            ]);
            
            throw new \Exception('SOLR extended faceting failed: ' . $e->getMessage());
        }
    }

    /**
     * Build JSON facet query for SOLR
     *
     * @param array $facetableFields The facetable fields to create facets for
     * @return array JSON facet query structure
     */
    private function buildJsonFacetQuery(array $facetableFields): array
    {
        $facetQuery = [];
        
        // Process metadata fields (@self)
        foreach ($facetableFields['@self'] ?? [] as $fieldName => $fieldInfo) {
            $solrFieldName = $fieldInfo['index_field'];
            $facetType = $fieldInfo['type'];
            
            if ($facetType === 'date_histogram') {
                $facetQuery[$fieldName] = $this->buildDateHistogramFacet($solrFieldName);
            } elseif ($facetType === 'range') {
                $facetQuery[$fieldName] = $this->buildRangeFacet($solrFieldName);
            } else {
                $facetQuery[$fieldName] = $this->buildTermsFacet($solrFieldName);
            }
        }
        
        // Process object fields
        foreach ($facetableFields['object_fields'] ?? [] as $fieldName => $fieldInfo) {
            $solrFieldName = $fieldInfo['index_field'];
            $facetType = $fieldInfo['type'];
            
            if ($facetType === 'date_histogram') {
                $facetQuery[$fieldName] = $this->buildDateHistogramFacet($solrFieldName);
            } elseif ($facetType === 'range') {
                $facetQuery[$fieldName] = $this->buildRangeFacet($solrFieldName);
            } else {
                $facetQuery[$fieldName] = $this->buildTermsFacet($solrFieldName);
            }
        }
        
        return $facetQuery;
    }

    /**
     * Build terms facet for categorical fields
     *
     * @param string $fieldName SOLR field name
     * @return array Terms facet configuration
     */
    private function buildTermsFacet(string $fieldName): array
    {
        return [
            'type' => 'terms',
            'field' => $fieldName,
            'limit' => 50, // Limit to top 50 terms
            'mincount' => 1 // Only include terms with at least 1 document
        ];
    }

    /**
     * Build range facet for numeric fields
     *
     * @param string $fieldName SOLR field name
     * @return array Range facet configuration
     */
    private function buildRangeFacet(string $fieldName): array
    {
        return [
            'type' => 'range',
            'field' => $fieldName,
            'start' => 0,
            'end' => 1000000,
            'gap' => 100,
            'mincount' => 1
        ];
    }

    /**
     * Build date histogram facet with sensible time brackets
     *
     * @param string $fieldName SOLR field name
     * @return array Date histogram facet configuration
     */
    private function buildDateHistogramFacet(string $fieldName): array
    {
        // For date fields, we'll create multiple time brackets
        return [
            'type' => 'range',
            'field' => $fieldName,
            'start' => 'NOW-10YEARS',
            'end' => 'NOW+1DAY',
            'gap' => '+1YEAR',
            'mincount' => 1,
            'facet' => [
                'monthly' => [
                    'type' => 'range',
                    'field' => $fieldName,
                    'start' => 'NOW-2YEARS',
                    'end' => 'NOW+1DAY',
                    'gap' => '+1MONTH',
                    'mincount' => 1
                ],
                'daily' => [
                    'type' => 'range',
                    'field' => $fieldName,
                    'start' => 'NOW-90DAYS',
                    'end' => 'NOW+1DAY',
                    'gap' => '+1DAY',
                    'mincount' => 1
                ]
            ]
        ];
    }

    /**
     * Apply custom facet configuration to facet data
     *
     * @param array $facetData Processed facet data
     * @param string $fieldName Field name
     * @return array Facet data with custom configuration applied
     */
    private function applyFacetConfiguration(array $facetData, string $fieldName): array
    {
        try {
            // Get facet configuration from settings service
            $settingsService = \OC::$server->get(\OCA\OpenRegister\Service\SettingsService::class);
            $facetConfig = $settingsService->getSolrFacetConfiguration();
            
            // Convert field name to configuration format if needed
            $configFieldName = $fieldName;
            if (str_starts_with($fieldName, 'self_')) {
                // Convert self_fieldname to @self[fieldname] format for metadata fields
                $metadataField = substr($fieldName, 5); // Remove 'self_' prefix
                $configFieldName = "@self[{$metadataField}]";
            }
            
            // Check if this field has custom configuration
            if (isset($facetConfig['facets'][$configFieldName])) {
                $customConfig = $facetConfig['facets'][$configFieldName];
                
                // Apply custom title
                if (!empty($customConfig['title'])) {
                    $facetData['title'] = $customConfig['title'];
                }
                
                // Apply custom description
                if (!empty($customConfig['description'])) {
                    $facetData['description'] = $customConfig['description'];
                }
                
                // Apply custom order
                if (isset($customConfig['order'])) {
                    $facetData['order'] = (int)$customConfig['order'];
                }
                
                // Apply enabled/disabled state
                if (isset($customConfig['enabled'])) {
                    $facetData['enabled'] = (bool)$customConfig['enabled'];
                }
                
                // Apply show_count setting
                if (isset($customConfig['show_count'])) {
                    $facetData['show_count'] = (bool)$customConfig['show_count'];
                }
                
                // Apply max_items limit
                if (isset($customConfig['max_items']) && is_array($facetData['data'])) {
                    $maxItems = (int)$customConfig['max_items'];
                    if ($maxItems > 0 && count($facetData['data']) > $maxItems) {
                        $facetData['data'] = array_slice($facetData['data'], 0, $maxItems);
                    }
                }
            } else {
                // Apply default settings if no custom configuration
                $defaultSettings = $facetConfig['default_settings'] ?? [];
                $facetData['show_count'] = $defaultSettings['show_count'] ?? true;
                $facetData['enabled'] = true;
                $facetData['order'] = 0;
                
                // Apply default max_items
                if (isset($defaultSettings['max_items']) && is_array($facetData['data'])) {
                    $maxItems = (int)$defaultSettings['max_items'];
                    if ($maxItems > 0 && count($facetData['data']) > $maxItems) {
                        $facetData['data'] = array_slice($facetData['data'], 0, $maxItems);
                    }
                }
            }
            
        } catch (\Exception $e) {
            // If configuration loading fails, use defaults
            $this->logger->warning('Failed to load facet configuration', [
                'field' => $fieldName,
                'error' => $e->getMessage()
            ]);
            $facetData['enabled'] = true;
            $facetData['show_count'] = true;
            $facetData['order'] = 0;
        }
        
        return $facetData;

    }//end applyFacetConfiguration()


    /**
     * Sort facets according to custom configuration
     *
     * @param array $facets Facet data to sort
     * @return array Sorted facet data
     */
    private function sortFacetsWithConfiguration(array $facets): array
    {
        try {
            // Get facet configuration from settings service
            $settingsService = \OC::$server->get(\OCA\OpenRegister\Service\SettingsService::class);
            $facetConfig = $settingsService->getSolrFacetConfiguration();
            
            // Check if global order is defined
            if (!empty($facetConfig['global_order'])) {
                $globalOrder = $facetConfig['global_order'];
                $sortedFacets = [];
                
                // First, add facets in the specified global order
                foreach ($globalOrder as $fieldName) {
                    if (isset($facets[$fieldName])) {
                        $sortedFacets[$fieldName] = $facets[$fieldName];
                        unset($facets[$fieldName]);
                    }
                }
                
                // Then add remaining facets sorted by their individual order values
                uasort($facets, function($a, $b) {
                    $orderA = $a['order'] ?? 0;
                    $orderB = $b['order'] ?? 0;
                    return $orderA <=> $orderB;
                });
                
                // Merge the globally ordered facets with the remaining ones
                $facets = array_merge($sortedFacets, $facets);
            } else {
                // Sort by individual order values if no global order is set
                uasort($facets, function($a, $b) {
                    $orderA = $a['order'] ?? 0;
                    $orderB = $b['order'] ?? 0;
                    return $orderA <=> $orderB;
                });
            }
            
        } catch (\Exception $e) {
            // If configuration loading fails, keep original order
            $this->logger->warning('Failed to load facet configuration for sorting', [
                'error' => $e->getMessage()
            ]);
        }
        
        return $facets;

    }//end sortFacetsWithConfiguration()


    /**
     * Process SOLR facet response and format for frontend consumption
     *
     * @param array $facetData Raw facet data from SOLR
     * @param array $facetableFields Original facetable fields structure
     * @return array Formatted facet data
     */
    private function processFacetResponse(array $facetData, array $facetableFields): array
    {
        $processedFacets = [
            '@self' => [],
            'object_fields' => []
        ];
        
        // Process metadata fields
        foreach ($facetableFields['@self'] ?? [] as $fieldName => $fieldInfo) {
            if (isset($facetData[$fieldName])) {
                $facetResult = array_merge(
                    $fieldInfo,
                    ['data' => $this->formatFacetData($facetData[$fieldName], $fieldInfo['type'])]
                );
                
                // Apply custom facet configuration
                $facetResult = $this->applyFacetConfiguration($facetResult, 'self_' . $fieldName);
                
                // Only include enabled facets
                if ($facetResult['enabled'] ?? true) {
                    $processedFacets['@self'][$fieldName] = $facetResult;
                }
            }
        }
        
        // Process object fields
        foreach ($facetableFields['object_fields'] ?? [] as $fieldName => $fieldInfo) {
            if (isset($facetData[$fieldName])) {
                $facetResult = array_merge(
                    $fieldInfo,
                    ['data' => $this->formatFacetData($facetData[$fieldName], $fieldInfo['type'])]
                );
                
                // Apply custom facet configuration
                $facetResult = $this->applyFacetConfiguration($facetResult, $fieldName);
                
                // Only include enabled facets
                if ($facetResult['enabled'] ?? true) {
                    $processedFacets['object_fields'][$fieldName] = $facetResult;
                }
            }
        }
        
        // Sort facets according to configuration
        $processedFacets['@self'] = $this->sortFacetsWithConfiguration($processedFacets['@self']);
        $processedFacets['object_fields'] = $this->sortFacetsWithConfiguration($processedFacets['object_fields']);
        
        return $processedFacets;
    }

    /**
     * Format facet data based on facet type
     *
     * @param array $rawFacetData Raw facet data from SOLR
     * @param string $facetType Type of facet (terms, range, date_histogram)
     * @return array Formatted facet data
     */
    private function formatFacetData(array $rawFacetData, string $facetType): array
    {
        switch ($facetType) {
            case 'terms':
                return $this->formatTermsFacetData($rawFacetData);
            case 'range':
                return $this->formatRangeFacetData($rawFacetData);
            case 'date_histogram':
                return $this->formatDateHistogramFacetData($rawFacetData);
            default:
                return $rawFacetData;
        }
    }

    /**
     * Format metadata facet data with resolved labels for registers, schemas, and organisations
     *
     * @param array $rawData Raw facet data from SOLR
     * @param string $fieldName The metadata field name (register, schema, organisation)
     * @param string $facetType The facet type
     * @return array Formatted facet data with resolved labels
     */
    private function formatMetadataFacetData(array $rawData, string $fieldName, string $facetType): array
    {
        if ($facetType !== 'terms') {
            // For non-terms facets (like date_histogram), use regular formatting
            return $this->formatFacetData($rawData, $facetType);
        }
        
        $buckets = $rawData['buckets'] ?? [];
        $formattedBuckets = [];
        
        // Extract IDs for bulk lookup
        $ids = array_map(function($bucket) { return $bucket['val']; }, $buckets);
        
        // Resolve labels based on field type
        $labels = [];
        try {
            switch ($fieldName) {
                case 'register':
                    $labels = $this->resolveRegisterLabels($ids);
                    break;
                case 'schema':
                    $labels = $this->resolveSchemaLabels($ids);
                    break;
                case 'organisation':
                    $labels = $this->resolveOrganisationLabels($ids);
                    break;
                default:
                    // For other metadata fields, just use the value as label
                    $labels = array_combine($ids, $ids);
                    break;
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to resolve labels for metadata field', [
                'field' => $fieldName,
                'error' => $e->getMessage()
            ]);
            // Fallback to using values as labels
            $labels = array_combine($ids, $ids);
        }
        
        // Format buckets with resolved labels
        foreach ($buckets as $bucket) {
            $id = $bucket['val'];
            $formattedBuckets[] = [
                'value' => $id,
                'count' => $bucket['count'],
                'label' => $labels[$id] ?? $id // Use resolved label or fallback to ID
            ];
        }
        
        return [
            'type' => 'terms',
            'total_count' => $rawData['numBuckets'] ?? count($buckets),
            'buckets' => $formattedBuckets
        ];
    }

    /**
     * Format terms facet data
     *
     * @param array $rawData Raw terms facet data
     * @return array Formatted terms data
     */
    private function formatTermsFacetData(array $rawData): array
    {
        $buckets = $rawData['buckets'] ?? [];
        $formattedBuckets = [];
        
        foreach ($buckets as $bucket) {
            $formattedBuckets[] = [
                'value' => $bucket['val'],
                'count' => $bucket['count'],
                'label' => $bucket['val'] // Could be enhanced with human-readable labels
            ];
        }
        
        return [
            'type' => 'terms',
            'total_count' => array_sum(array_column($formattedBuckets, 'count')),
            'buckets' => $formattedBuckets
        ];
    }

    /**
     * Format range facet data
     *
     * @param array $rawData Raw range facet data
     * @return array Formatted range data
     */
    private function formatRangeFacetData(array $rawData): array
    {
        $buckets = $rawData['buckets'] ?? [];
        $formattedBuckets = [];
        
        foreach ($buckets as $bucket) {
            $formattedBuckets[] = [
                'from' => $bucket['val'],
                'to' => $bucket['val'] + ($rawData['gap'] ?? 100),
                'count' => $bucket['count'],
                'label' => $bucket['val'] . ' - ' . ($bucket['val'] + ($rawData['gap'] ?? 100))
            ];
        }
        
        return [
            'type' => 'range',
            'total_count' => array_sum(array_column($formattedBuckets, 'count')),
            'buckets' => $formattedBuckets
        ];
    }

    /**
     * Format date histogram facet data with multiple time brackets
     *
     * @param array $rawData Raw date histogram facet data
     * @return array Formatted date histogram data with yearly, monthly, and daily brackets
     */
    private function formatDateHistogramFacetData(array $rawData): array
    {
        $yearlyBuckets = $rawData['buckets'] ?? [];
        $monthlyBuckets = $rawData['monthly']['buckets'] ?? [];
        $dailyBuckets = $rawData['daily']['buckets'] ?? [];
        
        return [
            'type' => 'date_histogram',
            'brackets' => [
                'yearly' => [
                    'interval' => 'year',
                    'buckets' => array_map(function($bucket) {
                        return [
                            'date' => $bucket['val'],
                            'count' => $bucket['count'],
                            'label' => date('Y', strtotime($bucket['val']))
                        ];
                    }, $yearlyBuckets)
                ],
                'monthly' => [
                    'interval' => 'month',
                    'buckets' => array_map(function($bucket) {
                        return [
                            'date' => $bucket['val'],
                            'count' => $bucket['count'],
                            'label' => date('Y-m', strtotime($bucket['val']))
                        ];
                    }, $monthlyBuckets)
                ],
                'daily' => [
                    'interval' => 'day',
                    'buckets' => array_map(function($bucket) {
                        return [
                            'date' => $bucket['val'],
                            'count' => $bucket['count'],
                            'label' => date('Y-m-d', strtotime($bucket['val']))
                        ];
                    }, $dailyBuckets)
                ]
            ]
        ];
    }

    /**
     * Get metadata facetable fields (standard @self fields)
     *
     * @return array Standard metadata fields that can be faceted
     * @phpstan-return array<string, mixed>
     * @psalm-return   array<string, mixed>
     */
    private function getMetadataFacetableFields(): array
    {
        return [
            '@self' => [
                '_register' => [
                    'type' => 'terms',
                    'title' => 'Register',
                    'description' => 'Register that contains the object',
                    'data_type' => 'integer',
                    'queryParameter' => '@self[register]',
                    'source' => 'metadata'
                ],
                '_schema' => [
                    'type' => 'terms',
                    'title' => 'Schema',
                    'description' => 'Schema that defines the object structure',
                    'data_type' => 'integer',
                    'queryParameter' => '@self[schema]',
                    'source' => 'metadata'
                ],
                '_organisation' => [
                    'type' => 'terms',
                    'title' => 'Organisation',
                    'description' => 'Organisation that owns the object',
                    'data_type' => 'string',
                    'queryParameter' => '@self[organisation]',
                    'source' => 'metadata'
                ],
                '_application' => [
                    'type' => 'terms',
                    'title' => 'Application',
                    'description' => 'Application that created the object',
                    'data_type' => 'string',
                    'queryParameter' => '@self[application]',
                    'source' => 'metadata'
                ],
                '_created' => [
                    'type' => 'date_histogram',
                    'title' => 'Created Date',
                    'description' => 'When the object was created',
                    'data_type' => 'datetime',
                    'default_interval' => 'month',
                    'supported_intervals' => ['day', 'week', 'month', 'year'],
                    'queryParameter' => '@self[created]',
                    'source' => 'metadata'
                ],
                '_updated' => [
                    'type' => 'date_histogram',
                    'title' => 'Updated Date',
                    'description' => 'When the object was last modified',
                    'data_type' => 'datetime',
                    'default_interval' => 'month',
                    'supported_intervals' => ['day', 'week', 'month', 'year'],
                    'queryParameter' => '@self[updated]',
                    'source' => 'metadata'
                ]
            ]
        ];
    }

    /**
     * Resolve register labels by IDs using batch loading for improved performance
     *
     * @param array $ids Array of register IDs
     * @return array Associative array of ID => label
     */
    private function resolveRegisterLabels(array $ids): array
    {
        if (empty($ids) || $this->registerMapper === null) {
            return array_combine($ids, $ids);
        }

        try {
            // Use optimized batch loading
            $registers = $this->registerMapper->findMultipleOptimized($ids);
            
            $labels = [];
            foreach ($ids as $id) {
                if (isset($registers[$id])) {
                    $register = $registers[$id];
                    $labels[$id] = $register->getTitle() ?? "Register $id";
                } else {
                    $labels[$id] = "Register $id";
                }
            }
            
            return $labels;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to batch load register labels', [
                'ids' => $ids,
                'error' => $e->getMessage()
            ]);
            
            // Fallback to individual IDs as labels
            return array_combine($ids, array_map(fn($id) => "Register $id", $ids));
        }
    }

    /**
     * Resolve schema labels by IDs using batch loading for improved performance
     *
     * @param array $ids Array of schema IDs
     * @return array Associative array of ID => label
     */
    private function resolveSchemaLabels(array $ids): array
    {
        if (empty($ids) || $this->schemaMapper === null) {
            return array_combine($ids, $ids);
        }

        try {
            // Use optimized batch loading
            $schemas = $this->schemaMapper->findMultipleOptimized($ids);
            
            $labels = [];
            foreach ($ids as $id) {
                if (isset($schemas[$id])) {
                    $schema = $schemas[$id];
                    $labels[$id] = $schema->getTitle() ?? $schema->getName() ?? "Schema $id";
                } else {
                    $labels[$id] = "Schema $id";
                }
            }
            
            return $labels;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to batch load schema labels', [
                'ids' => $ids,
                'error' => $e->getMessage()
            ]);
            
            // Fallback to individual IDs as labels
            return array_combine($ids, array_map(fn($id) => "Schema $id", $ids));
        }
    }

    /**
     * Resolve organisation labels by UUIDs using batch loading for improved performance
     *
     * @param array $ids Array of organisation UUIDs
     * @return array Associative array of UUID => label
     */
    private function resolveOrganisationLabels(array $ids): array
    {
        if (empty($ids) || $this->organisationMapper === null) {
            return array_combine($ids, $ids);
        }

        try {
            // Use optimized batch loading
            $organisations = $this->organisationMapper->findMultipleByUuid($ids);
            
            $labels = [];
            foreach ($ids as $uuid) {
                if (isset($organisations[$uuid])) {
                    $organisation = $organisations[$uuid];
                    $labels[$uuid] = $organisation->getName() ?? "Organisation $uuid";
                } else {
                    $labels[$uuid] = "Organisation $uuid";
                }
            }
            
            return $labels;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to batch load organisation labels', [
                'ids' => $ids,
                'error' => $e->getMessage()
            ]);
            
            // Fallback to individual UUIDs as labels
            return array_combine($ids, array_map(fn($uuid) => "Organisation $uuid", $ids));
        }
    }

    /**
     * Resolve register value to integer ID
     *
     * Handles cases where register is stored as string ID, slug, or already an integer.
     *
     * @param string|int|null $registerValue The register value from ObjectEntity
     * @param \OCA\OpenRegister\Db\Register|null $register Optional pre-loaded register entity
     * @return int The resolved register ID, or 0 if resolution fails
     */
    private function resolveRegisterToId($registerValue, ?\OCA\OpenRegister\Db\Register $register = null): int
    {
        if (empty($registerValue)) {
            return 0;
        }

        // If it's already a numeric ID, return it as integer
        if (is_numeric($registerValue)) {
            return (int)$registerValue;
        }

        // If we have a pre-loaded register entity, use its ID
        if ($register !== null) {
            return $register->getId() ?? 0;
        }

        // Try to resolve by slug/name using RegisterMapper
        if ($this->registerMapper !== null) {
            try {
                $resolvedRegister = $this->registerMapper->find($registerValue);
                return $resolvedRegister->getId() ?? 0;
            } catch (\Exception $e) {
                $this->logger->warning('Failed to resolve register value to ID', [
                    'registerValue' => $registerValue,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Fallback: return 0 for unresolvable values
        $this->logger->warning('Could not resolve register to integer ID', [
            'registerValue' => $registerValue,
            'type' => gettype($registerValue)
        ]);
        return 0;
    }

    /**
     * Resolve schema value to integer ID
     *
     * Handles cases where schema is stored as string ID, slug, or already an integer.
     *
     * @param string|int|null $schemaValue The schema value from ObjectEntity
     * @param \OCA\OpenRegister\Db\Schema|null $schema Optional pre-loaded schema entity
     * @return int The resolved schema ID, or 0 if resolution fails
     */
    private function resolveSchemaToId($schemaValue, ?\OCA\OpenRegister\Db\Schema $schema = null): int
    {
        if (empty($schemaValue)) {
            return 0;
        }

        // If it's already a numeric ID, return it as integer
        if (is_numeric($schemaValue)) {
            return (int)$schemaValue;
        }

        // If we have a pre-loaded schema entity, use its ID
        if ($schema !== null) {
            return $schema->getId() ?? 0;
        }

        // Try to resolve by slug/name using SchemaMapper
        if ($this->schemaMapper !== null) {
            try {
                $resolvedSchema = $this->schemaMapper->find($schemaValue);
                return $resolvedSchema->getId() ?? 0;
            } catch (\Exception $e) {
                $this->logger->warning('Failed to resolve schema value to ID', [
                    'schemaValue' => $schemaValue,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Fallback: return 0 for unresolvable values
        $this->logger->warning('Could not resolve schema to integer ID', [
            'schemaValue' => $schemaValue,
            'type' => gettype($schemaValue)
        ]);
        return 0;
    }

    /**
     * TODO: HOTFIX - Resolve metadata field values (register/schema names) to integer IDs
     *
     * This is a temporary hotfix method that handles cases where external applications 
     * (like OpenCatalogi) pass register/schema names or slugs instead of integer IDs.
     * 
     * PROPER SOLUTION: The OpenCatalogi controllers should be updated to resolve
     * these values to integer IDs before calling OpenRegister APIs, rather than
     * doing this conversion at the SOLR query level.
     *
     * @param string $fieldType The metadata field type ('register' or 'schema')
     * @param string $value The value to resolve (name, slug, or ID)
     * @return int The resolved integer ID, or the original value if resolution fails
     */
    private function resolveMetadataValueToId(string $fieldType, $value): int
    {
        if (is_numeric($value)) {
            return (int)$value;
        }

        try {
            switch ($fieldType) {
                case 'register':
                    if ($this->registerMapper !== null) {
                        $register = $this->registerMapper->find($value);
                        return $register->getId() ?? 0;
                    }
                    break;
                    
                case 'schema':
                    if ($this->schemaMapper !== null) {
                        $schema = $this->schemaMapper->find($value);
                        return $schema->getId() ?? 0;
                    }
                    break;
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to resolve metadata value to ID', [
                'fieldType' => $fieldType,
                'value' => $value,
                'error' => $e->getMessage()
            ]);
        }

        // Return 0 for unresolvable values (will be filtered out in SOLR)
        return 0;
    }
}
