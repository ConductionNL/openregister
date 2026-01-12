<?php
/**
 * Quick script to vectorize all objects
 */

require_once __DIR__ . '/../../../lib/base.php';

use OCA\OpenRegister\Service\VectorizationService;
use OCA\OpenRegister\Service\Vectorization\ObjectVectorizationStrategy;

// Get service from container.
$container = \OC::$server->getRegisteredAppContainer('openregister');
$vectorizationService = $container->get(VectorizationService::class);

echo "Starting object vectorization...\n";

$result = $vectorizationService->processEntities(
    ObjectVectorizationStrategy::class,
    [
        'views' => null,       // All objects
        'batch_size' => 100,   // Process all at once
    ]
);

echo "\n=== Vectorization Results ===\n";
echo "Total entities: " . $result['total_entities'] . "\n";
echo "Processed: " . $result['processed'] . "\n";
echo "Successful: " . $result['successful'] . "\n";
echo "Failed: " . $result['failed'] . "\n";

if (!empty($result['errors'])) {
    echo "\nErrors:\n";
    foreach ($result['errors'] as $error) {
        echo "- " . $error . "\n";
    }
}

echo "\nDone!\n";

