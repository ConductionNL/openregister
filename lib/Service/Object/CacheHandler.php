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

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectNameCacheInterface;
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
 * @SuppressWarnings(PHPMD.TooManyMethods)           Public API for comprehensive cache management
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex cache invalidation and warming logic
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Cache handler requires multiple dependencies for comprehensive caching
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 */
class CacheHandler implements CacheHandlerInterface, ObjectNameCacheInterface {
	/**
	 * The counters before anything was counted.
	 *
	 * @var array<string, int>
	 */
	private const EMPTY_STATS = [
		'hits'         => 0,
		'misses'       => 0,
		'preloads'     => 0,
		'query_hits'   => 0,
		'query_misses' => 0,
		'name_hits'    => 0,
		'name_misses'  => 0,
		'name_warmups' => 0,
	];


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
	 * The columns a magic table may hold a name in, in order of preference.
	 *
	 * @var list<string>
	 */
	private const NAME_COLUMNS = ['_name', 'naam', 'name', 'title'];

	/**
	 * Name source of an organisation's own name.
	 *
	 * @var string
	 */
	public const NAME_SOURCE_ORGANISATION = 'organisation';

	/**
	 * In-memory cache of object names indexed by ID/UUID
	 *
	 * Provides ultra-fast name lookups for frontend rendering without
	 * requiring full object data retrieval.
	 *
	 * Keys are int|string, not string: the cache is written under BOTH the uuid
	 * and the numeric id, and PHP coerces a canonical numeric string key to int.
	 *
	 * @var array<int|string, string>
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
	 * @var array<int|string, string|null> Keyed identically to $nameCache — see
	 *                                     the key-coercion note there.
	 */
	private array $nameOrganisations = [];

	/**
	 * Where every entry in $nameCache came from, which is what decides who may see it
	 *
	 * NAME_SOURCE_ORGANISATION for an organisation's name (decided by the caller's
	 * organisation scope), or "<registerId>:<schemaId>" for an object's name
	 * (decided by the object read path, see visibleNames()). An entry without a
	 * source is a cache MISS: it is resolved again, never served.
	 *
	 * @var array<int|string, string> Keyed identically to $nameCache.
	 */
	private array $nameSources = [];

	/**
	 * Read-path answers already given, per caller, per object UUID
	 *
	 * Spares a repeated lookup (a facet resolves one value per call) the same
	 * access query. Keyed by user id plus active organisation, so a long-lived
	 * process never hands one caller's answer to another, nor a user's answer in
	 * one organisation to the same user acting in another; an object that
	 * changes is forgotten for everyone (invalidateForObjectChange()).
	 *
	 * @var array<string, array<string, bool>>
	 */
	private array $readabilityMemo = [];

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
	 * `warmup_breakdown` is written by the name-cache warm-up for diagnostics
	 * and is absent until that has run, so it is optional here. It was missing
	 * from this shape entirely, which is why assigning it read as
	 * InvalidPropertyAssignmentValue: the declaration described eight int keys
	 * and the code stored a ninth holding an array.
	 *
	 * @var array{hits: int, misses: int, preloads: int, query_hits: int,
	 *            query_misses: int, name_hits: int, name_misses: int, name_warmups: int,
	 *            warmup_breakdown?: array{organisations: int, objects_table: int,
	 *                                     magic_tables: int, total_unique: int}}
	 */
	private array $stats = self::EMPTY_STATS;

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
	 * Container for lazy loading dependencies to break circular dependency
	 *
	 * @var IAppContainer|null
	 */
	private ?IAppContainer $container = null;

	/**
	 * Lazily loaded MagicMapper to break circular dependency
	 *
	 * @var MagicMapperInterface|null
	 */
	private ?MagicMapperInterface $objectMapper = null;

	/**
	 * Constructor for CacheHandler
	 *
	 * @param OrganisationMapperInterface $organisationMapper The organisation entity mapper
	 * @param LoggerInterface $logger Logger for performance monitoring
	 * @param ICacheFactory|null $cacheFactory Cache factory for query result caching
	 * @param IUserSession|null $userSession User session for cache key generation
	 * @param IAppContainer|null $container Container for lazy loading dependencies (optional)
	 * @param RegisterMapperInterface|null $registerMapper Register mapper for magic table queries
	 * @param SchemaMapperInterface|null $schemaMapper Schema mapper for magic table queries
	 * @param IDBConnection|null $db Database connection for magic table queries
	 * @param IGroupManager|null $groupManager Group manager for the admin-override arm of the name scope
	 * @param IAppConfig|null $appConfig App config for the multitenancy switches read by the name scope
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud constructor injection:
	 * every parameter is a distinct collaborator resolved by the DI container, and the
	 * two added by SEC-CTRL-2 step 2 are the same pair Db\MultiTenancyTrait consumes.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly OrganisationMapperInterface $organisationMapper,
		private readonly LoggerInterface $logger,
		?ICacheFactory $cacheFactory = null,
		private readonly ?IUserSession $userSession = null,
		?IAppContainer $container = null,
		private readonly ?RegisterMapperInterface $registerMapper = null,
		private readonly ?SchemaMapperInterface $schemaMapper = null,
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

		$this->container = $container;
	}//end __construct()

	/**
	 * Get the MagicMapper lazily to break circular dependency.
	 *
	 * @return MagicMapperInterface The unified object mapper.
	 *
	 * @throws RuntimeException When container is not available.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function getObjectMapper(): MagicMapperInterface {
		if ($this->objectMapper === null) {
			if ($this->container === null) {
				throw new RuntimeException('[CacheHandler] Container required for lazy loading MagicMapper');
			}

			$this->objectMapper = $this->container->get(MagicMapperInterface::class);
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
	 * Record the owning organisation and the source of a cached name.
	 *
	 * @param string $key The name-cache key (object id or uuid)
	 * @param string|null $organisation The owning organisation UUID, or null when unknown
	 * @param string|null $source NAME_SOURCE_ORGANISATION, "<register>:<schema>", or null when unknown
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	private function rememberNameOrganisation(string $key, ?string $organisation, ?string $source = null): void {
		$this->nameOrganisations[$key] = $organisation;
		if ($source === null) {
			unset($this->nameSources[$key]);
			return;
		}

		$this->nameSources[$key] = $source;
	}//end rememberNameOrganisation()

	/**
	 * The name source of an object: "<registerId>:<schemaId>", the table its read rule lives on.
	 *
	 * @param mixed $register The object's register id
	 * @param mixed $schema The object's schema id
	 *
	 * @return string|null The source, or null when either id is missing
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	public static function objectNameSource(mixed $register, mixed $schema): ?string {
		if (is_numeric($register) === false || is_numeric($schema) === false) {
			return null;
		}

		return (int)$register . ':' . (int)$schema;
	}//end objectNameSource()

	/**
	 * Wrap a name, its owning organisation and its source for the distributed cache.
	 *
	 * The distributed name cache is shared by every caller on the instance, so
	 * what decides visibility has to be stored WITH the value. Keeping the KEY
	 * unchanged is deliberate: every existing invalidation site removes
	 * `name_<identifier>` and keeps working untouched.
	 *
	 * @param string $name The cached name
	 * @param string|null $organisation The owning organisation UUID
	 * @param string|null $source The name source (see $nameSources)
	 *
	 * @return array{n: string, o: string|null, s: string|null} The envelope stored in the distributed cache
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	private function buildNameEnvelope(string $name, ?string $organisation, ?string $source = null): array {
		return ['n' => $name, 'o' => $organisation, 's' => $source];
	}//end buildNameEnvelope()

	/**
	 * Read a distributed-cache entry as an envelope that can be decided.
	 *
	 * An entry without a source (a bare string, or an envelope written before
	 * names followed read rights) is reported as a MISS rather than served: the
	 * fail-closed direction across a deploy, and the database re-establishes it.
	 *
	 * @param mixed $cached The raw value read from the distributed cache
	 *
	 * @return array{n: string, o: string|null, s: string}|null The envelope, or null when unusable
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	private function readNameEnvelope(mixed $cached): ?array {
		if (is_array($cached) === false || is_string($cached['n'] ?? null) === false) {
			return null;
		}

		$organisation = $cached['o'] ?? null;
		if ($organisation !== null && is_string($organisation) === false) {
			return null;
		}

		$source = $cached['s'] ?? null;
		if (is_string($source) === false || $source === '') {
			return null;
		}

		return ['n' => $cached['n'], 'o' => $organisation, 's' => $source];
	}//end readNameEnvelope()

	/**
	 * Keep only the names this caller may see.
	 *
	 * The rule: a caller gets the name of every object they may read, and
	 * nothing for an object they may not read. "May read" is asked of the
	 * object read path itself (MagicMapper::filterReadableUuids(), the RBAC +
	 * multitenancy filter of GET /api/objects/{register}/{schema}/{id}), so the
	 * two can never disagree. An organisation's name keeps the organisation
	 * scope (hasOrganisationAccess()). A name without a known source is dropped.
	 *
	 * @param array<int|string, string> $candidates Identifier => name, every one already in the name cache
	 *
	 * @return array<string, string> The visible subset
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	private function visibleNames(array $candidates): array {
		$visible = [];
		$uuidsBySource = [];
		foreach ($candidates as $key => $name) {
			$key = (string)$key;
			$source = $this->nameSources[$key] ?? null;
			if ($source === self::NAME_SOURCE_ORGANISATION) {
				if ($this->hasOrganisationAccess(organisation: ($this->nameOrganisations[$key] ?? null)) === true) {
					$visible[$key] = $name;
				}

				continue;
			}

			if ($source !== null) {
				$uuidsBySource[$source][] = $key;
			}
		}

		// Ask the read path per table, remembering each answer per caller (user
		// plus active organisation): a facet resolves one value per call, and the
		// same question must not cost the same query twice. A failure is "none".
		$caller = (string)$this->userSession?->getUser()?->getUID() . '|' . $this->getActiveOrganisationCacheScope();
		$memo = ($this->readabilityMemo[$caller] ?? []);
		foreach ($uuidsBySource as $source => $uuids) {
			$unknown = array_values(array_filter($uuids, fn (string $uuid): bool => isset($memo[$uuid]) === false));
			if (empty($unknown) === false) {
				[$registerId, $schemaId] = array_map('intval', explode(':', (string)$source, 2) + [1 => '0']);
				$readable = [];
				try {
					$readable = $this->getObjectMapper()->filterReadableUuids(registerId: $registerId, schemaId: $schemaId, uuids: $unknown);
				} catch (\Throwable $e) {
					$this->logger->warning(
						message: '[CacheHandler] Read access for names could not be established; disclosing none',
						context: ['file' => __FILE__, 'line' => __LINE__, 'source' => $source, 'error' => $e->getMessage()]
					);
				}

				foreach ($unknown as $uuid) {
					$memo[$uuid] = in_array($uuid, $readable, true);
				}
			}

			foreach ($uuids as $uuid) {
				if (($memo[$uuid] ?? false) === true) {
					$visible[$uuid] = $candidates[$uuid];
				}
			}
		}//end foreach

		$this->readabilityMemo[$caller] = $memo;

		return $visible;
	}//end visibleNames()

	/**
	 * Bulk preload objects to warm the cache
	 *
	 * This method loads multiple objects in a single database query and caches them
	 * all, significantly improving performance for operations that access many objects.
	 *
	 * With a register, the identifiers are looked up in that register's tables
	 * first and only the ones not found there in every table. A reference
	 * nearly always points into its own register, and one lookup across every
	 * magic table is a UNION over all of them (1.7k tables: 1.5-3.6 s).
	 *
	 * @param array $identifiers Array of object IDs/UUIDs to preload
	 * @param int|null $registerId The register to look in first, or null for every table.
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
	public function preloadObjects(array $identifiers, ?int $registerId = null): array {
		if (empty($identifiers) === true) {
			return [];
		}

		// Tenant-scoped keys (BUG-OBJ-6) decide what is already cached.
		$identifiersToLoad = array_filter(
			array_unique($identifiers),
			fn ($id) => $this->cachedObject(identifier: $id) === null
		);
		if (empty($identifiersToLoad) === true) {
			return array_filter(array_map(fn ($id) => $this->cachedObject(identifier: $id), $identifiers), fn ($obj) => $obj !== null);
		}

		return $this->bulkLoadObjects(identifiers: array_values($identifiersToLoad), registerId: $registerId);
	}//end preloadObjects()

	/**
	 * The object cached under this identifier for the current tenant.
	 *
	 * @param int|string $identifier The identifier.
	 *
	 * @return ObjectEntity|null The object, or null when it is not cached.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function cachedObject(int|string $identifier): ?ObjectEntity {
		return ($this->objectCache[$this->buildObjectCacheKey(rawKey: (string)$identifier)] ?? null);
	}//end cachedObject()

	/**
	 * Load objects in bulk and cache them; a failure is logged and loads nothing.
	 *
	 * @param array<int|string> $identifiers The identifiers to load.
	 * @param int|null          $registerId  The register to look in first.
	 *
	 * @return ObjectEntity[] The objects found.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function bulkLoadObjects(array $identifiers, ?int $registerId): array {
		try {
			$objects = $this->findForPreload(identifiers: $identifiers, registerId: $registerId);
			array_walk($objects, fn (ObjectEntity $object) => $this->cacheObject(object: $object));
			$this->stats['preloads'] += count($objects);
			return $objects;
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[CacheHandler] Bulk preload failed in CacheHandler',
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $e->getMessage(), 'identifiersToLoad' => count($identifiers)]
			);
			return [];
		}
	}//end bulkLoadObjects()

	/**
	 * Load objects by identifier, from one register's tables first when given.
	 *
	 * @param array<int|string> $identifiers The identifiers to load.
	 * @param int|null $registerId The register to look in first.
	 *
	 * @return ObjectEntity[] The objects found.
	 */
	private function findForPreload(array $identifiers, ?int $registerId): array {
		$mapper = $this->getObjectMapper();
		if ($registerId === null) {
			return $mapper->findMultiple($identifiers);
		}

		$objects = $mapper->findMultipleAcrossAllMagicTables(uuids: $identifiers, registerIdScope: $registerId);
		$found = [];
		foreach ($objects as $object) {
			$found[(string)$object->getUuid()] = true;
			$found[(string)$object->getId()] = true;
		}

		$missing = array_values(
			array_filter(
				$identifiers,
				fn ($id) => isset($found[(string)$id]) === false
			)
		);
		if ($missing === []) {
			return $objects;
		}

		return array_merge($objects, $mapper->findMultiple($missing));
	}//end findForPreload()

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
		return array_merge(
			$this->stats,
			[
				'hit_rate' => $this->hitRate(prefix: ''),
				'query_hit_rate' => $this->hitRate(prefix: 'query_'),
				'name_hit_rate' => $this->hitRate(prefix: 'name_'),
				'cache_size' => count($this->objectCache),
				'query_cache_size' => count($this->inMemoryQueryCache),
				'name_cache_size' => count($this->nameCache),
				'distributed_name_cache_size' => $this->getDistributedNameCacheCount(),
			]
		);
	}//end getStats()

	/**
	 * The hit rate of one counter pair, as a percentage with two decimals.
	 *
	 * @param string $prefix The pair's prefix in the stats.
	 *
	 * @return float The rate, 0 before any request.
	 */
	private function hitRate(string $prefix): float {
		$hits  = $this->stats[$prefix.'hits'];
		$total = ($hits + $this->stats[$prefix.'misses']);
		if ($total === 0) {
			return 0.0;
		}

		return round(($hits / $total) * 100, 2);
	}//end hitRate()

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
		$this->inMemoryQueryCache = $this->queriesWithout(pattern: $pattern);

		// For targeted clearing, we'd need a more sophisticated approach.
		// For now, clear all to ensure consistency.
		$this->clearDistributed(cache: $this->queryCache, failure: ['message' => '[CacheHandler] Failed to clear search cache', 'pattern' => $pattern]);

		$this->logger->debug(
			message: '[CacheHandler] 🧹 SEARCH CACHE CLEARED',
			context: ['file' => __FILE__, 'line' => __LINE__, 'pattern' => $pattern ?? 'all']
		);
	}//end clearSearchCache()

	/**
	 * The in-memory query cache without the entries whose key holds the pattern.
	 *
	 * @param string|null $pattern The pattern, null for every entry.
	 *
	 * @return array The entries that stay.
	 */
	private function queriesWithout(?string $pattern): array {
		if ($pattern === null) {
			return [];
		}

		return array_filter(
			$this->inMemoryQueryCache,
			static fn ($key): bool => strpos($key, $pattern) === false,
			ARRAY_FILTER_USE_KEY
		);
	}//end queriesWithout()

	/**
	 * Clear a distributed cache, reporting a failure instead of raising it.
	 *
	 * @param IMemcache|null $cache   The cache, null when there is none.
	 * @param array          $failure The warning's `message` and what it reports beside the error.
	 *
	 * @return bool True when the cache was cleared.
	 */
	private function clearDistributed(?IMemcache $cache, array $failure): bool {
		if ($cache === null) {
			return false;
		}

		try {
			$cache->clear();
		} catch (\Exception $e) {
			$message = $failure['message'];
			unset($failure['message']);
			$this->logger->warning(message: $message, context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()] + $failure);
			return false;
		}

		return true;
	}//end clearDistributed()

	/**
	 * Clear every search cache that may hold objects of a changed schema.
	 *
	 * Distributed keys cannot be pattern-matched, so all search entries go (a nuclear
	 * approach). This affects all users, which is how colleagues see each other's
	 * changes immediately.
	 *
	 * TODO: Implement more targeted cache clearing with schema-specific prefixes.
	 *
	 * @param ObjectChange $change The change.
	 *
	 * @return void
	 */
	private function clearSchemaRelatedCaches(ObjectChange $change): void {
		$startTime = microtime(true);
		$this->inMemoryQueryCache = [];
		$this->clearDistributedForSchema(change: $change);

		// Fallback: clear all search caches if no specific schema.
		if ($change->schemaId === null) {
			$this->clearSearchCache();
		}

		$this->logSchemaCleared(change: $change, startTime: $startTime);
	}//end clearSchemaRelatedCaches()

	/**
	 * Log a schema-wide clearing.
	 *
	 * @param ObjectChange $change    The change.
	 * @param float        $startTime When the clearing started.
	 *
	 * @return void
	 */
	private function logSchemaCleared(ObjectChange $change, float $startTime): void {
		$strategy = 'global_fallback';
		if ($change->schemaId !== null) {
			$strategy = 'schema_targeted';
		}

		$this->logger->info(
			message: '[CacheHandler] Schema-related caches cleared for CUD operation',
			context: ['file' => __FILE__, 'line' => __LINE__] + $change->facts() + [
				'executionTime' => round((microtime(true) - $startTime) * 1000, 2).'ms',
				'impact' => 'all_users_affected',
				'strategy' => $strategy,
			]
		);
	}//end logSchemaCleared()

	/**
	 * Clear the distributed search cache for a change that names a schema.
	 *
	 * @param ObjectChange $change The change.
	 *
	 * @return void
	 */
	private function clearDistributedForSchema(ObjectChange $change): void {
		if ($change->schemaId === null) {
			return;
		}

		$failure = ['message' => '[CacheHandler] Failed to clear schema-related distributed caches', 'schemaId' => $change->schemaId];
		if ($this->clearDistributed(cache: $this->queryCache, failure: $failure) === true) {
			$this->logger->debug(
				message: '[CacheHandler] Schema-related distributed caches cleared',
				context: ['file' => __FILE__, 'line' => __LINE__] + $change->facts() + ['strategy' => 'nuclear_clear']
			);
		}
	}//end clearDistributedForSchema()

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
		$change    = (new ObjectChange(object: $object, operation: $operation))->within(registerId: $registerId, schemaId: $schemaId);

		$this->refreshChangedObject(change: $change);
		$this->clearSchemaRelatedCaches(change: $change);

		$this->logger->info(
			message: '[CacheHandler] Schema-wide cache invalidated for CRUD operation',
			context: ['file' => __FILE__, 'line' => __LINE__] + $change->facts() + [
				'objectId' => $object?->getId(),
				'executionTime' => round((microtime(true) - $startTime) * 1000, 2).'ms',
				'scope' => 'all_users_in_schema',
			]
		);
	}//end invalidateForObjectChange()

	/**
	 * Forget a changed object and remember its new name.
	 *
	 * @param ObjectChange $change The change.
	 *
	 * @return void
	 */
	private function refreshChangedObject(ObjectChange $change): void {
		if ($change->object === null) {
			return;
		}

		$this->forgetChangedObject(object: $change->object, operation: $change->operation);
		if ($change->operation === 'create' || $change->operation === 'update') {
			$source = self::objectNameSource(register: $change->registerId, schema: $change->schemaId);
			$this->rememberChangedObjectName(object: $change->object, source: $source);
		}
	}//end refreshChangedObject()

	/**
	 * Drop a changed object and its cached name; a delete also drops its name everywhere it was recorded.
	 *
	 * BUG-OBJ-7: the stale name is dropped whatever the operation label, so a rename can never serve
	 * a stale value from cache.
	 *
	 * @param ObjectEntity $object    The changed object.
	 * @param string       $operation The operation performed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function forgetChangedObject(ObjectEntity $object, string $operation): void {
		$this->clearObjectFromCache(object: $object);
		$this->clearObjectNameFromCache(object: $object);
		if ($operation !== 'delete') {
			return;
		}

		unset($this->nameCache[$object->getUuid()]);
		unset($this->nameCache[(string)$object->getId()]);
		unset($this->nameOrganisations[$object->getUuid()]);
		unset($this->nameOrganisations[(string)$object->getId()]);

		if ($this->nameDistributedCache === null) {
			return;
		}

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
	}//end forgetChangedObject()

	/**
	 * Cache a created or updated object's fresh name under its UUID and id, with its tenancy and table.
	 *
	 * @param ObjectEntity $object The changed object.
	 * @param string|null  $source The object's name source, "<registerId>:<schemaId>".
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function rememberChangedObjectName(ObjectEntity $object, ?string $source): void {
		$name = $object->getName() ?? $object->getUuid();
		$organisation = $object->getOrganisation();
		$this->setObjectName(identifier: $object->getUuid(), name: $name, organisation: $organisation, source: $source);
		if (($object->getId() !== null) === true && (string)$object->getId() !== $object->getUuid()) {
			$this->setObjectName(identifier: $object->getId(), name: $name, organisation: $organisation, source: $source);
		}
	}//end rememberChangedObjectName()

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

		// A changed object may have changed who may read it: forget every
		// caller's remembered answer for it.
		foreach (array_keys($this->readabilityMemo) as $caller) {
			foreach ($keys as $key) {
				unset($this->readabilityMemo[$caller][$key]);
			}
		}

		foreach ($keys as $key) {
			unset($this->nameCache[$key]);
			unset($this->nameOrganisations[$key]);
			unset($this->nameSources[$key]);

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
		$this->stats = self::EMPTY_STATS;
		$this->forgetNames();

		$this->clearDistributed(cache: $this->queryCache, failure: ['message' => '[CacheHandler] Failed to clear distributed query cache']);
		$this->clearDistributed(cache: $this->nameDistributedCache, failure: ['message' => '[CacheHandler] Failed to clear distributed name cache']);

		$this->logger->info(
			message: '[CacheHandler] All object caches cleared (including name cache)',
			context: ['file' => __FILE__, 'line' => __LINE__, 'executionTime' => round((microtime(true) - $startTime) * 1000, 2).'ms']
		);
	}//end clearAllCaches()

	/**
	 * Empty the in-memory name cache and the tenancy recorded alongside it.
	 *
	 * @return void
	 */
	private function forgetNames(): void {
		$this->nameCache = [];
		$this->nameOrganisations = [];
		$this->nameSources = [];
		$this->readabilityMemo = [];
	}//end forgetNames()

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
	 * @param string|null $source Where the name came from: NAME_SOURCE_ORGANISATION or
	 *                            "<registerId>:<schemaId>" (see objectNameSource()). Without
	 *                            one the entry is never served; the next lookup resolves it again.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	public function setObjectName(
		string|int $identifier,
		string $name,
		int $ttl = 86400,
		?string $organisation = null,
		?string $source = null,
	): void {
		$key = (string)$identifier;

		// Enforce maximum cache TTL.
		$ttl = min($ttl, self::MAX_CACHE_TTL);

		// Store in in-memory cache, together with the tenancy of the entity the
		// name belongs to (SEC-CTRL-2 step 2). Without the second map the cache
		// is a cross-tenant disclosure channel of its own.
		$this->nameCache[$key] = $name;
		$this->rememberNameOrganisation(key: $key, organisation: $organisation, source: $source);

		// Store in distributed cache if available.
		if ($this->nameDistributedCache !== null) {
			try {
				$this->nameDistributedCache->set(
					'name_' . $key,
					$this->buildNameEnvelope(name: $name, organisation: $organisation, source: $source),
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
	 * The database LOOKUP is unscoped (`findAcrossAllSources(..., _rbac: false,
	 * _multitenancy: false)`, organisations first), so it can find anything. The
	 * ANSWER is not: an object's name is only returned when the caller may read
	 * the object (the object read path decides, see visibleNames()), and an
	 * organisation's name only inside the caller's organisation scope. The same
	 * decision gates every cache hit.
	 *
	 * It used to back `GET /api/names/{id}`, which was `#[PublicPage]` — any
	 * anonymous caller holding a UUID could read that object's name across tenant
	 * boundaries. That endpoint was removed (SEC-CTRL-2, gate-7); this method
	 * survives only as an internal cache primitive and has no caller in `lib/`.
	 *
	 * A numeric id or slug is never answered from cache: the read path is asked
	 * by UUID, so it goes to the database, which knows the UUID.
	 *
	 * @param string|int $identifier Object ID or UUID
	 *
	 * @return string|null Object name or null if not found
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	public function getSingleObjectName(string|int $identifier): ?string {
		$this->beginNameScope();

		// The read path is asked by UUID, so only a UUID is answered from cache;
		// a numeric id or slug goes to the database, which knows the UUID.
		if (str_contains((string)$identifier, '-') === true) {
			$cached = $this->cachedSingleName(key: (string)$identifier);
			if ($cached !== null) {
				return $cached['name'];
			}
		}

		$this->stats['name_misses']++;

		try {
			$organisation = $this->organisationSingleName(identifier: $identifier);
			if ($organisation !== null) {
				return $organisation['name'];
			}

			return $this->objectSingleName(identifier: $identifier);
		} catch (\Exception $e) {
			$this->logger->debug(
				message: '[CacheHandler] Failed to load entity for name lookup',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'identifier' => (string)$identifier,
					'error' => $e->getMessage(),
				]
			);
		}//end try

		return null;
	}//end getSingleObjectName()

	/**
	 * A cache hit for one UUID, from memory first and then the distributed cache.
	 *
	 * A hit is final: its name is returned only when the caller may see it, and a
	 * hidden hit answers null without going to the database.
	 *
	 * @param string $key The UUID.
	 *
	 * @return array{name: string|null}|null The hit, or null on a miss.
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	private function cachedSingleName(string $key): ?array {
		if (($this->nameCache[$key] ?? null) !== null && isset($this->nameSources[$key]) === true) {
			$this->stats['name_hits']++;
			return ['name' => ($this->visibleNames(candidates: [$key => $this->nameCache[$key]])[$key] ?? null)];
		}

		if ($this->nameDistributedCache === null) {
			return null;
		}

		try {
			$envelope = $this->readNameEnvelope(cached: $this->nameDistributedCache->get('name_' . $key));
			if ($envelope === null) {
				return null;
			}

			$this->nameCache[$key] = $envelope['n'];
			$this->rememberNameOrganisation(key: $key, organisation: $envelope['o'], source: $envelope['s']);
			$this->stats['name_hits']++;
			return ['name' => ($this->visibleNames(candidates: [$key => $envelope['n']])[$key] ?? null)];
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

		return null;
	}//end cachedSingleName()

	/**
	 * The name of the organisation with this UUID, which takes priority over objects.
	 *
	 * The mapper's findByUuid() throws rather than returning null, so any exception here means
	 * "no such organisation" and the lookup moves on to objects.
	 *
	 * @param string|int $identifier The identifier asked for.
	 *
	 * @return array{name: string|null}|null The answer, or null when no organisation matched.
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	private function organisationSingleName(string|int $identifier): ?array {
		$key = (string)$identifier;
		try {
			$organisation = $this->organisationMapper->findByUuid($key);
			$name = $organisation->getName() ?? $organisation->getUuid();
			if ($name === null) {
				return ['name' => null];
			}

			$this->setObjectName(
				identifier: $identifier,
				name: $name,
				organisation: $organisation->getUuid(),
				source: self::NAME_SOURCE_ORGANISATION
			);
			return ['name' => ($this->visibleNames(candidates: [$key => $name])[$key] ?? null)];
		} catch (\Exception $e) {
			return null;
		}
	}//end organisationSingleName()

	/**
	 * The name of the object with this id, uuid or slug, when the caller may read it.
	 *
	 * The LOOKUP is unscoped; the ANSWER is decided by the object read path. The name is
	 * cached under the identifier asked for and under the object's UUID.
	 *
	 * @param string|int $identifier The identifier asked for.
	 *
	 * @return string|null The name, or null when not found, sourceless or not readable.
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	private function objectSingleName(string|int $identifier): ?string {
		$result = $this->getObjectMapper()->findAcrossAllSources(
			identifier: $identifier,
			includeDeleted: false,
			_rbac: false,
			_multitenancy: false
		);
		$object = ($result['object'] ?? null);
		if ($object === null) {
			return null;
		}

		$name = $object->getName() ?? $object->getUuid();
		$source = self::objectNameSource(
			register: (($result['register'] ?? null)?->getId() ?? $object->getRegister()),
			schema: (($result['schema'] ?? null)?->getId() ?? $object->getSchema())
		);
		if ($name === null || $source === null) {
			return null;
		}

		$uuid = (string)$object->getUuid();
		$this->setObjectName(identifier: $identifier, name: $name, organisation: $object->getOrganisation(), source: $source);
		if ($uuid !== (string)$identifier) {
			$this->setObjectName(identifier: $uuid, name: $name, organisation: $object->getOrganisation(), source: $source);
		}

		return ($this->visibleNames(candidates: [$uuid => $name])[$uuid] ?? null);
	}//end objectSingleName()

	/**
	 * Get multiple object names from cache or database
	 *
	 * Efficiently retrieves names for multiple objects using bulk operations
	 * to minimize database queries.
	 *
	 * A caller gets the name of every object they may read, and nothing for an
	 * object they may not read: the answer is a PARTIAL map, never an error.
	 * Organisation names follow the caller's organisation scope.
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
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	public function getMultipleObjectNames(array $identifiers): array {
		if (empty($identifiers) === true) {
			return [];
		}

		$this->beginNameScope();

		// Who may see a candidate is decided once, at the end, by visibleNames():
		// the object read path for an object, the organisation scope for an
		// organisation. An entry whose source is unknown is a MISS, so the
		// database can establish it.
		$run = $this->collectCachedNames(identifiers: $identifiers);
		$this->collectDistributedNames(run: $run);

		if (empty($run->missing) === false) {
			$this->loadNamesFromDatabase(run: $run);
		}

		// Only UUID -> name mappings are returned (database ids are excluded),
		// and only those this caller may see.
		$uuidCandidates = array_filter(
			$run->candidates,
			fn ($key): bool => str_contains((string)$key, '-'),
			ARRAY_FILTER_USE_KEY
		);
		$uuidResults = $this->visibleNames(candidates: $uuidCandidates);

		$this->logger->debug(
			message: '[CacheHandler] 📦 BULK NAME LOOKUP COMPLETED',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'requested' => count($identifiers),
				'total_found' => count($run->candidates),
				'uuid_results_returned' => count($uuidResults),
				'db_loads' => count($run->missing),
			]
		);

		return $uuidResults;
	}//end getMultipleObjectNames()

	/**
	 * Start a name lookup with the names the in-memory cache already holds with a known source.
	 *
	 * @param array<string|int> $identifiers The identifiers asked for.
	 *
	 * @return NameLookupRun The run, with every other identifier missing.
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	private function collectCachedNames(array $identifiers): NameLookupRun {
		$run = new NameLookupRun();
		foreach ($identifiers as $identifier) {
			$key = (string)$identifier;
			if (($this->nameCache[$key] ?? null) !== null && isset($this->nameSources[$key]) === true) {
				$run->candidates[$key] = $this->nameCache[$key];
				$this->stats['name_hits']++;
				continue;
			}

			$run->missing[] = $key;
		}

		return $run;
	}//end collectCachedNames()

	/**
	 * Take the missing names the distributed cache holds as a valid envelope.
	 *
	 * @param NameLookupRun $run The lookup.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	private function collectDistributedNames(NameLookupRun $run): void {
		if (empty($run->missing) === true || $this->nameDistributedCache === null) {
			return;
		}

		$decided = [];
		foreach ($run->missing as $key) {
			try {
				$envelope = $this->readNameEnvelope(cached: $this->nameDistributedCache->get('name_' . $key));
				if ($envelope === null) {
					continue;
				}

				$this->nameCache[$key] = $envelope['n'];
				$this->rememberNameOrganisation(key: $key, organisation: $envelope['o'], source: $envelope['s']);
				$run->candidates[$key] = $envelope['n'];
				$decided[] = $key;
				$this->stats['name_hits']++;
			} catch (\Exception $e) {
				// Continue processing other identifiers.
			}//end try
		}

		$run->missing = array_values(array_diff($run->missing, $decided));
	}//end collectDistributedNames()

	/**
	 * Load the still-missing names: organisations first, then objects, then magic-table rows.
	 *
	 * A load failure is logged and leaves the names found so far.
	 *
	 * @param NameLookupRun $run The lookup.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	private function loadNamesFromDatabase(NameLookupRun $run): void {
		$this->stats['name_misses'] += count($run->missing);

		try {
			$this->loadOrganisationNames(run: $run);

			if (empty($run->missing) === false) {
				$this->loadObjectNames(run: $run);
			}

			if (empty($run->missing) === false) {
				$this->loadMagicTableNames(run: $run);
			}
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[CacheHandler] Failed to bulk load names from database',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'identifiers' => count($run->missing),
					'error' => $e->getMessage(),
				]
			);
		}//end try
	}//end loadNamesFromDatabase()

	/**
	 * Load missing identifiers that are organisations; they take priority. An organisation's own tenancy is itself.
	 *
	 * @param NameLookupRun $run The lookup.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	private function loadOrganisationNames(NameLookupRun $run): void {
		$organisations = $this->organisationMapper->findMultipleByUuid($run->missing);
		foreach ($organisations as $organisation) {
			$name = $organisation->getName() ?? $organisation->getUuid();
			$key = $organisation->getUuid();

			$this->setObjectName(
				identifier: $key,
				name: $name,
				organisation: $key,
				source: self::NAME_SOURCE_ORGANISATION
			);
			$run->candidates[$key] = $name;

			$run->missing = array_diff($run->missing, [$key]);
		}
	}//end loadOrganisationNames()

	/**
	 * Load missing identifiers as objects across magic tables.
	 *
	 * An object without a known table has no read rule to ask, so it is left for the magic-table load.
	 *
	 * @param NameLookupRun $run The lookup.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	private function loadObjectNames(NameLookupRun $run): void {
		$objects = $this->getObjectMapper()->findMultiple($run->missing);
		foreach ($objects as $object) {
			$source = self::objectNameSource(register: $object->getRegister(), schema: $object->getSchema());
			if ($source === null) {
				continue;
			}

			$name = $object->getName() ?? $object->getUuid();
			$uuid = $object->getUuid();

			$this->setObjectName(identifier: $uuid, name: $name, organisation: $object->getOrganisation(), source: $source);
			$this->cacheUnderRequestedIdentifier(run: $run, object: $object);

			$run->candidates[$uuid] = $name;
			$run->missing = array_diff($run->missing, [$uuid]);
		}
	}//end loadObjectNames()

	/**
	 * Also cache a loaded object's name under the identifier it was asked by, when that is not its uuid.
	 *
	 * @param NameLookupRun $run    The lookup.
	 * @param ObjectEntity  $object The loaded object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	private function cacheUnderRequestedIdentifier(NameLookupRun $run, ObjectEntity $object): void {
		$uuid = $object->getUuid();
		foreach ($run->missing as $originalId) {
			if ($this->identifiesObject(identifier: (string)$originalId, object: $object) === false) {
				continue;
			}

			if ((string)$originalId !== $uuid) {
				$this->setObjectName(
					identifier: $originalId,
					name: ($object->getName() ?? $uuid),
					organisation: $object->getOrganisation(),
					source: self::objectNameSource(register: $object->getRegister(), schema: $object->getSchema())
				);
				$run->missing = array_diff($run->missing, [$originalId]);
			}

			break;
		}
	}//end cacheUnderRequestedIdentifier()

	/**
	 * Whether an identifier names an object: its uuid, database id, slug or uri.
	 *
	 * @param string       $identifier The requested identifier.
	 * @param ObjectEntity $object     The object.
	 *
	 * @return bool True when the identifier names the object.
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	private function identifiesObject(string $identifier, ObjectEntity $object): bool {
		return $identifier === $object->getUuid()
			|| $identifier === (string)$object->getId()
			|| $identifier === $object->getSlug()
			|| $identifier === $object->getUri();
	}//end identifiesObject()

	/**
	 * Load the still-missing names from the magic tables, one IN query per table instead of N+1 lookups.
	 *
	 * A row without a known source is cached but never becomes a candidate.
	 *
	 * @param NameLookupRun $run The lookup.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	private function loadMagicTableNames(NameLookupRun $run): void {
		$batchResults = $this->batchLoadNamesFromMagicTables(uuids: $run->missing);
		foreach ($batchResults as $uuid => $entry) {
			$this->setObjectName(
				identifier: $uuid,
				name: $entry['name'],
				organisation: $entry['organisation'],
				source: ($entry['source'] ?? null)
			);
			if (($entry['source'] ?? null) !== null) {
				$run->candidates[$uuid] = $entry['name'];
			}
		}
	}//end loadMagicTableNames()

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

		$shouldWarmup = $forceWarmup || empty($this->nameCache);
		if ($shouldWarmup === true) {
			$this->warmupNameCache();
		}

		$uuidNames = $this->visibleUuidNames();
		$this->logger->debug(
			message: '[CacheHandler] All object names retrieved',
			context: [
				'file' => __FILE__, 'line' => __LINE__, 'total_cached' => count($this->nameCache), 'uuid_names_returned' => count($uuidNames),
				'warmup_triggered' => $shouldWarmup, 'execution_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms',
			]
		);

		return $uuidNames;
	}//end getAllObjectNames()

	/**
	 * The UUID to name entries this caller may see.
	 *
	 * Database ids are left out; the cast is there because PHP narrows numeric-looking keys to int.
	 * The name cache is process-wide, so a cache warmed for one caller is never handed whole to the next.
	 *
	 * @return array<string, string> The visible names.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function visibleUuidNames(): array {
		return $this->visibleNames(
			candidates: array_filter($this->nameCache, fn ($key): bool => str_contains((string)$key, '-'), ARRAY_FILTER_USE_KEY)
		);
	}//end visibleUuidNames()

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
		$this->stats['name_warmups']++;

		try {
			return $this->warmAllNames(startTime: microtime(true));
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[CacheHandler] Name cache warmup failed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return 0;
		}
	}//end warmupNameCache()

	/**
	 * Read every name source, persist the result and record the breakdown.
	 *
	 * @param float $startTime When the warm-up started.
	 *
	 * @return int Number of names cached.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function warmAllNames(float $startTime): int {
		$breakdown = $this->warmNameSources();
		$distributedCount = $this->persistNameCacheToDistributed();

		$this->logger->debug(
			message: '[CacheHandler] Name cache warmed up',
			context: [
				'file' => __FILE__, 'line' => __LINE__, 'organisations_processed' => $breakdown['organisations'],
				'objects_processed' => $breakdown['objects_table'], 'magic_names_loaded' => $breakdown['magic_tables'],
				'distributed_cache_stored' => $distributedCount, 'total_names_cached' => count($this->nameCache),
				'execution_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms',
			]
		);

		$breakdown['total_unique'] = count($this->nameCache);
		$this->stats['warmup_breakdown'] = $breakdown;
		return count($this->nameCache);
	}//end warmAllNames()

	/**
	 * Cache the names of organisations, the objects table and, when wired, the magic tables.
	 *
	 * @return array{organisations: int, objects_table: int, magic_tables: int} What each source gave.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function warmNameSources(): array {
		$breakdown = [
			'organisations' => $this->warmOrganisationNames(),
			'objects_table' => $this->warmObjectTableNames(),
			'magic_tables' => 0,
		];

		if ($this->registerMapper !== null && $this->schemaMapper !== null && $this->db !== null) {
			$breakdown['magic_tables'] = $this->loadNamesFromMagicTables();
		}

		return $breakdown;
	}//end warmNameSources()

	/**
	 * Cache every organisation's name under its UUID, recording the organisation as its own tenancy.
	 *
	 * @return int Number of organisations read.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function warmOrganisationNames(): int {
		$organisations = $this->organisationMapper->findAllWithUserCount();
		foreach ($organisations as $organisation) {
			$name = $organisation->getName() ?? $organisation->getUuid();
			if ($organisation->getUuid() !== null && $name !== null) {
				$this->nameCache[$organisation->getUuid()] = $name;
				$this->rememberNameOrganisation(
					key: $organisation->getUuid(),
					organisation: $organisation->getUuid(),
					source: self::NAME_SOURCE_ORGANISATION
				);
			}
		}

		return count($organisations);
	}//end warmOrganisationNames()

	/**
	 * Cache the names of the objects table under their UUID; a name already cached (an organisation's) stays.
	 *
	 * @return int Number of objects read.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function warmObjectTableNames(): int {
		$objects = $this->getObjectMapper()->findAll();
		array_walk($objects, fn (ObjectEntity $object) => $this->warmObjectName(object: $object));

		return count($objects);
	}//end warmObjectTableNames()

	/**
	 * Cache one object's name under its UUID, unless a name is cached there already.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function warmObjectName(ObjectEntity $object): void {
		$name = $object->getName() ?? $object->getUuid();
		$uuid = $object->getUuid();
		if ($uuid === null || $name === null || ($this->nameCache[$uuid] ?? null) !== null) {
			return;
		}

		$this->nameCache[$uuid] = $name;
		$this->rememberNameOrganisation(
			key: $uuid,
			organisation: $object->getOrganisation(),
			source: self::objectNameSource(register: $object->getRegister(), schema: $object->getSchema())
		);
	}//end warmObjectName()

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
			foreach ($this->registerMapper->findAll() as $register) {
				foreach ($register->getSchemas() ?? [] as $schemaId) {
					if ($this->isMagicNameTable(register: $register, schemaId: (int)$schemaId) === true) {
						$loadedCount += $this->loadNamesFromMagicTable(registerId: $register->getId(), schemaId: $schemaId);
					}
				}
			}
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[CacheHandler] Failed to load names from magic tables',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
		}

		return $loadedCount;
	}//end loadNamesFromMagicTables()

	/**
	 * Whether a register keeps a schema's objects in a magic table; an unknown schema is looked up without a slug.
	 *
	 * @param Register $register The register.
	 * @param int      $schemaId The schema id.
	 *
	 * @return bool True when magic mapping is enabled for the schema.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function isMagicNameTable(Register $register, int $schemaId): bool {
		$schemaSlug = null;
		try {
			$schemaSlug = $this->schemaMapper->find($schemaId)->getSlug();
		} catch (\Exception $e) {
			// Schema not found, continue without slug.
		}

		return $register->isMagicMappingEnabledForSchema(schemaId: $schemaId, schemaSlug: $schemaSlug);
	}//end isMagicNameTable()

	/**
	 * Cache the enriched name and tenancy of every live row of one magic table, overwriting earlier names.
	 *
	 * A table that cannot be read is skipped.
	 *
	 * @param mixed $registerId The register id.
	 * @param mixed $schemaId   The schema id.
	 *
	 * @return int Number of rows cached.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function loadNamesFromMagicTable(mixed $registerId, mixed $schemaId): int {
		$tableName = '*PREFIX*openregister_table_' . $registerId . '_' . $schemaId;
		$source = self::objectNameSource(register: $registerId, schema: $schemaId);
		$loadedCount = 0;

		try {
			// SEC-CTRL-2 step 2: `_organisation` is the tenancy oracle for every object-backed
			// name; the ObjectEntity built from a magic row never carries one.
			$result = $this->db->executeQuery('SELECT "_uuid", "_name", "_organisation" FROM ' . $tableName . ' WHERE "_deleted" IS NULL');
			while (($row = $result->fetch()) !== false) {
				$loadedCount += $this->cacheMagicRow(row: $row, source: $source);
			}

			$result->closeCursor();
		} catch (\Exception $e) {
			$this->logger->debug(
				message: '[CacheHandler] Could not query magic table for names',
				context: ['file' => __FILE__, 'line' => __LINE__, 'table' => $tableName, 'error' => $e->getMessage()]
			);
		}

		return $loadedCount;
	}//end loadNamesFromMagicTable()

	/**
	 * Cache one magic row's name, its UUID when the name is blank, and its tenancy.
	 *
	 * @param array       $row    The row.
	 * @param string|null $source The table's name source.
	 *
	 * @return int 1 when the row was cached, 0 when it has no UUID.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function cacheMagicRow(array $row, ?string $source): int {
		$uuid = ($row['_uuid'] ?? null);
		if ($uuid === null) {
			return 0;
		}

		$name = ($row['_name'] ?? null);
		$this->nameCache[$uuid] = $uuid;
		if ($name !== null && trim($name) !== '') {
			$this->nameCache[$uuid] = $name;
		}

		$this->rememberNameOrganisation(key: $uuid, organisation: $row['_organisation'] ?? null, source: $source);
		return 1;
	}//end cacheMagicRow()

	/**
	 * Batch load names for specific UUIDs from magic tables.
	 *
	 * Queries all magic tables with a single IN clause per table to efficiently
	 * resolve multiple UUIDs at once. This replaces the N+1 individual lookups.
	 *
	 * @param array $uuids Array of UUIDs to look up.
	 *
	 * @return array<string, array{name: string, organisation: string|null, source: string|null}> Map of UUID to
	 *                                                                                            name, owning organisation and name source.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Batch loading across multiple table types requires branching
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Table discovery, schema bulk-load
	 * and the per-table batch query, now also carrying each row's owning organisation.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function batchLoadNamesFromMagicTables(array $uuids): array {
		// Filter to only UUID-like strings.
		$uuidList = array_filter(
			$uuids,
			function ($id) {
				return is_string($id) === true && str_contains($id, '-');
			}
		);

		// The magic-table dependencies are optional constructor arguments. Without
		// them there is no tenancy oracle, so the honest answer is "nothing
		// resolved" rather than a fatal on a null mapper.
		if (empty($uuidList) === true
			|| $this->registerMapper === null || $this->schemaMapper === null || $this->db === null
		) {
			return [];
		}

		$results = [];
		try {
			$this->batchLoadNamesFromRegisters(uuidList: $uuidList, results: $results);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[CacheHandler] Failed to batch load names from magic tables',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage(), 'uuid_count' => count($uuids)]
			);
		}//end try

		return $results;
	}//end batchLoadNamesFromMagicTables()

	/**
	 * Walk the magic tables register by register, querying each for the UUIDs not resolved yet.
	 *
	 * Stops as soon as every UUID is resolved. Names found before a failure stay in $results.
	 *
	 * @param array                                                                              $uuidList The UUID-like identifiers to look up.
	 * @param array<string, array{name: string, organisation: string|null, source: string|null}> $results  Map of UUID to name,
	 *                                                                                                     owning organisation and name source.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function batchLoadNamesFromRegisters(array $uuidList, array &$results): void {
		$registers = $this->registerMapper->findAll();
		$schemaMap = $this->loadSchemasOfRegisters(registers: $registers);

		foreach ($registers as $register) {
			foreach ($register->getSchemas() ?? [] as $schemaId) {
				if (count($results) >= count($uuidList)) {
					break 2;
				}

				$schema = $schemaMap[(int)$schemaId] ?? null;
				if ($schema === null
					|| $register->isMagicMappingEnabledForSchema(schemaId: (int)$schemaId, schemaSlug: $schema->getSlug()) === false
				) {
					continue;
				}

				$remainingUuids = array_diff($uuidList, array_keys($results));
				if (empty($remainingUuids) === true) {
					break;
				}

				$tableResults = $this->queryTableForNames(
					tableName: '*PREFIX*openregister_table_' . $register->getId() . '_' . $schemaId,
					uuids: array_values($remainingUuids),
					source: self::objectNameSource(register: $register->getId(), schema: $schemaId)
				);
				$results = array_merge($results, $tableResults);
			}//end foreach
		}//end foreach
	}//end batchLoadNamesFromRegisters()

	/**
	 * Load every schema the registers list in one query, keyed by schema id.
	 *
	 * @param array $registers The registers.
	 *
	 * @return array The schemas keyed by id.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function loadSchemasOfRegisters(array $registers): array {
		$allSchemaIds = [];
		foreach ($registers as $register) {
			foreach ($register->getSchemas() ?? [] as $schemaId) {
				$allSchemaIds[(int)$schemaId] = true;
			}
		}

		if (empty($allSchemaIds) === true) {
			return [];
		}

		return $this->schemaMapper->findMultipleOptimized(ids: array_keys($allSchemaIds));
	}//end loadSchemasOfRegisters()

	/**
	 * Query a single magic table for names by UUIDs.
	 *
	 * @param string $tableName The table name, with the prefix or its `*PREFIX*` placeholder.
	 * @param array $uuids Array of UUIDs to look up.
	 * @param string|null $source The table's name source, "<registerId>:<schemaId>".
	 *
	 * @return array<string, array{name: string, organisation: string|null, source: string|null}> Map of UUID to
	 *                                                                                            name, owning organisation and name source.
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	private function queryTableForNames(string $tableName, array $uuids, ?string $source = null): array {
		if (empty($uuids) === true) {
			return [];
		}

		$query = new NameTableQuery(tableName: $tableName, uuids: $uuids, source: $source);
		foreach (self::NAME_COLUMNS as $nameColumn) {
			try {
				$this->readNameColumn(query: $query, column: $nameColumn);
				if (empty($query->names) === false) {
					return $query->names;
				}
			} catch (\Exception $e) {
				// Column doesn't exist, try next one.
				continue;
			}
		}

		return $query->names;
	}//end queryTableForNames()

	/**
	 * Read one name column of a table into the query.
	 *
	 * @param NameTableQuery $query  The query.
	 * @param string         $column The name column.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	private function readNameColumn(NameTableQuery $query, string $column): void {
		$stmt = $this->db->prepare($query->sqlFor(column: $column));
		foreach ($query->uuids as $index => $uuid) {
			$stmt->bindValue((int)$index + 1, $uuid);
		}

		$stmt->execute();
		while (($row = $stmt->fetch()) !== false) {
			$query->add(row: $row);
		}
	}//end readNameColumn()

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

		$storedCount = $this->storeNamesInDistributed();

		// Store metadata with the count for cross-request stats.
		try {
			$this->nameDistributedCache->set('_metadata_count', $storedCount, self::MAX_CACHE_TTL);
		} catch (\Exception $e) {
			// Ignore metadata storage failures.
		}

		return $storedCount;
	}//end persistNameCacheToDistributed()

	/**
	 * Store every cached name; only a failure before the first success is logged, to avoid log spam.
	 *
	 * @return int Number of names stored.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function storeNamesInDistributed(): int {
		$storedCount = 0;
		foreach ($this->nameCache as $identifier => $name) {
			try {
				$this->storeNameEnvelope(identifier: $identifier, name: $name);
				$storedCount++;
			} catch (\Exception $e) {
				if ($storedCount === 0) {
					$this->logger->warning(
						message: '[CacheHandler] Failed to persist name cache entry to distributed cache',
						context: ['file' => __FILE__, 'line' => __LINE__, 'identifier' => $identifier, 'error' => $e->getMessage()]
					);
				}
			}
		}

		return $storedCount;
	}//end storeNamesInDistributed()

	/**
	 * Store one name with its tenancy.
	 *
	 * SEC-CTRL-2 step 2: the KEY is deliberately unchanged so every existing invalidation site keeps
	 * matching; the tenancy rides in the VALUE, so an entity that moves organisation can never leave an
	 * orphaned entry under a stale prefix.
	 *
	 * @param int|string $identifier The cache identifier.
	 * @param string     $name       The name.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function storeNameEnvelope(int|string $identifier, string $name): void {
		$envelope = $this->buildNameEnvelope(
			name: $name,
			organisation: ($this->nameOrganisations[(string)$identifier] ?? null),
			source: ($this->nameSources[(string)$identifier] ?? null)
		);
		$this->nameDistributedCache->set('name_' . $identifier, $envelope, self::MAX_CACHE_TTL);
	}//end storeNameEnvelope()

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
		$this->forgetNames();
		$this->clearDistributed(cache: $this->nameDistributedCache, failure: ['message' => '[CacheHandler] Failed to clear distributed name cache']);

		$this->logger->debug(
			message: '[CacheHandler] OBJECT NAME CACHE CLEARED',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end clearNameCache()
}//end class
