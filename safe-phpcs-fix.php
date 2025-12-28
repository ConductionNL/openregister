#!/usr/bin/env php
<?php
/**
 * Safe PHPCS fixer - only fixes truly safe issues
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
 * Fix inline comment capitalization  
 *
 * @param string $content Content
 *
 * @return string
 */
function fixInlineComments(string $content): string
{
    $lines = explode("\n", $content);

    foreach ($lines as $i => $line) {
        // Only fix simple single-line comments that start with lowercase
        if (preg_match('/^(\s*\/\/)(\s*)([a-z])(.*)$/', $line, $matches)) {
            $lines[$i] = $matches[1] . $matches[2] . strtoupper($matches[3]) . $matches[4];
        }
    }

    return implode("\n", $lines);
}

/**
 * Fix file comment blocks (/* to /**)
 *
 * @param string $content Content
 *
 * @return string
 */
function fixFileCommentBlocks(string $content): string
{
    // Ensure file comment starts with /** not /*
    $content = preg_replace(
        '/^(<\?php\s*\n)\/\*\s*\n/',
        '$1/**\n',
        $content
    );
    
    return $content;
}

// Get all files
$files = getPhpFiles($libDir);
echo "Processing " . count($files) . " files...\n";

$fixed = 0;
$stats = ['inline-comments' => 0, 'file-comments' => 0];

foreach ($files as $file) {
    $content = file_get_contents($file);
    $original = $content;
    $changed = false;

    // Fix inline comments
    $newContent = fixInlineComments($content);
    if ($newContent !== $content) {
        $content = $newContent;
        $stats['inline-comments']++;
        $changed = true;
    }

    // Fix file comment blocks
    $newContent = fixFileCommentBlocks($content);
    if ($newContent !== $content) {
        $content = $newContent;
        $stats['file-comments']++;
        $changed = true;
    }

    if ($changed) {
        file_put_contents($file, $content);
        $fixed++;
        if ($fixed % 50 === 0) {
            echo ".";
        }
    }
}

echo "\n\n✓ Fixed $fixed files\n";
echo "  - Inline comment capitalization: {$stats['inline-comments']} files\n";
echo "  - File comment blocks: {$stats['file-comments']} files\n";
echo "\nDone!\n";

