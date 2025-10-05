<?php
/**
 * Debug endpoint for type filtering issue
 * This can be accessed via: /index.php/apps/openregister/debug_type_filtering_endpoint.php
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IDBConnection;

header('Content-Type: application/json');

try {
    // Get services
    $connection = \OC::$server->get(IDBConnection::class);
    $objectMapper = new ObjectEntityMapper($connection);
    $registerMapper = new RegisterMapper($connection);
    $schemaMapper = new SchemaMapper($connection);
    $objectService = \OC::$server->get(ObjectService::class);

    // Set register and schema context
    $objectService->setRegister('voorzieningen');
    $objectService->setSchema('organisatie');

    $results = [];

    // Test 1: Get all organizations
    echo "Testing all organizations...\n";
    $query1 = [
        '_limit' => 10,
        '_page' => 1,
        '_source' => 'database'
    ];
    $result1 = $objectService->searchObjectsPaginated($query1);
    $results['all_organizations'] = [
        'count' => count($result1['results']),
        'organizations' => array_map(function($org) {
            $objectData = $org->getObject();
            return [
                'id' => $org->getId(),
                'name' => $org->getName(),
                'type' => $objectData['type'] ?? 'NO TYPE',
                'object_data' => $objectData
            ];
        }, $result1['results'])
    ];

    // Test 2: Try type filtering with samenwerking
    echo "Testing type filtering with samenwerking...\n";
    $query2 = [
        '_limit' => 10,
        '_page' => 1,
        '_source' => 'database',
        'type' => ['samenwerking']
    ];
    $result2 = $objectService->searchObjectsPaginated($query2);
    $results['type_samenwerking'] = [
        'count' => count($result2['results']),
        'organizations' => array_map(function($org) {
            $objectData = $org->getObject();
            return [
                'id' => $org->getId(),
                'name' => $org->getName(),
                'type' => $objectData['type'] ?? 'NO TYPE'
            ];
        }, $result2['results'])
    ];

    // Test 3: Try type filtering with community
    echo "Testing type filtering with community...\n";
    $query3 = [
        '_limit' => 10,
        '_page' => 1,
        '_source' => 'database',
        'type' => ['community']
    ];
    $result3 = $objectService->searchObjectsPaginated($query3);
    $results['type_community'] = [
        'count' => count($result3['results']),
        'organizations' => array_map(function($org) {
            $objectData = $org->getObject();
            return [
                'id' => $org->getId(),
                'name' => $org->getName(),
                'type' => $objectData['type'] ?? 'NO TYPE'
            ];
        }, $result3['results'])
    ];

    // Test 4: Try type filtering with both types
    echo "Testing type filtering with both types...\n";
    $query4 = [
        '_limit' => 10,
        '_page' => 1,
        '_source' => 'database',
        'type' => ['samenwerking', 'community']
    ];
    $result4 = $objectService->searchObjectsPaginated($query4);
    $results['type_both'] = [
        'count' => count($result4['results']),
        'organizations' => array_map(function($org) {
            $objectData = $org->getObject();
            return [
                'id' => $org->getId(),
                'name' => $org->getName(),
                'type' => $objectData['type'] ?? 'NO TYPE'
            ];
        }, $result4['results'])
    ];

    // Test 5: Direct database query to check type field
    echo "Testing direct database query...\n";
    $qb = $connection->getQueryBuilder();
    $qb->select('o.id', 'o.name', 'o.object')
       ->from('openregister_objects', 'o')
       ->where($qb->expr()->like('o.name', $qb->createNamedParameter('%Samenwerking%')))
       ->orWhere($qb->expr()->like('o.name', $qb->createNamedParameter('%Community%')));

    $stmt = $qb->executeQuery();
    $rows = $stmt->fetchAllAssociative();

    $results['direct_database_query'] = [
        'count' => count($rows),
        'organizations' => array_map(function($row) {
            $objectData = json_decode($row['object'], true);
            return [
                'id' => $row['id'],
                'name' => $row['name'],
                'type' => $objectData['type'] ?? 'NO TYPE',
                'object_json' => $row['object']
            ];
        }, $rows)
    ];

    echo json_encode($results, JSON_PRETTY_PRINT);

} catch (\Exception $e) {
    echo json_encode([
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}
