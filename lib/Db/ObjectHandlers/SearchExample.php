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
     * Example 9: Using ObjectService wrapper for clean search interface
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

        // Example 3: Count objects using same query structure
        // $total = $objectService->countObjects($complexQuery);

        // Example 4: Get facets using same query structure  
        // $facets = $objectService->getFacetsForObjects($complexQuery);

        // This provides a much cleaner interface than the old findAll method
        // and makes the code more testable and maintainable.

    }//end exampleObjectServiceSearch()

}//end class 