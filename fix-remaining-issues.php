#!/usr/bin/env php
<?php
/**
 * Script to fix remaining PHPCS issues in OpenRegister
 *
 * This script automatically fixes:
 * - Inline IF statements (convert to full if-else)
 * - Missing @return tags for void methods
 * - Doc comment parameter name mismatches with & prefix
 *
 * @category Script
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://conduction.nl
 */

$libDir = __DIR__ . '/lib';

/**
 * Recursively get all PHP files in a directory
 *
 * @param string $dir Directory to search
 *
 * @return array Array of file paths
 */
function getPhpFiles(string $dir): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $dir,
            RecursiveDirectoryIterator::SKIP_DOTS
        )
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

/**
 * Fix inline IF statements to full if-else blocks
 *
 * @param string $content File content
 *
 * @return string Fixed content
 */
function fixInlineIfStatements(string $content): string
{
    $lines = explode("\n", $content);
    $result = [];
    
    foreach ($lines as $i => $line) {
        // Match simple inline if: $var = condition ? true : false;
        if (preg_match('/^(\s*)(\$\w+)\s*=\s*(.+)\s*\?\s*(.+)\s*:\s*(.+);$/', $line, $matches)) {
            $indent = $matches[1];
            $var = $matches[2];
            $condition = trim($matches[3]);
            $trueValue = trim($matches[4]);
            $falseValue = trim($matches[5]);
            
            // Convert to full if-else
            $result[] = $indent . 'if (' . $condition . ') {';
            $result[] = $indent . '    ' . $var . ' = ' . $trueValue . ';';
            $result[] = $indent . '} else {';
            $result[] = $indent . '    ' . $var . ' = ' . $falseValue . ';';
            $result[] = $indent . '}';
            continue;
        }
        
        $result[] = $line;
    }
    
    return implode("\n", $result);
}

/**
 * Fix doc comment parameter names with & prefix
 *
 * @param string $content File content
 *
 * @return string Fixed content
 */
function fixDocCommentParameters(string $content): string
{
    // Fix &$paramName to $paramName in doc comments
    $content = preg_replace(
        '/@param\s+([^\s]+)\s+&\$(\w+)/',
        '@param $1 &$2',
        $content
    );
    
    return $content;
}

/**
 * Add missing @return void tags for void methods
 *
 * @param string $content File content
 *
 * @return string Fixed content
 */
function addMissingReturnVoid(string $content): string
{
    $lines = explode("\n", $content);
    $result = [];
    $inDocComment = false;
    $docCommentLines = [];
    
    for ($i = 0; $i < count($lines); $i++) {
        $line = $lines[$i];
        
        // Check if starting a doc comment
        if (preg_match('/^\s*\/\*\*/', $line)) {
            $inDocComment = true;
            $docCommentLines = [$i];
            $result[] = $line;
            continue;
        }
        
        // Check if ending a doc comment
        if ($inDocComment && preg_match('/^\s*\*\//', $line)) {
            $inDocComment = false;
            
            // Check if next line is a method declaration with ': void'
            if ($i + 1 < count($lines)) {
                $nextLine = $lines[$i + 1];
                if (preg_match('/public\s+function\s+\w+\([^)]*\):\s*void/', $nextLine)) {
                    // Check if @return is missing in the doc comment
                    $hasReturn = false;
                    for ($j = $docCommentLines[0]; $j <= $i; $j++) {
                        if (preg_match('/@return/', $result[$j])) {
                            $hasReturn = true;
                            break;
                        }
                    }
                    
                    // Add @return void before closing
                    if (!$hasReturn) {
                        $indent = preg_replace('/^(\s*).*/', '$1', $line);
                        $result[] = $indent . ' * @return void';
                        $result[] = $indent . ' *';
                    }
                }
            }
            
            $result[] = $line;
            continue;
        }
        
        if ($inDocComment) {
            $docCommentLines[] = $i;
        }
        
        $result[] = $line;
    }
    
    return implode("\n", $result);
}

// Get all PHP files
$files = getPhpFiles($libDir);

echo "Found " . count($files) . " PHP files\n";

$fixed = 0;

foreach ($files as $file) {
    $content = file_get_contents($file);
    $original = $content;

    // Apply fixes
    $content = fixInlineIfStatements($content);
    $content = fixDocCommentParameters($content);
    $content = addMissingReturnVoid($content);

    if ($content !== $original) {
        file_put_contents($file, $content);
        $fixed++;
        echo "Fixed: " . basename($file) . "\n";
    }
}

echo "\nFixed $fixed files\n";

