<?php

/**
 * AggregationCache
 *
 * Generic 60-second TTL distributed cache for aggregation results.
 * Key shape: agg:{registerSlug}:{schemaSlug}:{name}:{sha1(resolvedFilters)}:{sha1(rbacScopeHash)}.
 * Fail-closes when the cache backend is unavailable — callers always get a fresh result.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/aggregations-backend-native/tasks.md#task-6
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Distributed aggregation result cache with 60-second TTL.
 *
 * @spec openspec/changes/aggregations-backend-native/tasks.md#task-6
 */
class AggregationCache
{

    /**
     * Cache time-to-live in seconds.
     *
     * @var int
     */
    public const TTL = 60;

    /**
     * Distributed cache instance (may be null when no caching backend is configured).
     *
     * @var ICache|null
     */
    private ?ICache $cache;

    /**
     * Constructor.
     *
     * @param ICacheFactory   $cacheFactory Cache factory.
     * @param LoggerInterface $logger       Logger.
     */
    public function __construct(
        ICacheFactory $cacheFactory,
        private readonly LoggerInterface $logger
    ) {
        try {
            $this->cache = $cacheFactory->createDistributed('openregister_aggregations');
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[AggregationCache] Failed to initialise distributed cache; running without cache.',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
            $this->cache = null;
        }
    }//end __construct()

    /**
     * Build the cache key for the given parameters.
     *
     * Key: agg:{register}:{schema}:{name}:{sha1(filters)}:{sha1(rbacScope)}.
     * Filters are ksort-stable so key order does not affect cache hits.
     *
     * @param string $registerSlug Register slug.
     * @param string $schemaSlug   Schema slug.
     * @param string $name         Named aggregation.
     * @param array  $filters      Resolved filter map.
     * @param string $rbacScope    RBAC scope string (uid or 'anonymous').
     *
     * @return string Cache key.
     *
     * @spec openspec/changes/aggregations-backend-native/tasks.md#task-6
     */
    public function buildKey(
        string $registerSlug,
        string $schemaSlug,
        string $name,
        array $filters,
        string $rbacScope
    ): string {
        ksort($filters);
        $encoded     = json_encode(value: $filters);
        $filtersHash = sha1(string: ($encoded !== false) ? $encoded : '{}');
        $rbacHash    = sha1(string: $rbacScope);

        return 'agg:'.$registerSlug.':'.$schemaSlug.':'.$name.':'.$filtersHash.':'.$rbacHash;
    }//end buildKey()

    /**
     * Retrieve a cached aggregation result.
     *
     * @param string $key Cache key from buildKey().
     *
     * @return AggregationResult|null Cached result, or null on miss/error.
     *
     * @spec openspec/changes/aggregations-backend-native/tasks.md#task-6
     */
    public function get(string $key): ?AggregationResult
    {
        if ($this->cache === null) {
            return null;
        }

        try {
            $data = $this->cache->get($key);
            if ($data === null) {
                return null;
            }

            return new AggregationResult(
                value: $data['value'],
                groups: $data['groups'] ?? null,
                backend: $data['backend'],
                cached: true
            );
        } catch (Throwable $e) {
            $this->logger->debug(
                message: '[AggregationCache] Cache read error; treating as miss.',
                context: ['file' => __FILE__, 'line' => __LINE__, 'key' => $key, 'error' => $e->getMessage()]
            );
            return null;
        }
    }//end get()

    /**
     * Store an aggregation result in the cache.
     *
     * @param string            $key    Cache key from buildKey().
     * @param AggregationResult $result Result to cache.
     *
     * @return void
     *
     * @spec openspec/changes/aggregations-backend-native/tasks.md#task-6
     */
    public function set(string $key, AggregationResult $result): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $this->cache->set(
                key: $key,
                value: $result->toArray(),
                ttl: self::TTL
            );
        } catch (Throwable $e) {
            $this->logger->debug(
                message: '[AggregationCache] Cache write error; result will not be cached.',
                context: ['file' => __FILE__, 'line' => __LINE__, 'key' => $key, 'error' => $e->getMessage()]
            );
        }
    }//end set()

    /**
     * Evict all cached aggregations for a (register, schema) pair.
     *
     * Uses ICache::clear() because the underlying backend has no prefix-delete.
     * This is intentionally coarse — the 60-second TTL bounds staleness.
     *
     * @return void
     *
     * @spec openspec/changes/aggregations-backend-native/tasks.md#task-6
     */
    public function evict(): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $this->cache->clear();
        } catch (Throwable $e) {
            $this->logger->debug(
                message: '[AggregationCache] Cache eviction error; entries will expire naturally.',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
        }
    }//end evict()
}//end class
