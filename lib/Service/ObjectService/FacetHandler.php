<?php

/**
 * FacetHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\ObjectService;

use OCA\OpenRegister\Service\FacetService;

/**
 * Handles faceting operations for ObjectService.
 *
 * This handler wraps FacetService to provide a consistent interface
 * and acts as a facade for faceting operations.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class FacetHandler
{


    /**
     * Constructor for FacetHandler.
     *
     * @param FacetService $facetService Service for facet operations.
     */
    public function __construct(
    private readonly FacetService $facetService
    ) {
    }//end __construct()


    /**
     * Get facets for objects based on query.
     *
     * @param array $query The search query array.
     *
     * @return array Facet results.
     *
     * @psalm-param    array<string, mixed> $query
     * @phpstan-param  array<string, mixed> $query
     * @psalm-return   array<string, mixed>
     * @phpstan-return array<string, mixed>
     */
    public function getFacetsForObjects(array $query = []): array
    {
        return $this->facetService->getFacetsForQuery($query);
    }//end getFacetsForObjects()


    /**
     * Get facetable fields for discovery.
     *
     * @param array $baseQuery  Base query filters to apply for context.
     * @param int   $sampleSize Sample size (kept for backward compatibility).
     *
     * @return array Facetable field information.
     *
     * @psalm-param    array<string, mixed> $baseQuery
     * @phpstan-param  array<string, mixed> $baseQuery
     * @psalm-param    int $sampleSize
     * @phpstan-param  int $sampleSize
     * @psalm-return   array<string, mixed>
     * @phpstan-return array<string, mixed>
     */
    public function getFacetableFields(array $baseQuery = [], int $sampleSize = 100): array
    {
        return $this->facetService->getFacetableFields(baseQuery: $baseQuery, _limit: $sampleSize);
    }//end getFacetableFields()


    /**
     * Get metadata facetable fields.
     *
     * @return array<int, string> List of metadata field names that are facetable.
     *
     * @psalm-return   list<string>
     * @phpstan-return array<int, string>
     */
    public function getMetadataFacetableFields(): array
    {
        return [
            'register',
            'schema',
            'owner',
            'organisation',
            'created',
            'updated',
        ];
    }//end getMetadataFacetableFields()


    /**
     * Calculate facet count for performance metrics.
     *
     * @param bool  $hasFacets Whether facets were requested.
     * @param array $query     The query array.
     *
     * @return int The facet count.
     *
     * @psalm-param    bool $hasFacets
     * @phpstan-param  bool $hasFacets
     * @psalm-param    array<string, mixed> $query
     * @phpstan-param  array<string, mixed> $query
     * @psalm-return   int
     * @phpstan-return int
     */
    public function getFacetCount(bool $hasFacets, array $query): int
    {
        if ($hasFacets === false) {
            return 0;
        }

        $facets = $query['_facets'] ?? [];
        if (is_array($facets) === true) {
            return count($facets);
        }

        return 0;
    }//end getFacetCount()
}//end class
