<?php
/**
 * Test tools functionality
 */

echo "=== TOOLS TEST ===\n\n";

// 1. List available tools
echo "Step 1: Getting available tools...\n";
$ch = curl_init('http://localhost/index.php/apps/openregister/api/agents/tools');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_USERPWD, 'admin:admin');

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";

if ($httpCode === 200) {
    $result = json_decode($response, true);
    $tools = $result['results'] ?? [];
    
    echo "Available tools: " . count($tools) . "\n\n";
    foreach ($tools as $toolId => $metadata) {
        echo "  - $toolId: {$metadata['name']}\n";
        echo "    {$metadata['description']}\n\n";
    }
    
    // 2. Configure Agent 4 with ApplicationTool
    echo "Step 2: Configuring Agent 4 with ApplicationTool...\n";
    if (isset($tools['openregister.application'])) {
        echo "✓ ApplicationTool is available\n";
        echo "  Adding to Agent 4...\n";
        
        // Update agent via API
        $agentData = [
            'tools' => ['openregister.application']
        ];
        
        $ch = curl_init('http://localhost/index.php/apps/openregister/api/agents/9966ab0a-f168-41ce-be82-fd1111f107e0');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($agentData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_USERPWD, 'admin:admin');
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            echo "✓ Agent 4 updated successfully\n\n";
        } else {
            echo "✗ Failed to update agent: HTTP $httpCode\n";
            echo substr($response, 0, 200) . "\n\n";
        }
    } else {
        echo "✗ ApplicationTool not found in registry\n";
        echo "Available tool IDs: " . implode(', ', array_keys($tools)) . "\n";
    }
} else {
    echo "Error! HTTP $httpCode\n";
    echo substr($response, 0, 500) . "\n";
}

echo "\n=== TEST COMPLETE ===\n";

