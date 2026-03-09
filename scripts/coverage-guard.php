#!/usr/bin/env php
<?php
/**
 * Coverage guard — prevents test coverage from dropping.
 *
 * Usage: php scripts/coverage-guard.php <clover.xml> [--update-baseline]
 *
 * Exit codes:
 *   0 — coverage is equal to or higher than baseline
 *   1 — coverage dropped (PR should be blocked)
 *   2 — missing files or invalid input
 */

$baselineFile = __DIR__ . '/../.coverage-baseline';
$cloverFile = $argv[1] ?? 'coverage/clover.xml';
$updateBaseline = in_array('--update-baseline', $argv, true);

if (!file_exists($cloverFile)) {
    fwrite(STDERR, "Error: Clover file not found: $cloverFile\n");
    exit(2);
}

if (!file_exists($baselineFile)) {
    fwrite(STDERR, "Error: Baseline file not found: $baselineFile\n");
    exit(2);
}

$xml = simplexml_load_file($cloverFile);
if ($xml === false) {
    fwrite(STDERR, "Error: Could not parse $cloverFile\n");
    exit(2);
}

$metrics = $xml->project->metrics;
$statements = (int)$metrics['statements'];
$covered = (int)$metrics['coveredstatements'];
$current = $statements > 0 ? round(($covered / $statements) * 100, 2) : 0.0;

$baseline = (float)trim(file_get_contents($baselineFile));

echo "Coverage baseline: {$baseline}%\n";
echo "Coverage current:  {$current}%\n";

if ($current < $baseline) {
    echo "FAIL: Coverage dropped by " . round($baseline - $current, 2) . "%\n";
    exit(1);
}

if ($current > $baseline) {
    echo "Coverage improved by " . round($current - $baseline, 2) . "%\n";
    if ($updateBaseline) {
        file_put_contents($baselineFile, number_format($current, 2) . "\n");
        echo "Baseline updated to {$current}%\n";
    }
} else {
    echo "Coverage unchanged.\n";
}

exit(0);
