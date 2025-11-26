<?php
/**
 * Direct semantic search test - bypass all caching
 */

require_once __DIR__ . '/../../../lib/base.php';

use OCA\OpenRegister\Service\VectorEmbeddingService;

echo "=== DIRECT SEMANTIC SEARCH TEST ===\n\n";

// Get service.
$container = \OC::$server->getRegisteredAppContainer('openregister');
$vectorService = $container->get(VectorEmbeddingService::class);

// Test 1: Search without filters.
echo "Test 1: Search 'mokum' WITHOUT filters\n";
echo "----------------------------------------\n";
try {
    $results = $vectorService->semanticSearch('mokum', 5, []);
    echo "Found " . count($results) . " results\n";
    foreach ($results as $i => $result) {
        echo "\n" . ($i + 1) . ". ";
        echo "Type: " . ($result['entity_type'] ?? 'unknown') . "\n";
        echo "   ID: " . ($result['entity_id'] ?? 'unknown') . "\n";
        echo "   Similarity: " . ($result['similarity'] ?? 0) . "\n";
        echo "   Text preview: " . substr($result['chunk_text'] ?? '', 0, 100) . "\n";
        
        // Check metadata.
        if (isset($result['metadata'])) {
            $meta = $result['metadata'];
            if (is_string($meta)) {
                $meta = json_decode($meta, true);
            }
            echo "   Metadata title: " . ($meta['object_title'] ?? $meta['title'] ?? $meta['name'] ?? 'NONE') . "\n";
        } else {
            echo "   Metadata: MISSING!\n";
        }
    }
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

// Test 2: Search with object filter.
echo "\n\nTest 2: Search 'mokum' WITH object filter\n";
echo "-------------------------------------------\n";
try {
    $results = $vectorService->semanticSearch('mokum', 5, ['entity_type' => ['object']]);
    echo "Found " . count($results) . " results\n";
    foreach ($results as $i => $result) {
        echo "\n" . ($i + 1) . ". ";
        echo "Type: " . ($result['entity_type'] ?? 'unknown') . "\n";
        echo "   ID: " . ($result['entity_id'] ?? 'unknown') . "\n";
        echo "   Similarity: " . ($result['similarity'] ?? 0) . "\n";
        echo "   Text preview: " . substr($result['chunk_text'] ?? '', 0, 100) . "\n";
    }
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n\nDone!\n";

