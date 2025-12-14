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

use RuntimeException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\AppFramework\IAppContainer;
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
 * @version   GIT: <git_id>
 * @copyright 2024 Conduction b.v.
 */
class ObjectCacheService
{

    /**
     * In-memory cache of objects indexed by ID/UUID
     *
     * @var array<string|int, ObjectEntity>
     */
    private array $objectCache = [];

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
     * Container for lazy loading GuzzleSolrService to break circular dependency
     *
     * @var IAppContainer|null
     */
    private ?IAppContainer $container = null;


    /**
     * Constructor for ObjectCacheService
     *
     * @param ObjectEntityMapper $objectEntityMapper The object entity mapper
     * @param OrganisationMapper $organisationMapper The organisation entity mapper
     * @param LoggerInterface    $logger             Logger for performance monitoring
     * @param ICacheFactory|null $cacheFactory       Cache factory for query result caching
     * @param IUserSession|null  $userSession        User session for cache key generation
     * @param IAppContainer|null $container          Container for lazy loading GuzzleSolrService (optional)
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly OrganisationMapper $organisationMapper,
        private readonly LoggerInterface $logger,
        ?ICacheFactory $cacheFactory=null,
        ?IUserSession $userSession=null,
        ?IAppContainer $container=null
    ) {
        // Initialize query cache if available.
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


            /**
             * Get user.
             *
             * @return null
             */
            public function getUser()
            {
                return null;
            }//end getUser()


        };
        $this->container   = $container;

    }//end __construct()


    /**
     * Get GuzzleSolrService instance using lazy loading from container
     *
     * Lazy loads GuzzleSolrService from container to break circular dependency.
     * Returns null if SOLR is unavailable or disabled.
     *
     * @return GuzzleSolrService|null SOLR service instance or null
     */
    private function getSolrService(): ?GuzzleSolrService
    {
        // Lazy-load GuzzleSolrService from container to break circular dependency.
        if ($this->container === null) {
            return null;
        }

        try {
            return $this->container->get(\OCA\OpenRegister\Service\GuzzleSolrService::class);
        } catch (\Exception $e) {
            // If GuzzleSolrService is not available, return null (graceful degradation).
            $this->logger->debug(
                    'GuzzleSolrService not available',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );
            return null;
        }

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

        // Check cache first.
        if (($this->objectCache[$key] ?? null) !== null) {
            $this->stats['hits']++;
            return $this->objectCache[$key];
        }

        // Cache miss - load from database.
        $this->stats['misses']++;

        try {
            $object = $this->objectEntityMapper->find($identifier);

            // Cache the object with both ID and UUID as keys.
            $this->cacheObject($object);

            return $object;
        } catch (\Exception $e) {
            return null;
        }

    }//end getObject()


    // ========================================.
    // SOLR INTEGRATION METHODS.
    // ========================================.


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
     *
     * @psalm-suppress UnusedReturnValue
     */
    private function indexObjectInSolr(ObjectEntity $object, bool $commit=false): bool
    {
        // Get SOLR service using factory pattern (performance optimized).
        $solrService = $this->getSolrService();

        // Determine SOLR availability for logging.
        if ($solrService !== null) {
            $solrIsAvailable = $solrService->isAvailable();
        } else {
            $solrIsAvailable = false;
        }

        $this->logger->info(
                '🔥 DEBUGGING: indexObjectInSolr called',
                [
                    'app'                    => 'openregister',
                    'object_id'              => $object->getId(),
                    'object_uuid'            => $object->getUuid(),
                    'object_name'            => $object->getName(),
                    'solr_service_available' => $solrService !== null,
                    'solr_is_available'      => $solrIsAvailable,
                ]
                );

        if ($solrService === null || $solrService->isAvailable() === false) {
            $this->logger->debug(
                    'SOLR service unavailable, skipping indexing',
                    [
                        'object_id'              => $object->getId(),
                        'solr_service_available' => $solrService !== null,
                        'solr_is_available'      => $solrIsAvailable,
                    ]
                    );
            return true;
            // Graceful degradation.
        }

        // Index in SOLR.
        $result = $solrService->indexObject(object: $object, commit: $commit);

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
     *
     * @psalm-suppress UnusedReturnValue
     */
    private function removeObjectFromSolr(ObjectEntity $object, bool $commit=false): bool
    {
        // Get SOLR service using factory pattern (performance optimized).
        $solrService = $this->getSolrService();
        if ($solrService === null || $solrService->isAvailable() === false) {
            return true;
            // Graceful degradation.
        }

        try {
            $result = $solrService->deleteObject(objectId: $object->getUuid(), commit: $commit);

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
            // Don't fail the whole operation for SOLR issues.
        }//end try

    }//end removeObjectFromSolr()


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
            // Skip meta fields and null values.
            if ($key === '@self' || $key === 'id' || $value === null) {
                continue;
            }

            $fieldName = $prefix.$key;

            if (is_array($value) === true) {
                if (($value[0] ?? null) !== null) {
                    // Multi-value array.
                    $dynamicFields[$fieldName.'_ss'] = $value;
                    // Also add as text for searching.
                    $dynamicFields[$fieldName.'_txt'] = implode(' ', array_filter($value, 'is_string'));
                } else {
                    // Nested object - recurse with dot notation.
                    $nestedFields  = $this->extractDynamicFieldsFromObject(objectData: $value, prefix: $fieldName.'_');
                    $dynamicFields = array_merge($dynamicFields, $nestedFields);
                }
            } else if (is_string($value) === true) {
                $dynamicFields[$fieldName.'_s']   = $value;
                $dynamicFields[$fieldName.'_txt'] = $value;
            } else if (is_int($value) === true || is_float($value) === true) {
                if (is_int($value) === true) {
                    $suffix = '_i';
                } else {
                    $suffix = '_f';
                }

                $dynamicFields[$fieldName.$suffix] = $value;
            } else if (is_bool($value) === true) {
                $dynamicFields[$fieldName.'_b'] = $value;
            } else if ($this->isDateString($value) === true) {
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

        // Add metadata fields.
        $textContent[] = $object->getName();
        $textContent[] = $object->getDescription();
        $textContent[] = $object->getSummary();

        // Extract text from object data recursively.
        $this->extractTextFromArray(data: $objectData, textContent: $textContent);

        return implode(' ', array_filter($textContent));

    }//end buildFullTextContent()


    /**
     * Extract text content from array recursively
     *
     * @param array $data        Array to extract text from
     * @param array $textContent Reference to text content array
     *
     * @return void
     */
    private function extractTextFromArray(array $data, array &$textContent): void
    {
        foreach ($data as $_key => $value) {
            if (is_string($value) === true) {
                $textContent[] = $value;
            } else if (is_array($value) === true) {
                $this->extractTextFromArray(data: $value, textContent: $textContent);
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
        if (is_string($value) === false) {
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
     * @return (ObjectEntity|\\OCA\OpenRegister\Db\ObjectEntity)[]
     *
     * @phpstan-param array<int|string> $identifiers
     *
     * @phpstan-return array<ObjectEntity>
     *
     * @psalm-param array<int|string> $identifiers
     *
     * @psalm-return array<ObjectEntity|\\OCA\OpenRegister\Db\ObjectEntity>
     */
    public function preloadObjects(array $identifiers): array
    {
        if (empty($identifiers) === true) {
            return [];
        }

        // Filter out already cached objects.
        $identifiersToLoad = array_filter(
            array_unique($identifiers),
            fn($id) => isset($this->objectCache[(string) $id]) === false
        );

        if (empty($identifiersToLoad) === true) {
            // All objects already cached.
            return array_filter(
                array_map(
                    fn($id) => $this->objectCache[(string) $id] ?? null,
                    $identifiers
                ),
                fn($obj) => $obj !== null
            );
        }

        // Bulk load from database.
        try {
            $objects = $this->objectEntityMapper->findMultiple($identifiersToLoad);

            // Cache all loaded objects.
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
        // Check cache size and evict oldest entries if necessary.
        if (count($this->objectCache) >= $this->maxCacheSize) {
            // Simple cache eviction - remove first 20% of entries.
            $entriesToRemove   = (int) ($this->maxCacheSize * 0.2);
            $this->objectCache = array_slice($this->objectCache, $entriesToRemove, null, true);
        }

        // Cache with ID.
        $this->objectCache[$object->getId()] = $object;

        // Also cache with UUID if available.
        if (($object->getUuid() !== null) === true) {
            $this->objectCache[$object->getUuid()] = $object;
        }

    }//end cacheObject()


    /**
     * Get cache statistics
     *
     * Returns information about cache performance for monitoring and optimization.
     *
     * @return (float|int)[]
     *
     * @phpstan-return array{hits: int, misses: int, preloads: int, query_hits: int, query_misses: int, name_hits: int, name_misses: int, name_warmups: int, hit_rate: float, query_hit_rate: float, name_hit_rate: float, cache_size: int, query_cache_size: int, name_cache_size: int}
     *
     * @psalm-return array{hits: int, misses: int, preloads: int, query_hits: int, query_misses: int, name_hits: int, name_misses: int, name_warmups: int, hit_rate: float, query_hit_rate: float, name_hit_rate: float, cache_size: int<0, max>, query_cache_size: int<0, max>, name_cache_size: int<0, max>}
     */
    public function getStats(): array
    {
        $totalRequests = $this->stats['hits'] + $this->stats['misses'];
        if ($totalRequests > 0) {
            $hitRate = ($this->stats['hits'] / $totalRequests) * 100;
        } else {
            $hitRate = 0;
        }

        $totalQueryRequests = $this->stats['query_hits'] + $this->stats['query_misses'];
        if ($totalQueryRequests > 0) {
            $queryHitRate = ($this->stats['query_hits'] / $totalQueryRequests) * 100;
        } else {
            $queryHitRate = 0;
        }

        $totalNameRequests = $this->stats['name_hits'] + $this->stats['name_misses'];
        if ($totalNameRequests > 0) {
            $nameHitRate = ($this->stats['name_hits'] / $totalNameRequests) * 100;
        } else {
            $nameHitRate = 0;
        }

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
        // Clear in-memory cache.
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

        // Clear distributed cache if available.
        if ($this->queryCache !== null) {
            try {
                if ($pattern !== null) {
                    // For targeted clearing, we'd need a more sophisticated approach.
                    // For now, clear all to ensure consistency.
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

        $this->logger->debug(message: '🧹 SEARCH CACHE CLEARED', context: ['pattern' => $pattern ?? 'all']);

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
        $startTime = microtime(true);

        // **STRATEGY 1**: Clear all in-memory search caches (fast).
        $this->inMemoryQueryCache = [];

        // **STRATEGY 2**: Clear distributed cache entries that could contain objects from this schema.
        if ($this->queryCache !== null && $schemaId !== null) {
            try {
                // Since we can't easily pattern-match keys in distributed cache,.
                // we clear all search cache entries for now (nuclear approach).
                // TODO: Implement more targeted cache clearing with schema-specific prefixes.
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
            // Fallback: clear all search caches if no specific schema.
            $this->clearSearchCache();
        }//end if

        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        // Determine strategy for logging.
        if ($schemaId !== null) {
            $strategy = 'schema_targeted';
        } else {
            $strategy = 'global_fallback';
        }

        $this->logger->info(
                'Schema-related caches cleared for CUD operation',
                [
                    'schemaId'      => $schemaId,
                    'registerId'    => $registerId,
                    'operation'     => $operation,
                    'executionTime' => $executionTime.'ms',
                    'impact'        => 'all_users_affected',
                    'strategy'      => $strategy,
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

        // Extract context from object if provided.
        if ($object !== null) {
            // Extract register ID if not provided.
            if ($registerId === null) {
                if ($object->getRegister() !== null) {
                    $registerId = (int) $object->getRegister();
                } else {
                    $registerId = null;
                }
            }

            // Extract schema ID if not provided.
            if ($schemaId === null) {
                if ($object->getSchema() !== null) {
                    $schemaId = (int) $object->getSchema();
                } else {
                    $schemaId = null;
                }
            }

            $object->getOrganisation();
            // Track organization for future use.
            // Clear individual object from cache.
            $this->clearObjectFromCache($object);

            // **SOLR INTEGRATION**: Index or remove from SOLR based on operation.
            if ($operation === 'create' || $operation === 'update') {
                // Index the object in SOLR with immediate commit for instant visibility.
                $this->indexObjectInSolr(object: $object, commit: true);

                // Update name cache for the modified object.
                $name = $object->getName() ?? $object->getUuid();
                $this->setObjectName(identifier: $object->getUuid(), name: $name);
                if (($object->getId() !== null) === true && (string) $object->getId() !== $object->getUuid()) {
                    $this->setObjectName(identifier: $object->getId(), name: $name);
                }
            } else if ($operation === 'delete') {
                // Remove from SOLR index with immediate commit for instant visibility.
                $this->removeObjectFromSolr(object: $object, commit: true);

                // Remove from name cache.
                unset($this->nameCache[$object->getUuid()]);
                unset($this->nameCache[(string) $object->getId()]);
            }
        }//end if

        // **SCHEMA-WIDE INVALIDATION**: Clear ALL search caches for this schema.
        // This ensures colleagues see each other's changes immediately.
        // schemaId and registerId are already typed as ?int, so no conversion needed.
        $schemaIdInt   = $schemaId;
        $registerIdInt = $registerId;

        $this->clearSchemaRelatedCaches(schemaId: $schemaIdInt, registerId: $registerIdInt, operation: $operation);

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
        // Remove by ID. Ensure ID is string for array key.
        $objectId = $object->getId();
        if (is_string($objectId) === true) {
            $objectIdKey = $objectId;
        } else {
            $objectIdKey = (string) $objectId;
        }

        unset($this->objectCache[$objectIdKey]);

        // Remove by UUID if available.
        if (($object->getUuid() !== null) === true) {
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
     * Generate cache key for search queries
     *
     * Creates a unique cache key based on search parameters, user context,
     * and authorization settings to ensure proper cache isolation.
     *
     * @param array       $query                  Search query parameters
     * @param string|null $activeOrganisationUuid Active organization UUID
     * @param bool        $_rbac                  Whether RBAC is enabled
     * @param bool        $_multitenancy          Whether multi-tenancy is enabled
     *
     * @return string The generated cache key
     */
    private function generateSearchCacheKey(array $query, ?string $activeOrganisationUuid, bool $_rbac, bool $_multitenancy): string
    {
        $user = $this->userSession->getUser();
        if ($user === true) {
            $userId = $user->getUID();
        } else {
            $userId = 'anonymous';
        }

        // Convert booleans to strings for cache key.
        if ($_rbac === true) {
            $rbacStr = 'true';
        } else {
            $rbacStr = 'false';
        }

        if ($_multitenancy === true) {
            $multiStr = 'true';
        } else {
            $multiStr = 'false';
        }

        // Create consistent key components.
        $keyComponents = [
            'user'  => $userId,
            'org'   => $activeOrganisationUuid ?? 'null',
            'rbac'  => $rbacStr,
            'multi' => $multiStr,
            'query' => $query,
        ];

        // Sort query parameters for consistent key generation.
        if (($keyComponents['query'] ?? null) !== null && is_array($keyComponents['query']) === true) {
            ksort($keyComponents['query']);
            array_walk_recursive(
                    $keyComponents['query'],
                    function (&$value) {
                        if (is_array($value) === true) {
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

        $this->objectCache = [];
        /*
         * @psalm-suppress UndefinedThisPropertyAssignment - relationshipCache property doesn't exist, not used)
         */
        $this->relationshipCache  = [];
        $this->inMemoryQueryCache = [];
        $this->nameCache          = [];
        $this->stats = ['hits' => 0, 'misses' => 0, 'preloads' => 0, 'query_hits' => 0, 'query_misses' => 0, 'name_hits' => 0, 'name_misses' => 0, 'name_warmups' => 0];

        // Clear distributed query cache.
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

        // Clear distributed name cache.
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


    // ========================================.
    // OBJECT NAME CACHE METHODS.
    // ========================================.


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

        // Enforce maximum cache TTL.
        $ttl = min($ttl, self::MAX_CACHE_TTL);

        // Store in in-memory cache.
        $this->nameCache[$key] = $name;

        // Store in distributed cache if available.
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

        // Check in-memory cache first (fastest).
        if (($this->nameCache[$key] ?? null) !== null) {
            $this->stats['name_hits']++;
            $this->logger->debug(message: '🚀 NAME CACHE HIT (in-memory)', context: ['identifier' => $key]);
            return $this->nameCache[$key];
        }

        // Check distributed cache.
        if ($this->nameDistributedCache !== null) {
            try {
                $cachedName = $this->nameDistributedCache->get('name_'.$key);
                if ($cachedName !== null) {
                    // Store in in-memory cache for faster future access.
                    $this->nameCache[$key] = $cachedName;
                    $this->stats['name_hits']++;
                    $this->logger->debug(message: '⚡ NAME CACHE HIT (distributed)', context: ['identifier' => $key]);
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

        // Cache miss - load from database.
        $this->stats['name_misses']++;
        $this->logger->debug(message: '❌ NAME CACHE MISS', context: ['identifier' => $key]);

        try {
            // STEP 1: Try to find as organisation first (they take priority).
            try {
                $organisation = $this->organisationMapper->findByUuid((string) $identifier);
                if ($organisation !== null) {
                    $name = $organisation->getName() ?? $organisation->getUuid();
                    $this->setObjectName(identifier: $identifier, name: $name);
                    return $name;
                }
            } catch (\Exception $e) {
                // Organisation not found, continue to objects.
            }

            // STEP 2: Try to find as object.
            $object = $this->objectEntityMapper->find($identifier);
            if ($object !== null) {
                $name = $object->getName() ?? $object->getUuid();
                $this->setObjectName(identifier: $identifier, name: $name);
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
        if (empty($identifiers) === true) {
            return [];
        }

        $results            = [];
        $missingIdentifiers = [];

        // Check in-memory cache for all identifiers.
        foreach ($identifiers as $identifier) {
            $key = (string) $identifier;
            if (($this->nameCache[$key] ?? null) !== null) {
                $results[$key] = $this->nameCache[$key];
                $this->stats['name_hits']++;
            } else {
                $missingIdentifiers[] = $key;
            }
        }

        // Check distributed cache for missing identifiers.
        if (empty($missingIdentifiers) === false && $this->nameDistributedCache !== null) {
            $distributedResults = [];
            foreach ($missingIdentifiers as $key) {
                try {
                    $cachedName = $this->nameDistributedCache->get('name_'.$key);
                    if ($cachedName !== null) {
                        $distributedResults[$key] = $cachedName;
                        $this->nameCache[$key]    = $cachedName;
                        // Store in memory.
                        $this->stats['name_hits']++;
                    }
                } catch (\Exception $e) {
                    // Continue processing other identifiers.
                }
            }

            $results            = array_merge($results, $distributedResults);
            $missingIdentifiers = array_diff($missingIdentifiers, array_keys($distributedResults));
        }

        // Load remaining missing names from database.
        if (empty($missingIdentifiers) === false) {
            $this->stats['name_misses'] += count($missingIdentifiers);

            try {
                // STEP 1: Try to find organisations first (they take priority).
                $organisations = $this->organisationMapper->findMultipleByUuid($missingIdentifiers);
                foreach ($organisations as $organisation) {
                    $name          = $organisation->getName() ?? $organisation->getUuid();
                    $key           = $organisation->getUuid();
                    $results[$key] = $name;

                    // Cache for future use (UUID only).
                    $this->setObjectName(identifier: $key, name: $name);

                    // Remove from missing list since we found it.
                    $missingIdentifiers = array_diff($missingIdentifiers, [$key]);
                }

                // STEP 2: Try to find remaining identifiers as objects.
                if (empty($missingIdentifiers) === false) {
                    $objects = $this->objectEntityMapper->findMultiple($missingIdentifiers);
                    foreach ($objects as $object) {
                        $name          = $object->getName() ?? $object->getUuid();
                        $key           = $object->getUuid();
                        $results[$key] = $name;

                        // Cache for future use (UUID only).
                        $this->setObjectName(identifier: $key, name: $name);
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

        // Filter to return only UUID -> name mappings (exclude database IDs).
        $uuidResults = array_filter(
                $results,
                function ($key) {
                    // Only return entries where key looks like a UUID (contains hyphens).
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

        // Filter to return only UUID -> name mappings (exclude database IDs).
        $uuidNames = array_filter(
                $this->nameCache,
                function ($key) {
                    // Only return entries where key looks like a UUID (contains hyphens).
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
     *
     * @psalm-return int<0, max>
     */
    public function warmupNameCache(): int
    {
        $startTime = microtime(true);
        $this->stats['name_warmups']++;

        try {
            $loadedCount = 0;

            // STEP 1: Load all organisations first (they take priority).
            $organisations = $this->organisationMapper->findAllWithUserCount();
            foreach ($organisations as $organisation) {
                $name = $organisation->getName() ?? $organisation->getUuid();

                // Cache by UUID only (not by database ID).
                if ($organisation->getUuid() !== null) {
                    $this->nameCache[$organisation->getUuid()] = $name;
                    $loadedCount++;
                }
            }

            // STEP 2: Load all objects (organisations will overwrite if same UUID).
            $objects = $this->objectEntityMapper->findAll();
            foreach ($objects as $object) {
                $name = $object->getName() ?? $object->getUuid();

                // Cache by UUID only (not by database ID).
                // Note: If an organisation has the same UUID, it will remain (organisations loaded first).
                if ($object->getUuid() !== null && (($this->nameCache[$object->getUuid()] ?? null) === null) === true) {
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
        // Clear in-memory name cache.
        $this->nameCache = [];

        // Clear distributed name cache.
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

        $this->logger->debug(message: '🧹 OBJECT NAME CACHE CLEARED');

    }//end clearNameCache()


    // ========================================.
    // SOLR BULK OPERATIONS.
    // ========================================.


    /**
     * Get comprehensive SOLR dashboard statistics
     *
     * @return array Dashboard statistics from SolrService
     */
    public function getSolrDashboardStats(): array
    {
        $solrService = $this->getSolrService();
        if ($solrService === null) {
            throw new RuntimeException('SOLR service is not available');
        }

        return $solrService->getDashboardStats();

    }//end getSolrDashboardStats()


    /**
     * Commit SOLR index
     *
     * @return (bool|string)[] Commit operation results
     *
     * @psalm-return array{success: bool, error?: string, timestamp?: string, message?: 'Commit failed'|'Commit successful'}
     */
    public function commitSolr(): array
    {
        $solrService = $this->getSolrService();
        if ($solrService === null) {
            return ['success' => false, 'error' => 'SOLR service is not available'];
        }

        try {
            $result = $solrService->commit();
            // Determine message based on result.
            if ($result === true) {
                $message = 'Commit successful';
            } else {
                $message = 'Commit failed';
            }

            return [
                'success'   => $result,
                'timestamp' => date('c'),
                'message'   => $message,
            ];
        } catch (\Exception $e) {
            return [
                'success'   => false,
                'error'     => $e->getMessage(),
                'timestamp' => date('c'),
            ];
        }//end try

    }//end commitSolr()


    /**
     * Optimize SOLR index
     *
     * @return (bool|string)[] Optimize operation results
     *
     * @psalm-return array{success: bool, error?: string, timestamp?: string, message?: 'Optimization failed'|'Optimization successful'}
     */
    public function optimizeSolr(): array
    {
        $solrService = $this->getSolrService();
        if ($solrService === null) {
            return ['success' => false, 'error' => 'SOLR service is not available'];
        }

        try {
            $result = $solrService->optimize();
            // Determine message based on result.
            if ($result === true) {
                $message = 'Optimization successful';
            } else {
                $message = 'Optimization failed';
            }

            return [
                'success'   => $result,
                'timestamp' => date('c'),
                'message'   => $message,
            ];
        } catch (\Exception $e) {
            return [
                'success'   => false,
                'error'     => $e->getMessage(),
                'timestamp' => date('c'),
            ];
        }//end try

    }//end optimizeSolr()


    /**
     * Clear SOLR index completely for dashboard
     *
     * @return (false|mixed|null|string)[] Clear operation results
     *
     * @psalm-return array{success: false|mixed, error: mixed|null|string, timestamp?: string, error_details?: mixed|null, message?: 'Index clear failed'|'Index cleared successfully'}
     */
    public function clearSolrIndexForDashboard(): array
    {
        $solrService = $this->getSolrService();
        if ($solrService === null) {
            return ['success' => false, 'error' => 'SOLR service is not available'];
        }

        try {
            $result = $solrService->clearIndex();
            // Determine message based on result.
            if (($result['success'] === true) === true) {
                $message = 'Index cleared successfully';
            } else {
                $message = 'Index clear failed';
            }

            return [
                'success'       => $result['success'],
                'error'         => $result['error'] ?? null,
                'error_details' => $result['error_details'] ?? null,
                'timestamp'     => date('c'),
                'message'       => $message,
            ];
        } catch (\Exception $e) {
            return [
                'success'   => false,
                'error'     => $e->getMessage(),
                'timestamp' => date('c'),
            ];
        }//end try

    }//end clearSolrIndexForDashboard()


}//end class
