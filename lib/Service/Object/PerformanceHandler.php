<?php

/**
 * PerformanceHandler - Performance Optimization and Monitoring Handler
 *
 * Handles performance optimization, caching strategies, and performance monitoring.
 * This handler consolidates performance-related operations from ObjectService,
 * improving code organization and making optimization logic more maintainable.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\CacheHandler;
use Psr\Log\LoggerInterface;

/**
 * PerformanceHandler class
 *
 * Handles performance operations including:
 * - Request optimization and fast-path detection
 * - Extend query optimization
 * - Entity caching and preloading
 * - Related data extraction
 * - Performance calculations and monitoring
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects
 */
class PerformanceHandler
{
    /**
     * PerformanceHandler constructor.
     *
     * @param CacheHandler    $objectCacheService Object cache service for caching.
     * @param LoggerInterface $logger             Logger for performance monitoring.
     */
    public function __construct(
        private readonly CacheHandler $objectCacheService,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Optimize request for performance
     *
     * Analyzes the query and applies various optimizations:
     * - Detects simple requests for fast-path processing
     * - Limits destructive extend operations
     * - Preloads critical entities for cache warmup
     *
     * @param array<string, mixed> $query       The search query (passed by reference).
     * @param array<string, mixed> $perfTimings Performance timing array (passed by reference).
     *
     * @return void
     */
    public function optimizeRequestForPerformance(array &$query, array &$perfTimings): void
    {
        $optimizeStart = microtime(true);

        // **OPTIMIZATION 1**: Fast path for simple requests.
        $isSimpleRequest = $this->isSimpleRequest($query);
        if ($isSimpleRequest === true) {
            $query['_fast_path'] = true;
            $this->logger->debug(
                message: '🚀 FAST PATH: Simple request detected',
                context: [
                    'benefit'         => 'skip_heavy_processing',
                    'estimatedSaving' => '200-300ms',
                ]
            );
        }

        // **OPTIMIZATION 2**: Limit destructive extend operations.
        if (empty($query['_extend']) === false) {
            // **BUGFIX**: Handle _extend as both string and array for count.
            $originalExtendCount = count(array_filter(array_map('trim', explode(',', $query['_extend']))));
            if (is_array($query['_extend']) === true) {
                $originalExtendCount = count($query['_extend']);
            }

            $query['_extend'] = $this->optimizeExtendQueries($query['_extend']);

            // OptimizeExtendQueries always returns an array, so no need to check.
            $newExtendCount = count($query['_extend']);

            if ($newExtendCount < $originalExtendCount) {
                $this->logger->info(
                    message: '⚡ EXTEND OPTIMIZATION: Reduced extend complexity',
                    context: [
                        'original'        => $originalExtendCount,
                        'optimized'       => $newExtendCount,
                        'estimatedSaving' => ($originalExtendCount - $newExtendCount) * (100).'ms',
                    ]
                );
            }
        }//end if

        // **OPTIMIZATION 3**: Preload critical entities for cache warmup.
        $this->preloadCriticalEntities($query);

        $perfTimings['request_optimization'] = round((microtime(true) - $optimizeStart) * 1000, 2);
    }//end optimizeRequestForPerformance()

    /**
     * Determine if this is a simple request that can use the fast path
     *
     * Simple requests have:
     * - No complex extend operations (≤ 2)
     * - No facets or facetable queries
     * - Small result set (limit ≤ 50)
     * - Few filter criteria (< 3)
     *
     * @param array<string, mixed> $query The search query.
     *
     * @return bool True if this is a simple request
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple criteria for determining request simplicity
     */
    public function isSimpleRequest(array $query): bool
    {
        // **BUGFIX**: Handle _extend as both string and array.
        $extendCount = 0;
        if (empty($query['_extend']) === false) {
            if (is_array($query['_extend']) === true) {
                $extendCount = count($query['_extend']);
            } else if (is_string($query['_extend']) === true) {
                // Count comma-separated extend fields.
                $extendCount = count(array_filter(array_map('trim', explode(',', $query['_extend']))));
            }
        }

        $hasComplexExtend = $extendCount > 2;
        $hasFacets        = empty($query['_facets']) === false || ($query['_facetable'] ?? false);
        $hasLargeLimit    = ($query['_limit'] ?? 20) > 50;

        // Count filter criteria (excluding system parameters).
        $filterCount = 0;
        foreach (array_keys($query) as $key) {
            $startsWithUnderscore = str_starts_with(haystack: $key, needle: '_') === true;
            $startsWithAt         = str_starts_with(haystack: $key, needle: '@') === true;
            if ($startsWithUnderscore === false && $startsWithAt === false) {
                $filterCount++;
            }
        }

        $hasComplexFilters = $filterCount > 3;

        return !($hasComplexExtend || $hasFacets || $hasLargeLimit || $hasComplexFilters);
    }//end isSimpleRequest()

    /**
     * Optimize extend queries for performance
     *
     * Analyzes and optimizes extend field requests to reduce unnecessary data loading.
     *
     * @param array<string>|string $extend Original extend data (array or comma-separated string).
     *
     * @return array<string> Optimized extend array
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function optimizeExtendQueries(array | string $extend): array
    {
        // **BUGFIX**: Handle _extend as both string and array.
        if (is_string($extend) === true) {
            if (trim($extend) === '') {
                return [];
            }

            // Convert comma-separated string to array.
            return array_filter(array_map('trim', explode(',', $extend)));
        }

        // For now, just return the array (future optimization: analyze and reduce).
        // Future improvements:
        // - Remove circular extends.
        // - Limit extend depth.
        // - Remove duplicate extends.
        return $extend;
    }//end optimizeExtendQueries()

    /**
     * Preload critical entities for cache warmup
     *
     * Preloads schemas and other critical entities to warm up the cache
     * before the main query executes, reducing database round-trips.
     *
     * @param array<string, mixed> $query The search query.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function preloadCriticalEntities(array $query): void
    {
        // Preloading is currently disabled - this is a placeholder for future cache warmup logic.
        // Future improvements:
        // - Preload schemas based on query.
        // - Preload registers.
        // - Warm up object cache for frequently accessed objects.
        return;
    }//end preloadCriticalEntities()

    /**
     * Extract related data from search results
     *
     * Extracts UUIDs of related objects from search results and optionally
     * fetches their names for display purposes.
     *
     * @param array<ObjectEntity> $results             Array of search results.
     * @param bool                $includeRelated      Whether to include related object IDs.
     * @param bool                $includeRelatedNames Whether to include related object names.
     *
     * @return string[][] Related data array
     *
     * @psalm-return array{related?: list<string>, relatedNames?: array<string, string>}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Nested extraction logic with multiple type checks
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  Boolean flags control optional extraction features
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple paths for different data types
     */
    public function extractRelatedData(array $results, bool $includeRelated, bool $includeRelatedNames): array
    {
        $startTime   = microtime(true);
        $relatedData = [];

        if (empty($results) === true) {
            return $relatedData;
        }

        $allRelatedIds = [];

        // Extract all related IDs from result objects.
        foreach ($results as $result) {
            if (($result instanceof ObjectEntity) === false) {
                continue;
            }

            $objectData = $result->getObject();

            // Look for relationship fields in the object data.
            foreach ($objectData ?? [] as $value) {
                if (is_array($value) === true) {
                    // Handle array of IDs.
                    foreach ($value as $relatedId) {
                        if (is_string($relatedId) === true && $this->isUuid($relatedId) === true) {
                            $allRelatedIds[] = $relatedId;
                        }
                    }
                } else if (is_string($value) === true && $this->isUuid($value) === true) {
                    // Handle single ID.
                    $allRelatedIds[] = $value;
                }
            }
        }//end foreach

        // Remove duplicates and filter valid UUIDs.
        $allRelatedIds = array_unique($allRelatedIds);

        if ($includeRelated === true) {
            $relatedData['related'] = array_values($allRelatedIds);
        }

        if ($includeRelatedNames === true && empty($allRelatedIds) === false) {
            // Get names for all related objects using the object cache service.
            $relatedNames = $this->objectCacheService->getMultipleObjectNames($allRelatedIds);
            $relatedData['relatedNames'] = $relatedNames;
        }

        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        $this->logger->debug(
            message: '🔗 RELATED DATA EXTRACTED',
            context: [
                'related_ids_found'     => count($allRelatedIds),
                'include_related'       => $includeRelated,
                'include_related_names' => $includeRelatedNames,
                'execution_time'        => $executionTime.'ms',
            ]
        );

        return $relatedData;
    }//end extractRelatedData()

    /**
     * Check if a value is a UUID string
     *
     * @param mixed $value The value to check.
     *
     * @return bool True if the value is a UUID string
     */
    private function isUuid(mixed $value): bool
    {
        if (is_string($value) === false) {
            return false;
        }

        // Standard UUID with dashes (8-4-4-4-12 format).
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1) {
            return true;
        }

        // UUID without dashes (32 hex chars).
        if (preg_match('/^[0-9a-f]{32}$/i', $value) === 1) {
            return true;
        }

        // Prefixed UUID (e.g., "id-uuid" with or without dashes).
        if (preg_match('/^[a-z]+-([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32})$/i', $value) === 1) {
            return true;
        }

        return false;
    }//end isUuid()

    /**
     * Get cached entities or use fallback function
     *
     * Attempts to retrieve entities from cache, falling back to the provided function if not cached.
     *
     * @param mixed    $ids          Entity ID(s) to retrieve (int, array, or 'all').
     * @param callable $fallbackFunc Function to call if not in cache.
     *
     * @return array<mixed> Array of entities
     */
    public function getCachedEntities(mixed $ids, callable $fallbackFunc): array
    {
        // Entity caching is disabled - always use fallback function.
        return call_user_func($fallbackFunc, $ids);
    }//end getCachedEntities()

    /**
     * Get facet count from query parameters
     *
     * @param bool                 $hasFacets Whether facets are requested.
     * @param array<string, mixed> $query     The search query.
     *
     * @return int Number of facets requested
     *
     * @psalm-return int<0, max>
     */
    public function getFacetCount(bool $hasFacets, array $query): int
    {
        if ($hasFacets === true) {
            $facets = $query['_facets'] ?? [];
            // Handle string value (e.g., _facets=extend).
            if (is_array($facets) === true) {
                return count($facets);
            }

            return 1;
        }

        return 0;
    }//end getFacetCount()

    /**
     * Calculate total pages for pagination
     *
     * @param int $total Total items.
     * @param int $limit Items per page.
     *
     * @return int Total pages
     */
    public function calculateTotalPages(int $total, int $limit): int
    {
        if ($total > 0) {
            return intval(ceil($total / $limit));
        }

        return 1;
    }//end calculateTotalPages()

    /**
     * Calculate extend count from extend parameter
     *
     * Handles both array and comma-separated string formats.
     *
     * @param mixed $extend Extend parameter (array or string).
     *
     * @return int Extend count
     *
     * @psalm-return int<0, max>
     */
    public function calculateExtendCount(mixed $extend): int
    {
        if (is_array($extend) === true) {
            return count($extend);
        }

        if (is_string($extend) === true) {
            if (trim($extend) === '') {
                return 0;
            }

            return count(array_filter(array_map('trim', explode(',', $extend))));
        }

        return 0;
    }//end calculateExtendCount()
}//end class
