<?php
/**
 * Simple chat API test using curl
 */

$url = 'http://localhost/index.php/apps/openregister/api/chat/send';
$data = [
    'agentUuid' => '9966ab0a-f168-41ce-be82-fd1111f107e0',
    'message' => 'Wat is de kleur van Mokum?'
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
]);
curl_setopt($ch, CURLOPT_USERPWD, 'admin:admin');

echo "=== RAG CHAT TEST ===\n\n";
echo "Sending: " . $data['message'] . "\n\n";

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response:\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

if ($httpCode === 200) {
    $result = json_decode($response, true);
    if ($result) {
        // DEBUG: Show full structure.
        echo "Full response structure:\n";
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
        
        // Try to extract message content.
        $messageContent = '';
        if (is_string($result['message'] ?? null)) {
            $messageContent = $result['message'];
        } elseif (is_array($result['message'] ?? null) && isset($result['message']['content'])) {
            $messageContent = $result['message']['content'];
        } elseif (isset($result['content'])) {
            $messageContent = $result['content'];
        }
        
        echo "Message content: " . $messageContent . "\n\n";
        echo "Sources: " . count($result['sources'] ?? []) . "\n";
        foreach (($result['sources'] ?? []) as $i => $source) {
            echo "  " . ($i+1) . ". " . ($source['name'] ?? 'Unknown') . " (" . ($source['type'] ?? 'unknown') . ")\n";
            echo "     Text: " . substr($source['text'] ?? '', 0, 80) . "...\n";
            echo "     Similarity: " . ($source['similarity'] ?? 'N/A') . "\n";
        }
        
        echo "\n";
        $message = strtolower($messageContent);
        if (strpos($message, 'blauw') !== false || strpos($message, 'blue') !== false) {
            echo "✅ SUCCESS! Answer contains 'blauw' or 'blue'\n";
        } else {
            echo "❌ FAIL: Answer does not mention the color\n";
        }
    } else {
        echo "Invalid JSON response:\n";
        echo $response . "\n";
    }
} else {
    echo "Error! HTTP $httpCode\n";
    echo $response . "\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

