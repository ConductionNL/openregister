<?php
/**
 * Test all available tools
 */

echo "=== TOOLS COMPREHENSIVE TEST ===\n\n";

// 1. List available tools
echo "Step 1: Getting available tools...\n";
echo "────────────────────────────────────────────\n";

$ch = curl_init('http://localhost/index.php/apps/openregister/api/agents/tools');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_USERPWD, 'admin:admin');

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo "✗ Failed to get tools: HTTP $httpCode\n";
    echo substr($response, 0, 500) . "\n";
    exit(1);
}

$result = json_decode($response, true);
$tools = $result['results'] ?? [];

echo "✓ Found " . count($tools) . " tools:\n\n";
foreach ($tools as $toolId => $metadata) {
    echo "  • $toolId\n";
    echo "    {$metadata['name']}\n";
    echo "    {$metadata['description']}\n\n";
}

// 2. Test ApplicationTool
echo "\n";
echo "Step 2: Testing ApplicationTool...\n";
echo "────────────────────────────────────────────\n";

// Get Agent 4's UUID
$ch = curl_init('http://localhost/index.php/apps/openregister/api/agents');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_USERPWD, 'admin:admin');

$response = curl_exec($ch);
$agentsResult = json_decode($response, true);
$agent4 = null;

foreach ($agentsResult['results'] ?? [] as $agent) {
    if ($agent['id'] === 4) {
        $agent4 = $agent;
        break;
    }
}

if (!$agent4) {
    echo "✗ Agent 4 not found\n";
    exit(1);
}

echo "✓ Found Agent 4: {$agent4['name']} (UUID: {$agent4['uuid']})\n";

// Send test message
$testData = [
    'agentUuid' => $agent4['uuid'],
    'message' => 'Can you list all available applications in the system?'
];

$ch = curl_init('http://localhost/index.php/apps/openregister/api/chat/send');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testData));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_USERPWD, 'admin:admin');

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "\nChat Response (HTTP $httpCode):\n";

if ($httpCode === 200) {
    $chatResult = json_decode($response, true);
    
    if (isset($chatResult['message']['content'])) {
        $content = $chatResult['message']['content'];
        
        // Check if tool was called (look for function call indicators)
        if (stripos($content, 'application') !== false && 
            (stripos($content, 'found') !== false || stripos($content, 'list') !== false)) {
            echo "✓ Tool appears to have been called!\n";
            echo "  Response length: " . strlen($content) . " chars\n";
            echo "  Preview: " . substr($content, 0, 150) . "...\n";
        } else {
            echo "✗ Tool may NOT have been called\n";
            echo "  Response: " . substr($content, 0, 200) . "...\n";
        }
    } else {
        echo "✗ Unexpected response structure\n";
        echo substr(json_encode($chatResult, JSON_PRETTY_PRINT), 0, 500) . "\n";
    }
} else {
    echo "✗ Error! HTTP $httpCode\n";
    echo substr($response, 0, 500) . "\n";
}

// 3. Test CMS Tool (if available)
echo "\n\n";
echo "Step 3: Testing CMS Tool...\n";
echo "────────────────────────────────────────────\n";

if (isset($tools['opencatalogi.cms'])) {
    echo "✓ CMS Tool is available\n";
    
    $testData2 = [
        'agentUuid' => $agent4['uuid'],
        'message' => 'Can you list all available pages?'
    ];
    
    $ch = curl_init('http://localhost/index.php/apps/openregister/api/chat/send');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testData2));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_USERPWD, 'admin:admin');
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "\nChat Response (HTTP $httpCode):\n";
    
    if ($httpCode === 200) {
        $chatResult = json_decode($response, true);
        
        if (isset($chatResult['message']['content'])) {
            $content = $chatResult['message']['content'];
            
            if (stripos($content, 'page') !== false) {
                echo "✓ CMS Tool appears to be working!\n";
                echo "  Response length: " . strlen($content) . " chars\n";
                echo "  Preview: " . substr($content, 0, 150) . "...\n";
            } else {
                echo "? CMS Tool response unclear\n";
                echo "  Response: " . substr($content, 0, 200) . "...\n";
            }
        }
    } else {
        echo "✗ Error! HTTP $httpCode\n";
    }
} else {
    echo "⊗ CMS Tool not available in registry\n";
}

echo "\n";
echo "=== TEST COMPLETE ===\n";
echo "\nNOTE: If tools are not being called, check:\n";
echo "  1. Is Agent 4 configured with tools? (openregister.application)\n";
echo "  2. Are you using OpenAI or Ollama? (NOT Fireworks AI)\n";
echo "  3. Check logs: docker logs -f master-nextcloud-1 | grep 'ChatService.*function'\n";

