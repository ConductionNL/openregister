<?php
/**
 * Check if god classes have handlers and are delegating properly
 */

$godClasses = [
    'ObjectService' => 'lib/Service/ObjectService.php',
    'FileService' => 'lib/Service/FileService.php', 
    'ConfigurationService' => 'lib/Service/ConfigurationService.php',
    'SaveObject' => 'lib/Service/Object/SaveObject.php',
    'SaveObjects' => 'lib/Service/Object/SaveObjects.php',
    'ChatService' => 'lib/Service/ChatService.php',
];

echo "\n=== God Classes Delegation Analysis ===\n\n";

foreach ($godClasses as $className => $file) {
    if (!file_exists($file)) continue;
    
    echo "📊 $className:\n";
    
    $content = file_get_contents($file);
    
    // Check for handler injections in constructor
    preg_match_all('/private readonly (\w+Handler|\w+Service) \$(\w+)/', $content, $handlerMatches);
    $handlers = array_unique($handlerMatches[1]);
    
    echo "  Handlers injected: " . count($handlers) . "\n";
    if (count($handlers) > 0) {
        echo "    - " . implode("\n    - ", array_slice($handlers, 0, 5)) . "\n";
        if (count($handlers) > 5) echo "    ... and " . (count($handlers) - 5) . " more\n";
    }
    
    // Check for delegation patterns
    $delegationCount = preg_match_all('/\$this->(\w+Handler|\w+Service)->/', $content, $delegationMatches);
    echo "  Delegation calls: $delegationCount\n";
    
    // Check for business logic (long methods)
    preg_match_all('/public function (\w+)\s*\([^)]*\)\s*:?\s*\w*\s*{/', $content, $methodMatches, PREG_OFFSET_CAPTURE);
    $longMethods = 0;
    
    foreach ($methodMatches[0] as $i => $match) {
        $start = $match[1];
        $nextStart = $methodMatches[0][$i + 1][1] ?? strlen($content);
        $methodLength = $nextStart - $start;
        $lines = substr_count(substr($content, $start, $methodLength), "\n");
        
        if ($lines > 50) {
            $longMethods++;
        }
    }
    
    echo "  Methods > 50 lines: $longMethods\n";
    
    // Assessment
    if (count($handlers) > 5 && $delegationCount > 20) {
        echo "  ✅ GOOD: Has handlers and delegates\n";
    } elseif (count($handlers) > 0) {
        echo "  ⚠️  PARTIAL: Has handlers but may not fully delegate\n";
    } else {
        echo "  ❌ BAD: No handlers, needs extraction\n";
    }
    
    echo "\n";
}
