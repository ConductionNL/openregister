<?php

/**
 * Script to remove all @psalm-suppress annotations from PHP files.
 *
 * @category Script
 * @package  OCA\OpenRegister\Scripts
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

$baseDir = __DIR__ . '/../lib';
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($baseDir),
    RecursiveIteratorIterator::LEAVES_ONLY
);

$modifiedCount = 0;

foreach ($files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $filePath = $file->getRealPath();
    $content = file_get_contents($filePath);
    $originalContent = $content;

    // Remove single-line @psalm-suppress annotations.
    // Pattern: @psalm-suppress followed by optional text, possibly on its own line or in docblock.
    $content = preg_replace('/\s*\*\s*@psalm-suppress[^\n]*\n/', "\n", $content);
    $content = preg_replace('/\s*@psalm-suppress[^\n]*\n/', "\n", $content);

    // Remove multi-line psalm-suppress annotations.
    $content = preg_replace('/\s*\*\s*@psalm-suppress[^\n]*(?:\n\s*\*[^\n]*)*/', '', $content);

    // Clean up empty docblock lines.
    $content = preg_replace('/\s*\*\s*\n\s*\*\s*\n/', "\n", $content);
    $content = preg_replace('/\s*\*\s*\n\s*\*\s*\*\s*\n/', "\n", $content);

    if ($content !== $originalContent) {
        file_put_contents($filePath, $content);
        $modifiedCount++;
        echo "Modified: {$filePath}\n";
    }
}

echo "\nTotal files modified: {$modifiedCount}\n";

