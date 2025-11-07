<?php
/**
 * Re-vectorize test objects with correct metadata
 */

$url = 'http://localhost/index.php/apps/openregister/api/objects/vectorize/batch';
$data = [
    'views' => [10],  // View 10 (test view)
    'batchSize' => 25
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_USERPWD, 'admin:admin');

echo "=== RE-VECTORIZING TEST OBJECTS ===\n\n";
echo "Vectorizing view 10...\n";

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n\n";

if ($httpCode === 200) {
    $result = json_decode($response, true);
    if ($result) {
        echo "Success: " . ($result['success'] ? 'YES' : 'NO') . "\n";
        echo "Vectorized: " . ($result['data']['vectorized'] ?? 0) . "\n";
        echo "Failed: " . ($result['data']['failed'] ?? 0) . "\n";
        echo "Total objects: " . ($result['data']['total_objects'] ?? 0) . "\n\n";
        
        if (!empty($result['data']['errors'])) {
            echo "Errors:\n";
            print_r($result['data']['errors']);
        }
    } else {
        echo "Invalid JSON response\n";
    }
} else {
    echo "Error! HTTP $httpCode\n";
    echo substr($response, 0, 500) . "\n";
}

