<?php

/**
 * OpenRegister New Faceting System Examples
 *
 * This file contains examples demonstrating the new faceting system
 * that has replaced the legacy getFacets approach.
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
 * Examples demonstrating the new faceting system exclusively
 *
 * The legacy getFacets method has been discontinued. All faceting
 * now uses the new _facets configuration approach.
 */
class NewFacetingExample
{

    /**
     * Constructor for NewFacetingExample
     *
     * @param ObjectService $objectService The object service instance
     */
    public function __construct(
        private readonly ObjectService $objectService
    ) {
    }//end __construct()


    /**
     * Example 1: Basic Terms Faceting
     *
     * Shows how to create basic categorical facets.
     *
     * @return array Basic facet results
     */
    public function basicTermsFaceting(): array
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
                'category' => ['type' => 'terms']
            ]
        ];

        return $this->objectService->getFacetsForObjects($query);

    }//end basicTermsFaceting()


    /**
     * Example 2: Date Histogram Faceting
     *
     * Shows how to create time-based facets with different intervals.
     *
     * @return array Date histogram facet results
     */
    public function dateHistogramFaceting(): array
    {
        $query = [
            '@self' => ['register' => 1],
            '_facets' => [
                '@self' => [
                    'created' => [
                        'type' => 'date_histogram',
                        'interval' => 'month'
                    ],
                    'updated' => [
                        'type' => 'date_histogram',
                        'interval' => 'week'
                    ]
                ],
                'event_date' => [
                    'type' => 'date_histogram',
                    'interval' => 'day'
                ]
            ]
        ];

        return $this->objectService->getFacetsForObjects($query);

    }//end dateHistogramFaceting()


    /**
     * Example 3: Range Faceting
     *
     * Shows how to create numeric range facets.
     *
     * @return array Range facet results
     */
    public function rangeFaceting(): array
    {
        $query = [
            '@self' => ['register' => 1],
            '_facets' => [
                'price' => [
                    'type' => 'range',
                    'ranges' => [
                        ['to' => 100],
                        ['from' => 100, 'to' => 500],
                        ['from' => 500, 'to' => 1000],
                        ['from' => 1000]
                    ]
                ],
                'age' => [
                    'type' => 'range',
                    'ranges' => [
                        ['to' => 18],
                        ['from' => 18, 'to' => 65],
                        ['from' => 65]
                    ]
                ]
            ]
        ];

        return $this->objectService->getFacetsForObjects($query);

    }//end rangeFaceting()


    /**
     * Example 4: Complete E-commerce Faceting
     *
     * Real-world example combining all facet types for an e-commerce site.
     *
     * @return array Complete e-commerce facet results
     */
    public function ecommerceFaceting(): array
    {
        $query = [
            // Base filters
            '@self' => [
                'register' => 1, // Products register
                'schema' => 2     // Product schema
            ],
            'category' => 'electronics',
            'in_stock' => true,
            '_published' => true,
            '_search' => 'smartphone',
            
            // Comprehensive faceting
            '_facets' => [
                // Metadata facets
                '@self' => [
                    'register' => ['type' => 'terms'],
                    'schema' => ['type' => 'terms'],
                    'created' => [
                        'type' => 'date_histogram',
                        'interval' => 'month'
                    ]
                ],
                
                // Product attribute facets
                'category' => ['type' => 'terms'],
                'brand' => ['type' => 'terms'],
                'color' => ['type' => 'terms'],
                'size' => ['type' => 'terms'],
                'condition' => ['type' => 'terms'],
                'availability' => ['type' => 'terms'],
                
                // Price range facets
                'price' => [
                    'type' => 'range',
                    'ranges' => [
                        ['to' => 50],
                        ['from' => 50, 'to' => 100],
                        ['from' => 100, 'to' => 250],
                        ['from' => 250, 'to' => 500],
                        ['from' => 500]
                    ]
                ],
                
                // Rating range facets
                'rating' => [
                    'type' => 'range',
                    'ranges' => [
                        ['from' => 4.5],
                        ['from' => 4.0, 'to' => 4.5],
                        ['from' => 3.0, 'to' => 4.0],
                        ['to' => 3.0]
                    ]
                ]
            ]
        ];

        return $this->objectService->getFacetsForObjects($query);

    }//end ecommerceFaceting()


    /**
     * Example 5: Paginated Search with Facets
     *
     * Shows how to use searchObjectsPaginated with the new faceting system.
     *
     * @return array Complete paginated results with facets
     */
    public function paginatedSearchWithFacets(): array
    {
        $query = [
            '@self' => ['register' => 1],
            'status' => 'active',
            '_limit' => 20,
            '_page' => 1,
            '_order' => [
                '@self.created' => 'DESC',
                'priority' => 'ASC'
            ],
            '_facets' => [
                '@self' => [
                    'register' => ['type' => 'terms'],
                    'schema' => ['type' => 'terms'],
                    'created' => [
                        'type' => 'date_histogram',
                        'interval' => 'month'
                    ]
                ],
                'status' => ['type' => 'terms'],
                'priority' => ['type' => 'terms'],
                'category' => ['type' => 'terms']
            ]
        ];

        // This returns: results, total, page, pages, limit, offset, facets, next, prev
        return $this->objectService->searchObjectsPaginated($query);

    }//end paginatedSearchWithFacets()


    /**
     * Example 6: Migration from Legacy getFacets
     *
     * Shows how to migrate from the old getFacets approach to the new system.
     *
     * @return array Comparison of old vs new approach
     */
    public function migrationExample(): array
    {
        // OLD WAY (deprecated - don't use):
        // $oldFacets = $objectService->getFacets(['status' => 'active'], 'search term');
        
        // NEW WAY (current approach):
        $newQuery = [
            '@self' => [
                'register' => $this->objectService->getRegister(),
                'schema' => $this->objectService->getSchema()
            ],
            'status' => 'active',
            '_search' => 'search term',
            '_facets' => [
                '@self' => [
                    'register' => ['type' => 'terms'],
                    'schema' => ['type' => 'terms']
                ],
                'status' => ['type' => 'terms']
            ]
        ];
        
        $newFacets = $this->objectService->getFacetsForObjects($newQuery);
        
        return [
            'migration_notes' => [
                'old_method' => 'getFacets() with simple filters',
                'new_method' => 'getFacetsForObjects() with _facets configuration',
                'benefits' => [
                    'More flexible facet types (terms, date_histogram, range)',
                    'Better performance with disjunctive faceting',
                    'Consistent query structure with searchObjects',
                    'Enhanced metadata support',
                    'Future-proof architecture'
                ]
            ],
            'new_facets' => $newFacets
        ];

    }//end migrationExample()


    /**
     * Example 7: Advanced Filtering with Facets
     *
     * Shows complex filtering scenarios with the new faceting system.
     *
     * @return array Advanced faceting results
     */
    public function advancedFilteringWithFacets(): array
    {
        $query = [
            // Complex metadata filters
            '@self' => [
                'register' => [1, 2, 3],        // Multiple registers
                'organisation' => 'IS NOT NULL', // Has organisation
                'owner' => 'user123'             // Specific owner
            ],
            
            // Complex object field filters
            'status' => ['active', 'pending'],   // Multiple statuses
            'priority' => 'high',                // Single priority
            'address.city' => 'Amsterdam',       // Nested field
            'tags' => ['vip', 'customer'],       // Array search
            
            // Search and options
            '_search' => 'important project',
            '_published' => true,
            
            // Comprehensive faceting
            '_facets' => [
                '@self' => [
                    'register' => ['type' => 'terms'],
                    'schema' => ['type' => 'terms'],
                    'organisation' => ['type' => 'terms'],
                    'created' => [
                        'type' => 'date_histogram',
                        'interval' => 'month'
                    ]
                ],
                'status' => ['type' => 'terms'],
                'priority' => ['type' => 'terms'],
                'category' => ['type' => 'terms'],
                'address.city' => ['type' => 'terms'],
                'budget' => [
                    'type' => 'range',
                    'ranges' => [
                        ['to' => 1000],
                        ['from' => 1000, 'to' => 5000],
                        ['from' => 5000, 'to' => 10000],
                        ['from' => 10000]
                    ]
                ]
            ]
        ];

        return $this->objectService->getFacetsForObjects($query);

    }//end advancedFilteringWithFacets()


    /**
     * Example 8: Performance Optimized Faceting
     *
     * Shows how to optimize faceting for performance.
     *
     * @return array Performance optimized facet results
     */
    public function performanceOptimizedFaceting(): array
    {
        $query = [
            // Use specific filters to reduce dataset
            '@self' => [
                'register' => 1,                 // Single register for better performance
                'schema' => 2                    // Single schema for better performance
            ],
            'status' => 'active',                // Pre-filter to reduce dataset
            '_published' => true,                // Only published objects
            
            // Focused faceting - only what's needed
            '_facets' => [
                // Only essential metadata facets
                '@self' => [
                    'schema' => ['type' => 'terms']  // Only schema facet needed
                ],
                
                // Only essential object field facets
                'category' => ['type' => 'terms'],   // Main category filter
                'priority' => ['type' => 'terms']    // Priority filter
                
                // Note: Avoid too many facets as they impact performance
                // Note: Date histograms and ranges are more expensive than terms
            ]
        ];

        return $this->objectService->getFacetsForObjects($query);

    }//end performanceOptimizedFaceting()

}//end class 