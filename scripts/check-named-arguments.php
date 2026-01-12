<?php
/**
 * Script to check for positional arguments after named arguments.
 * 
 * This is a PHP 8+ fatal error: "Cannot use positional argument after named argument"
 * 
 * Usage: php scripts/check-named-arguments.php [--path=lib/]
 * 
 * @author OpenRegister Team
 * @package OpenRegister
 */

declare(strict_types=1);

/**
 * Check a single PHP file for positional arguments after named arguments.
 *
 * @param string $filePath Path to the PHP file to check.
 *
 * @return array Array of errors found, empty if none.
 */
function checkFile(string $filePath): array
{
    $errors = [];
    $content = file_get_contents($filePath);
    
    if ($content === false) {
        return [['line' => 0, 'message' => 'Could not read file']];
    }
    
    $tokens = token_get_all($content);
    $inFunctionCall = false;
    $functionCallStart = null;
    $parenDepth = 0;
    $hasNamedParam = false;
    $namedParamLine = null;
    $currentLine = 1;
    $lastCommaPos = null;
    $lastCommaLine = null;
    
    for ($i = 0; $i < count($tokens); $i++) {
        $token = $tokens[$i];
        
        // Track line numbers.
        if (is_array($token)) {
            $currentLine = $token[2];
        }
        
        // Check for function calls: T_STRING followed by T_OPEN_PARENTHESIS.
        if (is_array($token) && $token[0] === T_STRING) {
            // Look ahead for opening parenthesis.
            $nextNonWhitespace = $i + 1;
            while ($nextNonWhitespace < count($tokens) && 
                   is_array($tokens[$nextNonWhitespace]) && 
                   $tokens[$nextNonWhitespace][0] === T_WHITESPACE) {
                $nextNonWhitespace++;
            }
            
            if ($nextNonWhitespace < count($tokens) && 
                is_string($tokens[$nextNonWhitespace]) && 
                $tokens[$nextNonWhitespace] === '(') {
                // Found a function call.
                $inFunctionCall = true;
                $functionCallStart = $i;
                $parenDepth = 1;
                $hasNamedParam = false;
                $namedParamLine = null;
                $lastCommaPos = null;
                $lastCommaLine = null;
                $i = $nextNonWhitespace; // Skip to the opening parenthesis.
                continue;
            }
        }
        
        // If we're in a function call, track parentheses depth and parameters.
        if ($inFunctionCall) {
            if (is_string($token)) {
                if ($token === '(') {
                    $parenDepth++;
                } elseif ($token === ')') {
                    $parenDepth--;
                    if ($parenDepth === 0) {
                        // End of function call.
                        $inFunctionCall = false;
                        $hasNamedParam = false;
                        $namedParamLine = null;
                        $lastCommaPos = null;
                        $lastCommaLine = null;
                    }
                } elseif ($token === ',') {
                    // Found a comma - check if we're at the top level of function call.
                    if ($parenDepth === 1) {
                        $lastCommaPos = $i;
                        $lastCommaLine = $currentLine;
                    }
                }
            } elseif (is_array($token)) {
                // Check for named parameter syntax: T_STRING followed by T_COLON.
                if ($token[0] === T_STRING && $parenDepth === 1) {
                    $nextTokenIdx = $i + 1;
                    // Skip whitespace.
                    while ($nextTokenIdx < count($tokens) && 
                           is_array($tokens[$nextTokenIdx]) && 
                           $tokens[$nextTokenIdx][0] === T_WHITESPACE) {
                        $nextTokenIdx++;
                    }
                    
                    // Check if next token is colon (named parameter).
                    if ($nextTokenIdx < count($tokens) && 
                        is_string($tokens[$nextTokenIdx]) && 
                        $tokens[$nextTokenIdx] === ':') {
                        // Found a named parameter.
                        $hasNamedParam = true;
                        $namedParamLine = $currentLine;
                    } elseif ($hasNamedParam && $lastCommaPos !== null) {
                        // We have a named parameter, and we're past a comma.
                        // Check if this is a positional argument (not another named param).
                        // A positional argument would be: value, variable, constant, etc. (not T_STRING followed by :).
                        $isPositional = false;
                        
                        // Check if this token could be a positional argument.
                        if (!in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                            // Check if it's NOT a named parameter (not T_STRING followed by :).
                            if ($token[0] !== T_STRING) {
                                $isPositional = true;
                            } else {
                                // It's T_STRING, check if followed by colon.
                                $checkNext = $i + 1;
                                while ($checkNext < count($tokens) && 
                                       is_array($tokens[$checkNext]) && 
                                       $tokens[$checkNext][0] === T_WHITESPACE) {
                                    $checkNext++;
                                }
                                if ($checkNext >= count($tokens) || 
                                    !is_string($tokens[$checkNext]) || 
                                    $tokens[$checkNext] !== ':') {
                                    $isPositional = true;
                                }
                            }
                            
                            if ($isPositional && $lastCommaPos < $i) {
                                // Found positional argument after named argument!
                                $errors[] = [
                                    'line' => $currentLine,
                                    'message' => sprintf(
                                        'Positional argument after named argument (PHP 8+ fatal error). ' .
                                        'First named parameter found at line %d, positional argument at line %d. ' .
                                        'All arguments after the first named argument must also be named.',
                                        $namedParamLine,
                                        $currentLine
                                    )
                                ];
                                // Reset to avoid duplicate errors for same function call.
                                $hasNamedParam = false;
                            }
                        }
                    }
                }
            }
        }
    }
    
    return $errors;
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
$exitCode = 0;

// Parse command line arguments.
$options = getopt('', ['path:', 'help']);
if (isset($options['help'])) {
    echo "Usage: php scripts/check-named-arguments.php [--path=lib/]\n";
    echo "\n";
    echo "Options:\n";
    echo "  --path=DIR    Directory to check (default: lib/)\n";
    echo "  --help        Show this help message\n";
    exit(0);
}

if (isset($options['path'])) {
    $path = $options['path'];
}

if (!is_dir($path)) {
    echo "Error: Directory '$path' does not exist.\n";
    exit(1);
}

echo "Checking for positional arguments after named arguments in: $path\n";
echo str_repeat('=', 70) . "\n";

$excludePatterns = ['vendor', 'node_modules', 'build', 'tests'];
$files = findPhpFiles($path, $excludePatterns);
$totalErrors = 0;

foreach ($files as $file) {
    $errors = checkFile($file);
    if (!empty($errors)) {
        $relativePath = str_replace(getcwd() . DIRECTORY_SEPARATOR, '', $file);
        echo "\n" . $relativePath . ":\n";
        foreach ($errors as $error) {
            echo "  Line {$error['line']}: {$error['message']}\n";
            $totalErrors++;
        }
        $exitCode = 1;
    }
}

if ($totalErrors === 0) {
    echo "\n✓ No errors found. All named arguments are used correctly.\n";
} else {
    echo "\n✗ Found $totalErrors error(s).\n";
}

exit($exitCode);


