<?php

/**
 * OpenRegister AggregationCache
 *
 * 60s distributed cache for aggregation results, keyed on register +
 * schema + name + resolved-filters hash + RBAC scope hash. Evicted
 * by the existing object-write event listeners.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 *
 * @spec openspec/changes/retrofit-2026-05-24-aggregations-backend-native/tasks.md#task-1
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-20
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use OCA\OpenRegister\Service\OrganisationService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Aggregation result cache.
 *
 * Reads are content-addressed by the resolved filter shape + the
 * caller's RBAC scope (current user uid + active organisation UUID), so
 * two users in different orgs — or the same user with a different active
 * organisation — see independently scoped cached values.
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
     * @param ICacheFactory       $cacheFactory        Factory used to create the distributed cache.
     * @param IUserSession        $userSession         Current user session, used to scope the cache key.
     * @param LoggerInterface     $logger              Logger for backend-unavailable warnings.
     * @param OrganisationService $organisationService Organisation service, used to include active organisation in cache key.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-20
     */
    public function __construct(
        ICacheFactory $cacheFactory,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
        private readonly OrganisationService $organisationService
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-20
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
            if (is_array($decoded) === true) {
                return $decoded;
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }//end try
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-aggregations-backend-native/tasks.md#task-1
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
     * Look up a cached ad-hoc aggregation result.
     *
     * Mirrors {@see get()} but derives the name slot from the query value
     * object. The literal `adhoc:` prefix keeps ad-hoc entries visually
     * distinct from named-aggregation entries in cache dumps.
     *
     * @param string           $registerSlug Register slug component of the cache key.
     * @param string           $schemaSlug   Schema slug component of the cache key.
     * @param AggregationQuery $query        Query value object hashed into the cache key.
     *
     * @return array<string, mixed>|null Cached envelope or null on miss.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-20
     */
    public function getAdhoc(string $registerSlug, string $schemaSlug, AggregationQuery $query): ?array
    {
        return $this->get(
            registerSlug: $registerSlug,
            schemaSlug: $schemaSlug,
            name: $this->adhocName(query: $query),
            filter: $query->filter
        );

    }//end getAdhoc()

    /**
     * Store an ad-hoc aggregation result.
     *
     * Mirrors {@see set()} for the ad-hoc path. The stored envelope is
     * rewritten on read (`cached: true`) by callers — see
     * {@see \OCA\OpenRegister\Service\Aggregation\AggregationRunner::runAdhoc()}.
     *
     * @param string               $registerSlug Register slug component of the cache key.
     * @param string               $schemaSlug   Schema slug component of the cache key.
     * @param AggregationQuery     $query        Query value object hashed into the cache key.
     * @param array<string, mixed> $result       Result envelope to store.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-aggregations-backend-native/tasks.md#task-1
     */
    public function setAdhoc(string $registerSlug, string $schemaSlug, AggregationQuery $query, array $result): void
    {
        $this->set(
            registerSlug: $registerSlug,
            schemaSlug: $schemaSlug,
            name: $this->adhocName(query: $query),
            filter: $query->filter,
            result: $result
        );

    }//end setAdhoc()

    /**
     * Derive the cache name slot for an ad-hoc query.
     *
     * Computes `'adhoc:'.sha1(json_encode($query->toArray()))`. The
     * `AggregationQuery::toArray()` output is ksort-stable so two
     * structurally-equivalent queries produce identical hashes.
     *
     * @param AggregationQuery $query The ad-hoc query value object.
     *
     * @return string The cache name slot, prefixed with `adhoc:`.
     */
    private function adhocName(AggregationQuery $query): string
    {
        $encoded    = json_encode($query->toArray());
        $encodedStr = '';
        if ($encoded !== false) {
            $encodedStr = $encoded;
        }

        return 'adhoc:'.sha1($encodedStr);

    }//end adhocName()

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
     *
     * @spec openspec/changes/retrofit-2026-05-24-aggregations-backend-native/tasks.md#task-1
     */
    public function evictForSchema(string $registerSlug, string $schemaSlug): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            // Scoped eviction: bump this (register, schema)'s version counter so
            // its cached aggregations become unreachable, WITHOUT wiping every
            // other schema's/user's cache (scope-cache-invalidation). The counter
            // outlives the data TTL so a bump can never be undone by expiry while
            // stale data is still live.
            $versionKey = $this->versionKey(registerSlug: $registerSlug, schemaSlug: $schemaSlug);
            $current    = $this->cache->get($versionKey);
            $next       = 1;
            if (is_numeric($current) === true) {
                $next = ((int) $current + 1);
            }

            // TTL well beyond the data TTL (self::TTL) so the bump persists.
            $this->cache->set($versionKey, $next, (self::TTL * 60));
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-20
     */
    private function key(string $registerSlug, string $schemaSlug, string $name, array $filter): string
    {
        ksort($filter);
        $filterEncoded = json_encode($filter);
        $filterStr     = '';
        if ($filterEncoded !== false) {
            $filterStr = $filterEncoded;
        }

        $filterHash = sha1($filterStr);
        $rbacHash   = $this->rbacScopeHash();

        // Fold the per-(register, schema) version into the key so eviction can be
        // scoped by bumping that version (scope-cache-invalidation) rather than
        // wiping the whole aggregation cache: after a bump, every key for the
        // schema carries the new version and old entries become unreachable.
        $version = $this->schemaVersion(registerSlug: $registerSlug, schemaSlug: $schemaSlug);

        return sprintf(
            'agg:%s:%s:v%d:%s:%s:%s',
            $registerSlug,
            $schemaSlug,
            $version,
            $name,
            $filterHash,
            $rbacHash
        );
    }//end key()

    /**
     * Version-counter cache key for a (register, schema) pair.
     *
     * @param string $registerSlug Register slug.
     * @param string $schemaSlug   Schema slug.
     *
     * @return string
     */
    private function versionKey(string $registerSlug, string $schemaSlug): string
    {
        return sprintf('aggver:%s:%s', $registerSlug, $schemaSlug);
    }//end versionKey()

    /**
     * Current aggregation-cache version for a (register, schema) pair.
     *
     * @param string $registerSlug Register slug.
     * @param string $schemaSlug   Schema slug.
     *
     * @return int The current version (0 when never evicted).
     */
    private function schemaVersion(string $registerSlug, string $schemaSlug): int
    {
        if ($this->cache === null) {
            return 0;
        }

        $current = $this->cache->get($this->versionKey(registerSlug: $registerSlug, schemaSlug: $schemaSlug));
        if (is_numeric($current) === true) {
            return (int) $current;
        }

        return 0;
    }//end schemaVersion()

    /**
     * Hash the current RBAC scope (user UID + active organisation).
     *
     * Including both dimensions prevents a cache hit when the same user
     * switches active organisation between requests, or when two users in
     * different organisations would otherwise share a cache key.
     *
     * @return string SHA-1 hash of "uid:orgUuid" (or "anonymous:none" for unauthenticated callers).
     */
    private function rbacScopeHash(): string
    {
        $uid   = ($this->userSession->getUser()?->getUID() ?? 'anonymous');
        $org   = $this->organisationService->getActiveOrganisation();
        $orgId = 'none';
        if ($org !== null) {
            $orgId = $org->getUuid();
        }

        return sha1($uid.':'.$orgId);
    }//end rbacScopeHash()
}//end class
