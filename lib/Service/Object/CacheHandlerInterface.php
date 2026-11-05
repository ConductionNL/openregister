<?php

/**
 * CacheHandlerInterface: the public surface of CacheHandler, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Kernel\Contracts\ObjectNameCacheInterface;

/**
 * Every public method of CacheHandler. Callers depend on this interface, not on the class.
 */
interface CacheHandlerInterface extends ObjectNameCacheInterface {

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
	public function getObject(int|string $identifier): ?ObjectEntity;

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
	public function preloadObjects(array $identifiers, ?int $registerId = null): array;

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
	public function getStats(): array;

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
	public function clearSearchCache(?string $pattern = null): void;

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
		?ObjectEntity $object = null, string $operation = 'unknown', ?int $registerId = null, ?int $schemaId = null
	): void;

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
	public function clearAllCaches(): void;

	/**
	 * Clear the cache (legacy method - kept for backward compatibility)
	 *
	 * @deprecated Use clearAllCaches() instead
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function clearCache(): void;

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
	public function setObjectName(string|int $identifier, string $name, int $ttl = 86400, ?string $organisation = null, ?string $source = null): void;

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
	public function getSingleObjectName(string|int $identifier): ?string;

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
	public function getMultipleObjectNames(array $identifiers): array;

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
	public function getAllObjectNames(bool $forceWarmup = false): array;

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
	public function warmupNameCache(): int;

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
	public function getDistributedNameCacheCount(): int;

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
	public function clearNameCache(): void;
}//end interface
