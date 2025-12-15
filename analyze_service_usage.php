<?php
/**
 * Analyze which Service methods are ACTUALLY called from controllers
 */

// Get all controller files
$controllers = glob('lib/Controller/**/*.php') + glob('lib/Controller/*.php');

$serviceCalls = [];

foreach ($controllers as $controller) {
    $content = file_get_contents($controller);
    
    // Find service injections
    preg_match_all('/private.*?\$(\w+Service|\w+Handler)/', $content, $serviceMatches);
    
    // Find method calls on those services
    foreach ($serviceMatches[1] as $serviceName) {
        preg_match_all('/\$this->' . $serviceName . '->(\w+)\(/', $content, $methodMatches);
        
        foreach ($methodMatches[1] as $method) {
            $key = $serviceName . '::' . $method;
            if (!isset($serviceCalls[$key])) {
                $serviceCalls[$key] = [];
            }
            $serviceCalls[$key][] = basename($controller);
        }
    }
}

// Group by service
$byService = [];
foreach ($serviceCalls as $call => $controllers) {
    [$service, $method] = explode('::', $call);
    if (!isset($byService[$service])) {
        $byService[$service] = [];
    }
    $byService[$service][$method] = count($controllers);
}

// Sort by most methods called
uasort($byService, fn($a, $b) => count($b) <=> count($a));

echo "\n=== Services Called from Controllers ===\n\n";

foreach (array_slice($byService, 0, 10) as $service => $methods) {
    echo "📦 $service (" . count($methods) . " methods called):\n";
    
    arsort($methods);
    foreach (array_slice($methods, 0, 5) as $method => $count) {
        echo "  → $method() - called $count times\n";
    }
    if (count($methods) > 5) {
        echo "  ... and " . (count($methods) - 5) . " more methods\n";
    }
    echo "\n";
}
