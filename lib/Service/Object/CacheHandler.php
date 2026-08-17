<?php

/**
 * CacheHandler
 *
 * Service class responsible for caching frequently accessed objects to improve
 * performance by reducing database queries. This service provides:
 * - In-memory caching of ObjectEntity objects
 * - Bulk preloading of relationship objects
 * - Cache warming strategies
 * - Memory-efficient cache management
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\IAppContainer;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IMemcache;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Cache service for ObjectEntity objects to improve performance
 *
 * This service provides efficient caching mechanisms to reduce database queries
 * when dealing with related objects and frequently accessed entities.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/OpenCatalogi/OpenRegister
 * @version   GIT: <git_id>
 * @copyright 2024 Conduction b.v.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Cache operations require many utility methods
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     Public API for comprehensive cache management
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex cache invalidation and warming logic
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Cache handler requires multiple dependencies for comprehensive caching
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 */
class CacheHandler {

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
	 * Maximum cache TTL for name caching (24 hours in seconds)
	 *
	 * UUIDs and names rarely change, so a longer TTL is appropriate.
	 * Cache is invalidated on object update/delete operations anyway.
	 *
	 * @var int
	 */
	private const MAX_CACHE_TTL = 86400;

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
	 * Owning organisation UUID for every entry in $nameCache (SEC-CTRL-2 step 2)
	 *
	 * The name cache is shared by every request served by this process, so the
	 * tenancy of a cached name has to travel WITH the name. Keyed identically to
	 * $nameCache; an identifier missing from this map has an unknown owner and is
	 * therefore never served (fail closed).
	 *
	 * @var array<string, string|null>
	 */
	private array $nameCacheOrganisation = [];

	/**
	 * Memoised name-visibility scope for the current request (SEC-CTRL-2 step 2)
	 *
	 * `null` combined with $nameScopeResolved === true means UNRESTRICTED (multi
	 * tenancy switched off, or an admin with the override enabled). An array is
	 * the list of organisation UUIDs whose names the caller may see; an empty
	 * array means the caller may see none.
	 *
	 * @var array<int, string>|null
	 */
	private ?array $nameScope = null;

	/**
	 * Whether $nameScope has been resolved yet for this request
	 *
	 * @var bool
	 */
	private bool $nameScopeResolved = false;

	/**
	 * Distributed cache for object names
	 *
	 * @var IMemcache|null
	 */
	private ?IMemcache $nameDistributedCache = null;

	/**
	 * Cache hit statistics
	 *
	 * @var array{hits: int, misses: int, preloads: int, query_hits: int,
	 *            query_misses: int, name_hits: int, name_misses: int, name_warmups: int}
	 */
	private array $stats = [
		'hits' => 0,
		'misses' => 0,
		'preloads' => 0,
		'query_hits' => 0,
		'query_misses' => 0,
		'name_hits' => 0,
		'name_misses' => 0,
		'name_warmups' => 0,
	];

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
	 * Container for lazy loading dependencies to break circular dependency
	 *
	 * @var IAppContainer|null
	 */
	private ?IAppContainer $container = null;

	/**
	 * Lazily loaded MagicMapper to break circular dependency
	 *
	 * @var MagicMapper|null
	 */
	private ?MagicMapper $objectMapper = null;

	/**
	 * Constructor for CacheHandler
	 *
	 * @param OrganisationMapper $organisationMapper The organisation entity mapper
	 * @param LoggerInterface $logger Logger for performance monitoring
	 * @param ICacheFactory|null $cacheFactory Cache factory for query result caching
	 * @param IUserSession|null $userSession User session for cache key generation
	 * @param IAppContainer|null $container Container for lazy loading dependencies (optional)
	 * @param RegisterMapper|null $registerMapper Register mapper for magic table queries
	 * @param SchemaMapper|null $schemaMapper Schema mapper for magic table queries
	 * @param IDBConnection|null $db Database connection for magic table queries
	 * @param IGroupManager|null $groupManager Group manager for the admin-override arm of the name scope
	 * @param IAppConfig|null $appConfig App config for the multitenancy switches read by the name scope
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly OrganisationMapper $organisationMapper,
		private readonly LoggerInterface $logger,
		?ICacheFactory $cacheFactory = null,
		?IUserSession $userSession = null,
		?IAppContainer $container = null,
		private readonly ?RegisterMapper $registerMapper = null,
		private readonly ?SchemaMapper $schemaMapper = null,
		private readonly ?IDBConnection $db = null,
		private readonly ?IGroupManager $groupManager = null,
		private readonly ?IAppConfig $appConfig = null,
	) {
		// Initialize query cache if available.
		if ($cacheFactory !== null) {
			try {
				$this->queryCache = $cacheFactory->createDistributed('openregister_query_results');
				$this->nameDistributedCache = $cacheFactory->createDistributed('openregister_object_names');
			} catch (\Exception $e) {
				$this->logger->warning(
					message: '[CacheHandler] Failed to initialize distributed caches',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
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
			 *
			 * @spec openspec/specs/object-lifecycle/spec.md
			 */
			public function getUser() {
				return null;
			}//end getUser()
		};
		$this->container = $container;
	}//end __construct()

	/**
	 * Get the MagicMapper lazily to break circular dependency.
	 *
	 * @return MagicMapper The unified object mapper.
	 *
	 * @throws RuntimeException When container is not available.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function getObjectMapper(): MagicMapper {
		if ($this->objectMapper === null) {
			if ($this->container === null) {
				throw new RuntimeException('[CacheHandler] Container required for lazy loading MagicMapper');
			}

			$this->objectMapper = $this->container->get(MagicMapper::class);
		}

		return $this->objectMapper;
	}//end getObjectMapper()

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
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getObject(int|string $identifier): ?ObjectEntity {
		// BUG-OBJ-6: the in-memory object cache is keyed per active-organisation so a
		// cached entity loaded under one tenant's context can never be served to a
		// different tenant within the same request lifecycle. The underlying find()
		// already applies RBAC + multitenancy on a cache miss; the tenant-scoped key
		// closes the cache-hit bypass.
		$key = $this->buildObjectCacheKey(rawKey: (string)$identifier);

		// Check cache first.
		if (($this->objectCache[$key] ?? null) !== null) {
			$this->stats['hits']++;
			return $this->objectCache[$key];
		}

		// Cache miss - load from database (RBAC + multitenancy enforced by find()).
		$this->stats['misses']++;

		try {
			$object = $this->getObjectMapper()->find($identifier);

			// Cache the object with both ID and UUID as keys.
			$this->cacheObject(object: $object);

			return $object;
		} catch (\Exception $e) {
			return null;
		}
	}//end getObject()

	/**
	 * Build a tenant-scoped key for the in-memory object cache (BUG-OBJ-6).
	 *
	 * Prefixes the raw id/uuid with the caller's active organisation so a cached
	 * entity is never shared across tenants. Falls back to a stable sentinel when
	 * no active organisation can be resolved (anonymous / system context).
	 *
	 * @param string $rawKey The raw cache key (object id or uuid)
	 *
	 * @return string The tenant-scoped cache key
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function buildObjectCacheKey(string $rawKey): string {
		return $this->getActiveOrganisationCacheScope() . '|' . $rawKey;
	}//end buildObjectCacheKey()

	/**
	 * Resolve the active-organisation discriminator for object-cache keys (BUG-OBJ-6).
	 *
	 * @return string The active organisation UUID, or a sentinel for no/unknown org
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function getActiveOrganisationCacheScope(): string {
		try {
			$user = $this->userSession?->getUser();
			if ($user === null) {
				return '__no_org__';
			}

			$organisation = $this->organisationMapper->getActiveOrganisationWithFallback($user->getUID());
			if ($organisation === null || $organisation === '') {
				return '__no_org__';
			}

			return $organisation;
		} catch (\Throwable $e) {
			// Never let cache-key resolution abort a read; isolate on a safe sentinel.
			return '__no_org__';
		}
	}//end getActiveOrganisationCacheScope()

	/**
	 * Drop the memoised name scope so the next lookup re-reads the caller.
	 *
	 * Called at the top of every public name reader. The scope is memoised for
	 * the duration of ONE call (a bulk lookup checks it once per name and would
	 * otherwise walk the organisation hierarchy thousands of times), but it must
	 * never survive between calls: a cron worker or a long-lived process serves
	 * more than one caller, and a scope carried over is the same cross-tenant
	 * reuse this change exists to close.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function beginNameScope(): void {
		$this->nameScopeResolved = false;
		$this->nameScope = null;
	}//end beginNameScope()

	/**
	 * Resolve which organisations' names the current caller may see (SEC-CTRL-2 step 2).
	 *
	 * Mirrors Db\MultiTenancyTrait::applyOrganisationFilter() so a name is never
	 * resolvable for an object the caller could not have read in the first place:
	 *
	 * - multitenancy switched off in config  -> unrestricted (null)
	 * - admin with the admin override on     -> unrestricted (null)
	 * - otherwise                            -> the active organisation plus its
	 *                                           parents, exactly like the mappers
	 * - no active organisation at all        -> [] (the trait's `1 = 0` arm)
	 *
	 * Memoised for the duration of one public call; see beginNameScope().
	 *
	 * @return array<int, string>|null Allowed organisation UUIDs, or null for unrestricted
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function resolveNameScope(): ?array {
		if ($this->nameScopeResolved === true) {
			return $this->nameScope;
		}

		$this->nameScopeResolved = true;
		$this->nameScope = [];

		try {
			if ($this->isMultiTenancyDisabled() === true || $this->isAdminOverrideActive() === true) {
				$this->nameScope = null;
				return $this->nameScope;
			}

			$activeOrganisation = $this->resolveActiveOrganisationUuid();
			if ($activeOrganisation === null || $activeOrganisation === '') {
				return $this->nameScope;
			}

			$hierarchy = [$activeOrganisation];
			try {
				$resolved = $this->organisationMapper->getOrganisationHierarchy($activeOrganisation);
				if (empty($resolved) === false) {
					$hierarchy = $resolved;
				}
			} catch (\Throwable $e) {
				// Hierarchy unavailable: fall back to the active organisation alone,
				// which is the narrower (fail-closed) answer.
				$this->logger->debug(
					message: '[CacheHandler] Organisation hierarchy unavailable for name scope',
					context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
				);
			}

			$this->nameScope = array_values(
				array_filter(
					array_map(fn ($uuid) => (string)$uuid, $hierarchy),
					fn (string $uuid) => $uuid !== ''
				)
			);
		} catch (\Throwable $e) {
			// Never let scope resolution grant more than it should: an error means
			// no organisation could be established, so nothing is visible.
			$this->logger->warning(
				message: '[CacheHandler] Name scope could not be resolved; denying all cached names',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			$this->nameScope = [];
		}//end try

		return $this->nameScope;
	}//end resolveNameScope()

	/**
	 * Whether multitenancy filtering is switched off instance-wide.
	 *
	 * @return bool True when the multitenancy config explicitly disables filtering
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function isMultiTenancyDisabled(): bool {
		if ($this->appConfig === null) {
			return false;
		}

		$raw = $this->appConfig->getValueString('openregister', 'multitenancy', '');
		if ($raw === '') {
			return false;
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return false;
		}

		return ($decoded['enabled'] ?? true) === false;
	}//end isMultiTenancyDisabled()

	/**
	 * Whether the caller is an admin acting under an enabled cross-tenant override.
	 *
	 * SaaS mode always wins and disables the override, mirroring MultiTenancyTrait.
	 *
	 * @return bool True when the caller may read names across organisations
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function isAdminOverrideActive(): bool {
		if ($this->appConfig === null || $this->groupManager === null) {
			return false;
		}

		$user = $this->userSession?->getUser();
		if ($user === null) {
			return false;
		}

		$raw = $this->appConfig->getValueString('openregister', 'multitenancy', '');
		if ($raw === '') {
			return false;
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return false;
		}

		if (($decoded['saasMode'] ?? false) === true) {
			return false;
		}

		if (($decoded['adminOverride'] ?? false) !== true) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}//end isAdminOverrideActive()

	/**
	 * Resolve the caller's active organisation UUID for name scoping.
	 *
	 * @return string|null The active organisation UUID, or null when none applies
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function resolveActiveOrganisationUuid(): ?string {
		$user = $this->userSession?->getUser();
		if ($user === null) {
			return $this->organisationMapper->getDefaultOrganisationFromConfig();
		}

		return $this->organisationMapper->getActiveOrganisationWithFallback($user->getUID());
	}//end resolveActiveOrganisationUuid()

	/**
	 * Per-object authorisation predicate for name resolution (SEC-CTRL-2 step 2).
	 *
	 * Answers "may THIS caller be told the name of an entity owned by THIS
	 * organisation?". An entity whose owning organisation is unknown is refused:
	 * absence of tenancy is not permission to disclose.
	 *
	 * @param string|null $organisation The owning organisation UUID of the entity
	 *
	 * @return bool True when the caller may see a name owned by that organisation
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function hasOrganisationAccess(?string $organisation): bool {
		$scope = $this->resolveNameScope();
		if ($scope === null) {
			return true;
		}

		if ($organisation === null || $organisation === '') {
			return false;
		}

		return in_array($organisation, $scope, true);
	}//end hasOrganisationAccess()

	/**
	 * Record the owning organisation of a cached name (SEC-CTRL-2 step 2).
	 *
	 * @param string      $key          The name-cache key (object id or uuid)
	 * @param string|null $organisation The owning organisation UUID, or null when unknown
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function rememberNameOrganisation(string $key, ?string $organisation): void {
		$this->nameCacheOrganisation[$key] = $organisation;
	}//end rememberNameOrganisation()

	/**
	 * Wrap a name and its owning organisation for the distributed cache.
	 *
	 * The distributed name cache is shared by every tenant on the instance, so the
	 * tenancy has to be stored WITH the value. Keeping the KEY unchanged is
	 * deliberate: every existing invalidation site removes `name_<identifier>` and
	 * keeps working untouched, and a name can never be orphaned under a stale
	 * organisation prefix when an object moves tenant.
	 *
	 * @param string      $name         The cached name
	 * @param string|null $organisation The owning organisation UUID
	 *
	 * @return array{n: string, o: string|null} The envelope stored in the distributed cache
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function buildNameEnvelope(string $name, ?string $organisation): array {
		return ['n' => $name, 'o' => $organisation];
	}//end buildNameEnvelope()

	/**
	 * Read a distributed-cache entry as a tenancy-bearing envelope.
	 *
	 * A value written before this change is a bare string with no tenancy, so it
	 * is reported as a MISS rather than served unscoped — the fail-closed
	 * direction across a deploy.
	 *
	 * @param mixed $cached The raw value read from the distributed cache
	 *
	 * @return array{n: string, o: string|null}|null The envelope, or null when unusable
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function readNameEnvelope(mixed $cached): ?array {
		if (is_array($cached) === false || array_key_exists('n', $cached) === false) {
			return null;
		}

		if (is_string($cached['n']) === false) {
			return null;
		}

		$organisation = $cached['o'] ?? null;
		if ($organisation !== null && is_string($organisation) === false) {
			return null;
		}

		return ['n' => $cached['n'], 'o' => $organisation];
	}//end readNameEnvelope()

	/**
	 * Bulk preload objects to warm the cache
	 *
	 * This method loads multiple objects in a single database query and caches them
	 * all, significantly improving performance for operations that access many objects.
	 *
	 * @param array $identifiers Array of object IDs/UUIDs to preload
	 *
	 * @return ObjectEntity[]
	 *
	 * @phpstan-param array<int|string> $identifiers
	 *
	 * @phpstan-return array<ObjectEntity>
	 *
	 * @psalm-param array<int|string> $identifiers
	 *
	 * @psalm-return array<ObjectEntity>
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function preloadObjects(array $identifiers): array {
		if (empty($identifiers) === true) {
			return [];
		}

		// Filter out already cached objects (BUG-OBJ-6: tenant-scoped keys).
		$identifiersToLoad = array_filter(
			array_unique($identifiers),
			fn ($id) => isset($this->objectCache[$this->buildObjectCacheKey(rawKey: (string)$id)]) === false
		);

		if (empty($identifiersToLoad) === true) {
			// All objects already cached.
			return array_filter(
				array_map(
					fn ($id) => $this->objectCache[$this->buildObjectCacheKey(rawKey: (string)$id)] ?? null,
					$identifiers
				),
				fn ($obj) => $obj !== null
			);
		}

		// Bulk load from database.
		try {
			$objects = $this->getObjectMapper()->findMultiple($identifiersToLoad);

			// Cache all loaded objects.
			foreach ($objects as $object) {
				$this->cacheObject(object: $object);
			}

			$this->stats['preloads'] += count($objects);

			return $objects;
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[CacheHandler] Bulk preload failed in CacheHandler',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'exception' => $e->getMessage(),
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
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function cacheObject(ObjectEntity $object): void {
		// Check cache size and evict oldest entries if necessary.
		// PERF-9: unset the oldest keys in a tight loop instead of rebuilding the whole
		// cache with array_slice() (which reallocates the entire array on every insert
		// once the cap is reached). PHP arrays preserve insertion order, so the leading
		// keys returned by array_keys() are the oldest.
		if (count($this->objectCache) >= $this->maxCacheSize) {
			$entriesToRemove = (int)($this->maxCacheSize * 0.2);
			if ($entriesToRemove < 1) {
				$entriesToRemove = 1;
			}

			$oldestKeys = array_slice(array_keys($this->objectCache), 0, $entriesToRemove);
			foreach ($oldestKeys as $oldestKey) {
				unset($this->objectCache[$oldestKey]);
			}
		}

		// Cache with ID (BUG-OBJ-6: tenant-scoped key).
		$this->objectCache[$this->buildObjectCacheKey(rawKey: (string)$object->getId())] = $object;

		// Also cache with UUID if available.
		if (($object->getUuid() !== null) === true) {
			$this->objectCache[$this->buildObjectCacheKey(rawKey: (string)$object->getUuid())] = $object;
		}
	}//end cacheObject()

	/**
	 * Get cache statistics
	 *
	 * Returns information about cache performance for monitoring and optimization.
	 *
	 * @return (float|int)[]
	 *
	 * @phpstan-return array{hits: int, misses: int, preloads: int,
	 *     query_hits: int, query_misses: int, name_hits: int, name_misses: int,
	 *     name_warmups: int, hit_rate: float, query_hit_rate: float,
	 *     name_hit_rate: float, cache_size: int, query_cache_size: int,
	 *     name_cache_size: int}
	 *
	 * @psalm-return array{hits: int, misses: int, preloads: int,
	 *     query_hits: int, query_misses: int, name_hits: int,
	 *     name_misses: int, name_warmups: int, hit_rate: float,
	 *     query_hit_rate: float, name_hit_rate: float,
	 *     cache_size: int<0, max>, query_cache_size: int<0, max>,
	 *     name_cache_size: int<0, max>}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getStats(): array {
		$totalRequests = $this->stats['hits'] + $this->stats['misses'];
		$hitRate = 0;
		if ($totalRequests > 0) {
			$hitRate = ($this->stats['hits'] / $totalRequests) * 100;
		}

		$totalQueryRequests = $this->stats['query_hits'] + $this->stats['query_misses'];
		$queryHitRate = 0;
		if ($totalQueryRequests > 0) {
			$queryHitRate = ($this->stats['query_hits'] / $totalQueryRequests) * 100;
		}

		$totalNameRequests = $this->stats['name_hits'] + $this->stats['name_misses'];
		$nameHitRate = 0;
		if ($totalNameRequests > 0) {
			$nameHitRate = ($this->stats['name_hits'] / $totalNameRequests) * 100;
		}

		// Get distributed cache count (persists across requests).
		$distNameCacheCount = $this->getDistributedNameCacheCount();

		return array_merge(
			$this->stats,
			[
				'hit_rate' => round($hitRate, 2),
				'query_hit_rate' => round($queryHitRate, 2),
				'name_hit_rate' => round($nameHitRate, 2),
				'cache_size' => count($this->objectCache),
				'query_cache_size' => count($this->inMemoryQueryCache),
				'name_cache_size' => count($this->nameCache),
				'distributed_name_cache_size' => $distNameCacheCount,
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
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function clearSearchCache(?string $pattern = null): void {
		// Clear in-memory cache.
		if ($pattern !== null) {
			$this->inMemoryQueryCache = array_filter(
				$this->inMemoryQueryCache,
				function ($key) use ($pattern) {
					return strpos($key, $pattern) === false;
				},
				ARRAY_FILTER_USE_KEY
			);
		}

		if ($pattern === null) {
			$this->inMemoryQueryCache = [];
		}

		// Clear distributed cache if available.
		if ($this->queryCache !== null) {
			try {
				// For targeted clearing, we'd need a more sophisticated approach.
				// For now, clear all to ensure consistency.
				$this->queryCache->clear();
			} catch (\Exception $e) {
				$this->logger->warning(
					message: '[CacheHandler] Failed to clear search cache',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'error' => $e->getMessage(),
						'pattern' => $pattern,
					]
				);
			}
		}

		$this->logger->debug(
			message: '[CacheHandler] 🧹 SEARCH CACHE CLEARED',
			context: ['file' => __FILE__, 'line' => __LINE__, 'pattern' => $pattern ?? 'all']
		);
	}//end clearSearchCache()

	/**
	 * Clear all search caches related to a specific schema (across all users)
	 *
	 * **SCHEMA-WIDE INVALIDATION**: When objects in a schema change, we need to clear
	 * all cached search results that could include objects from that schema.
	 * This ensures colleagues see each other's changes immediately.
	 *
	 * @param int|null $schemaId Schema ID to invalidate
	 * @param int|null $registerId Register ID for additional context
	 * @param string $operation Operation performed ('create', 'update', 'delete')
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function clearSchemaRelatedCaches(?int $schemaId = null, ?int $registerId = null, string $operation = 'unknown'): void {
		$startTime = microtime(true);

		// **STRATEGY 1**: Clear all in-memory search caches (fast).
		$this->inMemoryQueryCache = [];

		// **STRATEGY 2**: Clear distributed cache entries that could contain objects from this schema.
		if ($this->queryCache !== null && $schemaId !== null) {
			try {
				// Since we can't easily pattern-match keys in distributed cache,.
				// We clear all search cache entries for now (nuclear approach).
				// TODO: Implement more targeted cache clearing with schema-specific prefixes.
				$this->queryCache->clear();

				$this->logger->debug(
					message: '[CacheHandler] Schema-related distributed caches cleared',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'schemaId' => $schemaId,
						'registerId' => $registerId,
						'operation' => $operation,
						'strategy' => 'nuclear_clear',
					]
				);
			} catch (\Exception $e) {
				$this->logger->warning(
					message: '[CacheHandler] Failed to clear schema-related distributed caches',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'schemaId' => $schemaId,
						'error' => $e->getMessage(),
					]
				);
			}//end try
		}//end if

		if ($schemaId === null) {
			// Fallback: clear all search caches if no specific schema.
			$this->clearSearchCache();
		}//end if

		$executionTime = round((microtime(true) - $startTime) * 1000, 2);

		// Determine strategy for logging.
		$strategy = 'global_fallback';
		if ($schemaId !== null) {
			$strategy = 'schema_targeted';
		}

		$this->logger->info(
			message: '[CacheHandler] Schema-related caches cleared for CUD operation',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'schemaId' => $schemaId,
				'registerId' => $registerId,
				'operation' => $operation,
				'executionTime' => $executionTime . 'ms',
				'impact' => 'all_users_affected',
				'strategy' => $strategy,
			]
		);
	}//end clearSchemaRelatedCaches()

	/**
	 * Invalidate caches when objects are modified (CRUD operations)
	 *
	 * **MAIN CACHE INVALIDATION METHOD**: Called when objects are created,
	 * updated, or deleted to ensure cache consistency across the application.
	 *
	 * @param ObjectEntity|null $object The object that was modified (null for bulk operations)
	 * @param string $operation The operation performed (create/update/delete)
	 * @param int|null $registerId Register ID for targeted invalidation
	 * @param int|null $schemaId Schema ID for targeted invalidation
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function invalidateForObjectChange(
		?ObjectEntity $object = null,
		string $operation = 'unknown',
		?int $registerId = null,
		?int $schemaId = null,
	): void {
		$startTime = microtime(true);

		// Extract context from object if provided.
		if ($object !== null) {
			// Extract register ID if not provided.
			if ($registerId === null && $object->getRegister() !== null) {
				$registerId = (int)$object->getRegister();
			}

			// Extract schema ID if not provided.
			if ($schemaId === null && $object->getSchema() !== null) {
				$schemaId = (int)$object->getSchema();
			}

			// Clear individual object from cache.
			$this->clearObjectFromCache(object: $object);

			// BUG-OBJ-7: drop any stale cached name for this object up-front, regardless
			// of the operation label. The create/update branch below re-writes the fresh
			// name; for any other (or unknown) operation the stale name is at least
			// invalidated so a rename can never serve a stale value from cache.
			$this->clearObjectNameFromCache(object: $object);

			if ($operation === 'create' || $operation === 'update') {
				// Update name cache for the modified object, carrying its tenancy
				// so the refreshed entry is only served back to that organisation.
				$name = $object->getName() ?? $object->getUuid();
				$organisation = $object->getOrganisation();
				$this->setObjectName(
					identifier: $object->getUuid(),
					name: $name,
					organisation: $organisation
				);
				if (($object->getId() !== null) === true && (string)$object->getId() !== $object->getUuid()) {
					$this->setObjectName(
						identifier: $object->getId(),
						name: $name,
						organisation: $organisation
					);
				}
			} elseif ($operation === 'delete') {
				// Remove from in-memory name cache (name AND its recorded tenancy).
				unset($this->nameCache[$object->getUuid()]);
				unset($this->nameCache[(string)$object->getId()]);
				unset($this->nameCacheOrganisation[$object->getUuid()]);
				unset($this->nameCacheOrganisation[(string)$object->getId()]);

				// Remove from distributed name cache.
				if ($this->nameDistributedCache !== null) {
					try {
						$this->nameDistributedCache->remove('name_' . $object->getUuid());
						if ($object->getId() !== null) {
							$this->nameDistributedCache->remove('name_' . $object->getId());
						}
					} catch (\Exception $e) {
						$this->logger->warning(
							message: '[CacheHandler] Failed to remove object name from distributed cache',
							context: [
								'file' => __FILE__,
								'line' => __LINE__,
								'uuid' => $object->getUuid(),
								'error' => $e->getMessage(),
							]
						);
					}
				}
			}//end if
		}//end if

		// **SCHEMA-WIDE INVALIDATION**: Clear ALL search caches for this schema.
		// This ensures colleagues see each other's changes immediately.
		// SchemaId and registerId are already typed as ?int, so no conversion needed.
		$schemaIdInt = $schemaId;
		$registerIdInt = $registerId;

		$this->clearSchemaRelatedCaches(schemaId: $schemaIdInt, registerId: $registerIdInt, operation: $operation);

		$executionTime = round((microtime(true) - $startTime) * 1000, 2);

		$this->logger->info(
			message: '[CacheHandler] Schema-wide cache invalidated for CRUD operation',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'operation' => $operation,
				'registerId' => $registerId,
				'schemaId' => $schemaId,
				'objectId' => $object?->getId(),
				'executionTime' => $executionTime . 'ms',
				'scope' => 'all_users_in_schema',
			]
		);
	}//end invalidateForObjectChange()

	/**
	 * Clear specific object from cache by ID/UUID
	 *
	 * @param ObjectEntity $object The object to remove from cache
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function clearObjectFromCache(ObjectEntity $object): void {
		// BUG-OBJ-6: object-cache keys are tenant-scoped ("<org>|<rawKey>"). The active
		// organisation at invalidation time may differ from the one in effect when the
		// entry was written, so remove every scope's entry for this id/uuid by matching
		// the raw-key suffix rather than a single scoped key.
		$rawKeys = [(string)$object->getId()];
		if (($object->getUuid() !== null) === true) {
			$rawKeys[] = (string)$object->getUuid();
		}

		foreach ($rawKeys as $rawKey) {
			$suffix = '|' . $rawKey;
			foreach (array_keys($this->objectCache) as $cacheKey) {
				if ($cacheKey === $rawKey || str_ends_with((string)$cacheKey, $suffix) === true) {
					unset($this->objectCache[$cacheKey]);
				}
			}
		}

		$this->logger->debug(
			message: '[CacheHandler] Individual object cleared from cache',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'objectId' => $object->getId(),
				'objectUuid' => $object->getUuid(),
			]
		);
	}//end clearObjectFromCache()

	/**
	 * Remove an object's cached name entries (in-memory + distributed) — BUG-OBJ-7.
	 *
	 * Drops the name cached under both the object's UUID and id so a subsequent
	 * read recomputes the current name (fixing stale names after a rename).
	 *
	 * @param ObjectEntity $object The object whose cached name must be cleared
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function clearObjectNameFromCache(ObjectEntity $object): void {
		$keys = [];
		if ($object->getUuid() !== null) {
			$keys[] = (string)$object->getUuid();
		}

		if ($object->getId() !== null) {
			$keys[] = (string)$object->getId();
		}

		foreach ($keys as $key) {
			unset($this->nameCache[$key]);
			unset($this->nameCacheOrganisation[$key]);

			if ($this->nameDistributedCache !== null) {
				try {
					$this->nameDistributedCache->remove('name_' . $key);
				} catch (\Exception $e) {
					$this->logger->warning(
						message: '[CacheHandler] Failed to clear object name from distributed cache',
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'identifier' => $key,
							'error' => $e->getMessage(),
						]
					);
				}
			}
		}//end foreach
	}//end clearObjectNameFromCache()

	/**
	 * Clear all caches (Administrative Operation)
	 *
	 * **NUCLEAR OPTION**: Removes all cached objects, search results, name caches, and resets statistics.
	 * Use sparingly - typically for administrative operations or major system changes.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function clearAllCaches(): void {
		$startTime = microtime(true);

		$this->objectCache = [];
		$this->inMemoryQueryCache = [];
		$this->nameCache = [];
		$this->nameCacheOrganisation = [];
		$this->stats = [
			'hits' => 0,
			'misses' => 0,
			'preloads' => 0,
			'query_hits' => 0,
			'query_misses' => 0,
			'name_hits' => 0,
			'name_misses' => 0,
			'name_warmups' => 0,
		];

		// Clear distributed query cache.
		if ($this->queryCache !== null) {
			try {
				$this->queryCache->clear();
			} catch (\Exception $e) {
				$this->logger->warning(
					message: '[CacheHandler] Failed to clear distributed query cache',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
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
					message: '[CacheHandler] Failed to clear distributed name cache',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'error' => $e->getMessage(),
					]
				);
			}
		}

		$executionTime = round((microtime(true) - $startTime) * 1000, 2);

		$this->logger->info(
			message: '[CacheHandler] All object caches cleared (including name cache)',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'executionTime' => $executionTime . 'ms',
			]
		);
	}//end clearAllCaches()

	/**
	 * Clear the cache (legacy method - kept for backward compatibility)
	 *
	 * @deprecated Use clearAllCaches() instead
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function clearCache(): void {
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
	 * @param string $name Object name to cache
	 * @param int $ttl Cache TTL in seconds (default: 24 hours)
	 * @param string|null $organisation Owning organisation UUID (SEC-CTRL-2 step 2)
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function setObjectName(string|int $identifier, string $name, int $ttl = 86400, ?string $organisation = null): void {
		$key = (string)$identifier;

		// Enforce maximum cache TTL.
		$ttl = min($ttl, self::MAX_CACHE_TTL);

		// Store in in-memory cache, together with the tenancy of the entity the
		// name belongs to (SEC-CTRL-2 step 2). Without the second map the cache
		// is a cross-tenant disclosure channel of its own.
		$this->nameCache[$key] = $name;
		$this->rememberNameOrganisation(key: $key, organisation: $organisation);

		// Store in distributed cache if available.
		if ($this->nameDistributedCache !== null) {
			try {
				$this->nameDistributedCache->set(
					'name_' . $key,
					$this->buildNameEnvelope(name: $name, organisation: $organisation),
					$ttl
				);
			} catch (\Exception $e) {
				$this->logger->warning(
					message: '[CacheHandler] Failed to cache object name in distributed cache',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'identifier' => $key,
						'error' => $e->getMessage(),
					]
				);
			}
		}

		$this->logger->debug(
			message: '[CacheHandler] 💾 OBJECT NAME CACHED',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'identifier' => $key,
				'name' => $name,
				'ttl' => $ttl . 's',
			]
		);
	}//end setObjectName()

	/**
	 * Get single object name from cache or database
	 *
	 * Provides ultra-fast name lookup for frontend rendering.
	 * Falls back to database if not cached.
	 *
	 * ⚠️ THE DATABASE FALLBACK BELOW IS STILL UNSCOPED AT THE QUERY LEVEL: it calls
	 * `findAcrossAllSources(..., _rbac: false, _multitenancy: false)` and tries
	 * organisations first, so the LOOKUP crosses every register, schema and tenant.
	 * What SEC-CTRL-2 step 2 adds is the answer: whatever the lookup finds, the
	 * name is only returned when the entity's owning organisation is inside the
	 * caller's active-organisation scope — and the same check gates every cache
	 * hit, so a name warmed by one tenant is never served to another.
	 *
	 * It used to back `GET /api/names/{id}`, which was `#[PublicPage]` — any
	 * anonymous caller holding a UUID could read that object's name across tenant
	 * boundaries. That endpoint was removed (SEC-CTRL-2, gate-7); this method
	 * survives only as an internal cache primitive and has no caller in `lib/`.
	 *
	 * A new caller still gets a per-object read-permission check for free ONLY on
	 * the organisation dimension. RBAC (register/schema authorization blocks) is
	 * NOT evaluated here; if you need it, resolve through ObjectService.
	 *
	 * @param string|int $identifier Object ID or UUID
	 *
	 * @return string|null Object name or null if not found
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getSingleObjectName(string|int $identifier): ?string {
		$this->beginNameScope();
		$key = (string)$identifier;

		// Check in-memory cache first (fastest). SEC-CTRL-2 step 2: a cached name
		// is only served to a caller whose organisation may see the entity it
		// belongs to; an entry with no recorded tenancy is refused.
		if (($this->nameCache[$key] ?? null) !== null) {
			if ($this->hasOrganisationAccess($this->nameCacheOrganisation[$key] ?? null) === false) {
				return null;
			}

			$this->stats['name_hits']++;
			$this->logger->debug(
				message: '[CacheHandler] 🚀 NAME CACHE HIT (in-memory)',
				context: ['file' => __FILE__, 'line' => __LINE__, 'identifier' => $key]
			);
			return $this->nameCache[$key];
		}

		// Check distributed cache.
		if ($this->nameDistributedCache !== null) {
			try {
				$envelope = $this->readNameEnvelope($this->nameDistributedCache->get('name_' . $key));
				if ($envelope !== null) {
					if ($this->hasOrganisationAccess($envelope['o']) === false) {
						return null;
					}

					// Store in in-memory cache for faster future access.
					$this->nameCache[$key] = $envelope['n'];
					$this->rememberNameOrganisation(key: $key, organisation: $envelope['o']);
					$this->stats['name_hits']++;
					$this->logger->debug(
						message: '[CacheHandler] ⚡ NAME CACHE HIT (distributed)',
						context: ['file' => __FILE__, 'line' => __LINE__, 'identifier' => $key]
					);
					return $envelope['n'];
				}
			} catch (\Exception $e) {
				$this->logger->warning(
					message: '[CacheHandler] Failed to get object name from distributed cache',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'identifier' => $key,
						'error' => $e->getMessage(),
					]
				);
			}//end try
		}//end if

		// Cache miss - load from database.
		$this->stats['name_misses']++;
		$this->logger->debug(
			message: '[CacheHandler] NAME CACHE MISS',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'identifier' => $key,
			]
		);

		try {
			// STEP 1: Try to find as organisation first (they take priority).
			try {
				$organisation = $this->organisationMapper->findByUuid((string)$identifier);
				if ($organisation !== null) {
					$name = $organisation->getName() ?? $organisation->getUuid();
					// An organisation's own tenancy is itself. A row with neither a
					// name nor a uuid has nothing to cache — setObjectName() takes a
					// non-nullable string, so guard rather than fatal.
					if ($name !== null) {
						$this->setObjectName(
							identifier: $identifier,
							name: $name,
							organisation: $organisation->getUuid()
						);
					}

					if ($this->hasOrganisationAccess($organisation->getUuid()) === false) {
						return null;
					}

					return $name;
				}
			} catch (\Exception $e) {
				// Organisation not found, continue to objects.
			}

			// STEP 2: Try to find as object using unified interface (searches across all magic tables).
			$result = $this->getObjectMapper()->findAcrossAllSources(
				identifier: $identifier,
				includeDeleted: false,
				_rbac: false,
				_multitenancy: false
			);
			if (($result['object'] ?? null) !== null) {
				$object = $result['object'];
				$name = $object->getName() ?? $object->getUuid();
				$objectOrganisation = $object->getOrganisation();
				if ($name !== null) {
					$this->setObjectName(
						identifier: $identifier,
						name: $name,
						organisation: $objectOrganisation
					);
				}

				if ($this->hasOrganisationAccess($objectOrganisation) === false) {
					return null;
				}

				return $name;
			}
		} catch (\Exception $e) {
			$this->logger->debug(
				message: '[CacheHandler] Failed to load entity for name lookup',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'identifier' => $key,
					'error' => $e->getMessage(),
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
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 * Bulk name retrieval with multiple cache layers requires extensive handling.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getMultipleObjectNames(array $identifiers): array {
		if (empty($identifiers) === true) {
			return [];
		}

		$this->beginNameScope();

		$results = [];
		$missingIdentifiers = [];

		// Check in-memory cache for all identifiers. SEC-CTRL-2 step 2: a cached
		// name is only served when the caller's organisation may see the entity it
		// belongs to. An entry whose tenancy was never recorded is treated as a
		// MISS (not as a denial) so the database can establish it.
		$unrestricted = ($this->resolveNameScope() === null);
		foreach ($identifiers as $identifier) {
			$key = (string)$identifier;
			$cachedOrganisation = $this->nameCacheOrganisation[$key] ?? null;
			if (($this->nameCache[$key] ?? null) !== null
				&& ($cachedOrganisation !== null || $unrestricted === true)
			) {
				if ($this->hasOrganisationAccess($cachedOrganisation) === true) {
					$results[$key] = $this->nameCache[$key];
					$this->stats['name_hits']++;
				}

				continue;
			}

			$missingIdentifiers[] = $key;
		}

		// Check distributed cache for missing identifiers.
		if (empty($missingIdentifiers) === false && $this->nameDistributedCache !== null) {
			$decided = [];
			foreach ($missingIdentifiers as $key) {
				try {
					$envelope = $this->readNameEnvelope($this->nameDistributedCache->get('name_' . $key));
					if ($envelope === null) {
						continue;
					}

					// A stored entry with no tenancy settles nothing while scoping is
					// in force: fall through to the database, which can establish it.
					if ($envelope['o'] === null && $unrestricted === false) {
						continue;
					}

					// Store in memory together with its tenancy.
					$this->nameCache[$key] = $envelope['n'];
					$this->rememberNameOrganisation(key: $key, organisation: $envelope['o']);
					$decided[] = $key;

					if ($this->hasOrganisationAccess($envelope['o']) === true) {
						$results[$key] = $envelope['n'];
						$this->stats['name_hits']++;
					}
				} catch (\Exception $e) {
					// Continue processing other identifiers.
				}//end try
			}

			$missingIdentifiers = array_diff($missingIdentifiers, $decided);
		}//end if

		// Load remaining missing names from database.
		if (empty($missingIdentifiers) === false) {
			$this->stats['name_misses'] += count($missingIdentifiers);

			try {
				// STEP 1: Try to find organisations first (they take priority).
				// An organisation's own tenancy is itself.
				$organisations = $this->organisationMapper->findMultipleByUuid($missingIdentifiers);
				foreach ($organisations as $organisation) {
					$name = $organisation->getName() ?? $organisation->getUuid();
					$key = $organisation->getUuid();

					// Cache for future use (UUID only).
					$this->setObjectName(identifier: $key, name: $name, organisation: $key);

					if ($this->hasOrganisationAccess($key) === true) {
						$results[$key] = $name;
					}

					// Remove from missing list since we found it.
					$missingIdentifiers = array_diff($missingIdentifiers, [$key]);
				}

				// STEP 2: Try to find remaining identifiers as objects across magic tables.
				if (empty($missingIdentifiers) === false) {
					$objects = $this->getObjectMapper()->findMultiple($missingIdentifiers);
					foreach ($objects as $object) {
						$name = $object->getName() ?? $object->getUuid();
						$uuid = $object->getUuid();
						$objectOrganisation = $object->getOrganisation();

						// Cache with UUID for future lookups.
						$this->setObjectName(
							identifier: $uuid,
							name: $name,
							organisation: $objectOrganisation
						);

						// Also cache with original identifier if it differs from UUID.
						// Find the original identifier that matched this object.
						foreach ($missingIdentifiers as $originalId) {
							if ((string)$originalId === $uuid
								|| (string)$originalId === (string)$object->getId()
								|| (string)$originalId === $object->getSlug()
								|| (string)$originalId === $object->getUri()
							) {
								if ((string)$originalId !== $uuid) {
									$this->setObjectName(
										identifier: $originalId,
										name: $name,
										organisation: $objectOrganisation
									);
								}

								break;
							}
						}

						// An entity read out of a magic table carries no tenancy of its
						// own (MagicMapper::rowToObjectEntity never populates it), so an
						// unknown organisation here is NOT a decision while scoping is in
						// force: leave the identifier in the missing list and let STEP 3's
						// SQL — which reads the _organisation column — settle it.
						if (($objectOrganisation === null || $objectOrganisation === '')
							&& $unrestricted === false
						) {
							continue;
						}

						if ($this->hasOrganisationAccess($objectOrganisation) === true) {
							// Store result with UUID key (for consistent return format).
							$results[$uuid] = $name;
						}

						// Remove from missing list since we found it.
						$missingIdentifiers = array_diff($missingIdentifiers, [$uuid]);
					}//end foreach
				}//end if

				// STEP 3: Batch load any still-missing identifiers from magic tables.
				// This replaces the N+1 individual lookups with batch queries per table,
				// and is the tenancy oracle for every object-backed name: it reads the
				// _organisation column directly.
				if (empty($missingIdentifiers) === false) {
					$batchResults = $this->batchLoadNamesFromMagicTables(uuids: $missingIdentifiers);
					foreach ($batchResults as $uuid => $entry) {
						$this->setObjectName(
							identifier: $uuid,
							name: $entry['name'],
							organisation: $entry['organisation']
						);
						if ($this->hasOrganisationAccess($entry['organisation']) === true) {
							$results[$uuid] = $entry['name'];
						}
					}
				}
			} catch (\Exception $e) {
				$this->logger->error(
					message: '[CacheHandler] Failed to bulk load names from database',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'identifiers' => count($missingIdentifiers),
						'error' => $e->getMessage(),
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
			message: '[CacheHandler] 📦 BULK NAME LOOKUP COMPLETED',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'requested' => count($identifiers),
				'total_found' => count($results),
				'uuid_results_returned' => count($uuidResults),
				'cache_hits' => count($identifiers) - count($missingIdentifiers),
				'db_loads' => count($missingIdentifiers),
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
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getAllObjectNames(bool $forceWarmup = false): array {
		$this->beginNameScope();
		$startTime = microtime(true);

		// Check if we should trigger warmup.
		$shouldWarmup = $forceWarmup || empty($this->nameCache);

		if ($shouldWarmup === true) {
			$this->warmupNameCache();
		}

		// Filter to return only UUID -> name mappings (exclude database IDs), and
		// only for organisations this caller may see. SEC-CTRL-2 step 2: the name
		// cache is process-wide, so without this filter a cache warmed by one
		// tenant's request is handed to the next tenant that asks.
		$uuidNames = array_filter(
			$this->nameCache,
			function ($name, $key) {
				// Only return entries where key looks like a UUID (contains hyphens).
				if (is_string($key) === false || str_contains($key, '-') === false) {
					return false;
				}

				return $this->hasOrganisationAccess($this->nameCacheOrganisation[$key] ?? null);
			},
			ARRAY_FILTER_USE_BOTH
		);

		$executionTime = round((microtime(true) - $startTime) * 1000, 2);

		$this->logger->debug(
			message: '[CacheHandler] All object names retrieved',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'total_cached' => count($this->nameCache),
				'uuid_names_returned' => count($uuidNames),
				'warmup_triggered' => $shouldWarmup,
				'execution_time' => $executionTime . 'ms',
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
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function warmupNameCache(): int {
		$startTime = microtime(true);
		$this->stats['name_warmups']++;

		try {
			$loadedCount = 0;
			$magicNamesLoaded = 0;

			// STEP 1: Load all organisations first (they take priority).
			$organisations = $this->organisationMapper->findAllWithUserCount();
			foreach ($organisations as $organisation) {
				$name = $organisation->getName() ?? $organisation->getUuid();

				// Cache by UUID only (not by database ID). An organisation's own
				// tenancy is itself, so that is what is recorded alongside it.
				if ($organisation->getUuid() !== null && $name !== null) {
					$this->nameCache[$organisation->getUuid()] = $name;
					$this->rememberNameOrganisation(
						key: $organisation->getUuid(),
						organisation: $organisation->getUuid()
					);
					$loadedCount++;
				}
			}

			// STEP 2: Load all objects from main table.
			$objects = $this->getObjectMapper()->findAll();
			foreach ($objects as $object) {
				$name = $object->getName() ?? $object->getUuid();

				// Cache by UUID only (not by database ID).
				// Note: If an organisation has the same UUID, it will remain (organisations loaded first).
				$uuid = $object->getUuid();
				if ($uuid !== null && $name !== null && (($this->nameCache[$uuid] ?? null) === null) === true) {
					$this->nameCache[$uuid] = $name;
					$this->rememberNameOrganisation(key: $uuid, organisation: $object->getOrganisation());
					$loadedCount++;
				}
			}

			// STEP 3: Load names from magic tables (overwrites names with proper enriched values).
			if ($this->registerMapper !== null && $this->schemaMapper !== null && $this->db !== null) {
				$magicNamesLoaded = $this->loadNamesFromMagicTables();
			}

			// STEP 4: Persist to distributed cache for cross-request availability.
			$distributedCount = $this->persistNameCacheToDistributed();

			$executionTime = round((microtime(true) - $startTime) * 1000, 2);

			$this->logger->debug(
				message: '[CacheHandler] Name cache warmed up',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'organisations_processed' => count($organisations),
					'objects_processed' => count($objects),
					'magic_names_loaded' => $magicNamesLoaded,
					'distributed_cache_stored' => $distributedCount,
					'total_names_cached' => count($this->nameCache),
					'execution_time' => $executionTime . 'ms',
				]
			);

			// Store breakdown for diagnostics.
			$this->stats['warmup_breakdown'] = [
				'organisations' => count($organisations),
				'objects_table' => count($objects),
				'magic_tables' => $magicNamesLoaded,
				'total_unique' => count($this->nameCache),
			];

			return count($this->nameCache);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[CacheHandler] Name cache warmup failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
			return 0;
		}//end try
	}//end warmupNameCache()

	/**
	 * Load object names from magic tables.
	 *
	 * Queries all magic tables (register+schema combinations with magic mapping enabled)
	 * to get proper enriched names. These names overwrite any UUID-based names from
	 * the main objects table.
	 *
	 * @return int Number of names loaded from magic tables.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function loadNamesFromMagicTables(): int {
		$loadedCount = 0;

		try {
			// Get all registers.
			$registers = $this->registerMapper->findAll();

			foreach ($registers as $register) {
				$registerId = $register->getId();
				$schemaIds = $register->getSchemas() ?? [];

				foreach ($schemaIds as $schemaId) {
					// Get schema slug for config lookup (config uses slugs as keys).
					$schemaSlug = null;
					try {
						$schema = $this->schemaMapper->find((int)$schemaId);
						$schemaSlug = $schema->getSlug();
					} catch (\Exception $e) {
						// Schema not found, continue without slug.
					}

					// Check if this schema has magic mapping enabled.
					$magicEnabled = $register->isMagicMappingEnabledForSchema(
						schemaId: (int)$schemaId,
						schemaSlug: $schemaSlug
					);
					if ($magicEnabled === false) {
						continue;
					}

					// Query the magic table for names.
					$tableName = '*PREFIX*openregister_table_' . $registerId . '_' . $schemaId;

					try {
						// Check if table exists and has the name column.
						// Magic table columns have underscore prefix: _id, _name, _deleted, etc.
						// Note: _id is bigint (internal DB ID), we need _uuid (the UUID) for mapping.
						// Filter: only exclude deleted objects.
						// SEC-CTRL-2 step 2: `_organisation` is selected as well. It is the
						// tenancy oracle for every object-backed name — the ObjectEntity
						// produced by MagicMapper::rowToObjectEntity() never carries one.
						$sql = 'SELECT "_uuid", "_name", "_organisation" FROM ' . $tableName . ' WHERE "_deleted" IS NULL';
						$result = $this->db->executeQuery($sql);

						while (($row = $result->fetch()) !== false) {
							$uuid = $row['_uuid'] ?? null;
							$name = $row['_name'] ?? null;
							$rowOrganisation = $row['_organisation'] ?? null;

							if ($uuid !== null) {
								// Use name if available, otherwise fall back to UUID.
								$effectiveName = $uuid;
								if (($name !== null) && trim($name) !== '') {
									$effectiveName = $name;
								}

								// Overwrite any existing name (magic table has enriched names).
								$this->nameCache[$uuid] = $effectiveName;
								$this->rememberNameOrganisation(
									key: $uuid,
									organisation: $rowOrganisation
								);
								$loadedCount++;
							}
						}

						$result->closeCursor();
					} catch (\Exception $e) {
						// Table might not exist or have different structure - skip silently.
						$this->logger->debug(
							message: '[CacheHandler] Could not query magic table for names',
							context: [
								'file' => __FILE__,
								'line' => __LINE__,
								'table' => $tableName,
								'error' => $e->getMessage(),
							]
						);
					}//end try
				}//end foreach
			}//end foreach
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[CacheHandler] Failed to load names from magic tables',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
		}//end try

		return $loadedCount;
	}//end loadNamesFromMagicTables()

	/**
	 * Batch load names for specific UUIDs from magic tables.
	 *
	 * Queries all magic tables with a single IN clause per table to efficiently
	 * resolve multiple UUIDs at once. This replaces the N+1 individual lookups.
	 *
	 * @param array $uuids Array of UUIDs to look up.
	 *
	 * @return array<string, array{name: string, organisation: string|null}> Map of UUID to name + owning organisation.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Batch loading across multiple table types requires branching
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function batchLoadNamesFromMagicTables(array $uuids): array {
		$results = [];

		if (empty($uuids) === true) {
			return $results;
		}

		// Filter to only UUID-like strings.
		$uuidList = array_filter(
			$uuids,
			function ($id) {
				return is_string($id) === true && str_contains($id, '-');
			}
		);

		if (empty($uuidList) === true) {
			return $results;
		}

		// The magic-table dependencies are optional constructor arguments. Without
		// them there is no tenancy oracle, so the honest answer is "nothing
		// resolved" rather than a fatal on a null mapper.
		if ($this->registerMapper === null || $this->schemaMapper === null || $this->db === null) {
			return $results;
		}

		try {
			// Get all registers.
			$registers = $this->registerMapper->findAll();

			// Collect all schema IDs across all registers to load in one batch.
			$allSchemaIds = [];
			foreach ($registers as $register) {
				foreach ($register->getSchemas() ?? [] as $schemaId) {
					$allSchemaIds[(int)$schemaId] = true;
				}
			}

			// Bulk-load all schemas in one query instead of N individual find() calls.
			$schemaMap = [];
			if (empty($allSchemaIds) === false) {
				$schemaMap = $this->schemaMapper->findMultipleOptimized(ids: array_keys($allSchemaIds));
			}

			foreach ($registers as $register) {
				// If we found all UUIDs, stop searching.
				if (count($results) >= count($uuidList)) {
					break;
				}

				$registerId = $register->getId();
				$schemaIds = $register->getSchemas() ?? [];

				foreach ($schemaIds as $schemaId) {
					// If we found all UUIDs, stop searching.
					if (count($results) >= count($uuidList)) {
						break;
					}

					// Get schema slug from pre-loaded map (no individual query needed).
					$schema = $schemaMap[(int)$schemaId] ?? null;
					if ($schema === null) {
						continue;
					}

					$schemaSlug = $schema->getSlug();

					// Check if this schema has magic mapping enabled.
					if ($register->isMagicMappingEnabledForSchema(
						schemaId: (int)$schemaId,
						schemaSlug: $schemaSlug
					) === false
					) {
						continue;
					}

					// Build table name.
					$tableName = 'oc_openregister_table_' . $registerId . '_' . $schemaId;

					// Find UUIDs we still need to look up.
					$remainingUuids = array_diff($uuidList, array_keys($results));
					if (empty($remainingUuids) === true) {
						break;
					}

					// Batch query this table.
					$tableResults = $this->queryTableForNames(
						tableName: $tableName,
						uuids: array_values($remainingUuids)
					);

					$results = array_merge($results, $tableResults);
				}//end foreach
			}//end foreach
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[CacheHandler] Failed to batch load names from magic tables',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage(), 'uuid_count' => count($uuids)]
			);
		}//end try

		return $results;
	}//end batchLoadNamesFromMagicTables()

	/**
	 * Query a single magic table for names by UUIDs.
	 *
	 * @param string $tableName The table name (with oc_ prefix).
	 * @param array $uuids Array of UUIDs to look up.
	 *
	 * @return array<string, array{name: string, organisation: string|null}> Map of UUID to name + owning organisation.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function queryTableForNames(string $tableName, array $uuids): array {
		$results = [];

		if (empty($uuids) === true) {
			return $results;
		}

		// Try different name columns in order of preference.
		$nameColumns = ['_name', 'naam', 'name', 'title'];

		foreach ($nameColumns as $nameColumn) {
			try {
				// Build placeholders for IN clause.
				$placeholders = implode(',', array_fill(0, count($uuids), '?'));

				// SEC-CTRL-2 step 2: `_organisation` travels with the name so the
				// caller's tenancy can be checked before the name is disclosed.
				$sql = "SELECT _uuid, _organisation, {$nameColumn} as name_value
                        FROM {$tableName}
                        WHERE _uuid IN ({$placeholders})
                        AND _deleted IS NULL";

				// Raw SQL: QueryBuilder cannot accept a runtime-resolved magic
				// table name nor a column name picked at runtime from a fixed
				// allowlist. SQL itself is plain SELECT/IN/IS NULL — portable
				// across MariaDB / MySQL / PostgreSQL.
				$stmt = $this->db->prepare($sql);
				foreach ($uuids as $index => $uuid) {
					$stmt->bindValue((int)$index + 1, $uuid);
				}

				$stmt->execute();

				while (($row = $stmt->fetch()) !== false) {
					$uuid = $row['_uuid'];
					$name = $row['name_value'];
					$rowOrganisation = $row['_organisation'] ?? null;
					if ($name !== null && trim((string)$name) !== '') {
						$results[$uuid] = [
							'name' => (string)$name,
							'organisation' => ($rowOrganisation === null) ? null : (string)$rowOrganisation,
						];
					}
				}

				// If we found results with this column, return them.
				if (empty($results) === false) {
					return $results;
				}
			} catch (\Exception $e) {
				// Column doesn't exist, try next one.
				continue;
			}//end try
		}//end foreach

		return $results;
	}//end queryTableForNames()

	/**
	 * Persist in-memory name cache to distributed cache.
	 *
	 * Iterates through all entries in the in-memory name cache and stores them
	 * in the distributed cache (APCu) for cross-request availability.
	 * This ensures that warmed-up names are available to subsequent requests
	 * without requiring a fresh database query.
	 *
	 * @return int Number of entries stored in distributed cache.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function persistNameCacheToDistributed(): int {
		if ($this->nameDistributedCache === null) {
			return 0;
		}

		$storedCount = 0;
		$ttl = self::MAX_CACHE_TTL;

		foreach ($this->nameCache as $identifier => $name) {
			try {
				// SEC-CTRL-2 step 2: the KEY is deliberately unchanged so every
				// existing invalidation site keeps matching; the tenancy rides in
				// the VALUE, which also means an entity that moves organisation can
				// never leave an orphaned entry under a stale prefix.
				$this->nameDistributedCache->set(
					'name_' . $identifier,
					$this->buildNameEnvelope(
						name: $name,
						organisation: ($this->nameCacheOrganisation[(string)$identifier] ?? null)
					),
					$ttl
				);
				$storedCount++;
			} catch (\Exception $e) {
				// Log once per batch, not per entry to avoid log spam.
				if ($storedCount === 0) {
					$this->logger->warning(
						message: '[CacheHandler] Failed to persist name cache entry to distributed cache',
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'identifier' => $identifier,
							'error' => $e->getMessage(),
						]
					);
				}
			}
		}

		// Store metadata with the count for cross-request stats.
		try {
			$this->nameDistributedCache->set('_metadata_count', $storedCount, $ttl);
		} catch (\Exception $e) {
			// Ignore metadata storage failures.
		}

		return $storedCount;
	}//end persistNameCacheToDistributed()

	/**
	 * Get the name cache count from distributed cache metadata
	 *
	 * Returns the count of names stored in the distributed cache,
	 * useful for cross-request statistics.
	 *
	 * @return int The number of names in distributed cache, or 0 if unavailable
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getDistributedNameCacheCount(): int {
		if ($this->nameDistributedCache === null) {
			return 0;
		}

		try {
			$count = $this->nameDistributedCache->get('_metadata_count');
			if ($count !== null) {
				return (int)$count;
			}

			return 0;
		} catch (\Exception $e) {
			return 0;
		}
	}//end getDistributedNameCacheCount()

	/**
	 * Clear object name caches
	 *
	 * Removes all cached object names from both in-memory and distributed caches.
	 * Called when objects are modified to ensure name consistency.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function clearNameCache(): void {
		// Clear in-memory name cache and the tenancy recorded alongside it.
		$this->nameCache = [];
		$this->nameCacheOrganisation = [];

		// Clear distributed name cache.
		if ($this->nameDistributedCache !== null) {
			try {
				$this->nameDistributedCache->clear();
			} catch (\Exception $e) {
				$this->logger->warning(
					message: '[CacheHandler] Failed to clear distributed name cache',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'error' => $e->getMessage(),
					]
				);
			}
		}

		$this->logger->debug(
			message: '[CacheHandler] OBJECT NAME CACHE CLEARED',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end clearNameCache()
}//end class
