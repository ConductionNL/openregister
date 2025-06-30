<?php

/**
 * Search Examples for OpenRegister Objects
 *
 * This file contains examples demonstrating how to use the new searchObjects method
 * with various query patterns and search functionality.
 *
 * @category Database
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

/**
 * Search Examples
 *
 * This class contains static examples showing how to use the new searchObjects method.
 * These examples demonstrate various query patterns and search capabilities.
 *
 * @package OCA\OpenRegister\Db\ObjectHandlers
 */
class SearchExample
{

    /**
     * Example 1: Basic metadata search
     *
     * Search for objects in a specific register
     *
     * @return array Example query structure
     * @phpstan-return array<string, mixed>
     * @psalm-return array<string, mixed>
     */
    public static function basicMetadataSearch(): array
    {
        return [
            '@self' => [
                'register' => 1,
                'schema'   => 2,
            ],
            '_limit'  => 10,
            '_offset' => 0,
        ];

    }//end basicMetadataSearch()


    /**
     * Example 2: Array search (one of)
     *
     * Search for objects in multiple registers
     *
     * @return array Example query structure
     * @phpstan-return array<string, mixed>
     * @psalm-return array<string, mixed>
     */
    public static function arrayMetadataSearch(): array
    {
        return [
            '@self' => [
                'register' => [1, 2, 3],
                'owner'    => ['user1', 'user2'],
            ],
            '_limit'  => 20,
            '_offset' => 0,
        ];

    }//end arrayMetadataSearch()


    /**
     * Example 3: Object field search
     *
     * Search within the JSON object data
     *
     * @return array Example query structure
     * @phpstan-return array<string, mixed>
     * @psalm-return array<string, mixed>
     */
    public static function objectFieldSearch(): array
    {
        return [
            'name'         => 'John Doe',
            'age'          => 25,
            'address.city' => 'Amsterdam',
            'tags'         => ['important', 'customer'],
            '_limit'       => 10,
            '_offset'      => 0,
        ];

    }//end objectFieldSearch()


    /**
     * Example 4: Combined metadata and object search
     *
     * Search both metadata and object fields
     *
     * @return array Example query structure
     * @phpstan-return array<string, mixed>
     * @psalm-return array<string, mixed>
     */
    public static function combinedSearch(): array
    {
        return [
            '@self' => [
                'register' => 1,
                'schema'   => [2, 3],
            ],
            'name'     => 'John',
            'status'   => 'active',
            '_search'  => 'customer service',
            '_limit'   => 10,
            '_offset'  => 0,
        ];

    }//end combinedSearch()


    /**
     * Example 5: Sorting examples
     *
     * Sort by both metadata and object fields
     *
     * @return array Example query structure
     * @phpstan-return array<string, mixed>
     * @psalm-return array<string, mixed>
     */
    public static function sortingSearch(): array
    {
        return [
            '@self' => [
                'register' => 1,
            ],
            'status'  => 'active',
            '_order'  => [
                '@self.created' => 'DESC',  // Sort by metadata field
                'name'          => 'ASC',   // Sort by object field
                'priority'      => 'DESC',  // Sort by object field
            ],
            '_limit'  => 10,
            '_offset' => 0,
        ];

    }//end sortingSearch()


    /**
     * Example 6: Full-text search with filters
     *
     * Combine full-text search with specific filters
     *
     * @return array Example query structure
     * @phpstan-return array<string, mixed>
     * @psalm-return array<string, mixed>
     */
    public static function fullTextSearch(): array
    {
        return [
            '@self' => [
                'register' => [1, 2],
                'schema'   => 3,
            ],
            'category' => 'product',
            '_search'  => 'laptop computer electronics',
            '_order'   => [
                '@self.updated' => 'DESC',
            ],
            '_limit'   => 25,
            '_offset'  => 0,
        ];

    }//end fullTextSearch()


    /**
     * Example 7: Null value searches
     *
     * Search for objects with null or non-null values
     *
     * @return array Example query structure
     * @phpstan-return array<string, mixed>
     * @psalm-return array<string, mixed>
     */
    public static function nullValueSearch(): array
    {
        return [
            '@self' => [
                'owner'        => 'IS NOT NULL',
                'organisation' => 'IS NULL',
            ],
            'description' => 'IS NOT NULL',
            'archived'    => 'IS NULL',
            '_limit'      => 10,
            '_offset'     => 0,
        ];

    }//end nullValueSearch()


    /**
     * Example 8: Published objects search
     *
     * Search for currently published objects
     *
     * @return array Example query structure
     * @phpstan-return array<string, mixed>
     * @psalm-return array<string, mixed>
     */
    public static function publishedObjectsSearch(): array
    {
        return [
            '@self' => [
                'register' => 1,
            ],
            'featured'    => true,
            '_published'  => true,
            '_order'      => [
                '@self.published' => 'DESC',
                'priority'        => 'ASC',
            ],
            '_limit'      => 10,
            '_offset'     => 0,
        ];

    }//end publishedObjectsSearch()


    /**
     * Example 9: Advanced register and schema handling
     *
     * Demonstrate flexible register and schema value handling
     *
     * @return array Example query structure
     * @phpstan-return array<string, mixed>
     * @psalm-return array<string, mixed>
     */
    public static function advancedRegisterSchemaSearch(): array
    {
        return [
            '@self' => [
                'register' => [1, 2, 3],  // Array of register IDs
                'schema'   => 5,          // Single schema ID
            ],
            'status'  => 'active',
            '_limit'  => 15,
            '_offset' => 0,
        ];

    }//end advancedRegisterSchemaSearch()


    /**
     * Example 10: Register and schema with objects
     *
     * Show how to pass Register and Schema objects directly
     * (objects will be converted to IDs using getId() method)
     *
     * @return array Example query structure (conceptual - would use actual objects)
     * @phpstan-return array<string, mixed>
     * @psalm-return array<string, mixed>
     */
    public static function objectRegisterSchemaSearch(): array
    {
        // Note: In practice, you would pass actual Register and Schema objects:
        // '@self' => [
        //     'register' => $registerObject,       // Single register object
        //     'schema'   => [$schema1, $schema2],  // Array of schema objects
        // ],
        
        return [
            '@self' => [
                'register' => 'REGISTER_OBJECT_PLACEHOLDER',  // Would be actual Register object
                'schema'   => 'SCHEMA_ARRAY_PLACEHOLDER',     // Would be array of Schema objects
            ],
            'category' => 'product',
            '_limit'   => 20,
            '_offset'  => 0,
        ];

    }//end objectRegisterSchemaSearch()


    /**
     * Example 11: Mixed register and schema types
     *
     * Show mixing different types of register and schema values
     *
     * @return array Example query structure
     * @phpstan-return array<string, mixed>
     * @psalm-return array<string, mixed>
     */
    public static function mixedRegisterSchemaSearch(): array
    {
        return [
            '@self' => [
                'register' => ['1', 2, '3'],  // Mixed string and int IDs
                'schema'   => 'active',       // String ID
            ],
            'priority' => ['high', 'medium'],
            '_search'  => 'important',
            '_limit'   => 25,
            '_offset'  => 0,
        ];

    }//end mixedRegisterSchemaSearch()


    /**
     * Example 12: Search by specific IDs or UUIDs
     *
     * Filter objects by an array of specific IDs or UUIDs
     *
     * @return array Example query structure
     * @phpstan-return array<string, mixed>
     * @psalm-return array<string, mixed>
     */
    public static function idFilterSearch(): array
    {
        return [
            '@self' => [
                'register' => 1,
            ],
            '_ids'    => [1, 2, 3],  // Filter by specific IDs
            '_limit'  => 10,
            '_offset' => 0,
        ];

    }//end idFilterSearch()


    /**
     * Example 13: Search by UUIDs
     *
     * Filter objects by an array of UUIDs
     *
     * @return array Example query structure
     * @phpstan-return array<string, mixed>
     * @psalm-return array<string, mixed>
     */
    public static function uuidFilterSearch(): array
    {
        return [
            '_ids' => [
                'uuid-123-456-789',
                'uuid-987-654-321',
                'uuid-111-222-333',
            ],
            '_order'  => [
                '@self.created' => 'DESC',
            ],
            '_limit'  => 5,
            '_offset' => 0,
        ];

    }//end uuidFilterSearch()


    /**
     * Example 14: Mixed IDs and UUIDs search
     *
     * Filter objects by a mix of IDs and UUIDs
     *
     * @return array Example query structure
     * @phpstan-return array<string, mixed>
     * @psalm-return array<string, mixed>
     */
    public static function mixedIdUuidSearch(): array
    {
        return [
            '@self' => [
                'register' => [1, 2],
            ],
            'status'  => 'active',
            '_ids'    => [
                1,                      // Integer ID
                'uuid-123-456-789',     // UUID string
                5,                      // Another integer ID
                'uuid-987-654-321',     // Another UUID string
            ],
            '_search' => 'important',
            '_limit'  => 20,
            '_offset' => 0,
        ];

    }//end mixedIdUuidSearch()


    /**
     * Example 15: Count query using _count option
     *
     * Get count of matching objects using the same query structure as search
     *
     * @return array Example query structure
     * @phpstan-return array<string, mixed>
     * @psalm-return array<string, mixed>
     */
    public static function countQuery(): array
    {
        return [
            '@self' => [
                'register' => [1, 2, 3],
                'organisation' => 'IS NOT NULL',
            ],
            'status'      => ['active', 'pending'],
            'priority'    => 'high',
            '_search'     => 'important customer',
            '_published'  => true,
            '_count'      => true,  // Returns integer count instead of objects
            // Note: _limit, _offset, _order are ignored for count queries
        ];

    }//end countQuery()


    /**
     * Example 16: Comparing search and count with same filters
     *
     * Demonstrate how to use the same query for both search and count
     *
     * @return array Example showing both search and count queries
     * @phpstan-return array<string, array<string, mixed>>
     * @psalm-return array<string, array<string, mixed>>
     */
    public static function searchAndCountComparison(): array
    {
        $baseQuery = [
            '@self' => [
                'register' => 1,
                'schema' => 2,
            ],
            'name'        => 'John',
            'status'      => 'active',
            '_search'     => 'customer',
            '_published'  => true,
        ];

        return [
            'search' => array_merge($baseQuery, [
                '_limit'  => 10,
                '_offset' => 0,
                '_order'  => [
                    '@self.created' => 'DESC',
                    'priority'      => 'ASC',
                ],
            ]),
            'count' => array_merge($baseQuery, [
                '_count' => true,
                // Pagination and sorting options are ignored for count
            ]),
        ];

    }//end searchAndCountComparison()


    /**
     * Example 17: Using ObjectService wrapper for clean search interface
     *
     * This example demonstrates how to use the ObjectService searchObjects wrapper
     * which provides a cleaner interface and automatically handles rendering.
     *
     * @return void
     */
    public function exampleObjectServiceSearch(): void
    {
        // This example would typically be called from a service or controller

        // Example 1: Basic metadata search with rendering
        $basicQuery = [
            '@self' => [
                'register' => 1,
                'schema' => 2,
            ],
            '_limit' => 10,
            '_published' => true,
        ];

        // Using ObjectService wrapper (handles rendering automatically)
        // $objectService = $this->container->get(ObjectService::class);
        // $results = $objectService->searchObjects($basicQuery);
        // Results are already rendered ObjectEntity objects

        // Example 2: Complex search with object fields and metadata
        $complexQuery = [
            '@self' => [
                'register' => [1, 2, 3],
                'organisation' => 'IS NOT NULL',
            ],
            'name' => 'John',
            'status' => ['active', 'pending'],
            'address.city' => 'Amsterdam',
            '_search' => 'important customer',
            '_order' => [
                '@self.created' => 'DESC',
                'priority' => 'ASC'
            ],
            '_limit' => 25,
            '_offset' => 50,
            '_extend' => ['@self.register', '@self.schema'],
            '_fields' => ['name', 'email', 'status'],
        ];

        // $results = $objectService->searchObjects($complexQuery);

        // Example 3: Count objects using same query structure (optimized)
        // $total = $objectService->countSearchObjects($complexQuery);
        // This uses the new countSearchObjects method which is optimized for counting
        // and doesn't fetch actual data, just returns the count

        // Example 4: Get facets using same query structure  
        // $facets = $objectService->getFacetsForObjects($complexQuery);

        // Example 5: Search by specific IDs or UUIDs
        $idQuery = [
            '@self' => [
                'register' => 1,
            ],
            '_ids' => [1, 'uuid-123-456', 5, 'uuid-789-012'],  // Mix of IDs and UUIDs
            '_order' => [
                '@self.created' => 'DESC',
            ],
        ];
        // $specificObjects = $objectService->searchObjects($idQuery);
        // This will return only objects with IDs 1, 5 or UUIDs 'uuid-123-456', 'uuid-789-012'

        // Example 6: Count using the same method with _count option
        $countQuery = [
            '@self' => [
                'register' => [1, 2, 3],
                'organisation' => 'IS NOT NULL',
            ],
            'status' => ['active', 'pending'],
            '_search' => 'important customer',
            '_published' => true,
            '_count' => true,  // Returns integer count instead of objects
        ];
        // $totalCount = $objectService->searchObjects($countQuery);
        // This returns an integer count using the same method and query structure

        // This provides a much cleaner interface than the old findAll method
        // and makes the code more testable and maintainable.

        // Example 7: Performance comparison - Old vs New count methods
        // 
        // OLD WAY (multiple methods, less efficient):
        // $config = ['filters' => ['register' => 1], 'search' => 'test'];
        // $objects = $objectService->searchObjects($searchQuery);
        // $total = $objectService->count($config); // Separate method call
        //
        // NEW WAY (unified method, optimized):
        // $searchQuery = ['@self' => ['register' => 1], '_search' => 'test'];
        // $objects = $objectService->searchObjects($searchQuery);
        // $total = $objectService->searchObjects(array_merge($searchQuery, ['_count' => true]));
        //
        // EVEN BETTER (can use same base query):
        // $baseQuery = ['@self' => ['register' => 1], '_search' => 'test'];
        // $objects = $objectService->searchObjects(array_merge($baseQuery, ['_limit' => 10]));
        // $total = $objectService->searchObjects(array_merge($baseQuery, ['_count' => true]));
        //
        // Benefits of the unified approach:
        // - Single method for both search and count
        // - Uses COUNT(*) instead of selecting all data for counts
        // - Applies identical filters for consistency
        // - Skips unnecessary sorting operations for counts
        // - Better performance on large datasets
        // - Less code duplication

    }//end exampleObjectServiceSearch()


    /**
     * Example 18: Testing the unified count functionality
     *
     * This example demonstrates how to test the new unified searchObjects method
     * with the _count option for both search and count operations.
     *
     * @return void
     */
    public function exampleCountTesting(): void
    {
        // This example would typically be used in unit tests

        // Base test query
        $baseQuery = [
            '@self' => [
                'register' => 1,
                'schema' => 2,
            ],
            'status' => 'active',
            '_published' => true,
        ];

        // Using ObjectService with unified searchObjects method
        // $objectService = $this->container->get(ObjectService::class);
        
        // Get objects
        // $objects = $objectService->searchObjects($baseQuery);
        
        // Get count using same method with _count option
        // $count = $objectService->searchObjects(array_merge($baseQuery, ['_count' => true]));
        // 
        // // Verify that count matches actual results (when no pagination)
        // assert(count($objects) === $count, 'Count should match actual results');

        // Using ObjectEntityMapper directly (for testing internal logic)
        // $mapper = $this->container->get(ObjectEntityMapper::class);
        // $objects = $mapper->searchObjects($baseQuery);
        // $count = $mapper->searchObjects(array_merge($baseQuery, ['_count' => true]));
        //
        // // Verify that count matches actual results
        // assert(count($objects) === $count, 'Count should match actual results');

        // Test with pagination
        $searchQuery = array_merge($baseQuery, [
            '_limit' => 10,
            '_offset' => 0,
        ]);
        
        $countQuery = array_merge($baseQuery, [
            '_count' => true,
            // Note: pagination options are ignored for count queries
        ]);

        // $paginatedObjects = $objectService->searchObjects($searchQuery);
        // $totalCount = $objectService->searchObjects($countQuery);
        //
        // // Verify pagination works correctly
        // assert(count($paginatedObjects) <= 10, 'Should return max 10 objects');
        // assert($totalCount >= count($paginatedObjects), 'Total count should be >= paginated results');

        // Test type safety
        // $searchResult = $objectService->searchObjects($baseQuery);
        // $countResult = $objectService->searchObjects(array_merge($baseQuery, ['_count' => true]));
        //
        // assert(is_array($searchResult), 'Search should return array');
        // assert(is_int($countResult), 'Count should return integer');

    }//end exampleCountTesting()


    /**
     * Example 19: Using the consolidated paginated search method
     *
     * This example demonstrates how to use the searchObjectsPaginated method
     * which combines search, count, and facets into a single convenient call.
     * It also shows how the new _count option provides an alternative approach.
     *
     * @return void
     */
    public function examplePaginatedSearch(): void
    {
        // This example shows the new consolidated approach

        // Complex search query with pagination
        $query = [
            '@self' => [
                'register' => [1, 2, 3],
                'schema' => 2,
                'organisation' => 'IS NOT NULL',
            ],
            'name' => 'John',
            'status' => ['active', 'pending'],
            'address.city' => 'Amsterdam',
            '_search' => 'important customer',
            '_order' => [
                '@self.created' => 'DESC',
                'priority' => 'ASC'
            ],
            '_limit' => 25,
            '_page' => 2,  // Can use page instead of offset
            '_published' => true,
            '_extend' => ['@self.register', '@self.schema'],
            '_fields' => ['name', 'email', 'status'],
            '_queries' => ['status', 'priority'], // For facets
        ];

        // OLD WAY (multiple calls):
        // $objects = $objectService->searchObjects($query);
        // $total = $objectService->countSearchObjects($query);
        // $facets = $objectService->getFacetsForObjects($query);
        // $pages = ceil($total / $query['_limit']);
        // // Manual pagination calculation...

        // NEW WAY (single call):
        // $result = $objectService->searchObjectsPaginated($query);
        //
        // Result contains everything needed:
        // $result = [
        //     'results' => [...],    // Rendered ObjectEntity objects
        //     'total' => 150,        // Total matching objects
        //     'page' => 2,           // Current page
        //     'pages' => 6,          // Total pages
        //     'limit' => 25,         // Items per page
        //     'offset' => 25,        // Current offset
        //     'facets' => [...]      // Facet data for filtering
        // ];

        // Benefits of the new approach:
        // 1. Single method call instead of 3 separate calls
        // 2. Automatic pagination calculation
        // 3. Consistent query structure across all operations
        // 4. Optimized database queries (count uses COUNT(*))
        // 5. Less code duplication in services
        // 6. Built-in page/offset conversion

        // Usage in controllers/services:
        // return new JSONResponse($objectService->searchObjectsPaginated($query));

        // ALTERNATIVE: Using the new unified searchObjects with _count option
        // This approach gives you more control and uses a single method:
        
        // $searchQuery = array_merge($query, ['_limit' => 25, '_page' => 2]);
        // $countQuery = array_merge($query, ['_count' => true]);
        // 
        // $objects = $objectService->searchObjects($searchQuery);
        // $total = $objectService->searchObjects($countQuery);
        // $pages = ceil($total / 25);
        // 
        // $result = [
        //     'results' => $objects,
        //     'total' => $total,
        //     'page' => 2,
        //     'pages' => $pages,
        //     'limit' => 25
        // ];

        // Both approaches are valid:
        // - searchObjectsPaginated(): More convenient, single call
        // - searchObjects() with _count: More flexible, unified method

    }//end examplePaginatedSearch()

}//end class 