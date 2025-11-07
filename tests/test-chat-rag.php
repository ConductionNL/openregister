#!/usr/bin/env php
<?php
/**
 * Test script for RAG functionality in chat
 * 
 * This script:
 * 1. Creates a new conversation with Agent 4
 * 2. Sends a message about "Mokum"
 * 3. Checks if RAG correctly retrieves object data
 */

require_once __DIR__ . '/../vendor/autoload.php';

use OCA\OpenRegister\AppInfo\Application;
use OCP\AppFramework\App;

// Initialize Nextcloud app context
\OC::$CLI = true;
\OC::$WEBROOT = '/var/www/html';

try {
    // Bootstrap Nextcloud
    require_once '/var/www/html/lib/base.php';
    
    // Get application container
    $app = \OC::$server->query(Application::class);
    $container = $app->getContainer();
    
    // Get services
    $chatService = $container->get(\OCA\OpenRegister\Service\ChatService::class);
    $conversationMapper = $container->get(\OCA\OpenRegister\Db\ConversationMapper::class);
    $agentMapper = $container->get(\OCA\OpenRegister\Db\AgentMapper::class);
    $organisationService = $container->get(\OCA\OpenRegister\Service\OrganisationService::class);
    
    echo "=== RAG CHAT TEST ===\n\n";
    
    // 1. Find Agent 4
    echo "Step 1: Loading Agent 4...\n";
    $agent = $agentMapper->findByUuid('9966ab0a-f168-41ce-be82-fd1111f107e0');
    echo "✓ Agent loaded: {$agent->getName()}\n";
    echo "  - RAG enabled: " . ($agent->getEnableRag() ? 'YES' : 'NO') . "\n";
    echo "  - Search mode: {$agent->getRagSearchMode()}\n";
    echo "  - Include objects: " . ($agent->getRagIncludeObjects() ? 'YES' : 'NO') . "\n";
    echo "  - Include files: " . ($agent->getRagIncludeFiles() ? 'YES' : 'NO') . "\n";
    echo "  - Views: " . json_encode($agent->getViews()) . "\n\n";
    
    // 2. Create a test conversation
    echo "Step 2: Creating conversation...\n";
    $organisation = $organisationService->getActiveOrganisation();
    
    $conversation = new \OCA\OpenRegister\Db\Conversation();
    $conversation->setUserId('admin');
    $conversation->setOrganisation($organisation?->getUuid());
    $conversation->setAgentId($agent->getId());
    $conversation->setTitle('RAG Test - ' . date('H:i:s'));
    $conversation = $conversationMapper->insert($conversation);
    echo "✓ Conversation created: {$conversation->getUuid()}\n\n";
    
    // 3. Send test message
    echo "Step 3: Sending message: 'Wat is de kleur van Mokum?'\n";
    $result = $chatService->processMessage(
        $conversation->getId(),
        'admin',
        'Wat is de kleur van Mokum?'
    );
    
    echo "✓ Response received:\n";
    echo "─────────────────────────────────────────\n";
    echo "Message: " . ($result['message'] ?? 'NO MESSAGE') . "\n";
    echo "─────────────────────────────────────────\n\n";
    
    // 4. Check sources
    echo "Step 4: Analyzing sources...\n";
    $sources = $result['sources'] ?? [];
    echo "Sources found: " . count($sources) . "\n\n";
    
    if (!empty($sources)) {
        foreach ($sources as $i => $source) {
            echo "Source " . ($i + 1) . ":\n";
            echo "  - Name: " . ($source['name'] ?? 'UNKNOWN') . "\n";
            echo "  - Type: " . ($source['type'] ?? 'unknown') . "\n";
            echo "  - Text preview: " . substr($source['text'] ?? '', 0, 100) . "...\n";
            echo "  - Similarity: " . ($source['similarity'] ?? 'N/A') . "\n\n";
        }
    } else {
        echo "⚠️  WARNING: No sources found! RAG may not be working.\n\n";
    }
    
    // 5. Check if answer is correct
    echo "Step 5: Validation...\n";
    $message = strtolower($result['message'] ?? '');
    if (strpos($message, 'blauw') !== false) {
        echo "✅ SUCCESS! Answer contains 'blauw' - RAG is working!\n";
    } elseif (strpos($message, 'blue') !== false) {
        echo "✅ SUCCESS! Answer contains 'blue' - RAG is working!\n";
    } else {
        echo "❌ FAIL: Answer does not mention the correct color (blauw/blue).\n";
        echo "   Expected: Mokum is de kleur blauw\n";
        echo "   Got: " . substr($message, 0, 200) . "\n";
    }
    
    echo "\n=== TEST COMPLETE ===\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}

