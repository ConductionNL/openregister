<?php
/**
 * Simple API test to check organization structure and type filtering
 */

// Test the API calls directly
$baseUrl = 'http://localhost';
$username = 'admin';
$password = 'admin';

echo "🔍 Simple API Test for Type Filtering Issue\n";
echo "==========================================\n\n";

// Test 1: Get all organizations
echo "Test 1: Getting all organizations...\n";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $baseUrl . '/index.php/apps/openregister/api/objects/voorzieningen/organisatie?_limit=5&_source=database');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
$response1 = curl_exec($ch);
$httpCode1 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode1\n";
if ($response1) {
    $data1 = json_decode($response1, true);
    echo "Found " . count($data1['results'] ?? []) . " organizations\n";
    
    // Show structure of first organization
    if (!empty($data1['results'])) {
        $firstOrg = $data1['results'][0];
        echo "First organization structure:\n";
        echo "ID: " . ($firstOrg['id'] ?? 'N/A') . "\n";
        echo "Name: " . ($firstOrg['name'] ?? 'N/A') . "\n";
        echo "Type: " . ($firstOrg['type'] ?? 'N/A') . "\n";
        echo "Object data: " . json_encode($firstOrg['object'] ?? [], JSON_PRETTY_PRINT) . "\n";
    }
} else {
    echo "No response received\n";
}

echo "\n" . str_repeat("-", 50) . "\n\n";

// Test 2: Try type filtering
echo "Test 2: Trying type filtering with samenwerking...\n";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $baseUrl . '/index.php/apps/openregister/api/objects/voorzieningen/organisatie?_limit=5&_source=database&type[]=samenwerking');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
$response2 = curl_exec($ch);
$httpCode2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode2\n";
if ($response2) {
    $data2 = json_decode($response2, true);
    echo "Found " . count($data2['results'] ?? []) . " organizations with type=samenwerking\n";
    echo "Response: " . substr($response2, 0, 200) . "...\n";
} else {
    echo "No response received\n";
}

echo "\n" . str_repeat("-", 50) . "\n\n";

// Test 3: Try with community
echo "Test 3: Trying type filtering with community...\n";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $baseUrl . '/index.php/apps/openregister/api/objects/voorzieningen/organisatie?_limit=5&_source=database&type[]=community');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
$response3 = curl_exec($ch);
$httpCode3 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode3\n";
if ($response3) {
    $data3 = json_decode($response3, true);
    echo "Found " . count($data3['results'] ?? []) . " organizations with type=community\n";
    echo "Response: " . substr($response3, 0, 200) . "...\n";
} else {
    echo "No response received\n";
}

echo "\n✅ Simple API test completed!\n";
