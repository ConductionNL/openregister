<?php
$file = file_get_contents('lib/Service/ObjectService.php');
preg_match_all('/^\s*(public|private)\s+function\s+(\w+)\s*\(/m', $file, $matches, PREG_OFFSET_CAPTURE);

$methods = [];
for ($i = 0; $i < count($matches[0]); $i++) {
    $methodName = $matches[2][$i][0];
    $startPos = $matches[0][$i][1];
    $startLine = substr_count($file, "\n", 0, $startPos) + 1;
    
    // Find end of method (next method or end of class)
    if ($i < count($matches[0]) - 1) {
        $endPos = $matches[0][$i + 1][1];
    } else {
        $endPos = strrpos($file, '}//end class');
    }
    
    $endLine = substr_count($file, "\n", 0, $endPos);
    $lineCount = $endLine - $startLine;
    
    $methods[] = [
        'name' => $methodName,
        'lines' => $lineCount,
        'start' => $startLine
    ];
}

usort($methods, fn($a, $b) => $b['lines'] <=> $a['lines']);

echo "=== TOP 30 LARGEST METHODS IN ObjectService ===\n\n";
foreach (array_slice($methods, 0, 30) as $i => $method) {
    printf("%2d. %-50s %4d lines (starts line %d)\n", 
        $i + 1, 
        $method['name'] . '()', 
        $method['lines'],
        $method['start']
    );
}
