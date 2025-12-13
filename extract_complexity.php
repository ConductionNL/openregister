<?php
$html = file_get_contents('phpmetrics/violations.html');

// Find all "Too complex method code" sections
preg_match_all('/<span class="path">(.*?)<\/span>.*?Max cyclomatic complexity of class methods is (\d+)/s', $html, $matches, PREG_SET_ORDER);

$complexity_map = [];
foreach ($matches as $match) {
    $class = $match[1];
    $complexity = (int)$match[2];
    
    if ($complexity >= 11 && $complexity <= 15) {
        $complexity_map[$complexity][] = $class;
    }
}

ksort($complexity_map);

foreach ($complexity_map as $complexity => $classes) {
    echo "\n=== Complexity: $complexity (" . count($classes) . " classes) ===\n";
    foreach ($classes as $class) {
        echo "  - $class\n";
    }
}
