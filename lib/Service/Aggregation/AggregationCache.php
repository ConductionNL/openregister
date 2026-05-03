<?php

/**
 * OpenRegister AggregationCache
 *
 * 60s distributed cache for aggregation results, keyed on register +
 * schema + name + resolved-filters hash + RBAC scope hash. Evicted
 * by the existing object-write event listeners.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Aggregation result cache.
 *
 * Reads are content-addressed by the resolved filter shape + the
 * caller's RBAC scope (current user uid + active organisation), so two
 * users in different orgs see independently scoped cached values.
 *
 * Writes are evicted globally for a (register, schema) pair on any
 * object-write event (Created/Updated/Deleted/Transitioned). The
 * eviction is coarse — every aggregation on the schema goes — which is
 * the right tradeoff: aggregation results are derived data, and the
 * 60s TTL bounds staleness even when an evict is missed.
 */
class AggregationCache
{

    /**
     * Time-to-live for cached entries, in seconds.
     */
    public const TTL = 60;

    /**
     * Distributed cache backend, null when no backend is available.
     *
     * @var ICache|null
     */
    private ?ICache $cache = null;

    /**
     * Constructor.
     *
     * @param ICacheFactory   $cacheFactory Factory used to create the distributed cache.
     * @param IUserSession    $userSession  Current user session, used to scope the cache key.
     * @param LoggerInterface $logger       Logger for backend-unavailable warnings.
     *
     * @return void
     */
    public function __construct(
        ICacheFactory $cacheFactory,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger
    ) {
        try {
            $this->cache = $cacheFactory->createDistributed('openregister_aggregations');
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('[AggregationCache] cache backend unavailable: %s', $e->getMessage())
            );
            $this->cache = null;
        }
    }//end __construct()

    /**
     * Look up a cached aggregation result.
     *
     * Returns the cached associative array (with the same shape
     * AggregationRunner emits) or null on miss.
     *
     * @param string               $registerSlug Register slug component of the cache key.
     * @param string               $schemaSlug   Schema slug component of the cache key.
     * @param string               $name         Aggregation name component of the cache key.
     * @param array<string, mixed> $filter       Resolved filter (placeholders concrete).
     *
     * @return array<string, mixed>|null Cached result or null on miss.
     */
    public function get(string $registerSlug, string $schemaSlug, string $name, array $filter): ?array
    {
        if ($this->cache === null) {
            return null;
        }

        try {
            $blob = $this->cache->get(
                $this->key(
                    registerSlug: $registerSlug,
                    schemaSlug: $schemaSlug,
                    name: $name,
                    filter: $filter
                )
            );
            if (is_string($blob) === false) {
                return null;
            }

            $decoded = json_decode($blob, true);
            return is_array($decoded) === true ? $decoded : null;
        } catch (\Throwable $e) {
            return null;
        }
    }//end get()

    /**
     * Store an aggregation result.
     *
     * @param string               $registerSlug Register slug component of the cache key.
     * @param string               $schemaSlug   Schema slug component of the cache key.
     * @param string               $name         Aggregation name component of the cache key.
     * @param array<string, mixed> $filter       Resolved filter (placeholders concrete).
     * @param array<string, mixed> $result       Result envelope to store.
     *
     * @return void
     */
    public function set(string $registerSlug, string $schemaSlug, string $name, array $filter, array $result): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $this->cache->set(
                $this->key(
                    registerSlug: $registerSlug,
                    schemaSlug: $schemaSlug,
                    name: $name,
                    filter: $filter
                ),
                json_encode($result),
                self::TTL
            );
        } catch (\Throwable $e) {
            // Don't escalate: a cache write failure shouldn't break the response.
        }
    }//end set()

    /**
     * Evict every cached aggregation for a (register, schema). Called by
     * the object-write listeners.
     *
     * NB: the underlying ICache doesn't expose a prefix-delete; we
     * approximate with `clear()` which wipes the entire app cache.
     * That's acceptable because the TTL is 60s and the cache is
     * regenerated lazily on the next request.
     *
     * @param string $registerSlug Register slug whose aggregations should be evicted.
     * @param string $schemaSlug   Schema slug whose aggregations should be evicted.
     *
     * @return void
     */
    public function evictForSchema(string $registerSlug, string $schemaSlug): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            // ICache doesn't have prefix delete. Do a best-effort clear of
            // the whole openregister_aggregations cache — coarse but safe
            // because TTL is short. A future refinement can swap to a
            // backing store with prefix scan support.
            $this->cache->clear();
        } catch (\Throwable $e) {
            $this->logger->debug(
                sprintf('[AggregationCache] evict failed: %s', $e->getMessage())
            );
        }
    }//end evictForSchema()

    /**
     * Build the cache key. Hashes the filter and the RBAC scope so that:
     *   - identical filters from the same scope hit the same key
     *   - different filters or different scopes are independent entries
     *
     * @param string               $registerSlug Register slug component.
     * @param string               $schemaSlug   Schema slug component.
     * @param string               $name         Aggregation name.
     * @param array<string, mixed> $filter       Resolved filter map.
     *
     * @return string The cache key string.
     */
    private function key(string $registerSlug, string $schemaSlug, string $name, array $filter): string
    {
        ksort($filter);
        $filterHash = sha1(json_encode($filter) === false ? '' : json_encode($filter));
        $rbacHash   = $this->rbacScopeHash();
        return sprintf('agg:%s:%s:%s:%s:%s', $registerSlug, $schemaSlug, $name, $filterHash, $rbacHash);
    }//end key()

    /**
     * Hash the current RBAC scope (currently: the user UID).
     *
     * @return string SHA-1 hash of the user UID, or of "anonymous" when no user is logged in.
     */
    private function rbacScopeHash(): string
    {
        $uid = ($this->userSession->getUser()?->getUID() ?? 'anonymous');
        return sha1($uid);
    }//end rbacScopeHash()
}//end class
