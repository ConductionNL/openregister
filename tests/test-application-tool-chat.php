<?php
/**
 * Test ApplicationTool via chat
 */

$url = 'http://localhost/index.php/apps/openregister/api/chat/send';

// Test 1: Ask to list applications.
echo "=== APPLICATION TOOL CHAT TEST ===\n\n";

echo "Test 1: Asking agent to list applications...\n";
echo "────────────────────────────────────────────\n";

$data = [
    'agentUuid' => '9966ab0a-f168-41ce-be82-fd1111f107e0',  // Agent 4
    'message' => 'Can you list all available applications in the system?'
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_USERPWD, 'admin:admin');

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n\n";

if ($httpCode === 200) {
    $result = json_decode($response, true);
    
    if (isset($result['message']['content'])) {
        echo "AI Response:\n";
        echo "────────────────────────────────────────────\n";
        echo $result['message']['content'] . "\n";
        echo "────────────────────────────────────────────\n\n";
        
        // Check if tool was used (look for function call indicators).
        $content = strtolower($result['message']['content']);
        if (strpos($content, 'application') !== false && strpos($content, 'found') !== false) {
            echo "✓ Tool appears to have been called!\n";
        } else {
            echo "? Tool may not have been called (check response)\n";
        }
    } else {
        echo "Response structure:\n";
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
} else {
    echo "Error! HTTP $httpCode\n";
    echo substr($response, 0, 500) . "\n";
}

echo "\n";

// Test 2: Create an application.
echo "Test 2: Asking agent to create a test application...\n";
echo "────────────────────────────────────────────\n";

$data2 = [
    'agentUuid' => '9966ab0a-f168-41ce-be82-fd1111f107e0',
    'message' => 'Can you create a new application called "Test App" with description "This is a test application"?'
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data2));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_USERPWD, 'admin:admin');

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n\n";

if ($httpCode === 200) {
    $result = json_decode($response, true);
    
    if (isset($result['message']['content'])) {
        echo "AI Response:\n";
        echo "────────────────────────────────────────────\n";
        echo $result['message']['content'] . "\n";
        echo "────────────────────────────────────────────\n\n";
        
        // Check if tool was used.
        $content = strtolower($result['message']['content']);
        if (strpos($content, 'created') !== false || strpos($content, 'uuid') !== false) {
            echo "✓ Tool appears to have been called!\n";
        } else {
            echo "? Tool may not have been called (check response)\n";
        }
    }
} else {
    echo "Error! HTTP $httpCode\n";
    echo substr($response, 0, 500) . "\n";
}

echo "\n=== TEST COMPLETE ===\n";

