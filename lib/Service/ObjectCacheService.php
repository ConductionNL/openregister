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
     * @var int
     */
    private int $maxCacheSize = 1000;

    /**
     * Cache hit statistics
     *
     * @var array{hits: int, misses: int, preloads: int}
     */
    private array $stats = ['hits' => 0, 'misses' => 0, 'preloads' => 0];


    /**
     * Constructor for ObjectCacheService
     *
     * @param ObjectEntityMapper $objectEntityMapper The object entity mapper
     * @param LoggerInterface    $logger             Logger for performance monitoring
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()


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
     * @phpstan-param array<int|string> $identifiers
     * @phpstan-return array<ObjectEntity>
     * @psalm-param array<int|string> $identifiers
     * @psalm-return array<ObjectEntity>
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
            $this->logger->error('Bulk preload failed in ObjectCacheService', [
                'exception' => $e->getMessage(),
                'identifiersToLoad' => count($identifiersToLoad)
            ]);
            return [];
        }

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
            $entriesToRemove = (int) ($this->maxCacheSize * 0.2);
            $this->objectCache = array_slice($this->objectCache, $entriesToRemove, null, true);
        }

        // Cache with string representation
        $this->objectCache[(string)$object] = $object;

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
     * @phpstan-param array<ObjectEntity> $objects
     * @phpstan-param array<string> $extend
     * @phpstan-return array<ObjectEntity>
     * @psalm-param array<ObjectEntity> $objects
     * @psalm-param array<string> $extend
     * @psalm-return array<ObjectEntity>
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
                } elseif (is_string($value) || is_int($value)) {
                    $allRelationshipIds[] = (string) $value;
                }
            }
        }

        // Preload all relationship targets
        return $this->preloadObjects($allRelationshipIds);

    }//end preloadRelationships()


    /**
     * Get cache statistics
     *
     * Returns information about cache performance for monitoring and optimization.
     *
     * @return array{hits: int, misses: int, preloads: int, hit_rate: float, cache_size: int}
     *
     * @phpstan-return array{hits: int, misses: int, preloads: int, hit_rate: float, cache_size: int}
     * @psalm-return   array{hits: int, misses: int, preloads: int, hit_rate: float, cache_size: int}
     */
    public function getStats(): array
    {
        $totalRequests = $this->stats['hits'] + $this->stats['misses'];
        $hitRate       = $totalRequests > 0 ? ($this->stats['hits'] / $totalRequests) * 100 : 0;

        return array_merge(
                $this->stats,
                [
                    'hit_rate'   => round($hitRate, 2),
                    'cache_size' => count($this->objectCache),
                ]
                );

    }//end getStats()


    /**
     * Clear the cache
     *
     * Removes all cached objects and resets statistics.
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->objectCache      = [];
        $this->relationshipCache = [];
        $this->stats            = ['hits' => 0, 'misses' => 0, 'preloads' => 0];

    }//end clearCache()


}//end class
