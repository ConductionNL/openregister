<?php
/**
 * Example: Asynchronous Search with Faceting and Discovery
 * 
 * This example demonstrates how to use the new asynchronous search methods
 * for improved performance when requesting multiple operations.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use OCA\OpenRegister\Service\ObjectService;
use React\Promise\PromiseInterface;

/**
 * Example 1: Using the async method with promise handling
 */
function asyncSearchExample(ObjectService $objectService): PromiseInterface
{
    $query = [
        '@self' => [
            'register' => 1,
        ],
        'status' => 'active',
        '_search' => 'important',
        '_limit' => 20,
        '_page' => 1,
        '_facets' => [
            '@self' => [
                'register' => ['type' => 'terms'],
                'schema' => ['type' => 'terms'],
                'created' => ['type' => 'date_histogram', 'interval' => 'month']
            ],
            'status' => ['type' => 'terms'],
            'priority' => ['type' => 'range', 'ranges' => [
                ['to' => 5],
                ['from' => 5, 'to' => 10],
                ['from' => 10]
            ]]
        ],
        '_facetable' => true,
        '_sample_size' => 100
    ];

    // Execute async search - operations run concurrently
    return $objectService->searchObjectsPaginatedAsync($query)
        ->then(function ($results) {
            echo "Async search completed!\n";
            echo "Found {$results['total']} objects\n";
            echo "Page {$results['page']} of {$results['pages']}\n";
            
            if (isset($results['facetable'])) {
                $metadataFields = count($results['facetable']['@self']);
                $objectFields = count($results['facetable']['object_fields']);
                echo "Discovered {$metadataFields} metadata fields and {$objectFields} object fields\n";
            }
            
            return $results;
        })
        ->otherwise(function ($error) {
            echo "Error in async search: " . $error->getMessage() . "\n";
            throw $error;
        });
}

/**
 * Example 2: Using the sync convenience method
 */
function syncConvenienceExample(ObjectService $objectService): array
{
    $query = [
        '@self' => [
            'register' => 1,
        ],
        'status' => 'active',
        '_facets' => [
            '@self' => [
                'register' => ['type' => 'terms'],
                'schema' => ['type' => 'terms']
            ],
            'status' => ['type' => 'terms']
        ],
        '_facetable' => true,
        '_limit' => 10
    ];

    // Execute with async performance but sync interface
    $startTime = microtime(true);
    $results = $objectService->searchObjectsPaginatedSync($query);
    $endTime = microtime(true);
    
    $duration = ($endTime - $startTime) * 1000; // Convert to milliseconds
    
    echo "Sync convenience method completed in {$duration}ms\n";
    echo "Found {$results['total']} objects\n";
    
    return $results;
}

/**
 * Example 3: Performance comparison
 */
function performanceComparison(ObjectService $objectService): void
{
    $query = [
        '@self' => [
            'register' => 1,
        ],
        '_facets' => [
            '@self' => [
                'register' => ['type' => 'terms'],
                'schema' => ['type' => 'terms']
            ]
        ],
        '_facetable' => true,
        '_limit' => 20
    ];

    // Test traditional sync method
    $startTime = microtime(true);
    $syncResults = $objectService->searchObjectsPaginated($query);
    $syncDuration = (microtime(true) - $startTime) * 1000;

    // Test async convenience method
    $startTime = microtime(true);
    $asyncResults = $objectService->searchObjectsPaginatedSync($query);
    $asyncDuration = (microtime(true) - $startTime) * 1000;

    echo "\nPerformance Comparison:\n";
    echo "Traditional sync method: {$syncDuration}ms\n";
    echo "Async convenience method: {$asyncDuration}ms\n";
    
    $improvement = (($syncDuration - $asyncDuration) / $syncDuration) * 100;
    echo "Performance improvement: " . round($improvement, 1) . "%\n";
    
    // Verify results are identical
    unset($syncResults['facets'], $asyncResults['facets']); // Facets may have slight timing differences
    if ($syncResults === $asyncResults) {
        echo "✓ Results are identical\n";
    } else {
        echo "✗ Results differ\n";
    }
}

/**
 * Example 4: Error handling with async methods
 */
function errorHandlingExample(ObjectService $objectService): PromiseInterface
{
    $invalidQuery = [
        '@self' => [
            'register' => 999999, // Non-existent register
        ],
        '_facets' => [
            'invalid_field' => ['type' => 'terms']
        ],
        '_facetable' => true
    ];

    return $objectService->searchObjectsPaginatedAsync($invalidQuery)
        ->then(function ($results) {
            echo "Search succeeded despite invalid register\n";
            return $results;
        })
        ->otherwise(function ($error) {
            echo "Handled error gracefully: " . $error->getMessage() . "\n";
            
            // Return empty results structure
            return [
                'results' => [],
                'total' => 0,
                'page' => 1,
                'pages' => 1,
                'limit' => 20,
                'offset' => 0,
                'facets' => ['facets' => []],
                'facetable' => ['@self' => [], 'object_fields' => []]
            ];
        });
}

// Usage example (would be called from a controller or service):
/*
$objectService = $container->get(ObjectService::class);

// Example 1: Pure async with promises
asyncSearchExample($objectService)->then(function ($results) {
    // Handle results
});

// Example 2: Sync interface with async performance
$results = syncConvenienceExample($objectService);

// Example 3: Performance comparison
performanceComparison($objectService);

// Example 4: Error handling
errorHandlingExample($objectService)->then(function ($results) {
    // Handle results or errors
});
*/ 