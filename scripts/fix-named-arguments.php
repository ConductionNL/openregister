<?php
/**
 * Script to automatically fix positional arguments after named arguments.
 * 
 * This script fixes common patterns:
 * 1. JSONResponse(data: [...], 200) -> JSONResponse(data: [...], statusCode: 200)
 * 2. Logger->info(message: '...', [...]) -> Logger->info(message: '...', context: [...])
 * 3. Other common function call patterns
 * 
 * Usage: php scripts/fix-named-arguments.php [--dry-run] [--path=lib/]
 * 
 * @author OpenRegister Team
 * @package OpenRegister
 */

declare(strict_types=1);

/**
 * Fix a single PHP file for positional arguments after named arguments.
 *
 * @param string $filePath Path to the PHP file to fix.
 * @param bool   $dryRun   If true, only report what would be changed.
 *
 * @return array Array with 'fixed' count and 'errors' array.
 */
function fixFile(string $filePath, bool $dryRun = false): array
{
    $fixed = 0;
    $errors = [];
    
    if (!file_exists($filePath)) {
        return ['fixed' => 0, 'errors' => ['File not found']];
    }
    
    $content = file_get_contents($filePath);
    if ($content === false) {
        return ['fixed' => 0, 'errors' => ['Could not read file']];
    }
    
    $originalContent = $content;
    $replacements = [];
    
    // Use tokenizer for more accurate detection
    $tokens = token_get_all($content);
    $inJsonResponse = false;
    $jsonResponseStart = null;
    $parenDepth = 0;
    $hasNamedParam = false;
    $lastCommaPos = null;
    $dataEndPos = null;
    
    // Pattern 1: JSONResponse with data: followed by positional status code
    // Use balanced bracket matching to handle nested arrays
    $content = preg_replace_callback(
        '/new\s+JSONResponse\s*\(\s*data:\s*(\[[^\]]*(?:\[[^\]]*(?:\[[^\]]*(?:\[[^\]]*\][^\]]*)*\][^\]]*)*\][^\]]*)*)\],\s*\n?\s*(\d{3})\s*\n?\s*\)/s',
        function($matches) use (&$fixed) {
            $fixed++;
            $dataArray = $matches[1];
            $statusCode = $matches[2];
            // Determine indentation from the match
            $lines = explode("\n", $matches[0]);
            $indent = '';
            if (count($lines) > 1) {
                // Extract indentation from first line after "new JSONResponse("
                preg_match('/^(\s*)/', $lines[0] ?? '', $indentMatch);
                $indent = $indentMatch[1] ?? '                        ';
            } else {
                $indent = '                        ';
            }
            return "new JSONResponse(\n" . $indent . "data: " . $dataArray . ",\n" . $indent . "statusCode: " . $statusCode . "\n" . $indent . ")";
        },
        $content
    );
    
    // Pattern 1b: Simpler pattern for single-line cases
    $content = preg_replace_callback(
        '/new\s+JSONResponse\s*\(\s*data:\s*(\[[^\]]*)\],\s*(\d{3})\s*\)/s',
        function($matches) use (&$fixed) {
            $fixed++;
            return 'new JSONResponse(data: ' . $matches[1] . '], statusCode: ' . $matches[2] . ')';
        },
        $content
    );
    
    // Pattern 1c: More flexible multi-line pattern - match after data: [...] with any content
    // Match: data: [anything], \n whitespace number \n whitespace )
    $content = preg_replace_callback(
        '/(new\s+JSONResponse\s*\(\s*data:\s*\[.*?\],)\s*\n\s*(\d{3})\s*\n\s*\)/s',
        function($matches) use (&$fixed) {
            $fixed++;
            // Extract existing indentation
            $before = $matches[1];
            $statusCode = $matches[2];
            // Try to preserve indentation style
            $indent = '                        ';
            if (preg_match('/\n(\s+)/', $before, $indentMatch)) {
                $indent = $indentMatch[1];
            }
            return $before . ",\n" . $indent . "statusCode: " . $statusCode . "\n" . $indent . ")";
        },
        $content
    );
    
    // Pattern 2: Logger methods with message: followed by positional context array
    $loggerMethods = ['info', 'error', 'warning', 'debug', 'critical', 'alert', 'emergency', 'notice'];
    foreach ($loggerMethods as $method) {
        // Multi-line pattern: message: '...', \n [...] \n )
        $content = preg_replace_callback(
            '/->' . preg_quote($method, '/') . '\s*\(\s*message:\s*([^,]+),\s*\n\s*(\[[^\]]*\])/s',
            function ($matches) use ($method, &$fixed) {
                $fixed++;
                return '->' . $method . "(message: " . trim($matches[1]) . ",\n                        context: " . $matches[2];
            },
            $content
        );
        
        // Single-line pattern: message: '...', [...]
        $content = preg_replace_callback(
            '/->' . preg_quote($method, '/') . '\s*\(\s*message:\s*([^,]+),\s*(\[[^\]]*\])/s',
            function ($matches) use ($method, &$fixed) {
                $fixed++;
                return '->' . $method . "(message: " . trim($matches[1]) . ", context: " . $matches[2];
            },
            $content
        );
        
        // Pattern with string literal followed by array (old style logger calls)
        $content = preg_replace_callback(
            '/->' . preg_quote($method, '/') . '\s*\(\s*message:\s*([^,]+),\s*\n\s*(\[[^\]]*\])/s',
            function ($matches) use ($method, &$fixed) {
                $fixed++;
                return '->' . $method . "(message: " . trim($matches[1]) . ",\n                        context: " . $matches[2];
            },
            $content
        );
    }
    
    // Pattern 3: in_array with named parameters followed by strict flag
    $content = preg_replace_callback(
        '/in_array\s*\(\s*needle:\s*([^,]+),\s*haystack:\s*([^,]+),\s*(\d+|true|false)\s*\)/s',
        function ($matches) use (&$fixed) {
            $fixed++;
            return 'in_array(needle: ' . trim($matches[1]) . ', haystack: ' . trim($matches[2]) . ', strict: ' . trim($matches[3]) . ')';
        },
        $content
    );
    
    // Pattern 4: array_search with named parameters followed by strict flag
    $content = preg_replace_callback(
        '/array_search\s*\(\s*needle:\s*([^,]+),\s*haystack:\s*([^,]+),\s*(\d+|true|false)\s*\)/s',
        function ($matches) use (&$fixed) {
            $fixed++;
            return 'array_search(needle: ' . trim($matches[1]) . ', haystack: ' . trim($matches[2]) . ', strict: ' . trim($matches[3]) . ')';
        },
        $content
    );
    
    // Pattern 5: More complex JSONResponse - handle cases with nested arrays and proper formatting
    // This handles the case where data: array spans multiple lines
    $content = preg_replace_callback(
        '/(new\s+JSONResponse\s*\(\s*data:\s*\[[^\]]*\],)\s*\n\s*(\d{3})\s*\n\s*\)/s',
        function ($matches) use (&$fixed) {
            $fixed++;
            // Preserve the data part and add statusCode
            return $matches[1] . ",\n                        statusCode: " . $matches[2] . "\n                        )";
        },
        $content
    );
    
    // Only write if content changed and not dry run
    if ($content !== $originalContent && !$dryRun) {
        if (file_put_contents($filePath, $content) === false) {
            $errors[] = 'Failed to write file';
        }
    }
    
    return ['fixed' => $fixed, 'errors' => $errors];
}

/**
 * Recursively find all PHP files in a directory.
 *
 * @param string $directory Directory to search.
 * @param array  $exclude   Patterns to exclude.
 *
 * @return array Array of file paths.
 */
function findPhpFiles(string $directory, array $exclude = []): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $path = $file->getRealPath();
            $relativePath = str_replace(getcwd() . DIRECTORY_SEPARATOR, '', $path);
            
            // Check exclude patterns.
            $shouldExclude = false;
            foreach ($exclude as $pattern) {
                if (strpos($relativePath, $pattern) !== false) {
                    $shouldExclude = true;
                    break;
                }
            }
            
            if (!$shouldExclude) {
                $files[] = $path;
            }
        }
    }
    
    return $files;
}

// Main execution.
$path = 'lib';
$dryRun = false;
$exitCode = 0;

// Parse command line arguments.
$options = getopt('', ['path:', 'dry-run', 'help']);
if (isset($options['help'])) {
    echo "Usage: php scripts/fix-named-arguments.php [--dry-run] [--path=lib/]\n";
    echo "\n";
    echo "Options:\n";
    echo "  --path=DIR    Directory to fix (default: lib/)\n";
    echo "  --dry-run     Show what would be fixed without making changes\n";
    echo "  --help        Show this help message\n";
    echo "\n";
    echo "This script fixes common patterns:\n";
    echo "  1. JSONResponse(data: [...], 200) -> JSONResponse(data: [...], statusCode: 200)\n";
    echo "  2. Logger->info(message: '...', [...]) -> Logger->info(message: '...', context: [...])\n";
    echo "  3. in_array(needle: ..., haystack: ..., true) -> in_array(needle: ..., haystack: ..., strict: true)\n";
    exit(0);
}

if (isset($options['path'])) {
    $path = $options['path'];
}

if (isset($options['dry-run'])) {
    $dryRun = true;
}

if (!is_dir($path)) {
    echo "Error: Directory '$path' does not exist.\n";
    exit(1);
}

echo "Fixing positional arguments after named arguments in: $path\n";
if ($dryRun) {
    echo "DRY RUN MODE - No files will be modified\n";
}
echo str_repeat('=', 70) . "\n";

$excludePatterns = ['vendor', 'node_modules', 'build'];
$files = findPhpFiles($path, $excludePatterns);
$totalFixed = 0;
$filesModified = 0;

foreach ($files as $file) {
    $result = fixFile($file, $dryRun);
    if ($result['fixed'] > 0) {
        $relativePath = str_replace(getcwd() . DIRECTORY_SEPARATOR, '', $file);
        echo sprintf(
            "%s: Fixed %d pattern(s)\n",
            $relativePath,
            $result['fixed']
        );
        $totalFixed += $result['fixed'];
        $filesModified++;
    }
    if (!empty($result['errors'])) {
        foreach ($result['errors'] as $error) {
            echo "Error in $file: $error\n";
            $exitCode = 1;
        }
    }
}

echo "\n" . str_repeat('=', 70) . "\n";
if ($totalFixed > 0) {
    echo sprintf(
        "✓ Fixed %d pattern(s) in %d file(s).\n",
        $totalFixed,
        $filesModified
    );
    if ($dryRun) {
        echo "Run without --dry-run to apply these changes.\n";
    }
} else {
    echo "No patterns found to fix.\n";
}

exit($exitCode);
