<?php

/**
 * OpenRegister ObjectService Facet Examples
 *
 * This file contains comprehensive examples demonstrating the new faceting
 * capabilities integrated into the ObjectService, showing both new and legacy approaches.
 *
 * @category Example
 * @package  OCA\OpenRegister\Service\ObjectHandlers
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\ObjectHandlers;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Examples demonstrating the complete faceting system through ObjectService
 *
 * This class provides practical examples of how to use the new facet system
 * through the ObjectService, including both getFacetsForObjects and 
 * searchObjectsPaginated methods.
 */
class ObjectServiceFacetExample
{

    /**
     * Constructor for ObjectServiceFacetExample
     *
     * @param ObjectService $objectService The object service instance
     */
    public function __construct(
        private readonly ObjectService $objectService
    ) {
    }//end __construct()


    /**
     * Example 1: Basic New Faceting with getFacetsForObjects
     *
     * Demonstrates the new _facets configuration approach.
     *
     * @return array The facet results using new system
     */
    public function newFacetingApproach(): array
    {
        $query = [
            '@self' => ['register' => 1],
            'status' => 'active',
            '_facets' => [
                '@self' => [
                    'register' => ['type' => 'terms'],
                    'schema' => ['type' => 'terms']
                ],
                'status' => ['type' => 'terms'],
                'priority' => ['type' => 'terms']
            ]
        ];

        return $this->objectService->getFacetsForObjects($query);

    }//end newFacetingApproach()


    /**
     * Example 2: Legacy Faceting (Backward Compatibility)
     *
     * Demonstrates that the old approach still works without _facets config.
     *
     * @return array The facet results using legacy system
     */
    public function legacyFacetingApproach(): array
    {
        $query = [
            '@self' => [
                'register' => 1
            ],
            'status' => 'active',
            '_search' => 'customer',
            '_queries' => ['status', 'priority', 'category']
        ];

        // This will use the legacy getFacets method since no _facets config
        return $this->objectService->getFacetsForObjects($query);

    }//end legacyFacetingApproach()


    /**
     * Example 3: Complete Paginated Search with Facets
     *
     * Demonstrates searchObjectsPaginated with comprehensive faceting.
     *
     * @return array Complete search results with pagination and facets
     */
    public function paginatedSearchWithFacets(): array
    {
        $query = [
            '@self' => ['register' => 1],
            'status' => 'active',
            '_limit' => 25,
            '_page' => 1,
            '_facets' => [
                '@self' => [
                    'register' => ['type' => 'terms'],
                    'created' => [
                        'type' => 'date_histogram',
                        'interval' => 'month'
                    ]
                ],
                'status' => ['type' => 'terms'],
                'price' => [
                    'type' => 'range',
                    'ranges' => [
                        ['to' => 100],
                        ['from' => 100, 'to' => 500],
                        ['from' => 500]
                    ]
                ]
            ]
        ];

        return $this->objectService->searchObjectsPaginated($query);

    }//end paginatedSearchWithFacets()


    /**
     * Example 4: E-commerce Style Faceted Search
     *
     * Real-world example for an e-commerce product catalog.
     *
     * @return array E-commerce search results with product facets
     */
    public function ecommerceFacetedSearch(): array
    {
        $query = [
            // Product filters
            '@self' => [
                'register' => 1, // Products register
                'schema' => 2     // Product schema
            ],
            'category' => 'electronics',
            'in_stock' => true,
            '_published' => true,
            
            // Search and pagination
            '_search' => 'smartphone',
            '_limit' => 20,
            '_page' => 1,
            '_order' => [
                'popularity' => 'DESC',
                'price' => 'ASC'
            ],
            
            // E-commerce facets
            '_facets' => [
                // Product metadata
                '@self' => [
                    'register' => ['type' => 'terms'],
                    'created' => [
                        'type' => 'date_histogram',
                        'interval' => 'month'
                    ]
                ],
                
                // Product attributes
                'category' => ['type' => 'terms'],
                'brand' => ['type' => 'terms'],
                'color' => ['type' => 'terms'],
                'size' => ['type' => 'terms'],
                'condition' => ['type' => 'terms'],
                'availability' => ['type' => 'terms'],
                
                // Price ranges
                'price' => [
                    'type' => 'range',
                    'ranges' => [
                        ['to' => 50],
                        ['from' => 50, 'to' => 100],
                        ['from' => 100, 'to' => 200],
                        ['from' => 200, 'to' => 500],
                        ['from' => 500]
                    ]
                ],
                
                // Rating ranges
                'rating' => [
                    'type' => 'range',
                    'ranges' => [
                        ['from' => 4.5],
                        ['from' => 4, 'to' => 4.5],
                        ['from' => 3, 'to' => 4],
                        ['to' => 3]
                    ]
                ],
                
                // Release date histogram
                'release_date' => [
                    'type' => 'date_histogram',
                    'interval' => 'year'
                ]
            ]
        ];

        return $this->objectService->searchObjectsPaginated($query);

    }//end ecommerceFacetedSearch()


    /**
     * Example 5: Analytics Dashboard Facets
     *
     * Example for building analytics dashboards with time-based facets.
     *
     * @return array Analytics data with time-based facets
     */
    public function analyticsDashboardFacets(): array
    {
        $query = [
            // Analytics filters
            '@self' => [
                'register' => [1, 2, 3], // Multiple data sources
                'organisation' => 'IS NOT NULL'
            ],
            '_published' => true,
            
            // Time-based facets for analytics
            '_facets' => [
                '@self' => [
                    'register' => ['type' => 'terms'],
                    'schema' => ['type' => 'terms'],
                    'organisation' => ['type' => 'terms'],
                    
                    // Time-based analytics
                    'created' => [
                        'type' => 'date_histogram',
                        'interval' => 'day'
                    ],
                    'updated' => [
                        'type' => 'date_histogram',
                        'interval' => 'week'
                    ]
                ],
                
                // Object field analytics
                'status' => ['type' => 'terms'],
                'priority' => ['type' => 'terms'],
                'department' => ['type' => 'terms'],
                'type' => ['type' => 'terms'],
                
                // Value ranges for metrics
                'value' => [
                    'type' => 'range',
                    'ranges' => [
                        ['to' => 1000],
                        ['from' => 1000, 'to' => 5000],
                        ['from' => 5000, 'to' => 10000],
                        ['from' => 10000]
                    ]
                ],
                
                // Activity timeline
                'last_activity' => [
                    'type' => 'date_histogram',
                    'interval' => 'month'
                ]
            ]
        ];

        return $this->objectService->searchObjectsPaginated($query);

    }//end analyticsDashboardFacets()


    /**
     * Example 6: Disjunctive Faceting Demonstration
     *
     * Shows how facets remain available even when filters are applied.
     *
     * @return array Results demonstrating disjunctive faceting
     */
    public function disjunctiveFacetingDemo(): array
    {
        // User has selected: register=1, status='active', category='electronics'
        $query = [
            '@self' => ['register' => 1],
            'status' => 'active',
            'category' => 'electronics',
            
            '_facets' => [
                '@self' => [
                    'register' => ['type' => 'terms'], // Shows ALL registers, not just 1
                    'schema' => ['type' => 'terms']
                ],
                'status' => ['type' => 'terms'],     // Shows ALL statuses, not just 'active'
                'category' => ['type' => 'terms'],   // Shows ALL categories, not just 'electronics'
                'priority' => ['type' => 'terms']    // Shows priorities for register=1 AND status='active' AND category='electronics'
            ]
        ];

        $result = $this->objectService->getFacetsForObjects($query);
        
        // The result will show:
        // - register facet: counts for ALL registers (disjunctive)
        // - status facet: counts for ALL statuses (disjunctive)  
        // - category facet: counts for ALL categories (disjunctive)
        // - priority facet: counts within the context of other filters (conjunctive)
        
        return $result;

    }//end disjunctiveFacetingDemo()


    /**
     * Example 7: Performance Comparison
     *
     * Compares performance between new and legacy faceting approaches.
     *
     * @return array Performance comparison results
     */
    public function performanceComparison(): array
    {
        $baseQuery = [
            '@self' => ['register' => 1],
            'status' => 'active'
        ];

        // Test new faceting approach
        $newQuery = array_merge($baseQuery, [
            '_facets' => [
                '@self' => [
                    'register' => ['type' => 'terms'],
                    'schema' => ['type' => 'terms']
                ],
                'status' => ['type' => 'terms'],
                'priority' => ['type' => 'terms']
            ]
        ]);

        $startTime = microtime(true);
        $newResults = $this->objectService->getFacetsForObjects($newQuery);
        $newTime = microtime(true) - $startTime;

        // Test legacy faceting approach
        $legacyQuery = array_merge($baseQuery, [
            '_queries' => ['status', 'priority']
        ]);

        $startTime = microtime(true);
        $legacyResults = $this->objectService->getFacetsForObjects($legacyQuery);
        $legacyTime = microtime(true) - $startTime;

        return [
            'new_approach' => [
                'execution_time' => $newTime,
                'facet_count' => count($newResults['facets'] ?? []),
                'results' => $newResults
            ],
            'legacy_approach' => [
                'execution_time' => $legacyTime,
                'facet_count' => count($legacyResults),
                'results' => $legacyResults
            ],
            'performance_improvement' => $legacyTime > 0 ? ($legacyTime - $newTime) / $legacyTime * 100 : 0
        ];

    }//end performanceComparison()


    /**
     * Example 8: Complete Frontend Integration Example
     *
     * Shows how to structure data for frontend consumption.
     *
     * @return array Frontend-ready search results with facets
     */
    public function frontendIntegrationExample(): array
    {
        $query = [
            '@self' => ['register' => 1],
            'status' => 'active',
            '_limit' => 20,
            '_page' => 1,
            
            '_facets' => [
                '@self' => [
                    'register' => ['type' => 'terms'],
                    'schema' => ['type' => 'terms']
                ],
                'status' => ['type' => 'terms'],
                'priority' => ['type' => 'terms'],
                'category' => ['type' => 'terms']
            ]
        ];

        $result = $this->objectService->searchObjectsPaginated($query);
        
        // Transform for frontend consumption
        $frontendData = [
            'search' => [
                'results' => $result['results'],
                'pagination' => [
                    'current_page' => $result['page'],
                    'total_pages' => $result['pages'],
                    'total_items' => $result['total'],
                    'items_per_page' => $result['limit'],
                    'has_next' => isset($result['next']),
                    'has_prev' => isset($result['prev']),
                    'next_url' => $result['next'] ?? null,
                    'prev_url' => $result['prev'] ?? null
                ]
            ],
            'facets' => $this->transformFacetsForFrontend($result['facets'] ?? []),
            'applied_filters' => $this->extractAppliedFilters($query)
        ];

        return $frontendData;

    }//end frontendIntegrationExample()


    /**
     * Transform facets for frontend consumption
     *
     * @param array $facets Raw facet data from the service
     *
     * @phpstan-param array<string, mixed> $facets
     *
     * @psalm-param array<string, mixed> $facets
     *
     * @return array Frontend-friendly facet structure
     */
    private function transformFacetsForFrontend(array $facets): array
    {
        $transformed = [];
        
        foreach ($facets as $field => $facet) {
            if ($field === '@self') {
                // Handle metadata facets
                foreach ($facet as $metaField => $metaFacet) {
                    $transformed['metadata_' . $metaField] = [
                        'field' => $metaField,
                        'type' => $metaFacet['type'],
                        'label' => ucfirst(str_replace('_', ' ', $metaField)),
                        'options' => $this->transformBuckets($metaFacet['buckets'] ?? [])
                    ];
                }
            } else {
                // Handle object field facets
                $transformed[$field] = [
                    'field' => $field,
                    'type' => $facet['type'],
                    'label' => ucfirst(str_replace('_', ' ', $field)),
                    'options' => $this->transformBuckets($facet['buckets'] ?? [])
                ];
            }
        }
        
        return $transformed;

    }//end transformFacetsForFrontend()


    /**
     * Transform facet buckets for frontend
     *
     * @param array $buckets Raw bucket data
     *
     * @phpstan-param array<array<string, mixed>> $buckets
     *
     * @psalm-param array<array<string, mixed>> $buckets
     *
     * @return array Frontend-friendly bucket structure
     */
    private function transformBuckets(array $buckets): array
    {
        return array_map(function($bucket) {
            return [
                'value' => $bucket['key'],
                'label' => $bucket['label'] ?? $bucket['key'],
                'count' => $bucket['doc_count'],
                'from' => $bucket['from'] ?? null,
                'to' => $bucket['to'] ?? null
            ];
        }, $buckets);

    }//end transformBuckets()


    /**
     * Extract applied filters from query
     *
     * @param array $query The search query
     *
     * @phpstan-param array<string, mixed> $query
     *
     * @psalm-param array<string, mixed> $query
     *
     * @return array Applied filters structure
     */
    private function extractAppliedFilters(array $query): array
    {
        $filters = [];
        
        // Extract metadata filters
        if (isset($query['@self'])) {
            foreach ($query['@self'] as $field => $value) {
                $filters['metadata_' . $field] = [
                    'field' => $field,
                    'value' => $value,
                    'type' => 'metadata'
                ];
            }
        }
        
        // Extract object field filters
        foreach ($query as $field => $value) {
            if (!str_starts_with($field, '_') && $field !== '@self') {
                $filters[$field] = [
                    'field' => $field,
                    'value' => $value,
                    'type' => 'object_field'
                ];
            }
        }
        
        return $filters;

    }//end extractAppliedFilters()

}//end class 