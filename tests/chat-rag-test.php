#!/usr/bin/env php
<?php
/**
 * Test script for RAG semantic search
 * 
 * Tests if semantic search correctly finds objects based on vector similarity
 * 
 * Usage: docker exec -u 33 master-nextcloud-1 php apps-extra/openregister/tests/chat-rag-test.php
 */

require_once '/var/www/html/lib/base.php';

echo "=== RAG Semantic Search Test ===\n\n";

// Get services.
$container = \OC::$server->get(\OCP\IServerContainer::class);
$vectorService = $container->get(\OCA\OpenRegister\Service\VectorEmbeddingService::class);

// Test queries.
$queries = [
    'Wat is de kleur van mokum?',
    'Wat is de kleur van utrecht?',
    'Wat is de kleur van amsterdam?',
];

foreach ($queries as $query) {
    echo "Query: $query\n";
    echo str_repeat('-', 80) . "\n";
    
    try {
        // Perform semantic search.
        $results = $vectorService->semanticSearch($query, 5, []);
        
        echo "Found " . count($results) . " results:\n\n";
        
        foreach ($results as $i => $result) {
            $num = $i + 1;
            $type = $result['entity_type'] ?? 'unknown';
            $id = $result['entity_id'] ?? 'unknown';
            $similarity = round($result['similarity'] ?? 0, 3);
            $text = substr($result['chunk_text'] ?? '', 0, 150);
            
            echo "$num. [$type] $id (similarity: $similarity)\n";
            echo "   " . $text . "...\n\n";
        }
        
    } catch (\Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n\n";
    }
    
    echo "\n";
}

echo "=== Test Complete ===\n";

