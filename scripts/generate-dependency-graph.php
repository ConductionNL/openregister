#!/usr/bin/env php
<?php
/**
 * Generate Dependency Graph Script
 *
 * Creates a Mermaid dependency graph of all services and handlers.
 * This helps identify circular dependencies and understand the architecture.
 *
 * Usage: php scripts/generate-dependency-graph.php [--output=FILE] [--format=mermaid|json]
 *
 * @category Script
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0
 */

// Configuration.
$baseDir = dirname(__DIR__);
$libDir = $baseDir . '/lib';
$outputFile = $baseDir . '/website/docs/technical/dependency-graph.md';
$format = 'mermaid';

// Parse command line arguments.
$options = getopt('', ['output:', 'format:']);
if (isset($options['output'])) {
    $outputFile = $options['output'];
}
if (isset($options['format'])) {
    $format = $options['format'];
}

echo "🔍 Scanning dependencies in: $libDir\n";

/**
 * Extract dependencies from a PHP file
 *
 * @param string $file File path
 *
 * @return array|null Dependencies info
 */
function extractDependencies(string $file): ?array
{
    $content = file_get_contents($file);
    
    // Extract namespace and class name.
    preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatch);
    preg_match('/class\s+(\w+)/', $content, $classMatch);
    
    if (empty($classMatch)) {
        return null;
    }
    
    $namespace = $namespaceMatch[1] ?? '';
    $className = $classMatch[1];
    $fullClassName = $namespace . '\\' . $className;
    
    // Extract constructor parameters.
    $dependencies = [];
    if (preg_match('/__construct\s*\((.*?)\)/s', $content, $constructorMatch)) {
        $params = $constructorMatch[1];
        
        // Extract each parameter with type hint.
        preg_match_all('/(?:private|protected|public)?\s*(?:readonly)?\s*([^\s$]+)\s+\$\w+/i', $params, $paramMatches);
        
        foreach ($paramMatches[1] as $type) {
            // Skip primitive types and interfaces we don't care about.
            if (in_array($type, ['string', 'int', 'bool', 'array', 'float', '?string', '?int', '?bool'])) {
                continue;
            }
            
            // Resolve short name to full name if it's in our namespace.
            if (strpos($type, '\\') === false && strpos($type, 'OCA') !== 0) {
                // Check use statements.
                preg_match_all('/use\s+([^;]+);/', $content, $useMatches);
                foreach ($useMatches[1] as $useLine) {
                    if (str_ends_with($useLine, '\\' . $type) || str_ends_with($useLine, ' as ' . $type)) {
                        $type = trim(str_replace(' as ' . $type, '', $useLine));
                        break;
                    }
                }
            }
            
            // Only include OCA\OpenRegister dependencies.
            if (strpos($type, 'OCA\\OpenRegister') === 0) {
                $dependencies[] = $type;
            }
        }
    }
    
    return [
        'class' => $fullClassName,
        'shortName' => $className,
        'namespace' => $namespace,
        'dependencies' => array_unique($dependencies),
        'file' => str_replace($GLOBALS['libDir'] . '/', '', $file),
    ];
}

/**
 * Scan directory recursively for PHP files
 *
 * @param string $dir Directory to scan
 *
 * @return array List of PHP files
 */
function scanPhpFiles(string $dir): array
{
    $files = [];
    $items = scandir($dir);
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $path = $dir . '/' . $item;
        
        if (is_dir($path)) {
            $files = array_merge($files, scanPhpFiles($path));
        } elseif (pathinfo($path, PATHINFO_EXTENSION) === 'php') {
            $files[] = $path;
        }
    }
    
    return $files;
}

// Scan all PHP files.
$files = scanPhpFiles($libDir);
$allDependencies = [];

foreach ($files as $file) {
    $deps = extractDependencies($file);
    if ($deps !== null) {
        $allDependencies[$deps['class']] = $deps;
    }
}

echo "✅ Found " . count($allDependencies) . " classes\n";

// Detect circular dependencies.
function findCircularDependencies(array $allDeps): array
{
    $circular = [];
    
    function tracePath($class, $target, $allDeps, $path = [])
    {
        if (in_array($class, $path)) {
            return [$path]; // Found cycle.
        }
        
        if ($class === $target && count($path) > 0) {
            return [$path]; // Found path to target.
        }
        
        if (!isset($allDeps[$class])) {
            return [];
        }
        
        $newPath = array_merge($path, [$class]);
        $cycles = [];
        
        foreach ($allDeps[$class]['dependencies'] as $dep) {
            $result = tracePath($dep, $target, $allDeps, $newPath);
            $cycles = array_merge($cycles, $result);
        }
        
        return $cycles;
    }
    
    foreach ($allDeps as $class => $info) {
        $cycles = tracePath($class, $class, $allDeps);
        if (!empty($cycles)) {
            $circular[$class] = $cycles;
        }
    }
    
    return $circular;
}

$circularDeps = findCircularDependencies($allDependencies);

if (!empty($circularDeps)) {
    echo "\n🔴 CIRCULAR DEPENDENCIES DETECTED: " . count($circularDeps) . "\n";
    foreach ($circularDeps as $class => $cycles) {
        $shortClass = substr($class, strrpos($class, '\\') + 1);
        echo "  - $shortClass: " . count($cycles) . " cycle(s)\n";
    }
} else {
    echo "\n✅ No circular dependencies detected!\n";
}

// Generate output based on format.
if ($format === 'mermaid') {
    // Generate Mermaid diagram.
    $mermaid = "# Service Dependency Graph\n\n";
    $mermaid .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
    
    if (!empty($circularDeps)) {
        $mermaid .= "## ⚠️ Circular Dependencies Detected\n\n";
        foreach ($circularDeps as $class => $cycles) {
            $shortClass = substr($class, strrpos($class, '\\') + 1);
            $mermaid .= "- **$shortClass**: " . count($cycles) . " cycle(s)\n";
        }
        $mermaid .= "\n";
    }
    
    $mermaid .= "## Full Dependency Graph\n\n";
    $mermaid .= "```mermaid\ngraph TD\n";
    
    // Add nodes and edges.
    foreach ($allDependencies as $class => $info) {
        $shortName = $info['shortName'];
        $nodeId = str_replace('\\', '_', $class);
        
        // Determine node style based on type.
        $style = '';
        if (strpos($info['namespace'], 'Service') !== false && strpos($info['namespace'], 'Handler') === false) {
            $style = ':::serviceNode';
        } elseif (strpos($info['namespace'], 'Handler') !== false) {
            $style = ':::handlerNode';
        } elseif (strpos($info['namespace'], 'Controller') !== false) {
            $style = ':::controllerNode';
        }
        
        if (isset($info['dependencies']) && is_array($info['dependencies'])) {
            foreach ($info['dependencies'] as $dep) {
                $depShortName = substr($dep, strrpos($dep, '\\') + 1);
                $depNodeId = str_replace('\\', '_', $dep);
                
                // Check if this edge is part of a circular dependency.
                $isCircular = isset($circularDeps[$class]) || isset($circularDeps[$dep]);
                $edgeStyle = $isCircular ? '-.->|CIRCULAR|' : '-->';
                
                $mermaid .= "    $nodeId[$shortName] $edgeStyle $depNodeId[$depShortName]\n";
            }
        }
    }
    
    // Add styles.
    $mermaid .= "\n    classDef serviceNode fill:#f9f,stroke:#333,stroke-width:2px\n";
    $mermaid .= "    classDef handlerNode fill:#bbf,stroke:#333,stroke-width:2px\n";
    $mermaid .= "    classDef controllerNode fill:#bfb,stroke:#333,stroke-width:2px\n";
    $mermaid .= "```\n\n";
    
    // Add focused graph for Services only.
    $mermaid .= "## Services Only (Simplified)\n\n";
    $mermaid .= "```mermaid\ngraph LR\n";
    
    foreach ($allDependencies as $class => $info) {
        if (strpos($info['namespace'], '\\Service\\') === false || strpos($info['namespace'], 'Handler') !== false) {
            continue;
        }
        
        $shortName = $info['shortName'];
        $nodeId = str_replace('\\', '_', $class);
        
        if (isset($info['dependencies']) && is_array($info['dependencies'])) {
            foreach ($info['dependencies'] as $dep) {
                // Only show Service -> Service dependencies.
                if (strpos($dep, '\\Service\\') === false || strpos($dep, 'Handler') !== false) {
                    continue;
                }
                
                $depShortName = substr($dep, strrpos($dep, '\\') + 1);
                $depNodeId = str_replace('\\', '_', $dep);
                
                $mermaid .= "    $nodeId[$shortName] --> $depNodeId[$depShortName]\n";
            }
        }
    }
    
    $mermaid .= "```\n";
    
    file_put_contents($outputFile, $mermaid);
    echo "\n📝 Mermaid diagram written to: $outputFile\n";
    
} elseif ($format === 'json') {
    // Output as JSON.
    $output = [
        'generated' => date('Y-m-d H:i:s'),
        'total_classes' => count($allDependencies),
        'circular_dependencies' => count($circularDeps),
        'dependencies' => $allDependencies,
        'circular' => $circularDeps,
    ];
    
    file_put_contents($outputFile, json_encode($output, JSON_PRETTY_PRINT));
    echo "\n📝 JSON output written to: $outputFile\n";
}

echo "\n✨ Done!\n";

