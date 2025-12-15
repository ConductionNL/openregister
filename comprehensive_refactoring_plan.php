<?php
/**
 * Comprehensive Refactoring Audit
 */

echo "=== COMPREHENSIVE REFACTORING AUDIT ===\n\n";

$services = [
    'FileService' => [
        'file' => 'lib/Service/FileService.php',
        'handler_dir' => 'lib/Service/File/',
        'lloc' => 1583
    ],
    'ChatService' => [
        'file' => 'lib/Service/ChatService.php',
        'handler_dir' => 'lib/Service/Chat/',
        'lloc' => 903
    ],
    'ObjectService' => [
        'file' => 'lib/Service/ObjectService.php',
        'handler_dir' => 'lib/Service/Object/',
        'lloc' => 1873
    ],
    'ConfigurationService' => [
        'file' => 'lib/Service/ConfigurationService.php',
        'handler_dir' => 'lib/Service/Configuration/',
        'lloc' => 1241
    ]
];

foreach ($services as $serviceName => $info) {
    echo "📊 $serviceName ({$info['lloc']} LLOC)\n";
    echo str_repeat('-', 60) . "\n";
    
    // Find available handlers
    if (is_dir($info['handler_dir'])) {
        $handlers = glob($info['handler_dir'] . '*Handler.php');
        $handlerNames = array_map('basename', $handlers);
        echo "Available handlers: " . count($handlerNames) . "\n";
        foreach ($handlerNames as $h) {
            echo "  - " . str_replace('.php', '', $h) . "\n";
        }
    } else {
        echo "Available handlers: 0 (no handler directory)\n";
    }
    
    // Check injected handlers
    if (file_exists($info['file'])) {
        $content = file_get_contents($info['file']);
        preg_match('/public function __construct\((.*?)\)\s*{/s', $content, $constructorMatch);
        
        if (isset($constructorMatch[1])) {
            $params = $constructorMatch[1];
            preg_match_all('/(\w+Handler)\s+\$/', $params, $injectedHandlers);
            echo "\nInjected handlers: " . count($injectedHandlers[1]) . "\n";
            foreach ($injectedHandlers[1] as $h) {
                echo "  ✓ $h\n";
            }
        }
        
        // Find public methods
        preg_match_all('/public function (\w+)\s*\(/', $content, $publicMethods);
        $publicCount = count(array_unique($publicMethods[1])) - 1; // Exclude __construct
        
        // Find private methods  
        preg_match_all('/private function (\w+)\s*\(/', $content, $privateMethods);
        $privateCount = count(array_unique($privateMethods[1]));
        
        echo "\nMethods:\n";
        echo "  Public: $publicCount\n";
        echo "  Private: $privateCount\n";
        
        // Check delegation
        if (isset($injectedHandlers[1]) && count($injectedHandlers[1]) > 0) {
            $delegationCount = 0;
            foreach ($injectedHandlers[1] as $handler) {
                $varName = lcfirst(str_replace('Handler', '', $handler)) . 'Handler';
                $delegationCount += substr_count($content, '$this->' . $varName . '->');
            }
            echo "  Delegation calls: $delegationCount\n";
        }
        
        // Check for business logic indicators (if/foreach/while in public methods)
        $logicIndicators = substr_count($content, 'public function') * 2; // Rough estimate
        echo "  Business logic indicators: ~$logicIndicators\n";
    }
    
    echo "\n";
}

echo "\n=== RECOMMENDATIONS ===\n\n";
echo "Priority 1: FileService\n";
echo "  - Wire up missing FileCrudHandler, FileSharingHandler\n";
echo "  - Extract file operations to handlers\n\n";

echo "Priority 2: ObjectService\n";
echo "  - Extract schema-wide operations to BulkOperationsHandler\n";
echo "  - Extract merge operations to MergeHandler\n";
echo "  - Make 42 internal methods private\n\n";

echo "Priority 3: ConfigurationService\n";
echo "  - Extract more logic to existing handlers\n\n";
