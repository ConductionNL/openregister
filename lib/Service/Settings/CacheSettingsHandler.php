<?php

/**
 * OpenRegister Cache Settings Handler
 *
 * This file contains the handler class for managing cache operations.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service\Settings;

use DateTime;
use Exception;
use RuntimeException;
use InvalidArgumentException;
use OCP\ICacheFactory;
use OCP\AppFramework\IAppContainer;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;

/**
 * Handler for cache settings and operations.
 *
 * This handler is responsible for managing cache statistics, clearing,
 * and warmup operations across different cache types.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */
class CacheSettingsHandler
{

    /**
     * Cache factory
     *
     * @var ICacheFactory
     */
    private ICacheFactory $cacheFactory;

    /**
     * Schema cache handler
     *
     * @var SchemaCacheHandler
     */
    private SchemaCacheHandler $schemaCacheService;

    /**
     * Schema facet cache service
     *
     * @var FacetCacheHandler
     */
    private FacetCacheHandler $schemaFacetCacheService;

    /**
     * Object cache service (lazy-loaded when needed)
     *
     * @var CacheHandler|null
     */
    private ?CacheHandler $objectCacheService = null;

    /**
     * Container for lazy loading services
     *
     * @var IAppContainer|null
     */
    private ?IAppContainer $container = null;

    /**
     * Constructor for CacheSettingsHandler
     *
     * @param ICacheFactory      $cacheFactory            Cache factory.
     * @param SchemaCacheHandler $schemaCacheService      Schema cache handler.
     * @param FacetCacheHandler  $schemaFacetCacheService Schema facet cache service.
     * @param CacheHandler|null  $objectCacheService      Object cache service (optional, lazy-loaded).
     * @param IAppContainer|null $container               Container for lazy loading (optional).
     *
     * @return void
     */
    public function __construct(
        ICacheFactory $cacheFactory,
        SchemaCacheHandler $schemaCacheService,
        FacetCacheHandler $schemaFacetCacheService,
        ?CacheHandler $objectCacheService=null,
        ?IAppContainer $container=null
    ) {
        $this->cacheFactory            = $cacheFactory;
        $this->schemaCacheService      = $schemaCacheService;
        $this->schemaFacetCacheService = $schemaFacetCacheService;
        $this->objectCacheService      = $objectCacheService;
        $this->container = $container;
    }//end __construct()

    /**
     * Get comprehensive cache statistics from actual cache systems(not database)
     *
     * Provides detailed insights into cache usage and performance by querying
     * the actual cache backends rather than database tables for better performance.
     *
     * @return (((int|mixed)[]|bool|float|int|mixed|string)[]|string)[] Comprehensive cache statistics from cache systems
     *
     * @throws \RuntimeException If cache statistics retrieval fails
     *
     * @psalm-return array{overview: array{totalCacheSize: 0|mixed,
     *     totalCacheEntries: 0|mixed, overallHitRate: float,
     *     averageResponseTime: float, cacheEfficiency: float},
     *     services: array{object: array{entries: 0|mixed, hits: 0|mixed,
     *     requests: 0|mixed, memoryUsage: 0|mixed},
     *     schema: array{entries: 0, hits: 0, requests: 0, memoryUsage: 0},
     *     facet: array{entries: 0, hits: 0, requests: 0, memoryUsage: 0}},
     *     names: array{cache_size: 0|mixed, hit_rate: float|mixed,
     *     hits: 0|mixed, misses: 0|mixed, warmups: 0|mixed, enabled: bool},
     *     distributed: array{type: 'distributed'|'none', backend: string,
     *     available: bool, error?: string, keyCount?: 'Unknown',
     *     size?: 'Unknown'}, performance: array{averageHitTime: 0|float,
     *     averageMissTime: 0|float, performanceGain: 0|float,
     *     optimalHitRate: float, currentTrend?: 'improving'},
     *     lastUpdated: string, error?: string}
     */
    public function getCacheStats(): array
    {
        try {
            // Get basic distributed cache info.
            $distributedStats = $this->getDistributedCacheStats();
            $performanceStats = $this->getCachePerformanceMetrics();

            // Get object cache stats (only if CacheHandler provides them)
            // Use cached stats to avoid expensive operations on every request.
            $objectStats = $this->getCachedObjectStats();

            $stats = [
                'overview'    => [
                    'totalCacheSize'      => $objectStats['memoryUsage'] ?? 0,
                    'totalCacheEntries'   => $objectStats['entries'] ?? 0,
                    'overallHitRate'      => $this->calculateHitRate($objectStats),
                    'averageResponseTime' => $performanceStats['averageHitTime'] ?? 0.0,
                    'cacheEfficiency'     => $this->calculateHitRate($objectStats),
                ],
                'services'    => [
                    'object' => [
                        'entries'     => $objectStats['entries'] ?? 0,
                        'hits'        => $objectStats['hits'] ?? 0,
                        'requests'    => $objectStats['requests'] ?? 0,
                        'memoryUsage' => $objectStats['memoryUsage'] ?? 0,
                    ],
                    'schema' => [
                        'entries'     => 0,
                    // Not stored in database - would be performance issue.
                        'hits'        => 0,
                        'requests'    => 0,
                        'memoryUsage' => 0,
                    ],
                    'facet'  => [
                        'entries'     => 0,
                    // Not stored in database - would be performance issue.
                        'hits'        => 0,
                        'requests'    => 0,
                        'memoryUsage' => 0,
                    ],
                ],
                'names'       => [
                    'cache_size' => $objectStats['name_cache_size'] ?? 0,
                    'hit_rate'   => $objectStats['name_hit_rate'] ?? 0.0,
                    'hits'       => $objectStats['name_hits'] ?? 0,
                    'misses'     => $objectStats['name_misses'] ?? 0,
                    'warmups'    => $objectStats['name_warmups'] ?? 0,
                    'enabled'    => true,
                ],
                'distributed' => $distributedStats,
                'performance' => $performanceStats,
                'lastUpdated' => (new DateTime())->format('c'),
            ];

            return $stats;
        } catch (Exception $e) {
            // Return safe defaults if cache stats unavailable.
            return [
                'overview'    => [
                    'totalCacheSize'      => 0,
                    'totalCacheEntries'   => 0,
                    'overallHitRate'      => 0.0,
                    'averageResponseTime' => 0.0,
                    'cacheEfficiency'     => 0.0,
                ],
                'services'    => [
                    'object' => ['entries' => 0, 'hits' => 0, 'requests' => 0, 'memoryUsage' => 0],
                    'schema' => ['entries' => 0, 'hits' => 0, 'requests' => 0, 'memoryUsage' => 0],
                    'facet'  => ['entries' => 0, 'hits' => 0, 'requests' => 0, 'memoryUsage' => 0],
                ],
                'names'       => [
                    'cache_size' => 0,
                    'hit_rate'   => 0.0,
                    'hits'       => 0,
                    'misses'     => 0,
                    'warmups'    => 0,
                    'enabled'    => false,
                ],
                'distributed' => ['type' => 'none', 'backend' => 'Unknown', 'available' => false],
                'performance' => ['averageHitTime' => 0, 'averageMissTime' => 0, 'performanceGain' => 0, 'optimalHitRate' => 85.0],
                'lastUpdated' => (new DateTime())->format('c'),
                'error'       => 'Cache statistics unavailable: '.$e->getMessage(),
            ];
        }//end try
    }//end getCacheStats()

    /**
     * Get cached object statistics to avoid expensive operations on every request
     *
     * @return array Object cache statistics
     */
    private function getCachedObjectStats(): array
    {
        // Use a simple in-memory cache with 30-second TTL to avoid expensive CacheHandler calls.
        static $cachedStats = null;
        static $lastUpdate  = 0;

        $now = time();
        if ($cachedStats === null || ($now - $lastUpdate) > 30) {
            try {
                $objectCacheService = $this->objectCacheService;
                if ($objectCacheService === null && $this->container !== null) {
                    try {
                        $objectCacheService = $this->container->get(CacheHandler::class);
                    } catch (Exception $e) {
                        throw new Exception('CacheHandler not available');
                    }
                }

                if ($objectCacheService === null) {
                    throw new Exception('CacheHandler not available');
                }

                $cachedStats = $objectCacheService->getStats();
            } catch (Exception $e) {
                // If no object cache stats available, use defaults.
                $cachedStats = [
                    'entries'         => 0,
                    'hits'            => 0,
                    'requests'        => 0,
                    'memoryUsage'     => 0,
                    'name_cache_size' => 0,
                    'name_hit_rate'   => 0.0,
                    'name_hits'       => 0,
                    'name_misses'     => 0,
                    'name_warmups'    => 0,
                ];
            }//end try

            $lastUpdate = $now;
        }//end if

        return $cachedStats;
    }//end getCachedObjectStats()

     /**
      * Calculate hit rate from cache statistics
      *
      * @param array $stats Cache statistics array
      *
      * @return float Hit rate percentage
      */
    private function calculateHitRate(array $stats): float
    {
        $requests = $stats['requests'] ?? 0;
        $hits     = $stats['hits'] ?? 0;

        if ($requests > 0) {
            return ($hits / $requests) * 100;
        } else {
            return 0.0;
        }
    }//end calculateHitRate()

     /**
      * Get distributed cache statistics from Nextcloud's cache factory
      *
      * @return (bool|string)[] Distributed cache statistics
      *
      * @psalm-return array{type: 'distributed'|'none', backend: string, available: bool, error?: string, keyCount?: 'Unknown', size?: 'Unknown'}
      */
    private function getDistributedCacheStats(): array
    {
        try {
            $distributedCache = $this->cacheFactory->createDistributed('openregister');

            return [
                'type'      => 'distributed',
                'backend'   => get_class($distributedCache),
                'available' => true,
                'keyCount'  => 'Unknown',
            // Most cache backends don't provide this.
                'size'      => 'Unknown',
            ];
        } catch (Exception $e) {
            return [
                'type'      => 'none',
                'backend'   => 'fallback',
                'available' => false,
                'error'     => $e->getMessage(),
            ];
        }
    }//end getDistributedCacheStats()

     /**
      * Get cache performance metrics for the last period
      *
      * @return (float|string)[]
      *
      * @psalm-return array{averageHitTime: float, averageMissTime: float, performanceGain: float, optimalHitRate: float, currentTrend: 'improving'}
      */
    private function getCachePerformanceMetrics(): array
    {
        // This would typically come from a performance monitoring service
        // For now, return basic metrics.
        return [
            'averageHitTime'  => 2.5,
        // Ms.
            'averageMissTime' => 850.0,
        // Ms.
            'performanceGain' => 340.0,
        // Factor improvement with cache.
            'optimalHitRate'  => 85.0,
        // Target hit rate percentage.
            'currentTrend'    => 'improving',
        ];
    }//end getCachePerformanceMetrics()

     /**
      * Clear cache with granular control
      *
      * @param string      $type     Cache type: 'all', 'object', 'schema', 'facet', 'distributed', 'names'
      * @param string|null $userId   Specific user ID to clear cache for (if supported)
      * @param array       $_options Additional options for cache clearing
      *
      * @return (((float|int[]|mixed|string)[]|bool|int|mixed|string)[][]|int|mixed|null|string)[]
      *
      * @throws \RuntimeException If cache clearing fails
      *
      * @psalm-return     array{type: string, userId: null|string,
      *     timestamp: string, results: array{names?: array{service: 'names',
      *     cleared: 0|mixed, success: bool, error?: string,
      *     before?: array{name_cache_size: int|mixed, name_hits: int|mixed,
      *     name_misses: int|mixed}, after?: array{name_cache_size: int|mixed,
      *     name_hits: int|mixed, name_misses: int|mixed}},
      *     distributed?: array{service: 'distributed', cleared: 'all'|0,
      *     success: bool, error?: string},
      *     facet?: array{service: 'facet', cleared: int, success: bool,
      *     error?: string, before?: array{total_entries: int, by_type: array<int>,
      *     memory_cache_size: int<0, max>,
      *     cache_table: 'openregister_schema_facet_cache', query_time: string,
      *     timestamp: int<1, max>}, after?: array{total_entries: int,
      *     by_type: array<int>, memory_cache_size: int<0, max>,
      *     cache_table: 'openregister_schema_facet_cache', query_time: string,
      *     timestamp: int<1, max>}},
      *     schema?: array{service: 'schema', cleared: 0|mixed, success: bool,
      *     error?: string, before?: array{total_entries: int,
      *     entries_with_ttl: int, memory_cache_size: int<0, max>,
      *     cache_table: 'openregister_schema_cache', query_time: string,
      *     timestamp: int<1, max>, entries?: mixed},
      *     after?: array{total_entries: int, entries_with_ttl: int,
      *     memory_cache_size: int<0, max>,
      *     cache_table: 'openregister_schema_cache', query_time: string,
      *     timestamp: int<1, max>, entries?: mixed}},
      *     object?: array{service: 'object', cleared: 0|mixed, success: bool,
      *     error?: string, before?: array{hits: int, misses: int, preloads: int,
      *     query_hits: int, query_misses: int, name_hits: int, name_misses: int,
      *     name_warmups: int, hit_rate: float, query_hit_rate: float,
      *     name_hit_rate: float, cache_size: int, query_cache_size: int,
      *     name_cache_size: int}|mixed, after?: array{hits: int, misses: int,
      *     preloads: int, query_hits: int, query_misses: int, name_hits: int,
      *     name_misses: int, name_warmups: int, hit_rate: float,
      *     query_hit_rate: float, name_hit_rate: float, cache_size: int,
      *     query_cache_size: int, name_cache_size: int}|mixed}},
      *     errors: array<never, never>, totalCleared: 0|mixed}
      * @SuppressWarnings (PHPMD.UnusedFormalParameter)
      */
    public function clearCache(string $type='all', ?string $userId=null, array $_options=[]): array
    {
        try {
            $results = [
                'type'         => $type,
                'userId'       => $userId,
                'timestamp'    => (new DateTime())->format('c'),
                'results'      => [],
                'errors'       => [],
                'totalCleared' => 0,
            ];

            switch ($type) {
                case 'all':
                    $results['results']['object']      = $this->clearObjectCache($userId);
                    $results['results']['schema']      = $this->clearSchemaCache($userId);
                    $results['results']['facet']       = $this->clearFacetCache($userId);
                    $results['results']['distributed'] = $this->clearDistributedCache($userId);
                    $results['results']['names']       = $this->clearNamesCache();
                    break;

                case 'object':
                    $results['results']['object'] = $this->clearObjectCache($userId);
                    break;

                case 'schema':
                    $results['results']['schema'] = $this->clearSchemaCache($userId);
                    break;

                case 'facet':
                    $results['results']['facet'] = $this->clearFacetCache($userId);
                    break;

                case 'distributed':
                    $results['results']['distributed'] = $this->clearDistributedCache($userId);
                    break;

                case 'names':
                    $results['results']['names'] = $this->clearNamesCache();
                    break;

                default:
                    throw new InvalidArgumentException("Invalid cache type: {$type}");
            }//end switch

            // Calculate total cleared entries.
            foreach ($results['results'] as $serviceResult) {
                $results['totalCleared'] += $serviceResult['cleared'] ?? 0;
            }

            return $results;
        } catch (Exception $e) {
            throw new RuntimeException('Failed to clear cache: '.$e->getMessage());
        }//end try
    }//end clearCache()

     /**
      * Clear object cache service
      *
      * @param string|null $_userId Specific user ID (unused, kept for API compatibility)
      *
      * @return ((float|int)[]|bool|int|mixed|string)[] Clear operation results
      *
      * @psalm-return array{service: 'object', cleared: 0|mixed, success: bool,
      *     error?: string, before?: array{hits: int, misses: int, preloads: int,
      *     query_hits: int, query_misses: int, name_hits: int, name_misses: int,
      *     name_warmups: int, hit_rate: float, query_hit_rate: float,
      *     name_hit_rate: float, cache_size: int, query_cache_size: int,
      *     name_cache_size: int}|mixed, after?: array{hits: int, misses: int,
      *     preloads: int, query_hits: int, query_misses: int, name_hits: int,
      *     name_misses: int, name_warmups: int, hit_rate: float,
      *     query_hit_rate: float, name_hit_rate: float, cache_size: int,
      *     query_cache_size: int, name_cache_size: int}|mixed}
      *
      * @SuppressWarnings(PHPMD.UnusedFormalParameter)
      */
    private function clearObjectCache(?string $_userId=null): array
    {
        try {
            $objectCacheService = $this->objectCacheService;
            if ($objectCacheService === null && $this->container !== null) {
                try {
                    $objectCacheService = $this->container->get(CacheHandler::class);
                } catch (Exception $e) {
                    throw new Exception('CacheHandler not available');
                }
            }

            if ($objectCacheService === null) {
                throw new Exception('CacheHandler not available');
            }

            $beforeStats = $objectCacheService->getStats();
            $objectCacheService->clearCache();
            $afterStats = $objectCacheService->getStats();

            return [
                'service' => 'object',
                'cleared' => $beforeStats['entries'] - $afterStats['entries'],
                'before'  => $beforeStats,
                'after'   => $afterStats,
                'success' => true,
            ];
        } catch (Exception $e) {
            return [
                'service' => 'object',
                'cleared' => 0,
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }//end try
    }//end clearObjectCache()

     /**
      * Clear object names cache specifically
      *
      * @return ((int|mixed)[]|bool|int|mixed|string)[] Clear operation results
      *
      * @psalm-return array{service: 'names', cleared: 0|mixed, success: bool,
      *     error?: string, before?: array{name_cache_size: int|mixed,
      *     name_hits: int|mixed, name_misses: int|mixed},
      *     after?: array{name_cache_size: int|mixed, name_hits: int|mixed,
      *     name_misses: int|mixed}}
      */
    private function clearNamesCache(): array
    {
        try {
            $objectCacheService = $this->objectCacheService;
            if ($objectCacheService === null && $this->container !== null) {
                try {
                    $objectCacheService = $this->container->get(CacheHandler::class);
                } catch (Exception $e) {
                    throw new Exception('CacheHandler not available');
                }
            }

            if ($objectCacheService === null) {
                throw new Exception('CacheHandler not available');
            }

            $beforeStats         = $objectCacheService->getStats();
            $beforeNameCacheSize = $beforeStats['name_cache_size'] ?? 0;

            $objectCacheService->clearNameCache();

            $afterStats         = $objectCacheService->getStats();
            $afterNameCacheSize = $afterStats['name_cache_size'] ?? 0;

            return [
                'service' => 'names',
                'cleared' => $beforeNameCacheSize - $afterNameCacheSize,
                'before'  => [
                    'name_cache_size' => $beforeNameCacheSize,
                    'name_hits'       => $beforeStats['name_hits'] ?? 0,
                    'name_misses'     => $beforeStats['name_misses'] ?? 0,
                ],
                'after'   => [
                    'name_cache_size' => $afterNameCacheSize,
                    'name_hits'       => $afterStats['name_hits'] ?? 0,
                    'name_misses'     => $afterStats['name_misses'] ?? 0,
                ],
                'success' => true,
            ];
        } catch (Exception $e) {
            return [
                'service' => 'names',
                'cleared' => 0,
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }//end try
    }//end clearNamesCache()

     /**
      * Warmup object names cache manually
      *
      * @return ((int|mixed)[]|bool|int|mixed|string)[]
      *
      * @psalm-return array{success: bool, error?: string,
      *     loaded_names: int<0, max>|mixed, execution_time?: string,
      *     before?: array{name_cache_size: int<0, max>|mixed,
      *     name_warmups: int|mixed}, after?: array{name_cache_size: int<0, max>|mixed,
      *     name_warmups: int|mixed}
      */
    public function warmupNamesCache(): array
    {
        try {
            $startTime          = microtime(true);
            $objectCacheService = $this->objectCacheService;
            if ($objectCacheService === null && $this->container !== null) {
                try {
                    $objectCacheService = $this->container->get(CacheHandler::class);
                } catch (Exception $e) {
                    throw new Exception('CacheHandler not available');
                }
            }

            if ($objectCacheService === null) {
                throw new Exception('CacheHandler not available');
            }

            $beforeStats = $objectCacheService->getStats();

            $loadedCount = $objectCacheService->warmupNameCache();

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            $afterStats    = $objectCacheService->getStats();

            return [
                'success'        => true,
                'loaded_names'   => $loadedCount,
                'execution_time' => $executionTime.'ms',
                'before'         => [
                    'name_cache_size' => $beforeStats['name_cache_size'] ?? 0,
                    'name_warmups'    => $beforeStats['name_warmups'] ?? 0,
                ],
                'after'          => [
                    'name_cache_size' => $afterStats['name_cache_size'] ?? 0,
                    'name_warmups'    => $afterStats['name_warmups'] ?? 0,
                ],
            ];
        } catch (Exception $e) {
            return [
                'success'      => false,
                'error'        => 'Cache warmup failed: '.$e->getMessage(),
                'loaded_names' => 0,
            ];
        }//end try
    }//end warmupNamesCache()

     /**
      * Clear schema cache service
      *
      * @param string|null $_userId Specific user ID (unused, kept for API compatibility)
      *
      * @return ((int|mixed|string)[]|bool|int|mixed|string)[] Clear operation results
      *
      * @psalm-return array{service: 'schema', cleared: 0|mixed, success: bool,
      *     error?: string, before?: array{total_entries: int,
      *     entries_with_ttl: int, memory_cache_size: int<0, max>,
      *     cache_table: 'openregister_schema_cache', query_time: string,
      *     timestamp: int<1, max>, entries?: mixed},
      *     after?: array{total_entries: int, entries_with_ttl: int,
      *     memory_cache_size: int<0, max>,
      *     cache_table: 'openregister_schema_cache', query_time: string,
      *     timestamp: int<1, max>, entries?: mixed}
      *
      * @SuppressWarnings(PHPMD.UnusedFormalParameter)
      */
    private function clearSchemaCache(?string $_userId=null): array
    {
        try {
            $beforeStats = $this->schemaCacheService->getCacheStatistics();
            $this->schemaCacheService->clearAllCaches();
            $afterStats = $this->schemaCacheService->getCacheStatistics();

            // Stats arrays may contain 'entries' key even if not in type definition.
            if (array_key_exists('entries', $beforeStats) === true) {
                $beforeEntries = $beforeStats['entries'];
            } else {
                $beforeEntries = 0;
            }

            if (array_key_exists('entries', $afterStats) === true) {
                $afterEntries = $afterStats['entries'];
            } else {
                $afterEntries = 0;
            }

            return [
                'service' => 'schema',
                'cleared' => $beforeEntries - $afterEntries,
                'before'  => $beforeStats,
                'after'   => $afterStats,
                'success' => true,
            ];
        } catch (Exception $e) {
            return [
                'service' => 'schema',
                'cleared' => 0,
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }//end try
    }//end clearSchemaCache()

     /**
      * Clear facet cache service
      *
      * @param string|null $_userId Specific user ID (unused, kept for API compatibility)
      *
      * @return ((int|int[]|string)[]|bool|int|string)[] Clear operation results
      *
      * @psalm-return array{service: 'facet', cleared: int, success: bool,
      *     error?: string, before?: array{total_entries: int, by_type: array<int>,
      *     memory_cache_size: int<0, max>,
      *     cache_table: 'openregister_schema_facet_cache', query_time: string,
      *     timestamp: int<1, max>}, after?: array{total_entries: int,
      *     by_type: array<int>, memory_cache_size: int<0, max>,
      *     cache_table: 'openregister_schema_facet_cache', query_time: string,
      *     timestamp: int<1, max>}
      *
      * @SuppressWarnings(PHPMD.UnusedFormalParameter)
      */
    private function clearFacetCache(?string $_userId=null): array
    {
        try {
            $beforeStats = $this->schemaFacetCacheService->getCacheStatistics();
            $this->schemaFacetCacheService->clearAllCaches();
            $afterStats = $this->schemaFacetCacheService->getCacheStatistics();

            return [
                'service' => 'facet',
                'cleared' => ($beforeStats['total_entries'] ?? 0) - ($afterStats['total_entries'] ?? 0),
                'before'  => $beforeStats,
                'after'   => $afterStats,
                'success' => true,
            ];
        } catch (Exception $e) {
            return [
                'service' => 'facet',
                'cleared' => 0,
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }//end clearFacetCache()

     /**
      * Clear distributed cache
      *
      * @param string|null $_userId Specific user ID (unused, kept for API compatibility)
      *
      * @return (bool|int|string)[] Clear operation results
      *
      * @psalm-return array{service: 'distributed', cleared: 'all'|0, success: bool, error?: string}
      *
      * @SuppressWarnings(PHPMD.UnusedFormalParameter)
      */
    private function clearDistributedCache(?string $_userId=null): array
    {
        try {
            $distributedCache = $this->cacheFactory->createDistributed('openregister');
            $distributedCache->clear();

            return [
                'service' => 'distributed',
                'cleared' => 'all',
            // Can't count distributed cache entries.
                'success' => true,
            ];
        } catch (Exception $e) {
            return [
                'service' => 'distributed',
                'cleared' => 0,
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }//end clearDistributedCache()
}//end class
