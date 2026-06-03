<?php

/**
 * AggregationCache — distributed cache for aggregation results.
 *
 * Wraps Nextcloud's distributed cache with RBAC-scoped, content-addressed
 * key derivation for both named-aggregation and ad-hoc aggregation entries.
 *
 * Named entries key slot: the literal annotation name.
 * Ad-hoc entries key slot: 'adhoc:' + sha1(json_encode($query->toArray())).
 *
 * All entries share the 'openregister_aggregations' cache namespace so
 * {@see evictForSchema()} can clear both kinds in a single ICache::clear().
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
 * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Distributed-cache service for aggregation result envelopes.
 *
 * Invalidation is coarse: {@see evictForSchema()} calls ICache::clear() on
 * the entire 'openregister_aggregations' namespace, which covers both named
 * and ad-hoc entries.  The 60 s TTL bounds staleness in the event of a
 * missed eviction.
 *
 * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-2.2
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */
class AggregationCache
{

    /**
     * Cache TTL in seconds.
     *
     * @var int
     */
    public const TTL = 60;

    /**
     * Cache namespace shared by named and ad-hoc entries.
     *
     * @var string
     */
    private const NAMESPACE = 'openregister_aggregations';

    /**
     * Prefix distinguishing ad-hoc entries from named entries in cache dumps.
     *
     * @var string
     */
    private const ADHOC_PREFIX = 'adhoc:';

    /**
     * The underlying distributed cache instance (null when unavailable).
     *
     * @var ICache|null
     */
    private ?ICache $cache;

    /**
     * Constructor.
     *
     * @param ICacheFactory   $cacheFactory Cache factory provided by Nextcloud DI.
     * @param IUserSession    $userSession  User session for RBAC scope derivation.
     * @param LoggerInterface $logger       Logger.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-2.2
     */
    public function __construct(
        ICacheFactory $cacheFactory,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger
    ) {
        try {
            $this->cache = $cacheFactory->createDistributed(prefix: self::NAMESPACE);
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[AggregationCache] Distributed cache unavailable; gracefully degrading',
                context: ['error' => $e->getMessage()]
            );
            $this->cache = null;
        }
    }//end __construct()

    /**
     * Read a named-aggregation result from the cache.
     *
     * @param string $registerSlug Register slug.
     * @param string $schemaSlug   Schema slug.
     * @param string $name         Annotation name.
     * @param array  $filter       Applied filter (part of key derivation).
     *
     * @return array|null Cached envelope or null on miss.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-2.2
     */
    public function get(string $registerSlug, string $schemaSlug, string $name, array $filter): ?array
    {
        if ($this->cache === null) {
            return null;
        }

        $key  = $this->buildKey(registerSlug: $registerSlug, schemaSlug: $schemaSlug, name: $name, filter: $filter);
        $data = $this->cache->get(key: $key);

        return is_array(value: $data) === true ? $data : null;
    }//end get()

    /**
     * Write a named-aggregation result to the cache.
     *
     * @param string $registerSlug Register slug.
     * @param string $schemaSlug   Schema slug.
     * @param string $name         Annotation name.
     * @param array  $filter       Applied filter.
     * @param array  $envelope     Result envelope to cache.
     *
     * @return void
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-2.2
     */
    public function set(string $registerSlug, string $schemaSlug, string $name, array $filter, array $envelope): void
    {
        if ($this->cache === null) {
            return;
        }

        $key = $this->buildKey(registerSlug: $registerSlug, schemaSlug: $schemaSlug, name: $name, filter: $filter);
        $this->cache->set(key: $key, value: $envelope, ttl: self::TTL);
    }//end set()

    /**
     * Read an ad-hoc aggregation result from the cache.
     *
     * The name slot is derived as 'adhoc:' + sha1(json_encode($query->toArray()))
     * so ad-hoc entries are visually distinct from named entries in cache dumps.
     *
     * @param string           $registerSlug Register slug.
     * @param string           $schemaSlug   Schema slug.
     * @param AggregationQuery $query        Query whose content addresses the cache key.
     *
     * @return array|null Cached envelope or null on miss.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-2.2
     */
    public function getAdhoc(string $registerSlug, string $schemaSlug, AggregationQuery $query): ?array
    {
        if ($this->cache === null) {
            return null;
        }

        $key  = $this->buildAdhocKey(registerSlug: $registerSlug, schemaSlug: $schemaSlug, query: $query);
        $data = $this->cache->get(key: $key);

        return is_array(value: $data) === true ? $data : null;
    }//end getAdhoc()

    /**
     * Write an ad-hoc aggregation result to the cache.
     *
     * @param string           $registerSlug Register slug.
     * @param string           $schemaSlug   Schema slug.
     * @param AggregationQuery $query        Query whose content addresses the cache key.
     * @param array            $envelope     Result envelope to cache.
     *
     * @return void
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-2.2
     */
    public function setAdhoc(string $registerSlug, string $schemaSlug, AggregationQuery $query, array $envelope): void
    {
        if ($this->cache === null) {
            return;
        }

        $key = $this->buildAdhocKey(registerSlug: $registerSlug, schemaSlug: $schemaSlug, query: $query);
        $this->cache->set(key: $key, value: $envelope, ttl: self::TTL);
    }//end setAdhoc()

    /**
     * Evict all aggregation cache entries for a (register, schema) pair.
     *
     * Uses ICache::clear() on the shared namespace — a coarse but bounded
     * approach that covers both named and ad-hoc entries without requiring
     * prefix-scan support.  The 60 s TTL bounds residual staleness.
     *
     * No changes to AggregationCacheInvalidationListener are needed because
     * it calls this method on every ObjectCreatedEvent/ObjectUpdatedEvent/
     * ObjectDeletedEvent and the clear() covers ad-hoc entries too.
     *
     * @param string $registerSlug Register slug (logged for diagnostics only).
     * @param string $schemaSlug   Schema slug (logged for diagnostics only).
     *
     * @return void
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-2.4
     */
    public function evictForSchema(string $registerSlug, string $schemaSlug): void
    {
        if ($this->cache === null) {
            return;
        }

        $this->logger->debug(
            message: '[AggregationCache] Evicting all aggregation cache entries',
            context: ['register' => $registerSlug, 'schema' => $schemaSlug]
        );

        $this->cache->clear();
    }//end evictForSchema()

    /**
     * Build the named-aggregation cache key.
     *
     * Format: agg:{register}:{schema}:{name}:{filterHash}:{rbacHash}
     *
     * @param string $registerSlug Register slug.
     * @param string $schemaSlug   Schema slug.
     * @param string $name         Annotation name.
     * @param array  $filter       Filter map.
     *
     * @return string
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-2.2
     */
    private function buildKey(string $registerSlug, string $schemaSlug, string $name, array $filter): string
    {
        $filterHash = sha1(string: json_encode(value: $filter));
        $rbacHash   = $this->rbacHash();

        return sprintf('agg:%s:%s:%s:%s:%s', $registerSlug, $schemaSlug, $name, $filterHash, $rbacHash);
    }//end buildKey()

    /**
     * Build the ad-hoc aggregation cache key.
     *
     * Format: agg:{register}:{schema}:adhoc:{queryHash}:{filterHash}:{rbacHash}
     * The 'adhoc:' literal in the name slot keeps ad-hoc entries visually
     * distinct from named-aggregation entries in cache dumps.
     *
     * @param string           $registerSlug Register slug.
     * @param string           $schemaSlug   Schema slug.
     * @param AggregationQuery $query        Query.
     *
     * @return string
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-2.2
     */
    private function buildAdhocKey(string $registerSlug, string $schemaSlug, AggregationQuery $query): string
    {
        $queryHash  = sha1(string: json_encode(value: $query->toArray()));
        $filterHash = sha1(string: json_encode(value: $query->getFilter()));
        $rbacHash   = $this->rbacHash();
        $name       = self::ADHOC_PREFIX.$queryHash;

        return sprintf('agg:%s:%s:%s:%s:%s', $registerSlug, $schemaSlug, $name, $filterHash, $rbacHash);
    }//end buildAdhocKey()

    /**
     * Derive the RBAC scope hash from the current user session.
     *
     * @return string SHA-1 of the user UID, or SHA-1 of 'anonymous'.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-2.2
     */
    private function rbacHash(): string
    {
        $uid = $this->userSession->getUser()?->getUID() ?? 'anonymous';
        return sha1(string: $uid);
    }//end rbacHash()
}//end class
