<?php
/**
 * SolrService - Apache SOLR Integration Service
 *
 * This service provides comprehensive SOLR integration for the OpenRegister application,
 * including document indexing, searching, and cache integration. It seamlessly replaces
 * traditional database searches when SOLR is enabled and configured.
 *
 * @category Service
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

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Solarium\Client;
use Solarium\Core\Client\Adapter\Curl;
use Solarium\Core\Client\Endpoint;
use Solarium\Exception\HttpException;
use Solarium\QueryType\Select\Query\Query as SelectQuery;
use Solarium\QueryType\Update\Query\Query as UpdateQuery;
use Symfony\Component\EventDispatcher\EventDispatcher;
use OCA\OpenRegister\Setup\SolrSetup;

/**
 * SOLR service for advanced search and indexing capabilities
 *
 * Provides full-text search, faceted search, and high-performance querying
 * with seamless integration into the OpenRegister ecosystem.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @license   AGPL-3.0-or-later
 * @link      https://github.com/OpenCatalogi/OpenRegister
 * @version   1.0.0
 * @copyright 2024 Conduction b.v.
 */
class SolrService
{
    /**
     * Solarium client instance
     *
     * @var Client|null
     */
    private ?Client $client = null;

    /**
     * Flag to track if client initialization has been attempted
     *
     * @var bool
     */
    private bool $clientInitialized = false;

    /**
     * SOLR connection configuration
     *
     * @var array{enabled: bool, host: string, port: int, path: string, core: string, scheme: string, username: string, password: string, timeout: int, autoCommit: bool, commitWithin: int, enableLogging: bool}
     */
    private array $solrConfig = [];

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
     * Tenant identifier for multi-tenancy support
     *
     * @var string
     */
    private string $tenantId;

    /**
     * Default field boost weights for search relevance
     *
     * @var array<string, float>
     */
    private const DEFAULT_FIELD_BOOSTS = [
        'name' => 3.0,
        'title' => 3.0,
        'description' => 2.0,
        'summary' => 2.0,
        'content' => 1.0,
        '_text_' => 1.0, // Catch-all text field
    ];

    /**
     * Constructor for SolrService
     *
     * @param SettingsService        $settingsService  Settings service for configuration
     * @param LoggerInterface        $logger           Logger for debugging and monitoring  
     * @param ObjectEntityMapper     $objectMapper     Object mapper for database operations
     * @param IConfig               $config           Nextcloud configuration interface
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
        private readonly ObjectEntityMapper $objectMapper,
        private readonly IConfig $config
    ) {
        $this->tenantId = $this->generateTenantId();
        // Lazy initialization - only initialize when actually used
    }

    /**
     * Generate unique tenant ID for this Nextcloud instance
     *
     * Creates a consistent identifier based on instance configuration
     * to ensure proper multi-tenancy in shared SOLR environments.
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
     * Generate tenant-specific Solr collection name for SolrCloud
     *
     * Creates a collection name that includes tenant isolation for proper multi-tenancy.
     * SolrCloud format: {base_collection}_{tenant_id} (e.g., "openregister_nc_f0e53393")
     *
     * @param string $baseCollectionName Base collection name from configuration
     * @return string Tenant-specific collection name
     */
    private function getTenantSpecificCoreName(string $baseCollectionName): string
    {
        // SOLR CLOUD: Use collection names, not core names
        // Format: openregister_nc_f0e53393 (collection)
        // Underlying core: openregister_nc_f0e53393_shard1_replica_n1 (handled by SolrCloud)
        return $baseCollectionName . '_' . $this->tenantId;
    }

    /**
     * Ensure tenant-specific Solr collection exists, create if necessary (SolrCloud)
     *
     * Automatically creates SolrCloud collections for new tenants using the base collection as a template.
     * This ensures seamless multi-tenant operation without manual collection management.
     *
     * @param string $collectionName Collection name to check/create
     * @return bool True if collection exists or was created successfully
     */
    private function ensureTenantCollectionExists(string $collectionName): bool
    {
        $this->ensureClientInitialized();
        
        if (!$this->client) {
            $this->logger->warning('Cannot check collection existence: Solr client not initialized');
            return false;
        }
        
        try {
            // Check if collection already exists using Collections API (SolrCloud)
            if ($this->collectionExists($collectionName)) {
                $this->logger->info("Solr collection '{$collectionName}' already exists", [
                    'tenant_id' => $this->tenantId
                ]);
                return true;
            }
            
            // Collection doesn't exist, attempt to create it
            return $this->createTenantCollection($collectionName);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to check/create Solr collection', [
                'collection' => $collectionName,
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Create a new Solr collection for a tenant (SolrCloud)
     *
     * Creates a new collection using the base OpenRegister configSet.
     * Uses the same configSet as the base collection for consistent functionality.
     *
     * @param string $collectionName Name of the collection to create
     * @return bool True if collection was created successfully
     */
    private function createTenantCollection(string $collectionName): bool
    {
        try {
            // Use the base configSet name (same as base collection)
            $baseConfigSet = 'openregister'; // This should match the configSet name from SolrSetup
            
            // Create collection using Collections API
            $success = $this->createCollection($collectionName, $baseConfigSet);
            
            if ($success) {
                $this->logger->info("Successfully created Solr collection '{$collectionName}' for tenant", [
                    'tenant_id' => $this->tenantId,
                    'configSet' => $baseConfigSet
                ]);
                return true;
            } else {
                $this->logger->error("Failed to create Solr collection '{$collectionName}'", [
                    'tenant_id' => $this->tenantId,
                    'configSet' => $baseConfigSet
                ]);
                return false;
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Exception while creating Solr collection', [
                'collection' => $collectionName,
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Run SOLR setup to ensure proper multi-tenant configuration
     *
     * Sets up SOLR with the necessary configSets and base cores for
     * multi-tenant architecture. Should be run once during app initialization.
     *
     * @return bool True if setup completed successfully
     */
    public function runSolrSetup(): bool
    {
        try {
            // Ensure we have SOLR configuration loaded
            $solrSettings = $this->settingsService->getSolrSettings();
            
            if (!$solrSettings['enabled']) {
                $this->logger->info('SOLR is disabled, skipping setup');
                return false;
            }
            
            // Pass the loaded settings to setup
            $setup = new SolrSetup($solrSettings, $this->logger);
            return $setup->setupSolr();
        } catch (\Exception $e) {
            $this->logger->error('Failed to run SOLR setup', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Ensure SOLR client is initialized (lazy initialization)
     *
     * @return void
     */
    private function ensureClientInitialized(): void
    {
        if (!$this->clientInitialized) {
            $this->initializeClient();
            $this->clientInitialized = true;
        }
    }

    /**
     * Initialize SOLR client with current configuration
     *
     * Sets up the Solarium client based on settings from SettingsService.
     * Automatically detects configuration changes and reinitializes as needed.
     * Uses tenant-specific core names for proper multi-tenant isolation.
     *
     * @return void
     */
    private function initializeClient(): void
    {
        try {
            $solrSettings = $this->settingsService->getSolrSettings();
            
            if (!$solrSettings['enabled']) {
                $this->client = null;
                return;
            }
            
            $this->solrConfig = $solrSettings;
            
            // Generate tenant-specific core name for proper isolation
            $baseCoreName = $this->solrConfig['core'];
            $tenantSpecificCore = $this->getTenantSpecificCoreName($baseCoreName);
            
            // Initialize client first (required for collection management)
            $adapter = new Curl();
            $eventDispatcher = new EventDispatcher();
            $this->client = new Client($adapter, $eventDispatcher);
            
            // Default to base collection initially
            $coreToUse = $baseCoreName;
            
            // SOLR CLOUD: Check if tenant-specific collection exists
            $tenantSpecificCollection = $this->getTenantSpecificCoreName($baseCoreName);
            $collectionExists = $this->collectionExists($tenantSpecificCollection);
            
            if (!$collectionExists) {
                // FALLBACK: Use base collection
                if ($this->collectionExists($baseCoreName)) {
                    $coreToUse = $baseCoreName;
                    $this->logger->warning('Using base collection as fallback (no tenant isolation)', [
                        'tenant_id' => $this->tenantId,
                        'intended_collection' => $tenantSpecificCollection,
                        'fallback_collection' => $baseCoreName
                    ]);
                } else {
                    $this->logger->error('No suitable SOLR collection found', [
                        'tenant_collection' => $tenantSpecificCollection,
                        'base_collection' => $baseCoreName,
                        'tenant_id' => $this->tenantId
                    ]);
                    $coreToUse = $baseCoreName; // Last resort
                }
            } else {
                // SUCCESS: Use tenant-specific collection
                $coreToUse = $tenantSpecificCollection;
                $this->logger->info('Using tenant-specific collection for proper isolation', [
                    'tenant_id' => $this->tenantId,
                    'collection' => $tenantSpecificCollection
                ]);
            }
            
            // Build SOLR endpoint configuration with determined collection
            $endpointConfig = [
                'key' => 'default',
                'scheme' => $this->solrConfig['scheme'],
                'host' => $this->solrConfig['host'],
                'port' => $this->solrConfig['port'],
                'path' => $this->solrConfig['path'],
                'core' => $coreToUse,
                'timeout' => $this->solrConfig['timeout'],
            ];
            
            // Add authentication if configured
            if (!empty($this->solrConfig['username']) && !empty($this->solrConfig['password'])) {
                $endpointConfig['username'] = $this->solrConfig['username'];
                $endpointConfig['password'] = $this->solrConfig['password'];
            }
            
            // Create and configure endpoint
            $endpoint = $this->client->createEndpoint($endpointConfig);
            $this->client->setDefaultEndpoint($endpoint);
            
            $this->logger->info('SOLR client initialized successfully', [
                'host' => $this->solrConfig['host'],
                'port' => $this->solrConfig['port'],
                'base_collection' => $this->solrConfig['core'],
                'active_collection' => $coreToUse,
                'tenant' => $this->tenantId
            ]);
            
        } catch (\Exception $e) {
            $this->client = null;
            $this->stats['errors']++;
            $this->logger->error('Failed to initialize SOLR client', [
                'error' => $e->getMessage(),
                'config' => array_merge($this->solrConfig, ['password' => '[HIDDEN]'])
            ]);
        }
    }

    /**
     * Check if SOLR is available and properly configured
     *
     * @return bool True if SOLR is available for use
     */
    public function isAvailable(): bool
    {
        $this->ensureClientInitialized();
        return $this->client !== null && !empty($this->solrConfig['enabled']);
    }

    /**
     * Test SOLR connection and core availability
     *
     * Performs a ping test to verify SOLR connectivity and core status.
     *
     * @return array{success: bool, message: string, details: array} Connection test results
     */
    public function testConnection(): array
    {
        $this->ensureClientInitialized();
        if (!$this->isAvailable()) {
            return [
                'success' => false,
                'message' => 'SOLR is not enabled or configured',
                'details' => ['enabled' => $this->solrConfig['enabled'] ?? false]
            ];
        }

        try {
            $startTime = microtime(true);
            
            // Create ping query
            $ping = $this->client->createPing();
            $result = $this->client->ping($ping);
            
            $responseTime = (microtime(true) - $startTime) * 1000;
            
            return [
                'success' => true,
                'message' => 'SOLR connection successful',
                'details' => [
                    'response_time_ms' => round($responseTime, 2),
                    'core' => $this->solrConfig['core'],
                    'tenant_id' => $this->tenantId,
                    'status' => $result->getData()
                ]
            ];
            
        } catch (HttpException $e) {
            $this->stats['errors']++;
            return [
                'success' => false,
                'message' => 'SOLR HTTP error: ' . $e->getMessage(),
                'details' => [
                    'http_code' => $e->getCode(),
                    'url' => $this->buildSolrUrl()
                ]
            ];
        } catch (\Exception $e) {
            $this->stats['errors']++;
            return [
                'success' => false,
                'message' => 'SOLR connection failed: ' . $e->getMessage(),
                'details' => ['error_type' => get_class($e)]
            ];
        }
    }

    /**
     * Index a single ObjectEntity in SOLR
     *
     * Converts an ObjectEntity to SOLR document format and indexes it.
     * Automatically handles tenant isolation and schema-based routing.
     *
     * @param ObjectEntity $object  Object to index
     * @param bool        $commit   Whether to commit immediately (default: false)
     *
     * @return bool True if indexing was successful
     */
    public function indexObject(ObjectEntity $object, bool $commit = false): bool
    {
        $this->ensureClientInitialized();
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $startTime = microtime(true);
            
            // Create update query
            $update = $this->client->createUpdate();
            
            // Create document
            $doc = $this->createSolrDocument($update, $object);
            
            // Add document to update query
            $update->addDocument($doc);
            
            // Set commit options based on configuration
            if ($commit || $this->solrConfig['autoCommit']) {
                if ($this->solrConfig['commitWithin'] > 0) {
                    $update->addCommit(false, false, false, $this->solrConfig['commitWithin']);
                } else {
                    $update->addCommit();
                }
            }
            
            // Execute update
            $result = $this->client->update($update);
            
            $this->stats['indexes']++;
            $this->stats['index_time'] += (microtime(true) - $startTime);
            
            if ($this->solrConfig['enableLogging']) {
                $this->logger->debug('Object indexed in SOLR', [
                    'object_id' => $object->getId(),
                    'object_uuid' => $object->getUuid(),
                    'schema_id' => $object->getSchema(),
                    'register_id' => $object->getRegister(),
                    'tenant_id' => $this->tenantId,
                    'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
                ]);
            }
            
            return $result->getStatus() === 0;
            
        } catch (\Exception $e) {
            $this->stats['errors']++;
            $this->logger->error('Failed to index object in SOLR', [
                'object_id' => $object->getId(),
                'error' => $e->getMessage(),
                'tenant_id' => $this->tenantId
            ]);
            return false;
        }
    }

    /**
     * Bulk index multiple ObjectEntity objects
     *
     * Efficiently indexes multiple objects in a single SOLR request
     * for improved performance during bulk operations.
     *
     * @param array<ObjectEntity> $objects Array of objects to index
     * @param bool               $commit   Whether to commit after indexing
     *
     * @return array{indexed: int, errors: int, execution_time_ms: float} Indexing results
     */
    public function bulkIndexObjects(array $objects, bool $commit = true): array
    {
        $this->ensureClientInitialized();
        if (!$this->isAvailable() || empty($objects)) {
            return ['indexed' => 0, 'errors' => 0, 'execution_time_ms' => 0.0];
        }

        $startTime = microtime(true);
        $indexed = 0;
        $errors = 0;

        try {
            // Create update query
            $update = $this->client->createUpdate();
            
            // Convert objects to SOLR documents
            foreach ($objects as $object) {
                if (!$object instanceof ObjectEntity) {
                    $errors++;
                    continue;
                }
                
                try {
                    $doc = $this->createSolrDocument($update, $object);
                    $update->addDocument($doc);
                    $indexed++;
                } catch (\Exception $e) {
                    $errors++;
                    $this->logger->warning('Failed to create SOLR document for object', [
                        'object_id' => $object->getId(),
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            if ($indexed > 0) {
                // Set commit options
                if ($commit) {
                if ($this->solrConfig['commitWithin'] > 0) {
                    $update->addCommit(false, false, false, $this->solrConfig['commitWithin']);
                    } else {
                        $update->addCommit();
                    }
                }
                
                // Execute bulk update
                $result = $this->client->update($update);
                
                if ($result->getStatus() !== 0) {
                    $errors = $indexed;
                    $indexed = 0;
                }
            }
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->stats['indexes'] += $indexed;
            $this->stats['errors'] += $errors;
            $this->stats['index_time'] += ($executionTime / 1000);
            
            $this->logger->info('Bulk SOLR indexing completed', [
                'total_objects' => count($objects),
                'indexed' => $indexed,
                'errors' => $errors,
                'execution_time_ms' => round($executionTime, 2),
                'tenant_id' => $this->tenantId
            ]);
            
            return [
                'indexed' => $indexed,
                'errors' => $errors,
                'execution_time_ms' => round($executionTime, 2)
            ];
            
        } catch (\Exception $e) {
            $this->stats['errors'] += count($objects);
            $this->logger->error('Bulk SOLR indexing failed', [
                'total_objects' => count($objects),
                'error' => $e->getMessage(),
                'tenant_id' => $this->tenantId
            ]);
            
            return [
                'indexed' => 0,
                'errors' => count($objects),
                'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ];
        }
    }

    /**
     * Delete object from SOLR index
     *
     * Removes an object from the SOLR index using its unique identifier.
     *
     * @param string|int $objectId Object ID or UUID to delete
     * @param bool      $commit    Whether to commit immediately
     *
     * @return bool True if deletion was successful
     */
    public function deleteObject(string|int $objectId, bool $commit = false): bool
    {
        $this->ensureClientInitialized();
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $startTime = microtime(true);
            
            // Create update query
            $update = $this->client->createUpdate();
            
            // Create delete query with tenant isolation
            $deleteQuery = sprintf(
                'id:%s AND tenant_id:%s',
                $this->escapeSolrValue($objectId),
                $this->escapeSolrValue($this->tenantId)
            );
            
            $update->addDeleteQuery($deleteQuery);
            
            if ($commit || $this->solrConfig['autoCommit']) {
                $update->addCommit();
            }
            
            $result = $this->client->update($update);
            
            $this->stats['deletes']++;
            
            if ($this->solrConfig['enableLogging']) {
                $this->logger->debug('Object deleted from SOLR', [
                    'object_id' => $objectId,
                    'tenant_id' => $this->tenantId,
                    'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
                ]);
            }
            
            return $result->getStatus() === 0;
            
        } catch (\Exception $e) {
            $this->stats['errors']++;
            $this->logger->error('Failed to delete object from SOLR', [
                'object_id' => $objectId,
                'error' => $e->getMessage(),
                'tenant_id' => $this->tenantId
            ]);
            return false;
        }
    }

    /**
     * Search objects in SOLR with advanced query capabilities
     *
     * Performs full-text search with faceting, filtering, and sorting.
     * Returns results compatible with existing ObjectEntity expectations.
     *
     * @param array $searchParams Search parameters
     *
     * @return array{objects: array<ObjectEntity>, facets: array, total: int, execution_time_ms: float} Search results
     *
     * @phpstan-param array{
     *   q?: string,
     *   fq?: array<string>,
     *   sort?: string,
     *   start?: int,
     *   rows?: int,
     *   facet?: array<string>,
     *   register?: int,
     *   schema?: int,
     *   boost?: array<string, float>
     * } $searchParams
     * @phpstan-return array{objects: array<ObjectEntity>, facets: array, total: int, execution_time_ms: float}
     */
    public function searchObjects(array $searchParams): array
    {
        $this->ensureClientInitialized();
        if (!$this->isAvailable()) {
            return ['objects' => [], 'facets' => [], 'total' => 0, 'execution_time_ms' => 0.0];
        }

        try {
            $startTime = microtime(true);
            
            // Create select query
            $query = $this->client->createSelect();
            
            // Build query string with tenant isolation
            $queryString = $this->buildQueryString($searchParams);
            $query->setQuery($queryString);
            
            // Set pagination
            $query->setStart($searchParams['start'] ?? 0);
            $query->setRows($searchParams['rows'] ?? 10);
            
            // Add filter queries
            $this->addFilterQueries($query, $searchParams);
            
            // Add sorting
            $this->addSorting($query, $searchParams);
            
            // Add faceting
            $this->addFaceting($query, $searchParams);
            
            // Execute search
            $resultSet = $this->client->select($query);
            
            // Convert SOLR documents back to ObjectEntity objects
            $objects = $this->convertSolrResultsToObjects($resultSet);
            
            // Extract facets
            $facets = $this->extractFacets($resultSet);
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->stats['searches']++;
            $this->stats['search_time'] += ($executionTime / 1000);
            
            if ($this->solrConfig['enableLogging']) {
                $this->logger->debug('SOLR search executed', [
                    'query' => $queryString,
                    'total_found' => $resultSet->getNumFound(),
                    'returned' => count($objects),
                    'execution_time_ms' => round($executionTime, 2),
                    'tenant_id' => $this->tenantId
                ]);
            }
            
            return [
                'objects' => $objects,
                'facets' => $facets,
                'total' => $resultSet->getNumFound(),
                'execution_time_ms' => round($executionTime, 2)
            ];
            
        } catch (\Exception $e) {
            $this->stats['errors']++;
            $this->logger->error('SOLR search failed', [
                'search_params' => $searchParams,
                'error' => $e->getMessage(),
                'tenant_id' => $this->tenantId
            ]);
            
            return ['objects' => [], 'facets' => [], 'total' => 0, 'execution_time_ms' => 0.0];
        }
    }

    /**
     * Clear all documents from SOLR core for current tenant
     *
     * **DANGEROUS OPERATION**: Removes all indexed objects for this tenant.
     * Use with extreme caution.
     *
     * @return bool True if successful
     */
    public function clearIndex(): bool
    {
        $this->ensureClientInitialized();
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $update = $this->client->createUpdate();
            
            // Delete all documents for this tenant
            $deleteQuery = sprintf('tenant_id:%s', $this->escapeSolrValue($this->tenantId));
            $update->addDeleteQuery($deleteQuery);
            $update->addCommit();
            
            $result = $this->client->update($update);
            
            $this->logger->warning('SOLR index cleared for tenant', [
                'tenant_id' => $this->tenantId
            ]);
            
            return $result->getStatus() === 0;
            
        } catch (\Exception $e) {
            $this->stats['errors']++;
            $this->logger->error('Failed to clear SOLR index', [
                'error' => $e->getMessage(),
                'tenant_id' => $this->tenantId
            ]);
            return false;
        }
    }

    /**
     * Get SOLR service statistics
     *
     * @return array Service statistics and performance metrics
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'available' => $this->isAvailable(),
            'tenant_id' => $this->tenantId,
            'config' => array_merge($this->solrConfig, ['password' => '[HIDDEN]']),
            'avg_search_time' => $this->stats['searches'] > 0 ? 
                round($this->stats['search_time'] / $this->stats['searches'], 4) : 0,
            'avg_index_time' => $this->stats['indexes'] > 0 ? 
                round($this->stats['index_time'] / $this->stats['indexes'], 4) : 0,
        ]);
    }

    /**
     * Get comprehensive SOLR dashboard statistics
     * 
     * Provides detailed metrics for the SOLR Search Management dashboard
     * including core statistics, performance metrics, and health indicators.
     *
     * @return array{overview: array, cores: array, performance: array, health: array, operations: array}
     */
    public function getDashboardStats(): array
    {
        $this->ensureClientInitialized();
        
        $baseStats = $this->getStats();
        $connection = $this->testConnection();
        
        // Core overview statistics
        $overview = [
            'available' => $this->isAvailable(),
            'connection_status' => $connection['success'] ? 'healthy' : 'error',
            'response_time_ms' => $connection['details']['response_time_ms'] ?? 0,
            'total_documents' => $this->getDocumentCount(),
            'index_size' => $this->getIndexSize(),
            'last_commit' => $this->getLastCommitTime(),
        ];
        
        // Core information
        $cores = [
            'active_core' => $this->solrConfig['core'] ?? 'unknown',
            'core_status' => $connection['success'] ? 'active' : 'inactive',
            'tenant_id' => $this->tenantId,
            'endpoint_url' => $this->buildSolrUrl(),
        ];
        
        // Performance metrics
        $performance = [
            'total_searches' => $baseStats['searches'],
            'total_indexes' => $baseStats['indexes'],
            'total_deletes' => $baseStats['deletes'],
            'avg_search_time_ms' => round($baseStats['avg_search_time'] * 1000, 2),
            'avg_index_time_ms' => round($baseStats['avg_index_time'] * 1000, 2),
            'total_search_time' => round($baseStats['search_time'], 2),
            'total_index_time' => round($baseStats['index_time'], 2),
            'operations_per_sec' => $this->calculateOperationsPerSecond(),
            'error_rate' => $this->calculateErrorRate(),
        ];
        
        // Health indicators
        $health = [
            'status' => $this->getHealthStatus(),
            'uptime' => $this->getUptime(),
            'memory_usage' => $this->getMemoryUsage(),
            'disk_usage' => $this->getDiskUsage(),
            'warnings' => $this->getHealthWarnings(),
            'last_optimization' => $this->getLastOptimization(),
        ];
        
        // Recent operations
        $operations = [
            'recent_activity' => $this->getRecentActivity(),
            'queue_status' => $this->getQueueStatus(),
            'commit_frequency' => $this->getCommitFrequency(),
            'optimization_needed' => $this->isOptimizationNeeded(),
        ];
        
        return [
            'overview' => $overview,
            'cores' => $cores,
            'performance' => $performance,
            'health' => $health,
            'operations' => $operations,
            'generated_at' => date('c'),
        ];
    }

    /**
     * Get document count from SOLR core
     *
     * @return int Number of documents in the index
     */
    public function getDocumentCount(): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        try {
            $query = $this->client->createSelect();
            $query->setQuery(sprintf('tenant_id:%s', $this->escapeSolrValue($this->tenantId)));
            $query->setRows(0); // Only count, don't return documents
            
            $resultSet = $this->client->select($query);
            return $resultSet->getNumFound();
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get document count from SOLR', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Get approximate index size
     *
     * @return string Human-readable index size
     */
    public function getIndexSize(): string
    {
        if (!$this->isAvailable()) {
            return '0 B';
        }

        try {
            // This is an approximation - actual size would require SOLR admin API
            $docCount = $this->getDocumentCount();
            $estimatedSize = $docCount * 1024; // Rough estimate of 1KB per document
            
            return $this->formatBytes($estimatedSize);
            
        } catch (\Exception $e) {
            return '0 B';
        }
    }

    /**
     * Get last commit time (placeholder - would need SOLR admin API for real data)
     *
     * @return string Last commit timestamp
     */
    public function getLastCommitTime(): string
    {
        // In a real implementation, this would query SOLR admin API
        // For now, return a reasonable placeholder
        return date('c', time() - 300); // Assume last commit was 5 minutes ago
    }

    /**
     * Calculate operations per second
     *
     * @return float Operations per second
     */
    private function calculateOperationsPerSecond(): float
    {
        $totalOps = $this->stats['searches'] + $this->stats['indexes'] + $this->stats['deletes'];
        $totalTime = $this->stats['search_time'] + $this->stats['index_time'];
        
        return $totalTime > 0 ? round($totalOps / $totalTime, 2) : 0.0;
    }

    /**
     * Calculate error rate percentage
     *
     * @return float Error rate as percentage
     */
    private function calculateErrorRate(): float
    {
        $totalOps = $this->stats['searches'] + $this->stats['indexes'] + $this->stats['deletes'];
        
        return $totalOps > 0 ? round(($this->stats['errors'] / ($totalOps + $this->stats['errors'])) * 100, 2) : 0.0;
    }

    /**
     * Get overall health status
     *
     * @return string Health status: 'healthy', 'warning', 'critical'
     */
    private function getHealthStatus(): string
    {
        if (!$this->isAvailable()) {
            return 'critical';
        }
        
        $connection = $this->testConnection();
        if (!$connection['success']) {
            return 'critical';
        }
        
        $errorRate = $this->calculateErrorRate();
        if ($errorRate > 10) {
            return 'critical';
        } elseif ($errorRate > 5) {
            return 'warning';
        }
        
        return 'healthy';
    }

    /**
     * Get uptime (placeholder)
     *
     * @return string Uptime description
     */
    private function getUptime(): string
    {
        // Placeholder - would need SOLR admin API for real uptime
        return '24h 15m';
    }

    /**
     * Get memory usage (placeholder)
     *
     * @return array Memory usage information
     */
    private function getMemoryUsage(): array
    {
        // Placeholder - would need SOLR admin API for real memory stats
        return [
            'used' => '256 MB',
            'max' => '1 GB',
            'percentage' => 25.6
        ];
    }

    /**
     * Get disk usage (placeholder)
     *
     * @return array Disk usage information
     */
    private function getDiskUsage(): array
    {
        // Placeholder - would need SOLR admin API for real disk stats
        return [
            'used' => '1.2 GB',
            'available' => '8.8 GB', 
            'percentage' => 12.0
        ];
    }

    /**
     * Get health warnings
     *
     * @return array List of health warnings
     */
    private function getHealthWarnings(): array
    {
        $warnings = [];
        
        if (!$this->isAvailable()) {
            $warnings[] = 'SOLR service is not available';
        }
        
        if ($this->calculateErrorRate() > 5) {
            $warnings[] = 'High error rate detected';
        }
        
        if ($this->isOptimizationNeeded()) {
            $warnings[] = 'Index optimization recommended';
        }
        
        return $warnings;
    }

    /**
     * Get last optimization time (placeholder)
     *
     * @return string Last optimization timestamp
     */
    private function getLastOptimization(): string
    {
        // Placeholder - would track this in real implementation
        return date('c', time() - 86400); // Assume last optimization was 1 day ago
    }

    /**
     * Get recent activity
     *
     * @return array Recent operations
     */
    private function getRecentActivity(): array
    {
        return [
            [
                'type' => 'index',
                'count' => $this->stats['indexes'],
                'timestamp' => date('c', time() - 300),
                'status' => 'success'
            ],
            [
                'type' => 'search',
                'count' => $this->stats['searches'],
                'timestamp' => date('c', time() - 180),
                'status' => 'success'
            ],
            [
                'type' => 'delete',
                'count' => $this->stats['deletes'],
                'timestamp' => date('c', time() - 120),
                'status' => 'success'
            ]
        ];
    }

    /**
     * Get queue status (placeholder)
     *
     * @return array Queue information
     */
    private function getQueueStatus(): array
    {
        return [
            'pending_operations' => 0,
            'processing' => false,
            'last_processed' => date('c', time() - 60)
        ];
    }

    /**
     * Get commit frequency information
     *
     * @return array Commit frequency stats
     */
    private function getCommitFrequency(): array
    {
        return [
            'auto_commit' => $this->solrConfig['autoCommit'] ?? false,
            'commit_within' => $this->solrConfig['commitWithin'] ?? 0,
            'last_commit' => $this->getLastCommitTime()
        ];
    }

    /**
     * Check if optimization is needed
     *
     * @return bool True if optimization is recommended
     */
    private function isOptimizationNeeded(): bool
    {
        // Simple heuristic - optimize if we have many documents and haven't optimized recently
        $docCount = $this->getDocumentCount();
        return $docCount > 10000; // Suggest optimization for large indexes
    }

    /**
     * Format bytes to human readable format
     *
     * @param int $bytes Bytes to format
     *
     * @return string Human readable size
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Complete warmup process: mirror schemas to SOLR and index objects
     *
     * @param array $schemas Array of Schema entities to mirror (optional)
     * @param int   $maxObjects Maximum number of objects to index per schema (0 = all)
     * 
     * @return array{success: bool, operations: array, execution_time_ms: float} Warmup results
     */
    public function warmupIndex(array $schemas = [], int $maxObjects = 0, string $mode = 'serial', bool $collectErrors = false): array
    {
        $this->ensureClientInitialized();
        if (!$this->isAvailable()) {
            return [
                'success' => false,
                'operations' => [],
                'execution_time_ms' => 0.0,
                'error' => 'SOLR is not available'
            ];
        }

        $overallStartTime = microtime(true);
        $operations = [];
        $timing = [];
        
        try {
            // 1. Test connection
            $stageStart = microtime(true);
            $this->logger->info('🔗 SOLR WARMUP - Stage 1: Testing connection');
            
            $connectionTest = $this->testConnection();
            $operations['connection_test'] = $connectionTest['success'];
            
            $stageEnd = microtime(true);
            $timing['connection_test'] = round(($stageEnd - $stageStart) * 1000, 2) . 'ms';
            $this->logger->info('✅ Connection test complete', [
                'success' => $connectionTest['success'],
                'duration' => $timing['connection_test']
            ]);
            
            // 2. Mirror schemas to SOLR (if schemas provided)
            if (!empty($schemas)) {
                $stageStart = microtime(true);
                $this->logger->info('🏗️  SOLR WARMUP - Stage 2: Mirroring schemas', [
                    'schema_count' => count($schemas)
                ]);
                
                $mirrorResult = $this->mirrorSchemasToSolr($schemas);
                $operations['schema_mirroring'] = $mirrorResult['success'];
                $operations['schemas_processed'] = $mirrorResult['schemas_processed'];
                $operations['fields_created'] = $mirrorResult['fields_created'];
                
                if (!$mirrorResult['success']) {
                    $operations['mirror_errors'] = $mirrorResult['errors'];
                }
                
                $stageEnd = microtime(true);
                $timing['schema_mirroring'] = round(($stageEnd - $stageStart) * 1000, 2) . 'ms';
                $this->logger->info('✅ Schema mirroring complete', [
                    'success' => $mirrorResult['success'],
                    'schemas_processed' => $operations['schemas_processed'],
                    'duration' => $timing['schema_mirroring']
                ]);
            } else {
                $timing['schema_mirroring'] = '0ms (skipped)';
                $this->logger->info('⏭️  Schema mirroring skipped (no schemas provided)');
            }
            
            // 3. Batch index objects from database (if maxObjects > 0)
            if ($maxObjects > 0) {
                $stageStart = microtime(true);
                $this->logger->info('📦 SOLR WARMUP - Stage 3: Bulk indexing objects', [
                    'max_objects' => $maxObjects
                ]);
                
                try {
                    // **MODE SWITCHING**: Use serial or parallel based on mode parameter
                    if ($mode === 'parallel') {
                        $this->logger->info('Using PARALLEL bulk indexing mode');
                        $indexResult = $this->guzzleSolrService->bulkIndexFromDatabaseParallel(1000, $maxObjects, 5);
                    } else {
                        $this->logger->info('Using SERIAL bulk indexing mode');
                        $indexResult = $this->guzzleSolrService->bulkIndexFromDatabase(1000, $maxObjects);
                    }
                    
                    $operations['object_indexing'] = $indexResult['success'] ?? true;
                    $operations['objects_indexed'] = $indexResult['indexed'] ?? 0;
                    $operations['total_objects_found'] = $indexResult['total'] ?? 0;
                    $operations['batches_processed'] = $indexResult['batches'] ?? 0;
                    $operations['execution_mode'] = $mode;
                    
                    if (isset($indexResult['errors']) && !empty($indexResult['errors'])) {
                        $operations['indexing_errors'] = $indexResult['errors'];
                    }
                } catch (\Exception $e) {
                    $operations['object_indexing'] = false;
                    $operations['indexing_error'] = $e->getMessage();
                    $this->logger->error('Bulk indexing failed during warmup', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    
                    // **ERROR COLLECTION MODE**: Collect errors instead of re-throwing
                    if ($collectErrors) {
                        if (!isset($operations['collected_errors'])) {
                            $operations['collected_errors'] = [];
                        }
                        $operations['collected_errors'][] = [
                            'type' => 'bulk_indexing_error',
                            'message' => $e->getMessage(),
                            'class' => get_class($e),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'timestamp' => date('c')
                        ];
                    } else {
                        // **DEFAULT BEHAVIOR**: Re-throw exception for immediate visibility
                        throw $e;
                    }
                }
                
                $stageEnd = microtime(true);
                $timing['object_indexing'] = round(($stageEnd - $stageStart) * 1000, 2) . 'ms';
                $this->logger->info('✅ Object indexing complete', [
                    'success' => $operations['object_indexing'],
                    'objects_processed' => $operations['objects_processed'] ?? 0,
                    'objects_indexed' => $operations['objects_indexed'] ?? 0,
                    'batches_processed' => $operations['batches_processed'] ?? 0,
                    'duration' => $timing['object_indexing']
                ]);
            } else {
                $timing['object_indexing'] = '0ms (skipped)';
                $this->logger->info('⏭️  Object indexing skipped (maxObjects = 0)');
            }
            
            // 4. Perform sample search queries to warm caches
            $stageStart = microtime(true);
            $this->logger->info('🔥 SOLR WARMUP - Stage 4: Cache warming with sample queries');
            
            $warmupQueries = [
                ['q' => '*:*', 'rows' => 1],
                ['q' => '*:*', 'rows' => 10, 'facet' => ['register_id', 'schema_id']],
                ['q' => 'name:*', 'rows' => 5],
            ];
            
            $successfulQueries = 0;
            foreach ($warmupQueries as $i => $query) {
                try {
                    $this->searchObjects($query);
                    $operations["warmup_query_$i"] = true;
                    $successfulQueries++;
                } catch (\Exception $e) {
                    $operations["warmup_query_$i"] = false;
                    $this->logger->warning("Warmup query $i failed", ['error' => $e->getMessage()]);
                }
            }
            
            $stageEnd = microtime(true);
            $timing['cache_warming'] = round(($stageEnd - $stageStart) * 1000, 2) . 'ms';
            $this->logger->info('✅ Cache warming complete', [
                'successful_queries' => $successfulQueries,
                'total_queries' => count($warmupQueries),
                'duration' => $timing['cache_warming']
            ]);
            
            // 5. Final commit and optimization
            $stageStart = microtime(true);
            $this->logger->info('💾 SOLR WARMUP - Stage 5: Final commit and optimization');
            
            $operations['commit'] = $this->commit();
            
            if ($maxObjects > 1000) { // Only optimize for large datasets
                $operations['optimize'] = $this->optimize();
            }
            
            $stageEnd = microtime(true);
            $timing['commit_optimize'] = round(($stageEnd - $stageStart) * 1000, 2) . 'ms';
            $this->logger->info('✅ Commit and optimization complete', [
                'commit_success' => $operations['commit'],
                'optimize_performed' => isset($operations['optimize']),
                'duration' => $timing['commit_optimize']
            ]);
            
            $overallEndTime = microtime(true);
            $overallDuration = round(($overallEndTime - $overallStartTime) * 1000, 2);
            $timing['total'] = $overallDuration . 'ms';
            
            $this->logger->info('🎯 SOLR WARMUP - FINAL RESULTS', [
                'overall_duration' => $overallDuration . 'ms',
                'objects_indexed' => $operations['objects_indexed'] ?? 0,
                'timing_breakdown' => $timing,
                'tenant_id' => $this->tenantId
            ]);
            
            return [
                'success' => true,
                'operations' => $operations,
                'timing' => $timing,
                'execution_time_ms' => $overallDuration,
                'stats' => [
                    'totalProcessed' => $operations['objects_processed'] ?? 0,
                    'totalIndexed' => $operations['objects_indexed'] ?? 0,
                    'totalObjectsFound' => $operations['total_objects_found'] ?? 0,
                    'batchesProcessed' => $operations['batches_processed'] ?? 0,
                    'schemasProcessed' => $operations['schemas_processed'] ?? 0,
                    'duration' => $overallDuration . 'ms'
                ]
            ];
            
        } catch (\Exception $e) {
            $this->stats['errors']++;
            $this->logger->error('SOLR complete warmup failed', [
                'error' => $e->getMessage(),
                'tenant_id' => $this->tenantId
            ]);
            
            return [
                'success' => false,
                'operations' => $operations,
                'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Mirror OpenRegister schemas to SOLR field definitions
     *
     * This method analyzes all schemas and creates appropriate SOLR field definitions
     * to ensure proper field typing and validation during document indexing.
     *
     * @param array $schemas Array of Schema entities to mirror
     *
     * @return array Results of schema mirroring process
     */
    public function mirrorSchemasToSolr(array $schemas): array
    {
        $results = [
            'success' => true,
            'schemas_processed' => 0,
            'fields_created' => 0,
            'fields_updated' => 0,
            'errors' => []
        ];

        $this->logger->info('Starting schema mirroring to SOLR', [
            'total_schemas' => count($schemas)
        ]);

        foreach ($schemas as $schema) {
            try {
                $schemaResult = $this->mirrorSingleSchemaToSolr($schema);
                
                $results['schemas_processed']++;
                $results['fields_created'] += $schemaResult['fields_created'];
                $results['fields_updated'] += $schemaResult['fields_updated'];
                
                if (!$schemaResult['success']) {
                    $results['errors'] = array_merge($results['errors'], $schemaResult['errors']);
                }
                
            } catch (\Exception $e) {
                $results['errors'][] = [
                    'schema_id' => $schema->getId(),
                    'schema_title' => $schema->getTitle(),
                    'error' => $e->getMessage()
                ];
                $this->logger->error('Schema mirroring failed', [
                    'schema_id' => $schema->getId(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        $results['success'] = empty($results['errors']);
        
        $this->logger->info('Schema mirroring completed', [
            'schemas_processed' => $results['schemas_processed'],
            'fields_created' => $results['fields_created'],
            'success' => $results['success']
        ]);

        return $results;
    }

    /**
     * Mirror a single schema to SOLR field definitions
     *
     * @param \OCA\OpenRegister\Db\Schema $schema The schema to mirror
     *
     * @return array Results of single schema mirroring
     */
    private function mirrorSingleSchemaToSolr(\OCA\OpenRegister\Db\Schema $schema): array
    {
        $result = [
            'success' => true,
            'fields_created' => 0,
            'fields_updated' => 0,
            'errors' => []
        ];

        $properties = $schema->getProperties();
        
        if (empty($properties)) {
            $this->logger->debug('Schema has no properties to mirror', [
                'schema_id' => $schema->getId(),
                'schema_title' => $schema->getTitle()
            ]);
            return $result;
        }

        foreach ($properties as $propertyName => $propertyDefinition) {
            try {
                $fieldDefinitions = $this->generateSolrFieldDefinitions($propertyName, $propertyDefinition);
                
                foreach ($fieldDefinitions as $fieldDef) {
                    // For now, we rely on SOLR's schemaless mode to auto-create fields
                    // In the future, we could explicitly create fields via SOLR Schema API
                    $this->logger->debug('Field definition prepared for SOLR', [
                        'schema_id' => $schema->getId(),
                        'property' => $propertyName,
                        'field_name' => $fieldDef['name'],
                        'field_type' => $fieldDef['type']
                    ]);
                }
                
                $result['fields_created'] += count($fieldDefinitions);
                
            } catch (\Exception $e) {
                $result['errors'][] = [
                    'property' => $propertyName,
                    'error' => $e->getMessage()
                ];
                $result['success'] = false;
            }
        }

        return $result;
    }

    /**
     * Generate SOLR field definitions for a schema property
     *
     * @param string $propertyName The property name
     * @param array  $propertyDefinition The property definition from schema
     *
     * @return array Array of SOLR field definitions
     */
    private function generateSolrFieldDefinitions(string $propertyName, array $propertyDefinition): array
    {
        $fieldDefinitions = [];
        $propertyType = $propertyDefinition['type'] ?? 'string';
        $format = $propertyDefinition['format'] ?? null;

        switch ($propertyType) {
            case 'string':
                if ($format === 'date' || $format === 'date-time' || $format === 'datetime') {
                    $fieldDefinitions[] = [
                        'name' => $propertyName . '_dt',
                        'type' => 'pdate',
                        'description' => 'Date/datetime field for ' . $propertyName
                    ];
                } else {
                    $fieldDefinitions[] = [
                        'name' => $propertyName . '_s',
                        'type' => 'string',
                        'description' => 'String field for ' . $propertyName
                    ];
                    $fieldDefinitions[] = [
                        'name' => $propertyName . '_t',
                        'type' => 'text_general',
                        'description' => 'Text field for ' . $propertyName
                    ];
                }
                break;

            case 'integer':
                $fieldDefinitions[] = [
                    'name' => $propertyName . '_i',
                    'type' => 'pint',
                    'description' => 'Integer field for ' . $propertyName
                ];
                break;

            case 'number':
                $fieldDefinitions[] = [
                    'name' => $propertyName . '_f',
                    'type' => 'pfloat',
                    'description' => 'Float field for ' . $propertyName
                ];
                break;

            case 'boolean':
                $fieldDefinitions[] = [
                    'name' => $propertyName . '_b',
                    'type' => 'boolean',
                    'description' => 'Boolean field for ' . $propertyName
                ];
                break;

            case 'array':
                $itemType = $propertyDefinition['items']['type'] ?? 'string';
                if ($itemType === 'string') {
                    $fieldDefinitions[] = [
                        'name' => $propertyName . '_ss',
                        'type' => 'strings',
                        'description' => 'Multi-valued string field for ' . $propertyName
                    ];
                } elseif ($itemType === 'integer') {
                    $fieldDefinitions[] = [
                        'name' => $propertyName . '_is',
                        'type' => 'pints',
                        'description' => 'Multi-valued integer field for ' . $propertyName
                    ];
                } elseif ($itemType === 'number') {
                    $fieldDefinitions[] = [
                        'name' => $propertyName . '_fs',
                        'type' => 'pfloats',
                        'description' => 'Multi-valued float field for ' . $propertyName
                    ];
                } else {
                    // Complex array - store as JSON
                    $fieldDefinitions[] = [
                        'name' => $propertyName . '_json',
                        'type' => 'text_general',
                        'description' => 'JSON field for complex array ' . $propertyName
                    ];
                }
                break;

            case 'object':
                // Check if it's a reference object (has UUID)
                if (isset($propertyDefinition['properties']['value'])) {
                    $fieldDefinitions[] = [
                        'name' => $propertyName . '_ref',
                        'type' => 'string',
                        'description' => 'Reference UUID field for ' . $propertyName
                    ];
                } else {
                    // Complex object - store as JSON
                    $fieldDefinitions[] = [
                        'name' => $propertyName . '_json',
                        'type' => 'text_general',
                        'description' => 'JSON field for object ' . $propertyName
                    ];
                }
                break;

            case 'file':
                $fieldDefinitions[] = [
                    'name' => $propertyName . '_file',
                    'type' => 'text_general',
                    'description' => 'File metadata field for ' . $propertyName
                ];
                $fieldDefinitions[] = [
                    'name' => $propertyName . '_filename_s',
                    'type' => 'string',
                    'description' => 'Filename field for ' . $propertyName
                ];
                $fieldDefinitions[] = [
                    'name' => $propertyName . '_mimetype_s',
                    'type' => 'string',
                    'description' => 'MIME type field for ' . $propertyName
                ];
                break;

            default:
                // Unknown type - default to string
                $fieldDefinitions[] = [
                    'name' => $propertyName . '_s',
                    'type' => 'string',
                    'description' => 'String field for unknown type ' . $propertyName
                ];
        }

        return $fieldDefinitions;
    }

    // ========================================
    // FAST COLLECTION METHODS FOR HIGH PERFORMANCE
    // ========================================

    /**
     * Fast bulk indexing with optimized chunking for large collections
     *
     * Automatically chunks large collections into optimal batch sizes for maximum throughput.
     * Uses streaming updates and intelligent commit strategies for best performance.
     *
     * @param array<ObjectEntity> $objects      Collection of objects to index
     * @param int                $chunkSize     Objects per batch (default: 1000)
     * @param int                $commitEvery   Commit after N batches (default: 5)
     * @param bool               $optimize      Run optimize after completion (default: false)
     *
     * @return array{total: int, indexed: int, errors: int, batches: int, execution_time_ms: float, throughput_per_sec: float}
     */
    public function fastBulkIndex(array $objects, int $chunkSize = 1000, int $commitEvery = 5, bool $optimize = false): array
    {
        $this->ensureClientInitialized();
        if (!$this->isAvailable() || empty($objects)) {
            return ['total' => 0, 'indexed' => 0, 'errors' => 0, 'batches' => 0, 'execution_time_ms' => 0.0, 'throughput_per_sec' => 0.0];
        }

        $startTime = microtime(true);
        $totalObjects = count($objects);
        $totalIndexed = 0;
        $totalErrors = 0;
        $batchCount = 0;

        // Process in optimized chunks
        $chunks = array_chunk($objects, $chunkSize);
        
        foreach ($chunks as $chunk) {
            $batchCount++;
            
            try {
                $result = $this->bulkIndexObjects($chunk, false); // Don't commit each batch
                $totalIndexed += $result['indexed'];
                $totalErrors += $result['errors'];
                
                // Commit periodically for memory management
                if ($batchCount % $commitEvery === 0) {
                    $this->commit();
                }
                
            } catch (\Exception $e) {
                $totalErrors += count($chunk);
                $this->logger->error('Fast bulk index batch failed', [
                    'batch' => $batchCount,
                    'batch_size' => count($chunk),
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Final commit and optional optimize
        try {
            $this->commit();
            if ($optimize) {
                $this->optimize();
            }
        } catch (\Exception $e) {
            $this->logger->warning('Fast bulk index final commit/optimize failed', ['error' => $e->getMessage()]);
        }

        $executionTime = (microtime(true) - $startTime) * 1000;
        $throughput = $totalIndexed > 0 ? round($totalIndexed / ($executionTime / 1000), 2) : 0.0;

        $this->logger->info('Fast bulk indexing completed', [
            'total_objects' => $totalObjects,
            'indexed' => $totalIndexed,
            'errors' => $totalErrors,
            'batches' => $batchCount,
            'chunk_size' => $chunkSize,
            'execution_time_ms' => round($executionTime, 2),
            'throughput_per_sec' => $throughput,
            'tenant_id' => $this->tenantId
        ]);

        return [
            'total' => $totalObjects,
            'indexed' => $totalIndexed,
            'errors' => $totalErrors,
            'batches' => $batchCount,
            'execution_time_ms' => round($executionTime, 2),
            'throughput_per_sec' => $throughput
        ];
    }

    /**
     * Fast search with minimal data transfer - only returns IDs and essential fields
     *
     * Optimized for collection operations where you need to find objects quickly
     * but don't need full object data immediately.
     *
     * @param array  $searchParams Search parameters
     * @param array  $fields       Fields to return (default: ['id', 'object_id', 'uuid'])
     * @param int    $maxResults   Maximum results to return (default: 10000)
     *
     * @return array{ids: array<string>, total: int, execution_time_ms: float}
     */
    public function fastSearchIds(array $searchParams, array $fields = ['id', 'object_id', 'uuid'], int $maxResults = 10000): array
    {
        $this->ensureClientInitialized();
        if (!$this->isAvailable()) {
            return ['ids' => [], 'total' => 0, 'execution_time_ms' => 0.0];
        }

        try {
            $startTime = microtime(true);
            
            // Create lightweight select query
            $query = $this->client->createSelect();
            
            // Build query string with tenant isolation
            $queryString = $this->buildQueryString($searchParams);
            $query->setQuery($queryString);
            
            // Only request essential fields for speed
            $query->setFields($fields);
            
            // Set reasonable limits for performance
            $query->setStart(0);
            $query->setRows(min($maxResults, 50000)); // Cap at 50K for safety
            
            // Add filter queries but skip faceting and complex sorting
            $this->addFilterQueries($query, $searchParams);
            
            // Simple relevance sorting for speed
            $query->addSort('score', 'desc');
            
            // Execute search
            $resultSet = $this->client->select($query);
            
            // Extract just the IDs
            $ids = [];
            foreach ($resultSet as $document) {
                $ids[] = $document->object_id ?? $document->uuid ?? $document->id;
            }
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->stats['searches']++;
            $this->stats['search_time'] += ($executionTime / 1000);
            
            if ($this->solrConfig['enableLogging']) {
                $this->logger->debug('Fast SOLR ID search completed', [
                    'query' => $queryString,
                    'total_found' => $resultSet->getNumFound(),
                    'ids_returned' => count($ids),
                    'execution_time_ms' => round($executionTime, 2),
                    'tenant_id' => $this->tenantId
                ]);
            }
            
            return [
                'ids' => array_filter($ids), // Remove null values
                'total' => $resultSet->getNumFound(),
                'execution_time_ms' => round($executionTime, 2)
            ];
            
        } catch (\Exception $e) {
            $this->stats['errors']++;
            $this->logger->error('Fast SOLR ID search failed', [
                'search_params' => $searchParams,
                'error' => $e->getMessage(),
                'tenant_id' => $this->tenantId
            ]);
            
            return ['ids' => [], 'total' => 0, 'execution_time_ms' => 0.0];
        }
    }

    /**
     * Stream large result sets efficiently without loading all into memory
     *
     * Perfect for processing large collections where you need to iterate through
     * all results but don't want to load everything at once.
     *
     * @param array    $searchParams Search parameters
     * @param callable $processor    Function to process each batch: function(array $objectIds): void
     * @param int      $batchSize    Objects per batch (default: 1000)
     *
     * @return array{processed: int, batches: int, execution_time_ms: float}
     */
    public function streamSearch(array $searchParams, callable $processor, int $batchSize = 1000): array
    {
        $this->ensureClientInitialized();
        if (!$this->isAvailable()) {
            return ['processed' => 0, 'batches' => 0, 'execution_time_ms' => 0.0];
        }

        $startTime = microtime(true);
        $totalProcessed = 0;
        $batchCount = 0;
        $offset = 0;

        try {
            // First, get total count
            $countResult = $this->fastSearchIds(array_merge($searchParams, ['rows' => 0]), ['id'], 1);
            $totalResults = $countResult['total'];

            if ($totalResults === 0) {
                return ['processed' => 0, 'batches' => 0, 'execution_time_ms' => 0.0];
            }

            // Stream in batches
            while ($offset < $totalResults) {
                $batchSearchParams = array_merge($searchParams, [
                    'start' => $offset,
                    'rows' => $batchSize
                ]);

                $batchResult = $this->fastSearchIds($batchSearchParams, ['object_id', 'uuid'], $batchSize);
                
                if (!empty($batchResult['ids'])) {
                    // Process this batch
                    call_user_func($processor, $batchResult['ids']);
                    
                    $totalProcessed += count($batchResult['ids']);
                    $batchCount++;
                }

                $offset += $batchSize;

                // Safety break to prevent infinite loops
                if ($offset > 100000) { // Max 100K results for streaming
                    $this->logger->warning('Stream search safety limit reached', [
                        'total_results' => $totalResults,
                        'processed' => $totalProcessed
                    ]);
                    break;
                }
            }

            $executionTime = (microtime(true) - $startTime) * 1000;
            
            $this->logger->info('Stream search completed', [
                'total_results' => $totalResults,
                'processed' => $totalProcessed,
                'batches' => $batchCount,
                'batch_size' => $batchSize,
                'execution_time_ms' => round($executionTime, 2),
                'tenant_id' => $this->tenantId
            ]);

            return [
                'processed' => $totalProcessed,
                'batches' => $batchCount,
                'execution_time_ms' => round($executionTime, 2)
            ];

        } catch (\Exception $e) {
            $this->stats['errors']++;
            $this->logger->error('Stream search failed', [
                'search_params' => $searchParams,
                'processed' => $totalProcessed,
                'batches' => $batchCount,
                'error' => $e->getMessage(),
                'tenant_id' => $this->tenantId
            ]);

            return [
                'processed' => $totalProcessed,
                'batches' => $batchCount,
                'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ];
        }
    }

    /**
     * Commit changes to SOLR index
     *
     * @return bool True if successful
     */
    public function commit(): bool
    {
        $this->ensureClientInitialized();
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $update = $this->client->createUpdate();
            $update->addCommit();
            $result = $this->client->update($update);
            return $result->getStatus() === 0;
        } catch (\Exception $e) {
            $this->logger->error('SOLR commit failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Optimize SOLR index for better search performance
     *
     * @return bool True if successful
     */
    public function optimize(): bool
    {
        $this->ensureClientInitialized();
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $update = $this->client->createUpdate();
            $update->addOptimize();
            $result = $this->client->update($update);
            return $result->getStatus() === 0;
        } catch (\Exception $e) {
            $this->logger->error('SOLR optimize failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    // ========================================
    // PRIVATE HELPER METHODS
    // ========================================

    /**
     * Create SOLR document from ObjectEntity with schema-aware mapping
     *
     * @param UpdateQuery  $update Update query instance
     * @param ObjectEntity $object Object to convert
     * @param Schema|null  $schema Optional schema for schema-aware mapping
     *
     * @return \Solarium\QueryType\Update\Query\Document Document instance
     */
    private function createSolrDocument(UpdateQuery $update, ObjectEntity $object, ?\OCA\OpenRegister\Db\Schema $schema = null): \Solarium\QueryType\Update\Query\Document
    {
        $doc = $update->createDocument();
        
        $uuid = $object->getUuid() ?: $object->getId();
        
        // Set core identification fields
        $doc->setField('id', $uuid);
        $doc->setField('tenant_id', $this->tenantId);
        
        // Add metadata fields with self_ prefix for consistency
        $doc->setField('self_id', $uuid);
        $doc->setField('self_object_id', $object->getId());
        $doc->setField('self_uuid', $uuid);
        $doc->setField('self_register', $object->getRegister());
        $doc->setField('self_schema', $object->getSchema());
        $doc->setField('self_organisation', $object->getOrganisation());
        $doc->setField('self_name', $object->getName());
        $doc->setField('self_description', $object->getDescription());
        $doc->setField('self_summary', $object->getSummary());
        $doc->setField('self_image', $object->getImage());
        $doc->setField('self_slug', $object->getSlug());
        $doc->setField('self_uri', $object->getUri());
        $doc->setField('self_version', $object->getVersion());
        $doc->setField('self_size', $object->getSize());
        $doc->setField('self_owner', $object->getOwner());
        $doc->setField('self_locked', $object->getLocked());
        $doc->setField('self_folder', $object->getFolder());
        $doc->setField('self_application', $object->getApplication());
        
        // DateTime fields
        $doc->setField('self_created', $object->getCreated() ? $object->getCreated()->format('Y-m-d\TH:i:s\Z') : null);
        $doc->setField('self_updated', $object->getModified() ? $object->getModified()->format('Y-m-d\TH:i:s\Z') : null);
        $doc->setField('self_published', $object->getPublished() ? $object->getPublished()->format('Y-m-d\TH:i:s\Z') : null);
        $doc->setField('self_depublished', $object->getDepublished() ? $object->getDepublished()->format('Y-m-d\TH:i:s\Z') : null);
        
        // Complex fields as JSON
        $doc->setField('self_authorization', $object->getAuthorization() ? json_encode($object->getAuthorization()) : null);
        $doc->setField('self_deleted', $object->getDeleted() ? json_encode($object->getDeleted()) : null);
        $doc->setField('self_validation', $object->getValidation() ? json_encode($object->getValidation()) : null);
        $doc->setField('self_groups', $object->getGroups() ? json_encode($object->getGroups()) : null);
        
        // Index object data with schema-aware mapping if schema is provided
        $objectData = $object->getObject() ?: [];
        if ($schema) {
            // Schema-aware mapping: only properties defined in schema
            $mappedProperties = $this->mapPropertiesUsingSchema($objectData, $schema->getProperties());
            foreach ($mappedProperties as $fieldName => $value) {
                $doc->setField($fieldName, $value);
            }
        } else {
            // Fallback: dynamic field mapping for backward compatibility
            $this->addObjectDataToDocument($doc, $objectData);
        }
        
        // Store complete object data as JSON for exact reconstruction
        $doc->setField('self_object', json_encode($objectData));
        
        // Create full-text search field
        $textContent = $this->extractTextContent($object, $objectData);
        $doc->setField('_text_', $textContent);
        
        return $doc;
    }

    /**
     * Add object data as dynamic fields to SOLR document
     *
     * @param \Solarium\QueryType\Update\Query\Document $doc        SOLR document
     * @param array                                     $objectData Object data array
     * @param string                                    $prefix     Field prefix for nested objects
     *
     * @return void
     */
    private function addObjectDataToDocument(\Solarium\QueryType\Update\Query\Document $doc, array $objectData, string $prefix = ''): void
    {
        foreach ($objectData as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            
            $fieldName = $prefix . $key;
            
            if (is_array($value)) {
                // Handle arrays and nested objects
                if ($this->isAssociativeArray($value)) {
                    // Nested object - recurse with prefix
                    $this->addObjectDataToDocument($doc, $value, $fieldName . '_');
                } else {
                    // Simple array - add as multi-valued field
                    foreach ($value as $item) {
                        if (is_scalar($item)) {
                            $doc->addField($fieldName . '_ss', (string)$item);
                        }
                    }
                }
            } elseif (is_bool($value)) {
                $doc->setField($fieldName . '_b', $value);
            } elseif (is_int($value)) {
                $doc->setField($fieldName . '_i', $value);
            } elseif (is_float($value)) {
                $doc->setField($fieldName . '_f', $value);
            } elseif ($this->isDateString($value)) {
                $doc->setField($fieldName . '_dt', $this->formatDateForSolr($value));
            } else {
                // String value
                $doc->setField($fieldName . '_s', (string)$value);
                
                // Also add to text fields for searching
                if (strlen((string)$value) > 5) {
                    $doc->addField($fieldName . '_txt', (string)$value);
                }
            }
        }
    }

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
        
        // Add name
        if ($object->getName()) {
            $textParts[] = $object->getName();
        }
        
        // Add UUID
        if ($object->getUuid()) {
            $textParts[] = $object->getUuid();
        }
        
        // Extract text from object data
        $this->extractTextFromData($objectData, $textParts);
        
        return implode(' ', array_filter($textParts));
    }

    /**
     * Recursively extract text from object data
     *
     * @param array $data      Data to extract text from
     * @param array $textParts Array to collect text parts
     *
     * @return void
     */
    private function extractTextFromData(array $data, array &$textParts): void
    {
        foreach ($data as $value) {
            if (is_string($value) && strlen($value) > 2) {
                $textParts[] = $value;
            } elseif (is_array($value)) {
                $this->extractTextFromData($value, $textParts);
            }
        }
    }

    /**
     * Map object properties using schema definitions
     *
     * This method processes each property according to its schema definition:
     * - Validates property exists in schema
     * - Maps to appropriate SOLR field type
     * - Handles complex objects and arrays
     * - Filters out undefined properties
     *
     * @param array $objectData       The object's data properties
     * @param array $schemaProperties The schema property definitions
     *
     * @return array Mapped properties for SOLR document
     */
    private function mapPropertiesUsingSchema(array $objectData, array $schemaProperties): array
    {
        $mappedProperties = [];

        // Process each property defined in the schema
        foreach ($schemaProperties as $propertyName => $propertyDefinition) {
            // Skip if property not present in object data
            if (!array_key_exists($propertyName, $objectData)) {
                continue;
            }

            $value = $objectData[$propertyName];
            $propertyType = $propertyDefinition['type'] ?? 'string';

            // Map property based on its schema type
            $mappedFields = $this->mapPropertyByType($propertyName, $value, $propertyDefinition);
            $mappedProperties = array_merge($mappedProperties, $mappedFields);
        }

        $this->logger->debug('Schema-aware property mapping completed', [
            'total_schema_properties' => count($schemaProperties),
            'mapped_properties' => count($mappedProperties),
            'object_properties' => count($objectData)
        ]);

        return $mappedProperties;
    }

    /**
     * Map a single property based on its schema type definition
     *
     * @param string $propertyName The property name
     * @param mixed  $value        The property value
     * @param array  $definition   The schema property definition
     *
     * @return array Mapped SOLR fields for this property
     */
    private function mapPropertyByType(string $propertyName, $value, array $definition): array
    {
        $propertyType = $definition['type'] ?? 'string';
        $format = $definition['format'] ?? null;
        $mappedFields = [];

        switch ($propertyType) {
            case 'string':
                $mappedFields = $this->mapStringProperty($propertyName, $value, $format);
                break;

            case 'integer':
            case 'number':
                $mappedFields = $this->mapNumericProperty($propertyName, $value, $propertyType);
                break;

            case 'boolean':
                $mappedFields = $this->mapBooleanProperty($propertyName, $value);
                break;

            case 'array':
                $mappedFields = $this->mapArrayProperty($propertyName, $value, $definition);
                break;

            case 'object':
                $mappedFields = $this->mapObjectProperty($propertyName, $value, $definition);
                break;

            case 'file':
                $mappedFields = $this->mapFileProperty($propertyName, $value);
                break;

            default:
                // Unknown type - treat as string
                $mappedFields = $this->mapStringProperty($propertyName, $value, null);
                $this->logger->warning('Unknown property type - defaulting to string', [
                    'property' => $propertyName,
                    'type' => $propertyType
                ]);
        }

        return $mappedFields;
    }

    /**
     * Map string property to SOLR fields
     *
     * @param string      $propertyName The property name
     * @param mixed       $value        The property value
     * @param string|null $format       The string format (date, email, etc.)
     *
     * @return array Mapped SOLR fields
     */
    private function mapStringProperty(string $propertyName, $value, ?string $format): array
    {
        if (!is_string($value) && $value !== null) {
            $value = (string) $value;
        }

        $fields = [];

        if ($value !== null && $value !== '') {
            // Handle date/datetime formats specially
            if ($format === 'date' || $format === 'date-time' || $format === 'datetime') {
                $fields[$propertyName . '_dt'] = $this->formatDateForSolr($value);
            } else {
                // Regular string field
                $fields[$propertyName . '_s'] = $value;  // String field for exact matching
                $fields[$propertyName . '_t'] = $value;  // Text field for full-text search
            }
        }

        return $fields;
    }

    /**
     * Map numeric property to SOLR fields
     *
     * @param string $propertyName The property name
     * @param mixed  $value        The property value
     * @param string $type         The numeric type (integer or number)
     *
     * @return array Mapped SOLR fields
     */
    private function mapNumericProperty(string $propertyName, $value, string $type): array
    {
        $fields = [];

        if ($value !== null && is_numeric($value)) {
            if ($type === 'integer') {
                $fields[$propertyName . '_i'] = (int) $value;
            } else {
                $fields[$propertyName . '_f'] = (float) $value;
            }
        }

        return $fields;
    }

    /**
     * Map boolean property to SOLR fields
     *
     * @param string $propertyName The property name
     * @param mixed  $value        The property value
     *
     * @return array Mapped SOLR fields
     */
    private function mapBooleanProperty(string $propertyName, $value): array
    {
        $fields = [];

        if ($value !== null) {
            $fields[$propertyName . '_b'] = (bool) $value;
        }

        return $fields;
    }

    /**
     * Map array property to SOLR fields
     *
     * @param string $propertyName The property name
     * @param mixed  $value        The property value
     * @param array  $definition   The schema property definition
     *
     * @return array Mapped SOLR fields
     */
    private function mapArrayProperty(string $propertyName, $value, array $definition): array
    {
        $fields = [];

        if (!is_array($value)) {
            return $fields;
        }

        // Handle array of simple values
        $itemType = $definition['items']['type'] ?? 'string';
        
        if ($itemType === 'string') {
            $stringValues = array_filter($value, 'is_string');
            if (!empty($stringValues)) {
                $fields[$propertyName . '_ss'] = $stringValues;  // Multi-valued string field
            }
        } elseif ($itemType === 'integer' || $itemType === 'number') {
            $numericValues = array_filter($value, 'is_numeric');
            if (!empty($numericValues)) {
                $suffix = $itemType === 'integer' ? '_is' : '_fs';
                $fields[$propertyName . $suffix] = array_map(
                    $itemType === 'integer' ? 'intval' : 'floatval',
                    $numericValues
                );
            }
        } else {
            // Complex array - store as JSON
            $fields[$propertyName . '_json'] = json_encode($value);
        }

        return $fields;
    }

    /**
     * Map object property to SOLR fields
     *
     * @param string $propertyName The property name
     * @param mixed  $value        The property value
     * @param array  $definition   The schema property definition
     *
     * @return array Mapped SOLR fields
     */
    private function mapObjectProperty(string $propertyName, $value, array $definition): array
    {
        $fields = [];

        if (!is_array($value) && !is_object($value)) {
            return $fields;
        }

        // Convert object to array if needed
        if (is_object($value)) {
            $value = (array) $value;
        }

        // Handle special case: object with UUID reference
        if (isset($value['value']) && is_string($value['value'])) {
            // This is a reference object - store the UUID
            $fields[$propertyName . '_ref'] = $value['value'];
        } else {
            // Complex object - store as JSON for now
            // TODO: Could be enhanced to map nested properties based on schema
            $fields[$propertyName . '_json'] = json_encode($value);
        }

        return $fields;
    }

    /**
     * Map file property to SOLR fields
     *
     * @param string $propertyName The property name
     * @param mixed  $value        The property value
     *
     * @return array Mapped SOLR fields
     */
    private function mapFileProperty(string $propertyName, $value): array
    {
        $fields = [];

        if (is_array($value)) {
            // Store file metadata as JSON
            $fields[$propertyName . '_file'] = json_encode($value);
            
            // Extract searchable text if available
            if (isset($value['name'])) {
                $fields[$propertyName . '_filename_s'] = $value['name'];
            }
            if (isset($value['mimeType'])) {
                $fields[$propertyName . '_mimetype_s'] = $value['mimeType'];
            }
        }

        return $fields;
    }

    /**
     * Build SOLR query string from search parameters
     *
     * @param array $searchParams Search parameters
     *
     * @return string SOLR query string
     */
    private function buildQueryString(array $searchParams): string
    {
        $query = $searchParams['q'] ?? '*:*';
        
        // Always add tenant isolation
        $tenantFilter = sprintf('tenant_id:%s', $this->escapeSolrValue($this->tenantId));
        
        if ($query === '*:*') {
            return $tenantFilter;
        }
        
        // Apply field boosting for text searches
        if (!empty($query) && $query !== '*:*') {
            $boostedQuery = $this->applyFieldBoosting($query, $searchParams['boost'] ?? []);
            return sprintf('(%s) AND %s', $boostedQuery, $tenantFilter);
        }
        
        return sprintf('(%s) AND %s', $query, $tenantFilter);
    }

    /**
     * Apply field boosting to search query
     *
     * @param string $query  Base query
     * @param array  $boosts Custom field boosts
     *
     * @return string Boosted query
     */
    private function applyFieldBoosting(string $query, array $boosts = []): string
    {
        $fieldBoosts = array_merge(self::DEFAULT_FIELD_BOOSTS, $boosts);
        $boostedFields = [];
        
        foreach ($fieldBoosts as $field => $boost) {
            $boostedFields[] = sprintf('%s:(%s)^%s', $field, $query, $boost);
        }
        
        return '(' . implode(' OR ', $boostedFields) . ')';
    }

    /**
     * Add filter queries to SOLR query
     *
     * @param SelectQuery $query        SOLR query
     * @param array       $searchParams Search parameters
     *
     * @return void
     */
    private function addFilterQueries(SelectQuery $query, array $searchParams): void
    {
        // Add custom filter queries
        if (!empty($searchParams['fq'])) {
            foreach ($searchParams['fq'] as $filter) {
                $query->createFilterQuery(md5($filter))->setQuery($filter);
            }
        }
        
        // Add register filter
        if (!empty($searchParams['register'])) {
            $filterKey = 'register_' . $searchParams['register'];
            $filterQuery = sprintf('register_id:%d', (int)$searchParams['register']);
            $query->createFilterQuery($filterKey)->setQuery($filterQuery);
        }
        
        // Add schema filter
        if (!empty($searchParams['schema'])) {
            $filterKey = 'schema_' . $searchParams['schema'];
            $filterQuery = sprintf('schema_id:%d', (int)$searchParams['schema']);
            $query->createFilterQuery($filterKey)->setQuery($filterQuery);
        }
    }

    /**
     * Add sorting to SOLR query
     *
     * @param SelectQuery $query        SOLR query
     * @param array       $searchParams Search parameters
     *
     * @return void
     */
    private function addSorting(SelectQuery $query, array $searchParams): void
    {
        $sortString = $searchParams['sort'] ?? 'score desc';
        
        // Parse sort string (format: "field direction,field2 direction2")
        $sortParts = explode(',', $sortString);
        
        foreach ($sortParts as $sortPart) {
            $sortPart = trim($sortPart);
            if (strpos($sortPart, ' ') !== false) {
                list($field, $direction) = explode(' ', $sortPart, 2);
                $query->addSort(trim($field), trim($direction));
            } else {
                $query->addSort($sortPart, 'asc');
            }
        }
    }

    /**
     * Add faceting to SOLR query
     *
     * @param SelectQuery $query        SOLR query
     * @param array       $searchParams Search parameters
     *
     * @return void
     */
    private function addFaceting(SelectQuery $query, array $searchParams): void
    {
        if (empty($searchParams['facet'])) {
            return;
        }
        
        $facetSet = $query->getFacetSet();
        
        foreach ($searchParams['facet'] as $facetField) {
            $facetSet->createFacetField($facetField)->setField($facetField);
        }
    }

    /**
     * Convert SOLR search results back to ObjectEntity objects
     *
     * @param \Solarium\QueryType\Select\Result\Result $resultSet SOLR result set
     *
     * @return array<ObjectEntity> Array of ObjectEntity objects
     */
    private function convertSolrResultsToObjects(\Solarium\QueryType\Select\Result\Result $resultSet): array
    {
        $objects = [];
        $objectIds = [];
        
        // Extract object IDs from SOLR results
        foreach ($resultSet as $document) {
            $objectId = $document->object_id ?? $document->id;
            if ($objectId) {
                $objectIds[] = $objectId;
            }
        }
        
        if (empty($objectIds)) {
            return [];
        }
        
        // Load full ObjectEntity objects from database
        try {
            $objects = $this->objectMapper->findMultiple($objectIds);
        } catch (\Exception $e) {
            $this->logger->error('Failed to load ObjectEntity objects after SOLR search', [
                'object_ids' => $objectIds,
                'error' => $e->getMessage()
            ]);
        }
        
        return $objects;
    }

    /**
     * Extract facets from SOLR result set
     *
     * @param \Solarium\QueryType\Select\Result\Result $resultSet SOLR result set
     *
     * @return array Facet data
     */
    private function extractFacets(\Solarium\QueryType\Select\Result\Result $resultSet): array
    {
        $facets = [];
        
        $facetSet = $resultSet->getFacetSet();
        if ($facetSet) {
            foreach ($facetSet as $facetKey => $facet) {
                $facets[$facetKey] = [];
                foreach ($facet as $value => $count) {
                    $facets[$facetKey][] = ['value' => $value, 'count' => $count];
                }
            }
        }
        
        return $facets;
    }

    /**
     * Build full SOLR URL for debugging
     *
     * @return string SOLR URL
     */
    private function buildSolrUrl(): string
    {
        return sprintf(
            '%s://%s:%d%s/%s',
            $this->solrConfig['scheme'],
            $this->solrConfig['host'],
            $this->solrConfig['port'],
            $this->solrConfig['path'],
            $this->solrConfig['core']
        );
    }

    /**
     * Escape special characters for SOLR queries
     *
     * @param string $value Value to escape
     *
     * @return string Escaped value
     */
    private function escapeSolrValue(string $value): string
    {
        // Escape special SOLR characters
        $specialChars = ['\\', '+', '-', '!', '(', ')', '{', '}', '[', ']', '^', '"', '~', '*', '?', ':', '/'];
        $value = str_replace($specialChars, array_map(fn($char) => '\\' . $char, $specialChars), $value);
        return '"' . $value . '"';
    }

    /**
     * Check if array is associative
     *
     * @param array $array Array to check
     *
     * @return bool True if associative
     */
    private function isAssociativeArray(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Check if string appears to be a date
     *
     * @param mixed $value Value to check
     *
     * @return bool True if looks like a date
     */
    private function isDateString(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        
        // Simple date pattern matching
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}/', $value);
    }

    /**
     * Format date string for SOLR
     *
     * @param string $dateString Date string
     *
     * @return string SOLR-formatted date
     */
    private function formatDateForSolr(string $dateString): string
    {
        try {
            $date = new \DateTime($dateString);
            return $date->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception $e) {
            return $dateString;
        }
    }

    /**
     * Search objects with pagination using OpenRegister query format
     * 
     * This method translates OpenRegister query parameters into Solr queries
     * and converts results back to ObjectEntity format for compatibility
     *
     * @param array $query OpenRegister-style query parameters
     * @param bool $rbac Apply role-based access control (currently not implemented in Solr)
     * @param bool $multi Multi-tenant support (currently not implemented in Solr) 
     * @return array Paginated results in OpenRegister format
     * @throws \Exception When Solr is not available or query fails
     */
    public function searchObjectsPaginated(array $query = [], bool $rbac = false, bool $multi = false): array
    {
        if (!$this->isAvailable()) {
            throw new \Exception('Solr service is not available');
        }

        $this->ensureClientInitialized();
        
        // Translate OpenRegister query to Solr query
        $solrQuery = $this->translateOpenRegisterQuery($query);
        
        $this->logger->debug('[SolrService] Translated query', [
            'original' => $query,
            'solr' => $solrQuery
        ]);
        
        // Execute Solr search
        $solrResults = $this->searchObjects($solrQuery);
        
        // Convert Solr results back to OpenRegister format
        $openRegisterResults = $this->convertSolrResultsToOpenRegisterFormat($solrResults, $query);
        
        $this->logger->debug('[SolrService] Search completed', [
            'found' => $openRegisterResults['total'] ?? 0,
            'returned' => count($openRegisterResults['results'] ?? [])
        ]);
        
        return $openRegisterResults;
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
            'start' => 0,
            'rows' => 20,
            'sort' => 'self_created desc',
            'facet' => true,
            'facet.field' => []
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
            $solrQuery['start'] = ($page - 1) * $limit;
            $solrQuery['rows'] = $limit;
        } elseif (isset($query['_limit'])) {
            $solrQuery['rows'] = max(1, (int)$query['_limit']);
        }

        // Handle sorting
        if (!empty($query['_order'])) {
            $solrQuery['sort'] = $this->translateSortField($query['_order']);
        }

        // Handle filters
        $filterQueries = [];
        
        foreach ($query as $key => $value) {
            if (str_starts_with($key, '_')) {
                continue; // Skip internal parameters
            }
            
            $solrField = $this->translateFilterField($key);
            
            if (is_array($value)) {
                // Handle array values (OR condition)
                $conditions = array_map(fn($v) => $solrField . ':"' . $this->escapeSolrValue($v) . '"', $value);
                $filterQueries[] = '(' . implode(' OR ', $conditions) . ')';
            } else {
                // Handle single values
                $filterQueries[] = $solrField . ':"' . $this->escapeSolrValue($value) . '"';
            }
        }

        if (!empty($filterQueries)) {
            $solrQuery['fq'] = $filterQueries;
        }

        // Add faceting for common fields
        $solrQuery['facet.field'] = [
            'self_register',
            'self_schema', 
            'self_organisation',
            'self_owner',
            'type_s',
            'naam_s'
        ];

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
        $documents = $solrResults['documents'] ?? [];
        
        foreach ($documents as $doc) {
            // Reconstruct object from Solr document
            $objectEntity = $this->reconstructObjectFromSolrDocument($doc);
            if ($objectEntity) {
                $results[] = $objectEntity;
            }
        }

        // Build pagination info
        $total = $solrResults['numFound'] ?? count($results);
        $page = isset($originalQuery['_page']) ? (int)$originalQuery['_page'] : 1;
        $limit = isset($originalQuery['_limit']) ? (int)$originalQuery['_limit'] : 20;
        $pages = $limit > 0 ? ceil($total / $limit) : 1;

        return [
            'results' => $results,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'limit' => $limit,
            'facets' => $solrResults['facets'] ?? [],
            'aggregations' => $solrResults['facets'] ?? [] // Alias for compatibility
        ];
    }

    /**
     * Bulk index objects from database to Solr for environment warmup
     * 
     * This method processes objects in batches to avoid memory issues
     * and provides progress tracking for large datasets
     *
     * @param int $batchSize Number of objects to process per batch (default 1000)
     * @param int $maxObjects Maximum number of objects to index (0 = all)
     * @return array Results with statistics and progress information
     * @throws \Exception When Solr is not available or indexing fails
     */
    public function bulkIndexFromDatabase(int $batchSize = 1000, int $maxObjects = 0): array
    {
        if (!$this->isAvailable()) {
            throw new \Exception('Solr service is not available');
        }

        $this->ensureClientInitialized();
        
        $this->logger->info('[SolrService] Starting bulk index from database', [
            'batch_size' => $batchSize,
            'max_objects' => $maxObjects
        ]);

        $totalProcessed = 0;
        $totalErrors = 0;
        $startTime = microtime(true);
        $lastCommitTime = $startTime;
        
        try {
            // Get total object count for progress tracking
            $totalCount = $this->objectMapper->getTotalCount();
            
            if ($maxObjects > 0 && $maxObjects < $totalCount) {
                $totalCount = $maxObjects;
            }
            
            $this->logger->info('[SolrService] Found objects to index', ['total' => $totalCount]);
            
            $offset = 0;
            
            while (($maxObjects === 0 || $totalProcessed < $maxObjects) && $offset < $totalCount) {
                $currentBatchSize = $batchSize;
                if ($maxObjects > 0 && ($totalProcessed + $batchSize) > $maxObjects) {
                    $currentBatchSize = $maxObjects - $totalProcessed;
                }
                
                // Get batch of objects
                $objects = $this->objectMapper->findAllInRange($offset, $currentBatchSize);
                
                if (empty($objects)) {
                    break;
                }
                
                $this->logger->debug('[SolrService] Processing batch', [
                    'offset' => $offset,
                    'batch_size' => count($objects),
                    'progress' => round(($totalProcessed / $totalCount) * 100, 2) . '%'
                ]);
                
                // Index this batch
                $batchResult = $this->bulkIndexObjects($objects, false); // Don't commit each batch
                
                $totalProcessed += count($objects);
                $totalErrors += $batchResult['errors'] ?? 0;
                
                // Commit every 5 batches or at the end
                $currentTime = microtime(true);
                if (($currentTime - $lastCommitTime) > 30 || // Every 30 seconds
                    $totalProcessed >= $totalCount || 
                    ($totalProcessed % ($batchSize * 5)) === 0) {
                    
                    $this->commit();
                    $lastCommitTime = $currentTime;
                    
                    $this->logger->info('[SolrService] Committed batch progress', [
                        'processed' => $totalProcessed,
                        'total' => $totalCount,
                        'errors' => $totalErrors,
                        'progress' => round(($totalProcessed / $totalCount) * 100, 2) . '%'
                    ]);
                }
                
                $offset += $currentBatchSize;
                
                // Memory cleanup
                unset($objects, $batchResult);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
            
            // Final commit
            $this->commit();
            
            $endTime = microtime(true);
            $duration = $endTime - $startTime;
            
            $result = [
                'success' => true,
                'message' => 'Bulk indexing completed successfully',
                'statistics' => [
                    'total_processed' => $totalProcessed,
                    'total_errors' => $totalErrors,
                    'success_rate' => $totalProcessed > 0 ? round((($totalProcessed - $totalErrors) / $totalProcessed) * 100, 2) : 0,
                    'duration_seconds' => round($duration, 2),
                    'objects_per_second' => $duration > 0 ? round($totalProcessed / $duration, 2) : 0,
                    'batch_size' => $batchSize
                ]
            ];
            
            $this->logger->info('[SolrService] Bulk indexing completed', $result['statistics']);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->logger->error('[SolrService] Bulk indexing failed', [
                'error' => $e->getMessage(),
                'processed' => $totalProcessed,
                'errors' => $totalErrors
            ]);
            
            throw new \Exception('Bulk indexing failed: ' . $e->getMessage());
        }
    }

    /**
     * Extract field value from SOLR document, handling both array and single value responses
     *
     * Different SOLR field configurations can return values as either single values or arrays.
     * This helper method normalizes the extraction to always return a single value or null.
     *
     * @param array $doc SOLR document
     * @param string $fieldName Field name to extract
     * @return mixed|null Field value or null if not found
     */
    private function extractSolrFieldValue(array $doc, string $fieldName)
    {
        $value = $doc[$fieldName] ?? null;
        return is_array($value) ? ($value[0] ?? null) : $value;
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
            $objectId = $this->extractSolrFieldValue($doc, 'self_object_id');
            $uuid = $this->extractSolrFieldValue($doc, 'self_uuid');
            $register = $this->extractSolrFieldValue($doc, 'self_register');
            $schema = $this->extractSolrFieldValue($doc, 'self_schema');
            
            if (!$objectId || !$register || !$schema) {
                $this->logger->warning('[SolrService] Invalid document missing required fields', [
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
            $entity->setOrganisation($this->extractSolrFieldValue($doc, 'self_organisation'));
            $entity->setName($this->extractSolrFieldValue($doc, 'self_name'));
            $entity->setDescription($this->extractSolrFieldValue($doc, 'self_description'));
            $entity->setSummary($this->extractSolrFieldValue($doc, 'self_summary'));
            $entity->setImage($this->extractSolrFieldValue($doc, 'self_image'));
            $entity->setSlug($this->extractSolrFieldValue($doc, 'self_slug'));
            $entity->setUri($this->extractSolrFieldValue($doc, 'self_uri'));
            $entity->setVersion($this->extractSolrFieldValue($doc, 'self_version'));
            $entity->setSize($this->extractSolrFieldValue($doc, 'self_size'));
            $entity->setOwner($this->extractSolrFieldValue($doc, 'self_owner'));
            $entity->setLocked($this->extractSolrFieldValue($doc, 'self_locked'));
            $entity->setFolder($this->extractSolrFieldValue($doc, 'self_folder'));
            $entity->setApplication($this->extractSolrFieldValue($doc, 'self_application'));
            
            // Set datetime fields
            $createdValue = $this->extractSolrFieldValue($doc, 'self_created');
            if ($createdValue) {
                $entity->setCreated(new \DateTime($createdValue));
            }
            
            $updatedValue = $this->extractSolrFieldValue($doc, 'self_updated');
            if ($updatedValue) {
                $entity->setUpdated(new \DateTime($updatedValue));
            }
            
            $publishedValue = $this->extractSolrFieldValue($doc, 'self_published');
            if ($publishedValue) {
                $entity->setPublished(new \DateTime($publishedValue));
            }
            
            $depublishedValue = $this->extractSolrFieldValue($doc, 'self_depublished');
            if ($depublishedValue) {
                $entity->setDepublished(new \DateTime($depublishedValue));
            }
            
            // Reconstruct object data from JSON stored in self_object field
            $selfObject = $this->extractSolrFieldValue($doc, 'self_object');
            
            if (!$selfObject) {
                $this->logger->error('[SolrService] Missing self_object field in SOLR document - cannot reconstruct object', [
                    'doc_id' => $doc['id'] ?? 'unknown',
                    'available_fields' => array_keys($doc),
                    'has_self_object_key' => isset($doc['self_object']),
                    'self_object_value' => $doc['self_object'] ?? 'not_set'
                ]);
                throw new \RuntimeException('SOLR document missing required self_object field for object reconstruction');
            }

            $objectData = json_decode($selfObject, true);
            if ($objectData === null && json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error('[SolrService] Failed to decode self_object JSON in SOLR document', [
                    'doc_id' => $doc['id'] ?? 'unknown',
                    'json_error' => json_last_error_msg(),
                    'raw_json' => substr($selfObject, 0, 200) . '...',
                    'json_length' => strlen($selfObject)
                ]);
                throw new \RuntimeException('SOLR document contains invalid JSON in self_object field: ' . json_last_error_msg());
            }

            // Ensure we have an array (empty array if null)
            $objectData = $objectData ?: [];
            
            $entity->setObject($objectData);
            
            // Set complex fields from JSON
            $authorizationValue = $this->extractSolrFieldValue($doc, 'self_authorization');
            if ($authorizationValue) {
                $entity->setAuthorization(json_decode($authorizationValue, true));
            }
            
            $deletedValue = $this->extractSolrFieldValue($doc, 'self_deleted');
            if ($deletedValue) {
                $entity->setDeleted(json_decode($deletedValue, true));
            }
            
            $validationValue = $this->extractSolrFieldValue($doc, 'self_validation');
            if ($validationValue) {
                $entity->setValidation(json_decode($validationValue, true));
            }
            
            $groupsValue = $this->extractSolrFieldValue($doc, 'self_groups');
            if ($groupsValue) {
                $entity->setGroups(json_decode($groupsValue, true));
            }

            return $entity;
            
        } catch (\Exception $e) {
            $this->logger->error('[SolrService] Failed to reconstruct object from Solr document', [
                'doc_id' => $doc['id'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
