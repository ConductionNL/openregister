<?php
/**
 * Analyze which public methods in god classes are actually used externally
 */

$godClasses = [
    'lib/Service/ObjectService.php',
    'lib/Service/FileService.php',
    'lib/Service/ConfigurationService.php',
    'lib/Service/Object/SaveObject.php',
    'lib/Service/Object/SaveObjects.php',
    'lib/Service/ChatService.php',
    'lib/Service/SchemaService.php',
    'lib/Service/ImportService.php'
];

foreach ($godClasses as $file) {
    if (!file_exists($file)) continue;
    
    echo "\n=== " . basename($file) . " ===\n";
    
    // Extract public methods
    $content = file_get_contents($file);
    preg_match_all('/public function (\w+)\s*\(/', $content, $matches);
    
    $publicMethods = array_unique($matches[1]);
    echo "Public methods: " . count($publicMethods) . "\n";
    
    // Check which are called from controllers
    $calledFromControllers = 0;
    $onlyInternal = [];
    
    foreach ($publicMethods as $method) {
        // Search in controllers
        $cmd = "grep -r '->$method\(' lib/Controller/ 2>/dev/null | wc -l";
        $controllerCalls = (int)shell_exec($cmd);
        
        if ($controllerCalls > 0) {
            $calledFromControllers++;
        } else {
            $onlyInternal[] = $method;
        }
    }
    
    echo "Called from controllers: $calledFromControllers\n";
    echo "Potentially private (not called from controllers): " . count($onlyInternal) . "\n";
    
    if (count($onlyInternal) > 0 && count($onlyInternal) <= 10) {
        echo "  Methods: " . implode(', ', array_slice($onlyInternal, 0, 10)) . "\n";
    }
}
