<?php
/**
 * Test object metadata from searchObjects
 */

// Quick test via curl to ObjectService
$url = 'http://localhost/index.php/apps/openregister/api/objects?_limit=1&_schemas[]=306';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_USERPWD, 'admin:admin');

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "=== OBJECT METADATA TEST ===\n\n";
echo "HTTP Code: $httpCode\n\n";

if ($httpCode === 200) {
    $result = json_decode($response, true);
    $objects = $result['results'] ?? [];
    
    if (!empty($objects)) {
        $object = $objects[0];
        echo "Object ID: " . ($object['id'] ?? 'N/A') . "\n";
        echo "UUID: " . ($object['uuid'] ?? $object['_uuid'] ?? 'N/A') . "\n";
        echo "Register: " . ($object['_register'] ?? $object['register'] ?? 'N/A') . "\n";
        echo "Schema: " . ($object['_schema'] ?? $object['schema'] ?? 'N/A') . "\n";
        echo "URI: " . ($object['uri'] ?? $object['_uri'] ?? 'N/A') . "\n\n";
        
        echo "Full object:\n";
        echo json_encode($object, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "No objects found\n";
    }
} else {
    echo "Error! HTTP $httpCode\n";
    echo $response . "\n";
}

