<?php
/**
 * Debug script for type filtering issue in OpenRegister
 * This script tests the type[] filtering for samenwerking and community organizations
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IDBConnection;

echo "🔍 Debugging OpenRegister Type Filtering Issue\n";
echo "=============================================\n\n";

// Get database connection
$connection = \OC::$server->get(IDBConnection::class);

// Get mappers
$objectMapper = new ObjectEntityMapper($connection);
$registerMapper = new RegisterMapper($connection);
$schemaMapper = new SchemaMapper($connection);

// Get ObjectService
$objectService = \OC::$server->get(ObjectService::class);

echo "📋 Test 1: Get all organizations without type filter\n";
echo "----------------------------------------------------\n";

// Test 1: Get all organizations without type filter
$query1 = [
    '_limit' => 10,
    '_page' => 1,
    '_extend' => ['@self.schema'],
    '_source' => 'database'
];

$objectService->setRegister('voorzieningen');
$objectService->setSchema('organisatie');

$result1 = $objectService->searchObjectsPaginated($query1);
echo "Found " . count($result1['results']) . " organizations\n";
foreach ($result1['results'] as $org) {
    $objectData = $org->getObject();
    $type = $objectData['type'] ?? 'NO TYPE';
    $name = $objectData['name'] ?? 'NO NAME';
    echo "  - $name (type: $type)\n";
}
echo "\n";

echo "📋 Test 2: Try type filtering with samenwerking\n";
echo "----------------------------------------------\n";

// Test 2: Try type filtering with samenwerking
$query2 = [
    '_limit' => 10,
    '_page' => 1,
    '_extend' => ['@self.schema'],
    '_source' => 'database',
    'type' => ['samenwerking']
];

$result2 = $objectService->searchObjectsPaginated($query2);
echo "Found " . count($result2['results']) . " organizations with type=samenwerking\n";
foreach ($result2['results'] as $org) {
    $objectData = $org->getObject();
    $type = $objectData['type'] ?? 'NO TYPE';
    $name = $objectData['name'] ?? 'NO NAME';
    echo "  - $name (type: $type)\n";
}
echo "\n";

echo "📋 Test 3: Try type filtering with community\n";
echo "-------------------------------------------\n";

// Test 3: Try type filtering with community
$query3 = [
    '_limit' => 10,
    '_page' => 1,
    '_extend' => ['@self.schema'],
    '_source' => 'database',
    'type' => ['community']
];

$result3 = $objectService->searchObjectsPaginated($query3);
echo "Found " . count($result3['results']) . " organizations with type=community\n";
foreach ($result3['results'] as $org) {
    $objectData = $org->getObject();
    $type = $objectData['type'] ?? 'NO TYPE';
    $name = $objectData['name'] ?? 'NO NAME';
    echo "  - $name (type: $type)\n";
}
echo "\n";

echo "📋 Test 4: Try type filtering with both types\n";
echo "--------------------------------------------\n";

// Test 4: Try type filtering with both types
$query4 = [
    '_limit' => 10,
    '_page' => 1,
    '_extend' => ['@self.schema'],
    '_source' => 'database',
    'type' => ['samenwerking', 'community']
];

$result4 = $objectService->searchObjectsPaginated($query4);
echo "Found " . count($result4['results']) . " organizations with type=samenwerking OR type=community\n";
foreach ($result4['results'] as $org) {
    $objectData = $org->getObject();
    $type = $objectData['type'] ?? 'NO TYPE';
    $name = $objectData['name'] ?? 'NO NAME';
    echo "  - $name (type: $type)\n";
}
echo "\n";

echo "📋 Test 5: Get specific organizations by name to see their structure\n";
echo "-------------------------------------------------------------------\n";

// Test 5: Get specific organizations by name to see their structure
$query5 = [
    '_limit' => 10,
    '_page' => 1,
    '_extend' => ['@self.schema'],
    '_source' => 'database',
    'name' => ['Samenwerking 1']
];

$result5 = $objectService->searchObjectsPaginated($query5);
echo "Found " . count($result5['results']) . " organizations with name='Samenwerking 1'\n";
foreach ($result5['results'] as $org) {
    $objectData = $org->getObject();
    $type = $objectData['type'] ?? 'NO TYPE';
    $name = $objectData['name'] ?? 'NO NAME';
    echo "  - $name (type: $type)\n";
    echo "  Full object data: " . json_encode($objectData, JSON_PRETTY_PRINT) . "\n";
}
echo "\n";

$query6 = [
    '_limit' => 10,
    '_page' => 1,
    '_extend' => ['@self.schema'],
    '_source' => 'database',
    'name' => ['Community 1']
];

$result6 = $objectService->searchObjectsPaginated($query6);
echo "Found " . count($result6['results']) . " organizations with name='Community 1'\n";
foreach ($result6['results'] as $org) {
    $objectData = $org->getObject();
    $type = $objectData['type'] ?? 'NO TYPE';
    $name = $objectData['name'] ?? 'NO NAME';
    echo "  - $name (type: $type)\n";
    echo "  Full object data: " . json_encode($objectData, JSON_PRETTY_PRINT) . "\n";
}
echo "\n";

echo "📋 Test 6: Check database directly for type field\n";
echo "------------------------------------------------\n";

// Test 6: Check database directly for type field
$qb = $connection->getQueryBuilder();
$qb->select('o.id', 'o.name', 'o.object')
   ->from('openregister_objects', 'o')
   ->where($qb->expr()->like('o.name', $qb->createNamedParameter('%Samenwerking%')))
   ->orWhere($qb->expr()->like('o.name', $qb->createNamedParameter('%Community%')));

$stmt = $qb->executeQuery();
$rows = $stmt->fetchAllAssociative();

echo "Direct database query found " . count($rows) . " rows:\n";
foreach ($rows as $row) {
    $objectData = json_decode($row['object'], true);
    $type = $objectData['type'] ?? 'NO TYPE';
    $name = $row['name'];
    echo "  - ID: {$row['id']}, Name: $name, Type: $type\n";
    echo "  Object JSON: " . $row['object'] . "\n\n";
}

echo "✅ Type filtering investigation completed!\n";
echo "Check the results above to identify the issue with type filtering\n";
