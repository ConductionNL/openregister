<?php

/**
 * OpenRegister Simple Facet Examples
 *
 * This file contains simple examples demonstrating the new MetaDataFacetHandler and MariaDbFacetHandler through the ObjectEntityMapper's getSimpleFacets method.
 *
 * @category Example
 * @package  OCA\OpenRegister\Db\ObjectHandlers
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\ObjectHandlers;

use OCA\OpenRegister\Db\ObjectEntityMapper;

/**
 * Examples demonstrating the simple faceting system
 *
 * This class provides practical examples of how to use the new facet handlers
 * through the ObjectEntityMapper's getSimpleFacets method.
 */
class SimpleFacetExample
{

    /**
     * Constructor for SimpleFacetExample
     *
     * @param ObjectEntityMapper $objectEntityMapper The object entity mapper instance
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper
    ) {
    }//end __construct()


    /**
     * Example 1: Basic Terms Faceting
     *
     * Demonstrates how to get simple terms facets for both metadata
     * and object fields using the new handlers.
     *
     * @return array The facet results
     */
    public function basicTermsFaceting(): array
    {
        $query = [
            // Basic search filters
            '@self' => [
                'register' => 1,
                'organisation' => 'IS NOT NULL'
            ],
            'status' => 'active',
            
            // Simple facet configuration
            '_facets' => [
                // Metadata facets (handled by MetaDataFacetHandler)
                '@self' => [
                    'register' => ['type' => 'terms'],
                    'schema' => ['type' => 'terms'],
                    'owner' => ['type' => 'terms']
                ],
                
                // Object field facets (handled by MariaDbFacetHandler)
                'status' => ['type' => 'terms'],
                'priority' => ['type' => 'terms'],
                'category' => ['type' => 'terms']
            ]
        ];

        return $this->objectEntityMapper->getSimpleFacets($query);

    }//end basicTermsFaceting()


    /**
     * Example 2: Date Histogram Faceting
     *
     * Demonstrates how to create time-based facets with different intervals.
     *
     * @return array The facet results with date histograms
     */
    public function dateHistogramFaceting(): array
    {
        $query = [
            // Search filters
            '@self' => [
                'register' => [1, 2, 3]
            ],
            '_published' => true,
            
            // Date histogram facets
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
                
                // Object field date histograms
                'event_date' => [
                    'type' => 'date_histogram',
                    'interval' => 'day'
                ],
                'created_at' => [
                    'type' => 'date_histogram',
                    'interval' => 'year'
                ]
            ]
        ];

        return $this->objectEntityMapper->getSimpleFacets($query);

    }//end dateHistogramFaceting()


    /**
     * Example 3: Range Faceting
     *
     * Demonstrates how to create numeric range facets.
     *
     * @return array The facet results with ranges
     */
    public function rangeFaceting(): array
    {
        $query = [
            // Search filters
            'status' => 'active',
            
            // Range facets
            '_facets' => [
                // Price ranges (object field)
                'price' => [
                    'type' => 'range',
                    'ranges' => [
                        ['to' => 50],
                        ['from' => 50, 'to' => 100],
                        ['from' => 100, 'to' => 500],
                        ['from' => 500]
                    ]
                ],
                
                // Age groups (object field)
                'age' => [
                    'type' => 'range',
                    'ranges' => [
                        ['to' => 18],
                        ['from' => 18, 'to' => 25],
                        ['from' => 25, 'to' => 35],
                        ['from' => 35]
                    ]
                ]
            ]
        ];

        return $this->objectEntityMapper->getSimpleFacets($query);

    }//end rangeFaceting()


    /**
     * Example 4: Mixed Faceting Types
     *
     * Demonstrates how to combine different facet types in a single query.
     *
     * @return array The facet results with mixed types
     */
    public function mixedFaceting(): array
    {
        $query = [
            // Complex search filters
            '@self' => [
                'register' => [1, 2],
                'schema' => 'IS NOT NULL'
            ],
            'status' => ['active', 'pending'],
            'category' => 'electronics',
            '_published' => true,
            
            // Mixed facet configuration
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
                
                // Object field facets
                'status' => ['type' => 'terms'],
                'category' => ['type' => 'terms'],
                'brand' => ['type' => 'terms'],
                
                // Range facets
                'price' => [
                    'type' => 'range',
                    'ranges' => [
                        ['to' => 100],
                        ['from' => 100, 'to' => 300],
                        ['from' => 300, 'to' => 600],
                        ['from' => 600]
                    ]
                ],
                
                // Date histogram for object fields
                'purchase_date' => [
                    'type' => 'date_histogram',
                    'interval' => 'week'
                ]
            ]
        ];

        return $this->objectEntityMapper->getSimpleFacets($query);

    }//end mixedFaceting()


    /**
     * Example 5: Handler Availability Check
     *
     * Demonstrates how the method gracefully handles cases where
     * handlers are not available.
     *
     * @return array The facet results or empty array
     */
    public function handlerAvailabilityCheck(): array
    {
        $query = [
            '@self' => ['register' => 1],
            '_facets' => [
                '@self' => [
                    'register' => ['type' => 'terms']
                ],
                'status' => ['type' => 'terms']
            ]
        ];

        // This will return ['facets' => []] if handlers are not available
        // (e.g., on non-MySQL platforms)
        return $this->objectEntityMapper->getSimpleFacets($query);

    }//end handlerAvailabilityCheck()


    /**
     * Example 6: Empty Configuration Handling
     *
     * Demonstrates how the method handles empty or missing facet configuration.
     *
     * @return array The facet results (empty)
     */
    public function emptyConfigurationHandling(): array
    {
        // Query without _facets configuration
        $queryWithoutFacets = [
            '@self' => ['register' => 1],
            'status' => 'active'
        ];

        // Query with empty _facets configuration
        $queryWithEmptyFacets = [
            '@self' => ['register' => 1],
            'status' => 'active',
            '_facets' => []
        ];

        return [
            'without_facets' => $this->objectEntityMapper->getSimpleFacets($queryWithoutFacets),
            'with_empty_facets' => $this->objectEntityMapper->getSimpleFacets($queryWithEmptyFacets)
        ];

    }//end emptyConfigurationHandling()


    /**
     * Example 7: Performance Test
     *
     * Simple performance test to compare the new handlers.
     *
     * @return array Performance comparison results
     */
    public function performanceTest(): array
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

        // Measure execution time
        $startTime = microtime(true);
        $results = $this->objectEntityMapper->getSimpleFacets($query);
        $executionTime = microtime(true) - $startTime;

        return [
            'execution_time_seconds' => $executionTime,
            'facets_count' => count($results['facets'] ?? []),
            'results' => $results
        ];

    }//end performanceTest()

}//end class 