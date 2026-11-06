<?php

/**
 * PerformanceHandler - Performance Optimization and Monitoring Handler
 *
 * Handles performance optimization, caching strategies, and performance monitoring.
 * This handler consolidates performance-related operations from ObjectService,
 * improving code organization and making optimization logic more maintainable.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;


/**
 * PerformanceHandler class
 *
 * Handles performance operations including:
 * - Request optimization and fast-path detection
 * - Extend query optimization
 * - Entity caching and preloading
 * - Related data extraction
 * - Performance calculations and monitoring
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Performance monitoring methods require per-metric branching
 */
class PerformanceHandler {
	/**
	 * Determine if this is a simple request that can use the fast path
	 *
	 * Simple requests have:
	 * - No complex extend operations (≤ 2)
	 * - No facets or facetable queries
	 * - Small result set (limit ≤ 50)
	 * - Few filter criteria (< 3)
	 *
	 * @param array<string, mixed> $query The search query.
	 *
	 * @return bool True if this is a simple request
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple criteria for determining request simplicity
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function isSimpleRequest(array $query): bool {
		return !($this->extendCount(query: $query) > 2
			|| $this->wantsFacets(query: $query)
			|| ($query['_limit'] ?? 20) > 50
			|| $this->filterCount(query: $query) > 3);
	}//end isSimpleRequest()

	/**
	 * How many relations a query extends; `_extend` may be a list or a comma-separated string.
	 *
	 * @param array<string, mixed> $query The search query.
	 *
	 * @return int
	 */
	private function extendCount(array $query): int {
		if (empty($query['_extend']) === true) {
			return 0;
		}

		if (is_array($query['_extend']) === true) {
			return count($query['_extend']);
		}

		if (is_string($query['_extend']) === true) {
			return count(array_filter(array_map('trim', explode(',', $query['_extend']))));
		}

		return 0;
	}//end extendCount()

	/**
	 * Whether a query asks for facets.
	 *
	 * @param array<string, mixed> $query The search query.
	 *
	 * @return bool
	 */
	private function wantsFacets(array $query): bool {
		return empty($query['_facets']) === false || ($query['_facetable'] ?? false);
	}//end wantsFacets()

	/**
	 * How many filter criteria a query has: keys that start with neither `_` nor `@`.
	 *
	 * @param array<string, mixed> $query The search query.
	 *
	 * @return int
	 */
	private function filterCount(array $query): int {
		$filterCount = 0;
		foreach (array_keys($query) as $key) {
			if (str_starts_with(haystack: $key, needle: '_') === false && str_starts_with(haystack: $key, needle: '@') === false) {
				$filterCount++;
			}
		}

		return $filterCount;
	}//end filterCount()

	/**
	 * Optimize extend queries for performance
	 *
	 * Analyzes and optimizes extend field requests to reduce unnecessary data loading.
	 *
	 * @param array<string>|string $extend Original extend data (array or comma-separated string).
	 *
	 * @return array<string> Optimized extend array
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function optimizeExtendQueries(array|string $extend): array {
		// **BUGFIX**: Handle _extend as both string and array.
		if (is_string($extend) === true) {
			if (trim($extend) === '') {
				return [];
			}

			// Convert comma-separated string to array.
			return array_filter(array_map('trim', explode(',', $extend)));
		}

		// For now, just return the array (future optimization: analyze and reduce).
		// Future improvements:
		// - Remove circular extends.
		// - Limit extend depth.
		// - Remove duplicate extends.
		return $extend;
	}//end optimizeExtendQueries()

	/**
	 * Preload critical entities for cache warmup
	 *
	 * Preloads schemas and other critical entities to warm up the cache
	 * before the main query executes, reducing database round-trips.
	 *
	 * @param array<string, mixed> $query The search query.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function preloadCriticalEntities(array $query): void {
		// Preloading is currently disabled - this is a placeholder for future cache warmup logic.
		// Future improvements:
		// - Preload schemas based on query.
		// - Preload registers.
		// - Warm up object cache for frequently accessed objects.
		return;
	}//end preloadCriticalEntities()

}//end class
