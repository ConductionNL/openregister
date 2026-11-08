<?php

/**
 * OpenRegister Cache Settings Handler
 *
 * This file contains the handler class for managing cache operations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
use InvalidArgumentException;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\ObjectCacheMaintenanceInterface;
use OCA\OpenRegister\Service\Schemas\FacetResultCacheClearer;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCP\AppFramework\IAppContainer;
use OCP\ICacheFactory;
use RuntimeException;

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
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex cache management across multiple cache types
 * @SuppressWarnings(PHPMD.LongVariable)             Cache service properties use descriptive names for clarity
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class CacheSettingsHandler {

	/**
	 * The object cache service block: output field => [stats key, default].
	 *
	 * @var array<string, array{0: string, 1: int}>
	 */
	private const SERVICE_FIELDS = [
		'entries' => ['entries', 0],
		'hits' => ['hits', 0],
		'requests' => ['requests', 0],
		'memoryUsage' => ['memoryUsage', 0],
	];

	/**
	 * The names cache block: output field => [stats key, default].
	 *
	 * @var array<string, array{0: string, 1: int|float}>
	 */
	private const NAME_FIELDS = [
		'cache_size' => ['name_cache_size', 0],
		'hit_rate' => ['name_hit_rate', 0.0],
		'hits' => ['name_hits', 0],
		'misses' => ['name_misses', 0],
		'warmups' => ['name_warmups', 0],
	];

	/**
	 * A cache whose statistics are not stored in the database: counting them would be a performance issue.
	 *
	 * @var array<string, int>
	 */
	private const UNTRACKED_SERVICE = ['entries' => 0, 'hits' => 0, 'requests' => 0, 'memoryUsage' => 0];

	/**
	 * The safe defaults reported when the statistics are unavailable.
	 *
	 * @var array<string, mixed>
	 */
	private const UNAVAILABLE_STATS = [
		'overview' => [
			'totalCacheSize' => 0,
			'totalCacheEntries' => 0,
			'overallHitRate' => 0.0,
			'averageResponseTime' => 0.0,
			'cacheEfficiency' => 0.0,
		],
		'services' => [
			'object' => self::UNTRACKED_SERVICE,
			'schema' => self::UNTRACKED_SERVICE,
			'facet' => self::UNTRACKED_SERVICE,
		],
		'names' => [
			'cache_size' => 0,
			'hit_rate' => 0.0,
			'hits' => 0,
			'misses' => 0,
			'warmups' => 0,
			'enabled' => false,
		],
		'distributed' => ['type' => 'none', 'backend' => 'Unknown', 'available' => false],
		'performance' => [
			'averageHitTime' => 0,
			'averageMissTime' => 0,
			'performanceGain' => 0,
			'optimalHitRate' => 85.0,
		],
	];

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
	 * @var FacetResultCacheClearer
	 */
	private FacetResultCacheClearer $facetResults;

	/**
	 * Object cache service (lazy-loaded when needed)
	 *
	 * @var ObjectCacheMaintenanceInterface|null
	 */
	private ?ObjectCacheMaintenanceInterface $objectCacheService = null;

	/**
	 * Container for lazy loading services
	 *
	 * @var IAppContainer|null
	 */
	private ?IAppContainer $container = null;

	/**
	 * In-process cache for object cache stats with a short TTL to avoid
	 * calling CacheHandler on every request. Instance-scoped rather than
	 * method-static so test isolation is preserved.
	 *
	 * @var array|null
	 */
	private ?array $objectStatsCache = null;

	/**
	 * Unix timestamp of the last refresh of $objectStatsCache.
	 *
	 * @var integer
	 */
	private int $objectStatsCacheTimestamp = 0;

	/**
	 * Constructor for CacheSettingsHandler
	 *
	 * @param ICacheFactory $cacheFactory Cache factory.
	 * @param SchemaCacheHandler $schemaCacheService Schema cache handler.
	 * @param FacetResultCacheClearer $facetResults Clears the facet result caches.
	 * @param ObjectCacheMaintenanceInterface|null $objectCacheService Object cache service (optional, lazy-loaded).
	 * @param IAppContainer|null $container Container for lazy loading (optional).
	 *
	 * @return void
	 */
	public function __construct(
		ICacheFactory $cacheFactory,
		SchemaCacheHandler $schemaCacheService,
		FacetResultCacheClearer $facetResults,
		?ObjectCacheMaintenanceInterface $objectCacheService = null,
		?IAppContainer $container = null,
	) {
		$this->cacheFactory = $cacheFactory;
		$this->schemaCacheService = $schemaCacheService;
		$this->facetResults = $facetResults;
		$this->objectCacheService = $objectCacheService;
		$this->container = $container;
	}//end __construct()

	/**
	 * Get comprehensive cache statistics from actual cache systems(not database)
	 *
	 * Provides detailed insights into cache usage and performance by querying
	 * the actual cache backends rather than database tables for better performance.
	 *
	 * @throws \RuntimeException If cache statistics retrieval fails
	 *
	 * @return array Cache stats with overview, services, names, distributed, performance, and lastUpdated.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-3
	 */
	public function getCacheStats(): array {
		try {
			$distributedStats = $this->getDistributedCacheStats();
			$performanceStats = $this->getCachePerformanceMetrics();

			// Cached object stats avoid expensive operations on every request.
			$objectStats = $this->getCachedObjectStats();

			return [
				'overview' => $this->cacheOverview(objectStats: $objectStats, averageHitTime: $performanceStats['averageHitTime'] ?? 0.0),
				'services' => [
					'object' => $this->countsOf(stats: $objectStats, fields: self::SERVICE_FIELDS),
					'schema' => self::UNTRACKED_SERVICE,
					'facet' => self::UNTRACKED_SERVICE,
				],
				'names' => $this->countsOf(stats: $objectStats, fields: self::NAME_FIELDS) + ['enabled' => true],
				'distributed' => $distributedStats,
				'performance' => $performanceStats,
				'lastUpdated' => (new DateTime())->format('c'),
			];
		} catch (Exception $e) {
			return self::UNAVAILABLE_STATS + [
				'lastUpdated' => (new DateTime())->format('c'),
				'error' => 'Cache statistics unavailable: ' . $e->getMessage(),
			];
		}//end try
	}//end getCacheStats()

	/**
	 * The overview block: size, entries and hit rate of the object cache.
	 *
	 * @param array<string, mixed> $objectStats    The object cache statistics.
	 * @param mixed                $averageHitTime The average hit time from the performance metrics.
	 *
	 * @return array<string, mixed>
	 */
	private function cacheOverview(array $objectStats, $averageHitTime): array {
		return [
			'totalCacheSize' => $objectStats['memoryUsage'] ?? 0,
			'totalCacheEntries' => $objectStats['entries'] ?? 0,
			'overallHitRate' => $this->calculateHitRate(stats: $objectStats),
			'averageResponseTime' => $averageHitTime,
			'cacheEfficiency' => $this->calculateHitRate(stats: $objectStats),
		];
	}//end cacheOverview()

	/**
	 * Pick fields from a statistics payload: output field => [stats key, default].
	 *
	 * @param array<string, mixed>                          $stats  The statistics.
	 * @param array<string, array{0: string, 1: int|float}> $fields The fields to pick.
	 *
	 * @return array<string, mixed>
	 */
	private function countsOf(array $stats, array $fields): array {
		$counts = [];
		foreach ($fields as $field => [$key, $default]) {
			$counts[$field] = $stats[$key] ?? $default;
		}

		return $counts;
	}//end countsOf()

	/**
	 * Get cached object statistics to avoid expensive operations on every request
	 *
	 * @return array Object cache statistics
	 */
	private function getCachedObjectStats(): array {
		// In-memory cache with 30-second TTL to avoid expensive CacheHandler calls.
		$now = time();
		if ($this->objectStatsCache === null || ($now - $this->objectStatsCacheTimestamp) > 30) {
			try {
				$objectCacheService = $this->objectCacheHandler();

				$this->objectStatsCache = $objectCacheService->getStats();
			} catch (Exception $e) {
				// If no object cache stats available, use defaults.
				$this->objectStatsCache = [
					'entries' => 0,
					'hits' => 0,
					'requests' => 0,
					'memoryUsage' => 0,
					'name_cache_size' => 0,
					'name_hit_rate' => 0.0,
					'name_hits' => 0,
					'name_misses' => 0,
					'name_warmups' => 0,
				];
			}//end try

			$this->objectStatsCacheTimestamp = $now;
		}//end if

		return $this->objectStatsCache;
	}//end getCachedObjectStats()

	/**
	 * Calculate hit rate from cache statistics
	 *
	 * @param array $stats Cache statistics array
	 *
	 * @return float Hit rate percentage
	 *
	 * Else clause improves readability of simple ratio calculation
	 */
	private function calculateHitRate(array $stats): float {
		$requests = $stats['requests'] ?? 0;
		$hits = $stats['hits'] ?? 0;

		if ($requests > 0) {
			return ($hits / $requests) * 100;
		}

		return 0.0;
	}//end calculateHitRate()

	/**
	 * Get distributed cache statistics from Nextcloud's cache factory
	 *
	 * @return (bool|string)[] Distributed cache statistics
	 *
	 * @psalm-return array{type: 'distributed'|'none', backend: string,
	 *     available: bool, error?: string, keyCount?: 'Unknown', size?: 'Unknown'}
	 */
	private function getDistributedCacheStats(): array {
		try {
			$distributedCache = $this->cacheFactory->createDistributed('openregister');

			return [
				'type' => 'distributed',
				'backend' => get_class($distributedCache),
				'available' => true,
				'keyCount' => 'Unknown',
				// Most cache backends don't provide this.
				'size' => 'Unknown',
			];
		} catch (Exception $e) {
			return [
				'type' => 'none',
				'backend' => 'fallback',
				'available' => false,
				'error' => $e->getMessage(),
			];
		}
	}//end getDistributedCacheStats()

	/**
	 * Get cache performance metrics for the last period
	 *
	 * @return array Performance metrics with timing data.
	 */
	private function getCachePerformanceMetrics(): array {
		// This would typically come from a performance monitoring service
		// For now, return basic metrics.
		return [
			'averageHitTime' => 2.5,
			// Ms.
			'averageMissTime' => 850.0,
			// Ms.
			'performanceGain' => 340.0,
			// Factor improvement with cache.
			'optimalHitRate' => 85.0,
			// Target hit rate percentage.
			'currentTrend' => 'improving',
		];
	}//end getCachePerformanceMetrics()

	/**
	 * Clear cache with granular control
	 *
	 * @param string $type Cache type: 'all', 'object', 'schema', 'facet', 'distributed', 'names'
	 * @param string|null $userId Specific user ID to clear cache for (if supported)
	 * @param array $_options Additional options for cache clearing
	 *
	 * @return (((float|int[]|mixed|string)[]|bool|int|mixed|string)[][]|int|mixed|null|string)[]
	 *
	 * @throws \RuntimeException If cache clearing fails
	 *
	 * @psalm-return array{type: string, userId: null|string,
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
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Multiple cache types require switch-based routing
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-3
	 */
	public function clearCache(string $type = 'all', ?string $userId = null, array $_options = []): array {
		try {
			$results = [
				'type' => $type,
				'userId' => $userId,
				'timestamp' => (new DateTime())->format('c'),
				'results' => [],
				'errors' => [],
				'totalCleared' => 0,
			];

			switch ($type) {
				case 'all':
					$results['results']['object'] = $this->clearObjectCache(_userId: $userId);
					$results['results']['schema'] = $this->clearSchemaCache(_userId: $userId);
					$results['results']['facet'] = $this->clearFacetCache(_userId: $userId);
					$results['results']['distributed'] = $this->clearDistributedCache(_userId: $userId);
					$results['results']['names'] = $this->clearNamesCache();
					break;

				case 'object':
					$results['results']['object'] = $this->clearObjectCache(_userId: $userId);
					break;

				case 'schema':
					$results['results']['schema'] = $this->clearSchemaCache(_userId: $userId);
					break;

				case 'facet':
					$results['results']['facet'] = $this->clearFacetCache(_userId: $userId);
					break;

				case 'distributed':
					$results['results']['distributed'] = $this->clearDistributedCache(_userId: $userId);
					break;

				case 'names':
					$results['results']['names'] = $this->clearNamesCache();
					break;

				default:
					throw new InvalidArgumentException("Invalid cache type: {$type}");
			}//end switch

			// Calculate total cleared entries.
			foreach ($results['results'] as $serviceResult) {
				$cleared = $serviceResult['cleared'] ?? 0;
				if (is_int($cleared) === true) {
					$results['totalCleared'] += $cleared;
				}
			}

			return $results;
		} catch (Exception $e) {
			throw new RuntimeException('Failed to clear cache: ' . $e->getMessage());
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
	private function clearObjectCache(?string $_userId = null): array {
		try {
			$objectCacheService = $this->objectCacheHandler();

			$beforeStats = $objectCacheService->getStats();
			$objectCacheService->clearCache();
			$afterStats = $objectCacheService->getStats();

			// CacheHandler::getStats() reports cache_size, query_cache_size and
			// name_cache_size, and never an `entries` key. Reading `entries`
			// produced two "Undefined array key" warnings per call and a cleared
			// count of 0 - 0, so the admin action always reported clearing
			// nothing. clearCache() empties all three in-memory caches, so all
			// three are what it cleared.
			return [
				'service' => 'object',
				'cleared' => ($this->countCacheEntries(stats: $beforeStats) - $this->countCacheEntries(stats: $afterStats)),
				'before' => $beforeStats,
				'after' => $afterStats,
				'success' => true,
			];
		} catch (Exception $e) {
			return $this->clearFailed(service: 'object', error: $e);
		}//end try
	}//end clearObjectCache()

	/**
	 * Total in-memory entries a CacheHandler stats payload reports.
	 *
	 * @param array $stats A {@see \OCA\OpenRegister\Service\Object\CacheHandler::getStats()} payload.
	 *
	 * @return int The object, query and name cache sizes added together.
	 *
	 * @spec exclude Arithmetic over a stats payload; the behaviour it feeds is covered by clearObjectCache().
	 */
	private function countCacheEntries(array $stats): int {
		return ((int)($stats['cache_size'] ?? 0)
			+ (int)($stats['query_cache_size'] ?? 0)
			+ (int)($stats['name_cache_size'] ?? 0));
	}//end countCacheEntries()

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
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Cache clearing with fallback logic requires multiple branches
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple conditional branches for cache service resolution
	 */
	private function clearNamesCache(): array {
		try {
			$objectCacheService = $this->objectCacheHandler();

			$beforeStats = $objectCacheService->getStats();
			$beforeNameCacheSize = $beforeStats['name_cache_size'] ?? 0;

			$objectCacheService->clearNameCache();

			$afterStats = $objectCacheService->getStats();
			$afterNameCacheSize = $afterStats['name_cache_size'] ?? 0;

			return [
				'service' => 'names',
				'cleared' => $beforeNameCacheSize - $afterNameCacheSize,
				'before' => [
					'name_cache_size' => $beforeNameCacheSize,
					'name_hits' => $beforeStats['name_hits'] ?? 0,
					'name_misses' => $beforeStats['name_misses'] ?? 0,
				],
				'after' => [
					'name_cache_size' => $afterNameCacheSize,
					'name_hits' => $afterStats['name_hits'] ?? 0,
					'name_misses' => $afterStats['name_misses'] ?? 0,
				],
				'success' => true,
			];
		} catch (Exception $e) {
			return $this->clearFailed(service: 'names', error: $e);
		}//end try
	}//end clearNamesCache()

	/**
	 * Warmup object names cache manually
	 *
	 * @return array Result with success, loaded_names, execution_time, and before/after stats.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Cache warmup with fallback logic requires multiple branches
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple conditional branches for cache service resolution
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-3
	 */
	public function warmupNamesCache(): array {
		try {
			$startTime = microtime(true);
			$objectCacheService = $this->objectCacheHandler();

			$beforeStats = $objectCacheService->getStats();

			$loadedCount = $objectCacheService->warmupNameCache();

			$executionTime = round((microtime(true) - $startTime) * 1000, 2);
			$afterStats = $objectCacheService->getStats();

			return [
				'success' => true,
				'loaded_names' => $loadedCount,
				'execution_time' => $executionTime . 'ms',
				'before' => [
					'name_cache_size' => $beforeStats['name_cache_size'] ?? 0,
					'name_warmups' => $beforeStats['name_warmups'] ?? 0,
				],
				'after' => [
					'name_cache_size' => $afterStats['name_cache_size'] ?? 0,
					'name_warmups' => $afterStats['name_warmups'] ?? 0,
				],
			];
		} catch (Exception $e) {
			return [
				'success' => false,
				'error' => 'Cache warmup failed: ' . $e->getMessage(),
				'loaded_names' => 0,
			];
		}//end try
	}//end warmupNamesCache()

	/**
	 * Clear schema cache service
	 *
	 * @param string|null $_userId Specific user ID (unused, kept for API compatibility)
	 *
	 * @return array Result with service, cleared count, success, and before/after stats.
	 *
	 * @SuppressWarnings (PHPMD.UnusedFormalParameter)
	 * Conditional handling of optional array keys requires if-else structure
	 */
	private function clearSchemaCache(?string $_userId = null): array {
		try {
			$beforeStats = $this->schemaCacheService->getCacheStatistics();
			$this->schemaCacheService->clearAllCaches();
			$afterStats = $this->schemaCacheService->getCacheStatistics();

			// Stats arrays may contain 'entries' key even if not in type definition.
			$beforeEntries = $beforeStats['entries'] ?? 0;
			$afterEntries = $afterStats['entries'] ?? 0;

			return [
				'service' => 'schema',
				'cleared' => $beforeEntries - $afterEntries,
				'before' => $beforeStats,
				'after' => $afterStats,
				'success' => true,
			];
		} catch (Exception $e) {
			return $this->clearFailed(service: 'schema', error: $e);
		}//end try
	}//end clearSchemaCache()

	/**
	 * Clear facet cache service
	 *
	 * @param string|null $_userId Specific user ID (unused, kept for API compatibility)
	 *
	 * @return array Result with service, cleared count, success, and before/after stats.
	 *
	 * @SuppressWarnings (PHPMD.UnusedFormalParameter)
	 */
	private function clearFacetCache(?string $_userId = null): array {
		$before = $this->emptyFacetStatistics();
		$this->facetResults->clearAll();

		return [
			'service' => 'facet',
			'cleared' => 0,
			'before' => $before,
			'after' => $this->emptyFacetStatistics(),
			'success' => true,
		];
	}//end clearFacetCache()

	/**
	 * The facet cache statistics in the shape the cache API has always returned.
	 *
	 * Facet results live only in the distributed facet caches, which keep no
	 * count; the persistent facet table was never written, so its figures are
	 * zero by construction.
	 *
	 * @return array{total_entries: int, by_type: array<string, int>, memory_cache_size: int,
	 *     cache_table: string, query_time: string, timestamp: int}
	 */
	private function emptyFacetStatistics(): array {
		$startTime = microtime(true);

		return [
			'total_entries' => 0,
			'by_type' => [],
			'memory_cache_size' => 0,
			'cache_table' => 'openregister_schema_facet_cache',
			'query_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms',
			'timestamp' => time(),
		];
	}//end emptyFacetStatistics()

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
	private function clearDistributedCache(?string $_userId = null): array {
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
			return $this->clearFailed(service: 'distributed', error: $e);
		}
	}//end clearDistributedCache()

	/**
	 * The object cache handler: the one injected, else the one the container builds.
	 *
	 * @return ObjectCacheMaintenanceInterface The handler.
	 *
	 * @throws Exception When neither supplies one.
	 */
	private function objectCacheHandler(): ObjectCacheMaintenanceInterface {
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

		return $objectCacheService;
	}//end objectCacheHandler()

	/**
	 * The result of a cache clear that failed.
	 *
	 * @param string    $service The cache that was being cleared.
	 * @param Exception $error   Why it failed.
	 *
	 * @return array{service: T, cleared: 0, success: false, error: string}
	 *
	 * @template T of string
	 * @phpstan-param T $service
	 */
	private function clearFailed(string $service, Exception $error): array {
		return [
			'service' => $service,
			'cleared' => 0,
			'success' => false,
			'error' => $error->getMessage(),
		];
	}//end clearFailed()
}//end class
