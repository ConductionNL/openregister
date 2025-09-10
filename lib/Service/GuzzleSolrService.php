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
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

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
     * @param SettingsService $settingsService Settings service for configuration
     * @param LoggerInterface $logger          Logger for debugging and monitoring
     * @param IClientService  $clientService   HTTP client service
     * @param IConfig         $config          Nextcloud configuration
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
        private readonly IClientService $clientService,
        private readonly IConfig $config
    ) {
        $this->httpClient = $clientService->newClient();
        $this->tenantId = $this->generateTenantId();
        $this->initializeConfig();
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
     * Generate unique tenant ID for this Nextcloud instance
     *
     * @return string Tenant identifier
     */
    private function generateTenantId(): string
    {
        $instanceId = $this->config->getSystemValue('instanceid', 'default');
        $overwriteHost = $this->config->getSystemValue('overwrite.cli.url', '');
        
        if (!empty($overwriteHost)) {
            return 'nc_' . hash('crc32', $overwriteHost);
        }
        
        return 'nc_' . substr($instanceId, 0, 8);
    }

    /**
     * Generate tenant-specific collection name
     *
     * @param string $baseCollectionName Base collection name
     * @return string Tenant-specific collection name
     */
    private function getTenantSpecificCollectionName(string $baseCollectionName): string
    {
        return $baseCollectionName . '_' . $this->tenantId;
    }

    /**
     * Build SOLR base URL
     *
     * @return string SOLR base URL
     */
    private function buildSolrBaseUrl(): string
    {
        return sprintf(
            '%s://%s:%d%s',
            $this->solrConfig['scheme'] ?? 'http',
            $this->solrConfig['host'] ?? 'localhost',
            $this->solrConfig['port'] ?? 8983,
            $this->solrConfig['path'] ?? '/solr'
        );
    }

    /**
     * Check if SOLR is available and configured
     *
     * @return bool True if SOLR is available
     */
    public function isAvailable(): bool
    {
        return !empty($this->solrConfig['enabled']) && $this->solrConfig['enabled'] === true;
    }

    /**
     * Test SOLR connection
     *
     * @return array{success: bool, message: string, details: array} Connection test results
     */
    public function testConnection(): array
    {
        if (!$this->isAvailable()) {
            return [
                'success' => false,
                'message' => 'SOLR is not enabled or configured',
                'details' => ['enabled' => $this->solrConfig['enabled'] ?? false]
            ];
        }

        try {
            $startTime = microtime(true);
            
            // Test system info endpoint
            $url = $this->buildSolrBaseUrl() . '/admin/info/system?wt=json';
            $response = $this->httpClient->get($url, ['timeout' => 10]);
            $responseTime = (microtime(true) - $startTime) * 1000;

            $data = json_decode($response->getBody(), true);
            
            return [
                'success' => true,
                'message' => 'SOLR connection successful',
                'details' => [
                    'response_time_ms' => round($responseTime, 2),
                    'tenant_id' => $this->tenantId,
                    'solr_version' => $data['lucene']['solr-spec-version'] ?? 'unknown',
                    'mode' => 'SolrCloud'
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
            $data = json_decode($response->getBody(), true);
            
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
     * Create tenant-specific collection if it doesn't exist
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

        // Create tenant collection
        return $this->createCollection($tenantCollectionName, 'openregister');
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
            $data = json_decode($response->getBody(), true);

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

            $baseCollectionName = $this->solrConfig['core'] ?? 'openregister';
            $tenantCollectionName = $this->getTenantSpecificCollectionName($baseCollectionName);

            // Create SOLR document
            $document = $this->createSolrDocument($object);
            
            // Prepare update request
            $updateData = [
                'add' => [
                    'doc' => $document,
                    'commitWithin' => $this->solrConfig['commitWithin'] ?? 1000
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

            $data = json_decode($response->getBody(), true);
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
            $baseCollectionName = $this->solrConfig['core'] ?? 'openregister';
            $tenantCollectionName = $this->getTenantSpecificCollectionName($baseCollectionName);

            $deleteData = [
                'delete' => [
                    'query' => sprintf('id:%s AND tenant_id:%s', 
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

            $data = json_decode($response->getBody(), true);
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
            $baseCollectionName = $this->solrConfig['core'] ?? 'openregister';
            $tenantCollectionName = $this->getTenantSpecificCollectionName($baseCollectionName);

            $url = $this->buildSolrBaseUrl() . '/' . $tenantCollectionName . '/select?' . http_build_query([
                'q' => 'tenant_id:' . $this->tenantId,
                'rows' => 0,
                'wt' => 'json'
            ]);

            $response = $this->httpClient->get($url, ['timeout' => 10]);
            $data = json_decode($response->getBody(), true);

            return (int)($data['response']['numFound'] ?? 0);

        } catch (\Exception $e) {
            $this->logger->error('Failed to get document count', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Create SOLR document from ObjectEntity
     *
     * @param ObjectEntity $object Object to convert
     * @return array SOLR document
     */
    private function createSolrDocument(ObjectEntity $object): array
    {
        $objectData = $object->getObject();
        
        $document = [
            // Core identifiers
            'id' => $object->getUuid() ?: $object->getId(),
            'tenant_id' => $this->tenantId,
            'object_id' => $object->getId(),
            'uuid' => $object->getUuid(),
            
            // Organizational fields
            'register_id' => $object->getRegister(),
            'schema_id' => $object->getSchema(),
            'organisation_id' => $object->getOrganisation(),
            
            // Metadata fields matching ObjectEntity serialization
            'name' => $object->getName(),
            'description' => $object->getDescription(),
            'summary' => $object->getSummary(),
            'image' => $object->getImage(),
            'slug' => $object->getSlug(),
            'uri' => $object->getUri(),
            'version' => $object->getVersion(),
            'size' => $object->getSize(),
            
            // DateTime fields (matching ObjectEntity getObjectArray format)
            'created' => $object->getCreated() ? $object->getCreated()->format('Y-m-d\TH:i:s\Z') : null,
            'updated' => $object->getUpdated() ? $object->getUpdated()->format('Y-m-d\TH:i:s\Z') : null,
            'published' => $object->getPublished() ? $object->getPublished()->format('Y-m-d\TH:i:s\Z') : null,
            'depublished' => $object->getDepublished() ? $object->getDepublished()->format('Y-m-d\TH:i:s\Z') : null,
            
            // Additional metadata fields for comprehensive indexing
            'owner' => $object->getOwner(),
            'locked' => $object->getLocked(),
            'authorization' => $object->getAuthorization() ? json_encode($object->getAuthorization()) : null,
            'deleted' => $object->getDeleted() ? json_encode($object->getDeleted()) : null,
            'validation' => $object->getValidation() ? json_encode($object->getValidation()) : null,
            'groups' => $object->getGroups() ? json_encode($object->getGroups()) : null,
            'folder' => $object->getFolder(),
            'application' => $object->getApplication(),
            
            // Full-text search content
            '_text_' => $this->extractTextContent($object, $objectData ?: []),
        ];

        // Add dynamic fields from object data
        if (is_array($objectData)) {
            $document = array_merge($document, $this->extractDynamicFields($objectData));
        }

        // Remove null values
        return array_filter($document, fn($value) => $value !== null && $value !== '');
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
        if (!$this->isAvailable() || empty($documents)) {
            return false;
        }

        try {
            $startTime = microtime(true);

            // Ensure tenant collection exists
            if (!$this->ensureTenantCollection()) {
                $this->logger->warning('Cannot bulk index: tenant collection unavailable');
                return false;
            }

            $baseCollectionName = $this->solrConfig['core'] ?? 'openregister';
            $tenantCollectionName = $this->getTenantSpecificCollectionName($baseCollectionName);

            // Prepare documents
            $solrDocs = [];
            foreach ($documents as $doc) {
                if ($doc instanceof ObjectEntity) {
                    $solrDocs[] = $this->createSolrDocument($doc);
                } elseif (is_array($doc)) {
                    $solrDocs[] = array_merge($doc, ['tenant_id' => $this->tenantId]);
                } else {
                    $this->logger->warning('Invalid document type in bulk index', ['type' => gettype($doc)]);
                    continue;
                }
            }

            if (empty($solrDocs)) {
                return false;
            }

            // Prepare bulk update request
            $updateData = [];
            foreach ($solrDocs as $doc) {
                $updateData[] = [
                    'add' => [
                        'doc' => $doc,
                        'commitWithin' => $this->solrConfig['commitWithin'] ?? 1000
                    ]
                ];
            }

            $url = $this->buildSolrBaseUrl() . '/' . $tenantCollectionName . '/update?wt=json';
            
            if ($commit) {
                $url .= '&commit=true';
            }

            $response = $this->httpClient->post($url, [
                'body' => json_encode($updateData),
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 60
            ]);

            $data = json_decode($response->getBody(), true);
            $success = ($data['responseHeader']['status'] ?? -1) === 0;

            if ($success) {
                $this->stats['indexes'] += count($solrDocs);
                $this->stats['index_time'] += (microtime(true) - $startTime);
                
                $this->logger->debug('📦 BULK INDEXED IN SOLR', [
                    'document_count' => count($solrDocs),
                    'collection' => $tenantCollectionName,
                    'tenant_id' => $this->tenantId,
                    'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
                ]);
            } else {
                $this->stats['errors']++;
                $this->logger->error('SOLR bulk indexing failed', ['response' => $data]);
            }

            return $success;

        } catch (\Exception $e) {
            $this->stats['errors']++;
            $this->logger->error('Exception during bulk indexing', ['error' => $e->getMessage()]);
            return false;
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
            $baseCollectionName = $this->solrConfig['core'] ?? 'openregister';
            $tenantCollectionName = $this->getTenantSpecificCollectionName($baseCollectionName);

            $url = $this->buildSolrBaseUrl() . '/' . $tenantCollectionName . '/update?wt=json&commit=true';

            $response = $this->httpClient->post($url, [
                'body' => json_encode(['commit' => []]),
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 30
            ]);

            $data = json_decode($response->getBody(), true);
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
            $baseCollectionName = $this->solrConfig['core'] ?? 'openregister';
            $tenantCollectionName = $this->getTenantSpecificCollectionName($baseCollectionName);

            // Add tenant isolation to query
            $tenantQuery = sprintf('(%s) AND tenant_id:%s', $query, $this->tenantId);

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

            $data = json_decode($response->getBody(), true);
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
        if (!$this->isAvailable()) {
            return ['success' => false, 'error' => 'SOLR not available'];
        }

        try {
            $startTime = microtime(true);
            $baseCollectionName = $this->solrConfig['core'] ?? 'openregister';
            $tenantCollectionName = $this->getTenantSpecificCollectionName($baseCollectionName);

            // Build search parameters
            $params = [
                'wt' => 'json',
                'q' => $searchParams['q'] ?? '*:*',
                'rows' => $searchParams['limit'] ?? 25,
                'start' => $searchParams['offset'] ?? 0,
                'fq' => 'tenant_id:' . $this->tenantId
            ];

            // Add additional filters
            if (!empty($searchParams['filters'])) {
                foreach ($searchParams['filters'] as $filter) {
                    $params['fq'] .= ' AND ' . $filter;
                }
            }

            // Add facets
            if (!empty($searchParams['facets'])) {
                $params['facet'] = 'true';
                $params['facet.field'] = $searchParams['facets'];
            }

            // Add sorting
            if (!empty($searchParams['sort'])) {
                $params['sort'] = $searchParams['sort'];
            }

            $url = $this->buildSolrBaseUrl() . '/' . $tenantCollectionName . '/select?' . http_build_query($params);

            $response = $this->httpClient->get($url, ['timeout' => 30]);
            $data = json_decode($response->getBody(), true);

            if (($data['responseHeader']['status'] ?? -1) === 0) {
                $this->stats['searches']++;
                $this->stats['search_time'] += (microtime(true) - $startTime);

                return [
                    'success' => true,
                    'data' => $data['response']['docs'] ?? [],
                    'total' => $data['response']['numFound'] ?? 0,
                    'facets' => $data['facet_counts'] ?? [],
                    'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
                ];
            }

            return ['success' => false, 'error' => 'SOLR search failed', 'response' => $data];

        } catch (\Exception $e) {
            $this->stats['errors']++;
            $this->logger->error('Exception searching SOLR', [
                'params' => $searchParams,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
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
            $baseCollectionName = $this->solrConfig['core'] ?? 'openregister';
            $tenantCollectionName = $this->getTenantSpecificCollectionName($baseCollectionName);

            $url = $this->buildSolrBaseUrl() . '/' . $tenantCollectionName . '/update?wt=json&optimize=true';

            $response = $this->httpClient->post($url, [
                'body' => json_encode(['optimize' => []]),
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 120 // Optimization can take time
            ]);

            $data = json_decode($response->getBody(), true);
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
        if (!$this->isAvailable()) {
            return ['available' => false, 'error' => 'SOLR not available'];
        }

        try {
            $baseCollectionName = $this->solrConfig['core'] ?? 'openregister';
            $tenantCollectionName = $this->getTenantSpecificCollectionName($baseCollectionName);

            // Get collection stats
            $statsUrl = $this->buildSolrBaseUrl() . '/admin/collections?action=CLUSTERSTATUS&collection=' . $tenantCollectionName . '&wt=json';
            $statsResponse = $this->httpClient->get($statsUrl, ['timeout' => 10]);
            $statsData = json_decode($statsResponse->getBody(), true);

            // Get document count
            $docCount = $this->getDocumentCount();

            // Get index size (approximate)
            $indexSizeUrl = $this->buildSolrBaseUrl() . '/' . $tenantCollectionName . '/admin/luke?wt=json&numTerms=0';
            $sizeResponse = $this->httpClient->get($indexSizeUrl, ['timeout' => 10]);
            $sizeData = json_decode($sizeResponse->getBody(), true);

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
}
