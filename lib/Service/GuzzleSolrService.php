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
        
        // Check required configuration values
        $requiredConfig = ['host', 'port', 'collection'];
        foreach ($requiredConfig as $key) {
            if (empty($this->solrConfig[$key])) {
                $this->logger->debug('SOLR configuration missing required key: ' . $key);
                return false;
            }
        }
        
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
        $baseCollectionName = $this->solrConfig['core'] ?? 'openregister';
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
     */
    public function createCollection(string $collectionName, string $configSetName): bool
    {
        try {
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

            $this->logger->error('SOLR collection creation failed', [
                'collection' => $collectionName,
                'response' => $data
            ]);
            return false;

        } catch (\Exception $e) {
            $this->logger->error('Exception creating SOLR collection', [
                'collection' => $collectionName,
                'error' => $e->getMessage()
            ]);
            return false;
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
        // **DEBUG**: Track individual indexObject calls
        $this->logger->debug('=== GUZZLE indexObject() CALLED ===');
        $this->logger->debug('Object ID: ' . $object->getId());
        $this->logger->debug('Object UUID: ' . ($object->getUuid() ?? 'null'));
        $this->logger->debug('Called from: ' . (debug_backtrace()[1]['function'] ?? 'unknown'));
        $this->logger->debug('=== END indexObject DEBUG ===');
        
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
            $document = $this->createSolrDocument($object);
            
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
    private function createSolrDocument(ObjectEntity $object, array $solrFieldTypes = []): array
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
            'self_object_id' => $object->getId(),
            'self_uuid' => $object->getUuid(),
            
            // Context fields
            'self_register' => (int)$object->getRegister(),
            'self_register_id' => (int)$object->getRegister(),
            'self_register_uuid' => $register?->getUuid(),
            'self_register_slug' => $register?->getSlug(),
            
            'self_schema' => (int)$object->getSchema(),
            'self_schema_id' => (int)$object->getSchema(),
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
            'self_object' => json_encode($objectData ?: [])
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
        
        // Remove null values to prevent SOLR errors
        return array_filter($document, fn($value) => $value !== null && $value !== '');
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
        $document['self_object_id'] = $object->getId();
        $document['self_uuid'] = $uuid;
        $document['self_register'] = $object->getRegister();
        $document['self_schema'] = $object->getSchema();
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

        // Remove null values
        return array_filter($document, fn($value) => $value !== null && $value !== '');
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
            throw new \Exception(
                'SOLR is not properly configured. Please check your SOLR settings in the OpenRegister admin panel. ' .
                'Verify that SOLR URL, collection name, and authentication are correctly set.'
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
            $searchResults = $this->executeSearch($solrQuery, $collectionName);
            
            // Convert SOLR results to OpenRegister paginated format
            $paginatedResults = $this->convertToOpenRegisterPaginatedFormat($searchResults, $query);
            
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
        
        // Multi-tenancy filtering with published object exception
        if ($multi) {
            $multitenancyEnabled = $this->isMultitenancyEnabled();
            if ($multitenancyEnabled) {
                $activeOrganisationUuid = $this->getActiveOrganisationUuid();
                if ($activeOrganisationUuid !== null) {
                    // Include objects from user's organisation OR published objects from any organisation
                    $filters[] = '(self_organisation:' . $this->escapeSolrValue($activeOrganisationUuid) . ' OR ' . $publishedCondition . ')';
                }
            }
        }
        
        // RBAC filtering with published object exception
        if ($rbac) {
            // Note: RBAC role filtering would be implemented here if we had role-based fields
            // For now, we assume all authenticated users have basic access
            // Published objects bypass RBAC restrictions
            $this->logger->debug('[SOLR] RBAC filtering applied with published object exception');
        }
        
        // Published filtering (only if explicitly requested)
        if ($published) {
            $filters[] = $publishedCondition;
        }
        
        // Deleted filtering
        if ($deleted) {
            // Include only deleted objects
            $filters[] = 'self_deleted:[* TO *]';
        } else {
            // Exclude deleted objects (default behavior)
            $filters[] = '-self_deleted:[* TO *]';
        }
        
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
        try {
            // Extract metadata from self_ fields
            // Handle both single values and arrays (SOLR can return either)
            $objectId = is_array($doc['self_object_id'] ?? null) ? ($doc['self_object_id'][0] ?? null) : ($doc['self_object_id'] ?? null);
            $uuid = is_array($doc['self_uuid'] ?? null) ? ($doc['self_uuid'][0] ?? null) : ($doc['self_uuid'] ?? null);
            $register = is_array($doc['self_register'] ?? null) ? ($doc['self_register'][0] ?? null) : ($doc['self_register'] ?? null);
            $schema = is_array($doc['self_schema'] ?? null) ? ($doc['self_schema'][0] ?? null) : ($doc['self_schema'] ?? null);
            
            if (!$objectId || !$register || !$schema) {
                $this->logger->warning('[GuzzleSolrService] Invalid document missing required fields', [
                    'doc_id' => $doc['id'] ?? 'unknown',
                    'object_id' => $objectId,
                    'register' => $register,
                    'schema' => $schema
                ]);
                return null;
            }

            // Create ObjectEntity instance
            $entity = new \OCA\OpenRegister\Db\ObjectEntity();
            $entity->setId($objectId);
            $entity->setUuid($uuid);
            $entity->setRegister($register);
            $entity->setSchema($schema);
            
            // Set metadata fields
            $entity->setOrganisation(is_array($doc['self_organisation'] ?? null) ? ($doc['self_organisation'][0] ?? null) : ($doc['self_organisation'] ?? null));
            $entity->setName(is_array($doc['self_name'] ?? null) ? ($doc['self_name'][0] ?? null) : ($doc['self_name'] ?? null));
            $entity->setDescription(is_array($doc['self_description'] ?? null) ? ($doc['self_description'][0] ?? null) : ($doc['self_description'] ?? null));
            $entity->setSummary(is_array($doc['self_summary'] ?? null) ? ($doc['self_summary'][0] ?? null) : ($doc['self_summary'] ?? null));
            $entity->setImage(is_array($doc['self_image'] ?? null) ? ($doc['self_image'][0] ?? null) : ($doc['self_image'] ?? null));
            $entity->setSlug(is_array($doc['self_slug'] ?? null) ? ($doc['self_slug'][0] ?? null) : ($doc['self_slug'] ?? null));
            $entity->setUri(is_array($doc['self_uri'] ?? null) ? ($doc['self_uri'][0] ?? null) : ($doc['self_uri'] ?? null));
            $entity->setVersion(is_array($doc['self_version'] ?? null) ? ($doc['self_version'][0] ?? null) : ($doc['self_version'] ?? null));
            $entity->setSize(is_array($doc['self_size'] ?? null) ? ($doc['self_size'][0] ?? null) : ($doc['self_size'] ?? null));
            $entity->setOwner(is_array($doc['self_owner'] ?? null) ? ($doc['self_owner'][0] ?? null) : ($doc['self_owner'] ?? null));
            $entity->setLocked(is_array($doc['self_locked'] ?? null) ? ($doc['self_locked'][0] ?? null) : ($doc['self_locked'] ?? null));
            $entity->setFolder(is_array($doc['self_folder'] ?? null) ? ($doc['self_folder'][0] ?? null) : ($doc['self_folder'] ?? null));
            $entity->setApplication(is_array($doc['self_application'] ?? null) ? ($doc['self_application'][0] ?? null) : ($doc['self_application'] ?? null));
            
            // Set datetime fields
            $created = is_array($doc['self_created'] ?? null) ? ($doc['self_created'][0] ?? null) : ($doc['self_created'] ?? null);
            if ($created) {
                $entity->setCreated(new \DateTime($created));
            }
            $updated = is_array($doc['self_updated'] ?? null) ? ($doc['self_updated'][0] ?? null) : ($doc['self_updated'] ?? null);
            if ($updated) {
                $entity->setUpdated(new \DateTime($updated));
            }
            $published = is_array($doc['self_published'] ?? null) ? ($doc['self_published'][0] ?? null) : ($doc['self_published'] ?? null);
            if ($published) {
                $entity->setPublished(new \DateTime($published));
            }
            $depublished = is_array($doc['self_depublished'] ?? null) ? ($doc['self_depublished'][0] ?? null) : ($doc['self_depublished'] ?? null);
            if ($depublished) {
                $entity->setDepublished(new \DateTime($depublished));
            }
            
            // Reconstruct object data from JSON or individual fields
            $objectData = [];
            $selfObject = is_array($doc['self_object'] ?? null) ? ($doc['self_object'][0] ?? null) : ($doc['self_object'] ?? null);
            if ($selfObject) {
                $objectData = json_decode($selfObject, true) ?: [];
            } else {
                // Fallback: extract object properties from root level fields
                foreach ($doc as $key => $value) {
                    if (!str_starts_with($key, 'self_') && 
                        !in_array($key, ['id', 'tenant_id', '_text_', '_version_', '_root_'])) {
                        // Remove Solr type suffixes
                        $cleanKey = preg_replace('/_(s|t|i|f|b)$/', '', $key);
                        $objectData[$cleanKey] = is_array($value) ? $value[0] : $value;
                    }
                }
            }
            
            $entity->setObject($objectData);
            
            // Set complex fields from JSON
            $authorization = is_array($doc['self_authorization'] ?? null) ? ($doc['self_authorization'][0] ?? null) : ($doc['self_authorization'] ?? null);
            if ($authorization) {
                $entity->setAuthorization(json_decode($authorization, true));
            }
            $deleted = is_array($doc['self_deleted'] ?? null) ? ($doc['self_deleted'][0] ?? null) : ($doc['self_deleted'] ?? null);
            if ($deleted) {
                $entity->setDeleted(json_decode($deleted, true));
            }
            $validation = is_array($doc['self_validation'] ?? null) ? ($doc['self_validation'][0] ?? null) : ($doc['self_validation'] ?? null);
            if ($validation) {
                $entity->setValidation(json_decode($validation, true));
            }
            $groups = is_array($doc['self_groups'] ?? null) ? ($doc['self_groups'][0] ?? null) : ($doc['self_groups'] ?? null);
            if ($groups) {
                $entity->setGroups(json_decode($groups, true));
            }

            return $entity;
            
        } catch (\Exception $e) {
            $this->logger->error('[GuzzleSolrService] Failed to reconstruct object from Solr document', [
                'doc_id' => $doc['id'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return null;
        }
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
     * @return bool True if successful
     */
    public function deleteByQuery(string $query, bool $commit = false): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            // Get the active collection name - return false if no collection exists
            $tenantCollectionName = $this->getActiveCollectionName();
            if ($tenantCollectionName === null) {
                $this->logger->warning('Cannot delete by query: no active collection available');
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
            }

            return $success;

        } catch (\Exception $e) {
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
            $searchResults = $this->executeSearch($solrQuery, $collectionName);
            
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
        // Priority order: self_name > self_summary > self_description > legacy fields > catch-all
        // **FIX**: Removed 'beschrijving' field as it doesn't exist in SOLR schema
        $fieldWeights = [
            'self_name' => 15.0,       // OpenRegister standardized name (highest priority)
            'self_summary' => 10.0,    // OpenRegister standardized summary
            'self_description' => 7.0, // OpenRegister standardized description
            'naam' => 5.0,             // Legacy name field (lower priority)
            'beschrijvingKort' => 3.0, // Legacy short description
            // 'beschrijving' => 2.0,  // REMOVED: Field doesn't exist in SOLR schema
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

        // Handle search query with wildcard support and field weighting
        if (!empty($query['_search'])) {
            $searchTerm = trim($query['_search']);
            
            // Build weighted multi-field search query
            $searchQuery = $this->buildWeightedSearchQuery($searchTerm);
            $solrQuery['q'] = $searchQuery;
            
            
            // Enable highlighting for search results (prioritize self_* fields)
            $solrQuery['hl'] = 'true';
            $solrQuery['hl.fl'] = 'self_name,self_summary,self_description,naam,beschrijvingKort';
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
                    if (is_array($metaValue)) {
                        $conditions = array_map(function($v) use ($solrField) {
                            return $solrField . ':' . (is_numeric($v) ? $v : '"' . $this->escapeSolrValue((string)$v) . '"');
                        }, $metaValue);
                        $filters[] = '(' . implode(' OR ', $conditions) . ')';
                    } else {
                        if (is_numeric($metaValue)) {
                            $filters[] = $solrField . ':' . $metaValue;
                        } else {
                            $filters[] = $solrField . ':"' . $this->escapeSolrValue((string)$metaValue) . '"';
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
        


        // Handle facets
        if (!empty($query['_facets'])) {
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
     * @return array Search results
     * @throws \Exception When search fails
     */
    private function executeSearch(array $solrQuery, string $collectionName): array
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
            $this->logger->debug('Executing SOLR search', [
                'full_url' => $fullUrl,
                'collection' => $collectionName,
                'query_string' => $queryString
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


            return $this->parseSolrResponse($responseData);

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
     * @return array Parsed search results
     */
    private function parseSolrResponse(array $responseData): array
    {
        $results = [
            'objects' => [],
            'total' => 0,
            'facets' => []
        ];

        // Parse documents and convert back to OpenRegister objects
        if (isset($responseData['response']['docs'])) {
            $results['objects'] = $this->convertSolrDocumentsToOpenRegisterObjects($responseData['response']['docs']);
            $results['total'] = $responseData['response']['numFound'] ?? count($results['objects']);
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
    private function convertToOpenRegisterPaginatedFormat(array $searchResults, array $originalQuery): array
    {
        $limit = (int)($originalQuery['_limit'] ?? 20);
        $page = (int)($originalQuery['_page'] ?? 1);
        $offset = (int)($originalQuery['_offset'] ?? (($page - 1) * $limit));
        $total = $searchResults['total'] ?? 0;
        $pages = $limit > 0 ? max(1, ceil($total / $limit)) : 1;

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
            '_source' => 'index'
        ];

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
     * and merges it with essential metadata from the SOLR document.
     *
     * @param array $solrDocuments Array of SOLR documents
     * @return array Array of OpenRegister objects
     */
    private function convertSolrDocumentsToOpenRegisterObjects(array $solrDocuments): array
    {
        $openRegisterObjects = [];

        foreach ($solrDocuments as $solrDoc) {
            try {
                // Extract the actual object from self_object field
                $actualObject = null;
                if (isset($solrDoc['self_object']) && is_array($solrDoc['self_object']) && !empty($solrDoc['self_object'])) {
                    // self_object is stored as JSON string in an array
                    $objectJson = $solrDoc['self_object'][0];
                    $actualObject = json_decode($objectJson, true);
                    
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $this->logger->warning('Failed to decode self_object JSON for document', [
                            'document_id' => $solrDoc['id'] ?? 'unknown',
                            'json_error' => json_last_error_msg(),
                            'raw_json' => substr($objectJson, 0, 200) . '...'
                        ]);
                        continue;
                    }
                }

                // If we couldn't extract the actual object, skip this document
                if ($actualObject === null) {
                    $this->logger->warning('No valid self_object found in SOLR document', [
                        'document_id' => $solrDoc['id'] ?? 'unknown'
                    ]);
                    continue;
                }

                // Add essential metadata from SOLR document to the object
                $actualObject['@self'] = [
                    'id' => $solrDoc['self_uuid'] ?? $solrDoc['id'],
                    'slug' => null, // Not stored in SOLR
                    'name' => $solrDoc['self_name'] ?? null,
                    'description' => null, // Not stored separately in SOLR
                    'summary' => null, // Not stored separately in SOLR
                    'image' => null, // Not stored separately in SOLR
                    'uri' => null, // Not stored separately in SOLR
                    'version' => null, // Not stored separately in SOLR
                    'register' => (string)($solrDoc['self_register'] ?? ''),
                    'schema' => (string)($solrDoc['self_schema'] ?? ''),
                    'schemaVersion' => null, // Not stored separately in SOLR
                    'files' => [], // Files would need separate handling
                    'relations' => [], // Relations would need separate handling
                    'locked' => null, // Not stored in SOLR
                    'owner' => $solrDoc['self_owner'] ?? null,
                    'organisation' => $solrDoc['self_organisation'] ?? null,
                    'groups' => [], // Not stored in SOLR
                    'authorization' => [], // Not stored in SOLR
                    'folder' => null, // Not stored in SOLR
                    'application' => null, // Not stored in SOLR
                    'validation' => [], // Not stored in SOLR
                    'geo' => [], // Not stored in SOLR
                    'retention' => [], // Not stored in SOLR
                    'size' => null, // Not stored in SOLR
                    'updated' => isset($solrDoc['self_updated']) ? $solrDoc['self_updated'] : null,
                    'created' => isset($solrDoc['self_created']) ? $solrDoc['self_created'] : null,
                    'published' => null, // Not stored separately in SOLR
                    'depublished' => null, // Not stored separately in SOLR
                    'deleted' => [] // Not stored in SOLR
                ];

                $openRegisterObjects[] = $actualObject;

            } catch (\Exception $e) {
                $this->logger->error('Error converting SOLR document to OpenRegister object', [
                    'document_id' => $solrDoc['id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
                continue;
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
     * @return bool True if successful
     */
    public function clearIndex(): bool
    {
        return $this->deleteByQuery('*:*', true);
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

            // Get document count
            $docCount = $this->getDocumentCount();

            // Get index size (approximate)
            $indexSizeUrl = $this->buildSolrBaseUrl() . '/' . $tenantCollectionName . '/admin/luke?wt=json&numTerms=0';
            $sizeResponse = $this->httpClient->get($indexSizeUrl, ['timeout' => 10]);
            $sizeData = json_decode((string)$sizeResponse->getBody(), true);

            $collectionInfo = $statsData['cluster']['collections'][$tenantCollectionName] ?? [];
            $shards = $collectionInfo['shards'] ?? [];

            return [
                'available' => true,
                'tenant_id' => $this->tenantId,
                'collection' => $tenantCollectionName,
                'document_count' => $docCount,
                'shards' => count($shards),
                'index_version' => $sizeData['index']['version'] ?? 'unknown',
                'last_modified' => $sizeData['index']['lastModified'] ?? 'unknown',
                'service_stats' => $this->stats,
                'health' => !empty($collectionInfo) ? 'healthy' : 'degraded'
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
            
            $this->logger->info('Starting sequential bulk index from database using ObjectEntityMapper directly');
            
            // Get total count for planning using ObjectEntityMapper's countAll method
            $totalObjects = $objectMapper->countAll(
                rbac: false,  // Skip RBAC for performance
                multi: false  // Skip multitenancy for performance  
            );
            $this->logger->info('📊 Sequential bulk index planning', [
                'totalObjects' => $totalObjects,
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
                
                // Fetch objects directly from ObjectEntityMapper using simpler findAll method
                $fetchStart = microtime(true);
                $this->logger->info('📥 Fetching batch {batch} using ObjectEntityMapper::findAll', [
                    'batch' => $batchCount + 1,
                    'limit' => $currentBatchSize,
                    'offset' => $offset,
                    'totalProcessed' => $totalProcessed
                ]);
                
                $objects = $objectMapper->findAll(
                    limit: $currentBatchSize,
                    offset: $offset,
                    rbac: false,  // Skip RBAC for performance
                    multi: false  // Skip multitenancy for performance
                );
                
                $fetchEnd = microtime(true);
                $fetchDuration = round(($fetchEnd - $fetchStart) * 1000, 2);
                $this->logger->info('✅ Batch fetch complete', [
                    'batch' => $batchCount + 1,
                    'objectsFound' => count($objects),
                    'fetchTime' => $fetchDuration . 'ms'
                ]);
                
                $this->logger->debug('Fetched {count} objects from database', [
                    'count' => count($objects)
                ]);
                
                if (empty($objects)) {
                    $this->logger->debug('No more objects found, breaking pagination loop');
                    break; // No more objects
                }
                
                // **DEBUG**: Test bulk indexing with detailed logging
                $documents = [];
                foreach ($objects as $object) {
                    try {
                        if ($object instanceof ObjectEntity) {
                            $documents[] = $this->createSolrDocument($object, $solrFieldTypes);
                        } else if (is_array($object)) {
                            // Convert array to ObjectEntity if needed
                            $entity = new ObjectEntity();
                            $entity->hydrate($object);
                            $documents[] = $this->createSolrDocument($entity, $solrFieldTypes);
                        }
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
                    $this->logger->info('📤 Attempting bulk index to SOLR', [
                        'batch' => $batchCount + 1,
                        'documents' => count($documents),
                        'totalProcessedSoFar' => $totalProcessed
                    ]);
                    
                    // Debug first document structure
                    if (!empty($documents)) {
                        $firstDoc = $documents[0];
                        $this->logger->debug('First document structure', [
                            'batch' => $batchCount + 1,
                            'documentFields' => array_keys($firstDoc),
                            'hasId' => isset($firstDoc['id']),
                            'hasObject' => isset($firstDoc['self_object']),
                            'id' => $firstDoc['id'] ?? 'missing'
                        ]);
                    }
                    
                    $this->bulkIndex($documents, false); // Don't commit each batch - will throw on error
                    $indexed = count($documents); // If we reach here, indexing succeeded
                    
                    $indexEnd = microtime(true);
                    $indexDuration = round(($indexEnd - $indexStart) * 1000, 2);
                    
                    $this->logger->info('✅ Bulk index result', [
                        'batch' => $batchCount + 1,
                        'indexed' => $indexed,
                        'documentsProvided' => count($documents),
                        'indexTime' => $indexDuration . 'ms'
                    ]);
                } else {
                    $this->logger->warning('⚠️  No documents to bulk index', [
                        'batch' => $batchCount + 1,
                        'objects_count' => count($objects),
                        'possibleIssue' => 'Document creation failed for all objects in batch'
                    ]);
                }
                
                $this->logger->info('Indexed {indexed} objects in batch {batch}', [
                    'indexed' => $indexed,
                    'batch' => $batchCount + 1
                ]);
                
                // Commit after each batch
                if (!empty($objects)) {
                    $this->commit();
                    $this->logger->debug('Committed batch {batch} to Solr', [
                        'batch' => $batchCount + 1
                    ]);
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
                'batch_size' => $batchSize
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
            $this->logger->info('Starting parallel bulk index from database using ObjectEntityMapper', [
                'batchSize' => $batchSize,
                'maxObjects' => $maxObjects,
                'parallelBatches' => $parallelBatches
            ]);

            // First, get the total count to plan batches using ObjectEntityMapper's dedicated count method
            $countQuery = []; // Empty query to count all objects
            $totalObjects = $objectMapper->countSearchObjects($countQuery, null, false, false);
            
            // Total objects retrieved from database
            
            $this->logger->info('Total objects found for parallel indexing', [
                'totalFromDatabase' => $totalObjects,
                'maxObjectsLimit' => $maxObjects
            ]);
            
            if ($maxObjects > 0) {
                $totalObjects = min($totalObjects, $maxObjects);
                $this->logger->info('Applied maxObjects limit', [
                    'finalTotal' => $totalObjects
                ]);
            }

            $this->logger->info('Planning parallel batch processing', [
                'totalObjects' => $totalObjects,
                'estimatedBatches' => ceil($totalObjects / $batchSize)
            ]);

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

            $this->logger->info('Created batch jobs', [
                'totalJobs' => count($batchJobs),
                'parallelBatches' => $parallelBatches
            ]);

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
            // Fetch objects for this batch
            $objects = $objectMapper->searchObjects([
                '_offset' => $job['offset'],
                '_limit' => $job['limit'],
                '_bulk_operation' => true
            ]);

            if (empty($objects)) {
                return ['success' => true, 'indexed' => 0, 'batchNumber' => $job['batchNumber']];
            }

            // Create SOLR documents for the entire batch
            $documents = [];
            foreach ($objects as $object) {
                try {
                    if ($object instanceof ObjectEntity) {
                        $documents[] = $this->createSolrDocument($object);
                    } else if (is_array($object)) {
                        $entity = new ObjectEntity();
                        $entity->hydrate($object);
                        $documents[] = $this->createSolrDocument($entity);
                    }
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
                $this->bulkIndex($documents, false); // Don't commit each batch - will throw on error
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
            $query = [
                '_limit' => $job['limit'],
                '_offset' => $job['offset']
            ];

            $this->logger->debug('Processing batch async with ObjectEntityMapper', [
                'batchNumber' => $job['batchNumber'],
                'offset' => $job['offset'],
                'limit' => $job['limit']
            ]);

            $objects = $objectMapper->searchObjects($query, null, false, false);

            if (empty($objects)) {
                return ['success' => true, 'indexed' => 0, 'batchNumber' => $job['batchNumber']];
            }

            // Parallel batch processing
            
            // **PERFORMANCE**: Use bulk indexing for the entire batch
            $documents = [];
            foreach ($objects as $object) {
                try {
                    if ($object instanceof ObjectEntity) {
                        $documents[] = $this->createSolrDocument($object);
                    } else if (is_array($object)) {
                        $entity = new ObjectEntity();
                        $entity->hydrate($object);
                        $documents[] = $this->createSolrDocument($entity);
                    }
                } catch (\Exception $e) {
                    // Log document creation errors
                }
            }
            
            // Bulk index the entire batch
            $indexed = 0;
            if (!empty($documents)) {
                $success = $this->bulkIndex($documents, false); // Don't commit each batch
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

            $objects = $objectMapper->searchObjects($query, null, false, false);
            
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
                if ($object instanceof ObjectEntity) {
                    $documents[] = $this->createSolrDocument($object);
                } else if (is_array($object)) {
                    $entity = new ObjectEntity();
                    $entity->hydrate($object);
                    $documents[] = $this->createSolrDocument($entity);
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
                            if ($this->bulkIndex([$document], false)) {
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
     *
     * @return array Warmup results
     */
    public function warmupIndex(array $schemas = [], int $maxObjects = 0, string $mode = 'serial', bool $collectErrors = false): array
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
        
        try {
            // 1. Test connection
            $connectionResult = $this->testConnection();
            $operations['connection_test'] = $connectionResult['success'] ?? false;
            
            // 2. Schema mirroring with intelligent conflict resolution
            if (!empty($schemas)) {
                $stageStart = microtime(true);
                
                $this->logger->info('🔄 Starting schema mirroring with conflict resolution', [
                    'schema_count' => count($schemas)
                ]);
                
                // Lazy-load SolrSchemaService to avoid circular dependency
                $solrSchemaService = \OC::$server->get(SolrSchemaService::class);
                $mirrorResult = $solrSchemaService->mirrorSchemas(true); // Force update for testing
                $operations['schema_mirroring'] = $mirrorResult['success'] ?? false;
                $operations['schemas_processed'] = $mirrorResult['stats']['schemas_processed'] ?? 0;
                $operations['fields_created'] = $mirrorResult['stats']['fields_created'] ?? 0;
                $operations['conflicts_resolved'] = $mirrorResult['stats']['conflicts_resolved'] ?? 0;
                
                // 2.5. Collect current SOLR field types for validation (force refresh after schema changes)
                $solrFieldTypes = $this->getSolrFieldTypes(true);
                $operations['field_types_collected'] = count($solrFieldTypes);
                
                if ($mirrorResult['success']) {
                    $this->logger->info('✅ Schema mirroring completed successfully', [
                        'schemas_processed' => $operations['schemas_processed'],
                        'fields_created' => $operations['fields_created'],
                        'conflicts_resolved' => $operations['conflicts_resolved']
                    ]);
                } else {
                    $this->logger->error('❌ Schema mirroring failed', [
                        'error' => $mirrorResult['error'] ?? 'Unknown error'
                    ]);
                }
                
                $stageEnd = microtime(true);
                $timing['schema_mirroring'] = round(($stageEnd - $stageStart) * 1000, 2) . 'ms';
            } else {
                $operations['schema_mirroring'] = false;
                $operations['schemas_processed'] = 0;
                $operations['fields_created'] = 0;
                $operations['conflicts_resolved'] = 0;
                $timing['schema_mirroring'] = '0ms (no schemas provided)';
            }
            
            // 3. Object indexing using mode-based bulk indexing
            $this->logger->debug('=== WARMUP MODE DEBUG ===');
            $this->logger->debug('Mode: ' . $mode);
            $this->logger->debug('MaxObjects: ' . $maxObjects);
            
            if ($mode === 'hyper') {
                $this->logger->debug('Calling: bulkIndexFromDatabaseOptimized (hyper mode)');
                $indexResult = $this->bulkIndexFromDatabaseOptimized(2000, $maxObjects, $solrFieldTypes ?? []);
            } elseif ($mode === 'parallel') {
                $this->logger->debug('Calling: bulkIndexFromDatabaseParallel');
                $indexResult = $this->bulkIndexFromDatabaseParallel(1000, $maxObjects, 5, $solrFieldTypes ?? []);
            } else {
                $this->logger->debug('Calling: bulkIndexFromDatabase (serial)');
                $indexResult = $this->bulkIndexFromDatabase(1000, $maxObjects, $solrFieldTypes ?? []);
            }
            $this->logger->debug('=== END WARMUP MODE DEBUG ===');
            
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
            
            return [
                'success' => true,
                'operations' => $operations,
                'execution_time_ms' => round($executionTime, 2),
                'message' => 'GuzzleSolrService warmup completed (limited functionality - no schema mirroring)',
                'total_objects_found' => $indexResult['total'] ?? 0,
                'batches_processed' => $indexResult['batches'] ?? 0,
                'max_objects_limit' => $maxObjects
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'operations' => $operations,
                'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'error' => $e->getMessage()
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
            // Get total count efficiently
            $totalObjects = $this->objectEntityMapper->countAll();
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
                
                // Fetch objects efficiently
                $objects = $this->objectEntityMapper->findAll($currentBatchSize, $offset);
                
                if (empty($objects)) {
                    break;
                }
                
                // Create SOLR documents with field validation
                $documents = [];
                foreach ($objects as $object) {
                    try {
                        $document = $this->createSolrDocument($object, $solrFieldTypes);
                        if (!empty($document)) {
                            $documents[] = $document;
                        }
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
                    $indexResult = $this->bulkIndex($documents, false); // No commit per batch
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
}
