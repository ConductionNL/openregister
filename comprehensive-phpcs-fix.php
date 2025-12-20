#!/usr/bin/env php
<?php
/**
 * Comprehensive PHPCS fixer for OpenRegister
 *
 * @category Script
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://conduction.nl
 */

$libDir = __DIR__ . '/lib';

/**
 * Get all PHP files
 *
 * @param string $dir Directory
 *
 * @return array
 */
function getPhpFiles(string $dir): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

/**
 * Fix inline comments capitalization
 *
 * @param string $content Content
 *
 * @return string
 */
function fixInlineCommentCapitalization(string $content): string
{
    $lines = explode("\n", $content);

    foreach ($lines as $i => $line) {
        if (preg_match('/^(\s*\/\/)(\s*)([a-z])(.*)$/', $line, $matches)) {
            $lines[$i] = $matches[1] . $matches[2] . strtoupper($matches[3]) . $matches[4];
        }
    }

    return implode("\n", $lines);
}

/**
 * Fix inline IF to full if-else
 *
 * @param string $content Content
 *
 * @return string
 */
function fixInlineIfStatements(string $content): string
{
    $lines = explode("\n", $content);
    $result = [];
    
    foreach ($lines as $line) {
        // Simple ternary assignment
        if (preg_match('/^(\s*)(\$\w+)\s*=\s*(.+)\s*\?\s*(.+)\s*:\s*(.+);$/', $line, $matches)) {
            $indent = $matches[1];
            $var = $matches[2];
            $condition = trim($matches[3]);
            $trueVal = trim($matches[4]);
            $falseVal = trim($matches[5]);
            
            $result[] = $indent . 'if (' . $condition . ') {';
            $result[] = $indent . '    ' . $var . ' = ' . $trueVal . ';';
            $result[] = $indent . '} else {';
            $result[] = $indent . '    ' . $var . ' = ' . $falseVal . ';';
            $result[] = $indent . '}';
            continue;
        }
        
        // Return ternary
        if (preg_match('/^(\s*)return\s+(.+)\s*\?\s*(.+)\s*:\s*(.+);$/', $line, $matches)) {
            $indent = $matches[1];
            $condition = trim($matches[2]);
            $trueVal = trim($matches[3]);
            $falseVal = trim($matches[4]);
            
            $result[] = $indent . 'if (' . $condition . ') {';
            $result[] = $indent . '    return ' . $trueVal . ';';
            $result[] = $indent . '} else {';
            $result[] = $indent . '    return ' . $falseVal . ';';
            $result[] = $indent . '}';
            continue;
        }
        
        $result[] = $line;
    }
    
    return implode("\n", $result);
}

/**
 * Fix file comment blocks
 *
 * @param string $content Content
 *
 * @return string
 */
function fixFileCommentBlocks(string $content): string
{
    // Ensure file starts with /** not /*
    $content = preg_replace(
        '/^<\?php\s*\n\/\*\s*\n/',
        "<?php\n/**\n",
        $content
    );
    
    return $content;
}

/**
 * Fix empty lines around block comments
 *
 * @param string $content Content
 *
 * @return string
 */
function fixEmptyLinesAroundComments(string $content): string
{
    $lines = explode("\n", $content);
    $result = [];
    
    for ($i = 0; $i < count($lines); $i++) {
        $line = $lines[$i];
        $trimmed = trim($line);
        
        // Before block comment
        if (preg_match('/^\s*\/\*\*?/', $line)) {
            if (count($result) > 0 
                && trim($result[count($result) - 1]) !== ''
                && !preg_match('/^\s*\{/', $result[count($result) - 1])
                && !preg_match('/^\s*\/\//', $result[count($result) - 1])
            ) {
                $result[] = '';
            }
        }
        
        $result[] = $line;
    }
    
    return implode("\n", $result);
}

/**
 * Fix extremely long lines by breaking arrays
 *
 * @param string $content Content
 *
 * @return string
 */
function fixExtremelyLongLines(string $content): string
{
    $lines = explode("\n", $content);
    $result = [];
    
    foreach ($lines as $line) {
        // If line is over 200 chars and contains array syntax
        if (strlen($line) > 200 && (strpos($line, '[') !== false || strpos($line, 'array(') !== false)) {
            // Try to break at commas
            if (preg_match('/^(\s*)(.+?)(\[.+\]);$/', $line, $matches)) {
                $indent = $matches[1];
                // This is complex - let phpcbf handle it
                $result[] = $line;
            } else {
                $result[] = $line;
            }
        } else {
            $result[] = $line;
        }
    }
    
    return implode("\n", $result);
}

// Get all files
$files = getPhpFiles($libDir);
echo "Processing " . count($files) . " files...\n";

$fixed = 0;
$fixTypes = [];

foreach ($files as $file) {
    $content = file_get_contents($file);
    $original = $content;
    $fileFixed = [];

    // Apply all fixes
    $newContent = fixInlineCommentCapitalization($content);
    if ($newContent !== $content) {
        $fileFixed[] = 'inline-comment-caps';
        $content = $newContent;
    }

    $newContent = fixInlineIfStatements($content);
    if ($newContent !== $content) {
        $fileFixed[] = 'inline-if';
        $content = $newContent;
    }

    $newContent = fixFileCommentBlocks($content);
    if ($newContent !== $content) {
        $fileFixed[] = 'file-comment';
        $content = $newContent;
    }

    $newContent = fixEmptyLinesAroundComments($content);
    if ($newContent !== $content) {
        $fileFixed[] = 'empty-lines';
        $content = $newContent;
    }

    if ($content !== $original) {
        file_put_contents($file, $content);
        $fixed++;
        foreach ($fileFixed as $type) {
            $fixTypes[$type] = ($fixTypes[$type] ?? 0) + 1;
        }
        echo ".";
        if ($fixed % 50 === 0) {
            echo " $fixed\n";
        }
    }
}

echo "\n\n";
echo "✓ Fixed $fixed files\n";
echo "\nFix breakdown:\n";
foreach ($fixTypes as $type => $count) {
    echo "  - $type: $count files\n";
}
echo "\nDone!\n";

