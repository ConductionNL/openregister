<?php
/**
 * ObjectCacheService
 *
 * Service class responsible for caching frequently accessed objects to improve
 * performance by reducing database queries. This service provides:
 * - In-memory caching of ObjectEntity objects
 * - Bulk preloading of relationship objects
 * - Cache warming strategies
 * - Memory-efficient cache management
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
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\GuzzleSolrService;
use OCP\ICacheFactory;
use OCP\IMemcache;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Cache service for ObjectEntity objects to improve performance
 *
 * This service provides efficient caching mechanisms to reduce database queries
 * when dealing with related objects and frequently accessed entities.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @license   AGPL-3.0-or-later
 * @link      https://github.com/OpenCatalogi/OpenRegister
 * @version   1.0.0
 * @copyright 2024 Conduction b.v.
 */
class ObjectCacheService
{

    /**
     * In-memory cache of objects indexed by ID/UUID
     *
     * @var array<string, ObjectEntity>
     */
    private array $objectCache = [];

    /**
     * Cache of relationship mappings to avoid repeated lookups
     *
     * @var array<string, array<string>>
     */
    private array $relationshipCache = [];

    /**
     * Maximum number of objects to keep in memory cache
     *
     * @var integer
     */
    private int $maxCacheSize = 1000;

    /**
     * Maximum cache TTL for office environments (8 hours in seconds)
     *
     * This prevents indefinite cache buildup while maintaining performance
     * during business hours.
     *
     * @var int
     */
    private const MAX_CACHE_TTL = 28800;

    /**
     * In-memory cache of object names indexed by ID/UUID
     *
     * Provides ultra-fast name lookups for frontend rendering without
     * requiring full object data retrieval.
     *
     * @var array<string, string>
     */
    private array $nameCache = [];

    /**
     * Distributed cache for object names
     *
     * @var IMemcache|null
     */
    private ?IMemcache $nameDistributedCache = null;

    /**
     * Cache hit statistics
     *
     * @var array{hits: int, misses: int, preloads: int, query_hits: int, query_misses: int, name_hits: int, name_misses: int, name_warmups: int}
     */
    private array $stats = ['hits' => 0, 'misses' => 0, 'preloads' => 0, 'query_hits' => 0, 'query_misses' => 0, 'name_hits' => 0, 'name_misses' => 0, 'name_warmups' => 0];

    /**
     * Distributed cache for query results
     *
     * @var IMemcache|null
     */
    private ?IMemcache $queryCache = null;

    /**
     * In-memory cache for frequently accessed query results
     *
     * @var array<string, mixed>
     */
    private array $inMemoryQueryCache = [];

    /**
     * User session for cache key generation
     *
     * @var IUserSession
     */
    private IUserSession $userSession;


    /**
     * Constructor for ObjectCacheService
     *
     * @param ObjectEntityMapper     $objectEntityMapper The object entity mapper
     * @param OrganisationMapper     $organisationMapper The organisation entity mapper
     * @param LoggerInterface        $logger             Logger for performance monitoring
     * @param GuzzleSolrService|null $guzzleSolrService  Lightweight SOLR service using Guzzle HTTP
     * @param ICacheFactory|null     $cacheFactory       Cache factory for query result caching
     * @param IUserSession|null      $userSession        User session for cache key generation
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly OrganisationMapper $organisationMapper,
        private readonly LoggerInterface $logger,
        private readonly ?GuzzleSolrService $guzzleSolrService=null,
        ?ICacheFactory $cacheFactory=null,
        ?IUserSession $userSession=null
    ) {
        // Initialize query cache if available
        if ($cacheFactory !== null) {
            try {
                $this->queryCache           = $cacheFactory->createDistributed('openregister_query_results');
                $this->nameDistributedCache = $cacheFactory->createDistributed('openregister_object_names');
            } catch (\Exception $e) {
                $this->logger->warning(
                        'Failed to initialize distributed caches',
                        [
                            'error' => $e->getMessage(),
                        ]
                        );
            }
        }

        $this->userSession = $userSession ?? new class {


            public function getUser()
            {
                return null;
            }//end getUser()


        };

    }//end __construct()


    /**
     * Get GuzzleSolrService instance using direct DI injection
     *
     * Since Guzzle is lightweight, we can use direct DI registration without
     * performance issues. Returns null if SOLR is unavailable or disabled.
     *
     * @return GuzzleSolrService|null SOLR service instance or null
     */
    private function getSolrService(): ?GuzzleSolrService
    {
        // Since Guzzle is lightweight, we use direct DI injection (no factory needed!)
        return $this->guzzleSolrService;

    }//end getSolrService()


    /**
     * Get an object from cache or database
     *
     * This method first checks the in-memory cache before falling back to the database.
     * It automatically caches retrieved objects for future use.
     *
     * @param int|string $identifier The object ID or UUID
     *
     * @return ObjectEntity|null The object or null if not found
     *
     * @phpstan-return ObjectEntity|null
     * @psalm-return   ObjectEntity|null
     */
    public function getObject(int | string $identifier): ?ObjectEntity
    {
        $key = (string) $identifier;

        // Check cache first
        if (isset($this->objectCache[$key])) {
            $this->stats['hits']++;
            return $this->objectCache[$key];
        }

        // Cache miss - load from database
        $this->stats['misses']++;

        try {
            $object = $this->objectEntityMapper->find($identifier);

            // Cache the object with both ID and UUID as keys
            $this->cacheObject($object);

            return $object;
        } catch (\Exception $e) {
            return null;
        }

    }//end getObject()


    // ========================================
    // SOLR INTEGRATION METHODS
    // ========================================


    /**
     * Index object in SOLR when available
     *
     * Creates a SOLR document from ObjectEntity matching the ObjectEntity structure.
     * Metadata fields (name, description, etc.) are at root level, with flexible
     * object data in a nested 'object' field.
     *
     * @param ObjectEntity $object Object to index in SOLR
     * @param bool         $commit Whether to commit immediately
     *
     * @return bool True if indexing was successful or SOLR unavailable
     */
    private function indexObjectInSolr(ObjectEntity $object, bool $commit=false): bool
    {
        // Get SOLR service using factory pattern (performance optimized)
        $solrService = $this->getSolrService();

        $this->logger->info(
                '🔥 DEBUGGING: indexObjectInSolr called',
                [
                    'app'                    => 'openregister',
                    'object_id'              => $object->getId(),
                    'object_uuid'            => $object->getUuid(),
                    'object_name'            => $object->getName(),
                    'solr_service_available' => $solrService !== null,
                    'solr_is_available'      => $solrService ? $solrService->isAvailable() : false,
                ]
                );

        if ($solrService === null || !$solrService->isAvailable()) {
            $this->logger->debug(
                    'SOLR service unavailable, skipping indexing',
                    [
                        'object_id'              => $object->getId(),
                        'solr_service_available' => $solrService !== null,
                        'solr_is_available'      => $solrService ? $solrService->isAvailable() : false,
                    ]
                    );
            return true;
            // Graceful degradation
        }

        // Index in SOLR.
        $result = $solrService->indexObject($object, $commit);

        if ($result === true) {
            $this->logger->debug(
                    '🔍 OBJECT INDEXED IN SOLR',
                    [
                        'object_id' => $object->getId(),
                        'uuid'      => $object->getUuid(),
                        'schema'    => $object->getSchema(),
                        'register'  => $object->getRegister(),
                    ]
                    );
        } else {
            $this->logger->error(
                    'SOLR object indexing failed',
                    [
                        'object_id' => $object->getId(),
                        'uuid'      => $object->getUuid(),
                        'schema'    => $object->getSchema(),
                        'register'  => $object->getRegister(),
                    ]
                    );
        }//end if

        return $result;

    }//end indexObjectInSolr()


    /**
     * Remove object from SOLR index
     *
     * @param ObjectEntity $object Object to remove from SOLR
     * @param bool         $commit Whether to commit immediately
     *
     * @return bool True if removal was successful or SOLR unavailable
     */
    private function removeObjectFromSolr(ObjectEntity $object, bool $commit=false): bool
    {
        // Get SOLR service using factory pattern (performance optimized)
        $solrService = $this->getSolrService();
        if ($solrService === null || !$solrService->isAvailable()) {
            return true;
            // Graceful degradation
        }

        try {
            $result = $solrService->deleteObject($object->getUuid(), $commit);

            if ($result === true) {
                $this->logger->debug(
                        '🗑️  OBJECT REMOVED FROM SOLR',
                        [
                            'object_id' => $object->getId(),
                            'uuid'      => $object->getUuid(),
                        ]
                        );
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->warning(
                    'Failed to remove object from SOLR',
                    [
                        'object_id' => $object->getId(),
                        'error'     => $e->getMessage(),
                    ]
                    );
            return true;
            // Don't fail the whole operation for SOLR issues
        }//end try

    }//end removeObjectFromSolr()


    /**
     * Create SOLR document from ObjectEntity
     *
     * This method creates a SOLR document structure that matches the ObjectEntity
     * architecture, with metadata fields at root level and flexible object data.
     *
     * @param ObjectEntity $object Object to convert to SOLR document
     *
     * @return array SOLR document array
     */
    private function createSolrDocumentFromObject(ObjectEntity $object): array
    {
        // Get object data
        $objectData  = $object->getObject();
        $objectArray = $object->getObjectArray();

        // Base SOLR document matching ObjectEntity structure
        $document = [
            // Core identifiers (always present)
            'id'               => $object->getUuid(),
            'object_id_i'      => $object->getId(),
            'uuid_s'           => $object->getUuid(),

            // Context fields
            'register_id_i'    => (int) $object->getRegister(),
            'schema_id_i'      => (int) $object->getSchema(),
            'schema_version_s' => $object->getSchemaVersion(),

            // Ownership and organization
            'owner_s'          => $object->getOwner(),
            'organisation_s'   => $object->getOrganisation(),
            'application_s'    => $object->getApplication(),

            // Metadata fields (extracted from object data or entity)
            'name_s'           => $object->getName(),
            'name_txt'         => $object->getName(),
        // For full-text search
            'description_s'    => $object->getDescription(),
            'description_txt'  => $object->getDescription(),
            'summary_s'        => $object->getSummary(),
            'summary_txt'      => $object->getSummary(),
            'image_s'          => $object->getImage(),

            // URL and navigation
            'slug_s'           => $object->getSlug(),
            'uri_s'            => $object->getUri(),
            'folder_s'         => $object->getFolder(),

            // Timestamps
            'created_dt'       => $object->getCreated()?->format('Y-m-d\\TH:i:s\\Z'),
            'updated_dt'       => $object->getUpdated()?->format('Y-m-d\\TH:i:s\\Z'),
            'published_dt'     => $object->getPublished()?->format('Y-m-d\\TH:i:s\\Z'),
            'depublished_dt'   => $object->getDepublished()?->format('Y-m-d\\TH:i:s\\Z'),
            'expires_dt'       => $object->getExpires()?->format('Y-m-d\\TH:i:s\\Z'),

            // Status fields
            'version_s'        => $object->getVersion(),
            'size_s'           => $object->getSize(),

            // Arrays and complex data
            'files_ss'         => $object->getFiles(),
            'relations_ss'     => $object->getRelations(),
            'groups_ss'        => $object->getGroups(),

            // The flexible object data (JSON stored as text for SOLR)
            'object_txt'       => json_encode($objectData, JSON_UNESCAPED_UNICODE),

            // Full-text search catch-all field
            '_text_'           => $this->buildFullTextContent($object, $objectData),
        ];

        // Add dynamic fields from object data
        $document = array_merge($document, $this->extractDynamicFieldsFromObject($objectData));

        // Remove null values, but keep published/depublished fields for proper filtering
        return array_filter(
                $document,
                function ($value, $key) {
                    // Always keep published/depublished fields even if null for proper Solr filtering
                    if (in_array($key, ['published_dt', 'depublished_dt'])) {
                        return true;
                    }

                    return $value !== null && $value !== '' && $value !== [];
                },
                ARRAY_FILTER_USE_BOTH
                );

    }//end createSolrDocumentFromObject()


    /**
     * Extract dynamic fields from object data for SOLR indexing
     *
     * Converts object properties into SOLR dynamic fields with appropriate suffixes.
     *
     * @param array  $objectData Object data to extract fields from
     * @param string $prefix     Field prefix for nested objects
     *
     * @return array Dynamic SOLR fields
     */
    private function extractDynamicFieldsFromObject(array $objectData, string $prefix=''): array
    {
        $dynamicFields = [];

        foreach ($objectData as $key => $value) {
            // Skip meta fields and null values
            if ($key === '@self' || $key === 'id' || $value === null) {
                continue;
            }

            $fieldName = $prefix.$key;

            if (is_array($value)) {
                if (isset($value[0])) {
                    // Multi-value array
                    $dynamicFields[$fieldName.'_ss'] = $value;
                    // Also add as text for searching
                    $dynamicFields[$fieldName.'_txt'] = implode(' ', array_filter($value, 'is_string'));
                } else {
                    // Nested object - recurse with dot notation
                    $nestedFields  = $this->extractDynamicFieldsFromObject($value, $fieldName.'_');
                    $dynamicFields = array_merge($dynamicFields, $nestedFields);
                }
            } else if (is_string($value)) {
                $dynamicFields[$fieldName.'_s']   = $value;
                $dynamicFields[$fieldName.'_txt'] = $value;
            } else if (is_int($value) || is_float($value)) {
                $suffix = is_int($value) ? '_i' : '_f';
                $dynamicFields[$fieldName.$suffix] = $value;
            } else if (is_bool($value)) {
                $dynamicFields[$fieldName.'_b'] = $value;
            } else if ($this->isDateString($value)) {
                $dynamicFields[$fieldName.'_dt'] = $this->formatDateForSolr($value);
            }//end if
        }//end foreach

        return $dynamicFields;

    }//end extractDynamicFieldsFromObject()


    /**
     * Build full-text content for SOLR catch-all field
     *
     * @param ObjectEntity $object     Object entity
     * @param array        $objectData Object data
     *
     * @return string Full-text content for searching
     */
    private function buildFullTextContent(ObjectEntity $object, array $objectData): string
    {
        $textContent = [];

        // Add metadata fields
        $textContent[] = $object->getName();
        $textContent[] = $object->getDescription();
        $textContent[] = $object->getSummary();

        // Extract text from object data recursively
        $this->extractTextFromArray($objectData, $textContent);

        return implode(' ', array_filter($textContent));

    }//end buildFullTextContent()


    /**
     * Extract text content from array recursively
     *
     * @param array $data         Array to extract text from
     * @param array &$textContent Reference to text content array
     *
     * @return void
     */
    private function extractTextFromArray(array $data, array &$textContent): void
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $textContent[] = $value;
            } else if (is_array($value)) {
                $this->extractTextFromArray($value, $textContent);
            }
        }

    }//end extractTextFromArray()


    /**
     * Check if a string represents a date
     *
     * @param mixed $value Value to check
     *
     * @return bool True if value is a date string
     */
    private function isDateString($value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        return (bool) strtotime($value);

    }//end isDateString()


    /**
     * Format date string for SOLR
     *
     * @param string $dateString Date string to format
     *
     * @return string|null Formatted date or null
     */
    private function formatDateForSolr(string $dateString): ?string
    {
        $timestamp = strtotime($dateString);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d\\TH:i:s\\Z', $timestamp);

    }//end formatDateForSolr()


    /**
     * Bulk preload objects to warm the cache
     *
     * This method loads multiple objects in a single database query and caches them
     * all, significantly improving performance for operations that access many objects.
     *
     * @param array $identifiers Array of object IDs/UUIDs to preload
     *
     * @return array<ObjectEntity> Array of loaded objects
     *
     * @phpstan-param  array<int|string> $identifiers
     * @phpstan-return array<ObjectEntity>
     * @psalm-param    array<int|string> $identifiers
     * @psalm-return   array<ObjectEntity>
     */
    public function preloadObjects(array $identifiers): array
    {
        if (empty($identifiers)) {
            return [];
        }

        // Filter out already cached objects
        $identifiersToLoad = array_filter(
            array_unique($identifiers),
            fn($id) => !isset($this->objectCache[(string) $id])
        );

        if (empty($identifiersToLoad)) {
            // All objects already cached
            return array_filter(
                array_map(
                    fn($id) => $this->objectCache[(string) $id] ?? null,
                    $identifiers
                ),
                fn($obj) => $obj !== null
            );
        }

        // Bulk load from database
        try {
            $objects = $this->objectEntityMapper->findMultiple($identifiersToLoad);

            // Cache all loaded objects
            foreach ($objects as $object) {
                $this->cacheObject($object);
            }

            $this->stats['preloads'] += count($objects);

            return $objects;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Bulk preload failed in ObjectCacheService',
                    [
                        'exception'         => $e->getMessage(),
                        'identifiersToLoad' => count($identifiersToLoad),
                    ]
                    );
            return [];
        }//end try

    }//end preloadObjects()


    /**
     * Cache an object with memory management
     *
     * This method caches an object using both its ID and UUID as keys.
     * It implements LRU-style eviction when the cache becomes too large.
     *
     * @param ObjectEntity $object The object to cache
     *
     * @return void
     */
    private function cacheObject(ObjectEntity $object): void
    {
        // Check cache size and evict oldest entries if necessary
        if (count($this->objectCache) >= $this->maxCacheSize) {
            // Simple cache eviction - remove first 20% of entries
            $entriesToRemove   = (int) ($this->maxCacheSize * 0.2);
            $this->objectCache = array_slice($this->objectCache, $entriesToRemove, null, true);
        }

        // Cache with ID
        $this->objectCache[$object->getId()] = $object;

        // Also cache with UUID if available
        if ($object->getUuid()) {
            $this->objectCache[$object->getUuid()] = $object;
        }

    }//end cacheObject()


    /**
     * Preload relationship data for multiple objects
     *
     * This method analyzes objects and preloads their relationship targets
     * to prevent N+1 queries during rendering.
     *
     * @param array $objects Array of objects to analyze
     * @param array $extend  Array of relationship fields to preload
     *
     * @return array<ObjectEntity> Array of preloaded related objects
     *
     * @phpstan-param  array<ObjectEntity> $objects
     * @phpstan-param  array<string> $extend
     * @phpstan-return array<ObjectEntity>
     * @psalm-param    array<ObjectEntity> $objects
     * @psalm-param    array<string> $extend
     * @psalm-return   array<ObjectEntity>
     */
    public function preloadRelationships(array $objects, array $extend): array
    {
        if (empty($objects) || empty($extend)) {
            return [];
        }

        $allRelationshipIds = [];

        // Extract all relationship IDs
        foreach ($objects as $object) {
            if (!$object instanceof ObjectEntity) {
                continue;
            }

            $objectData = $object->getObject();

            foreach ($extend as $field) {
                if (str_starts_with($field, '@')) {
                    continue;
                }

                $value = $objectData[$field] ?? null;

                if (is_array($value)) {
                    foreach ($value as $relId) {
                        if (is_string($relId) || is_int($relId)) {
                            $allRelationshipIds[] = (string) $relId;
                        }
                    }
                } else if (is_string($value) || is_int($value)) {
                    $allRelationshipIds[] = (string) $value;
                }
            }
        }//end foreach

        // Preload all relationship targets
        return $this->preloadObjects($allRelationshipIds);

    }//end preloadRelationships()


    /**
     * Get cache statistics
     *
     * Returns information about cache performance for monitoring and optimization.
     *
     * @return array{hits: int, misses: int, preloads: int, query_hits: int, query_misses: int, name_hits: int, name_misses: int, name_warmups: int, hit_rate: float, query_hit_rate: float, name_hit_rate: float, cache_size: int, query_cache_size: int, name_cache_size: int}
     *
     * @phpstan-return array{hits: int, misses: int, preloads: int, query_hits: int, query_misses: int, name_hits: int, name_misses: int, name_warmups: int, hit_rate: float, query_hit_rate: float, name_hit_rate: float, cache_size: int, query_cache_size: int, name_cache_size: int}
     * @psalm-return   array{hits: int, misses: int, preloads: int, query_hits: int, query_misses: int, name_hits: int, name_misses: int, name_warmups: int, hit_rate: float, query_hit_rate: float, name_hit_rate: float, cache_size: int, query_cache_size: int, name_cache_size: int}
     */
    public function getStats(): array
    {
        $totalRequests = $this->stats['hits'] + $this->stats['misses'];
        $hitRate       = $totalRequests > 0 ? ($this->stats['hits'] / $totalRequests) * 100 : 0;

        $totalQueryRequests = $this->stats['query_hits'] + $this->stats['query_misses'];
        $queryHitRate       = $totalQueryRequests > 0 ? ($this->stats['query_hits'] / $totalQueryRequests) * 100 : 0;

        $totalNameRequests = $this->stats['name_hits'] + $this->stats['name_misses'];
        $nameHitRate       = $totalNameRequests > 0 ? ($this->stats['name_hits'] / $totalNameRequests) * 100 : 0;

        return array_merge(
                $this->stats,
                [
                    'hit_rate'         => round($hitRate, 2),
                    'query_hit_rate'   => round($queryHitRate, 2),
                    'name_hit_rate'    => round($nameHitRate, 2),
                    'cache_size'       => count($this->objectCache),
                    'query_cache_size' => count($this->inMemoryQueryCache),
                    'name_cache_size'  => count($this->nameCache),
                ]
                );

    }//end getStats()


    /**
     * Get cached query result for search operations
     *
     * This method provides caching for expensive search queries with filters,
     * pagination, and authorization context to avoid repeated database execution.
     *
     * @param array       $query                  Search query parameters
     * @param string|null $activeOrganisationUuid Active organization UUID
     * @param bool        $rbac                   Whether RBAC is enabled
     * @param bool        $multi                  Whether multi-tenancy is enabled
     *
     * @return array|int|null Cached search results or null if not cached
     *
     * @phpstan-return array<ObjectEntity>|int|null
     * @psalm-return   array<ObjectEntity>|int|null
     */
    public function getCachedSearchResult(array $query, ?string $activeOrganisationUuid, bool $rbac, bool $multi): array|int|null
    {
        $cacheKey = $this->generateSearchCacheKey($query, $activeOrganisationUuid, $rbac, $multi);

        // Check in-memory cache first (fastest)
        if (isset($this->inMemoryQueryCache[$cacheKey])) {
            $this->stats['query_hits']++;
            $this->logger->debug(
                    '🚀 SEARCH CACHE HIT (in-memory)',
                    [
                        'cacheKey' => substr($cacheKey, 0, 16).'...',
                    ]
                    );
            return $this->inMemoryQueryCache[$cacheKey];
        }

        // Check distributed cache
        if ($this->queryCache !== null) {
            $cachedResult = $this->queryCache->get($cacheKey);
            if ($cachedResult !== null) {
                // Store in in-memory cache for faster future access
                $this->inMemoryQueryCache[$cacheKey] = $cachedResult;
                $this->stats['query_hits']++;
                $this->logger->debug(
                        '⚡ SEARCH CACHE HIT (distributed)',
                        [
                            'cacheKey' => substr($cacheKey, 0, 16).'...',
                        ]
                        );
                return $cachedResult;
            }
        }

        $this->stats['query_misses']++;
        $this->logger->debug(
                '❌ SEARCH CACHE MISS',
                [
                    'cacheKey' => substr($cacheKey, 0, 16).'...',
                ]
                );
        return null;

    }//end getCachedSearchResult()


    /**
     * Cache search query results
     *
     * This method stores search results in both in-memory and distributed caches
     * for improved performance on repeated queries.
     *
     * @param array       $query                  Search query parameters
     * @param string|null $activeOrganisationUuid Active organization UUID
     * @param bool        $rbac                   Whether RBAC is enabled
     * @param bool        $multi                  Whether multi-tenancy is enabled
     * @param array|int   $result                 Search results to cache
     * @param int         $ttl                    Cache TTL in seconds (default: 300 = 5 minutes)
     *
     * @return void
     *
     * @phpstan-param array<ObjectEntity>|int $result
     * @psalm-param   array<ObjectEntity>|int $result
     */
    public function cacheSearchResult(
        array $query,
        ?string $activeOrganisationUuid,
        bool $rbac,
        bool $multi,
        array|int $result,
        int $ttl=300
    ): void {
        // Enforce maximum cache TTL for office environments
        $ttl = min($ttl, self::MAX_CACHE_TTL);

        $cacheKey = $this->generateSearchCacheKey($query, $activeOrganisationUuid, $rbac, $multi);

        // Store in in-memory cache
        $this->inMemoryQueryCache[$cacheKey] = $result;

        // Store in distributed cache if available
        if ($this->queryCache !== null) {
            try {
                $this->queryCache->set($cacheKey, $result, $ttl);
            } catch (\Exception $e) {
                $this->logger->warning(
                        'Failed to cache search result',
                        [
                            'error'    => $e->getMessage(),
                            'cacheKey' => substr($cacheKey, 0, 16).'...',
                        ]
                        );
            }
        }

        $this->logger->debug(
                '💾 SEARCH RESULT CACHED',
                [
                    'cacheKey'   => substr($cacheKey, 0, 16).'...',
                    'resultType' => is_array($result) ? 'array('.count($result).')' : 'count('.$result.')',
                    'ttl'        => $ttl.'s',
                ]
                );

        // Limit in-memory cache size to prevent memory issues
        if (count($this->inMemoryQueryCache) > 50) {
            // Remove oldest entries (simple FIFO)
            $this->inMemoryQueryCache = array_slice($this->inMemoryQueryCache, -25, null, true);
        }

    }//end cacheSearchResult()


    /**
     * Clear query result caches
     *
     * This method clears cached search results. Called when objects are modified
     * to ensure cache consistency.
     *
     * @param string|null $pattern Optional pattern to clear specific cache entries
     *
     * @return void
     */
    public function clearSearchCache(?string $pattern=null): void
    {
        // Clear in-memory cache
        if ($pattern !== null) {
            $this->inMemoryQueryCache = array_filter(
                $this->inMemoryQueryCache,
                function ($key) use ($pattern) {
                    return strpos($key, $pattern) === false;
                },
                ARRAY_FILTER_USE_KEY
            );
        } else {
            $this->inMemoryQueryCache = [];
        }

        // Clear distributed cache if available
        if ($this->queryCache !== null) {
            try {
                if ($pattern !== null) {
                    // For targeted clearing, we'd need a more sophisticated approach
                    // For now, clear all to ensure consistency
                    $this->queryCache->clear();
                } else {
                    $this->queryCache->clear();
                }
            } catch (\Exception $e) {
                $this->logger->warning(
                        'Failed to clear search cache',
                        [
                            'error'   => $e->getMessage(),
                            'pattern' => $pattern,
                        ]
                        );
            }
        }

        $this->logger->debug('🧹 SEARCH CACHE CLEARED', ['pattern' => $pattern ?? 'all']);

    }//end clearSearchCache()


    /**
     * Clear all search caches related to a specific schema (across all users)
     *
     * **SCHEMA-WIDE INVALIDATION**: When objects in a schema change, we need to clear
     * all cached search results that could include objects from that schema.
     * This ensures colleagues see each other's changes immediately.
     *
     * @param int|null $schemaId   Schema ID to invalidate
     * @param int|null $registerId Register ID for additional context
     * @param string   $operation  Operation performed ('create', 'update', 'delete')
     *
     * @return void
     */
    private function clearSchemaRelatedCaches(?int $schemaId=null, ?int $registerId=null, string $operation='unknown'): void
    {
        $startTime    = microtime(true);
        $clearedCount = 0;

        // **STRATEGY 1**: Clear all in-memory search caches (fast)
        $this->inMemoryQueryCache = [];

        // **STRATEGY 2**: Clear distributed cache entries that could contain objects from this schema
        if ($this->queryCache !== null && $schemaId !== null) {
            try {
                // Since we can't easily pattern-match keys in distributed cache,
                // we clear all search cache entries for now (nuclear approach)
                // TODO: Implement more targeted cache clearing with schema-specific prefixes
                $this->queryCache->clear();

                $this->logger->debug(
                        'Schema-related distributed caches cleared',
                        [
                            'schemaId'   => $schemaId,
                            'registerId' => $registerId,
                            'operation'  => $operation,
                            'strategy'   => 'nuclear_clear',
                        ]
                        );
            } catch (\Exception $e) {
                $this->logger->warning(
                        'Failed to clear schema-related distributed caches',
                        [
                            'schemaId' => $schemaId,
                            'error'    => $e->getMessage(),
                        ]
                        );
            }//end try
        } else {
            // Fallback: clear all search caches if no specific schema
            $this->clearSearchCache();
        }//end if

        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        $this->logger->info(
                'Schema-related caches cleared for CUD operation',
                [
                    'schemaId'      => $schemaId,
                    'registerId'    => $registerId,
                    'operation'     => $operation,
                    'executionTime' => $executionTime.'ms',
                    'impact'        => 'all_users_affected',
                    'strategy'      => $schemaId ? 'schema_targeted' : 'global_fallback',
                ]
                );

    }//end clearSchemaRelatedCaches()


    /**
     * Invalidate caches when objects are modified (CRUD operations)
     *
     * **MAIN CACHE INVALIDATION METHOD**: Called when objects are created,
     * updated, or deleted to ensure cache consistency across the application.
     *
     * @param ObjectEntity|null $object     The object that was modified (null for bulk operations)
     * @param string            $operation  The operation performed (create/update/delete)
     * @param int|null          $registerId Register ID for targeted invalidation
     * @param int|null          $schemaId   Schema ID for targeted invalidation
     *
     * @return void
     */
    public function invalidateForObjectChange(
        ?ObjectEntity $object=null,
        string $operation='unknown',
        ?int $registerId=null,
        ?int $schemaId=null
    ): void {
        $startTime = microtime(true);

        // Extract context from object if provided
        if ($object !== null) {
            $registerId = $registerId ?? $object->getRegister();
            $schemaId   = $schemaId ?? $object->getSchema();
            $orgId      = $object->getOrganisation();
            // Track organization for future use
            // Clear individual object from cache
            $this->clearObjectFromCache($object);

            // **SOLR INTEGRATION**: Index or remove from SOLR based on operation
            if ($operation === 'create' || $operation === 'update') {
                // Index the object in SOLR with immediate commit for instant visibility
                $this->indexObjectInSolr($object, true);

                // Update name cache for the modified object
                $name = $object->getName() ?? $object->getUuid();
                $this->setObjectName($object->getUuid(), $name);
                if ($object->getId() && (string) $object->getId() !== $object->getUuid()) {
                    $this->setObjectName($object->getId(), $name);
                }
            } else if ($operation === 'delete') {
                // Remove from SOLR index with immediate commit for instant visibility
                $this->removeObjectFromSolr($object, true);

                // Remove from name cache
                unset($this->nameCache[$object->getUuid()]);
                unset($this->nameCache[(string) $object->getId()]);
            }
        }//end if

        // **SCHEMA-WIDE INVALIDATION**: Clear ALL search caches for this schema
        // This ensures colleagues see each other's changes immediately
        $this->clearSchemaRelatedCaches($schemaId, $registerId, $operation);

        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        $this->logger->info(
                'Schema-wide cache invalidated for CRUD operation',
                [
                    'operation'     => $operation,
                    'registerId'    => $registerId,
                    'schemaId'      => $schemaId,
                    'objectId'      => $object?->getId(),
                    'executionTime' => $executionTime.'ms',
                    'scope'         => 'all_users_in_schema',
                ]
                );

    }//end invalidateForObjectChange()


    /**
     * Clear specific object from cache by ID/UUID
     *
     * @param ObjectEntity $object The object to remove from cache
     *
     * @return void
     */
    private function clearObjectFromCache(ObjectEntity $object): void
    {
        // Remove by ID
        unset($this->objectCache[$object->getId()]);

        // Remove by UUID if available
        if ($object->getUuid()) {
            unset($this->objectCache[$object->getUuid()]);
        }

        $this->logger->debug(
                'Individual object cleared from cache',
                [
                    'objectId'   => $object->getId(),
                    'objectUuid' => $object->getUuid(),
                ]
                );

    }//end clearObjectFromCache()


    /**
     * Clear caches for specific register/schema combination
     *
     * Used when register or schema configurations change that might affect
     * object queries and validation.
     *
     * @param int|null $registerId Register ID to clear caches for
     * @param int|null $schemaId   Schema ID to clear caches for
     *
     * @return void
     */
    public function invalidateForSchemaChange(?int $registerId=null, ?int $schemaId=null): void
    {
        $startTime = microtime(true);

        // Clear search caches since schema changes affect query results
        $this->clearSearchCache();

        // For individual object cache, we keep objects but they'll be re-validated
        // against new schema on next access
        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        $this->logger->info(
                'Object cache invalidated for schema change',
                [
                    'registerId'    => $registerId,
                    'schemaId'      => $schemaId,
                    'executionTime' => $executionTime.'ms',
                ]
                );

    }//end invalidateForSchemaChange()


    /**
     * Generate cache key for search queries
     *
     * Creates a unique cache key based on search parameters, user context,
     * and authorization settings to ensure proper cache isolation.
     *
     * @param array       $query                  Search query parameters
     * @param string|null $activeOrganisationUuid Active organization UUID
     * @param bool        $rbac                   Whether RBAC is enabled
     * @param bool        $multi                  Whether multi-tenancy is enabled
     *
     * @return string The generated cache key
     */
    private function generateSearchCacheKey(array $query, ?string $activeOrganisationUuid, bool $rbac, bool $multi): string
    {
        $user   = $this->userSession->getUser();
        $userId = $user ? $user->getUID() : 'anonymous';

        // Create consistent key components
        $keyComponents = [
            'user'  => $userId,
            'org'   => $activeOrganisationUuid ?? 'null',
            'rbac'  => $rbac ? 'true' : 'false',
            'multi' => $multi ? 'true' : 'false',
            'query' => $query,
        ];

        // Sort query parameters for consistent key generation
        if (isset($keyComponents['query']) && is_array($keyComponents['query'])) {
            ksort($keyComponents['query']);
            array_walk_recursive(
                    $keyComponents['query'],
                    function (&$value) {
                        if (is_array($value)) {
                            sort($value);
                        }
                    }
                    );
        }

        return 'search_'.hash('sha256', json_encode($keyComponents));

    }//end generateSearchCacheKey()


    /**
     * Clear all caches (Administrative Operation)
     *
     * **NUCLEAR OPTION**: Removes all cached objects, search results, name caches, and resets statistics.
     * Use sparingly - typically for administrative operations or major system changes.
     *
     * @return void
     */
    public function clearAllCaches(): void
    {
        $startTime = microtime(true);

        $this->objectCache        = [];
        $this->relationshipCache  = [];
        $this->inMemoryQueryCache = [];
        $this->nameCache          = [];
        $this->stats = ['hits' => 0, 'misses' => 0, 'preloads' => 0, 'query_hits' => 0, 'query_misses' => 0, 'name_hits' => 0, 'name_misses' => 0, 'name_warmups' => 0];

        // Clear distributed query cache
        if ($this->queryCache !== null) {
            try {
                $this->queryCache->clear();
            } catch (\Exception $e) {
                $this->logger->warning(
                        'Failed to clear distributed query cache',
                        [
                            'error' => $e->getMessage(),
                        ]
                        );
            }
        }

        // Clear distributed name cache
        if ($this->nameDistributedCache !== null) {
            try {
                $this->nameDistributedCache->clear();
            } catch (\Exception $e) {
                $this->logger->warning(
                        'Failed to clear distributed name cache',
                        [
                            'error' => $e->getMessage(),
                        ]
                        );
            }
        }

        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        $this->logger->info(
                'All object caches cleared (including name cache)',
                [
                    'executionTime' => $executionTime.'ms',
                ]
                );

    }//end clearAllCaches()


    /**
     * Clear the cache (legacy method - kept for backward compatibility)
     *
     * @deprecated Use clearAllCaches() instead
     * @return     void
     */
    public function clearCache(): void
    {
        $this->clearAllCaches();

    }//end clearCache()


    // ========================================
    // OBJECT NAME CACHE METHODS
    // ========================================


    /**
     * Set object name in cache
     *
     * Stores the name of an object in both in-memory and distributed caches
     * for ultra-fast frontend rendering without full object retrieval.
     *
     * @param string|int $identifier Object ID or UUID
     * @param string     $name       Object name to cache
     * @param int        $ttl        Cache TTL in seconds (default: 1 hour)
     *
     * @return void
     */
    public function setObjectName(string|int $identifier, string $name, int $ttl=3600): void
    {
        $key = (string) $identifier;

        // Enforce maximum cache TTL
        $ttl = min($ttl, self::MAX_CACHE_TTL);

        // Store in in-memory cache
        $this->nameCache[$key] = $name;

        // Store in distributed cache if available
        if ($this->nameDistributedCache !== null) {
            try {
                $this->nameDistributedCache->set('name_'.$key, $name, $ttl);
            } catch (\Exception $e) {
                $this->logger->warning(
                        'Failed to cache object name in distributed cache',
                        [
                            'identifier' => $key,
                            'error'      => $e->getMessage(),
                        ]
                        );
            }
        }

        $this->logger->debug(
                '💾 OBJECT NAME CACHED',
                [
                    'identifier' => $key,
                    'name'       => $name,
                    'ttl'        => $ttl.'s',
                ]
                );

    }//end setObjectName()


    /**
     * Get single object name from cache or database
     *
     * Provides ultra-fast name lookup for frontend rendering.
     * Falls back to database if not cached.
     *
     * @param string|int $identifier Object ID or UUID
     *
     * @return string|null Object name or null if not found
     */
    public function getSingleObjectName(string|int $identifier): ?string
    {
        $key = (string) $identifier;

        // Check in-memory cache first (fastest)
        if (isset($this->nameCache[$key])) {
            $this->stats['name_hits']++;
            $this->logger->debug('🚀 NAME CACHE HIT (in-memory)', ['identifier' => $key]);
            return $this->nameCache[$key];
        }

        // Check distributed cache
        if ($this->nameDistributedCache !== null) {
            try {
                $cachedName = $this->nameDistributedCache->get('name_'.$key);
                if ($cachedName !== null) {
                    // Store in in-memory cache for faster future access
                    $this->nameCache[$key] = $cachedName;
                    $this->stats['name_hits']++;
                    $this->logger->debug('⚡ NAME CACHE HIT (distributed)', ['identifier' => $key]);
                    return $cachedName;
                }
            } catch (\Exception $e) {
                $this->logger->warning(
                        'Failed to get object name from distributed cache',
                        [
                            'identifier' => $key,
                            'error'      => $e->getMessage(),
                        ]
                        );
            }
        }

        // Cache miss - load from database
        $this->stats['name_misses']++;
        $this->logger->debug('❌ NAME CACHE MISS', ['identifier' => $key]);

        try {
            // STEP 1: Try to find as organisation first (they take priority)
            try {
                $organisation = $this->organisationMapper->findByUuid($identifier);
                if ($organisation !== null) {
                    $name = $organisation->getName() ?? $organisation->getUuid();
                    $this->setObjectName($identifier, $name);
                    return $name;
                }
            } catch (\Exception $e) {
                // Organisation not found, continue to objects
            }

            // STEP 2: Try to find as object
            $object = $this->objectEntityMapper->find($identifier);
            if ($object !== null) {
                $name = $object->getName() ?? $object->getUuid();
                $this->setObjectName($identifier, $name);
                return $name;
            }
        } catch (\Exception $e) {
            $this->logger->debug(
                    'Failed to load entity for name lookup',
                    [
                        'identifier' => $key,
                        'error'      => $e->getMessage(),
                    ]
                    );
        }//end try

        return null;

    }//end getSingleObjectName()


    /**
     * Get multiple object names from cache or database
     *
     * Efficiently retrieves names for multiple objects using bulk operations
     * to minimize database queries.
     *
     * @param array $identifiers Array of object IDs/UUIDs
     *
     * @return array<string, string> Array mapping identifier => name
     *
     * @phpstan-param  array<string|int> $identifiers
     * @phpstan-return array<string, string>
     * @psalm-param    array<string|int> $identifiers
     * @psalm-return   array<string, string>
     */
    public function getMultipleObjectNames(array $identifiers): array
    {
        if (empty($identifiers)) {
            return [];
        }

        $results            = [];
        $missingIdentifiers = [];

        // Check in-memory cache for all identifiers
        foreach ($identifiers as $identifier) {
            $key = (string) $identifier;
            if (isset($this->nameCache[$key])) {
                $results[$key] = $this->nameCache[$key];
                $this->stats['name_hits']++;
            } else {
                $missingIdentifiers[] = $key;
            }
        }

        // Check distributed cache for missing identifiers
        if (!empty($missingIdentifiers) && $this->nameDistributedCache !== null) {
            $distributedResults = [];
            foreach ($missingIdentifiers as $key) {
                try {
                    $cachedName = $this->nameDistributedCache->get('name_'.$key);
                    if ($cachedName !== null) {
                        $distributedResults[$key] = $cachedName;
                        $this->nameCache[$key]    = $cachedName;
                        // Store in memory
                        $this->stats['name_hits']++;
                    }
                } catch (\Exception $e) {
                    // Continue processing other identifiers
                }
            }

            $results            = array_merge($results, $distributedResults);
            $missingIdentifiers = array_diff($missingIdentifiers, array_keys($distributedResults));
        }

        // Load remaining missing names from database
        if (!empty($missingIdentifiers)) {
            $this->stats['name_misses'] += count($missingIdentifiers);

            try {
                // STEP 1: Try to find organisations first (they take priority)
                $organisations = $this->organisationMapper->findMultipleByUuid($missingIdentifiers);
                foreach ($organisations as $organisation) {
                    $name          = $organisation->getName() ?? $organisation->getUuid();
                    $key           = $organisation->getUuid();
                    $results[$key] = $name;

                    // Cache for future use (UUID only)
                    $this->setObjectName($key, $name);

                    // Remove from missing list since we found it
                    $missingIdentifiers = array_diff($missingIdentifiers, [$key]);
                }

                // STEP 2: Try to find remaining identifiers as objects
                if (!empty($missingIdentifiers)) {
                    $objects = $this->objectEntityMapper->findMultiple($missingIdentifiers);
                    foreach ($objects as $object) {
                        $name          = $object->getName() ?? $object->getUuid();
                        $key           = $object->getUuid();
                        $results[$key] = $name;

                        // Cache for future use (UUID only)
                        $this->setObjectName($key, $name);
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error(
                        'Failed to bulk load names from database',
                        [
                            'identifiers' => count($missingIdentifiers),
                            'error'       => $e->getMessage(),
                        ]
                        );
            }//end try
        }//end if

        // Filter to return only UUID -> name mappings (exclude database IDs)
        $uuidResults = array_filter(
                $results,
                function ($key) {
                    // Only return entries where key looks like a UUID (contains hyphens)
                    return is_string($key) && str_contains($key, '-');
                },
                ARRAY_FILTER_USE_KEY
                );

        $this->logger->debug(
                '📦 BULK NAME LOOKUP COMPLETED',
                [
                    'requested'             => count($identifiers),
                    'total_found'           => count($results),
                    'uuid_results_returned' => count($uuidResults),
                    'cache_hits'            => count($identifiers) - count($missingIdentifiers),
                    'db_loads'              => count($missingIdentifiers),
                ]
                );

        return $uuidResults;

    }//end getMultipleObjectNames()


    /**
     * Get all object names with cache warmup
     *
     * Returns all object names in the system. Triggers cache warmup
     * to ensure optimal performance for subsequent name lookups.
     *
     * @param bool $forceWarmup Whether to force cache warmup even if cache exists
     *
     * @return array<string, string> Array mapping identifier => name
     *
     * @phpstan-return array<string, string>
     * @psalm-return   array<string, string>
     */
    public function getAllObjectNames(bool $forceWarmup=false): array
    {
        $startTime = microtime(true);

        // Check if we should trigger warmup.
        $shouldWarmup = $forceWarmup || empty($this->nameCache);

        if ($shouldWarmup === true) {
            $this->warmupNameCache();
        }

        // Filter to return only UUID -> name mappings (exclude database IDs)
        $uuidNames = array_filter(
                $this->nameCache,
                function ($key) {
                    // Only return entries where key looks like a UUID (contains hyphens)
                    return is_string($key) && str_contains($key, '-');
                },
                ARRAY_FILTER_USE_KEY
                );

        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        $this->logger->info(
                '📋 ALL OBJECT NAMES RETRIEVED',
                [
                    'total_cached'        => count($this->nameCache),
                    'uuid_names_returned' => count($uuidNames),
                    'warmup_triggered'    => $shouldWarmup,
                    'execution_time'      => $executionTime.'ms',
                ]
                );

        return $uuidNames;

    }//end getAllObjectNames()


    /**
     * Warmup name cache by preloading all object names
     *
     * Loads all object names from the database into cache to ensure
     * optimal performance for name lookup operations.
     *
     * @return int Number of names loaded into cache
     */
    public function warmupNameCache(): int
    {
        $startTime = microtime(true);
        $this->stats['name_warmups']++;

        try {
            $loadedCount = 0;

            // STEP 1: Load all organisations first (they take priority)
            $organisations = $this->organisationMapper->findAllWithUserCount();
            foreach ($organisations as $organisation) {
                $name = $organisation->getName() ?? $organisation->getUuid();

                // Cache by UUID only (not by database ID)
                if ($organisation->getUuid()) {
                    $this->nameCache[$organisation->getUuid()] = $name;
                    $loadedCount++;
                }
            }

            // STEP 2: Load all objects (organisations will overwrite if same UUID)
            $objects = $this->objectEntityMapper->findAll();
            foreach ($objects as $object) {
                $name = $object->getName() ?? $object->getUuid();

                // Cache by UUID only (not by database ID)
                // Note: If an organisation has the same UUID, it will remain (organisations loaded first)
                if ($object->getUuid() && !isset($this->nameCache[$object->getUuid()])) {
                    $this->nameCache[$object->getUuid()] = $name;
                    $loadedCount++;
                }
            }

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->info(
                    '🔥 NAME CACHE WARMED UP',
                    [
                        'organisations_processed' => count($organisations),
                        'objects_processed'       => count($objects),
                        'total_names_cached'      => $loadedCount,
                        'execution_time'          => $executionTime.'ms',
                    ]
                    );

            return $loadedCount;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Name cache warmup failed',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );
            return 0;
        }//end try

    }//end warmupNameCache()


    /**
     * Clear object name caches
     *
     * Removes all cached object names from both in-memory and distributed caches.
     * Called when objects are modified to ensure name consistency.
     *
     * @return void
     */
    public function clearNameCache(): void
    {
        // Clear in-memory name cache
        $this->nameCache = [];

        // Clear distributed name cache
        if ($this->nameDistributedCache !== null) {
            try {
                $this->nameDistributedCache->clear();
            } catch (\Exception $e) {
                $this->logger->warning(
                        'Failed to clear distributed name cache',
                        [
                            'error' => $e->getMessage(),
                        ]
                        );
            }
        }

        $this->logger->debug('🧹 OBJECT NAME CACHE CLEARED');

    }//end clearNameCache()


    // ========================================
    // SOLR BULK OPERATIONS
    // ========================================


    /**
     * Warm up SOLR index with all objects (bulk operation)
     *
     * Efficiently indexes all objects in the database to SOLR in batches,
     * optimized for handling 200K+ objects with performance monitoring.
     *
     * @param int|null $registerId  Optional register filter
     * @param int|null $schemaId    Optional schema filter
     * @param int      $batchSize   Number of objects to process per batch
     * @param int      $commitEvery Commit to SOLR every N batches
     *
     * @return array Performance statistics
     */
    public function warmupSolrIndex(?int $registerId=null, ?int $schemaId=null, int $batchSize=500, int $commitEvery=10): array
    {
        // Get SOLR service using factory pattern (performance optimized)
        $solrService = $this->getSolrService();
        if ($solrService === null || !$solrService->isAvailable()) {
            return [
                'success' => false,
                'message' => 'SOLR service is not available',
                'stats'   => [],
            ];
        }

        $startTime      = microtime(true);
        $totalProcessed = 0;
        $totalIndexed   = 0;
        $totalErrors    = 0;
        $batchCount     = 0;

        $this->logger->info(
                '🔥 STARTING SOLR INDEX WARMUP',
                [
                    'registerId'  => $registerId,
                    'schemaId'    => $schemaId,
                    'batchSize'   => $batchSize,
                    'commitEvery' => $commitEvery,
                ]
                );

        try {
            // Get total count for progress tracking - use countAll with no published filter to get ALL objects
            $totalCount = $this->objectEntityMapper->countAll(
                filters: [],
                search: null,
                ids: null,
                uses: null,
                includeDeleted: false,
                register: null,
            // Don't filter by register for total count
                schema: null,
            // Don't filter by schema for total count
                published: null,
            // Count ALL objects (published and unpublished)
                rbac: false,
                multi: false
            );

            $this->logger->info(
                    '📊 SOLR WARMUP: Total objects to process (ALL objects, not just published)',
                    [
                        'totalCount'        => $totalCount,
                        'registerId'        => $registerId,
                        'schemaId'          => $schemaId,
                        'estimatedDuration' => round(($totalCount / $batchSize) * 2).'s',
                    ]
                    );

            $offset = 0;

            while (true) {
                $batchStartTime = microtime(true);

                // Load batch of objects
                $objects = $this->objectEntityMapper->findInBatches(
                    $offset,
                    $batchSize,
                    $registerId,
                    $schemaId
                );

                if (empty($objects)) {
                    break;
                    // No more objects
                }

                $batchCount++;
                $batchIndexed = 0;
                $batchErrors  = 0;

                // Prepare batch of SOLR documents using consistent schema-aware mapping
                $solrDocuments = [];
                foreach ($objects as $object) {
                    try {
                        // Use the same createSolrDocument method as single object creation for consistency
                        $solrDocument    = $solrService->createSolrDocument($object);
                        $solrDocuments[] = $solrDocument;
                        $batchIndexed++;
                    } catch (\Exception $e) {
                        $batchErrors++;
                        $this->logger->warning(
                                'Failed to create SOLR document',
                                [
                                    'object_id' => $object->getId(),
                                    'error'     => $e->getMessage(),
                                ]
                                );
                    }
                }

                // Bulk index documents in SOLR.
                if (!empty($solrDocuments)) {
                    $bulkResult = $solrService->bulkIndex($solrDocuments, true);
                    if ($bulkResult === false) {
                        $batchErrors += count($solrDocuments);
                        $this->logger->warning(
                                'Bulk index failed for batch',
                                [
                                    'batchCount'     => $batchCount,
                                    'documentsCount' => count($solrDocuments),
                                ]
                                );
                    }
                }

                // Commit periodically for memory management
                if ($batchCount % $commitEvery === 0) {
                    $solrService->commit();
                    $this->logger->debug(
                            '💾 SOLR COMMIT',
                            [
                                'batchesProcessed' => $batchCount,
                                'objectsProcessed' => $totalProcessed + count($objects),
                            ]
                            );
                }

                $totalProcessed += count($objects);
                $totalIndexed   += $batchIndexed;
                $totalErrors    += $batchErrors;
                $offset         += $batchSize;

                $batchDuration   = microtime(true) - $batchStartTime;
                $progressPercent = round(($totalProcessed / $totalCount) * 100, 1);

                // Log progress every 10 batches
                if ($batchCount % 10 === 0) {
                    $this->logger->info(
                            '📈 SOLR WARMUP PROGRESS',
                            [
                                'batchCount'          => $batchCount,
                                'processed'           => $totalProcessed,
                                'total'               => $totalCount,
                                'progress'            => $progressPercent.'%',
                                'indexed'             => $totalIndexed,
                                'errors'              => $totalErrors,
                                'batchDuration'       => round($batchDuration * 1000).'ms',
                                'avgObjectsPerSecond' => round(count($objects) / $batchDuration),
                            ]
                            );
                }

                // Memory management
                if ($batchCount % 50 === 0) {
                    $this->logger->debug(
                            '🧹 MEMORY CLEANUP',
                            [
                                'memoryUsage' => round(memory_get_usage() / 1024 / 1024, 2).'MB',
                                'peakMemory'  => round(memory_get_peak_usage() / 1024 / 1024, 2).'MB',
                            ]
                            );
                }

                // Prevent memory exhaustion
                unset($objects, $solrDocuments);
            }//end while

            // Final commit
            $solrService->commit();

            $totalDuration       = microtime(true) - $startTime;
            $avgObjectsPerSecond = $totalProcessed > 0 ? round($totalProcessed / $totalDuration) : 0;

            $stats = [
                'success'             => true,
                'totalProcessed'      => $totalProcessed,
                'totalIndexed'        => $totalIndexed,
                'totalErrors'         => $totalErrors,
                'batchCount'          => $batchCount,
                'duration'            => round($totalDuration, 2),
                'avgObjectsPerSecond' => $avgObjectsPerSecond,
                'memoryPeak'          => round(memory_get_peak_usage() / 1024 / 1024, 2).'MB',
                'errorRate'           => $totalProcessed > 0 ? round(($totalErrors / $totalProcessed) * 100, 2).'%' : '0%',
            ];

            $this->logger->info('✅ SOLR INDEX WARMUP COMPLETED', $stats);

            return ['success' => true, 'stats' => $stats];
        } catch (\Exception $e) {
            $duration = microtime(true) - $startTime;

            $this->logger->error(
                    '❌ SOLR WARMUP FAILED',
                    [
                        'error'     => $e->getMessage(),
                        'processed' => $totalProcessed,
                        'duration'  => round($duration, 2),
                        'trace'     => $e->getTraceAsString(),
                    ]
                    );

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'stats'   => [
                    'totalProcessed' => $totalProcessed,
                    'totalIndexed'   => $totalIndexed,
                    'totalErrors'    => $totalErrors,
                    'duration'       => round($duration, 2),
                ],
            ];
        }//end try

    }//end warmupSolrIndex()


    /**
     * Clear entire SOLR index for a register/schema
     *
     * @param int|null $registerId Optional register filter
     * @param int|null $schemaId   Optional schema filter
     *
     * @return bool True if clearing was successful
     */
    public function clearSolrIndex(?int $registerId=null, ?int $schemaId=null): bool
    {
        // Get SOLR service using factory pattern (performance optimized)
        $solrService = $this->getSolrService();
        if ($solrService === null || !$solrService->isAvailable()) {
            return true;
            // Graceful degradation
        }

        try {
            // Build query to clear specific register/schema or all
            $query = '*:*';
            // Default: clear all
            if ($registerId !== null && $schemaId !== null) {
                $query = 'register_id_i:'.$registerId.' AND schema_id_i:'.$schemaId;
            } else if ($registerId !== null) {
                $query = 'register_id_i:'.$registerId;
            } else if ($schemaId !== null) {
                $query = 'schema_id_i:'.$schemaId;
            }

            $result = $solrService->deleteByQuery($query, true);

            $this->logger->info(
                    '🗑️  SOLR INDEX CLEARED',
                    [
                        'registerId' => $registerId,
                        'schemaId'   => $schemaId,
                        'query'      => $query,
                        'success'    => $result,
                    ]
                    );

            return $result;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to clear SOLR index',
                    [
                        'registerId' => $registerId,
                        'schemaId'   => $schemaId,
                        'error'      => $e->getMessage(),
                    ]
                    );
            return false;
        }//end try

    }//end clearSolrIndex()


    /**
     * Get comprehensive SOLR dashboard statistics
     *
     * @return array Dashboard statistics from SolrService
     */
    public function getSolrDashboardStats(): array
    {
        $solrService = $this->getSolrService();
        if ($solrService === null) {
            throw new \RuntimeException('SOLR service is not available');
        }

        return $solrService->getDashboardStats();

    }//end getSolrDashboardStats()


    /**
     * Commit SOLR index
     *
     * @return array Commit operation results
     */
    public function commitSolr(): array
    {
        $solrService = $this->getSolrService();
        if ($solrService === null) {
            return ['success' => false, 'error' => 'SOLR service is not available'];
        }

        try {
            $result = $solrService->commit();
            return [
                'success'   => $result,
                'timestamp' => date('c'),
                'message'   => $result ? 'Commit successful' : 'Commit failed',
            ];
        } catch (\Exception $e) {
            return [
                'success'   => false,
                'error'     => $e->getMessage(),
                'timestamp' => date('c'),
            ];
        }

    }//end commitSolr()


    /**
     * Optimize SOLR index
     *
     * @return array Optimize operation results
     */
    public function optimizeSolr(): array
    {
        $solrService = $this->getSolrService();
        if ($solrService === null) {
            return ['success' => false, 'error' => 'SOLR service is not available'];
        }

        try {
            $result = $solrService->optimize();
            return [
                'success'   => $result,
                'timestamp' => date('c'),
                'message'   => $result ? 'Optimization successful' : 'Optimization failed',
            ];
        } catch (\Exception $e) {
            return [
                'success'   => false,
                'error'     => $e->getMessage(),
                'timestamp' => date('c'),
            ];
        }

    }//end optimizeSolr()


    /**
     * Clear SOLR index completely for dashboard
     *
     * @return array Clear operation results
     */
    public function clearSolrIndexForDashboard(): array
    {
        $solrService = $this->getSolrService();
        if ($solrService === null) {
            return ['success' => false, 'error' => 'SOLR service is not available'];
        }

        try {
            $result = $solrService->clearIndex();
            return [
                'success'       => $result['success'],
                'error'         => $result['error'] ?? null,
                'error_details' => $result['error_details'] ?? null,
                'timestamp'     => date('c'),
                'message'       => $result['success'] ? 'Index cleared successfully' : 'Index clear failed',
            ];
        } catch (\Exception $e) {
            return [
                'success'   => false,
                'error'     => $e->getMessage(),
                'timestamp' => date('c'),
            ];
        }

    }//end clearSolrIndexForDashboard()


    /**
     * Test SOLR connection
     *
     * @return array Connection test results
     */
    public function testSolrConnection(): array
    {
        $solrService = $this->getSolrService();
        if ($solrService === null) {
            return [
                'success' => false,
                'message' => 'SOLR service is not available',
                'details' => [],
            ];
        }

        return $solrService->testConnection();

    }//end testSolrConnection()


    /**
     * Get SOLR service statistics
     *
     * @return array SOLR statistics
     */
    public function getSolrStats(): array
    {
        $solrService = $this->getSolrService();
        if ($solrService === null) {
            return ['available' => false];
        }

        return $solrService->getStats();

    }//end getSolrStats()


}//end class
