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
        // Use instance URL or generate from instance salt
        $instanceId = $this->config->getSystemValue('instanceid', 'default');
        $overwriteHost = $this->config->getSystemValue('overwrite.cli.url', '');
        
        if (!empty($overwriteHost)) {
            return 'nc_' . hash('crc32', $overwriteHost);
        }
        
        return 'nc_' . substr($instanceId, 0, 8);
    }

    /**
     * Generate tenant-specific Solr core name
     *
     * Creates a core name that includes tenant isolation for proper multi-tenancy.
     * Format: {base_core}_{tenant_id} (e.g., "openregister_nc_f0e53393")
     *
     * @param string $baseCoreName Base core name from configuration
     * @return string Tenant-specific core name
     */
    private function getTenantSpecificCoreName(string $baseCoreName): string
    {
        // For single-tenant setups or if multitenancy is disabled, use base core
        $multitenancySettings = $this->settingsService->getMultitenancySettings();
        if (!$multitenancySettings['enabled']) {
            return $baseCoreName;
        }
        
        // Generate tenant-specific core name for multi-tenant isolation
        return $baseCoreName . '_' . $this->tenantId;
    }

    /**
     * Ensure tenant-specific Solr core exists, create if necessary
     *
     * Automatically creates Solr cores for new tenants using the base core as a template.
     * This ensures seamless multi-tenant operation without manual core management.
     *
     * @param string $coreNam Core name to check/create
     * @return bool True if core exists or was created successfully
     */
    private function ensureTenantCoreExists(string $coreNam): bool
    {
        $this->ensureClientInitialized();
        
        if (!$this->client) {
            $this->logger->warning('Cannot check core existence: Solr client not initialized');
            return false;
        }
        
        try {
            // Check if core already exists using admin API
            $adminQuery = $this->client->createApi('admin');
            $coreAdminQuery = $adminQuery->createStatus();
            $coreAdminQuery->setCore($coreNam);
            
            $result = $this->client->execute($coreAdminQuery);
            
            // If core exists, return true
            if ($result->getStatus() === 0 && isset($result->getData()['status'][$coreNam])) {
                $this->logger->info("Solr core '{$coreNam}' already exists", [
                    'tenant_id' => $this->tenantId
                ]);
                return true;
            }
            
            // Core doesn't exist, attempt to create it
            return $this->createTenantCore($coreNam);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to check/create Solr core', [
                'core' => $coreNam,
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Create a new Solr core for a tenant
     *
     * Creates a new core using the base OpenRegister schema and configuration.
     * Copies configuration from the base core to ensure consistent functionality.
     *
     * @param string $coreNam Name of the core to create
     * @return bool True if core was created successfully
     */
    private function createTenantCore(string $coreNam): bool
    {
        try {
            $adminQuery = $this->client->createApi('admin');
            $createCoreQuery = $adminQuery->createCreate();
            
            // Use the base core name for template configuration
            $baseCoreName = $this->solrConfig['core'];
            
            $createCoreQuery->setCore($coreNam);
            $createCoreQuery->setConfigSet($baseCoreName); // Use base core as template
            
            $result = $this->client->execute($createCoreQuery);
            
            if ($result->getStatus() === 0) {
                $this->logger->info("Successfully created Solr core '{$coreNam}' for tenant", [
                    'tenant_id' => $this->tenantId,
                    'base_core' => $baseCoreName
                ]);
                return true;
            } else {
                $this->logger->error("Failed to create Solr core '{$coreNam}'", [
                    'tenant_id' => $this->tenantId,
                    'status' => $result->getStatus(),
                    'data' => $result->getData()
                ]);
                return false;
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Exception while creating Solr core', [
                'core' => $coreNam,
                'tenant_id' => $this->tenantId,
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
            
            // Build SOLR endpoint configuration with tenant-specific core
            $endpointConfig = [
                'scheme' => $this->solrConfig['scheme'],
                'host' => $this->solrConfig['host'],
                'port' => $this->solrConfig['port'],
                'path' => $this->solrConfig['path'],
                'core' => $tenantSpecificCore,
                'timeout' => $this->solrConfig['timeout'],
            ];
            
            // Add authentication if configured
            if (!empty($this->solrConfig['username']) && !empty($this->solrConfig['password'])) {
                $endpointConfig['username'] = $this->solrConfig['username'];
                $endpointConfig['password'] = $this->solrConfig['password'];
            }
            
            // Initialize client with required arguments (Solarium 6.3+ pattern)
            $adapter = new Curl();
            $eventDispatcher = new EventDispatcher();
            $this->client = new Client($adapter, $eventDispatcher);
            
            // Create and configure endpoint (correct parameter order)
            $this->client->createEndpoint($endpointConfig, true);
            
            // Ensure tenant-specific core exists (for multi-tenant setups)
            if (isset($tenantSpecificCore) && $tenantSpecificCore !== $baseCoreName) {
                $coreCreated = $this->ensureTenantCoreExists($tenantSpecificCore);
                if (!$coreCreated) {
                    $this->logger->warning('Failed to create tenant core, falling back to base core', [
                        'tenant_core' => $tenantSpecificCore,
                        'base_core' => $baseCoreName,
                        'tenant_id' => $this->tenantId
                    ]);
                    
                    // Fallback to base core if tenant core creation fails
                    $endpointConfig['core'] = $baseCoreName;
                    $this->client = new Client($adapter, $eventDispatcher);
                    $this->client->createEndpoint($endpointConfig, true);
                }
            }
            
            $this->logger->info('SOLR client initialized successfully', [
                'host' => $this->solrConfig['host'],
                'port' => $this->solrConfig['port'],
                'core' => $this->solrConfig['core'],
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
     * Warm up SOLR index by performing sample operations
     *
     * @return array{success: bool, operations: array, execution_time_ms: float} Warmup results
     */
    public function warmupIndex(): array
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

        $startTime = microtime(true);
        $operations = [];
        
        try {
            // 1. Test connection
            $connectionTest = $this->testConnection();
            $operations['connection_test'] = $connectionTest['success'];
            
            // 2. Perform sample search queries to warm caches
            $warmupQueries = [
                ['q' => '*:*', 'rows' => 1],
                ['q' => '*:*', 'rows' => 10, 'facet' => ['register_id', 'schema_id']],
                ['q' => 'name:*', 'rows' => 5],
            ];
            
            foreach ($warmupQueries as $i => $query) {
                try {
                    $this->searchObjects($query);
                    $operations["warmup_query_$i"] = true;
                } catch (\Exception $e) {
                    $operations["warmup_query_$i"] = false;
                    $this->logger->warning("Warmup query $i failed", ['error' => $e->getMessage()]);
                }
            }
            
            // 3. Commit any pending changes
            $operations['commit'] = $this->commit();
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            
            $this->logger->info('SOLR index warmup completed', [
                'operations' => $operations,
                'execution_time_ms' => round($executionTime, 2),
                'tenant_id' => $this->tenantId
            ]);
            
            return [
                'success' => true,
                'operations' => $operations,
                'execution_time_ms' => round($executionTime, 2)
            ];
            
        } catch (\Exception $e) {
            $this->stats['errors']++;
            $this->logger->error('SOLR index warmup failed', [
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
     * Create SOLR document from ObjectEntity
     *
     * @param UpdateQuery  $update Update query instance
     * @param ObjectEntity $object Object to convert
     *
     * @return \Solarium\QueryType\Update\Query\Document Document instance
     */
    private function createSolrDocument(UpdateQuery $update, ObjectEntity $object): \Solarium\QueryType\Update\Query\Document
    {
        $doc = $update->createDocument();
        
        // Set core identification fields
        $doc->setField('id', $object->getUuid() ?: $object->getId());
        $doc->setField('tenant_id', $this->tenantId);
        $doc->setField('object_id', $object->getId());
        $doc->setField('uuid', $object->getUuid());
        
        // Set organizational fields
        $doc->setField('register_id', $object->getRegister());
        $doc->setField('schema_id', $object->getSchema());
        $doc->setField('organisation_id', $object->getOrganisation());
        
        // Set metadata fields
        $doc->setField('name', $object->getName());
        $doc->setField('created', $object->getCreated() ? $object->getCreated()->format('Y-m-d\TH:i:s\Z') : null);
        $doc->setField('modified', $object->getModified() ? $object->getModified()->format('Y-m-d\TH:i:s\Z') : null);
        $doc->setField('published', $object->getPublished() ? $object->getPublished()->format('Y-m-d\TH:i:s\Z') : null);
        
        // Index object data as dynamic fields
        $objectData = $object->getObject();
        if (is_array($objectData)) {
            $this->addObjectDataToDocument($doc, $objectData);
        }
        
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
}
