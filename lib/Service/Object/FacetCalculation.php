<?php

/**
 * OpenRegister FacetCalculation
 *
 * The facets of the filtered dataset.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/faceting-configuration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;


use OCA\OpenRegister\Db\MagicMapperInterface;
use Psr\Log\LoggerInterface;

/**
 * Asks the mapper for the facets of a query and computes each non-aggregated field for its own schema.
 *
 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
 */
final class FacetCalculation {

	/**
	 * Constructor.
	 *
	 * @param MagicMapperInterface $mapper The object mapper.
	 * @param LoggerInterface      $logger The logger.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function __construct(
		private readonly MagicMapperInterface $mapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The facets of the query in the standard format, with how they were calculated.
	 *
	 * @param array<string, mixed> $facetQuery      The facet query.
	 * @param array<string, mixed> $facetableConfig The discovered facetable fields.
	 *
	 * @return array{facets: array<string, mixed>, performance_metadata: array<string, mixed>}
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-response-standardized-format
	 */
	public function calculate(array $facetQuery, array $facetableConfig): array {
		$facets = $this->mergedFacets(facetQuery: $facetQuery, facetableConfig: $facetableConfig);
		$performanceMetadata = $this->performanceMetadata(facets: $facets, facetQuery: $facetQuery);
		unset($facets['_metrics']);
		return [
			'facets' => (new FacetListing(facetableConfig: $facetableConfig))->format(facets: $facets),
			'performance_metadata' => $performanceMetadata,
		];
	}//end calculate()

	/**
	 * The facets of the filtered dataset, with the non-aggregated fields computed per schema.
	 *
	 * Facets stay scoped to the filters even when empty: counts over the whole collection
	 * after a filter was selected are misleading (#453).
	 *
	 * @param array<string, mixed> $facetQuery      The facet query.
	 * @param array<string, mixed> $facetableConfig The discovered facetable fields.
	 *
	 * @return array<int|string, mixed>
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private function mergedFacets(array $facetQuery, array $facetableConfig): array {
		$facets = $this->mapper->getSimpleFacets($facetQuery);
		if ($this->hasRestrictiveFilters(query: $facetQuery) === true && $this->countFacetResults(facets: $facets) === 0) {
			$this->logEmptyFilteredFacets(facetQuery: $facetQuery);
		}

		$nonAggregatedFields = ($facetableConfig['non_aggregated_fields'] ?? []);
		return array_replace(
			$this->withoutNonAggregatedOnlyFields(facets: $facets, facetableConfig: $facetableConfig),
			$this->nonAggregatedFacets(facetQuery: $facetQuery, nonAggregatedFields: $nonAggregatedFields)
		);
	}//end mergedFacets()

	/**
	 * What a response says about how it was calculated.
	 *
	 * @param array<int|string, mixed> $facets     The facets, with `_metrics` when the mapper gave any.
	 * @param array<string, mixed>     $facetQuery The facet query.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-performance-optimization-via-hyperfacethandler
	 */
	private function performanceMetadata(array $facets, array $facetQuery): array {
		$metrics = $facets['_metrics'] ?? null;
		unset($facets['_metrics']);
		$metadata = [
			'strategy' => 'filtered',
			'fallback_used' => false,
			'total_facet_results' => $this->countFacetResults(facets: $facets),
			'has_restrictive_filters' => $this->hasRestrictiveFilters(query: $facetQuery),
		];
		if ($metrics !== null) {
			$metadata['facet_db_ms'] = $metrics;
		}

		return $metadata;
	}//end performanceMetadata()

	/**
	 * Note that a filtered query found no facet results; there is no collection-wide fallback.
	 *
	 * @param array $facetQuery Query for facet calculation (without pagination).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-request-configuration-via-facets-parameter
	 */
	private function logEmptyFilteredFacets(array $facetQuery): void {
		$this->logger->debug(
			message: '[FacetHandler] Facets empty with restrictive filters — returning empty facets (no fallback)',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'originalQuery' => array_keys($facetQuery),
				'totalResults' => 0,
			]
		);
	}//end logEmptyFilteredFacets()

	/**
	 * Drop the aggregated facets of fields that are configured ONLY as non-aggregated.
	 *
	 * The mapper's getSimpleFacets returns every field of the magic table, but a field that is exclusively
	 * non-aggregated (not in object_fields) gets its own schema-scoped facet instead.
	 *
	 * @param array $facets          Raw facets from the mapper
	 * @param array $facetableConfig Facetable field configuration from schema discovery
	 *
	 * @return array The facets without those fields
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private function withoutNonAggregatedOnlyFields(array $facets, array $facetableConfig): array {
		$aggregatedFieldKeys = array_keys($facetableConfig['object_fields'] ?? []);
		foreach ($facetableConfig['non_aggregated_fields'] ?? [] as $afterField) {
			$fieldName = $afterField['field'];
			if (in_array($fieldName, $aggregatedFieldKeys, true) === false && isset($facets[$fieldName]) === true) {
				unset($facets[$fieldName]);
			}
		}

		return $facets;
	}//end withoutNonAggregatedOnlyFields()

	/**
	 * The schema-scoped facets of every non-aggregated field, keyed by their unique facet key.
	 *
	 * @param array $facetQuery          Query for facet calculation (without pagination)
	 * @param array $nonAggregatedFields The non-aggregated fields: field, schemaId and facetConfig
	 *
	 * @return array The non-aggregated facets, each with its metadata for the transform step
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private function nonAggregatedFacets(array $facetQuery, array $nonAggregatedFields): array {
		$facets = [];
		foreach ($nonAggregatedFields as $afterField) {
			$facets = array_replace(
				$facets,
				$this->nonAggregatedFacet(facetQuery: $facetQuery, afterField: $afterField)
			);
		}

		return $facets;
	}//end nonAggregatedFacets()

	/**
	 * One non-aggregated field's facet from a query scoped to its schema; none when the query fails.
	 *
	 * The plural schema/register keys are removed so getSimpleFacets() takes the single-schema
	 * path instead of the multi-schema UNION path. Every other key of the query is kept.
	 *
	 * @param array $facetQuery Query for facet calculation (without pagination)
	 * @param array $afterField The non-aggregated field: field, schemaId and facetConfig
	 *
	 * @return array The facet under its unique key, or an empty array
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private function nonAggregatedFacet(array $facetQuery, array $afterField): array {
		$scopedQuery = $this->scopedToSchema(facetQuery: $facetQuery, schemaId: $afterField['schemaId']);
		try {
			$scopedFacets = $this->mapper->getSimpleFacets($scopedQuery);
		} catch (\Exception $e) {
			$this->logNonAggregatedFailure(afterField: $afterField, exception: $e);
			return [];
		}

		unset($scopedFacets['_metrics']);
		if (isset($scopedFacets[$afterField['field']]) === false) {
			return [];
		}

		$key = new NonAggregatedFacetKey(fieldName: $afterField['field'], schemaId: $afterField['schemaId']);
		return [$key->under(facetConfig: $afterField['facetConfig']) => $this->marked(facet: $scopedFacets[$afterField['field']], afterField: $afterField)];
	}//end nonAggregatedFacet()

	/**
	 * The facet query narrowed to one schema; every other filter stays.
	 *
	 * @param array<string, mixed> $facetQuery The facet query.
	 * @param mixed                $schemaId   The schema id.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private function scopedToSchema(array $facetQuery, mixed $schemaId): array {
		$facetQuery['@self']['schema'] = $schemaId;
		unset($facetQuery['@self']['schemas'], $facetQuery['_schemas']);
		return $facetQuery;
	}//end scopedToSchema()

	/**
	 * A scoped facet with the markers the response format reads.
	 *
	 * @param mixed                $facet      The scoped facet.
	 * @param array<string, mixed> $afterField The non-aggregated field.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private function marked(mixed $facet, array $afterField): array {
		$facet['_nonAggregated'] = true;
		$facet['_schemaId'] = $afterField['schemaId'];
		$facet['_facetConfig'] = $afterField['facetConfig'];
		$facet['_fieldName'] = $afterField['field'];
		return $facet;
	}//end marked()

	/**
	 * Note that a non-aggregated field's scoped facet query failed; the field is left out.
	 *
	 * @param array      $afterField The non-aggregated field: field and schemaId
	 * @param \Exception $exception  What the scoped query threw
	 *
	 * @return void
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private function logNonAggregatedFailure(array $afterField, \Exception $exception): void {
		$this->logger->warning(
			message: '[FacetHandler] Failed to get non-aggregated facet',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'field' => $afterField['field'],
				'schemaId' => $afterField['schemaId'],
				'error' => $exception->getMessage(),
			]
		);
	}//end logNonAggregatedFailure()

	/**
	 * Count total results across all facet buckets.
	 *
	 * @param array $facets Facet data structure.
	 *
	 * @return int Total number of facet results.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-request-configuration-via-facets-parameter
	 */
	private function countFacetResults(array $facets): int {
		$total = 0;
		foreach (array_filter($facets, 'is_array') as $facetGroup) {
			foreach ($facetGroup as $facet) {
				$total += $this->bucketResults(facet: $facet);
			}
		}

		return $total;
	}//end countFacetResults()

	/**
	 * The summed results of one facet's buckets; anything without a bucket list counts nothing.
	 *
	 * @param mixed $facet The facet.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	private function bucketResults(mixed $facet): int {
		if (is_array($facet['buckets'] ?? null) === false) {
			return 0;
		}

		return array_sum(array_map(fn ($bucket): int => (int)($bucket['results'] ?? 0), $facet['buckets']));
	}//end bucketResults()

	/**
	 * Check if query has restrictive filters that might eliminate all results.
	 *
	 * @param array $query Query parameters.
	 *
	 * @return bool True if query has restrictive filters.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	private function hasRestrictiveFilters(array $query): bool {
		if (empty($query['_search']) === false) {
			return true;
		}

		foreach ($query as $key => $value) {
			if ($this->isFieldFilter(key: $key, value: $value) === true) {
				return true;
			}
		}

		return false;
	}//end hasRestrictiveFilters()

	/**
	 * Whether a query entry filters on an object field: set, and not a `_` or `@self` key.
	 *
	 * @param string $key   The query key.
	 * @param mixed  $value The value.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	private function isFieldFilter(string $key, mixed $value): bool {
		return str_starts_with($key, '_') === false && $key !== '@self' && empty($value) === false;
	}//end isFieldFilter()
}//end class
