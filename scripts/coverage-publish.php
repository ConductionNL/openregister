#!/usr/bin/env php
<?php
/**
 * Publishes the measured test coverage of this app.
 *
 * Reads the reports the test suites write and turns them into two things:
 *   - coverage-summary.json at the repository root, in the Istanbul
 *     json-summary shape ("total.lines.pct"), with the PHP and JavaScript
 *     suites listed separately;
 *   - the block between the coverage markers in README.md.
 *
 * It measures nothing itself. Run it through `composer coverage:publish`,
 * which first runs PHPUnit and Jest with coverage enabled.
 *
 * Usage:
 *   php scripts/coverage-publish.php [--clover=coverage/clover.xml]
 *       [--jest=coverage-frontend/coverage-summary.json] [--readme=README.md]
 *       [--out=coverage-summary.json]
 *
 * Exit codes: 0 published, 2 missing or unreadable input.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

$root = dirname(__DIR__);
$opts = getopt('', ['clover::', 'jest::', 'readme::', 'out::']);
$clover = $root . '/' . ($opts['clover'] ?? 'coverage/clover.xml');
$jest = $root . '/' . ($opts['jest'] ?? 'coverage-frontend/coverage-summary.json');
$readme = $root . '/' . ($opts['readme'] ?? 'README.md');
$out = $root . '/' . ($opts['out'] ?? 'coverage-summary.json');

/**
 * Builds one Istanbul metric entry.
 *
 * @param int $total   Number of measured items.
 * @param int $covered Number of those that ran.
 *
 * @return array{total: int, covered: int, skipped: int, pct: float|int}
 */
function metric(int $total, int $covered): array
{
    $pct = 0;
    if ($total > 0) {
        $pct = round(($covered / $total) * 100, 2);
    }

    return [
        'total' => $total,
        'covered' => $covered,
        'skipped' => 0,
        'pct' => $pct,
    ];
}

$suites = [];

if (is_file($clover) === false) {
    fwrite(STDERR, "coverage-publish: $clover not found; run the PHPUnit suite with --coverage-clover first\n");
    exit(2);
}
$xml = simplexml_load_file($clover);
if ($xml === false || isset($xml->project->metrics) === false) {
    fwrite(STDERR, "coverage-publish: $clover is not a clover report\n");
    exit(2);
}
$m = $xml->project->metrics;
$suites['phpunit'] = [
    'statements' => metric((int) $m['statements'], (int) $m['coveredstatements']),
    'functions' => metric((int) $m['methods'], (int) $m['coveredmethods']),
];
$suites['phpunit']['lines'] = $suites['phpunit']['statements'];

if (is_file($jest) === true) {
    $js = json_decode((string) file_get_contents($jest), true);
    if (is_array($js) === false || isset($js['total']['statements']) === false) {
        fwrite(STDERR, "coverage-publish: $jest is not a json-summary report\n");
        exit(2);
    }
    foreach (['lines', 'statements', 'functions'] as $key) {
        $suites['jest'][$key] = metric((int) $js['total'][$key]['total'], (int) $js['total'][$key]['covered']);
    }
}

$total = [];
foreach (['lines', 'statements', 'functions'] as $key) {
    $sum = 0;
    $hit = 0;
    foreach ($suites as $suite) {
        $sum += $suite[$key]['total'];
        $hit += $suite[$key]['covered'];
    }
    $total[$key] = metric($sum, $hit);
}

$commit = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD 2>/dev/null'));
$summary = [
    'total' => $total,
    'suites' => $suites,
    'measuredAt' => gmdate('Y-m-d'),
    'commit' => $commit,
    'generator' => 'scripts/coverage-publish.php',
];
file_put_contents($out, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$fmt = static fn (float $pct): string => number_format($pct, 1) . '%';
$parts = ['PHP ' . $fmt($suites['phpunit']['statements']['pct']) . ' of ' . number_format($suites['phpunit']['statements']['total']) . ' statements'];
if (isset($suites['jest']) === true) {
    $parts[] = 'JavaScript ' . $fmt($suites['jest']['statements']['pct'])
        . ' of ' . number_format($suites['jest']['statements']['total']) . ' statements';
}

$jsNote = 'The JavaScript frontend is not in this figure, because it has no coverage measurement yet. ';
if (isset($suites['jest']) === true) {
    $jsNote = '';
}
$block = "<!-- coverage:start -->\n"
    . 'Measured test coverage: ' . $fmt($total['statements']['pct']) . ' of statements (' . implode(', ', $parts) . '), '
    . 'at commit `' . $commit . '` on ' . $summary['measuredAt'] . '. '
    . $jsNote
    . "The figures come from [`coverage-summary.json`](coverage-summary.json); refresh them with `composer coverage:publish`.\n"
    . '<!-- coverage:end -->';

if (is_file($readme) === true) {
    $text = (string) file_get_contents($readme);
    $pattern = '/<!-- coverage:start -->.*?<!-- coverage:end -->/s';
    if (preg_match($pattern, $text) === 1) {
        $text = preg_replace($pattern, $block, $text);
        file_put_contents($readme, $text);
    } else {
        fwrite(
            STDERR,
            "coverage-publish: no coverage markers in README.md; add <!-- coverage:start --><!-- coverage:end --> where the line belongs\n"
        );
    }
}

echo 'Coverage ' . $fmt($total['statements']['pct']) . ' (' . implode(', ', $parts) . ")\n";
exit(0);
