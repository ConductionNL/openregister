<?php

/**
 * ObjectNameCacheInterface: the object-name cache that turns ids into display names.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Reads, warms and clears the cache of object display names. Implemented by CacheHandler.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) forceWarmup mirrors CacheHandler's existing public signature.
 */
interface ObjectNameCacheInterface {

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
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getAllObjectNames(bool $forceWarmup = false): array;

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
}//end interface
