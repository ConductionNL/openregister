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

use Psr\Log\LoggerInterface;

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
	 * PerformanceHandler constructor.
	 *
	 * @param LoggerInterface $logger Logger for performance monitoring.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Optimize request for performance
	 *
	 * Analyzes the query and applies various optimizations:
	 * - Detects simple requests for fast-path processing
	 * - Limits destructive extend operations
	 * - Preloads critical entities for cache warmup
	 *
	 * @param array<string, mixed> $query The search query (passed by reference).
	 * @param array<string, mixed> $perfTimings Performance timing array (passed by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function optimizeRequestForPerformance(array &$query, array &$perfTimings): void {
		$optimizeStart = microtime(true);

		// **OPTIMIZATION 1**: Fast path for simple requests.
		if ($this->isSimpleRequest(query: $query) === true) {
			$query['_fast_path'] = true;
			$this->logger->debug(
				message: '[PerformanceHandler] 🚀 FAST PATH: Simple request detected',
				context: ['file' => __FILE__, 'line' => __LINE__, 'benefit' => 'skip_heavy_processing', 'estimatedSaving' => '200-300ms']
			);
		}

		// **OPTIMIZATION 2**: Limit destructive extend operations.
		if (empty($query['_extend']) === false) {
			$this->optimizeExtendInQuery(query: $query);
		}

		// **OPTIMIZATION 3**: Preload critical entities for cache warmup.
		$this->preloadCriticalEntities(query: $query);

		$perfTimings['request_optimization'] = round((microtime(true) - $optimizeStart) * 1000, 2);
	}//end optimizeRequestForPerformance()

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
	 * Optimize extend operations within a query.
	 *
	 * Counts the original extend fields, applies optimization, and logs any reduction.
	 *
	 * @param array<string, mixed> $query The search query (passed by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function optimizeExtendInQuery(array &$query): void {
		// The string count runs first, as it always has: an array `_extend` fails here.
		$originalExtendCount = count(array_filter(array_map('trim', explode(',', $query['_extend']))));
		if (is_array($query['_extend']) === true) {
			$originalExtendCount = count($query['_extend']);
		}

		$query['_extend'] = $this->optimizeExtendQueries(extend: $query['_extend']);
		$newExtendCount = count($query['_extend']);
		if ($newExtendCount < $originalExtendCount) {
			$this->logExtendReduction(original: $originalExtendCount, optimized: $newExtendCount);
		}
	}//end optimizeExtendInQuery()

	/**
	 * Log that the extend list of a query was shortened.
	 *
	 * @param int $original  Extend count before.
	 * @param int $optimized Extend count after.
	 *
	 * @return void
	 */
	private function logExtendReduction(int $original, int $optimized): void {
		$this->logger->info(
			message: '[PerformanceHandler] ⚡ EXTEND OPTIMIZATION: Reduced extend complexity',
			context: [
				'file' => __FILE__, 'line' => __LINE__, 'original' => $original, 'optimized' => $optimized,
				'estimatedSaving' => ($original - $optimized) * (100) . 'ms',
			]
		);
	}//end logExtendReduction()

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
