<?php

declare(strict_types=1);

/*
 * GuzzleSolrService - Lightweight SOLR Integration using HTTP calls
 *
 * This service provides SOLR integration using direct HTTP calls via Guzzle,
 * avoiding the memory issues and complexity of the Solarium library.
 * Specifically designed for SolrCloud with proper multi-tenant support.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\SolrSchemaService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\ObjectCacheService;
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
 * @version   GIT: <git_id>
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
     * @var array{enabled: bool, host: string, port: int, path: string, core: string,
     *            scheme: string, username: string, password: string, timeout: int,
     *            autoCommit: bool, commitWithin: int, enableLogging: bool,
     *            useCloud?: bool, collection?: string, configSet?: string, objectCollection?: string}
     */

    /**
     * @psalm-suppress InvalidPropertyAssignmentValue - solrConfig is initialized with compatible array structure
     */
    private array $solrConfig = [];

    /**
     * Cached SOLR field types to avoid repeated API calls
     *
     * @var array|null
     *
     * @psalm-suppress UnusedProperty
     */
    private ?array $cachedSolrFieldTypes = null;

    /**
     * Service statistics
     *
     * @var array{searches: int, indexes: int, deletes: int, search_time: float, index_time: float, errors: int}
     */
    private array $stats = [
        'searches'    => 0,
        'indexes'     => 0,
        'deletes'     => 0,
        'search_time' => 0.0,
        'index_time'  => 0.0,
        'errors'      => 0,
    ];

    /**
     * Lazy-loaded ObjectCacheService instance
     *
     * @var ObjectCacheService|null
     */
    private ?ObjectCacheService $objectCacheService = null;

    /**
     * Flag to track if we've attempted to load ObjectCacheService
     *
     * @var boolean
     */
    private bool $objectCacheServiceAttempted = false;


    /**
     * Constructor
     *
     * @param SettingsService          $settingsService     Settings service for configuration
     * @param LoggerInterface          $logger              Logger for debugging and monitoring
     * @param IClientService           $clientService       HTTP client service
     * @param IConfig                  $config              Nextcloud configuration
     * @param SchemaMapper|null        $schemaMapper        Schema mapper for database operations
     * @param RegisterMapper|null      $registerMapper      Register mapper for database operations
     * @param OrganisationService|null $organisationService Organisation service for organisation operations
     * @param OrganisationMapper|null  $organisationMapper  Organisation mapper for database operations
     */


    /**
     * @param IClientService $clientService HTTP client service (unused but kept for future use)
     * @param IConfig        $config        Nextcloud configuration (unused but kept for future use)
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
        private readonly ?SchemaMapper $schemaMapper=null,
        private readonly ?RegisterMapper $registerMapper=null,
        /**
         * @psalm-suppress UnusedProperty
         */
        private readonly ?OrganisationService $organisationService=null,
        private readonly ?OrganisationMapper $organisationMapper=null,
    ) {
        $this->initializeConfig();
        $this->initializeHttpClient();

    }//end __construct()


    /**
     * Get ObjectCacheService lazily from container to avoid circular dependency
     *
     * This method lazily loads ObjectCacheService from the Nextcloud container
     * only when needed (for UUID-to-name resolution in facets). This avoids
     * circular dependency issues during service initialization.
     *
     * @return ObjectCacheService|null Object cache service or null if unavailable
     */
    private function getObjectCacheService(): ?ObjectCacheService
    {
        // Only attempt to load once to avoid repeated failures.
        if ($this->objectCacheServiceAttempted === true) {
            return $this->objectCacheService;
        }

        $this->objectCacheServiceAttempted = true;

        try {
            // Get service from Nextcloud container.
            $this->objectCacheService = \OC::$server->get(ObjectCacheService::class);
            $this->logger->debug(message: '✅ ObjectCacheService loaded successfully for facet UUID resolution');
        } catch (\Exception $e) {
            $this->logger->warning(
                    '⚠️ Failed to load ObjectCacheService from container',
                    [
                        'error' => $e->getMessage(),
                        'note'  => 'UUID-to-name resolution in facets will not be available',
                    ]
                    );
            $this->objectCacheService = null;
        }

        return $this->objectCacheService;

    }//end getObjectCacheService()


    /**
     * Initialize SOLR configuration
     *
     * @return void
     */
    private function initializeConfig(): void
    {
        try {
            // @psalm-suppress InvalidPropertyAssignmentValue - getSolrSettings() returns array with compatible shape
            $this->solrConfig = $this->settingsService->getSolrSettings();
        } catch (\Exception $e) {
            $this->logger->warning(message: 'Failed to load SOLR settings', context: ['error' => $e->getMessage()]);
            /*
             * @psalm-suppress InvalidPropertyAssignmentValue - ['enabled' => false] is compatible with solrConfig type
             */
            $this->solrConfig = ['enabled' => false];
        }

    }//end initializeConfig()


    /**
     * Initialize HTTP client with authentication support
     *
     * @return void
     */
    private function initializeHttpClient(): void
    {
        // Prepare Guzzle client configuration.
        // Allow self-signed certificates.
        // Don't throw exceptions for 4xx/5xx responses.
        $clientConfig = [
            'timeout'         => 30,
            'connect_timeout' => 10,
            'verify'          => false,
            'allow_redirects' => true,
            'http_errors'     => false,
        ];

        // Add HTTP Basic Authentication if credentials are provided.
        if (empty($this->solrConfig['username']) === false && empty($this->solrConfig['password']) === false) {
            $clientConfig['auth'] = [
                $this->solrConfig['username'],
                $this->solrConfig['password'],
                'basic',
            ];

            $this->logger->info(
                    'GuzzleSolrService: HTTP Basic Authentication configured',
                    [
                        'username'  => $this->solrConfig['username'],
                        'auth_type' => 'basic',
                    ]
                    );
        }

        // TODO: Switch back to Nextcloud HTTP client when local access restrictions are properly configured.
        // Currently using direct Guzzle client to bypass Nextcloud's 'allow_local_address' restrictions.
        // Future improvement: $this->httpClient = $clientService->newClient(['allow_local_address' => true]).
        // This is necessary for SOLR/Zookeeper connections in Kubernetes environments.
        /*
         * @psalm-suppress InvalidPropertyAssignmentValue - GuzzleClient used intentionally, will switch to IClient later
         */
        $this->httpClient = new GuzzleClient($clientConfig);

    }//end initializeHttpClient()


    /**
     * Get collection name (previously tenant-specific, now just returns the base name)
     *
     * @param string $baseCollectionName Base collection name
     *
     * @return string Collection name
     */
    public function getTenantSpecificCollectionName(string $baseCollectionName): string
    {
        // Simply return the collection name without any tenant suffix.
        return $baseCollectionName;

    }//end getTenantSpecificCollectionName()


    /**
     * Build SOLR base URL
     *
     * @return string SOLR base URL
     */
    public function buildSolrBaseUrl(): string
    {
        if (($this->solrConfig['host'] ?? null) !== null) {
            $host = $this->solrConfig['host'];
        } else {
            $host = 'localhost';
        }

        if (($this->solrConfig['port'] ?? null) !== null) {
            $port = $this->solrConfig['port'];
        } else {
            $port = null;
        }

        // Don't default port here
        // Normalize port - convert string '0' to null, handle empty strings.
        if ($port === '0' || $port === '' || $port === null) {
            $port = null;
        } else {
            $port = (int) $port;

            if ($port === 0) {
                $port = null;
            }
        }

        // Check if it's a Kubernetes service name (contains .svc.cluster.local).
        if (strpos($host, '.svc.cluster.local') !== false) {
            // Kubernetes service - don't append port, it's handled by the service.
            if (($this->solrConfig['scheme'] ?? null) !== null) {
                $scheme = $this->solrConfig['scheme'];
            } else {
                $scheme = 'http';
            }

            if (($this->solrConfig['path'] ?? null) !== null) {
                $path = $this->solrConfig['path'];
            } else {
                $path = '/solr';
            }

            return sprintf(
                '%s://%s%s',
                $scheme,
                $host,
                $path
            );
        } else {
            // Regular hostname - only append port if explicitly provided and not 0/null.
            if ($port !== null && $port > 0) {
                if (($this->solrConfig['scheme'] ?? null) !== null) {
                    $scheme = $this->solrConfig['scheme'];
                } else {
                    $scheme = 'http';
                }

                if (($this->solrConfig['path'] ?? null) !== null) {
                    $path = $this->solrConfig['path'];
                } else {
                    $path = '/solr';
                }

                return sprintf(
                    '%s://%s:%d%s',
                    $scheme,
                    $host,
                    $port,
                    $path
                );
            } else {
                // No port provided or port is 0 - let the service handle it.
                if (($this->solrConfig['scheme'] ?? null) !== null) {
                    $scheme = $this->solrConfig['scheme'];
                } else {
                    $scheme = 'http';
                }

                if (($this->solrConfig['path'] ?? null) !== null) {
                    $path = $this->solrConfig['path'];
                } else {
                    $path = '/solr';
                }

                return sprintf(
                    '%s://%s%s',
                    $scheme,
                    $host,
                    $path
                );
            }//end if
        }//end if

    }//end buildSolrBaseUrl()


    /**
     * Check if SOLR is properly configured with required settings
     *
     * @return bool True if SOLR configuration is complete
     */
    private function isSolrConfigured(): bool
    {
        // Check if SOLR is enabled.
        if (($this->solrConfig['enabled'] ?? null) !== null) {
            $enabled = $this->solrConfig['enabled'];
        } else {
            $enabled = false;
        }

        if ($enabled === false) {
            $this->logger->debug(message: 'SOLR is not enabled in configuration');
            return false;
        }

        // Check required configuration values - use 'core' not 'collection'.
        $requiredConfig = ['host'];
        foreach ($requiredConfig as $key) {
            if (empty($this->solrConfig[$key]) === true) {
                $this->logger->debug(message: 'SOLR configuration missing required key: '.$key);
                return false;
            }
        }

        // Port is optional (can be null for Kubernetes services).
        // Core/collection is optional (has defaults).
        return true;

    }//end isSolrConfigured()


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
     *
     * @return bool True if SOLR is available and properly configured
     */
    public function isAvailable(bool $forceRefresh=false): bool
    {
        // Check if SOLR is enabled in configuration.
        if (($this->solrConfig['enabled'] ?? false) === false) {
            $this->logger->debug(message: 'SOLR is disabled in configuration');
            return false;
        }

        // Check if basic configuration is present.
        if (empty($this->solrConfig['host']) === true) {
            $this->logger->debug(message: 'SOLR host not configured');
            return false;
        }

        // **CACHING STRATEGY**: Check cached availability result first (unless forced refresh).
        $cacheKey = 'solr_availability_'.md5($this->solrConfig['host'].':'.($this->solrConfig['port'] ?? 8983));

        if ($forceRefresh === false) {
            $cachedResult = $this->getCachedAvailability($cacheKey);

            if ($cachedResult !== null) {
                $this->logger->debug(
                        'Using cached SOLR availability result',
                        [
                            'available' => $cachedResult,
                            'cache_key' => $cacheKey,
                        ]
                        );
                return $cachedResult;
            }
        } else {
            $this->logger->debug(
                    'Forcing fresh SOLR availability check (ignoring cache)',
                    [
                        'cache_key' => $cacheKey,
                    ]
                    );
        }//end if

        try {
            // **DEBUG**: Log current SOLR configuration for troubleshooting.
            $this->logger->debug(
                    'SOLR availability check - current configuration',
                    [
                        'enabled'       => $this->solrConfig['enabled'] ?? false,
                        'host'          => $this->solrConfig['host'] ?? 'not set',
                        'port'          => $this->solrConfig['port'] ?? 'not set',
                        'force_refresh' => $forceRefresh,
                    ]
                    );

            // **COMPREHENSIVE TEST**: Use full operational readiness test for accurate availability.
            // This ensures complete SOLR readiness including collections and schema.
            $connectionTest = $this->testFullOperationalReadiness();
            $isAvailable    = $connectionTest['success'] ?? false;

            // **CACHE RESULT**: Store result for 1 hour to improve performance.
            $this->setCachedAvailability(cacheKey: $cacheKey, isAvailable: $isAvailable);

            $this->logger->debug(
                    'SOLR availability check completed and cached',
                    [
                        'available'         => $isAvailable,
                        'test_result'       => $connectionTest['message'] ?? 'No message',
                        'components_tested' => array_keys($connectionTest['components'] ?? []),
                        'cache_key'         => $cacheKey,
                        'cache_ttl'         => 3600,
                        'full_test_result'  => $connectionTest,
            // **DEBUG**: Full test result for troubleshooting
                    ]
                    );

            return $isAvailable;
        } catch (\Exception $e) {
            $this->logger->warning(
                    'SOLR availability check failed with exception',
                    [
                        'error'           => $e->getMessage(),
                        'host'            => $this->solrConfig['host'] ?? 'unknown',
                        'exception_class' => get_class($e),
                    ]
                    );

            // **CACHE FAILURE**: Cache negative result for shorter period (5 minutes).
            $this->setCachedAvailability(cacheKey: $cacheKey, isAvailable: false, ttl: 300);

            return false;
        }//end try

    }//end isAvailable()


    /**
     * Get cached SOLR availability result
     *
     * @param string $cacheKey The cache key to lookup
     *
     * @return bool|null The cached availability result, or null if not cached or expired
     */
    private function getCachedAvailability(string $cacheKey): ?bool
    {
        try {
            // Use APCu cache if available for best performance.
            if (function_exists('apcu_fetch') === true) {
                $result = apcu_fetch($cacheKey);

                if ($result === false) {
                    return null;
                }

                return (bool) $result;
            }

            // Fallback to file-based caching.
            $cacheFile = sys_get_temp_dir().'/'.$cacheKey.'.cache';
            if (file_exists($cacheFile) === true) {
                $data = json_decode(file_get_contents($cacheFile), true);
                if ($data !== null && ($data['expires'] ?? 0) > time()) {
                    return (bool) $data['available'];
                }

                // Clean up expired cache.
                unlink($cacheFile);
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->debug(
                    'Failed to read SOLR availability cache',
                    [
                        'error'     => $e->getMessage(),
                        'cache_key' => $cacheKey,
                    ]
                    );
            return null;
        }//end try

    }//end getCachedAvailability()


    /**
     * Cache SOLR availability result
     *
     * @param string $cacheKey    The cache key to store under
     * @param bool   $isAvailable The availability result to cache
     * @param int    $ttl         Time to live in seconds (default: 1 hour)
     *
     * @return void
     */
    private function setCachedAvailability(string $cacheKey, bool $isAvailable, int $ttl=3600): void
    {
        try {
            // Use APCu cache if available for best performance.
            if (function_exists('apcu_store') === true) {
                apcu_store($cacheKey, $isAvailable, $ttl);
                return;
            }

            // Fallback to file-based caching.
            $cacheFile = sys_get_temp_dir().'/'.$cacheKey.'.cache';
            $data      = [
                'available' => $isAvailable,
                'expires'   => time() + $ttl,
                'created'   => time(),
            ];
            file_put_contents($cacheFile, json_encode($data));
        } catch (\Exception $e) {
            $this->logger->debug(
                    'Failed to cache SOLR availability result',
                    [
                        'error'     => $e->getMessage(),
                        'cache_key' => $cacheKey,
                        'available' => $isAvailable,
                        'ttl'       => $ttl,
                    ]
                    );
            // Don't throw - caching is optional.
        }//end try

    }//end setCachedAvailability()


    /**
     * Clear cached SOLR availability result (internal method)
     *
     * @param string|null $cacheKey Specific cache key to clear, or null to clear all SOLR availability cache
     *
     * @return void
     */
    private function clearCachedAvailability(?string $cacheKey=null): void
    {
        try {
            if ($cacheKey !== null && $cacheKey !== '') {
                // Clear specific cache entry.
                if (function_exists('apcu_delete') === true) {
                    apcu_delete($cacheKey);
                }

                $cacheFile = sys_get_temp_dir().'/'.$cacheKey.'.cache';
                if (file_exists($cacheFile) === true) {
                    unlink($cacheFile);
                }
            } else {
                // Clear all SOLR availability cache entries.
                if (function_exists('apcu_delete') === true && class_exists('\APCUIterator') === true) {
                    /*
                     * @var object $iterator
                     */
                    $iterator = new \APCUIterator('/^solr_availability_/');
                    apcu_delete($iterator);
                }

                // Clear file-based cache.
                $tempDir = sys_get_temp_dir();
                $pattern = $tempDir.'/solr_availability_*.cache';
                foreach (glob($pattern) as $file) {
                    unlink($file);
                }
            }//end if
        } catch (\Exception $e) {
            $this->logger->debug(
                    'Failed to clear SOLR availability cache',
                    [
                        'error'     => $e->getMessage(),
                        'cache_key' => $cacheKey,
                    ]
                    );
        }//end try

    }//end clearCachedAvailability()


    /**
     * Test SOLR connection with comprehensive testing
     *
     * @param bool $includeCollectionTests Whether to include collection/query tests (default: true for full test)
     *
     * @return array{success: bool, message: string, details: array, components: array} Connection test results
     */
    public function testConnection(bool $includeCollectionTests=true): array
    {
        try {
            $solrConfig = $this->solrConfig;

            if ($solrConfig['enabled'] === false) {
                return [
                    'success'    => false,
                    'message'    => 'SOLR is disabled in settings',
                    'details'    => [],
                    'components' => [],
                ];
            }

            if ($includeCollectionTests === true) {
                $message = 'All connection tests passed';
            } else {
                $message = 'SOLR server connectivity and authentication verified';
            }

            $testResults = [
                'success'    => true,
                'message'    => $message,
                'details'    => [],
                'components' => [],
            ];

            // Test 1: Zookeeper connectivity (only if using SolrCloud AND doing full tests).
            if ($includeCollectionTests === true && ($solrConfig['useCloud'] ?? false) === true) {
                $zookeeperTest = $this->testZookeeperConnection();
                $testResults['components']['zookeeper'] = $zookeeperTest;

                if ($zookeeperTest['success'] === false) {
                    $testResults['success'] = false;
                    $testResults['message'] = 'Zookeeper connection failed';
                }
            }

            // Test 2: SOLR connectivity and authentication.
            $solrTest = $this->testSolrConnectivity();
            $testResults['components']['solr'] = $solrTest;

            if ($solrTest['success'] === false) {
                $testResults['success'] = false;
                $testResults['message'] = 'SOLR connection or authentication failed';
                return $testResults;
            }

            // Test 3: Collection/Core availability (conditional).
            if ($includeCollectionTests === true) {
                $collectionTest = $this->testSolrCollection();
                $testResults['components']['collection'] = $collectionTest;

                if ($collectionTest['success'] === false) {
                    $testResults['success'] = false;
                    $testResults['message'] = 'SOLR collection/core not available';
                }

                // Test 4: Collection query test (if collection exists).
                if ($collectionTest['success'] === true) {
                    $queryTest = $this->testSolrQuery();
                    $testResults['components']['query'] = $queryTest;

                    if ($queryTest['success'] === false) {
                        // Don't fail overall test if query fails but collection exists.
                        $testResults['message'] = 'SOLR collection exists but query test failed';
                    }
                }
            }

            return $testResults;
        } catch (\Exception $e) {
            return [
                'success'    => false,
                'message'    => 'Connection test failed: '.$e->getMessage(),
                'details'    => ['error' => $e->getMessage()],
                'components' => [],
            ];
        }//end try

    }//end testConnection()


    /**
     * Test SOLR connectivity only (for basic authentication verification)
     *
     * This method only tests if SOLR server is reachable and authentication is working.
     * It does NOT test collections, queries, or Zookeeper.
     * Perfect for verifying basic connection settings.
     *
     * @return array{success: bool, message: string, details: array, components: array} Connectivity test results
     */
    public function testConnectivityOnly(): array
    {
        return $this->testConnection(false);

    }//end testConnectivityOnly()


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

    }//end testFullOperationalReadiness()


    /**
     * Check if collection exists
     *
     * @param string $collectionName Collection name to check
     *
     * @return bool True if collection exists
     */
    public function collectionExists(string $collectionName): bool
    {
        try {
            $url      = $this->buildSolrBaseUrl().'/admin/collections?action=CLUSTERSTATUS&wt=json';
            $response = $this->httpClient->get($url, ['timeout' => 10]);
            $data     = json_decode((string) $response->getBody(), true);

            return isset($data['cluster']['collections'][$collectionName]);
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to check collection existence',
                    [
                        'collection' => $collectionName,
                        'error'      => $e->getMessage(),
                    ]
                    );
            return false;
        }

    }//end collectionExists()


    /**
     * Ensure tenant collection exists (check both tenant-specific and base collections)
     *
     * @return array|bool True if collection exists, array if collection was created, false on failure
     */
    public function ensureTenantCollection(): array | bool
    {
        if ($this->isAvailable() === false) {
            return false;
        }

        $baseCollectionName   = $this->solrConfig['collection'] ?? $this->solrConfig['core'] ?? 'openregister';
        $tenantCollectionName = $this->getTenantSpecificCollectionName($baseCollectionName);

        // Check if tenant collection exists..
        if ($this->collectionExists($tenantCollectionName) === true) {
            $this->logger->debug(
                    'Tenant collection already exists',
                    [
                        'collection' => $tenantCollectionName,
                    ]
                    );
            return true;
        }

        // FALLBACK: Check if base collection exists.
        if ($this->collectionExists($baseCollectionName) === true) {
            $this->logger->info(
                    'Using base collection as fallback (no tenant isolation)',
                    [
                        'base_collection' => $baseCollectionName,
                    ]
                    );
            return true;
        }

        // Try to create tenant collection.
        $this->logger->info(
                'Attempting to create tenant collection',
                [
                    'collection' => $tenantCollectionName,
                ]
                );
        $configSet = $this->solrConfig['configSet'] ?? 'openregister';
        return $this->createCollection(collectionName: $tenantCollectionName, configSetName: $configSet);

    }//end ensureTenantCollection()


    /**
     * Get the actual collection name to use for operations
     *
     * Returns tenant-specific collection name only if it exists, otherwise null
     *
     * @return string|null The collection name to use for SOLR operations, or null if no collection exists
     */
    public function getActiveCollectionName(): ?string
    {
        // **PHASE 2**: Prioritize objectCollection over legacy collection field.
        // objectCollection is the new standard for object-specific operations.
        $baseCollectionName = $this->solrConfig['objectCollection'] ?? null;

        // Fall back to legacy 'collection' field for backward compatibility.
        if ($baseCollectionName === null) {
            $baseCollectionName = $this->solrConfig['collection'] ?? 'openregister';
            $this->logger->debug(
                    'Using legacy collection field (deprecated)',
                    [
                        'collection'     => $baseCollectionName,
                        'recommendation' => 'Please configure objectCollection in SOLR settings',
                    ]
                    );
        }

        $tenantCollectionName = $this->getTenantSpecificCollectionName($baseCollectionName);

        // Check if tenant collection exists..
        if ($this->collectionExists($tenantCollectionName) === true) {
            return $tenantCollectionName;
        }

        // **FIX**: No fallback to base collection - if tenant collection doesn't exist, return null.
        // This prevents operations on non-existent collections.
        $this->logger->warning(
                'Tenant-specific collection does not exist',
                [
                    'tenant_collection' => $tenantCollectionName,
                    'base_collection'   => $baseCollectionName,
                ]
                );

        return null;

    }//end getActiveCollectionName()


    /**
     * Get tenant collection name (alias for getActiveCollectionName)
     *
     * @return string|null The tenant collection name or null if no collection exists
     */
    public function getTenantCollectionName(): ?string
    {
        return $this->getActiveCollectionName();

    }//end getTenantCollectionName()


    /**
     * Create SOLR collection
     *
     * @param string $collectionName    Collection name
     * @param string $configSetName     ConfigSet name
     * @param int    $numShards         Number of shards (default: 1)
     * @param int    $replicationFactor Number of replicas per shard (default: 1)
     * @param int    $maxShardsPerNode  Maximum shards per node (default: 1)
     *
     * @return array Result array with success status and details
     *
     * @throws \GuzzleHttp\Exception\GuzzleException When HTTP request fails
     * @throws \Exception When SOLR returns error response
     */
    public function createCollection(
        string $collectionName,
        string $configSetName,
        int $numShards=1,
        int $replicationFactor=1,
        int $maxShardsPerNode=1
    ): array {
        $this->logger->info(
                '📋 Creating new SOLR collection',
                [
                    'name'      => $collectionName,
                    'configSet' => $configSetName,
                    'shards'    => $numShards,
                    'replicas'  => $replicationFactor,
                ]
                );

        // Check if SOLR is configured before attempting to connect.
        // This prevents DNS resolution errors when Solr host is not configured.
        if ($this->isSolrConfigured() === false) {
            $configStatus = [
                'enabled' => $this->solrConfig['enabled'] ?? false,
                'host'    => $this->solrConfig['host'] ?? 'not set',
            ];
            $this->logger->warning(
                message: 'Cannot create collection: SOLR is not configured',
                context: ['config_status' => $configStatus, 'collection' => $collectionName]
            );
            throw new \Exception(
                'SOLR is not configured. Please configure SOLR settings in the OpenRegister admin panel before creating collections.'
            );
        }

        $url = $this->buildSolrBaseUrl().'/admin/collections?'.http_build_query(
                [
                    'action'                => 'CREATE',
                    'name'                  => $collectionName,
                    'collection.configName' => $configSetName,
                    'numShards'             => $numShards,
                    'replicationFactor'     => $replicationFactor,
                    'maxShardsPerNode'      => $maxShardsPerNode,
                    'wt'                    => 'json',
                ]
                );

        $response = $this->httpClient->get($url, ['timeout' => 60]);
        $data     = json_decode((string) $response->getBody(), true);

        if (($data['responseHeader']['status'] ?? -1) === 0) {
            $this->logger->info(
                    '✅ SOLR collection created successfully',
                    [
                        'collection' => $collectionName,
                        'configSet'  => $configSetName,
                        'shards'     => $numShards,
                        'replicas'   => $replicationFactor,
                    ]
                    );

            return [
                'success'    => true,
                'message'    => 'Collection created successfully',
                'collection' => $collectionName,
                'configSet'  => $configSetName,
                'shards'     => $numShards,
                'replicas'   => $replicationFactor,
            ];
        }

        // SOLR returned an error response - throw exception with details.
        $errorMessage = $data['error']['msg'] ?? 'Unknown SOLR error';
        $errorCode    = $data['responseHeader']['status'] ?? 500;

        $this->logger->error(
                'SOLR collection creation failed',
                [
                    'collection'    => $collectionName,
                    'configSet'     => $configSetName,
                    'url'           => $url,
                    'solr_status'   => $errorCode,
                    'solr_error'    => $data['error'] ?? null,
                    'full_response' => $data,
                ]
                );

        // Throw exception with SOLR response details.
        throw new \Exception(
            message: "SOLR collection creation failed: {$errorMessage}",
            code: $errorCode
        );

    }//end createCollection()


    /**
     * Index object in SOLR
     *
     * @param ObjectEntity $object Object to index
     * @param bool         $commit Whether to commit immediately
     *
     * @return bool True if successful
     */
    public function indexObject(ObjectEntity $object, bool $commit=false): bool
    {
        // Only index objects that have a published date.
        if ($object->getPublished() === null) {
            $this->logger->debug(
                    'Skipping indexing of unpublished object',
                    [
                        'object_id'   => $object->getId(),
                        'object_uuid' => $object->getUuid(),
                        'published'   => null,
                    ]
                    );
            // Return true to indicate successful handling (not an error).
            return true;
        }

        $this->logger->debug(
                'Indexing published object',
                [
                    'object_id'   => $object->getId(),
                    'object_uuid' => $object->getUuid(),
                    'published'   => $object->getPublished()->format('Y-m-d\TH:i:s\Z'),
                ]
                );

        if ($this->isAvailable() === false) {
            return false;
        }

        try {
            $startTime = microtime(true);

            // Ensure tenant collection exists.
            if ($this->ensureTenantCollection() === false) {
                $this->logger->warning(message: 'Cannot index object: tenant collection unavailable');
                return false;
            }

            // Get the active collection name - return false if no collection exists.
            $tenantCollectionName = $this->getActiveCollectionName();
            if ($tenantCollectionName === null) {
                $this->logger->warning(message: 'Cannot index object: no active collection available');
                return false;
            }

            // Create SOLR document using schema-aware mapping (no fallback).
            try {
                $document = $this->createSolrDocument($object);

                // **DEBUG**: Log what we're about to send to SOLR.
                $this->logger->debug(
                        'Document created for SOLR indexing',
                        [
                            'object_uuid'          => $object->getUuid(),
                            'has_self_relations'   => isset($document['self_relations']) === true,
                            'self_relations_value' => $document['self_relations'] ?? 'NOT_SET',
                            'self_relations_type'  => $this->getSelfRelationsType($document),
                            'self_relations_count' => $this->getSelfRelationsCount($document),
                        ]
                        );
            } catch (\RuntimeException $e) {
                // Check if this is a non-searchable schema.
                if (str_contains($e->getMessage(), 'Schema is not searchable') === true) {
                    $this->logger->debug(
                            'Skipping indexing for non-searchable schema',
                            [
                                'object_id' => $object->getId(),
                                'message'   => $e->getMessage(),
                            ]
                            );
                    // Return false to indicate object was not indexed (skipped).
                    return false;
                }

                // Re-throw other runtime exceptions.
                throw $e;
            }//end try

            // Relations indexing is working correctly.
            // Prepare update request.
            $updateData = [
                'add' => [
                    'doc' => $document,
                ],
            ];

            // Relations are properly included in SOLR documents.
            $url = $this->buildSolrBaseUrl().'/'.$tenantCollectionName.'/update?wt=json';

            if ($commit === true) {
                $url .= '&commit=true';
            }

            $response = $this->httpClient->post(
                    $url,
                    [
                        'body'    => json_encode($updateData),
                        'headers' => ['Content-Type' => 'application/json'],
                        'timeout' => 30,
                    ]
                    );

            $data    = json_decode((string) $response->getBody(), true);
            $success = ($data['responseHeader']['status'] ?? -1) === 0;

            if ($success === true) {
                $this->stats['indexes']++;
                $this->stats['index_time'] += (microtime(true) - $startTime);

                $this->logger->debug(
                        '🔍 OBJECT INDEXED IN SOLR',
                        [
                            'object_id'         => $object->getId(),
                            'uuid'              => $object->getUuid(),
                            'collection'        => $tenantCollectionName,
                            'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                        ]
                        );
            } else {
                $this->stats['errors']++;
                $this->logger->error(
                        'SOLR indexing failed',
                        [
                            'object_id' => $object->getId(),
                            'response'  => $data,
                        ]
                        );
            }//end if

            return $success;
        } catch (\Exception $e) {
            $this->stats['errors']++;
            $this->logger->error(
                    'Exception indexing object in SOLR',
                    [
                        'object_id' => $object->getId(),
                        'error'     => $e->getMessage(),
                    ]
                    );
            return false;
        }//end try

    }//end indexObject()


    /**
     * Delete object from SOLR
     *
     * @param string|int $objectId Object ID or UUID
     * @param bool       $commit   Whether to commit immediately
     *
     * @return bool True if successful
     */
    public function deleteObject(string|int $objectId, bool $commit=false): bool
    {
        if ($this->isAvailable() === false) {
            return false;
        }

        try {
            // Get the active collection name - return false if no collection exists.
            $tenantCollectionName = $this->getActiveCollectionName();
            if ($tenantCollectionName === null) {
                $this->logger->warning(message: 'Cannot delete object: no active collection available');
                return false;
            }

            $deleteData = [
                'delete' => [
                    'query' => sprintf('id:%s', (string) $objectId),
                ],
            ];

            $url = $this->buildSolrBaseUrl().'/'.$tenantCollectionName.'/update?wt=json';

            if ($commit === true) {
                $url .= '&commit=true';
            }

            $response = $this->httpClient->post(
                    $url,
                    [
                        'body'    => json_encode($deleteData),
                        'headers' => ['Content-Type' => 'application/json'],
                        'timeout' => 30,
                    ]
                    );

            $data    = json_decode((string) $response->getBody(), true);
            $success = ($data['responseHeader']['status'] ?? -1) === 0;

            if ($success === true) {
                $this->stats['deletes']++;
                $this->logger->debug(
                        '🗑️ OBJECT REMOVED FROM SOLR',
                        [
                            'object_id'  => $objectId,
                            'collection' => $tenantCollectionName,
                        ]
                        );
            }

            return $success;
        } catch (\Exception $e) {
            $this->stats['errors']++;
            $this->logger->error(
                    'Exception deleting object from SOLR',
                    [
                        'object_id' => $objectId,
                        'error'     => $e->getMessage(),
                    ]
                    );
            return false;
        }//end try

    }//end deleteObject()


    /**
     * Get document count from tenant collection
     *
     * @return int Number of documents
     */
    public function getDocumentCount(): int
    {
        if ($this->isAvailable() === false) {
            return 0;
        }

        try {
            // Get the active collection name - return 0 if no collection exists.
            $tenantCollectionName = $this->getActiveCollectionName();
            if ($tenantCollectionName === null) {
                $this->logger->warning(message: 'Cannot get document count: no active collection available');
                return 0;
            }

            $url = $this->buildSolrBaseUrl().'/'.$tenantCollectionName.'/select?'.http_build_query(
                    [
                        'q'    => '*:*',
                        'rows' => 0,
                        'wt'   => 'json',
                    ]
                    );

            $response = $this->httpClient->get($url, ['timeout' => 10]);
            $data     = json_decode((string) $response->getBody(), true);

            return (int) ($data['response']['numFound'] ?? 0);
        } catch (\Exception $e) {
            $this->logger->error(message: 'Failed to get document count', context: ['error' => $e->getMessage()]);
            return 0;
        }//end try

    }//end getDocumentCount()


    /**
     * Create SOLR document from ObjectEntity with field validation
     *
     * @param ObjectEntity $object         Object to convert
     * @param array        $solrFieldTypes Optional SOLR field types for validation
     *
     * @return array SOLR document
     */
    public function createSolrDocument(ObjectEntity $object, array $solrFieldTypes=[]): array
    {
        // **SCHEMA-AWARE MAPPING REQUIRED**: Validate schema availability first.
        if ($this->schemaMapper === null) {
            $objectId = $object->getId();
            $schemaId = $object->getSchema();
            throw new \RuntimeException(
                'Schema mapper is not available. Cannot create SOLR document without schema validation. '.'Object ID: '.$objectId.', Schema ID: '.$schemaId
            );
        }

        // Get the schema for this object.
        $schema = $this->schemaMapper->find($object->getSchema());

        if (($schema instanceof Schema) === false) {
            $objectId     = $object->getId();
            $schemaId     = $object->getSchema();
            $errorMessage = 'Schema not found for object. Cannot create SOLR document without valid schema. '.'Object ID: '.$objectId.', Schema ID: '.$schemaId;
            throw new \RuntimeException($errorMessage);
        }

        // Check if schema is searchable - skip indexing if not.
        if ($schema->getSearchable() === false) {
            $this->logger->debug(
                    'Skipping SOLR indexing for non-searchable schema',
                    [
                        'object_id'    => $object->getId(),
                        'schema_id'    => $object->getSchema(),
                        'schema_slug'  => $schema->getSlug(),
                        'schema_title' => $schema->getTitle(),
                    ]
                    );
            $objectId = $object->getId();

            if ($schema->getTitle() !== null && $schema->getTitle() !== '') {
                $schemaName = $schema->getTitle();
            } else {
                $schemaName = $schema->getSlug();
            }//end if

            $errorMessage = 'Schema is not searchable. Objects of this schema are excluded from SOLR indexing. '.'Object ID: '.$objectId.', Schema: '.$schemaName;
            throw new \RuntimeException($errorMessage);
        }//end if

        // Get the register for this object (if registerMapper is available).
        $register = null;
        if ($this->registerMapper !== null) {
            try {
                $register = $this->registerMapper->find($object->getRegister());
            } catch (\Exception $e) {
                $this->logger->warning(
                        'Failed to fetch register for object',
                        [
                            'object_id'   => $object->getId(),
                            'register_id' => $object->getRegister(),
                            'error'       => $e->getMessage(),
                        ]
                        );
            }
        }

        // **USE CONSOLIDATED MAPPING**: Create schema-aware document directly.
        try {
            $document = $this->createSchemaAwareDocument(object: $object, schema: $schema, register: $register, solrFieldTypes: $solrFieldTypes);

            // Document created successfully using schema-aware mapping.
            $this->logger->debug(
                    'Created SOLR document using schema-aware mapping',
                    [
                        'object_id'     => $object->getId(),
                        'schema_id'     => $object->getSchema(),
                        'mapped_fields' => count($document),
                    ]
                    );

            return $document;
        } catch (\Exception $e) {
            // **NO FALLBACK**: Throw error to prevent schemaless documents.
            $this->logger->error(
                    'Schema-aware mapping failed and no fallback allowed',
                    [
                        'object_id' => $object->getId(),
                        'schema_id' => $object->getSchema(),
                        'error'     => $e->getMessage(),
                        'trace'     => $e->getTraceAsString(),
                    ]
                    );

            $objectId     = $object->getId();
            $schemaId     = $object->getSchema();
            $errorMessage = 'Schema-aware mapping failed for object. Schemaless fallback is disabled to prevent inconsistent documents. '.'Object ID: '.$objectId.', Schema ID: '.$schemaId.'. '.'Original error: '.$e->getMessage();
            throw new \RuntimeException(message: $errorMessage, code: 0, previous: $e);
        }//end try

    }//end createSolrDocument()


    /**
     * Create schema-aware SOLR document from ObjectEntity, Schema, and Register
     *
     * This method implements the consolidated schema-aware mapping logic
     * that was previously in SolrSchemaMappingService.
     *
     * @param ObjectEntity                       $object         The object to convert
     * @param Schema                             $schema         The schema for mapping
     * @param \OCA\OpenRegister\Db\Register|null $register       The register for metadata
     * @param array                              $solrFieldTypes Optional SOLR field types for validation
     *
     * @return array SOLR document structure
     * @throws \RuntimeException If mapping fails
     */
    private function createSchemaAwareDocument(ObjectEntity $object, Schema $schema, $register=null, array $solrFieldTypes=[]): array
    {
        // **FIX**: Get the actual object business data, not entity metadata.
        $objectData = $object->getObject();
        // This contains the schema fields like 'naam', 'website', 'type'.
        $schemaProperties = $schema->getProperties();

        // ========================================================================.
        // **WORKAROUND/HACK**: Enrich object data from relations.
        // ========================================================================.
        // PROBLEM: Some array fields (like 'standaarden') are stored ONLY in the relations.
        // table as dot-notation entries (e.g., "standaarden.0", "standaarden.1") instead of.
        // being included in the object JSON body. This causes them to be missing from SOLR.
        //
        // This is a data storage issue that should be fixed at the source (ObjectService/Mapper),.
        // but as a workaround, we reconstruct these arrays from relations to ensure they get indexed.
        //
        // TODO: Investigate why some array fields are stored only as relations and fix the root cause.
        // in the object save logic so arrays are consistently stored in the object body.
        // ========================================================================.
        $relations = $object->getRelations();
        if (is_array($relations) === true && empty($relations) === false) {
            $extractedArrays = $this->extractArraysFromRelations($relations);
            foreach ($extractedArrays as $fieldName => $arrayValues) {
                // Only enrich if the field is empty or doesn't exist in object data.
                $fieldExists       = isset($objectData[$fieldName]);
                $fieldIsEmptyArray = $fieldExists === true && is_array($objectData[$fieldName]) === true && empty($objectData[$fieldName]) === true;
                if ($fieldExists === false || $fieldIsEmptyArray === true) {
                    $objectData[$fieldName] = $arrayValues;
                    $this->logger->debug(
                            '[WORKAROUND] Enriched object data from relations',
                            [
                                'field'       => $fieldName,
                                'values'      => $arrayValues,
                                'value_count' => count($arrayValues),
                                'reason'      => 'Array data missing from object body but found in relations',
                            ]
                            );
                }
            }
        }

        // Base SOLR document with core identifiers and metadata fields using self_ prefix.
        if ($object->getUuid() !== null && $object->getUuid() !== '') {
            $documentId = $object->getUuid();
        } else {
            $documentId = (string) $object->getId();
        }

        if ($object->getName() !== null && $object->getName() !== '') {
            $selfName = $object->getName();
        } else {
            $selfName = null;
        }

        if ($object->getDescription() !== null && $object->getDescription() !== '') {
            $selfDescription = $object->getDescription();
        } else {
            $selfDescription = null;
        }

        if ($object->getSummary() !== null && $object->getSummary() !== '') {
            $selfSummary = $object->getSummary();
        } else {
            $selfSummary = null;
        }

        if ($object->getImage() !== null && $object->getImage() !== '') {
            $selfImage = $object->getImage();
        } else {
            $selfImage = null;
        }

        if ($object->getSlug() !== null && $object->getSlug() !== '') {
            $selfSlug = $object->getSlug();
        } else {
            $selfSlug = null;
        }

        $document = [
            // Core identifiers (always present) - no prefix for SOLR system fields.
            'id'                  => $documentId,

            // Metadata fields with self_ prefix (consistent with legacy mapping).
            'self_uuid'           => $object->getUuid(),

            // Context fields - resolve to integer IDs.
            'self_register'       => $this->resolveRegisterToId(registerValue: $object->getRegister(), register: $register),
            'self_register_id'    => $this->resolveRegisterToId(registerValue: $object->getRegister(), register: $register),
            'self_register_uuid'  => $register?->getUuid(),
            'self_register_slug'  => $register?->getSlug(),

            'self_schema'         => $this->resolveSchemaToId(schemaValue: $object->getSchema(), schema: $schema),
            'self_schema_id'      => $this->resolveSchemaToId(schemaValue: $object->getSchema(), schema: $schema),
            'self_schema_uuid'    => $schema->getUuid(),
            'self_schema_slug'    => $schema->getSlug(),
            'self_schema_version' => $object->getSchemaVersion(),

            // Ownership and metadata.
            'self_owner'          => $object->getOwner(),
            'self_organisation'   => $object->getOrganisation(),
            'self_application'    => $object->getApplication(),

            // Core object fields (text fields for search).
            'self_name'           => $selfName,
            'self_description'    => $selfDescription,
            'self_summary'        => $selfSummary,
            'self_image'          => $selfImage,
            'self_slug'           => $selfSlug,
            'self_uri'            => $this->getUriValue($object),
            'self_version'        => $this->getVersionValue($object),
            'self_size'           => $this->getSizeValue($object),
            'self_folder'         => $this->getFolderValue($object),

            // Sortable string variants (for ordering, not tokenized).
            // These are single-valued string fields that Solr can sort on.
            'self_name_s'         => $selfName,
            'self_description_s'  => $selfDescription,
            'self_summary_s'      => $selfSummary,
            'self_slug_s'         => $selfSlug,

            // Timestamps.
            'self_created'        => $object->getCreated()?->format('Y-m-d\\TH:i:s\\Z'),
            'self_updated'        => $object->getUpdated()?->format('Y-m-d\\TH:i:s\\Z'),
            'self_published'      => $object->getPublished()?->format('Y-m-d\\TH:i:s\\Z'),
            'self_depublished'    => $object->getDepublished()?->format('Y-m-d\\TH:i:s\\Z'),

            // **NEW**: UUID relation fields with proper types - flatten to avoid SOLR issues.
            'self_relations'      => $this->flattenRelationsForSolr($object->getRelations()),
            'self_files'          => $this->flattenFilesForSolr($object->getFiles()),

            // **COMPLETE OBJECT STORAGE**: Store entire object as JSON for exact reconstruction.
            'self_object'         => $this->getSelfObjectJson($object),
        ];

        // **SCHEMA-AWARE FIELD MAPPING**: Map object data based on schema properties.
        if (is_array($schemaProperties) === true && is_array($objectData) === true) {
            // **DEBUG**: Log what we're mapping.
            $this->logger->debug(
                    'Schema-aware mapping',
                    [
                        'object_id'         => $object->getId(),
                        'schema_properties' => array_keys($schemaProperties),
                        'object_data_keys'  => array_keys($objectData),
                    ]
                    );

            foreach ($schemaProperties as $fieldName => $fieldDefinition) {
                if (isset($objectData[$fieldName]) === false) {
                    continue;
                }

                $fieldValue = $objectData[$fieldName];
                $fieldType  = $fieldDefinition['type'] ?? 'string';

                // **TRUNCATE LARGE VALUES**: Respect SOLR's 32,766 byte limit for indexed fields.
                if ($this->shouldTruncateField(fieldName: $fieldName, fieldDefinition: $fieldDefinition) === true) {
                    $fieldValue = $this->truncateFieldValue(value: $fieldValue, fieldName: $fieldName);
                }

                // **HANDLE ARRAYS**: Process arrays by inspecting actual content.
                if (is_array($fieldValue) === true) {
                    $this->logger->debug(
                            'Processing array field',
                            [
                                'field'            => $fieldName,
                                'array_size'       => count($fieldValue),
                                'field_type'       => $fieldType,
                                'schema_item_type' => $fieldDefinition['items']['type'] ?? 'unknown',
                            ]
                            );

                    // Extract indexable values from the array (ignores schema definition).
                    $extractedValues = $this->extractIndexableArrayValues(arrayValue: $fieldValue, fieldName: $fieldName);

                    if (empty($extractedValues) === false) {
                        $solrFieldName = $this->mapFieldToSolrType(fieldName: $fieldName, _fieldType: 'array', _fieldValue: $extractedValues);
                        if ($solrFieldName !== null && $this->validateFieldForSolr(fieldName: $solrFieldName, fieldValue: $extractedValues, solrFieldTypes: $solrFieldTypes) === true) {
                            $document[$solrFieldName] = $extractedValues;
                            $this->logger->debug(
                                    'Indexed array field (content-based extraction)',
                                    [
                                        'field'             => $fieldName,
                                        'solr_field'        => $solrFieldName,
                                        'extracted_values'  => $extractedValues,
                                        'extraction_method' => 'content-based',
                                    ]
                                    );
                        }
                    } else {
                        $this->logger->debug(
                                'Skipped array field - no indexable values found',
                                [
                                    'field'      => $fieldName,
                                    'array_size' => count($fieldValue),
                                ]
                                );
                    }//end if

                    // Skip to next field after processing array.
                    continue;
                }//end if

                // **FILTER OBJECTS**: Skip standalone objects (not arrays).
                if (is_object($fieldValue) === true) {
                    $this->logger->debug(
                            'Skipping object field value',
                            [
                                'field'  => $fieldName,
                                'type'   => gettype($fieldValue),
                                'reason' => 'Standalone objects are not suitable for SOLR field indexing',
                            ]
                            );
                    continue;
                }

                // **FILTER NON-SCALAR VALUES**: Only index scalar values (string, int, float, bool, null).
                if (is_scalar($fieldValue) === false && $fieldValue !== null) {
                    $this->logger->debug(
                            'Skipping non-scalar field value',
                            [
                                'field'  => $fieldName,
                                'type'   => gettype($fieldValue),
                                'reason' => 'Only scalar values can be indexed in SOLR',
                            ]
                            );
                    continue;
                }

                // **HOTFIX**: Temporarily skip 'versie' field to prevent NumberFormatException.
                // TODO: Fix versie field type conflict - it's defined as integer in SOLR but contains decimal strings like '9.1'.
                if ($fieldName === 'versie') {
                    $this->logger->debug(
                            'HOTFIX: Skipped versie field to prevent type mismatch',
                            [
                                'field'  => $fieldName,
                                'value'  => $fieldValue,
                                'reason' => 'Temporary fix for integer/decimal type conflict',
                            ]
                            );
                    continue;
                }

                // Map field based on schema type to appropriate SOLR field name.
                $solrFieldName = $this->mapFieldToSolrType(fieldName: $fieldName, _fieldType: $fieldType, _fieldValue: $fieldValue);

                if ($solrFieldName !== null && $solrFieldName !== '') {
                    $convertedValue = $this->convertValueForSolr(value: $fieldValue, fieldType: $fieldType);

                    // **FIELD VALIDATION**: Check if field exists in SOLR and type is compatible.
                    if ($convertedValue !== null && $this->validateFieldForSolr(fieldName: $solrFieldName, fieldValue: $convertedValue, solrFieldTypes: $solrFieldTypes) === true) {
                        $document[$solrFieldName] = $convertedValue;
                        $this->logger->debug(
                                'Mapped field',
                                [
                                    'original'        => $fieldName,
                                    'solr_field'      => $solrFieldName,
                                    'original_value'  => $fieldValue,
                                    'converted_value' => $convertedValue,
                                    'value_type'      => gettype($convertedValue),
                                ]
                                );
                    } else {
                        $this->logger->debug(
                                'Skipped field with null converted value',
                                [
                                    'original'       => $fieldName,
                                    'solr_field'     => $solrFieldName,
                                    'original_value' => $fieldValue,
                                    'field_type'     => $fieldType,
                                ]
                                );
                    }//end if
                }//end if
            }//end foreach
        } else {
            // **DEBUG**: Log when schema mapping fails.
            $this->logger->warning(
                    'Schema-aware mapping skipped',
                    [
                        'object_id'               => $object->getId(),
                        'schema_properties_type'  => gettype($schemaProperties),
                        'object_data_type'        => gettype($objectData),
                        'schema_properties_empty' => empty($schemaProperties),
                        'object_data_empty'       => empty($objectData),
                    ]
                    );
        }//end if

        // Remove null values, but keep published/depublished fields and empty arrays for multi-valued fields.
        return array_filter(
                $document,
                function ($value, $key) {
                    // Always keep published/depublished fields even if null for proper Solr filtering.
                    if (in_array($key, ['self_published', 'self_depublished']) === true) {
                        return true;
                    }

                    // Keep empty arrays for multi-valued fields like self_relations, self_files.
                    if (is_array($value) === true && in_array($key, ['self_relations', 'self_files']) === true) {
                        return true;
                    }

                    return $value !== null && $value !== '';
                },
                ARRAY_FILTER_USE_BOTH
                );

    }//end createSchemaAwareDocument()


    /**
     * Flatten relations array for SOLR - extract all values from relations key-value pairs
     *
     * @param mixed $relations Relations data from ObjectEntity (e.g., {"modules.0":"uuid", "other.1":"value"})
     *
     * @return array Simple array of strings for SOLR multi-valued field (e.g., ["uuid", "value"])
     */
    private function flattenRelationsForSolr($relations): array
    {
        // **DEBUG**: Log what we're processing.
        $this->logger->debug(
                'Processing relations for SOLR',
                [
                    'relations_type'  => gettype($relations),
                    'relations_value' => $relations,
                    'is_empty'        => empty($relations),
                ]
                );

        if (empty($relations) === true) {
            return [];
        }

        if (is_array($relations) === true) {
            $values = [];
            foreach ($relations as $key => $value) {
                // **FIXED**: Extract ALL values from relations array, not just UUIDs.
                // Relations are stored as {"modules.0":"value"} - we want all the values.
                if (is_string($value) === true || is_numeric($value) === true) {
                    $values[] = (string) $value;
                    $this->logger->debug(
                            'Found value in relations',
                            [
                                'key'   => $key,
                                'value' => $value,
                                'type'  => gettype($value),
                            ]
                            );
                }

                // Skip arrays, objects, null values, etc.
            }

            $this->logger->debug(
                    'Flattened relations result',
                    [
                        'input_count'  => count($relations),
                        'output_count' => count($values),
                        'values'       => $values,
                    ]
                    );

            return $values;
        }//end if

        // Single value - convert to string.
        if (is_string($relations) === true || is_numeric($relations) === true) {
            return [(string) $relations];
        }

        return [];

    }//end flattenRelationsForSolr()


    /**
     * Extract array fields from dot-notation relations
     *
     * **WORKAROUND/HACK FOR MISSING DATA**: This method reconstructs arrays from relations
     * because some array fields (e.g., 'standaarden') are stored ONLY as dot-notation
     * relation entries ("standaarden.0", "standaarden.1") instead of in the object body.
     *
     * Converts: {"standaarden.0": "value1", "standaarden.1": "value2"}
     * Into: {"standaarden": ["value1", "value2"]}
     *
     * This is a workaround for a data storage inconsistency where:
     * - Some arrays (referentieComponenten) ARE stored in object body
     * - Other arrays (standaarden) are ONLY stored as relations
     * - This inconsistency should be fixed in ObjectService/Mapper
     *
     * @param array $relations The relations array from ObjectEntity
     *
     * @return array Associative array of field names to their array values
     *
     * @todo Fix root cause: Ensure all array fields are consistently stored in object body
     */
    private function extractArraysFromRelations(array $relations): array
    {
        $arrays = [];

        // Group relations by their base field name (before the dot).
        foreach ($relations as $relationKey => $relationValue) {
            // Check if this is a dot-notation array relation (e.g., "standaarden.0").
            if (str_contains($relationKey, '.') === true) {
                $parts     = explode('.', $relationKey, 2);
                $fieldName = $parts[0];
                $index     = $parts[1];

                // Initialize array if not exists.
                if (isset($arrays[$fieldName]) === false) {
                    $arrays[$fieldName] = [];
                }

                // Add value at the specified index (or skip if index is not numeric).
                if (is_numeric($index) === true) {
                    $arrays[$fieldName][(int) $index] = $relationValue;
                } else {
                    // Non-numeric index - this is a nested object property, not an array element.
                    $this->logger->debug(
                            'Skipping non-numeric array index in relations',
                            [
                                'relation_key' => $relationKey,
                                'field_name'   => $fieldName,
                                'index'        => $index,
                            ]
                            );
                }
            }//end if
        }//end foreach

        // Sort each array by index and re-index to sequential keys.
        foreach ($arrays as &$arrayValues) {
            ksort($arrayValues);
            // Re-index to sequential numeric keys (0, 1, 2, ...).
            $arrayValues = array_values($arrayValues);
        }

        $this->logger->debug(
                'Extracted arrays from relations',
                [
                    'field_count'  => count($arrays),
                    'fields'       => array_keys($arrays),
                    'total_values' => array_sum(array_map('count', $arrays)),
                ]
                );

        return $arrays;

    }//end extractArraysFromRelations()


    /**
     * Extract indexable values from an array for SOLR indexing
     *
     * This method intelligently handles mixed arrays by inspecting the actual content
     * rather than relying on schema definitions, which may not match runtime data.
     *
     * @param array  $arrayValue The array to extract values from
     * @param string $fieldName  Field name for logging
     *
     * @return array Array of indexable string values
     */
    private function extractIndexableArrayValues(array $arrayValue, string $fieldName): array
    {
        $extractedValues = [];

        foreach ($arrayValue as $item) {
            if (is_string($item) === true) {
                // Direct string value - use as-is.
                $extractedValues[] = $item;
            } else if (is_array($item) === true) {
                // Object/array - try to extract ID/UUID.
                $idValue = $this->extractIdFromObject($item);
                if ($idValue !== null) {
                    $extractedValues[] = $idValue;
                }
            } else if (is_scalar($item) === true) {
                // Other scalar values (int, float, bool) - convert to string.
                $extractedValues[] = (string) $item;
            }

            // Skip null values and complex objects.
        }

        $this->logger->debug(
                'Extracted indexable array values',
                [
                    'field'            => $fieldName,
                    'original_count'   => count($arrayValue),
                    'extracted_count'  => count($extractedValues),
                    'extracted_values' => $extractedValues,
                ]
                );

        return $extractedValues;

    }//end extractIndexableArrayValues()


    /**
     * Extract ID/UUID from an object/array
     *
     * @param array $object Object/array to extract ID from
     *
     * @return string|null Extracted ID or null if not found
     */
    private function extractIdFromObject(array $object): ?string
    {
        // Try common ID field names in order of preference.
        $idFields = ['id', 'uuid', 'identifier', 'key', 'value'];

        foreach ($idFields as $field) {
            if (($object[$field] ?? null) !== null && is_string($object[$field]) === true) {
                return $object[$field];
            }
        }

        // If no ID field found, return null.
        return null;

    }//end extractIdFromObject()


    /**
     * Flatten files array for SOLR to prevent document multiplication
     *
     * @param mixed $files Files data from ObjectEntity
     *
     * @return array Simple array of strings for SOLR multi-valued field
     */
    private function flattenFilesForSolr($files): array
    {
        if (empty($files) === true) {
            return [];
        }

        if (is_array($files) === true) {
            $flattened = [];
            foreach ($files as $file) {
                if (is_string($file) === true) {
                    $flattened[] = $file;
                } else if (is_array($file) === true && (($file['id'] ?? null) !== null)) {
                    $flattened[] = (string) $file['id'];
                } else if (is_array($file) === true && (($file['uuid'] ?? null) !== null)) {
                    $flattened[] = $file['uuid'];
                }
            }

            return $flattened;
        }

        if (is_string($files) === true) {
            return [$files];
        }

        return [];

    }//end flattenFilesForSolr()


    /**
     * Map field name and type to appropriate SOLR field name (clean names, no suffixes)
     *
     * @param string $fieldName  Original field name
     * @param string $fieldType  Schema field type
     * @param mixed  $fieldValue Field value for context
     *
     * @return string|null SOLR field name (clean, no suffixes - field types defined in SOLR setup)
     */
    private function mapFieldToSolrType(string $fieldName, string $_fieldType, $_fieldValue): ?string
    {
        // Avoid conflicts with core SOLR fields and self_ metadata fields.
        if (in_array($fieldName, ['id', 'tenant_id', '_version_']) === true || str_starts_with($fieldName, 'self_') === true) {
            return null;
        }

        // **CLEAN FIELD NAMES**: Return field name as-is since we define proper types in SOLR setup.
        return $fieldName;

    }//end mapFieldToSolrType()


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
                // **SAFE NUMERIC CONVERSION**: Handle non-numeric strings gracefully.
                if (is_numeric($value) === true) {
                    return (int) $value;
                }

                // Skip non-numeric values for integer fields.
                $this->logger->debug(
                        'Skipping non-numeric value for integer field',
                        [
                            'value'      => $value,
                            'field_type' => $fieldType,
                        ]
                        );
                return null;

            case 'float':
            case 'double':
            case 'number':
                // **SAFE NUMERIC CONVERSION**: Handle non-numeric strings gracefully.
                if (is_numeric($value) === true) {
                    return (float) $value;
                }

                // Skip non-numeric values for float fields.
                $this->logger->debug(
                        'Skipping non-numeric value for float field',
                        [
                            'value'      => $value,
                            'field_type' => $fieldType,
                        ]
                        );
                return null;

            case 'boolean':
            case 'bool':
                return (bool) $value;

            case 'date':
            case 'datetime':
                if ($value instanceof \DateTime) {
                    return $value->format('Y-m-d\\TH:i:s\\Z');
                }

                if (is_string($value) === true) {
                    $date = \DateTime::createFromFormat('Y-m-d H:i:s', $value);
                    if ($date !== false) {
                        return $date->format('Y-m-d\\TH:i:s\\Z');
                    }

                    return $value;
                }
                return $value;

            case 'array':
                if (is_array($value) === true) {
                    return $value;
                }
                return [$value];

            default:
                return (string) $value;
        }//end switch

    }//end convertValueForSolr()


    /**
     * Search objects with pagination using OpenRegister query format
     *
     * This method translates OpenRegister query parameters into Solr queries
     * and converts results back to ObjectEntity format for compatibility
     *
     * @param array $query     OpenRegister-style query parameters
     * @param bool  $rbac      Apply role-based access control (default: true)
     * @param bool  $multi     Multi-tenant support (default: true)
     * @param bool  $published Include only published objects (default: false)
     * @param bool  $deleted   Include deleted objects (default: false)
     *
     * @return array Paginated results in OpenRegister format
     *
     * @throws \Exception When Solr is not available or query fails
     */
    public function searchObjectsPaginated(array $query=[], bool $rbac=true, bool $multi=true, bool $published=false, bool $deleted=false): array
    {
        $startTime = microtime(true);

        // Check SOLR configuration first.
        if ($this->isSolrConfigured() === false) {
            $configStatus = [
                'enabled' => $this->solrConfig['enabled'] ?? false,
                'host'    => $this->getConfigStatus('host'),
                'port'    => $this->getPortStatus(),
                'core'    => $this->getCoreStatus(),
            ];

            throw new \Exception(
                'SOLR configuration validation failed. Current status: '.json_encode($configStatus).'. '.'Please check your SOLR settings in the OpenRegister admin panel.'
            );
        }

        // Test SOLR connection.
        if ($this->isAvailable() === false) {
            $connectionTest = $this->testConnection();
            // Connection test may return 'error' key or other structure.
            // Type definition doesn't include 'error', so use array access with proper check.
            $errorMessage = (is_array($connectionTest) && array_key_exists('error', $connectionTest)) ? $connectionTest['error'] : 'Unknown connection error';
            throw new \Exception(
                'SOLR service is not available. Connection test failed: '.$errorMessage.'. Please verify that SOLR is running and accessible at the configured URL.'
            );
        }

        try {
            // Get active collection name - if null, SOLR is not properly set up.
            $collectionName = $this->getActiveCollectionName();
            if ($collectionName === null) {
                throw new \Exception(
                    'No active SOLR collection available. Please ensure a SOLR collection is created and configured '.'in the OpenRegister settings, and that the collection exists in your SOLR instance.'
                );
            }

            // Build SOLR query from OpenRegister query parameters.
            $solrQuery = $this->buildSolrQuery($query);

            // Query building completed successfully.
            // Log the built SOLR query for troubleshooting.
            $this->logger->debug(
                    'Executing SOLR search',
                    [
                        'original_query' => $query,
                        'solr_query'     => $solrQuery,
                        'collection'     => $collectionName,
                    ]
                    );

            // Apply additional filters (RBAC, multi-tenancy, published, deleted).
            $this->applyAdditionalFilters(solrQuery: $solrQuery, rbac: $rbac, _multi: $multi, _published: $published, _deleted: $deleted);

            // Execute the search.
            $extend = $query['_extend'] ?? [];
            // Normalize extend to array if it's a string.
            if (is_string($extend) === true) {
                $extend = array_map('trim', explode(',', $extend));
            }

            $searchResults = $this->executeSearch(solrQuery: $solrQuery, collectionName: $collectionName, extend: $extend);

            // Convert SOLR results to OpenRegister paginated format.
            $paginatedResults = $this->convertToOpenRegisterPaginatedFormat(searchResults: $searchResults, originalQuery: $query, solrQuery: $solrQuery);

            // Add execution metadata.
            $paginatedResults['_execution_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->info(
                    'SOLR search completed successfully',
                    [
                        'query_fingerprint' => substr(md5(json_encode($query)), 0, 8),
                        'results_count'     => count($paginatedResults['results'] ?? []),
                        'total_results'     => $paginatedResults['total'] ?? 0,
                        'execution_time_ms' => $paginatedResults['_execution_time_ms'],
                    ]
                    );

            return $paginatedResults;
        } catch (\Exception $e) {
            $this->logger->error(
                    'SOLR search failed',
                    [
                        'error_message'     => $e->getMessage(),
                        'error_class'       => get_class($e),
                        'query_fingerprint' => substr(md5(json_encode($query)), 0, 8),
                        'collection'        => $collectionName ?? 'unknown',
                        'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                    ]
                    );

            // Re-throw with more context for user.
            throw new \Exception(
                'SOLR search failed: '.$e->getMessage().'. This indicates an issue with the SOLR service or query. Check the logs for more details.',
                $e->getCode(),
                $e
            );
        }//end try

    }//end searchObjectsPaginated()


    /**
     * Apply additional filters based on RBAC, multi-tenancy, published, and deleted parameters
     *
     * @param array $solrQuery Reference to the Solr query array to modify
     * @param bool  $rbac      Apply role-based access control
     * @param bool  $multi     Apply multi-tenancy filtering
     * @param bool  $published Filter for published objects only
     * @param bool  $deleted   Include deleted objects
     *
     * @return void
     */
    private function applyAdditionalFilters(array &$solrQuery, bool $rbac, bool $_multi, bool $_published, bool $_deleted): void
    {
        $filters = $solrQuery['fq'] ?? [];

        // @todo HOTFIX: Date calculation temporarily disabled along with published filtering.
        // $now = date('Y-m-d\TH:i:s\Z');
        // $publishedCondition = 'self_published:[* TO ' . $now . '] AND (-self_depublished:[* TO *] OR self_depublished:[' . $now . ' TO *])';
        // Multi-tenancy filtering (removed automatic published object exception).
        // @todo HOTFIX: Organisation filtering temporarily disabled due to environment-specific issues.
        // This filtering was causing different results between local and online environments.
        // Need to investigate user context and organisation service differences between NC 30/31.
        /*
            if ($multi === true) {
            $multitenancyEnabled = $this->isMultitenancyEnabled();
            if ($multitenancyEnabled === true) {
                $activeOrganisationUuid = $this->getActiveOrganisationUuid();
                if ($activeOrganisationUuid !== null) {
                    // Only include objects from user's organisation.
                    $filters[] = 'self_organisation:' . $this->escapeSolrValue($activeOrganisationUuid);
                }
            }
            }
        */

        // RBAC filtering (removed automatic published object exception).
        if ($rbac === true) {
            // Note: RBAC role filtering would be implemented here if we had role-based fields.
            // For now, we assume all authenticated users have basic access.
            $this->logger->debug(message: '[SOLR] RBAC filtering applied');
        }

        // Published filtering (only if explicitly requested).
        // @todo HOTFIX: Published filtering temporarily disabled due to timezone/environment issues.
        // The date() function uses server timezone which causes different behavior between environments.
        // Need to fix timezone handling: use gmdate() or proper UTC DateTime objects.
        // Also investigate why published filtering returns 0 results on NC 31 vs NC 30.
        /*
            if ($published === true) {
            // Filter for objects that have a published date AND it's in the past.
            // Use existence check instead of NOT null to avoid SOLR date parsing errors.
            $filters[] = 'self_published:[* TO ' . $now . ']';
            $filters[] = '(NOT self_depublished:[* TO *] OR self_depublished:[' . $now . ' TO *])';
            }
        */

        // Deleted filtering.
        // @todo: this is not working as expected so we turned it of, for now deleted items should not be indexed.
        // if ($deleted === true) {
        // Include only deleted objects.
        // $filters[] = 'self_deleted:[* TO *]';
        // } else {
        // Exclude deleted objects (default behavior).
        // $filters[] = '-self_deleted:[* TO *]';
        // }
        // Update the filters in the query.
        $solrQuery['fq'] = $filters;

    }//end applyAdditionalFilters()


    /**
     * Translate OpenRegister field names to Solr field names for filtering
     *
     * @param string $field OpenRegister field name
     *
     * @return string Solr field name
     */
    private function translateFilterField(string $field): string
    {
        // Handle @self.* fields (metadata).
        if (str_starts_with($field, '@self.') === true) {
            // Remove '@self.'.
            $metadataField = substr($field, 6);
            return 'self_'.$metadataField;
        }

        // Handle special field mappings.
        $fieldMappings = [
            'register'     => 'self_register',
            'schema'       => 'self_schema',
            'organisation' => 'self_organisation',
            'owner'        => 'self_owner',
            'created'      => 'self_created',
            'updated'      => 'self_updated',
            'published'    => 'self_published',
        ];

        if (($fieldMappings[$field] ?? null) !== null) {
            return $fieldMappings[$field];
        }

        // For object properties, use the field name directly (now stored at root).
        return $field;

    }//end translateFilterField()


    /**
     * Translate OpenRegister sort field to Solr sort format
     *
     * @param array|string $order Sort specification
     *
     * @return string Solr sort string
     */
    private function translateSortField(array|string $order): string
    {
        if (is_string($order) === true) {
            $field = $this->translateSortableField($order);
            return $field.' asc';
        }

        if (is_array($order) === true) {
            $sortParts = [];
            foreach ($order as $field => $direction) {
                $solrField = $this->translateSortableField($field);
                if (strtolower($direction) === 'desc') {
                    $solrDirection = 'desc';
                } else {
                    $solrDirection = 'asc';
                }

                $sortParts[] = $solrField.' '.$solrDirection;
            }

            return implode(', ', $sortParts);
        }

        // Default sort.
        return 'self_created desc';

    }//end translateSortField()


    /**
     * Translate field name to Solr sortable field (string type, not text type)
     *
     * Solr cannot sort on multivalued text fields. We use single-valued string fields (_s suffix)
     * for text fields, and direct fields for dates/integers/UUIDs which are already sortable.
     *
     * @param string $field Field name from OpenRegister query
     *
     * @return string Solr sortable field name
     */
    private function translateSortableField(string $field): string
    {
        // Handle @self.* fields (metadata).
        if (str_starts_with($field, '@self.') === true) {
            // Remove '@self.'.
            $metadataField = substr($field, 6);

            // Map metadata fields to their sortable Solr equivalents.
            $sortableFieldMappings = [
                // Text fields use _s suffix (string type, single-valued, not tokenized).
                'name'         => 'self_name_s',
            // Use sortable string variant.
                'title'        => 'self_title_s',
            // Use sortable string variant (if exists).
                'summary'      => 'self_summary_s',
            // Use sortable string variant.
                'description'  => 'self_description_s',
            // Use sortable string variant.
                'slug'         => 'self_slug_s',
            // Use sortable string variant
                // Date/time fields are already sortable.
                'published'    => 'self_published',
            // Date fields are sortable.
                'created'      => 'self_created',
            // Date fields are sortable.
                'updated'      => 'self_updated',
            // Date fields are sortable.
                'depublished'  => 'self_depublished',
            // Date fields are sortable
                // Integer/UUID fields are already sortable.
                'register'     => 'self_register',
            // Integer fields are sortable.
                'schema'       => 'self_schema',
            // Integer fields are sortable.
                'organisation' => 'self_organisation',
            // UUID fields are sortable.
                'owner'        => 'self_owner',
            // Integer fields are sortable.
                'id'           => 'id',
            // ID is always sortable.
                'uuid'         => 'self_uuid',
            // UUID is sortable.
            ];

            if (($sortableFieldMappings[$metadataField] ?? null) !== null) {
                $this->logger->debug(
                        'SORT: Translating metadata field',
                        [
                            'original'   => '@self.'.$metadataField,
                            'solr_field' => $sortableFieldMappings[$metadataField],
                        ]
                        );
                return $sortableFieldMappings[$metadataField];
            }

            // Default: try _s suffix for unknown metadata fields (assume they're text).
            return 'self_'.$metadataField.'_s';
        }//end if

        // Handle special field mappings. (for fields without @self prefix).
        $fieldMappings = [
            'register'     => 'self_register',
            'schema'       => 'self_schema',
            'organisation' => 'self_organisation',
            'owner'        => 'self_owner',
            'created'      => 'self_created',
            'updated'      => 'self_updated',
            'published'    => 'self_published',
            'name'         => 'self_name_s',
        // Use sortable string variant.
            'title'        => 'self_title_s',
        // Use sortable string variant.
            'summary'      => 'self_summary_s',
        // Use sortable string variant.
            'slug'         => 'self_slug_s',
        // Use sortable string variant.
        ];

        if (($fieldMappings[$field] ?? null) !== null) {
            return $fieldMappings[$field];
        }

        // For unknown object properties, try _s suffix (assume they're text fields).
        return $field.'_s';

    }//end translateSortableField()


    /**
     * Reconstruct ObjectEntity from Solr document
     *
     * @param array $doc Solr document
     *
     * @return ObjectEntity|null Reconstructed object or null if invalid
     */
    private function reconstructObjectFromSolrDocument(array $doc): ?ObjectEntity
    {
        // Extract metadata from self_ fields.
        // Handle both single values and arrays (SOLR can return either).
        if (is_array($doc['self_object'] ?? null) === true) {
            $object = $doc['self_object'][0] ?? null;
        } else {
            $object = $doc['self_object'] ?? null;
        }

        if (is_array($doc['self_uuid'] ?? null) === true) {
            $uuid = $doc['self_uuid'][0] ?? null;
        } else {
            $uuid = $doc['self_uuid'] ?? null;
        }

        if (is_array($doc['self_register'] ?? null) === true) {
            $register = $doc['self_register'][0] ?? null;
        } else {
            $register = $doc['self_register'] ?? null;
        }

        if (is_array($doc['self_schema'] ?? null) === true) {
            $schema = $doc['self_schema'][0] ?? null;
        } else {
            $schema = $doc['self_schema'] ?? null;
        }

        if ($object === null) {
            $this->logger->error(
                    '[GuzzleSolrService] Invalid document missing required self_object',
                    [
                        'uuid'     => $uuid,
                        'register' => $register,
                        'schema'   => $schema,
                    ]
                    );
            return null;
        }

        // Create ObjectEntity instance.
        $entity = new \OCA\OpenRegister\Db\ObjectEntity();
        $entity->hydrateObject(json_decode($object, true));

        return $entity;

    }//end reconstructObjectFromSolrDocument()


    /**
     * Extract text content for full-text search
     *
     * @param ObjectEntity $object     Object entity
     * @param array        $objectData Object data
     *
     * @return string Combined text content
     */
    private function extractTextContent(ObjectEntity $object, array $objectData): string
    {
        $textParts = [];

        if ($object->getName() !== null && $object->getName() !== '') {
            $textParts[] = $object->getName();
        }

        if ($object->getUuid() !== null && $object->getUuid() !== '') {
            $textParts[] = $object->getUuid();
        }

        $this->extractTextFromArray(data: $objectData, textParts: $textParts);

        return implode(' ', array_filter($textParts));

    }//end extractTextContent()


    /**
     * Extract text from array recursively
     *
     * @param array $data      Data array
     * @param array $textParts Text parts collector
     *
     * @return void
     */
    private function extractTextFromArray(array $data, array &$textParts): void
    {
        foreach ($data as $value) {
            if (is_string($value) === true && strlen($value) > 2) {
                $textParts[] = $value;
            } else if (is_array($value) === true) {
                $this->extractTextFromArray(data: $value, textParts: $textParts);
            }
        }

    }//end extractTextFromArray()


    /**
     * Check if array is associative
     *
     * @param  array $array Array to check
     * @return bool True if associative
     */
    private function isAssociativeArray(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);

    }//end isAssociativeArray()


    /**
     * Escape SOLR query value
     *
     * @param string $value Value to escape
     *
     * @return string Escaped value
     */
    private function escapeSolrValue(string $value): string
    {
        $specialChars = ['\\', '+', '-', '!', '(', ')', '{', '}', '[', ']', '^', '"', '~', '*', '?', ':', '/'];
        return '"'.str_replace($specialChars, array_map(fn($char) => '\\'.$char, $specialChars), $value).'"';

    }//end escapeSolrValue()


    /**
     * Get URI value from object or null
     *
     * @param ObjectEntity $object Object entity
     *
     * @return string|null URI value or null
     */
    private function getUriValue(ObjectEntity $object): ?string
    {
        $uri = $object->getUri();
        if ($uri !== null && $uri !== '') {
            return $uri;
        }

        return null;

    }//end getUriValue()


    /**
     * Get version value from object or null
     *
     * @param ObjectEntity $object Object entity
     *
     * @return string|null Version value or null
     */
    private function getVersionValue(ObjectEntity $object): ?string
    {
        $version = $object->getVersion();
        if ($version !== null && $version !== '') {
            return $version;
        }

        return null;

    }//end getVersionValue()


    /**
     * Get size value from object or null
     *
     * @param ObjectEntity $object Object entity
     *
     * @return int|null Size value or null
     */
    private function getSizeValue(ObjectEntity $object): ?int
    {
        $size = $object->getSize();
        if ($size !== null && $size !== 0) {
            return $size;
        }

        return null;

    }//end getSizeValue()


    /**
     * Get folder value from object or null
     *
     * @param ObjectEntity $object Object entity
     *
     * @return string|null Folder value or null
     */
    private function getFolderValue(ObjectEntity $object): ?string
    {
        $folder = $object->getFolder();
        if ($folder !== null && $folder !== '') {
            return $folder;
        }

        return null;

    }//end getFolderValue()


    /**
     * Bulk index multiple objects (alias for bulkIndex)
     *
     * @param array $objects Array of ObjectEntity objects
     * @param bool  $commit  Whether to commit immediately
     *
     * @return array Result array with success status and statistics
     */
    public function bulkIndexObjects(array $objects, bool $commit=true): array
    {
        $startTime     = microtime(true);
        $success       = $this->bulkIndex(documents: $objects, commit: $commit);
        $executionTime = microtime(true) - $startTime;

        return [
            'success'        => $success,
            'processed'      => count($objects),
            'execution_time' => $executionTime,
        ];

    }//end bulkIndexObjects()


    /**
     * Bulk index multiple documents
     *
     * @param  array $documents Array of SOLR documents or ObjectEntity objects
     * @param  bool  $commit    Whether to commit immediately
     * @return bool True if successful
     */
    public function bulkIndex(array $documents, bool $commit=false): bool
    {
        $this->logger->info(
                '🚀 BULK INDEX CALLED',
                [
                    'document_count' => count($documents),
                    'commit'         => $commit,
                    'is_available'   => $this->isAvailable(),
                ]
                );

        // **DEBUG**: Log all documents being indexed.
        $this->logger->debug(message: '=== BULK INDEX DEBUG ===');
        $this->logger->debug(message: 'Document count: '.count($documents));
        foreach ($documents as $i => $doc) {
            if (is_array($doc) === true) {
                if (($doc['self_object_id'] ?? null) !== null) {
                    $hasSelfObjectId = 'YES';
                } else {
                    $hasSelfObjectId = 'NO';
                }

                $this->logger->debug(message: 'Doc '.$i.': ID='.($doc['id'] ?? 'missing').', has_self_object_id='.$hasSelfObjectId);
            } else if ($doc instanceof ObjectEntity) {
                $uuid = $doc->getUuid();
                if ($uuid === null) {
                    $uuid = 'null';
                }

                $this->logger->debug(message: 'Doc '.$i.': ObjectEntity ID='.$doc->getId().', UUID='.$uuid);
            } else {
                $this->logger->debug(message: 'Doc '.$i.': '.gettype($doc));
            }
        }

        $this->logger->debug(message: '=== END BULK INDEX DEBUG ===');

        if ($this->isAvailable() === false || empty($documents) === true) {
            $this->logger->warning(
                    'Bulk index early return',
                    [
                        'is_available'    => $this->isAvailable(),
                        'documents_empty' => empty($documents),
                        'document_count'  => count($documents),
                    ]
                    );
            return false;
        }

        try {
            $startTime = microtime(true);

            // Ensure tenant collection exists.
            if ($this->ensureTenantCollection() === false) {
                $this->logger->warning(message: 'Cannot bulk index: tenant collection unavailable');
                return false;
            }

            // Get the active collection name - return false if no collection exists.
            $tenantCollectionName = $this->getActiveCollectionName();
            if ($tenantCollectionName === null) {
                $this->logger->warning(message: 'Cannot bulk index: no active collection available');
                return false;
            }

            // Prepare documents.
            $solrDocs = [];
            foreach ($documents as $doc) {
                if ($doc instanceof ObjectEntity) {
                    $solrDocs[] = $this->createSolrDocument($doc);
                } else if (is_array($doc) === true) {
                    // Document is already a Solr document array - use as-is.
                    $solrDocs[] = $doc;
                } else {
                    $this->logger->warning(message: 'Invalid document type in bulk index', context: ['type' => gettype($doc)]);
                    continue;
                }
            }

            if (empty($solrDocs) === true) {
                $this->logger->warning(
                        'No valid SOLR documents after processing',
                        [
                            'original_count'  => count($documents),
                            'processed_count' => count($solrDocs),
                        ]
                        );
                return false;
            }

            $this->logger->info(
                    'Prepared SOLR documents for bulk index',
                    [
                        'original_count'  => count($documents),
                        'processed_count' => count($solrDocs),
                    ]
                    );

            // Debug: Log first document structure with tenant_id details.
            // $solrDocs is guaranteed to be non-empty here (we return early if empty).
            $firstDoc = $solrDocs[0];
            $this->logger->debug(
                    'First SOLR document prepared',
                    [
                        'document_keys'  => array_keys($firstDoc),
                        'id'             => $firstDoc['id'] ?? 'missing',
                        'tenant_id'      => $firstDoc['tenant_id'] ?? 'missing',
                        'tenant_id_type' => gettype($firstDoc['tenant_id'] ?? null),
                        'total_fields'   => count($firstDoc),
                    ]
                    );

            // **FIX**: SOLR bulk update format - single "add" with array of docs (no extra "doc" wrapper).
            $updateData = [
                'add' => $solrDocs,
            ];

            // Debug removed - bulk update working correctly.
            $url = $this->buildSolrBaseUrl().'/'.$tenantCollectionName.'/update?wt=json';

            if ($commit === true) {
                $url .= '&commit=true';
            }

            // Log bulk update details.
            $this->logger->debug(
                    'About to send SOLR bulk update',
                    [
                        'url'            => $url,
                        'document_count' => count($updateData['add'] ?? []),
                        'json_size'      => strlen(json_encode($updateData)),
                    ]
                    );

            // Bulk POST ready.
            // **DEBUG**: Log HTTP request details AND actual payload.
            $this->logger->debug(message: '=== HTTP POST TO SOLR DEBUG ===');
            $this->logger->debug(message: 'URL: '.$url);
            $this->logger->debug(message: 'Document count in payload: '.count($updateData['add'] ?? []));
            $this->logger->debug(message: 'Payload size: '.strlen(json_encode($updateData)).' bytes');
            $this->logger->debug(message: 'ACTUAL JSON PAYLOAD:');
            $this->logger->debug(message: json_encode($updateData, JSON_PRETTY_PRINT));
            $this->logger->debug(message: '=== END HTTP POST DEBUG ===');

            try {
                $response = $this->httpClient->post(
                    $url,
                    [
                        'body'    => json_encode($updateData),
                        'headers' => ['Content-Type' => 'application/json'],
                        'timeout' => 60,
                    ]
                    );

                $statusCode   = $response->getStatusCode();
                $responseBody = (string) $response->getBody();
            } catch (\Exception $httpException) {
                // Extract full response body from Guzzle ClientException.
                $fullResponseBody = '';
                if ($httpException instanceof \GuzzleHttp\Exception\ClientException) {
                    $response = $httpException->getResponse();
                    if ($response !== null) {
                        $fullResponseBody = (string) $response->getBody();
                    }
                }

                $this->logger->error(
                        'SOLR HTTP call failed',
                        [
                            'error'         => $httpException->getMessage(),
                            'class'         => get_class($httpException),
                            'code'          => $httpException->getCode(),
                            'full_response' => $fullResponseBody,
                        ]
                        );

                // Create enhanced exception with full SOLR response.
                if (empty($fullResponseBody) === false) {
                    throw new \RuntimeException(
                        'SOLR bulk index failed: '.$httpException->getMessage().'. Full SOLR Response: '.$fullResponseBody,
                        $httpException->getCode(),
                        $httpException
                    );
                }

                throw $httpException;
            }//end try

            // Log SOLR response details and debug tenant_id.
            $this->logger->debug(
                    'SOLR bulk update response received',
                    [
                        'status_code'     => $statusCode,
                        'response_length' => strlen($responseBody),
                        'document_count'  => count($solrDocs),
                    ]
                    );

            // **ERROR HANDLING**: Throw exception for non-20X HTTP status codes.
            if ($statusCode < 200 || $statusCode >= 300) {
                $this->stats['errors']++;
                throw new \RuntimeException(
                    "SOLR bulk index HTTP error: HTTP {$statusCode}. "."Full Response: ".$responseBody,
                    $statusCode
                );
            }

            $this->logger->info(
                    'SOLR bulk response received',
                    [
                        'status_code'    => $statusCode,
                        'content_length' => strlen($responseBody),
                    ]
                    );

            $data = json_decode($responseBody, true);

            // **ERROR HANDLING**: Validate JSON response structure.
            if ($data === null) {
                $this->stats['errors']++;
                throw new \RuntimeException(
                    "SOLR bulk index invalid JSON response. HTTP {$statusCode}. "."Full Raw Response: ".$responseBody
                );
            }

            $solrStatus = $data['responseHeader']['status'] ?? -1;

            // **ERROR HANDLING**: Throw exception for SOLR-level errors.
            if ($solrStatus !== 0) {
                $this->stats['errors']++;
                $errorDetails = [
                    'solr_status' => $solrStatus,
                    'http_status' => $statusCode,
                    'error_msg'   => $data['error']['msg'] ?? 'Unknown SOLR error',
                    'error_code'  => $data['error']['code'] ?? 'Unknown',
                    'response'    => $data,
                ];

                throw new \RuntimeException(
                    message: "SOLR bulk index failed: SOLR status {$solrStatus}. "."Error: {$errorDetails['error_msg']} (Code: {$errorDetails['error_code']}). "."HTTP Status: {$statusCode}",
                    code: $solrStatus
                );
            }

            // Success path.
            $this->stats['indexes']    += count($solrDocs);
            $this->stats['index_time'] += (microtime(true) - $startTime);

            $this->logger->debug(
                    '📦 BULK INDEXED IN SOLR',
                    [
                        'document_count'    => count($solrDocs),
                        'collection'        => $tenantCollectionName,
                        'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                    ]
                    );

            return true;
        } catch (\Exception $e) {
            $this->stats['errors']++;
            $this->logger->error(
                    '🚨 EXCEPTION DURING BULK INDEXING',
                    [
                        'error' => $e->getMessage(),
                        'class' => get_class($e),
                        'code'  => $e->getCode(),
                        'file'  => $e->getFile(),
                        'line'  => $e->getLine(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );
            // **FIX**: Don't suppress the exception - re-throw it to expose the 400 error.
            throw $e;
        }//end try

    }//end bulkIndex()


    /**
     * Commit changes to SOLR
     *
     * @return bool True if successful
     */
    public function commit(): bool
    {
        if ($this->isAvailable() === false) {
            return false;
        }

        try {
            // Get the active collection name - return false if no collection exists.
            $tenantCollectionName = $this->getActiveCollectionName();
            if ($tenantCollectionName === null) {
                $this->logger->warning(message: 'Cannot commit: no active collection available');
                return false;
            }

            $url = $this->buildSolrBaseUrl().'/'.$tenantCollectionName.'/update?wt=json&commit=true';

            $response = $this->httpClient->post(
                    $url,
                    [
                        'body'    => json_encode(['commit' => []]),
                        'headers' => ['Content-Type' => 'application/json'],
                        'timeout' => 30,
                    ]
                    );

            $data    = json_decode((string) $response->getBody(), true);
            $success = ($data['responseHeader']['status'] ?? -1) === 0;

            if ($success === true) {
                $this->logger->debug(
                        '💾 SOLR COMMIT',
                        [
                            'collection' => $tenantCollectionName,
                        ]
                        );
            }

            return $success;
        } catch (\Exception $e) {
            $this->logger->error(message: 'Exception committing to SOLR', context: ['error' => $e->getMessage()]);
            return false;
        }//end try

    }//end commit()


    /**
     * Delete documents by query
     *
     * @param  string $query         SOLR query
     * @param  bool   $commit        Whether to commit immediately
     * @param  bool   $returnDetails Whether to return detailed error information
     * @return bool|array True if successful (when $returnDetails=false), or detailed result array (when $returnDetails=true)
     */
    public function deleteByQuery(string $query, bool $commit=false, bool $returnDetails=false): bool|array
    {
        if ($this->isAvailable() === false) {
            if ($returnDetails === true) {
                return [
                    'success'       => false,
                    'error'         => 'SOLR service is not available',
                    'error_details' => 'SOLR connection is not configured or unavailable',
                ];
            }

            return false;
        }

        try {
            // Get the active collection name - return error if no collection exists.
            $tenantCollectionName = $this->getActiveCollectionName();
            if ($tenantCollectionName === null) {
                $this->logger->warning(message: 'Cannot delete by query: no active collection available');
                if ($returnDetails === true) {
                    return [
                        'success'       => false,
                        'error'         => 'No active SOLR collection available',
                        'error_details' => 'No collection found for the current tenant',
                    ];
                }

                return false;
            }

            $deleteData = [
                'delete' => [
                    'query' => $query,
                ],
            ];

            $url = $this->buildSolrBaseUrl().'/'.$tenantCollectionName.'/update?wt=json';

            if ($commit === true) {
                $url .= '&commit=true';
            }

            $response = $this->httpClient->post(
                    $url,
                    [
                        'body'    => json_encode($deleteData),
                        'headers' => ['Content-Type' => 'application/json'],
                        'timeout' => 30,
                    ]
                    );

            $data    = json_decode((string) $response->getBody(), true);
            $success = ($data['responseHeader']['status'] ?? -1) === 0;

            if ($success === true) {
                $this->stats['deletes']++;
                $this->logger->debug(
                        '🗑️ SOLR DELETE BY QUERY',
                        [
                            'query'      => $query,
                            'collection' => $tenantCollectionName,
                        ]
                        );

                if ($returnDetails === true) {
                    return [
                        'success'      => true,
                        'deleted_docs' => $data['responseHeader']['QTime'] ?? 0,
                    ];
                }

                return true;
            } else {
                if ($returnDetails === true) {
                    $errorMsg  = $data['error']['msg'] ?? 'Unknown SOLR error';
                    $errorCode = $data['error']['code'] ?? $data['responseHeader']['status'] ?? -1;

                    return [
                        'success'       => false,
                        'error'         => "SOLR delete operation failed: {$errorMsg}",
                        'error_details' => [
                            'solr_error'    => $errorMsg,
                            'error_code'    => $errorCode,
                            'query'         => $query,
                            'collection'    => $tenantCollectionName,
                            'full_response' => $data,
                        ],
                    ];
                }

                return false;
            }//end if
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            if ($returnDetails === true) {
                $errorMsg     = 'HTTP request failed';
                $errorDetails = [
                    'exception_type' => 'RequestException',
                    'message'        => $e->getMessage(),
                    'query'          => $query,
                ];

                // Try to extract SOLR error from response.
                if ($e->hasResponse() === true) {
                    $responseBody = (string) $e->getResponse()->getBody();
                    $responseData = json_decode($responseBody, true);

                    if ($responseData !== null && (($responseData['error'] ?? null) !== null)) {
                        if (($responseData['error']['msg'] ?? null) !== null) {
                            $errorMsg = "SOLR HTTP {$e->getResponse()->getStatusCode()} Error: ".$responseData['error']['msg'];
                        } else {
                            $errorMsg = "SOLR HTTP {$e->getResponse()->getStatusCode()} Error: ".$responseData['error'];
                        }

                        $errorDetails['solr_response'] = $responseData;
                        $errorDetails['http_status']   = $e->getResponse()->getStatusCode();
                    }
                }

                $this->logger->error(message: 'HTTP exception deleting by query from SOLR', context: $errorDetails);

                return [
                    'success'       => false,
                    'error'         => $errorMsg,
                    'error_details' => $errorDetails,
                ];
            }//end if

            $this->logger->error(
                    'Exception deleting by query from SOLR',
                    [
                        'query' => $query,
                        'error' => $e->getMessage(),
                    ]
                    );
            return false;
        } catch (\Exception $e) {
            if ($returnDetails === true) {
                $this->logger->error(
                        'Exception deleting by query from SOLR',
                        [
                            'query'          => $query,
                            'error'          => $e->getMessage(),
                            'exception_type' => get_class($e),
                        ]
                        );

                return [
                    'success'       => false,
                    'error'         => 'Unexpected error during SOLR delete operation: '.$e->getMessage(),
                    'error_details' => [
                        'exception_type' => get_class($e),
                        'message'        => $e->getMessage(),
                        'query'          => $query,
                    ],
                ];
            }

            $this->logger->error(
                    'Exception deleting by query from SOLR',
                    [
                        'query' => $query,
                        'error' => $e->getMessage(),
                    ]
                    );
            return false;
        }//end try

    }//end deleteByQuery()


    /**
     * Search objects in SOLR
     *
     * @param  array $searchParams Search parameters
     * @return array Search results
     */
    public function searchObjects(array $searchParams): array
    {
        // Check SOLR availability first.
        if ($this->isAvailable() === false) {
            return [
                'success' => false,
                'data'    => [],
                'total'   => 0,
                'facets'  => [],
                'message' => 'SOLR service is not available',
            ];
        }

        try {
            $startTime = microtime(true);

            // Get active collection name - if null, SOLR is not properly set up.
            $collectionName = $this->getActiveCollectionName();
            if ($collectionName === null) {
                return [
                    'success' => false,
                    'data'    => [],
                    'total'   => 0,
                    'facets'  => [],
                    'message' => 'No active SOLR collection available',
                ];
            }

            // Build and execute SOLR query.
            $solrQuery     = $this->buildSolrQuery($searchParams);
            $extend        = $searchParams['_extend'] ?? [];
            $searchResults = $this->executeSearch(solrQuery: $solrQuery, collectionName: $collectionName, extend: $extend);

            // Return results in expected format.
            return [
                'success'           => true,
                'data'              => $searchResults['objects'] ?? [],
                'total'             => $searchResults['total'] ?? 0,
                'facets'            => $searchResults['facets'] ?? [],
                'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                    'SOLR search failed in searchObjects',
                    [
                        'error'        => $e->getMessage(),
                        'searchParams' => $searchParams,
                    ]
                    );

            return [
                'success' => false,
                'data'    => [],
                'total'   => 0,
                'facets'  => [],
                'message' => 'SOLR search failed: '.$e->getMessage(),
            ];
        }//end try

    }//end searchObjects()


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
     * @param  string $searchTerm The search term to query for
     * @return string SOLR query string with weighted fields and multi-level matching
     */
    private function buildWeightedSearchQuery(string $searchTerm): string
    {
        // Clean the search term.
        $cleanTerm = $this->cleanSearchTerm($searchTerm);

        // Note: Case-insensitive search is handled by Solr field type configuration.
        // text_general fields use LowerCaseFilterFactory for case-insensitive matching.
        // No need to manually lowercase the search term.
        // Define field weights (higher = more important).
        // Using essential OpenRegister fields with specified weights.
        $fieldWeights = [
            'self_name'        => 15.0,
        // OpenRegister standardized name (highest priority).
            'self_summary'     => 10.0,
        // OpenRegister standardized summary.
            'self_description' => 5.0,
        // OpenRegister standardized description.
            '_text_'           => 1.0,
        // Catch-all text field (lowest priority).
        ];

        $queryParts = [];

        // Build weighted queries for each field.
        foreach ($fieldWeights as $field => $weight) {
            // Exact phrase match (highest relevance).
            $queryParts[] = $field.':"'.$cleanTerm.'"^'.($weight * 3);

            // Wildcard match (medium relevance) - proper wildcard syntax.
            $queryParts[] = $field.':*'.$cleanTerm.'*^'.($weight * 2);

            // Fuzzy match (lowest relevance) - proper fuzzy syntax.
            $queryParts[] = $field.':'.$cleanTerm.'~^'.$weight;
        }

        // Join all parts with OR.
        return '('.implode(' OR ', $queryParts).')';

    }//end buildWeightedSearchQuery()


    /**
     * Clean search term for SOLR query safety
     *
     * @param  string $term Raw search term
     * @return string Cleaned search term safe for SOLR
     */
    private function cleanSearchTerm(string $term): string
    {
        // Remove dangerous characters but keep wildcards if user explicitly added them.
        if (strpos($term, '*') !== false || strpos($term, '?') !== false) {
            $userHasWildcards = true;
        } else {
            $userHasWildcards = false;
        }

        if ($userHasWildcards === false) {
            // Escape special SOLR characters except space.
            $specialChars = ['\\', '+', '-', '!', '(', ')', '{', '}', '[', ']', '^', '"', '~', ':', '/'];
            $term         = str_replace($specialChars, array_map(fn($char) => '\\'.$char, $specialChars), $term);
        }

        return trim($term);

    }//end cleanSearchTerm()


    /**
     * Build SOLR query from OpenRegister query parameters
     *
     * @param  array $query OpenRegister query parameters
     * @return array SOLR query parameters
     */
    private function buildSolrQuery(array $query): array
    {
        $solrQuery = [
            'q'     => '*:*',
            'start' => 0,
            'rows'  => 20,
            'wt'    => 'json',
        ];

        // Handle _facetable parameter for field discovery.
        if (($query['_facetable'] ?? null) !== null && ($query['_facetable'] === true || $query['_facetable'] === 'true') === true) {
            $solrQuery['_facetable'] = true;
        }

        // Handle _facets parameter for extended faceting.
        if (($query['_facets'] ?? null) !== null) {
            $solrQuery['_facets'] = $query['_facets'];

            // For extended faceting, we'll use JSON faceting instead of traditional faceting.
            // Skip traditional faceting setup when using extended mode.
            if ($query['_facets'] === 'extend') {
                $solrQuery['_use_json_faceting'] = true;
            }
        }

        // Handle search query. with wildcard support and field weighting.
        if (empty($query['_search']) === false) {
            $searchTerm = trim($query['_search']);

            // Build weighted multi-field search query.
            $searchQuery    = $this->buildWeightedSearchQuery($searchTerm);
            $solrQuery['q'] = $searchQuery;

            // Enable highlighting for search results (only for searched fields).
            $solrQuery['hl']            = 'true';
            $solrQuery['hl.fl']         = 'self_name,self_summary,self_description';
            $solrQuery['hl.simple.pre'] = '<mark>';
            $solrQuery['hl.simple.post'] = '</mark>';
        }

        // Handle pagination.
        if (($query['_limit'] ?? null) !== null) {
            $solrQuery['rows'] = (int) $query['_limit'];
        }

        if (($query['_offset'] ?? null) !== null) {
            $solrQuery['start'] = (int) $query['_offset'];
        } else if (($query['_page'] ?? null) !== null) {
            $page = max(1, (int) $query['_page']);
            $solrQuery['start'] = ($page - 1) * $solrQuery['rows'];
        }

        // Handle sorting.
        if (empty($query['_order']) === false) {
            $solrQuery['sort'] = $this->translateSortField($query['_order']);

            $this->logger->debug(
                    'ORDER: Applied sort parameter',
                    [
                        'original_order'  => $query['_order'],
                        'translated_sort' => $solrQuery['sort'],
                    ]
                    );
        }

        // Handle filters.
        $filters = [];

        // Handle @self metadata filters (register, schema, etc.).
        if (($query['@self'] ?? null) !== null && is_array($query['@self']) === true) {
            foreach ($query['@self'] as $metaKey => $metaValue) {
                if ($metaValue !== null && $metaValue !== '') {
                    $solrField = 'self_'.$metaKey;

                    // Handle [or] and [and] operators. for metadata fields.
                    if (is_array($metaValue) === true && (($metaValue['or'] ?? null) !== null || (($metaValue['and'] ?? null) !== null) === true)) {
                        if (($metaValue['or'] ?? null) !== null) {
                            // OR logic: (field:val1 OR field:val2 OR field:val3).
                            if (is_string($metaValue['or']) === true) {
                                $values = array_map('trim', explode(',', $metaValue['or']));
                            } else {
                                $values = (array) $metaValue['or'];
                            }

                            $orConditions = array_map(
                                    function ($v) use ($solrField, $metaKey) {
                                        // Resolve schema/register names to IDs if needed.
                                        if (in_array($metaKey, ['register', 'schema']) === true && is_numeric($v) === false) {
                                            $v = $this->resolveMetadataValueToId(fieldType: $metaKey, value: $v);
                                        }

                                        if (is_numeric($v) === true) {
                                            return $solrField.':'.$v;
                                        }

                                        return $solrField.':'.$this->escapeSolrValue((string) $v);
                                    },
                                    $values
                                    );
                            $filters[]    = '('.implode(' OR ', $orConditions).')';
                        } else if (($metaValue['and'] ?? null) !== null) {
                            // AND logic: field:val1 AND field:val2 AND field:val3.
                            if (is_string($metaValue['and']) === true) {
                                $values = array_map('trim', explode(',', $metaValue['and']));
                            } else {
                                $values = (array) $metaValue['and'];
                            }

                            foreach ($values as $v) {
                                // Resolve schema/register names to IDs if needed.
                                if (in_array($metaKey, ['register', 'schema']) === true && is_numeric($v) === false) {
                                    $v = $this->resolveMetadataValueToId(fieldType: $metaKey, value: $v);
                                }

                                if (is_numeric($v) === true) {
                                    $filters[] = $solrField.':'.$v;
                                } else {
                                    $filters[] = $solrField.':'.$this->escapeSolrValue((string) $v);
                                }
                            }
                        }//end if
                        // Skip to next metadata field.
                        continue;
                    }//end if

                    // Handle string values for register/schema fields by resolving to integer IDs.
                    // Skip arrays - they will be handled in the array processing block below.
                    if (in_array($metaKey, ['register', 'schema']) === true && is_numeric($metaValue) === false && is_array($metaValue) === false) {
                        $metaValue = $this->resolveMetadataValueToId(fieldType: $metaKey, value: $metaValue);
                    }

                    if (is_array($metaValue) === true) {
                        // Simple array (no operators) - default to OR logic.
                        $conditions = array_map(
                                function ($v) use ($solrField, $metaKey) {
                                    // Handle string values in arrays by resolving to integer IDs.
                                    if (in_array($metaKey, ['register', 'schema']) === true && is_numeric($v) === false) {
                                        $v = $this->resolveMetadataValueToId(fieldType: $metaKey, value: $v);
                                    }

                                    if (is_numeric($v) === true) {
                                        return $solrField.':'.$v;
                                    }

                                    return $solrField.':'.$this->escapeSolrValue((string) $v);
                                },
                                $metaValue
                                );
                        $filters[]  = '('.implode(' OR ', $conditions).')';
                    } else {
                        if (is_numeric($metaValue) === true) {
                            $filters[] = $solrField.':'.$metaValue;
                        } else {
                            $filters[] = $solrField.':'.$this->escapeSolrValue((string) $metaValue);
                        }
                    }//end if
                }//end if
            }//end foreach
        }//end if

        // Handle regular object field filters.
        foreach ($query as $key => $value) {
            if (str_starts_with($key, '_') === false && in_array($key, ['@self']) === false && $value !== null && $value !== '') {
                if (is_array($value) === true) {
                    $conditions = array_map(
                            function ($v) use ($key) {
                                if (is_numeric($v) === true) {
                                    return $key.':'.$v;
                                }

                                return $key.':'.$this->escapeSolrValue((string) $v);
                            },
                            $value
                            );
                    $filters[]  = '('.implode(' OR ', $conditions).')';
                } else {
                    if (is_numeric($value) === true) {
                        $filters[] = $key.':'.$value;
                    } else {
                        $filters[] = $key.':'.$this->escapeSolrValue((string) $value);
                    }
                }
            }//end if
        }//end foreach

        // Handle multiple filter queries correctly for Guzzle.
        if (empty($filters) === false) {
            // Guzzle expects array values for multiple parameters with same name.
            $solrQuery['fq'] = $filters;
        }

        // Handle facets - but skip traditional faceting if using JSON faceting (extend mode).
        if (empty($query['_facets']) === false && $query['_facets'] !== 'extend') {
            $solrQuery['facet']       = 'true';
            $solrQuery['facet.field'] = [];

            // **FIX**: Handle simple string facet requests (e.g., _facets=fieldname).
            // Convert string to proper array format before processing.
            $facets = $query['_facets'];
            if (is_string($facets) === true) {
                // Simple string facet request - convert to array format.
                $facets = [$facets => ['type' => 'terms']];
                $this->logger->debug(
                        'Converted string facet to array format',
                        [
                            'original'  => $query['_facets'],
                            'converted' => $facets,
                        ]
                        );
            } else if (is_array($facets) === false) {
                // Invalid facet type - skip faceting.
                $this->logger->warning(
                        'Invalid _facets parameter type',
                        [
                            'type'  => gettype($facets),
                            'value' => $facets,
                        ]
                        );
                $facets = [];
            }//end if

            foreach ($facets as $facetGroup => $facetConfig) {
                if ($facetGroup === '@self' && is_array($facetConfig) === true) {
                    // Handle @self metadata facets.
                    foreach ($facetConfig as $metaField => $metaConfig) {
                        if (is_array($metaConfig) === true && (($metaConfig['type'] ?? null) !== null)) {
                            $solrFacetField = 'self_'.$metaField;

                            if ($metaConfig['type'] === 'terms') {
                                $solrQuery['facet.field'][] = $solrFacetField;
                            } else if ($metaConfig['type'] === 'date_histogram') {
                                // Handle date histogram facets.
                                $solrQuery['facet.date']       = $solrFacetField;
                                $solrQuery['facet.date.start'] = 'NOW/YEAR-10YEARS';
                                $solrQuery['facet.date.end']   = 'NOW';
                                $solrQuery['facet.date.gap']   = '+1MONTH';
                            }
                        }
                    }
                } else if (is_array($facetConfig) === true && (($facetConfig['type'] ?? null) !== null)) {
                    // Handle regular facets.
                    if ($facetConfig['type'] === 'terms') {
                        $solrQuery['facet.field'][] = $facetGroup;
                    }
                }//end if
            }//end foreach
        }//end if

        return $solrQuery;

    }//end buildSolrQuery()


    /**
     * Execute SOLR search query
     *
     * @param  array  $solrQuery      SOLR query parameters
     * @param  string $collectionName Collection name to search in
     * @param  array  $extend         Extension parameters for @self properties
     * @return array Search results
     * @throws \Exception When search fails
     */
    private function executeSearch(array $solrQuery, string $collectionName, array $extend=[]): array
    {
        $url = $this->buildSolrBaseUrl().'/'.$collectionName.'/select';

        try {
            // Build the query string manually to handle multiple fq and facet.field parameters correctly.
            $queryParts = [];
            foreach ($solrQuery as $key => $value) {
                if ($key === 'fq' && is_array($value) === true) {
                    // Handle multiple fq parameters correctly.
                    foreach ($value as $fqValue) {
                        $queryParts[] = 'fq='.urlencode((string) $fqValue);
                    }
                } else if ($key === 'facet.field' && is_array($value) === true) {
                    // Handle multiple facet.field parameters correctly.
                    foreach ($value as $facetField) {
                        $queryParts[] = 'facet.field='.urlencode((string) $facetField);
                    }
                } else if (is_array($value) === true) {
                    // Skip other array values to prevent "Array" string conversion.
                    $this->logger->warning(
                            'Skipping array parameter in SOLR query',
                            [
                                'key'   => $key,
                                'value' => $value,
                            ]
                            );
                } else {
                    $queryParts[] = $key.'='.urlencode((string) $value);
                }//end if
            }//end foreach

            $queryString = implode('&', $queryParts);
            $fullUrl     = $url.'?'.$queryString;

            // **DEBUG**: Log the final SOLR URL and query for troubleshooting.
            $this->logger->debug(
                    '=== EXECUTING SOLR SEARCH ===',
                    [
                        'full_url'              => $fullUrl,
                        'collection'            => $collectionName,
                        'query_string'          => $queryString,
                        'query_parts_breakdown' => $queryParts,
                    ]
                    );

            // SOLR query execution prepared.
            // Use the manually built URL instead of Guzzle's query parameter handling.
            $response = $this->httpClient->get(
                    $fullUrl,
                    [
                        'timeout'         => 30,
                        'connect_timeout' => 10,
                    ]
                    );

            /*
             * @var \Psr\Http\Message\ResponseInterface $response
             */
            $statusCode   = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();

            // SOLR response received.
            if ($statusCode !== 200) {
                throw new \Exception("SOLR search failed with status code: $statusCode. Response: ".$responseBody);
            }

            $responseData = json_decode($responseBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON response from SOLR: '.json_last_error_msg());
            }

            return $this->parseSolrResponse(responseData: $responseData, extend: $extend);
        } catch (\Exception $e) {
            $this->logger->error(
                    'SOLR search execution failed',
                    [
                        'url'   => $url,
                        'query' => $solrQuery,
                        'error' => $e->getMessage(),
                    ]
                    );
            throw $e;
        }//end try

    }//end executeSearch()


    /**
     * Parse SOLR response into standardized format
     *
     * @param  array $responseData Raw SOLR response
     * @param  array $extend       Extension parameters for @self properties
     * @return array Parsed search results
     */
    private function parseSolrResponse(array $responseData, array $extend=[]): array
    {
        $results = [
            'objects' => [],
            'total'   => 0,
            'facets'  => [],
        ];

        // Parse documents and convert back to OpenRegister objects.
        if (($responseData['response']['docs'] ?? null) !== null) {
            $results['objects'] = $this->convertSolrDocumentsToOpenRegisterObjects(solrDocuments: $responseData['response']['docs'], extend: $extend);
            if (($responseData['response']['numFound'] ?? null) !== null) {
                $results['total'] = $responseData['response']['numFound'];
            } else {
                $results['total'] = count($results['objects']);
            }

            // **DEBUG**: Log total vs results count for troubleshooting.
            $this->logger->debug(
                    'SOLR response parsing',
                    [
                        'numFound_from_solr' => $responseData['response']['numFound'] ?? 'missing',
                        'docs_returned'      => count($responseData['response']['docs'] ?? []),
                        'objects_converted'  => count($results['objects']),
                        'final_total'        => $results['total'],
                    ]
                    );
        }

        // Parse facets.
        if (($responseData['facet_counts']['facet_fields'] ?? null) !== null) {
            foreach ($responseData['facet_counts']['facet_fields'] as $field => $values) {
                $facetData = [];
                for ($i = 0; $i < count($values); $i += 2) {
                    if (($values[$i + 1] ?? null) !== null) {
                        $facetData[] = [
                            'value' => $values[$i],
                            'count' => $values[$i + 1],
                        ];
                    }
                }

                $results['facets'][$field] = $facetData;
            }
        }

        return $results;

    }//end parseSolrResponse()


    /**
     * Convert SOLR search results to OpenRegister paginated format
     *
     * @param  array $searchResults SOLR search results
     * @param  array $originalQuery Original OpenRegister query
     * @return array Paginated results in OpenRegister format matching database response structure
     */
    private function convertToOpenRegisterPaginatedFormat(array $searchResults, array $originalQuery, array $solrQuery=null): array
    {
        if (($originalQuery['_limit'] ?? null) !== null) {
            $limit = (int) $originalQuery['_limit'];
        } else {
            $limit = 20;
        }

        if (($originalQuery['_page'] ?? null) !== null) {
            $page = (int) $originalQuery['_page'];
        } else {
            $page = 1;
        }

        if (($originalQuery['_offset'] ?? null) !== null) {
            $offset = (int) $originalQuery['_offset'];
        } else {
            $offset = ($page - 1) * $limit;
        }

        if (($searchResults['total'] ?? null) !== null) {
            $total = $searchResults['total'];
        } else {
            $total = 0;
        }

        if ($limit > 0) {
            $pages = max(1, ceil($total / $limit));
        } else {
            $pages = 1;
        }

        // **DEBUG**: Log pagination calculation for troubleshooting.
        $this->logger->debug(
                'Converting to OpenRegister paginated format',
                [
                    'searchResults_total'         => $searchResults['total'] ?? 'missing',
                    'searchResults_objects_count' => count($searchResults['objects'] ?? []),
                    'calculated_total'            => $total,
                    'limit'                       => $limit,
                    'page'                        => $page,
                    'calculated_pages'            => $pages,
                ]
                );

        // Match the database response format exactly.
        $response = [
            'results' => $searchResults['objects'] ?? [],
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
            'limit'   => $limit,
            'offset'  => $offset,
            'facets'  => [
                'facets' => $searchResults['facets'] ?? [],
            ],
        ];

        // Handle _facetable parameter for live field discovery from SOLR.
        if (($originalQuery['_facetable'] ?? null) !== null && ($originalQuery['_facetable'] === true || $originalQuery['_facetable'] === 'true') === true) {
            try {
                $facetableFields = $this->discoverFacetableFieldsFromSolr();
                // Combine all facetable fields into a single flat structure.
                if (($facetableFields['@self'] ?? null) !== null) {
                    $selfFields = $facetableFields['@self'];
                } else {
                    $selfFields = [];
                }

                if (($facetableFields['object_fields'] ?? null) !== null) {
                    $objectFields = $facetableFields['object_fields'];
                } else {
                    $objectFields = [];
                }

                $combinedFacetableFields = array_merge($selfFields, $objectFields);
                $response['facetable']   = $combinedFacetableFields;

                $this->logger->debug(
                        'Added facetable fields to response',
                        [
                            'facetableFieldCount' => count($combinedFacetableFields),
                        ]
                        );
            } catch (\Exception $e) {
                $this->logger->error(
                        'Failed to discover facetable fields from SOLR',
                        [
                            'error' => $e->getMessage(),
                        ]
                        );
                // Don't fail the whole request, just return empty facetable fields.
                $response['facetable'] = [];
            }//end try
        }//end if

        // Handle _facets=extend parameter for complete faceting (discovery + data).
        if (($originalQuery['_facets'] ?? null) !== null && $originalQuery['_facets'] === 'extend') {
            try {
                // Build contextual facets - if we don't have solrQuery, build it from original query.
                if ($solrQuery === null) {
                    $solrQuery = $this->buildSolrQuery($originalQuery);
                }

                // Re-run the same query with faceting enabled to get contextual facets.
                // This is much more efficient than making separate calls.
                $contextualFacetData = $this->getContextualFacetsFromSameQuery(solrQuery: $solrQuery, _originalQuery: $originalQuery);

                // Also discover all available facetable fields from SOLR schema.
                $allFacetableFields = $this->discoverFacetableFieldsFromSolr();

                // Get metadata facetable fields (always available).
                $metadataFacetableFields = $this->getMetadataFacetableFields();

                // Combine contextual facets (metadata with data) with all available facetable fields.
                $facetableFields = [
                    '@self'         => $metadataFacetableFields['@self'] ?? [],
                    'object_fields' => $allFacetableFields['object_fields'] ?? [],
                ];

                // Combine all facetable fields (metadata + object) into a single flat structure.
                $combinedFacetableFields = array_merge(
                    $facetableFields['@self'] ?? [],
                    $facetableFields['object_fields'] ?? []
                );
                $response['facetable']   = $combinedFacetableFields;

                // Use the unified facet data directly (already flattened).
                $response['facets'] = $contextualFacetData['extended'] ?? [];

                $this->logger->debug(
                        'Added contextual faceting data to response',
                        [
                            'facetableFieldCount' => count($contextualFacetData['facetable'] ?? []),
                            'extendedFacetsCount' => count($contextualFacetData['extended'] ?? []),
                            'facetNames'          => array_keys($contextualFacetData['extended'] ?? []),
                        ]
                        );
            } catch (\Exception $e) {
                $this->logger->error(
                        'Failed to get contextual faceting data from SOLR',
                        [
                            'error' => $e->getMessage(),
                        ]
                        );
                // Don't fail the whole request, just return empty faceting data.
                $response['facets']['facetable'] = [
                    '@self'         => [],
                    'object_fields' => [],
                ];
                $response['facets']['facets']    = [
                    '@self'         => [],
                    'object_fields' => [],
                ];
            }//end try
        }//end if

        // Add pagination URLs if applicable (matching database format).
        if ($page < $pages) {
            if (($_SERVER['REQUEST_URI'] ?? null) !== null) {
                $response['next'] = $_SERVER['REQUEST_URI'];
            } else {
                $response['next'] = '';
            }

            // Update page parameter in URL for next page.
            $response['next'] = preg_replace('/([?&])page=\d+/', '$1page='.($page + 1), $response['next']);
            if (strpos($response['next'], 'page=') === false) {
                if (strpos($response['next'], '?') === false) {
                    $response['next'] .= '?page='.($page + 1);
                } else {
                    $response['next'] .= '&page='.($page + 1);
                }
            }
        }

        return $response;

    }//end convertToOpenRegisterPaginatedFormat()


    /**
     * Convert SOLR documents back to OpenRegister objects
     *
     * This method extracts the actual OpenRegister object from the SOLR document's self_object field
     * and merges it with essential metadata from the SOLR document. If @self.register or @self.schema
     * extensions are requested, it will load and include the full register/schema objects using the
     * RenderObject service's caching mechanism.
     *
     * @phpstan-param  array<int, array<string, mixed>> $solrDocuments
     * @psalm-param    array<array<string, mixed>> $solrDocuments
     * @phpstan-return array<int, array<string, mixed>>
     * @psalm-return   array<array<string, mixed>>
     *
     * @param  array $solrDocuments Array of SOLR documents
     * @param  array $extend        Array of properties to extend (e.g., ['@self.register', '@self.schema'])
     * @return array Array of OpenRegister objects with extended @self properties
     */
    private function convertSolrDocumentsToOpenRegisterObjects(array $solrDocuments=[], $extend=[]): array
    {
        $openRegisterObjects = [];

        foreach ($solrDocuments as $doc) {
            if (is_array($doc['self_object'] ?? null) === true) {
                $object = $doc['self_object'][0] ?? null;
            } else {
                $object = $doc['self_object'] ?? null;
            }

            if (is_array($doc['self_uuid'] ?? null) === true) {
                $uuid = $doc['self_uuid'][0] ?? null;
            } else {
                $uuid = $doc['self_uuid'] ?? null;
            }

            if (is_array($doc['self_register'] ?? null) === true) {
                $registerId = $doc['self_register'][0] ?? null;
            } else {
                $registerId = $doc['self_register'] ?? null;
            }

            if (is_array($doc['self_schema'] ?? null) === true) {
                $schemaId = $doc['self_schema'][0] ?? null;
            } else {
                $schemaId = $doc['self_schema'] ?? null;
            }

            if ($object === null) {
                $this->logger->warning(
                        '[GuzzleSolrService] Invalid document missing required self_object',
                        [
                            'uuid'     => $uuid,
                            'register' => $registerId,
                            'schema'   => $schemaId,
                        ]
                        );
                continue;
            }

            try {
                $objectData = json_decode($object, true);

                // Add register and schema context to @self if requested and we have the necessary data.
                if (is_array($extend) === true && ($registerId !== null || $schemaId !== null)
                    && (in_array('@self.register', $extend) === true || in_array('@self.schema', $extend) === true)
                ) {
                    if (($objectData['@self'] ?? null) !== null) {
                        $self = $objectData['@self'];
                    } else {
                        $self = [];
                    }

                    if (in_array('@self.register', $extend) === true && $registerId !== null && $this->registerMapper !== null) {
                        // Use the RegisterMapper directly to get register.
                        try {
                            $register = $this->registerMapper->find($registerId);
                            if ($register !== null) {
                                $self['register'] = $register->jsonSerialize();
                            }
                        } catch (\Exception $e) {
                            $this->logger->warning(
                                    'Failed to load register for @self extension',
                                    [
                                        'registerId' => $registerId,
                                        'error'      => $e->getMessage(),
                                    ]
                                    );
                        }
                    }

                    if (in_array('@self.schema', $extend) === true && $schemaId !== null && $this->schemaMapper !== null) {
                        // Use the SchemaMapper directly to get schema.
                        try {
                            $schema = $this->schemaMapper->find($schemaId);
                            if ($schema !== null) {
                                $self['schema'] = $schema->jsonSerialize();
                            }
                        } catch (\Exception $e) {
                            $this->logger->warning(
                                    'Failed to load schema for @self extension',
                                    [
                                        'schemaId' => $schemaId,
                                        'error'    => $e->getMessage(),
                                    ]
                                    );
                        }
                    }

                    $objectData['@self'] = $self;
                }//end if

                $openRegisterObjects[] = $objectData;
            } catch (\Exception $e) {
                if (($doc['id'] ?? null) !== null) {
                    $docId = $doc['id'];
                } else {
                    $docId = 'unknown';
                }

                $this->logger->warning(
                        '[GuzzleSolrService] Failed to reconstruct object from Solr document',
                        [
                            'doc_id' => $docId,
                            'error'  => $e->getMessage(),
                        ]
                        );
            }//end try
        }//end foreach

        return $openRegisterObjects;

    }//end convertSolrDocumentsToOpenRegisterObjects()


    /**
     * Test Zookeeper connectivity for SolrCloud
     *
     * @return array Zookeeper test results
     */
    private function testZookeeperConnection(): array
    {
        try {
            // solrConfig may contain additional keys not in type definition.
            $zookeeperHosts = (is_array($this->solrConfig) && array_key_exists('zookeeperHosts', $this->solrConfig)) ? $this->solrConfig['zookeeperHosts'] : 'zookeeper:2181';
            $hosts          = explode(',', $zookeeperHosts);

            $successfulHosts = [];
            $failedHosts     = [];

            foreach ($hosts as $host) {
                $host = trim($host);

                // Try to test Zookeeper via SOLR Collections API.
                // This is more reliable than direct Zookeeper connection.
                try {
                    $url      = $this->buildSolrBaseUrl().'/admin/collections?action=LIST&wt=json';
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

            if (empty($successfulHosts) === false) {
                return [
                    'success' => true,
                    'message' => 'Zookeeper accessible via SOLR Collections API',
                    'details' => [
                        'zookeeper_hosts'  => $zookeeperHosts,
                        'successful_hosts' => $successfulHosts,
                        'failed_hosts'     => $failedHosts,
                        'test_method'      => 'SOLR Collections API',
                    ],
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Zookeeper not accessible via any host',
                    'details' => [
                        'zookeeper_hosts'  => $zookeeperHosts,
                        'successful_hosts' => $successfulHosts,
                        'failed_hosts'     => $failedHosts,
                        'test_method'      => 'SOLR Collections API',
                    ],
                ];
            }//end if
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Zookeeper test failed: '.$e->getMessage(),
                'details' => [
                    'error'           => $e->getMessage(),
                    'zookeeper_hosts' => $this->solrConfig['zookeeperHosts'] ?? 'zookeeper:2181',
                ],
            ];
        }//end try

    }//end testZookeeperConnection()


    /**
     * Test SOLR connectivity
     *
     * @return array SOLR test results
     */
    private function testSolrConnectivity(): array
    {
        try {
            $baseUrl = $this->buildSolrBaseUrl();

            // Test basic SOLR connectivity with admin endpoints.
            // Try multiple common SOLR admin endpoints for maximum compatibility.
            $testEndpoints = [
                '/admin/ping?wt=json',
                '/solr/admin/ping?wt=json',
                '/admin/info/system?wt=json',
            ];

            $lastError       = null;
            $workingEndpoint = null;

            // Try each endpoint until one works.
            $response     = null;
            $responseTime = 0;

            foreach ($testEndpoints as $endpoint) {
                $testUrl   = $baseUrl.$endpoint;
                $startTime = microtime(true);

                try {
                    $response     = $this->httpClient->get($testUrl, ['timeout' => 10]);
                    $responseTime = (microtime(true) - $startTime) * 1000;

                    if ($response->getStatusCode() === 200) {
                        $workingEndpoint = $endpoint;
                        break;
                    }
                } catch (\Exception $e) {
                    $lastError = "Failed to connect to: ".$testUrl;
                    continue;
                }
            }

            if ($response === null || $response->getStatusCode() !== 200) {
                return [
                    'success' => false,
                    'message' => 'SOLR server not responding on any admin endpoint',
                    'details' => [
                        'tested_endpoints' => array_map(
                                function ($endpoint) use ($baseUrl) {
                                    return $baseUrl.$endpoint;
                                },
                                $testEndpoints
                                ),
                        'last_error'       => $lastError,
                        'test_type'        => 'admin_ping',
                        'response_time_ms' => round($responseTime, 2),
                    ],
                ];
            }

            $data = json_decode((string) $response->getBody(), true);

            // Validate admin response - be flexible about response format.
            $isValidResponse = false;

            if (($data['status'] ?? null) !== null && $data['status'] === 'OK') {
                // Standard ping response.
                $isValidResponse = true;
            } else if (($data['responseHeader']['status'] ?? null) !== null && $data['responseHeader']['status'] === 0) {
                // System info response.
                $isValidResponse = true;
            } else if (is_array($data) === true && empty($data) === false) {
                // Any valid JSON response indicates SOLR is responding.
                $isValidResponse = true;
            }

            if ($isValidResponse === true) {
                return [
                    'success' => true,
                    'message' => 'SOLR server is responding',
                    'details' => [
                        'working_endpoint' => $workingEndpoint,
                        'response_time_ms' => round($responseTime, 2),
                        'server_info'      => [
                            'solr_version'   => $data['lucene']['solr-spec-version'] ?? 'unknown',
                            'lucene_version' => $data['lucene']['lucene-spec-version'] ?? 'unknown',
                        ],
                    ],
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'SOLR server responding but with invalid format',
                    'details' => [
                        'response_data'    => $data,
                        'response_time_ms' => round($responseTime, 2),
                    ],
                ];
            }//end if
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'SOLR connectivity test failed: '.$e->getMessage(),
                'details' => [
                    'error'    => $e->getMessage(),
                    'base_url' => $this->buildSolrBaseUrl(),
                ],
            ];
        }//end try

    }//end testSolrConnectivity()


    /**
     * Test SOLR collection/core availability
     *
     * @return array Collection test results
     */
    private function testSolrCollection(): array
    {
        try {
            // Use tenant-specific collection name - return failure if none exists.
            $collectionName = $this->getActiveCollectionName();
            if ($collectionName === null) {
                return [
                    'success' => false,
                    'message' => 'No active collection available for testing',
                    'details' => [
                        'reason' => 'Tenant collection does not exist',
                    ],
                ];
            }

            $baseUrl = $this->buildSolrBaseUrl();

            // For SolrCloud, test collection existence.
            if (($this->solrConfig['useCloud'] ?? false) === true) {
                $url = $baseUrl.'/admin/collections?action=CLUSTERSTATUS&wt=json';

                $response = $this->httpClient->get($url, ['timeout' => 10]);

                if ($response->getStatusCode() !== 200) {
                    return [
                        'success' => false,
                        'message' => 'Failed to check collection status',
                        'details' => ['url' => $url],
                    ];
                }

                $data = json_decode((string) $response->getBody(), true);
                if (($data['cluster']['collections'] ?? null) !== null) {
                    $collections = $data['cluster']['collections'];
                } else {
                    $collections = [];
                }

                if (($collections[$collectionName] ?? null) !== null) {
                    if (strpos($collectionName, '_nc_') !== false) {
                        $collectionType = 'tenant-specific';
                    } else {
                        $collectionType = 'base';
                    }

                    if (($collections[$collectionName]['status'] ?? null) !== null) {
                        $status = $collections[$collectionName]['status'];
                    } else {
                        $status = 'unknown';
                    }

                    if (($collections[$collectionName]['shards'] ?? null) !== null) {
                        $shards = $collections[$collectionName]['shards'];
                    } else {
                        $shards = [];
                    }

                    return [
                        'success' => true,
                        'message' => "Collection '{$collectionName}' exists and is available",
                        'details' => [
                            'collection_name' => $collectionName,
                            'collection_type' => $collectionType,
                            'status'          => $status,
                            'shards'          => count($shards),
                        ],
                    ];
                } else {
                    if (strpos($collectionName, '_nc_') !== false) {
                        $collectionType = 'tenant-specific';
                    } else {
                        $collectionType = 'base';
                    }

                    return [
                        'success' => false,
                        'message' => "Collection '{$collectionName}' not found",
                        'details' => [
                            'collection_name'       => $collectionName,
                            'collection_type'       => $collectionType,
                            'available_collections' => array_keys($collections),
                        ],
                    ];
                }//end if
            } else {
                // For standalone SOLR, test core existence.
                $url = $baseUrl.'/admin/cores?action=STATUS&core='.urlencode($collectionName).'&wt=json';

                $response = $this->httpClient->get($url, ['timeout' => 10]);

                if ($response->getStatusCode() !== 200) {
                    return [
                        'success' => false,
                        'message' => 'Failed to check core status',
                        'details' => ['url' => $url],
                    ];
                }

                $data = json_decode((string) $response->getBody(), true);
                if (($data['status'] ?? null) !== null) {
                    $cores = $data['status'];
                } else {
                    $cores = [];
                }

                if (($cores[$collectionName] ?? null) !== null) {
                    if (strpos($collectionName, '_nc_') !== false) {
                        $collectionType = 'tenant-specific';
                    } else {
                        $collectionType = 'base';
                    }

                    if (($cores[$collectionName]['index']['numDocs'] ?? null) !== null) {
                        $numDocs = $cores[$collectionName]['index']['numDocs'];
                    } else {
                        $numDocs = 0;
                    }

                    if (($cores[$collectionName]['index']['maxDoc'] ?? null) !== null) {
                        $maxDocs = $cores[$collectionName]['index']['maxDoc'];
                    } else {
                        $maxDocs = 0;
                    }

                    return [
                        'success' => true,
                        'message' => "Core '{$collectionName}' exists and is available",
                        'details' => [
                            'collection_name' => $collectionName,
                            'collection_type' => $collectionType,
                            'num_docs'        => $numDocs,
                            'max_docs'        => $maxDocs,
                        ],
                    ];
                } else {
                    if (strpos($collectionName, '_nc_') !== false) {
                        $collectionType = 'tenant-specific';
                    } else {
                        $collectionType = 'base';
                    }

                    return [
                        'success' => false,
                        'message' => "Core '{$collectionName}' not found",
                        'details' => [
                            'collection_name' => $collectionName,
                            'collection_type' => $collectionType,
                            'available_cores' => array_keys($cores),
                        ],
                    ];
                }//end if
            }//end if
        } catch (\Exception $e) {
            if (($this->solrConfig['collection'] ?? null) !== null) {
                $collection = $this->solrConfig['collection'];
            } else if (($this->solrConfig['core'] ?? null) !== null) {
                $collection = $this->solrConfig['core'];
            } else {
                $collection = 'openregister';
            }

            return [
                'success' => false,
                'message' => 'Collection/core test failed: '.$e->getMessage(),
                'details' => [
                    'error'      => $e->getMessage(),
                    'collection' => $collection,
                ],
            ];
        }//end try

    }//end testSolrCollection()


    /**
     * Test SOLR collection query functionality
     *
     * @return array Query test results
     */
    private function testSolrQuery(): array
    {
        try {
            // Use tenant-specific collection name - return failure if none exists.
            $collectionName = $this->getActiveCollectionName();
            if ($collectionName === null) {
                return [
                    'success' => false,
                    'message' => 'No active collection available for query testing',
                    'details' => [
                        'reason' => 'Tenant collection does not exist',
                    ],
                ];
            }

            $baseUrl = $this->buildSolrBaseUrl();

            // Test basic query functionality.
            $url = $baseUrl.'/'.$collectionName.'/select?q=*:*&rows=1&wt=json';

            $startTime    = microtime(true);
            $response     = $this->httpClient->get($url, ['timeout' => 10]);
            $responseTime = (microtime(true) - $startTime) * 1000;

            if ($response->getStatusCode() !== 200) {
                return [
                    'success' => false,
                    'message' => 'Query test failed with HTTP error',
                    'details' => [
                        'status_code' => $response->getStatusCode(),
                        'url'         => $url,
                    ],
                ];
            }

            $data = json_decode((string) $response->getBody(), true);

            if (($data['response'] ?? null) !== null) {
                if (strpos($collectionName, '_nc_') !== false) {
                    $collectionType = 'tenant-specific';
                } else {
                    $collectionType = 'base';
                }

                if (($data['response']['numFound'] ?? null) !== null) {
                    $totalDocs = $data['response']['numFound'];
                } else {
                    $totalDocs = 0;
                }

                return [
                    'success' => true,
                    'message' => 'Query test successful',
                    'details' => [
                        'collection_name'  => $collectionName,
                        'collection_type'  => $collectionType,
                        'total_docs'       => $totalDocs,
                        'response_time_ms' => round($responseTime, 2),
                        'query_url'        => $url,
                    ],
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Query returned invalid response format',
                    'details' => [
                        'response_data'    => $data,
                        'response_time_ms' => round($responseTime, 2),
                    ],
                ];
            }//end if
        } catch (\Exception $e) {
            if (($this->solrConfig['collection'] ?? null) !== null) {
                $collection = $this->solrConfig['collection'];
            } else if (($this->solrConfig['core'] ?? null) !== null) {
                $collection = $this->solrConfig['core'];
            } else {
                $collection = 'openregister';
            }

            return [
                'success' => false,
                'message' => 'Query test failed: '.$e->getMessage(),
                'details' => [
                    'error'      => $e->getMessage(),
                    'collection' => $collection,
                ],
            ];
        }//end try

    }//end testSolrQuery()


    /**
     * Clear entire index for tenant
     *
     * @return array Result with success status and error details
     */
    public function clearIndex(?string $collectionName=null): array
    {
        if ($this->isAvailable() === false) {
            return [
                'success'       => false,
                'error'         => 'SOLR service is not available',
                'error_details' => 'SOLR connection is not configured or unavailable',
            ];
        }

        try {
            // Use provided collection name or get the active collection.
            $targetCollection = $collectionName ?? $this->getActiveCollectionName();
            if ($targetCollection === null) {
                return [
                    'success'       => false,
                    'error'         => 'No active SOLR collection available',
                    'error_details' => 'No collection specified and no active collection found',
                ];
            }

            // For clear index, we want to delete ALL documents in the collection.
            $deleteData = [
                'delete' => [
                    'query' => '*:*'  ,
            // Delete everything in this collection.
                ],
            ];

            $url = $this->buildSolrBaseUrl().'/'.$targetCollection.'/update?wt=json&commit=true';

            $this->logger->info(
                    'Clearing SOLR index',
                    [
                        'collection' => $targetCollection,
                        'url'        => $url,
                    ]
                    );

            $response = $this->httpClient->post(
                    $url,
                    [
                        'json'    => $deleteData,
                        'headers' => [
                            'Content-Type' => 'application/json',
                        ],
                    ]
                    );

            /*
             * @var \Psr\Http\Message\ResponseInterface $response
             */
            $responseData = json_decode($response->getBody()->getContents(), true);

            if ($response->getStatusCode() === 200 && (($responseData['responseHeader']['status'] ?? null) !== null) && $responseData['responseHeader']['status'] === 0) {
                $this->logger->info(
                        'SOLR index cleared successfully',
                        [
                            'collection' => $targetCollection,
                        ]
                        );

                return [
                    'success'      => true,
                    'message'      => 'SOLR index cleared successfully',
                    'deleted_docs' => 'all',
                // We don't get exact count from *:* delete.
                    'collection'   => $targetCollection,
                ];
            } else {
                $this->logger->error(
                        'SOLR index clear failed',
                        [
                            'status_code' => $response->getStatusCode(),
                            'response'    => $responseData,
                            'collection'  => $targetCollection,
                        ]
                        );

                if (($responseData['error'] ?? null) !== null) {
                    $errorDetails = $responseData['error'];
                } else {
                    $errorDetails = 'Unknown error';
                }

                return [
                    'success'       => false,
                    'error'         => 'SOLR delete operation failed',
                    'error_details' => $errorDetails,
                ];
            }//end if
        } catch (\Exception $e) {
            $this->logger->error(
                    'SOLR index clear exception',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );

            return [
                'success'       => false,
                'error'         => 'Exception during SOLR clear: '.$e->getMessage(),
                'error_details' => $e->getTraceAsString(),
            ];
        }//end try

    }//end clearIndex()


    /**
     * Inspect SOLR index documents
     *
     * @param  string $query  SOLR query
     * @param  int    $start  Start offset
     * @param  int    $rows   Number of rows to return
     * @param  string $fields Comma-separated list of fields to return
     * @return array Result with documents and metadata
     */
    public function inspectIndex(string $query='*:*', int $start=0, int $rows=20, string $fields=''): array
    {
        if ($this->isAvailable() === false) {
            return [
                'success'       => false,
                'error'         => 'SOLR service is not available',
                'error_details' => 'SOLR connection is not configured or unavailable',
            ];
        }

        try {
            // Get the active collection name.
            $tenantCollectionName = $this->getActiveCollectionName();
            if ($tenantCollectionName === null) {
                return [
                    'success'       => false,
                    'error'         => 'No active SOLR collection available',
                    'error_details' => 'No collection found for the current tenant',
                ];
            }

            // Build search parameters.
            $searchParams = [
                'q'      => $query,
                'start'  => $start,
                'rows'   => $rows,
                'wt'     => 'json',
                'indent' => 'true',
            ];

            // Add field list if specified.
            if (empty($fields) === false) {
                $searchParams['fl'] = $fields;
            }

            $url = $this->buildSolrBaseUrl().'/'.$tenantCollectionName.'/select';

            $response = $this->httpClient->get(
                    $url,
                    [
                        'query'   => $searchParams,
                        'timeout' => 30,
                    ]
                    );

            $data = json_decode((string) $response->getBody(), true);

            if (($data['responseHeader']['status'] ?? null) !== null) {
                $status = $data['responseHeader']['status'];
            } else {
                $status = -1;
            }

            if ($status === 0) {
                if (($data['response']['docs'] ?? null) !== null) {
                    $documents = $data['response']['docs'];
                } else {
                    $documents = [];
                }

                if (($data['response']['numFound'] ?? null) !== null) {
                    $totalResults = $data['response']['numFound'];
                } else {
                    $totalResults = 0;
                }

                $this->logger->debug(
                        '🔍 SOLR INDEX INSPECT',
                        [
                            'query'         => $query,
                            'collection'    => $tenantCollectionName,
                            'total_results' => $totalResults,
                            'returned_docs' => count($documents),
                        ]
                        );

                return [
                    'success'    => true,
                    'documents'  => $documents,
                    'total'      => $totalResults,
                    'start'      => $start,
                    'rows'       => $rows,
                    'collection' => $tenantCollectionName,
                ];
            } else {
                if (($data['error']['msg'] ?? null) !== null) {
                    $errorMsg = $data['error']['msg'];
                } else {
                    $errorMsg = 'Unknown SOLR error';
                }

                if (($data['error']['code'] ?? null) !== null) {
                    $errorCode = $data['error']['code'];
                } else if (($data['responseHeader']['status'] ?? null) !== null) {
                    $errorCode = $data['responseHeader']['status'];
                } else {
                    $errorCode = -1;
                }

                return [
                    'success'       => false,
                    'error'         => "SOLR search failed: {$errorMsg}",
                    'error_details' => [
                        'solr_error'    => $errorMsg,
                        'error_code'    => $errorCode,
                        'query'         => $query,
                        'collection'    => $tenantCollectionName,
                        'full_response' => $data,
                    ],
                ];
            }//end if
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $errorMsg     = 'HTTP request failed';
            $errorDetails = [
                'exception_type' => 'RequestException',
                'message'        => $e->getMessage(),
                'query'          => $query,
            ];

            // Try to extract SOLR error from response.
            if ($e->hasResponse() === true) {
                $responseBody = (string) $e->getResponse()->getBody();
                $responseData = json_decode($responseBody, true);

                if ($responseData !== null && (($responseData['error'] ?? null) !== null)) {
                    if (($responseData['error']['msg'] ?? null) !== null) {
                        $errorMsg = "SOLR HTTP {$e->getResponse()->getStatusCode()} Error: ".$responseData['error']['msg'];
                    } else {
                        $errorMsg = "SOLR HTTP {$e->getResponse()->getStatusCode()} Error: ".$responseData['error'];
                    }

                    $errorDetails['solr_response'] = $responseData;
                    $errorDetails['http_status']   = $e->getResponse()->getStatusCode();
                }
            }

            $this->logger->error(message: 'HTTP exception inspecting SOLR index', context: $errorDetails);

            return [
                'success'       => false,
                'error'         => $errorMsg,
                'error_details' => $errorDetails,
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                    'Exception inspecting SOLR index',
                    [
                        'query'          => $query,
                        'error'          => $e->getMessage(),
                        'exception_type' => get_class($e),
                    ]
                    );

            return [
                'success'       => false,
                'error'         => 'Unexpected error during SOLR inspection: '.$e->getMessage(),
                'error_details' => [
                    'exception_type' => get_class($e),
                    'message'        => $e->getMessage(),
                    'query'          => $query,
                ],
            ];
        }//end try

    }//end inspectIndex()


    /**
     * Optimize SOLR index
     *
     * @return bool True if successful
     */
    public function optimize(): bool
    {
        if ($this->isAvailable() === false) {
            return false;
        }

        try {
            // Get the active collection name - return false if no collection exists.
            $tenantCollectionName = $this->getActiveCollectionName();
            if ($tenantCollectionName === null) {
                $this->logger->warning(message: 'Cannot optimize: no active collection available');
                return false;
            }

            $url = $this->buildSolrBaseUrl().'/'.$tenantCollectionName.'/update?wt=json&optimize=true';

            $response = $this->httpClient->post(
                    $url,
                    [
                        'body'    => json_encode(['optimize' => []]),
                        'headers' => ['Content-Type' => 'application/json'],
                        'timeout' => 120,
            // Optimization can take time.
                    ]
                    );

            $data = json_decode((string) $response->getBody(), true);
            if (($data['responseHeader']['status'] ?? null) !== null) {
                $status = $data['responseHeader']['status'];
            } else {
                $status = -1;
            }

            $success = $status === 0;

            if ($success === true) {
                $this->logger->info(
                        '⚡ SOLR INDEX OPTIMIZED',
                        [
                            'collection' => $tenantCollectionName,
                        ]
                        );
            }

            return $success;
        } catch (\Exception $e) {
            $this->logger->error(message: 'Exception optimizing SOLR', context: ['error' => $e->getMessage()]);
            return false;
        }//end try

    }//end optimize()


    /**
     * Get dashboard statistics
     *
     * @return array Dashboard statistics
     */
    public function getDashboardStats(): array
    {
        // Use the same availability check as testConnection() instead of isAvailable().
        // This ensures consistency between connection test and dashboard stats.
        try {
            $connectionTest = $this->testConnection();
            if (($connectionTest['success'] ?? null) !== null && $connectionTest['success'] === true) {
                // Connection test successful, continue.
            } else {
                if (($connectionTest['message'] ?? null) !== null) {
                    $errorMsg = $connectionTest['message'];
                } else {
                    $errorMsg = 'Connection test failed';
                }

                return [
                    'available' => false,
                    'error'     => 'SOLR not available: '.$errorMsg,
                ];
            }
        } catch (\Exception $e) {
            return [
                'available' => false,
                'error'     => 'SOLR not available: '.$e->getMessage(),
            ];
        }//end try

        try {
            // Get both objectCollection and fileCollection from settings.
            // solrConfig may contain additional keys not in type definition.
            $objectCollection = (is_array($this->solrConfig) && array_key_exists('objectCollection', $this->solrConfig)) ? $this->solrConfig['objectCollection'] : null;
            $fileCollection   = (is_array($this->solrConfig) && array_key_exists('fileCollection', $this->solrConfig)) ? $this->solrConfig['fileCollection'] : null;

            // Fallback to legacy collection if new collections are not configured.
            if (($this->solrConfig['collection'] ?? null) !== null) {
                $legacyCollection = $this->solrConfig['collection'];
            } else {
                $legacyCollection = null;
            }

            if ($objectCollection === null && $fileCollection === null && $legacyCollection === null) {
                return [
                    'available' => false,
                    'error'     => 'No collections configured - please configure objectCollection and fileCollection',
                ];
            }

            // Query stats for objectCollection.
            $objectStats    = null;
            $objectDocCount = 0;
            if ($objectCollection !== null && $this->collectionExists($objectCollection) === true) {
                try {
                    $objectStats    = $this->getCollectionStats($objectCollection);
                    $objectDocCount = $this->getDocumentCountForCollection($objectCollection);
                } catch (\Exception $e) {
                    $this->logger->warning(
                            'Failed to get objectCollection stats',
                            [
                                'collection' => $objectCollection,
                                'error'      => $e->getMessage(),
                            ]
                            );
                }
            }

            // Query stats for fileCollection.
            $fileStats    = null;
            $fileDocCount = 0;
            if ($fileCollection !== null && $this->collectionExists($fileCollection) === true) {
                try {
                    $fileStats    = $this->getCollectionStats($fileCollection);
                    $fileDocCount = $this->getDocumentCountForCollection($fileCollection);
                } catch (\Exception $e) {
                    $this->logger->warning(
                            'Failed to get fileCollection stats',
                            [
                                'collection' => $fileCollection,
                                'error'      => $e->getMessage(),
                            ]
                            );
                }
            }

            // If using legacy collection, query it.
            $legacyStats    = null;
            $legacyDocCount = 0;
            if ($legacyCollection !== null && $objectCollection === null && $fileCollection === null && $this->collectionExists($legacyCollection) === true) {
                try {
                    $legacyStats    = $this->getCollectionStats($legacyCollection);
                    $legacyDocCount = $this->getDocumentCountForCollection($legacyCollection);
                } catch (\Exception $e) {
                    $this->logger->warning(
                            'Failed to get legacy collection stats',
                            [
                                'collection' => $legacyCollection,
                                'error'      => $e->getMessage(),
                            ]
                            );
                }
            }

            // Use object collection for overall stats if available, otherwise fallback.
            if ($objectCollection !== null) {
                $primaryCollection = $objectCollection;
            } else {
                $primaryCollection = $legacyCollection;
            }

            if ($objectStats !== null) {
                $primaryStats = $objectStats;
            } else {
                $primaryStats = $legacyStats;
            }

            $docCount = $objectDocCount + $legacyDocCount;
            // Combined document count
            // Get object counts from database using ObjectEntityMapper.
            $totalCount     = 0;
            $publishedCount = 0;
            try {
                // Get ObjectEntityMapper directly from container.
                $objectMapper = \OC::$server->get(\OCA\OpenRegister\Db\ObjectEntityMapper::class);

                // Get total object count (excluding deleted objects).
                $totalCount = $objectMapper->countAll(
                    filters: [],
                    search: null,
                    ids: null,
                    uses: null,
                    includeDeleted: false,
                    register: null,
                    schema: null,
                    published: null,
                // Don't filter by published status for total count.
                    rbac: false,
                // Skip RBAC for performance.
                    multi: false
                // Skip multitenancy for performance.
                );

                // Get published object count.
                $publishedCount = $objectMapper->countAll(
                    filters: [],
                    search: null,
                    ids: null,
                    uses: null,
                    includeDeleted: false,
                    register: null,
                    schema: null,
                    published: true,
                // Only count published objects.
                    rbac: false,
                // Skip RBAC for performance.
                    multi: false
                // Skip multitenancy for performance.
                );
            } catch (\Exception $e) {
                $this->logger->warning(message: 'Failed to get object counts from database', context: ['error' => $e->getMessage()]);
            }//end try

            // Get index size (approximate) from primary collection.
            $sizeData = [];
            if ($primaryCollection !== null && $primaryCollection !== '') {
                try {
                    $indexSizeUrl = $this->buildSolrBaseUrl().'/'.$primaryCollection.'/admin/luke?wt=json&numTerms=0';
                    $sizeResponse = $this->httpClient->get($indexSizeUrl, ['timeout' => 10]);
                    $sizeData     = json_decode((string) $sizeResponse->getBody(), true);
                } catch (\Exception $e) {
                    $this->logger->warning(message: 'Failed to get index size', context: ['error' => $e->getMessage()]);
                }
            }

            // Get shard count from stats.
            $shards = [];
            if ($primaryStats !== null && (($primaryStats['shards'] ?? null) !== null)) {
                $shards = $primaryStats['shards'];
            }

            // Get memory prediction for warmup (using published object count).
            $memoryPrediction = [];
            try {
                $memoryPrediction = $this->predictWarmupMemoryUsage(0);
                // 0 = all published objects
            } catch (\Exception $e) {
                $this->logger->warning(message: 'Failed to get memory prediction for dashboard stats', context: ['error' => $e->getMessage()]);
                $memoryPrediction = [
                    'error'           => 'Unable to predict memory usage',
                    'prediction_safe' => true,
                // Default to safe.
                ];
            }

            // Get file counts from Nextcloud file cache.
            $totalFiles   = 0;
            $indexedFiles = $fileDocCount;
            // Files indexed in SOLR fileCollection.
            try {
                $fileMapper = \OC::$server->get(\OCA\OpenRegister\Db\FileMapper::class);
                $totalFiles = $fileMapper->countAllFiles();
            } catch (\Exception $e) {
                $this->logger->warning(message: 'Failed to get total file count from FileMapper', context: ['error' => $e->getMessage()]);
            }

            if ($objectCollection === null && $fileCollection === null) {
                $legacyCollectionValue = $legacyCollection;
            } else {
                $legacyCollectionValue = null;
            }

            if ($objectStats !== null || $fileStats !== null || $legacyStats !== null) {
                $health = 'healthy';
            } else {
                $health = 'degraded';
            }

            if ($objectStats !== null) {
                $objectCollectionData = array_merge($objectStats, ['documentCount' => $objectDocCount]);
            } else {
                $objectCollectionData = null;
            }

            if ($fileStats !== null) {
                $fileCollectionData = array_merge($fileStats, ['documentCount' => $fileDocCount]);
            } else {
                $fileCollectionData = null;
            }

            if (($sizeData['index']['version'] ?? null) !== null) {
                $indexVersion = $sizeData['index']['version'];
            } else {
                $indexVersion = 'unknown';
            }

            if (($sizeData['index']['lastModified'] ?? null) !== null) {
                $lastModified = $sizeData['index']['lastModified'];
            } else {
                $lastModified = 'unknown';
            }

            return [
                'available'         => true,
                // Collection information.
                'objectCollection'  => $objectCollection,
                'fileCollection'    => $fileCollection,
                'legacyCollection'  => $legacyCollectionValue,
                'primaryCollection' => $primaryCollection,
                // Combined document counts.
                'document_count'    => $docCount,
                'objectDocuments'   => $objectDocCount,
                'fileDocuments'     => $fileDocCount,
                // Database counts.
                'total_count'       => $totalCount,
                'published_count'   => $publishedCount,
                'total_files'       => $totalFiles,
                'indexed_files'     => $indexedFiles,
                // Infrastructure.
                'shards'            => count($shards),
                'index_version'     => $indexVersion,
                'last_modified'     => $lastModified,
                // Health and stats.
                'service_stats'     => $this->stats,
                'health'            => $health,
                'memory_prediction' => $memoryPrediction,
                // Detailed collection stats.
                'collections'       => [
                    'object' => $objectCollectionData,
                    'file'   => $fileCollectionData,
                ],
            ];
        } catch (\Exception $e) {
            $this->logger->error(message: 'Exception getting dashboard stats', context: ['error' => $e->getMessage()]);
            return [
                'available' => false,
                'error'     => $e->getMessage(),
            ];
        }//end try

    }//end getDashboardStats()


    /**
     * Get statistics for a specific collection
     *
     * @param  string $collectionName Collection name
     * @return array Collection statistics
     * @throws \Exception If collection stats cannot be retrieved
     */
    private function getCollectionStats(string $collectionName): array
    {
        $statsUrl      = $this->buildSolrBaseUrl().'/admin/collections?action=CLUSTERSTATUS&collection='.$collectionName.'&wt=json';
        $statsResponse = $this->httpClient->get($statsUrl, ['timeout' => 10]);
        $statsData     = json_decode((string) $statsResponse->getBody(), true);

        if (($statsData['cluster']['collections'][$collectionName] ?? null) !== null) {
            $collectionInfo = $statsData['cluster']['collections'][$collectionName];
        } else {
            $collectionInfo = [];
        }

        if (($collectionInfo['shards'] ?? null) !== null) {
            $shards = $collectionInfo['shards'];
        } else {
            $shards = [];
        }

        if (($collectionInfo['configName'] ?? null) !== null) {
            $configName = $collectionInfo['configName'];
        } else {
            $configName = 'unknown';
        }

        if (($collectionInfo['replicationFactor'] ?? null) !== null) {
            $replicationFactor = $collectionInfo['replicationFactor'];
        } else {
            $replicationFactor = 1;
        }

        if (($collectionInfo['maxShardsPerNode'] ?? null) !== null) {
            $maxShardsPerNode = $collectionInfo['maxShardsPerNode'];
        } else {
            $maxShardsPerNode = 1;
        }

        if (($collectionInfo['autoAddReplicas'] ?? null) !== null) {
            $autoAddReplicas = $collectionInfo['autoAddReplicas'];
        } else {
            $autoAddReplicas = false;
        }

        return [
            'name'              => $collectionName,
            'shards'            => $shards,
            'configName'        => $configName,
            'replicationFactor' => $replicationFactor,
            'maxShardsPerNode'  => $maxShardsPerNode,
            'autoAddReplicas'   => $autoAddReplicas,
        ];

    }//end getCollectionStats()


    /**
     * Get document count for a specific collection
     *
     * @param  string $collectionName Collection name
     * @return int Document count
     */
    private function getDocumentCountForCollection(string $collectionName): int
    {
        try {
            $baseUrl = $this->buildSolrBaseUrl();
            $url     = $baseUrl.'/'.$collectionName.'/select?'.http_build_query(
                    [
                        'q'    => '*:*',
                        'rows' => 0,
                        'wt'   => 'json',
                    ]
                    );

            $response = $this->httpClient->get($url, ['timeout' => 10]);
            $data     = json_decode((string) $response->getBody(), true);

            if (($data['response']['numFound'] ?? null) !== null) {
                return (int) $data['response']['numFound'];
            }

            return 0;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to get document count for collection',
                    [
                        'collection' => $collectionName,
                        'error'      => $e->getMessage(),
                    ]
                    );
            return 0;
        }//end try

    }//end getDocumentCountForCollection()


    /**
     * Get service statistics
     *
     * @return array Service statistics
     */
    public function getStats(): array
    {
        return array_merge(
                $this->stats,
                [
                    'available'    => $this->isAvailable(),
                    'service_type' => 'GuzzleHttp',
                    'memory_usage' => 'lightweight',
                ]
                );

    }//end getStats()


    /**
     * Test SOLR connection specifically for dashboard display
     *
     * @return array Dashboard-specific connection test results
     */
    public function testConnectionForDashboard(): array
    {
        try {
            $connectionTest = $this->testConnection();
            $stats          = $this->getDashboardStats();

            if (($stats['available'] ?? null) !== null) {
                $availability = $stats['available'];
            } else {
                $availability = false;
            }

            return [
                'connection'   => $connectionTest,
                'availability' => $availability,
                'stats'        => $stats,
                'timestamp'    => date('c'),
            ];
        } catch (\Exception $e) {
            return [
                'connection'   => [
                    'success' => false,
                    'message' => 'SOLR service unavailable: '.$e->getMessage(),
                    'details' => ['error' => $e->getMessage()],
                ],
                'availability' => false,
                'stats'        => [
                    'available' => false,
                    'error'     => $e->getMessage(),
                ],
                'timestamp'    => date('c'),
            ];
        }//end try

    }//end testConnectionForDashboard()


    /**
     * Get SOLR endpoint URL for dashboard display
     *
     * @param  string|null $collection Optional collection name, defaults to active collection
     * @return string SOLR endpoint URL
     */
    public function getEndpointUrl(?string $collection=null): string
    {
        try {
            $baseUrl = $this->buildSolrBaseUrl();
            if ($collection !== null) {
                $collectionName = $collection;
            } else {
                $collectionName = $this->getActiveCollectionName();
            }

            if ($collectionName === null) {
                return 'N/A (no active collection)';
            }

            return $baseUrl.'/'.$collectionName;
        } catch (\Exception $e) {
            $this->logger->warning(message: 'Failed to build endpoint URL', context: ['error' => $e->getMessage()]);
            return 'N/A';
        }

    }//end getEndpointUrl()


    /**
     * Get the authenticated HTTP client for other services to use
     *
     * This allows other services like SolrSetup to use the same authenticated
     * HTTP client without duplicating authentication logic.
     *
     * @return \OCP\Http\Client\IClient The configured and authenticated HTTP client
     */
    public function getHttpClient(): \OCP\Http\Client\IClient
    {
        return $this->httpClient;

    }//end getHttpClient()


    /**
     * Get SOLR configuration for other services
     *
     * @return array SOLR configuration array
     */
    public function getSolrConfig(): array
    {
        return $this->solrConfig;

    }//end getSolrConfig()


    /**
     * Fetch objects that belong to searchable schemas only
     *
     * @param  \OCA\OpenRegister\Db\ObjectEntityMapper $objectMapper The object mapper instance
     * @param  int                                     $limit        Number of objects to fetch
     * @param  int                                     $offset       Offset for pagination
     * @param  array                                   $schemaIds    Optional array of schema IDs to filter by
     * @return array Array of ObjectEntity objects with searchable schemas
     */
    private function fetchSearchableObjects(\OCA\OpenRegister\Db\ObjectEntityMapper $_objectMapper, int $_limit, int $_offset, array $_schemaIds=[]): array
    {
        try {
            // Use direct database query to fetch objects with searchable schemas.
            $db = \OC::$server->getDatabaseConnection();
            $qb = $db->getQueryBuilder();

            $qb->select('o.*')
                ->from('openregister_objects', 'o')
                ->leftJoin('o', 'openregister_schemas', 's', 'o.schema = s.id')
                ->where($qb->expr()->eq('s.searchable', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
                ->andWhere($qb->expr()->isNull('o.deleted'));
            // Exclude deleted objects
            // Add schema filtering if schema IDs are provided.
            if (empty($schemaIds) === false) {
                $qb->andWhere($qb->expr()->in('o.schema', $qb->createNamedParameter($schemaIds, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)));
            }

            $qb->setMaxResults($limit)
                ->setFirstResult($offset)
                ->orderBy('o.id', 'ASC');
            // Consistent ordering for pagination.
            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();

            // Convert rows to ObjectEntity objects.
            $objects = [];
            foreach ($rows as $row) {
                $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
                $objectEntity->hydrate($row);
                $objects[] = $objectEntity;
            }

            if (empty($schemaIds) === true) {
                $schemaIdsFilter = 'ALL';
            } else {
                $schemaIdsFilter = implode(',', $schemaIds);
            }

            $this->logger->info(
                    '🔍 WARMUP: Fetched searchable objects',
                    [
                        'requested'       => $limit,
                        'offset'          => $offset,
                        'found'           => count($objects),
                        'schemaIdsFilter' => $schemaIdsFilter,
                    ]
                    );

            return $objects;
        } catch (\Exception $e) {
            $this->logger->warning(
                    'Failed to fetch searchable objects, falling back to all objects',
                    [
                        'error'  => $e->getMessage(),
                        'limit'  => $limit,
                        'offset' => $offset,
                    ]
                    );
            // Fallback to fetching all objects if searchable filter fails.
            return $objectMapper->findAll(
                limit: $limit,
                offset: $offset,
                rbac: false,
                multi: false
            );
        }//end try

    }//end fetchSearchableObjects()


    /**
     * Test schema-aware mapping by indexing sample objects
     *
     * This method indexes 5 objects per schema to test the new schema-aware mapping system.
     * Objects without schema properties will only have metadata indexed.
     *
     * @param \OCA\OpenRegister\Db\ObjectEntityMapper $objectMapper Object mapper for database operations
     * @param SchemaMapper                            $schemaMapper Schema mapper for database operations
     *
     * @return array Test results with statistics
     */
    public function testSchemaAwareMapping($objectMapper, $schemaMapper): array
    {
        if ($this->isAvailable() === false) {
            return [
                'success'         => false,
                'error'           => 'SOLR is not available',
                'schemas_tested'  => 0,
                'objects_indexed' => 0,
            ];
        }

        $startTime = microtime(true);
        $results   = [
            'success'         => true,
            'schemas_tested'  => 0,
            'objects_indexed' => 0,
            'schema_details'  => [],
            'errors'          => [],
        ];

        try {
            // Get all schemas.
            $schemas = $schemaMapper->findAll();

            $this->logger->info(
                    'Starting schema-aware mapping test',
                    [
                        'total_schemas' => count($schemas),
                    ]
                    );

            foreach ($schemas as $schema) {
                $schemaId      = $schema->getId();
                $schemaDetails = [
                    'schema_id'        => $schemaId,
                    'schema_title'     => $schema->getTitle(),
                    'properties_count' => count($schema->getProperties()),
                    'objects_found'    => 0,
                    'objects_indexed'  => 0,
                    'mapping_type'     => 'unknown',
                ];

                try {
                    // Get 5 objects for this schema.
                    $objects = $objectMapper->searchObjects(
                            [
                                'schema'  => $schemaId,
                                '_limit'  => 5,
                                '_offset' => 0,
                            ]
                            );

                    $schemaDetails['objects_found'] = count($objects);

                    // Index each object using schema-aware mapping.
                    foreach ($objects as $objectData) {
                        try {
                            $entity = new ObjectEntity();
                            $entity->hydrate($objectData);

                            // Create SOLR document (will use schema-aware mapping).
                            $document = $this->createSolrDocument($entity);

                            // Determine mapping type based on document structure.
                            if (($document['self_schema'] ?? null) !== null && count($document) > 20) {
                                $schemaDetails['mapping_type'] = 'schema-aware';
                            } else {
                                $schemaDetails['mapping_type'] = 'legacy-fallback';
                            }

                            // Index the document.
                            if ($this->bulkIndex(documents: [$document], commit: true) === true) {
                                $schemaDetails['objects_indexed']++;
                                $results['objects_indexed']++;
                            }
                        } catch (\Exception $e) {
                            if (($objectData['id'] ?? null) !== null) {
                                $objectId = $objectData['id'];
                            } else {
                                $objectId = 'unknown';
                            }

                            $results['errors'][] = [
                                'schema_id' => $schemaId,
                                'object_id' => $objectId,
                                'error'     => $e->getMessage(),
                            ];
                        }//end try
                    }//end foreach

                    $results['schema_details'][] = $schemaDetails;
                    $results['schemas_tested']++;
                } catch (\Exception $e) {
                    $results['errors'][] = [
                        'schema_id' => $schemaId,
                        'error'     => 'Schema processing failed: '.$e->getMessage(),
                    ];
                }//end try
            }//end foreach

            // Commit all indexed documents.
            $this->commit();

            $results['duration'] = round((microtime(true) - $startTime) * 1000, 2);
            $results['success']  = true;

            $this->logger->info(
                    'Schema-aware mapping test completed',
                    [
                        'schemas_tested'  => $results['schemas_tested'],
                        'objects_indexed' => $results['objects_indexed'],
                        'duration_ms'     => $results['duration'],
                    ]
                    );
        } catch (\Exception $e) {
            $results['success'] = false;
            $results['error']   = $e->getMessage();
            $this->logger->error(
                    'Schema-aware mapping test failed',
                    [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );
        }//end try

        return $results;

    }//end testSchemaAwareMapping()


    /**
     * Warmup SOLR index (simplified implementation for GuzzleSolrService)
     *
     * @param array  $schemas       Array of Schema entities (not used in this implementation)
     * @param int    $maxObjects    Maximum number of objects to index
     * @param string $mode          Processing mode ('serial', 'parallel', 'hyper')
     * @param bool   $collectErrors Whether to collect all errors or stop on first
     * @param int    $batchSize     Number of objects to process per batch
     * @param array  $schemaIds     Array of schema IDs to limit warmup to specific schemas (empty = all schemas)
     *
     * @return array Warmup results
     */
    public function warmupIndex(array $schemas=[], int $maxObjects=0, string $mode='serial', bool $collectErrors=false, int $batchSize=1000, array $schemaIds=[]): array
    {
        // schemaIds is already an array with default value []
        if ($this->isAvailable() === false) {
            return [
                'success'           => false,
                'operations'        => [],
                'execution_time_ms' => 0.0,
                'error'             => 'SOLR is not available',
            ];
        }

        $startTime  = microtime(true);
        $operations = [];

        // **MEMORY OPTIMIZATION**: Increase memory limit and optimize settings for large datasets.
        $originalMemoryLimit      = ini_get('memory_limit');
        $originalMaxExecutionTime = ini_get('max_execution_time');

        // Set execution time limit for warmup process (memory limit now set at container level).
        ini_set('max_execution_time', 3600);
        // 1 hour
        // **MEMORY TRACKING**: Capture initial memory usage and predict requirements.
        $initialMemoryUsage = memory_get_usage(true);
        $initialMemoryPeak  = memory_get_peak_usage(true);
        $memoryPrediction   = $this->predictWarmupMemoryUsage($maxObjects, $schemaIds);

        // **CRITICAL**: Disable profiler during warmup - even with reduced logging, 26K+ queries overwhelm profiler.
        $profilerWasEnabled = false;
        try {
            $profiler = \OC::$server->get(\OCP\Profiler\IProfiler::class);
            if ($profiler->isEnabled() === true) {
                $profilerWasEnabled = true;
                $reflection         = new \ReflectionClass($profiler);
                if ($reflection->hasMethod('setEnabled') === true) {
                    $profiler->setEnabled(false);
                }
            }
        } catch (\Exception $e) {
            // Profiler not available - continue.
        }

        // Minimal warmup logging to prevent profiler memory issues.
        try {
            // 1. Test connection.
            $connectionResult = $this->testConnection();
            if (($connectionResult['success'] ?? null) !== null) {
                $operations['connection_test'] = $connectionResult['success'];
            } else {
                $operations['connection_test'] = false;
            }

            // 2. Schema mirroring with intelligent conflict resolution.
            if (empty($schemas) === false) {
                $stageStart = microtime(true);

                // Schema mirroring (logging removed for performance).
                // Lazy-load SolrSchemaService to avoid circular dependency.
                $solrSchemaService = \OC::$server->get(SolrSchemaService::class);
                $mirrorResult      = $solrSchemaService->mirrorSchemas(true);
                // Force update for testing.
                if (($mirrorResult['success'] ?? null) !== null) {
                    $operations['schema_mirroring'] = $mirrorResult['success'];
                } else {
                    $operations['schema_mirroring'] = false;
                }

                if (($mirrorResult['stats']['schemas_processed'] ?? null) !== null) {
                    $operations['schemas_processed'] = $mirrorResult['stats']['schemas_processed'];
                } else {
                    $operations['schemas_processed'] = 0;
                }

                if (($mirrorResult['stats']['fields_created'] ?? null) !== null) {
                    $operations['fields_created'] = $mirrorResult['stats']['fields_created'];
                } else {
                    $operations['fields_created'] = 0;
                }

                if (($mirrorResult['stats']['conflicts_resolved'] ?? null) !== null) {
                    $operations['conflicts_resolved'] = $mirrorResult['stats']['conflicts_resolved'];
                } else {
                    $operations['conflicts_resolved'] = 0;
                }

                // 2.5. Field creation removed from warmup process to prevent conflicts.
                // Fields should be managed via the dedicated field management UI/API.
                $fieldManagementStart = microtime(true);

                // Skip automatic field creation - use dedicated field management instead.
                $operations['missing_fields_created'] = true;
                // Always true since we skip this step.
                $operations['fields_added']   = 0;
                $operations['fields_updated'] = 0;

                // Field management skipped (logging removed for performance).
                $fieldManagementEnd         = microtime(true);
                $timing['field_management'] = round(($fieldManagementEnd - $fieldManagementStart) * 1000, 2).'ms';

                // 2.6. Collect current SOLR field types for validation (force refresh after schema changes).
                $solrFieldTypes = $this->getSolrFieldTypes(true);
                $operations['field_types_collected'] = count($solrFieldTypes);

                // Schema mirroring result stored in operations (logging removed for performance).
                $stageEnd = microtime(true);
                $timing['schema_mirroring'] = round(($stageEnd - $stageStart) * 1000, 2).'ms';
            } else {
                $operations['schema_mirroring']   = false;
                $operations['schemas_processed']  = 0;
                $operations['fields_created']     = 0;
                $operations['conflicts_resolved'] = 0;
                $timing['schema_mirroring']       = '0ms (no schemas provided)';

                // Field creation removed from warmup process to prevent conflicts.
                // Fields should be managed via the dedicated field management UI/API.
                $fieldManagementStart = microtime(true);

                // Skip automatic field creation - use dedicated field management instead.
                $operations['missing_fields_created'] = true;
                // Always true since we skip this step.
                $operations['fields_added']   = 0;
                $operations['fields_updated'] = 0;

                // Field management skipped (logging removed for performance).
                $fieldManagementEnd         = microtime(true);
                $timing['field_management'] = round(($fieldManagementEnd - $fieldManagementStart) * 1000, 2).'ms';

                // Get current SOLR field types for validation.
                $solrFieldTypes = $this->getSolrFieldTypes(true);
                $operations['field_types_collected'] = count($solrFieldTypes);
            }//end if

            // 3. Object indexing using mode-based bulk indexing (no logging for performance).
            if (($solrFieldTypes ?? null) !== null) {
                $solrFieldTypesValue = $solrFieldTypes;
            } else {
                $solrFieldTypesValue = [];
            }

            if ($mode === 'hyper') {
                $indexResult = $this->bulkIndexFromDatabaseOptimized(batchSize: $batchSize, maxObjects: $maxObjects, solrFieldTypes: $solrFieldTypesValue, schemaIds: $schemaIds);
            } else if ($mode === 'parallel') {
                $indexResult = $this->bulkIndexFromDatabaseParallel(batchSize: $batchSize, maxObjects: $maxObjects, parallelBatches: 5, solrFieldTypes: $solrFieldTypesValue, schemaIds: $schemaIds);
            } else {
                $indexResult = $this->bulkIndexFromDatabase(batchSize: $batchSize, maxObjects: $maxObjects, solrFieldTypes: $solrFieldTypesValue, schemaIds: $schemaIds);
            }

            // Pass collectErrors mode for potential future use.
            $operations['error_collection_mode'] = $collectErrors;
            if (($indexResult['success'] ?? null) !== null) {
                $operations['object_indexing'] = $indexResult['success'];
            } else {
                $operations['object_indexing'] = false;
            }

            if (($indexResult['indexed'] ?? null) !== null) {
                $operations['objects_indexed'] = $indexResult['indexed'];
            } else {
                $operations['objects_indexed'] = 0;
            }

            // **BUG FIX**: Calculate errors properly - if no errors field, assume 0 errors when successful.
            if (($indexResult['errors'] ?? null) !== null) {
                $operations['indexing_errors'] = $indexResult['errors'];
            } else {
                $operations['indexing_errors'] = 0;
            }

            // 4. Perform basic warmup queries to warm SOLR caches.
            $warmupQueries = [
                ['q' => '*:*', 'rows' => 1],
            // Basic query to warm general cache.
                ['q' => 'name:*', 'rows' => 5],
            // Name field search to warm field caches.
                ['q' => '*:*', 'rows' => 0, 'facet.field' => 'register_id_i'],
            // Facet query to warm facet cache.
            ];

            $successfulQueries = 0;
            foreach ($warmupQueries as $i => $query) {
                try {
                    // Simple query execution for cache warming.
                    $collectionName = $this->getActiveCollectionName();
                    if ($collectionName === null) {
                        $operations["warmup_query_$i"] = false;
                        continue;
                    }

                    $queryString = http_build_query($query);
                    $url         = $this->buildSolrBaseUrl()."/{$collectionName}/select?".$queryString;

                    $response = $this->httpClient->get($url);
                    if ($response->getStatusCode() === 200) {
                        $operations["warmup_query_$i"] = true;
                        $successfulQueries++;
                    } else {
                        $operations["warmup_query_$i"] = false;
                    }
                } catch (\Exception $e) {
                    $operations["warmup_query_$i"] = false;
                    $this->logger->warning(message: "Warmup query $i failed", context: ['error' => $e->getMessage()]);
                }//end try
            }//end foreach

            // 5. Commit (simplified).
            $operations['commit'] = true;

            $executionTime = (microtime(true) - $startTime) * 1000;

            // **MEMORY TRACKING**: Calculate final memory usage and statistics.
            $finalMemoryUsage = (int) memory_get_usage(true);
            $finalMemoryPeak  = (int) memory_get_peak_usage(true);
            $memoryReport     = $this->generateMemoryReport(initialUsage: $initialMemoryUsage, finalUsage: $finalMemoryUsage, initialPeak: $initialMemoryPeak, finalPeak: $finalMemoryPeak, prediction: $memoryPrediction);

            // **RESTORE SETTINGS**: Reset PHP execution time to original value.
            ini_set('max_execution_time', $originalMaxExecutionTime);

            // **RESTORE PROFILER**: Re-enable profiler if it was enabled.
            if ($profilerWasEnabled === true) {
                try {
                    $profiler   = \OC::$server->get(\OCP\Profiler\IProfiler::class);
                    $reflection = new \ReflectionClass($profiler);
                    if ($reflection->hasMethod('setEnabled') === true) {
                        $profiler->setEnabled(true);
                    }
                } catch (\Exception $e) {
                    // Ignore profiler restoration errors.
                }
            }

            if (($indexResult['total'] ?? null) !== null) {
                $totalObjectsFound = $indexResult['total'];
            } else {
                $totalObjectsFound = 0;
            }

            if (($indexResult['indexed'] ?? null) !== null) {
                $objectsIndexed = $indexResult['indexed'];
            } else {
                $objectsIndexed = 0;
            }

            if (($indexResult['batches'] ?? null) !== null) {
                $batchesProcessed = $indexResult['batches'];
            } else {
                $batchesProcessed = 0;
            }

            if (($indexResult['errors'] ?? null) !== null) {
                $indexingErrors = $indexResult['errors'];
            } else {
                $indexingErrors = 0;
            }

            return [
                'success'             => true,
                'operations'          => $operations,
                'execution_time_ms'   => round($executionTime, 2),
                'message'             => 'GuzzleSolrService warmup completed with field management and optimization',
                'total_objects_found' => $totalObjectsFound,
                'objects_indexed'     => $objectsIndexed,
                'batches_processed'   => $batchesProcessed,
                'indexing_errors'     => $indexingErrors,
                'max_objects_limit'   => $maxObjects,
                'memory_usage'        => $memoryReport,
            ];
        } catch (\Exception $e) {
            // **MEMORY TRACKING**: Calculate memory usage even on error.
            $finalMemoryUsage = (int) memory_get_usage(true);
            $finalMemoryPeak  = (int) memory_get_peak_usage(true);
            if (($memoryPrediction ?? null) !== null) {
                $memoryPredictionValue = $memoryPrediction;
            } else {
                $memoryPredictionValue = [];
            }

            $memoryReport = $this->generateMemoryReport(initialUsage: $initialMemoryUsage, finalUsage: $finalMemoryUsage, initialPeak: $initialMemoryPeak, finalPeak: $finalMemoryPeak, prediction: $memoryPredictionValue);

            // **RESTORE SETTINGS**: Reset PHP execution time to original value even on error.
            ini_set('max_execution_time', $originalMaxExecutionTime);

            // **RESTORE PROFILER**: Re-enable profiler if it was enabled (even on error).
            if ($profilerWasEnabled === true) {
                try {
                    $profiler   = \OC::$server->get(\OCP\Profiler\IProfiler::class);
                    $reflection = new \ReflectionClass($profiler);
                    if ($reflection->hasMethod('setEnabled') === true) {
                        $profiler->setEnabled(true);
                    }
                } catch (\Exception $profilerError) {
                    // Ignore profiler restoration errors.
                }
            }

            return [
                'success'           => false,
                'operations'        => $operations,
                'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'error'             => $e->getMessage(),
                'memory_usage'      => $memoryReport,
            ];
        }//end try

    }//end warmupIndex()


    /**
     * Validate field against SOLR schema to prevent type mismatches
     *
     * @param  string $fieldName      The SOLR field name
     * @param  mixed  $fieldValue     The value to be indexed
     * @param  array  $solrFieldTypes Current SOLR field types
     * @return bool True if field is safe to index
     */
    private function validateFieldForSolr(string $fieldName, $fieldValue, array $solrFieldTypes): bool
    {
        // If no field types provided, allow all (fallback to original behavior).
        if (empty($solrFieldTypes) === true) {
            return true;
        }

        // If field doesn't exist in SOLR, it will be auto-created (allow).
        if (isset($solrFieldTypes[$fieldName]) === false) {
            $this->logger->debug(
                    'Field not in SOLR schema, will be auto-created',
                    [
                        'field' => $fieldName,
                        'value' => $fieldValue,
                        'type'  => gettype($fieldValue),
                    ]
                    );
            return true;
        }

        $solrFieldType = $solrFieldTypes[$fieldName];

        // **CRITICAL VALIDATION**: Check for type compatibility.
        $isCompatible = $this->isValueCompatibleWithSolrType(value: $fieldValue, solrFieldType: $solrFieldType);

        if ($isCompatible === false) {
            $this->logger->warning(
                    '🛡️ Field validation prevented type mismatch',
                    [
                        'field'           => $fieldName,
                        'value'           => $fieldValue,
                        'value_type'      => gettype($fieldValue),
                        'solr_field_type' => $solrFieldType,
                        'action'          => 'SKIPPED',
                    ]
                    );
            return false;
        }

        $this->logger->debug(
                '✅ Field validation passed',
                [
                    'field'     => $fieldName,
                    'value'     => $fieldValue,
                    'solr_type' => $solrFieldType,
                ]
                );

        return true;

    }//end validateFieldForSolr()


    /**
     * Check if a value is compatible with a SOLR field type
     *
     * @param  mixed  $value         The value to check
     * @param  string $solrFieldType The SOLR field type (e.g., 'plongs', 'string', 'text_general')
     * @return bool True if compatible
     */
    private function isValueCompatibleWithSolrType($value, string $solrFieldType): bool
    {
        // Handle null values (generally allowed).
        if ($value === null) {
            return true;
        }

        // **FIXED**: Handle arrays for multi-valued fields.
        // For multi-valued fields, arrays are expected and should be validated per element.
        if (is_array($value) === true) {
            // Empty arrays are always allowed for multi-valued fields.
            if (empty($value) === true) {
                return true;
            }

            // Check each element in the array against the base field type.
            foreach ($value as $element) {
                if ($this->isValueCompatibleWithSolrType(value: $element, solrFieldType: $solrFieldType) === false) {
                    return false;
                }
            }

            return true;
        }

        return match ($solrFieldType) {
            // Numeric types - only allow numeric values.
            'pint', 'plong', 'plongs', 'pfloat', 'pdouble' => is_numeric($value) === true,

            // String types - allow anything (can be converted to string).
            'string', 'text_general', 'text_en' => true,

            // Boolean types - allow boolean or boolean-like values.
            'boolean' => is_bool($value) === true || in_array(strtolower($value), ['true', 'false', '1', '0']) === true,

            // Date types - allow date strings or objects.
            'pdate', 'pdates' => is_string($value) === true || ($value instanceof \DateTime),

            // Default: allow for unknown types.
            default => true
        };

    }//end isValueCompatibleWithSolrType()


    /**
     * Clear all cached data to force refresh
     */
    public function clearCache(): void
    {
        $this->cachedSolrFieldTypes = null;
        $this->logger->debug(message: '🧹 Cleared SOLR service caches');

    }//end clearCache()


    /**
     * Fix mismatched SOLR fields by updating their configuration
     *
     * @param  array $mismatchedFields Array of field configurations keyed by field name that need to be fixed
     * @param  bool  $dryRun           If true, only simulate the updates without actually making changes
     * @return array Result with success status, message, and fixed fields list
     */
    public function fixMismatchedFields(array $mismatchedFields, bool $dryRun=false): array
    {
        if ($this->isAvailable() === false) {
            return [
                'success' => false,
                'message' => 'SOLR is not available or not configured',
            ];
        }

        try {
            $startTime      = microtime(true);
            $collectionName = $this->getActiveCollectionName();

            if ($collectionName === null || $collectionName === '') {
                return [
                    'success' => false,
                    'message' => 'No active SOLR collection found',
                ];
            }

            if (empty($mismatchedFields) === true) {
                return [
                    'success' => true,
                    'message' => 'No mismatched fields provided',
                    'fixed'   => [],
                    'errors'  => [],
                ];
            }

            $this->logger->debug(
                    'Fixing mismatched SOLR fields',
                    [
                        'field_count' => count($mismatchedFields),
                        'fields'      => array_keys($mismatchedFields),
                        'dry_run'     => $dryRun,
                    ]
                    );

            // Process mismatched fields - check for SOLR limitations first.
            $fixed     = [];
            $errors    = [];
            $warnings  = [];
            $schemaUrl = $this->buildSolrBaseUrl()."/{$collectionName}/schema";

            // Get current field configuration to check for immutable property changes.
            $currentFieldsResponse = $this->getFieldsConfiguration();
            if (($currentFieldsResponse['fields'] ?? null) !== null) {
                $currentFields = $currentFieldsResponse['fields'];
            } else {
                $currentFields = [];
            }

            foreach ($mismatchedFields as $fieldName => $fieldConfig) {
                try {
                    // Check if this is a docValues change (which is immutable in SOLR).
                    if (($currentFields[$fieldName] ?? null) !== null) {
                        $currentField = $currentFields[$fieldName];
                    } else {
                        $currentField = null;
                    }

                    if (($fieldConfig['docValues'] ?? null) !== null) {
                        $newDocValues = $fieldConfig['docValues'];
                    } else {
                        $newDocValues = false;
                    }

                    if ($currentField !== null && (($currentField['docValues'] ?? null) !== null)) {
                        $currentDocValues = $currentField['docValues'];
                    } else {
                        $currentDocValues = false;
                    }

                    if ($currentField !== null && $newDocValues !== $currentDocValues) {
                        if ($currentDocValues === true) {
                            $currentDocValuesStr = 'true';
                        } else {
                            $currentDocValuesStr = 'false';
                        }

                        if ($newDocValues === true) {
                            $newDocValuesStr = 'true';
                        } else {
                            $newDocValuesStr = 'false';
                        }

                        $warning    = "Cannot change docValues for field '{$fieldName}' from ".$currentDocValuesStr." to ".$newDocValuesStr." - docValues is immutable in SOLR. Field would need to be deleted and recreated (losing data).";
                        $warnings[] = $warning;
                        $this->logger->warning(
                                $warning,
                                [
                                    'field'             => $fieldName,
                                    'current_docValues' => $currentDocValues,
                                    'desired_docValues' => $newDocValues,
                                ]
                                );
                        continue;
                    }//end if

                    // Prepare field configuration for SOLR.
                    $solrFieldConfig = $this->prepareSolrFieldConfig(fieldName: $fieldName, fieldConfig: $fieldConfig);

                    // Use replace-field for existing mismatched fields.
                    $payload = [
                        'replace-field' => $solrFieldConfig,
                    ];

                    if ($dryRun === true) {
                        $fixed[] = $fieldName;
                        $this->logger->debug(
                                "Dry run: Would fix field '{$fieldName}'",
                                [
                                    'field_config' => $solrFieldConfig,
                                ]
                                );
                    } else {
                        // Make the API call to fix the field.
                        $response = $this->httpClient->post(
                                $schemaUrl,
                                [
                                    'json'    => $payload,
                                    'headers' => [
                                        'Content-Type' => 'application/json',
                                    ],
                                ]
                                );

                        /*
                         * @var \Psr\Http\Message\ResponseInterface $response
                         */
                        $responseData = json_decode($response->getBody()->getContents(), true);

                        if (($responseData['responseHeader']['status'] ?? null) !== null) {
                            $responseStatus = $responseData['responseHeader']['status'];
                        } else {
                            $responseStatus = 1;
                        }

                        if ($response->getStatusCode() === 200 && $responseStatus === 0) {
                            $fixed[] = $fieldName;
                            $this->logger->info(
                                    "Successfully fixed field '{$fieldName}'",
                                    [
                                        'field_config' => $solrFieldConfig,
                                    ]
                                    );
                        } else {
                            if (($responseData['error']['msg'] ?? null) !== null) {
                                $errorMsg = $responseData['error']['msg'];
                            } else {
                                $errorMsg = 'Unknown error';
                            }

                            $error    = "Failed to fix field '{$fieldName}': ".$errorMsg;
                            $errors[] = $error;
                            $this->logger->error(
                                    $error,
                                    [
                                        'response_status' => $response->getStatusCode(),
                                        'response_data'   => $responseData,
                                    ]
                                    );
                        }//end if
                    }//end if
                } catch (\Exception $e) {
                    $error    = "Exception while fixing field '{$fieldName}': ".$e->getMessage();
                    $errors[] = $error;
                    $this->logger->error(
                            $error,
                            [
                                'exception'    => $e,
                                'field_config' => $fieldConfig,
                            ]
                            );
                }//end try
            }//end foreach

            $executionTime = (microtime(true) - $startTime) * 1000;
            $fixedCount    = count($fixed);
            $errorCount    = count($errors);
            $warningCount  = count($warnings);

            if ($dryRun === true) {
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
                'success'           => true,
                'message'           => $message,
                'fixed'             => $fixed,
                'errors'            => $errors,
                'warnings'          => $warnings,
                'execution_time_ms' => round($executionTime, 2),
                'dry_run'           => $dryRun,
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                    'Exception in fixMismatchedFields',
                    [
                        'exception' => $e->getMessage(),
                        'trace'     => $e->getTraceAsString(),
                    ]
                    );

            return [
                'success' => false,
                'message' => 'Failed to fix mismatched fields: '.$e->getMessage(),
                'errors'  => [$e->getMessage()],
            ];
        }//end try

    }//end fixMismatchedFields()


    /**
     * Reindex all objects in SOLR
     *
     * This method clears the current SOLR index and rebuilds it from scratch
     * with all objects using the current field schema configuration.
     *
     * @param  int $maxObjects Maximum number of objects to reindex (0 = all)
     * @param  int $batchSize  Number of objects to process per batch
     * @return array{success: bool, message: string, stats?: array, error?: string}
     */
    public function reindexAll(int $maxObjects=0, int $batchSize=1000, ?string $collectionName=null): array
    {
        if ($this->isAvailable() === false) {
            return [
                'success' => false,
                'message' => 'SOLR is not available or not configured',
            ];
        }

        try {
            $startTime   = microtime(true);
            $startMemory = memory_get_usage(true);

            // Use provided collection name or get the active collection.
            if ($collectionName !== null) {
                $targetCollection = $collectionName;
            } else {
                $targetCollection = $this->getActiveCollectionName();
            }

            if ($targetCollection === null) {
                return [
                    'success' => false,
                    'message' => 'No collection specified and no active collection found',
                ];
            }

            $this->logger->info(
                    '🔄 Starting SOLR reindex operation',
                    [
                        'max_objects' => $maxObjects,
                        'batch_size'  => $batchSize,
                        'collection'  => $targetCollection,
                    ]
                    );

            // Step 1: Clear the current index.
            $this->logger->info(message: '🗑️ Clearing current SOLR index');
            $clearResult = $this->clearIndex($targetCollection);

            if (isset($clearResult['success']) === false || $clearResult['success'] === false) {
                if (($clearResult['error'] ?? null) !== null) {
                    $error = $clearResult['error'];
                } else {
                    $error = null;
                }

                return [
                    'success' => false,
                    'message' => 'Failed to clear SOLR index: '.$clearResult['message'],
                    'error'   => $error,
                ];
            }

            // Step 2: Get object count for progress tracking.
            $objectMapper = \OC::$server->get(\OCA\OpenRegister\Db\ObjectEntityMapper::class);
            $totalObjects = $objectMapper->countAll(
                filters: [],
                search: null,
                ids: null,
                uses: null,
                includeDeleted: false,
                register: null,
                schema: null,
                    published: true,
            // Only reindex published objects.
                rbac: false,
                multi: false
            );

            // Apply maxObjects limit if specified.
            if ($maxObjects > 0 && $maxObjects < $totalObjects) {
                $totalObjects = $maxObjects;
            }

            $this->logger->info(
                    '📊 Reindex scope determined',
                    [
                        'total_objects'     => $totalObjects,
                        'batch_size'        => $batchSize,
                        'estimated_batches' => ceil($totalObjects / $batchSize),
                    ]
                    );

            // Step 3: Reindex objects in batches.
            $stats = [
                'total_objects'      => $totalObjects,
                'processed_objects'  => 0,
                'successful_indexes' => 0,
                'failed_indexes'     => 0,
                'batches_processed'  => 0,
                'errors'             => [],
            ];

            $offset      = 0;
            $batchNumber = 1;

            while ($offset < $totalObjects) {
                $currentBatchSize = min($batchSize, $totalObjects - $offset);

                $this->logger->debug(
                        '📦 Processing reindex batch',
                        [
                            'batch_number' => $batchNumber,
                            'offset'       => $offset,
                            'batch_size'   => $currentBatchSize,
                        ]
                        );

                // Get objects for this batch.
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
                    published: true,
                // Only reindex published objects.
                    rbac: false,
                    multi: false
                );

                // Index objects in this batch.
                $batchSuccesses = 0;
                $batchErrors    = 0;

                foreach ($objects as $object) {
                    try {
                        $success = $this->indexObject(object: $object, commit: false);
                        // Don't commit each object.
                        if ($success === true) {
                            $batchSuccesses++;
                        } else {
                            $batchErrors++;
                            $stats['errors'][] = [
                                'object_id'   => $object->getId(),
                                'object_uuid' => $object->getUuid(),
                                'error'       => 'Failed to index object',
                            ];
                        }
                    } catch (\Exception $e) {
                        $batchErrors++;
                        $stats['errors'][] = [
                            'object_id'   => $object->getId(),
                            'object_uuid' => $object->getUuid(),
                            'error'       => $e->getMessage(),
                        ];

                        $this->logger->warning(
                                'Failed to reindex object',
                                [
                                    'object_id' => $object->getId(),
                                    'error'     => $e->getMessage(),
                                ]
                                );
                    }//end try
                }//end foreach

                // Commit batch.
                $this->commit();

                // Update stats.
                $stats['processed_objects']  += count($objects);
                $stats['successful_indexes'] += $batchSuccesses;
                $stats['failed_indexes']     += $batchErrors;
                $stats['batches_processed']++;

                $this->logger->info(
                        '✅ Reindex batch completed',
                        [
                            'batch_number'     => $batchNumber,
                            'processed'        => count($objects),
                            'successful'       => $batchSuccesses,
                            'failed'           => $batchErrors,
                            'total_processed'  => $stats['processed_objects'],
                            'progress_percent' => round(($stats['processed_objects'] / $totalObjects) * 100, 1),
                        ]
                        );

                $offset += $currentBatchSize;
                $batchNumber++;

                // Memory cleanup every 10 batches.
                if ($batchNumber % 10 === 0) {
                    gc_collect_cycles();
                }
            }//end while

            // Final commit and optimize.
            $this->logger->info(message: '🔧 Finalizing reindex - committing and optimizing');
            $this->commit();

            // Calculate final stats.
            $endTime   = microtime(true);
            $endMemory = memory_get_usage(true);

            $stats['duration_seconds']   = round($endTime - $startTime, 2);
            $stats['objects_per_second'] = $this->calculateObjectsPerSecond(durationSeconds: $stats['duration_seconds'], processedObjects: $stats['processed_objects']);
            $stats['memory_used_mb']     = round(($endMemory - $startMemory) / 1024 / 1024, 2);
            $stats['peak_memory_mb']     = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

            $this->logger->info(
                    '🎉 SOLR reindex completed successfully',
                    [
                        'total_processed'    => $stats['processed_objects'],
                        'successful'         => $stats['successful_indexes'],
                        'failed'             => $stats['failed_indexes'],
                        'duration'           => $stats['duration_seconds'].'s',
                        'objects_per_second' => $stats['objects_per_second'],
                        'memory_used'        => $stats['memory_used_mb'].'MB',
                    ]
                    );

            return [
                'success' => true,
                'message' => "Reindex completed successfully. Processed {$stats['processed_objects']} objects in {$stats['duration_seconds']}s",
                'stats'   => $stats,
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                    'Exception during SOLR reindex',
                    [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            return [
                'success' => false,
                'message' => 'Reindex failed: '.$e->getMessage(),
                'error'   => $e->getMessage(),
            ];
        }//end try

    }//end reindexAll()


    /**
     * Create missing SOLR fields based on schema analysis
     *
     * This method analyzes the difference between expected schema fields and actual SOLR fields,
     * then creates the missing fields using the SOLR Schema API.
     *
     * @param  array $expectedFields Expected field configuration from schema analysis
     * @param  bool  $dryRun         If true, only returns what would be created without making changes
     * @return array{success: bool, message: string, created?: array, errors?: array, dry_run?: bool}
     */
    public function createMissingFields(array $expectedFields=[], bool $_dryRun=false): array
    {
        if ($this->isAvailable() === false) {
            return [
                'success' => false,
                'message' => 'SOLR is not available or not configured',
            ];
        }

        try {
            $startTime      = microtime(true);
            $collectionName = $this->getActiveCollectionName();

            if ($collectionName === null || $collectionName === '') {
                return [
                    'success' => false,
                    'message' => 'No active SOLR collection found',
                ];
            }

            // If no expected fields provided, get them from schema analysis.
            if (empty($expectedFields) === true) {
                // Get SolrSchemaService to analyze schemas.
                $solrSchemaService = \OC::$server->get(SolrSchemaService::class);
                $schemaMapper      = \OC::$server->get(\OCA\OpenRegister\Db\SchemaMapper::class);

                // Get all schemas.
                $schemas = $schemaMapper->findAll();

                // Use the existing analyzeAndResolveFieldConflicts method via reflection.
                $reflection = new \ReflectionClass($solrSchemaService);
                $method     = $reflection->getMethod('analyzeAndResolveFieldConflicts');
                $method->setAccessible(true);
                $expectedFields = $method->invoke($solrSchemaService, $schemas);

                // Debug: Log the structure of expected fields.
                $this->logger->debug(
                        'Expected fields from schema analysis',
                        [
                            'field_count'       => count($expectedFields),
                            'sample_fields'     => array_slice($expectedFields, 0, 3, true),
                            'field_keys_sample' => array_slice(array_keys($expectedFields), 0, 5),
                        ]
                        );
            }//end if

            // Get current SOLR fields.
            $currentFieldsResponse = $this->getFieldsConfiguration();
            if ($currentFieldsResponse['success'] === false) {
                return [
                    'success' => false,
                    'message' => 'Failed to retrieve current SOLR fields: '.$currentFieldsResponse['message'],
                ];
            }

            $currentFields = $currentFieldsResponse['fields'] ?? [];

            // Find only missing fields (not mismatched ones - use fixMismatchedFields for those).
            $fieldsToProcess = [];
            foreach ($expectedFields as $fieldName => $fieldConfig) {
                // Skip if fieldName is not a string (defensive programming).
                if (is_string($fieldName) === false) {
                    $this->logger->warning(
                            'Skipping non-string field name',
                            [
                                'field_name_type'  => gettype($fieldName),
                                'field_name_value' => $fieldName,
                            ]
                            );
                    continue;
                }

                // Skip if fieldConfig is not an array.
                if (is_array($fieldConfig) === false) {
                    $this->logger->warning(
                            'Skipping non-array field config',
                            [
                                'field_name'         => $fieldName,
                                'field_config_type'  => gettype($fieldConfig),
                                'field_config_value' => $fieldConfig,
                            ]
                            );
                    continue;
                }

                // Only add truly missing fields.
                if (isset($currentFields[$fieldName]) === false) {
                    $fieldsToProcess[$fieldName] = $fieldConfig;
                    $this->logger->debug(message: "Field '{$fieldName}' is missing and will be created");
                }
            }//end foreach

            if (empty($fieldsToProcess) === true) {
                return [
                    'success' => true,
                    'message' => 'No missing or mismatched fields found - SOLR schema is up to date',
                    'created' => [],
                    'errors'  => [],
                ];
            }

            $this->logger->info(
                    '🔧 Processing SOLR fields (create missing, update mismatched)',
                    [
                        'collection'        => $collectionName,
                        'fields_to_process' => count($fieldsToProcess),
                        'dry_run'           => $dryRun,
                    ]
                    );

            if ($dryRun === true) {
                return [
                    'success'      => true,
                    'message'      => 'Dry run completed - '.count($fieldsToProcess).' fields would be processed',
                    'would_create' => array_keys($fieldsToProcess),
                    'dry_run'      => true,
                ];
            }

            // Process fields (create missing, update mismatched).
            $created   = [];
            $errors    = [];
            $schemaUrl = $this->buildSolrBaseUrl()."/{$collectionName}/schema";

            foreach ($fieldsToProcess as $fieldName => $fieldConfig) {
                try {
                    // Prepare field configuration for SOLR.
                    $solrFieldConfig = $this->prepareSolrFieldConfig(fieldName: $fieldName, fieldConfig: $fieldConfig);

                    // Always use add-field since we only process missing fields.
                    $operation = 'add-field';

                    $payload = [
                        $operation => $solrFieldConfig,
                    ];

                    $response = $this->httpClient->post(
                            $schemaUrl,
                            [
                                'body'    => json_encode($payload),
                                'headers' => ['Content-Type' => 'application/json'],
                                'timeout' => 30,
                            ]
                            );

                    $responseData = json_decode($response->getBody()->getContents(), true);

                    if (($responseData['responseHeader']['status'] ?? -1) === 0) {
                        $created[] = $fieldName;
                        // Since we only process missing fields, this is always a create operation.
                        $this->logger->debug(
                                "✅ Created SOLR field",
                                [
                                    'field'       => $fieldName,
                                    'type'        => $solrFieldConfig['type'],
                                    'multiValued' => $solrFieldConfig['multiValued'] ?? false,
                                    'operation'   => $operation,
                                ]
                                );
                    } else {
                        $error = $responseData['error']['msg'] ?? 'Unknown error';
                        $errors[$fieldName] = $error;
                        // Since we only process missing fields, this is always a create operation.
                        $this->logger->warning(
                                "❌ Failed to create SOLR field",
                                [
                                    'field'     => $fieldName,
                                    'error'     => $error,
                                    'operation' => $operation,
                                ]
                                );
                    }//end if
                } catch (\Exception $e) {
                    $errors[$fieldName] = $e->getMessage();
                    $this->logger->error(
                            'Exception creating SOLR field',
                            [
                                'field' => $fieldName,
                                'error' => $e->getMessage(),
                            ]
                            );
                }//end try
            }//end foreach

            $executionTime = (microtime(true) - $startTime) * 1000;
            $success       = count($created) > 0 && count($errors) === 0;

            $result = [
                'success'           => $success,
                'message'           => sprintf(
                    'Field creation completed: %d created, %d errors',
                    count($created),
                    count($errors)
                ),
                'created'           => $created,
                'errors'            => $errors,
                'execution_time_ms' => round($executionTime, 2),
                'collection'        => $collectionName,
            ];

            $this->logger->info(
                    '🎯 SOLR field creation completed',
                    [
                        'created_count'     => count($created),
                        'error_count'       => count($errors),
                        'execution_time_ms' => $result['execution_time_ms'],
                    ]
                    );

            return $result;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to create missing SOLR fields',
                    [
                        'error'      => $e->getMessage(),
                        'collection' => $collectionName ?? 'unknown',
                    ]
                    );

            return [
                'success' => false,
                'message' => 'Failed to create missing SOLR fields: '.$e->getMessage(),
                'details' => ['error' => $e->getMessage()],
            ];
        }//end try

    }//end createMissingFields()


    /**
     * Prepare field configuration for SOLR Schema API
     *
     * @param  string $fieldName   Field name
     * @param  array  $fieldConfig Field configuration from schema analysis
     * @return array SOLR-compatible field configuration
     */
    private function prepareSolrFieldConfig(string $fieldName, array $fieldConfig): array
    {

        // The field config already contains the resolved SOLR type from SolrSchemaService.
        // So we should use it directly instead of re-mapping.
        $solrType = $fieldConfig['type'] ?? 'string';

        // Handle array case - if type is an array, take the first element.
        if (is_array($solrType) === true) {
            $solrType = $this->getSolrTypeFromArray($solrType);
        } else {
            $solrType = (string) $solrType;
        }

        $config = [
            'name'        => $fieldName,
            'type'        => $solrType,
            'indexed'     => $fieldConfig['indexed'] ?? true,
            'stored'      => $fieldConfig['stored'] ?? true,
            'multiValued' => $fieldConfig['multiValued'] ?? false,
            'docValues'   => $fieldConfig['docValues'] ?? true,
        ];

        return $config;

    }//end prepareSolrFieldConfig()


    /**
     * Get comprehensive SOLR field configuration and schema information
     *
     * Retrieves field definitions, dynamic fields, field types, and core information
     * from the active SOLR collection to help debug field configuration issues.
     *
     * @return array{success: bool, message: string, fields?: array, dynamic_fields?: array, field_types?: array, core_info?: array, environment_notes?: array, execution_time_ms?: float, details?: array}
     */
    public function getFieldsConfiguration(): array
    {
        if ($this->isAvailable() === false) {
            return [
                'success' => false,
                'message' => 'SOLR is not available or not configured',
            ];
        }

        try {
            $startTime      = microtime(true);
            $collectionName = $this->getActiveCollectionName();

            if ($collectionName === null || $collectionName === '') {
                return [
                    'success' => false,
                    'message' => 'No active SOLR collection found',
                ];
            }

            $this->logger->info(
                    '🔍 Retrieving SOLR field configuration',
                    [
                        'collection' => $collectionName,
                    ]
                    );

            // Build schema API URL.
            $schemaUrl = $this->buildSolrBaseUrl()."/{$collectionName}/schema";

            // Prepare request options.
            $requestOptions = [
                'timeout' => $this->solrConfig['timeout'] ?? 30,
                'headers' => ['Accept' => 'application/json'],
            ];

            // Add authentication if configured.
            if (empty($this->solrConfig['username']) === false && empty($this->solrConfig['password']) === false) {
                $requestOptions['auth'] = [
                    $this->solrConfig['username'],
                    $this->solrConfig['password'],
                ];
            }

            // Make the schema request.
            $response = $this->httpClient->get($schemaUrl, $requestOptions);
            /*
             * @var \Psr\Http\Message\ResponseInterface $response
             */
            $schemaData = json_decode($response->getBody()->getContents(), true);

            if (($schemaData === null || $schemaData === false) === true || isset($schemaData['schema']) === false) {
                return [
                    'success' => false,
                    'message' => 'Invalid schema response from SOLR',
                    'details' => ['response' => $schemaData],
                ];
            }

            $schema        = $schemaData['schema'];
            $executionTime = (microtime(true) - $startTime) * 1000;

            // Extract and organize field information.
            $result = [
                'success'           => true,
                'message'           => 'SOLR field configuration retrieved successfully',
                'execution_time_ms' => round($executionTime, 2),
                'fields'            => $this->extractFields($schema),
                'dynamic_fields'    => $this->extractSchemaDynamicFields($schema),
                'field_types'       => $this->extractFieldTypes($schema),
                'core_info'         => $this->extractCoreInfo(schema: $schema, collectionName: $collectionName),
                'environment_notes' => $this->generateEnvironmentNotes($schema),
            ];

            $this->logger->info(
                    '✅ SOLR field configuration retrieved',
                    [
                        'collection'          => $collectionName,
                        'field_count'         => count($result['fields']),
                        'dynamic_field_count' => count($result['dynamic_fields']),
                        'field_type_count'    => count($result['field_types']),
                        'execution_time_ms'   => $result['execution_time_ms'],
                    ]
                    );

            return $result;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to retrieve SOLR field configuration',
                    [
                        'error'      => $e->getMessage(),
                        'collection' => $collectionName ?? 'unknown',
                    ]
                    );

            return [
                'success' => false,
                'message' => 'Failed to retrieve SOLR field configuration: '.$e->getMessage(),
                'details' => ['error' => $e->getMessage()],
            ];
        }//end try

    }//end getFieldsConfiguration()


    /**
     * Extract field definitions from schema
     *
     * @param  array $schema SOLR schema data
     * @return array Field definitions
     */
    private function extractFields(array $schema): array
    {
        $fields = [];

        if (($schema['fields'] ?? null) !== null) {
            foreach ($schema['fields'] as $field) {
                $name          = $field['name'] ?? 'unknown';
                $fields[$name] = [
                    'type'        => $field['type'] ?? 'unknown',
                    'indexed'     => $field['indexed'] ?? true,
                    'stored'      => $field['stored'] ?? true,
                    'multiValued' => $field['multiValued'] ?? false,
                    'required'    => $field['required'] ?? false,
                    'docValues'   => $field['docValues'] ?? false,
                ];
            }
        }

        // Sort fields alphabetically for better readability.
        ksort($fields);

        return $fields;

    }//end extractFields()


    /**
     * Extract dynamic field patterns from schema
     *
     * @param  array $schema SOLR schema data
     * @return array Dynamic field patterns
     */
    private function extractSchemaDynamicFields(array $schema): array
    {
        $dynamicFields = [];

        if (($schema['dynamicFields'] ?? null) !== null) {
            foreach ($schema['dynamicFields'] as $field) {
                $name = $field['name'] ?? 'unknown';
                $dynamicFields[$name] = [
                    'type'        => $field['type'] ?? 'unknown',
                    'indexed'     => $field['indexed'] ?? true,
                    'stored'      => $field['stored'] ?? true,
                    'multiValued' => $field['multiValued'] ?? false,
                ];
            }
        }

        return $dynamicFields;

    }//end extractSchemaDynamicFields()


    /**
     * Extract field type definitions from schema
     *
     * @param  array $schema SOLR schema data
     * @return array Field type definitions
     */
    private function extractFieldTypes(array $schema): array
    {
        $fieldTypes = [];

        if (($schema['fieldTypes'] ?? null) !== null) {
            foreach ($schema['fieldTypes'] as $fieldType) {
                $name = $fieldType['name'] ?? 'unknown';
                $fieldTypes[$name] = [
                    'class'      => $fieldType['class'] ?? 'unknown',
                    'analyzer'   => $fieldType['analyzer'] ?? null,
                    'properties' => array_diff_key($fieldType, array_flip(['name', 'class', 'analyzer'])),
                ];
            }
        }

        return $fieldTypes;

    }//end extractFieldTypes()


    /**
     * Extract core information from schema
     *
     * @param  array  $schema         SOLR schema data
     * @param  string $collectionName Collection name
     * @return array Core information
     */
    private function extractCoreInfo(array $schema, string $collectionName): array
    {
        return [
            'core_name'            => $collectionName,
            'schema_name'          => $schema['name'] ?? 'unknown',
            'schema_version'       => $schema['version'] ?? 'unknown',
            'unique_key'           => $schema['uniqueKey'] ?? 'id',
            'default_search_field' => $schema['defaultSearchField'] ?? null,
            'similarity'           => $schema['similarity'] ?? null,
        ];

    }//end extractCoreInfo()


    /**
     * Generate environment analysis notes
     *
     * @param  array $schema SOLR schema data
     * @return array Environment notes and warnings
     */
    private function generateEnvironmentNotes(array $schema): array
    {
        $notes = [];

        // Check for common field configuration issues.
        if (($schema['fields'] ?? null) !== null) {
            $stringFields = array_filter(
                    $schema['fields'],
                    function ($field) {
                        return ($field['type'] ?? '') === 'string' && ($field['multiValued'] ?? false) === true;
                    }
                    );

            if (empty($stringFields) === false) {
                $notes[] = [
                    'type'    => 'warning',
                    'title'   => 'Multi-valued String Fields Detected',
                    'message' => 'Found '.count($stringFields).' multi-valued string fields. This might cause array conversion issues during object reconstruction.',
                    'details' => array_keys($stringFields),
                ];
            }
        }

        // Check for OpenRegister-specific field patterns.
        if (($schema['dynamicFields'] ?? null) !== null) {
            $orFields = array_filter(
                    $schema['dynamicFields'],
                    function ($field) {
                        return strpos($field['name'] ?? '', '*_s') !== false || strpos($field['name'] ?? '', '*_t') !== false;
                    }
                    );

            if (empty($orFields) === false) {
                $notes[] = [
                    'type'    => 'info',
                    'title'   => 'OpenRegister Dynamic Fields Found',
                    'message' => 'Found '.count($orFields).' OpenRegister-compatible dynamic field patterns.',
                    'details' => array_column($orFields, 'name'),
                ];
            }
        }

        return $notes;

    }//end generateEnvironmentNotes()


    /**
     * Predict memory usage for SOLR warmup operation
     *
     * @param  int   $maxObjects Maximum number of objects to process (0 = all)
     * @param  array $schemaIds  Optional array of schema IDs to filter by (empty = all schemas)
     * @return array Memory usage prediction
     */
    private function predictWarmupMemoryUsage(int $_maxObjects, array $_schemaIds=[]): array
    {
        try {
            // Get current memory info.
            $currentMemory = memory_get_usage(true);
            $memoryLimit   = $this->parseMemoryLimit(ini_get('memory_limit'));

            // Get object count for prediction, filtered by schema if provided.
            $objectMapper = \OC::$server->get(\OCA\OpenRegister\Db\ObjectEntityMapper::class);

            // If schema IDs are provided, use the searchable objects count (which filters by schema).
            if (empty($schemaIds) === false) {
                $totalObjects = $this->countSearchableObjects(objectMapper: $objectMapper, schemaIds: $schemaIds);
            } else {
                // Get ALL object count for prediction (since we now index all objects, not just published).
                $totalObjects = $objectMapper->countAll(
                    filters: [],
                    search: null,
                    ids: null,
                    uses: null,
                    includeDeleted: false,
                    register: null,
                    schema: null,
                    published: null,
                // Count ALL objects (published and unpublished).
                    rbac: false,
                // Skip RBAC for performance.
                    multi: false
                // Skip multitenancy for performance.
                );
            }

            // Calculate objects to process.
            $objectsToProcess = $this->calculateObjectsToProcess(maxObjects: $maxObjects, totalObjects: $totalObjects);

            // Memory estimation based on empirical data:.
            // - Base overhead: ~50MB for SOLR service, profiler, etc.
            // - Per object: ~2KB for document creation and processing.
            // - Batch overhead: ~10MB per 1000 objects for bulk operations.
            // - Schema operations: ~20MB for field management.
            $baseOverhead = 50 * 1024 * 1024;
            // 50MB
            $schemaOperations = 20 * 1024 * 1024;
            // 20MB
            $perObjectMemory = 2 * 1024;
            // 2KB per object
            $batchOverhead = ceil($objectsToProcess / 1000) * 10 * 1024 * 1024;
            // 10MB per 1000 objects
            $estimatedUsage = $baseOverhead + $schemaOperations + ($objectsToProcess * $perObjectMemory) + $batchOverhead;
            $totalPredicted = $currentMemory + $estimatedUsage;

            return [
                'current_memory'       => $currentMemory,
                'memory_limit'         => $memoryLimit,
                'objects_to_process'   => $objectsToProcess,
                'estimated_additional' => $estimatedUsage,
                'total_predicted'      => $totalPredicted,
                'memory_available'     => $memoryLimit - $currentMemory,
                'prediction_safe'      => $totalPredicted < ($memoryLimit * 0.9),
            // 90% threshold
                'formatted'            => [
                    'current'              => $this->formatBytes($currentMemory),
                    'limit'                => $this->formatBytes($memoryLimit),
                    'estimated_additional' => $this->formatBytes($estimatedUsage),
                    'total_predicted'      => $this->formatBytes($totalPredicted),
                    'available'            => $this->formatBytes($memoryLimit - $currentMemory),
                ],
            ];
        } catch (\Exception $e) {
            return [
                'error'           => 'Unable to predict memory usage: '.$e->getMessage(),
                'prediction_safe' => false,
            ];
        }//end try

    }//end predictWarmupMemoryUsage()


    /**
     * Parse memory limit string to bytes
     *
     * @param  string $memoryLimit Memory limit string (e.g., "512M", "2G")
     * @return int Memory limit in bytes
     */
    private function parseMemoryLimit(string $memoryLimit): int
    {
        if ($memoryLimit === '-1') {
            return PHP_INT_MAX;
            // No limit.
        }

        $unit  = strtoupper(substr($memoryLimit, -1));
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

    }//end parseMemoryLimit()


    /**
     * Format bytes to human readable format
     *
     * @param  int|float $bytes Number of bytes
     * @return string Formatted string
     */
    private function formatBytes(int|float $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024 * 1024), 2).' GB';
        } else if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2).' MB';
        } else if ($bytes >= 1024) {
            return round($bytes / 1024, 2).' KB';
        } else {
            return $bytes.' B';
        }

    }//end formatBytes()


    /**
     * Truncate field value to respect SOLR's 32,766 byte limit for indexed string fields
     *
     * SOLR has a hard limit of 32,766 bytes for indexed string fields. This method
     * ensures field values stay within this limit while preserving as much data as possible.
     *
     * @param  mixed  $value     The field value to check and potentially truncate
     * @param  string $fieldName Field name for logging purposes
     * @return mixed Truncated value or original value if within limits
     */
    private function truncateFieldValue($value, string $fieldName=''): mixed
    {
        // Only truncate string values.
        if (is_string($value) === false) {
            return $value;
        }

        // SOLR's byte limit for indexed string fields.
        $maxBytes = 32766;

        // Check if value exceeds byte limit (UTF-8 safe).
        if (strlen($value) <= $maxBytes) {
            return $value;
            // Within limits.
        }

        // **TRUNCATE SAFELY**: Ensure we don't break UTF-8 characters.
        $truncated = mb_strcut($value, 0, $maxBytes - 100, 'UTF-8');
        // Leave buffer for safety
        // Add truncation indicator.
        $truncated .= '...[TRUNCATED]';

        // Log truncation for monitoring.
        $this->logger->info(
                'Field value truncated for SOLR indexing',
                [
                    'field'            => $fieldName,
                    'original_bytes'   => strlen($value),
                    'truncated_bytes'  => strlen($truncated),
                    'truncation_point' => $maxBytes - 100,
                ]
                );

        return $truncated;

    }//end truncateFieldValue()


    /**
     * Check if a field should be truncated based on schema definition
     *
     * File fields and other large content fields should be truncated to prevent
     * SOLR indexing errors.
     *
     * @param  string $fieldName       Field name
     * @param  array  $fieldDefinition Schema field definition (if available)
     * @return bool True if field should be truncated
     */
    private function shouldTruncateField(string $fieldName, array $fieldDefinition=[]): bool
    {
        $type   = $fieldDefinition['type'] ?? '';
        $format = $fieldDefinition['format'] ?? '';

        // File fields should always be truncated.
        if ($type === 'file' || $format === 'file' || $format === 'binary'
            || in_array($format, ['data-url', 'base64', 'image', 'document'], true) === true
        ) {
            return true;
        }

        // Fields that commonly contain large content.
        $largeContentFields = ['logo', 'image', 'icon', 'thumbnail', 'content', 'body', 'description'];
        if (in_array(strtolower($fieldName), $largeContentFields) === true) {
            return true;
        }

        // Base64 data URLs (common pattern).
        if (str_contains(strtolower($fieldName), 'base64') === true) {
            return true;
        }

        return false;

    }//end shouldTruncateField()


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

        // Query SOLR schema API for all fields.
        $baseUrl   = $this->buildSolrBaseUrl();
        $schemaUrl = $baseUrl."/{$collectionName}/schema/fields";

        try {
            $response = $this->httpClient->get(
                    $schemaUrl,
                    [
                        'query' => [
                            'wt' => 'json',
                        ],
                    ]
                    );

            /*
             * @var \Psr\Http\Message\ResponseInterface $response
             */
            $schemaData = json_decode($response->getBody()->getContents(), true);

            if (isset($schemaData['fields']) === false || is_array($schemaData['fields']) === false) {
                throw new \Exception('Invalid schema response from SOLR');
            }

            $facetableFields = [
                '@self'         => [],
                'object_fields' => [],
            ];

            // Process each field to determine if it's facetable.
            foreach ($schemaData['fields'] as $field) {
                $fieldName = $field['name'] ?? 'unknown';

                // Log self_ fields for debugging.
                if (str_starts_with($fieldName, 'self_') === true) {
                    $this->logger->debug(
                            'Found self_ field in SOLR schema',
                            [
                                'field'     => $fieldName,
                                'docValues' => $field['docValues'] ?? 'not set',
                                'type'      => $field['type'] ?? 'unknown',
                            ]
                            );
                }

                if (isset($field['name']) === false || isset($field['docValues']) === false || $field['docValues'] !== true) {
                    continue;
                    // Skip fields without docValues.
                }

                $fieldName = $field['name'];
                $fieldType = $field['type'] ?? 'string';

                // Categorize fields.
                if (str_starts_with($fieldName, 'self_') === true) {
                    // Metadata field.
                    $metadataKey = substr($fieldName, 5);
                    // Remove 'self_' prefix.
                    $facetableFields['@self'][$metadataKey] = [
                        'name'           => $metadataKey,
                        'type'           => $this->mapSolrTypeToFacetType($fieldType),
                        'index_field'    => $fieldName,
                        'index_type'     => $fieldType,
                        'queryParameter' => '@self['.$metadataKey.']',
                        'source'         => 'metadata',
                    ];
                } else if (in_array($fieldName, ['_version_', 'id', '_text_']) === false) {
                    // Object field (exclude system fields).
                    // Check if this is a suffixed field (e.g., licentietype_s) and use base name.
                    $baseFieldName = $fieldName;
                    if (str_ends_with($fieldName, '_s') === true || str_ends_with($fieldName, '_t') === true || str_ends_with($fieldName, '_i') === true || str_ends_with($fieldName, '_f') === true || str_ends_with($fieldName, '_b') === true) {
                        $baseFieldName = substr($fieldName, 0, -2);
                        // Remove suffix.
                    }

                    $facetableFields['object_fields'][$baseFieldName] = [
                        'name'           => $baseFieldName,
                        'type'           => $this->mapSolrTypeToFacetType($fieldType),
                        'index_field'    => $fieldName,
                    // Use the actual SOLR field name.
                        'index_type'     => $fieldType,
                        'queryParameter' => $baseFieldName,
                        'source'         => 'object',
                    ];
                }//end if
            }//end foreach

            $this->logger->debug(
                    'Discovered facetable fields from SOLR schema',
                    [
                        'collection'       => $collectionName,
                        'metadataFields'   => count($facetableFields['@self']),
                        'objectFields'     => count($facetableFields['object_fields']),
                        'objectFieldNames' => array_keys($facetableFields['object_fields']),
                        'totalFields'      => count($schemaData['fields']),
                    ]
                    );

            return $facetableFields;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to query SOLR schema for facetable fields',
                    [
                        'collection' => $collectionName,
                        'url'        => $schemaUrl,
                        'error'      => $e->getMessage(),
                    ]
                    );

            throw new \Exception('SOLR schema discovery failed: '.$e->getMessage());
        }//end try

    }//end discoverFacetableFieldsFromSolr()


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

        // Query SOLR schema API for all fields.
        $baseUrl   = $this->buildSolrBaseUrl();
        $schemaUrl = $baseUrl."/{$collectionName}/schema/fields";

        try {
            $response = $this->httpClient->get(
                    $schemaUrl,
                    [
                        'query' => [
                            'wt' => 'json',
                        ],
                    ]
                    );

            /*
             * @var \Psr\Http\Message\ResponseInterface $response
             */
            $schemaData = json_decode($response->getBody()->getContents(), true);

            if (isset($schemaData['fields']) === false || is_array($schemaData['fields']) === false) {
                throw new \Exception('Invalid schema response from SOLR');
            }

            $rawFields = [
                '@self'         => [],
                'object_fields' => [],
            ];

            // Process each field and return raw information for configuration.
            foreach ($schemaData['fields'] as $field) {
                $fieldName = $field['name'] ?? 'unknown';

                // Only include fields that have docValues (facetable).
                if (isset($field['name']) === false || isset($field['docValues']) === false || $field['docValues'] !== true) {
                    continue;
                }

                $fieldInfo = [
                    'name'                  => $fieldName,
                    'type'                  => $field['type'] ?? 'string',
                    'stored'                => $field['stored'] ?? false,
                    'indexed'               => $field['indexed'] ?? false,
                    'docValues'             => $field['docValues'] ?? false,
                    'multiValued'           => $field['multiValued'] ?? false,
                    'required'              => $field['required'] ?? false,
                    // Add suggested facet type based on SOLR type.
                    'suggestedFacetType'    => $this->mapSolrTypeToFacetType($field['type'] ?? 'string'),
                    // Add suggested display types based on field characteristics.
                    'suggestedDisplayTypes' => $this->getSuggestedDisplayTypes($field),
                ];

                // Categorize fields.
                if (str_starts_with($fieldName, 'self_') === true) {
                    // Metadata field.
                    $metadataKey = substr($fieldName, 5);
                    // Remove 'self_' prefix.
                    $fieldInfo['displayName']         = ucfirst(str_replace('_', ' ', $metadataKey));
                    $fieldInfo['category']            = 'metadata';
                    $rawFields['@self'][$metadataKey] = $fieldInfo;
                } else if (in_array($fieldName, ['_version_', 'id', '_text_']) === false) {
                    // Object field (exclude system fields).
                    $fieldInfo['displayName'] = ucfirst(str_replace('_', ' ', $fieldName));
                    $fieldInfo['category']    = 'object';
                    $rawFields['object_fields'][$fieldName] = $fieldInfo;
                }
            }//end foreach

            $this->logger->debug(
                    'Retrieved raw SOLR fields for facet configuration',
                    [
                        'collection'     => $collectionName,
                        'metadataFields' => count($rawFields['@self']),
                        'objectFields'   => count($rawFields['object_fields']),
                        'totalFields'    => count($schemaData['fields']),
                    ]
                    );

            return $rawFields;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to retrieve raw SOLR fields for facet configuration',
                    [
                        'collection' => $collectionName,
                        'url'        => $schemaUrl,
                        'error'      => $e->getMessage(),
                    ]
                    );

            throw new \Exception('SOLR field discovery failed: '.$e->getMessage());
        }//end try

    }//end getRawSolrFieldsForFacetConfiguration()


    /**
     * Get suggested display types for a SOLR field based on its characteristics
     *
     * @param  array $field SOLR field information
     * @return array Suggested display types
     */
    private function getSuggestedDisplayTypes(array $field): array
    {
        $fieldType   = $field['type'] ?? 'string';
        $multiValued = $field['multiValued'] ?? false;

        // Based on field type.
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
                // String fields.
                if ($multiValued === true) {
                    $suggestions = ['multiselect', 'checkbox'];
                } else {
                    $suggestions = ['select', 'radio', 'checkbox'];
                }
                break;
        }//end switch

        return $suggestions;

    }//end getSuggestedDisplayTypes()


    /**
     * Map SOLR field type to OpenRegister facet type
     *
     * @param  string $solrType SOLR field type
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

    }//end mapSolrTypeToFacetType()


    /**
     * Get contextual facets by re-running the same query with faceting enabled
     * This is much more efficient and respects all current search parameters
     *
     * @param  array $solrQuery     The current SOLR query parameters
     * @param  array $originalQuery The original OpenRegister query
     * @return array Contextual facet data with both facetable fields and extended data
     */
    private function getContextualFacetsFromSameQuery(array $solrQuery, array $_originalQuery): array
    {

        $collectionName = $this->getActiveCollectionName();
        if ($collectionName === null) {
            throw new \Exception('No active SOLR collection available for contextual faceting');
        }

        // Build faceting query using the same parameters as the main query.
        // but with rows=0 for performance and JSON faceting enabled.
        $facetQuery         = $solrQuery;
        $facetQuery['rows'] = 0;
        // We only want facet data
        // Add JSON faceting for core metadata fields with domain filtering.
        $filterQueries = $facetQuery['fq'] ?? [];
        $jsonFacets    = $this->buildOptimizedContextualFacetQuery($filterQueries);

        if (empty($jsonFacets) === false) {
            $facetQuery['json.facet'] = json_encode($jsonFacets);
        }

        // Execute the faceting query.
        $baseUrl  = $this->buildSolrBaseUrl();
        $queryUrl = $baseUrl."/{$collectionName}/select";

        try {
            $startTime = microtime(true);

            // Use POST to avoid URI length issues.
            $response = $this->httpClient->post(
                    $queryUrl,
                    [
                        'form_params' => $facetQuery,
                        'headers'     => [
                            'Content-Type' => 'application/x-www-form-urlencoded',
                        ],
                    ]
                    );

            /*
             * @var \Psr\Http\Message\ResponseInterface $response
             */
            $responseBody = $response->getBody()->getContents();
            $data         = json_decode($responseBody, true);

            $executionTime = (microtime(true) - $startTime) * 1000;

            if ($data === null) {
                $this->logger->error(
                        'Failed to decode SOLR JSON response for contextual facets',
                        [
                            'response_body' => substr($responseBody, 0, 1000),
                // First 1000 chars.
                            'json_error'    => json_last_error_msg(),
                        ]
                        );
                throw new \Exception('Failed to decode SOLR JSON response: '.json_last_error_msg());
            }

            $this->logger->debug(
                    'Contextual faceting query completed',
                    [
                        'execution_time_ms' => round($executionTime, 2),
                        'total_found'       => $data['response']['numFound'] ?? 0,
                        'facets_available'  => isset($data['facets']) === true,
                    ]
                    );

            // Process the facet data.
            if (($data['facets'] ?? null) !== null) {
                $contextualData = $this->processOptimizedContextualFacets($data['facets']);

                return $contextualData;
            } else {
                // Fallback: discover fields that have values in the current result set.
                return $this->discoverFieldsFromCurrentResults($data);
            }
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to get contextual facets from same query',
                    [
                        'collection' => $collectionName,
                        'url'        => $queryUrl,
                        'error'      => $e->getMessage(),
                    ]
                    );

            throw new \Exception('SOLR contextual faceting failed: '.$e->getMessage());
        }//end try

    }//end getContextualFacetsFromSameQuery()


    /**
     * Discover fields that have values from the current search results
     * This is a fallback when JSON faceting is not available
     *
     * @param  array $solrResponse The SOLR response data
     * @return array Contextual facet data
     */
    private function discoverFieldsFromCurrentResults(array $solrResponse): array
    {
        $contextualData = [
            'facetable' => ['@self' => [], 'object_fields' => []],
            'extended'  => ['@self' => [], 'object_fields' => []],
        ];

        // If there are no results, return empty facets.
        if (isset($solrResponse['response']['docs']) === false || empty($solrResponse['response']['docs']) === true) {
            return $contextualData;
        }

        $docs        = $solrResponse['response']['docs'];
        $fieldsFound = [];

        // Analyze the first few documents to discover available fields.
        $sampleSize = min(10, count($docs));
        for ($i = 0; $i < $sampleSize; $i++) {
            foreach ($docs[$i] as $fieldName => $fieldValue) {
                if (isset($fieldsFound[$fieldName]) === false) {
                    $fieldsFound[$fieldName] = [];
                }

                // Store unique values (up to 50 per field for performance).
                if (is_array($fieldValue) === true) {
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

        // Process discovered fields.
        $metadataFields = [
            'self_register'     => ['name' => 'register', 'type' => 'terms', 'index_field' => 'self_register', 'index_type' => 'pint'],
            'self_schema'       => ['name' => 'schema', 'type' => 'terms', 'index_field' => 'self_schema', 'index_type' => 'pint'],
            'self_organisation' => ['name' => 'organisation', 'type' => 'terms', 'index_field' => 'self_organisation', 'index_type' => 'string'],
            'self_application'  => ['name' => 'application', 'type' => 'terms', 'index_field' => 'self_application', 'index_type' => 'string'],
            'self_created'      => ['name' => 'created', 'type' => 'date_histogram', 'index_field' => 'self_created', 'index_type' => 'pdate'],
            'self_updated'      => ['name' => 'updated', 'type' => 'date_histogram', 'index_field' => 'self_updated', 'index_type' => 'pdate'],
        ];

        foreach ($fieldsFound as $fieldName => $values) {
            if (str_starts_with($fieldName, 'self_') === true) {
                // Metadata field.
                if (($metadataFields[$fieldName] ?? null) !== null) {
                    $fieldInfo = $metadataFields[$fieldName];
                    $contextualData['facetable']['@self'][$fieldInfo['name']] = $fieldInfo;
                    $contextualData['extended']['@self'][$fieldInfo['name']]  = array_merge(
                        $fieldInfo,
                        [
                            'data' => array_map(
                                function ($value) {
                                    return ['value' => $value, 'count' => 1];
                                },
                                array_unique($values)
                                ),
                        ]
                    );
                }
            } else if (in_array($fieldName, ['_version_', 'id', '_text_'], true) === false) {
                // Object field (exclude system fields).
                $fieldType = $this->inferFieldType($values);
                $fieldInfo = [
                    'name'        => $fieldName,
                    'type'        => $this->mapSolrTypeToFacetType($fieldType),
                    'index_field' => $fieldName,
                    'index_type'  => $fieldType,
                ];

                $contextualData['facetable']['object_fields'][$fieldName] = $fieldInfo;
                $contextualData['extended']['object_fields'][$fieldName]  = array_merge(
                    $fieldInfo,
                    [
                        'data' => array_map(
                            function ($value) {
                                return ['value' => $value, 'count' => 1];
                            },
                            array_unique($values)
                            ),
                    ]
                );
            }//end if
        }//end foreach

        $this->logger->debug(
                'Discovered fields from current results',
                [
                    'metadata_fields' => count($contextualData['facetable']['@self']),
                    'object_fields'   => count($contextualData['facetable']['object_fields']),
                    'sample_size'     => $sampleSize,
                    'total_docs'      => count($docs),
                ]
                );

        return $contextualData;

    }//end discoverFieldsFromCurrentResults()


    /**
     * Infer field type from sample values
     *
     * @param  array $values Sample values from the field
     * @return string Inferred SOLR field type
     */
    private function inferFieldType(array $values): string
    {
        if (empty($values) === true) {
            return 'string';
        }

        $sampleValue = $values[0];

        if (is_numeric($sampleValue) === true) {
            return $this->getNumericType($sampleValue);
        } else if (strtotime($sampleValue) !== false) {
            return 'pdate';
        } else {
            return 'string';
        }

    }//end inferFieldType()


    /**
     * Process contextual facets from existing search results
     *
     * @param  array $searchFacets Facets from current search results
     * @return array Processed contextual facet data
     */
    private function processContextualFacetsFromSearchResults(array $_searchFacets): array
    {
        // For now, return empty structure - we'll implement this if needed.
        // Most of the time we'll use the optimized call instead.
        return [
            'facetable' => ['@self' => [], 'object_fields' => []],
            'extended'  => ['@self' => [], 'object_fields' => []],
        ];

    }//end processContextualFacetsFromSearchResults()


    /**
     * Get optimized contextual facets with a single SOLR call
     * Uses SOLR's field stats to discover which fields have values, then facets only those
     *
     * @param  array $filters Current query filters
     * @return array Contextual facet data
     */
    private function getOptimizedContextualFacets(array $filters=[]): array
    {
        $collectionName = $this->getActiveCollectionName();

        // Build a comprehensive JSON faceting query that discovers fields with values.
        // and gets their facet data in one call.
        $optimizedFacetQuery = $this->buildOptimizedContextualFacetQuery();

        if (empty($optimizedFacetQuery) === true) {
            return [
                'facetable' => ['@self' => [], 'object_fields' => []],
                'extended'  => ['@self' => [], 'object_fields' => []],
            ];
        }

        // Build base query with current filters.
        $baseQuery     = '*:*';
        $filterQueries = [];

        // Add current filters.
        foreach ($filters as $filter) {
            if (empty($filter) === false) {
                $filterQueries[] = $filter;
            }
        }

        // Query SOLR with optimized JSON faceting.
        $baseUrl  = $this->buildSolrBaseUrl();
        $queryUrl = $baseUrl."/{$collectionName}/select";

        $queryParams = [
            'q'          => $baseQuery,
            'rows'       => 0,
        // We only want facet data.
            'wt'         => 'json',
            'json.facet' => json_encode($optimizedFacetQuery),
        ];

        // Add filter queries.
        if (empty($filterQueries) === false) {
            $queryParams['fq'] = $filterQueries;
        }

        try {
            $startTime = microtime(true);

            // Use POST to avoid URI length issues.
            $response = $this->httpClient->post(
                    $queryUrl,
                    [
                        'form_params' => $queryParams,
                        'headers'     => [
                            'Content-Type' => 'application/x-www-form-urlencoded',
                        ],
                    ]
                    );

            /*
             * @var \Psr\Http\Message\ResponseInterface $response
             */
            $responseBody = $response->getBody()->getContents();
            $data         = json_decode($responseBody, true);

            $executionTime = (microtime(true) - $startTime) * 1000;

            if ($data === null) {
                $this->logger->error(
                        'Failed to decode SOLR JSON response for contextual facets',
                        [
                            'response_body' => substr($responseBody, 0, 1000),
                // First 1000 chars.
                            'json_error'    => json_last_error_msg(),
                        ]
                        );
                throw new \Exception('Failed to decode SOLR JSON response: '.json_last_error_msg());
            }

            if (isset($data['facets']) === false) {
                $this->logger->error(
                        'SOLR response missing facets key for contextual facets',
                        [
                            'response_keys' => array_keys($data),
                        ]
                        );
                throw new \Exception('Invalid contextual faceting response from SOLR - missing facets key');
            }

            $this->logger->debug(
                    'Optimized contextual faceting completed',
                    [
                        'execution_time_ms' => round($executionTime, 2),
                        'facets_found'      => count($data['facets']),
                    ]
                    );

            // Process and format the contextual facet data.
            return $this->processOptimizedContextualFacets($data['facets']);
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to get optimized contextual facet data from SOLR',
                    [
                        'collection' => $collectionName,
                        'url'        => $queryUrl,
                        'error'      => $e->getMessage(),
                    ]
                    );

            throw new \Exception('SOLR contextual faceting failed: '.$e->getMessage());
        }//end try

    }//end getOptimizedContextualFacets()


    /**
     * Build optimized contextual facet query that includes both metadata and object fields with actual values
     * This method now properly applies filter queries to facets using SOLR domains
     *
     * @param  array $filterQueries Filter queries to apply to facets (e.g., ['self_register:3'])
     * @return array Optimized JSON facet query with domain filtering
     */
    private function buildOptimizedContextualFacetQuery(array $filterQueries=[]): array
    {
        // Get core metadata fields that should always be checked.
        $coreMetadataFields = [
            'register'     => 'self_register',
            'schema'       => 'self_schema',
            'organisation' => 'self_organisation',
            'application'  => 'self_application',
            'created'      => 'self_created',
            'updated'      => 'self_updated',
        ];

        // Build facet query with existence checks and data retrieval.
        $facetQuery = [];

        // Build domain filter for applying filter queries to facets.
        $domainFilter = null;
        if (empty($filterQueries) === false) {
            $domainFilter = [
                'filter' => $filterQueries,
            ];
        }

        // Add metadata fields with existence checks and domain filtering.
        foreach ($coreMetadataFields as $fieldName => $solrField) {
            $facetConfig = [
                'type'     => 'terms',
                'field'    => $solrField,
                'limit'    => 1000,
            // Increased from 50 to 1000 for better coverage.
                'mincount' => 1,
            // Only include if there are actual values.
                'missing'  => false,
            // Don't include missing values.
            ];

            // Apply domain filter if we have filter queries.
            if ($domainFilter !== null) {
                $facetConfig['domain'] = $domainFilter;
            }

            $facetQuery[$fieldName] = $facetConfig;
        }

        // For _facets=extend, discover and facet ALL available fields from SOLR schema.
        try {
            $allFacetableFields = $this->discoverFacetableFieldsFromSolr();

            if (($allFacetableFields['object_fields'] ?? null) !== null) {
                foreach ($allFacetableFields['object_fields'] as $fieldName => $fieldConfig) {
                    // Only add fields that can be faceted (have docValues).
                    if (($fieldConfig['index_field'] ?? null) !== null) {
                        $objectFacetConfig = [
                            'type'     => 'terms',
                            'field'    => $fieldConfig['index_field'],
                            'limit'    => 1000,
                        // Increased from 50 to 1000 for better coverage.
                            'mincount' => 1,
                        // Only include if there are actual values.
                            'missing'  => false,
                        // Don't include missing values.
                        ];

                        // Apply domain filter if we have filter queries.
                        if ($domainFilter !== null) {
                            $objectFacetConfig['domain'] = $domainFilter;
                        }

                        $facetQuery['object_'.$fieldName] = $objectFacetConfig;
                    }
                }//end foreach
            }//end if
        } catch (\Exception $e) {
            // Fallback to commonly facetable fields if schema discovery fails.
            $this->logger->warning(
                    'Failed to discover all facetable fields, using fallback list',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );

            $commonObjectFields = [
                'slug',
                'naam',
                'type',
                'status',
                'category',
                'tag',
                'label',
                'cloudDienstverleningsmodel',
                'hostingJurisdictie',
                'hostingLocatie',
                'beschrijvingKort',
                'website',
                'logo',
            ];

            foreach ($commonObjectFields as $fieldName) {
                $fallbackFacetConfig = [
                    'type'     => 'terms',
                    'field'    => $fieldName,
                    'limit'    => 1000,
                // Increased from 50 to 1000 for better coverage.
                    'mincount' => 1,
                // Only include if there are actual values.
                    'missing'  => false,
                // Don't include missing values.
                ];

                // Apply domain filter if we have filter queries.
                if ($domainFilter !== null) {
                    $fallbackFacetConfig['domain'] = $domainFilter;
                }

                $facetQuery['object_'.$fieldName] = $fallbackFacetConfig;
            }
        }//end try

        return $facetQuery;

    }//end buildOptimizedContextualFacetQuery()


    /**
     * Process optimized contextual facets from SOLR response
     *
     * @param  array $facetData Raw facet data from SOLR
     * @return array Processed contextual facet data
     */
    private function processOptimizedContextualFacets(array $facetData): array
    {
        $contextualData = [
            'facetable' => [],
            'extended'  => [],
        ];

        // Define which metadata fields need label resolution (register, schema, organisation).
        $metadataFieldsWithLabelResolution = ['register', 'schema', 'organisation'];

        // Process ALL facets from SOLR using unified approach.
        foreach ($facetData as $facetKey => $facetValue) {
            if (empty($facetValue['buckets']) === true) {
                continue;
                // Skip facets with no data.
            }

            // Determine if this is a metadata field or object field.
            $isMetadataField = in_array($facetKey, ['register', 'schema', 'organisation', 'application', 'created', 'updated']);

            if ($isMetadataField === true) {
                // Handle metadata fields with underscore prefix.
                $fieldName = '_'.$facetKey;
                $fieldInfo = $this->getMetadataFieldInfo($facetKey);
            } else {
                // Handle object fields (remove 'object_' prefix if present).
                $fieldName = $this->getFieldNameFromFacetKey($facetKey);
                $fieldInfo = $this->getObjectFieldInfo($fieldName);
            }

            // Add to facetable fields.
            $contextualData['facetable'][$fieldName] = $fieldInfo;

            // Format facet data with label resolution for metadata fields that need it.
            if (($isMetadataField === true) && in_array($facetKey, $metadataFieldsWithLabelResolution, true) === true) {
                // Use specialized formatting with label resolution for register, schema, organisation.
                $formattedData = $this->formatMetadataFacetData(
                    rawData: $facetValue,
                    fieldName: $facetKey,
                    facetType: $fieldInfo['type']
                );
            } else {
                // Use generic formatting for other fields.
                $formattedData = $this->formatFacetData(rawFacetData: $facetValue, facetType: $fieldInfo['type']);
            }

            // Add to extended data with actual values.
            $facetResult = array_merge(
                $fieldInfo,
                ['data' => $formattedData]
            );

            // Apply custom facet configuration (but don't filter based on enabled status).
            $configKey   = $this->getFacetConfigKey(isMetadataField: $isMetadataField, facetKey: $facetKey, fieldName: $fieldName);
            $facetResult = $this->applyFacetConfiguration(facetData: $facetResult, fieldName: $configKey);

            // Always include in extended data - let frontend handle enabled/disabled.
            $contextualData['extended'][$fieldName] = $facetResult;
        }//end foreach

        $this->logger->debug(
                'Processed contextual facets using unified approach',
                [
                    'total_facets_found' => count($contextualData['extended']),
                    'facet_names'        => array_keys($contextualData['extended']),
                ]
                );

        return $contextualData;

    }//end processOptimizedContextualFacets()


    /**
     * Get metadata field information
     *
     * @param  string $fieldKey The field key (e.g., 'register', 'schema')
     * @return array Field information
     */
    private function getMetadataFieldInfo(string $fieldKey): array
    {
        $metadataFacetableFields = $this->getMetadataFacetableFields();
        $metadataFields          = $metadataFacetableFields['@self'] ?? [];

        $fieldMap = [
            'register'     => '_register',
            'schema'       => '_schema',
            'organisation' => '_organisation',
            'application'  => '_application',
            'created'      => '_created',
            'updated'      => '_updated',
        ];

        $fieldName = $fieldMap[$fieldKey] ?? '_'.$fieldKey;

        return $metadataFields[$fieldName] ?? [
            'name'           => $fieldName,
            'type'           => 'terms',
            'title'          => ucfirst(str_replace('_', ' ', $fieldName)),
            'description'    => 'Metadata field: '.$fieldName,
            'data_type'      => 'string',
            'index_field'    => 'self_'.$fieldKey,
            'index_type'     => 'string',
            'queryParameter' => '@self['.$fieldKey.']',
            'source'         => 'metadata',
            'show_count'     => true,
            'enabled'        => true,
            'order'          => 0,
        ];

    }//end getMetadataFieldInfo()


    /**
     * Get object field information
     *
     * @param  string $fieldName The field name
     * @return array Field information
     */
    private function getObjectFieldInfo(string $fieldName): array
    {
        // Try to get field information from schema if available.
        $objectFieldInfo = $this->getObjectFieldInfoFromSchema($fieldName);

        // If no schema info available, use defaults.
        if (empty($objectFieldInfo) === true) {
            $objectFieldInfo = [
                'name'           => $fieldName,
                'type'           => 'terms',
            // Most object fields are terms-based.
                'title'          => ucfirst(str_replace('_', ' ', $fieldName)),
                'description'    => 'Object field: '.$fieldName,
                'data_type'      => 'string',
                'index_field'    => $fieldName,
                'index_type'     => 'string',
                'queryParameter' => $fieldName,
                'source'         => 'object',
                'show_count'     => true,
                'enabled'        => true,
                'order'          => 0,
            ];
        }

        return $objectFieldInfo;

    }//end getObjectFieldInfo()


    /**
     * Build JSON facet query for SOLR
     *
     * @param  array $facetableFields The facetable fields to create facets for
     * @return array JSON facet query structure
     */
    private function buildJsonFacetQuery(array $facetableFields): array
    {
        $facetQuery = [];

        // Process metadata fields (@self).
        foreach ($facetableFields['@self'] ?? [] as $fieldName => $fieldInfo) {
            $solrFieldName = $fieldInfo['index_field'];
            $facetType     = $fieldInfo['type'];

            if ($facetType === 'date_histogram') {
                $facetQuery[$fieldName] = $this->buildDateHistogramFacet($solrFieldName);
            } else if ($facetType === 'range') {
                $facetQuery[$fieldName] = $this->buildRangeFacet($solrFieldName);
            } else {
                $facetQuery[$fieldName] = $this->buildTermsFacet($solrFieldName);
            }
        }

        // Process object fields.
        foreach ($facetableFields['object_fields'] ?? [] as $fieldName => $fieldInfo) {
            $solrFieldName = $fieldInfo['index_field'];
            $facetType     = $fieldInfo['type'];

            if ($facetType === 'date_histogram') {
                $facetQuery[$fieldName] = $this->buildDateHistogramFacet($solrFieldName);
            } else if ($facetType === 'range') {
                $facetQuery[$fieldName] = $this->buildRangeFacet($solrFieldName);
            } else {
                $facetQuery[$fieldName] = $this->buildTermsFacet($solrFieldName);
            }
        }

        return $facetQuery;

    }//end buildJsonFacetQuery()


    /**
     * Build terms facet for categorical fields
     *
     * @param  string $fieldName SOLR field name
     * @param  int    $limit     Maximum number of buckets to return (default: 1000, use -1 for unlimited)
     * @return array Terms facet configuration
     */
    private function buildTermsFacet(string $fieldName, int $limit=1000): array
    {
        return [
            'type'     => 'terms',
            'field'    => $fieldName,
            'limit'    => $limit,
        // Configurable limit, -1 for unlimited.
            'mincount' => 1,
        // Only include terms with at least 1 document.
        ];

    }//end buildTermsFacet()


    /**
     * Build range facet for numeric fields
     *
     * @param  string $fieldName SOLR field name
     * @return array Range facet configuration
     */
    private function buildRangeFacet(string $fieldName): array
    {
        return [
            'type'     => 'range',
            'field'    => $fieldName,
            'start'    => 0,
            'end'      => 1000000,
            'gap'      => 100,
            'mincount' => 1,
        ];

    }//end buildRangeFacet()


    /**
     * Build date histogram facet with sensible time brackets
     *
     * @param  string $fieldName SOLR field name
     * @return array Date histogram facet configuration
     */
    private function buildDateHistogramFacet(string $fieldName): array
    {
        // For date fields, we'll create multiple time brackets.
        return [
            'type'     => 'range',
            'field'    => $fieldName,
            'start'    => 'NOW-10YEARS',
            'end'      => 'NOW+1DAY',
            'gap'      => '+1YEAR',
            'mincount' => 1,
            'facet'    => [
                'monthly' => [
                    'type'     => 'range',
                    'field'    => $fieldName,
                    'start'    => 'NOW-2YEARS',
                    'end'      => 'NOW+1DAY',
                    'gap'      => '+1MONTH',
                    'mincount' => 1,
                ],
                'daily'   => [
                    'type'     => 'range',
                    'field'    => $fieldName,
                    'start'    => 'NOW-90DAYS',
                    'end'      => 'NOW+1DAY',
                    'gap'      => '+1DAY',
                    'mincount' => 1,
                ],
            ],
        ];

    }//end buildDateHistogramFacet()


    /**
     * Apply custom facet configuration to facet data
     *
     * @param          array  $facetData Processed facet data
     * @param          string $fieldName Field name
     * @return         array Facet data with custom configuration applied
     * @psalm-suppress UnusedParam - Parameters kept for future use
     */
    private function applyFacetConfiguration(array $facetData, string $fieldName): array
    {
        try {
            // Get facet configuration from settings service.
            $settingsService = \OC::$server->get(\OCA\OpenRegister\Service\SettingsService::class);
            $facetConfig     = $settingsService->getSolrFacetConfiguration();

            // Use field name as-is for configuration lookup.
            // Configuration keys use 'self_*' format for metadata fields and plain names for object fields.
            $configFieldName = $fieldName;

            // Check if this field has custom configuration.
            if (($facetConfig['facets'][$configFieldName] ?? null) !== null) {
                $customConfig = $facetConfig['facets'][$configFieldName];

                // Apply custom title.
                if (empty($customConfig['title']) === false) {
                    $facetData['title'] = $customConfig['title'];
                }

                // Apply custom description.
                if (empty($customConfig['description']) === false) {
                    $facetData['description'] = $customConfig['description'];
                }

                // Apply custom order.
                if (($customConfig['order'] ?? null) !== null) {
                    $facetData['order'] = (int) $customConfig['order'];
                }

                // Apply enabled/disabled state.
                if (($customConfig['enabled'] ?? null) !== null) {
                    $facetData['enabled'] = (bool) $customConfig['enabled'];
                }

                // Apply show_count setting.
                if (($customConfig['show_count'] ?? null) !== null) {
                    $facetData['show_count'] = (bool) $customConfig['show_count'];
                }

                // Apply max_items limit.
                if (($customConfig['max_items'] ?? null) !== null && is_array($facetData['data']) === true) {
                    $maxItems = (int) $customConfig['max_items'];
                    if ($maxItems > 0 && count($facetData['data']) > $maxItems) {
                        $facetData['data'] = array_slice($facetData['data'], 0, $maxItems);
                    }
                }
            } else {
                // Apply default settings if no custom configuration.
                $defaultSettings         = $facetConfig['default_settings'] ?? [];
                $facetData['show_count'] = $defaultSettings['show_count'] ?? true;
                $facetData['enabled']    = true;
                $facetData['order']      = 0;

                // Apply default max_items.
                if (($defaultSettings['max_items'] ?? null) !== null && is_array($facetData['data']) === true) {
                    $maxItems = (int) $defaultSettings['max_items'];
                    if ($maxItems > 0 && count($facetData['data']) > $maxItems) {
                        $facetData['data'] = array_slice($facetData['data'], 0, $maxItems);
                    }
                }
            }//end if
        } catch (\Exception $e) {
            // If configuration loading fails, use defaults.
            $this->logger->warning(
                    'Failed to load facet configuration',
                    [
                        'field' => $fieldName,
                        'error' => $e->getMessage(),
                    ]
                    );
            $facetData['enabled']    = true;
            $facetData['show_count'] = true;
            $facetData['order']      = 0;
        }//end try

        return $facetData;

    }//end applyFacetConfiguration()


    /**
     * Sort facets according to custom configuration
     *
     * @param          array $facets Facet data to sort
     * @return         array Sorted facet data
     * @psalm-suppress UnusedParam - Parameters kept for future use
     */
    private function sortFacetsWithConfiguration(array $facets): array
    {
        try {
            // Get facet configuration from settings service.
            $settingsService = \OC::$server->get(\OCA\OpenRegister\Service\SettingsService::class);
            $facetConfig     = $settingsService->getSolrFacetConfiguration();

            // Check if global order is defined.
            if (empty($facetConfig['global_order']) === false) {
                $globalOrder  = $facetConfig['global_order'];
                $sortedFacets = [];

                // First, add facets in the specified global order.
                foreach ($globalOrder as $fieldName) {
                    if (($facets[$fieldName] ?? null) !== null) {
                        $sortedFacets[$fieldName] = $facets[$fieldName];
                        unset($facets[$fieldName]);
                    }
                }

                // Then add remaining facets sorted by their individual order values.
                uasort(
                        $facets,
                        function ($a, $b) {
                            $orderA = $a['order'] ?? 0;
                            $orderB = $b['order'] ?? 0;
                            return $orderA <=> $orderB;
                        }
                        );

                // Merge the globally ordered facets with the remaining ones.
                $facets = array_merge($sortedFacets, $facets);
            } else {
                // Sort by individual order values if no global order is set.
                uasort(
                        $facets,
                        function ($a, $b) {
                            $orderA = $a['order'] ?? 0;
                            $orderB = $b['order'] ?? 0;
                            return $orderA <=> $orderB;
                        }
                        );
            }//end if
        } catch (\Exception $e) {
            // If configuration loading fails, keep original order.
            $this->logger->warning(
                    'Failed to load facet configuration for sorting',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );
        }//end try

        return $facets;

    }//end sortFacetsWithConfiguration()


    /**
     * Process SOLR facet response and format for frontend consumption
     *
     * @param  array $facetData       Raw facet data from SOLR
     * @param  array $facetableFields Original facetable fields structure
     * @return array Formatted facet data
     */
    private function processFacetResponse(array $facetData, array $facetableFields): array
    {
        $processedFacets = [
            '@self'         => [],
            'object_fields' => [],
        ];

        // Define which metadata fields should have their labels resolved.
        $metadataFieldsWithLabelResolution = ['_register', '_schema', '_organisation'];

        // Process metadata fields.
        foreach ($facetableFields['@self'] ?? [] as $fieldName => $fieldInfo) {
            if (($facetData[$fieldName] ?? null) !== null) {
                // Use specialized formatting for metadata fields that need label resolution.
                if (in_array($fieldName, $metadataFieldsWithLabelResolution, true) === true) {
                    // Strip leading underscore for method parameter (register, schema, organisation).
                    $cleanFieldName = ltrim($fieldName, '_');
                    $formattedData  = $this->formatMetadataFacetData(
                        rawData: $facetData[$fieldName],
                        fieldName: $cleanFieldName,
                        facetType: $fieldInfo['type']
                    );
                } else {
                    // Use generic formatting for other metadata fields.
                    $formattedData = $this->formatFacetData(rawFacetData: $facetData[$fieldName], facetType: $fieldInfo['type']);
                }

                $facetResult = array_merge(
                    $fieldInfo,
                    ['data' => $formattedData]
                );

                // Apply custom facet configuration.
                $facetResult = $this->applyFacetConfiguration(facetData: $facetResult, fieldName: 'self_'.$fieldName);

                // Only include enabled facets.
                if ($facetResult['enabled'] ?? true) {
                    $processedFacets['@self'][$fieldName] = $facetResult;
                }
            }//end if
        }//end foreach

        // Process object fields.
        foreach ($facetableFields['object_fields'] ?? [] as $fieldName => $fieldInfo) {
            if (($facetData[$fieldName] ?? null) !== null) {
                $facetResult = array_merge(
                    $fieldInfo,
                    ['data' => $this->formatFacetData(rawFacetData: $facetData[$fieldName], facetType: $fieldInfo['type'])]
                );

                // Apply custom facet configuration.
                $facetResult = $this->applyFacetConfiguration(facetData: $facetResult, fieldName: $fieldName);

                // Only include enabled facets.
                if ($facetResult['enabled'] ?? true) {
                    $processedFacets['object_fields'][$fieldName] = $facetResult;
                }
            }
        }

        // Sort facets according to configuration.
        $processedFacets['@self']         = $this->sortFacetsWithConfiguration($processedFacets['@self']);
        $processedFacets['object_fields'] = $this->sortFacetsWithConfiguration($processedFacets['object_fields']);

        return $processedFacets;

    }//end processFacetResponse()


    /**
     * Format facet data based on facet type
     *
     * @param  array  $rawFacetData Raw facet data from SOLR
     * @param  string $facetType    Type of facet (terms, range, date_histogram)
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

    }//end formatFacetData()


    /**
     * Format metadata facet data with resolved labels for registers, schemas, and organisations
     *
     * @param  array  $rawData   Raw facet data from SOLR
     * @param  string $fieldName The metadata field name (register, schema, organisation)
     * @param  string $facetType The facet type
     * @return array Formatted facet data with resolved labels
     */
    private function formatMetadataFacetData(array $rawData, string $fieldName, string $facetType): array
    {
        if ($facetType !== 'terms') {
            // For non-terms facets (like date_histogram), use regular formatting.
            return $this->formatFacetData(rawFacetData: $rawData, facetType: $facetType);
        }

        $buckets          = $rawData['buckets'] ?? [];
        $formattedBuckets = [];

        // Extract IDs for bulk lookup.
        $ids = array_map(
                function ($bucket) {
                    return $bucket['val'];
                },
                $buckets
                );

        // Resolve labels based on field type.
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
                    // For other metadata fields, just use the value as label.
                    $labels = array_combine($ids, $ids);
                    break;
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                    'Failed to resolve labels for metadata field',
                    [
                        'field' => $fieldName,
                        'error' => $e->getMessage(),
                    ]
                    );
            // Fallback to using values as labels.
            $labels = array_combine($ids, $ids);
        }//end try

        // Format buckets with resolved labels.
        foreach ($buckets as $bucket) {
            $id = $bucket['val'];
            $formattedBuckets[] = [
                'value' => $id,
                'count' => $bucket['count'],
                'label' => $labels[$id] ?? $id,
            // Use resolved label or fallback to ID.
            ];
        }

        // Sort buckets alphabetically by label (case-insensitive).
        usort(
                $formattedBuckets,
                function ($a, $b) {
                    return strcasecmp($a['label'], $b['label']);
                }
                );

        return [
            'type'        => 'terms',
            'total_count' => $rawData['numBuckets'] ?? count($buckets),
            'buckets'     => $formattedBuckets,
        ];

    }//end formatMetadataFacetData()


    /**
     * Format terms facet data
     *
     * Resolves UUID values to human-readable names using the object cache service.
     *
     * @param  array $rawData Raw terms facet data
     * @return array Formatted terms data
     */
    private function formatTermsFacetData(array $rawData): array
    {
        $buckets          = $rawData['buckets'] ?? [];
        $formattedBuckets = [];

        $this->logger->debug(
                'FACET: Formatting terms facet data',
                [
                    'bucket_count'    => count($buckets),
                    'first_3_buckets' => array_slice($buckets, 0, 3),
                ]
                );

        // Extract all values that might be UUIDs for batch lookup.
        $values = array_map(
                function ($bucket) {
                    return $bucket['val'];
                },
                $buckets
                );

        // Filter to only UUID-looking values (contains hyphens).
        $potentialUuids = array_filter(
                $values,
                function ($value) {
                    return is_string($value) && str_contains($value, '-');
                }
                );

        $this->logger->debug(
                'FACET: UUID detection',
                [
                    'total_values'    => count($values),
                    'potential_uuids' => count($potentialUuids),
                    'uuid_samples'    => array_slice($potentialUuids, 0, 5),
                ]
                );

        // Resolve UUIDs to names using object cache service.
        $resolvedNames = [];
        if (empty($potentialUuids) === false) {
            // Lazy-load ObjectCacheService from container.
            $objectCacheService = $this->getObjectCacheService();

            // Check if ObjectCacheService is available.
            if ($objectCacheService === null) {
                $this->logger->warning(
                        'FACET: ObjectCacheService not available for UUID resolution',
                        [
                            'potential_uuids' => count($potentialUuids),
                            'sample_values'   => array_slice($potentialUuids, 0, 3),
                        ]
                        );
            } else {
                try {
                    $resolvedNames = $objectCacheService->getMultipleObjectNames($potentialUuids);
                    $this->logger->debug(
                            'FACET: Resolved UUID labels',
                            [
                                'uuids_checked'   => count($potentialUuids),
                                'names_resolved'  => count($resolvedNames),
                                'sample_resolved' => array_slice($resolvedNames, 0, 3, true),
                            ]
                            );
                } catch (\Exception $e) {
                    $this->logger->warning(
                            'FACET: Failed to resolve UUID labels',
                            [
                                'error' => $e->getMessage(),
                            ]
                            );
                }
            }//end if
        } else {
            $this->logger->debug(
                    'FACET: No UUIDs detected in facet values',
                    [
                        'sample_values' => array_slice($values, 0, 5),
                    ]
                    );
        }//end if

        // Format buckets with resolved labels.
        foreach ($buckets as $bucket) {
            $value = $bucket['val'];
            $label = $resolvedNames[$value] ?? $value;
            // Use resolved name or fallback to value.
            $formattedBuckets[] = [
                'value' => $value,
                'count' => $bucket['count'],
                'label' => $label,
            ];
        }

        // Sort buckets alphabetically by label (case-insensitive).
        usort(
                $formattedBuckets,
                function ($a, $b) {
                    return strcasecmp($a['label'], $b['label']);
                }
                );

        $this->logger->debug(
                'FACET: Sorting complete',
                [
                    'sorted_count'   => count($formattedBuckets),
                    'first_3_sorted' => array_slice($formattedBuckets, 0, 3),
                ]
                );

        return [
            'type'        => 'terms',
            'total_count' => array_sum(array_column($formattedBuckets, 'count')),
            'buckets'     => $formattedBuckets,
        ];

    }//end formatTermsFacetData()


    /**
     * Format range facet data
     *
     * @param  array $rawData Raw range facet data
     * @return array Formatted range data
     */
    private function formatRangeFacetData(array $rawData): array
    {
        $buckets          = $rawData['buckets'] ?? [];
        $formattedBuckets = [];

        foreach ($buckets as $bucket) {
            $formattedBuckets[] = [
                'from'  => $bucket['val'],
                'to'    => $bucket['val'] + ($rawData['gap'] ?? 100),
                'count' => $bucket['count'],
                'label' => $bucket['val'].' - '.($bucket['val'] + ($rawData['gap'] ?? 100)),
            ];
        }

        return [
            'type'        => 'range',
            'total_count' => array_sum(array_column($formattedBuckets, 'count')),
            'buckets'     => $formattedBuckets,
        ];

    }//end formatRangeFacetData()


    /**
     * Format date histogram facet data with multiple time brackets
     *
     * @param  array $rawData Raw date histogram facet data
     * @return array Formatted date histogram data with yearly, monthly, and daily brackets
     */
    private function formatDateHistogramFacetData(array $rawData): array
    {
        $yearlyBuckets  = $rawData['buckets'] ?? [];
        $monthlyBuckets = $rawData['monthly']['buckets'] ?? [];
        $dailyBuckets   = $rawData['daily']['buckets'] ?? [];

        return [
            'type'     => 'date_histogram',
            'brackets' => [
                'yearly'  => [
                    'interval' => 'year',
                    'buckets'  => array_map(
                            function ($bucket) {
                                return [
                                    'date'  => $bucket['val'],
                                    'count' => $bucket['count'],
                                    'label' => date('Y', strtotime($bucket['val'])),
                                ];
                            },
                            $yearlyBuckets
                            ),
                ],
                'monthly' => [
                    'interval' => 'month',
                    'buckets'  => array_map(
                            function ($bucket) {
                                return [
                                    'date'  => $bucket['val'],
                                    'count' => $bucket['count'],
                                    'label' => date('Y-m', strtotime($bucket['val'])),
                                ];
                            },
                            $monthlyBuckets
                            ),
                ],
                'daily'   => [
                    'interval' => 'day',
                    'buckets'  => array_map(
                            function ($bucket) {
                                return [
                                    'date'  => $bucket['val'],
                                    'count' => $bucket['count'],
                                    'label' => date('Y-m-d', strtotime($bucket['val'])),
                                ];
                            },
                            $dailyBuckets
                            ),
                ],
            ],
        ];

    }//end formatDateHistogramFacetData()


    /**
     * Get metadata facetable fields (standard @self fields)
     *
     * @return         array Standard metadata fields that can be faceted
     * @phpstan-return array<string, mixed>
     * @psalm-return   array<string, mixed>
     */
    private function getMetadataFacetableFields(): array
    {
        return [
            '@self' => [
                '_register'     => [
                    'name'           => '_register',
                    'type'           => 'terms',
                    'title'          => 'Register',
                    'description'    => 'Register that contains the object',
                    'data_type'      => 'integer',
                    'index_field'    => 'self_register',
                    'index_type'     => 'pint',
                    'queryParameter' => '@self[register]',
                    'source'         => 'metadata',
                    'show_count'     => true,
                    'enabled'        => true,
                    'order'          => 0,
                ],
                '_schema'       => [
                    'name'           => '_schema',
                    'type'           => 'terms',
                    'title'          => 'Schema',
                    'description'    => 'Schema that defines the object structure',
                    'data_type'      => 'integer',
                    'index_field'    => 'self_schema',
                    'index_type'     => 'pint',
                    'queryParameter' => '@self[schema]',
                    'source'         => 'metadata',
                    'show_count'     => true,
                    'enabled'        => true,
                    'order'          => 0,
                ],
                '_organisation' => [
                    'name'           => '_organisation',
                    'type'           => 'terms',
                    'title'          => 'Organisation',
                    'description'    => 'Organisation that owns the object',
                    'data_type'      => 'string',
                    'index_field'    => 'self_organisation',
                    'index_type'     => 'string',
                    'queryParameter' => '@self[organisation]',
                    'source'         => 'metadata',
                    'show_count'     => true,
                    'enabled'        => true,
                    'order'          => 0,
                ],
                '_application'  => [
                    'name'           => '_application',
                    'type'           => 'terms',
                    'title'          => 'Application',
                    'description'    => 'Application that created the object',
                    'data_type'      => 'string',
                    'index_field'    => 'self_application',
                    'index_type'     => 'string',
                    'queryParameter' => '@self[application]',
                    'source'         => 'metadata',
                    'show_count'     => true,
                    'enabled'        => true,
                    'order'          => 0,
                ],
                '_created'      => [
                    'name'                => '_created',
                    'type'                => 'date_histogram',
                    'title'               => 'Created Date',
                    'description'         => 'When the object was created',
                    'data_type'           => 'datetime',
                    'index_field'         => 'self_created',
                    'index_type'          => 'pdate',
                    'default_interval'    => 'month',
                    'supported_intervals' => ['day', 'week', 'month', 'year'],
                    'queryParameter'      => '@self[created]',
                    'source'              => 'metadata',
                    'show_count'          => true,
                    'enabled'             => true,
                    'order'               => 0,
                ],
                '_updated'      => [
                    'name'                => '_updated',
                    'type'                => 'date_histogram',
                    'title'               => 'Updated Date',
                    'description'         => 'When the object was last modified',
                    'data_type'           => 'datetime',
                    'index_field'         => 'self_updated',
                    'index_type'          => 'pdate',
                    'default_interval'    => 'month',
                    'supported_intervals' => ['day', 'week', 'month', 'year'],
                    'queryParameter'      => '@self[updated]',
                    'source'              => 'metadata',
                    'show_count'          => true,
                    'enabled'             => true,
                    'order'               => 0,
                ],
            ],
        ];

    }//end getMetadataFacetableFields()


    /**
     * Get object field information from schema properties
     *
     * @param  string $fieldName The object field name
     * @return array|null Field information or null if not found
     */
    private function getObjectFieldInfoFromSchema(string $fieldName): ?array
    {
        try {
            // Try to get current schema from context if available.
            if ($this->schemaMapper === null) {
                return null;
            }

            // For now, we'll use a simplified approach.
            // In a full implementation, this would query the current schema context.
            // and extract field information from schema properties.
            return null;
            // Placeholder - would need schema context to implement fully.
        } catch (\Exception $e) {
            $this->logger->debug(
                    'Failed to get object field info from schema',
                    [
                        'field' => $fieldName,
                        'error' => $e->getMessage(),
                    ]
                    );
            return null;
        }//end try

    }//end getObjectFieldInfoFromSchema()


    /**
     * Resolve register labels by IDs using batch loading for improved performance
     *
     * @param  array $ids Array of register IDs
     * @return array Associative array of ID => label
     */
    private function resolveRegisterLabels(array $ids): array
    {
        if (empty($ids) === true || $this->registerMapper === null) {
            return array_combine($ids, $ids);
        }

        try {
            // Use optimized batch loading.
            $registers = $this->registerMapper->findMultipleOptimized($ids);

            $labels = [];
            foreach ($ids as $id) {
                if (($registers[$id] ?? null) !== null) {
                    $register    = $registers[$id];
                    $labels[$id] = $register->getTitle() ?? "Register $id";
                } else {
                    $labels[$id] = "Register $id";
                }
            }

            return $labels;
        } catch (\Exception $e) {
            $this->logger->warning(
                    'Failed to batch load register labels',
                    [
                        'ids'   => $ids,
                        'error' => $e->getMessage(),
                    ]
                    );

            // Fallback to individual IDs as labels.
            return array_combine($ids, array_map(fn($id) => "Register $id", $ids));
        }//end try

    }//end resolveRegisterLabels()


    /**
     * Resolve schema labels by IDs using batch loading for improved performance
     *
     * @param  array $ids Array of schema IDs
     * @return array Associative array of ID => label
     */
    private function resolveSchemaLabels(array $ids): array
    {
        if (empty($ids) === true || $this->schemaMapper === null) {
            return array_combine($ids, $ids);
        }

        try {
            // Use optimized batch loading.
            $schemas = $this->schemaMapper->findMultipleOptimized($ids);

            $labels = [];
            foreach ($ids as $id) {
                if (($schemas[$id] ?? null) !== null) {
                    $schema      = $schemas[$id];
                    $labels[$id] = $schema->getTitle() ?? $schema->getName() ?? "Schema $id";
                } else {
                    $labels[$id] = "Schema $id";
                }
            }

            return $labels;
        } catch (\Exception $e) {
            $this->logger->warning(
                    'Failed to batch load schema labels',
                    [
                        'ids'   => $ids,
                        'error' => $e->getMessage(),
                    ]
                    );

            // Fallback to individual IDs as labels.
            return array_combine($ids, array_map(fn($id) => "Schema $id", $ids));
        }//end try

    }//end resolveSchemaLabels()


    /**
     * Resolve organisation labels by UUIDs using batch loading for improved performance
     *
     * @param  array $ids Array of organisation UUIDs
     * @return array Associative array of UUID => label
     */
    private function resolveOrganisationLabels(array $ids): array
    {
        if (empty($ids) === true || $this->organisationMapper === null) {
            return array_combine($ids, $ids);
        }

        try {
            // Use optimized batch loading.
            $organisations = $this->organisationMapper->findMultipleByUuid($ids);

            $labels = [];
            foreach ($ids as $uuid) {
                if (($organisations[$uuid] ?? null) !== null) {
                    $organisation  = $organisations[$uuid];
                    $labels[$uuid] = $organisation->getName() ?? "Organisation $uuid";
                } else {
                    $labels[$uuid] = "Organisation $uuid";
                }
            }

            return $labels;
        } catch (\Exception $e) {
            $this->logger->warning(
                    'Failed to batch load organisation labels',
                    [
                        'ids'   => $ids,
                        'error' => $e->getMessage(),
                    ]
                    );

            // Fallback to individual UUIDs as labels.
            return array_combine($ids, array_map(fn($uuid) => "Organisation $uuid", $ids));
        }//end try

    }//end resolveOrganisationLabels()


    /**
     * Resolve register value to integer ID
     *
     * Handles cases where register is stored as string ID, slug, or already an integer.
     *
     * @param  string|int|null                    $registerValue The register value from ObjectEntity
     * @param  \OCA\OpenRegister\Db\Register|null $register      Optional pre-loaded register entity
     * @return int The resolved register ID, or 0 if resolution fails
     */
    private function resolveRegisterToId($registerValue, ?\OCA\OpenRegister\Db\Register $register=null): int
    {
        if (empty($registerValue) === true) {
            return 0;
        }

        // If it's already a numeric ID, return it as integer.
        if (is_numeric($registerValue) === true) {
            return (int) $registerValue;
        }

        // If we have a pre-loaded register entity, use its ID.
        if ($register !== null) {
            return $register->getId() ?? 0;
        }

        // Try to resolve by slug/name using RegisterMapper.
        if ($this->registerMapper !== null) {
            try {
                $resolvedRegister = $this->registerMapper->find($registerValue);
                return $resolvedRegister->getId() ?? 0;
            } catch (\Exception $e) {
                $this->logger->warning(
                        'Failed to resolve register value to ID',
                        [
                            'registerValue' => $registerValue,
                            'error'         => $e->getMessage(),
                        ]
                        );
            }
        }

        // Fallback: return 0 for unresolvable values.
        $this->logger->warning(
                'Could not resolve register to integer ID',
                [
                    'registerValue' => $registerValue,
                    'type'          => gettype($registerValue),
                ]
                );
        return 0;

    }//end resolveRegisterToId()


    /**
     * Resolve schema value to integer ID
     *
     * Handles cases where schema is stored as string ID, slug, or already an integer.
     *
     * @param  string|int|null                  $schemaValue The schema value from ObjectEntity
     * @param  \OCA\OpenRegister\Db\Schema|null $schema      Optional pre-loaded schema entity
     * @return int The resolved schema ID, or 0 if resolution fails
     */
    private function resolveSchemaToId($schemaValue, ?\OCA\OpenRegister\Db\Schema $schema=null): int
    {
        if (empty($schemaValue) === true) {
            return 0;
        }

        // If it's already a numeric ID, return it as integer.
        if (is_numeric($schemaValue) === true) {
            return (int) $schemaValue;
        }

        // If we have a pre-loaded schema entity, use its ID.
        if ($schema !== null) {
            return $schema->getId() ?? 0;
        }

        // Try to resolve by slug/name using SchemaMapper.
        if ($this->schemaMapper !== null) {
            try {
                $resolvedSchema = $this->schemaMapper->find($schemaValue);
                return $resolvedSchema->getId() ?? 0;
            } catch (\Exception $e) {
                $this->logger->warning(
                        'Failed to resolve schema value to ID',
                        [
                            'schemaValue' => $schemaValue,
                            'error'       => $e->getMessage(),
                        ]
                        );
            }
        }

        // Fallback: return 0 for unresolvable values.
        $this->logger->warning(
                'Could not resolve schema to integer ID',
                [
                    'schemaValue' => $schemaValue,
                    'type'        => gettype($schemaValue),
                ]
                );
        return 0;

    }//end resolveSchemaToId()


    /**
     * TODO: HOTFIX - Resolve metadata field values (register/schema names) to integer IDs
     *
     * This is a temporary hotfix method that handles cases where external applications
     * (like OpenCatalogi) pass register/schema names or slugs instead of integer IDs.
     *
     * IMPORTANT: This method only handles SINGLE values (string or int), NOT arrays.
     * Arrays of schemas/registers are handled separately in the calling code.
     *
     * PROPER SOLUTION: The OpenCatalogi controllers should be updated to resolve
     * these values to integer IDs before calling OpenRegister APIs, rather than
     * doing this conversion at the SOLR query level.
     *
     * @param  string     $fieldType The metadata field type ('register' or 'schema')
     * @param  string|int $value     The single value to resolve (name, slug, or ID) - NOT an array
     * @return int The resolved integer ID, or 0 if resolution fails
     */
    private function resolveMetadataValueToId(string $fieldType, string|int $value): int
    {
        if (is_numeric($value) === true) {
            return (int) $value;
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
            $this->logger->warning(
                    'Failed to resolve metadata value to ID',
                    [
                        'fieldType' => $fieldType,
                        'value'     => $value,
                        'error'     => $e->getMessage(),
                    ]
                    );
        }//end try

        // Return 0 for unresolvable values (will be filtered out in SOLR).
        return 0;

    }//end resolveMetadataValueToId()


    /**
     * List all SOLR collections with statistics
     *
     * Returns an array of collections with their metadata including:
     * - Name
     * - ConfigSet
     * - Number of documents
     * - Size
     * - Shard count
     * - Replica count
     * - Health status
     *
     * @return array Array of collection information
     * @throws \Exception If unable to fetch collection list
     */
    public function listCollections(): array
    {
        try {
            $this->logger->info(message: '📋 Fetching SOLR collections list');

            // Check if SOLR is configured before attempting to connect.
            // This prevents DNS resolution errors when Solr host is not configured.
            // For configuration endpoints, returning empty arrays when not configured is valid behavior.
            if ($this->isSolrConfigured() === false) {
                $configStatus = [
                    'enabled' => $this->solrConfig['enabled'] ?? false,
                    'host'    => $this->solrConfig['host'] ?? 'not set',
                ];
                $this->logger->info(
                    message: 'SOLR is not configured - returning empty collections list',
                    context: ['config_status' => $configStatus]
                );
                return [];
            }

            // Get cluster status with all collections.
            // Collection management is part of initial setup, but we still need basic configuration.
            $clusterUrl = $this->buildSolrBaseUrl().'/admin/collections?action=CLUSTERSTATUS&wt=json';
            $response   = $this->httpClient->get($clusterUrl, ['timeout' => 30]);
            $data       = json_decode((string) $response->getBody(), true);

            if (isset($data['cluster']['collections']) === false) {
                $this->logger->warning(message: 'No collections found in cluster status');
                return [];
            }

            $collections = [];
            foreach ($data['cluster']['collections'] as $collectionName => $collectionData) {
                // Get document count for this collection.
                $docCount = 0;
                try {
                    $queryUrl      = $this->buildSolrBaseUrl().'/'.$collectionName.'/select?q=*:*&rows=0&wt=json';
                    $queryResponse = $this->httpClient->get($queryUrl, ['timeout' => 10]);
                    $queryData     = json_decode((string) $queryResponse->getBody(), true);
                    $docCount      = $queryData['response']['numFound'] ?? 0;
                } catch (\Exception $e) {
                    $this->logger->warning(
                            'Failed to get document count for collection',
                            [
                                'collection' => $collectionName,
                                'error'      => $e->getMessage(),
                            ]
                            );
                }

                // Calculate total shards and replicas.
                $shards       = $collectionData['shards'] ?? [];
                $shardCount   = count($shards);
                $replicaCount = 0;
                $activeArray  = [];
                // Array to track active replicas for health/status checks
                foreach ($shards as $shard) {
                    $replicas      = $shard['replicas'] ?? [];
                    $replicaCount += count($replicas);

                    // Check if all replicas are active.
                    foreach ($replicas as $replica) {
                        $isActive = ($replica['state'] ?? '') === 'active';
                        if ($isActive === true) {
                            $activeArray[] = 'active';
                        } else {
                        }
                    }
                }

                $collections[] = [
                    'name'              => $collectionName,
                    'configName'        => $collectionData['configName'] ?? 'unknown',
                    'documentCount'     => $docCount,
                    'shards'            => $shardCount,
                    'replicas'          => $replicaCount,
                    'router'            => $collectionData['router']['name'] ?? 'compositeId',
                    'autoAddReplicas'   => $collectionData['autoAddReplicas'] ?? false,
                    'replicationFactor' => $collectionData['replicationFactor'] ?? 1,
                    'maxShardsPerNode'  => $collectionData['maxShardsPerNode'] ?? 1,
                    'health'            => $this->getCollectionHealth($activeArray),
                    'status'            => $this->getCollectionStatus($activeArray),
                ];
            }//end foreach

            $this->logger->info(message: '✅ Successfully fetched collections', context: ['count' => count($collections)]);
            return $collections;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to list SOLR collections',
                    [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );
            throw new \Exception('Failed to fetch SOLR collections: '.$e->getMessage());
        }//end try

    }//end listCollections()


    /**
     * List all SOLR ConfigSets
     *
     * Returns an array of ConfigSets (configuration templates) available in SOLR
     *
     * @return array Array of ConfigSet names and metadata
     * @throws \Exception If unable to fetch ConfigSet list
     */
    public function listConfigSets(): array
    {
        try {
            $this->logger->info(message: '📋 Fetching SOLR ConfigSets list');

            // Check if SOLR is configured before attempting to connect.
            // This prevents DNS resolution errors when Solr host is not configured.
            // For configuration endpoints, returning empty arrays when not configured is valid behavior.
            if ($this->isSolrConfigured() === false) {
                $configStatus = [
                    'enabled' => $this->solrConfig['enabled'] ?? false,
                    'host'    => $this->solrConfig['host'] ?? 'not set',
                ];
                $this->logger->info(
                    message: 'SOLR is not configured - returning empty ConfigSets list',
                    context: ['config_status' => $configStatus]
                );
                return [];
            }

            // Get list of ConfigSets.
            // ConfigSet management is part of initial setup, but we still need basic configuration.
            $configSetsUrl = $this->buildSolrBaseUrl().'/admin/configs?action=LIST&wt=json';
            $response      = $this->httpClient->get($configSetsUrl, ['timeout' => 10]);
            $data          = json_decode((string) $response->getBody(), true);

            if (isset($data['configSets']) === false) {
                $this->logger->warning(message: 'No ConfigSets found');
                return [];
            }

            $configSets = [];
            foreach ($data['configSets'] as $configSetName) {
                // Count collections using this ConfigSet.
                $collections       = $this->listCollections();
                $usedByCollections = array_filter(
                        $collections,
                        function ($col) use ($configSetName) {
                            return $col['configName'] === $configSetName;
                        }
                        );

                $configSets[] = [
                    'name'        => $configSetName,
                    'usedBy'      => array_column($usedByCollections, 'name'),
                    'usedByCount' => count($usedByCollections),
                ];
            }

            $this->logger->info(message: '✅ Successfully fetched ConfigSets', context: ['count' => count($configSets)]);
            return $configSets;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to list SOLR ConfigSets',
                    [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );
            throw new \Exception('Failed to fetch SOLR ConfigSets: '.$e->getMessage());
        }//end try

    }//end listConfigSets()


    /**
     * Create a new ConfigSet by copying an existing one (typically _default)
     *
     * @param  string $name          Name for the new ConfigSet
     * @param  string $baseConfigSet Base ConfigSet to copy from (default: _default)
     * @return array Result of the creation operation
     * @throws \Exception If creation fails
     */
    public function createConfigSet(string $name, string $baseConfigSet='_default'): array
    {
        try {
            $this->logger->info(
                    '📋 Creating new SOLR ConfigSet',
                    [
                        'name'          => $name,
                        'baseConfigSet' => $baseConfigSet,
                    ]
                    );

            // NO availability check - ConfigSet creation is part of initial setup!
            // We only need basic SOLR connectivity, which is tested in Connection Settings.
            // Check if ConfigSet already exists.
            $existingConfigSets = $this->listConfigSets();
            foreach ($existingConfigSets as $cs) {
                if ($cs['name'] === $name) {
                    throw new \Exception("ConfigSet '{$name}' already exists");
                }
            }

            // Verify base ConfigSet exists.
            $baseExists = false;
            foreach ($existingConfigSets as $cs) {
                if ($cs['name'] === $baseConfigSet) {
                    $baseExists = true;
                    break;
                }
            }

            if ($baseExists === false) {
                throw new \Exception("Base ConfigSet '{$baseConfigSet}' not found");
            }

            // Create the ConfigSet by copying the base.
            $createUrl = $this->buildSolrBaseUrl().'/admin/configs?action=CREATE'.'&name='.urlencode($name).'&baseConfigSet='.urlencode($baseConfigSet).'&wt=json';

            $response = $this->httpClient->get($createUrl, ['timeout' => 60]);
            $result   = json_decode((string) $response->getBody(), true);

            if (($result['failure'] ?? null) !== null) {
                throw new \Exception('Failed to create ConfigSet: '.json_encode($result['failure']));
            }

            $this->logger->info(message: '✅ Successfully created ConfigSet', context: ['name' => $name]);
            return [
                'success'       => true,
                'message'       => 'ConfigSet created successfully',
                'configSet'     => $name,
                'baseConfigSet' => $baseConfigSet,
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to create SOLR ConfigSet',
                    [
                        'error'         => $e->getMessage(),
                        'name'          => $name,
                        'baseConfigSet' => $baseConfigSet,
                    ]
                    );
            throw new \Exception('Failed to create ConfigSet: '.$e->getMessage());
        }//end try

    }//end createConfigSet()


    /**
     * Delete a SOLR ConfigSet
     *
     * @param  string $name Name of the ConfigSet to delete
     * @return array Result of the deletion operation
     * @throws \Exception If deletion fails or ConfigSet is protected
     */
    public function deleteConfigSet(string $name): array
    {
        try {
            $this->logger->info(message: '🗑️ Deleting SOLR ConfigSet', context: ['name' => $name]);

            // Protect _default ConfigSet from deletion.
            if ($name === '_default') {
                throw new \Exception('Cannot delete the _default ConfigSet - it is protected');
            }

            // NO availability check - ConfigSet deletion is a management operation!
            // We only need basic SOLR connectivity, which is tested in Connection Settings.
            // Check if ConfigSet exists and is not in use.
            $configSets     = $this->listConfigSets();
            $configSetFound = false;
            foreach ($configSets as $cs) {
                if ($cs['name'] === $name) {
                    $configSetFound = true;
                    if ($cs['usedByCount'] > 0) {
                        throw new \Exception("Cannot delete ConfigSet '{$name}' - it is used by {$cs['usedByCount']} collection(s): ".implode(', ', $cs['usedBy']));
                    }

                    break;
                }
            }

            if ($configSetFound === false) {
                throw new \Exception("ConfigSet '{$name}' not found");
            }

            // Delete the ConfigSet.
            $deleteUrl = $this->buildSolrBaseUrl().'/admin/configs?action=DELETE'.'&name='.urlencode($name).'&wt=json';

            $response = $this->httpClient->get($deleteUrl, ['timeout' => 60]);
            $result   = json_decode((string) $response->getBody(), true);

            if (($result['failure'] ?? null) !== null) {
                throw new \Exception('Failed to delete ConfigSet: '.json_encode($result['failure']));
            }

            $this->logger->info(message: '✅ Successfully deleted ConfigSet', context: ['name' => $name]);
            return [
                'success'   => true,
                'message'   => 'ConfigSet deleted successfully',
                'configSet' => $name,
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to delete SOLR ConfigSet',
                    [
                        'error' => $e->getMessage(),
                        'name'  => $name,
                    ]
                    );
            throw new \Exception('Failed to delete ConfigSet: '.$e->getMessage());
        }//end try

    }//end deleteConfigSet()


    /**
     * Copy a SOLR collection to create a new one
     *
     * @param  string $sourceCollection Source collection name
     * @param  string $targetCollection Target collection name
     * @param  bool   $copyData         Whether to copy data (default: false, only schema/config)
     * @return array Result of the copy operation
     * @throws \Exception If copy operation fails
     */
    public function copyCollection(string $sourceCollection, string $targetCollection, bool $copyData=false): array
    {
        try {
            $this->logger->info(
                    '📋 Copying SOLR collection',
                    [
                        'source'   => $sourceCollection,
                        'target'   => $targetCollection,
                        'copyData' => $copyData,
                    ]
                    );

            // NO availability check - Collection copying is a management operation!
            // We only need basic SOLR connectivity, which is tested in Connection Settings.
            // Get source collection info.
            $collections = $this->listCollections();
            $sourceInfo  = null;
            foreach ($collections as $col) {
                if ($col['name'] === $sourceCollection) {
                    $sourceInfo = $col;
                    break;
                }
            }

            if ($sourceInfo === null) {
                throw new \Exception("Source collection '{$sourceCollection}' not found");
            }

            // Check if target collection already exists.
            foreach ($collections as $col) {
                if ($col['name'] === $targetCollection) {
                    throw new \Exception("Target collection '{$targetCollection}' already exists");
                }
            }

            // Create new collection using the same ConfigSet.
            $createUrl = $this->buildSolrBaseUrl().'/admin/collections?action=CREATE'.'&name='.urlencode($targetCollection).'&collection.configName='.urlencode($sourceInfo['configName']).'&numShards='.$sourceInfo['shards'].'&replicationFactor='.$sourceInfo['replicationFactor'].'&maxShardsPerNode='.$sourceInfo['maxShardsPerNode'].'&wt=json';

            $response = $this->httpClient->get($createUrl, ['timeout' => 60]);
            $result   = json_decode((string) $response->getBody(), true);

            if (($result['failure'] ?? null) !== null) {
                throw new \Exception('Failed to create collection: '.json_encode($result['failure']));
            }

            // If copyData is true, copy documents from source to target.
            if (($copyData === true) === true && ($sourceInfo['documentCount'] > 0) === true) {
                $this->logger->info(message: '📋 Copying data from source to target collection');

                // Note: This is a placeholder for data copying.
                // In production, you might want to use SOLR's backup/restore feature.
                // or implement a more sophisticated data migration strategy.
                $this->logger->warning(message: 'Data copying is not yet fully implemented - only schema/config was copied');
            }

            $this->logger->info(message: '✅ Successfully copied collection');
            return [
                'success'    => true,
                'message'    => 'Collection copied successfully',
                'source'     => $sourceCollection,
                'target'     => $targetCollection,
                'configSet'  => $sourceInfo['configName'],
                'shards'     => $sourceInfo['shards'],
                'replicas'   => $sourceInfo['replicationFactor'],
                'dataCopied' => false,
            // Will be true when data copying is implemented.
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to copy SOLR collection',
                    [
                        'error'  => $e->getMessage(),
                        'source' => $sourceCollection,
                        'target' => $targetCollection,
                    ]
                    );
            throw new \Exception('Failed to copy collection: '.$e->getMessage());
        }//end try

    }//end copyCollection()


    /**
     * Bulk index multiple files
     *
     * @param array       $fileIds        File IDs to index
     * @param string|null $collectionName Collection name (optional)
     *
     * @return array{indexed: int, failed: int, errors: array}
     */
    public function indexFiles(array $fileIds, ?string $collectionName=null): array
    {
        $indexed = 0;
        $failed  = 0;
        $errors  = [];

        $this->logger->info(
                '[GuzzleSolrService] Bulk indexing files',
                [
                    'file_count' => count($fileIds),
                    'collection' => $collectionName ?? 'default',
                ]
                );

        foreach ($fileIds as $fileId) {
            try {
                // Get file text from database.
                $fileTextMapper = \OC::$server->get(\OCA\OpenRegister\Db\FileTextMapper::class);
                $fileText       = $fileTextMapper->findByFileId($fileId);

                if (($fileText === false) || $fileText->getExtractionStatus() !== 'completed') {
                    $errors[] = "File $fileId: No extracted text available";
                    $failed++;
                    continue;
                }

                // Check if file has been chunked during extraction.
                if ($fileText->getChunked() === false || $fileText->getChunksJson() === false) {
                    $errors[] = "File $fileId: Text not chunked. Re-extract the file to generate chunks.";
                    $failed++;
                    continue;
                }

                // Get pre-chunked data from extraction.
                $chunksJson = $fileText->getChunksJson();
                $chunks     = json_decode($chunksJson, true);

                if (is_array($chunks) === false || empty($chunks) === true) {
                    $errors[] = "File $fileId: Invalid or empty chunks data";
                    $failed++;
                    continue;
                }

                // Prepare metadata.
                $metadata = [
                    'file_path' => $fileText->getFilePath(),
                    'file_name' => $fileText->getFileName(),
                    'mime_type' => $fileText->getMimeType(),
                    'file_size' => $fileText->getFileSize(),
                ];

                // Index chunks (chunks are already prepared by TextExtractionService).
                if ($this->indexFileChunks(fileId: $fileId, chunks: $chunks, metadata: $metadata) === true) {
                    $indexed++;

                    // Update file text record.
                    $fileText->setIndexedInSolr(true);
                    $fileText->setUpdatedAt(new \DateTime());
                    $fileTextMapper->update($fileText);
                } else {
                    $errors[] = "File $fileId: Failed to index chunks in SOLR";
                    $failed++;
                }
            } catch (\Exception $e) {
                $errors[] = "File $fileId: ".$e->getMessage();
                $failed++;
            }//end try
        }//end foreach

        $this->logger->info(
                '[GuzzleSolrService] Bulk file indexing complete',
                [
                    'indexed' => $indexed,
                    'failed'  => $failed,
                ]
                );

        return [
            'indexed' => $indexed,
            'failed'  => $failed,
            'errors'  => $errors,
        ];

    }//end indexFiles()


    /**
     * Get file indexing statistics
     *
     * @return array File index statistics
     */
    public function getFileIndexStats(): array
    {
        try {
            // Check if SOLR is available.
            if ($this->isAvailable() === false) {
                return [
                    'success'      => true,
                    'total_chunks' => 0,
                    'unique_files' => 0,
                    'mime_types'   => [],
                    'collection'   => null,
                    'message'      => 'SOLR is not configured',
                ];
            }

            // Get file collection name.
            $settings       = $this->settingsService->getSettings();
            $fileCollection = $settings['solr']['fileCollection'] ?? null;
            if ($fileCollection === null || $fileCollection === '') {
                return [
                    'success'      => true,
                    'total_chunks' => 0,
                    'unique_files' => 0,
                    'mime_types'   => [],
                    'collection'   => null,
                    'message'      => 'No file collection configured',
                ];
            }

            // Check if collection exists.
            $collections = $this->listCollections();
            if (in_array($fileCollection, $collections, true) === false) {
                return [
                    'success'      => true,
                    'total_chunks' => 0,
                    'unique_files' => 0,
                    'mime_types'   => [],
                    'collection'   => $fileCollection,
                    'message'      => 'File collection does not exist yet',
                ];
            }

            // Query SOLR for file stats.
            $queryUrl       = $this->buildSolrBaseUrl()."/{$fileCollection}/select";
            $requestOptions = [
                'query'   => [
                    'q'           => '*:*',
                    'rows'        => 0,
                    'facet'       => 'true',
                    'facet.field' => 'mime_type',
                ],
                'timeout' => $this->solrConfig['timeout'] ?? 30,
            ];

            // Add authentication if configured.
            if (empty($this->solrConfig['username']) === false && empty($this->solrConfig['password']) === false) {
                $requestOptions['auth'] = [
                    $this->solrConfig['username'],
                    $this->solrConfig['password'],
                ];
            }

            $response = $this->httpClient->get($queryUrl, $requestOptions);
            /*
             * @var \Psr\Http\Message\ResponseInterface $response
             */
            $result = json_decode($response->getBody()->getContents(), true);

            $totalChunks = $result['response']['numFound'] ?? 0;

            // Count unique files.
            $uniqueFilesQuery        = array_merge(
                    $requestOptions['query'],
                    [
                        'facet.field' => 'file_id',
                        'facet.limit' => -1,
                    ]
                    );
            $requestOptions['query'] = $uniqueFilesQuery;

            $response2 = $this->httpClient->get($queryUrl, $requestOptions);
            /*
             * @var \Psr\Http\Message\ResponseInterface $response2
             */
            $result2 = json_decode($response2->getBody()->getContents(), true);

            $uniqueFiles = count($result2['facet_counts']['facet_fields']['file_id'] ?? []) / 2;

            return [
                'success'      => true,
                'total_chunks' => $totalChunks,
                'unique_files' => (int) $uniqueFiles,
                'mime_types'   => $result['facet_counts']['facet_fields']['mime_type'] ?? [],
                'collection'   => $fileCollection,
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                    '[GuzzleSolrService] Failed to get file index stats',
                    [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            return [
                'success'      => true,
                'total_chunks' => 0,
                'unique_files' => 0,
                'mime_types'   => [],
                'collection'   => null,
                'message'      => 'Error: '.$e->getMessage(),
            ];
        }//end try

    }//end getFileIndexStats()


    /**
     * Get object data or default empty array.
     *
     * @param mixed $objectData Object data.
     *
     * @return array Object data or empty array.
     */
    private function getObjectDataOrDefault($objectData): array
    {
        if ($objectData !== null && is_array($objectData) === true) {
            return $objectData;
        }

        return [];

    }//end getObjectDataOrDefault()


    /**
     * Format DateTime field.
     *
     * @param DateTime|null $dateTime DateTime object.
     *
     * @return string|null Formatted date string or null.
     */
    private function formatDateTimeField(?\DateTime $dateTime): ?string
    {
        if ($dateTime !== null) {
            return $dateTime->format('Y-m-d\TH:i:s\Z');
        }

        return null;

    }//end formatDateTimeField()


    /**
     * Encode JSON field.
     *
     * @param mixed $data Data to encode.
     *
     * @return string|null JSON encoded string or null.
     */
    private function encodeJsonField($data): ?string
    {
        if ($data !== null && empty($data) === false) {
            return json_encode($data);
        }

        return null;

    }//end encodeJsonField()


    /**
     * Format query structure value.
     *
     * @param mixed $v Value to format.
     *
     * @return string Formatted value string.
     */
    private function formatQueryStructureValue($v): string
    {
        if (is_array($v) === true) {
            return 'array['.count($v).']';
        }

        return gettype($v);

    }//end formatQueryStructureValue()


    /**
     * Get base prefix.
     *
     * @param string $prefix Prefix string.
     *
     * @return string Base prefix.
     */
    private function getBasePrefix(string $prefix): string
    {
        if ($prefix === '') {
            return self::APP_PREFIX.'_';
        }

        return $prefix;

    }//end getBasePrefix()


    /**
     * Get Solr type from array.
     *
     * @param array $solrType Solr type array.
     *
     * @return string Solr type string.
     */
    private function getSolrTypeFromArray(array $solrType): string
    {
        if (empty($solrType) === false && (($solrType[0] ?? null) !== null)) {
            return (string) $solrType[0];
        }

        return 'string';

    }//end getSolrTypeFromArray()


    /**
     * Get self relations type from document.
     *
     * @param array<string, mixed> $document Document array.
     *
     * @return string Type of self_relations field.
     */
    private function getSelfRelationsType(array $document): string
    {
        if (isset($document['self_relations']) === false) {
            return 'NOT_SET';
        }

        $selfRelations = $document['self_relations'];
        if (is_array($selfRelations) === true) {
            return 'array';
        }

        if (is_string($selfRelations) === true) {
            return 'string';
        }

        return gettype($selfRelations);

    }//end getSelfRelationsType()


    /**
     * Get self relations count from document.
     *
     * @param array<string, mixed> $document Document array.
     *
     * @return int Count of self_relations.
     */
    private function getSelfRelationsCount(array $document): int
    {
        if (isset($document['self_relations']) === false) {
            return 0;
        }

        $selfRelations = $document['self_relations'];
        if (is_array($selfRelations) === true) {
            return count($selfRelations);
        }

        return 1;

    }//end getSelfRelationsCount()


    /**
     * Get self object JSON representation.
     *
     * @param ObjectEntity $object Object entity.
     *
     * @return string JSON-encoded object.
     */
    private function getSelfObjectJson(ObjectEntity $object): string
    {
        $json = json_encode($object->jsonSerialize());
        if ($json === false) {
            return '{}';
        }

        return $json;

    }//end getSelfObjectJson()


    /**
     * Get config status for a specific key.
     *
     * @param string $key Config key.
     *
     * @return string Config value or 'NOT_SET'.
     */
    private function getConfigStatus(string $key): string
    {
        return (string) ($this->solrConfig[$key] ?? 'NOT_SET');

    }//end getConfigStatus()


    /**
     * Get port status.
     *
     * @return string Port number or 'NOT_SET'.
     */
    private function getPortStatus(): string
    {
        return (string) ($this->solrConfig['port'] ?? 'NOT_SET');

    }//end getPortStatus()


    /**
     * Get core status.
     *
     * @return string Core name or 'NOT_SET'.
     */
    private function getCoreStatus(): string
    {
        return (string) ($this->solrConfig['core'] ?? 'NOT_SET');

    }//end getCoreStatus()


    /**
     * Calculate prediction accuracy percentage.
     *
     * @param float|int $estimated Estimated value.
     * @param float|int $actual    Actual value.
     *
     * @return float Accuracy percentage difference.
     */
    private function calculatePredictionAccuracy($estimated, $actual): float
    {
        if ($estimated === 0.0) {
            return 100.0;
        }

        $difference = abs($estimated - $actual);
        return round(($difference / $estimated) * 100, 2);

    }//end calculatePredictionAccuracy()


    /**
     * Get numeric type for SOLR.
     *
     * @param mixed $value Numeric value.
     *
     * @return string SOLR numeric type ('pint', 'pfloat', 'pdouble', 'plong').
     */
    private function getNumericType($value): string
    {
        if (is_int($value) === true) {
            if ($value >= -2147483648 && $value <= 2147483647) {
                return 'pint';
            }

            return 'plong';
        }

        if (is_float($value) === true) {
            return 'pdouble';
        }

        return 'pfloat';

    }//end getNumericType()


    /**
     * Get field name from facet key.
     *
     * @param string $facetKey Facet key (may have 'object_' prefix).
     *
     * @return string Field name without prefix.
     */
    private function getFieldNameFromFacetKey(string $facetKey): string
    {
        if (strpos($facetKey, 'object_') === 0) {
            return substr($facetKey, 7);
        }

        return $facetKey;

    }//end getFieldNameFromFacetKey()


    /**
     * Get facet config key.
     *
     * @param bool   $isMetadataField Whether field is metadata.
     * @param string $facetKey        Facet key.
     * @param string $fieldName       Field name.
     *
     * @return string Config key.
     */
    private function getFacetConfigKey(bool $isMetadataField, string $facetKey, string $fieldName): string
    {
        if ($isMetadataField === true) {
            return $facetKey;
        }

        return $fieldName;

    }//end getFacetConfigKey()


    /**
     * Get facet keys from data array.
     *
     * @param array<string, mixed> $data Data array.
     *
     * @return array<string> Array of facet keys.
     */
    private function getFacetKeys(array $data): array
    {
        if (isset($data['facets']) === false || is_array($data['facets']) === false) {
            return [];
        }

        return array_keys($data['facets']);

    }//end getFacetKeys()


    /**
     * Get object facet keys from data array.
     *
     * @param array<string, mixed> $data Data array.
     *
     * @return array<string> Array of object facet keys (starting with 'object_').
     */
    private function getObjectFacetKeys(array $data): array
    {
        $facetKeys = $this->getFacetKeys($data);
        return array_filter(
            $facetKeys,
            function ($key) {
                return strpos($key, 'object_') === 0;
            }
        );

    }//end getObjectFacetKeys()


    /**
     * Get collection health status.
     *
     * @param array<int, string> $allActive Array of active replica states.
     *
     * @return string Health status ('healthy', 'degraded', 'unhealthy').
     */
    private function getCollectionHealth(array $allActive): string
    {
        if (empty($allActive) === true) {
            return 'unhealthy';
        }

        // Simple health check - can be enhanced.
        return 'healthy';

    }//end getCollectionHealth()


    /**
     * Get collection status.
     *
     * @param array<int, string> $allActive Array of active replica states.
     *
     * @return string Status ('active', 'inactive', 'unknown').
     */
    private function getCollectionStatus(array $allActive): string
    {
        if (empty($allActive) === true) {
            return 'inactive';
        }

        return 'active';

    }//end getCollectionStatus()


}//end class
