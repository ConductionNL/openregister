<?php
$file = 'lib/Service/ObjectService.php';
$content = file_get_contents($file);

// Replace tabs with spaces (4 spaces per tab)
$content = str_replace("\t", "    ", $content);

// Save the modified content back to the file
file_put_contents($file, $content);

echo "Tabs replaced with spaces in $file\n"; 