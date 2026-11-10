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
 * @spec openspec/specs/aggregations-backend-native/spec.md
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-20
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Aggregation;

use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
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
class AggregationCache implements AggregationCacheInterface {

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
	 * @param ICacheFactory $cacheFactory Factory used to create the distributed cache.
	 * @param IUserSession $userSession Current user session, used to scope the cache key.
	 * @param LoggerInterface $logger Logger for backend-unavailable warnings.
	 * @param OrganisationServiceInterface $organisationService Organisation service, used to include active organisation in cache key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-20
	 */
	public function __construct(
		ICacheFactory $cacheFactory,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly OrganisationServiceInterface $organisationService,
	) {
		$this->cache = $this->openCache(cacheFactory: $cacheFactory);
	}//end __construct()

	/**
	 * The distributed cache, or null when no backend is available.
	 *
	 * @param ICacheFactory $cacheFactory Factory used to create the distributed cache.
	 *
	 * @return ICache|null
	 */
	private function openCache(ICacheFactory $cacheFactory): ?ICache {
		try {
			return $cacheFactory->createDistributed('openregister_aggregations');
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('[AggregationCache] cache backend unavailable: %s', $e->getMessage())
			);
			return null;
		}
	}//end openCache()

	/**
	 * Look up a cached aggregation result.
	 *
	 * Returns the cached associative array (with the same shape
	 * AggregationRunner emits) or null on miss.
	 *
	 * @param AggregationSlot $slot The register, schema, aggregation name and resolved filter (placeholders concrete).
	 *
	 * @return array<string, mixed>|null Cached result or null on miss.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-20
	 */
	public function get(AggregationSlot $slot): ?array {
		return $this->read(slot: $slot);
	}//end get()

	/**
	 * Store an aggregation result.
	 *
	 * @param AggregationSlot      $slot   The register, schema, aggregation name and resolved filter.
	 * @param array<string, mixed> $result Result envelope to store.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/aggregations-backend-native/spec.md
	 */
	public function set(AggregationSlot $slot, array $result): void {
		$this->write(slot: $slot, result: $result);
	}//end set()

	/**
	 * The cached result in a slot, or null on a miss, an unreadable blob or a backend failure.
	 *
	 * @param AggregationSlot $slot The slot to read.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read(AggregationSlot $slot): ?array {
		if ($this->cache === null) {
			return null;
		}

		try {
			$blob = $this->cache->get($this->key(slot: $slot));
		} catch (\Throwable $e) {
			return null;
		}

		return $this->decode(blob: $blob);
	}//end read()

	/**
	 * A cached blob as an array, or null when it is not a JSON object or list.
	 *
	 * @param mixed $blob The raw cache value.
	 *
	 * @return array<string, mixed>|null
	 */
	private function decode(mixed $blob): ?array {
		if (is_string($blob) === false) {
			return null;
		}

		$decoded = json_decode($blob, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return null;
	}//end decode()

	/**
	 * Store a result in a slot for TTL seconds; a backend failure is swallowed.
	 *
	 * @param AggregationSlot      $slot   The slot to write.
	 * @param array<string, mixed> $result The aggregation result.
	 *
	 * @return void
	 */
	private function write(AggregationSlot $slot, array $result): void {
		if ($this->cache === null) {
			return;
		}

		try {
			$this->cache->set($this->key(slot: $slot), json_encode($result), self::TTL);
		} catch (\Throwable $e) {
			// Don't escalate: a cache write failure shouldn't break the response.
			$this->logger->debug(
				message: '[AggregationCache] Cache write failed',
				context: [
					'exception' => $e->getMessage(),
				]
			);
		}
	}//end write()

	/**
	 * Look up a cached ad-hoc aggregation result.
	 *
	 * Mirrors {@see get()} but derives the name slot from the query value
	 * object. The literal `adhoc:` prefix keeps ad-hoc entries visually
	 * distinct from named-aggregation entries in cache dumps.
	 *
	 * @param string $registerSlug Register slug component of the cache key.
	 * @param string $schemaSlug Schema slug component of the cache key.
	 * @param AggregationQuery $query Query value object hashed into the cache key.
	 *
	 * @return array<string, mixed>|null Cached envelope or null on miss.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-20
	 */
	public function getAdhoc(string $registerSlug, string $schemaSlug, AggregationQuery $query): ?array {
		$table = new RegisterSchemaRef(register: $registerSlug, schema: $schemaSlug);
		return $this->read(slot: AggregationSlot::fromAdhocQuery(table: $table, query: $query));
	}//end getAdhoc()

	/**
	 * Store an ad-hoc aggregation result.
	 *
	 * Mirrors {@see set()} for the ad-hoc path. The stored envelope is
	 * rewritten on read (`cached: true`) by callers — see
	 * {@see \OCA\OpenRegister\Service\Aggregation\AggregationRunner::runAdhoc()}.
	 *
	 * @param string $registerSlug Register slug component of the cache key.
	 * @param string $schemaSlug Schema slug component of the cache key.
	 * @param AggregationQuery $query Query value object hashed into the cache key.
	 * @param array<string, mixed> $result Result envelope to store.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/aggregations-backend-native/spec.md
	 */
	public function setAdhoc(string $registerSlug, string $schemaSlug, AggregationQuery $query, array $result): void {
		$table = new RegisterSchemaRef(register: $registerSlug, schema: $schemaSlug);
		$this->write(slot: AggregationSlot::fromAdhocQuery(table: $table, query: $query), result: $result);
	}//end setAdhoc()

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
	 * @param string $schemaSlug Schema slug whose aggregations should be evicted.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/aggregations-backend-native/spec.md
	 */
	public function evictForSchema(string $registerSlug, string $schemaSlug): void {
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
			$current = $this->cache->get($versionKey);
			$next = 1;
			if (is_numeric($current) === true) {
				$next = ((int)$current + 1);
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
	 * @param AggregationSlot $slot Register, schema, aggregation name and resolved filter.
	 *
	 * @return string The cache key string.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-20
	 */
	private function key(AggregationSlot $slot): string {
		$filter = $slot->filter;
		ksort($filter);
		$filterEncoded = json_encode($filter);
		$filterStr = '';
		if ($filterEncoded !== false) {
			$filterStr = $filterEncoded;
		}

		// Fold the per-(register, schema) version into the key so eviction can be
		// scoped by bumping that version (scope-cache-invalidation) rather than
		// wiping the whole aggregation cache: after a bump, every key for the
		// schema carries the new version and old entries become unreachable.
		$version = $this->schemaVersion(registerSlug: $slot->registerSlug, schemaSlug: $slot->schemaSlug);

		return sprintf(
			'agg:%s:%s:v%d:%s:%s:%s',
			$slot->registerSlug,
			$slot->schemaSlug,
			$version,
			$slot->name,
			// NOSEMGREP: php.lang.security.weak-crypto.weak-crypto -- The hash only builds a cache, lock or settings key, not a security value.
			sha1($filterStr),
			$this->rbacScopeHash()
		);
	}//end key()

	/**
	 * Version-counter cache key for a (register, schema) pair.
	 *
	 * @param string $registerSlug Register slug.
	 * @param string $schemaSlug Schema slug.
	 *
	 * @return string
	 */
	private function versionKey(string $registerSlug, string $schemaSlug): string {
		return sprintf('aggver:%s:%s', $registerSlug, $schemaSlug);
	}//end versionKey()

	/**
	 * Current aggregation-cache version for a (register, schema) pair.
	 *
	 * @param string $registerSlug Register slug.
	 * @param string $schemaSlug Schema slug.
	 *
	 * @return int The current version (0 when never evicted).
	 */
	private function schemaVersion(string $registerSlug, string $schemaSlug): int {
		if ($this->cache === null) {
			return 0;
		}

		$current = $this->cache->get($this->versionKey(registerSlug: $registerSlug, schemaSlug: $schemaSlug));
		if (is_numeric($current) === true) {
			return (int)$current;
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
	private function rbacScopeHash(): string {
		$uid = ($this->userSession->getUser()?->getUID() ?? 'anonymous');
		$org = $this->organisationService->getActiveOrganisation();
		$orgId = 'none';
		if ($org !== null) {
			$orgId = $org->getUuid();
		}

		// NOSEMGREP: php.lang.security.weak-crypto.weak-crypto -- The hash only builds a cache, lock or settings key, not a security value.
		return sha1($uid . ':' . $orgId);
	}//end rbacScopeHash()
}//end class
