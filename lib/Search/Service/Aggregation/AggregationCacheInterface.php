<?php

/**
 * AggregationCacheInterface: the public surface of AggregationCache, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search\Service\Aggregation
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

namespace OCA\OpenRegister\Search\Service\Aggregation;

use OCP\ICache;

/**
 * Every public method of AggregationCache. Callers depend on this interface, not on the class.
 */
interface AggregationCacheInterface {

	/**
	 * Look up a cached aggregation result.
	 *
	 * Returns the cached associative array (with the same shape
	 * AggregationRunner emits) or null on miss.
	 *
	 * @param string $registerSlug Register slug component of the cache key.
	 * @param string $schemaSlug Schema slug component of the cache key.
	 * @param string $name Aggregation name component of the cache key.
	 * @param array<string, mixed> $filter Resolved filter (placeholders concrete).
	 *
	 * @return array<string, mixed>|null Cached result or null on miss.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-20
	 */
	public function get(string $registerSlug, string $schemaSlug, string $name, array $filter): ?array;

	/**
	 * Store an aggregation result.
	 *
	 * @param string $registerSlug Register slug component of the cache key.
	 * @param string $schemaSlug Schema slug component of the cache key.
	 * @param string $name Aggregation name component of the cache key.
	 * @param array<string, mixed> $filter Resolved filter (placeholders concrete).
	 * @param array<string, mixed> $result Result envelope to store.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/aggregations-backend-native/spec.md
	 */
	public function set(string $registerSlug, string $schemaSlug, string $name, array $filter, array $result): void;

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
	public function getAdhoc(string $registerSlug, string $schemaSlug, AggregationQuery $query): ?array;

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
	public function setAdhoc(string $registerSlug, string $schemaSlug, AggregationQuery $query, array $result): void;

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
	public function evictForSchema(string $registerSlug, string $schemaSlug): void;
}//end interface
