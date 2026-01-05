<?php
/**
 * Quick script to remove duplicate methods from UnifiedObjectMapper
 */

$file = '/var/www/html/custom_apps/openregister/lib/Db/UnifiedObjectMapper.php';
$content = file_get_contents($file);

// Find and remove the duplicate block (lines 775-951)
$lines = explode("\n", $content);
$cleanLines = [];

for ($i = 0; $i < count($lines); $i++) {
    // Skip lines 774-950 (0-indexed, so 774-950)
    if ($i >= 774 && $i <= 950) {
        continue;
    }
    $cleanLines[] = $lines[$i];
}

$cleanContent = implode("\n", $cleanLines);
file_put_contents($file, $cleanContent);

echo "✅ Removed duplicate methods (lines 775-951)\n";
echo "File size: " . strlen($content) . " → " . strlen($cleanContent) . " bytes\n";

