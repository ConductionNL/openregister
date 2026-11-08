<?php

/**
 * OpenRegister Facet Handler
 *
 * **CENTRALIZED FACETING SYSTEM**: This handler manages all faceting operations
 * with intelligent fallback strategies, response caching, and performance optimization.
 * Solves the fundamental pagination vs faceting architectural conflict.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Service\Object
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-type-auto-detection-from-property-definitions
 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
 * @spec openspec/specs/faceting-configuration/spec.md#requirement-metadata-facets-via-self-namespace
 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-discovery-via-facetable-parameter
 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-request-configuration-via-facets-parameter
 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-response-standardized-format
 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-performance-optimization-via-hyperfacethandler
 * @spec openspec/specs/faceting-configuration/spec.md
 * @spec openspec/specs/faceting-configuration/spec.md
 * @spec openspec/specs/faceting-configuration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\PropertyRbacHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Facet Handler - Centralized Faceting Operations
 *
 * **ARCHITECTURAL BREAKTHROUGH**: Handles faceting concerns with intelligent fallback strategies
 * that solve the pagination vs faceting conflict.
 *
 * **KEY FEATURES**:
 * - Smart Fallback: Collection-wide facets when filters return empty.
 * - Response Caching: Lightning-fast repeated requests.
 * - Clean Architecture: Integrated handler pattern.
 * - Performance Optimized: Multiple optimization strategies.
 * - Backwards Compatible: Drop-in replacement for existing faceting.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://OpenRegister.app
 * @version  2.0.0
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex faceting logic with multiple strategies
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class FacetHandler {

	/**
	 * Constructor for FacetHandler.
	 *
	 * @param MagicMapperInterface $unifiedObjectMapper Unified object mapper with storage routing.
	 * @param SchemaMapperInterface $schemaMapper Schema database mapper.
	 * @param FacetResponseCache $responseCache The response cache in front of facet computation.
	 * @param LoggerInterface $logger Logger for debugging and monitoring.
	 * @param PropertyRbacHandlerInterface|null $propertyRbac Withholds a facet over a property the caller may not read.
	 *                                               Nullable and last so no construction site shifts; absent, a
	 *                                               governed property is withheld, which is the safe direction.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	public function __construct(
		private readonly MagicMapperInterface $unifiedObjectMapper,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly FacetResponseCache $responseCache,
		private readonly LoggerInterface $logger,
		// LAST AND NULLABLE so every existing construction keeps working. The
		// container always supplies it; null happens only in a hand-built test,
		// and then a GOVERNED property is withheld, which is the safe direction.
		private readonly ?PropertyRbacHandlerInterface $propertyRbac = null,
	) {
	}//end __construct()

	/**
	 * Get facets for objects based on query.
	 *
	 * **BREAKTHROUGH SOLUTION**: Solves the pagination vs faceting conflict by implementing
	 * intelligent fallback strategies that ensure facets are always meaningful and relevant.
	 *
	 * **STRATEGY**:
	 * 1. Check response cache first (lightning-fast repeated requests).
	 * 2. Try facets on current filtered dataset.
	 * 3. If empty + restrictive filters → fall back to collection-wide facets.
	 * 4. Cache results for future requests.
	 * 5. Include performance metadata.
	 *
	 * **PAGINATION INDEPENDENCE**: Facets are calculated on the complete filtered dataset,
	 * ignoring pagination parameters (_limit, _offset, _page) to ensure users always see
	 * relevant navigation options regardless of current page or limit.
	 *
	 * @param array $query The search query array.
	 *
	 * @throws \OCP\DB\Exception If database error occurs.
	 *
	 * @psalm-param   array<string, mixed> $query
	 * @phpstan-param array<string, mixed> $query
	 *
	 * @return array Facet results with intelligent fallback and performance metadata.
	 *
	 * @psalm-return   array<string, mixed>
	 * @phpstan-return array<string, mixed>
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-request-configuration-via-facets-parameter
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	public function getFacetsForObjects(array $query = []): array {
		$startTime = microtime(true);
		$facetConfig = $this->facetConfigOf(query: $query);
		if ($facetConfig === null) {
			return ['facets' => []];
		}

		$facetQuery = $this->withoutPaging(query: $query);
		$cacheKey = $this->responseCache->keyFor(facetQuery: $facetQuery, facetConfig: $facetConfig);
		return $this->responseCache->get(cacheKey: $cacheKey)
			?? $this->storedFacets(cacheKey: $cacheKey, run: new FacetRun(query: $facetQuery, startTime: $startTime));
	}//end getFacetsForObjects()

	/**
	 * Compute the facets of a run, store them and log the outcome.
	 *
	 * @param string   $cacheKey The response cache key.
	 * @param FacetRun $run      The facet query and when the request started.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-request-configuration-via-facets-parameter
	 */
	private function storedFacets(string $cacheKey, FacetRun $run): array {
		$schemas = $this->getSchemasForQuery(baseQuery: $run->query);
		$facetableConfig = $this->getFacetableFieldsFromSchemas(schemas: $schemas);
		$result = $this->calculateFacetsWithFallback(facetQuery: $run->query, facetableConfig: $facetableConfig);
		$result['performance_metadata']['total_execution_time_ms'] = $run->elapsedMs();
		$this->responseCache->put(cacheKey: $cacheKey, result: $result);
		$this->logCompletion(result: $result);
		return $result;
	}//end storedFacets()

	/**
	 * Log a finished facet calculation.
	 *
	 * @param array<string, mixed> $result The calculated facets with their performance metadata.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-request-configuration-via-facets-parameter
	 */
	private function logCompletion(array $result): void {
		$metadata = $result['performance_metadata'];
		$this->logger->debug(
			message: '[FacetHandler] FacetHandler completed facet calculation',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'executionTime' => $metadata['total_execution_time_ms'] . 'ms',
				'strategy' => $metadata['strategy'] ?? 'unknown',
				'cacheUsed' => false,
				'totalFacetResults' => $metadata['total_facet_results'] ?? 0,
			]
		);
	}//end logCompletion()

	/**
	 * The requested facets as a list, or null when none are requested; a string (`_facets=extend`) is one entry.
	 *
	 * @param array<string, mixed> $query The search query.
	 *
	 * @return array<int|string, mixed>|null
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-request-configuration-via-facets-parameter
	 */
	private function facetConfigOf(array $query): ?array {
		$facetConfig = $query['_facets'] ?? [];
		if (empty($facetConfig) === true) {
			return null;
		}

		if (is_string($facetConfig) === true) {
			return [$facetConfig];
		}

		return $facetConfig;
	}//end facetConfigOf()

	/**
	 * The query without its paging keys: facets describe the whole filtered dataset, not one page.
	 *
	 * @param array<string, mixed> $query The search query.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-request-configuration-via-facets-parameter
	 */
	private function withoutPaging(array $query): array {
		unset($query['_limit'], $query['_offset'], $query['_page'], $query['_facetable']);
		return $query;
	}//end withoutPaging()

	/**
	 * Get facetable fields for discovery.
	 *
	 * PERFORMANCE OPTIMIZED**: Uses pre-computed schema facets stored in database
	 * instead of runtime analysis for lightning-fast _facetable=true requests.
	 *
	 * @param array $baseQuery Base query filters to apply for context.
	 * @param int $_sampleSize Sample size (kept for backward compatibility).
	 *
	 * @psalm-param array<string, mixed> $baseQuery
	 * @psalm-param int $_sampleSize
	 *
	 * @phpstan-param array<string, mixed> $baseQuery
	 * @phpstan-param int $_sampleSize
	 *
	 * @return array[]
	 *
	 * @psalm-return   array{'@self': array, object_fields: array}
	 * @phpstan-return array<string, mixed>
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-discovery-via-facetable-parameter
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	public function getFacetableFields(array $baseQuery = [], int $_sampleSize = 100): array {
		$run = new FacetRun(query: $baseQuery, startTime: microtime(true));
		$schemas = $this->getSchemasForQuery(baseQuery: $run->query);
		$facetableFields = $this->getFacetableFieldsFromSchemas(schemas: $schemas);
		$fieldCount = count($facetableFields['@self'] ?? []) + count($facetableFields['object_fields'] ?? []);
		$counts = ['schemaCount' => count($schemas), 'facetableFieldCount' => $fieldCount];
		$this->logger->debug(
			message: '[FacetHandler] Facetable fields discovery completed',
			context: ['file' => __FILE__, 'line' => __LINE__, 'executionTime' => $run->elapsedMs() . 'ms'] + $counts
		);
		return $facetableFields;
	}//end getFacetableFields()

	/**
	 * Calculate Facets with Intelligent Fallback Strategy.
	 *
	 * Implements smart fallback logic that ensures users always see meaningful facet
	 * options, even when their current search/filters return zero results.
	 * Also handles non-aggregated facets by making separate schema-scoped queries.
	 *
	 * @param array $facetQuery      Query for facet calculation (without pagination).
	 * @param array $facetableConfig Facetable field configuration from schema discovery.
	 *
	 * @return array Facets with performance metadata including strategy and fallback status.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-request-configuration-via-facets-parameter
	 */
	private function calculateFacetsWithFallback(array $facetQuery, array $facetableConfig = []): array {
		return (new FacetCalculation(mapper: $this->unifiedObjectMapper, logger: $this->logger))
			->calculate(facetQuery: $facetQuery, facetableConfig: $facetableConfig);
	}//end calculateFacetsWithFallback()

	/**
	 * Get schemas relevant to the current query (cached for performance).
	 *
	 * @param array $baseQuery Base query with register/schema filters.
	 *
	 * @return \OCA\OpenRegister\Db\Schema[] Array of Schema objects.
	 *
	 * @psalm-return array<\OCA\OpenRegister\Db\Schema>
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	private function getSchemasForQuery(array $baseQuery): array {
		$schemasPlural = $baseQuery['@self']['schemas'] ?? $baseQuery['_schemas'] ?? null;
		if (is_array($schemasPlural) === true && count($schemasPlural) > 0) {
			return $this->schemaMapper->findMultiple(ids: array_map('intval', $schemasPlural), _rbac: false, _multitenancy: false);
		}

		$schemaFilter = $baseQuery['@self']['schema'] ?? null;
		if ($schemaFilter !== null) {
			return $this->schemasMatching(schemaFilter: $schemaFilter);
		}

		// Null = no limit: collection-wide discovery reads every schema.
		return $this->schemaMapper->findAll(limit: null, _rbac: false, _multitenancy: false);
	}//end getSchemasForQuery()

	/**
	 * The schemas a singular `@self[schema]` filter names, without RBAC: schemas are system-level.
	 *
	 * @param mixed $schemaFilter A schema id or a list of them.
	 *
	 * @return array<int, mixed> The schemas; none when a single id cannot be loaded.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-discovery-via-facetable-parameter
	 */
	private function schemasMatching(mixed $schemaFilter): array {
		if (is_array($schemaFilter) === true) {
			return $this->schemaMapper->findMultiple(ids: $schemaFilter, _rbac: false, _multitenancy: false);
		}

		try {
			return [$this->schemaMapper->find(id: $schemaFilter, _multitenancy: false, _rbac: false)];
		} catch (\Exception $e) {
			return [];
		}
	}//end schemasMatching()

	/**
	 * Get facetable fields from schema configurations.
	 *
	 * Discovers facetable fields from schema properties, supporting both
	 * `facetable: true` (boolean) and `facetable: { aggregated, title, description, order }` (config object).
	 * Non-aggregated fields are tracked separately with their schema context.
	 *
	 * @param array $schemas Array of Schema objects.
	 *
	 * @return array[] Facetable field configuration with non-aggregated field metadata.
	 *
	 * @psalm-return array{'@self': array, object_fields: array, non_aggregated_fields: array}
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-discovery-via-facetable-parameter
	 */
	private function getFacetableFieldsFromSchemas(array $schemas): array {
		return (new FacetableFieldDiscovery(logger: $this->logger, propertyRbac: $this->propertyRbac))->fieldsOf(schemas: $schemas);
	}//end getFacetableFieldsFromSchemas()

}//end class
